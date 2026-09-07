<?php

declare(strict_types=1);

namespace App\Service;

/**
 * Read-time-only track cleanup, applied fresh on every /map/data request
 * (never persisted) so tuning the thresholds below never requires a
 * re-upload - and, just as importantly, so the ORIGINAL rows in
 * trip_track_points are never touched by any of this (Stefan's explicit
 * ask: never delete/alter the recorded data, only ever change how it's
 * displayed). The route-editieren page deliberately reads the raw,
 * unfiltered points instead (TrackEditController), since editing needs to
 * see and fix the real data, not a cleaned-up view of it.
 *
 * 1. Outlier rejection (Stefan's report: GPS drift inside a building, e.g.
 *    an airport terminal, produced physically-impossible jumps - a point
 *    seemingly 900+ km/h away from the last one). See filterOutliers().
 * 2. Pause detection: a run of consecutive points that all stay within
 *    ~30m of the first point in the run, spanning >10 minutes, collapses
 *    into a single averaged point (a real GPS dwelling around one spot,
 *    e.g. lunch or a museum visit). Shorter stops (a red light) are left
 *    untouched — only sustained dwelling counts as a "pause".
 * 3. Accuracy smoothing (best-effort — most consumer GPX/photo-derived
 *    tracks never carry accuracy at all, so this quietly no-ops then): a
 *    point whose accuracy is meaningfully worse than both neighbors gets
 *    pulled toward them, weighted by each point's precision.
 */
final class TrackSmoothingService
{
    private const PAUSE_RADIUS_METERS = 30.0;
    private const PAUSE_MIN_SECONDS = 600;
    private const ACCURACY_WORSE_FACTOR = 2.0;
    // Comfortably above any real ground transport (motorway traffic, a
    // fast train) so a legitimate segment is never flagged - only meant to
    // catch GPS fixes that are simply impossible, not merely "fast".
    private const MAX_PLAUSIBLE_SPEED_KMH = 300.0;
    // How many points ahead to check for a "the jump recovers, so the
    // points in between were noise" pattern - catches a short BURST of bad
    // fixes (not just a single stray one), while still leaving a genuine
    // large, sustained hop (a real flight) alone: a flight has nothing to
    // "recover back" to, so the lookahead never finds a plausible point
    // relative to the last good one and the jump is accepted as real.
    private const OUTLIER_LOOKAHEAD_POINTS = 5;

    /**
     * Each output point carries the seq range of the raw points it came
     * from (seq..seqEnd, equal for an uncollapsed point). Trimming from the
     * map works on those raw seq values, so a collapsed pause still maps
     * back to a real cut position - see TrackController::trim. A point
     * dropped entirely by filterOutliers() has no output row at all - its
     * seq is simply skipped, which trimming already tolerates (it clips a
     * range of seq values, it doesn't require every seq in between to
     * exist as its own row).
     *
     * @param list<array{seq: int, lat: float, lng: float, elevation: ?float, recordedAt: ?string, accuracy: ?float}> $points
     * @return list<array{seq: int, seqEnd: int, lat: float, lng: float, elevation: ?float, recordedAt: ?string, recordedUntil: ?string, isPause: bool}>
     */
    public function smooth(array $points): array
    {
        $points = array_values($points);
        $points = $this->filterOutliers($points);
        $points = $this->smoothAccuracy($points);
        return $this->collapsePauses($points);
    }

    /**
     * Greedy pass: walks forward from the last ACCEPTED point. A candidate
     * implying an impossible speed from there is provisionally suspect;
     * if looking a few points further ahead finds one that's plausible
     * again relative to the last accepted point, everything strictly
     * between is dropped as a burst of bad fixes. If nothing in the
     * lookahead window recovers, the candidate is kept as-is (a real,
     * merely large, jump - e.g. a flight).
     *
     * @param list<array<string, mixed>> $points
     * @return list<array<string, mixed>>
     */
    private function filterOutliers(array $points): array
    {
        $count = count($points);
        if ($count < 3) {
            return $points;
        }

        $result = [$points[0]];
        $lastIndex = 0;
        $i = 1;
        while ($i < $count) {
            $last = $points[$lastIndex];
            $speedKmh = $this->speedKmh($last, $points[$i]);
            if ($speedKmh === null || $speedKmh <= self::MAX_PLAUSIBLE_SPEED_KMH) {
                $result[] = $points[$i];
                $lastIndex = $i;
                $i++;
                continue;
            }

            $recoveredAt = null;
            $maxJ = min($count - 1, $i + self::OUTLIER_LOOKAHEAD_POINTS);
            for ($j = $i + 1; $j <= $maxJ; $j++) {
                $skipSpeedKmh = $this->speedKmh($last, $points[$j]);
                if ($skipSpeedKmh !== null && $skipSpeedKmh <= self::MAX_PLAUSIBLE_SPEED_KMH) {
                    $recoveredAt = $j;
                    break;
                }
            }

            if ($recoveredAt !== null) {
                // Everything from $i up to (excluding) $recoveredAt was a
                // spike relative to $last - drop it, resume from the point
                // that actually makes sense again.
                $i = $recoveredAt;
                continue;
            }

            $result[] = $points[$i];
            $lastIndex = $i;
            $i++;
        }
        return $result;
    }

