<?php

declare(strict_types=1);

namespace App\Repository;

use PDO;

final class TripRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * All trips the (possibly logged-out) viewer is allowed to see,
     * newest first by trip start date.
     *
     * @return list<array<string, mixed>>
     */
    public function findVisibleFor(?int $viewerId, bool $isAdmin): array
    {
        if ($isAdmin) {
            $stmt = $this->pdo->query($this->baseSelect() . ' ORDER BY t.date_start DESC, t.id DESC');
            return $stmt->fetchAll();
        }

        if ($viewerId === null) {
            $stmt = $this->pdo->prepare(
                $this->baseSelect() . " WHERE t.visibility = 'public' ORDER BY t.date_start DESC, t.id DESC"
            );
            $stmt->execute();
            return $stmt->fetchAll();
        }

        $stmt = $this->pdo->prepare(
            $this->baseSelect() . " WHERE t.visibility = 'public' OR t.user_id = ?
             ORDER BY t.date_start DESC, t.id DESC"
        );
        $stmt->execute([$viewerId]);
        return $stmt->fetchAll();
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findBySlug(string $slug): ?array
    {
        $stmt = $this->pdo->prepare($this->baseSelect() . ' WHERE t.slug = ?');
        $stmt->execute([$slug]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findById(int $id): ?array
    {
        $stmt = $this->pdo->prepare($this->baseSelect() . ' WHERE t.id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    /**
     * @param array<string, mixed> $data
     */
    public function create(int $userId, array $data): int
    {
        $now = gmdate('Y-m-d H:i:s');
        $stmt = $this->pdo->prepare(
            'INSERT INTO trips (user_id, title, slug, country, operator, description,
                                date_start, date_end, visibility, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $userId,
            $data['title'],
            $data['slug'],
            $data['country'],
            $data['operator'],
            $data['description'],
            $data['date_start'],
            $data['date_end'],
            $data['visibility'],
            $now,
            $now,
        ]);
        return (int) $this->pdo->lastInsertId();
    }

    /**
     * @param array<string, mixed> $data
     */
    public function update(int $id, array $data): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE trips SET title = ?, slug = ?, country = ?, operator = ?, description = ?,
                    date_start = ?, date_end = ?, visibility = ?, updated_at = ?
             WHERE id = ?'
        );
        $stmt->execute([
            $data['title'],
            $data['slug'],
            $data['country'],
            $data['operator'],
            $data['description'],
            $data['date_start'],
            $data['date_end'],
            $data['visibility'],
            gmdate('Y-m-d H:i:s'),
            $id,
        ]);
    }

    public function delete(int $id): void
    {
        $this->pdo->prepare('DELETE FROM trips WHERE id = ?')->execute([$id]);
    }

    /**
     * Moves every trip owned by one user to another. Nothing else needs to
     * move: slugs are globally unique regardless of owner, and photo/video
     * file paths are keyed by their own id, not the owning user's.
     *
     * @return int number of trips transferred
     */
    public function transferOwnership(int $fromUserId, int $toUserId): int
    {
        $stmt = $this->pdo->prepare('UPDATE trips SET user_id = ?, updated_at = ? WHERE user_id = ?');
        $stmt->execute([$toUserId, gmdate('Y-m-d H:i:s'), $fromUserId]);
        return $stmt->rowCount();
    }

    public function countByUser(int $userId): int
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM trips WHERE user_id = ?');
        $stmt->execute([$userId]);
        return (int) $stmt->fetchColumn();
    }

    public function slugExists(string $slug, ?int $exceptId = null): bool
    {
        if ($exceptId !== null) {
            $stmt = $this->pdo->prepare('SELECT 1 FROM trips WHERE slug = ? AND id <> ?');
            $stmt->execute([$slug, $exceptId]);
        } else {
            $stmt = $this->pdo->prepare('SELECT 1 FROM trips WHERE slug = ?');
            $stmt->execute([$slug]);
        }
        return $stmt->fetchColumn() !== false;
    }

    private function baseSelect(): string
    {
        return 'SELECT t.*, u.name AS author_name FROM trips t JOIN users u ON u.id = t.user_id';
    }
}
