<?php

declare(strict_types=1);

namespace App\Controller;

use App\Repository\DayEntryRepository;
use App\Repository\DayEntryWeatherHourRepository;
use App\Repository\ShareTokenRepository;
use App\Repository\StationRepository;
use App\Repository\TripRepository;
use App\Service\MediaCleanupService;
use App\Service\Slugger;
use App\Service\TripAccess;
use App\Support\Env;
use App\Support\Flash;
use App\Support\View;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Exception\HttpForbiddenException;
use Slim\Exception\HttpNotFoundException;

final class TripController
{
    public function __construct(
        private readonly View $view,
        private readonly TripRepository $trips,
        private readonly StationRepository $stations,
        private readonly DayEntryRepository $entries,
        private readonly DayEntryWeatherHourRepository $weatherHours,
        private readonly ShareTokenRepository $shareTokens,
        private readonly Slugger $slugger,
        private readonly TripAccess $access,
        private readonly MediaCleanupService $mediaCleanup,
        private readonly Flash $flash,
    ) {
    }

    public function show(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $trip = $this->trips->findBySlug((string) $args['slug']);
        if ($trip === null) {
            throw new HttpNotFoundException($request);
        }

        $user = $request->getAttribute('user');
        if (!$this->access->canView($trip, $user, $request)) {
            // Treat private trips as "doesn't exist" for strangers.
            throw new HttpNotFoundException($request);
        }

        $entries = $this->entries->findByTrip((int) $trip['id']);

        // Compact day/night reading per entry for the collapsed accordion
        // header (see day_night_weather_summary()) - cheap (24 small rows
        // per entry, no media), unlike the photos/videos that stay lazy.
        $weatherSummaryByEntry = [];
        foreach ($entries as $entry) {
            $weatherSummaryByEntry[(int) $entry['id']]
                = day_night_weather_summary($this->weatherHours->findByEntry((int) $entry['id']));
        }

        // Deliberately the plain, non-token-aware check (no $request) -
        // sharing is managed by the real owner/admin only, never by someone
        // who got in via an edit share token themselves.
        $canManageSharing = $this->access->canEdit($trip, $user);

        return $this->view->render($response, 'trips/show', [
            'trip' => $trip,
            'stations' => $this->stations->findByTrip((int) $trip['id']),
            'entries' => $entries,
            'weatherSummaryByEntry' => $weatherSummaryByEntry,
            'canEdit' => $this->access->canEdit($trip, $user, $request),
            'canManageSharing' => $canManageSharing,
            'shareTokens' => $canManageSharing ? $this->shareTokens->findByTrip((int) $trip['id']) : [],
            'shareBaseUrl' => rtrim((string) Env::get('APP_URL', ''), '/') . '/share/',
            'headExtra' => '<link rel="stylesheet" href="/assets/js/vendor/leaflet.css">',
        ]);
    }

    public function create(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        return $this->view->render($response, 'trips/form', [
            'trip' => null,
            'stations' => [],
        ]);
    }

    public function store(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $user = $request->getAttribute('user');
        [$data, $stations, $errors] = $this->validate((array) $request->getParsedBody());

        if ($errors !== []) {
            return $this->view->render($response, 'trips/form', [
                'trip' => $data,
                'stations' => $stations,
                'errors' => $errors,
            ], status: 422);
        }

        $data['slug'] = $this->slugger->uniqueTripSlug($data['title']);
        $tripId = $this->trips->create((int) $user['id'], $data);
        $this->stations->replaceForTrip($tripId, $stations);

        $this->flash->add('success', t('flash.trip_created'));
        return $response->withHeader('Location', '/trip/' . $data['slug'])->withStatus(302);
    }

    public function edit(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $trip = $this->requireEditable($request, (int) $args['id']);

        return $this->view->render($response, 'trips/form', [
            'trip' => $trip,
            'stations' => $this->stations->findByTrip((int) $trip['id']),
        ]);
    }

    public function update(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $trip = $this->requireEditable($request, (int) $args['id']);
        [$data, $stations, $errors] = $this->validate((array) $request->getParsedBody());

        if ($errors !== []) {
            $data['id'] = $trip['id'];
            return $this->view->render($response, 'trips/form', [
                'trip' => $data,
                'stations' => $stations,
                'errors' => $errors,
            ], status: 422);
        }

        // Keep the slug stable as long as the title doesn't change (links stay valid).
        $data['slug'] = $data['title'] === $trip['title']
            ? $trip['slug']
            : $this->slugger->uniqueTripSlug($data['title'], (int) $trip['id']);

        $this->trips->update((int) $trip['id'], $data);
        $this->stations->replaceForTrip((int) $trip['id'], $stations);

        $this->flash->add('success', t('flash.trip_updated'));
        return $response->withHeader('Location', '/trip/' . $data['slug'])->withStatus(302);
    }

    public function delete(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $trip = $this->requireEditable($request, (int) $args['id']);
        // Must run before the DB delete: it looks up photo/video ids via
        // the very rows the cascade is about to remove.
        $this->mediaCleanup->deleteForTrip((int) $trip['id']);
        $this->trips->delete((int) $trip['id']);

        $this->flash->add('success', t('flash.trip_deleted'));
        return $response->withHeader('Location', '/')->withStatus(302);
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

    /**
     * Normalizes and validates the form data.
     *
     * @param array<string, mixed> $body
     * @return array{0: array<string, mixed>, 1: list<array{name: string, arrival_date: ?string, notes: ?string}>, 2: list<string>}
     */
    private function validate(array $body): array
    {
        $errors = [];

        $data = [
            'title' => trim((string) ($body['title'] ?? '')),
            'country' => $this->nullable($body['country'] ?? null),
            'operator' => $this->nullable($body['operator'] ?? null),
            'description' => $this->nullable($body['description'] ?? null),
            'date_start' => $this->validDateOrNull($body['date_start'] ?? null),
            'date_end' => $this->validDateOrNull($body['date_end'] ?? null),
            'visibility' => ($body['visibility'] ?? 'private') === 'public' ? 'public' : 'private',
        ];

        if (mb_strlen($data['title']) < 3) {
            $errors[] = t('validation.trip_title_min_length');
        }
        if ($data['date_start'] !== null && $data['date_end'] !== null && $data['date_end'] < $data['date_start']) {
            $errors[] = t('validation.trip_end_before_start');
        }

        // Stations: parallel arrays from the form (station_name[], station_date[], station_notes[])
        $stations = [];
        $names = (array) ($body['station_name'] ?? []);
        $dates = (array) ($body['station_date'] ?? []);
        $notes = (array) ($body['station_notes'] ?? []);
        foreach ($names as $i => $name) {
            $name = trim((string) $name);
            if ($name === '') {
                continue; // Skip empty rows (e.g. the template row)
            }
            $stations[] = [
                'name' => mb_substr($name, 0, 190),
                'arrival_date' => $this->validDateOrNull($dates[$i] ?? null),
                'notes' => $this->nullable($notes[$i] ?? null),
            ];
        }

        return [$data, $stations, $errors];
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
}
