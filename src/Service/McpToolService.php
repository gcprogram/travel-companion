<?php

declare(strict_types=1);

namespace App\Service;

use App\Repository\DayEntryRepository;
use App\Repository\JobRepository;
use App\Repository\PhotoRepository;
use App\Repository\TripRepository;
use App\Support\McpToolException;

/**
 * Business logic behind the MCP tools (McpController) - deliberately kept
 * separate from the JSON-RPC plumbing, same "controller stays thin" split
 * as everywhere else in this app. Every method takes the authenticated
 * user (resolved by McpAuthMiddleware from the bearer token) as its first
 * argument and never touches any trip that user can't already edit -
 * TripAccess::canEdit(), same check the browser-facing controllers use,
 * called with no $request (an MCP token is not a share token, so
 * share-link access never applies here).
 */
final class McpToolService
{
    private const MOODS = ['very_bad', 'bad', 'neutral', 'good', 'very_good'];
    private const ALLOWED_EXTENSIONS = ['jpg' => 'jpg', 'jpeg' => 'jpg', 'png' => 'png', 'webp' => 'webp'];

    public function __construct(
        private readonly TripRepository $trips,
        private readonly DayEntryRepository $entries,
        private readonly PhotoRepository $photos,
        private readonly PhotoStorage $storage,
        private readonly JobRepository $jobs,
        private readonly StorageQuotaService $quota,
        private readonly TripAccess $access,
    ) {
    }

    /**
     * @param array<string, mixed> $user
     * @return list<array<string, mixed>>
     */
    public function listTrips(array $user): array
    {
        $today = (new \DateTimeImmutable('today'))->format('Y-m-d');
        return array_map(function (array $trip) use ($today): array {
            return [
                'id' => (int) $trip['id'],
                'slug' => $trip['slug'],
                'title' => $trip['title'],
                'country' => $trip['country'],
                'date_start' => $trip['date_start'],
                'date_end' => $trip['date_end'],
                'visibility' => $trip['visibility'],
                'is_current' => $trip['date_start'] !== null && $trip['date_end'] !== null
                    && $trip['date_start'] <= $today && $today <= $trip['date_end'],
            ];
        }, $this->trips->findByUser((int) $user['id']));
    }

    /**
     * @param array<string, mixed> $user
     * @return array<string, mixed>
     */
    public function getTrip(array $user, string $tripRef): array
    {
        $trip = $this->resolveTrip($user, $tripRef);
        $days = array_map(static fn (array $e): array => [
            'date' => $e['entry_date'],
            'has_text' => trim((string) $e['body']) !== '',
            'title' => $e['title'],
        ], $this->entries->findByTrip((int) $trip['id']));

        return [
            'id' => (int) $trip['id'],
            'slug' => $trip['slug'],
            'title' => $trip['title'],
            'country' => $trip['country'],
            'date_start' => $trip['date_start'],
            'date_end' => $trip['date_end'],
            'visibility' => $trip['visibility'],
            'days' => $days,
        ];
    }

    /**
     * @param array<string, mixed> $user
     * @return array<string, mixed>
     */
    public function getDayEntry(array $user, string $tripRef, string $date): array
    {
        $trip = $this->resolveTrip($user, $tripRef);
        $date = $this->validDate($date);
        $entry = $this->entries->findByTripAndDate((int) $trip['id'], $date);
        if ($entry === null) {
            return ['date' => $date, 'exists' => false];
        }

        return [
            'date' => $date,
            'exists' => true,
            'title' => $entry['title'],
            'mood' => $entry['mood'],
            'body' => $entry['body'],
            'photo_count' => count($this->photos->findByEntry((int) $entry['id'])),
        ];
    }

    /**
     * Appends dictated text to the day's diary entry (creating it if this
     * is the first content for that date), separated by a blank line from
     * whatever is already there - never replaces existing text. title/mood
     * are only ever set when the entry doesn't already have one, so a
     * later dictation for the same day can't overwrite what a human (or an
     * earlier call) already wrote.
     *
     * @param array<string, mixed> $user
     * @return array<string, mixed>
     */
    public function appendDayEntryText(
        array $user,
        string $tripRef,
        string $date,
        string $text,
        ?string $title = null,
        ?string $mood = null,
    ): array {
        $text = trim($text);
        if ($text === '') {
            throw new McpToolException('text must not be empty.');
        }
        $trip = $this->resolveTrip($user, $tripRef);
        $date = $this->validDate($date);
        $entry = $this->findOrCreateEntry((int) $trip['id'], $date);

        $existingBody = trim((string) $entry['body']);
        $newBody = $existingBody === '' ? $text : $existingBody . "\n\n" . $text;
        $this->entries->updateBody((int) $entry['id'], $newBody);

        if ($title !== null && trim((string) $entry['title']) === '') {
            $this->entries->updateTitle((int) $entry['id'], trim($title));
        }
        if ($mood !== null) {
            if (!in_array($mood, self::MOODS, true)) {
                throw new McpToolException('mood must be one of: ' . implode(', ', self::MOODS));
            }
            if ($entry['mood'] === null) {
                $this->entries->updateMood((int) $entry['id'], $mood);
            }
        }

        $updated = $this->entries->findById((int) $entry['id']);
        return [
            'date' => $date,
            'title' => $updated['title'],
            'mood' => $updated['mood'],
            'body' => $updated['body'],
        ];
    }

