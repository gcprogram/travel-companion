-- Caches AI-translated sight names by their original (non-Latin-script)
-- OSM name, so the same place name is never sent to the AI provider twice -
-- global, not per-trip: a translation of e.g. "วัดพระแก้ว" -> "Wat Phra
-- Kaew" is the same fact regardless of which trip/user encounters it. See
-- PoiNameTranslationService/PoiDiscoveryService.
CREATE TABLE poi_name_translations (
    source_text VARCHAR(190) NOT NULL,
    translated_text VARCHAR(190) NOT NULL,
    created_at DATETIME NOT NULL,
    PRIMARY KEY (source_text)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
