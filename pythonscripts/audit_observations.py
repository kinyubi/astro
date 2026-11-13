"""
audit_observations.py

Ensures a 1-to-1 correspondence between observation folders on disk under
WORKS_ROOT (C:\\Astronomy\\MyWorks) and rows in the Observations table, per
DB_REWORK_PLAN.md sections 3-4.

For each top-level folder under MyWorks (expected to match a Projects.ProjectFolder):
  - No matching Projects row -> reported as an "unregistered project folder" and
    skipped (add the DSO/Project via admin first, then re-run).
  - Matched -> scan its subfolders for observation folders (matching the two
    naming patterns below) and:
      - Match each disk folder to an existing Observations row by date: the
        first 8 characters of ObservationFolder if it has one, or its plain
        ObservationDate if it doesn't yet -- rows with no ObservationFolder
        set are included in matching, not excluded, so they can get linked to
        a real disk folder this run instead of staying permanently unlinked.
        Historical count discrepancies mean the disk name and the stored
        ObservationFolder can differ (e.g. a corrected exposure count), which
        is why the match key is only the date, not the full string. If more
        than one existing row shares that date, disambiguate by EquipmentID;
        if still ambiguous, skip it and report rather than guessing.
      - The name on disk always supersedes: if a match's stored
        ObservationFolder differs from the disk name (including when it was
        NULL), update it to the disk name and refresh the name-derived fields
        (TotalExposures, ExposureTimeSecs) unconditionally. Every other field
        (GoodLights, StartTime, EndTime) is only filled if currently NULL/0 --
        never overwrites data that's already there (e.g. GoodLights may
        reflect manual curation later).
      - Create a new Observations row for any observation folder with no
        matching date in the DB at all.
      - DELETE any existing Observations row whose date wasn't found among
        this run's disk folders -- "orphaned" if it had a folder recorded,
        "legacy, no ObservationFolder" if it never had one -- to keep a real
        1-to-1 correspondence between disk and DB. The one exception: a row
        carrying manually-entered data this script can never rederive
        (see MANUAL_DATA_FIELDS -- Filter/Notes/SeeingConditions/
        Temperature/Humidity/NinaWorkflowName/MosaicPanel) is held back and
        reported instead of deleted, since a future re-sync can't bring that
        back the way it can TotalExposures/GoodLights/etc.
      - Report (skip) any subfolder that starts with an 8-digit date but
        still doesn't match either observation folder pattern, so a
        malformed observation folder is never silently ignored. A subfolder
        that doesn't even start with 8 digits (lights_ folders aside) isn't
        an attempt at an observation folder at all -- e.g. "processed",
        "masters", misc notes folders -- so those are skipped without being
        reported.

NOTHING IS WRITTEN until you confirm at the y/N prompt. All changes run in a
single SQLite transaction -- rolls back cleanly on any error.

FIT filename matching: the free-text segments (the DSO/target name and the
filter name) are matched permissively rather than with a restricted
character class, since real-world names can contain hyphens, spaces, and
other punctuation (e.g. a comet target name), and the filter/date boundary
isn't guaranteed to have a literal separating underscore either -- only the
exposure-seconds and date/time segments are anchored to a specific format.

Integration minutes calculation: normally GoodLights * ExposureTimeSecs / 60
(GoodLights reflects what's actually still on disk right now). If GoodLights
comes back 0 or missing -- e.g. the FIT-file date-boundary matching found
nothing for that specific observation, even though the folder name (or a
prior fill) recorded a TotalExposures count -- falls back to
TotalExposures * ExposureTimeSecs / 60 instead of reporting/writing zero.

Reporting: every row previewed for create/update shows its own projected
IntegrationMins, and a per-project "Projected Total Integration Time"
summary at the end shows each touched project's current total alongside what
it will become after applying -- computed from the DB's existing
IntegrationMins values plus only the rows that will actually get
IntegrationMins newly filled (rows that already have a value keep it
untouched, per the fill-if-blank rule, so they never contribute to the
projected delta even if their GoodLights/ExposureTimeSecs are being
corrected this run).

Tested against a synthetic folder/DB structure before being placed here --
verified new-row creation, gap-filling on incomplete rows (leaving complete
rows untouched), the disk-name-supersedes count-correction case, a
pre-existing no-folder row correctly getting linked to a real disk folder for
its date, ambiguous same-night/same-equipment collision detection (correctly
skipped rather than guessed), unregistered-folder detection, orphan
detection, idempotency, FIT filenames with punctuation in the target name and
with no separating underscore before the date/time, the
per-row/per-project IntegrationMins reporting, and the
GoodLights-zero-falls-back-to-TotalExposures case.

Usage:
    python audit_observations.py [path_to_astro.db] [--works-root PATH]

Scoped, non-interactive mode (used by the admin UI's "Audit Observations"
button -- see public/admin/api_audit_observations.php):

    python audit_observations.py --project-folder=<folder> --json

Restricts the scan to that one top-level folder instead of all of
WORKS_ROOT, skips the printed report and the y/N prompt, applies the
safe creates/updates immediately (ambiguous matches are still never
auto-applied -- they're reported the same as always), and prints a single
JSON object to stdout instead of the text report. Add --dry-run alongside
--json to get the same JSON shape without writing anything, for testing.
"""

