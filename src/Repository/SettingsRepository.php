<?php

declare(strict_types=1);

namespace App\Repository;

use PDO;

final class SettingsRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * @return array<string, string>
     */
    public function all(): array
    {
        $rows = $this->pdo->query('SELECT `key`, `value` FROM settings')->fetchAll();
        $out = [];
        foreach ($rows as $row) {
            $out[(string) $row['key']] = (string) $row['value'];
        }
        return $out;
    }

    public function get(string $key): ?string
    {
        $stmt = $this->pdo->prepare('SELECT `value` FROM settings WHERE `key` = ?');
        $stmt->execute([$key]);
        $value = $stmt->fetchColumn();
        return $value === false ? null : (string) $value;
    }

    public function set(string $key, string $value): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO settings (`key`, `value`, updated_at) VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE `value` = VALUES(`value`), updated_at = VALUES(updated_at)'
        );
        $stmt->execute([$key, $value, gmdate('Y-m-d H:i:s')]);
    }
}
