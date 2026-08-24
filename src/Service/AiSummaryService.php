<?php

declare(strict_types=1);

namespace App\Service;

/**
 * First KI feature (Phase 7, see CLAUDE.md): a short summary of a day
 * entry from its own text/mood/weather. Talks to an OpenAI-compatible
 * chat completions endpoint - config comes from the "main" AI slot
 * (AiProviderResolver, saved provider profiles managed in
 * /admin/settings) rather than a single fixed ai.base_url/model/key; the
 * adapter shape itself still only speaks OpenAI-compatible (covers OpenAI,
 * DeepSeek, OpenRouter, Ollama, ...) per the provider-abstraction decision
 * already documented in CLAUDE.md - native Anthropic/Gemini adapters (for
 * their own vision/websearch capabilities) are a later addition once a
 * feature actually needs them, e.g. a future "vision" slot.
 *
 * Best-effort like GooglePlacesService/ReverseGeocodingService: returns
 * null only once every candidate in the "main" slot's fallback chain has
 * failed (network, bad response, no key configured) rather than throwing -
 * always called from a job handler where that's a cosmetic gap (the entry
 * simply keeps no AI summary), never worth failing a request over.
 *
 * Chain/retry (Stefan's ask): a rate limit or any other non-2xx/malformed
 * response from one saved provider profile moves on to the next one in
 * AiProviderResolver::resolveChain() (assigned profile first, then every
 * other saved profile) rather than giving up on the first hiccup.
 */
final class AiSummaryService
{
    public function __construct(private readonly AiProviderResolver $resolver)
    {
    }

    /**
     * @param array{title: ?string, body: string, mood: ?string, entryDate: string,
     *     locationName: ?string, weatherTempC: ?float, weatherCode: ?int} $entry
     */
    public function summarize(array $entry): ?string
    {
        $prompt = $this->buildPrompt($entry);

        foreach ($this->resolver->resolveChain('main') as $provider) {
            $result = $this->callProvider($provider, $prompt);
            if ($result !== null) {
                return $result;
            }
        }

        return null;
    }

    /**
     * @param array{baseUrl: string, model: string, apiKey: string} $provider
     */
    private function callProvider(array $provider, string $prompt): ?string
    {
        $ch = curl_init($provider['baseUrl'] . '/chat/completions');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 25,
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $provider['apiKey'],
            ],
            CURLOPT_POSTFIELDS => json_encode([
                'model' => $provider['model'],
                'messages' => [
                    ['role' => 'system', 'content' =>
                        'Du fasst private Reisetagebuch-Einträge auf Deutsch in 2-3 kurzen, '
                        . 'persönlich geschriebenen Sätzen zusammen. Keine Überschrift, keine Anführungszeichen, '
                        . 'keine Aufzählung - nur Fließtext.'],
                    ['role' => 'user', 'content' => $prompt],
                ],
                'max_tokens' => 220,
                'temperature' => 0.7,
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

        return mb_substr(trim($content), 0, 2000);
    }

    /**
     * @param array{title: ?string, body: string, mood: ?string, entryDate: string,
     *     locationName: ?string, weatherTempC: ?float, weatherCode: ?int} $entry
     */
    private function buildPrompt(array $entry): string
    {
        $lines = [];
        $lines[] = 'Datum: ' . $entry['entryDate'];
        if ($entry['locationName'] !== null && $entry['locationName'] !== '') {
            $lines[] = 'Ort: ' . $entry['locationName'];
        }
        if ($entry['mood'] !== null) {
            $lines[] = 'Stimmung: ' . $entry['mood'];
        }
        if ($entry['weatherTempC'] !== null) {
            $lines[] = 'Wetter: ' . number_format($entry['weatherTempC'], 0) . ' °C'
                . ($entry['weatherCode'] !== null ? ' (Wettercode ' . $entry['weatherCode'] . ')' : '');
        }
        if ($entry['title'] !== null && $entry['title'] !== '') {
            $lines[] = 'Titel: ' . $entry['title'];
        }
        $lines[] = '';
        $lines[] = 'Tagebuchtext:';
        $lines[] = $entry['body'];

        return implode("\n", $lines);
    }
}
