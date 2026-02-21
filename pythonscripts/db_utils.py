"""
Astro Database Utilities
========================
Common database operations and queries.

Usage:
    python db_utils.py <command> [options]

Commands:
    stats           - Show database statistics
    objects         - List all objects
    projects        - List all projects with integration time
    observations    - List recent observations
    needs-data      - List objects needing more integration time
    search <term>   - Search for objects by name
    export-json     - Export gallery data to JSON (replaces dso_watchlist_info.json)
"""

import sqlite3
import json
import sys
from pathlib import Path
from datetime import datetime

DB_PATH = r"C:\laragon7\www\astro\dsodb\astro.db"
JSON_OUTPUT_PATH = r"C:\laragon7\www\astro\public\dso_watchlist_info.json"


def get_connection():
    """Get database connection."""
    return sqlite3.connect(DB_PATH)


def show_stats():
    """Show database statistics."""
    conn = get_connection()
    cursor = conn.cursor()
    
    print("DATABASE STATISTICS")
    print("=" * 50)
    
    # Record counts
    tables = ['Objects', 'CatalogIDs', 'Projects', 'Observations', 'ProcessingRuns', 'Images']
    for table in tables:
        cursor.execute(f'SELECT COUNT(*) FROM {table}')
        print(f"  {table}: {cursor.fetchone()[0]}")
    
    # Total integration time
    cursor.execute('''
        SELECT ROUND(SUM(GoodLights * ExposureTimeSecs / 3600.0), 1)
        FROM Observations
    ''')
    total_hours = cursor.fetchone()[0] or 0
    print(f"\nTotal integration time: {total_hours} hours")
    
    # Most recent observation
    cursor.execute('''
        SELECT MAX(ObservationDate) FROM Observations
    ''')
    last_obs = cursor.fetchone()[0]
    print(f"Most recent observation: {last_obs}")
    
    # Published images
    cursor.execute('SELECT COUNT(*) FROM Images WHERE IsPublished = 1')
    print(f"Published images: {cursor.fetchone()[0]}")
    
    conn.close()


def list_objects():
    """List all objects."""
    conn = get_connection()
    cursor = conn.cursor()
    
    cursor.execute('''
        SELECT o.DSOKey, o.CommonName, c.Name as Constellation,
               ot.TypeName, o.Magnitude
        FROM Objects o
        LEFT JOIN Constellations c ON o.ConstellationID = c.ConstellationID
        LEFT JOIN ObjectTypes ot ON o.ObjectTypeID = ot.ObjectTypeID
        ORDER BY o.DSOKey
    ''')
    
    print(f"{'DSO Key':<12} {'Common Name':<30} {'Constellation':<15} {'Type':<25}")
    print("-" * 85)
    
    for row in cursor.fetchall():
        dso_key, name, const, obj_type, mag = row
        name = (name or '')[:28]
        const = (const or '')[:13]
        obj_type = (obj_type or '')[:23]
        print(f"{dso_key:<12} {name:<30} {const:<15} {obj_type:<25}")
    
    conn.close()


def list_projects():
    """List all projects with integration time."""
    conn = get_connection()
    cursor = conn.cursor()
    
    cursor.execute('''
        SELECT p.ProjectFolder, o.CommonName, p.IsMosaic,
               p.TotalGoodLights, ROUND(p.TotalIntegrationMins / 60.0, 2) as Hours,
               (SELECT MAX(ObservationDate) FROM Observations WHERE ProjectID = p.ProjectID) as LastObs
        FROM Projects p
        JOIN Objects o ON p.DSOKey = o.DSOKey
        ORDER BY p.TotalIntegrationMins DESC
    ''')
    
    print(f"{'Project Folder':<45} {'Mosaic':<7} {'Lights':<8} {'Hours':<8} {'Last Obs':<12}")
    print("-" * 85)
    
    for row in cursor.fetchall():
        folder, name, is_mosaic, lights, hours, last_obs = row
        folder = folder[:43]
        mosaic = 'Yes' if is_mosaic else 'No'
        lights = lights or 0
        hours = hours or 0
        last_obs = last_obs or 'Never'
        print(f"{folder:<45} {mosaic:<7} {lights:<8} {hours:<8.2f} {last_obs:<12}")
    
    conn.close()


def list_observations(limit=20):
    """List recent observations."""
    conn = get_connection()
    cursor = conn.cursor()
    
    cursor.execute('''
        SELECT obs.ObservationDate, p.ProjectFolder, obj.CommonName,
               obs.EquipmentID, obs.GoodLights, obs.ExposureTimeSecs,
               ROUND(obs.GoodLights * obs.ExposureTimeSecs / 60.0, 1) as IntMins
        FROM Observations obs
        JOIN Projects p ON obs.ProjectID = p.ProjectID
        JOIN Objects obj ON p.DSOKey = obj.DSOKey
        ORDER BY obs.ObservationDate DESC
        LIMIT ?
    ''', (limit,))
    
    print(f"{'Date':<12} {'Object':<25} {'Equipment':<10} {'Lights':<8} {'Exp(s)':<8} {'Int(min)':<10}")
    print("-" * 85)
    
    for row in cursor.fetchall():
        date, folder, name, equip, lights, exp, int_mins = row
        name = (name or folder)[:23]
        print(f"{date:<12} {name:<25} {equip:<10} {lights:<8} {exp:<8.1f} {int_mins:<10.1f}")
    
    conn.close()


