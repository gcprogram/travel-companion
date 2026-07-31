<?php

declare(strict_types=1);

namespace App\Repository;

use PDO;

final class UserRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findByEmail(string $email): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM users WHERE email = ?');
        $stmt->execute([mb_strtolower(trim($email))]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findById(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM users WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    public function countAll(): int
    {
        return (int) $this->pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
    }

    public function create(string $email, string $name, string $passwordHash, string $role): int
    {
        $now = gmdate('Y-m-d H:i:s');
        $stmt = $this->pdo->prepare(
            'INSERT INTO users (email, name, password_hash, role, is_active, created_at, updated_at)
             VALUES (?, ?, ?, ?, 1, ?, ?)'
        );
        $stmt->execute([mb_strtolower(trim($email)), trim($name), $passwordHash, $role, $now, $now]);
        return (int) $this->pdo->lastInsertId();
    }

    public function updatePassword(int $userId, string $passwordHash): void
    {
        $stmt = $this->pdo->prepare('UPDATE users SET password_hash = ?, updated_at = ? WHERE id = ?');
        $stmt->execute([$passwordHash, gmdate('Y-m-d H:i:s'), $userId]);
    }

    public function recordLogin(int $userId): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE users SET last_login_at = ?, login_count = login_count + 1 WHERE id = ?'
        );
        $stmt->execute([gmdate('Y-m-d H:i:s'), $userId]);
    }
}
