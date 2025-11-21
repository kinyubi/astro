# DSO Visibility Web Application Setup Guide

## Overview
This application generates Deep Sky Object (DSO) visibility reports accessible via web browser at `/vis` route.

## Installation Steps

### 1. Install Python Dependencies in Virtual Environment

Open Command Prompt or PowerShell and run:

```bash
# Navigate to the pythonscripts directory
cd C:\laragon7\www\astro\pythonscripts

# Activate the existing virtual environment
C:\Astronomy\Apps\pythonScripts\venv\Scripts\activate

# Install required packages
pip install -r requirements.txt

# Deactivate when done
deactivate
```

### 2. Verify Apache Rewrite Module is Enabled

In Laragon, ensure mod_rewrite is enabled:
- Open Laragon menu → Apache → httpd.conf
- Search for "mod_rewrite"
- Ensure this line is NOT commented out:
  ```
  LoadModule rewrite_module modules/mod_rewrite.so
  ```
- Restart Apache if you made changes

### 3. Test the Installation

#### Local Testing:
1. Open your browser and navigate to:
   ```
   http://localhost/astro/vis
   ```
   or with a specific date:
   ```
   http://localhost/astro/vis?date=2025-11-21
   ```

2. The page should display a table of visible DSOs sorted by viewing duration

#### Production Testing (wiibopp.com):
1. Upload the following files to your server:
   - `public/vis.php`
   - `public/.htaccess`
   - `pythonscripts/todays_dsos_web.py`
   - `pythonscripts/requirements.txt`

2. SSH into your server and install dependencies:
   ```bash
   cd /path/to/astro/pythonscripts
   python3 -m venv venv
   source venv/bin/activate
   pip install -r requirements.txt
   deactivate
   ```

3. Update the venv path in `vis.php` to match your server's path

4. Test at: `https://wiibopp.com/astro/vis`

## File Structure

```
C:\laragon7\www\astro\
├── public/
│   ├── .htaccess          # URL routing (added /vis route)
│   ├── vis.php            # PHP handler for /vis route
│   └── index.php          # Your existing slideshow
├── pythonscripts/
│   ├── todays_dsos_web.py # Python script that generates HTML
│   └── requirements.txt   # Python dependencies
└── README_SETUP.md        # This file
```

## How It Works

1. User visits `/vis` or `/vis?date=2025-11-21`
2. Apache routes request to `vis.php` via .htaccess rewrite rule
3. `vis.php` executes Python script with the virtual environment's Python interpreter
4. Python script:
   - Calculates DSO visibility for the specified date
   - Queries your Google Sheets watchlist
   - Generates HTML output with styled table
   - Returns HTML to PHP
5. PHP outputs the HTML to the browser

## Customization

### Change Location
Edit `todays_dsos_web.py`:
```python
LOCATION_NAME = 'Your City, State'
LAT_DEG = 43.69    # Your latitude
LON_DEG = -116.49  # Your longitude
TIME_ZONE = 'America/Boise'  # Your timezone
```

### Adjust Visibility Criteria
Edit `todays_dsos_web.py`:
```python
MIN_ALTITUDE_DEG = 25.0   # Minimum altitude
AZ_MIN_DEG = 10.0         # Minimum azimuth
AZ_MAX_DEG = 145.0        # Maximum azimuth
```

### Styling
Edit the CSS in the `<style>` section of `todays_dsos_web.py`

## Troubleshooting

### "No output from Python script"
- Check that Python virtual environment exists at specified path
- Verify Python packages are installed: `pip list` in activated venv
- Check file permissions

### "Python Error" displayed
- Read the error traceback
- Common issues:
  - Missing Python packages (run pip install)
  - Google Sheets access issues
  - Invalid date format

### Page Not Found (404)
- Verify .htaccess is in the public directory
- Check that mod_rewrite is enabled in Apache
- Restart Apache after changes

### Script Takes Too Long / Times Out
- The script may take 30-60 seconds for calculations
- Increase timeout in vis.php if needed:
  ```php
  set_time_limit(180); // 3 minutes
  ```

## Performance Notes

- First run may be slow while Python loads libraries and downloads ephemeris data
- Subsequent runs should be faster (ephemeris data is cached)
- Consider adding caching in PHP for frequently requested dates
- Python calculations are CPU-intensive; may take 30-60 seconds

## Security Considerations

- Date parameter is validated before use
- shell_exec is used safely with escapeshellarg()
- Consider adding rate limiting for production
- Python script has no user input vulnerabilities
- Google Sheets URL is public (as per original script)
