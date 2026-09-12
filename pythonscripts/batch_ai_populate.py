#!/usr/bin/env python3
"""
batch_ai_populate.py
Calls the DSO admin AI populate endpoint for every object in the database.
Only fills empty fields; always regenerates SocialBlurb.

Runs on localhost only — no authentication required.

Usage:
    python batch_ai_populate.py              # all objects
    python batch_ai_populate.py NGC1976      # single object
    python batch_ai_populate.py M1           # also works — resolves via CatalogIDs
    python batch_ai_populate.py --blurb-only # regenerate SocialBlurb only
    python batch_ai_populate.py --empty-only # only objects with no ObjectTypeID
    python batch_ai_populate.py --dry-run    # show what would be processed

Requires: pip install requests
"""

import argparse
import time
import sys
import requests
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent))  # pythonscripts/, for db_connect
from db_connect import get_connection  # noqa: E402

# ── Configure ────────────────────────────────────────────────────────────────
API_BASE_URL = "http://localhost/astro/public/admin"

# Delay between API calls (seconds) — be kind to the Anthropic API
DELAY_SECS = 3

# ── Helpers ──────────────────────────────────────────────────────────────────
def get_objects(dso_key_filter: str = None, empty_only: bool = False) -> list[dict]:
    """Fetch objects from the live Postgres DB (via db_connect) with their
    primary CatalogID. This used to read a separate legacy SQLite copy that
    nothing kept in sync, so any DSO added since was silently invisible to
    this script."""
    conn = get_connection()
    cur = conn.cursor()

    if dso_key_filter:
        key = dso_key_filter.upper()
        # Accept either a DSOKey (NGC1952) or any CatalogID (M1, Taurus A, etc.)
        cur.execute("""
            SELECT
                o.DSOKey AS "DSOKey", o.CommonName AS "CommonName",
                o.ConstellationID AS "ConstellationID", o.DistanceLY AS "DistanceLY",
                o.ObjectSize AS "ObjectSize",
                c.CatalogID AS "PrimaryCatalogID"
            FROM Objects o
            LEFT JOIN CatalogIDs c ON o.DSOKey = c.DSOKey AND c.IsPrimary = 1
            WHERE UPPER(o.DSOKey) = UPPER(?)
               OR o.DSOKey = (SELECT DSOKey FROM CatalogIDs WHERE UPPER(CatalogID) = UPPER(?))
        """, (key, key))
    elif empty_only:
        cur.execute("""
            SELECT
                o.DSOKey AS "DSOKey", o.CommonName AS "CommonName",
                o.ConstellationID AS "ConstellationID", o.DistanceLY AS "DistanceLY",
                o.ObjectSize AS "ObjectSize",
                c.CatalogID AS "PrimaryCatalogID"
            FROM Objects o
            LEFT JOIN CatalogIDs c ON o.DSOKey = c.DSOKey AND c.IsPrimary = 1
            WHERE o.ObjectTypeID IS NULL
            ORDER BY o.DSOKey
        """)
    else:
        cur.execute("""
            SELECT
                o.DSOKey AS "DSOKey", o.CommonName AS "CommonName",
                o.ConstellationID AS "ConstellationID", o.DistanceLY AS "DistanceLY",
                o.ObjectSize AS "ObjectSize",
                c.CatalogID AS "PrimaryCatalogID"
            FROM Objects o
            LEFT JOIN CatalogIDs c ON o.DSOKey = c.DSOKey AND c.IsPrimary = 1
            ORDER BY o.DSOKey
        """)

    rows = [dict(r) for r in cur.fetchall()]
    conn.close()
    return rows


def populate_object(session: requests.Session, obj: dict) -> dict:
    """Call api_populate.php for one object. Returns the response data."""
    payload = {
        "dso_id":             obj["DSOKey"],
        "primary_catalog_id": obj.get("PrimaryCatalogID") or obj["DSOKey"],
        "common_name":        obj["CommonName"]      or "",
        "constellation":      obj["ConstellationID"] or "",
        "distance":           obj["DistanceLY"]      or "",
        "object_size":        obj["ObjectSize"]      or "",
    }
    resp = session.post(f"{API_BASE_URL}/api_populate.php", json=payload, timeout=60)
    resp.raise_for_status()
    return resp.json()


def save_object(session: requests.Session, obj: dict, fields: dict, blurb_only: bool) -> dict:
    """Call api_save.php with AI-returned fields merged over existing values."""
    field_map = [
        "CommonName", "ObjectTypeID", "ConstellationID",
        "RAHours", "DecDegrees", "Magnitude",
        "ObjectSize", "DistanceLY", "SocialBlurb",
    ]

    payload = {"DSOKey": obj["DSOKey"], "CatalogIDs": []}

    for key in field_map:
        ai_val   = fields.get(key)
        existing = obj.get(key)

        if key == "SocialBlurb":
            payload[key] = ai_val                            # always regenerate
        elif blurb_only:
            payload[key] = existing                          # leave everything else alone
        else:
            payload[key] = existing if existing else ai_val  # fill if empty

    resp = session.post(f"{API_BASE_URL}/api_save.php", json=payload, timeout=30)
    resp.raise_for_status()
    return resp.json()


# ── Main ─────────────────────────────────────────────────────────────────────
def main():
    parser = argparse.ArgumentParser(description="Batch AI populate for all DSO objects")
    parser.add_argument("dso_key", nargs="?", help="Optional: process a single DSO key or catalog ID")
    parser.add_argument("--blurb-only",  action="store_true", help="Only regenerate SocialBlurb")
    parser.add_argument("--empty-only",  action="store_true", help="Only process objects with no ObjectTypeID")
    parser.add_argument("--dry-run",     action="store_true", help="Show what would be processed without calling the API")
    args = parser.parse_args()

    objects = get_objects(args.dso_key, args.empty_only)
    if not objects:
        print("No objects found" + (f" matching '{args.dso_key}'" if args.dso_key else ""))
        sys.exit(0)

    print(f"{'DRY RUN — ' if args.dry_run else ''}Processing {len(objects)} object(s)"
          + (" [blurb only]" if args.blurb_only else "")
          + (" [empty only]" if args.empty_only else "") + "\n")

    if args.dry_run:
        for obj in objects:
            primary = obj.get("PrimaryCatalogID") or obj["DSOKey"]
            print(f"  {obj['DSOKey']:20s}  primary={primary:12s}  {obj['CommonName'] or '—'}")
        sys.exit(0)

    session = requests.Session()
    ok = err = 0

    for i, obj in enumerate(objects, 1):
        key     = obj["DSOKey"]
        primary = obj.get("PrimaryCatalogID") or key
        name    = obj["CommonName"] or "—"
        print(f"[{i}/{len(objects)}] {key:20s} ({primary:10s}) {name}", end="  ", flush=True)

        try:
            result = populate_object(session, obj)

            if not result.get("success"):
                print(f"X AI error: {result.get('error', 'unknown')}")
                err += 1
            else:
                save_result = save_object(session, obj, result["fields"], args.blurb_only)
                if save_result.get("success"):
                    print("OK")
                    ok += 1
                else:
                    print(f"X Save error: {save_result.get('error', 'unknown')}")
                    err += 1

        except Exception as e:
            print(f"X Exception: {e}")
            err += 1

        if i < len(objects):
            time.sleep(DELAY_SECS)

    print(f"\nDone. {ok} succeeded, {err} failed.")


if __name__ == "__main__":
    main()
