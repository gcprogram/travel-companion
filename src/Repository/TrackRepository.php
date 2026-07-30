<?php

declare(strict_types=1);

namespace App\Repository;

use PDO;

final class TrackRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findByTrip(int $tripId): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM trip_tracks WHERE trip_id = ?');
        $stmt->execute([$tripId]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    public function countPoints(int $trackId): int
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM trip_track_points WHERE track_id = ?');
        $stmt->execute([$trackId]);
        return (int) $stmt->fetchColumn();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function findPoints(int $trackId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM trip_track_points WHERE track_id = ? ORDER BY seq ASC'
        );
        $stmt->execute([$trackId]);
        return $stmt->fetchAll();
    }

    /**
     * Atomically replaces the trip's track (if any) with a freshly parsed
     * one. One track per trip, so a re-upload is a full swap rather than a
     * merge — mirrors StationRepository::replaceForTrip.
     *
     * @param list<array{lat: float, lng: float, elevation: ?float, recordedAt: ?string, accuracy: ?float}> $points
     */
    public function replaceForTrip(int $tripId, string $source, ?string $originalFilename, array $points): int
    {
        $this->pdo->beginTransaction();
        try {
            $this->pdo->prepare('DELETE FROM trip_tracks WHERE trip_id = ?')->execute([$tripId]);

            $now = gmdate('Y-m-d H:i:s');
            $insertTrack = $this->pdo->prepare(
                'INSERT INTO trip_tracks (trip_id, source, original_filename, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?)'
            );
            $insertTrack->execute([$tripId, $source, $originalFilename, $now, $now]);
            $trackId = (int) $this->pdo->lastInsertId();

            $insertPoint = $this->pdo->prepare(
                'INSERT INTO trip_track_points (track_id, seq, lat, lng, elevation_m, recorded_at, accuracy_m)
                 VALUES (?, ?, ?, ?, ?, ?, ?)'
            );
            foreach (array_values($points) as $seq => $point) {
                $insertPoint->execute([
                    $trackId,
                    $seq,
                    $point['lat'],
                    $point['lng'],
                    $point['elevation'],
                    $point['recordedAt'],
                    $point['accuracy'],
                ]);
            }

            $this->pdo->commit();
            return $trackId;
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    public function updateTrim(int $trackId, ?int $trimStartSeq, ?int $trimEndSeq): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE trip_tracks SET trim_start_seq = ?, trim_end_seq = ?, updated_at = ? WHERE id = ?'
        );
        $stmt->execute([$trimStartSeq, $trimEndSeq, gmdate('Y-m-d H:i:s'), $trackId]);
    }

    public function deleteForTrip(int $tripId): void
    {
        $this->pdo->prepare('DELETE FROM trip_tracks WHERE trip_id = ?')->execute([$tripId]);
    }
}
