-- Personal long-lived API tokens for the MCP (Model Context Protocol)
-- endpoint (/mcp) - lets a user's own AI agent read/write their trips
-- (e.g. dictate a diary entry, attach a photo) without a browser session.
-- Only the SHA-256 hash is stored (same pattern as email_confirmations/
-- password_resets), the raw token is shown to the user once at creation
-- and never recoverable afterwards.
CREATE TABLE mcp_api_tokens (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id INT UNSIGNED NOT NULL,
    label VARCHAR(100) NOT NULL,
    token_hash CHAR(64) NOT NULL,
    last_used_at DATETIME NULL,
    revoked_at DATETIME NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_mcp_api_tokens_hash (token_hash),
    KEY idx_mcp_api_tokens_user (user_id),
    CONSTRAINT fk_mcp_api_tokens_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
