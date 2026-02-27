-- ============================================================
-- ASTRO DATABASE SCHEMA v3.0
-- Deep Sky Object Observation & Image Management
--
-- Changes from v1.0:
--   * Objects.AngularSize renamed to ObjectSize (now a plain-English
--     description including physical size, angular size, and moon comparison)
--   * CatalogIDs.CatalogName removed (not used in UI)
--   * vw_GalleryObjects updated to reference ObjectSize
--   * SUN/MOON seed data updated to use ObjectSize
--
-- Changes from v2.0:
--   * Objects.WantBetter added (INTEGER NOT NULL DEFAULT 0)
--     Flags priority targets in the DSO visibility report (1 = want better data)
--   * Google Sheets watchlist retired; todays_dsos_web.py now reads
--     coordinates, names, types, and WantBetter directly from this DB
--
-- Changes from v2.1:
--   * CatalogIDs cleaned: 67 non-standard entries removed; only HD, IC, NGC,
--     M, LDN, SH2 catalog prefixes and comet designations (C20xx) retained
--   * vw_ProcessingStatus fixed: removed invalid join on pr.PaletteID
--     (PaletteID lives on Images, not ProcessingRuns; PaletteName column removed
--     from this view until a proper palette-per-run design is decided)
-- ============================================================

-- Enable foreign keys
PRAGMA foreign_keys = ON;

-- ============================================================
-- REFERENCE TABLES
-- ============================================================

DROP TABLE IF EXISTS Constellations;
CREATE TABLE Constellations (
    ConstellationID TEXT PRIMARY KEY,  -- 3-letter IAU abbreviation (e.g., 'ORI', 'CYG')
    Name TEXT NOT NULL,
    GenitiveName TEXT,                  -- For "in Orion" → "Orionis"
    RightAscensionHours REAL,          -- Center RA for sorting/grouping
    DeclinationDegrees REAL            -- Center Dec
);

DROP TABLE IF EXISTS ObjectCategories;
CREATE TABLE ObjectCategories (
    CategoryID TEXT PRIMARY KEY,        -- e.g., 'NEBULA', 'GALAXY', 'CLUSTER'
    CategoryName TEXT NOT NULL,
    Description TEXT
);

DROP TABLE IF EXISTS ObjectTypes;
CREATE TABLE ObjectTypes (
    ObjectTypeID TEXT PRIMARY KEY,      -- e.g., 'EMISSION_NEBULA', 'SPIRAL_GALAXY'
    CategoryID TEXT NOT NULL,
    TypeName TEXT NOT NULL,
    Description TEXT,
    FOREIGN KEY (CategoryID) REFERENCES ObjectCategories(CategoryID)
);

DROP TABLE IF EXISTS Equipment;
CREATE TABLE Equipment (
    EquipmentID TEXT PRIMARY KEY,       -- e.g., 'S30', 'S50'
    EquipmentName TEXT NOT NULL,
    Manufacturer TEXT,
    Model TEXT,
    EquipmentType TEXT,                 -- 'SMART_TELESCOPE', 'CAMERA', 'MOUNT', etc.
    FocalLengthMM INTEGER,
    ApertureMM INTEGER,
    PixelSizeMicrons REAL,
    SensorWidthPx INTEGER,
    SensorHeightPx INTEGER,
    ArcSecsPerPixel REAL,
    Notes TEXT
);

DROP TABLE IF EXISTS ImageTypes;
CREATE TABLE ImageTypes (
    ImageTypeID TEXT PRIMARY KEY,       -- 'fav', 'full', 'wall', 'wall4k', 'thumb'
    Description TEXT,
    DefaultWidth INTEGER,
    DefaultHeight INTEGER,
    AspectRatio TEXT,                   -- '4:5', '16:9', '9:16', etc.
    WebFolder TEXT                      -- Subfolder name in public/images/
);

DROP TABLE IF EXISTS PaletteTreatments;
CREATE TABLE PaletteTreatments (
    PaletteID INTEGER PRIMARY KEY,
    PaletteName TEXT NOT NULL,          -- 'SHO', 'HOO', 'HSO', 'OHS', 'Natural', etc.
    Description TEXT,
    ChannelMapping TEXT                 -- e.g., 'R=SII, G=Ha, B=OIII'
);

DROP TABLE IF EXISTS SocialPlatforms;
CREATE TABLE SocialPlatforms (
    PlatformID TEXT PRIMARY KEY,        -- 'REDDIT', 'INSTAGRAM', 'FACEBOOK', etc.
    PlatformName TEXT NOT NULL,
    BaseURL TEXT
);

