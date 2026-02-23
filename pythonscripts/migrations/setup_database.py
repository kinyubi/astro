"""
Astro Database Setup Script
===========================
This script initializes the SQLite database and populates it from existing data sources:
- dso_watchlist_info.json for object metadata
- myWorks folder structure for projects and observations
- Web images folder for published images

Run this script from C:\laragon7\www\astro\pythonscripts:
    python setup_database.py
"""

import sqlite3
import json
import os
import re
from datetime import datetime
from pathlib import Path

# Configuration
DB_PATH = r"C:\laragon7\www\astro\dsodb\astro.db"
SCHEMA_PATH = r"C:\laragon7\www\astro\dsodb\schema_v1.sql"
JSON_PATH = r"C:\laragon7\www\astro\public\dso_watchlist_info.json"
MYWORKS_PATH = r"C:\Astronomy\myWorks"
WEB_IMAGES_PATH = r"C:\laragon7\www\astro\public\images"

# Constellation name to IAU code mapping
CONSTELLATION_MAP = {
    'Andromeda': 'AND',
    'Aquarius': 'AQR',
    'Aquila': 'AQL',
    'Aries': 'ARI',
    'Auriga': 'AUR',
    'Canis Major': 'CMA',
    'Canis Minor': 'CMI',
    'Cancer': 'CNC',
    'Canes Venatici': 'CVN',
    'Cassiopeia': 'CAS',
    'Cassiopeia/Cepheus': 'CAS',  # Map to primary
    'Cepheus': 'CEP',
    'Cepheus/Cygnus': 'CEP',  # Map to primary
    'Cygnus': 'CYG',
    'Dorado': 'DOR',
    'Eridanus': 'ERI',
    'Fornax': 'FOR',
    'Gemini': 'GEM',
    'Hercules': 'HER',
    'Leo': 'LEO',
    'Lyra': 'LYR',
    'Monoceros': 'MON',
    'Monoceros ': 'MON',  # Handle trailing space
    'Orion': 'ORI',
    'Pegasus': 'PEG',
    'Perseus': 'PER',
    'Pisces': 'PSC',
    'Sagittarius': 'SGR',
    'Serpens': 'SER',
    'Taurus': 'TAU',
    'Triangulum': 'TRI',
    'Ursa Major': 'UMA',
    'Ursa Minor': 'UMI',
    'Virgo': 'VIR',
    'Vulpecula': 'VUL',
}

# Type description to ObjectTypeID mapping
TYPE_MAP = {
    'emission nebula': 'EMISSION_NEBULA',
    'emission': 'EMISSION_NEBULA',
    'h ii': 'HII_REGION',
    'hii': 'HII_REGION',
    'ionized hydrogen': 'HII_REGION',
    'reflection nebula': 'REFLECTION_NEBULA',
    'reflection': 'REFLECTION_NEBULA',
    'dark nebula': 'DARK_NEBULA',
    'planetary nebula': 'PLANETARY_NEBULA',
    'supernova remnant': 'SUPERNOVA_REMNANT',
    'wolf-rayet': 'WOLF_RAYET_BUBBLE',
    'wolf\u2013rayet': 'WOLF_RAYET_BUBBLE',
    'spiral galaxy': 'SPIRAL_GALAXY',
    'barred spiral': 'BARRED_SPIRAL',
    'interacting': 'INTERACTING_GALAXIES',
    'globular cluster': 'GLOBULAR_CLUSTER',
    'open cluster': 'OPEN_CLUSTER',
    'cluster': 'OPEN_CLUSTER',
    'star': 'SINGLE_STAR',
}


def classify_object_type(type_desc):
    """Map a type description to an ObjectTypeID."""
    if not type_desc:
        return None

    type_lower = type_desc.lower()

    for pattern, type_id in TYPE_MAP.items():
        if pattern in type_lower:
            return type_id

    if 'galaxy' in type_lower:
        return 'SPIRAL_GALAXY'
    if 'nebula' in type_lower:
        return 'EMISSION_NEBULA'
    if 'cluster' in type_lower:
        return 'OPEN_CLUSTER'

    return None


