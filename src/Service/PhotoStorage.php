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

    public function derivativePath(int $photoId, string $variant): string
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
