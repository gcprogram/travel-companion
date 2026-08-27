<?php

declare(strict_types=1);

namespace App\Service;

/**
 * "Generiere Beschreibung" (Stefan's ask): a full trip narrative from
 * everything the trip already knows about itself - far richer input than
 * AiTripMetaService's title/tags suggestion (weather, stays, per-photo
 * captions/addresses/persons/POIs/geocaches, video captions/transcripts,
 * route distance from the GPS track) - assembled by
 * TripSuggestDescriptionHandler, this service only turns that already-
 * gathered context into a prompt and calls the "main" AI slot, exactly like
 * AiSummaryService/AiTripMetaService (AiProviderResolver, same chain/retry
 * across saved provider profiles).
 *
 * $depth controls the OUTPUT's length/detail (Stefan's "Tiefe/Umfang
 * wählbar" ask) - the gathered INPUT context is the same regardless, only
 * the system prompt's instruction and max_tokens change.
 */
final class AiTripDescriptionService
{
    private const DEPTHS = [
        'short' => ['instruction' => 'Schreibe einen kurzen Absatz (ca. 60-100 Wörter).', 'maxTokens' => 220],
        'medium' => ['instruction' => 'Schreibe 2-3 Absätze (ca. 200-300 Wörter).', 'maxTokens' => 500],
        'long' => ['instruction' => 'Schreibe eine ausführliche Beschreibung mit mehreren Absätzen (ca. 400-600 Wörter).', 'maxTokens' => 950],
    ];

    public function __construct(private readonly AiProviderResolver $resolver)
    {
    }

    /**
     * @param array<string, mixed> $context see TripSuggestDescriptionHandler::handle()
     */
    public function suggest(array $context, string $depth): ?string
    {
        $depthConfig = self::DEPTHS[$depth] ?? self::DEPTHS['medium'];
        $prompt = $this->buildPrompt($context);

        foreach ($this->resolver->resolveChain('main') as $provider) {
            $result = $this->callProvider($provider, $prompt, $depthConfig);
            if ($result !== null) {
                return $result;
            }
        }

        return null;
    }

    /**
     * @param array{baseUrl: string, model: string, apiKey: string} $provider
     * @param array{instruction: string, maxTokens: int} $depthConfig
     */
    private function callProvider(array $provider, string $prompt, array $depthConfig): ?string
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
                        . $depthConfig['instruction']],
                    ['role' => 'user', 'content' => $prompt],
                ],
                'max_tokens' => $depthConfig['maxTokens'],
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

        return mb_substr(trim($content), 0, 6000);
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
