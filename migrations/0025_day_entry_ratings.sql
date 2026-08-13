-- Star ratings move from the entry's creator (day_entries.rating, one value
-- set by whoever could edit the entry) to its viewers: one rating per
-- logged-in user per entry, averaged for display (see
-- DayEntryRatingRepository). Existing creator-set ratings are kept as that
-- trip owner's own rating rather than silently discarded, so an average
-- exists immediately instead of every entry resetting to "not yet rated".
CREATE TABLE day_entry_ratings (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    day_entry_id INT UNSIGNED NOT NULL,
    user_id INT UNSIGNED NOT NULL,
    rating TINYINT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_day_entry_ratings_entry_user (day_entry_id, user_id),
    KEY idx_day_entry_ratings_entry (day_entry_id),
    CONSTRAINT fk_day_entry_ratings_entry FOREIGN KEY (day_entry_id) REFERENCES day_entries (id) ON DELETE CASCADE,
    CONSTRAINT fk_day_entry_ratings_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO day_entry_ratings (day_entry_id, user_id, rating, created_at, updated_at)
SELECT e.id, t.user_id, e.rating, NOW(), NOW()
FROM day_entries e
JOIN trips t ON t.id = e.trip_id
WHERE e.rating IS NOT NULL;

ALTER TABLE day_entries DROP COLUMN rating;
