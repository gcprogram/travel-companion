-- User management foundation: new role model (admin/manager/ai_user/user),
-- login stats, email-confirmation state, per-user quota override, AI token
-- counters, a DB-backed settings store (admin-changeable at runtime, unlike
-- .env), email-confirmation tokens (mirrors password_resets) and
-- registration rate-limit bookkeeping (mirrors login_attempts).

-- Role enum rebuild in three steps: the enum can't drop values while rows
-- still hold them, so widen -> migrate rows -> narrow. Existing admins stay
-- admin (the first registered user); author/visitor were never
-- distinguished anywhere in code and both become plain users.
ALTER TABLE users MODIFY role ENUM('admin', 'author', 'visitor', 'manager', 'ai_user', 'user') NOT NULL DEFAULT 'user';
UPDATE users SET role = 'user' WHERE role IN ('author', 'visitor');
ALTER TABLE users MODIFY role ENUM('admin', 'manager', 'ai_user', 'user') NOT NULL DEFAULT 'user';

ALTER TABLE users
    ADD COLUMN last_login_at DATETIME NULL,
    ADD COLUMN login_count INT UNSIGNED NOT NULL DEFAULT 0,
    ADD COLUMN email_confirmed_at DATETIME NULL,
    ADD COLUMN approved_at DATETIME NULL,
    ADD COLUMN storage_quota_override_bytes BIGINT UNSIGNED NULL,
    ADD COLUMN ai_tokens_used INT UNSIGNED NOT NULL DEFAULT 0,
    ADD COLUMN ai_tokens_month CHAR(7) NULL;

-- Accounts that exist and are active predate confirmation/approval and are
-- grandfathered in as both confirmed and approved.
UPDATE users SET email_confirmed_at = created_at, approved_at = created_at WHERE is_active = 1;

-- Admin-changeable runtime settings (quota defaults, registration mode, ...).
-- Values that will ever hold secrets (e.g. AI API keys in phase 5) must be
-- sodium-encrypted by the writing service before they land in `value`.
CREATE TABLE settings (
    `key` VARCHAR(100) NOT NULL,
    `value` TEXT NOT NULL,
    updated_at DATETIME NOT NULL,
    PRIMARY KEY (`key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Email-confirmation tokens, one valid per user (newest wins), token stored
-- hashed only - same construction as password_resets.
CREATE TABLE email_confirmations (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id INT UNSIGNED NOT NULL,
    token_hash CHAR(64) NOT NULL,
    expires_at DATETIME NOT NULL,
    created_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    KEY idx_emailconf_user (user_id),
    KEY idx_emailconf_token (token_hash),
    CONSTRAINT fk_emailconf_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Registration attempt log for the anti-abuse rules (per-email cooldown,
-- per-IP failure block, per-IP distinct-email cap). Rows age out via
-- pruning on insert; no FK on purpose (emails may never become users).
CREATE TABLE registration_attempts (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    ip VARCHAR(45) NOT NULL,
    email VARCHAR(190) NULL,
    result ENUM('started', 'confirmed', 'failed') NOT NULL,
    created_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    KEY idx_regattempts_ip_time (ip, created_at),
    KEY idx_regattempts_email_time (email, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
