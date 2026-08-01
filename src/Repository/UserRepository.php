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

    public function create(string $email, string $name, string $passwordHash, string $role, bool $active): int
    {
        $now = gmdate('Y-m-d H:i:s');
        $stmt = $this->pdo->prepare(
            'INSERT INTO users (email, name, password_hash, role, is_active, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([mb_strtolower(trim($email)), trim($name), $passwordHash, $role, $active ? 1 : 0, $now, $now]);
        return (int) $this->pdo->lastInsertId();
    }

    public function markEmailConfirmed(int $userId): void
    {
        $stmt = $this->pdo->prepare('UPDATE users SET email_confirmed_at = ?, updated_at = ? WHERE id = ?');
        $now = gmdate('Y-m-d H:i:s');
        $stmt->execute([$now, $now, $userId]);
    }

    public function markApprovedAndActive(int $userId): void
    {
        $stmt = $this->pdo->prepare('UPDATE users SET approved_at = ?, is_active = 1, updated_at = ? WHERE id = ?');
        $now = gmdate('Y-m-d H:i:s');
        $stmt->execute([$now, $now, $userId]);
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

    /**
     * @return list<array<string, mixed>>
     */
    public function findAll(): array
    {
        $stmt = $this->pdo->query('SELECT * FROM users ORDER BY created_at ASC');
        return $stmt->fetchAll();
    }

    public function setRole(int $userId, string $role): void
    {
        $stmt = $this->pdo->prepare('UPDATE users SET role = ?, updated_at = ? WHERE id = ?');
        $stmt->execute([$role, gmdate('Y-m-d H:i:s'), $userId]);
    }

    public function setActive(int $userId, bool $active): void
    {
        $stmt = $this->pdo->prepare('UPDATE users SET is_active = ?, updated_at = ? WHERE id = ?');
        $stmt->execute([$active ? 1 : 0, gmdate('Y-m-d H:i:s'), $userId]);
    }

    public function setStorageQuotaOverride(int $userId, ?int $bytes): void
    {
        $stmt = $this->pdo->prepare('UPDATE users SET storage_quota_override_bytes = ?, updated_at = ? WHERE id = ?');
        $stmt->execute([$bytes, gmdate('Y-m-d H:i:s'), $userId]);
    }

    /**
     * Deletion itself is deliberately not exposed here in a way callers
     * could invoke synchronously - see UserDeleteHandler. The DB row is
     * only removed after that job has cleared the user's files, so a
     * request-time delete never leaves a used-up user record with orphaned
     * files and no owner to attribute them to.
     */
    public function delete(int $userId): void
    {
        $this->pdo->prepare('DELETE FROM users WHERE id = ?')->execute([$userId]);
    }
}
