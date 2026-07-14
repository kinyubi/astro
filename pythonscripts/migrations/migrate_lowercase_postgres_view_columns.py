"""
migrate_lowercase_postgres_view_columns.py

Follow-up to migrate_lowercase_postgres_identifiers.py.

That script renamed every base-table column to lowercase. Postgres
automatically cascades those renames into each view's *internal*
references (the SELECT-list expressions), but a view's own *exposed*
output column names are fixed at CREATE VIEW time and are NOT renamed
just because the underlying table column was -- they're a separate,
independent set of attributes on the view relation itself.

Fix: ALTER VIEW ... RENAME COLUMN ... TO ... for every view column that
isn't already lowercase. No view drop/recreate needed.

Dynamic approach, same as the base-table script: reads views/columns
from information_schema rather than a hardcoded list. Safe to re-run.

Usage:
    python migrate_lowercase_postgres_view_columns.py
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


def fetch_views(cur):
    cur.execute("""
        SELECT table_name FROM information_schema.tables
        WHERE table_schema = 'public' AND table_type = 'VIEW'
        ORDER BY table_name
    """)
    return [r[0] for r in cur.fetchall()]


def fetch_columns(cur, view_name):
    cur.execute("""
        SELECT column_name FROM information_schema.columns
        WHERE table_schema = 'public' AND table_name = %s
        ORDER BY ordinal_position
    """, (view_name,))
    return [r[0] for r in cur.fetchall()]


def main():
    conn = get_connection()
    conn.autocommit = False
    cur = conn.cursor()

    views = fetch_views(cur)

    plan = []  # [(view_name, [(old_col, new_col), ...], needs_view_rename)]
    for v in views:
        cols = fetch_columns(cur, v)
        col_renames = [(c, c.lower()) for c in cols if c != c.lower()]
        needs_view_rename = v != v.lower()
        if col_renames or needs_view_rename:
            plan.append((v, col_renames, needs_view_rename))

    print("=" * 70)
    print("Lowercase Postgres view names/columns -- preview")
    print("=" * 70)

    if not plan:
        print("\nNothing to do -- every view name and column is already lowercase.")
        cur.close()
        conn.close()
        return

    total_cols = sum(len(c) for _, c, _ in plan)
    total_views = sum(1 for _, _, needs in plan if needs)
    print(f"\nViews needing rename: {total_views}")
    print(f"Columns needing rename: {total_cols}\n")

    for v, col_renames, needs_view_rename in plan:
        label = f'"{v}"' + (f' -> "{v.lower()}"' if needs_view_rename else " (name OK)")
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
        for v, col_renames, needs_view_rename in plan:
            # Rename columns first, while the view still has its original
            # (possibly mixed-case) name.
            for old_c, new_c in col_renames:
                cur.execute(
                    sql.SQL("ALTER VIEW {} RENAME COLUMN {} TO {}").format(
                        sql.Identifier(v), sql.Identifier(old_c), sql.Identifier(new_c)
                    )
                )
            if needs_view_rename:
                cur.execute(
                    sql.SQL("ALTER VIEW {} RENAME TO {}").format(
                        sql.Identifier(v), sql.Identifier(v.lower())
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

    # ── Verify across everything (tables + views) ────────────────────
    cur.execute("""
        SELECT table_name FROM information_schema.tables
        WHERE table_schema = 'public'
    """)
    remaining_tables = [r[0] for r in cur.fetchall() if r[0] != r[0].lower()]

    cur.execute("""
        SELECT table_name, column_name FROM information_schema.columns
        WHERE table_schema = 'public'
    """)
    remaining_cols = [(r[0], r[1]) for r in cur.fetchall() if r[1] != r[1].lower()]

    if remaining_tables or remaining_cols:
        print("\nWARNING -- some identifiers are still not lowercase:")
        for t in remaining_tables:
            print(f'  table "{t}"')
        for t, c in remaining_cols:
            print(f'  {t}."{c}"')
    else:
        print("\nVerified: every table, view, and column in public schema is now lowercase.")

    cur.close()
    conn.close()


if __name__ == '__main__':
    main()
