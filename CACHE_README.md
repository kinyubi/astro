# DSO Visibility Report - Caching System

## Overview
The DSO visibility calculation is computationally intensive (30-60 seconds), so we've implemented a caching system to improve performance.

## How It Works

### Automatic Caching
- When you visit `/vis`, the system checks for a cached report for that date
- If a valid cache exists (less than 24 hours old), it's served instantly
- If no cache exists or it's expired, a new report is generated and cached

### Cache Status Headers
The server sends HTTP headers to indicate cache status:
- `X-Cache-Status: HIT` - Report served from cache
- `X-Cache-Status: MISS` - New report generated
- `X-Cache-Age: X minutes` - How old the cached version is (only on HIT)
- `X-Cache-Rebuild: FORCED` - Cache was manually rebuilt

### Force Rebuild
To force regeneration of a report, add `?rebuild=1` to the URL:
```
/vis?rebuild=1                    # Rebuild today's report
/vis?date=2025-01-15&rebuild=1   # Rebuild specific date
```

## Cache Management

### View Cache Status
Visit `/cache-manager.php` to:
- See all cached reports
- View cache age and file sizes
- Manually delete individual cache files
- Clear all cache at once

### Cache Location
Cache files are stored in: `/public/cache/`
- Format: `dso_report_YYYY-MM-DD.html`
- Protected from web access via .htaccess (if on Apache)

### Cache Expiration
- **Max Age:** 24 hours
- After 24 hours, cache is automatically regenerated on next visit
- You can manually clear cache anytime via cache manager

## URLs

| URL | Description |
|-----|-------------|
| `/vis` | Today's report (cached) |
| `/vis?date=2025-01-15` | Specific date report (cached) |
| `/vis?rebuild=1` | Force rebuild today's report |
| `/vis?date=2025-01-15&rebuild=1` | Force rebuild specific date |
| `/cache-manager.php` | View and manage cache |

## Benefits

1. **Speed:** First load takes 30-60s, subsequent loads are instant
2. **Server Load:** Reduces computational load on your server
3. **Flexibility:** Easy to force rebuild when needed (e.g., after updating DSO watchlist)

## When to Force Rebuild

You should force a cache rebuild when:
- You update the DSO watchlist in Google Sheets
- You modify the Python calculation script
- You change location parameters
- You want fresh data (though 24h cache is usually fine)

## Troubleshooting

### Cache Not Working
Check that:
1. The `cache/` directory exists and is writable
2. PHP has permissions to create files in that directory
3. Check server error logs for permission issues

### Old Data Showing
- Force a rebuild with `?rebuild=1`
- Or delete the cache file via cache manager
- Check that cache max age is set correctly in vis.php

## Technical Details

**Cache Implementation:** File-based caching in PHP
**Cache Key:** Date (YYYY-MM-DD format)
**Storage:** HTML files in `/public/cache/`
**Expiration:** Time-based (24 hours)