def parse_folder_name(folder_name):
    """
    Parse a myWorks folder name to extract DSO info.
    Handles standard catalog IDs as well as hyphenated ones like SH2-101 and C2025-A6.

    Examples:
        'm42_orion_nebula'           -> ('M42', 'orion_nebula', False)
        'ic1805_heart_nebula_mosaic' -> ('IC1805', 'heart_nebula', True)
        'ngc2024_flame_nebula'       -> ('NGC2024', 'flame_nebula', False)
        'sh2-101_tulip_nebula'       -> ('SH2-101', 'tulip_nebula', False)
        'sh2-157_lobster_claw_nebula'-> ('SH2-157', 'lobster_claw_nebula', False)
        'c2025-A6_lemmon_comet'      -> ('C2025-A6', 'lemmon_comet', False)
    """
    is_mosaic = folder_name.endswith('_mosaic')
    name = folder_name.replace('_mosaic', '') if is_mosaic else folder_name

    # Pattern: one or more letters, then digits, then an optional hyphen+alphanumeric
    # suffix (e.g. '-101' in sh2-101, or '-A6' in c2025-A6), then underscore + rest.
    match = re.match(r'^([a-z]+\d+(?:-[a-z0-9]+)?)_(.+)$', name, re.IGNORECASE)
    if match:
        catalog_id = match.group(1).upper()
        common_part = match.group(2)
        return catalog_id, common_part, is_mosaic

    return None, None, is_mosaic


def parse_observation_folder(folder_name):
    """
    Parse an observation folder name to extract date and equipment.
    Examples:
        '20251228_s30'      -> (date(2025,12,28), 'S30')
        '20251213_s50'      -> (date(2025,12,13), 'S50')
        '20250928_162x60s'  -> (date(2025,9,28),  'S30')  # Default to S30
        '20251102'          -> (date(2025,11,2),   'S30')  # No equipment suffix
    """
    match = re.match(r'^(\d{8})(?:_(.+))?$', folder_name)
    if match:
        date_str = match.group(1)
        suffix = match.group(2) if match.group(2) else ''

        try:
            obs_date = datetime.strptime(date_str, '%Y%m%d').date()
        except ValueError:
            return None, None

        suffix_lower = suffix.lower()
        if 's50' in suffix_lower:
            equipment = 'S50'
        elif 's30' in suffix_lower:
            equipment = 'S30'
        else:
            equipment = 'S30'  # Default

        return obs_date, equipment

    return None, None


def count_lights_in_folder(lights_path):
    """Count .fit/.fits/.xisf files in a lights folder."""
    if not os.path.exists(lights_path):
        return 0

    count = 0
    for f in os.listdir(lights_path):
        if f.lower().endswith(('.fit', '.fits', '.xisf')):
            count += 1
    return count


def parse_light_filename(filename):
    """
    Parse a light frame filename to extract observation date.
    Example: Light_M 1_30.0s_LP_20251228-210243.fit -> date(2025, 12, 28)
    """
    match = re.search(r'(\d{8})-\d{6}', filename)
    if match:
        try:
            return datetime.strptime(match.group(1), '%Y%m%d').date()
        except ValueError:
            pass
    return None


def get_observation_dates_from_lights(lights_path):
    """Get unique observation dates from light frame filenames."""
    dates = set()
    if not os.path.exists(lights_path):
        return dates

    for f in os.listdir(lights_path):
        if f.lower().endswith(('.fit', '.fits', '.xisf')):
            obs_date = parse_light_filename(f)
            if obs_date:
                dates.add(obs_date)

    return dates


def get_exposure_from_filename(filename):
    """Extract exposure time from light filename."""
    match = re.search(r'_(\d+(?:\.\d+)?)s_', filename)
    if match:
        return float(match.group(1))
    return 30.0  # Default


def setup_database():
    """Create the database from schema."""
    print("Setting up database...")

    if os.path.exists(DB_PATH):
        os.remove(DB_PATH)
        print(f"  Removed existing database: {DB_PATH}")

    with open(SCHEMA_PATH, 'r', encoding='utf-8') as f:
        schema_sql = f.read()

    conn = sqlite3.connect(DB_PATH)
    conn.executescript(schema_sql)
    conn.commit()
    print(f"  Created database from schema: {DB_PATH}")

    return conn


