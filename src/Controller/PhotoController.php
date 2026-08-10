<?php

declare(strict_types=1);

namespace App\Controller;

use App\Repository\DayEntryRepository;
use App\Repository\PhotoRepository;
use App\Repository\TripRepository;
use App\Service\DayEntryAccess;
use App\Service\PhotoStorage;
use App\Service\TripAccess;
use App\Support\Flash;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Exception\HttpNotFoundException;

final class PhotoController
{
    private const VARIANTS = ['thumb', 'web'];

    public function __construct(
        private readonly PhotoRepository $photos,
        private readonly DayEntryRepository $entries,
        private readonly TripRepository $trips,
        private readonly TripAccess $tripAccess,
        private readonly DayEntryAccess $entryAccess,
        private readonly PhotoStorage $storage,
        private readonly Flash $flash,
    ) {
    }

    public function show(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $variant = (string) $args['variant'];
        if (!in_array($variant, self::VARIANTS, true)) {
            throw new HttpNotFoundException($request);
        }

        $photo = $this->photos->findById((int) $args['id']);
        if ($photo === null || $photo['status'] !== 'ready') {
            throw new HttpNotFoundException($request);
        }

        $entry = $this->entries->findById((int) $photo['day_entry_id']);
        $trip = $entry !== null ? $this->trips->findById((int) $entry['trip_id']) : null;
        if ($trip === null || !$this->tripAccess->canView($trip, $request->getAttribute('user'))) {
            // Treat photos on a private trip as "doesn't exist" for strangers, same as the trip itself.
            throw new HttpNotFoundException($request);
        }

        $path = $this->storage->derivativePath((int) $photo['id'], $variant);
        $contentType = $variant === 'web' ? 'image/jpeg' : 'image/webp';
        if (!is_file($path)) {
            // Backward compat: photos processed before the 'web' variant
            // switched from WebP to JPEG (see PhotoStorage::derivativePath).
            $path = $this->storage->legacyDerivativePath((int) $photo['id'], $variant);
            $contentType = 'image/webp';
        }
        if (!is_file($path)) {
            throw new HttpNotFoundException($request);
        }

        $response->getBody()->write((string) file_get_contents($path));
        return $response
            ->withHeader('Content-Type', $contentType)
            ->withHeader('Cache-Control', 'private, max-age=86400');
    }

    public function delete(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $photo = $this->photos->findById((int) $args['id']);
        if ($photo === null) {
            throw new HttpNotFoundException($request);
        }

        [, $entry] = $this->entryAccess->requireEditableEntry($request, (int) $photo['day_entry_id']);

        $this->photos->delete((int) $photo['id']);
        $this->storage->deleteAll((int) $photo['id']);

        $this->flash->add('success', t('flash.photo_deleted'));
        return $response->withHeader('Location', '/entries/' . $entry['id'] . '/edit')->withStatus(302);
    }
}
