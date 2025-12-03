"""
Calculates and lists deep-sky objects (DSOs) visible from a specified location on a given date.
Web version: Outputs HTML for browser display.
Sorted by viewing duration (longest first).
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

# --- Configuration ---
CSV_FILE = r"G:\My Drive\Astronomy\dso_watchlist.csv"
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


def calculate_visibility():
    """
    Main function to calculate visibility of objects and output HTML.
    """
    target_date = datetime.date.today()

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
            most_recent = row['MostRecent']
            do_me = '&#9733;' if len(str(most_recent)) < 4 else ''
            
            try:
                obj = SkyCoord.from_name(name)
                star = Star(ra=Angle(degrees=obj.ra.deg), dec=Angle(degrees=obj.dec.deg))
            except Exception as e:
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
                
                if time_span >= 60:
                    visible_objects.append({
                        'do_me': do_me,
                        'name': name,
                        'aka': aka,
                        'start': obj_start,
                        'end': obj_end,
                        'duration': time_span,
                        'size': size,
                        'magnitude': magnitude,
                        'constellation': constellation,
                        'type_desc': type_desc
                    })

    except Exception as e:
        print(f"<p>Error reading data: {e}</p>")
        return

    # Sort by viewing duration in descending order
    visible_objects.sort(key=lambda x: x['duration'], reverse=True)

    # Output HTML
    target_date_str = target_date.strftime('%Y-%m-%d')
    
    print("""<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>DSO Visibility Report - {}</title>
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
        }}
    </style>
</head>
<body>
    <h1>DSO Visibility Report for {}</h1>
    <div class="info">
        <p><strong>Location:</strong> {}</p>
        <p><strong>Viewing Window:</strong> {} to {}</p>
        <p><strong>Criteria:</strong> Altitude &gt;= {}&deg;, Azimuth {}&deg;-{}&deg;</p>
        <p><strong>Sorted by:</strong> Viewing Duration (longest first)</p>
    </div>
""".format(
        target_date_str, target_date_str, LOCATION_NAME,
        start_local.strftime('%H:%M %Z'), end_local.strftime('%H:%M %Z'),
        MIN_ALTITUDE_DEG, AZ_MIN_DEG, AZ_MAX_DEG
    ))

    if not visible_objects:
        print("<p>No objects meet the visibility criteria for this date.</p>")
    else:
        print("""
    <table>
        <thead>
            <tr>
                <th>Priority</th>
                <th>Name</th>
                <th>Also Known As</th>
                <th>Start</th>
                <th>End</th>
                <th>Duration</th>
                <th>Size (sq')</th>
                <th>Mag</th>
                <th>Constellation</th>
                <th>Type</th>
            </tr>
        </thead>
        <tbody>
""")
        
        for obj in visible_objects:
            duration_hrs = obj['duration'] / 60
            duration_str = f"{duration_hrs:.1f}h" if duration_hrs >= 1 else f"{obj['duration']:.0f}m"
            
            print(f"""
            <tr>
                <td class="priority">{obj['do_me']}</td>
                <td><strong>{obj['name']}</strong></td>
                <td>{obj['aka']}</td>
                <td class="time">{obj['start'].strftime('%H:%M')}</td>
                <td class="time">{obj['end'].strftime('%H:%M')}</td>
                <td class="duration">{duration_str}</td>
                <td>{obj['size']:.0f}</td>
                <td>{obj['magnitude']:.1f}</td>
                <td>{obj['constellation']}</td>
                <td>{obj['type_desc']}</td>
            </tr>
""")
        
        print("""
        </tbody>
    </table>
    <div class="info" style="margin-top: 20px;">
        <p><strong>Total visible objects:</strong> {}</p>
        <p><strong>&#9733;</strong> = Priority target (not recently observed)</p>
    </div>
</body>
</html>
""".format(len(visible_objects)))


if __name__ == '__main__':
    calculate_visibility()
