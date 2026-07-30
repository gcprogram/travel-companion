-- Which photos/videos belong to which POI. Kept as two tables (not one
-- polymorphic one) to mirror the existing photos/videos split. assigned_by
-- distinguishes the auto-assignment algorithm (PoiAssignmentService, nearest
-- POI within ~150m) from a future manual override, without needing a
-- separate audit table.
CREATE TABLE trip_poi_photos (
    poi_id INT UNSIGNED NOT NULL,
    photo_id INT UNSIGNED NOT NULL,
    assigned_by ENUM('auto', 'manual') NOT NULL DEFAULT 'auto',
    created_at DATETIME NOT NULL,
    PRIMARY KEY (poi_id, photo_id),
    KEY idx_poi_photos_photo (photo_id),
    CONSTRAINT fk_poi_photos_poi FOREIGN KEY (poi_id) REFERENCES trip_pois (id) ON DELETE CASCADE,
    CONSTRAINT fk_poi_photos_photo FOREIGN KEY (photo_id) REFERENCES photos (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE trip_poi_videos (
    poi_id INT UNSIGNED NOT NULL,
    video_id INT UNSIGNED NOT NULL,
    assigned_by ENUM('auto', 'manual') NOT NULL DEFAULT 'auto',
    created_at DATETIME NOT NULL,
    PRIMARY KEY (poi_id, video_id),
    KEY idx_poi_videos_video (video_id),
    CONSTRAINT fk_poi_videos_poi FOREIGN KEY (poi_id) REFERENCES trip_pois (id) ON DELETE CASCADE,
    CONSTRAINT fk_poi_videos_video FOREIGN KEY (video_id) REFERENCES videos (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
