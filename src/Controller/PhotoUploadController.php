<?php

declare(strict_types=1);

namespace App\Controller;

use App\Repository\JobRepository;
use App\Repository\PhotoRepository;
use App\Service\DayEntryAccess;
use App\Service\PhotoStorage;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UploadedFileInterface;

/**
 * Receives one chunk per request (client slices the file, see
 * photo-upload.js) and reassembles it server-side. Chunking exists because
 * the host caps a single request body at post_max_size=20M; the reassembled
 * original itself can be larger, up to MAX_ORIGINAL_BYTES.
 */
final class PhotoUploadController
{
    private const MAX_ORIGINAL_BYTES = 25 * 1024 * 1024;
    private const ALLOWED_EXTENSIONS = ['jpg', 'jpeg', 'png', 'webp'];

    public function __construct(
        private readonly PhotoRepository $photos,
        private readonly PhotoStorage $storage,
        private readonly JobRepository $jobs,
        private readonly DayEntryAccess $access,
    ) {
    }

    public function uploadChunk(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        [, $entry] = $this->access->requireEditableEntry($request, (int) $args['entryId']);

        $body = (array) $request->getParsedBody();
        $uploadId = preg_replace('/[^a-zA-Z0-9_-]/', '', (string) ($body['upload_id'] ?? '')) ?? '';
        $chunkIndex = (int) ($body['chunk_index'] ?? -1);
        $chunkCount = (int) ($body['chunk_count'] ?? 0);
        $originalName = (string) ($body['filename'] ?? 'photo');

        if ($uploadId === '' || $chunkIndex < 0 || $chunkCount < 1 || $chunkIndex >= $chunkCount) {
            return $this->json($response, ['error' => 'invalid_request'], 422);
        }

        $chunk = $request->getUploadedFiles()['chunk'] ?? null;
        if (!$chunk instanceof UploadedFileInterface || $chunk->getError() !== UPLOAD_ERR_OK) {
            return $this->json($response, ['error' => 'upload_failed'], 422);
        }

        $this->storage->ensureDir($this->storage->tmpDir());
        $tmpPath = $this->storage->tmpChunkPath($uploadId);

        // Chunks arrive one at a time, in order, awaited sequentially by the client.
        $handle = fopen($tmpPath, $chunkIndex === 0 ? 'wb' : 'ab');
        if ($handle === false) {
            return $this->json($response, ['error' => 'server_error'], 500);
        }
        $stream = $chunk->getStream()->detach();
        stream_copy_to_stream($stream, $handle);
        fclose($handle);
        if (is_resource($stream)) {
            fclose($stream);
        }

        if (filesize($tmpPath) > self::MAX_ORIGINAL_BYTES) {
            unlink($tmpPath);
            return $this->json($response, ['error' => 'too_large'], 413);
        }

        if ($chunkIndex < $chunkCount - 1) {
            return $this->json($response, ['status' => 'chunk_received']);
        }

        return $this->finalize($tmpPath, (int) $entry['id'], $originalName, $response);
    }

    private function finalize(string $tmpPath, int $entryId, string $originalName, ResponseInterface $response): ResponseInterface
    {
        $extension = $this->extensionFor($originalName);
        if ($extension === null || @getimagesize($tmpPath) === false) {
            unlink($tmpPath);
            return $this->json($response, ['error' => 'unsupported_type'], 422);
        }

        $photoId = $this->photos->create($entryId, $originalName, $extension);
        $this->storage->ensureDir($this->storage->directoryFor($photoId));
        rename($tmpPath, $this->storage->originalPath($photoId, $extension));

        $this->jobs->dispatch('photo.process', ['photo_id' => $photoId]);

        return $this->json($response, ['status' => 'processing', 'photo_id' => $photoId]);
    }

    private function extensionFor(string $filename): ?string
    {
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        return in_array($ext, self::ALLOWED_EXTENSIONS, true) ? $ext : null;
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
