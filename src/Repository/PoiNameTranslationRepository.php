<?php

declare(strict_types=1);

namespace App\Repository;

use PDO;

/**
 * AI-translated sight-name cache, keyed by the original (non-Latin-script)
 * OSM name (migration 0033_poi_name_translations.sql) - global rather than
 * per-trip, since "วัดพระแก้ว" translates to "Wat Phra Kaew" the same way
 * no matter which trip encounters it. See PoiNameTranslationService.
 */
final class PoiNameTranslationRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function find(string $sourceText): ?string
    {
        $stmt = $this->pdo->prepare('SELECT translated_text FROM poi_name_translations WHERE source_text = ?');
        $stmt->execute([$sourceText]);
        $value = $stmt->fetchColumn();
        return $value === false ? null : $value;
    }

    public function store(string $sourceText, string $translatedText): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO poi_name_translations (source_text, translated_text, created_at) VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE translated_text = VALUES(translated_text), created_at = VALUES(created_at)'
        );
        $stmt->execute([$sourceText, $translatedText, gmdate('Y-m-d H:i:s')]);
    }
}
