-- ============================================================
-- fix_ngc6960_projects.sql  (one-off, 2026-10-06)
--
-- NGC6960 ended up with two Projects:
--   #43  ProjectFolder 'ngc6960_witches_broom_nebula'  <- folder no longer exists on disk
--   #66  ProjectFolder 'ngc6960_western_veil_nebula'   <- the real folder; created by
--        the admin's "unregistered folder" prompt, and already audited (it holds all
--        four sessions incl. 20250818_701x10s_S30, 584.5 mins total)
--
-- This folds #43 into #66 and fixes the stale GalleryImages BaseName
-- 'ngc6960_witches_broom_S30' (no such file) -> 'ngc6960_western_veil_nebula_S30'
-- (the fav file that actually exists), keeping that row's other fields.
--
-- Runs in a transaction. Review the SELECT output, then COMMIT (or ROLLBACK).
-- ============================================================

BEGIN;

-- 1. Look before touching anything
SELECT * FROM Observations WHERE ProjectID IN (43, 66) ORDER BY ObservationDate, ProjectID;
SELECT GalleryImageID, BaseName, ProjectID, SessionDir, IsFeature
  FROM GalleryImages WHERE DSOKey = 'NGC6960' ORDER BY SortOrder, GalleryImageID;

-- 2. Re-link any gallery images from #43 to #66
UPDATE GalleryImages SET ProjectID = 66 WHERE ProjectID = 43;

-- 3. Fix the stale BaseName (only if no row already uses the correct name)
UPDATE GalleryImages
   SET BaseName = 'ngc6960_western_veil_nebula_S30'
 WHERE DSOKey = 'NGC6960'
   AND BaseName = 'ngc6960_witches_broom_S30'
   AND NOT EXISTS (SELECT 1 FROM GalleryImages
                    WHERE DSOKey = 'NGC6960'
                      AND LOWER(BaseName) = 'ngc6960_western_veil_nebula_s30');

-- 4. Drop #43's duplicate observation(s) and the Project itself
DELETE FROM ProcessingObservations
 WHERE ObservationID IN (SELECT ObservationID FROM Observations WHERE ProjectID = 43);
DELETE FROM Observations WHERE ProjectID = 43;
DELETE FROM Projects     WHERE ProjectID = 43;

-- 5. Confirm
SELECT ProjectID, ProjectFolder FROM Projects WHERE DSOKey = 'NGC6960';
SELECT GalleryImageID, BaseName, ProjectID FROM GalleryImages WHERE DSOKey = 'NGC6960';

DELETE FROM GalleryImages WHERE GalleryImageID = 42;
