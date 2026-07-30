-- Photos attached to a diary entry. Files live outside the webroot under
-- var/uploads/photos/{id}/ (original + thumb.webp + web.webp), served through
-- a permission-checked controller. status tracks async Imagick processing.
CREATE TABLE photos (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    day_entry_id INT UNSIGNED NOT NULL,
    position SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    original_filename VARCHAR(255) NOT NULL,
    extension VARCHAR(10) NOT NULL,
    status ENUM("pending", "ready", "failed") NOT NULL DEFAULT "pending",
    width SMALLINT UNSIGNED NULL,
    height SMALLINT UNSIGNED NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    KEY idx_photos_entry (day_entry_id, position),
    CONSTRAINT fk_photos_entry FOREIGN KEY (day_entry_id) REFERENCES day_entries (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
