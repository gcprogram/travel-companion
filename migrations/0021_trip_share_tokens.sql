-- Login-free share links for a trip. A visitor who opens /share/{token} gets
-- a cookie (see ShareAccessCookie) remembering the grant for that one trip;
-- the token row itself is the source of truth (view vs edit, revocable by
-- deleting the row) - the cookie only says which token to re-check each
-- time, it carries no permission of its own.
CREATE TABLE trip_share_tokens (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    trip_id INT UNSIGNED NOT NULL,
    token CHAR(64) NOT NULL,
    label VARCHAR(100) NULL,
    permission ENUM('view', 'edit') NOT NULL DEFAULT 'view',
    created_at DATETIME NOT NULL,
    last_used_at DATETIME NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_share_tokens_token (token),
    KEY idx_share_tokens_trip (trip_id),
    CONSTRAINT fk_share_tokens_trip FOREIGN KEY (trip_id) REFERENCES trips (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
