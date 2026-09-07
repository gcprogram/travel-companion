-- Bulk "KI generiere Fotobeschreibung" (Stefan's ask): one photo.caption
-- job per photo, tracked as a batch so the progress panel can find "this
-- trip's current batch" after a reload without any client-side state.
CREATE TABLE photo_caption_batches (
    id VARCHAR(32) NOT NULL,
    trip_id INT UNSIGNED NOT NULL,
    mode ENUM('missing', 'overwrite') NOT NULL,
    total INT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    KEY idx_photo_caption_batches_trip (trip_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Shared, persistent rate-limit state per AI purpose slot (currently only
-- 'vision', used by the bulk caption job) - survives across cron ticks and
-- across every user's uploads, since the provider's RPM limit is per API
-- key, not per user. interval_seconds/consecutive_ok/next_allowed_at
-- implement the adaptive backoff-then-probe algorithm in
-- PhotoCaptionHandler; provider_config_id lets the handler remember which
-- saved provider profile it fell back to, independent of the admin's
-- assigned primary (Settings 'ai.slot.vision').
CREATE TABLE ai_rate_limit_state (
    slot VARCHAR(32) NOT NULL,
    provider_config_id INT UNSIGNED NULL,
    interval_seconds INT UNSIGNED NOT NULL DEFAULT 0,
    consecutive_ok INT UNSIGNED NOT NULL DEFAULT 0,
    next_allowed_at DATETIME NULL,
    updated_at DATETIME NOT NULL,
    PRIMARY KEY (slot)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
