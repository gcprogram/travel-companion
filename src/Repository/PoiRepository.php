<?php

declare(strict_types=1);

namespace App\Repository;

use PDO;

final class PoiRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function findByTrip(int $tripId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM trip_pois WHERE trip_id = ? ORDER BY category ASC, name ASC'
        );
        $stmt->execute([$tripId]);
        return $stmt->fetchAll();
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findById(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM trip_pois WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    /**
     * Re-running discovery must not clobber a POI the user already
     * confirmed/annotated, so only the Overpass-sourced fields are updated
     * on conflict — visited/notes/visit_date are left untouched.
     */
    public function upsertFromOverpass(
        int $tripId,
        string $externalRef,
        string $category,
        string $name,
        float $lat,
        float $lng,
    ): void {
        $now = gmdate('Y-m-d H:i:s');
        $stmt = $this->pdo->prepare(
            "INSERT INTO trip_pois (trip_id, source, external_ref, category, name, lat, lng, visited, created_at, updated_at)
             VALUES (?, 'overpass', ?, ?, ?, ?, ?, 0, ?, ?)
             ON DUPLICATE KEY UPDATE category = VALUES(category), name = VALUES(name),
                 lat = VALUES(lat), lng = VALUES(lng), updated_at = VALUES(updated_at)"
        );
        $stmt->execute([$tripId, $externalRef, $category, $name, $lat, $lng, $now, $now]);
    }

    public function createManual(
        int $tripId,
        string $category,
        string $name,
        float $lat,
        float $lng,
        ?string $visitDate,
        ?string $notes,
    ): int {
        $now = gmdate('Y-m-d H:i:s');
        $stmt = $this->pdo->prepare(
            "INSERT INTO trip_pois (trip_id, source, external_ref, category, name, lat, lng, visit_date, notes, visited, created_at, updated_at)
             VALUES (?, 'manual', NULL, ?, ?, ?, ?, ?, ?, 0, ?, ?)"
        );
        $stmt->execute([$tripId, $category, $name, $lat, $lng, $visitDate, $notes, $now, $now]);
        return (int) $this->pdo->lastInsertId();
    }

    public function setVisited(int $id, bool $visited): void
    {
        $stmt = $this->pdo->prepare('UPDATE trip_pois SET visited = ?, updated_at = ? WHERE id = ?');
        $stmt->execute([$visited ? 1 : 0, gmdate('Y-m-d H:i:s'), $id]);
    }

    public function delete(int $id): void
    {
        $this->pdo->prepare('DELETE FROM trip_pois WHERE id = ?')->execute([$id]);
    }

    /**
     * Clears out discovered-but-never-visited POIs: everything in the trip
     * with no photo/video assigned to it (PoiAssignmentService does the
     * assigning, within poi.photo_match_meters).
     *
     * Deliberately keeps POIs the user has taken ownership of even without
     * media - marked visited, given notes/a visit date, or added by hand -
     * since those are explicit statements that the place matters, and
     * silently deleting them would lose real user input.
     *
     * @return int number of POIs removed
     */
    public function deleteUnphotographed(int $tripId): int
    {
        $stmt = $this->pdo->prepare(
            "DELETE FROM trip_pois
             WHERE trip_id = ?
               AND source = 'overpass'
               AND visited = 0
               AND visit_date IS NULL
               AND (notes IS NULL OR notes = '')
               AND id NOT IN (SELECT poi_id FROM trip_poi_photos)
               AND id NOT IN (SELECT poi_id FROM trip_poi_videos)"
        );
        $stmt->execute([$tripId]);
        return $stmt->rowCount();
    }
}
