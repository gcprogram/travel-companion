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
 * the 'web' derivative's own EXIF data (GPSLatitude/Longitude/Altitude,
 * DateTimeOriginal) — so a future trip export/download doesn't lose that
 * information just because the original is gone. GPS altitude is only
 * re-embedded (not stored in its own DB column - nothing reads it yet), kept
 * for a possible future elevation-profile feature. Everything else (camera
 * model, serial numbers, thumbnail preview, ...) is stripped for privacy.
 * 'web' is JPEG rather than WebP specifically because Imagick's EXIF write
 * support is far more reliable for JPEG output (see PhotoStorage). 'thumb'
 * stays WebP - it's a small gallery-grid image and never needs to carry
 * metadata.
 */
final class PhotoProcessHandler implements JobHandlerInterface
{
    private const THUMB_MAX_EDGE = 400;
    // 1600/85 measured close to ~1 MB per photo on real uploads (Stefan,
    // 2026-08-13) - well above the roadmap's ~1/10-of-original target.
    // 1280/78 + chroma subsampling below cut a synthetic detailed test
    // image (4000x3000, noise-heavy - no real camera photo was on hand
    // locally) from 496 KB to 169 KB at these same two settings, ~66%
    // smaller, still fully viewable on any screen. Real phone photos will
    // land somewhere else since they compress differently than synthetic
    // noise - worth re-checking actual sizes on a real upload once deployed.
    private const WEB_MAX_EDGE = 1280;
    private const THUMB_QUALITY = 82;
    private const WEB_QUALITY = 78;

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
                $this->dispatchTripWideFollowUps((int) $photo['day_entry_id']);
                $this->jobs->dispatch('entry.locate', ['day_entry_id' => (int) $photo['day_entry_id']]);
            }
        } catch (\Throwable $e) {
            $this->photos->markFailed($photoId);
            throw $e; // Let the Worker's retry/backoff take over; it logs the failure.
        }
    }

    /**
     * All cheap no-ops when there's nothing new for them to do
     * (PoiAssignmentService when the trip has no confirmed POIs yet,
     * PhotoTrackGapFillService when the photo doesn't fall in a gap,
     * TripMetadataAutoFillHandler when country/dates are already filled) —
     * dispatched unconditionally rather than checking first, to avoid this
     * job needing its own PoiRepository/TrackRepository/TripRepository
     * dependencies.
     */
    private function dispatchTripWideFollowUps(int $dayEntryId): void
    {
        $entry = $this->entries->findById($dayEntryId);
        if ($entry === null) {
            return;
        }
        $tripId = (int) $entry['trip_id'];
        $this->jobs->dispatch('poi.assign', ['trip_id' => $tripId]);
        $this->jobs->dispatch('track.gapfill', ['trip_id' => $tripId]);
        $this->jobs->dispatch('trip.metadata_refresh', ['trip_id' => $tripId]);
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
        if ($format === 'jpeg') {
            // 4:2:0 chroma subsampling - halves the stored color resolution
            // (luminance stays full-res), the standard web-JPEG tradeoff:
            // a further meaningful size cut with no visible effect on
            // photographic content. WebP (thumb) subsamples internally
            // already, no equivalent Imagick setting needed there.
            $image->setSamplingFactors(['2x2', '1x1', '1x1']);
        }

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
     * future trip export/download). Wrapped so that if anything here goes
     * wrong, processing still succeeds - the photo just falls back to the
     * DB columns for map/track display, same as before this existed.
     *
     * Builds the raw EXIF/GPS IFD block by hand (setImageProfile()) rather
     * than Imagick's exif:* setImageProperty() calls: confirmed by direct
     * testing (both via the PHP binding and the `magick` CLI with -set) that
     * ImageMagick only patches values into an EXIF profile that already
     * exists on the image - it never synthesizes one from scratch, which is
     * exactly the case here since stripImage() just removed the original's
     * profile. setImageProperty() calls on a profile-less image are
     * silently dropped on write; no error, no warning, just gone.
     *
     * @param array{lat: ?float, lng: ?float, takenAt: ?string, altitude: ?float} $meta
     */
    private function embedMetadata(\Imagick $image, array $meta): void
    {
        try {
            $blob = $this->buildExifBlob($meta['lat'], $meta['lng'], $meta['takenAt'], $meta['altitude']);
            if ($blob !== null) {
                // setImageProfile() writes the buffer as the literal APP1
                // payload - it does NOT add the "Exif\0\0" identifier itself,
                // even though that's mandatory for an APP1 segment to count
                // as Exif data. Without it, Imagick's own (lenient) reader
                // still finds the data by recognizing the TIFF header
                // ("II*\0"/"MM\0*") directly, but PHP's exif_read_data() -
                // and, per Stefan, at least one external EXIF tool - refuses
                // to recognize the segment at all. Confirmed by roundtrip
                // testing against a real processed photo from production.
                $image->setImageProfile('exif', "Exif\x00\x00" . $blob);
            }
        } catch (\Throwable $e) {
            $this->logger->warning('Photo EXIF re-embedding failed, continuing without it', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Hand-rolled minimal TIFF/EXIF structure: little-endian header, an IFD0
     * with pointers to whichever of the Exif SubIFD (DateTimeOriginal) and
     * GPS IFD (lat/lng/refs/altitude) are present, and the tag data itself.
     * Returns null when there's nothing to embed. $altitude is ignored
     * without lat/lng (it lives inside the GPS IFD, which wouldn't exist).
     */
    private function buildExifBlob(?float $lat, ?float $lng, ?string $takenAt, ?float $altitude = null): ?string
    {
        $hasGps = $lat !== null && $lng !== null;
        $hasTime = $takenAt !== null;
        $hasAltitude = $hasGps && $altitude !== null;
        if (!$hasGps && !$hasTime) {
            return null;
        }

        $ifd0EntryCount = ($hasTime ? 1 : 0) + ($hasGps ? 1 : 0);
        $ifd0Size = 2 + $ifd0EntryCount * 12 + 4;
        $offExifIfd = 8 + $ifd0Size;
        $exifIfdSize = $hasTime ? (2 + 1 * 12 + 4) : 0;
        $offGpsIfd = $offExifIfd + $exifIfdSize;
        $gpsEntryCount = $hasGps ? (4 + ($hasAltitude ? 2 : 0)) : 0;
        $gpsIfdSize = $hasGps ? (2 + $gpsEntryCount * 12 + 4) : 0;
        $offExternal = $offGpsIfd + $gpsIfdSize;

        $dtBytes = '';
        $offDt = 0;
        if ($hasTime) {
            $dtBytes = str_replace('-', ':', substr((string) $takenAt, 0, 10)) . substr((string) $takenAt, 10) . "\0";
            $offDt = $offExternal;
        }

        $latRational = '';
        $lngRational = '';
        $altRational = '';
        $offLat = 0;
        $offLng = 0;
        $offAlt = 0;
        if ($hasGps) {
            $offLat = $offExternal + strlen($dtBytes);
            $latRational = $this->rationalTriplet($this->decimalToDms((float) $lat));
            $offLng = $offLat + strlen($latRational);
            $lngRational = $this->rationalTriplet($this->decimalToDms((float) $lng));
            if ($hasAltitude) {
                $offAlt = $offLng + strlen($lngRational);
                $altRational = pack('VV', (int) round(abs((float) $altitude) * 10), 10);
            }
        }

        $ifd0 = pack('v', $ifd0EntryCount);
        if ($hasTime) {
            $ifd0 .= pack('vvV', 0x8769, 4, 1) . pack('V', $offExifIfd);
        }
        if ($hasGps) {
            $ifd0 .= pack('vvV', 0x8825, 4, 1) . pack('V', $offGpsIfd);
        }
        $ifd0 .= pack('V', 0);

        $exifIfd = '';
        if ($hasTime) {
            $exifIfd = pack('v', 1)
                . pack('vvV', 0x9003, 2, strlen($dtBytes)) . pack('V', $offDt)
                . pack('V', 0);
        }

        $gpsIfd = '';
        if ($hasGps) {
            $gpsIfd = pack('v', $gpsEntryCount)
                . pack('vvV', 0x0001, 2, 2) . str_pad($lat >= 0 ? 'N' : 'S', 4, "\0")
                . pack('vvV', 0x0002, 5, 3) . pack('V', $offLat)
                . pack('vvV', 0x0003, 2, 2) . str_pad($lng >= 0 ? 'E' : 'W', 4, "\0")
                . pack('vvV', 0x0004, 5, 3) . pack('V', $offLng);
            if ($hasAltitude) {
                $gpsIfd .= pack('vvV', 0x0005, 1, 1) . str_pad(pack('C', $altitude < 0 ? 1 : 0), 4, "\0")
                    . pack('vvV', 0x0006, 5, 1) . pack('V', $offAlt);
            }
            $gpsIfd .= pack('V', 0);
        }

        $header = "II" . pack('v', 42) . pack('V', 8);

        return $header . $ifd0 . $exifIfd . $gpsIfd . $dtBytes . $latRational . $lngRational . $altRational;
    }

    /**
     * @param array{0: string, 1: string, 2: string} $dms "D/1", "M/1", "S/100" strings from decimalToDms()
     */
    private function rationalTriplet(array $dms): string
    {
        $bytes = '';
        foreach ($dms as $part) {
            [$num, $den] = explode('/', $part);
            $bytes .= pack('VV', (int) $num, (int) $den);
        }
        return $bytes;
    }

    /**
     * Best-effort GPS + capture-time extraction from EXIF (JPEG/TIFF only —
     * the exif extension doesn't read these out of PNG/WebP, which is fine
     * since real camera/phone photos are essentially always JPEG). Returns
     * nulls rather than throwing when absent or unparsable; missing
     * metadata is not a processing failure.
     *
     * @return array{lat: ?float, lng: ?float, takenAt: ?string, altitude: ?float}
     */
    private function extractMetadata(string $path): array
    {
        $result = ['lat' => null, 'lng' => null, 'takenAt' => null, 'altitude' => null];
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
            $result['takenAt'] = $this->parseExifDateTime($dateTimeOriginal, $this->readUtcOffset($path));
        }

        if (isset($exif['GPSAltitude'])) {
            $altitude = $this->fractionToFloat((string) $exif['GPSAltitude']);
            if ($altitude !== null) {
                // Ref byte: 0 = above sea level, 1 = below. PHP's exif
                // extension surfaces it as a raw single-byte string.
                $belowSeaLevel = isset($exif['GPSAltitudeRef']) && ord((string) $exif['GPSAltitudeRef']) === 1;
                $result['altitude'] = round($belowSeaLevel ? -$altitude : $altitude, 1);
            }
        }

        return $result;
    }

    /**
     * EXIF DateTimeOriginal ("YYYY:MM:DD HH:MM:SS") is always local time
     * with no timezone info of its own - unlike GPX <time>, which is always
     * UTC per the GPX spec. Storing it as-is used to mean a photo's
     * taken_at was actually local time masquerading as UTC: on a trip that
     * crosses a timezone (or just isn't in UTC+0), that put photos hours
     * off from where they really belonged in a track's chronological order,
     * which PhotoTrackGapFillService then wove in at the wrong spot -
     * visible on the map as straight lines jumping between unrelated
     * parts of the route (reported by Stefan, 2026-08-14). $offset (from
     * readUtcOffset(), "+02:00" style) corrects for that when available;
     * without one this falls back to the previous best-effort behaviour.
     */
    private function parseExifDateTime(string $value, ?string $offset = null): ?string
    {
        $value = trim($value);
        if ($offset !== null) {
            $normalizedOffset = preg_replace('/^([+-]\d{2})(\d{2})$/', '$1:$2', trim($offset));
            if (is_string($normalizedOffset) && preg_match('/^[+-]\d{2}:\d{2}$/', $normalizedOffset)) {
                $dt = \DateTimeImmutable::createFromFormat('Y:m:d H:i:sP', $value . $normalizedOffset);
                if ($dt !== false) {
                    return $dt->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
                }
            }
        }

        $dt = \DateTimeImmutable::createFromFormat('Y:m:d H:i:s', $value);
        return $dt !== false ? $dt->format('Y-m-d H:i:s') : null;
    }

    /**
     * PHP's exif_read_data() doesn't expose OffsetTime/OffsetTimeOriginal
     * (the EXIF 2.31+ tags that say what UTC offset DateTimeOriginal's
     * local time is in) at all - confirmed by direct testing against a
     * tagged file, its hardcoded tag table predates them. Imagick's own
     * EXIF reader does support them, so this opens the file a second time
     * just for that. Best-effort: a read failure here just means the
     * offset stays unknown, same as always - never worth failing photo
     * processing over.
     */
    private function readUtcOffset(string $path): ?string
    {
        try {
            $img = new \Imagick($path);
            $offset = $img->getImageProperty('exif:OffsetTimeOriginal');
            if ($offset === false || $offset === '') {
                $offset = $img->getImageProperty('exif:OffsetTime');
            }
            $img->clear();
            return ($offset !== false && $offset !== '') ? $offset : null;
        } catch (\Throwable) {
            return null;
        }
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
