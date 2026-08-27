<?php

declare(strict_types=1);

namespace App\Controller;

use App\Repository\DayEntryRatingRepository;
use App\Repository\DayEntryRepository;
use App\Repository\DayEntryWeatherHourRepository;
use App\Repository\JobRepository;
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
        private readonly DayEntryRatingRepository $ratings,
        private readonly ShareTokenRepository $shareTokens,
        private readonly JobRepository $jobs,
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

        // Averaged viewer ratings (day_entry_ratings) per entry, for the
        // collapsed accordion header - only entries with at least one
        // rating show up here (see DayEntryRatingRepository::summaryForEntries()).
        $ratingSummaryByEntry = $this->ratings->summaryForEntries(
            array_map(static fn (array $e): int => (int) $e['id'], $entries)
        );

        // Deliberately the plain, non-token-aware check (no $request) -
        // sharing is managed by the real owner/admin only, never by someone
        // who got in via an edit share token themselves.
        $canManageSharing = $this->access->canEdit($trip, $user);

        return $this->view->render($response, 'trips/show', [
            'trip' => $trip,
            'stations' => $this->stations->findByTrip((int) $trip['id']),
            'entries' => $entries,
            'weatherSummaryByEntry' => $weatherSummaryByEntry,
            'ratingSummaryByEntry' => $ratingSummaryByEntry,
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
        ]);
    }

    public function store(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $user = $request->getAttribute('user');
        [$data, $errors] = $this->validate((array) $request->getParsedBody());

        if ($errors !== []) {
            return $this->view->render($response, 'trips/form', [
                'trip' => $data,
                'errors' => $errors,
            ], status: 422);
        }

        // country/operator/date_start/date_end aren't asked for anymore -
        // country and the date range get auto-filled once photos/a track
        // exist (see TripMetadataAutoFillHandler), operator stays unused
        // for new trips entirely.
        $data['country'] = null;
        $data['operator'] = null;
        $data['date_start'] = null;
        $data['date_end'] = null;
        $data['slug'] = $this->slugger->uniqueTripSlug($data['title']);
        $tripId = $this->trips->create((int) $user['id'], $data);

        $this->flash->add('success', t('flash.trip_created'));
        // A freshly created trip goes straight into the creation wizard
        // (photos -> route -> visited places -> trip) - photos first since
        // that's the most intuitive first action for a user just back from
        // a trip; editing an existing trip's metadata later (update(),
        // below) always lands back on the plain trip page instead - that's
        // not the wizard.
        return $response->withHeader('Location', '/trip/' . $data['slug'] . '/photos?wizard=1')->withStatus(302);
    }

    public function edit(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $trip = $this->requireEditable($request, (int) $args['id']);

        return $this->view->render($response, 'trips/form', [
            'trip' => $trip,
        ]);
    }

    public function update(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $trip = $this->requireEditable($request, (int) $args['id']);
        [$data, $errors] = $this->validate((array) $request->getParsedBody());

        if ($errors !== []) {
            $data['id'] = $trip['id'];
            return $this->view->render($response, 'trips/form', [
                'trip' => $data,
                'errors' => $errors,
            ], status: 422);
        }

        // This form no longer edits country/operator/date_start/date_end -
        // keep whatever the trip already has (manually set earlier, or
        // auto-filled by TripMetadataAutoFillHandler) untouched.
        $data['country'] = $trip['country'];
        $data['operator'] = $trip['operator'];
        $data['date_start'] = $trip['date_start'];
        $data['date_end'] = $trip['date_end'];

        // Keep the slug stable as long as the title doesn't change (links stay valid).
        $data['slug'] = $data['title'] === $trip['title']
            ? $trip['slug']
            : $this->slugger->uniqueTripSlug($data['title'], (int) $trip['id']);

        $this->trips->update((int) $trip['id'], $data);

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
     * Dispatches the trip.suggest_meta job (see TripSuggestMetaHandler) - a
     * text-completion call, so it goes through the queue like the day-entry
     * summary job rather than blocking this request.
     */
    public function suggestMeta(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $trip = $this->requireEditable($request, (int) $args['id']);

        $this->jobs->dispatch('trip.suggest_meta', ['trip_id' => (int) $trip['id']]);

        $this->flash->add('success', t('trip.form.ai_suggest_started'));
        return $response->withHeader('Location', '/trips/' . (int) $trip['id'] . '/edit')->withStatus(302);
    }

    /**
     * Dispatches the trip.suggest_description job (see
     * TripSuggestDescriptionHandler) - same async-via-queue reasoning as
     * suggestMeta() above, a text-completion call that shouldn't block the
     * request. $depth (Stefan's "Tiefe/Umfang wählbar" ask) comes from the
     * form's select next to the button.
     */
    public function suggestDescription(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $trip = $this->requireEditable($request, (int) $args['id']);

        $body = (array) $request->getParsedBody();
        $depth = in_array($body['depth'] ?? null, ['short', 'medium', 'long'], true) ? $body['depth'] : 'medium';

        $this->jobs->dispatch('trip.suggest_description', ['trip_id' => (int) $trip['id'], 'depth' => $depth]);

        $this->flash->add('success', t('trip.form.ai_suggest_started'));
        return $response->withHeader('Location', '/trips/' . (int) $trip['id'] . '/edit')->withStatus(302);
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
     * Normalizes and validates the form data. Only title/description/
     * visibility are asked for anymore - country/operator/date range/route
     * stations are gone from the form (see store()/update() for how those
     * columns get filled instead).
     *
     * @param array<string, mixed> $body
     * @return array{0: array<string, mixed>, 1: list<string>}
     */
    private function validate(array $body): array
    {
        $errors = [];

        $data = [
            'title' => trim((string) ($body['title'] ?? '')),
            'description' => $this->nullable($body['description'] ?? null),
            'tags' => $this->nullable($body['tags'] ?? null),
            'visibility' => in_array($body['visibility'] ?? null, ['public', 'member_only'], true)
                ? $body['visibility']
                : 'private',
        ];

        if (mb_strlen($data['title']) < 3) {
            $errors[] = t('validation.trip_title_min_length');
        }

        return [$data, $errors];
    }

    private function nullable(mixed $value): ?string
    {
        $value = trim((string) ($value ?? ''));
        return $value === '' ? null : $value;
    }
}
