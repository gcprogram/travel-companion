-- Points of interest along a trip's route, either discovered via Overpass
-- (source='overpass', external_ref = "node/123" etc. for idempotent
-- re-discovery — this doubling as the "caching" the CLAUDE.md decision log
-- calls for, since results just live on the trip rather than a separate
-- global cache) or added by hand (source='manual', external_ref NULL;
-- MySQL allows multiple NULLs in a UNIQUE index so these never collide).
CREATE TABLE trip_pois (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    trip_id INT UNSIGNED NOT NULL,
    source ENUM('overpass', 'manual') NOT NULL,
    external_ref VARCHAR(40) NULL,
    category VARCHAR(40) NOT NULL,
    name VARCHAR(190) NOT NULL,
    lat DECIMAL(9,6) NOT NULL,
    lng DECIMAL(9,6) NOT NULL,
    visit_date DATE NULL,
    visited TINYINT(1) NOT NULL DEFAULT 0,
    notes TEXT NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    KEY idx_pois_trip (trip_id),
    UNIQUE KEY uq_pois_trip_ref (trip_id, external_ref),
    CONSTRAINT fk_pois_trip FOREIGN KEY (trip_id) REFERENCES trips (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
