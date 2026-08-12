<?php

declare(strict_types=1);

namespace App\Service;

use App\Repository\PhotoRepository;
use App\Repository\TrackRepository;

/**
 * Fills real gaps in a trip's GPS track using its own geotagged photos:
 * a phone with GPS off between shots, a GPX recorder that lost signal, or a
 * day with no track upload at all still has photos with EXIF position and
 * capture time, which is often the only location data for that stretch.
 *
 * Deliberately gap-filling only, not merging every geotagged photo into the
 * track regardless of coverage: a photo taken seconds from an existing
 * track point adds no information and would just clutter
 * TrackSmoothingService's pause detection with a near-duplicate point. A
 * photo is used only where the nearest existing track point in time is
 * further away than MIN_GAP_SECONDS - i.e. only where the track actually
 * has nothing to say for that moment.
 *
 * Runs automatically, never as a manual user action: TrackController calls
 * it right after every GPX/points upload, and TrackGapFillHandler
 * (dispatched wherever a geotagged photo finishes processing, mirroring
 * poi.assign) calls it for photos that arrive after a track already exists.
 * Idempotent either way - a photo whose gap is already filled just fails
 * isGap() on the next call. Callers rely on TrackRepository::appendForTrip
 * sorting the merge by recordedAt so these points end up interleaved with
 * the rest of the track, not as a disconnected block.
 */
final class PhotoTrackGapFillService
{
    private const MIN_GAP_SECONDS = 900; // 15 min - matches TrackSmoothingService's pause threshold

    public function __construct(
        private readonly PhotoRepository $photos,
        private readonly TrackRepository $tracks,
    ) {
    }

    /**
     * @return int number of points added
     */
    public function fillGaps(int $tripId): int
    {
        $photoPoints = $this->photos->findGeotaggedByTrip($tripId);
        if ($photoPoints === []) {
            return 0;
        }

        $existingTimes = $this->existingTrackTimes($tripId);
        $gapPoints = array_values(array_filter(
            $photoPoints,
            fn (array $p): bool => $this->isGap($existingTimes, $p['takenAt']),
        ));
        if ($gapPoints === []) {
            return 0;
        }

        $points = array_map(static fn (array $p): array => [
            'lat' => $p['lat'],
            'lng' => $p['lng'],
            'elevation' => null,
            'recordedAt' => $p['takenAt'],
            'accuracy' => null,
        ], $gapPoints);

        $this->tracks->appendForTrip($tripId, 'points', null, $points);
        return count($points);
    }

    /**
     * @return list<int> existing track points' recordedAt as unix timestamps
     */
    private function existingTrackTimes(int $tripId): array
    {
        $track = $this->tracks->findByTrip($tripId);
        if ($track === null) {
            return [];
        }

        $times = [];
        foreach ($this->tracks->findPoints((int) $track['id']) as $point) {
            if ($point['recorded_at'] === null) {
                continue;
            }
            try {
                $times[] = (new \DateTimeImmutable((string) $point['recorded_at']))->getTimestamp();
            } catch (\Exception) {
                continue;
            }
        }
        return $times;
    }

    /**
     * @param list<int> $existingTimes sorted or not - the full trip is small
     *        enough that a linear scan is fine, and this runs at most once
     *        per track upload or per photo finishing processing, not per
     *        page request.
     */
    private function isGap(array $existingTimes, string $takenAt): bool
    {
        if ($existingTimes === []) {
            return true; // No track at all yet - every photo point is new information.
        }

        try {
            $target = (new \DateTimeImmutable($takenAt))->getTimestamp();
        } catch (\Exception) {
            return false;
        }

        foreach ($existingTimes as $t) {
            if (abs($t - $target) < self::MIN_GAP_SECONDS) {
                return false;
            }
        }
        return true;
    }
}
