-- Reisen. slug ist der URL-Bestandteil, visibility steuert den öffentlichen Zugriff.
CREATE TABLE trips (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id INT UNSIGNED NOT NULL,
    title VARCHAR(190) NOT NULL,
    slug VARCHAR(190) NOT NULL,
    country VARCHAR(100) NULL,
    operator VARCHAR(190) NULL,
    description TEXT NULL,
    date_start DATE NULL,
    date_end DATE NULL,
    visibility ENUM("private", "public") NOT NULL DEFAULT "private",
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_trips_slug (slug),
    KEY idx_trips_user (user_id),
    KEY idx_trips_dates (date_start, date_end),
    CONSTRAINT fk_trips_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
