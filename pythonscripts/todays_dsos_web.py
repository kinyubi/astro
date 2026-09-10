"""
Calculates and lists deep-sky objects (DSOs) visible from a specified location on a given date.
Web version with sortable output: Outputs HTML for browser display with dropdown to change sort order.
"""
import datetime
import sqlite3
import numpy as np
import math
from zoneinfo import ZoneInfo
from skyfield.api import load, Topos, Star, Angle
from skyfield.almanac import dark_twilight_day, find_discrete, moon_phase
from skyfield.magnitudelib import planetary_magnitude
import sys
import json
import argparse
import time
from profile_manager import load_profile
from pathlib import Path
from db_connect import get_connection

# Derive DB path relative to this script: pythonscripts/ -> astro/ -> dsodb/astro.db
ASTRO_DB = Path(__file__).parent.parent / 'dsodb' / 'astro.db'

# No longer hardcoded - these come from profiles now
# See profile_manager.py for profile management
LOCATION_NAME = 'Star, Idaho'
LAT_DEG = 43.69
LON_DEG = -116.49
TIME_ZONE = 'America/Boise'
MIN_ALTITUDE_DEG = 25.0
AZ_MIN_DEG = 10.0  # Due North + 10 degrees
AZ_MAX_DEG = 160.0  # Due South - 20 degrees (Eastern Sky)

def get_viewing_window(specified_date, ts, eph, observer):
    """
    Determines the viewing window from astronomical twilight end to astronomical sunrise.
    """
    t0 = ts.utc(specified_date.year, specified_date.month, specified_date.day, 12)
    t1 = ts.utc(specified_date.year, specified_date.month, specified_date.day + 2, 12)

    f = dark_twilight_day(eph, observer)
    times, events = find_discrete(t0, t1, f)

    tz = ZoneInfo(TIME_ZONE)

    viewing_start = None
    viewing_end = None

    for i in range(len(times) - 1):
        t = times[i]
        event = events[i]
        next_event = events[i + 1]
        t_local = t.astimezone(tz)

        if event == 1 and next_event == 0 and viewing_start is None and t_local.date() >= specified_date:
            viewing_start = t

        if viewing_start is not None and event == 0 and next_event == 1 and viewing_end is None:
            viewing_end = times[i + 1]
            break

    return viewing_start, viewing_end


# ── Upcoming visibility forecast helpers ───────────────────────────────────────
# Used to find the next date a currently non-visible DSO will meet the same
# visibility criteria. Twilight/viewing-window times depend only on date and
# location (not on the object), so they're cached by day-offset and shared
# across every object's search to keep this affordable.

FORECAST_MAX_DAYS = 370
FORECAST_COARSE_STEP_DAYS = 7


def _get_cached_viewing_window(day_offset, base_date, ts, eph, observer, viewing_window_cache):
    """Returns (viewing_start, viewing_end) for base_date + day_offset, cached.

    Previously also built and returned a full 1-minute-resolution time array
    for the night, but neither caller needs it anymore now that both
    _is_object_visible_on_offset and _is_planet_visible_on_offset build their
    own coarse-stride sample times directly from v_start/v_end -- keeping it
    here was pure wasted work."""
    if day_offset in viewing_window_cache:
        return viewing_window_cache[day_offset]

    check_date = base_date + datetime.timedelta(days=day_offset)
    v_start, v_end = get_viewing_window(check_date, ts, eph, observer)

    if v_start is None or v_end is None:
        viewing_window_cache[day_offset] = (None, None)
        return viewing_window_cache[day_offset]

    duration_minutes = int((v_end.utc_datetime() - v_start.utc_datetime()).total_seconds() / 60)
    if duration_minutes < 1:
        viewing_window_cache[day_offset] = (None, None)
        return viewing_window_cache[day_offset]

    viewing_window_cache[day_offset] = (v_start, v_end)
    return viewing_window_cache[day_offset]


def _binary_refine_boundary(observer_pos, target, ts, t_visible, t_not_visible, min_alt, az_min, az_max,
                             fine_stride_min=2):
    """
    Narrows a visibility transition between t_visible (criteria met) and
    t_not_visible (criteria not met) -- one coarse stride apart -- via
    binary search: check the midpoint, keep halving the gap toward whichever
    side it falls on (e.g. 16 -> 8 -> 4 -> 2 minutes) until the gap is
    within fine_stride_min. Returns the tightest known-visible time found.
    """
    lo, hi = t_visible, t_not_visible
    gap_minutes = abs((hi.tt - lo.tt) * 24 * 60)
    while gap_minutes > fine_stride_min:
        mid = ts.tt_jd((lo.tt + hi.tt) / 2.0)
        astrometric = observer_pos.at(mid).observe(target).apparent()
        alt, az, _ = astrometric.altaz()
        if (alt.degrees >= min_alt) and (az.degrees >= az_min) and (az.degrees <= az_max):
            lo = mid
        else:
            hi = mid
        gap_minutes = abs((hi.tt - lo.tt) * 24 * 60)
    return lo


