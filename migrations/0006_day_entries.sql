-- Tagesblog-Eintraege einer Reise. Fotos/Videos folgen in einer spaeteren Migration.
CREATE TABLE day_entries (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    trip_id INT UNSIGNED NOT NULL,
    entry_date DATE NOT NULL,
    title VARCHAR(190) NULL,
    body TEXT NULL,
    mood ENUM("sehr_schlecht", "schlecht", "neutral", "gut", "sehr_gut") NULL,
    rating TINYINT UNSIGNED NULL,
    lat DECIMAL(9,6) NULL,
    lng DECIMAL(9,6) NULL,
    weather_temp_c DECIMAL(4,1) NULL,
    weather_code SMALLINT UNSIGNED NULL,
    weather_fetched_at DATETIME NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    KEY idx_day_entries_trip (trip_id, entry_date),
    CONSTRAINT fk_day_entries_trip FOREIGN KEY (trip_id) REFERENCES trips (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