def build_social_blurb(info):
    """
    Combine Composition, Distance, and FunFacts from the legacy JSON into
    a single SocialBlurb narrative string.

    The result is two paragraphs:
      1. Composition + distance woven into a single descriptive opening.
      2. Fun facts joined into flowing prose.

    If any piece is missing the blurb is built from whatever is available.
    The admin web tool can regenerate this with AI at any time.
    """
    composition = (info.get('Composition') or '').strip()
    distance    = (info.get('Distance')    or '').strip()
    fun_facts   = info.get('FunFacts', []) or []

    parts = []

    # Paragraph 1 — what it is and how far away
    p1_pieces = []
    if composition:
        p1_pieces.append(composition)
    if distance:
        p1_pieces.append(f"Located {distance}.")
    if p1_pieces:
        parts.append('  '.join(p1_pieces))

    # Paragraph 2 — fun facts as prose
    if fun_facts:
        parts.append('  '.join(str(f) for f in fun_facts))

    return '\n\n'.join(parts) if parts else None


def populate_objects_from_json(conn):
    """Populate Objects and CatalogIDs tables from JSON."""
    print("Populating objects from JSON...")

    with open(JSON_PATH, 'r', encoding='utf-8') as f:
        data = json.load(f)

    cursor = conn.cursor()

    aliases = {}
    primary_objects = {}

    for catalog_id, info in data.items():
        if 'See' in info:
            aliases[catalog_id] = info['See']
        else:
            primary_objects[catalog_id] = info

    for catalog_id, info in primary_objects.items():
        dso_key = catalog_id

        constellation_name = info.get('Constellation', '').strip()
        constellation_id = CONSTELLATION_MAP.get(constellation_name)

        type_desc = info.get('Type', '')
        object_type_id = classify_object_type(type_desc)

        social_blurb = build_social_blurb(info)

        # AngularSize: parse from the legacy Size field if present.
        # The Size field is free-text (e.g. '~100 light-years across; ~2°—about 4× the full Moon').
        # We store it as-is for now; the admin tool can populate a clean arcmin value later.
        angular_size = None  # Will be populated via the admin tool / AI lookup

        cursor.execute('''
            INSERT OR REPLACE INTO Objects (
                DSOKey, CommonName, ObjectTypeID, ConstellationID,
                DistanceLY, AngularSize, SocialBlurb
            ) VALUES (?, ?, ?, ?, ?, ?, ?)
        ''', (
            dso_key,
            info.get('CommonName'),
            object_type_id,
            constellation_id,
            info.get('Distance'),
            angular_size,
            social_blurb
        ))

        cursor.execute('''
            INSERT OR REPLACE INTO CatalogIDs (CatalogID, DSOKey, IsPrimary)
            VALUES (?, ?, 1)
        ''', (catalog_id, dso_key))

        other_names = info.get('OtherNames', [])
        for other_name in other_names:
            cursor.execute('''
                INSERT OR REPLACE INTO CatalogIDs (CatalogID, DSOKey, IsPrimary)
                VALUES (?, ?, 0)
            ''', (other_name.replace(' ', ''), dso_key))

    for alias_id, target_id in aliases.items():
        cursor.execute('''
            INSERT OR REPLACE INTO CatalogIDs (CatalogID, DSOKey, IsPrimary)
            VALUES (?, ?, 0)
        ''', (alias_id, target_id))

    conn.commit()

    cursor.execute('SELECT COUNT(*) FROM Objects')
    obj_count = cursor.fetchone()[0]
    cursor.execute('SELECT COUNT(*) FROM CatalogIDs')
    cat_count = cursor.fetchone()[0]

    print(f"  Inserted {obj_count} objects and {cat_count} catalog IDs")


