<?php

declare(strict_types=1);

namespace App\Job;

use App\Repository\PhotoRepository;
use App\Service\PhotoStorage;
use Psr\Log\LoggerInterface;

/**
 * Job type "photo.process". Payload: {"photo_id": int}.
 * Generates the thumb/web WebP derivatives from the uploaded original via
 * Imagick. Only available where the imagick extension is installed (the
 * Bitpalast hosting has it; a bare-PHP dev machine typically doesn't).
 */
final class PhotoProcessHandler implements JobHandlerInterface
{
    private const THUMB_MAX_EDGE = 400;
    private const WEB_MAX_EDGE = 1600;
    private const WEBP_QUALITY = 82;

    public function __construct(
        private readonly PhotoRepository $photos,
        private readonly PhotoStorage $storage,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function handle(array $payload): void
    {
        $photoId = (int) ($payload['photo_id'] ?? 0);
        $photo = $this->photos->findById($photoId);
        if ($photo === null) {
            return; // Deleted again before the job ran.
        }

        $originalPath = $this->storage->originalPath($photoId, (string) $photo['extension']);
        if (!is_file($originalPath)) {
            $this->logger->error('Photo original missing on disk', ['photo_id' => $photoId, 'path' => $originalPath]);
            $this->photos->markFailed($photoId);
            return;
        }

        try {
            $this->renderVariant($originalPath, $this->storage->derivativePath($photoId, 'thumb'), self::THUMB_MAX_EDGE);
            $webSize = $this->renderVariant($originalPath, $this->storage->derivativePath($photoId, 'web'), self::WEB_MAX_EDGE);
            $this->photos->markReady($photoId, $webSize['width'], $webSize['height']);
        } catch (\Throwable $e) {
            $this->photos->markFailed($photoId);
            throw $e; // Let the Worker's retry/backoff take over; it logs the failure.
        }
    }

    /**
     * @return array{width: int, height: int}
     */
    private function renderVariant(string $sourcePath, string $destPath, int $maxEdge): array
    {
        $image = new \Imagick($sourcePath);

        // Normalize orientation from EXIF, then strip metadata (incl. GPS) from
        // the public derivatives; the original keeps it for a later EXIF phase.
        $this->autoOrient($image);
        $image->stripImage();
        $image->setImageColorspace(\Imagick::COLORSPACE_SRGB);

        if (max($image->getImageWidth(), $image->getImageHeight()) > $maxEdge) {
            $image->resizeImage($maxEdge, $maxEdge, \Imagick::FILTER_LANCZOS, 1, true);
        }

        $image->setImageFormat('webp');
        $image->setImageCompressionQuality(self::WEBP_QUALITY);

        $dir = dirname($destPath);
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        $image->writeImage($destPath);

        $size = ['width' => $image->getImageWidth(), 'height' => $image->getImageHeight()];
        $image->destroy();

        return $size;
    }

    /**
     * Rotates the image to match its EXIF orientation and clears the tag
     * afterwards. Implemented by hand with rotateImage()/setImageOrientation()
     * rather than the newer Imagick::autoOrientImage() convenience method,
     * because that method isn't available on every imagick extension build
     * (confirmed missing on the production host). Only handles plain
     * rotation (the common case for camera photos); the rare mirrored
     * orientations are left as-is rather than adding flopImage()/flipImage()
     * branches nothing in practice produces.
     */
    private function autoOrient(\Imagick $image): void
    {
        $degrees = match ($image->getImageOrientation()) {
            \Imagick::ORIENTATION_BOTTOMRIGHT => 180,
            \Imagick::ORIENTATION_RIGHTTOP => 90,
            \Imagick::ORIENTATION_LEFTBOTTOM => -90,
            default => 0,
        };

        if ($degrees !== 0) {
            $image->rotateImage('#000000', $degrees);
        }
        $image->setImageOrientation(\Imagick::ORIENTATION_TOPLEFT);
    }
}
