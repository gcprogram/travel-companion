<?php

declare(strict_types=1);

namespace App\Service;

/**
 * Finds places the traveller actually stopped at, from the raw GPS track.
 *
 * Distinct from TrackSmoothingService's pause collapsing, which only
 * recognises a tight ~30m huddle: indoors (a museum, a restaurant) the
 * receiver loses sight of most satellites and the fix wanders far wider
 * than that, often 100m+, while the person hasn't moved at all. Detecting
 * those needs a wider radius, which on its own would also swallow genuine
 * slow movement (strolling through an old town). The discriminator is
 * displacement, not distance: during a real stay the track wanders back and
 * forth around one spot, so the straight-line distance from first to last
 * point stays small compared to the total path walked. Real movement, even
 * slow, keeps making net progress.
 *
 * A detected stay's averaged centre is a far better estimate of where the
 * person actually was than any single jittery fix, which is what makes it
 * usable as a POI/visit location (see PoiController::addStay).
 */
final class StayDetectionService
{
    private const MAX_RADIUS_METERS = 120.0;
    private const MIN_DURATION_SECONDS = 900; // 15 min
    /** Net displacement over path length; below this the track is wandering, not travelling. */
    private const MAX_DISPLACEMENT_RATIO = 0.35;
    /** Fewer points than this is too little evidence to call it a stay. */
    private const MIN_POINTS = 4;

    /**
     * @param list<array{seq: int, lat: float, lng: float, recordedAt: ?string}> $points
     *        raw track points, chronological
     * @return list<array{startSeq: int, endSeq: int, lat: float, lng: float,
     *         startedAt: string, endedAt: string, durationSeconds: int, pointCount: int}>
     */
    public function detect(array $points): array
    {
        $points = array_values(array_filter($points, static fn (array $p): bool => $p['recordedAt'] !== null));
        $count = count($points);
        if ($count < self::MIN_POINTS) {
            return [];
        }

        $stays = [];
        $i = 0;

        while ($i < $count) {
            // Grow the window as long as every point in it stays within
            // MAX_RADIUS of the window's own centre. Re-centring as it grows
            // (rather than measuring from the first point) matters because
            // jitter scatters around the building, so the first fix is often
            // itself an outlier on the edge of the cloud.
            $j = $i;
            while ($j + 1 < $count) {
                $centre = $this->centroid($points, $i, $j + 1);
                if ($this->maxDistanceFrom($points, $i, $j + 1, $centre) > self::MAX_RADIUS_METERS) {
                    break;
                }
                $j++;
            }

            $stay = $j > $i ? $this->validate($points, $i, $j) : null;
            if ($stay !== null) {
                $stays[] = $stay;
                $i = $j + 1;
                continue;
            }
            $i++;
        }

        return $stays;
    }

    /**
     * @param list<array{seq: int, lat: float, lng: float, recordedAt: ?string}> $points
     * @return array{startSeq: int, endSeq: int, lat: float, lng: float, startedAt: string,
     *         endedAt: string, durationSeconds: int, pointCount: int}|null
     */
    private function validate(array $points, int $start, int $end): ?array
    {
        $pointCount = $end - $start + 1;
        if ($pointCount < self::MIN_POINTS) {
            return null;
        }

        $startedAt = (string) $points[$start]['recordedAt'];
        $endedAt = (string) $points[$end]['recordedAt'];
        $duration = $this->secondsBetween($startedAt, $endedAt);
        if ($duration === null || $duration < self::MIN_DURATION_SECONDS) {
            return null;
        }

        $pathLength = 0.0;
        for ($k = $start; $k < $end; $k++) {
            $pathLength += $this->haversineMeters(
                $points[$k]['lat'],
                $points[$k]['lng'],
                $points[$k + 1]['lat'],
                $points[$k + 1]['lng'],
            );
        }
        if ($pathLength <= 0.0) {
            // Every fix identical - a stationary device, still a stay.
            $pathLength = 0.0;
        } else {
            $displacement = $this->haversineMeters(
                $points[$start]['lat'],
                $points[$start]['lng'],
                $points[$end]['lat'],
                $points[$end]['lng'],
            );
            if ($displacement / $pathLength > self::MAX_DISPLACEMENT_RATIO) {
                return null; // Made real progress - travelling, not staying.
            }
        }

        $centre = $this->centroid($points, $start, $end);

        return [
            'startSeq' => (int) $points[$start]['seq'],
            'endSeq' => (int) $points[$end]['seq'],
            'lat' => round($centre['lat'], 6),
            'lng' => round($centre['lng'], 6),
            'startedAt' => $startedAt,
            'endedAt' => $endedAt,
            'durationSeconds' => $duration,
            'pointCount' => $pointCount,
        ];
    }

    /**
     * @param list<array{lat: float, lng: float}> $points
     * @return array{lat: float, lng: float}
     */
    private function centroid(array $points, int $start, int $end): array
    {
        $latSum = 0.0;
        $lngSum = 0.0;
        for ($k = $start; $k <= $end; $k++) {
            $latSum += $points[$k]['lat'];
            $lngSum += $points[$k]['lng'];
        }
        $n = $end - $start + 1;
        return ['lat' => $latSum / $n, 'lng' => $lngSum / $n];
    }

    /**
     * @param list<array{lat: float, lng: float}> $points
     * @param array{lat: float, lng: float} $centre
     */
    private function maxDistanceFrom(array $points, int $start, int $end, array $centre): float
    {
        $max = 0.0;
        for ($k = $start; $k <= $end; $k++) {
            $d = $this->haversineMeters($points[$k]['lat'], $points[$k]['lng'], $centre['lat'], $centre['lng']);
            if ($d > $max) {
                $max = $d;
            }
        }
        return $max;
    }

    private function secondsBetween(string $a, string $b): ?int
    {
        try {
            $dtA = new \DateTimeImmutable($a);
            $dtB = new \DateTimeImmutable($b);
        } catch (\Exception) {
            return null;
        }
        return abs($dtB->getTimestamp() - $dtA->getTimestamp());
    }

    private function haversineMeters(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $earthRadius = 6371000.0;
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;
        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));
        return $earthRadius * $c;
    }
}
