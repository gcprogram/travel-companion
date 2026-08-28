<?php

declare(strict_types=1);

namespace App\Job;

use App\Repository\DayEntryRepository;
use App\Repository\PhotoRepository;
use App\Repository\PoiMediaRepository;
use App\Repository\PoiRepository;
use App\Repository\TrackRepository;
use App\Repository\TripRepository;
use App\Repository\VideoRepository;
use App\Service\AiTripDescriptionService;

/**
 * Job type "trip.suggest_description". Payload: {"trip_id": int, "depth":
 * "short"|"medium"|"long"}. Dispatched by TripController::suggestDescription()
 * - a text-completion call, so it goes through the queue like
 * TripSuggestMetaHandler rather than running synchronously inside a
 * request. Gathers everything Stefan asked for (existing description,
 * per-day weather, stays, per-photo captions/addresses/persons/assigned
 * POI, video captions/transcripts, GPS route distance) into a bounded
 * context, then hands it to AiTripDescriptionService to turn into a prompt.
 */
final class TripSuggestDescriptionHandler implements JobHandlerInterface
{
    /** Keeps the prompt a reasonable size for a long trip with many days/photos. */
    private const MAX_TEXT_LENGTH = 300;
    private const MAX_STAYS = 30;
    private const MAX_SIGHTS = 40;
    private const MAX_PHOTO_NOTES = 40;
    private const MAX_VIDEO_NOTES = 15;

    public function __construct(
        private readonly TripRepository $trips,
        private readonly DayEntryRepository $entries,
        private readonly PoiRepository $pois,
        private readonly PoiMediaRepository $poiMedia,
        private readonly PhotoRepository $photos,
        private readonly VideoRepository $videos,
        private readonly TrackRepository $tracks,
        private readonly AiTripDescriptionService $ai,
    ) {
    }

    public function handle(array $payload): void
    {
        $id = $payload['trip_id'] ?? null;
        if (!is_int($id) && !is_numeric($id)) {
            return;
        }
        $id = (int) $id;
        $depth = in_array($payload['depth'] ?? null, ['short', 'medium', 'long'], true)
            ? $payload['depth']
            : 'medium';

        $trip = $this->trips->findById($id);
        if ($trip === null) {
            return;
        }

        $poiByPhoto = $this->poiMedia->findPoiByPhotoForTrip($id);

        $days = [];
        $photoNotes = [];
        $videoNotes = [];
        foreach ($this->entries->findByTrip($id) as $entry) {
            $days[] = [
                'date' => $entry['entry_date'],
                'weather' => $entry['weather_code'] !== null
                    ? weather_description((int) $entry['weather_code'])
                        . ($entry['weather_temp_c'] !== null ? ', ' . number_format((float) $entry['weather_temp_c'], 0) . ' °C' : '')
                    : null,
                'body' => $entry['body'] !== null ? mb_substr(trim((string) $entry['body']), 0, self::MAX_TEXT_LENGTH) : null,
            ];

            if (count($photoNotes) < self::MAX_PHOTO_NOTES) {
                foreach ($this->photos->findByEntry((int) $entry['id']) as $photo) {
                    if ($photo['status'] !== 'ready') {
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
                        if (count($photoNotes) >= self::MAX_PHOTO_NOTES) {
                            break;
                        }
                    }
                }
            }

            if (count($videoNotes) < self::MAX_VIDEO_NOTES) {
                foreach ($this->videos->findByEntry((int) $entry['id']) as $video) {
                    if ($video['type'] === 'youtube' || $video['status'] !== 'ready') {
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
                        if (count($videoNotes) >= self::MAX_VIDEO_NOTES) {
                            break;
                        }
                    }
                }
            }
        }

        $stays = [];
        $sights = [];
        foreach ($this->pois->findByTrip($id) as $poi) {
            if (!$poi['visited']) {
                continue;
            }
            if ($poi['category'] === 'other') {
                if (count($stays) >= self::MAX_STAYS) {
                    continue;
                }
                // A detected "stay" isn't bound to a day entry the way a
                // photo is - a GPS track can produce one for home, the
                // evening before departure, or for a stop on the drive
                // home, outside the trip's actual (day-entry-clipped,
                // see TripMetadataAutoFillHandler) date range. Left in,
                // that pre-/post-trip stay dominated the description
                // (Stefan's report: it opened with the drive to the
                // airport instead of the actual destination).
                if ($this->isOutsideTripRange((string) ($poi['visit_date'] ?? ''), $trip)) {
                    continue;
                }
                $bits = [(string) $poi['name']];
                if (!empty($poi['notes'])) {
                    $bits[] = mb_substr((string) $poi['notes'], 0, self::MAX_TEXT_LENGTH);
                }
                $stays[] = implode(' - ', $bits);
                continue;
            }
            if (count($sights) < self::MAX_SIGHTS) {
                $sights[] = $poi['category'] === 'geocache' && !empty($poi['gc_code'])
                    ? $poi['gc_code'] . ' ' . $poi['name']
                    : (string) $poi['name'];
            }
        }

        if ($days === [] && $stays === [] && $sights === [] && $photoNotes === [] && $videoNotes === []
            && empty($trip['description'])
        ) {
            return; // Nothing at all to work with yet - no point calling the API.
        }

        $suggestion = $this->ai->suggest([
            'title' => $trip['title'],
            'country' => $trip['country'],
            'dateStart' => $trip['date_start'],
            'dateEnd' => $trip['date_end'],
            'existingDescription' => $trip['description'],
            'routeDistanceKm' => $this->routeDistanceKm($id),
            'days' => $days,
            'stays' => $stays,
            'sights' => $sights,
            'photoNotes' => $photoNotes,
            'videoNotes' => $videoNotes,
        ], $depth);

        if ($suggestion === null) {
            return;
        }

        $this->trips->updateAiDescriptionSuggestion($id, $suggestion);
    }

    private function routeDistanceKm(int $tripId): ?float
    {
        $track = $this->tracks->findByTrip($tripId);
        if ($track === null) {
            return null;
        }

        $points = $this->tracks->findPoints((int) $track['id']);
        if (count($points) < 2) {
            return null;
        }

        $meters = 0.0;
        for ($i = 1; $i < count($points); $i++) {
            $meters += $this->haversineMeters(
                (float) $points[$i - 1]['lat'],
                (float) $points[$i - 1]['lng'],
                (float) $points[$i]['lat'],
                (float) $points[$i]['lng'],
            );
        }

        return $meters > 0 ? round($meters / 1000, 1) : null;
    }

    private function haversineMeters(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;
        return 6371000.0 * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }

    /**
     * @param array<string, mixed> $trip
     */
    private function isOutsideTripRange(string $date, array $trip): bool
    {
        if ($date === '' || $trip['date_start'] === null || $trip['date_end'] === null) {
            return false; // Nothing to compare against - don't drop it over a guess.
        }
        return $date < $trip['date_start'] || $date > $trip['date_end'];
    }
}
