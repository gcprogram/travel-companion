<?php

declare(strict_types=1);

namespace App\Repository;

use PDO;

final class ShareTokenRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * @return array<string, mixed>
     */
    public function create(int $tripId, string $permission, ?string $label): array
    {
        $token = bin2hex(random_bytes(32));
        $now = gmdate('Y-m-d H:i:s');
        $stmt = $this->pdo->prepare(
            'INSERT INTO trip_share_tokens (trip_id, token, label, permission, created_at)
             VALUES (?, ?, ?, ?, ?)'
        );
        $stmt->execute([$tripId, $token, $label, $permission, $now]);

        return [
            'id' => (int) $this->pdo->lastInsertId(),
            'trip_id' => $tripId,
            'token' => $token,
            'label' => $label,
            'permission' => $permission,
            'created_at' => $now,
            'last_used_at' => null,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function findByTrip(int $tripId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM trip_share_tokens WHERE trip_id = ? ORDER BY created_at DESC'
        );
        $stmt->execute([$tripId]);
        return $stmt->fetchAll();
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findById(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM trip_share_tokens WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findByToken(string $token): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM trip_share_tokens WHERE token = ?');
        $stmt->execute([$token]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    public function touchLastUsed(int $id): void
    {
        $this->pdo->prepare('UPDATE trip_share_tokens SET last_used_at = ? WHERE id = ?')
            ->execute([gmdate('Y-m-d H:i:s'), $id]);
    }

    public function delete(int $id): void
    {
        $this->pdo->prepare('DELETE FROM trip_share_tokens WHERE id = ?')->execute([$id]);
    }
}
