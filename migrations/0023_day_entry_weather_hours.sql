-- Hourly weather per diary entry, each hour tied to wherever the traveller
-- actually was at that time (nearest track point / geotagged photo, falling
-- back to the entry's own single lat/lng - see WeatherFetchHandler), not
-- just the entry's one fixed location for the whole day. Replaces
-- day_entries.weather_temp_c/weather_code's role for detail; those columns
-- stay as-is for the compact one-line summary.
CREATE TABLE day_entry_weather_hours (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    day_entry_id INT UNSIGNED NOT NULL,
    hour TINYINT UNSIGNED NOT NULL,
    lat DECIMAL(9,6) NOT NULL,
    lng DECIMAL(9,6) NOT NULL,
    temp_c DECIMAL(4,1) NULL,
    feels_like_c DECIMAL(4,1) NULL,
    precipitation_probability TINYINT UNSIGNED NULL,
    weather_code SMALLINT UNSIGNED NULL,
    wind_speed_kmh DECIMAL(5,1) NULL,
    wind_direction_deg SMALLINT UNSIGNED NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_weather_hours_entry_hour (day_entry_id, hour),
    CONSTRAINT fk_weather_hours_entry FOREIGN KEY (day_entry_id) REFERENCES day_entries (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
