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

    /**
     * Merges freshly parsed points into the trip's existing track instead of
     * replacing it, so repeated uploads (e.g. one GPX per day, or a photo
     * position squeezed in later - see PhotoTrackGapFillService) accumulate
     * into a single continuous, chronologically-connected track rather than
     * each one wiping the last or leaving a straight "teleport" line between
     * unrelated points.
     *
     * Every point that has a recordedAt is sorted into place by time, so a
     * sparse set (e.g. photo-derived points) properly interleaves with a
     * denser one (e.g. a GPX track) instead of forming its own disconnected
     * segment. Points with no recordedAt at all can't be placed
     * chronologically - those keep their relative order and are appended
     * after the timed ones, same as a plain, timestamp-less GPX track was
     * appended before this method understood partial timestamps. This used
     * to be all-or-nothing (any single untimed point skipped sorting for the
     * *entire* merged set), which is what produced those teleport lines
     * whenever a GPX export was missing <time> on part of the file.
     *
     * Trim range resets to the full track, since old trim indices no longer
     * line up once points are re-sequenced.
     *
     * @param list<array{lat: float, lng: float, elevation: ?float, recordedAt: ?string, accuracy: ?float}> $newPoints
     */
    public function appendForTrip(int $tripId, string $source, ?string $originalFilename, array $newPoints): int
    {
        $existing = $this->findByTrip($tripId);
        if ($existing === null) {
            return $this->replaceForTrip($tripId, $source, $originalFilename, $newPoints);
        }

        $trackId = (int) $existing['id'];
        $existingPoints = array_map(static fn (array $p): array => [
            'lat' => (float) $p['lat'],
            'lng' => (float) $p['lng'],
            'elevation' => $p['elevation_m'] !== null ? (float) $p['elevation_m'] : null,
            'recordedAt' => $p['recorded_at'],
            'accuracy' => $p['accuracy_m'] !== null ? (float) $p['accuracy_m'] : null,
        ], $this->findPoints($trackId));

        $merged = array_merge($existingPoints, $newPoints);
        $timed = array_values(array_filter($merged, static fn (array $p): bool => $p['recordedAt'] !== null));
        $untimed = array_values(array_filter($merged, static fn (array $p): bool => $p['recordedAt'] === null));
        usort($timed, static fn (array $a, array $b): int => $a['recordedAt'] <=> $b['recordedAt']);
        $merged = array_merge($timed, $untimed);

        $this->pdo->beginTransaction();
        try {
            $this->pdo->prepare('DELETE FROM trip_track_points WHERE track_id = ?')->execute([$trackId]);

            $insertPoint = $this->pdo->prepare(
                'INSERT INTO trip_track_points (track_id, seq, lat, lng, elevation_m, recorded_at, accuracy_m)
                 VALUES (?, ?, ?, ?, ?, ?, ?)'
            );
            foreach (array_values($merged) as $seq => $point) {
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

            $this->pdo->prepare(
                'UPDATE trip_tracks SET trim_start_seq = NULL, trim_end_seq = NULL, updated_at = ? WHERE id = ?'
            )->execute([gmdate('Y-m-d H:i:s'), $trackId]);

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

    public function countByUser(int $userId): int
    {
        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*) FROM trip_tracks tt JOIN trips t ON t.id = tt.trip_id WHERE t.user_id = ?'
        );
        $stmt->execute([$userId]);
        return (int) $stmt->fetchColumn();
    }
}