import re
import sys
import os
import json as jsonlib
import datetime
from pathlib import Path
from db_connect import get_connection

WORKS_ROOT = Path(r"C:\Astronomy\MyWorks")
DB_PATH = Path(__file__).parent.parent / 'dsodb' / 'astro.db'
PROJECT_FOLDER_FILTER = None  # --project-folder=NAME -- scope to one top-level folder
JSON_MODE = False             # --json -- non-interactive, JSON output, used by the admin UI
DRY_RUN = False               # --dry-run -- only meaningful with --json (see module docstring)

# Parse remaining args (allow overriding DB path / works root for testing)
args = sys.argv[1:]
for a in list(args):
    if a.startswith('--works-root='):
        WORKS_ROOT = Path(a.split('=', 1)[1])
        args.remove(a)
    elif a.startswith('--project-folder='):
        PROJECT_FOLDER_FILTER = a.split('=', 1)[1].strip()
        args.remove(a)
    elif a == '--json':
        JSON_MODE = True
        args.remove(a)
    elif a == '--dry-run':
        DRY_RUN = True
        args.remove(a)
if args:
    DB_PATH = Path(args[0])

# Patterns from DB_REWORK_PLAN.md section 3. The optional "_mosaic" marker
# right before the equipment code (e.g. "20251102_207x60s_mosaic_S30") flags
# a session shot as part of a mosaic -- it's a non-capturing token, not a
# panel identifier, so it doesn't feed MosaicPanel or anything else; it just
# needs to not break the match.
#
# Observation folder names are <yyyymmdd>_[anything_]<EQUIPMENT>, e.g.
#   20251102_207x60s_mosaic_S30    20260907_639x20s_AW_S50P
#   20251110_60s_S30               20251114_S30
#   20251206_waning_gibbous_moon_8SE
# The LAST part must be a code from the Equipment table (S30, S50, S50P,
# 6SE, 8SE...) -- anything else (moon, Crescent, IGNORE) means the folder
# isn't counted, and it's reported with the reason instead of being
# written with a bogus EquipmentID. A part like 207x60s (or 1102x10) gives
# the shot count and exposure, 60s alone gives the exposure; every other
# part (mosaic, AW, closeup, a description) is just ignored.
COUNT_TOKEN = re.compile(r'^(\d+)x(\d+)s?$', re.IGNORECASE)
SECS_TOKEN = re.compile(r'^(\d+)s$', re.IGNORECASE)
KNOWN_EQUIPMENT = set()   # upper-case EquipmentIDs, filled from the Equipment table in main()
LIGHTS_FOLDER = re.compile(r'^lights_([A-Z0-9]+)$', re.IGNORECASE)
FIT_FILENAME_PATTERN = r'^.+_(\d+)\.0s_.*(\d{8})-(\d{6})\.fit' + chr(36)
FIT_FILENAME = re.compile(FIT_FILENAME_PATTERN, re.IGNORECASE)


def calc_integration_mins(good_lights, total_exposures, exposure_secs):
    """Returns rounded integration minutes, or None if not calculable.

    Normally GoodLights * ExposureTimeSecs / 60. If GoodLights is missing or
    zero, falls back to TotalExposures * ExposureTimeSecs / 60 instead of
    reporting/writing zero."""
    if exposure_secs is None:
        return None
    if good_lights:
        return round(good_lights * exposure_secs / 60.0, 1)
    if total_exposures:
        return round(total_exposures * exposure_secs / 60.0, 1)
    return None


# Fields that only ever get into an Observations row by hand (nothing this
# script scans for on disk derives them) -- SeeingConditions/Temperature/
# Humidity/NinaWorkflowName/Notes/MosaicPanel/Filter. A row with no matching
# disk folder is normally safe to delete outright (everything else about it
# -- TotalExposures, GoodLights, ExposureTimeSecs, StartTime/EndTime -- is
# fully rederivable next time the folder shows up again), but one of these
# fields means someone typed something in that a re-sync can never bring
# back, so it's held out of auto-delete and reported for manual review
# instead.
MANUAL_DATA_FIELDS = ('Filter', 'Notes', 'SeeingConditions', 'Temperature',
                       'Humidity', 'NinaWorkflowName', 'MosaicPanel')


