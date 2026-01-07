# Date Parameter Testing Guide

## What Was Fixed

**Before:**
- `vis.php` accepted `?date=YYYY-MM-DD` but never passed it to Python
- `todays_dsos_web.py` was hardcoded to `datetime.date.today()`
- All reports showed today's data regardless of URL parameter

**After:**
- `vis.php` now passes the date to Python via `--date` argument
- `todays_dsos_web.py` accepts `--date` command line argument
- Each date gets its own correct calculation and cache file

## Testing Steps

### 1. Test Today's Report (Default)
```bash
# Visit: http://localhost/vis
# Should show today's date (2025-01-07) in the title
```

### 2. Test Specific Past Date
```bash
# Visit: http://localhost/vis?date=2025-01-01
# Should show "DSO Visibility Report for 2025-01-01" in the title
```

### 3. Test Future Date
```bash
# Visit: http://localhost/vis?date=2025-02-15
# Should show "DSO Visibility Report for 2025-02-15" in the title
```

### 4. Test Invalid Date Format
```bash
# Visit: http://localhost/vis?date=01-07-2025
# Should show: "Error: Invalid date format. Use YYYY-MM-DD"
```

### 5. Test Cache Per Date
```bash
# 1. Visit: http://localhost/vis?date=2025-01-01
#    - Should generate and cache (30-60s)
#    - Check cache/ folder for dso_report_2025-01-01.html

# 2. Visit: http://localhost/vis?date=2025-01-02
#    - Should generate NEW cache file (30-60s)
#    - Check cache/ folder for dso_report_2025-01-02.html

# 3. Visit: http://localhost/vis?date=2025-01-01 (again)
#    - Should serve from cache (instant)
#    - Should show "Served from cache" status
```

### 6. Test Manual Python Execution
You can also test the Python script directly:

**Windows:**
```bash
cd C:\laragon7\www\astro\pythonscripts
venv\Scripts\python.exe todays_dsos_web.py --date 2025-01-15 > test_output.html
```

**Linux:**
```bash
cd /path/to/pythonscripts
source venv/bin/activate
python todays_dsos_web.py --date 2025-01-15 > test_output.html
```

## Verification Checklist

- [ ] Reports show correct date in title for different URLs
- [ ] Each date creates its own cache file
- [ ] Cache files are named correctly (dso_report_YYYY-MM-DD.html)
- [ ] Force rebuild works for specific dates (?date=X&rebuild=1)
- [ ] Invalid dates show proper error message
- [ ] Cache manager shows all different date caches
- [ ] Viewing windows are calculated for the correct date

## Common Issues

### Issue: All reports show today's date
**Cause:** Changes weren't applied or cache is serving old data  
**Fix:** Clear cache via cache manager or use `?rebuild=1`

### Issue: "Error: Invalid date format" in Python output
**Cause:** Date not being escaped properly in shell command  
**Fix:** Check that date parameter is properly passed in vis.php

### Issue: Wrong viewing window times
**Cause:** Python is calculating for wrong date  
**Fix:** Verify the date is being parsed correctly in Python

## Example URLs for Quick Testing

```
http://localhost/vis                           # Today
http://localhost/vis?date=2025-01-01          # New Year's Day
http://localhost/vis?date=2025-01-15          # Mid-January
http://localhost/vis?date=2025-06-21          # Summer Solstice
http://localhost/vis?date=2025-12-31          # New Year's Eve
http://localhost/vis?date=2025-01-01&rebuild=1 # Force rebuild Jan 1
```

## What to Look For

In each report, verify:
1. **Title** shows the correct date
2. **Viewing Window** times are appropriate for that date (twilight times change throughout the year)
3. **Object list** may differ (some objects are seasonal)
4. **Cache status** shows correct age for cached reports

## Notes

- Astronomical twilight times vary by date, so viewing windows should be different for summer vs winter dates
- Some DSOs are seasonal - you may see more/fewer objects depending on the date
- Cache files are date-specific, so you can have reports for multiple dates cached simultaneously
