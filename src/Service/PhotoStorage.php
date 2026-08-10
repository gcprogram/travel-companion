<?php

declare(strict_types=1);

namespace App\Service;

/**
 * Computes on-disk paths for photo files. Everything lives under
 * var/uploads, a sibling of public/ — outside the webroot, so files are
 * only reachable through PhotoController's permission-checked routes.
 */
final class PhotoStorage
{
    public function __construct(private readonly string $baseDir)
    {
    }

    public function tmpDir(): string
    {
        return $this->baseDir . '/tmp';
    }

    public function tmpChunkPath(string $uploadId): string
    {
        return $this->tmpDir() . '/' . $uploadId . '.part';
    }

    public function directoryFor(int $photoId): string
    {
        return $this->baseDir . '/photos/' . $photoId;
    }

    public function originalPath(int $photoId, string $extension): string
    {
        return $this->directoryFor($photoId) . '/original.' . $extension;
    }

    /**
     * 'web' is JPEG (not WebP) so GPS/capture-time EXIF can be written back
     * into it reliably (see PhotoProcessHandler) — it's the file that
     * survives once the original is deleted after processing. 'thumb' stays
     * WebP: small gallery grid image, never needs to carry metadata.
     */
    public function derivativePath(int $photoId, string $variant): string
    {
        return $this->directoryFor($photoId) . '/' . $variant . '.' . ($variant === 'web' ? 'jpg' : 'webp');
    }

    /**
     * Photos processed before the JPEG+EXIF switch still have their 'web'
     * derivative as .webp on disk - PhotoController falls back to this path
     * when the new one doesn't exist, so old photos keep working without a
     * reprocessing/backfill pass.
     */
    public function legacyDerivativePath(int $photoId, string $variant): string
    {
        return $this->directoryFor($photoId) . '/' . $variant . '.webp';
    }

    public function ensureDir(string $dir): void
    {
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
    }

    /**
     * Removes a photo's whole directory (original + derivatives).
     */
    public function deleteAll(int $photoId): void
    {
        $dir = $this->directoryFor($photoId);
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $file) {
            if ($file !== '.' && $file !== '..') {
                unlink($dir . '/' . $file);
            }
        }
        rmdir($dir);
    }
}
