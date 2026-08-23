<?php

declare(strict_types=1);

namespace App\Repository;

use PDO;

/**
 * Saved AI provider profiles (migration 0034_ai_provider_configs.sql) - the
 * encrypted API key itself is NOT stored here, see the migration's own
 * comment for why (Settings::setSecret() under a synthetic per-row key,
 * reused rather than duplicated).
 */
final class AiProviderConfigRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function findAll(): array
    {
        $stmt = $this->pdo->query('SELECT * FROM ai_provider_configs ORDER BY label ASC');
        return $stmt->fetchAll();
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findById(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM ai_provider_configs WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    public function create(string $label, string $provider, string $baseUrl, string $model): int
    {
        $now = gmdate('Y-m-d H:i:s');
        $stmt = $this->pdo->prepare(
            'INSERT INTO ai_provider_configs (label, provider, base_url, model, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([$label, $provider, $baseUrl, $model, $now, $now]);
        return (int) $this->pdo->lastInsertId();
    }

    public function delete(int $id): void
    {
        $this->pdo->prepare('DELETE FROM ai_provider_configs WHERE id = ?')->execute([$id]);
    }
}
