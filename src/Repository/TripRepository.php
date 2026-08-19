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
            $this->baseSelect() . " WHERE t.visibility IN ('public', 'member_only') OR t.user_id = ?
             ORDER BY t.date_start DESC, t.id DESC"
        );
        $stmt->execute([$viewerId]);
        return $stmt->fetchAll();
    }

    /**
     * Only this user's own trips, private included - unlike findVisibleFor()
     * this never mixes in other people's public trips. Used by the "my
     * trips" page (nav username link).
     *
     * @return list<array<string, mixed>>
     */
    public function findByUser(int $userId): array
    {
        $stmt = $this->pdo->prepare(
            $this->baseSelect() . ' WHERE t.user_id = ? ORDER BY t.date_start DESC, t.id DESC'
        );
        $stmt->execute([$userId]);
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
            'INSERT INTO trips (user_id, title, slug, country, operator, description, tags,
                                date_start, date_end, visibility, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $userId,
            $data['title'],
            $data['slug'],
            $data['country'],
            $data['operator'],
            $data['description'],
            $data['tags'],
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
            'UPDATE trips SET title = ?, slug = ?, country = ?, operator = ?, description = ?, tags = ?,
                    date_start = ?, date_end = ?, visibility = ?, updated_at = ?
             WHERE id = ?'
        );
        $stmt->execute([
            $data['title'],
            $data['slug'],
            $data['country'],
            $data['operator'],
            $data['description'],
            $data['tags'],
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
     * TripSuggestMetaHandler's write path - stashes the AI-generated
     * title/tags suggestion for the metadata form to display, never writes
     * into the real title/tags columns directly (see migration 0029).
     */
    public function updateAiSuggestions(int $id, ?string $title, ?string $tags): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE trips SET ai_title_suggestion = ?, ai_tags_suggestion = ?, updated_at = ? WHERE id = ?'
        );
        $stmt->execute([$title, $tags, gmdate('Y-m-d H:i:s'), $id]);
    }

    /**
     * TripMetadataAutoFillHandler's write path: fills country/date_start/
     * date_end from track/photo data. Plain UPDATE, no COALESCE - unlike
     * country (a single value, fine to set once and leave alone), the
     * caller (TripMetadataAutoFillHandler) already works out the correct
     * date_start/date_end itself (widening the existing range rather than
     * only filling from NULL), so this just writes whatever it was told.
     * There's no UI path for a user to type these three fields in
     * themselves (checked - not present in the metadata edit form), so
     * nothing here is ever "resetting a manual edit".
     */
    public function updateAutoMetadata(int $id, ?string $country, ?string $dateStart, ?string $dateEnd): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE trips SET country = ?, date_start = ?, date_end = ?, updated_at = ? WHERE id = ?'
        );
        $stmt->execute([$country, $dateStart, $dateEnd, gmdate('Y-m-d H:i:s'), $id]);
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
