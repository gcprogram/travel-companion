<?php

declare(strict_types=1);

namespace App\Repository;

use PDO;

final class LoginAttemptRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function record(string $ip, ?string $email): void
    {
        $stmt = $this->pdo->prepare('INSERT INTO login_attempts (ip, email, created_at) VALUES (?, ?, ?)');
        $stmt->execute([$ip, $email, gmdate('Y-m-d H:i:s')]);
    }

    public function countRecent(string $ip, int $windowSeconds): int
    {
        $since = gmdate('Y-m-d H:i:s', time() - $windowSeconds);
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM login_attempts WHERE ip = ? AND created_at >= ?');
        $stmt->execute([$ip, $since]);
        return (int) $stmt->fetchColumn();
    }
}
