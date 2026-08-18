<?php

declare(strict_types=1);

namespace App\Service;

use App\Repository\TrackRepository;
use App\Support\TrackEditUndoStack;

/**
 * Business logic for the "Route editieren" trackpoint editor
 * (TrackEditController): delete/insert a single point, undo the most recent
 * one, reset back to how the track looked before this editing session's
 * first edit. TrackRepository stays a thin data-access layer (raw seq
 * arithmetic, snapshot copy/restore) - this is where "what counts as one
 * edit", snapshot-on-first-touch, and the undo bookkeeping live.
 */
final class TrackEditService
{
    public function __construct(
        private readonly TrackRepository $tracks,
        private readonly TrackEditUndoStack $undo,
    ) {
    }

    /**
     * @return array{ok: bool}
     */
    public function deletePoint(int $tripId, int $pointId): array
    {
        $trackId = $this->trackIdForTrip($tripId);
        if ($trackId === null) {
            return ['ok' => false];
        }

        $this->snapshotIfFirstEdit($trackId);

        $removed = $this->tracks->deletePoint($trackId, $pointId);
        if ($removed === null) {
            return ['ok' => false];
        }

        $this->undo->set($trackId, ['type' => 'delete', 'point' => $removed]);
        return ['ok' => true];
    }

    /**
     * Inserts a point between two existing, adjacent points - which one the
     * caller calls "after"/"before" doesn't matter, only that they're
     * neighbours (consecutive seq); a user clicking two map markers has no
     * reason to click them in track order. Time/elevation are interpolated
     * from the two neighbours so the new point fits believably into the
     * track's own timeline rather than carrying no time at all.
     *
     * @return array{ok: bool, error?: string}
     */
    public function insertPoint(int $tripId, int $pointIdA, int $pointIdB, float $lat, float $lng): array
    {
        $trackId = $this->trackIdForTrip($tripId);
        if ($trackId === null) {
            return ['ok' => false, 'error' => 'no_track'];
        }

        $a = $this->tracks->findPointById($pointIdA);
        $b = $this->tracks->findPointById($pointIdB);
        if ($a === null || $b === null || (int) $a['track_id'] !== $trackId || (int) $b['track_id'] !== $trackId) {
            return ['ok' => false, 'error' => 'not_found'];
        }

        $seqA = (int) $a['seq'];
        $seqB = (int) $b['seq'];
        if (abs($seqA - $seqB) !== 1) {
            return ['ok' => false, 'error' => 'not_adjacent'];
        }

        $earlier = $seqA < $seqB ? $a : $b;
        $later = $seqA < $seqB ? $b : $a;

        $this->snapshotIfFirstEdit($trackId);

        $recordedAt = $this->interpolateTime($earlier['recorded_at'], $later['recorded_at']);
        $elevation = $this->interpolateElevation($earlier['elevation_m'], $later['elevation_m']);

        $newId = $this->tracks->insertPointAt($trackId, (int) $earlier['seq'], $lat, $lng, $elevation, $recordedAt);

        $this->undo->set($trackId, ['type' => 'insert', 'pointId' => $newId]);
        return ['ok' => true];
    }

    /**
     * Reverts the single most recent delete/insert - no-op (returns false)
     * if there's nothing to undo, e.g. after a Reset already consumed it or
     * before any edit was made yet.
     */
    public function undo(int $tripId): bool
    {
        $trackId = $this->trackIdForTrip($tripId);
        if ($trackId === null) {
            return false;
        }

        $operation = $this->undo->consume($trackId);
        if ($operation === null) {
            return false;
        }

        if ($operation['type'] === 'delete') {
            $point = $operation['point'];
            $this->tracks->insertPointAt(
                $trackId,
                (int) $point['seq'] - 1,
                (float) $point['lat'],
                (float) $point['lng'],
                $point['elevation_m'] !== null ? (float) $point['elevation_m'] : null,
                $point['recorded_at'],
                $point['accuracy_m'] !== null ? (float) $point['accuracy_m'] : null,
            );
            return true;
        }

        if ($operation['type'] === 'insert') {
            $this->tracks->deletePoint($trackId, (int) $operation['pointId']);
            return true;
        }

        return false;
    }

    /**
     * Restores the track to how it looked before this editing session's
     * first delete/insert - a no-op (returns false) if no edit has
     * happened yet this session (no snapshot to restore from).
     */
    public function reset(int $tripId): bool
    {
        $trackId = $this->trackIdForTrip($tripId);
        if ($trackId === null) {
            return false;
        }

        $restored = $this->tracks->restoreEditSnapshot($trackId);
        if ($restored) {
            $this->undo->clear($trackId);
        }
        return $restored;
    }

    private function trackIdForTrip(int $tripId): ?int
    {
        $track = $this->tracks->findByTrip($tripId);
        return $track !== null ? (int) $track['id'] : null;
    }

    private function snapshotIfFirstEdit(int $trackId): void
    {
        if (!$this->tracks->hasEditSnapshot($trackId)) {
            $this->tracks->createEditSnapshot($trackId);
        }
    }

    private function interpolateTime(?string $a, ?string $b): ?string
    {
        if ($a === null || $b === null) {
            return $a ?? $b;
        }
        try {
            $ta = new \DateTimeImmutable($a, new \DateTimeZone('UTC'));
            $tb = new \DateTimeImmutable($b, new \DateTimeZone('UTC'));
        } catch (\Exception) {
            return $a;
        }
        $midTimestamp = intdiv($ta->getTimestamp() + $tb->getTimestamp(), 2);
        return (new \DateTimeImmutable('@' . $midTimestamp))->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }

    private function interpolateElevation(mixed $a, mixed $b): ?float
    {
        if ($a === null && $b === null) {
            return null;
        }
        if ($a === null || $b === null) {
            return (float) ($a ?? $b);
        }
        return ((float) $a + (float) $b) / 2;
    }
}
