<?php

declare(strict_types=1);

namespace App\Support;

/**
 * A single-slot, session-backed undo for the "Route editieren" trackpoint
 * editor (TrackEditService) - deliberately not a multi-step history: each
 * new delete/insert overwrites whatever was here, so Undo only ever reverts
 * the single most recent edit. A deeper stack would need to track seq
 * positions that go stale the moment a later edit shifts them, which this
 * sidesteps entirely by only ever holding one operation at a time.
 */
final class TrackEditUndoStack
{
    private const SESSION_KEY = '_track_edit_undo';

    /**
     * @param array<string, mixed> $operation
     */
    public function set(int $trackId, array $operation): void
    {
        $_SESSION[self::SESSION_KEY][$trackId] = $operation;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function consume(int $trackId): ?array
    {
        $operation = $_SESSION[self::SESSION_KEY][$trackId] ?? null;
        unset($_SESSION[self::SESSION_KEY][$trackId]);
        return $operation;
    }

    public function clear(int $trackId): void
    {
        unset($_SESSION[self::SESSION_KEY][$trackId]);
    }
}