-- ============================================================
-- CORE OBJECT TABLES
-- ============================================================

DROP TABLE IF EXISTS Objects;
CREATE TABLE Objects (
    DSOKey TEXT PRIMARY KEY,            -- Canonical key, e.g., 'NGC1976', 'IC405'
    CommonName TEXT,                    -- e.g., 'Orion Nebula', 'Flaming Star Nebula'
    ObjectTypeID TEXT,
    ConstellationID TEXT,
    RAHours REAL,                       -- Right Ascension in decimal hours
    DecDegrees REAL,                    -- Declination in decimal degrees
    Magnitude REAL,                     -- Apparent magnitude
    ObjectSize TEXT,                    -- Plain-English size: physical size, angular size,
                                        -- and moon comparison in one sentence.
                                        -- e.g. '70 light-years across with an apparent diameter
                                        -- of 45-50 arcminutes, about 1.5× the full moon'
    DistanceLY TEXT,                    -- e.g., '~1,350 light-years'
    SocialBlurb TEXT,                   -- 1-2 paragraph conversational narrative for social media
    WantBetter INTEGER NOT NULL DEFAULT 0, -- 1 = priority target (want improved data/imaging)
    LastUpdated DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (ObjectTypeID) REFERENCES ObjectTypes(ObjectTypeID),
    FOREIGN KEY (ConstellationID) REFERENCES Constellations(ConstellationID)
);

DROP TABLE IF EXISTS CatalogIDs;
CREATE TABLE CatalogIDs (
    CatalogID TEXT NOT NULL,            -- e.g., 'M42', 'NGC1976', 'LBN974'
    DSOKey TEXT NOT NULL,
    IsPrimary INTEGER DEFAULT 0,        -- 1 if this is the preferred display ID
    PRIMARY KEY (CatalogID),
    FOREIGN KEY (DSOKey) REFERENCES Objects(DSOKey)
);

CREATE INDEX idx_catalogids_dsokey ON CatalogIDs(DSOKey);

-- ============================================================
-- OBSERVATION & DATA COLLECTION
-- ============================================================

DROP TABLE IF EXISTS Projects;
CREATE TABLE Projects (
    ProjectID INTEGER PRIMARY KEY AUTOINCREMENT,
    DSOKey TEXT NOT NULL,
    ProjectFolder TEXT NOT NULL,        -- Folder name in myWorks, e.g., 'm42_orion_nebula'
    IsMosaic INTEGER DEFAULT 0,
    MosaicConfig TEXT,                  -- '1x2', '2x2', etc. (NULL if not mosaic)
    Status TEXT DEFAULT 'ACTIVE',       -- 'ACTIVE', 'COMPLETED', 'ARCHIVED'
    TotalGoodLights INTEGER DEFAULT 0,  -- Computed: sum of all observation good lights
    TotalIntegrationMins REAL,          -- Computed: total integration time in minutes
    CreatedDate DATE,
    Notes TEXT,
    FOREIGN KEY (DSOKey) REFERENCES Objects(DSOKey)
);

DROP TABLE IF EXISTS Observations;
CREATE TABLE Observations (
    ObservationID INTEGER PRIMARY KEY AUTOINCREMENT,
    ProjectID INTEGER NOT NULL,
    EquipmentID TEXT NOT NULL,
    ObservationDate DATE NOT NULL,      -- The night of observation
    StartTime DATETIME,
    EndTime DATETIME,
    ExposureTimeSecs REAL,              -- Per-frame exposure in seconds (e.g., 30.0)
    Filter TEXT,                        -- 'LP', 'Ha', 'OIII', 'SII', etc.
    TotalExposures INTEGER DEFAULT 0,   -- Total frames captured
    GoodLights INTEGER DEFAULT 0,       -- Frames kept after rejection
    RejectedLights INTEGER DEFAULT 0,   -- Frames rejected
    MosaicPanel TEXT,                   -- For mosaics: '1,1', '1,2', '2,1', '2,2'
    BortleScale INTEGER,                -- Sky darkness 1-9
    SeeingConditions TEXT,              -- 'Excellent', 'Good', 'Fair', 'Poor'
    Temperature REAL,                   -- Celsius
    Humidity REAL,                      -- Percentage
    NinaWorkflowName TEXT,
    Notes TEXT,
    FOREIGN KEY (ProjectID) REFERENCES Projects(ProjectID),
    FOREIGN KEY (EquipmentID) REFERENCES Equipment(EquipmentID)
);

