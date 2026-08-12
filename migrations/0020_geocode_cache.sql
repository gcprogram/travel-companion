-- Reverse-geocoding cache, ~111m grid (3 decimal places). Lets the map page
-- show a place name for a detected stay without ever calling Nominatim
-- synchronously from a request - a row's mere existence means "already
-- looked up" (name may legitimately be NULL if Nominatim had nothing),
-- so a cache miss is what triggers a one-off geocode.resolve job instead of
-- re-querying every page load. See GeocodeCacheRepository/GeocodeResolveHandler.
CREATE TABLE geocode_cache (
    lat_rounded DECIMAL(6,3) NOT NULL,
    lng_rounded DECIMAL(6,3) NOT NULL,
    name VARCHAR(190) NULL,
    created_at DATETIME NOT NULL,
    PRIMARY KEY (lat_rounded, lng_rounded)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
