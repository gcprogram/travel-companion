<?php

declare(strict_types=1);

namespace App\Service;

/**
 * Decimates a track down to a render/storage-friendly point count via
 * Douglas-Peucker, so a multi-day GPX export (tens of thousands of points)
 * doesn't blow the shared host's 30s max_execution_time on upload, or force
 * the browser to render thousands of markers. Runs in degree-space, not
 * meters — fine for point-reduction purposes, doesn't need geodesic
 * precision.
 */
final class TrackSimplifier
{
    private const MAX_POINTS = 1500;

    /**
     * Douglas-Peucker is worst-case O(n^2), and the tolerance-escalation loop
     * below re-runs it from scratch on every retry — for a multi-week GPX
     * export (hundreds of thousands of points) that's too slow for a 30s
     * request. A cheap uniform stride pre-pass bounds the input to DP
     * regardless of how large the upload is, so worst-case cost stays
     * predictable; DP then does the actual shape-preserving work on top.
     */
    private const PRE_DECIMATE_TARGET = 20000;

    /**
     * @param list<array<string, mixed>> $points
     * @return list<array<string, mixed>>
     */
    public function simplify(array $points): array
    {
        if (count($points) <= self::MAX_POINTS) {
            return $points;
        }

        $points = $this->strideDecimate($points, self::PRE_DECIMATE_TARGET);

        $tolerance = 0.00001;
        $result = $points;
        for ($i = 0; $i < 20; $i++) {
            $result = $this->douglasPeucker($points, $tolerance);
            if (count($result) <= self::MAX_POINTS) {
                break;
            }
            $tolerance *= 1.6;
        }

        return $result;
    }

    /**
     * Keeps every Nth point (always including the last one) so the DP pass
     * downstream never has to look at more than ~$target points.
     *
     * @param list<array<string, mixed>> $points
     * @return list<array<string, mixed>>
     */
    private function strideDecimate(array $points, int $target): array
    {
        $count = count($points);
        if ($count <= $target) {
            return $points;
        }

        $stride = (int) ceil($count / $target);
        $result = [];
        for ($i = 0; $i < $count; $i += $stride) {
            $result[] = $points[$i];
        }
        $last = $points[$count - 1];
        if (end($result) !== $last) {
            $result[] = $last;
        }
        return $result;
    }

    /**
     * @param list<array<string, mixed>> $points
     * @return list<array<string, mixed>>
     */
    private function douglasPeucker(array $points, float $tolerance): array
    {
        $count = count($points);
        if ($count < 3) {
            return $points;
        }

        $keep = array_fill(0, $count, false);
        $keep[0] = true;
        $keep[$count - 1] = true;
        $this->simplifySegment($points, 0, $count - 1, $tolerance, $keep);

        $result = [];
        foreach ($keep as $i => $shouldKeep) {
            if ($shouldKeep) {
                $result[] = $points[$i];
            }
        }
        return $result;
    }

    /**
     * @param list<array<string, mixed>> $points
     * @param array<int, bool> $keep
     */
    private function simplifySegment(array $points, int $start, int $end, float $tolerance, array &$keep): void
    {
        if ($end <= $start + 1) {
            return;
        }

        $maxDist = 0.0;
        $maxIndex = $start;
        for ($i = $start + 1; $i < $end; $i++) {
            $dist = $this->perpendicularDistance($points[$i], $points[$start], $points[$end]);
            if ($dist > $maxDist) {
                $maxDist = $dist;
                $maxIndex = $i;
            }
        }

        if ($maxDist > $tolerance) {
            $keep[$maxIndex] = true;
            $this->simplifySegment($points, $start, $maxIndex, $tolerance, $keep);
            $this->simplifySegment($points, $maxIndex, $end, $tolerance, $keep);
        }
    }

    /**
     * @param array<string, mixed> $point
     * @param array<string, mixed> $start
     * @param array<string, mixed> $end
     */
    private function perpendicularDistance(array $point, array $start, array $end): float
    {
        $x = $point['lng'];
        $y = $point['lat'];
        $x1 = $start['lng'];
        $y1 = $start['lat'];
        $x2 = $end['lng'];
        $y2 = $end['lat'];

        $dx = $x2 - $x1;
        $dy = $y2 - $y1;

        if ($dx === 0.0 && $dy === 0.0) {
            return sqrt(($x - $x1) ** 2 + ($y - $y1) ** 2);
        }

        $t = (($x - $x1) * $dx + ($y - $y1) * $dy) / ($dx ** 2 + $dy ** 2);
        $t = max(0.0, min(1.0, $t));
        $projX = $x1 + $t * $dx;
        $projY = $y1 + $t * $dy;

        return sqrt(($x - $projX) ** 2 + ($y - $projY) ** 2);
    }
}
