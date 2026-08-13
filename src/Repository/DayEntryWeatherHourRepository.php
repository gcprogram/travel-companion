<?php

declare(strict_types=1);

namespace App\Repository;

use PDO;

final class DayEntryWeatherHourRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * @return list<array<string, mixed>> ordered by hour ascending
     */
    public function findByEntry(int $entryId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM day_entry_weather_hours WHERE day_entry_id = ? ORDER BY hour ASC'
        );
        $stmt->execute([$entryId]);
        return $stmt->fetchAll();
    }

    /**
     * Atomic full replace, same reasoning as TrackRepository::replaceForTrip
     * - re-fetching is always a full day's worth of hours, never a partial
     * update, so there's nothing to merge.
     *
     * @param list<array{hour: int, lat: float, lng: float, tempC: ?float, feelsLikeC: ?float,
     *     precipitationProbability: ?int, weatherCode: ?int, windSpeedKmh: ?float, windDirectionDeg: ?int}> $hours
     */
    public function replaceForEntry(int $entryId, array $hours): void
    {
        $this->pdo->beginTransaction();
        try {
            $this->pdo->prepare('DELETE FROM day_entry_weather_hours WHERE day_entry_id = ?')->execute([$entryId]);

            $insert = $this->pdo->prepare(
                'INSERT INTO day_entry_weather_hours
                    (day_entry_id, hour, lat, lng, temp_c, feels_like_c, precipitation_probability, weather_code, wind_speed_kmh, wind_direction_deg)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            foreach ($hours as $h) {
                $insert->execute([
                    $entryId,
                    $h['hour'],
                    $h['lat'],
                    $h['lng'],
                    $h['tempC'],
                    $h['feelsLikeC'],
                    $h['precipitationProbability'],
                    $h['weatherCode'],
                    $h['windSpeedKmh'],
                    $h['windDirectionDeg'],
                ]);
            }

            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }
}
