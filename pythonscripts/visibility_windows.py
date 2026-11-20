#!/usr/bin/env python3
"""
visibility_windows.py

Works out when each DSO and planet is visible under each visibility profile
and stores the windows in the VisibilityWindows and PlanetVisibilityWindows
tables. The /vis/span/ page shows them, and you can query them directly:

    SELECT * FROM vw_VisibilityEndingSoon
    WHERE profile = 'default' ORDER BY nightsleft, dsokey;

A window is a run of consecutive nights where the object meets the same rule
DSO Visibility uses (todays_dsos_web.py), during astronomical darkness (Sun
below -18 deg), inside the profile's min_altitude and az_min..az_max:
  * DSOs: the span from the first to the last moment it's in that part of
    the sky is at least 60 minutes.
  * Planets (Mercury to Neptune): any moment at all -- no minimum, the same
    as the Planets section of DSO Visibility. Their real, moving positions
    are used. Mercury and Venus are rarely up in full darkness.
Nights are dated by their local evening.

Calculated with Skyfield for every night from 183 days ago to 370 days
ahead, sampled every 2 minutes. The past half year is included so a window
that's open now has its real first night. A window cut off by either end of
that range is flagged (StartsBeforeRange / RunsPastRange).

DSO positions are Objects.RAHours / DecDegrees (J2000). DSOs with no
position, and SOLAR_SYSTEM objects, are skipped.

Each run replaces the stored windows for the objects and profiles it covers.
It lists what changed and asks before saving. Windows that change only
because the date range moved on are counted, not listed.

Usage:
    visibility_windows.py                     # every DSO and planet, every profile
    visibility_windows.py m31 ngc7000 saturn  # just these (DSO keys and/or planet names)
    visibility_windows.py -p default -p backyard35
    visibility_windows.py --what-if           # report only
    visibility_windows.py -a                  # save without asking (scheduled runs)
    visibility_windows.py --ending 14         # also list what stops being visible within 14 nights

The weekly VPS cron job (install_visibility_cron.sh) runs it with
-a --ending 14 and emails the output via run_and_mail.py.

Needs the tables: dsodb/migrations/migrate_add_visibility_windows.py
"""
import argparse
import datetime as dt
import json
import sys
import time
from pathlib import Path
from zoneinfo import ZoneInfo

import numpy as np
from skyfield.api import Loader, wgs84
from skyfield.almanac import dark_twilight_day, find_discrete

HERE = Path(__file__).resolve().parent
sys.path.insert(0, str(HERE))
from db_connect import get_connection, get_driver  # noqa: E402

PROFILE_DIR = HERE / 'profiles'
DAYS_BACK = 183
DAYS_AHEAD = 370
STRIDE_MIN = 2
MIN_SPAN_MIN = 60
CHUNK_NIGHTS = 30
SPAN_URL = 'https://astro.wiibopp.com/vis/span/'
SAVE_LOCK = 734251  # Postgres advisory lock: one save at a time
PLANETS = [  # name, ephemeris key (same targets as todays_dsos_web.py, without the Moon)
    ('Mercury', 'mercury barycenter'),
    ('Venus', 'venus barycenter'),
    ('Mars', 'mars barycenter'),
    ('Jupiter', 'jupiter barycenter'),
    ('Saturn', 'saturn barycenter'),
    ('Uranus', 'uranus barycenter'),
    ('Neptune', 'neptune barycenter'),
]


def fmt(d):
    return f"{d.day} {d:%b %Y}"


def ask(question):
    try:
        return input(question + ' [y/N] ').strip().lower() in ('y', 'yes')
    except EOFError:
        return False


# ── Calculation ─────────────────────────────────────────────────────────────

def dark_windows(ts, eph, site, tz, first, last):
    """{evening date: (dark start, dark end)} for each night first..last."""
    t0 = ts.utc(first.year, first.month, first.day - 1, 12)
    t1 = ts.utc(last.year, last.month, last.day + 2, 12)
    times, events = find_discrete(t0, t1, dark_twilight_day(eph, site))
    nights = {}
    for i in range(len(times) - 1):
        if events[i] == 1 and events[i + 1] == 0:                # twilight -> dark
            start = times[i + 1]
            end = next((times[j + 1] for j in range(i + 1, len(times) - 1)
                        if events[j] == 0 and events[j + 1] == 1), None)
            if end is None:
                continue
            local = start.astimezone(tz)
            evening = local.date() if local.hour >= 12 else local.date() - dt.timedelta(days=1)
            if first <= evening <= last:
                nights[evening] = (start.tt, end.tt)
    return nights