def manual_data_fields_present(row):
    """Returns the subset of MANUAL_DATA_FIELDS that are actually set on
    this Observations row (non-NULL, non-empty-string)."""
    return [f for f in MANUAL_DATA_FIELDS if row.get(f) not in (None, '')]


def observation_folder_problem(name):
    """Why a date-named folder can't be used as an observation folder, in
    plain words, or None if it can."""
    parts = name.split('_')
    if len(parts) < 2 or not re.fullmatch(r'\d{8}', parts[0]):
        return "name doesn't start with a yyyymmdd date and a part after it"
    if parts[-1].upper() not in KNOWN_EQUIPMENT:
        codes = ', '.join(sorted(KNOWN_EQUIPMENT)) or 'S30, S50, S50P'
        return (f"name doesn't end with an equipment code ({codes}), so it isn't counted"
                f" as an observation -- e.g. rename it {name}_S30")
    return None


def extra_words(name):
    """How many parts of the name are neither the date, the equipment, the
    shot count/exposure nor 'mosaic' (e.g. AW, closeup, a description)."""
    middle = name.split('_')[1:-1]
    return sum(1 for p in middle
               if not (COUNT_TOKEN.match(p) or SECS_TOKEN.match(p) or p.lower() == 'mosaic'))


def parse_observation_folder(name):
    """Returns a dict of what the folder name itself tells us, or None if it
    isn't a usable observation folder name (see observation_folder_problem)."""
    if observation_folder_problem(name):
        return None
    parts = name.split('_')
    total_exp = exp_secs = None
    for p in parts[1:-1]:
        m = COUNT_TOKEN.match(p)
        if m:
            total_exp, exp_secs = int(m.group(1)), float(m.group(2))
            continue
        m = SECS_TOKEN.match(p)
        if m:
            exp_secs = float(m.group(1))
    return {
        'ObservationDate': parts[0],
        'EquipmentID': parts[-1].upper(),
        'TotalExposures': total_exp,
        'ExposureTimeSecs': exp_secs,
    }


def scan_lights_folder(lights_path):
    """Returns list of (datetime, exposure_secs) for every FIT file found in a
    lights_<EQUIPMENT> folder. Cached per-equipment by the caller since one
    lights folder holds FIT files from every observation for that equipment."""
    results = []
    if not lights_path.is_dir():
        return results
    for f in os.listdir(lights_path):
        m = FIT_FILENAME.match(f)
        if not m:
            continue
        exp_secs, date_str, time_str = m.groups()
        try:
            dt = datetime.datetime.strptime(date_str + time_str, '%Y%m%d%H%M%S')
        except ValueError:
            continue
        results.append((dt, float(exp_secs)))
    return results


def fits_for_observation_night(fit_list, obs_date):
    """Date-boundary rule from DB_REWORK_PLAN.md section 3: a FIT file belongs
    to the observation night starting on obs_date if its date matches obs_date
    and its hour is > 12, OR its date matches obs_date + 1 day and its hour is
    < 12."""
    next_day = obs_date + datetime.timedelta(days=1)
    matched = []
    for dt, exp in fit_list:
        if dt.date() == obs_date and dt.hour > 12:
            matched.append((dt, exp))
        elif dt.date() == next_day and dt.hour < 12:
            matched.append((dt, exp))
    return matched


def derive_observation_fields(obs_folder_name, lights_fit_cache, project_path):
    """Given an observation folder name, return the full set of Observations
    fields it implies, or None if the name doesn't match a known pattern."""
    parsed = parse_observation_folder(obs_folder_name)
    if not parsed:
        return None

    obs_date = datetime.datetime.strptime(parsed['ObservationDate'], '%Y%m%d').date()
    equip = parsed['EquipmentID']

    if equip not in lights_fit_cache:
        lights_fit_cache[equip] = scan_lights_folder(project_path / f'lights_{equip}')
    night_fits = fits_for_observation_night(lights_fit_cache[equip], obs_date)
    night_fits.sort(key=lambda x: x[0])

    start_time = night_fits[0][0] if night_fits else None
    end_time = night_fits[-1][0] if night_fits else None

    if parsed['TotalExposures'] is not None:
        # Folder name gave us the original shot count -- GoodLights reflects
        # what's actually still on disk now (may differ after later culling).
        total_exposures = parsed['TotalExposures']
        exposure_secs = parsed['ExposureTimeSecs']
        good_lights = len(night_fits) if night_fits else None
    else:
        # No count in the folder name -- derive both from the FIT files found.
        total_exposures = len(night_fits) if night_fits else None
        exposure_secs = night_fits[0][1] if night_fits else None
        good_lights = total_exposures

    d = parsed['ObservationDate']
    return {
        'ObservationDate': f'{d[0:4]}-{d[4:6]}-{d[6:8]}',
        'EquipmentID': equip,
        'ObservationFolder': obs_folder_name,
        'StartTime': start_time.strftime('%Y-%m-%d %H:%M:%S') if start_time else None,
        'EndTime': end_time.strftime('%Y-%m-%d %H:%M:%S') if end_time else None,
        'ExposureTimeSecs': exposure_secs,
        'TotalExposures': total_exposures,
        'GoodLights': good_lights,
    }


