-- Tracks found vs. DNF (Did Not Find) separately for imported geocaches
-- (GeocachingGpxParser/PoiController::importGpx) - a DNF still counts as a
-- visited sight (the traveller was there and searched), just with a
-- different outcome worth keeping distinct for a future AI description
-- feature (search time/frustration - see HANDOVER.md step 6 follow-up).
ALTER TABLE trip_pois ADD COLUMN geocache_status ENUM('found', 'dnf') NULL AFTER terrain;
