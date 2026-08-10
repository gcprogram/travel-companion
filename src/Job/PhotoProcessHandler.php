<?php

declare(strict_types=1);

namespace App\Job;

use App\Repository\DayEntryRepository;
use App\Repository\JobRepository;
use App\Repository\PhotoRepository;
use App\Service\PhotoStorage;
use Psr\Log\LoggerInterface;

/**
 * Job type "photo.process". Payload: {"photo_id": int}.
 * Generates the thumb/web derivatives from the uploaded original via
 * Imagick, then deletes the original — only the two small derivatives are
 * kept on disk (and count against storage quota), not the multi-MB
 * camera/phone original. Only available where the imagick extension is
 * installed (the Bitpalast hosting has it; a bare-PHP dev machine typically
 * doesn't).
 *
 * GPS coordinates and capture time (EXIF DateTimeOriginal) are extracted
 * from the original before it's discarded, stored in their own columns for
 * route/POI matching and chronological ordering, and also written back into
 * the 'web' derivative's own EXIF data (GPSLatitude/Longitude,
 * DateTimeOriginal) — so a future trip export/download doesn't lose that
 * information just because the original is gone. Everything else (camera
 * model, serial numbers, thumbnail preview, ...) is stripped for privacy.
 * 'web' is JPEG rather than WebP specifically because Imagick's EXIF write
 * support is far more reliable for JPEG output (see PhotoStorage). 'thumb'
 * stays WebP - it's a small gallery-grid image and never needs to carry
 * metadata.
 */
final class PhotoProcessHandler implements JobHandlerInterface
{
    private const THUMB_MAX_EDGE = 400;
    private const WEB_MAX_EDGE = 1600;
    private const THUMB_QUALITY = 82;
    private const WEB_QUALITY = 85;