def populate_projects_and_observations(conn):
    """Scan myWorks folder and create projects and observations."""
    print("Scanning myWorks folder for projects and observations...")

    cursor = conn.cursor()
    project_count = 0
    obs_count = 0

    for folder_name in os.listdir(MYWORKS_PATH):
        folder_path = os.path.join(MYWORKS_PATH, folder_name)
        if not os.path.isdir(folder_path):
            continue

        # Skip untracked utility folders
        if folder_name.lower() in ('scenery', 'temp'):
            continue

        # Handle Sun and Moon as special fixed-DSOKey objects
        if folder_name.lower() == 'sun':
            dso_key = 'SUN'
            is_mosaic = False
        elif folder_name.lower() == 'moon':
            dso_key = 'MOON'
            is_mosaic = False
        else:
            catalog_id, common_part, is_mosaic = parse_folder_name(folder_name)
            if not catalog_id:
                print(f"  Warning: Could not parse folder name: {folder_name}")
                continue

            cursor.execute('SELECT DSOKey FROM CatalogIDs WHERE CatalogID = ?', (catalog_id,))
            result = cursor.fetchone()

            if not result:
                print(f"  Warning: No object found for catalog ID: {catalog_id}")
                continue

            dso_key = result[0]

        cursor.execute('''
            INSERT INTO Projects (DSOKey, ProjectFolder, IsMosaic, Status, CreatedDate)
            VALUES (?, ?, ?, 'ACTIVE', date('now'))
        ''', (dso_key, folder_name, 1 if is_mosaic else 0))

        project_id = cursor.lastrowid
        project_count += 1

        for obs_folder in os.listdir(folder_path):
            obs_path = os.path.join(folder_path, obs_folder)
            if not os.path.isdir(obs_path):
                continue

            obs_date, equipment = parse_observation_folder(obs_folder)
            if not obs_date:
                continue

            lights_path = os.path.join(obs_path, 'lights')
            good_lights_path = os.path.join(obs_path, 'good_lights')

            if os.path.exists(good_lights_path):
                active_lights_path = good_lights_path
            elif os.path.exists(lights_path):
                active_lights_path = lights_path
            else:
                active_lights_path = None

            good_lights = 0
            exposure_time = 30.0

            if active_lights_path:
                good_lights = count_lights_in_folder(active_lights_path)
                for f in os.listdir(active_lights_path):
                    if f.lower().endswith(('.fit', '.fits')):
                        exposure_time = get_exposure_from_filename(f)
                        break

            cursor.execute('''
                INSERT INTO Observations (
                    ProjectID, EquipmentID, ObservationDate,
                    ExposureTimeSecs, GoodLights
                ) VALUES (?, ?, ?, ?, ?)
            ''', (project_id, equipment, obs_date.isoformat(), exposure_time, good_lights))

            obs_count += 1

    conn.commit()
    print(f"  Created {project_count} projects and {obs_count} observations")


