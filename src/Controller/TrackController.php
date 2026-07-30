<?php

declare(strict_types=1);

namespace App\Controller;

use App\Repository\TrackRepository;
use App\Repository\TripRepository;
use App\Service\GpxParser;
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
        private readonly GpxParser $gpxParser,
        private readonly TrackSimplifier $simplifier,
        private readonly TripAccess $access,
        private readonly Flash $flash,
    ) {
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
        $this->tracks->replaceForTrip((int) $trip['id'], 'gpx', $file->getClientFilename(), $points);

        $this->flash->add('success', t('trip.map.gpx_uploaded'));
        return $this->redirectToMap($response, $trip);
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

    /**
     * @return array<string, mixed>
     */
    private function requireEditable(ServerRequestInterface $request, int $tripId): array
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
}
