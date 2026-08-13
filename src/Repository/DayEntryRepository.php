<?php

declare(strict_types=1);

namespace App\Repository;

use PDO;

final class DayEntryRepository
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
            'SELECT * FROM day_entries WHERE trip_id = ? ORDER BY entry_date ASC, id ASC'
        );
        $stmt->execute([$tripId]);
        return $stmt->fetchAll();
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findById(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM day_entries WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    /**
     * @param array<string, mixed> $data
     */
    public function create(int $tripId, array $data): int
    {
        $now = gmdate('Y-m-d H:i:s');
        $stmt = $this->pdo->prepare(
            'INSERT INTO day_entries (trip_id, entry_date, title, body, mood, rating, lat, lng, location_name, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $tripId,
            $data['entry_date'],
            $data['title'],
            $data['body'],
            $data['mood'],
            $data['rating'],
            $data['lat'],
            $data['lng'],
            $data['location_name'],
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
            'UPDATE day_entries SET entry_date = ?, title = ?, body = ?, mood = ?, rating = ?, lat = ?, lng = ?, location_name = ?, updated_at = ?
             WHERE id = ?'
        );
        $stmt->execute([
            $data['entry_date'],
            $data['title'],
            $data['body'],
            $data['mood'],
            $data['rating'],
            $data['lat'],
            $data['lng'],
            $data['location_name'],
            gmdate('Y-m-d H:i:s'),
            $id,
        ]);
    }

    /**
     * Auto-fill only: never overwrites a name the user already set (typed
     * manually, or filled by an earlier run of this same method).
     */
    public function updateLocationNameIfEmpty(int $id, string $name): void
    {
        $stmt = $this->pdo->prepare(
            "UPDATE day_entries SET location_name = ?, updated_at = ?
             WHERE id = ? AND (location_name IS NULL OR location_name = '')"
        );
        $stmt->execute([$name, gmdate('Y-m-d H:i:s'), $id]);
    }

    /**
     * Auto-fill only: never overwrites coordinates the user already set
     * (the "Standort erfassen" button), same convention as
     * updateLocationNameIfEmpty(). Lets EntryLocateHandler give an entry a
     * numeric location automatically from photos/track, not just a
     * display name - which in turn is what lets weather.fetch run without
     * anyone touching that button.
     */
    public function updateCoordinatesIfEmpty(int $id, float $lat, float $lng): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE day_entries SET lat = ?, lng = ?, updated_at = ?
             WHERE id = ? AND lat IS NULL AND lng IS NULL'
        );
        $stmt->execute([$lat, $lng, gmdate('Y-m-d H:i:s'), $id]);
    }

    public function updateWeather(int $id, float $tempC, int $code): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE day_entries SET weather_temp_c = ?, weather_code = ?, weather_fetched_at = ? WHERE id = ?'
        );
        $stmt->execute([$tempC, $code, gmdate('Y-m-d H:i:s'), $id]);
    }

    public function delete(int $id): void
    {
        $this->pdo->prepare('DELETE FROM day_entries WHERE id = ?')->execute([$id]);
    }

    public function countByUser(int $userId): int
    {
        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*) FROM day_entries e JOIN trips t ON t.id = e.trip_id WHERE t.user_id = ?'
        );
        $stmt->execute([$userId]);
        return (int) $stmt->fetchColumn();
    }
}
