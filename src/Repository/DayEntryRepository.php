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
            'INSERT INTO day_entries (trip_id, entry_date, title, body, mood, rating, lat, lng, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
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
            'UPDATE day_entries SET entry_date = ?, title = ?, body = ?, mood = ?, rating = ?, lat = ?, lng = ?, updated_at = ?
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
            gmdate('Y-m-d H:i:s'),
            $id,
        ]);
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
}
