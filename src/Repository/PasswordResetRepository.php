<?php

declare(strict_types=1);

namespace App\Repository;

use PDO;

final class PasswordResetRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function create(int $userId, string $tokenHash, \DateTimeImmutable $expiresAt): void
    {
        // Discard the user's old tokens, only the newest one is ever valid.
        $this->pdo->prepare('DELETE FROM password_resets WHERE user_id = ?')->execute([$userId]);

        $stmt = $this->pdo->prepare(
            'INSERT INTO password_resets (user_id, token_hash, expires_at, created_at) VALUES (?, ?, ?, ?)'
        );
        $stmt->execute([$userId, $tokenHash, $expiresAt->format('Y-m-d H:i:s'), gmdate('Y-m-d H:i:s')]);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findValidByTokenHash(string $tokenHash): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM password_resets WHERE token_hash = ? AND expires_at > ?'
        );
        $stmt->execute([$tokenHash, gmdate('Y-m-d H:i:s')]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    public function deleteForUser(int $userId): void
    {
        $this->pdo->prepare('DELETE FROM password_resets WHERE user_id = ?')->execute([$userId]);
    }
}
