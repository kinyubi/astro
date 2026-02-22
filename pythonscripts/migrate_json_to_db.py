#!/usr/bin/env python3
"""
migrate_json_to_db.py
Reads dso_watchlist_info.json and UPDATEs matching rows in the Objects table.
Skips redirect-only entries (those with only a "See" key).
Run from anywhere; paths are configured below.
"""

import json
import sqlite3
import sys
from pathlib import Path

# ── Configure paths ──────────────────────────────────────────────────────────
JSON_PATH = Path(r"C:\laragon7\www\astro\public\dso_watchlist_info.json")
DB_PATH   = Path(r"C:\laragon7\www\astro\dsodb\astro.db")

# ── Constellation name → IAU 3-letter code ───────────────────────────────────
CONST_MAP = {
    "Andromeda":        "AND",
    "Aquarius":         "AQR",
    "Aquila":           "AQL",
    "Aries":            "ARI",
    "Auriga":           "AUR",
    "Canis Major":      "CMA",
    "Canis Minor":      "CMI",
    "Cancer":           "CNC",
    "Canes Venatici":   "CVN",
    "Cassiopeia":       "CAS",
    "Cepheus":          "CEP",
    "Cepheus/Cygnus":   "CEP",
    "Cygnus":           "CYG",
    "Dorado":           "DOR",
    "Eridanus":         "ERI",
    "Fornax":           "FOR",
    "Gemini":           "GEM",
    "Hercules":         "HER",
    "Leo":              "LEO",
    "Lyra":             "LYR",
    "Monoceros":        "MON",
    "Orion":            "ORI",
    "Pegasus":          "PEG",
    "Perseus":          "PER",
    "Pisces":           "PSC",
    "Sagittarius":      "SGR",
    "Serpens":          "SER",
    "Taurus":           "TAU",
    "Triangulum":       "TRI",
    "Ursa Major":       "UMA",
    "Ursa Minor":       "UMI",
    "Virgo":            "VIR",
    "Vulpecula":        "VUL",
    "Cassiopeia/Cepheus": "CAS",
}

def build_social_blurb(entry: dict) -> str | None:
    """Build a plain-text placeholder blurb from Composition + FunFacts."""
    parts = []
    composition = entry.get("Composition", "").strip()
    if composition:
        parts.append(composition)
    fun_facts = entry.get("FunFacts", [])
    if fun_facts:
        parts.append("  ".join(fun_facts))
    return "\n\n".join(parts) if parts else None

def is_redirect(entry: dict) -> bool:
    """Return True if this entry is just a cross-reference (has 'See' and nothing else useful)."""
    return "See" in entry and "Composition" not in entry

def main():
    if not JSON_PATH.exists():
        print(f"ERROR: JSON not found at {JSON_PATH}", file=sys.stderr)
        sys.exit(1)
    if not DB_PATH.exists():
        print(f"ERROR: Database not found at {DB_PATH}", file=sys.stderr)
        sys.exit(1)

    with open(JSON_PATH, encoding="utf-8") as f:
        data = json.load(f)

    con = sqlite3.connect(DB_PATH)
    con.row_factory = sqlite3.Row
    cur = con.cursor()

    updated = skipped_redirect = skipped_no_row = 0

    for dso_key, entry in data.items():
        if is_redirect(entry):
            skipped_redirect += 1
            continue

        # Check the row exists in Objects
        cur.execute("SELECT DSOKey FROM Objects WHERE DSOKey = ?", (dso_key,))
        if not cur.fetchone():
            print(f"  SKIP (no Objects row): {dso_key}")
            skipped_no_row += 1
            continue

        # Map fields
        common_name  = entry.get("CommonName") or None
        constellation_full = entry.get("Constellation") or ""
        constellation_id   = CONST_MAP.get(constellation_full.strip()) or None
        distance_ly  = entry.get("Distance") or None
        object_size  = entry.get("Size") or None
        social_blurb = build_social_blurb(entry)

        # Only update fields that are currently NULL in the DB — don't clobber
        # anything already populated via AI or manual entry.
        cur.execute("""
            UPDATE Objects SET
                CommonName      = COALESCE(CommonName,      ?),
                ConstellationID = COALESCE(ConstellationID, ?),
                DistanceLY      = COALESCE(DistanceLY,      ?),
                ObjectSize      = COALESCE(ObjectSize,       ?),
                SocialBlurb     = COALESCE(SocialBlurb,     ?)
            WHERE DSOKey = ?
        """, (common_name, constellation_id, distance_ly, object_size, social_blurb, dso_key))

        if cur.rowcount:
            print(f"  OK  {dso_key:20s}  {common_name or '—'}")
            updated += 1

    # Also insert CatalogID aliases from OtherNames
    other_names_added = 0
    for dso_key, entry in data.items():
        if is_redirect(entry):
            continue
        for alias in entry.get("OtherNames", []):
            alias = alias.strip()
            if not alias:
                continue
            cur.execute("""
                INSERT OR IGNORE INTO CatalogIDs (CatalogID, DSOKey, IsPrimary)
                VALUES (?, ?, 0)
            """, (alias, dso_key))
            if cur.rowcount:
                other_names_added += 1

    con.commit()
    con.close()

    print(f"\nDone.")
    print(f"  Objects updated      : {updated}")
    print(f"  Redirect entries skipped: {skipped_redirect}")
    print(f"  No matching DB row   : {skipped_no_row}")
    print(f"  OtherNames aliases added to CatalogIDs: {other_names_added}")

if __name__ == "__main__":
    main()