CREATE INDEX idx_observations_date ON Observations(ObservationDate);
CREATE INDEX idx_observations_project ON Observations(ProjectID);

-- ============================================================
-- PROCESSING & WORKFLOW
-- ============================================================

DROP TABLE IF EXISTS ProcessingRuns;
CREATE TABLE ProcessingRuns (
    ProcessingID INTEGER PRIMARY KEY AUTOINCREMENT,
    ProjectID INTEGER NOT NULL,
    ProcessingDateStart DATE,
    ProcessingDateEnd DATE,
    ProcessingSoftware TEXT,            -- 'PixInsight', 'Siril', 'Photoshop', etc.
    WorkflowNotes TEXT,
    DrizzleScale REAL,                  -- 1.0, 1.5, 2.0, etc.
    LightsUsed INTEGER,
    IntegrationTimeMins REAL,
    OutputFolder TEXT,
    MasterFilename TEXT,
    Status TEXT DEFAULT 'IN_PROGRESS',  -- 'IN_PROGRESS', 'COMPLETED', 'PUBLISHED'
    HoursSpent REAL,
    Notes TEXT,
    FOREIGN KEY (ProjectID) REFERENCES Projects(ProjectID)
);

DROP TABLE IF EXISTS ProcessingObservations;
CREATE TABLE ProcessingObservations (
    ProcessingID INTEGER NOT NULL,
    ObservationID INTEGER NOT NULL,
    PRIMARY KEY (ProcessingID, ObservationID),
    FOREIGN KEY (ProcessingID) REFERENCES ProcessingRuns(ProcessingID),
    FOREIGN KEY (ObservationID) REFERENCES Observations(ObservationID)
);

-- ============================================================
-- IMAGE MANAGEMENT
-- ============================================================

DROP TABLE IF EXISTS Images;
CREATE TABLE Images (
    ImageID INTEGER PRIMARY KEY AUTOINCREMENT,
    ProcessingID INTEGER NOT NULL,
    ImageTypeID TEXT NOT NULL,
    ParentImageID INTEGER,              -- If derived from another image (palette/annotated)
    PaletteID INTEGER DEFAULT 0,        -- 0 = natural, others from PaletteTreatments
    Filename TEXT NOT NULL,
    SourcePath TEXT,                    -- Full path in myWorks
    WebPath TEXT,                       -- Path in public/images (if published)
    Width INTEGER,
    Height INTEGER,
    IsAnnotated INTEGER DEFAULT 0,
    StarRating INTEGER,                 -- 1-5 stars
    IsPublished INTEGER DEFAULT 0,
    IsRetired INTEGER DEFAULT 0,
    CreatedDate DATETIME DEFAULT CURRENT_TIMESTAMP,
    Notes TEXT,
    FOREIGN KEY (ProcessingID) REFERENCES ProcessingRuns(ProcessingID),
    FOREIGN KEY (ImageTypeID) REFERENCES ImageTypes(ImageTypeID),
    FOREIGN KEY (ParentImageID) REFERENCES Images(ImageID),
    FOREIGN KEY (PaletteID) REFERENCES PaletteTreatments(PaletteID)
);

CREATE INDEX idx_images_processing ON Images(ProcessingID);

-- ============================================================
-- SOCIAL MEDIA TRACKING
-- ============================================================

DROP TABLE IF EXISTS SocialPosts;
CREATE TABLE SocialPosts (
    PostID INTEGER PRIMARY KEY AUTOINCREMENT,
    ImageID INTEGER NOT NULL,
    PlatformID TEXT NOT NULL,
    PostDate DATETIME,
    PostURL TEXT,
    Caption TEXT,
    Hashtags TEXT,                      -- Comma-separated or JSON
    Likes INTEGER DEFAULT 0,
    Comments INTEGER DEFAULT 0,
    Shares INTEGER DEFAULT 0,
    LastUpdated DATETIME,
    Notes TEXT,
    FOREIGN KEY (ImageID) REFERENCES Images(ImageID),
    FOREIGN KEY (PlatformID) REFERENCES SocialPlatforms(PlatformID)
);

-- ============================================================
-- WEBSITE GALLERY SUPPORT
-- ============================================================

