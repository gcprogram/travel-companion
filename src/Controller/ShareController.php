<?php

declare(strict_types=1);

namespace App\Controller;

use App\Repository\ShareTokenRepository;
use App\Repository\TripRepository;
use App\Support\Flash;
use App\Support\ShareAccessCookie;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Exception\HttpForbiddenException;
use Slim\Exception\HttpNotFoundException;

/**
 * Login-free trip access via /share/{token}: redeeming a token sets a
 * cookie (ShareAccessCookie) so the rest of the site treats this browser as
 * having the token's permission (view or edit) for that one trip - see
 * TripAccess. Creating/revoking tokens is deliberately checked against the
 * real logged-in user directly here, not via TripAccess::canEdit(), so a
 * visitor holding an "edit" token can use it to add photos/POIs/tracks but
 * never to mint or revoke share links themselves - only the trip's actual
 * owner (or an admin) manages sharing.
 */
final class ShareController
{
    private const PERMISSIONS = ['view', 'edit'];

    public function __construct(
        private readonly TripRepository $trips,
        private readonly ShareTokenRepository $shareTokens,
        private readonly Flash $flash,
    ) {
    }

    public function redeem(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $token = (string) $args['token'];
        $row = $this->shareTokens->findByToken($token);
        if ($row === null) {
            throw new HttpNotFoundException($request);
        }
        $trip = $this->trips->findById((int) $row['trip_id']);
        if ($trip === null) {
            throw new HttpNotFoundException($request);
        }

        $this->shareTokens->touchLastUsed((int) $row['id']);

        $map = ShareAccessCookie::read($request);
        $map[(int) $trip['id']] = $token;

        return $response
            ->withHeader('Location', '/trip/' . $trip['slug'])
            ->withHeader('Set-Cookie', ShareAccessCookie::header($map))
            ->withStatus(302);
    }

    public function create(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $trip = $this->requireOwner($request, (int) $args['id']);

        $body = (array) $request->getParsedBody();
        $permission = in_array($body['permission'] ?? null, self::PERMISSIONS, true) ? $body['permission'] : 'view';
        $label = trim((string) ($body['label'] ?? ''));

        $this->shareTokens->create((int) $trip['id'], $permission, $label !== '' ? mb_substr($label, 0, 100) : null);

        $this->flash->add('success', t('trip.share.token_created'));
        return $this->redirectToTrip($response, $trip);
    }

    public function delete(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $shareToken = $this->shareTokens->findById((int) $args['id']);
        if ($shareToken === null) {
            throw new HttpNotFoundException($request);
        }
        $trip = $this->requireOwner($request, (int) $shareToken['trip_id']);

        $this->shareTokens->delete((int) $shareToken['id']);

        $this->flash->add('success', t('trip.share.token_deleted'));
        return $this->redirectToTrip($response, $trip);
    }

    /**
     * @return array<string, mixed>
     */
    private function requireOwner(ServerRequestInterface $request, int $tripId): array
    {
        $trip = $this->trips->findById($tripId);
        if ($trip === null) {
            throw new HttpNotFoundException($request);
        }
        $user = $request->getAttribute('user');
        if ($user === null || ($user['role'] !== 'admin' && (int) $user['id'] !== (int) $trip['user_id'])) {
            throw new HttpForbiddenException($request);
        }
        return $trip;
    }

    /**
     * @param array<string, mixed> $trip
     */
    private function redirectToTrip(ResponseInterface $response, array $trip): ResponseInterface
    {
        return $response->withHeader('Location', '/trip/' . $trip['slug'])->withStatus(302);
    }
}
