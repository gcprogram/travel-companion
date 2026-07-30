-- GPS track per trip (from GPX upload or reconstructed from photo/video
-- geotags, see trip_track_points.recorded_at). One track per trip: a new
-- upload replaces the previous one entirely (see TrackRepository::replaceForTrip).
-- Points are stored raw and unsmoothed; smoothing/trimming happen read-time
-- (TrackSmoothingService) so tuning the algorithm never requires a re-upload.
CREATE TABLE trip_tracks (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    trip_id INT UNSIGNED NOT NULL,
    source ENUM('gpx', 'points') NOT NULL,
    original_filename VARCHAR(255) NULL,
    trim_start_seq SMALLINT UNSIGNED NULL,
    trim_end_seq SMALLINT UNSIGNED NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_tracks_trip (trip_id),
    CONSTRAINT fk_tracks_trip FOREIGN KEY (trip_id) REFERENCES trips (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE trip_track_points (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    track_id INT UNSIGNED NOT NULL,
    seq SMALLINT UNSIGNED NOT NULL,
    lat DECIMAL(9,6) NOT NULL,
    lng DECIMAL(9,6) NOT NULL,
    elevation_m DECIMAL(7,1) NULL,
    recorded_at DATETIME NULL,
    accuracy_m DECIMAL(6,1) NULL,
    PRIMARY KEY (id),
    KEY idx_track_points_track (track_id, seq),
    CONSTRAINT fk_track_points_track FOREIGN KEY (track_id) REFERENCES trip_tracks (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