def list_needs_data(min_hours=2.0):
    """List objects needing more integration time."""
    conn = get_connection()
    cursor = conn.cursor()
    
    cursor.execute('''
        SELECT p.ProjectFolder, o.CommonName,
               p.TotalGoodLights, ROUND(p.TotalIntegrationMins / 60.0, 2) as Hours,
               (SELECT MAX(ObservationDate) FROM Observations WHERE ProjectID = p.ProjectID) as LastObs
        FROM Projects p
        JOIN Objects o ON p.DSOKey = o.DSOKey
        WHERE p.TotalIntegrationMins < ? OR p.TotalIntegrationMins IS NULL
        ORDER BY p.TotalIntegrationMins ASC
    ''', (min_hours * 60,))
    
    print(f"Objects with less than {min_hours} hours of integration:")
    print(f"{'Project':<40} {'Lights':<8} {'Hours':<8} {'Last Obs':<12}")
    print("-" * 70)
    
    for row in cursor.fetchall():
        folder, name, lights, hours, last_obs = row
        folder = folder[:38]
        lights = lights or 0
        hours = hours or 0
        last_obs = last_obs or 'Never'
        print(f"{folder:<40} {lights:<8} {hours:<8.2f} {last_obs:<12}")
    
    conn.close()


def search_objects(term):
    """Search for objects by name."""
    conn = get_connection()
    cursor = conn.cursor()
    
    search_pattern = f"%{term}%"
    
    cursor.execute('''
        SELECT DISTINCT o.DSOKey, o.CommonName, c.CatalogID
        FROM Objects o
        LEFT JOIN CatalogIDs c ON o.DSOKey = c.DSOKey
        WHERE o.DSOKey LIKE ? 
           OR o.CommonName LIKE ?
           OR c.CatalogID LIKE ?
        ORDER BY o.DSOKey
    ''', (search_pattern, search_pattern, search_pattern))
    
    print(f"Search results for '{term}':")
    print("-" * 50)
    
    results = {}
    for row in cursor.fetchall():
        dso_key, name, cat_id = row
        if dso_key not in results:
            results[dso_key] = {'name': name, 'catalogs': []}
        if cat_id:
            results[dso_key]['catalogs'].append(cat_id)
    
    for dso_key, info in results.items():
        cats = ', '.join(info['catalogs'])
        print(f"  {dso_key}: {info['name']} ({cats})")
    
    if not results:
        print("  No results found.")
    
    conn.close()


def export_gallery_json():
    """Export gallery data to JSON format for website."""
    conn = get_connection()
    cursor = conn.cursor()
    
    cursor.execute('''
        SELECT 
            o.DSOKey,
            o.CommonName,
            o.Composition,
            c.Name as Constellation,
            o.DistanceLY,
            o.FunFacts,
            ot.TypeName
        FROM Objects o
        LEFT JOIN Constellations c ON o.ConstellationID = c.ConstellationID
        LEFT JOIN ObjectTypes ot ON o.ObjectTypeID = ot.ObjectTypeID
        ORDER BY o.DSOKey
    ''')
    
    data = {}
    for row in cursor.fetchall():
        dso_key, name, composition, constellation, distance, fun_facts_json, type_name = row
        
        # Get all catalog IDs for this object
        cursor.execute('''
            SELECT CatalogID, IsPrimary FROM CatalogIDs WHERE DSOKey = ?
        ''', (dso_key,))
        catalog_ids = cursor.fetchall()
        
        # Parse fun facts
        fun_facts = []
        if fun_facts_json:
            try:
                fun_facts = json.loads(fun_facts_json)
            except json.JSONDecodeError:
                pass
        
        # Build entry
        entry = {
            'CommonName': name,
            'Type': type_name,
            'Constellation': constellation,
            'Distance': distance,
            'Composition': composition,
            'FunFacts': fun_facts,
        }
        
        # Add other names
        other_names = [c[0] for c in catalog_ids if c[0] != dso_key and not c[1]]
        if other_names:
            entry['OtherNames'] = other_names
        
        data[dso_key] = entry
    
    # Add aliases (M42 -> NGC1976 style)
    cursor.execute('''
        SELECT CatalogID, DSOKey FROM CatalogIDs 
        WHERE IsPrimary = 0 AND CatalogID != DSOKey
    ''')
    
    for cat_id, dso_key in cursor.fetchall():
        if cat_id not in data and dso_key in data:
            # Get common name from primary
            common_name = data[dso_key].get('CommonName', '')
            data[cat_id] = {
                'CommonName': common_name,
                'See': dso_key
            }
    
    # Write JSON
    with open(JSON_OUTPUT_PATH, 'w', encoding='utf-8') as f:
        json.dump(data, f, indent=4, ensure_ascii=False)
    
    print(f"Exported {len(data)} entries to {JSON_OUTPUT_PATH}")
    
    conn.close()


def main():
    """Main entry point."""
    if len(sys.argv) < 2:
        print(__doc__)
        return
    
    command = sys.argv[1].lower()
    
    if command == 'stats':
        show_stats()
    elif command == 'objects':
        list_objects()
    elif command == 'projects':
        list_projects()
    elif command == 'observations':
        limit = int(sys.argv[2]) if len(sys.argv) > 2 else 20
        list_observations(limit)
    elif command == 'needs-data':
        min_hours = float(sys.argv[2]) if len(sys.argv) > 2 else 2.0
        list_needs_data(min_hours)
    elif command == 'search':
        if len(sys.argv) < 3:
            print("Usage: db_utils.py search <term>")
            return
        search_objects(sys.argv[2])
    elif command == 'export-json':
        export_gallery_json()
    else:
        print(f"Unknown command: {command}")
        print(__doc__)


if __name__ == '__main__':
    main()
