-- Scopes geocode_cache to a trip instead of being one global table shared by
-- every trip of every user (Stefan's Teil-8 ask: the "Ortsnamen-Cache
-- leeren" admin button was clearing everyone's cache at once). trip_id=0 is
-- a sentinel for the pre-migration rows (a coordinate's owning trip isn't
-- knowable in general - the same point can appear in more than one trip),
-- kept only so the PRIMARY KEY can stay NOT NULL; nothing writes trip_id=0
-- going forward, so those rows are effectively orphaned and will simply be
-- re-resolved (harmless - see GeocodeCacheRepository/GeocodeResolveHandler,
-- a cache miss just costs a re-lookup, never a failure).
ALTER TABLE geocode_cache
    ADD COLUMN trip_id INT NOT NULL DEFAULT 0 AFTER lng_rounded,
    DROP PRIMARY KEY,
    ADD PRIMARY KEY (trip_id, lat_rounded, lng_rounded);
