"""
Calculates and lists deep-sky objects (DSOs) visible from a specified location on a given date.
Web version with sortable output: Outputs HTML for browser display with dropdown to change sort order.
"""
import csv
import datetime
import numpy as np
import pandas as pd
from zoneinfo import ZoneInfo
from skyfield.api import load, Topos, Star, Angle
from skyfield.almanac import dark_twilight_day, find_discrete
from astropy.coordinates import SkyCoord
import sys
import json
import argparse
from profile_manager import load_profile

# No longer hardcoded - these come from profiles now
# See profile_manager.py for profile management
LOCATION_NAME = 'Star, Idaho'
LAT_DEG = 43.69
LON_DEG = -116.49
TIME_ZONE = 'America/Boise'
MIN_ALTITUDE_DEG = 25.0
AZ_MIN_DEG = 10.0  # Due North
AZ_MAX_DEG = 145.0  # Due South (Eastern Sky)

def get_viewing_window(target_date, ts, eph, observer):
    """
    Determines the viewing window from astronomical twilight end to astronomical sunrise.
    """
    t0 = ts.utc(target_date.year, target_date.month, target_date.day, 12)
    t1 = ts.utc(target_date.year, target_date.month, target_date.day + 2, 12)

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

        if event == 1 and next_event == 0 and viewing_start is None and t_local.date() >= target_date:
            viewing_start = t

        if viewing_start is not None and event == 0 and next_event == 1 and viewing_end is None:
            viewing_end = times[i + 1]
            break

    return viewing_start, viewing_end


def calculate_visibility(target_date=None, profile_name='default'):
    """
    Main function to calculate visibility of objects and output HTML with sorting capability.
    
    Args:
        target_date: datetime.date object or None for today
        profile_name: Name of location profile to use
    """
    if target_date is None:
        target_date = datetime.date.today()
    
    # Load profile
    profile = load_profile(profile_name)
    if profile is None:
        print(f"<p>Error: Could not load profile '{profile_name}'</p>")
        return
    
    # Extract settings from profile
    LOCATION_NAME = profile['location']
    LAT_DEG = profile['latitude']
    LON_DEG = profile['longitude']
    TIME_ZONE = profile['timezone']
    MIN_ALTITUDE_DEG = profile['min_altitude']
    AZ_MIN_DEG = profile['az_min']
    AZ_MAX_DEG = profile['az_max']

    # Setup Skyfield
    ts = load.timescale(builtin=True)
    eph = load('de421.bsp')
    observer = Topos(LAT_DEG, LON_DEG)
    earth = eph['earth']
    observer_pos = earth + observer

    tz = ZoneInfo(TIME_ZONE)

    # Get viewing window
    viewing_start, viewing_end = get_viewing_window(target_date, ts, eph, observer)

    if viewing_start is None or viewing_end is None:
        print("<p>Error: Could not determine astronomical twilight times.</p>")
        return

    start_local = viewing_start.astimezone(tz)
    end_local = viewing_end.astimezone(tz)

    # Create time array (1-minute intervals)
    duration_minutes = int((viewing_end.utc_datetime() - viewing_start.utc_datetime()).total_seconds() / 60)
    time_range = ts.linspace(viewing_start, viewing_end, duration_minutes)

    visible_objects = []

    try:
        log = []
        sheet_id = '1ntqVhvlPvBZFG59KJVQgiIdV65MeYnYBin5CT0alpsA'
        sheet_name = 'dso_watchlist'
        csv_url = f'https://docs.google.com/spreadsheets/d/{sheet_id}/gviz/tq?tqx=out:csv&sheet={sheet_name}'
        df = pd.read_csv(csv_url)

        for index, row in df.iterrows():
            name = row['Name']
            aka = row['Aka']
            type_desc = row['TypeDesc']
            constellation = row['Constellation']
            size = row['SqArcMins']
            magnitude = row['Mag']
            want_better = row.get('WantBetter', False)
            do_me = '&#9733;' if str(want_better).upper() == 'TRUE' else ''

            try:
                obj = SkyCoord.from_name(str(name))
                star = Star(ra=Angle(degrees=obj.ra.deg), dec=Angle(degrees=obj.dec.deg))
            except Exception as e:
                log.append(f"Error resolving {name}: {e}")
                continue

            astrometric = observer_pos.at(time_range).observe(star)
            alt, az, _ = astrometric.apparent().altaz()

            is_visible = (alt.degrees >= MIN_ALTITUDE_DEG) & \
                         (az.degrees >= AZ_MIN_DEG) & \
                         (az.degrees <= AZ_MAX_DEG)

            visible_indices = np.where(is_visible)[0]

            if len(visible_indices) > 0:
                start_idx = visible_indices[0]
                end_idx = visible_indices[-1]

                obj_start = time_range[start_idx].astimezone(tz)
                obj_end = time_range[end_idx].astimezone(tz)
                time_span = (obj_end - obj_start).total_seconds() / 60
                start_minutes = obj_start.hour * 60 + obj_start.minute
                if obj_start.hour < 12:
                    start_minutes += 24 * 60  # Adjust for sorting past midnight
                end_minutes = obj_end.hour * 60 + obj_end.minute
                if obj_end.hour < 12:
                    end_minutes += 24 * 60  # Adjust for sorting past midnight
                
                # Get altitude and azimuth at start and end times
                start_alt = alt.degrees[start_idx]
                start_az = az.degrees[start_idx]
                end_alt = alt.degrees[end_idx]
                end_az = az.degrees[end_idx]
                
                if time_span >= 60:
                    visible_objects.append({
                        'do_me': do_me,
                        'name': name,
                        'aka': aka,
                        'start': obj_start,
                        'start_minutes': start_minutes,  # For sorting
                        'end': obj_end,
                        'end_minutes': end_minutes,
                        'duration': time_span,
                        'size': size,
                        'magnitude': magnitude,
                        'constellation': constellation,
                        'type_desc': type_desc,
                        'start_alt': start_alt,
                        'start_az': start_az,
                        'end_alt': end_alt,
                        'end_az': end_az
                    })
        if len(log) > 0:
            # write log to dso_visibility.log
            with open('dso_visibility.log', 'a') as log_file:
                for entry in log:
                    log_file.write(f"{datetime.datetime.now().isoformat()} - {entry}\n")
    except Exception as e:
        print(f"<p>Error reading data: {e}</p>")
        return

    def safe_float(value, default=0.0):
        if pd.isna(value) or value is None or value == '':
            return default
        try:
            return float(value)
        except (ValueError, TypeError):
            try:
                return float(str(value))
            except Exception:
                return default

    def safe_str(value, default=''):
        if pd.isna(value) or value is None:
            return default
        # Handle numeric numpy and python types cleanly
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
        try:
            ts = pd.to_datetime(value, errors='coerce')
            if not pd.isna(ts):
                return ts.strftime('%H:%M')
        except Exception:
            pass
        return ''

    # If you still have the `most_recent = row['MostRecent'] + row['S50Date']` line,
    # replace it with a safe concatenation like:
    # most_recent = safe_str(row.get('MostRecent')) + safe_str(row.get('S50Date'))

    objects_json = json.dumps([{
        'do_me': safe_str(obj.get('do_me', '')),
        'name': safe_str(obj.get('name', '')),
        'aka': safe_str(obj.get('aka', '')),
        'start': safe_time_str(obj.get('start')),
        'start_minutes': int(obj.get('start_minutes') or 0),
        'end': safe_time_str(obj.get('end')),
        'end_minutes': int(obj.get('end_minutes') or 0),
        'duration': safe_float(obj.get('duration')),
        'size': safe_float(obj.get('size')),
        'magnitude': safe_float(obj.get('magnitude')),
        'constellation': safe_str(obj.get('constellation')),
        'type_desc': safe_str(obj.get('type_desc')),
        'start_alt': safe_float(obj.get('start_alt')),
        'start_az': safe_float(obj.get('start_az')),
        'end_alt': safe_float(obj.get('end_alt')),
        'end_az': safe_float(obj.get('end_az'))
    } for obj in visible_objects])


    # Output HTML
    target_date_str = target_date.strftime('%Y-%m-%d')

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
        #force-rebuild-btn, controls button {{
            padding: 8px 16px;
            background: #4a9eff !important;
            color: #ffffff !important;
            border: 1px solid #4a9eff !important;
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
    </style>
