# Profile System Setup and Testing Guide

## Overview

The DSO Visibility Report now supports multiple location profiles! Each profile stores:
- **Location name** (e.g., "Star, Idaho")
- **Latitude & Longitude** (automatically geocoded)
- **Timezone** (automatically determined)
- **Minimum Altitude** (customizable viewing constraint)
- **Azimuth Range** (customizable viewing window)

## Setup Steps

### 1. Install New Dependencies

The profile system requires two new Python libraries:

```bash
# Windows
cd C:\laragon7\www\astro\pythonscripts
venv\Scripts\activate
pip install geopy timezonefinder

# Linux
cd /path/to/pythonscripts
source venv/bin/activate
pip install geopy timezonefinder
```

Or install from requirements.txt:
```bash
pip install -r requirements.txt
```

### 2. Initialize Default Profile

Run the profile manager to create the default profile:

```bash
# Windows
venv\Scripts\python.exe profile_manager.py

# Linux
source venv/bin/activate
python profile_manager.py
```

This creates:
- `pythonscripts/profiles/` directory
- `pythonscripts/profiles/default.json` with your Star, Idaho settings

### 3. Test Geocoding

Before creating profiles via the web interface, test that geocoding works:

```bash
# Windows
venv\Scripts\python.exe profile_cli.py geocode "New York, NY"

# Linux
python profile_cli.py geocode "New York, NY"
```

Expected output:
```json
{
  "success": true,
  "latitude": 40.7128,
  "longitude": -74.0060,
  "timezone": "America/New_York",
  "display_name": "New York, NY, United States"
}
```

## Using the Profile Manager

### Web Interface

Visit: `http://localhost/profiles.php`

**Features:**
1. **Test Location Lookup** - Verify a location can be geocoded before creating profile
2. **Create Profile** - Enter location name and viewing parameters
3. **View Profiles** - See all profiles with their settings
4. **Use Profile** - Click "Use Profile" to generate report with that location
5. **Delete Profile** - Remove profiles (except default)

### Command Line Interface

```bash
# List all profiles
python profile_cli.py list

# Get specific profile
python profile_cli.py get default

# Create new profile
python profile_cli.py create "my-backyard" "Phoenix, Arizona" --min-altitude 20 --az-min 0 --az-max 180

# Delete profile
python profile_cli.py delete my-backyard

# Test geocoding
python profile_cli.py geocode "Seattle, WA"
```

## Testing the Profile System

### Test 1: Create a New Profile

1. Visit `http://localhost/profiles.php`
2. Enter location: "New York, NY"
3. Click "Test Geocode" to verify
4. Enter profile name: "new-york"
5. Set viewing parameters (or use defaults)
6. Click "Create Profile"
7. Verify profile appears in the list

### Test 2: Generate Report with Profile

1. Click "Use Profile" button on any profile
2. Should redirect to: `/vis?profile=new-york`
3. Report should show correct location in the info section
4. Viewing window times should match the new location's timezone

### Test 3: Profile in Cache Key

1. Generate report for "new-york" profile
2. Check `public/cache/` directory
3. Should see: `dso_report_new-york_2025-01-07.html`
4. Generate report for "default" profile
5. Should see: `dso_report_default_2025-01-07.html`
6. Each profile gets its own cache files!

### Test 4: Different Locations Show Different Objects

Create profiles for various locations:

```
Profile: "northern" - Location: "Anchorage, Alaska"
Profile: "southern" - Location: "Miami, Florida"
Profile: "international" - Location: "London, UK"
```

Generate reports for each and compare:
- Viewing window times should differ significantly
- Some DSOs may not be visible from certain locations
- Duration of visibility will vary by latitude

## URL Patterns

```
/vis                                    # Default profile, today's date
/vis?profile=backyard                   # Backyard profile, today
/vis?profile=backyard&date=2025-02-15   # Backyard profile, specific date
/vis?profile=vacation&rebuild=1         # Force rebuild with vacation profile
```

## Profile File Format

Profiles are stored as JSON in `pythonscripts/profiles/`:

```json
{
  "name": "my-backyard",
  "location": "Phoenix, Arizona",
  "latitude": 33.4484,
  "longitude": -112.0740,
  "timezone": "America/Phoenix",
  "min_altitude": 20.0,
  "az_min": 0.0,
  "az_max": 180.0,
  "geocoded_name": "Phoenix, Maricopa County, Arizona, United States"
}
```

## Geocoding Tips

### Location Format

**Best formats:**
- "City, State" - e.g., "Seattle, WA"
- "City, Country" - e.g., "London, UK"
- "City, State, Country" - e.g., "Paris, Île-de-France, France"

**Also works:**
- Full addresses: "123 Main St, Portland, OR"
- Landmarks: "Grand Canyon National Park, AZ"
- Observatories: "Mauna Kea Observatory, Hawaii"

**May not work:**
- Ambiguous names: just "Portland" (which state?)
- Very small towns without state/country
- Misspelled locations

### Geocoding Service

- Uses **Nominatim** (OpenStreetMap)
- Free, no API key required
- Rate limit: ~1 request per second
- Courtesy delay built into geopy

If geocoding fails:
1. Try more specific location (add state/country)
2. Use nearby larger city
3. Try different spelling
4. Test with "Test Geocode" button first

## Troubleshooting

### "Module not found: geopy"
- Install dependencies: `pip install -r requirements.txt`
- Activate virtual environment first

### "Location not found"
- Try more specific location name
- Add state or country
- Check spelling
- Try nearby major city

### Profile not showing in report
- Check URL has `?profile=name` parameter
- Verify profile exists: visit `/profiles.php`
- Check for Python errors in output

### Wrong coordinates
- Delete profile and recreate
- Verify location name is specific enough
- Use "Test Geocode" to verify before creating

### Cache showing wrong location
- Each profile has separate cache files
- Clear cache via cache manager
- Or use `?rebuild=1` to force regeneration

## Advanced Usage

### Manually Edit Profiles

Profiles are JSON files, so you can edit them directly:

```bash
cd pythonscripts/profiles
# Edit with your favorite editor
nano default.json
```

After editing, no restart needed - changes take effect immediately.

### Import/Export Profiles

```bash
# Export all profiles
tar -czf profiles-backup.tar.gz pythonscripts/profiles/

# Import profiles
tar -xzf profiles-backup.tar.gz
```

### Share Profiles

Send the JSON file to others:
```bash
# Copy profile
cp pythonscripts/profiles/my-backyard.json /path/to/share/

# They can copy it to their profiles directory
cp received-profile.json pythonscripts/profiles/
```

## What's Different Per Profile

When you switch profiles, these change:
- ✅ Location name in report header
- ✅ Viewing window times (based on timezone)
- ✅ Which objects are visible (based on latitude)
- ✅ How long objects are visible (based on constraints)
- ✅ Cache files (each profile cached separately)

These stay the same:
- ❌ DSO watchlist (same list for all profiles)
- ❌ Date calculations (unless timezone differs)
- ❌ Object coordinates (objects don't move!)

## Next Steps

1. Install dependencies
2. Test geocoding
3. Create profiles for your observing locations
4. Generate reports with different profiles
5. Compare visibility between locations!

## Example Profiles to Create

```
"home" - Your backyard
"dark-site" - Your favorite dark sky location  
"club" - Astronomy club observing site
"vacation" - Where you're traveling
"portable" - Mobile setup location
```

Each gets its own cache, so you can quickly switch between them!
