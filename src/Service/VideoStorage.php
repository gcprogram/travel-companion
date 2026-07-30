<?php

declare(strict_types=1);

namespace App\Service;

/**
 * Computes on-disk paths for video files. Mirrors PhotoStorage; everything
 * lives under var/uploads, outside the webroot.
 */
final class VideoStorage
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

    public function directoryFor(int $videoId): string
    {
        return $this->baseDir . '/videos/' . $videoId;
    }

    public function originalPath(int $videoId, string $extension): string
    {
        return $this->directoryFor($videoId) . '/original.' . $extension;
    }

    public function posterPath(int $videoId): string
    {
        return $this->directoryFor($videoId) . '/poster.webp';
    }

    public function ensureDir(string $dir): void
    {
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
    }

    public function deleteAll(int $videoId): void
    {
        $dir = $this->directoryFor($videoId);
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
