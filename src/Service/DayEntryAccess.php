<?php

declare(strict_types=1);

namespace App\Service;

use App\Repository\DayEntryRepository;
use App\Repository\TripRepository;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Exception\HttpForbiddenException;
use Slim\Exception\HttpNotFoundException;

/**
 * Shared "does this request's user own this trip / entry" guard, used by
 * DayEntryController and the photo controllers so they don't each
 * reimplement the same lookup-and-check.
 */
final class DayEntryAccess
{
    public function __construct(
        private readonly DayEntryRepository $entries,
        private readonly TripRepository $trips,
        private readonly TripAccess $access,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function requireEditableTrip(ServerRequestInterface $request, int $tripId): array
    {
        $trip = $this->trips->findById($tripId);
        if ($trip === null) {
            throw new HttpNotFoundException($request);
        }
        if (!$this->access->canEdit($trip, $request->getAttribute('user'))) {
            throw new HttpForbiddenException($request);
        }
        return $trip;
    }

    /**
     * @return array{0: array<string, mixed>, 1: array<string, mixed>} [trip, entry]
     */
    public function requireEditableEntry(ServerRequestInterface $request, int $entryId): array
    {
        $entry = $this->entries->findById($entryId);
        if ($entry === null) {
            throw new HttpNotFoundException($request);
        }
        $trip = $this->requireEditableTrip($request, (int) $entry['trip_id']);
        return [$trip, $entry];
    }
}
