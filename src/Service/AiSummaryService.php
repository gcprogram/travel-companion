<?php

declare(strict_types=1);

namespace App\Service;

/**
 * First KI feature (Phase 7, see CLAUDE.md): a short summary of a day
 * entry from its own text/mood/weather. Talks to an OpenAI-compatible
 * chat completions endpoint (config in /admin/settings, ai.base_url +
 * ai.model + encrypted ai.api_key) - the same adapter shape covers OpenAI
 * itself, DeepSeek, OpenRouter, Ollama, etc. per the provider-abstraction
 * decision already documented in CLAUDE.md; native Anthropic/Gemini
 * adapters (for their own vision/websearch capabilities) are a later
 * addition once a second AI feature actually needs them - nothing here
 * assumes only one provider shape will ever exist.
 *
 * Best-effort like GooglePlacesService/ReverseGeocodingService: returns
 * null on any failure (network, bad response, no key configured) rather
 * than throwing - always called from a job handler where that's a
 * cosmetic gap (the entry simply keeps no AI summary), never worth
 * retrying aggressively or failing a request over.
 */
final class AiSummaryService
{
    public function __construct(private readonly Settings $settings)
    {
    }

    /**
     * @param array{title: ?string, body: string, mood: ?string, entryDate: string,
     *     locationName: ?string, weatherTempC: ?float, weatherCode: ?int} $entry
     */
    public function summarize(array $entry): ?string
    {
        $apiKey = $this->settings->getSecret('ai.api_key');
        if ($apiKey === null) {
            return null;
        }

        $baseUrl = rtrim($this->settings->get('ai.base_url'), '/');
        $model = $this->settings->get('ai.model');
        if ($baseUrl === '' || $model === '') {
            return null;
        }

        $prompt = $this->buildPrompt($entry);

        $ch = curl_init($baseUrl . '/chat/completions');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 25,
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $apiKey,
            ],
            CURLOPT_POSTFIELDS => json_encode([
                'model' => $model,
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
