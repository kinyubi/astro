#!/usr/bin/env python3
"""
regenerate_blurbs.py
Calls the local Laragon api_populate.php endpoint for every object in the
Objects table and saves the returned SocialBlurb back via api_save.php.

Only SocialBlurb is written back — all other fields are left untouched.

Usage:
    python regenerate_blurbs.py            # process all objects
    python regenerate_blurbs.py SH2-101    # process one object by DSOKey
    python regenerate_blurbs.py --skip-existing  # skip objects that already have a blurb

Run from any directory; Laragon must be running.
"""

import sqlite3
import requests
import json
import sys
import time
import re

# ── Config ────────────────────────────────────────────────────────────────────
DB_PATH       = r"C:\laragon7\www\astro\dsodb\astro.db"
POPULATE_URL  = "http://astro.app/admin/api_populate.php"
SAVE_URL      = "http://astro.app/admin/api_save.php"
DELAY_SECS    = 1.5   # polite pause between API calls

# Objects to skip entirely (solar system, etc.)
SKIP_KEYS = {"SUN", "MOON"}
# ──────────────────────────────────────────────────────────────────────────────


def get_objects(db_path, target_key=None, skip_existing=False):
    conn = sqlite3.connect(db_path)
    conn.row_factory = sqlite3.Row
    query = """
        SELECT o.DSOKey, o.CommonName, o.ConstellationID, o.DistanceLY,
               o.ObjectSize, o.SocialBlurb,
               c.CatalogID AS PrimaryCatalog
        FROM Objects o
        LEFT JOIN CatalogIDs c ON c.DSOKey = o.DSOKey AND c.IsPrimary = 1
        ORDER BY o.DSOKey
    """
    rows = conn.execute(query).fetchall()
    conn.close()

    result = []
    for r in rows:
        key = r["DSOKey"]
        if key in SKIP_KEYS:
            continue
        if target_key and key != target_key:
            continue
        if skip_existing and r["SocialBlurb"] and len(r["SocialBlurb"].strip()) > 20:
            continue
        result.append(dict(r))
    return result


def call_populate(obj):
    """POST to api_populate.php and return the fields dict, or None on failure."""
    payload = {
        "dso_id":            obj["DSOKey"],
        "primary_catalog_id": obj["PrimaryCatalog"] or obj["DSOKey"],
        "common_name":       obj["CommonName"] or "",
        "constellation":     obj["ConstellationID"] or "",
        "object_size":       obj["ObjectSize"] or "",
        "distance":          obj["DistanceLY"] or "",
    }
    try:
        resp = requests.post(POPULATE_URL, json=payload, timeout=90)
        resp.raise_for_status()
        data = resp.json()
        if data.get("success"):
            return data.get("fields", {})
        else:
            print(f"    ✗ API error: {data.get('error', 'unknown')}")
            return None
    except Exception as e:
        print(f"    ✗ Request failed: {e}")
        return None


def save_blurb(dso_key, blurb):
    """POST only SocialBlurb back via api_save.php."""
    payload = {"DSOKey": dso_key, "SocialBlurb": blurb}
    try:
        resp = requests.post(SAVE_URL, json=payload, timeout=30)
        resp.raise_for_status()
        data = resp.json()
        return data.get("success", False)
    except Exception as e:
        print(f"    ✗ Save failed: {e}")
        return False


def validate_blurb(blurb, obj):
    """Basic quality checks. Returns list of warnings (not hard failures)."""
    warnings = []
    bad_words = [
        "stunning", "breathtaking", "magnificent", "incredible",
        "remarkable", "iconic", "beautiful", "favorite target",
        "stellar nursery", "cosmic tapestry", "dance of stars", "captivated"
    ]
    blurb_lower = blurb.lower()
    for w in bad_words:
        if w in blurb_lower:
            warnings.append(f"contains '{w}'")
    if len(blurb.split()) > 250:
        warnings.append(f"over word limit ({len(blurb.split())} words)")
    # Check it starts with expected opener
    name = obj.get("CommonName") or ""
    cat  = obj.get("PrimaryCatalog") or obj["DSOKey"]
    expected = f"The {name} ({cat}) is"
    if name and not blurb.startswith(expected):
        warnings.append(f"doesn't start with expected opener")
    return warnings


def main():
    args = sys.argv[1:]
    target_key    = None
    skip_existing = False

    for arg in args:
        if arg == "--skip-existing":
            skip_existing = True
        else:
            target_key = arg

    objects = get_objects(DB_PATH, target_key=target_key, skip_existing=skip_existing)

    if not objects:
        print("No objects to process.")
        return

    print(f"Processing {len(objects)} object(s)...\n")

    success_count = 0
    fail_count    = 0
    warn_count    = 0

    for i, obj in enumerate(objects, 1):
        key  = obj["DSOKey"]
        name = obj["CommonName"] or "(no name)"
        print(f"[{i}/{len(objects)}] {key} — {name}")

        fields = call_populate(obj)
        if not fields:
            fail_count += 1
            continue

        blurb = fields.get("SocialBlurb", "").strip()
        if not blurb:
            print(f"    ✗ No blurb returned")
            fail_count += 1
            continue

        warnings = validate_blurb(blurb, obj)
        if warnings:
            warn_count += 1
            print(f"    ⚠ Warnings: {'; '.join(warnings)}")

        saved = save_blurb(key, blurb)
        if saved:
            preview = blurb[:100].replace('\n', ' ')
            print(f"    ✓ Saved: {preview}...")
            success_count += 1
        else:
            fail_count += 1

        if i < len(objects):
            time.sleep(DELAY_SECS)

    print(f"\nDone. ✓ {success_count} saved  ⚠ {warn_count} with warnings  ✗ {fail_count} failed")


if __name__ == "__main__":
    main()
