-- Saved AI provider profiles (label, provider preset, base URL, model) -
-- lets several AI features each pick their own provider/model later
-- (e.g. a cheap/fast one vs. a stronger one) instead of one shared
-- ai.base_url/model/key. The encrypted API key itself is NOT a column
-- here - it's stored via the existing Settings::setSecret() mechanism
-- under a synthetic key 'ai.provider.<id>.api_key', reusing the same
-- sodium encryption/APP_KEY path every other secret in this app already
-- goes through rather than duplicating that logic. See
-- AiProviderConfigRepository/AiProviderResolver.
--
-- Which config a given feature actually uses is a plain Settings value
-- (e.g. 'ai.slot.main' = this table's id) - deliberately not a column
-- here, since the set of named slots is expected to grow (vision, ...)
-- without needing a schema change each time.
CREATE TABLE ai_provider_configs (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    label VARCHAR(100) NOT NULL,
    provider VARCHAR(40) NOT NULL,
    base_url VARCHAR(255) NOT NULL,
    model VARCHAR(190) NOT NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
