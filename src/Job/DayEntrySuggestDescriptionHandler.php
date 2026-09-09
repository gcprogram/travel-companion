<?php

declare(strict_types=1);

namespace App\Job;

use App\Repository\DayEntryRepository;
use App\Repository\DayEntryWeatherHourRepository;
use App\Repository\PhotoRepository;
use App\Repository\PoiMediaRepository;
use App\Repository\PoiRepository;
use App\Repository\VideoRepository;
use App\Service\AiDayDescriptionService;
use App\Service\PoiApproachService;

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
        private readonly PoiApproachService $poiApproach,
        private readonly DayEntryWeatherHourRepository $weatherHours,
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

        // Ordered by time of closest approach (PoiApproachService - the
        // same "when did the track/a photo actually pass nearest to this
        // place" computation already shown in the sightseeing list), not
        // just trip order - trip_pois.visit_date has no time-of-day at
        // all, so without this a geocache visited at 8am and one at 6pm
        // would look interchangeable to the model. Stefan's ask: this lets
        // the generated text place a sight/geocache at roughly the right
        // point in the day's narrative instead of dumping them all in one
        // undifferentiated bucket.
        $allPois = $this->pois->findByTrip((int) $entry['trip_id']);
        $approach = $this->poiApproach->computeForTrip((int) $entry['trip_id'], $allPois);

        $sightItems = [];
        foreach ($allPois as $poi) {
            if (!$poi['visited'] || $poi['category'] === 'other') {
                continue;
            }
            if ((string) ($poi['visit_date'] ?? '') !== (string) $entry['entry_date']) {
                continue;
            }
            $label = $poi['category'] === 'geocache' && !empty($poi['gc_code'])
                ? $poi['gc_code'] . ' ' . $poi['name']
                : (string) $poi['name'];
            $closestAt = $approach[(int) $poi['id']]['closestAt'] ?? null;
            $sightItems[] = [
                'sortKey' => $closestAt ?? (string) $entry['entry_date'] . ' 99:99:99',
                'time' => $closestAt !== null ? substr($closestAt, 11, 5) : null,
                'label' => $label,
            ];
        }
        usort($sightItems, static fn (array $a, array $b): int => $a['sortKey'] <=> $b['sortKey']);
        $sightItems = array_slice($sightItems, 0, self::MAX_SIGHTS);
        $sights = array_map(
            static fn (array $s): string => $s['time'] !== null ? $s['time'] . ' Uhr: ' . $s['label'] : $s['label'],
            $sightItems,
        );

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
            'weatherTimeline' => $this->buildWeatherTimeline($id),
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

    /**
     * Hour-by-hour weather timeline for the prompt (Stefan's ask: let the AI
     * place a weather SHIFT at the right moment, e.g. "sunny photo at 15:15,
     * thunderstorm from 16:00" - the single daily summary above can't do
     * that). Full resolution, one line per row from
     * DayEntryWeatherHourRepository (already UTC-anchored/one-row-per-hour,
     * see WeatherFetchHandler) - same GMT-only-on-change rule as the
     * hourly table in templates/day_entries/panel.php, so the two views of
     * the same data read consistently.
     *
     * @return list<string>
     */
    private function buildWeatherTimeline(int $entryId): array
    {
        $lines = [];
        $previousOffset = null;
        foreach ($this->weatherHours->findByEntry($entryId) as $wh) {
            $offset = $wh['utc_offset_seconds'] !== null ? (int) $wh['utc_offset_seconds'] : null;
            $showOffset = $offset !== null && $previousOffset !== null && $offset !== $previousOffset;
            $previousOffset = $offset;

            $parts = [sprintf('%02d:00 Uhr', (int) $wh['hour'])];
            if ($showOffset) {
                $parts[] = 'GMT' . ($offset >= 0 ? '+' : '') . (int) round($offset / 3600);
            }
            if (!empty($wh['location_name'])) {
                $parts[] = (string) $wh['location_name'];
            }
            $condition = $wh['weather_code'] !== null ? weather_description((int) $wh['weather_code']) : null;
            $tail = array_filter([
                $condition,
                $wh['temp_c'] !== null ? number_format((float) $wh['temp_c'], 0) . ' °C' : null,
            ]);
            $line = implode(' ', $parts);
            if ($tail !== []) {
                $line .= ': ' . implode(', ', $tail);
            }
            $lines[] = $line;
        }
        return $lines;
    }
}
