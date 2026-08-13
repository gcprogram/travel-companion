<?php

declare(strict_types=1);

namespace App\Controller;

use App\Repository\DayEntryRepository;
use App\Repository\TripRepository;
use App\Repository\VideoRepository;
use App\Service\DayEntryAccess;
use App\Service\TripAccess;
use App\Service\VideoStorage;
use App\Support\Flash;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Exception\HttpNotFoundException;

final class VideoController
{
    public function __construct(
        private readonly VideoRepository $videos,
        private readonly DayEntryRepository $entries,
        private readonly TripRepository $trips,
        private readonly TripAccess $tripAccess,
        private readonly DayEntryAccess $entryAccess,
        private readonly VideoStorage $storage,
        private readonly Flash $flash,
    ) {
    }

    /**
     * Serves the uploaded video with HTTP Range support — browsers rely on
     * 206 Partial Content responses to seek within a <video>, not just to
     * play it start to finish.
     */
    public function show(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $video = $this->requireViewableUpload($request, (int) $args['id']);

        $path = $this->storage->originalPath($this->storageId($video), (string) $video['extension']);
        if (!is_file($path)) {
            throw new HttpNotFoundException($request);
        }

        return $this->streamFile($request, $response, $path, 'video/mp4');
    }

    public function poster(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $video = $this->requireViewableUpload($request, (int) $args['id']);

        $path = $this->storage->posterPath($this->storageId($video));
        if (!is_file($path)) {
            throw new HttpNotFoundException($request);
        }

        $response->getBody()->write((string) file_get_contents($path));
        return $response
            ->withHeader('Content-Type', 'image/webp')
            ->withHeader('Cache-Control', 'private, max-age=86400');
    }

    public function delete(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $video = $this->videos->findById((int) $args['id']);
        if ($video === null) {
            throw new HttpNotFoundException($request);
        }

        [, $entry] = $this->entryAccess->requireEditableEntry($request, (int) $video['day_entry_id']);

        $storageId = $this->storageId($video);
        $stillReferenced = $video['type'] === 'upload'
            && $this->videos->countReferencingStorage($storageId, (int) $video['id']) > 0;

        $this->videos->delete((int) $video['id']);
        if ($video['type'] === 'upload' && !$stillReferenced) {
            $this->storage->deleteAll($storageId);
        }

        $this->flash->add('success', t('flash.video_deleted'));
        return $response->withHeader('Location', '/entries/' . $entry['id'] . '/edit')->withStatus(302);
    }

    /**
     * A reference (see migration 0019) owns no files of its own - its bytes
     * live under the video it duplicates.
     *
     * @param array<string, mixed> $video
     */
    private function storageId(array $video): int
    {
        return $video['source_video_id'] !== null ? (int) $video['source_video_id'] : (int) $video['id'];
    }

    /**
     * @return array<string, mixed>
     */
    private function requireViewableUpload(ServerRequestInterface $request, int $videoId): array
    {
        $video = $this->videos->findById($videoId);
        if ($video === null || $video['type'] !== 'upload' || $video['status'] !== 'ready') {
            throw new HttpNotFoundException($request);
        }

        $entry = $this->entries->findById((int) $video['day_entry_id']);
        $trip = $entry !== null ? $this->trips->findById((int) $entry['trip_id']) : null;
        if ($trip === null || !$this->tripAccess->canView($trip, $request->getAttribute('user'), $request)) {
            // Treat videos on a private trip as "doesn't exist" for strangers, same as everything else.
            throw new HttpNotFoundException($request);
        }

        return $video;
    }

    private function streamFile(
        ServerRequestInterface $request,
        ResponseInterface $response,
        string $path,
        string $contentType,
    ): ResponseInterface {
        $size = filesize($path);
        $response = $response
            ->withHeader('Content-Type', $contentType)
            ->withHeader('Accept-Ranges', 'bytes')
            ->withHeader('Cache-Control', 'private, max-age=86400');

        $rangeHeader = $request->getHeaderLine('Range');
        if ($rangeHeader === '') {
            $response->getBody()->write((string) file_get_contents($path));
            return $response->withHeader('Content-Length', (string) $size);
        }

        $range = $this->parseRange($rangeHeader, $size);
        if ($range === null) {
            return $response->withStatus(416)->withHeader('Content-Range', "bytes */{$size}");
        }

        [$start, $end] = $range;
        $length = $end - $start + 1;

        $handle = fopen($path, 'rb');
        fseek($handle, $start);
        $data = fread($handle, $length);
        fclose($handle);

        $response->getBody()->write((string) $data);
        return $response
            ->withStatus(206)
            ->withHeader('Content-Range', "bytes {$start}-{$end}/{$size}")
            ->withHeader('Content-Length', (string) $length);
    }

    /**
     * Parses a "Range: bytes=..." header. Only single-range requests are
     * supported (the only kind browsers send for <video> seeking) — start-end,
     * start-, and -suffixLength forms.
     *
     * @return array{0: int, 1: int}|null
     */
    private function parseRange(string $header, int $size): ?array
    {
        if (!str_starts_with($header, 'bytes=')) {
            return null;
        }
        $spec = trim(explode(',', substr($header, 6))[0]);
        if (!preg_match('/^(\d*)-(\d*)$/', $spec, $m) || ($m[1] === '' && $m[2] === '')) {
            return null;
        }

        if ($m[1] === '') {
            $start = max(0, $size - (int) $m[2]);
            $end = $size - 1;
        } else {
            $start = (int) $m[1];
            $end = $m[2] === '' ? $size - 1 : min((int) $m[2], $size - 1);
        }

        return ($start <= $end && $start < $size) ? [$start, $end] : null;
    }
}
