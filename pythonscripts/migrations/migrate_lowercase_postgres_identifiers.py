"""
migrate_lowercase_postgres_identifiers.py

Root cause: migrate_sqlite_to_postgres.py preserved SQLite's mixed-case
table/column names by creating them as quoted identifiers in Postgres
(e.g. "Objects", "DSOKey"). Postgres folds *unquoted* identifiers to
lowercase, so every existing SQL query across the PHP/Python codebase --
written as plain `SELECT DSOKey FROM Objects` with no quotes -- silently
resolves to `objects`/`dsokey`, which don't exist. This is why the
gallery, /vis, admin, etc. failed silently or with "relation does not
exist" once DB_DRIVER was flipped to pgsql.

Fix: rename every table and column in the public schema to lowercase.
Once renamed, Postgres's default unquoted-identifier folding naturally
matches all the existing app code -- no changes needed to any SQL string
in PHP or Python.

Postgres automatically cascades RENAME operations into dependent views,
foreign key definitions, and generated-column expressions (e.g.
Projects.IsMosaic's LOWER(ProjectFolder) LIKE '%mosaic%' expression) --
these are tracked internally by OID/attnum, not by text name, so no
view/generated-column recreation is needed here.

Dynamic approach: reads current tables/columns from information_schema
rather than a hardcoded list per table, so nothing needs to be manually
kept in sync with the schema, and it's safe to re-run -- anything already
lowercase is skipped.

Usage:
    python migrate_lowercase_postgres_identifiers.py
"""

import psycopg2
from psycopg2 import sql

PG_HOST = "10.8.0.1"
PG_PORT = 5432
PG_DBNAME = "astro"
PG_USER = "astro_app"
PG_PASSWORD = "+3DT2ujE*4?335Sp"


def get_connection():
    return psycopg2.connect(
        host=PG_HOST, port=PG_PORT, dbname=PG_DBNAME,
        user=PG_USER, password=PG_PASSWORD,
    )


def fetch_tables(cur):
    cur.execute("""
        SELECT table_name FROM information_schema.tables
        WHERE table_schema = 'public' AND table_type = 'BASE TABLE'
        ORDER BY table_name
    """)
    return [r[0] for r in cur.fetchall()]


def fetch_columns(cur, table_name):
    cur.execute("""
        SELECT column_name FROM information_schema.columns
        WHERE table_schema = 'public' AND table_name = %s
        ORDER BY ordinal_position
    """, (table_name,))
    return [r[0] for r in cur.fetchall()]


def main():
    conn = get_connection()
    conn.autocommit = False
    cur = conn.cursor()

    tables = fetch_tables(cur)

    # Build the full plan first (table -> [(old_col, new_col), ...], plus
    # whether the table itself needs renaming) so we can show a clean
    # preview before touching anything.
    plan = []  # [(table_name, [(old_col, new_col), ...], needs_table_rename)]
    for t in tables:
        cols = fetch_columns(cur, t)
        col_renames = [(c, c.lower()) for c in cols if c != c.lower()]
        needs_table_rename = t != t.lower()
        if col_renames or needs_table_rename:
            plan.append((t, col_renames, needs_table_rename))

    print("=" * 70)
    print("Lowercase Postgres identifiers -- preview")
    print("=" * 70)

    if not plan:
        print("\nNothing to do -- every table and column is already lowercase.")
        cur.close()
        conn.close()
        return

    total_col_renames = sum(len(c) for _, c, _ in plan)
    total_table_renames = sum(1 for _, _, needs in plan if needs)
    print(f"\nTables needing rename: {total_table_renames}")
    print(f"Columns needing rename: {total_col_renames}\n")

    for t, col_renames, needs_table_rename in plan:
        label = f'"{t}"' + (f' -> "{t.lower()}"' if needs_table_rename else " (name OK)")
        print(f"  {label}")
        for old_c, new_c in col_renames:
            print(f'    "{old_c}" -> "{new_c}"')

    answer = input("\nApply? [y/N] ").strip().lower()
    if answer != 'y':
        print("Aborted. No changes made.")
        cur.close()
        conn.close()
        return

    try:
        for t, col_renames, needs_table_rename in plan:
            # Rename columns first, while the table still has its original
            # (possibly mixed-case) name -- avoids needing to track a
            # before/after table-name mapping mid-loop.
            for old_c, new_c in col_renames:
                cur.execute(
                    sql.SQL("ALTER TABLE {} RENAME COLUMN {} TO {}").format(
                        sql.Identifier(t), sql.Identifier(old_c), sql.Identifier(new_c)
                    )
                )
            if needs_table_rename:
                cur.execute(
                    sql.SQL("ALTER TABLE {} RENAME TO {}").format(
                        sql.Identifier(t), sql.Identifier(t.lower())
                    )
                )

        conn.commit()
        print("\nCOMMIT successful.")

    except Exception as e:
        conn.rollback()
        print(f"\nERROR -- rolled back, no changes were kept: {e}")
        cur.close()
        conn.close()
        return

    # ── Verify ────────────────────────────────────────────────────────
    cur.execute("""
        SELECT table_name FROM information_schema.tables
        WHERE table_schema = 'public' AND table_type = 'BASE TABLE'
        ORDER BY table_name
    """)
    remaining_tables = [r[0] for r in cur.fetchall() if r[0] != r[0].lower()]

    cur.execute("""
        SELECT table_name, column_name FROM information_schema.columns
        WHERE table_schema = 'public'
        ORDER BY table_name, ordinal_position
    """)
    remaining_cols = [(r[0], r[1]) for r in cur.fetchall() if r[1] != r[1].lower()]

    if remaining_tables or remaining_cols:
        print("\nWARNING -- some identifiers are still not lowercase:")
        for t in remaining_tables:
            print(f'  table "{t}"')
        for t, c in remaining_cols:
            print(f'  {t}."{c}"')
    else:
        print("\nVerified: every table and column in public schema is now lowercase.")

    cur.close()
    conn.close()


if __name__ == '__main__':
    main()
