"""
Calculates and lists deep-sky objects (DSOs) visible from a specified location on a given date.
Web version with sortable output: Outputs HTML for browser display with dropdown to change sort order.
"""
import datetime
import sqlite3
import numpy as np
from zoneinfo import ZoneInfo
from skyfield.api import load, Topos, Star, Angle
from skyfield.almanac import dark_twilight_day, find_discrete
import sys
import json
import argparse
from profile_manager import load_profile
from pathlib import Path

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


def calculate_visibility(specified_date=None, profile_name='default'):
    """
    Main function to calculate visibility of objects and output HTML with sorting capability.
    
    Args:
        specified_date: datetime.date object or None for today
        profile_name: Name of location profile to use
    """
    if specified_date is None:
        specified_date = datetime.date.today()
    
    # Load profile
    profile = load_profile(profile_name)
    if profile is None:
        print(f"<p>Error: Could not load profile '{profile_name}'</p>")
        return
    
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

    visible_objects = []

    try:
        log = []
        conn = sqlite3.connect(ASTRO_DB)
        conn.row_factory = sqlite3.Row
        cur = conn.cursor()
        cur.execute("""
            SELECT
                o.DSOKey,
                o.CommonName,
                ot.TypeName  AS TypeDesc,
                con.Name     AS Constellation,
                o.Magnitude,
                o.RAHours,
                o.DecDegrees,
                o.WantBetter,
                o.SqArcMins
            FROM Objects o
            LEFT JOIN ObjectTypes  ot  ON o.ObjectTypeID  = ot.ObjectTypeID
            LEFT JOIN Constellations con ON o.ConstellationID = con.ConstellationID
            WHERE o.RAHours IS NOT NULL
              AND o.DecDegrees IS NOT NULL
              AND o.ObjectTypeID NOT IN ('SOLAR_SYSTEM', 'SINGLE_STAR')
        """)
        dso_rows = cur.fetchall()
        conn.close()

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
        if log:
            with open('dso_visibility.log', 'a') as log_file:
                for entry in log:
                    log_file.write(f"{datetime.datetime.now().isoformat()} - {entry}\n")
    except Exception as e:
        print(f"<p>Error reading data: {e}</p>")
        return

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


    # Output HTML
    target_date_str = specified_date.strftime('%Y-%m-%d')

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
    <h1>DSO Visibility Report</h1>
    <div class="info">
        <p><strong>Location:</strong> {location_name}</p>
        <p><strong>Viewing Window:</strong> {start_local.strftime('%H:%M %Z')} to {end_local.strftime('%H:%M %Z')}</p>
        <p><strong>Criteria:</strong> Altitude &gt;= {minimum_altitude}&deg;, Azimuth {azimuth_minimum_degrees}&deg;-{azimuth_maximum_degrees}&deg;</p>
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
        <button id="force-rebuild-btn" onclick="window.location.href=window.location.pathname + '?date={target_date_str}&profile={profile_name}&rebuild=1'">Force Rebuild</button>
        <button id="quickadd-btn" onclick="openQuickAdd()" style="background:#3fb950 !important; border-color:#3fb950 !important;">&#43; Quick Add DSO</button>
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
                <th>Size (arcmin²)</th>
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
    title.textContent = d.DSOKey + (d.CommonName ? ' \u2013 ' + d.CommonName : '');

    function fv(v) { return (v !== null && v !== undefined && v !== '') ? String(v) : '\u2014'; }
    function fn(v, dec) { return (v !== null && v !== undefined && v !== '') ? parseFloat(v).toFixed(dec ?? 2) : '\u2014'; }

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
        <div class="modal-field"><span class="modal-field-label">RA (hours)</span><span class="modal-field-value">${fn(d.RAHours, 4)}</span></div>
        <div class="modal-field"><span class="modal-field-label">Dec (degrees)</span><span class="modal-field-value">${fn(d.DecDegrees, 4)}</span></div>
        <div class="modal-field"><span class="modal-field-label">Magnitude</span><span class="modal-field-value">${fn(d.Magnitude, 1)}</span></div>
        <div class="modal-field"><span class="modal-field-label">Size (arcmin&sup2;)</span><span class="modal-field-value">${d.SqArcMins ? parseFloat(d.SqArcMins).toFixed(0) : '\u2014'}</span></div>
        <div class="modal-field modal-full"><span class="modal-field-label">Object Size</span><span class="modal-field-value">${fv(d.ObjectSize)}</span></div>
        ${wantBetterHtml}
      </div>
    </div>`;

    // Observation & Project
    const integStr = d.TotalIntegrationMins ? (parseFloat(d.TotalIntegrationMins)/60).toFixed(1) + ' hrs' : '\u2014';
    html += `<div class="modal-section">
      <div class="modal-section-header">Observation &amp; Project</div>
      <div class="modal-section-body">
        <div class="modal-field"><span class="modal-field-label">Project Folder</span><span class="modal-field-value">${fv(d.ProjectFolder)}</span></div>
        <div class="modal-field"><span class="modal-field-label">Mosaic?</span><span class="modal-field-value">${d.IsMosaic ? 'Yes' : 'No'}</span></div>
        <div class="modal-field"><span class="modal-field-label">Last Observed</span><span class="modal-field-value">${fv(d.MostRecentObservation)}</span></div>
        <div class="modal-field"><span class="modal-field-label">Total Lights</span><span class="modal-field-value">${fv(d.TotalLights)}</span></div>
        <div class="modal-field"><span class="modal-field-label">Integration Time</span><span class="modal-field-value">${integStr}</span></div>
      </div>
    </div>`;

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
  status.textContent = 'Looking up ' + dsoKey + '\u2026';

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
      status.textContent = name + ' added! Rebuilding visibility report\u2026';
      setTimeout(() => {
        window.location.href = '/vis?date={target_date_str}&profile={profile_name}&rebuild=1';
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
    args = parser.parse_args()
    
    target_date = None
    if args.date:
        try:
            target_date = datetime.datetime.strptime(args.date, '%Y-%m-%d').date()
        except ValueError:
            print("<p>Error: Invalid date format. Use YYYY-MM-DD</p>")
            sys.exit(1)
    
    calculate_visibility(target_date, args.profile)