</head>
<body>
    <h1>DSO Visibility Report</h1>
    <div class="info">
        <p><strong>Location:</strong> {LOCATION_NAME}</p>
        <p><strong>Viewing Window:</strong> {start_local.strftime('%H:%M %Z')} to {end_local.strftime('%H:%M %Z')}</p>
        <p><strong>Criteria:</strong> Altitude &gt;= {MIN_ALTITUDE_DEG}&deg;, Azimuth {AZ_MIN_DEG}&deg;-{AZ_MAX_DEG}&deg;</p>
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
            <option value="size">Size (largest first)</option>
            <option value="name">Name (A-Z)</option>
            <option value="aka">Friendly Name</option>
        </select>
        <button id="force-rebuild-btn" onclick="window.location.href=window.location.pathname + '?date={target_date_str}&profile={profile_name}&rebuild=1'">Force Rebuild</button>
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
                <th>Size (sq')</th>
                <th>Mag</th>
                <th>Constellation</th>
                <th>Type</th>
            </tr>
        </thead>
        <tbody id="tableBody">
        </tbody>
    </table>
    <div class="info" style="margin-top: 20px;">
        <p><strong>Total visible objects:</strong> <span id="totalCount"></span></p>
        <p><strong>&#9733;</strong> = Priority target (not recently observed)</p>
    </div>

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
                    <td>${obj.size.toFixed(0)}</td>
                    <td>${obj.magnitude.toFixed(1)}</td>
                    <td>${obj.constellation}</td>
                    <td>${obj.type_desc}</td>
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
                case 'size':
                    sortedData.sort((a, b) => b.size - a.size);
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
</body>
</html>
""")


if __name__ == '__main__':
    parser = argparse.ArgumentParser(description='Calculate DSO visibility for a given date')
    parser.add_argument('--date', type=str, help='Date in YYYY-MM-DD format (default: today)')
    parser.add_argument('--profile', type=str, default='default', help='Profile name to use (default: default)')
    args = parser.parse_args()
    
    target_date = None
    if args.date:
        try:
            target_date = datetime.datetime.strptime(args.date, '%Y-%m-%d').date()
        except ValueError:
            print("<p>Error: Invalid date format. Use YYYY-MM-DD</p>")
            sys.exit(1)
    
    calculate_visibility(target_date, args.profile)
