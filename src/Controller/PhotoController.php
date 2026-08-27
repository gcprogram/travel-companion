<?php

declare(strict_types=1);

namespace App\Controller;

use App\Repository\DayEntryRepository;
use App\Repository\PhotoRepository;
use App\Repository\TripRepository;
use App\Service\AiVisionCaptionService;
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
        private readonly AiVisionCaptionService $visionCaption,
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

        [$path, $contentType] = $this->resolveDerivative($storageId, $variant);
        if ($path === null) {
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

        // confirm-remember.js's inline-delete path: the photo tile is
        // already removed client-side, no navigation happens for a flash
        // message or redirect target to matter.
        if ($request->getHeaderLine('X-Inline-Delete') === '1') {
            return $response->withStatus(204);
        }

        $this->flash->add('success', t('flash.photo_deleted'));
        return $response->withHeader('Location', '/entries/' . $entry['id'] . '/edit')->withStatus(302);
    }

    /**
     * "Detailed" diary view's per-photo "generate description" button
     * (day-entry-photo-caption.js) - always overwrites whatever caption is
     * already there (EXIF-imported or an earlier vision-AI run), same
     * "the online model is expected to do better" call Stefan made when
     * asking for this. Sends the small 'web' derivative, never the
     * (already-deleted, for photos) original.
     */
    public function caption(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $photo = $this->photos->findById((int) $args['id']);
        if ($photo === null || $photo['status'] !== 'ready') {
            throw new HttpNotFoundException($request);
        }

        $this->entryAccess->requireEditableEntry($request, (int) $photo['day_entry_id']);

        $storageId = $photo['source_photo_id'] !== null ? (int) $photo['source_photo_id'] : (int) $photo['id'];
        $path = $this->storage->derivativePath($storageId, 'web');
        if (!is_file($path)) {
            return $this->json($response, ['ok' => false, 'error' => t('media.caption_error')], 404);
        }

        $caption = $this->visionCaption->describe((string) file_get_contents($path), 'image/jpeg');
        if ($caption === null) {
            return $this->json($response, ['ok' => false, 'error' => t('media.caption_error')], 502);
        }

        $this->photos->updateVisionCaption((int) $photo['id'], $caption);

        return $this->json($response, ['ok' => true, 'caption' => $caption], 200);
    }

    /**
     * Lightbox rotate ("l"/"r" keyboard shortcuts, day-entry-detail-view.js).
     * Rotates the stored derivatives' actual pixels rather than an EXIF
     * orientation flag: PhotoProcessHandler already auto-orients and strips
     * the original at upload time and deletes it right after, so there is
     * no persisted file left whose orientation tag any viewer still reads -
     * a flag-only rotation would be visually invisible everywhere. Acts on
     * the storage id (like delete) since a reference (migration 0019)
     * shares the same physical files - rotating one rotates the image for
     * every trip/entry referencing it, which is correct, it's the same photo.
     */
    public function rotate(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $photo = $this->photos->findById((int) $args['id']);
        if ($photo === null || $photo['status'] !== 'ready') {
            throw new HttpNotFoundException($request);
        }

        $this->entryAccess->requireEditableEntry($request, (int) $photo['day_entry_id']);

        $body = (array) $request->getParsedBody();
        $direction = $body['direction'] ?? null;
        if ($direction !== 'l' && $direction !== 'r') {
            return $this->json($response, ['ok' => false], 400);
        }
        $degrees = $direction === 'r' ? 90 : -90;

        $storageId = $photo['source_photo_id'] !== null ? (int) $photo['source_photo_id'] : (int) $photo['id'];
        $canonical = $photo['source_photo_id'] !== null ? $this->photos->findById($storageId) : $photo;
        if ($canonical === null) {
            throw new HttpNotFoundException($request);
        }

        [$thumbPath] = $this->resolveDerivative($storageId, 'thumb');
        [$webPath] = $this->resolveDerivative($storageId, 'web');
        if ($thumbPath === null || $webPath === null
            || !$this->rotateFile($thumbPath, $degrees) || !$this->rotateFile($webPath, $degrees)
        ) {
            return $this->json($response, ['ok' => false, 'error' => t('media.rotate_error')], 500);
        }

        if ($canonical['width'] !== null && $canonical['height'] !== null) {
            $this->photos->updateDimensions($storageId, (int) $canonical['height'], (int) $canonical['width']);
        }

        return $this->json($response, ['ok' => true], 200);
    }

    /**
     * Lightbox crop: x/y/width/height as fractions (0-1) of the currently
     * displayed image, computed client-side from where the user dragged a
     * selection box over the (object-fit: contain, so possibly letterboxed)
     * <img> - fractions apply identically to both derivatives despite their
     * different absolute pixel sizes, since thumb/web are always resized
     * from the same source preserving aspect ratio. Same storage-id
     * semantics as rotate(): acts on the canonical file, so every
     * reference (migration 0019) sees the crop too.
     */
    public function crop(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $photo = $this->photos->findById((int) $args['id']);
        if ($photo === null || $photo['status'] !== 'ready') {
            throw new HttpNotFoundException($request);
        }

        $this->entryAccess->requireEditableEntry($request, (int) $photo['day_entry_id']);

        $body = (array) $request->getParsedBody();
        $x = $body['x'] ?? null;
        $y = $body['y'] ?? null;
        $width = $body['width'] ?? null;
        $height = $body['height'] ?? null;
        if (!is_numeric($x) || !is_numeric($y) || !is_numeric($width) || !is_numeric($height)) {
            return $this->json($response, ['ok' => false], 400);
        }
        $x = (float) $x;
        $y = (float) $y;
        $width = (float) $width;
        $height = (float) $height;
        // A sliver crop is almost certainly an accidental click-drag, not a
        // deliberate edit - and would round to a 0px Imagick crop anyway.
        if ($x < 0.0 || $y < 0.0 || $width < 0.03 || $height < 0.03 || $x + $width > 1.0001 || $y + $height > 1.0001) {
            return $this->json($response, ['ok' => false], 422);
        }

        $storageId = $photo['source_photo_id'] !== null ? (int) $photo['source_photo_id'] : (int) $photo['id'];

        [$thumbPath] = $this->resolveDerivative($storageId, 'thumb');
        [$webPath] = $this->resolveDerivative($storageId, 'web');
        $thumbDims = $thumbPath !== null ? $this->cropFile($thumbPath, $x, $y, $width, $height) : null;
        $webDims = $webPath !== null ? $this->cropFile($webPath, $x, $y, $width, $height) : null;
        if ($thumbDims === null || $webDims === null) {
            return $this->json($response, ['ok' => false, 'error' => t('media.crop_error')], 500);
        }

        $this->photos->updateDimensions($storageId, $webDims['width'], $webDims['height']);

        return $this->json($response, ['ok' => true], 200);
    }

    /**
     * Lightbox "Fav" stars (0-5, xmp:Rating convention) - per photo row,
     * not per storage id, see migration 0037.
     */
    public function rate(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $photo = $this->photos->findById((int) $args['id']);
        if ($photo === null) {
            throw new HttpNotFoundException($request);
        }

        $this->entryAccess->requireEditableEntry($request, (int) $photo['day_entry_id']);

        $body = (array) $request->getParsedBody();
        $rating = filter_var($body['rating'] ?? null, FILTER_VALIDATE_INT);
        if ($rating === false || $rating < 0 || $rating > 5) {
            return $this->json($response, ['ok' => false], 400);
        }

        $this->photos->updateRating((int) $photo['id'], $rating);

        return $this->json($response, ['ok' => true, 'rating' => $rating], 200);
    }

    /**
     * @return array{0: ?string, 1: string} [path, contentType] - path is
     *         null when neither the current nor legacy derivative exists.
     */
    private function resolveDerivative(int $storageId, string $variant): array
    {
        $path = $this->storage->derivativePath($storageId, $variant);
        $contentType = $variant === 'web' ? 'image/jpeg' : 'image/webp';
        if (!is_file($path)) {
            // Backward compat: photos processed before the 'web' variant
            // switched from WebP to JPEG (see PhotoStorage::derivativePath).
            $path = $this->storage->legacyDerivativePath($storageId, $variant);
            $contentType = 'image/webp';
        }
        return is_file($path) ? [$path, $contentType] : [null, $contentType];
    }

    private function rotateFile(string $path, int $degrees): bool
    {
        try {
            $image = new \Imagick($path);
            $image->rotateImage('#000000', $degrees);
            $image->writeImage($path);
            $image->destroy();
            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * @return array{width: int, height: int}|null the file's new pixel
     *         dimensions after cropping, or null on failure
     */
    private function cropFile(string $path, float $xFrac, float $yFrac, float $wFrac, float $hFrac): ?array
    {
        try {
            $image = new \Imagick($path);
            $sourceWidth = $image->getImageWidth();
            $sourceHeight = $image->getImageHeight();

            $cropX = (int) round($xFrac * $sourceWidth);
            $cropY = (int) round($yFrac * $sourceHeight);
            // Rounding each edge independently can push the box 1px past
            // the source (e.g. x=0.998 rounds up) - clamp rather than let
            // Imagick reject/misbehave on an out-of-bounds crop.
            $cropWidth = min((int) round($wFrac * $sourceWidth), $sourceWidth - $cropX);
            $cropHeight = min((int) round($hFrac * $sourceHeight), $sourceHeight - $cropY);
            if ($cropWidth < 1 || $cropHeight < 1) {
                return null;
            }

            $image->cropImage($cropWidth, $cropHeight, $cropX, $cropY);
            // Without this, the cropped image keeps its old canvas/page
            // offset metadata - harmless for display but confusing for any
            // tool that reads it (and for a second crop's own math, which
            // assumes (0,0) is the current image's top-left).
            $image->setImagePage(0, 0, 0, 0);
            $image->writeImage($path);

            $dims = ['width' => $image->getImageWidth(), 'height' => $image->getImageHeight()];
            $image->destroy();
            return $dims;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @param array<string, mixed> $data
     */
    private function json(ResponseInterface $response, array $data, int $status): ResponseInterface
    {
        $response->getBody()->write((string) json_encode($data, JSON_THROW_ON_ERROR));
        return $response->withHeader('Content-Type', 'application/json')->withStatus($status);
    }
}