def visible_nights(ts, eph, site, nights, dsos, planets, profiles):
    """For one site: {(object key, profile name): set of visible evening dates}.

    DSO alt/az comes from Skyfield's rotation from the celestial frame to the
    site's horizon frame, applied to each DSO's J2000 direction -- within
    about 0.01 deg of a full observe().apparent().altaz(), and far faster
    when hundreds of DSOs are checked on every night of a year and a half.
    Planets move, so they get a full observe() at every sample."""
    out = {(k, p['name']): set() for k in [d['key'] for d in dsos] + [n for n, _ in planets] for p in profiles}
    if dsos:
        ra = np.radians(np.array([d['ra'] for d in dsos]) * 15.0)
        dec = np.radians(np.array([d['dec'] for d in dsos]))
        u = np.stack([np.cos(dec) * np.cos(ra), np.cos(dec) * np.sin(ra), np.sin(dec)])  # (3, N)
    observer = eph['earth'] + site
    dates = sorted(nights)
    for c in range(0, len(dates), CHUNK_NIGHTS):
        chunk = dates[c:c + CHUNK_NIGHTS]
        tts, owner = [], []
        for k, d in enumerate(chunk):
            s, e = nights[d]
            n = max(2, int((e - s) * 1440 // STRIDE_MIN) + 1)
            tts.append(np.linspace(s, e, n))
            owner.append(np.full(n, k))
        t = ts.tt_jd(np.concatenate(tts))
        owner = np.concatenate(owner)
        bounds = np.searchsorted(owner, np.arange(len(chunk) + 1))

        if dsos:
            v = np.einsum('ijt,jn->tni', site.rotation_at(t), u)  # (T, N, 3)
            alt = np.degrees(np.arcsin(np.clip(v[..., 2], -1, 1)))
            az = np.degrees(np.arctan2(v[..., 1], v[..., 0])) % 360.0
            for p in profiles:
                ok = (alt >= p['min_altitude']) & (az >= p['az_min']) & (az <= p['az_max'])
                for k, d in enumerate(chunk):
                    seg = ok[bounds[k]:bounds[k + 1]]             # (samples, N)
                    anyv = seg.any(axis=0)
                    if not anyv.any():
                        continue
                    first_i = seg.argmax(axis=0)
                    last_i = seg.shape[0] - 1 - seg[::-1].argmax(axis=0)
                    span = (last_i - first_i) * STRIDE_MIN
                    for n in np.where(anyv & (span >= MIN_SPAN_MIN))[0]:
                        out[(dsos[n]['key'], p['name'])].add(d)

        if planets:
            here = observer.at(t)
            for name, body in planets:
                palt, paz, _ = here.observe(eph[body]).apparent().altaz()
                palt, paz = palt.degrees, paz.degrees
                for p in profiles:
                    ok = (palt >= p['min_altitude']) & (paz >= p['az_min']) & (paz <= p['az_max'])
                    hit = np.add.reduceat(ok.astype(np.int32), bounds[:-1]) > 0
                    for k in np.where(hit)[0]:
                        out[(name, p['name'])].add(chunk[k])
    return out


def to_windows(dates, first, last):
    """Runs of consecutive dates -> [(firstnight, lastnight, startsbefore, runspast)]."""
    runs, cur = [], None
    for d in sorted(dates):
        if cur and d == cur[1] + dt.timedelta(days=1):
            cur[1] = d
        else:
            if cur:
                runs.append(cur)
            cur = [d, d]
    if cur:
        runs.append(cur)
    return [(a, b, a == first, b == last) for a, b in runs]


def ending_report(new, names_by_key, within, profile_names):
    """Lines listing objects visible tonight whose window ends within `within`
    nights, most urgent first, with every profile where that's so."""
    soon = {}
    for kind in ('dso', 'planet'):
        for key, prof, a, b, _n, _sb, rp, first, _last in new[kind]:
            today = first + dt.timedelta(days=DAYS_BACK)
            if rp or not (a <= today <= b):
                continue
            left = (b - today).days + 1
            if left <= within:
                soon.setdefault(key, {})[prof] = (left, b)
    if not soon:
        return [f"Nothing visible now stops being visible within {within} nights."]
    lines = [f"Ending within {within} nights (nights left counts tonight):"]
    order = sorted(soon, key=lambda k: (min(v[0] for v in soon[k].values()), k))
    for key in order:
        name = names_by_key.get(key, key)
        label = name if name == key else f"{name} ({key})"
        parts = [f"{prof} {soon[key][prof][0]} night{'' if soon[key][prof][0] == 1 else 's'} (to {soon[key][prof][1].day} {soon[key][prof][1]:%b})"
                 for prof in profile_names if prof in soon[key]]
        lines.append(f"  {label}: " + ' · '.join(parts))
    return lines


# ── Main ────────────────────────────────────────────────────────────────────

def main():
    ap = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    ap.add_argument('objects', nargs='*', help='DSO keys and/or planet names (default: every DSO with a position, and every planet)')
    ap.add_argument('-p', '--profile', action='append', help='Profile name; repeat for more (default: every profile)')
    ap.add_argument('-a', '--apply', action='store_true', help='Save without asking')
    ap.add_argument('--what-if', action='store_true', help='Report only, never save')
    ap.add_argument('--ending', type=int, metavar='N',
                    help='Also list objects visible tonight that stop being visible within N nights')
    args = ap.parse_args()

    names = args.profile or sorted(f.stem for f in PROFILE_DIR.glob('*.json'))
    profiles = []
    for n in names:
        f = PROFILE_DIR / f'{n}.json'
        if not f.exists():
            sys.exit(f"No profile named '{n}' (looked for {f})")
        p = json.load(open(f))
        p['name'] = n
        profiles.append(p)

    planet_names = {n.upper(): (n, body) for n, body in PLANETS}
    wanted = {k.upper() for k in args.objects}
    planets = [planet_names[k] for k in planet_names if not wanted or k in wanted]
    wanted_dsos = wanted - set(planet_names)

    conn = get_connection()
    cur = conn.cursor()
    cur.execute("""
        SELECT DSOKey AS "DSOKey", CommonName AS "CommonName", RAHours AS "RAHours",
               DecDegrees AS "DecDegrees", ObjectTypeID AS "ObjectTypeID"
        FROM Objects ORDER BY DSOKey
    """)
    rows = cur.fetchall()
    found = {r['DSOKey'].upper() for r in rows}
    for k in sorted(wanted_dsos - found):
        print(f"No DSO or planet called {k}")
    dsos, skipped = [], []
    if not wanted or wanted_dsos:
        for r in rows:
            if wanted and r['DSOKey'].upper() not in wanted_dsos:
                continue
            if r['ObjectTypeID'] == 'SOLAR_SYSTEM':
                continue
            if r['RAHours'] is None or r['DecDegrees'] is None:
                skipped.append(r)
                continue
            dsos.append({'key': r['DSOKey'], 'name': r['CommonName'] or r['DSOKey'],
                         'ra': float(r['RAHours']), 'dec': float(r['DecDegrees'])})
    if not dsos and not planets:
        sys.exit("Nothing to calculate.")
    names_by_key = {d['key']: d['name'] for d in dsos}

    # Group profiles by site so each site's positions are worked out once
    sites = {}
    for p in profiles:
        sites.setdefault((p['latitude'], p['longitude'], p.get('timezone', 'America/Boise')), []).append(p)

    load = Loader(str(HERE), verbose=False)
    ts = load.timescale(builtin=True)
    eph = load('de421.bsp')
    started = time.time()
    new = {'dso': [], 'planet': []}   # rows: (key, profile, first, last, nights, startsbefore, runspast, rangefrom, rangeto)
    ranges = {}
    for (lat, lon, tzname), plist in sites.items():
        tz = ZoneInfo(tzname)
        today = dt.datetime.now(tz).date()
        first, last = today - dt.timedelta(days=DAYS_BACK), today + dt.timedelta(days=DAYS_AHEAD)
        site = wgs84.latlon(lat, lon)
        nights = dark_windows(ts, eph, site, tz, first, last)
        vis = visible_nights(ts, eph, site, nights, dsos, planets, plist)
        for p in plist:
            ranges[p['name']] = (first, last)
            for kind, keys in (('dso', [d['key'] for d in dsos]), ('planet', [n for n, _ in planets])):
                for key in keys:
                    for a, b, sb, rp in to_windows(vis[(key, p['name'])], first, last):
                        new[kind].append((key, p['name'], a, b, (b - a).days + 1, sb, rp, first, last))
    secs = time.time() - started

    # What's stored now for the same objects and profiles
    tables = {'dso': ('VisibilityWindows', 'DSOKey', [d['key'] for d in dsos]),
              'planet': ('PlanetVisibilityWindows', 'Planet', [n for n, _ in planets])}
    old = {}
    for kind, (table, col, keys) in tables.items():
        old[kind] = []
        if keys:
            cur.execute(f"""
                SELECT {col} AS "Key", Profile AS "Profile", FirstNight AS "FirstNight", LastNight AS "LastNight",
                       StartsBeforeRange AS "SB", RunsPastRange AS "RP", RangeFrom AS "RF", RangeTo AS "RT"
                FROM {table} WHERE Profile = ANY(?) AND {col} = ANY(?)
            """, (names, keys))
            old[kind] = cur.fetchall()

    old_range = {}
    for kind in old:
        for r in old[kind]:
            old_range[r['Profile']] = (r['RF'], r['RT'])

    def in_both_ranges(prof, a, b):
        """True if a window lies where both the old and new runs calculated --
        a difference outside that is just the range moving on."""
        if prof not in old_range:
            return True
        lo = max(old_range[prof][0], ranges[prof][0])
        hi = min(old_range[prof][1], ranges[prof][1])
        return a <= hi and b >= lo

    def norm(key, prof, a, b, sb, rp):
        return (key, prof, None if sb else a, None if rp else b)

    shown_add, shown_rem, changed, quiet = [], [], False, 0
    for kind in ('dso', 'planet'):
        old_n = {norm(r['Key'], r['Profile'], r['FirstNight'], r['LastNight'], r['SB'], r['RP']):
                 (r['Key'], r['Profile'], r['FirstNight'], r['LastNight'], r['SB'], r['RP']) for r in old[kind]}
        new_n = {norm(r[0], r[1], r[2], r[3], r[5], r[6]): (r[0], r[1], r[2], r[3], r[5], r[6]) for r in new[kind]}
        added = [new_n[k] for k in new_n.keys() - old_n.keys()]
        removed = [old_n[k] for k in old_n.keys() - new_n.keys()]
        changed = changed or bool(added or removed)
        sa = [r for r in added if in_both_ranges(r[1], r[2], r[3])]
        sr = [r for r in removed if in_both_ranges(r[1], r[2], r[3])]
        quiet += len(added) - len(sa) + len(removed) - len(sr)
        shown_add += sa
        shown_rem += sr

    n_dso, n_pl = len(new['dso']), len(new['planet'])
    plural = lambda n, word: f"{n} {word}{'' if n == 1 else 's'}"
    what = ', '.join(x for x in (plural(len(dsos), 'DSO') if dsos else '',
                                 plural(len(planets), 'planet') if planets else '') if x)
    print(f"Calculated {what} x {plural(len(profiles), 'profile')} ({', '.join(names)}) in {secs:.0f} s: "
          f"{n_dso + n_pl} visibility windows" + (f" ({n_pl} for planets)" if planets and dsos else '') + '.')
    for r in skipped:
        print(f"  Skipped {r['CommonName'] or r['DSOKey']} ({r['DSOKey']}): no RA/Dec")
    if args.ending is not None:
        print()
        print('\n'.join(ending_report(new, names_by_key, args.ending, names)))
        print(f"Full list: {SPAN_URL}")
        print()

    def line(key, prof, a, b, sb, rp):
        name = names_by_key.get(key, key)
        label = name if name == key else f"{name} ({key})"
        return f"  {label}, {prof}: {'before ' if sb else ''}{fmt(a)} - {fmt(b)}{' or later' if rp else ''}"

    if not old['dso'] and not old['planet']:
        print(f"Nothing stored yet for these objects and profiles -- would add all {n_dso + n_pl} windows.")
    else:
        if shown_rem:
            print("\nWindows that change or go away:")
            for r in sorted(shown_rem, key=lambda r: (r[1], r[0], r[2])):
                print(line(*r))
        if shown_add:
            print("\nNew or changed windows:")
            for r in sorted(shown_add, key=lambda r: (r[1], r[0], r[2])):
                print(line(*r))
        if quiet:
            print(f"\n{quiet} window(s) differ only because the date range moved on.")
        if not changed:
            print("No changes to the stored windows.")
            conn.close()
            return

    if args.what_if:
        print("\nReport only -- nothing saved.")
        conn.close()
        return
    if not args.apply and not ask("\nSave these windows?"):
        print("Nothing saved.")
        conn.close()
        return

    # DSO Admin starts a background run each time a DSO's RA/Dec is saved, so
    # two runs can overlap: take turns saving instead of colliding.
    if get_driver() == 'pgsql':
        cur.execute("SELECT pg_advisory_xact_lock(?)", (SAVE_LOCK,))
    for kind, (table, col, keys) in tables.items():
        if not keys:
            continue
        cur.execute(f"DELETE FROM {table} WHERE Profile = ANY(?) AND {col} = ANY(?)", (names, keys))
        if new[kind]:
            cur.executemany(f"""
                INSERT INTO {table} ({col}, Profile, FirstNight, LastNight, Nights,
                                     StartsBeforeRange, RunsPastRange, RangeFrom, RangeTo)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
            """, new[kind])
    conn.commit()
    conn.close()
    print(f"Saved {n_dso + n_pl} windows for {what} ({', '.join(names)}).")


if __name__ == '__main__':
    main()
