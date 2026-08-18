<?php

declare(strict_types=1);

namespace App\Job;

use App\Repository\DayEntryRepository;
use App\Repository\PoiRepository;
use App\Repository\TripRepository;
use App\Service\AiTripMetaService;

/**
 * Job type "trip.suggest_meta". Payload: {"trip_id": int}. Dispatched by
 * TripController::suggestMeta() - a text-completion call, so it goes
 * through the queue like DayEntrySummarizeHandler rather than running
 * synchronously inside a request.
 */
final class TripSuggestMetaHandler implements JobHandlerInterface
{
    /** Keeps the prompt a reasonable size for a long trip with many entries. */
    private const MAX_DAY_TEXTS = 12;
    private const MAX_TEXT_LENGTH = 400;

    public function __construct(
        private readonly TripRepository $trips,
        private readonly DayEntryRepository $entries,
        private readonly PoiRepository $pois,
        private readonly AiTripMetaService $ai,
    ) {
    }

    public function handle(array $payload): void
    {
        $id = $payload['trip_id'] ?? null;
        if (!is_int($id) && !is_numeric($id)) {
            return;
        }
        $id = (int) $id;

        $trip = $this->trips->findById($id);
        if ($trip === null) {
            return;
        }

        $dayTexts = [];
        foreach ($this->entries->findByTrip($id) as $entry) {
            $text = trim((string) $entry['body']);
            if ($text === '') {
                continue;
            }
            $dayTexts[] = mb_substr($text, 0, self::MAX_TEXT_LENGTH);
            if (count($dayTexts) >= self::MAX_DAY_TEXTS) {
                break;
            }
        }

        $sightNames = array_values(array_filter(array_map(
            static fn (array $poi): string => (string) $poi['name'],
            $this->pois->findByTrip($id),
        )));

        if ($dayTexts === [] && $sightNames === []) {
            return; // Nothing to work with yet - no point calling the API.
        }

        $suggestion = $this->ai->suggest([
            'country' => $trip['country'],
            'sightNames' => $sightNames,
            'dayTexts' => $dayTexts,
        ]);

        if ($suggestion === null) {
            return;
        }

        $this->trips->updateAiSuggestions(
            $id,
            $suggestion['title'],
            $suggestion['tags'] !== null ? implode(', ', $suggestion['tags']) : null,
        );
    }
}
