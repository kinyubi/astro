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
      - Report (never delete) any existing Observations row whose date wasn't
        found among this run's disk folders: "orphaned" if it had a folder
        recorded, "legacy, no ObservationFolder" (with its ObservationDate
        shown, since that's all there is) if it never had one and none was
        found to link it to this run either.
      - Report (skip) any subfolder that doesn't match either observation
        folder pattern or the lights_ folder pattern, so nothing is silently
        ignored without you seeing it.

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
"""

import re
import sys
import os
import sqlite3
import datetime
from pathlib import Path

WORKS_ROOT = Path(r"C:\Astronomy\MyWorks")
DB_PATH = Path(__file__).parent.parent / 'dsodb' / 'astro.db'

# Parse remaining args (allow overriding DB path / works root for testing)
args = sys.argv[1:]
for a in list(args):
    if a.startswith('--works-root='):
        WORKS_ROOT = Path(a.split('=', 1)[1])
        args.remove(a)
if args:
    DB_PATH = Path(args[0])

# Patterns from DB_REWORK_PLAN.md section 3
OBS_FOLDER_WITH_COUNT = re.compile(r'^(\d{8})_(\d+)x(\d+)s_([A-Z0-9]+)$', re.IGNORECASE)
OBS_FOLDER_DATE_EQUIP = re.compile(r'^(\d{8})_([A-Z0-9]+)$', re.IGNORECASE)
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


def parse_observation_folder(name):
    """Returns a dict of what the folder name itself tells us, or None if it
    doesn't match either known pattern."""
    m = OBS_FOLDER_WITH_COUNT.match(name)
    if m:
        date_str, total_exp, exp_secs, equip = m.groups()
        return {
            'ObservationDate': date_str,
            'EquipmentID': equip.upper(),
            'TotalExposures': int(total_exp),
            'ExposureTimeSecs': float(exp_secs),
        }
    m = OBS_FOLDER_DATE_EQUIP.match(name)
    if m:
        date_str, equip = m.groups()
        return {
            'ObservationDate': date_str,
            'EquipmentID': equip.upper(),
            'TotalExposures': None,
            'ExposureTimeSecs': None,
        }
    return None


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


def main():
    if not WORKS_ROOT.is_dir():
        print(f"ERROR: WORKS_ROOT not found: {WORKS_ROOT}")
        sys.exit(1)
    if not DB_PATH.exists():
        print(f"ERROR: Database not found: {DB_PATH}")
        sys.exit(1)

    conn = sqlite3.connect(DB_PATH)
    conn.row_factory = sqlite3.Row
    cur = conn.cursor()

    cur.execute("SELECT ProjectID, DSOKey, ProjectFolder FROM Projects")
    projects_by_folder = {row['ProjectFolder']: dict(row) for row in cur.fetchall()}

    unregistered_folders = []
    unrecognized_subfolders = []   # (top, sub) -- doesn't match any known pattern
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

        project = projects_by_folder.get(top)
        if not project:
            unregistered_folders.append(top)
            continue

        project_id = project['ProjectID']
        dso_key = project['DSOKey']
        lights_fit_cache = {}

        cur.execute("SELECT * FROM Observations WHERE ProjectID = ?", (project_id,))
        all_existing = [dict(r) for r in cur.fetchall()]

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

        for sub in sorted(os.listdir(top_path)):
            sub_path = top_path / sub
            if not sub_path.is_dir():
                continue
            if LIGHTS_FOLDER.match(sub):
                continue  # shared lights folder, not an observation folder itself

            fields = derive_observation_fields(sub, lights_fit_cache, top_path)
            if not fields:
                unrecognized_subfolders.append((top, sub))
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
        for r in all_existing:
            if r['ObservationID'] in matched_observation_ids or r['ObservationID'] in ambiguous_ids_this_project:
                continue
            if r['ObservationFolder']:
                orphaned.append((r['ObservationID'], dso_key, top, r['ObservationFolder']))
            else:
                legacy_no_folder.append((r['ObservationID'], r['ObservationDate'], dso_key, top))

        if project_touched:
            project_integration.append((dso_key, top, round(current_total, 1), round(current_total + projected_delta, 1)))

    # Report
    print("=" * 70)
    print("Observation Folder Audit -- preview")
    print("=" * 70)

    print(f"\nUnregistered project folders ({len(unregistered_folders)}) -- no Projects row"
          " matches this folder name; add the DSO/Project via admin first, then re-run:")
    for f in unregistered_folders:
        print(f"  {f}")

    print(f"\nUnrecognized subfolders ({len(unrecognized_subfolders)}) -- didn't match either"
          " observation-folder pattern or the lights_ pattern, skipped:")
    for top, sub in unrecognized_subfolders:
        print(f"  {top}/{sub}")

    print(f"\nLegacy Observations rows with no ObservationFolder set ({len(legacy_no_folder)})"
          " -- pre-existing data this script won't try to auto-link:")
    for obs_id, obs_date, dso_key, top in legacy_no_folder:
        print(f"  {dso_key}  ({top})  observed {obs_date}")

    print(f"\nAmbiguous matches ({len(ambiguous_matches)}) -- more than one existing"
          " Observations row shares this date and equipment; skipped rather than guessing:")
    for dso_key, top, sub, candidate_folders in ambiguous_matches:
        print(f"  {dso_key}  {top}/{sub}  candidates={candidate_folders}")

    print(f"\nOrphaned Observations rows ({len(orphaned)}) -- ObservationFolder no longer"
          " found on disk (NOT deleted, just flagged):")
    for obs_id, dso_key, top, folder in orphaned:
        print(f"  {dso_key}  {top}/{folder}")

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

    if not to_create and not to_update:
        if ambiguous_matches:
            print(f"\nNothing to auto-apply, but {len(ambiguous_matches)} ambiguous match(es)"
                  " above need manual attention (e.g. fix a duplicate/stale ObservationFolder"
                  " by hand, then re-run).")
        else:
            print("\nNothing to create or update -- database already matches disk.")
        conn.close()
        return

    answer = input("\nApply the creates/updates above in one transaction? [y/N] ").strip().lower()
    if answer != 'y':
        print("Aborted. No changes made.")
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
                CASE
                    WHEN GoodLights IS NOT NULL AND GoodLights > 0
                        THEN GoodLights * ExposureTimeSecs
                    ELSE TotalExposures * ExposureTimeSecs
                END / 60.0, 1)
            WHERE IntegrationMins IS NULL
              AND ExposureTimeSecs IS NOT NULL
              AND (
                    (GoodLights IS NOT NULL AND GoodLights > 0)
                    OR (TotalExposures IS NOT NULL AND TotalExposures > 0)
              )
        """)
        integration_filled = cur.rowcount

        conn.commit()
        print(f"\nCOMMIT successful. Created {len(to_create)}, updated {len(to_update)}, "
              f"IntegrationMins filled for {integration_filled} row(s).")
    except Exception as e:
        conn.rollback()
        print(f"\nERROR -- rolled back, no changes were kept: {e}")
        sys.exit(1)
    finally:
        conn.close()


if __name__ == '__main__':
    main()
