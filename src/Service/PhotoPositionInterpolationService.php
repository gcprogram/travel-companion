<?php

declare(strict_types=1);

namespace App\Service;

use App\Repository\PhotoRepository;
use App\Repository\TrackRepository;

/**
 * Gives a photo without its own EXIF GPS fix a best-guess position by
 * linear interpolation between the nearest known points in time - Stefan's
 * ask: a photo with no location never shows up on any map, even though the
 * trip's track (GPX upload, folder-scan, or a Google Timeline import - all
 * three end up as the same trip_track_points via TrackController/
 * PhotoTrackGapFillService) or another nearby photo usually pins down
 * roughly where it must have been.
 *
 * "Known points" are the trip's track points plus every OTHER photo's real
 * EXIF fix (PhotoRepository::findExifGeotaggedByTrip() - deliberately
 * excludes already-interpolated photos, so a guess never anchors on another
 * guess). A photo whose capture time falls outside the earliest/latest known
 * point is left alone rather than extrapolated - a guess beyond the known
 * range is a lot less trustworthy than one bracketed by real data on both
 * sides.
 *
 * Every run clears every currently-interpolated position first and
 * recomputes from scratch (PhotoRepository::clearInterpolatedPosition() no-
 * ops harmlessly on rows that were never interpolated) - real EXIF
 * positions (lat_source='exif') are never touched. This is what makes an
 * interpolated guess "sticky but revisable": it stays in the DB (visible on
 * the map, usable) until something changes what a fresh recompute would
 * produce - a newly uploaded photo providing a tighter bracket, an extended
 * GPX/Timeline track, or another photo nearby finally getting processed.
 * Dispatched (job type "photo.interpolate") after every track upload
 * (TrackController) and every photo finishing processing
 * (PhotoProcessHandler) - both are exactly the "better data might exist
 * now" events.
 */
final class PhotoPositionInterpolationService
{
    public function __construct(
        private readonly PhotoRepository $photos,
        private readonly TrackRepository $tracks,
    ) {
    }

    /**
     * @return int number of photos given a position
     */
    public function interpolateForTrip(int $tripId): int
    {
        $needing = $this->photos->findNeedingInterpolation($tripId);
        foreach ($needing as $photo) {
            $this->photos->clearInterpolatedPosition($photo['id']);
        }
        if ($needing === []) {
            return 0;
        }

        $knownPoints = $this->collectKnownPoints($tripId);
        if (count($knownPoints) < 2) {
            return 0; // Nothing to bracket against.
        }
        usort($knownPoints, static fn (array $a, array $b): int => $a['at'] <=> $b['at']);

        $count = 0;
        foreach ($needing as $photo) {
            $position = $this->interpolate($knownPoints, $photo['takenAt']);
            if ($position !== null) {
                $this->photos->updateInterpolatedPosition($photo['id'], $position['lat'], $position['lng']);
                $count++;
            }
        }
        return $count;
    }

    /**
     * @return list<array{lat: float, lng: float, at: string}>
     */
    private function collectKnownPoints(int $tripId): array
    {
        $points = [];

        $track = $this->tracks->findByTrip($tripId);
        if ($track !== null) {
            foreach ($this->tracks->findPoints((int) $track['id']) as $p) {
                if ($p['recorded_at'] === null) {
                    continue;
                }
                $points[] = ['lat' => (float) $p['lat'], 'lng' => (float) $p['lng'], 'at' => (string) $p['recorded_at']];
            }
        }

        foreach ($this->photos->findExifGeotaggedByTrip($tripId) as $p) {
            $points[] = ['lat' => $p['lat'], 'lng' => $p['lng'], 'at' => $p['takenAt']];
        }

        return $points;
    }

    /**
     * @param list<array{lat: float, lng: float, at: string}> $sortedPoints ascending by 'at'
     * @return array{lat: float, lng: float}|null
     */
    private function interpolate(array $sortedPoints, string $targetAt): ?array
    {
        $target = $this->toTimestamp($targetAt);
        if ($target === null) {
            return null;
        }

        $before = null;
        $after = null;
        foreach ($sortedPoints as $point) {
            $t = $this->toTimestamp($point['at']);
            if ($t === null) {
                continue;
            }
            if ($t <= $target) {
                $before = ['point' => $point, 'at' => $t];
            }
            if ($t >= $target && $after === null) {
                $after = ['point' => $point, 'at' => $t];
            }
        }

        if ($before === null || $after === null) {
            return null; // Outside the known range - refuse to extrapolate.
        }
        if ($before['at'] === $after['at']) {
            return ['lat' => $before['point']['lat'], 'lng' => $before['point']['lng']];
        }

        $fraction = ($target - $before['at']) / ($after['at'] - $before['at']);
        return [
            'lat' => round($before['point']['lat'] + ($after['point']['lat'] - $before['point']['lat']) * $fraction, 6),
            'lng' => round($before['point']['lng'] + ($after['point']['lng'] - $before['point']['lng']) * $fraction, 6),
        ];
    }

    private function toTimestamp(string $value): ?int
    {
        try {
            return (new \DateTimeImmutable($value))->getTimestamp();
        } catch (\Exception) {
            return null;
        }
    }
}
