"""
Migration: add the ImageRatings table for the Lightbox (public/lightbox/).

One row per rated/noted session image in MyWorks:

    DSOToken      first '_' token of the project folder, uppercased (M31, SH2-101).
                  Used instead of the folder name so a project-folder rename
                  doesn't orphan ratings.
    SessionDir    session folder name, e.g. 20250913_452x30s_S30
    FileName      e.g. m31_andromeda_S30_fav.jpg
    ProjectFolder folder the image was in when last rated (informational)
    ImageType     fav | full | wall | 4k
    Rating        1-5, or NULL when only a note is set
    Note          free text
    UpdatedAt     last change

Primary key (DSOToken, SessionDir, FileName). A row with no rating and no
note is deleted by the Lightbox rather than kept empty.

Additive only — no existing table is touched. Runs against whichever DB
shared/db_config.json points at (Postgres or SQLite) via db_connect.

Run once from any directory:  python migrate_add_image_ratings.py
Safe to re-run: uses CREATE TABLE IF NOT EXISTS.
"""
import argparse
import sys
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent.parent.parent / 'pythonscripts'))
from db_connect import get_connection, get_driver  # noqa: E402

SQL = """
CREATE TABLE IF NOT EXISTS ImageRatings (
    DSOToken      TEXT NOT NULL,
    SessionDir    TEXT NOT NULL,
    FileName      TEXT NOT NULL,
    ProjectFolder TEXT NOT NULL,
    ImageType     TEXT NOT NULL,
    Rating        INTEGER CHECK (Rating BETWEEN 1 AND 5),
    Note          TEXT,
    UpdatedAt     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (DSOToken, SessionDir, FileName)
)
"""


def table_exists(conn, driver: str) -> bool:
    if driver == 'pgsql':
        row = conn.execute("SELECT to_regclass('imageratings') IS NOT NULL AS \"Exists\"").fetchone()
        return bool(row['Exists'])
    row = conn.execute(
        "SELECT COUNT(*) AS n FROM sqlite_master WHERE type='table' AND name='ImageRatings'"
    ).fetchone()
    return row['n'] > 0


def main():
    argparse.ArgumentParser(
        description="Create the ImageRatings table used by the Lightbox (safe to re-run)."
    ).parse_args()

    driver = get_driver()
    conn = get_connection()
    try:
        existed_before = table_exists(conn, driver)
        conn.execute(SQL)
        conn.commit()
    finally:
        conn.close()

    where = 'Postgres' if driver == 'pgsql' else 'SQLite'
    if existed_before:
        print(f"ImageRatings already existed in {where} — nothing changed.")
    else:
        print(f"ImageRatings table created in {where}.")


if __name__ == "__main__":
    main()
