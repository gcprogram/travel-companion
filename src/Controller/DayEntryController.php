<?php

declare(strict_types=1);

namespace App\Controller;

use App\Repository\DayEntryRepository;
use App\Repository\JobRepository;
use App\Repository\TripRepository;
use App\Service\TripAccess;
use App\Support\Flash;
use App\Support\View;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Exception\HttpForbiddenException;
use Slim\Exception\HttpNotFoundException;

final class DayEntryController
{
    private const MOODS = ['very_bad', 'bad', 'neutral', 'good', 'very_good'];

    public function __construct(
        private readonly View $view,
        private readonly TripRepository $trips,
        private readonly DayEntryRepository $entries,
        private readonly JobRepository $jobs,
        private readonly TripAccess $access,
        private readonly Flash $flash,
    ) {
    }

    public function create(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $trip = $this->requireEditableTrip($request, (int) $args['tripId']);

        return $this->view->render($response, 'day_entries/form', [
            'trip' => $trip,
            'entry' => null,
        ]);
    }

    public function store(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $trip = $this->requireEditableTrip($request, (int) $args['tripId']);
        [$data, $errors] = $this->validate((array) $request->getParsedBody());

        if ($errors !== []) {
            return $this->view->render($response, 'day_entries/form', [
                'trip' => $trip,
                'entry' => $data,
                'errors' => $errors,
            ], status: 422);
        }

        $id = $this->entries->create((int) $trip['id'], $data);
        $this->dispatchWeatherJobIfPossible($id, $data);

        $this->flash->add('success', t('flash.entry_saved'));
        return $response->withHeader('Location', '/trip/' . $trip['slug'])->withStatus(302);
    }

    public function edit(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        [$trip, $entry] = $this->requireEditableEntry($request, (int) $args['id']);

        return $this->view->render($response, 'day_entries/form', [
            'trip' => $trip,
            'entry' => $entry,
        ]);
    }

    public function update(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        [$trip, $entry] = $this->requireEditableEntry($request, (int) $args['id']);
        [$data, $errors] = $this->validate((array) $request->getParsedBody());

        if ($errors !== []) {
            $data['id'] = $entry['id'];
            return $this->view->render($response, 'day_entries/form', [
                'trip' => $trip,
                'entry' => $data,
                'errors' => $errors,
            ], status: 422);
        }

        $this->entries->update((int) $entry['id'], $data);
        $this->dispatchWeatherJobIfPossible((int) $entry['id'], $data);

        $this->flash->add('success', t('flash.entry_saved'));
        return $response->withHeader('Location', '/trip/' . $trip['slug'])->withStatus(302);
    }

    public function delete(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        [$trip, $entry] = $this->requireEditableEntry($request, (int) $args['id']);
        $this->entries->delete((int) $entry['id']);

        $this->flash->add('success', t('flash.entry_deleted'));
        return $response->withHeader('Location', '/trip/' . $trip['slug'])->withStatus(302);
    }

    /**
     * @return array<string, mixed>
     */
    private function requireEditableTrip(ServerRequestInterface $request, int $tripId): array
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
     * @return array{0: array<string, mixed>, 1: array<string, mixed>}
     */
    private function requireEditableEntry(ServerRequestInterface $request, int $entryId): array
    {
        $entry = $this->entries->findById($entryId);
        if ($entry === null) {
            throw new HttpNotFoundException($request);
        }
        $trip = $this->requireEditableTrip($request, (int) $entry['trip_id']);
        return [$trip, $entry];
    }

    private function dispatchWeatherJobIfPossible(int $entryId, array $data): void
    {
        if ($data['lat'] !== null && $data['lng'] !== null) {
            $this->jobs->dispatch('weather.fetch', ['day_entry_id' => $entryId]);
        }
    }

    /**
     * @param array<string, mixed> $body
     * @return array{0: array<string, mixed>, 1: list<string>}
     */
    private function validate(array $body): array
    {
        $errors = [];

        $data = [
            'entry_date' => $this->validDateOrNull($body['entry_date'] ?? null),
            'title' => $this->nullable($body['title'] ?? null),
            'body' => $this->nullable($body['body'] ?? null),
            'mood' => $this->validMoodOrNull($body['mood'] ?? null),
            'rating' => $this->validRatingOrNull($body['rating'] ?? null),
            'lat' => null,
            'lng' => null,
        ];

        if ($data['entry_date'] === null) {
            $errors[] = t('validation.entry_date_invalid');
        }
        if ($data['body'] === null) {
            $errors[] = t('validation.entry_body_required');
        }

        $lat = trim((string) ($body['lat'] ?? ''));
        $lng = trim((string) ($body['lng'] ?? ''));
        if ($lat !== '' && $lng !== '' && is_numeric($lat) && is_numeric($lng)) {
            $data['lat'] = round((float) $lat, 6);
            $data['lng'] = round((float) $lng, 6);
        }

        return [$data, $errors];
    }

    private function nullable(mixed $value): ?string
    {
        $value = trim((string) ($value ?? ''));
        return $value === '' ? null : $value;
    }

    private function validDateOrNull(mixed $value): ?string
    {
        $value = trim((string) ($value ?? ''));
        if ($value === '') {
            return null;
        }
        $dt = \DateTimeImmutable::createFromFormat('Y-m-d', $value);
        return ($dt !== false && $dt->format('Y-m-d') === $value) ? $value : null;
    }

    private function validMoodOrNull(mixed $value): ?string
    {
        $value = (string) ($value ?? '');
        return in_array($value, self::MOODS, true) ? $value : null;
    }

    private function validRatingOrNull(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        $rating = (int) $value;
        return ($rating >= 1 && $rating <= 5) ? $rating : null;
    }
}
