-- Hintergrund-Jobs (EXIF, Geocoding, KI, ...). Wird per Cron abgearbeitet:
-- * * * * * php /pfad/zur/app/bin/console.php jobs:work --max-runtime=50
CREATE TABLE jobs (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    type VARCHAR(100) NOT NULL,
    payload JSON NOT NULL,
    status ENUM("pending", "running", "done", "failed") NOT NULL DEFAULT "pending",
    attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
    max_attempts TINYINT UNSIGNED NOT NULL DEFAULT 3,
    run_after DATETIME NOT NULL,
    claim_token CHAR(16) NULL,
    last_error TEXT NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    KEY idx_jobs_pick (status, run_after)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