def _is_object_visible_on_offset(star, observer_pos, day_offset, base_date, ts, eph, observer,
                                  min_alt, az_min, az_max, viewing_window_cache,
                                  coarse_stride_min=16, fine_stride_min=2):
    """Returns True if `star` meets the visibility criteria (duration >= 60 min) on base_date + day_offset.

    Samples the night at a coarse_stride_min-minute stride (16 min default)
    instead of the full 1-minute resolution -- each sampled point is a real
    per-point astrometry cost, repeated for every DSO and every day checked
    during forecast scanning, so this was the single biggest lever for
    forecast_loop's cost (roughly 16x fewer points evaluated per call).
    Unlike a pure visible/not-visible boolean, this DOES need real boundary
    precision -- the 60-minute duration threshold can't be judged from
    16-minute-resolution samples alone -- so on a coarse hit, the start and
    end boundaries are each refined via binary search (halving the gap:
    16 -> 8 -> 4 -> 2 minutes) rather than left at coarse resolution.
    """
    v_start, v_end = _get_cached_viewing_window(day_offset, base_date, ts, eph, observer, viewing_window_cache)
    if v_start is None:
        return False

    total_minutes = int((v_end.utc_datetime() - v_start.utc_datetime()).total_seconds() / 60)
    if total_minutes < 1:
        return False

    n_samples = max(2, total_minutes // coarse_stride_min + 1)
    coarse_times = ts.linspace(v_start, v_end, n_samples)

    astrometric = observer_pos.at(coarse_times).observe(star)
    alt, az, _ = astrometric.apparent().altaz()

    is_vis = (alt.degrees >= min_alt) & (az.degrees >= az_min) & (az.degrees <= az_max)
    visible_indices = np.where(is_vis)[0]
    if len(visible_indices) == 0:
        return False

    start_idx = visible_indices[0]
    end_idx = visible_indices[-1]

    # If the coarse hit is at the very first/last sample, there's no
    # "not visible" neighbor on that side within the window to refine
    # against -- use the coarse sample as-is (matches the window edge).
    start_time = coarse_times[start_idx]
    if start_idx > 0:
        start_time = _binary_refine_boundary(
            observer_pos, star, ts, coarse_times[start_idx], coarse_times[start_idx - 1],
            min_alt, az_min, az_max, fine_stride_min
        )

    end_time = coarse_times[end_idx]
    if end_idx < len(coarse_times) - 1:
        end_time = _binary_refine_boundary(
            observer_pos, star, ts, coarse_times[end_idx], coarse_times[end_idx + 1],
            min_alt, az_min, az_max, fine_stride_min
        )

    span_minutes = (end_time.tt - start_time.tt) * 24 * 60
    return span_minutes >= 60


def find_visibility_window(star, base_date, max_days, ts, eph, observer, observer_pos,
                            min_alt, az_min, az_max, viewing_window_cache):
    """
    Searches forward from base_date (exclusive) up to max_days for the next date
    the object meets the visibility criteria, then finds the last consecutive date
    of that visibility run. Uses a coarse step first, then refines day-by-day.

    Returns (first_visible_date, last_visible_date) as datetime.date objects,
    or (None, None) if nothing is found within max_days.
    """
    def visible_on(offset):
        return _is_object_visible_on_offset(star, observer_pos, offset, base_date, ts, eph, observer,
                                             min_alt, az_min, az_max, viewing_window_cache)

    # Coarse search for the first visible day offset
    first_offset = None
    prev_offset = 0
    offset = FORECAST_COARSE_STEP_DAYS
    while offset <= max_days:
        if visible_on(offset):
            first_offset = offset
            break
        prev_offset = offset
        offset += FORECAST_COARSE_STEP_DAYS

    if first_offset is None:
        return None, None

    # Refine backward to find the exact first visible day
    exact_first = first_offset
    for d in range(first_offset - 1, prev_offset, -1):
        if visible_on(d):
            exact_first = d
        else:
            break

    # Coarse search forward from exact_first to find approx end of the visibility run
    last_known_visible = exact_first
    offset = exact_first + FORECAST_COARSE_STEP_DAYS
    while offset <= max_days:
        if visible_on(offset):
            last_known_visible = offset
            offset += FORECAST_COARSE_STEP_DAYS
        else:
            break

    # Refine forward to find the exact last visible day
    exact_last = last_known_visible
    upper_bound = min(offset, max_days)
    for d in range(last_known_visible + 1, upper_bound + 1):
        if visible_on(d):
            exact_last = d
        else:
            break

    first_date = base_date + datetime.timedelta(days=exact_first)
    last_date = base_date + datetime.timedelta(days=exact_last)
    return first_date, last_date


# ── Planets & Moon helpers ──────────────────────────────────────────────────
# Planets aren't in the Objects table (their RA/Dec change constantly, unlike
# catalog DSOs) -- their positions come straight from the ephemeris already
# loaded for twilight calculations, not the database.

PLANET_TARGETS = [
    ('Moon', 'moon'),
    ('Mercury', 'mercury barycenter'),
    ('Venus', 'venus barycenter'),
    ('Mars', 'mars barycenter'),
    ('Jupiter', 'jupiter barycenter'),
    ('Saturn', 'saturn barycenter'),
    ('Uranus', 'uranus barycenter'),
    ('Neptune', 'neptune barycenter'),
]

MOON_PHASE_NAMES = ['New Moon', 'Waxing Crescent', 'First Quarter', 'Waxing Gibbous',
                     'Full Moon', 'Waning Gibbous', 'Last Quarter', 'Waning Crescent']


def _compute_planet_window(alt_deg, az_deg, time_range, tz, min_alt, az_min, az_max):
    """Given alt/az degree arrays across time_range, returns
    (start_local, end_local, duration_minutes, start_alt, start_az, end_alt,
    end_az) for the span from the first to the last moment meeting the given
    alt/az criteria -- same convention used for DSOs and alignment stars
    elsewhere in this script. Returns None if the criteria are never met
    during time_range."""
    is_vis = (alt_deg >= min_alt) & (az_deg >= az_min) & (az_deg <= az_max)
    visible_indices = np.where(is_vis)[0]
    if len(visible_indices) == 0:
        return None
    start_idx = visible_indices[0]
    end_idx = visible_indices[-1]
    obj_start = time_range[start_idx].astimezone(tz)
    obj_end = time_range[end_idx].astimezone(tz)
    duration = (obj_end - obj_start).total_seconds() / 60
    return (obj_start, obj_end, duration,
            float(alt_deg[start_idx]), float(az_deg[start_idx]),
            float(alt_deg[end_idx]), float(az_deg[end_idx]))


def _is_planet_visible_on_offset(target_key, observer_pos, day_offset, base_date, ts, eph, observer,
                                  min_alt, az_min, az_max, viewing_window_cache, coarse_stride_min=16):
    """Returns True if the planet at target_key meets the alt/az criteria at
    any point during base_date + day_offset's viewing window. No minimum
    duration -- matches the "show any window" rule used for tonight's planet
    visibility.

    Samples the night at a coarse_stride_min-minute stride (16 min default)
    instead of the full 1-minute resolution. This only needs a yes/no answer
    -- unlike the DSO duration check, there's no threshold to pin down
    precisely -- so the moment any coarse sample hits, that's the answer;
    no boundary refinement is needed or performed. Roughly 16x fewer points
    evaluated per day checked than the previous full-resolution scan."""
    v_start, v_end = _get_cached_viewing_window(day_offset, base_date, ts, eph, observer, viewing_window_cache)
    if v_start is None:
        return False
    total_minutes = int((v_end.utc_datetime() - v_start.utc_datetime()).total_seconds() / 60)
    if total_minutes < 1:
        return False
    n_samples = max(2, total_minutes // coarse_stride_min + 1)
    coarse_times = ts.linspace(v_start, v_end, n_samples)
    body = eph[target_key]
    astrometric = observer_pos.at(coarse_times).observe(body).apparent()
    alt, az, _ = astrometric.altaz()
    is_vis = (alt.degrees >= min_alt) & (az.degrees >= az_min) & (az.degrees <= az_max)
    return bool(np.any(is_vis))


def find_planet_next_visible_date(target_key, base_date, max_days, ts, eph, observer, observer_pos,
                                   min_alt, az_min, az_max, viewing_window_cache):
    """Returns the next date after base_date (exclusive) that the planet at
    target_key meets the alt/az criteria at some point during that night.

    Uses the same coarse-then-refine search as find_visibility_window (step
    every FORECAST_COARSE_STEP_DAYS days, then refine backward day-by-day)
    instead of a plain linear day-by-day scan. The linear scan was the single
    biggest cost in the whole report on nights where planets aren't visible:
    up to max_days individual full-resolution alt/az evaluations per planet
    per view (restricted + unrestricted) -- up to 16x that across all 8
    planets, each one a real per-call astrometry cost (light-time iteration
    etc.), not something a cache can remove since the position is planet-
    and day-specific. Coarse-then-refine cuts the worst case to roughly
    max_days/FORECAST_COARSE_STEP_DAYS + FORECAST_COARSE_STEP_DAYS per
    planet per view -- a several-fold reduction that gets bigger as
    max_days grows.

    Like find_visibility_window, this assumes visibility trends smoothly
    within a coarse step (true here -- planet alt/az drifts slowly day to
    day). A planet visible for only a single day buried in the middle of an
    otherwise-invisible week could be missed by up to
    FORECAST_COARSE_STEP_DAYS-1 days; acceptable for a several-month-ahead
    forecast display, same tradeoff already accepted for the DSO forecast.
    Returns None if not found within max_days.
    """
    def visible_on(offset):
        return _is_planet_visible_on_offset(target_key, observer_pos, offset, base_date, ts, eph, observer,
                                             min_alt, az_min, az_max, viewing_window_cache)

    first_offset = None
    prev_offset = 0
    offset = FORECAST_COARSE_STEP_DAYS
    while offset <= max_days:
        if visible_on(offset):
            first_offset = offset
            break
        prev_offset = offset
        offset += FORECAST_COARSE_STEP_DAYS

    if first_offset is None:
        return None

    # Refine backward to find the exact first visible day within this coarse step
    exact_first = first_offset
    for d in range(first_offset - 1, prev_offset, -1):
        if visible_on(d):
            exact_first = d
        else:
            break

    return base_date + datetime.timedelta(days=exact_first)


def calculate_visibility(specified_date=None, profile_name='default', show_all=False):
    """
    Main function to calculate visibility of objects and output HTML with sorting capability.
    
    Args:
        specified_date: datetime.date object or None for today
        profile_name: Name of location profile to use
        show_all: If False (default), only the core DSO report is computed/shown --
            the main visible-tonight table plus the Visibility Dates forecast.
            Alignment Stars and Planets & Moon are skipped entirely (not just
            hidden) to keep the everyday report fast. If True, the full report
            is computed, same as this script's original behavior.
    """
    if specified_date is None:
        specified_date = datetime.date.today()

    # ── Timing instrumentation ──────────────────────────────────────────────
    # Logs a per-phase breakdown to dso_visibility.log on every run so slow
    # requests can be diagnosed without guessing. Also surfaced as an HTML
    # comment near the top of the output for a quick "View Source" check.
    _timings = []
    _t_prev = time.time()

    def _lap(label):
        nonlocal _t_prev
        now = time.time()
        _timings.append((label, now - _t_prev))
        _t_prev = now

    # Load profile
    profile = load_profile(profile_name)
    if profile is None:
        print(f"<p>Error: Could not load profile '{profile_name}'</p>")
        return
    _lap('load_profile')
    
    # Extract settings from profile
    location_name = profile['location']
    latitude = profile['latitude']
    longitude = profile['longitude']
    time_zone = profile['timezone']
    minimum_altitude = profile['min_altitude']
    azimuth_minimum_degrees = profile['az_min']
    azimuth_maximum_degrees = profile['az_max']

    # Setup Skyfield
    ts = load.timescale(builtin=True)
    eph = load('de421.bsp')
    observer = Topos(latitude, longitude)
    earth = eph['earth']
    observer_pos = earth + observer

    tz = ZoneInfo(time_zone)
    _lap('skyfield_setup')

    # Get viewing window
    viewing_start, viewing_end = get_viewing_window(specified_date, ts, eph, observer)

    if viewing_start is None or viewing_end is None:
        print("<p>Error: Could not determine astronomical twilight times.</p>")
        return

    start_local = viewing_start.astimezone(tz)
    end_local = viewing_end.astimezone(tz)

    # Create time array (1-minute intervals)
    duration_minutes = int((viewing_end.utc_datetime() - viewing_start.utc_datetime()).total_seconds() / 60)
    time_range = ts.linspace(viewing_start, viewing_end, duration_minutes)
    _lap('viewing_window')

    visible_objects = []
    planets_restricted = []
    planets_unrestricted = []

    try:
        log = []
        conn = get_connection()
        cur = conn.cursor()
        cur.execute("""
            SELECT
                o.DSOKey       AS "DSOKey",
                o.CommonName   AS "CommonName",
                ot.TypeName    AS "TypeDesc",
                con.Name       AS "Constellation",
                o.Magnitude    AS "Magnitude",
                o.RAHours      AS "RAHours",
                o.DecDegrees   AS "DecDegrees",
                o.WantBetter   AS "WantBetter",
                o.SqArcMins    AS "SqArcMins"
            FROM Objects o
            LEFT JOIN ObjectTypes  ot  ON o.ObjectTypeID  = ot.ObjectTypeID
            LEFT JOIN Constellations con ON o.ConstellationID = con.ConstellationID
            WHERE o.RAHours IS NOT NULL
              AND o.DecDegrees IS NOT NULL
              AND o.ObjectTypeID NOT IN ('SOLAR_SYSTEM', 'SINGLE_STAR', 'DOUBLE_STAR', 'VARIABLE_STAR')
        """)
        dso_rows = cur.fetchall()

        # Alignment-star rows are only needed for the Alignment Stars section,
        # which is skipped entirely when show_all is False -- no reason to
        # even query for them on the fast, default path.
        if show_all:
            cur.execute("""
                SELECT
                    o.DSOKey       AS "DSOKey",
                    o.CommonName   AS "CommonName",
                    con.Name       AS "Constellation",
                    o.Magnitude    AS "Magnitude",
                    o.RAHours      AS "RAHours",
                    o.DecDegrees   AS "DecDegrees"
                FROM Objects o
                LEFT JOIN Constellations con ON o.ConstellationID = con.ConstellationID
                WHERE o.RAHours IS NOT NULL
                  AND o.DecDegrees IS NOT NULL
                  AND o.ObjectTypeID IN ('SINGLE_STAR', 'DOUBLE_STAR', 'VARIABLE_STAR')
            """)
            star_rows = cur.fetchall()
        else:
            star_rows = []
        conn.close()
        _lap('db_fetch')

        for row in dso_rows:
            name      = row['DSOKey']
            aka       = row['CommonName'] or name
            type_desc = row['TypeDesc'] or ''
            constellation = row['Constellation'] or ''
            magnitude = row['Magnitude'] or 0.0
            do_me     = '&#9733;' if row['WantBetter'] else ''

            try:
                star = Star(ra=Angle(hours=float(row['RAHours'])),
                            dec=Angle(degrees=float(row['DecDegrees'])))
            except Exception as e:
                log.append(f"Error building star for {name}: {e}")
                continue

            astrometric = observer_pos.at(time_range).observe(star)
            alt, az, _ = astrometric.apparent().altaz()

            is_visible = (alt.degrees >= minimum_altitude) & \
                         (az.degrees >= azimuth_minimum_degrees) & \
                         (az.degrees <= azimuth_maximum_degrees)

            visible_indices = np.where(is_visible)[0]

            if len(visible_indices) > 0:
                start_idx = visible_indices[0]
                end_idx   = visible_indices[-1]

                obj_start = time_range[start_idx].astimezone(tz)
                obj_end   = time_range[end_idx].astimezone(tz)
                time_span = (obj_end - obj_start).total_seconds() / 60
                start_minutes = obj_start.hour * 60 + obj_start.minute
                if obj_start.hour < 12:
                    start_minutes += 24 * 60
                end_minutes = obj_end.hour * 60 + obj_end.minute
                if obj_end.hour < 12:
                    end_minutes += 24 * 60

                start_alt = alt.degrees[start_idx]
                start_az  = az.degrees[start_idx]
                end_alt   = alt.degrees[end_idx]
                end_az    = az.degrees[end_idx]

                if time_span >= 60:
                    visible_objects.append({
                        'do_me': do_me,
                        'name': name,
                        'aka': aka,
                        'start': obj_start,
                        'start_minutes': start_minutes,
                        'end': obj_end,
                        'end_minutes': end_minutes,
                        'duration': time_span,
                        'magnitude': magnitude,
                        'constellation': constellation,
                        'type_desc': type_desc,
                        'start_alt': start_alt,
                        'start_az': start_az,
                        'end_alt': end_alt,
                        'end_az': end_az,
                        'sq_arcmins': row['SqArcMins']
                    })
        _lap('dso_visibility_loop')

        # ── Alignment Stars — same viewing-window logic, separate list ──────
        # Skipped entirely on the default (non-/all) path -- this section
        # isn't shown there, so there's no reason to spend the per-star
        # astrometry cost computing it.
        visible_stars = []
        if show_all:
            for row in star_rows:
                name          = row['DSOKey']
                aka           = row['CommonName'] or name
                constellation = row['Constellation'] or ''
                magnitude     = row['Magnitude'] or 0.0

                try:
                    star = Star(ra=Angle(hours=float(row['RAHours'])),
                                dec=Angle(degrees=float(row['DecDegrees'])))
                except Exception as e:
                    log.append(f"Error building star for {name}: {e}")
                    continue

                astrometric = observer_pos.at(time_range).observe(star)
                alt, az, _ = astrometric.apparent().altaz()

                is_visible = (alt.degrees >= minimum_altitude) & \
                             (az.degrees >= azimuth_minimum_degrees) & \
                             (az.degrees <= azimuth_maximum_degrees)

                visible_indices = np.where(is_visible)[0]

                if len(visible_indices) > 0:
                    start_idx = visible_indices[0]
                    end_idx   = visible_indices[-1]

                    obj_start = time_range[start_idx].astimezone(tz)
                    obj_end   = time_range[end_idx].astimezone(tz)
                    time_span = (obj_end - obj_start).total_seconds() / 60
                    start_minutes = obj_start.hour * 60 + obj_start.minute
                    if obj_start.hour < 12:
                        start_minutes += 24 * 60
                    end_minutes = obj_end.hour * 60 + obj_end.minute
                    if obj_end.hour < 12:
                        end_minutes += 24 * 60

                    start_alt = alt.degrees[start_idx]
                    start_az  = az.degrees[start_idx]
                    end_alt   = alt.degrees[end_idx]
                    end_az    = az.degrees[end_idx]

                    if time_span >= 60:
                        visible_stars.append({
                            'name': name,
                            'aka': aka,
                            'start': obj_start,
                            'start_minutes': start_minutes,
                            'end': obj_end,
                            'end_minutes': end_minutes,
                            'duration': time_span,
                            'magnitude': magnitude,
                            'constellation': constellation,
                            'start_alt': start_alt,
                            'start_az': start_az,
                            'end_alt': end_alt,
                            'end_az': end_az,
                        })
        _lap('star_visibility_loop')

        # ── Planets & Moon ──────────────────────────────────────────────────
        # Two views: "restricted" uses this profile's own alt/az criteria
        # (same as everything else in this report); "unrestricted" ignores
        # azimuth entirely and just asks whether it clears 25° altitude
        # anywhere in the sky. No minimum-duration cutoff (unlike DSOs/stars
        # above) -- even a brief window is shown.
        #
        # Skipped entirely on the default (non-/all) path -- this is the
        # single most expensive section of the report (find_planet_next_visible_date
        # is a real up-to-FORECAST_MAX_DAYS-day forward search per planet per view whenever
        # that planet isn't visible tonight), and it isn't shown outside /all.
        #
        # When show_all is True, next-visible-date results are cached in
        # PlanetVisibilityForecast (mirrors DSOs' VisibilityForecast table)
        # so repeated /all runs don't re-run that forward search every time --
        # only once the previously predicted next-visible date has arrived.
        if show_all:
            UNRESTRICTED_MIN_ALT = 25.0
            UNRESTRICTED_AZ_MIN = 0.0
            UNRESTRICTED_AZ_MAX = 360.0

            mid_time = time_range[len(time_range) // 2]
            planet_viewing_window_cache = {}

            planet_forecast_conn = get_connection()
            planet_forecast_conn.execute("""
                CREATE TABLE IF NOT EXISTS PlanetVisibilityForecast (
                    ProfileName     TEXT NOT NULL,
                    PlanetName      TEXT NOT NULL,
                    ViewType        TEXT NOT NULL,
                    ComputedDate    TEXT NOT NULL,
                    NextVisibleDate TEXT,
                    SearchDays      INTEGER NOT NULL DEFAULT 370,
                    PRIMARY KEY (ProfileName, PlanetName, ViewType)
                )
            """)
            pfcur = planet_forecast_conn.cursor()
            target_date_iso_planets = specified_date.strftime('%Y-%m-%d')

            def _get_planet_next_visible(target_key, planet_name, view_type,
                                          min_alt, az_min, az_max):
                """Cached wrapper around find_planet_next_visible_date. Reuses
                the cached NextVisibleDate as long as specified_date hasn't
                reached it yet -- once we're at or past that date, either
                tonight's direct check above already found it visible (this
                function is only called when it didn't), or the prediction
                needs to be recomputed for real."""
                pfcur.execute(
                    "SELECT NextVisibleDate AS \"NextVisibleDate\" FROM PlanetVisibilityForecast "
                    "WHERE ProfileName=? AND PlanetName=? AND ViewType=?",
                    (profile_name, planet_name, view_type)
                )
                cached = pfcur.fetchone()

                if cached and cached['NextVisibleDate']:
                    cached_next = datetime.datetime.strptime(cached['NextVisibleDate'], '%Y-%m-%d').date()
                    if specified_date < cached_next:
                        return cached_next

                next_date = find_planet_next_visible_date(
                    target_key, specified_date, FORECAST_MAX_DAYS, ts, eph, observer, observer_pos,
                    min_alt, az_min, az_max, planet_viewing_window_cache
                )
                pfcur.execute("""
                    INSERT INTO PlanetVisibilityForecast (ProfileName, PlanetName, ViewType, ComputedDate, NextVisibleDate, SearchDays)
                    VALUES (?, ?, ?, ?, ?, ?)
                    ON CONFLICT(ProfileName, PlanetName, ViewType) DO UPDATE SET
                        ComputedDate = excluded.ComputedDate,
                        NextVisibleDate = excluded.NextVisibleDate,
                        SearchDays = excluded.SearchDays
                """, (
                    profile_name, planet_name, view_type, target_date_iso_planets,
                    next_date.strftime('%Y-%m-%d') if next_date else None,
                    FORECAST_MAX_DAYS
                ))
                return next_date

            for planet_name, target_key in PLANET_TARGETS:
                try:
                    body = eph[target_key]
                except (KeyError, ValueError) as e:
                    log.append(f"Planet target not found in ephemeris: {target_key} ({e})")
                    continue

                astrometric_series = observer_pos.at(time_range).observe(body).apparent()
                alt_p, az_p, _ = astrometric_series.altaz()

                # Magnitude and (Moon-only) phase barely change over a single
                # night -- one representative value at the mid-window time is
                # enough, same approach as everything else in this report using
                # a single static value per object per night.
                mid_astrometric = observer_pos.at(mid_time).observe(body).apparent()
                try:
                    planet_magnitude = float(planetary_magnitude(mid_astrometric))
                except Exception as e:
                    planet_magnitude = None
                    log.append(f"Magnitude lookup failed for {planet_name}: {e}")

                # Diagnostic Alt/Az at the same mid-window reference time, shown
                # for every row (visible or not) -- helps confirm the underlying
                # alt/az calculation is producing sane values.
                try:
                    mid_alt, mid_az, _ = mid_astrometric.altaz()
                    current_alt = round(float(mid_alt.degrees), 1)
                    current_az = round(float(mid_az.degrees), 1)
                    log.append(f"{planet_name} alt/az at mid-window ({mid_time.utc_iso()}): "
                               f"alt={current_alt}, az={current_az}")
                except Exception as e:
                    current_alt = None
                    current_az = None
                    log.append(f"Alt/az lookup failed for {planet_name}: {e}")

                phase_pct = None
                phase_name = None
                if planet_name == 'Moon':
                    try:
                        phase_angle_deg = moon_phase(eph, mid_time).degrees
                        phase_pct = round((1 - math.cos(math.radians(phase_angle_deg))) / 2 * 100)
                        phase_name = MOON_PHASE_NAMES[int(((phase_angle_deg + 22.5) % 360) // 45)]
                    except Exception as e:
                        log.append(f"Moon phase lookup failed: {e}")

                restricted_window = _compute_planet_window(
                    alt_p.degrees, az_p.degrees, time_range, tz,
                    minimum_altitude, azimuth_minimum_degrees, azimuth_maximum_degrees
                )
                if restricted_window:
                    obj_start, obj_end, duration, start_alt, start_az, end_alt, end_az = restricted_window
                    planets_restricted.append({
                        'name': planet_name, 'visible_tonight': True,
                        'start': obj_start, 'end': obj_end, 'duration': duration,
                        'start_alt': start_alt, 'start_az': start_az,
                        'end_alt': end_alt, 'end_az': end_az,
                        'magnitude': planet_magnitude, 'alt': current_alt, 'az': current_az,
                        'phase_pct': phase_pct, 'phase_name': phase_name,
                        'next_visible': None,
                    })
                else:
                    next_date = _get_planet_next_visible(
                        target_key, planet_name, 'restricted',
                        minimum_altitude, azimuth_minimum_degrees, azimuth_maximum_degrees
                    )
                    planets_restricted.append({
                        'name': planet_name, 'visible_tonight': False,
                        'start': None, 'end': None, 'duration': None,
                        'start_alt': None, 'start_az': None, 'end_alt': None, 'end_az': None,
                        'magnitude': planet_magnitude, 'alt': current_alt, 'az': current_az,
                        'phase_pct': phase_pct, 'phase_name': phase_name,
                        'next_visible': next_date.strftime('%Y-%m-%d') if next_date else None,
                    })

                unrestricted_window = _compute_planet_window(
                    alt_p.degrees, az_p.degrees, time_range, tz,
                    UNRESTRICTED_MIN_ALT, UNRESTRICTED_AZ_MIN, UNRESTRICTED_AZ_MAX
                )
                if unrestricted_window:
                    obj_start, obj_end, duration, start_alt, start_az, end_alt, end_az = unrestricted_window
                    planets_unrestricted.append({
                        'name': planet_name, 'visible_tonight': True,
                        'start': obj_start, 'end': obj_end, 'duration': duration,
                        'start_alt': start_alt, 'start_az': start_az,
                        'end_alt': end_alt, 'end_az': end_az,
                        'magnitude': planet_magnitude, 'alt': current_alt, 'az': current_az,
                        'phase_pct': phase_pct, 'phase_name': phase_name,
                        'next_visible': None,
                    })
                else:
                    next_date = _get_planet_next_visible(
                        target_key, planet_name, 'unrestricted',
                        UNRESTRICTED_MIN_ALT, UNRESTRICTED_AZ_MIN, UNRESTRICTED_AZ_MAX
                    )
                    planets_unrestricted.append({
                        'name': planet_name, 'visible_tonight': False,
                        'start': None, 'end': None, 'duration': None,
                        'start_alt': None, 'start_az': None, 'end_alt': None, 'end_az': None,
                        'magnitude': planet_magnitude, 'alt': current_alt, 'az': current_az,
                        'phase_pct': phase_pct, 'phase_name': phase_name,
                        'next_visible': next_date.strftime('%Y-%m-%d') if next_date else None,
                    })

            planet_forecast_conn.commit()
            planet_forecast_conn.close()
        _lap('planets_loop')

        if log:
            with open('dso_visibility.log', 'a') as log_file:
                for entry in log:
                    log_file.write(f"{datetime.datetime.now().isoformat()} - {entry}\n")
    except Exception as e:
        print(f"<p>Error reading data: {e}</p>")
        return

    # ── Visibility Dates table — all DSOs (visible tonight + upcoming) ──────────────
    # Part of the core DSO report (same as the main visible-tonight table),
    # so this always runs regardless of show_all -- only Alignment Stars and
    # Planets & Moon are held back for /all. Each DSO's range is cached in
    # VisibilityForecast and only recomputed once the cached window has
    # fully elapsed, so this stays cheap on the default path too.
    forecast_objects = []
    try:
        visible_names = {o['name'] for o in visible_objects}
        target_date_iso = specified_date.strftime('%Y-%m-%d')

        forecast_conn = get_connection()
        forecast_conn.execute("""
            CREATE TABLE IF NOT EXISTS VisibilityForecast (
                ProfileName      TEXT NOT NULL,
                DSOKey           TEXT NOT NULL,
                ComputedDate     TEXT NOT NULL,
                FirstVisibleDate TEXT,
                LastVisibleDate  TEXT,
                SearchDays       INTEGER NOT NULL DEFAULT 370,
                PRIMARY KEY (ProfileName, DSOKey)
            )
        """)
        fcur = forecast_conn.cursor()

        viewing_window_cache = {}

        for row in dso_rows:
            name = row['DSOKey']

            # Objects visible tonight: first = today, reuse or compute season end.
            # Previously this branch always called find_visibility_window() (a full
            # coarse-then-refine search up to FORECAST_MAX_DAYS days out) on EVERY request for
            # EVERY object visible that night, regardless of whether the season-end
            # date was already known and still valid -- the main cost driver on
            # nights with many visible objects. Now checks VisibilityForecast first,
            # same "only recompute once the cached window has fully elapsed" rule
            # already used below for objects not visible tonight.
            if name in visible_names:
                fcur.execute(
                    "SELECT LastVisibleDate AS \"LastVisibleDate\" FROM VisibilityForecast WHERE ProfileName=? AND DSOKey=?",
                    (profile_name, name)
                )
                cached = fcur.fetchone()

                last_date = None
                need_compute = True
                if cached and cached['LastVisibleDate']:
                    cached_last = datetime.datetime.strptime(cached['LastVisibleDate'], '%Y-%m-%d').date()
                    if specified_date <= cached_last:
                        last_date = cached_last
                        need_compute = False

                if need_compute:
                    try:
                        star = Star(ra=Angle(hours=float(row['RAHours'])),
                                    dec=Angle(degrees=float(row['DecDegrees'])))
                    except Exception:
                        continue
                    _, last_date = find_visibility_window(
                        star, specified_date - datetime.timedelta(days=1),
                        FORECAST_MAX_DAYS, ts, eph, observer, observer_pos,
                        minimum_altitude, azimuth_minimum_degrees, azimuth_maximum_degrees,
                        viewing_window_cache
                    )
                    fcur.execute("""
                        INSERT INTO VisibilityForecast (ProfileName, DSOKey, ComputedDate, FirstVisibleDate, LastVisibleDate, SearchDays)
                        VALUES (?, ?, ?, ?, ?, ?)
                        ON CONFLICT(ProfileName, DSOKey) DO UPDATE SET
                            ComputedDate = excluded.ComputedDate,
                            FirstVisibleDate = excluded.FirstVisibleDate,
                            LastVisibleDate = excluded.LastVisibleDate,
                            SearchDays = excluded.SearchDays
                    """, (
                        profile_name, name, target_date_iso,
                        target_date_iso,
                        last_date.strftime('%Y-%m-%d') if last_date else None,
                        FORECAST_MAX_DAYS
                    ))

                forecast_objects.append({
                    'do_me': '&#9733;' if row['WantBetter'] else '',
                    'name': name,
                    'aka': row['CommonName'] or name,
                    'first_visible': target_date_iso,
                    'last_visible': last_date.strftime('%Y-%m-%d') if last_date else target_date_iso,
                    'first_visible_sort': specified_date.toordinal(),
                    'visible_tonight': True,
                    'no_window': False,
                })
                continue

            # Not visible tonight: look up or compute next window
            fcur.execute(
                "SELECT FirstVisibleDate AS \"FirstVisibleDate\", LastVisibleDate AS \"LastVisibleDate\", "
                "ComputedDate AS \"ComputedDate\", SearchDays AS \"SearchDays\" "
                "FROM VisibilityForecast WHERE ProfileName=? AND DSOKey=?",
                (profile_name, name)
            )
            cached = fcur.fetchone()

            first_date = None
            last_date = None
            need_compute = True

            if cached and cached['FirstVisibleDate']:
                cached_first = datetime.datetime.strptime(cached['FirstVisibleDate'], '%Y-%m-%d').date()
                cached_last = (datetime.datetime.strptime(cached['LastVisibleDate'], '%Y-%m-%d').date()
                               if cached['LastVisibleDate'] else None)
                # Reuse the cached window as long as we haven't passed the end of it.
                # Only recompute once the window has fully elapsed (i.e. next year's
                # window is needed) -- so each DSO's range is calculated once per
                # season instead of on every report run.
                if cached_last is not None and specified_date <= cached_last:
                    first_date = cached_first
                    last_date = cached_last
                    need_compute = False
            elif cached and cached['ComputedDate']:
                # A previous search found NO window at all within SearchDays of
                # ComputedDate -- e.g. a seasonal target between windows (like
                # M16 in late summer/fall), or one that genuinely never meets
                # this profile's criteria. Before this fix, a null result was
                # never cached as valid, so this branch recomputed the full
                # forward search on EVERY single report run forever. Now it's
                # only re-checked once specified_date has moved far enough past
                # ComputedDate that the search window would actually cover new
                # ground -- same "don't recompute until necessary" rule used
                # for found windows above.
                computed_date = datetime.datetime.strptime(cached['ComputedDate'], '%Y-%m-%d').date()
                search_days = cached['SearchDays'] or FORECAST_MAX_DAYS
                # Only trust the cached null result if it was searched over at
                # least as many days as the script searches now -- otherwise a
                # null result cached under a shorter, now-stale search range
                # (e.g. everything computed before FORECAST_MAX_DAYS was raised
                # from 180) would block the new, wider search from ever running
                # until the old range's ComputedDate+SearchDays happened to age
                # out on its own.
                if search_days >= FORECAST_MAX_DAYS and specified_date < computed_date + datetime.timedelta(days=search_days):
                    need_compute = False
                    # first_date/last_date stay None -- correctly represents "no window found"

            if need_compute:
                try:
                    star = Star(ra=Angle(hours=float(row['RAHours'])),
                                dec=Angle(degrees=float(row['DecDegrees'])))
                except Exception:
                    continue

                first_date, last_date = find_visibility_window(
                    star, specified_date, FORECAST_MAX_DAYS, ts, eph, observer, observer_pos,
                    minimum_altitude, azimuth_minimum_degrees, azimuth_maximum_degrees,
                    viewing_window_cache
                )

                fcur.execute("""
                    INSERT INTO VisibilityForecast (ProfileName, DSOKey, ComputedDate, FirstVisibleDate, LastVisibleDate, SearchDays)
                    VALUES (?, ?, ?, ?, ?, ?)
                    ON CONFLICT(ProfileName, DSOKey) DO UPDATE SET
                        ComputedDate = excluded.ComputedDate,
                        FirstVisibleDate = excluded.FirstVisibleDate,
                        LastVisibleDate = excluded.LastVisibleDate,
                        SearchDays = excluded.SearchDays
                """, (
                    profile_name, name, target_date_iso,
                    first_date.strftime('%Y-%m-%d') if first_date else None,
                    last_date.strftime('%Y-%m-%d') if last_date else None,
                    FORECAST_MAX_DAYS
                ))

            if first_date is not None:
                forecast_objects.append({
                    'do_me': '&#9733;' if row['WantBetter'] else '',
                    'name': name,
                    'aka': row['CommonName'] or name,
                    'first_visible': first_date.strftime('%Y-%m-%d'),
                    'last_visible': last_date.strftime('%Y-%m-%d') if last_date else '',
                    'first_visible_sort': first_date.toordinal(),
                    'visible_tonight': False,
                    'no_window': False,
                })
            else:
                # No window found within FORECAST_MAX_DAYS -- shown explicitly
                # rather than silently dropped, so a DSO that's simply between
                # seasons (or unreachable under the current alt/az criteria)
                # doesn't just vanish from the report with no explanation.
                forecast_objects.append({
                    'do_me': '&#9733;' if row['WantBetter'] else '',
                    'name': name,
                    'aka': row['CommonName'] or name,
                    'first_visible': '',
                    'last_visible': '',
                    'first_visible_sort': 99999999,  # sorts after every real date
                    'visible_tonight': False,
                    'no_window': True,
                })

        _lap('forecast_loop')
        forecast_conn.commit()
        forecast_conn.close()
    except Exception as e:
        with open('dso_visibility.log', 'a') as log_file:
            log_file.write(f"{datetime.datetime.now().isoformat()} - Forecast error: {e}\n")
        forecast_objects = []

    forecast_json = json.dumps([{
        'do_me': o['do_me'],
        'name': o['name'],
        'aka': o['aka'],
        'first_visible': o['first_visible'],
        'last_visible': o['last_visible'],
        'first_visible_sort': o['first_visible_sort'],
        'visible_tonight': o['visible_tonight'],
        'no_window': o.get('no_window', False),
    } for o in forecast_objects])

    forecast_table_html = f"""
    <h2 style="color:#4a9eff; border-bottom: 2px solid #4a9eff; padding-bottom: 10px; margin-top: 40px;">Visibility Dates</h2>
    <p style="color:#b8c5d6;">All DSOs with known coordinates. Objects visible tonight show today as first date. Others show next window within {FORECAST_MAX_DAYS} days.</p>
    <div class="controls" style="margin-top:10px;">
        <label for="forecastSort">Sort by:</label>
        <select id="forecastSort" onchange="sortForecast()">
            <option value="first_visible">Date First Visible</option>
            <option value="name">Name (A-Z)</option>
            <option value="aka">Friendly Name (A-Z)</option>
        </select>
    </div>
    <table id="forecastTable">
        <thead>
            <tr>
                <th>Priority</th>
                <th>Tonight</th>
                <th>Name</th>
                <th>Also Known As</th>
                <th>Date First Visible</th>
                <th>Date Last Visible</th>
            </tr>
        </thead>
        <tbody id="forecastBody"></tbody>
    </table>
"""

    alignment_table_html = ''
    if show_all:
        if not visible_stars:
            alignment_table_html = """
    <h2 style="color:#4a9eff; border-bottom: 2px solid #4a9eff; padding-bottom: 10px; margin-top: 40px;">Alignment Stars</h2>
    <p style="color:#b8c5d6;">No alignment stars meet the visibility criteria for this date.</p>
"""
        else:
            alignment_table_html = """
    <h2 style="color:#4a9eff; border-bottom: 2px solid #4a9eff; padding-bottom: 10px; margin-top: 40px;">Alignment Stars</h2>
    <p style="color:#b8c5d6;">Bright stars visible tonight within your viewing window &mdash; useful for scope alignment (e.g. Celestron SkyAlign).</p>
    <div class="controls" style="margin-top:10px;">
        <label for="starSortOrder">Sort by:</label>
        <select id="starSortOrder" onchange="sortStarTable()">
            <option value="duration">Duration (longest first)</option>
            <option value="start">Start Time (earliest first)</option>
            <option value="end">End Time (earliest first)</option>
            <option value="start_az">Starting Azimuth (lowest first)</option>
            <option value="start_alt">Starting Altitude (highest first)</option>
            <option value="magnitude">Magnitude (brightest first)</option>
            <option value="name">Name (A-Z)</option>
        </select>
    </div>
    <table id="starTable">
        <thead>
            <tr>
                <th>Name</th>
                <th>Also Known As</th>
                <th>Start</th>
                <th>Start Alt</th>
                <th>Start Az</th>
                <th>End</th>
                <th>End Alt</th>
                <th>End Az</th>
                <th>Duration</th>
                <th>Mag</th>
                <th>Constellation</th>
            </tr>
        </thead>
        <tbody id="starTableBody">
        </tbody>
    </table>
    <div class="info" style="margin-top: 20px;">
        <p><strong>Total alignment stars visible:</strong> <span id="starTotalCount"></span></p>
    </div>
"""

    planets_table_html = ''
    if show_all:
        planets_table_html = """
    <h2 style="color:#4a9eff; border-bottom: 2px solid #4a9eff; padding-bottom: 10px; margin-top: 40px;">Planets &amp; Moon</h2>
    <p style="color:#b8c5d6;">Restricted view uses your profile's altitude/azimuth criteria. Unrestricted view shows anywhere in the sky above 25&deg; altitude.</p>

    <h3 style="color:#7ec8ff; margin-top:20px;">Restricted View (your criteria)</h3>
    <div class="controls" style="margin-top:10px;">
        <label for="planetRestrictedSort">Sort by:</label>
        <select id="planetRestrictedSort" onchange="sortPlanetTable('restricted')">
            <option value="duration">Duration (longest first)</option>
            <option value="start">Start Time (earliest first)</option>
            <option value="name">Name (A-Z)</option>
        </select>
    </div>
    <table id="planetRestrictedTable">
        <thead>
            <tr>
                <th>Name</th>
                <th>Start</th>
                <th>Start Alt</th>
                <th>Start Az</th>
                <th>End</th>
                <th>End Alt</th>
                <th>End Az</th>
                <th>Duration</th>
                <th>Mag</th>
                <th>Phase</th>
            </tr>
        </thead>
        <tbody id="planetRestrictedBody"></tbody>
    </table>

    <h3 style="color:#7ec8ff; margin-top:28px;">Unrestricted View (any direction, Alt &gt; 25&deg;)</h3>
    <div class="controls" style="margin-top:10px;">
        <label for="planetUnrestrictedSort">Sort by:</label>
        <select id="planetUnrestrictedSort" onchange="sortPlanetTable('unrestricted')">
            <option value="duration">Duration (longest first)</option>
            <option value="start">Start Time (earliest first)</option>
            <option value="name">Name (A-Z)</option>
        </select>
    </div>
    <table id="planetUnrestrictedTable">
        <thead>
            <tr>
                <th>Name</th>
                <th>Start</th>
                <th>Start Alt</th>
                <th>Start Az</th>
                <th>End</th>
                <th>End Alt</th>
                <th>End Az</th>
                <th>Duration</th>
                <th>Mag</th>
                <th>Phase</th>
            </tr>
        </thead>
        <tbody id="planetUnrestrictedBody"></tbody>
    </table>
"""

    def safe_float(value, default=0.0):
        if value is None or value == '':
            return default
        try:
            return float(value)
        except (ValueError, TypeError):
            return default

    def safe_str(value, default=''):
        if value is None:
            return default
        try:
            if isinstance(value, (float, int, np.floating, np.integer)):
                if float(value).is_integer():
                    return str(int(value))
                return str(value)
        except Exception:
            pass
        return str(value)

    def safe_time_str(value):
        """Return HH:MM for datetimes/timestamps, or empty string for missing/invalid."""
        if hasattr(value, 'strftime'):
            try:
                return value.strftime('%H:%M')
            except Exception:
                pass
        return ''

    objects_json = json.dumps([{
        'do_me': safe_str(obj.get('do_me', '')),
        'name': safe_str(obj.get('name', '')),
        'aka': safe_str(obj.get('aka', '')),
        'start': safe_time_str(obj.get('start')),
        'start_minutes': int(obj.get('start_minutes') or 0),
        'end': safe_time_str(obj.get('end')),
        'end_minutes': int(obj.get('end_minutes') or 0),
        'duration': safe_float(obj.get('duration')),
        'magnitude': safe_float(obj.get('magnitude')),
        'constellation': safe_str(obj.get('constellation')),
        'type_desc': safe_str(obj.get('type_desc')),
        'start_alt': safe_float(obj.get('start_alt')),
        'start_az': safe_float(obj.get('start_az')),
        'end_alt': safe_float(obj.get('end_alt')),
        'end_az': safe_float(obj.get('end_az')),
        'sq_arcmins': safe_float(obj.get('sq_arcmins'))
    } for obj in visible_objects])

    stars_json = json.dumps([{
        'name': safe_str(obj.get('name', '')),
        'aka': safe_str(obj.get('aka', '')),
        'start': safe_time_str(obj.get('start')),
        'start_minutes': int(obj.get('start_minutes') or 0),
        'end': safe_time_str(obj.get('end')),
        'end_minutes': int(obj.get('end_minutes') or 0),
        'duration': safe_float(obj.get('duration')),
        'magnitude': safe_float(obj.get('magnitude')),
        'constellation': safe_str(obj.get('constellation')),
        'start_alt': safe_float(obj.get('start_alt')),
        'start_az': safe_float(obj.get('start_az')),
        'end_alt': safe_float(obj.get('end_alt')),
        'end_az': safe_float(obj.get('end_az')),
    } for obj in visible_stars])

    def _planet_start_minutes(dt):
        if dt is None:
            return 999999  # sorts "not visible tonight" entries last
        m = dt.hour * 60 + dt.minute
        return m + 24 * 60 if dt.hour < 12 else m

    def _planets_to_json(planet_list):
        return json.dumps([{
            'name': safe_str(p.get('name', '')),
            'visible_tonight': bool(p.get('visible_tonight')),
            'start': safe_time_str(p.get('start')) if p.get('start') else None,
            'start_minutes': _planet_start_minutes(p.get('start')),
            'end': safe_time_str(p.get('end')) if p.get('end') else None,
            'duration': safe_float(p.get('duration')) if p.get('duration') is not None else -1,
            'start_alt': p.get('start_alt'),
            'start_az': p.get('start_az'),
            'end_alt': p.get('end_alt'),
            'end_az': p.get('end_az'),
            'magnitude': p.get('magnitude'),
            'alt': p.get('alt'),
            'az': p.get('az'),
            'phase_pct': p.get('phase_pct'),
            'phase_name': p.get('phase_name'),
            'next_visible': p.get('next_visible'),
        } for p in planet_list])

    planets_restricted_json = _planets_to_json(planets_restricted)
    planets_unrestricted_json = _planets_to_json(planets_unrestricted)

    # ── Extra-sections script (Alignment Stars / Planets & Moon) — built only
    # when show_all is True. On the default path these tables aren't in the
    # DOM at all, so their render/sort JS is omitted rather than emitted-but-
    # inert. Visibility Dates (forecast_script, below) is part of the core
    # DSO report and is always emitted regardless of show_all.
    extra_sections_script = ''
    if show_all:
        extra_sections_script = """
    <script>
        // -- Alignment Stars table -------------------------------------------
        const starsData = """ + stars_json + """;

        function renderStarTable(data) {
            const tbody = document.getElementById('starTableBody');
            if (!tbody) return;
            tbody.innerHTML = '';

            data.forEach(obj => {
                const row = tbody.insertRow();
                row.innerHTML = `
                    <td><strong>${obj.name}</strong></td>
                    <td>${obj.aka}</td>
                    <td class="time">${obj.start}</td>
                    <td>${obj.start_alt.toFixed(0)}&deg;</td>
                    <td>${obj.start_az.toFixed(0)}&deg;</td>
                    <td class="time">${obj.end}</td>
                    <td>${obj.end_alt.toFixed(0)}&deg;</td>
                    <td>${obj.end_az.toFixed(0)}&deg;</td>
                    <td class="duration">${formatDuration(obj.duration)}</td>
                    <td>${obj.magnitude.toFixed(1)}</td>
                    <td>${obj.constellation}</td>
                `;
            });

            const totalEl = document.getElementById('starTotalCount');
            if (totalEl) totalEl.textContent = data.length;
        }

        function sortStarTable() {
            const sortSelect = document.getElementById('starSortOrder');
            if (!sortSelect) return;
            const sortBy = sortSelect.value;
            const sortedData = [...starsData];

            switch(sortBy) {
                case 'duration':
                    sortedData.sort((a, b) => b.duration - a.duration);
                    break;
                case 'start':
                    sortedData.sort((a, b) => a.start_minutes - b.start_minutes);
                    break;
                case 'end':
                    sortedData.sort((a, b) => a.end_minutes - b.end_minutes);
                    break;
                case 'start_az':
                    sortedData.sort((a, b) => a.start_az - b.start_az);
                    break;
                case 'start_alt':
                    sortedData.sort((a, b) => b.start_alt - a.start_alt);
                    break;
                case 'magnitude':
                    sortedData.sort((a, b) => a.magnitude - b.magnitude);
                    break;
                case 'name':
                    sortedData.sort((a, b) => a.name.localeCompare(b.name));
                    break;
            }

            renderStarTable(sortedData);
        }

        // Initial render with default sort (duration)
        sortStarTable();

        // -- Planets & Moon tables -------------------------------------------
        const planetsRestrictedData = """ + planets_restricted_json + """;
        const planetsUnrestrictedData = """ + planets_unrestricted_json + """;

        function formatPhase(p) {
            if (p.phase_pct === null || p.phase_pct === undefined) return '';
            return p.phase_pct + '% (' + p.phase_name + ')';
        }

        function renderPlanetTable(kind, data) {
            const bodyId = kind === 'restricted' ? 'planetRestrictedBody' : 'planetUnrestrictedBody';
            const tbody = document.getElementById(bodyId);
            if (!tbody) return;
            tbody.innerHTML = '';
            const fmtDeg = v => (v !== null && v !== undefined) ? v.toFixed(1) + '&deg;' : '&mdash;';
            data.forEach(obj => {
                const row = tbody.insertRow();
                const magCell = obj.magnitude !== null && obj.magnitude !== undefined ? obj.magnitude.toFixed(1) : '&mdash;';
                const phaseCell = formatPhase(obj) || '&mdash;';
                if (obj.visible_tonight) {
                    row.innerHTML = `
                        <td><strong>${obj.name}</strong></td>
                        <td class="time">${obj.start}</td>
                        <td>${fmtDeg(obj.start_alt)}</td>
                        <td>${fmtDeg(obj.start_az)}</td>
                        <td class="time">${obj.end}</td>
                        <td>${fmtDeg(obj.end_alt)}</td>
                        <td>${fmtDeg(obj.end_az)}</td>
                        <td class="duration">${formatDuration(obj.duration)}</td>
                        <td>${magCell}</td>
                        <td>${phaseCell}</td>
                    `;
                } else {
                    const currentPos = (obj.alt !== null && obj.alt !== undefined)
                        ? ' (currently ' + fmtDeg(obj.alt) + ' alt, ' + fmtDeg(obj.az) + ' az)'
                        : '';
                    const nextText = obj.next_visible
                        ? 'Not visible tonight' + currentPos + ' &mdash; next: ' + obj.next_visible
                        : 'Not visible tonight' + currentPos + ' &mdash; none in next """ + str(FORECAST_MAX_DAYS) + """ days';
                    row.innerHTML = `
                        <td><strong>${obj.name}</strong></td>
                        <td colspan="7" style="color:#8b9dc3; font-style:italic;">${nextText}</td>
                        <td>${magCell}</td>
                        <td>${phaseCell}</td>
                    `;
                }
            });
        }

        function sortPlanetTable(kind) {
            const selectId = kind === 'restricted' ? 'planetRestrictedSort' : 'planetUnrestrictedSort';
            const sortSelect = document.getElementById(selectId);
            if (!sortSelect) return;
            const sortBy = sortSelect.value;
            const source = kind === 'restricted' ? planetsRestrictedData : planetsUnrestrictedData;
            const sortedData = [...source];

            switch(sortBy) {
                case 'duration':
                    sortedData.sort((a, b) => b.duration - a.duration);
                    break;
                case 'start':
                    sortedData.sort((a, b) => a.start_minutes - b.start_minutes);
                    break;
                case 'name':
                    sortedData.sort((a, b) => a.name.localeCompare(b.name));
                    break;
            }

            renderPlanetTable(kind, sortedData);
        }

        // Initial render, both tables
        sortPlanetTable('restricted');
        sortPlanetTable('unrestricted');
    </script>
"""

    # ── Visibility Dates (forecast) script — always emitted, part of the
    # core DSO report regardless of show_all.
    forecast_script = """
    <script>
        // -- Visibility Dates table ----------------------------------------
        const forecastData = """ + forecast_json + """;

        function renderForecast(data) {
            const tbody = document.getElementById('forecastBody');
            if (!tbody) return;
            tbody.innerHTML = '';
            data.forEach(obj => {
                const row = tbody.insertRow();
                const tonightCell = obj.visible_tonight
                    ? '<span style="color:#7ec8a3; font-weight:600;">&#10003;</span>'
                    : '';
                if (obj.no_window) {
                    row.innerHTML = `
                        <td class="priority">${obj.do_me}</td>
                        <td style="text-align:center;">${tonightCell}</td>
                        <td><strong>${obj.name}</strong></td>
                        <td>${obj.aka}</td>
                        <td colspan="2" style="color:#8b9dc3; font-style:italic;">No window in next """ + str(FORECAST_MAX_DAYS) + """ days</td>
                    `;
                } else {
                    row.innerHTML = `
                        <td class="priority">${obj.do_me}</td>
                        <td style="text-align:center;">${tonightCell}</td>
                        <td><strong>${obj.name}</strong></td>
                        <td>${obj.aka}</td>
                        <td class="time">${obj.first_visible}</td>
                        <td class="time">${obj.last_visible}</td>
                    `;
                }
            });
        }

        function sortForecast() {
            const sortSelect = document.getElementById('forecastSort');
            if (!sortSelect) return;
            const sortBy = sortSelect.value;
            const sorted = [...forecastData];
            switch (sortBy) {
                case 'first_visible':
                    sorted.sort((a, b) => a.first_visible_sort - b.first_visible_sort);
                    break;
                case 'name':
                    sorted.sort((a, b) => a.name.localeCompare(b.name));
                    break;
                case 'aka':
                    sorted.sort((a, b) => a.aka.localeCompare(b.aka));
                    break;
            }
            renderForecast(sorted);
        }

        // Initial forecast render
        sortForecast();
    </script>
"""

    # ── Write timing breakdown to log, and build an HTML comment summary ────
    _total_elapsed = sum(dur for _, dur in _timings)
    _timing_line = ", ".join(f"{label}={dur:.2f}s" for label, dur in _timings)
    try:
        with open('dso_visibility.log', 'a') as _tlog:
            _tlog.write(
                f"{datetime.datetime.now().isoformat()} - TIMING date={specified_date} "
                f"profile={profile_name} all={show_all}: {_timing_line}, TOTAL={_total_elapsed:.2f}s\n"
            )
    except Exception:
        pass
    timing_comment = f"<!-- timing: {_timing_line}, TOTAL={_total_elapsed:.2f}s -->"

    # Output HTML
    target_date_str = specified_date.strftime('%Y-%m-%d')
    route_base = '/vis/all' if show_all else '/vis'

    print(f"""<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>DSO Visibility Report - {target_date_str}</title>
    <link rel="icon" type="image/png" href="/images/favicon.png">
    <style>
        body {{
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            max-width: 1400px;
            margin: 0 auto;
            padding: 20px;
            background: #0a0e27;
            color: #e0e0e0;
        }}
        h1 {{
            color: #4a9eff;
            border-bottom: 2px solid #4a9eff;
            padding-bottom: 10px;
        }}
        .info {{
            background: #1a1f3a;
            padding: 15px;
            border-radius: 8px;
            margin: 20px 0;
            border-left: 4px solid #4a9eff;
        }}
        .info p {{
            margin: 5px 0;
        }}
        .controls {{
            background: #1a1f3a;
            padding: 15px;
            border-radius: 8px;
            margin: 20px 0;
            display: flex;
            align-items: center;
            gap: 10px;
        }}
        .controls label {{
            color: #4a9eff;
            font-weight: 600;
        }}
        #force-rebuild-btn, .controls button {{
            padding: 8px 16px;
            background: #4a9eff !important;
            color: #ffffff !important;
            border: 1px solid rgba(255,255,255,0.3) !important;
            border-radius: 4px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            margin-left: 10px;
            transition: background 0.2s ease;
        }}

        #force-rebuild-btn:hover, controls button:hover {{
            background: #3a8eef !important;
            border-color: #3a8eef !important;
        }}

        #force-rebuild-btn:active, controls button:active {{
            background: #2a7edf !important;
            border-color: #2a7edf !important;
        }}
        .controls input[type="date"] {{
            padding: 8px 12px;
            background: #2a3f5f;
            color: #e0e0e0;
            border: 1px solid #4a9eff;
            border-radius: 4px;
            font-size: 14px;
            cursor: pointer;
            color-scheme: dark;
        }}

        .controls input[type="date"]:hover {{
            background: #3a4f6f;
        }}

        .controls input[type="date"]:focus {{
            outline: none;
            border-color: #7ec8a3;
        }}

        .controls select {{
            padding: 8px 12px;
            background: #2a3f5f;
            color: #e0e0e0;
            border: 1px solid #4a9eff;
            border-radius: 4px;
            font-size: 14px;
            cursor: pointer;
        }}
        .controls select:hover {{
            background: #3a4f6f;
        }}
        table {{
            width: 100%;
            border-collapse: collapse;
            margin-top: 20px;
            background: #1a1f3a;
            border-radius: 8px;
            overflow: hidden;
        }}
        th {{
            background: #2a3f5f;
            color: #4a9eff;
            padding: 12px;
            text-align: left;
            font-weight: 600;
            position: sticky;
            top: 0;
        }}
        td {{
            padding: 10px 12px;
            border-bottom: 1px solid #2a3f5f;
        }}
        tr:hover {{
            background: #243447;
        }}
        .priority {{
            color: #ffd700;
            font-size: 1.2em;
        }}
        .duration {{
            color: #7ec8a3;
            font-weight: 600;
        }}
        .time {{
            color: #b8c5d6;
        }}
        @media (max-width: 768px) {{
            body {{
                padding: 10px;
            }}
            table {{
                font-size: 0.85em;
            }}
            th, td {{
                padding: 6px;
            }}
            .controls {{
                flex-direction: column;
                align-items: flex-start;
            }}
        }}
        /* Info button */
        .info-btn {{
            background: none;
            border: 1px solid #4a9eff;
            border-radius: 4px;
            color: #4a9eff;
            cursor: pointer;
            font-size: 13px;
            padding: 2px 8px;
            line-height: 1.4;
            transition: background 0.15s;
        }}
        .info-btn:hover {{
            background: rgba(74,158,255,0.18);
        }}
        /* DSO Info Modal */
        #dso-modal-overlay {{
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(0,0,0,0.78);
            z-index: 1000;
            align-items: center;
            justify-content: center;
        }}
        #dso-modal-overlay.open {{
            display: flex;
        }}
        #dso-modal {{
            background: #1a1f3a;
            border: 1px solid #4a9eff;
            border-radius: 8px;
            width: 580px;
            max-width: 95vw;
            max-height: 88vh;
            overflow-y: auto;
            padding: 24px;
            position: relative;
        }}
        #dso-modal h2 {{
            color: #4a9eff;
            font-size: 18px;
            margin: 0 0 16px 0;
            padding-right: 32px;
        }}
        .modal-section {{
            background: rgba(255,255,255,0.03);
            border: 1px solid #2a3f5f;
            border-radius: 6px;
            margin-bottom: 12px;
            overflow: hidden;
        }}
        .modal-section-header {{
            background: #2a3f5f;
            color: #8b9dc3;
            font-size: 11px;
            font-weight: 600;
            letter-spacing: 0.05em;
            padding: 6px 12px;
            text-transform: uppercase;
        }}
        .modal-section-body {{
            padding: 12px;
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 8px 16px;
        }}
        .modal-field {{
            display: flex;
            flex-direction: column;
            gap: 2px;
        }}
        .modal-field-label {{
            color: #8b9dc3;
            font-size: 11px;
            font-weight: 500;
        }}
        .modal-field-value {{
            color: #e0e0e0;
            font-size: 13px;
        }}
        .modal-full {{
            grid-column: span 2;
        }}
        .modal-blurb {{
            color: #c0cce0;
            font-size: 13px;
            line-height: 1.6;
            white-space: pre-wrap;
        }}
        .modal-close {{
            position: absolute;
            top: 12px;
            right: 14px;
            background: none;
            border: none;
            color: #8b9dc3;
            font-size: 20px;
            cursor: pointer;
            line-height: 1;
            padding: 4px 8px;
            border-radius: 4px;
        }}
        .modal-close:hover {{
            color: #e0e0e0;
            background: rgba(255,255,255,0.07);
        }}
    </style>
</head>
<body>
    {timing_comment}
    <h1>DSO Visibility Report</h1>
    <div class="info">
        <p><strong>Location:</strong> {location_name}</p>
        <p><strong>Viewing Window:</strong> {start_local.strftime('%H:%M %Z')} to {end_local.strftime('%H:%M %Z')}</p>
        <p><strong>Criteria:</strong> Altitude &gt;= {minimum_altitude}&deg;, Azimuth {azimuth_minimum_degrees}&deg;-{azimuth_maximum_degrees}&deg;</p>
        <p><strong>View:</strong> {"Full report (adds Alignment Stars &amp; Planets/Moon)" if show_all else "DSOs &amp; Visibility Dates"} &mdash; <a href="{'/vis' if show_all else '/vis/all'}?date={target_date_str}&amp;profile={profile_name}" style="color:#7ec8ff;">{"Switch to DSO Only" if show_all else "Switch to Full Report"}</a></p>
    </div>

    <div class="controls">
        <label for="sortOrder">Sort by:</label>
        <select id="sortOrder" onchange="sortTable()">
            <option value="duration">Duration (longest first)</option>
            <option value="start">Start Time (earliest first)</option>
            <option value="end">End Time (earliest first)</option>
            <option value="start_az">Starting Azimuth (lowest first)</option>
            <option value="start_alt">Starting Altitude (highest first)</option>
            <option value="magnitude">Magnitude (brightest first)</option>
            <option value="sq_arcmins">Size (largest first)</option>
            <option value="name">Name (A-Z)</option>
            <option value="aka">Friendly Name</option>
        </select>
        <button id="force-rebuild-btn" onclick="window.location.href='{route_base}?date={target_date_str}&profile={profile_name}&rebuild=1'">Force Rebuild</button>
        <button id="quickadd-btn" onclick="openQuickAdd()" style="background:#3fb950 !important; border-color:rgba(255,255,255,0.3) !important;">&#43; Quick Add DSO</button>
    </div>

""")

    if not visible_objects:
        print("<p>No objects meet the visibility criteria for this date.</p>")
    else:
        print("""
    <table id="dsoTable">
        <thead>
            <tr>
                <th>Priority</th>
                <th>Name</th>
                <th>Also Known As</th>
                <th>Start</th>
                <th>Start Alt</th>
                <th>Start Az</th>
                <th>End</th>
                <th>End Alt</th>
                <th>End Az</th>
                <th>Duration</th>
                <th>Mag</th>
                <th>Size (arcmin&sup2;)</th>
                <th>Constellation</th>
                <th>Type</th>
                <th></th>
            </tr>
        </thead>
        <tbody id="tableBody">
        </tbody>
    </table>
    <div class="info" style="margin-top: 20px;">
        <p><strong>Total visible objects:</strong> <span id="totalCount"></span></p>
        <p><strong>&#9733;</strong> = Priority target (not recently observed)</p>
    </div>
""" + alignment_table_html + planets_table_html + forecast_table_html + """
    <script>
        const objectsData = """ + objects_json + """;

        function formatDuration(minutes) {
            const hours = minutes / 60;
            return hours >= 1 ? `${hours.toFixed(1)}h` : `${minutes.toFixed(0)}m`;
        }

        function renderTable(data) {
            const tbody = document.getElementById('tableBody');
            tbody.innerHTML = '';

            data.forEach(obj => {
                const row = tbody.insertRow();
                row.innerHTML = `
                    <td class="priority">${obj.do_me}</td>
                    <td><strong>${obj.name}</strong></td>
                    <td>${obj.aka}</td>
                    <td class="time">${obj.start}</td>
                    <td>${obj.start_alt.toFixed(0)}&deg;</td>
                    <td>${obj.start_az.toFixed(0)}&deg;</td>
                    <td class="time">${obj.end}</td>
                    <td>${obj.end_alt.toFixed(0)}&deg;</td>
                    <td>${obj.end_az.toFixed(0)}&deg;</td>
                    <td class="duration">${formatDuration(obj.duration)}</td>
                    <td>${obj.magnitude.toFixed(1)}</td>
                    <td>${obj.sq_arcmins > 0 ? obj.sq_arcmins.toFixed(0) : ''}</td>
                    <td>${obj.constellation}</td>
                    <td>${obj.type_desc}</td>
                    <td><button class="info-btn" onclick="showDSOInfo('${obj.name}')">&#x2139;</button></td>
                `;
            });

            document.getElementById('totalCount').textContent = data.length;
        }

        function sortTable() {
            const sortBy = document.getElementById('sortOrder').value;
            const sortedData = [...objectsData];

            switch(sortBy) {
                case 'duration':
                    sortedData.sort((a, b) => b.duration - a.duration);
                    break;
                case 'start':
                    sortedData.sort((a, b) => a.start_minutes - b.start_minutes);
                    break;
                case 'end':
                    sortedData.sort((a, b) => a.end_minutes - b.end_minutes);
                    break;
                case 'start_az':
                    sortedData.sort((a, b) => a.start_az - b.start_az);
                    break;
                case 'start_alt':
                    sortedData.sort((a, b) => b.start_alt - a.start_alt);
                    break;
                case 'magnitude':
                    sortedData.sort((a, b) => a.magnitude - b.magnitude);
                    break;
                case 'sq_arcmins':
                    sortedData.sort((a, b) => b.sq_arcmins - a.sq_arcmins);
                    break;
                case 'name':
                    sortedData.sort((a, b) => a.name.localeCompare(b.name));
                    break;
                case 'aka':
                    sortedData.sort((a, b) => a.aka.localeCompare(b.aka));
                    break;
            }

            renderTable(sortedData);
        }

        // Initial render with default sort (duration)
        sortTable();
    </script>
""" + extra_sections_script + forecast_script + """
<!-- DSO Info Modal -->
<div id="dso-modal-overlay" onclick="if(event.target===this)closeDSOInfo()">
  <div id="dso-modal">
    <button class="modal-close" onclick="closeDSOInfo()">&#x2715;</button>
    <h2 id="dso-modal-title">Loading&#x2026;</h2>
    <div id="dso-modal-body"></div>
  </div>
</div>

<script>
async function showDSOInfo(dsoKey) {
  const overlay = document.getElementById('dso-modal-overlay');
  const title   = document.getElementById('dso-modal-title');
  const body    = document.getElementById('dso-modal-body');
  title.textContent = dsoKey;
  body.innerHTML = '<div style="color:#8b9dc3;text-align:center;padding:20px;">Fetching data&#x2026;</div>';
  overlay.classList.add('open');

  try {
    const res  = await fetch('/api/dso.php?key=' + encodeURIComponent(dsoKey));
    const json = await res.json();
    if (!json.success) {
      body.innerHTML = '<div style="color:#f85149;padding:12px;">Error: ' + (json.error || 'Unknown') + '</div>';
      return;
    }
    const d = json.data;
    title.textContent = d.DSOKey + (d.CommonName ? ' - ' + d.CommonName : '');

    function fv(v) { return (v !== null && v !== undefined && v !== '') ? String(v) : '-'; }
    function fn(v, dec) { return (v !== null && v !== undefined && v !== '') ? parseFloat(v).toFixed(dec ?? 2) : '-'; }

    const SEESTAR_LABELS = { s30: 'Seestar S30', s50: 'Seestar S50', s50p: 'Seestar S50 Pro' };
    function equipmentLabel(codes) {
      if (!codes || !codes.length) return '-';
      return codes.map(c => SEESTAR_LABELS[String(c).toLowerCase()] || c).join(', ');
    }

    function raToHMS(h) {
      if (h === null || h === undefined || h === '' || isNaN(h)) return '-';
      h = parseFloat(h);
      const hh = Math.floor(h);
      const rem = (h - hh) * 60;
      const mm = Math.floor(rem);
      const ss = (rem - mm) * 60;
      return String(hh).padStart(2,'0') + 'h ' + String(mm).padStart(2,'0') + 'm ' + ss.toFixed(1).padStart(4,'0') + 's &nbsp;(J2000)';
    }

    function decToDMS(d) {
      if (d === null || d === undefined || d === '' || isNaN(d)) return '-';
      d = parseFloat(d);
      const sign = d < 0 ? '&minus;' : '+';
      const abs = Math.abs(d);
      const dd = Math.floor(abs);
      const rem = (abs - dd) * 60;
      const mm = Math.floor(rem);
      const ss = (rem - mm) * 60;
      return sign + String(dd).padStart(2,'0') + '&deg; ' + String(mm).padStart(2,'0') + '&prime; ' + ss.toFixed(1).padStart(4,'0') + '&Prime; &nbsp;(J2000)';
    }

    // Fetch preview image URL first, then render everything at once
    let previewUrl = null;
    try {
      const pr = await fetch('/api/dso_preview.php?key=' + encodeURIComponent(d.DSOKey));
      if (pr.ok) {
        const pj = await pr.json();
        if (pj && pj.url) previewUrl = pj.url;
      }
    } catch (e) {}

    let html = '';

    // Preview image
    if (previewUrl) {
      html += '<div style="text-align:center;padding:10px 16px 6px;">'
            + '<img src="' + previewUrl + '" alt="" style="max-width:100%;max-height:220px;object-fit:contain;border-radius:6px;">'
            + '</div>';
    }

    // Identity
    html += `<div class="modal-section">
      <div class="modal-section-header">Identity</div>
      <div class="modal-section-body">
        <div class="modal-field"><span class="modal-field-label">DSO Key</span><span class="modal-field-value">${fv(d.DSOKey)}</span></div>
        <div class="modal-field"><span class="modal-field-label">Primary Catalog ID</span><span class="modal-field-value">${fv(d.PrimaryCatalogID)}</span></div>
        <div class="modal-field"><span class="modal-field-label">Common Name</span><span class="modal-field-value">${fv(d.CommonName)}</span></div>
        <div class="modal-field"><span class="modal-field-label">Object Type</span><span class="modal-field-value">${fv(d.ObjectTypeName)}</span></div>
        <div class="modal-field"><span class="modal-field-label">Constellation</span><span class="modal-field-value">${fv(d.ConstellationName)}</span></div>
        <div class="modal-field"><span class="modal-field-label">Distance</span><span class="modal-field-value">${fv(d.DistanceLY)}</span></div>
      </div>
    </div>`;

    // Astrometrics
    const wantBetterHtml = d.WantBetter ? `<div class="modal-field modal-full"><span class="modal-field-value" style="color:#ffd700;">&#9733; Priority &#8212; want better data</span></div>` : '';
    html += `<div class="modal-section">
      <div class="modal-section-header">Astrometrics</div>
      <div class="modal-section-body">
        <div class="modal-field"><span class="modal-field-label">Right Ascension</span><span class="modal-field-value" style="font-family:monospace;">${raToHMS(d.RAHours)}</span></div>
        <div class="modal-field"><span class="modal-field-label">Declination</span><span class="modal-field-value" style="font-family:monospace;">${decToDMS(d.DecDegrees)}</span></div>
        <div class="modal-field"><span class="modal-field-label">Magnitude</span><span class="modal-field-value">${fn(d.Magnitude, 1)}</span></div>
        <div class="modal-field"><span class="modal-field-label">Size (arcmin&sup2;)</span><span class="modal-field-value">${d.SqArcMins ? parseFloat(d.SqArcMins).toFixed(0) : '-'}</span></div>
        <div class="modal-field modal-full"><span class="modal-field-label">Object Size</span><span class="modal-field-value">${fv(d.ObjectSize)}</span></div>
        ${wantBetterHtml}
      </div>
    </div>`;

    // Observation & Project -- a DSO can have more than one Project (e.g. a
    // standard framing and a separate mosaic framing), so render one block
    // per project rather than assuming exactly one.
    const projects = d.Projects || [];
    if (projects.length === 0) {
      html += `<div class="modal-section">
        <div class="modal-section-header">Observation &amp; Project</div>
        <div class="modal-section-body" style="grid-template-columns:1fr;">
          <div class="modal-field"><span class="modal-field-value" style="color:#8b9dc3;">No project yet for this DSO.</span></div>
        </div>
      </div>`;
    } else {
      projects.forEach((p, idx) => {
        const integStr = p.TotalIntegrationMins ? (parseFloat(p.TotalIntegrationMins)/60).toFixed(1) + ' hrs' : '-';
        const mosaicSuffix = p.IsMosaic ? ' - Mosaic' : '';
        const header = projects.length > 1
          ? ('Project ' + (idx + 1) + ' of ' + projects.length + mosaicSuffix)
          : ('Observation &amp; Project' + mosaicSuffix);
        html += `<div class="modal-section">
          <div class="modal-section-header">${header}</div>
          <div class="modal-section-body">
            <div class="modal-field"><span class="modal-field-label">Project Folder</span><span class="modal-field-value">${fv(p.ProjectFolder)}</span></div>
            <div class="modal-field"><span class="modal-field-label">Mosaic?</span><span class="modal-field-value">${p.IsMosaic ? 'Yes' : 'No'}</span></div>
            <div class="modal-field"><span class="modal-field-label">Last Observed</span><span class="modal-field-value">${fv(p.MostRecentObservation)}</span></div>
            <div class="modal-field"><span class="modal-field-label">Total Lights</span><span class="modal-field-value">${fv(p.TotalLights)}</span></div>
            <div class="modal-field"><span class="modal-field-label">Integration Time</span><span class="modal-field-value">${integStr}</span></div>
            <div class="modal-field"><span class="modal-field-label">Equipment</span><span class="modal-field-value">${equipmentLabel(p.Equipment)}</span></div>
          </div>
        </div>`;
      });
    }

    // Notes
    if (d.Notes) {
      html += `<div class="modal-section">
        <div class="modal-section-header">Notes</div>
        <div class="modal-section-body" style="grid-template-columns:1fr;">
          <div class="modal-field"><span class="modal-field-value" style="white-space:pre-wrap;">${d.Notes}</span></div>
        </div>
      </div>`;
    }

    // Social Blurb
    if (d.SocialBlurb) {
      html += `<div class="modal-section">
        <div class="modal-section-header">Social Blurb</div>
        <div class="modal-section-body" style="grid-template-columns:1fr;">
          <div class="modal-blurb">${d.SocialBlurb}</div>
        </div>
      </div>`;
    }

    body.innerHTML = html;
  } catch (e) {
    body.innerHTML = '<div style="color:#f85149;padding:12px;">Network error: ' + e.message + '</div>';
  }
}

function closeDSOInfo() {
  document.getElementById('dso-modal-overlay').classList.remove('open');
}

document.addEventListener('keydown', e => { if (e.key === 'Escape') closeDSOInfo(); });
</script>

<!-- Quick Add Modal -->
<div id="qa-overlay" style="display:none; position:fixed; inset:0; background:rgba(0,0,0,0.7); z-index:999; align-items:center; justify-content:center;">
  <div style="background:#1a1f3a; border:1px solid #4a9eff; border-radius:8px; padding:28px; width:340px; max-width:90vw; display:flex; flex-direction:column; gap:14px;">
    <h2 style="color:#4a9eff; font-size:16px; margin:0;">&#43; Quick Add DSO</h2>
    <input id="qa-input" type="text" placeholder="e.g. NGC1499, M57, IC405"
      style="background:#0a0e27; border:1px solid #4a9eff; border-radius:4px; color:#e0e0e0; padding:9px 12px; font-size:15px; outline:none; text-transform:uppercase;"
      onkeydown="if(event.key==='Enter') submitQuickAdd(); if(event.key==='Escape') closeQuickAdd();">
    <div id="qa-status" style="font-size:13px; min-height:18px; color:#7ec8a3;"></div>
    <div style="display:flex; gap:10px; justify-content:flex-end;">
      <button onclick="closeQuickAdd()"
        style="padding:7px 16px; background:#2a3f5f; border:1px solid #4a9eff; border-radius:4px; color:#e0e0e0; cursor:pointer; font-size:13px;">Cancel</button>
      <button id="qa-submit" onclick="submitQuickAdd()"
        style="padding:7px 16px; background:#4a9eff; border:1px solid #4a9eff; border-radius:4px; color:#fff; font-weight:600; cursor:pointer; font-size:13px;">Add</button>
    </div>
  </div>
</div>

<script>
function openQuickAdd() {
  const overlay = document.getElementById('qa-overlay');
  overlay.style.display = 'flex';
  const inp = document.getElementById('qa-input');
  inp.value = '';
  document.getElementById('qa-status').textContent = '';
  document.getElementById('qa-submit').disabled = false;
  setTimeout(() => inp.focus(), 50);
}

function closeQuickAdd() {
  document.getElementById('qa-overlay').style.display = 'none';
}

async function submitQuickAdd() {
  const inp    = document.getElementById('qa-input');
  const status = document.getElementById('qa-status');
  const btn    = document.getElementById('qa-submit');
  const dsoKey = inp.value.trim().toUpperCase();
  if (!dsoKey) { inp.focus(); return; }

  btn.disabled = true;
  status.style.color = '#7ec8a3';
  status.textContent = 'Looking up ' + dsoKey + '...';

  try {
    const res  = await fetch('/admin/api_quickadd.php', {
      method:  'POST',
      headers: { 'Content-Type': 'application/json' },
      body:    JSON.stringify({ dso_key: dsoKey }),
    });
    const data = await res.json();

    if (data.exists) {
      status.style.color = '#ffd700';
      status.textContent = dsoKey + ' is already in the database.';
      btn.disabled = false;
      return;
    }

    if (data.success && data.created) {
      const name = data.CommonName ? data.CommonName + ' (' + dsoKey + ')' : dsoKey;
      status.textContent = name + ' added! Rebuilding visibility report...';
      setTimeout(() => {
        window.location.href = '{route_base}?date={target_date_str}&profile={profile_name}&rebuild=1';
      }, 1200);
      return;
    }

    status.style.color = '#f85149';
    status.textContent = 'Error: ' + (data.error || 'Unknown error');
    btn.disabled = false;

  } catch (e) {
    status.style.color = '#f85149';
    status.textContent = 'Network error: ' + e.message;
    btn.disabled = false;
  }
}

// Close overlay on background click
document.getElementById('qa-overlay').addEventListener('click', function(e) {
  if (e.target === this) closeQuickAdd();
});
</script>

</body>
</html>
""")


if __name__ == '__main__':
    parser = argparse.ArgumentParser(description='Calculate DSO visibility for a given date')
    parser.add_argument('--date', type=str, help='Date in YYYY-MM-DD format (default: today)')
    parser.add_argument('--profile', type=str, default='default', help='Profile name to use (default: default)')
    parser.add_argument('--all', action='store_true',
                         help='Include Alignment Stars and Planets & Moon sections '
                              '(default: DSO table + Visibility Dates only)')
    args = parser.parse_args()
    
    target_date = None
    if args.date:
        try:
            target_date = datetime.datetime.strptime(args.date, '%Y-%m-%d').date()
        except ValueError:
            print("<p>Error: Invalid date format. Use YYYY-MM-DD</p>")
            sys.exit(1)
    
    calculate_visibility(target_date, args.profile, args.all)
