# DSO Visibility Report - Quick Reference

## 🚀 What's New

Your DSO visibility reports now have **intelligent caching** to dramatically improve performance!

## 📊 Usage

### View Reports
- **Today's report:** `http://localhost/vis`
- **Specific date:** `http://localhost/vis?date=2025-01-15`

### Force Rebuild
- **Rebuild today:** `http://localhost/vis?rebuild=1`
- **Rebuild specific date:** `http://localhost/vis?date=2025-01-15&rebuild=1`

### Manage Cache
- **Cache Manager:** `http://localhost/cache-manager.php`

## ⚡ How It Works

### First Visit (Cold Cache)
```
User → vis.php → Python (30-60s) → Generate HTML → Cache + Display
```

### Subsequent Visits (Warm Cache)
```
User → vis.php → Serve from cache (instant!) → Display
```

### Cache Expiration
- Reports are cached for **24 hours**
- After 24 hours, next visit automatically regenerates
- You can force rebuild anytime with `?rebuild=1`

## 🎯 On-Page Features

Each report now displays cache status at the bottom:

**Cached Report:**
```
⚡ Cache Status: Served from cache (generated 15 minutes ago)
[🔄 Force Rebuild] [📊 Cache Manager]
```

**Fresh Report:**
```
🔥 Cache Status: Freshly generated
[📊 Cache Manager]
```

## 🛠️ Cache Manager Features

The cache manager (`/cache-manager.php`) lets you:
- ✅ View all cached reports
- ✅ See cache age and file sizes
- ✅ Delete individual cache files
- ✅ Clear all cache at once
- ✅ Quick links to view or rebuild any cached report

## 💡 When to Force Rebuild

You should force a cache rebuild when:
1. **Updated watchlist** - You modified the DSO watchlist in Google Sheets
2. **Changed location** - You modified LAT_DEG, LON_DEG in the Python script
3. **Script updates** - You changed the visibility calculation logic
4. **Testing** - You're debugging or want to verify current calculations

## 📁 File Structure

```
public/
├── vis.php                  # Main report handler (with caching)
├── cache-manager.php        # Cache management interface
├── index.php               # Slideshow page
└── cache/                  # Cache directory (auto-created)
    ├── dso_report_2025-01-07.html
    ├── dso_report_2025-01-08.html
    └── ... (one file per date)
```

## 🔍 Troubleshooting

### "No output from Python script"
- Check that the Python script path is correct
- Verify the virtual environment exists
- Test running the Python script manually

### Cache not working
- Check that `cache/` directory exists and is writable
- Look at HTTP headers (X-Cache-Status: HIT/MISS) in browser dev tools
- Check PHP error logs

### Old data showing
- Use `?rebuild=1` to force regeneration
- Or delete the cache file in cache manager
- Check that you're not viewing a different date

## 🎨 Customization

### Change Cache Duration
Edit `vis.php` line 36:
```php
$cacheMaxAge = 86400; // 24 hours in seconds
// Examples:
// 12 hours: 43200
// 48 hours: 172800
// 1 hour: 3600
```

### Disable Cache Status Footer
In `vis.php`, comment out lines 141-159 (the cache status injection code)

## 📈 Performance Impact

| Scenario | Before Caching | After Caching |
|----------|---------------|---------------|
| First visit | 30-60 seconds | 30-60 seconds |
| Subsequent visits | 30-60 seconds | < 1 second |
| **Speed improvement** | - | **30-60x faster** |

## 🔐 Security Note

The `cache/` directory should have a `.htaccess` file (on Apache) or proper nginx config to prevent direct web access to cached HTML files. The files are only meant to be served through `vis.php`.
