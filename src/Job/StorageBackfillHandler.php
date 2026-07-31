<?php

declare(strict_types=1);

namespace App\Job;

use App\Repository\PhotoRepository;
use App\Repository\VideoRepository;
use App\Service\PhotoStorage;
use App\Service\VideoStorage;
use Psr\Log\LoggerInterface;

/**
 * Job type "storage.backfill". Payload: {} (idempotent, whole-table).
 * One-shot: enqueued by migration 0016 to fill the new photos.bytes /
 * videos.bytes columns for media uploaded before size tracking existed.
 * Sums every file in the item's directory (original + derivatives), which
 * is exactly what quota accounting counts. Safe to re-run: only touches
 * rows where bytes IS NULL.
 */
final class StorageBackfillHandler implements JobHandlerInterface
{
    public function __construct(
        private readonly PhotoRepository $photos,
        private readonly VideoRepository $videos,
        private readonly PhotoStorage $photoStorage,
        private readonly VideoStorage $videoStorage,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function handle(array $payload): void
    {
        $count = 0;
        foreach ($this->photos->findIdsWithoutBytes() as $id) {
            $this->photos->updateBytes($id, $this->directoryBytes($this->photoStorage->directoryFor($id)));
            $count++;
        }
        foreach ($this->videos->findIdsWithoutBytes() as $id) {
            $this->videos->updateBytes($id, $this->directoryBytes($this->videoStorage->directoryFor($id)));
            $count++;
        }

        $this->logger->info('Storage byte backfill finished', ['rows' => $count]);
    }

    private function directoryBytes(string $dir): int
    {
        if (!is_dir($dir)) {
            return 0; // File already gone - record 0 so the row stops matching.
        }
        $total = 0;
        foreach (scandir($dir) ?: [] as $file) {
            $path = $dir . '/' . $file;
            if ($file !== '.' && $file !== '..' && is_file($path)) {
                $total += (int) filesize($path);
            }
        }
        return $total;
    }
}
