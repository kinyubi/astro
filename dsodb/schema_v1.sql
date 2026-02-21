-- ============================================================
-- ASTRO DATABASE SCHEMA v1.0
-- Deep Sky Object Observation & Image Management
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
    AngularSize TEXT,                   -- e.g., '90×40' or '45' (arcminutes); largest dim first
    DistanceLY TEXT,                    -- Text to allow ranges like '~1,300-1,500 light-years'
    SocialBlurb TEXT,                   -- 1-2 paragraph narrative: composition, distance, fun facts
                                        -- Used as the basis for social media captions
    LastUpdated DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (ObjectTypeID) REFERENCES ObjectTypes(ObjectTypeID),
    FOREIGN KEY (ConstellationID) REFERENCES Constellations(ConstellationID)
);

DROP TABLE IF EXISTS CatalogIDs;
CREATE TABLE CatalogIDs (
    CatalogID TEXT NOT NULL,            -- e.g., 'M42', 'NGC1976', 'LBN974'
    DSOKey TEXT NOT NULL,
    CatalogName TEXT,                   -- 'Messier', 'NGC', 'IC', 'Sharpless', etc.
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
    o.AngularSize,
    o.DistanceLY,
    o.SocialBlurb,
    p.ProjectFolder,
    p.IsMosaic,
    (SELECT MAX(ObservationDate) FROM Observations WHERE ProjectID = p.ProjectID) AS MostRecentObservation,
    (SELECT SUM(GoodLights) FROM Observations WHERE ProjectID = p.ProjectID) AS TotalLights,
    (SELECT SUM(GoodLights * ExposureTimeSecs / 60.0) FROM Observations WHERE ProjectID = p.ProjectID) AS TotalIntegrationMins
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
('PLANETARY', 'Solar System Object', 'Sun, Moon, planets, comets, etc.'),
('OTHER',     'Other',               'Other celestial objects');

INSERT INTO ObjectTypes (ObjectTypeID, CategoryID, TypeName, Description) VALUES
-- Nebulae
('EMISSION_NEBULA',   'NEBULA', 'Emission Nebula',          'Cloud of ionized gas emitting light'),
('REFLECTION_NEBULA', 'NEBULA', 'Reflection Nebula',        'Cloud reflecting light from nearby stars'),
('DARK_NEBULA',       'NEBULA', 'Dark Nebula',              'Dense cloud blocking background light'),
('PLANETARY_NEBULA',  'NEBULA', 'Planetary Nebula',         'Expanding shell from dying star'),
('SUPERNOVA_REMNANT', 'NEBULA', 'Supernova Remnant',        'Debris from stellar explosion'),
('EMISSION_REFLECTION','NEBULA','Emission/Reflection Nebula','Combined emission and reflection'),
('WOLF_RAYET_BUBBLE', 'NEBULA', 'Wolf-Rayet Bubble',        'Bubble blown by Wolf-Rayet star winds'),
('HII_REGION',        'NEBULA', 'H II Region',              'Region of ionized hydrogen'),
-- Galaxies
('SPIRAL_GALAXY',       'GALAXY', 'Spiral Galaxy',          'Galaxy with spiral arm structure'),
('BARRED_SPIRAL',       'GALAXY', 'Barred Spiral Galaxy',   'Spiral with central bar'),
('ELLIPTICAL_GALAXY',   'GALAXY', 'Elliptical Galaxy',      'Smooth, ellipsoidal galaxy'),
('IRREGULAR_GALAXY',    'GALAXY', 'Irregular Galaxy',       'Galaxy without regular structure'),
('INTERACTING_GALAXIES','GALAXY', 'Interacting Galaxies',   'Gravitationally interacting system'),
-- Clusters
('OPEN_CLUSTER',    'CLUSTER', 'Open Cluster',           'Loosely bound group of young stars'),
('GLOBULAR_CLUSTER','CLUSTER', 'Globular Cluster',       'Tightly bound spherical cluster'),
('CLUSTER_NEBULA',  'CLUSTER', 'Cluster with Nebulosity','Star cluster embedded in nebula'),
-- Stars
('SINGLE_STAR',  'STAR', 'Star',          'Individual star'),
('DOUBLE_STAR',  'STAR', 'Double Star',   'Visual or physical double star'),
('VARIABLE_STAR','STAR', 'Variable Star', 'Star with varying brightness'),
-- Solar System
('SOLAR_SYSTEM', 'PLANETARY', 'Solar System Object', 'Sun, Moon, planets, comets, and other solar system bodies');

INSERT INTO Equipment (EquipmentID, EquipmentName, Manufacturer, Model, EquipmentType, FocalLengthMM, ApertureMM, SensorWidthPx, SensorHeightPx, ArcSecsPerPixel) VALUES
('S30', 'Seestar S30', 'ZWO', 'Seestar S30', 'SMART_TELESCOPE', 250, 30, 1080, 1920, NULL),
('S50', 'Seestar S50', 'ZWO', 'Seestar S50', 'SMART_TELESCOPE', 250, 50, 1080, 1920, NULL);

INSERT INTO ImageTypes (ImageTypeID, Description, DefaultWidth, DefaultHeight, AspectRatio, WebFolder) VALUES
('fav',     'Social Media Favorite', 1080, 1350, '4:5',  'fav'),
('full',    'Full Resolution',       NULL, NULL,  NULL,   'full'),
('wall',    'Wallpaper HD',          1920, 1080, '16:9', 'wall'),
('wall4k',  'Wallpaper 4K',          3840, 2160, '16:9', 'wall4k'),
('thumb',   'Thumbnail',             300,  300,  '1:1',  'thumbs'),
('portrait','Portrait/Story',        1080, 1920, '9:16', NULL);

INSERT INTO PaletteTreatments (PaletteID, PaletteName, Description, ChannelMapping) VALUES
(0, 'Natural',  'Natural/true color processing', NULL),
(1, 'SHO',      'Hubble Palette',               'R=SII, G=Ha, B=OIII'),
(2, 'HOO',      'Hydrogen-Oxygen-Oxygen',        'R=Ha, G=OIII, B=OIII'),
(3, 'HSO',      'Ha-SII-OIII',                  'R=Ha, G=SII, B=OIII'),
(4, 'OHS',      'OIII-Ha-SII',                  'R=OIII, G=Ha, B=SII'),
(5, 'HOS',      'Ha-OIII-SII',                  'R=Ha, G=OIII, B=SII'),
(6, 'Starless', 'Starless processing',           NULL),
(7, 'Mono',     'Monochrome/Luminance only',     NULL);

-- Solar System objects — seeded directly; no CatalogID entries needed
INSERT INTO Objects (DSOKey, CommonName, ObjectTypeID, DistanceLY, AngularSize, SocialBlurb) VALUES
('SUN', 'The Sun', 'SOLAR_SYSTEM',
 '8.3 light-minutes',
 '31.6–32.7',
 'The Sun is a middle-aged G-type main-sequence star sitting just 8.3 light-minutes from Earth — the closest star by an enormous margin, and the only one we can image in true detail. Its surface churns with granules, sunspots, filaments, and erupting prominences, making it one of the most dynamic and rewarding targets in all of astrophotography.

At its core, the Sun fuses roughly 620 million tonnes of hydrogen into helium every second, releasing the energy that drives all life on Earth. It accounts for 99.86% of the total mass of the Solar System, with a surface temperature around 5,500°C and a core reaching 15 million°C. Despite its familiarity, no two solar images are ever quite the same — the Sun is always changing.'),

('MOON', 'The Moon', 'SOLAR_SYSTEM',
 '1.3 light-seconds',
 '29.4–33.5',
 'The Moon is Earth''s only natural satellite and the most detail-rich target in the night sky — close enough at just 1.3 light-seconds that even modest equipment resolves craters, mountains, rilles, and ancient lava plains with striking clarity. Its heavily cratered surface is a pristine record of billions of years of solar system history, preserved by the absence of an atmosphere and geological activity.

The Moon drifts away from Earth at ~3.8 cm per year, and its gravitational pull is the primary driver of Earth''s ocean tides. For astrophotographers, it offers endless variety across its 29.5-day cycle — from the dramatic shadow play along a crescent terminator to the ghostly glow of earthshine on the dark limb. Every phase tells a different story.');

INSERT INTO SocialPlatforms (PlatformID, PlatformName, BaseURL) VALUES
('REDDIT',    'Reddit',     'https://reddit.com'),
('INSTAGRAM', 'Instagram',  'https://instagram.com'),
('FACEBOOK',  'Facebook',   'https://facebook.com'),
('TWITTER',   'X/Twitter',  'https://x.com'),
('ASTROBIN',  'AstroBin',   'https://astrobin.com'),
('FLICKR',    'Flickr',     'https://flickr.com');

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
    pt.PaletteName,
    pr.Status,
    pr.HoursSpent,
    (SELECT COUNT(*) FROM Images WHERE ProcessingID = pr.ProcessingID AND IsPublished = 1) AS PublishedImages
FROM ProcessingRuns pr
JOIN Projects p ON pr.ProjectID = p.ProjectID
JOIN Objects obj ON p.DSOKey = obj.DSOKey
LEFT JOIN PaletteTreatments pt ON pr.PaletteID = pt.PaletteID
ORDER BY pr.ProcessingDateStart DESC;
