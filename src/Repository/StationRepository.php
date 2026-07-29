<?php

declare(strict_types=1);

namespace App\Repository;

use PDO;

final class StationRepository
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
            'SELECT * FROM trip_stations WHERE trip_id = ? ORDER BY position ASC, id ASC'
        );
        $stmt->execute([$tripId]);
        return $stmt->fetchAll();
    }

    /**
     * Ersetzt alle Stationen einer Reise atomar durch die übergebene Liste.
     * Einfach und robust für den Formular-Workflow; feingranulare Updates
     * lohnen sich erst, wenn Stationen eigene Kind-Daten bekommen.
     *
     * @param list<array{name: string, arrival_date: ?string, notes: ?string}> $stations
     */
    public function replaceForTrip(int $tripId, array $stations): void
    {
        $this->pdo->beginTransaction();
        try {
            $this->pdo->prepare('DELETE FROM trip_stations WHERE trip_id = ?')->execute([$tripId]);

            $stmt = $this->pdo->prepare(
                'INSERT INTO trip_stations (trip_id, position, name, arrival_date, notes)
                 VALUES (?, ?, ?, ?, ?)'
            );
            foreach (array_values($stations) as $i => $station) {
                $stmt->execute([
                    $tripId,
                    $i,
                    $station['name'],
                    $station['arrival_date'],
                    $station['notes'],
                ]);
            }
            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }
}