def populate_images_from_web(conn):
    """Scan web images folder and create image records."""
    print("Scanning web images folder...")

    cursor = conn.cursor()
    image_count = 0

    image_folders = {
        'fav': 'fav',
        'full': 'full',
        'wall': 'wall',
        'wall4k': 'wall4k',
        'thumbs': 'thumb',
        'annotated_fav': 'fav',
        'annotated_full': 'full',
        'annotated_wall': 'wall',
        'annotated_wall4k': 'wall4k',
    }

    for folder_name, image_type_id in image_folders.items():
        folder_path = os.path.join(WEB_IMAGES_PATH, folder_name)
        if not os.path.exists(folder_path):
            continue

        is_annotated = folder_name.startswith('annotated_')

        for filename in os.listdir(folder_path):
            if not filename.lower().endswith(('.jpg', '.jpeg', '.png')):
                continue

            base_name = os.path.splitext(filename)[0]

            if '_annotated' in base_name:
                base_name = base_name.replace('_annotated', '')

            match = re.match(r'^([a-z]+[\d-]+)_', base_name, re.IGNORECASE)
            if not match:
                continue

            catalog_match = match.group(1).upper().replace('-', '')

            cursor.execute('''
                SELECT p.ProjectID, p.ProjectFolder
                FROM Projects p
                JOIN CatalogIDs c ON p.DSOKey = c.DSOKey
                WHERE c.CatalogID = ?
            ''', (catalog_match,))

            result = cursor.fetchone()
            if not result:
                catalog_match2 = re.sub(r'(\D+)(\d+)', r'\1\2', catalog_match)
                cursor.execute('''
                    SELECT p.ProjectID, p.ProjectFolder
                    FROM Projects p
                    JOIN CatalogIDs c ON p.DSOKey = c.DSOKey
                    WHERE UPPER(REPLACE(c.CatalogID, ' ', '')) = ?
                ''', (catalog_match2,))
                result = cursor.fetchone()

            if not result:
                continue

            project_id = result[0]

            palette_id = 0
            palette_patterns = {
                '_sho_': 1,
                '_hoo_': 2,
                '_hso_': 3,
                '_ohs_': 4,
            }
            for pattern, pid in palette_patterns.items():
                if pattern in base_name.lower():
                    palette_id = pid
                    break

            cursor.execute('''
                SELECT ProcessingID FROM ProcessingRuns
                WHERE ProjectID = ?
                LIMIT 1
            ''', (project_id,))

            proc_result = cursor.fetchone()
            if proc_result:
                processing_id = proc_result[0]
            else:
                cursor.execute('''
                    INSERT INTO ProcessingRuns (ProjectID, Status)
                    VALUES (?, 'COMPLETED')
                ''', (project_id,))
                processing_id = cursor.lastrowid

            web_path = f"images/{folder_name}/{filename}"

            cursor.execute('''
                INSERT INTO Images (
                    ProcessingID, ImageTypeID, PaletteID, Filename,
                    WebPath, IsAnnotated, IsPublished
                ) VALUES (?, ?, ?, ?, ?, ?, 1)
            ''', (
                processing_id,
                image_type_id,
                palette_id,
                filename,
                web_path,
                1 if is_annotated else 0
            ))

            image_count += 1

    conn.commit()
    print(f"  Created {image_count} image records")


def update_project_totals(conn):
    """Update computed totals on projects."""
    print("Updating project totals...")

    cursor = conn.cursor()

    cursor.execute('''
        UPDATE Projects SET
            TotalGoodLights = (
                SELECT COALESCE(SUM(GoodLights), 0)
                FROM Observations
                WHERE Observations.ProjectID = Projects.ProjectID
            ),
            TotalIntegrationMins = (
                SELECT COALESCE(SUM(GoodLights * ExposureTimeSecs / 60.0), 0)
                FROM Observations
                WHERE Observations.ProjectID = Projects.ProjectID
            )
    ''')

    conn.commit()
    print("  Project totals updated")


def print_summary(conn):
    """Print a summary of the database contents."""
    print("\n" + "=" * 50)
    print("DATABASE SUMMARY")
    print("=" * 50)

    cursor = conn.cursor()

    tables = [
        'Objects', 'CatalogIDs', 'Projects', 'Observations',
        'ProcessingRuns', 'Images', 'Constellations', 'Equipment'
    ]

    for table in tables:
        cursor.execute(f'SELECT COUNT(*) FROM {table}')
        count = cursor.fetchone()[0]
        print(f"  {table}: {count} records")

    print("\nTop 10 projects by integration time:")
    cursor.execute('''
        SELECT p.ProjectFolder, o.CommonName, p.TotalGoodLights,
               ROUND(p.TotalIntegrationMins, 1) as IntMins
        FROM Projects p
        JOIN Objects o ON p.DSOKey = o.DSOKey
        ORDER BY p.TotalIntegrationMins DESC
        LIMIT 10
    ''')

    for row in cursor.fetchall():
        print(f"    {row[0]}: {row[2]} lights, {row[3]} mins")


def main():
    """Main entry point."""
    print("=" * 50)
    print("ASTRO DATABASE SETUP")
    print("=" * 50 + "\n")

    conn = setup_database()

    try:
        populate_objects_from_json(conn)
        populate_projects_and_observations(conn)
        populate_images_from_web(conn)
        update_project_totals(conn)
        print_summary(conn)

        print("\n" + "=" * 50)
        print("Setup complete!")
        print(f"Database created at: {DB_PATH}")
        print("=" * 50)

    finally:
        conn.close()


if __name__ == '__main__':
    main()
