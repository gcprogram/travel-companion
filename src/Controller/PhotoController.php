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
        if ($trip === null || !$this->tripAccess->canView($trip, $request->getAttribute('user'), $request)) {
            // Treat photos on a private trip as "doesn't exist" for strangers, same as the trip itself.
            throw new HttpNotFoundException($request);
        }

        // A reference (see migration 0019) owns no files of its own - its
        // bytes live under the photo it duplicates.
        $storageId = $photo['source_photo_id'] !== null ? (int) $photo['source_photo_id'] : (int) $photo['id'];

        $path = $this->storage->derivativePath($storageId, $variant);
        $contentType = $variant === 'web' ? 'image/jpeg' : 'image/webp';
        if (!is_file($path)) {
            // Backward compat: photos processed before the 'web' variant
            // switched from WebP to JPEG (see PhotoStorage::derivativePath).
            $path = $this->storage->legacyDerivativePath($storageId, $variant);
            $contentType = 'image/webp';
        }
        if (!is_file($path)) {
            throw new HttpNotFoundException($request);
        }

        $extension = $contentType === 'image/jpeg' ? 'jpg' : 'webp';
        $response->getBody()->write((string) file_get_contents($path));
        return $response
            ->withHeader('Content-Type', $contentType)
            // The URL itself has no extension, so without a filename hint
            // browsers guess one from the MIME type - on Windows that often
            // means "image/jpeg" saves as .jfif instead of .jpg.
            ->withHeader('Content-Disposition', 'inline; filename="' . $variant . '-' . $photo['id'] . '.' . $extension . '"')
            ->withHeader('Cache-Control', 'private, max-age=86400');
    }

    public function delete(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $photo = $this->photos->findById((int) $args['id']);
        if ($photo === null) {
            throw new HttpNotFoundException($request);
        }

        [, $entry] = $this->entryAccess->requireEditableEntry($request, (int) $photo['day_entry_id']);

        $storageId = $photo['source_photo_id'] !== null ? (int) $photo['source_photo_id'] : (int) $photo['id'];
        // Checked before deleting the row: other references to the same
        // files (this trip's other entries, or the still-live canonical
        // row) must keep working - see migration 0019.
        $stillReferenced = $this->photos->countReferencingStorage($storageId, (int) $photo['id']) > 0;

        $this->photos->delete((int) $photo['id']);
        if (!$stillReferenced) {
            $this->storage->deleteAll($storageId);
        }

        $this->flash->add('success', t('flash.photo_deleted'));
        return $response->withHeader('Location', '/entries/' . $entry['id'] . '/edit')->withStatus(302);
    }
}
