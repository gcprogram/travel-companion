<?php

declare(strict_types=1);

namespace App\Controller;

use App\Repository\DayEntryRepository;
use App\Repository\JobRepository;
use App\Repository\TrackRepository;
use App\Repository\TripRepository;
use App\Service\GpxParser;
use App\Service\PhotoTrackGapFillService;
use App\Service\TrackSimplifier;
use App\Service\TripAccess;
use App\Support\Flash;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Exception\HttpForbiddenException;
use Slim\Exception\HttpNotFoundException;

final class TrackController
{
    public function __construct(
        private readonly TripRepository $trips,
        private readonly TrackRepository $tracks,
        private readonly DayEntryRepository $entries,
        private readonly JobRepository $jobs,
        private readonly GpxParser $gpxParser,
        private readonly TrackSimplifier $simplifier,
        private readonly PhotoTrackGapFillService $gapFill,
        private readonly TripAccess $access,
        private readonly Flash $flash,
    ) {
    }

    /**
     * A track just arrived (or grew) - dispatch entry.locate for every entry
     * in the trip that still has no location_name, in case the track can
     * now fill it (photo/video-derived locations already dispatch this
     * themselves when they're the source; this covers entries with neither).
     */
    private function dispatchLocateForOpenEntries(int $tripId): void
    {
        foreach ($this->entries->findByTrip($tripId) as $entry) {
            if (empty($entry['location_name'])) {
                $this->jobs->dispatch('entry.locate', ['day_entry_id' => (int) $entry['id']]);
            }
        }
    }

    public function uploadGpx(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $trip = $this->requireEditable($request, (int) $args['id']);

        $files = $request->getUploadedFiles();
        $file = $files['gpx'] ?? null;
        if ($file === null || $file->getError() !== UPLOAD_ERR_OK) {
            $this->flash->add('error', t('trip.map.gpx_upload_error'));
            return $this->redirectToMap($response, $trip);
        }

        $points = $this->gpxParser->parse($file->getStream()->getContents());
        if ($points === []) {
            $this->flash->add('error', t('trip.map.gpx_empty'));
            return $this->redirectToMap($response, $trip);
        }

        $points = $this->simplifier->simplify($points);
        $this->tracks->appendForTrip((int) $trip['id'], 'gpx', $file->getClientFilename(), $points);
        $this->gapFill->fillGaps((int) $trip['id']);
        $this->dispatchLocateForOpenEntries((int) $trip['id']);

        $this->flash->add('success', t('trip.map.gpx_uploaded'));
        return $this->redirectToMap($response, $trip);
    }

    /**
     * Builds/replaces the trip's track from client-extracted {lat, lng,
     * recordedAt} points (track-folder-scan.js) — the whole point of this
     * endpoint is that the source photos/videos themselves are never
     * uploaded, only their extracted coordinates.
     */
    public function submitPoints(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $trip = $this->requireEditable($request, (int) $args['id']);

        $body = (array) $request->getParsedBody();
        $rawPoints = is_array($body['points'] ?? null) ? $body['points'] : [];

        $points = [];
        foreach ($rawPoints as $raw) {
            if (!is_array($raw)) {
                continue;
            }
            $lat = $raw['lat'] ?? null;
            $lng = $raw['lng'] ?? null;
            $recordedAt = $raw['recordedAt'] ?? null;
            if (!is_numeric($lat) || !is_numeric($lng) || !is_string($recordedAt)) {
                continue;
            }
            $lat = (float) $lat;
            $lng = (float) $lng;
            if ($lat < -90.0 || $lat > 90.0 || $lng < -180.0 || $lng > 180.0) {
                continue;
            }
            $normalized = $this->normalizeTime($recordedAt);
            if ($normalized === null) {
                continue;
            }
            $points[] = [
                'lat' => round($lat, 6),
                'lng' => round($lng, 6),
                'elevation' => null,
                'recordedAt' => $normalized,
                'accuracy' => null,
            ];
        }

        if (count($points) < 2) {
            return $this->json($response, ['error' => t('trip.map.points_insufficient')], 422);
        }

        usort($points, static fn (array $a, array $b): int => $a['recordedAt'] <=> $b['recordedAt']);
        $points = $this->simplifier->simplify($points);
        $this->tracks->appendForTrip((int) $trip['id'], 'points', null, $points);
        $this->gapFill->fillGaps((int) $trip['id']);
        $this->dispatchLocateForOpenEntries((int) $trip['id']);

        return $this->json($response, ['ok' => true], 200);
    }

    public function trim(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $trip = $this->requireEditable($request, (int) $args['id']);
        $track = $this->tracks->findByTrip((int) $trip['id']);
        if ($track === null) {
            throw new HttpNotFoundException($request);
        }

        $body = (array) $request->getParsedBody();
        $start = $this->intOrNull($body['trim_start'] ?? null);
        $end = $this->intOrNull($body['trim_end'] ?? null);
        if ($start !== null && $end !== null && $start > $end) {
            [$start, $end] = [$end, $start];
        }

        $this->tracks->updateTrim((int) $track['id'], $start, $end);
        return $this->redirectToMap($response, $trip);
    }

    public function delete(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $trip = $this->requireEditable($request, (int) $args['id']);
        $this->tracks->deleteForTrip((int) $trip['id']);

        $this->flash->add('success', t('trip.map.track_deleted'));
        return $this->redirectToMap($response, $trip);
    }

    private function redirectToMap(ResponseInterface $response, array $trip): ResponseInterface
    {
        return $response->withHeader('Location', '/trip/' . $trip['slug'] . '/map')->withStatus(302);
    }

    private function intOrNull(mixed $value): ?int
    {
        $value = trim((string) ($value ?? ''));
        return $value === '' || !is_numeric($value) ? null : (int) $value;
    }

    private function normalizeTime(string $time): ?string
    {
        try {
            $dt = new \DateTimeImmutable($time);
        } catch (\Exception) {
            return null;
        }
        return $dt->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }

    /**
     * @param array<string, mixed> $data
     */
    private function json(ResponseInterface $response, array $data, int $status): ResponseInterface
    {
        $response->getBody()->write((string) json_encode($data, JSON_THROW_ON_ERROR));
        return $response->withHeader('Content-Type', 'application/json')->withStatus($status);
    }

    /**
     * @return array<string, mixed>
     */
    private function requireEditable(ServerRequestInterface $request, int $tripId): array
    {
        $trip = $this->trips->findById($tripId);
        if ($trip === null) {
            throw new HttpNotFoundException($request);
        }
        if (!$this->access->canEdit($trip, $request->getAttribute('user'), $request)) {
            throw new HttpForbiddenException($request);
        }
        return $trip;
    }
}