DROP VIEW IF EXISTS vw_GalleryObjects;
CREATE VIEW vw_GalleryObjects AS
SELECT
    o.DSOKey,
    o.CommonName,
    c.CatalogID AS PrimaryCatalogID,
    o.ObjectTypeID,
    ot.TypeName AS ObjectTypeName,
    oc.CategoryName AS ObjectCategory,
    o.ConstellationID,
    con.Name AS ConstellationName,
    o.RAHours,
    o.DecDegrees,
    o.Magnitude,
    o.ObjectSize,
    o.DistanceLY,
    o.SocialBlurb,
    p.ProjectFolder,
    p.IsMosaic,
    (SELECT MAX(ObservationDate) FROM Observations obs WHERE obs.ProjectID = p.ProjectID) AS MostRecentObservation,
    (SELECT SUM(GoodLights) FROM Observations obs WHERE obs.ProjectID = p.ProjectID) AS TotalLights,
    (SELECT SUM(GoodLights * ExposureTimeSecs / 60.0) FROM Observations obs WHERE obs.ProjectID = p.ProjectID) AS TotalIntegrationMins
FROM Objects o
LEFT JOIN CatalogIDs c ON o.DSOKey = c.DSOKey AND c.IsPrimary = 1
LEFT JOIN ObjectTypes ot ON o.ObjectTypeID = ot.ObjectTypeID
LEFT JOIN ObjectCategories oc ON ot.CategoryID = oc.CategoryID
LEFT JOIN Constellations con ON o.ConstellationID = con.ConstellationID
LEFT JOIN Projects p ON o.DSOKey = p.DSOKey AND p.Status = 'ACTIVE';

-- ============================================================
-- INITIAL REFERENCE DATA
-- ============================================================

INSERT INTO Constellations (ConstellationID, Name, GenitiveName) VALUES
('AND', 'Andromeda', 'Andromedae'),
('AQL', 'Aquila', 'Aquilae'),
('AQR', 'Aquarius', 'Aquarii'),
('ARI', 'Aries', 'Arietis'),
('AUR', 'Auriga', 'Aurigae'),
('CMA', 'Canis Major', 'Canis Majoris'),
('CMI', 'Canis Minor', 'Canis Minoris'),
('CNC', 'Cancer', 'Cancri'),
('CAS', 'Cassiopeia', 'Cassiopeiae'),
('CEP', 'Cepheus', 'Cephei'),
('CYG', 'Cygnus', 'Cygni'),
('DOR', 'Dorado', 'Doradus'),
('ERI', 'Eridanus', 'Eridani'),
('GEM', 'Gemini', 'Geminorum'),
('HER', 'Hercules', 'Herculis'),
('LEO', 'Leo', 'Leonis'),
('LYR', 'Lyra', 'Lyrae'),
('MON', 'Monoceros', 'Monocerotis'),
('ORI', 'Orion', 'Orionis'),
('PEG', 'Pegasus', 'Pegasi'),
('PER', 'Perseus', 'Persei'),
('PSC', 'Pisces', 'Piscium'),
('SGR', 'Sagittarius', 'Sagittarii'),
('SER', 'Serpens', 'Serpentis'),
('TAU', 'Taurus', 'Tauri'),
('TRI', 'Triangulum', 'Trianguli'),
('UMA', 'Ursa Major', 'Ursae Majoris'),
('UMI', 'Ursa Minor', 'Ursae Minoris'),
('VIR', 'Virgo', 'Virginis'),
('VUL', 'Vulpecula', 'Vulpeculae'),
('CVN', 'Canes Venatici', 'Canum Venaticorum'),
('FOR', 'Fornax', 'Fornacis');

INSERT INTO ObjectCategories (CategoryID, CategoryName, Description) VALUES
('NEBULA',    'Nebula',              'Clouds of gas and dust in space'),
('GALAXY',    'Galaxy',              'Large systems of stars, gas, and dust'),
('CLUSTER',   'Star Cluster',        'Gravitationally bound groups of stars'),
('STAR',      'Star',                'Individual stars or stellar systems'),
('PLANETARY', 'Planetary',            'Sun, Moon, planets, comets, etc.'),
('OTHER',     'Other',               'Other celestial objects');

