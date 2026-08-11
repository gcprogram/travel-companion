<?php

declare(strict_types=1);

namespace App\Service;

/**
 * Read-time-only track cleanup, applied fresh on every /map/data request
 * (never persisted) so tuning the thresholds below never requires a
 * re-upload:
 *
 * 1. Pause detection: a run of consecutive points that all stay within
 *    ~30m of the first point in the run, spanning >10 minutes, collapses
 *    into a single averaged point (a real GPS dwelling around one spot,
 *    e.g. lunch or a museum visit). Shorter stops (a red light) are left
 *    untouched — only sustained dwelling counts as a "pause".
 * 2. Accuracy smoothing (best-effort — most consumer GPX/photo-derived
 *    tracks never carry accuracy at all, so this quietly no-ops then): a
 *    point whose accuracy is meaningfully worse than both neighbors gets
 *    pulled toward them, weighted by each point's precision.
 */
final class TrackSmoothingService
{
    private const PAUSE_RADIUS_METERS = 30.0;
    private const PAUSE_MIN_SECONDS = 600;
    private const ACCURACY_WORSE_FACTOR = 2.0;

    /**
     * Each output point carries the seq range of the raw points it came
     * from (seq..seqEnd, equal for an uncollapsed point). Trimming from the
     * map works on those raw seq values, so a collapsed pause still maps
     * back to a real cut position - see TrackController::trim.
     *
     * @param list<array{seq: int, lat: float, lng: float, elevation: ?float, recordedAt: ?string, accuracy: ?float}> $points
     * @return list<array{seq: int, seqEnd: int, lat: float, lng: float, elevation: ?float, recordedAt: ?string, recordedUntil: ?string, isPause: bool}>
     */
    public function smooth(array $points): array
    {
        $points = array_values($points);
        $points = $this->smoothAccuracy($points);
        return $this->collapsePauses($points);
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
