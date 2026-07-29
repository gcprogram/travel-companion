<?php

declare(strict_types=1);

namespace App\Controller;

use App\Repository\StationRepository;
use App\Repository\TripRepository;
use App\Service\Slugger;
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
        private readonly Slugger $slugger,
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
        if (!$this->canView($trip, $user)) {
            // Private Reisen für Fremde wie "nicht vorhanden" behandeln.
            throw new HttpNotFoundException($request);
        }

        return $this->view->render($response, 'trips/show', [
            'trip' => $trip,
            'stations' => $this->stations->findByTrip((int) $trip['id']),
            'canEdit' => $this->canEdit($trip, $user),
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

        $this->flash->add('success', 'Reise angelegt.');
        return $response->withHeader('Location', '/reise/' . $data['slug'])->withStatus(302);
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

        // Slug stabil halten, solange sich der Titel nicht ändert (Links bleiben gültig).
        $data['slug'] = $data['title'] === $trip['title']
            ? $trip['slug']
            : $this->slugger->uniqueTripSlug($data['title'], (int) $trip['id']);

        $this->trips->update((int) $trip['id'], $data);
        $this->stations->replaceForTrip((int) $trip['id'], $stations);

        $this->flash->add('success', 'Reise gespeichert.');
        return $response->withHeader('Location', '/reise/' . $data['slug'])->withStatus(302);
    }

    public function delete(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $trip = $this->requireEditable($request, (int) $args['id']);
        $this->trips->delete((int) $trip['id']);

        $this->flash->add('success', 'Reise gelöscht.');
        return $response->withHeader('Location', '/')->withStatus(302);
    }

    /**
     * @param array<string, mixed> $trip
     * @param array<string, mixed>|null $user
     */
    private function canView(array $trip, ?array $user): bool
    {
        if ($trip['visibility'] === 'public') {
            return true;
        }
        return $this->canEdit($trip, $user);
    }

    /**
     * @param array<string, mixed> $trip
     * @param array<string, mixed>|null $user
     */
    private function canEdit(array $trip, ?array $user): bool
    {
        if ($user === null) {
            return false;
        }
        return $user['role'] === 'admin' || (int) $user['id'] === (int) $trip['user_id'];
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
        if (!$this->canEdit($trip, $request->getAttribute('user'))) {
            throw new HttpForbiddenException($request);
        }
        return $trip;
    }

    /**
     * Normalisiert und validiert die Formulardaten.
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
            $errors[] = 'Bitte einen Titel mit mindestens 3 Zeichen angeben.';
        }
        if ($data['date_start'] !== null && $data['date_end'] !== null && $data['date_end'] < $data['date_start']) {
            $errors[] = 'Das Enddatum liegt vor dem Startdatum.';
        }

        // Stationen: parallele Arrays aus dem Formular (station_name[], station_date[], station_notes[])
        $stations = [];
        $names = (array) ($body['station_name'] ?? []);
        $dates = (array) ($body['station_date'] ?? []);
        $notes = (array) ($body['station_notes'] ?? []);
        foreach ($names as $i => $name) {
            $name = trim((string) $name);
            if ($name === '') {
                continue; // leere Zeilen (z.B. die Vorlagezeile) überspringen
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
