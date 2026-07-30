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
 *
 * Also extracts GPS coordinates from the original's EXIF data — the
 * derivatives get stripImage()'d for privacy/size, but the location itself
 * is important for later route/POI matching, so it's pulled out into its
 * own columns before that metadata is gone from the images we actually
 * hand out.
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
            $gps = $this->extractGps($originalPath);
            $this->photos->markReady($photoId, $webSize['width'], $webSize['height'], $gps['lat'] ?? null, $gps['lng'] ?? null);
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
     * Best-effort GPS extraction from EXIF (JPEG/TIFF only — the exif
     * extension doesn't read GPS out of PNG/WebP, which is fine since real
     * camera/phone photos are essentially always JPEG). Returns null rather
     * than throwing when absent or unparsable; a missing location is not
     * a processing failure.
     *
     * @return array{lat: float, lng: float}|null
     */
    private function extractGps(string $path): ?array
    {
        if (!function_exists('exif_read_data')) {
            return null;
        }

        $exif = @exif_read_data($path);
        if (
            $exif === false
            || !isset($exif['GPSLatitude'], $exif['GPSLongitude'], $exif['GPSLatitudeRef'], $exif['GPSLongitudeRef'])
            || !is_array($exif['GPSLatitude'])
            || !is_array($exif['GPSLongitude'])
        ) {
            return null;
        }

        $lat = $this->dmsToDecimal($exif['GPSLatitude'], (string) $exif['GPSLatitudeRef']);
        $lng = $this->dmsToDecimal($exif['GPSLongitude'], (string) $exif['GPSLongitudeRef']);
        if ($lat === null || $lng === null) {
            return null;
        }

        return ['lat' => round($lat, 6), 'lng' => round($lng, 6)];
    }

    /**
     * @param array<int, string> $dms Three "numerator/denominator" strings: degrees, minutes, seconds.
     */
    private function dmsToDecimal(array $dms, string $ref): ?float
    {
        if (count($dms) !== 3) {
            return null;
        }

        $degrees = $this->fractionToFloat((string) $dms[0]);
        $minutes = $this->fractionToFloat((string) $dms[1]);
        $seconds = $this->fractionToFloat((string) $dms[2]);
        if ($degrees === null || $minutes === null || $seconds === null) {
            return null;
        }

        $decimal = $degrees + ($minutes / 60) + ($seconds / 3600);
        return in_array(strtoupper($ref), ['S', 'W'], true) ? -$decimal : $decimal;
    }

    private function fractionToFloat(string $fraction): ?float
    {
        $parts = explode('/', $fraction);
        if (count($parts) === 1) {
            return is_numeric($parts[0]) ? (float) $parts[0] : null;
        }
        if (count($parts) !== 2 || !is_numeric($parts[0]) || !is_numeric($parts[1]) || (float) $parts[1] === 0.0) {
            return null;
        }
        return (float) $parts[0] / (float) $parts[1];
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