INSERT INTO ObjectTypes (ObjectTypeID, CategoryID, TypeName, Description) VALUES
-- Nebulae
('EMISSION_NEBULA',    'NEBULA', 'Emission Nebula',           'Cloud of ionized gas emitting light'),
('REFLECTION_NEBULA',  'NEBULA', 'Reflection Nebula',         'Cloud reflecting light from nearby stars'),
('DARK_NEBULA',        'NEBULA', 'Dark Nebula',               'Dense cloud blocking background light'),
('PLANETARY_NEBULA',   'NEBULA', 'Planetary Nebula',          'Expanding shell from dying star'),
('SUPERNOVA_REMNANT',  'NEBULA', 'Supernova Remnant',         'Debris from stellar explosion'),
('EMISSION_REFLECTION','NEBULA', 'Emission/Reflection Nebula','Combined emission and reflection'),
('WOLF_RAYET_BUBBLE',  'NEBULA', 'Wolf-Rayet Bubble',         'Bubble blown by Wolf-Rayet star winds'),
('HII_REGION',         'NEBULA', 'H II Region',               'Region of ionized hydrogen'),
-- Galaxies
('SPIRAL_GALAXY',        'GALAXY', 'Spiral Galaxy',           'Galaxy with spiral arm structure'),
('BARRED_SPIRAL',        'GALAXY', 'Barred Spiral Galaxy',    'Spiral with central bar'),
('ELLIPTICAL_GALAXY',    'GALAXY', 'Elliptical Galaxy',       'Smooth, ellipsoidal galaxy'),
('IRREGULAR_GALAXY',     'GALAXY', 'Irregular Galaxy',        'Galaxy without regular structure'),
('INTERACTING_GALAXIES', 'GALAXY', 'Interacting Galaxies',    'Gravitationally interacting system'),
-- Clusters
('OPEN_CLUSTER',     'CLUSTER', 'Open Cluster',            'Loosely bound group of young stars'),
('GLOBULAR_CLUSTER', 'CLUSTER', 'Globular Cluster',        'Tightly bound spherical cluster'),
('CLUSTER_NEBULA',   'CLUSTER', 'Cluster with Nebulosity', 'Star cluster embedded in nebula'),
-- Stars
('SINGLE_STAR',   'STAR', 'Star',          'Individual star'),
('DOUBLE_STAR',   'STAR', 'Double Star',   'Visual or physical double star'),
('VARIABLE_STAR', 'STAR', 'Variable Star', 'Star with varying brightness'),
-- Solar System
('SOLAR_SYSTEM',        'PLANETARY', 'Solar System Object', 'Sun, Moon, planets, comets, and other solar system bodies'),
('NON_PERIODIC_COMET',  'PLANETARY', 'Non-Periodic Comet',  'Comet with a hyperbolic or very long-period orbit (e.g. C/2023 A3)');

INSERT INTO Equipment (EquipmentID, EquipmentName, Manufacturer, Model, EquipmentType, FocalLengthMM, ApertureMM, SensorWidthPx, SensorHeightPx, ArcSecsPerPixel) VALUES
('S30', 'Seestar S30', 'ZWO', 'Seestar S30', 'SMART_TELESCOPE', 250, 30, 1080, 1920, NULL),
('S50', 'Seestar S50', 'ZWO', 'Seestar S50', 'SMART_TELESCOPE', 250, 50, 1080, 1920, NULL);

INSERT INTO ImageTypes (ImageTypeID, Description, DefaultWidth, DefaultHeight, AspectRatio, WebFolder) VALUES
('fav',      'Social Media Favorite', 1080, 1350, '4:5',  'fav'),
('full',     'Full Resolution',       NULL, NULL,  NULL,   'full'),
('wall',     'Wallpaper HD',          1920, 1080, '16:9', 'wall'),
('wall4k',   'Wallpaper 4K',          3840, 2160, '16:9', 'wall4k'),
('thumb',    'Thumbnail',             300,  300,  '1:1',  'thumbs'),
('portrait', 'Portrait/Story',        1080, 1920, '9:16', NULL);

INSERT INTO PaletteTreatments (PaletteID, PaletteName, Description, ChannelMapping) VALUES
(0, 'Natural',  'Natural/true color processing', NULL),
(1, 'SHO',      'Hubble Palette',               'R=SII, G=Ha, B=OIII'),
(2, 'HOO',      'Hydrogen-Oxygen-Oxygen',        'R=Ha, G=OIII, B=OIII'),
(3, 'HSO',      'Ha-SII-OIII',                  'R=Ha, G=SII, B=OIII'),
(4, 'OHS',      'OIII-Ha-SII',                  'R=OIII, G=Ha, B=SII'),
(5, 'HOS',      'Ha-OIII-SII',                  'R=Ha, G=OIII, B=SII'),
(6, 'Starless', 'Starless processing',           NULL),
(7, 'Mono',     'Monochrome/Luminance only',     NULL);

