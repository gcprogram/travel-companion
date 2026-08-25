-- Distinguishes a photo's real EXIF-derived GPS position from one this app
-- computed itself by interpolating between known points (PhotoPosition-
-- InterpolationService) - Stefan's own requirement: an interpolated
-- position must stay recognisable as such so it can be recomputed (not
-- just left alone) once better data arrives (more photo positions before/
-- after, a new/extended GPX track or Google Timeline import), and must
-- never be confused with - or overwrite - a real EXIF fix.
--
-- Existing photos already carrying a lat/lng got there via real EXIF
-- extraction (PhotoProcessHandler predates this column) - backfilled
-- accordingly so they're correctly protected from ever being touched by
-- interpolation.
ALTER TABLE photos
    ADD COLUMN lat_source ENUM('exif', 'interpolated') NULL AFTER lng;

UPDATE photos SET lat_source = 'exif' WHERE lat IS NOT NULL;
