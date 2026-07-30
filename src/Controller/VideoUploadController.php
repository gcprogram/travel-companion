<?php

declare(strict_types=1);

namespace App\Controller;

use App\Repository\JobRepository;
use App\Repository\VideoRepository;
use App\Service\DayEntryAccess;
use App\Service\VideoStorage;
use App\Support\Flash;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UploadedFileInterface;
use Psr\Log\LoggerInterface;

/**
 * Receives the already-compressed video (see video-compress.js — compression
 * happens entirely client-side via WebCodecs before upload) one chunk per
 * request, same reassembly approach as PhotoUploadController. Also handles
 * adding a YouTube link as a video "entry" with no file/processing at all.
 */
final class VideoUploadController
{
    // The client caps source clips at 120s and re-encodes at ~2.1 Mbps combined;
    // this leaves generous headroom for encoder variance.
    private const MAX_ORIGINAL_BYTES = 40 * 1024 * 1024;

    public function __construct(
        private readonly VideoRepository $videos,
        private readonly VideoStorage $storage,
        private readonly JobRepository $jobs,
        private readonly DayEntryAccess $access,
        private readonly Flash $flash,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function uploadChunk(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        [, $entry] = $this->access->requireEditableEntry($request, (int) $args['entryId']);

        $body = (array) $request->getParsedBody();
        $uploadId = preg_replace('/[^a-zA-Z0-9_-]/', '', (string) ($body['upload_id'] ?? '')) ?? '';
        $chunkIndex = (int) ($body['chunk_index'] ?? -1);
        $chunkCount = (int) ($body['chunk_count'] ?? 0);
        $originalName = (string) ($body['filename'] ?? 'video.mp4');

        if ($uploadId === '' || $chunkIndex < 0 || $chunkCount < 1 || $chunkIndex >= $chunkCount) {
            $this->logger->warning('Video chunk upload: invalid request', [
                'entry_id' => $entry['id'], 'chunk_index' => $chunkIndex, 'chunk_count' => $chunkCount,
            ]);
            return $this->json($response, ['error' => 'invalid_request'], 422);
        }

        $chunk = $request->getUploadedFiles()['chunk'] ?? null;
        if (!$chunk instanceof UploadedFileInterface || $chunk->getError() !== UPLOAD_ERR_OK) {
            $this->logger->warning('Video chunk upload: no/broken chunk file', [
                'entry_id' => $entry['id'],
                'upload_error' => $chunk instanceof UploadedFileInterface ? $chunk->getError() : null,
            ]);
            return $this->json($response, ['error' => 'upload_failed'], 422);
        }

        $this->storage->ensureDir($this->storage->tmpDir());
        $tmpPath = $this->storage->tmpChunkPath($uploadId);

        $handle = fopen($tmpPath, $chunkIndex === 0 ? 'wb' : 'ab');
        if ($handle === false) {
            $this->logger->error('Video chunk upload: could not open tmp file for writing', ['path' => $tmpPath]);
            return $this->json($response, ['error' => 'server_error'], 500);
        }
        $stream = $chunk->getStream()->detach();
        stream_copy_to_stream($stream, $handle);
        fclose($handle);
        if (is_resource($stream)) {
            fclose($stream);
        }

        if (filesize($tmpPath) > self::MAX_ORIGINAL_BYTES) {
            $this->logger->warning('Video chunk upload: exceeded max size', ['entry_id' => $entry['id'], 'size' => filesize($tmpPath)]);
            unlink($tmpPath);
            return $this->json($response, ['error' => 'too_large'], 413);
        }

        if ($chunkIndex < $chunkCount - 1) {
            return $this->json($response, ['status' => 'chunk_received']);
        }

        return $this->finalize($tmpPath, (int) $entry['id'], (int) $entry['trip_id'], $originalName, $body, $response);
    }

    public function addYoutube(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        [$trip, $entry] = $this->access->requireEditableEntry($request, (int) $args['entryId']);

        $body = (array) $request->getParsedBody();
        $youtubeId = $this->parseYoutubeId((string) ($body['youtube_url'] ?? ''));

        if ($youtubeId === null) {
            $this->flash->add('error', t('entry.form.video_youtube_invalid'));
            return $response->withHeader('Location', '/entries/' . $entry['id'] . '/edit')->withStatus(302);
        }

        $this->videos->createYoutube((int) $entry['id'], $youtubeId);

        $this->flash->add('success', t('flash.video_added'));
        return $response->withHeader('Location', '/entries/' . $entry['id'] . '/edit')->withStatus(302);
    }

    /**
     * @param array<string, mixed> $body
     */
    private function finalize(string $tmpPath, int $entryId, int $tripId, string $originalName, array $body, ResponseInterface $response): ResponseInterface
    {
        if (!$this->looksLikeMp4($tmpPath)) {
            $this->logger->warning('Video chunk upload: reassembled file is not a valid MP4', ['entry_id' => $entryId]);
            unlink($tmpPath);
            return $this->json($response, ['error' => 'unsupported_type'], 422);
        }

        $videoId = $this->videos->createUpload($entryId, $originalName, 'mp4');
        $this->storage->ensureDir($this->storage->directoryFor($videoId));
        rename($tmpPath, $this->storage->originalPath($videoId, 'mp4'));

        // The client already knows these from compressing the file (VideoCompress.compress());
        // the video is playable immediately, no need to wait on server-side processing for them.
        // lat/lng (if present) come from video-geotag.js reading the original's container
        // metadata before compression, since the compressed output carries none of it.
        $lat = $this->coordinateOrNull($body['lat'] ?? null, 90.0);
        $lng = $this->coordinateOrNull($body['lng'] ?? null, 180.0);

        $this->videos->markReady(
            $videoId,
            $this->positiveIntOrNull($body['width'] ?? null),
            $this->positiveIntOrNull($body['height'] ?? null),
            $this->positiveIntOrNull($body['duration'] ?? null),
            $lat,
            $lng,
        );

        // Poster thumbnail is a best-effort bonus handled async; the video itself is already usable.
        $this->jobs->dispatch('video.process', ['video_id' => $videoId]);

        if ($lat !== null && $lng !== null) {
            // Cheap no-op via PoiAssignmentService if the trip has no confirmed POIs yet.
            $this->jobs->dispatch('poi.assign', ['trip_id' => $tripId]);
        }

        return $this->json($response, ['status' => 'ready', 'video_id' => $videoId]);
    }

    private function positiveIntOrNull(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        $int = (int) round((float) $value);
        return $int > 0 ? $int : null;
    }

    private function coordinateOrNull(mixed $value, float $maxAbs): ?float
    {
        if ($value === null || $value === '' || !is_numeric($value)) {
            return null;
        }
        $float = round((float) $value, 6);
        return abs($float) <= $maxAbs ? $float : null;
    }

    /**
     * MP4 files start with a size (4 bytes) followed by the ASCII box type
     * "ftyp". Cheap, dependency-free sanity check that we actually received
     * a video our own compressor produced, not something arbitrary.
     */
    private function looksLikeMp4(string $path): bool
    {
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            return false;
        }
        $header = fread($handle, 12);
        fclose($handle);
        return $header !== false && strlen($header) >= 8 && substr($header, 4, 4) === 'ftyp';
    }

    private function parseYoutubeId(string $url): ?string
    {
        $url = trim($url);
        if ($url === '') {
            return null;
        }
        if (preg_match('~(?:youtu\.be/|youtube\.com/(?:watch\?v=|embed/|shorts/))([A-Za-z0-9_-]{11})~', $url, $m)) {
            return $m[1];
        }
        if (preg_match('/^[A-Za-z0-9_-]{11}$/', $url)) {
            return $url;
        }
        return null;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function json(ResponseInterface $response, array $data, int $status = 200): ResponseInterface
    {
        $response->getBody()->write((string) json_encode($data, JSON_THROW_ON_ERROR));
        return $response->withHeader('Content-Type', 'application/json')->withStatus($status);
    }
}
