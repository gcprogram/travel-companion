<?php

declare(strict_types=1);

namespace App\Job;

use App\Repository\DayEntryRepository;
use App\Service\AiSummaryService;

/**
 * Job type "day_entry.summarize". Payload: {"day_entry_id": int}. Dispatched
 * by DayEntryController::summarize() - a text-completion call is exactly
 * the kind of "slow" work CLAUDE.md's architecture routes through the job
 * queue rather than running synchronously inside a request.
 */
final class DayEntrySummarizeHandler implements JobHandlerInterface
{
    public function __construct(
        private readonly DayEntryRepository $entries,
        private readonly AiSummaryService $ai,
    ) {
    }

    public function handle(array $payload): void
    {
        $id = $payload['day_entry_id'] ?? null;
        if (!is_int($id) && !is_numeric($id)) {
            return;
        }
        $id = (int) $id;

        $entry = $this->entries->findById($id);
        if ($entry === null || trim((string) $entry['body']) === '') {
            return;
        }

        $summary = $this->ai->summarize([
            'title' => $entry['title'],
            'body' => (string) $entry['body'],
            'mood' => $entry['mood'],
            'entryDate' => (string) $entry['entry_date'],
            'locationName' => $entry['location_name'],
            'weatherTempC' => $entry['weather_temp_c'] !== null ? (float) $entry['weather_temp_c'] : null,
            'weatherCode' => $entry['weather_code'] !== null ? (int) $entry['weather_code'] : null,
        ]);

        if ($summary !== null) {
            $this->entries->updateAiSummary($id, $summary);
        }
    }
}
