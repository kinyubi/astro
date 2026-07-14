"""
db_connect.py — driver-aware DB connection for Python scripts.

Reads shared/db_config.json -- the SAME file the PHP side reads via
shared/config.php -- so the driver toggle can never drift out of sync
between languages. That drift is exactly what caused /vis to silently
keep reading stale SQLite data after the admin panel had already
switched to writing Postgres.

get_connection() returns a connection whose cursor:
  - accepts sqlite3-style '?' positional placeholders even in Postgres
    mode (translated to '%s' internally), so existing query text needs
    no placeholder-syntax rewrite
  - fetches rows as dict-like objects keyed by column name, matching
    sqlite3.Row's row['ColumnName'] access style

IMPORTANT -- casing: the live Postgres schema stores table/column names
in lowercase (see migrations/migrate_lowercase_postgres_identifiers.py),
while every script here was written expecting SQLite's exact-case-
preserving result labels. So any SELECT whose result is accessed by
column name (row['DSOKey']) needs an explicit quoted alias:

    SELECT DSOKey AS "DSOKey" FROM Objects

This is a no-op on SQLite (it already returns that exact case) and
forces Postgres to label the output column exactly as written, so the
same row['DSOKey'] access works unchanged on both engines. INSERT/
UPDATE/DELETE statements don't need this -- unquoted column references
there only need correct *resolution*, which works fine now that the
underlying schema is lowercase.

IMPORTANT -- literal '%' characters: psycopg2 uses Python %-style
parameter substitution, so any literal '%' in SQL text (e.g. a LIKE
'%foo%' pattern) must be escaped as '%%' whenever parameters are bound,
or psycopg2 raises a formatting error. This module handles that
automatically -- callers don't need to think about it.
"""
import json
import sqlite3

_CONFIG_PATH = r"C:\laragon7\www\astro\shared\db_config.json"


def _load_config():
    with open(_CONFIG_PATH, 'r') as f:
        return json.load(f)


def get_driver():
    return _load_config().get('driver', 'sqlite')


class _PgCursor:
    """Wraps a psycopg2 RealDictCursor so callers can keep using
    sqlite3-style '?' placeholders and row['ColumnName'] access
    unchanged."""

    def __init__(self, cursor):
        self._cursor = cursor

    def execute(self, sql, params=None):
        if params is not None:
            # Escape literal '%' first (so it survives as a literal '%'
            # after psycopg2's substitution), THEN swap '?' -> '%s'.
            translated = sql.replace('%', '%%').replace('?', '%s')
            return self._cursor.execute(translated, params)
        return self._cursor.execute(sql.replace('?', '%s'))

    def executemany(self, sql, seq_of_params):
        translated = sql.replace('%', '%%').replace('?', '%s')
        return self._cursor.executemany(translated, seq_of_params)

    def __getattr__(self, name):
        return getattr(self._cursor, name)

    def __iter__(self):
        return iter(self._cursor)


class _PgConnection:
    """Wraps a psycopg2 connection to match the small subset of
    sqlite3.Connection's interface these scripts use: .cursor(),
    .execute() (a sqlite3 convenience psycopg2 lacks natively),
    .commit(), .close()."""

    def __init__(self, conn):
        self._conn = conn

    def cursor(self):
        return _PgCursor(self._conn.cursor())

    def execute(self, sql, params=None):
        cur = self.cursor()
        cur.execute(sql, params)
        return cur

    def __getattr__(self, name):
        return getattr(self._conn, name)


def get_connection():
    """Returns a DB-API connection driven by shared/db_config.json's
    "driver" setting. SQLite mode: plain sqlite3.Connection with
    row_factory=sqlite3.Row. Postgres mode: a _PgConnection wrapping
    psycopg2 with RealDictCursor, so row['ColumnName'] access and '?'
    placeholders behave the same as SQLite mode."""
    cfg = _load_config()
    driver = cfg.get('driver', 'sqlite')

    if driver == 'pgsql':
        import psycopg2
        import psycopg2.extras
        conn = psycopg2.connect(
            host=cfg['pg_host'], port=cfg['pg_port'], dbname=cfg['pg_dbname'],
            user=cfg['pg_user'], password=cfg['pg_password'],
            cursor_factory=psycopg2.extras.RealDictCursor,
        )
        return _PgConnection(conn)

    conn = sqlite3.connect(cfg['sqlite_path'])
    conn.row_factory = sqlite3.Row
    return conn
