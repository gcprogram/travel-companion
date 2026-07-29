-- Stationen einer Reiseroute, sortiert über position.
-- lat/lng sind ab Phase 4 (Karte/Geocoding) befüllt, das Schema ist vorbereitet.
CREATE TABLE trip_stations (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    trip_id INT UNSIGNED NOT NULL,
    position SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    name VARCHAR(190) NOT NULL,
    arrival_date DATE NULL,
    lat DECIMAL(9,6) NULL,
    lng DECIMAL(9,6) NULL,
    notes TEXT NULL,
    PRIMARY KEY (id),
    KEY idx_stations_trip (trip_id, position),
    CONSTRAINT fk_stations_trip FOREIGN KEY (trip_id) REFERENCES trips (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
