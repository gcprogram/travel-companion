<?php

declare(strict_types=1);

namespace App\Job;

use App\Repository\DayEntryRepository;
use App\Service\WeatherService;
use Psr\Log\LoggerInterface;

/**
 * Job-Typ "weather.fetch". Payload: {"day_entry_id": int}.
 * Wird beim Speichern eines Tagebucheintrags mit Standort eingereiht.
 */
final class WeatherFetchHandler implements JobHandlerInterface
{
    public function __construct(
        private readonly DayEntryRepository $entries,
        private readonly WeatherService $weather,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function handle(array $payload): void
    {
        $entryId = (int) ($payload['day_entry_id'] ?? 0);
        $entry = $this->entries->findById($entryId);

        if ($entry === null || $entry['lat'] === null || $entry['lng'] === null) {
            return; // Eintrag zwischenzeitlich gelöscht oder ohne Standort.
        }

        $result = $this->weather->fetchDaily((float) $entry['lat'], (float) $entry['lng'], (string) $entry['entry_date']);
        if ($result === null) {
            $this->logger->info('Kein Wetter für Tagebucheintrag verfügbar', ['id' => $entryId]);
            return;
        }

        $this->entries->updateWeather($entryId, $result['temp_c'], $result['code']);
    }
}
