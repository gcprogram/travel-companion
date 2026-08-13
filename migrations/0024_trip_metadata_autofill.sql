-- Supports the new trip metadata auto-fill flow (see TripMetadataAutoFillHandler):
-- country/date range get computed from track points + geotagged photos
-- instead of asked for on the create form, and the "Shared" visibility
-- option in that form gains a real "member_only" state (any logged-in
-- user, not just the owner) alongside the existing private/public ones -
-- "Shared" itself stays a UI-only redirect into the existing share-token
-- flow, never a stored visibility value.
ALTER TABLE geocode_cache ADD COLUMN country VARCHAR(100) NULL AFTER name;
ALTER TABLE trips MODIFY COLUMN visibility ENUM('private', 'member_only', 'public') NOT NULL;
