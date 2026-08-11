<?php

declare(strict_types=1);

namespace App\Controller;

use App\Repository\DayEntryRepository;
use App\Repository\JobRepository;
use App\Repository\PhotoRepository;
use App\Repository\VideoRepository;
use App\Service\DayEntryAccess;
use App\Service\MediaCleanupService;
use App\Support\Flash;
use App\Support\View;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final class DayEntryController
{
    private const MOODS = ['very_bad', 'bad', 'neutral', 'good', 'very_good'];

    public function __construct(
        private readonly View $view,
        private readonly DayEntryRepository $entries,
        private readonly PhotoRepository $photos,
        private readonly VideoRepository $videos,
        private readonly JobRepository $jobs,
        private readonly DayEntryAccess $access,
        private readonly MediaCleanupService $mediaCleanup,
        private readonly Flash $flash,
    ) {
    }

    public function create(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $trip = $this->access->requireEditableTrip($request, (int) $args['tripId']);

        return $this->view->render($response, 'day_entries/form', [
            'trip' => $trip,
            'entry' => null,
            'photos' => [],
            'videos' => [],
            'defaultDate' => $this->suggestedEntryDate($trip),
        ]);
    }

    /**
     * The first date in the trip's range that has no entry yet, capped at
     * today (never suggests a future date the user hasn't lived through) -
     * falls back to today outright if the trip has no date range or every
     * day in it already has an entry.
     *
     * @param array<string, mixed> $trip
     */
    private function suggestedEntryDate(array $trip): string
    {
        $today = new \DateTimeImmutable('today');
        if ($trip['date_start'] === null || $trip['date_end'] === null) {
            return $today->format('Y-m-d');
        }

        $start = new \DateTimeImmutable((string) $trip['date_start']);
        $end = new \DateTimeImmutable((string) $trip['date_end']);

        $used = array_flip(array_map(
            static fn (array $e): string => (string) $e['entry_date'],
            $this->entries->findByTrip((int) $trip['id']),
        ));

        $firstMissing = null;
        for ($date = $start; $date <= $end; $date = $date->modify('+1 day')) {
            if (!isset($used[$date->format('Y-m-d')])) {
                $firstMissing = $date;
                break;
            }
        }

        if ($firstMissing === null) {
            return $today->format('Y-m-d');
        }

        return min($firstMissing, $today)->format('Y-m-d');
    }

    public function store(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $trip = $this->access->requireEditableTrip($request, (int) $args['tripId']);
        [$data, $errors] = $this->validate((array) $request->getParsedBody());

        if ($errors !== []) {
            return $this->view->render($response, 'day_entries/form', [
                'trip' => $trip,
                'entry' => $data,
                'photos' => [],
                'videos' => [],
                'errors' => $errors,
            ], status: 422);
        }

        $id = $this->entries->create((int) $trip['id'], $data);
        $this->dispatchWeatherJobIfPossible($id, $data);

        $this->flash->add('success', t('flash.entry_saved'));
        return $response->withHeader('Location', '/entries/' . $id . '/edit')->withStatus(302);
    }

    public function edit(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        [$trip, $entry] = $this->access->requireEditableEntry($request, (int) $args['id']);

        return $this->view->render($response, 'day_entries/form', [
            'trip' => $trip,
            'entry' => $entry,
            'photos' => $this->photos->findByEntry((int) $entry['id']),
            'videos' => $this->videos->findByEntry((int) $entry['id']),
        ]);
    }

    public function update(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        [$trip, $entry] = $this->access->requireEditableEntry($request, (int) $args['id']);
        [$data, $errors] = $this->validate((array) $request->getParsedBody());

        if ($errors !== []) {
            $data['id'] = $entry['id'];
            return $this->view->render($response, 'day_entries/form', [
                'trip' => $trip,
                'entry' => $data,
                'photos' => $this->photos->findByEntry((int) $entry['id']),
                'videos' => $this->videos->findByEntry((int) $entry['id']),
                'errors' => $errors,
            ], status: 422);
        }

        $this->entries->update((int) $entry['id'], $data);
        $this->dispatchWeatherJobIfPossible((int) $entry['id'], $data);

        $this->flash->add('success', t('flash.entry_saved'));
        return $response->withHeader('Location', '/trip/' . $trip['slug'])->withStatus(302);
    }

    /**
     * Tiny poke for day-entry-form.js's "processing" placeholders: used to
     * be a blind location.reload() every few seconds while anything was
     * still pending, which on a gallery with two dozen photos meant
     * re-fetching two dozen image URLs on every single poll - exactly the
     * "many requests to many different URLs" pattern that got the host's
     * abuse detection to block the app's own IP. One tiny JSON response
     * instead; the client only reloads once, when this actually says done.
     */
    public function mediaStatus(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        [, $entry] = $this->access->requireEditableEntry($request, (int) $args['id']);

        $pending = false;
        foreach ($this->photos->findByEntry((int) $entry['id']) as $photo) {
            if ($photo['status'] === 'pending') {
                $pending = true;
                break;
            }
        }
        if (!$pending) {
            foreach ($this->videos->findByEntry((int) $entry['id']) as $video) {
                if ($video['status'] === 'pending') {
                    $pending = true;
                    break;
                }
            }
        }

        $response->getBody()->write((string) json_encode(['pending' => $pending], JSON_THROW_ON_ERROR));
        return $response->withHeader('Content-Type', 'application/json');
    }

    public function delete(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        [$trip, $entry] = $this->access->requireEditableEntry($request, (int) $args['id']);
        // Must run before the DB delete: it looks up photo/video ids via
        // the very rows the cascade is about to remove.
        $this->mediaCleanup->deleteForEntry((int) $entry['id']);
        $this->entries->delete((int) $entry['id']);

        $this->flash->add('success', t('flash.entry_deleted'));
        return $response->withHeader('Location', '/trip/' . $trip['slug'])->withStatus(302);
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
            'location_name' => $this->nullable($body['location_name'] ?? null),
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
