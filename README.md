# Astronomy Website — Project Overview

Carl Baker's astrophotography website and toolchain.  
Live site: **astro.wiibopp.com** | Local dev: **localhost/astro/public**

---

## Directory Structure

```
C:\laragon7\www\astro\
├── dsodb\                  SQLite database and schema files
│   ├── astro.db            Live database
│   ├── schema_v2.sql       Current schema (source of truth for rebuilds)
│   └── schema_v1.sql       Legacy — kept for reference only
│
├── public\                 Web root (served by Apache/Laragon)
│   ├── index.php           Public gallery — slideshow + browse
│   ├── .htaccess           Apache config: image caching headers, CGI
│   ├── admin\              DSO database admin tool
│   ├── vis\                DSO visibility report
│   ├── profiles\           Observation location profile manager
│   ├── cache-manager\      Visibility report cache viewer/manager
│   ├── images\             All published web images (see below)
│   ├── css\                Shared stylesheets
│   └── dso_watchlist_info.json   Legacy JSON — kept as reference
│
├── pythonscripts\          Web-facing Python scripts (called by PHP or CLI)
│   └── migrations\         One-off scripts — already run, kept for reference
│
└── secrets.php             DB credentials / API keys — not in git
```

### Image Directories (`public/images/`)

| Folder | Contents | Size |
|--------|----------|------|
| `fav/` | Social media square (no annotation) | 1080×1350 |
| `annotated_fav/` | Social media square (with title) | 1080×1350 |
| `full/` | Portrait (no annotation) | 1080×1920 |
| `annotated_full/` | Portrait (with title) | 1080×1920 |
| `wall/` | Widescreen wallpaper (no annotation) | 1920×1080 |
| `annotated_wall/` | Widescreen wallpaper (with title) | 1920×1080 |
| `wall4k/` | 4K wallpaper (no annotation) | 3840×2160 |
| `annotated_wall4k/` | 4K wallpaper (with title) | 3840×2160 |
| `thumbs/` | Gallery thumbnails | 300×375 |

---

## Web Endpoints

All routes are served from `public/`. Apache `DirectoryIndex` resolves
`/vis` → `vis/index.php` automatically — no rewrite rules needed.

### `GET /` — Public Gallery
File: `public/index.php`  
The main public-facing gallery. Slideshow and browse modes. Reads all
published images from the filesystem and enriches them with metadata from
`astro.db` via the CatalogIDs alias table (so `m1_*.jpg` correctly resolves
to Crab Nebula data).

### `GET /vis` — DSO Visibility Report
File: `public/vis/index.php`  
Parameters: `?date=YYYY-MM-DD` `?profile=name` `?rebuild=1`  
Calls `todays_dsos_web.py` to generate a visibility report for a given
date and location profile. Output is cached in `public/vis/cache/` for 24
hours. Cached responses return `X-Cache-Status: HIT`.

### `GET /profiles` — Location Profile Manager
File: `public/profiles/index.php`  
Create, edit, and delete observation location profiles (lat/lon, timezone,
min altitude, azimuth limits). Profiles are stored as JSON files in
`pythonscripts/profiles/` and are used by the visibility report.

### `GET /cache-manager` — Cache Manager
File: `public/cache-manager/index.php`  
View, rebuild, or delete cached visibility reports. Lists all files in
`public/vis/cache/` with their age, size, and profile.

### `GET /admin` — DSO Database Admin
File: `public/admin/index.php`  
Internal tool for managing the Objects database. Features:
- Search objects by name, DSOKey, or any catalog ID (M1, NGC, IC, SH2 etc.)
- Edit all object fields (common name, type, constellation, size, distance)
- AI Populate — calls Anthropic API to fill empty fields and generate social blurb
- Manage CatalogID aliases per object

#### Admin API Endpoints
All under `public/admin/`:

| File | Method | Purpose |
|------|--------|---------|
| `api_search.php` | GET `?q=` | Search objects; returns JSON |
| `api_save.php` | POST | Save object fields to DB |
| `api_populate.php` | POST | Call Anthropic API to populate fields |
| `api_object_types.php` | GET | Return ObjectTypes list for dropdown |
| `api_debug.php` | GET | Debug DB connection and schema |
| `auth.php` | — | Session auth helper (localhost bypass enabled) |
| `auth_api.php` | — | API auth helper |
| `config.php` | — | DB path and shared config |
| `diag.php` | GET | Diagnostic page for environment checks |

---

## Database

SQLite database at `dsodb/astro.db`.  
Schema defined in `dsodb/schema_v2.sql` — this is the source of truth.

### Key Tables

| Table | Purpose |
|-------|---------|
| `Objects` | One row per unique DSO. DSOKey uses M-designations where applicable (M1, M42 etc.) |
| `CatalogIDs` | All known IDs for each object (M1, NGC1952, Taurus A…). `IsPrimary=1` marks the preferred display ID |
| `Projects` | One row per myWorks project folder, linked to an Object |
| `Observations` | Individual imaging sessions within a project |
| `ProcessingRuns` | Processing/stacking runs for a project |
| `Images` | Published web image files |
| `SocialPosts` | Social media post tracking |
| `Equipment` | S30, S50 telescope specs |
| `ObjectTypes` | Controlled vocabulary for object classification |

### Useful Views

| View | Purpose |
|------|---------|
| `vw_GalleryObjects` | Gallery data with integration totals |
| `vw_ObservationSummary` | Per-session stats |
| `vw_NeedsMoreData` | Objects with < 2 hours integration |
| `vw_ProcessingStatus` | Processing pipeline status |

---

## Naming Conventions

### myWorks Folder Names
```
{catalog_id}_{common_name}/           DSO project folder  e.g. m1_crab_nebula/
{catalog_id}_{common_name}_mosaic/    Mosaic project      e.g. ic1805_heart_nebula_mosaic/
{YYYYMMDD}_{count}x{exp}s_{equip}/   Observation folder  e.g. 20260214_195x30s_S50/
```

### Web Image Filenames
```
{dso_key}_{description}_{type}.jpg
  e.g. m1_crab_nebula_fav.jpg
       ngc6888_crescent_nebula_sho_wall_annotated.jpg
```
Palette suffix (`_sho_`, `_hoo_`, `_hso_`) appears before the type suffix.

---

## Secrets

`secrets.php` is excluded from git. It contains:
- `ANTHROPIC_API_KEY` — used by `api_populate.php`
- Any other credentials

Copy `secrets.php.example` (if present) to set up a new environment.
