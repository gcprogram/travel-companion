<?php

declare(strict_types=1);

namespace App\Job;

use App\Repository\DayEntryRepository;
use App\Repository\PhotoRepository;
use App\Repository\PoiMediaRepository;
use App\Repository\PoiRepository;
use App\Repository\VideoRepository;
use App\Service\AiDayDescriptionService;

/**
 * Job type "day_entry.suggest_description". Payload: {"day_entry_id": int,
 * "depth": "short"|"medium"|"long"}. Dispatched by
 * DayEntryController::suggestDescription() - a text-completion call, so it
 * goes through the queue like DayEntrySummarizeHandler rather than running
 * synchronously inside a request. Unlike DayEntrySummarizeHandler (which
 * requires an already-written body to condense), this gathers the day's
 * photos/videos/visited sights/geocaches/weather and can produce a full
 * description even when body is empty.
 */
final class DayEntrySuggestDescriptionHandler implements JobHandlerInterface
{
    private const MAX_TEXT_LENGTH = 300;
    private const MAX_SIGHTS = 40;
    private const MAX_PHOTO_NOTES = 40;
    private const MAX_VIDEO_NOTES = 15;

    public function __construct(
        private readonly DayEntryRepository $entries,
        private readonly PhotoRepository $photos,
        private readonly VideoRepository $videos,
        private readonly PoiRepository $pois,
        private readonly PoiMediaRepository $poiMedia,
        private readonly AiDayDescriptionService $ai,
    ) {
    }

    public function handle(array $payload): void
    {
        $id = $payload['day_entry_id'] ?? null;
        if (!is_int($id) && !is_numeric($id)) {
            return;
        }
        $id = (int) $id;
        $depth = in_array($payload['depth'] ?? null, ['short', 'medium', 'long'], true)
            ? $payload['depth']
            : 'medium';

        $entry = $this->entries->findById($id);
        if ($entry === null) {
            return;
        }

        $poiByPhoto = $this->poiMedia->findPoiByPhotoForTrip((int) $entry['trip_id']);

        $photoNotes = [];
        foreach ($this->photos->findByEntry($id) as $photo) {
            if ($photo['status'] !== 'ready' || count($photoNotes) >= self::MAX_PHOTO_NOTES) {
                continue;
            }
            $poi = $poiByPhoto[(int) $photo['id']] ?? null;
            $bits = [];
            if (!empty($photo['caption'])) {
                $bits[] = mb_substr((string) $photo['caption'], 0, self::MAX_TEXT_LENGTH);
            }
            if (!empty($photo['ai_address'])) {
                $bits[] = (string) $photo['ai_address'];
            }
            if (!empty($photo['ai_persons'])) {
                $bits[] = 'Personen: ' . $photo['ai_persons'];
            }
            if ($poi !== null) {
                $bits[] = 'Ort: ' . $poi['name'];
            }
            if ($bits !== []) {
                $photoNotes[] = implode(' - ', $bits);
            }
        }

        $videoNotes = [];
        foreach ($this->videos->findByEntry($id) as $video) {
            if ($video['type'] === 'youtube' || $video['status'] !== 'ready' || count($videoNotes) >= self::MAX_VIDEO_NOTES) {
                continue;
            }
            $bits = [];
            if (!empty($video['caption'])) {
                $bits[] = mb_substr((string) $video['caption'], 0, self::MAX_TEXT_LENGTH);
            }
            if (!empty($video['transcript'])) {
                $bits[] = mb_substr((string) $video['transcript'], 0, self::MAX_TEXT_LENGTH);
            }
            if ($bits !== []) {
                $videoNotes[] = implode(' - ', $bits);
            }
        }

        $sights = [];
        foreach ($this->pois->findByTrip((int) $entry['trip_id']) as $poi) {
            if (!$poi['visited'] || $poi['category'] === 'other' || count($sights) >= self::MAX_SIGHTS) {
                continue;
            }
            if ((string) ($poi['visit_date'] ?? '') !== (string) $entry['entry_date']) {
                continue;
            }
            $sights[] = $poi['category'] === 'geocache' && !empty($poi['gc_code'])
                ? $poi['gc_code'] . ' ' . $poi['name']
                : (string) $poi['name'];
        }

        if (trim((string) $entry['body']) === '' && $photoNotes === [] && $videoNotes === [] && $sights === []) {
            return; // Nothing at all to work with yet - no point calling the API.
        }

        $suggestion = $this->ai->suggest([
            'entryDate' => (string) $entry['entry_date'],
            'locationName' => $entry['location_name'],
            'weather' => $entry['weather_code'] !== null
                ? weather_description((int) $entry['weather_code'])
                    . ($entry['weather_temp_c'] !== null ? ', ' . number_format((float) $entry['weather_temp_c'], 0) . ' °C' : '')
                : null,
            'existingTitle' => $entry['title'],
            'existingBody' => $entry['body'],
            'sights' => $sights,
            'photoNotes' => $photoNotes,
            'videoNotes' => $videoNotes,
        ], $depth);

        if ($suggestion === null) {
            return;
        }

        $this->entries->updateAiDescriptionSuggestion($id, $suggestion);
    }
}
