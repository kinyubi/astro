# DSO Visibility Web Application - Quick Start

## What I Created For You

I've set up a complete web application that displays your DSO visibility reports in a browser. Here's what was created:

### Files Created:

1. **`pythonscripts/todays_dsos_web.py`** - Python script that generates HTML output instead of text/PDF
2. **`public/vis.php`** - PHP handler that executes the Python script and returns HTML
3. **`public/.htaccess`** - Updated with routing rule for `/vis`
4. **`pythonscripts/requirements.txt`** - Python package dependencies
5. **`test_dso_web.bat`** - Batch file to test the Python script directly
6. **`README_SETUP.md`** - Detailed setup and troubleshooting guide

## Quick Installation (5 minutes)

### Step 1: Install Python Dependencies
```bash
# Open Command Prompt and run:
cd C:\laragon7\www\astro\pythonscripts
C:\Astronomy\Apps\pythonScripts\venv\Scripts\activate
pip install -r requirements.txt
deactivate
```

### Step 2: Test the Python Script
Double-click `C:\laragon7\www\astro\test_dso_web.bat`
- This will run the script and open the HTML output in your browser
- If you see a nicely formatted table of DSOs, you're good to go!

### Step 3: Test the Web Interface
Open your browser and go to:
- **Today's report:** http://localhost/astro/vis
- **Specific date:** http://localhost/astro/vis?date=2025-11-21

## How to Use

### Basic Usage
Visit `http://wiibopp.com/astro/vis` (or localhost for testing)
- Shows DSOs visible tonight from your location
- Sorted by viewing duration (longest first)
- ★ symbol marks priority targets (not recently observed)

### With Date Parameter
Visit `http://wiibopp.com/astro/vis?date=2025-12-25`
- Shows DSOs for any specific date
- Date format: YYYY-MM-DD

## Key Features

✅ **Sorted by viewing duration** - Objects with longest visibility windows appear first
✅ **Responsive design** - Works on desktop, tablet, and mobile
✅ **Dark theme** - Easy on the eyes for astronomy planning
✅ **Priority markers** - ★ indicates objects you haven't observed recently
✅ **Real-time calculations** - Uses your Google Sheets watchlist
✅ **Time zone aware** - Displays times in America/Boise timezone

## The Technical Flow

1. User visits `/vis` → Apache routes to `vis.php`
2. PHP activates your Python venv and runs `todays_dsos_web.py`
3. Python script:
   - Reads your Google Sheets DSO watchlist
   - Calculates astronomical twilight times for the date
   - Determines which DSOs meet altitude/azimuth criteria
   - Calculates viewing duration for each object
   - Sorts by duration (longest first)
   - Generates HTML with embedded CSS styling
4. PHP returns HTML to browser

## Deploying to Production (wiibopp.com)

When ready to deploy to your live site:

1. Upload files via FTP/SFTP:
   - `public/vis.php`
   - `public/.htaccess`
   - `pythonscripts/todays_dsos_web.py`
   - `pythonscripts/requirements.txt`

2. SSH into server and install Python packages:
   ```bash
   cd /path/to/astro/pythonscripts
   python3 -m venv venv
   source venv/bin/activate
   pip install -r requirements.txt
   deactivate
   ```

3. Update paths in `vis.php` if different on server

4. Test at: https://wiibopp.com/astro/vis

## Customization Options

### Change Your Location
Edit `todays_dsos_web.py` lines 18-22:
```python
LOCATION_NAME = 'Your City, State'
LAT_DEG = 43.69    # Your latitude
LON_DEG = -116.49  # Your longitude  
TIME_ZONE = 'America/Boise'
```

### Adjust Visibility Criteria
Edit `todays_dsos_web.py` lines 23-25:
```python
MIN_ALTITUDE_DEG = 25.0   # Minimum degrees above horizon
AZ_MIN_DEG = 10.0         # Northern limit
AZ_MAX_DEG = 145.0        # Southern limit
```

### Modify Styling
The CSS is embedded in `todays_dsos_web.py` in the `<style>` block (lines ~150-220)
- Colors, fonts, spacing all customizable
- Currently uses dark astronomy-friendly theme

## Troubleshooting

### "No output from Python script"
- Run the test batch file to diagnose
- Check that all packages are installed: `pip list` in activated venv
- Verify venv path in vis.php matches your setup

### Script is slow (30-60 seconds)
- This is normal for first run (downloads ephemeris data)
- Subsequent runs should be faster
- Data files are cached

### "Module not found" errors
- Activate venv and install: `pip install -r requirements.txt`
- Check that you're using the venv's Python, not system Python

## Performance Notes

- **First load:** 30-60 seconds (downloading star catalogs)
- **Subsequent loads:** 15-30 seconds (calculations)
- **Consider caching:** Add PHP caching for frequently-requested dates
- **Ephemeris data:** Downloaded once, cached at: `~/.skyfield/`

## What's Different from Original Script

| Original | Web Version |
|----------|-------------|
| Text output to console | HTML output to browser |
| Saves to .txt and .pdf files | Returns HTML directly |
| Order from Google Sheet | Sorted by viewing duration |
| Monospace text | Styled, responsive table |
| Local execution only | Web-accessible |

## Next Steps

1. ✅ Run the test batch file
2. ✅ View output in browser
3. ✅ Test at http://localhost/astro/vis
4. ✅ Bookmark for easy access
5. 🚀 Deploy to production when ready

## Support

For detailed troubleshooting, see `README_SETUP.md`

For questions about:
- Python script behavior → Check original `todays_dsos_sorted.py`
- Web routing → Check `.htaccess` and `vis.php`
- Styling → Check `<style>` block in `todays_dsos_web.py`

---

**Enjoy planning your astronomy sessions! 🔭✨**