INSERT INTO SocialPlatforms (PlatformID, PlatformName, BaseURL) VALUES
('REDDIT',    'Reddit',    'https://reddit.com'),
('INSTAGRAM', 'Instagram', 'https://instagram.com'),
('FACEBOOK',  'Facebook',  'https://facebook.com'),
('TWITTER',   'X/Twitter', 'https://x.com'),
('ASTROBIN',  'AstroBin',  'https://astrobin.com'),
('FLICKR',    'Flickr',    'https://flickr.com');

-- Solar System objects — seeded directly; no CatalogID entries needed
INSERT INTO Objects (DSOKey, CommonName, ObjectTypeID, DistanceLY, ObjectSize, SocialBlurb) VALUES
('SUN', 'The Sun', 'SOLAR_SYSTEM',
 '8.3 light-minutes',
 '864,000 miles (1.4 million km) across — its apparent diameter of about 31-33 arcminutes makes it almost exactly the same size as the full moon in our sky.',
 'The Sun is our closest star — just 8.3 light-minutes away, close enough that we can see its surface in remarkable detail. It is a churning ball of superheated gas, covered in sunspots, flares, and erupting plasma loops called prominences.

At its core, the Sun converts 600 million tonnes of hydrogen into helium every single second. That energy takes around 100,000 years to travel from the core to the surface, then just 8 minutes to reach us. Despite seeing it every day, most people have never seen its surface structure — and it is genuinely spectacular.'),

('MOON', 'The Moon', 'SOLAR_SYSTEM',
 '1.3 light-seconds',
 '2,159 miles (3,475 km) across — its apparent diameter of 29-34 arcminutes is roughly the same as the Sun, which is why solar eclipses look so perfect from Earth.',
 'The Moon is Earth''s only natural satellite, sitting just 1.3 light-seconds away. Close enough that we can see mountains, craters, and ancient lava plains with the naked eye. It is the most detailed object in the night sky by far.

The Moon is slowly drifting away from Earth at about 1.5 inches per year. It has no atmosphere, so every crater it has ever received is still there — a perfect record of billions of years of solar system history. The same side always faces us, so the far side remained completely unseen by human eyes until 1959.');

-- ============================================================
-- USEFUL VIEWS
-- ============================================================

DROP VIEW IF EXISTS vw_ObservationSummary;
CREATE VIEW vw_ObservationSummary AS
SELECT
    o.ObservationDate,
    p.ProjectFolder,
    obj.CommonName,
    e.EquipmentID,
    o.GoodLights,
    o.RejectedLights,
    ROUND(100.0 * o.RejectedLights / NULLIF(o.TotalExposures, 0), 1) AS RejectionPct,
    o.ExposureTimeSecs,
    ROUND(o.GoodLights * o.ExposureTimeSecs / 60.0, 1) AS IntegrationMins
FROM Observations o
JOIN Projects p ON o.ProjectID = p.ProjectID
JOIN Objects obj ON p.DSOKey = obj.DSOKey
JOIN Equipment e ON o.EquipmentID = e.EquipmentID
ORDER BY o.ObservationDate DESC;

DROP VIEW IF EXISTS vw_NeedsMoreData;
CREATE VIEW vw_NeedsMoreData AS
SELECT
    obj.DSOKey,
    obj.CommonName,
    p.ProjectFolder,
    SUM(o.GoodLights) AS TotalLights,
    ROUND(SUM(o.GoodLights * o.ExposureTimeSecs / 60.0), 1) AS TotalIntegrationMins,
    MAX(o.ObservationDate) AS LastObserved
FROM Objects obj
JOIN Projects p ON obj.DSOKey = p.DSOKey
LEFT JOIN Observations o ON p.ProjectID = o.ProjectID
GROUP BY obj.DSOKey
HAVING TotalIntegrationMins < 120 OR TotalIntegrationMins IS NULL
ORDER BY TotalIntegrationMins ASC;

DROP VIEW IF EXISTS vw_ProcessingStatus;
CREATE VIEW vw_ProcessingStatus AS
SELECT
    obj.CommonName,
    p.ProjectFolder,
    pr.ProcessingDateStart,
    pr.Status,
    pr.HoursSpent,
    (SELECT COUNT(*) FROM Images WHERE ProcessingID = pr.ProcessingID AND IsPublished = 1) AS PublishedImages
FROM ProcessingRuns pr
JOIN Projects p ON pr.ProjectID = p.ProjectID
JOIN Objects obj ON p.DSOKey = obj.DSOKey
ORDER BY pr.ProcessingDateStart DESC;
