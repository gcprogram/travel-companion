<?php

declare(strict_types=1);

namespace App\Job;

use App\Repository\VideoRepository;
use App\Service\VideoStorage;
use Psr\Log\LoggerInterface;

/**
 * Job type "video.process". Payload: {"video_id": int}.
 *
 * The video itself is already marked ready at upload time (the client
 * supplies width/height/duration from compressing it, and the file was
 * sanity-checked to actually be an MP4) — this job only adds a poster
 * thumbnail, extracted via Imagick's video frame support. CLAUDE.md flags
 * that support as untested on the production host, so this must degrade
 * gracefully: no poster is a cosmetic gap, not a broken video, so failures
 * here are logged and swallowed rather than retried/failed like other jobs.
 */
final class VideoProcessHandler implements JobHandlerInterface
{
    private const POSTER_MAX_EDGE = 800;

    public function __construct(
        private readonly VideoRepository $videos,
        private readonly VideoStorage $storage,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function handle(array $payload): void
    {
        $videoId = (int) ($payload['video_id'] ?? 0);
        $video = $this->videos->findById($videoId);
        if ($video === null || $video['type'] !== 'upload') {
            return;
        }

        $originalPath = $this->storage->originalPath($videoId, (string) $video['extension']);
        if (!is_file($originalPath)) {
            return;
        }

        try {
            $posterPath = $this->storage->posterPath($videoId);
            $this->extractPoster($originalPath, $posterPath);
            // finalize() only recorded the original's size; the poster adds
            // to the quota-relevant total once it exists.
            if (is_file($posterPath)) {
                $this->videos->updateBytes($videoId, (int) filesize($originalPath) + (int) filesize($posterPath));
            }
        } catch (\Throwable $e) {
            $this->logger->warning('Video poster extraction failed (non-fatal, video stays usable)', [
                'video_id' => $videoId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function extractPoster(string $sourcePath, string $destPath): void
    {
        // "[0]" selects just the first frame instead of reading the whole video.
        $image = new \Imagick($sourcePath . '[0]');
        $image->setImageColorspace(\Imagick::COLORSPACE_SRGB);

        if (max($image->getImageWidth(), $image->getImageHeight()) > self::POSTER_MAX_EDGE) {
            $image->resizeImage(self::POSTER_MAX_EDGE, self::POSTER_MAX_EDGE, \Imagick::FILTER_LANCZOS, 1, true);
        }

        $image->setImageFormat('webp');
        $image->setImageCompressionQuality(82);

        $dir = dirname($destPath);
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        $image->writeImage($destPath);
        $image->destroy();
    }
}
