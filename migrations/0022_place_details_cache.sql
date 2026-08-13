-- Caches Google Places "Place Details" lookups by placeId (from a Google
-- Timeline import's visit.topCandidate.placeId, which the new export
-- generation gives no plain-text name/address for) - Places API calls cost
-- money per request, so a placeId is never looked up twice. See
-- GooglePlacesService/PlaceDetailsCacheRepository.
CREATE TABLE place_details_cache (
    place_id VARCHAR(190) NOT NULL,
    name VARCHAR(190) NULL,
    address VARCHAR(300) NULL,
    lat DECIMAL(9,6) NULL,
    lng DECIMAL(9,6) NULL,
    created_at DATETIME NOT NULL,
    PRIMARY KEY (place_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
