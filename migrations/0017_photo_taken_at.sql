-- EXIF capture time (DateTimeOriginal), extracted alongside GPS in
-- PhotoProcessHandler. Was previously not captured at all; TripMapController
-- used photos.created_at (upload time, not capture time) as a stand-in for
-- chronological pin ordering. NULL where EXIF has no DateTimeOriginal tag or
-- the photo predates this column (falls back to created_at at read time).
ALTER TABLE photos ADD COLUMN taken_at DATETIME NULL;
