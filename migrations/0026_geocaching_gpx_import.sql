-- Supports importing found caches from a Geocaching GPX (see
-- GeocachingGpxParser/PoiController::importGpx) as sights with their real
-- cache_type icon - trip_pois gains the geocaching-specific fields and a
-- third source, external_ref becomes the GC code (e.g. "GC11PH4") so
-- re-importing the same file/PQ updates rather than duplicates (same
-- pattern as the existing 'overpass' source + external_ref).
ALTER TABLE trip_pois
    MODIFY COLUMN source ENUM('overpass', 'manual', 'geocaching_gpx') NOT NULL,
    ADD COLUMN gc_code VARCHAR(10) NULL AFTER external_ref,
    ADD COLUMN cache_type VARCHAR(20) NULL AFTER category,
    ADD COLUMN difficulty DECIMAL(2,1) NULL AFTER cache_type,
    ADD COLUMN terrain DECIMAL(2,1) NULL AFTER difficulty;
