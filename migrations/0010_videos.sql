-- Videos attached to a diary entry: either an uploaded (client-compressed)
-- file or a referenced YouTube video. Uploaded files live outside the
-- webroot under var/uploads/videos/{id}/ (original.<ext> + poster.webp),
-- served through a permission-checked, Range-aware controller.
CREATE TABLE videos (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    day_entry_id INT UNSIGNED NOT NULL,
    position SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    type ENUM("upload", "youtube") NOT NULL,
    original_filename VARCHAR(255) NULL,
    extension VARCHAR(10) NULL,
    youtube_id VARCHAR(20) NULL,
    status ENUM("pending", "ready", "failed") NOT NULL DEFAULT "pending",
    width SMALLINT UNSIGNED NULL,
    height SMALLINT UNSIGNED NULL,
    duration_seconds SMALLINT UNSIGNED NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    KEY idx_videos_entry (day_entry_id, position),
    CONSTRAINT fk_videos_entry FOREIGN KEY (day_entry_id) REFERENCES day_entries (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
