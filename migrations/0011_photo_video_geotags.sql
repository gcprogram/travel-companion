-- Structured GPS coordinates extracted from photo EXIF / video container
-- metadata (see PhotoProcessHandler and video-geotag.js). Needed so the
-- location survives derivative generation / client-side video compression,
-- and so a later phase can use it for maps/routes without re-parsing the
-- original file's binary metadata every time.
ALTER TABLE photos
    ADD COLUMN lat DECIMAL(9,6) NULL AFTER height,
    ADD COLUMN lng DECIMAL(9,6) NULL AFTER lat;

ALTER TABLE videos
    ADD COLUMN lat DECIMAL(9,6) NULL AFTER duration_seconds,
    ADD COLUMN lng DECIMAL(9,6) NULL AFTER lat;