    /**
     * Stores a photo from raw base64 bytes (an MCP tool call has no
     * multipart form, so the browser's chunked-upload dance
     * (PhotoUploadController) doesn't apply - same end sequence though:
     * insert row, write bytes to the id-numbered directory, queue
     * photo.process for thumbnails/EXIF/geotag extraction.
     *
     * @param array<string, mixed> $user
     * @return array<string, mixed>
     */
    public function addDayEntryPhoto(
        array $user,
        string $tripRef,
        string $date,
        string $imageBase64,
        ?string $filename = null,
    ): array {
        $trip = $this->resolveTrip($user, $tripRef);
        $date = $this->validDate($date);
        $entry = $this->findOrCreateEntry((int) $trip['id'], $date);

        $bytes = base64_decode($imageBase64, true);
        if ($bytes === false || $bytes === '') {
            throw new McpToolException('image_base64 is not valid base64 data.');
        }
        $info = @getimagesizefromstring($bytes);
        if ($info === false) {
            throw new McpToolException('image_base64 does not decode to a supported image.');
        }
        $extension = $this->extensionFor($filename, $info['mime']);
        if ($extension === null) {
            throw new McpToolException('Unsupported image type - only JPEG, PNG, and WebP are accepted.');
        }

        $size = strlen($bytes);
        $ownerId = (int) $trip['user_id'];
        if ($this->quota->wouldExceed($ownerId, $size)) {
            throw new McpToolException('This would exceed the trip owner\'s storage quota.');
        }

        $hash = hash('sha256', $bytes);
        $existing = $this->photos->findReadyByTripAndHash((int) $trip['id'], $hash);
        if ($existing !== null && (int) $existing['day_entry_id'] === (int) $entry['id']) {
            return ['photo_id' => (int) $existing['id'], 'status' => 'duplicate_ignored'];
        }

        $photoId = $this->photos->create(
            (int) $entry['id'],
            $filename ?? ('mcp-upload.' . $extension),
            $extension,
            $hash,
        );
        $this->storage->ensureDir($this->storage->directoryFor($photoId));
        file_put_contents($this->storage->originalPath($photoId, $extension), $bytes);
        $this->photos->updateBytes($photoId, $size);
        $this->jobs->dispatch('photo.process', ['photo_id' => $photoId]);

        return ['photo_id' => $photoId, 'status' => 'processing'];
    }

    /**
     * @param array<string, mixed> $user
     * @return array<string, mixed>
     */
    private function resolveTrip(array $user, string $tripRef): array
    {
        $trip = ctype_digit($tripRef) ? $this->trips->findById((int) $tripRef) : $this->trips->findBySlug($tripRef);
        if ($trip === null) {
            throw new McpToolException('Trip not found: ' . $tripRef);
        }
        if (!$this->access->canEdit($trip, $user)) {
            throw new McpToolException('You do not have edit access to this trip.');
        }
        return $trip;
    }

    /**
     * @return array<string, mixed>
     */
    private function findOrCreateEntry(int $tripId, string $date): array
    {
        $entry = $this->entries->findByTripAndDate($tripId, $date);
        if ($entry !== null) {
            return $entry;
        }
        $id = $this->entries->create($tripId, [
            'entry_date' => $date,
            'title' => null,
            'body' => '',
            'mood' => null,
            'lat' => null,
            'lng' => null,
            'location_name' => null,
        ]);
        return $this->entries->findById($id);
    }

    private function validDate(string $date): string
    {
        $dt = \DateTimeImmutable::createFromFormat('Y-m-d', $date);
        if ($dt === false || $dt->format('Y-m-d') !== $date) {
            throw new McpToolException('date must be in YYYY-MM-DD format.');
        }
        return $date;
    }

    private function extensionFor(?string $filename, string $mimeType): ?string
    {
        if ($filename !== null) {
            $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
            if (isset(self::ALLOWED_EXTENSIONS[$ext])) {
                return $ext;
            }
        }
        return match ($mimeType) {
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
            default => null,
        };
    }
}
