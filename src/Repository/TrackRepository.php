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
     * A coarse, trim-respecting point list for the small non-interactive
     * map preview on the trip list (home/my-trips) - deliberately NOT the
     * full (already-decimated-for-the-real-map) point list findPoints()
     * returns, since that's still hundreds of points and this renders once
     * per trip card on a page that can list many trips at once. Evenly
     * strided down to $maxPoints, always keeping the last point so the
     * preview doesn't visually stop short of the track's real end.
     *
     * @return list<array{lat: float, lng: float}>
     */
    public function previewPoints(int $tripId, int $maxPoints = 40): array
    {
        $track = $this->findByTrip($tripId);
        if ($track === null) {
            return [];
        }

        $points = $this->findPoints((int) $track['id']);
        if ($points === []) {
            return [];
        }

        $trimStart = $track['trim_start_seq'] !== null ? (int) $track['trim_start_seq'] : 0;
        $trimEnd = $track['trim_end_seq'] !== null ? (int) $track['trim_end_seq'] : max(0, count($points) - 1);

        $visible = array_values(array_filter(
            $points,
            static fn (array $p): bool => (int) $p['seq'] >= $trimStart && (int) $p['seq'] <= $trimEnd,
        ));
        if ($visible === []) {
            return [];
        }

        $stride = max(1, (int) ceil(count($visible) / $maxPoints));
        $sampled = [];
        foreach ($visible as $i => $p) {
            if ($i % $stride === 0) {
                $sampled[] = ['lat' => (float) $p['lat'], 'lng' => (float) $p['lng']];
            }
        }

        $last = end($visible);
        $lastPoint = ['lat' => (float) $last['lat'], 'lng' => (float) $last['lng']];
        if ($sampled[count($sampled) - 1] !== $lastPoint) {
            $sampled[] = $lastPoint;
        }

        return $sampled;
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

            // A fresh upload invalidates any in-progress Route-editieren
            // session for this track - its snapshot describes a point set
            // that no longer exists (see TrackEditService::deletePoint/
            // insertPoint, which create it lazily on first edit).
            // replaceForTrip() doesn't need the same call: it deletes the
            // whole trip_tracks row, cascading the snapshot away with it.
            $this->clearEditSnapshot($trackId);

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

    /**
     * "Move" mode (TrackEditService::movePoint): relocates a point without
     * touching its seq/time - unlike delete+insert, this keeps it the "same"
     * point (same id), just fixing a GPS outlier's position.
     */
    public function updatePointPosition(int $pointId, float $lat, float $lng): void
    {
        $this->pdo->prepare('UPDATE trip_track_points SET lat = ?, lng = ? WHERE id = ?')
            ->execute([$lat, $lng, $pointId]);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findPointById(int $pointId): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM trip_track_points WHERE id = ?');
        $stmt->execute([$pointId]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    /**
     * Removes one point and closes the seq gap it leaves - every later
     * point's seq decrements by one, keeping the sequence contiguous
     * (findPoints()'s ORDER BY seq and every other seq-based consumer
     * assume 0..n-1 with no holes). Trim range resets, same convention
     * appendForTrip() already uses whenever the point set's shape changes -
     * old trim indices wouldn't line up with the shifted points anyway.
     *
     * @return array<string, mixed>|null the removed row (for the caller's
     *         undo stack), or null if it didn't belong to this track
     */
    public function deletePoint(int $trackId, int $pointId): ?array
    {
        $point = $this->findPointById($pointId);
        if ($point === null || (int) $point['track_id'] !== $trackId) {
            return null;
        }

        $this->pdo->beginTransaction();
        try {
            $this->pdo->prepare('DELETE FROM trip_track_points WHERE id = ?')->execute([$pointId]);
            $this->pdo->prepare(
                'UPDATE trip_track_points SET seq = seq - 1 WHERE track_id = ? AND seq > ?'
            )->execute([$trackId, (int) $point['seq']]);
            $this->resetTrim($trackId);
            $this->pdo->commit();
            return $point;
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    /**
     * Inserts a point right after an existing seq, shifting every later
     * point's seq up by one to make room - the general primitive both a
     * real "insert between two neighbours" (TrackEditService::insertPoint,
     * $afterSeq = the earlier neighbour's own seq) and an undo-of-delete
     * (TrackEditService::undo, $afterSeq = the deleted point's former
     * seq - 1, putting it back exactly where it was) are built from.
     *
     * @return int the new point's id
     */
    public function insertPointAt(
        int $trackId,
        int $afterSeq,
        float $lat,
        float $lng,
        ?float $elevation,
        ?string $recordedAt,
        ?float $accuracy = null,
    ): int {
        $this->pdo->beginTransaction();
        try {
            $this->pdo->prepare(
                'UPDATE trip_track_points SET seq = seq + 1 WHERE track_id = ? AND seq > ?'
            )->execute([$trackId, $afterSeq]);

            $stmt = $this->pdo->prepare(
                'INSERT INTO trip_track_points (track_id, seq, lat, lng, elevation_m, recorded_at, accuracy_m)
                 VALUES (?, ?, ?, ?, ?, ?, ?)'
            );
            $stmt->execute([$trackId, $afterSeq + 1, $lat, $lng, $elevation, $recordedAt, $accuracy]);
            $pointId = (int) $this->pdo->lastInsertId();

            $this->resetTrim($trackId);
            $this->pdo->commit();
            return $pointId;
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    public function hasEditSnapshot(int $trackId): bool
    {
        $stmt = $this->pdo->prepare('SELECT 1 FROM trip_track_edit_snapshots WHERE track_id = ?');
        $stmt->execute([$trackId]);
        return $stmt->fetchColumn() !== false;
    }

    /**
     * Copies the track's current point set aside, so Reset has something to
     * restore to - called once, lazily, on the first edit of a Route-
     * editieren session (TrackEditService), never again until the snapshot
     * is consumed by restoreEditSnapshot() or invalidated by a fresh upload
     * (clearEditSnapshot(), see appendForTrip()/replaceForTrip()).
     */
    public function createEditSnapshot(int $trackId): void
    {
        $this->pdo->beginTransaction();
        try {
            $this->pdo->prepare('DELETE FROM trip_track_edit_snapshots WHERE track_id = ?')->execute([$trackId]);
            $this->pdo->prepare('INSERT INTO trip_track_edit_snapshots (track_id, created_at) VALUES (?, ?)')
                ->execute([$trackId, gmdate('Y-m-d H:i:s')]);
            $snapshotId = (int) $this->pdo->lastInsertId();

            $insertPoint = $this->pdo->prepare(
                'INSERT INTO trip_track_edit_snapshot_points (snapshot_id, seq, lat, lng, elevation_m, recorded_at, accuracy_m)
                 VALUES (?, ?, ?, ?, ?, ?, ?)'
            );
            foreach ($this->findPoints($trackId) as $point) {
                $insertPoint->execute([
                    $snapshotId, $point['seq'], $point['lat'], $point['lng'],
                    $point['elevation_m'], $point['recorded_at'], $point['accuracy_m'],
                ]);
            }
            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    /**
     * Reset: replaces the track's current points with whatever
     * createEditSnapshot() captured before the session's first edit, then
     * consumes the snapshot - a further edit after this starts a new
     * editing session (its own fresh snapshot on its first change), rather
     * than every future Reset always jumping back to the same original
     * point regardless of how many edits happened since.
     */
    public function restoreEditSnapshot(int $trackId): bool
    {
        $stmt = $this->pdo->prepare('SELECT id FROM trip_track_edit_snapshots WHERE track_id = ?');
        $stmt->execute([$trackId]);
        $snapshotId = $stmt->fetchColumn();
        if ($snapshotId === false) {
            return false;
        }
        $snapshotId = (int) $snapshotId;

        $stmt2 = $this->pdo->prepare(
            'SELECT * FROM trip_track_edit_snapshot_points WHERE snapshot_id = ? ORDER BY seq'
        );
        $stmt2->execute([$snapshotId]);
        $points = $stmt2->fetchAll();

        $this->pdo->beginTransaction();
        try {
            $this->pdo->prepare('DELETE FROM trip_track_points WHERE track_id = ?')->execute([$trackId]);
            $insertPoint = $this->pdo->prepare(
                'INSERT INTO trip_track_points (track_id, seq, lat, lng, elevation_m, recorded_at, accuracy_m)
                 VALUES (?, ?, ?, ?, ?, ?, ?)'
            );
            foreach ($points as $point) {
                $insertPoint->execute([
                    $trackId, $point['seq'], $point['lat'], $point['lng'],
                    $point['elevation_m'], $point['recorded_at'], $point['accuracy_m'],
                ]);
            }
            $this->pdo->prepare('DELETE FROM trip_track_edit_snapshots WHERE id = ?')->execute([$snapshotId]);
            $this->resetTrim($trackId);
            $this->pdo->commit();
            return true;
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    public function clearEditSnapshot(int $trackId): void
    {
        $this->pdo->prepare('DELETE FROM trip_track_edit_snapshots WHERE track_id = ?')->execute([$trackId]);
    }

    private function resetTrim(int $trackId): void
    {
        $this->pdo->prepare(
            'UPDATE trip_tracks SET trim_start_seq = NULL, trim_end_seq = NULL, updated_at = ? WHERE id = ?'
        )->execute([gmdate('Y-m-d H:i:s'), $trackId]);
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