def _fail(msg):
    """Report a fatal error and exit, in whichever shape the caller expects."""
    if JSON_MODE:
        print(jsonlib.dumps({'error': msg}))
    else:
        print(f"ERROR: {msg}")
    sys.exit(1)


def main():
    if not WORKS_ROOT.is_dir():
        _fail(f"WORKS_ROOT not found: {WORKS_ROOT}")
    from db_connect import get_driver
    if get_driver() == 'sqlite' and not DB_PATH.exists():
        _fail(f"Database not found: {DB_PATH}")

    if PROJECT_FOLDER_FILTER and not (WORKS_ROOT / PROJECT_FOLDER_FILTER).is_dir():
        # Case-insensitive fallback -- Windows folder names are
        # case-insensitive, so the caller's stored ProjectFolder casing
        # may not match disk exactly (same concern as api_sync_folder.php).
        match = next(
            (d for d in os.listdir(WORKS_ROOT) if d.lower() == PROJECT_FOLDER_FILTER.lower()),
            None
        )
        if not match:
            _fail(f"Project folder not found under {WORKS_ROOT}: {PROJECT_FOLDER_FILTER}")

    conn = get_connection()
    cur = conn.cursor()

    cur.execute('SELECT EquipmentID AS "EquipmentID" FROM Equipment')
    KNOWN_EQUIPMENT.update(str(r['EquipmentID']).strip().upper() for r in cur.fetchall() if r['EquipmentID'])

    cur.execute("""
        SELECT
            ProjectID     AS "ProjectID",
            DSOKey        AS "DSOKey",
            ProjectFolder AS "ProjectFolder"
        FROM Projects
    """)
    projects_by_folder = {row['ProjectFolder']: dict(row) for row in cur.fetchall()}

    unregistered_folders = []
    unrecognized_subfolders = []   # (top, sub, reason) -- not counted as an observation
    legacy_no_folder = []          # existing Observations rows with ObservationFolder IS NULL
    ambiguous_matches = []         # (DSOKey, top, sub, [candidate ObservationFolders]) -- can't safely pick one
    to_create = []                 # (ProjectID, DSOKey, top, fields)
    to_update = []                 # (ObservationID, DSOKey, top, sub, updates, projected_integration_mins)
    orphaned = []                  # (ObservationID, DSOKey, top, ObservationFolder) -- not found on disk
    project_integration = []       # (DSOKey, top, current_total, projected_total) -- one per touched project

    for top in sorted(os.listdir(WORKS_ROOT)):
        top_path = WORKS_ROOT / top
        if not top_path.is_dir():
            continue

        if PROJECT_FOLDER_FILTER and top.lower() != PROJECT_FOLDER_FILTER.lower():
            continue

        # Same case-insensitive concern as api_sync_folder.php: the disk
        # folder's casing may not match what's stored in ProjectFolder.
        project = projects_by_folder.get(top)
        if not project:
            project = next(
                (p for p in projects_by_folder.values()
                 if p['ProjectFolder'] and p['ProjectFolder'].lower() == top.lower()),
                None
            )
        if not project:
            unregistered_folders.append(top)
            continue

        project_id = project['ProjectID']
        dso_key = project['DSOKey']
        lights_fit_cache = {}

        cur.execute("""
            SELECT
                ObservationID      AS "ObservationID",
                ProjectID          AS "ProjectID",
                EquipmentID        AS "EquipmentID",
                ObservationDate    AS "ObservationDate",
                ObservationFolder  AS "ObservationFolder",
                StartTime          AS "StartTime",
                EndTime            AS "EndTime",
                ExposureTimeSecs   AS "ExposureTimeSecs",
                Filter             AS "Filter",
                TotalExposures     AS "TotalExposures",
                GoodLights         AS "GoodLights",
                MosaicPanel        AS "MosaicPanel",
                SeeingConditions   AS "SeeingConditions",
                Temperature        AS "Temperature",
                Humidity           AS "Humidity",
                NinaWorkflowName   AS "NinaWorkflowName",
                Notes              AS "Notes",
                IntegrationMins    AS "IntegrationMins"
            FROM Observations WHERE ProjectID = ?
        """, (project_id,))
        all_existing = [dict(r) for r in cur.fetchall()]
        # Postgres/psycopg2 returns ObservationDate as a native datetime.date
        # (SQLite returned it as plain text) -- normalize to 'YYYY-MM-DD' text
        # here so every date-string operation below (and JSON serialization
        # of legacy_no_folder further down) works the same regardless of
        # driver.
        for r in all_existing:
            if isinstance(r['ObservationDate'], (datetime.date, datetime.datetime)):
                r['ObservationDate'] = r['ObservationDate'].strftime('%Y-%m-%d')

        # Current total integration time for this project, straight from the
        # DB, before anything this run does.
        current_total = sum(
            r['IntegrationMins'] for r in all_existing if r.get('IntegrationMins') is not None
        )
        projected_delta = 0.0
        project_touched = False

        # Match key is the first 8 characters (the date) of ObservationFolder,
        # not the full string -- historical count discrepancies mean the disk
        # folder name and the stored ObservationFolder can differ (e.g. a
        # corrected exposure count). The name on disk always supersedes.
        # Rows with no ObservationFolder yet are included too, keyed by their
        # ObservationDate -- this lets them get linked to a real disk folder
        # this run (rather than being permanently unmatchable), which is what
        # actually gives you the real folder name instead of a date fallback.
        existing_by_prefix = {}
        for r in all_existing:
            folder = r['ObservationFolder']
            key = folder[:8] if folder else r['ObservationDate'].replace('-', '')
            existing_by_prefix.setdefault(key, []).append(r)

        matched_observation_ids = set()

        # Two folders for the same night and equipment (e.g.
        # 20260907_639x20s_S50P and 20260907_639x20s_AW_S50P -- the same
        # data processed twice) are one observation: count the plainer name
        # and report the other, so the night isn't counted twice.
        same_night_as = {}
        by_night = {}
        for sub in sorted(os.listdir(top_path)):
            if (top_path / sub).is_dir() and sub[:8].isdigit():
                p = parse_observation_folder(sub)
                if p:
                    by_night.setdefault((p['ObservationDate'], p['EquipmentID']), []).append(sub)
        for subs in by_night.values():
            if len(subs) > 1:
                keep = min(subs, key=lambda s: (extra_words(s), len(s), s))
                for s in subs:
                    if s != keep:
                        same_night_as[s] = keep

        for sub in sorted(os.listdir(top_path)):
            sub_path = top_path / sub
            if not sub_path.is_dir():
                continue
            if LIGHTS_FOLDER.match(sub):
                continue  # shared lights folder, not an observation folder itself

            if not sub[:8].isdigit():
                # Doesn't even start with an 8-digit date -- not an attempt
                # at an observation folder at all (e.g. "processed",
                # "masters", misc notes folders), so there's nothing to flag
                # here. Only a folder that DOES start with 8 digits but still
                # fails to parse below is worth reporting -- that one looks
                # like it was meant to be an observation folder and got the
                # naming wrong.
                continue

            if sub in same_night_as:
                unrecognized_subfolders.append(
                    (top, sub, f"same night and equipment as {same_night_as[sub]}, which is counted instead"))
                continue

            fields = derive_observation_fields(sub, lights_fit_cache, top_path)
            if not fields:
                unrecognized_subfolders.append((top, sub, observation_folder_problem(sub) or "name not recognized"))
                continue

            candidates = existing_by_prefix.get(sub[:8], [])
            match = None
            if len(candidates) == 1:
                match = candidates[0]
            elif len(candidates) > 1:
                # Same night, more than one existing row -- disambiguate by
                # equipment (different scopes the same night is plausible).
                equip_matches = [c for c in candidates if c['EquipmentID'] == fields['EquipmentID']]
                if len(equip_matches) == 1:
                    match = equip_matches[0]
                else:
                    ambiguous_matches.append(
                        (dso_key, top, sub, [c['ObservationFolder'] for c in candidates])
                    )
                    continue

            if match is None:
                to_create.append((project_id, dso_key, top, fields))
                project_touched = True
                new_mins = calc_integration_mins(
                    fields['GoodLights'], fields['TotalExposures'], fields['ExposureTimeSecs']
                )
                if new_mins is not None:
                    projected_delta += new_mins
                continue

            matched_observation_ids.add(match['ObservationID'])
            updates = {}

            # Disk name supersedes: if it differs from what's stored, update
            # ObservationFolder and refresh the name-derived fields
            # (TotalExposures/ExposureTimeSecs) unconditionally, since the
            # whole point of the mismatch is that the disk name is the
            # corrected value.
            if match['ObservationFolder'] != sub:
                updates['ObservationFolder'] = sub
                if fields['TotalExposures'] is not None:
                    updates['TotalExposures'] = fields['TotalExposures']
                if fields['ExposureTimeSecs'] is not None:
                    updates['ExposureTimeSecs'] = fields['ExposureTimeSecs']

            # Everything else: only fill true gaps, never overwrite existing
            # data (e.g. GoodLights may reflect manual curation later).
            for key in ('GoodLights', 'ExposureTimeSecs', 'TotalExposures', 'StartTime', 'EndTime'):
                if key in updates:
                    continue
                cur_val = match.get(key)
                new_val = fields.get(key)
                if (cur_val is None or cur_val == 0) and new_val is not None:
                    updates[key] = new_val

            if updates:
                project_touched = True
                # IntegrationMins itself is never written here (per the
                # separate "post update" bulk step below) -- but for
                # reporting, project what it *will* become: if this row
                # already has an IntegrationMins value, that value survives
                # untouched no matter what else changes; only a currently
                # blank IntegrationMins gets newly computed, from whatever
                # GoodLights/TotalExposures/ExposureTimeSecs will be after
                # this update (falling back to TotalExposures if GoodLights
                # is zero/missing).
                existing_mins = match.get('IntegrationMins')
                if existing_mins is not None:
                    projected_mins = existing_mins
                else:
                    eff_good_lights = updates.get('GoodLights', match.get('GoodLights'))
                    eff_total_exposures = updates.get('TotalExposures', match.get('TotalExposures'))
                    eff_exposure_secs = updates.get('ExposureTimeSecs', match.get('ExposureTimeSecs'))
                    projected_mins = calc_integration_mins(eff_good_lights, eff_total_exposures, eff_exposure_secs)
                    if projected_mins is not None:
                        projected_delta += projected_mins
                to_update.append((match['ObservationID'], dso_key, top, sub, updates, projected_mins))

        ambiguous_ids_this_project = {
            oid for (_, t, _, oids) in ambiguous_matches if t == top for oid in oids
        }
        # A row whose folder is still on disk is never deleted, even when that
        # folder isn't counted as an observation (e.g. its name has no
        # equipment code yet, or it's an _IGNORE session) -- it's reported
        # above under "not counted", and fixing the name brings it back.
        still_on_disk = {s.lower() for s in os.listdir(top_path) if (top_path / s).is_dir()}
        for r in all_existing:
            if r['ObservationID'] in matched_observation_ids or r['ObservationID'] in ambiguous_ids_this_project:
                continue
            if r['ObservationFolder'] and r['ObservationFolder'].lower() in still_on_disk:
                continue
            manual_fields = manual_data_fields_present(r)
            if r['ObservationFolder']:
                orphaned.append((r['ObservationID'], dso_key, top, r['ObservationFolder'], manual_fields))
            else:
                legacy_no_folder.append((r['ObservationID'], r['ObservationDate'], dso_key, top, manual_fields))

        if project_touched:
            project_integration.append((dso_key, top, round(current_total, 1), round(current_total + projected_delta, 1)))

    # Enforce the 1-to-1 rule: an Observations row with no corresponding disk
    # folder gets deleted, UNLESS it carries manually-entered data this
    # script can never rederive (see MANUAL_DATA_FIELDS) -- those are held
    # back and reported for manual review instead of being silently
    # destroyed. Applies to both "orphaned" (had a folder, now gone) and
    # "legacy, no ObservationFolder" (never had one) rows alike -- either
    # way, no disk folder currently backs it.
    orphaned_to_delete = [o for o in orphaned if not o[4]]
    orphaned_kept       = [o for o in orphaned if o[4]]
    legacy_to_delete    = [o for o in legacy_no_folder if not o[4]]
    legacy_kept         = [o for o in legacy_no_folder if o[4]]
    delete_ids = [o[0] for o in orphaned_to_delete] + [o[0] for o in legacy_to_delete]

    if not JSON_MODE:
        # Report
        print("=" * 70)
        print("Observation Folder Audit -- preview")
        print("=" * 70)

        print(f"\nUnregistered project folders ({len(unregistered_folders)}) -- no Projects row"
              " matches this folder name; add the DSO/Project via admin first, then re-run:")
        for f in unregistered_folders:
            print(f"  {f}")

        print(f"\nFolders not counted as observations ({len(unrecognized_subfolders)}):")
        for top, sub, why in unrecognized_subfolders:
            print(f"  {top}/{sub} -- {why}")

        print(f"\nLegacy Observations rows with no ObservationFolder set ({len(legacy_no_folder)}):")
        for obs_id, obs_date, dso_key, top, manual_fields in legacy_to_delete:
            print(f"  DELETE  {dso_key}  ({top})  observed {obs_date}  -- no disk folder ever linked")
        for obs_id, obs_date, dso_key, top, manual_fields in legacy_kept:
            print(f"  KEEP    {dso_key}  ({top})  observed {obs_date}  -- has {', '.join(manual_fields)}, review by hand")

        print(f"\nAmbiguous matches ({len(ambiguous_matches)}) -- more than one existing"
              " Observations row shares this date and equipment; skipped rather than guessing:")
        for dso_key, top, sub, candidate_folders in ambiguous_matches:
            print(f"  {dso_key}  {top}/{sub}  candidates={candidate_folders}")

        print(f"\nOrphaned Observations rows ({len(orphaned)}) -- ObservationFolder no longer found on disk:")
        for obs_id, dso_key, top, folder, manual_fields in orphaned_to_delete:
            print(f"  DELETE  {dso_key}  {top}/{folder}")
        for obs_id, dso_key, top, folder, manual_fields in orphaned_kept:
            print(f"  KEEP    {dso_key}  {top}/{folder}  -- has {', '.join(manual_fields)}, review by hand")

        print(f"\nNew Observations rows to create ({len(to_create)}):")
        for project_id, dso_key, top, fields in to_create:
            mins = calc_integration_mins(fields['GoodLights'], fields['TotalExposures'], fields['ExposureTimeSecs'])
            print(f"  {dso_key} ({top}/{fields['ObservationFolder']}): "
                  f"date={fields['ObservationDate']} equip={fields['EquipmentID']} "
                  f"exposures={fields['TotalExposures']} exp_secs={fields['ExposureTimeSecs']} "
                  f"good_lights={fields['GoodLights']} integration_mins={mins}")

        print(f"\nExisting Observations rows to fill gaps in ({len(to_update)}):")
        for obs_id, dso_key, top, sub, updates, projected_mins in to_update:
            print(f"  {dso_key} ({top}/{sub}): {updates}  integration_mins={projected_mins}")

        print(f"\nProjected Total Integration Time by project ({len(project_integration)} touched):")
        for dso_key, top, current_total, projected_total in project_integration:
            print(f"  {dso_key} ({top}): {current_total} min -> {projected_total} min")

    # Preview/JSON payload builders shared by every branch below -- 'orphaned'
    # and 'legacyNoFolder' now mean specifically "kept back for manual review"
    # (they carry manual data this script won't destroy); rows with nothing
    # manual attached don't appear there any more, they're just gone.
    def _orphaned_payload():
        return [{'DSOKey': d, 'Top': t, 'ObservationFolder': f, 'ManualFields': mf}
                 for _, d, t, f, mf in orphaned_kept]

    def _legacy_payload():
        return [{'DSOKey': d, 'Top': t, 'ObservationDate': od, 'ManualFields': mf}
                 for _, od, d, t, mf in legacy_kept]

    def _to_delete_payload():
        return (
            [{'DSOKey': d, 'Top': t, 'ObservationFolder': f, 'Reason': 'orphaned'}
             for _, d, t, f, _ in orphaned_to_delete] +
            [{'DSOKey': d, 'Top': t, 'ObservationDate': od, 'Reason': 'no-folder-ever-linked'}
             for _, od, d, t, _ in legacy_to_delete]
        )

    if not to_create and not to_update and not delete_ids:
        if JSON_MODE:
            print(jsonlib.dumps({
                'success': True,
                'applied': False,
                'created': [],
                'updated': [],
                'deleted': [],
                'orphaned': _orphaned_payload(),
                'ambiguous': [{'DSOKey': d, 'Top': t, 'Sub': s, 'Candidates': c} for d, t, s, c in ambiguous_matches],
                'unrecognized': [{'Top': t, 'Sub': s, 'Reason': r} for t, s, r in unrecognized_subfolders],
                'legacyNoFolder': _legacy_payload(),
                'projectedIntegration': [
                    {'DSOKey': d, 'Top': t, 'CurrentMins': c, 'ProjectedMins': p}
                    for d, t, c, p in project_integration
                ],
            }))
        else:
            if ambiguous_matches:
                print(f"\nNothing to auto-apply, but {len(ambiguous_matches)} ambiguous match(es)"
                      " above need manual attention (e.g. fix a duplicate/stale ObservationFolder"
                      " by hand, then re-run).")
            else:
                print("\nNothing to create, update, or delete -- database already matches disk.")
        conn.close()
        return

    if not JSON_MODE:
        print(f"\nThis will also DELETE {len(delete_ids)} Observations row(s) with no matching disk"
              " folder (see DELETE lines above).")
        answer = input("Apply the creates/updates/deletes above in one transaction? [y/N] ").strip().lower()
        if answer != 'y':
            print("Aborted. No changes made.")
            conn.close()
            return
    elif DRY_RUN:
        print(jsonlib.dumps({
            'success': True,
            'applied': False,
            'dryRun': True,
            'created': [{'DSOKey': d, 'Top': t, 'ObservationFolder': f['ObservationFolder']} for _, d, t, f in to_create],
            'updated': [{'DSOKey': d, 'Top': t, 'Sub': s, 'Updates': u} for _, d, t, s, u, _ in to_update],
            'toDelete': _to_delete_payload(),
            'orphaned': _orphaned_payload(),
            'ambiguous': [{'DSOKey': d, 'Top': t, 'Sub': s, 'Candidates': c} for d, t, s, c in ambiguous_matches],
            'unrecognized': [{'Top': t, 'Sub': s, 'Reason': r} for t, s, r in unrecognized_subfolders],
            'legacyNoFolder': _legacy_payload(),
            'projectedIntegration': [
                {'DSOKey': d, 'Top': t, 'CurrentMins': c, 'ProjectedMins': p}
                for d, t, c, p in project_integration
            ],
        }))
        conn.close()
        return

    try:
        cur.execute("BEGIN TRANSACTION")
        for project_id, dso_key, top, fields in to_create:
            cur.execute("""
                INSERT INTO Observations
                    (ProjectID, EquipmentID, ObservationDate, ObservationFolder,
                     StartTime, EndTime, ExposureTimeSecs, TotalExposures, GoodLights)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
            """, (
                project_id, fields['EquipmentID'], fields['ObservationDate'], fields['ObservationFolder'],
                fields['StartTime'], fields['EndTime'], fields['ExposureTimeSecs'],
                fields['TotalExposures'], fields['GoodLights']
            ))

        for obs_id, dso_key, top, sub, updates, projected_mins in to_update:
            set_clause = ', '.join(f"{k} = ?" for k in updates)
            cur.execute(f"UPDATE Observations SET {set_clause} WHERE ObservationID = ?",
                        (*updates.values(), obs_id))

        # Enforce the 1-to-1 rule: delete every Observations row with no
        # corresponding disk folder and no manually-entered data attached
        # (see MANUAL_DATA_FIELDS / delete_ids above). Cascades harmlessly to
        # any ProcessingObservations rows for these (ON DELETE CASCADE).
        deleted_count = 0
        if delete_ids:
            placeholders = ', '.join('?' for _ in delete_ids)
            cur.execute(f"DELETE FROM Observations WHERE ObservationID IN ({placeholders})",
                        tuple(delete_ids))
            deleted_count = cur.rowcount

        # Post-update step, deliberately separate from the per-row inserts/updates
        # above: fill IntegrationMins wherever it's still blank. Never touches a
        # row that already has an IntegrationMins value -- that's what preserves
        # a manual correction (e.g. for an observation folder with FIT files
        # carried over from a previous night). Uses GoodLights normally, but
        # falls back to TotalExposures when GoodLights is 0 or NULL (e.g. the
        # FIT date-boundary matching found nothing for that specific night even
        # though a shot count was recorded), so a real observation never gets
        # silently recorded as zero minutes.
        cur.execute("""
            UPDATE Observations
            SET IntegrationMins = ROUND(
                (CASE
                    WHEN GoodLights IS NOT NULL AND GoodLights > 0
                        THEN GoodLights * ExposureTimeSecs
                    ELSE TotalExposures * ExposureTimeSecs
                END / 60.0)::numeric, 1)
            WHERE IntegrationMins IS NULL
              AND ExposureTimeSecs IS NOT NULL
              AND (
                    (GoodLights IS NOT NULL AND GoodLights > 0)
                    OR (TotalExposures IS NOT NULL AND TotalExposures > 0)
              )
        """)
        integration_filled = cur.rowcount

        conn.commit()

        if JSON_MODE:
            print(jsonlib.dumps({
                'success': True,
                'applied': True,
                'created': [{'DSOKey': d, 'Top': t, 'ObservationFolder': f['ObservationFolder']} for _, d, t, f in to_create],
                'updated': [{'DSOKey': d, 'Top': t, 'Sub': s, 'Updates': u} for _, d, t, s, u, _ in to_update],
                'deleted': _to_delete_payload(),
                'integrationFilled': integration_filled,
                'orphaned': _orphaned_payload(),
                'ambiguous': [{'DSOKey': d, 'Top': t, 'Sub': s, 'Candidates': c} for d, t, s, c in ambiguous_matches],
                'unrecognized': [{'Top': t, 'Sub': s, 'Reason': r} for t, s, r in unrecognized_subfolders],
                'legacyNoFolder': _legacy_payload(),
                'projectedIntegration': [
                    {'DSOKey': d, 'Top': t, 'CurrentMins': c, 'ProjectedMins': p}
                    for d, t, c, p in project_integration
                ],
            }))
        else:
            print(f"\nCOMMIT successful. Created {len(to_create)}, updated {len(to_update)}, "
                  f"deleted {deleted_count}, IntegrationMins filled for {integration_filled} row(s).")
    except Exception as e:
        conn.rollback()
        if JSON_MODE:
            print(jsonlib.dumps({'success': False, 'error': str(e)}))
        else:
            print(f"\nERROR -- rolled back, no changes were kept: {e}")
        sys.exit(1)
    finally:
        conn.close()


if __name__ == '__main__':
    main()