    public function __construct(
        private readonly PhotoRepository $photos,
        private readonly PhotoStorage $storage,
        private readonly DayEntryRepository $entries,
        private readonly JobRepository $jobs,
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
            $meta = $this->extractMetadata($originalPath);

            $thumbPath = $this->storage->derivativePath($photoId, 'thumb');
            $webPath = $this->storage->derivativePath($photoId, 'web');
            $this->renderVariant($originalPath, $thumbPath, self::THUMB_MAX_EDGE, 'webp', self::THUMB_QUALITY, null);
            $webSize = $this->renderVariant($originalPath, $webPath, self::WEB_MAX_EDGE, 'jpeg', self::WEB_QUALITY, $meta);

            $this->photos->markReady(
                $photoId,
                $webSize['width'],
                $webSize['height'],
                $meta['lat'],
                $meta['lng'],
                $meta['takenAt'],
            );
            // finalize() only knew the original's size; now the derivatives
            // exist too, so the quota-relevant total is the sum of both -
            // the original itself is deleted right below, once both
            // derivatives (which carry the GPS/time metadata forward) exist.
            $this->photos->updateBytes($photoId, (int) filesize($thumbPath) + (int) filesize($webPath));
            @unlink($originalPath);

            if ($meta['lat'] !== null) {
                $this->dispatchPoiAssignment((int) $photo['day_entry_id']);
            }
        } catch (\Throwable $e) {
            $this->photos->markFailed($photoId);
            throw $e; // Let the Worker's retry/backoff take over; it logs the failure.
        }
    }

    /**
     * A cheap no-op via PoiAssignmentService when the trip has no confirmed
     * POIs yet — dispatched unconditionally rather than checking first, to
     * avoid this job needing its own PoiRepository dependency.
     */
    private function dispatchPoiAssignment(int $dayEntryId): void
    {
        $entry = $this->entries->findById($dayEntryId);
        if ($entry === null) {
            return;
        }
        $this->jobs->dispatch('poi.assign', ['trip_id' => (int) $entry['trip_id']]);
    }

    /**
     * @param array{lat: ?float, lng: ?float, takenAt: ?string}|null $meta
     *        Passed (non-null) only for the 'web' variant - GPS/capture time
     *        get written back into its EXIF. 'thumb' never carries metadata.
     * @return array{width: int, height: int}
     */
    private function renderVariant(
        string $sourcePath,
        string $destPath,
        int $maxEdge,
        string $format,
        int $quality,
        ?array $meta,
    ): array {
        $image = new \Imagick($sourcePath);

        // Normalize orientation from EXIF, then strip ALL metadata (camera
        // model/serial, embedded preview thumbnail, maker notes, ...) for
        // privacy/size. GPS + capture time get explicitly re-added below,
        // for 'web' only - the only variant kept once the original is gone.
        $this->autoOrient($image);
        $image->stripImage();
        $image->setImageColorspace(\Imagick::COLORSPACE_SRGB);

        if (max($image->getImageWidth(), $image->getImageHeight()) > $maxEdge) {
            $image->resizeImage($maxEdge, $maxEdge, \Imagick::FILTER_LANCZOS, 1, true);
        }

        $image->setImageFormat($format);
        $image->setImageCompressionQuality($quality);

        if ($meta !== null) {
            $this->embedMetadata($image, $meta);
        }

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
     * Best-effort: writes GPS + DateTimeOriginal back into the image's own
     * EXIF data so they survive independently of the database (needed for a
     * future trip export/download). Wrapped so that if a particular
     * Imagick/libjpeg build can't write one of these properties, processing
     * still succeeds - the photo just falls back to the DB columns for
     * map/track display, same as before this existed.
     *
     * @param array{lat: ?float, lng: ?float, takenAt: ?string} $meta
     */
    private function embedMetadata(\Imagick $image, array $meta): void
    {
        try {
            if ($meta['lat'] !== null && $meta['lng'] !== null) {
                $lat = $this->decimalToDms($meta['lat']);
                $lng = $this->decimalToDms($meta['lng']);
                $image->setImageProperty('exif:GPSLatitudeRef', $meta['lat'] >= 0 ? 'N' : 'S');
                $image->setImageProperty('exif:GPSLatitude', implode(',', $lat));
                $image->setImageProperty('exif:GPSLongitudeRef', $meta['lng'] >= 0 ? 'E' : 'W');
                $image->setImageProperty('exif:GPSLongitude', implode(',', $lng));
            }
            if ($meta['takenAt'] !== null) {
                $exifDate = str_replace('-', ':', substr($meta['takenAt'], 0, 10)) . substr($meta['takenAt'], 10);
                $image->setImageProperty('exif:DateTimeOriginal', $exifDate);
            }
        } catch (\Throwable $e) {
            $this->logger->warning('Photo EXIF re-embedding failed, continuing without it', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Best-effort GPS + capture-time extraction from EXIF (JPEG/TIFF only —
     * the exif extension doesn't read these out of PNG/WebP, which is fine
     * since real camera/phone photos are essentially always JPEG). Returns
     * nulls rather than throwing when absent or unparsable; missing
     * metadata is not a processing failure.
     *
     * @return array{lat: ?float, lng: ?float, takenAt: ?string}
     */
    private function extractMetadata(string $path): array
    {
        $result = ['lat' => null, 'lng' => null, 'takenAt' => null];
        if (!function_exists('exif_read_data')) {
            return $result;
        }

        $exif = @exif_read_data($path);
        if ($exif === false) {
            return $result;
        }

        if (
            isset($exif['GPSLatitude'], $exif['GPSLongitude'], $exif['GPSLatitudeRef'], $exif['GPSLongitudeRef'])
            && is_array($exif['GPSLatitude'])
            && is_array($exif['GPSLongitude'])
        ) {
            $lat = $this->dmsToDecimal($exif['GPSLatitude'], (string) $exif['GPSLatitudeRef']);
            $lng = $this->dmsToDecimal($exif['GPSLongitude'], (string) $exif['GPSLongitudeRef']);
            if ($lat !== null && $lng !== null) {
                $result['lat'] = round($lat, 6);
                $result['lng'] = round($lng, 6);
            }
        }

        $dateTimeOriginal = $exif['EXIF']['DateTimeOriginal'] ?? $exif['DateTimeOriginal'] ?? null;
        if (is_string($dateTimeOriginal)) {
            $result['takenAt'] = $this->parseExifDateTime($dateTimeOriginal);
        }

        return $result;
    }

    private function parseExifDateTime(string $value): ?string
    {
        // EXIF datetime format: "YYYY:MM:DD HH:MM:SS".
        $dt = \DateTimeImmutable::createFromFormat('Y:m:d H:i:s', $value);
        return $dt !== false ? $dt->format('Y-m-d H:i:s') : null;
    }

    /**
     * Inverse of dmsToDecimal(): decimal degrees -> three EXIF rationals
     * ("D/1", "M/1", "S/100"), as Imagick::setImageProperty() expects for
     * exif:GPSLatitude/GPSLongitude.
     *
     * @return array{0: string, 1: string, 2: string}
     */
    private function decimalToDms(float $decimal): array
    {
        $decimal = abs($decimal);
        $degrees = (int) floor($decimal);
        $minutesFull = ($decimal - $degrees) * 60;
        $minutes = (int) floor($minutesFull);
        $seconds = ($minutesFull - $minutes) * 60;

        return [$degrees . '/1', $minutes . '/1', (string) round($seconds * 100) . '/100'];
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
