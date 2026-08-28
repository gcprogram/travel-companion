<?php

declare(strict_types=1);

namespace App\Service;

/**
 * "Generiere Beschreibung" (Stefan's ask): a trip-level narrative from
 * everything the trip already knows about itself - far richer input than
 * AiTripMetaService's title/tags suggestion (weather, stays, per-photo
 * captions/addresses/persons/POIs/geocaches, video captions/transcripts,
 * route distance from the GPS track) - assembled by
 * TripSuggestDescriptionHandler, this service only turns that already-
 * gathered context into a prompt and calls the "main" AI slot, exactly like
 * AiSummaryService/AiTripMetaService (AiProviderResolver, same chain/retry
 * across saved provider profiles).
 *
 * Deliberately a fixed short OVERVIEW only (visited cities/places) - no
 * depth choice here anymore (Stefan's call): the day-by-day depth belongs
 * to AiDayDescriptionService instead, so this text stays a quick summary
 * rather than duplicating what the day descriptions already say in detail.
 */
final class AiTripDescriptionService
{
    private const INSTRUCTION = 'Schreibe einen KURZEN Überblick (ca. 80-150 Wörter) über die Reise: '
        . 'wohin gereist wurde, welche Städte/Orte/Regionen besucht wurden. Keine Details zu einzelnen '
        . 'Ereignissen, Sehenswürdigkeiten oder Tagesabläufen - das steht in den separaten Tagesbeschreibungen.';

    public function __construct(
        private readonly AiProviderResolver $resolver,
        private readonly Settings $settings,
    ) {
    }

    /**
     * @param array<string, mixed> $context see TripSuggestDescriptionHandler::handle()
     */
    public function suggest(array $context): ?string
    {
        $prompt = $this->buildPrompt($context);
        $maxTokens = $this->settings->getInt('ai.description_max_tokens');

        foreach ($this->resolver->resolveChain('main') as $provider) {
            $result = $this->callProvider($provider, $prompt, $maxTokens);
            if ($result !== null) {
                return $result;
            }
        }

        return null;
    }

    /**
     * @param array{baseUrl: string, model: string, apiKey: string} $provider
     */
    private function callProvider(array $provider, string $prompt, int $maxTokens): ?string
    {
        $ch = curl_init($provider['baseUrl'] . '/chat/completions');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 40,
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $provider['apiKey'],
            ],
            CURLOPT_POSTFIELDS => json_encode([
                'model' => $provider['model'],
                'messages' => [
                    ['role' => 'system', 'content' =>
                        'Du schreibst für ein privates Reisetagebuch eine zusammenhängende, persönlich '
                        . 'klingende Reisebeschreibung auf Deutsch, basierend NUR auf den gegebenen Fakten - '
                        . 'nichts erfinden, keine Orte/Ereignisse hinzudichten, die nicht genannt sind. Falls '
                        . 'schon ein von einem Menschen geschriebener Text vorliegt, dessen Ton/Inhalt '
                        . 'aufgreifen und sinnvoll erweitern statt ihn zu ignorieren. Fließtext, keine '
                        . 'Überschrift, keine Aufzählung, keine Anführungszeichen. '
                        . self::INSTRUCTION],
                    ['role' => 'user', 'content' => $prompt],
                ],
                'max_tokens' => $maxTokens,
                'temperature' => 0.75,
            ], JSON_THROW_ON_ERROR),
        ]);
        $body = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($body === false || $status !== 200) {
            return null;
        }

        try {
            $data = json_decode((string) $body, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        $content = $data['choices'][0]['message']['content'] ?? null;
        if (!is_string($content) || trim($content) === '') {
            return null;
        }

        return mb_substr(trim($content), 0, 20000);
    }

    /**
     * @param array<string, mixed> $context
     */
    private function buildPrompt(array $context): string
    {
        $lines = [];
        if ($context['title'] !== null && $context['title'] !== '') {
            $lines[] = 'Titel: ' . $context['title'];
        }
        if ($context['country'] !== null && $context['country'] !== '') {
            $lines[] = 'Land: ' . $context['country'];
        }
        if ($context['dateStart'] !== null && $context['dateEnd'] !== null) {
            $lines[] = 'Zeitraum: ' . $context['dateStart'] . ' bis ' . $context['dateEnd'];
        }
        if ($context['routeDistanceKm'] !== null) {
            $lines[] = 'Zurückgelegte Strecke laut GPS-Track: ca. ' . $context['routeDistanceKm'] . ' km';
        }
        if ($context['existingDescription'] !== null && $context['existingDescription'] !== '') {
            $lines[] = '';
            $lines[] = 'Bisherige Beschreibung (evtl. von einem Menschen geschrieben - Ton/Inhalt aufgreifen):';
            $lines[] = $context['existingDescription'];
        }

        if ($context['days'] !== []) {
            $lines[] = '';
            $lines[] = 'Tagesübersicht:';
            foreach ($context['days'] as $day) {
                $parts = [$day['date']];
                if ($day['weather'] !== null) {
                    $parts[] = $day['weather'];
                }
                if ($day['body'] !== null && $day['body'] !== '') {
                    $parts[] = $day['body'];
                }
                $lines[] = '- ' . implode(' - ', $parts);
            }
        }

        if ($context['stays'] !== []) {
            $lines[] = '';
            $lines[] = 'Aufenthalte:';
            foreach ($context['stays'] as $stay) {
                $lines[] = '- ' . $stay;
            }
        }

        if ($context['sights'] !== []) {
            $lines[] = '';
            $lines[] = 'Besuchte Sehenswürdigkeiten/Geocaches:';
            $lines[] = implode(', ', $context['sights']);
        }

        if ($context['photoNotes'] !== []) {
            $lines[] = '';
            $lines[] = 'Notizen aus Fotos (Bildunterschrift/Adresse/Personen/Ort):';
            foreach ($context['photoNotes'] as $note) {
                $lines[] = '- ' . $note;
            }
        }

        if ($context['videoNotes'] !== []) {
            $lines[] = '';
            $lines[] = 'Notizen aus Videos (Bildunterschrift/Transkript):';
            foreach ($context['videoNotes'] as $note) {
                $lines[] = '- ' . $note;
            }
        }

        return implode("\n", $lines);
    }
}