    /**
     * @param array<string, mixed> $a
     * @param array<string, mixed> $b
     */
    private function speedKmh(array $a, array $b): ?float
    {
        $seconds = $this->secondsBetween($a['recordedAt'], $b['recordedAt']);
        if ($seconds === null || $seconds <= 0) {
            return null; // Can't judge without a real time gap - never reject on this alone.
        }
        $meters = $this->haversineMeters($a['lat'], $a['lng'], $b['lat'], $b['lng']);
        return ($meters / $seconds) * 3.6;
    }

    /**
     * @param list<array<string, mixed>> $points
     * @return list<array<string, mixed>>
     */
    private function smoothAccuracy(array $points): array
    {
        $count = count($points);
        for ($i = 1; $i < $count - 1; $i++) {
            $acc = $points[$i]['accuracy'];
            $prevAcc = $points[$i - 1]['accuracy'];
            $nextAcc = $points[$i + 1]['accuracy'];
            if ($acc === null || $prevAcc === null || $nextAcc === null) {
                continue;
            }

            $isWorse = $acc > $prevAcc * self::ACCURACY_WORSE_FACTOR && $acc > $nextAcc * self::ACCURACY_WORSE_FACTOR;
            if (!$isWorse) {
                continue;
            }

            $weightPrev = 1 / max($prevAcc, 0.1);
            $weightSelf = 1 / max($acc, 0.1);
            $weightNext = 1 / max($nextAcc, 0.1);
            $totalWeight = $weightPrev + $weightSelf + $weightNext;

            $points[$i]['lat'] = (
                $points[$i - 1]['lat'] * $weightPrev
                + $points[$i]['lat'] * $weightSelf
                + $points[$i + 1]['lat'] * $weightNext
            ) / $totalWeight;
            $points[$i]['lng'] = (
                $points[$i - 1]['lng'] * $weightPrev
                + $points[$i]['lng'] * $weightSelf
                + $points[$i + 1]['lng'] * $weightNext
            ) / $totalWeight;
        }
        return $points;
    }

    /**
     * @param list<array<string, mixed>> $points
     * @return list<array<string, mixed>>
     */
    private function collapsePauses(array $points): array
    {
        $count = count($points);
        if ($count === 0) {
            return [];
        }

        $result = [];
        $clusterStart = 0;

        for ($i = 1; $i <= $count; $i++) {
            $withinRadius = $i < $count && $this->haversineMeters(
                $points[$clusterStart]['lat'],
                $points[$clusterStart]['lng'],
                $points[$i]['lat'],
                $points[$i]['lng'],
            ) <= self::PAUSE_RADIUS_METERS;

            if ($withinRadius) {
                continue;
            }

            $result = array_merge($result, $this->finalizeCluster($points, $clusterStart, $i - 1));
            $clusterStart = $i;
        }

        return $result;
    }

    /**
     * @param list<array<string, mixed>> $points
     * @return list<array<string, mixed>>
     */
    private function finalizeCluster(array $points, int $start, int $end): array
    {
        if ($end === $start) {
            return [$this->withPauseFlag($points[$start], false, null, $points[$start]['seq'])];
        }

        $first = $points[$start];
        $last = $points[$end];
        $durationSeconds = $this->secondsBetween($first['recordedAt'], $last['recordedAt']);

        if ($durationSeconds !== null && $durationSeconds >= self::PAUSE_MIN_SECONDS) {
            $n = $end - $start + 1;
            $latSum = 0.0;
            $lngSum = 0.0;
            for ($j = $start; $j <= $end; $j++) {
                $latSum += $points[$j]['lat'];
                $lngSum += $points[$j]['lng'];
            }
            $merged = [
                'seq' => $first['seq'],
                'lat' => $latSum / $n,
                'lng' => $lngSum / $n,
                'elevation' => $first['elevation'],
                'recordedAt' => $first['recordedAt'],
                'accuracy' => null,
            ];
            return [$this->withPauseFlag($merged, true, $last['recordedAt'], $last['seq'])];
        }

        $out = [];
        for ($j = $start; $j <= $end; $j++) {
            $out[] = $this->withPauseFlag($points[$j], false, null, $points[$j]['seq']);
        }
        return $out;
    }

    /**
     * @param array<string, mixed> $point
     * @return array<string, mixed>
     */
    private function withPauseFlag(array $point, bool $isPause, ?string $recordedUntil, int $seqEnd): array
    {
        unset($point['accuracy']);
        $point['isPause'] = $isPause;
        $point['recordedUntil'] = $recordedUntil;
        $point['seqEnd'] = $seqEnd;
        return $point;
    }

    private function secondsBetween(?string $a, ?string $b): ?int
    {
        if ($a === null || $b === null) {
            return null;
        }
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
