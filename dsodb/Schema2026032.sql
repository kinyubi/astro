create table Constellations
(
    ConstellationID     TEXT
        primary key,
    Name                TEXT not null,
    GenitiveName        TEXT,
    RightAscensionHours REAL,
    DeclinationDegrees  REAL
);

create table Equipment
(
    EquipmentID      TEXT
        primary key,
    EquipmentName    TEXT not null,
    Manufacturer     TEXT,
    Model            TEXT,
    EquipmentType    TEXT,
    FocalLengthMM    INTEGER,
    ApertureMM       INTEGER,
    PixelSizeMicrons REAL,
    SensorWidthPx    INTEGER,
    SensorHeightPx   INTEGER,
    ArcSecsPerPixel  REAL,
    Notes            TEXT
);

create table ImageTypes
(
    ImageTypeID   TEXT
        primary key,
    Description   TEXT,
    DefaultWidth  INTEGER,
    DefaultHeight INTEGER,
    AspectRatio   TEXT,
    WebFolder     TEXT
);

create table ObjectCategories
(
    CategoryID   TEXT
        primary key,
    CategoryName TEXT not null,
    Description  TEXT
);

create table ObjectTypes
(
    ObjectTypeID TEXT
        primary key,
    CategoryID   TEXT not null
        references ObjectCategories,
    TypeName     TEXT not null,
    Description  TEXT
);

create table Objects
(
    DSOKey                TEXT
        primary key,
    CommonName            TEXT,
    ObjectTypeID          TEXT
        references ObjectTypes,
    ConstellationID       TEXT
        references Constellations,
    RAHours               REAL,
    DecDegrees            REAL,
    Magnitude             REAL,
    ObjectSize            TEXT,
    DistanceLY            TEXT,
    SocialBlurb           TEXT,
    LastUpdated           DATETIME default CURRENT_TIMESTAMP,
    ProjectFolder         TEXT,
    IsMosaic              INTEGER  default 0,
    MostRecentObservation DATE,
    WantBetter            INTEGER  default 0 not null,
    SqArcMins             REAL
);

create table CatalogIDs
(
    CatalogID TEXT not null
        primary key,
    DSOKey    TEXT not null
        references Objects,
    IsPrimary INTEGER default 0
);

create index idx_catalogids_dsokey
    on CatalogIDs (DSOKey);

create table PaletteTreatments
(
    PaletteID      INTEGER
        primary key,
    PaletteName    TEXT not null,
    Description    TEXT,
    ChannelMapping TEXT
);

create table Projects
(
    ProjectID            INTEGER
        primary key autoincrement,
    DSOKey               TEXT not null
        references Objects,
    ProjectFolder        TEXT not null,
    IsMosaic             INTEGER default 0,
    MosaicConfig         TEXT,
    Status               TEXT    default 'ACTIVE',
    TotalGoodLights      INTEGER default 0,
    TotalIntegrationMins REAL,
    CreatedDate          DATE,
    Notes                TEXT
);

create table Observations
(
    ObservationID    INTEGER
        primary key autoincrement,
    ProjectID        INTEGER not null
        references Projects,
    EquipmentID      TEXT    not null
        references Equipment,
    ObservationDate  DATE    not null,
    StartTime        DATETIME,
    EndTime          DATETIME,
    ExposureTimeSecs REAL,
    Filter           TEXT,
    TotalExposures   INTEGER default 0,
    GoodLights       INTEGER default 0,
    RejectedLights   INTEGER default 0,
    MosaicPanel      TEXT,
    BortleScale      INTEGER,
    SeeingConditions TEXT,
    Temperature      REAL,
    Humidity         REAL,
    NinaWorkflowName TEXT,
    Notes            TEXT
);

create index idx_observations_date
    on Observations (ObservationDate);

create index idx_observations_project
    on Observations (ProjectID);

create table ProcessingRuns
(
    ProcessingID        INTEGER
        primary key autoincrement,
    ProjectID           INTEGER not null
        references Projects,
    ProcessingDateStart DATE,
    ProcessingDateEnd   DATE,
    ProcessingSoftware  TEXT,
    WorkflowNotes       TEXT,
    DrizzleScale        REAL,
    LightsUsed          INTEGER,
    IntegrationTimeMins REAL,
    OutputFolder        TEXT,
    MasterFilename      TEXT,
    Status              TEXT default 'IN_PROGRESS',
    HoursSpent          REAL,
    Notes               TEXT
);

create table Images
(
    ImageID       INTEGER
        primary key autoincrement,
    ProcessingID  INTEGER not null
        references ProcessingRuns,
    ImageTypeID   TEXT    not null
        references ImageTypes,
    ParentImageID INTEGER
        references Images,
    PaletteID     INTEGER  default 0
        references PaletteTreatments,
    Filename      TEXT    not null,
    SourcePath    TEXT,
    WebPath       TEXT,
    Width         INTEGER,
    Height        INTEGER,
    IsAnnotated   INTEGER  default 0,
    StarRating    INTEGER,
    IsPublished   INTEGER  default 0,
    IsRetired     INTEGER  default 0,
    CreatedDate   DATETIME default CURRENT_TIMESTAMP,
    Notes         TEXT
);

create index idx_images_processing
    on Images (ProcessingID);

create table ProcessingObservations
(
    ProcessingID  INTEGER not null
        references ProcessingRuns,
    ObservationID INTEGER not null
        references Observations,
    primary key (ProcessingID, ObservationID)
);

create table SocialPlatforms
(
    PlatformID   TEXT
        primary key,
    PlatformName TEXT not null,
    BaseURL      TEXT
);

create table SocialPosts
(
    PostID      INTEGER
        primary key autoincrement,
    ImageID     INTEGER not null
        references Images,
    PlatformID  TEXT    not null
        references SocialPlatforms,
    PostDate    DATETIME,
    PostURL     TEXT,
    Caption     TEXT,
    Hashtags    TEXT,
    Likes       INTEGER default 0,
    Comments    INTEGER default 0,
    Shares      INTEGER default 0,
    LastUpdated DATETIME,
    Notes       TEXT
);

CREATE VIEW vw_GalleryObjects as
SELECT
    o.DSOKey,
    o.CommonName,
    c.CatalogID AS PrimaryCatalogID,
    o.ObjectTypeID,
    ot.TypeName AS ObjectTypeName,
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
    (SELECT MAX(ObservationDate) FROM Observations WHERE ProjectID = p.ProjectID) AS MostRecentObservation,
    (SELECT SUM(GoodLights) FROM Observations WHERE ProjectID = p.ProjectID) AS TotalLights,
    (SELECT SUM(GoodLights * ExposureTimeSecs / 60.0) FROM Observations WHERE ProjectID = p.ProjectID) AS TotalIntegrationMins
FROM Objects o
         LEFT JOIN CatalogIDs c ON o.DSOKey = c.DSOKey AND c.IsPrimary = 1
         LEFT JOIN ObjectTypes ot ON o.ObjectTypeID = ot.ObjectTypeID
         LEFT JOIN ObjectCategories oc ON ot.CategoryID = oc.CategoryID
         LEFT JOIN Constellations con ON o.ConstellationID = con.ConstellationID
         LEFT JOIN Projects p ON o.DSOKey = p.DSOKey AND p.Status = 'ACTIVE';

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

CREATE VIEW vw_ProcessingStatus as
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

