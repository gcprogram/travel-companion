<?php

declare(strict_types=1);

namespace App\Service;

/**
 * Second KI feature (Phase 7, see AiSummaryService for the first/CLAUDE.md
 * background): suggests a trip title and a handful of tags from the trip's
 * own day-entry texts, visited sights, and country. Same OpenAI-compatible
 * chat completions endpoint/config as AiSummaryService (ai.base_url/
 * ai.model/ai.api_key in /admin/settings) - kept as its own service rather
 * than folded into AiSummaryService since the prompt/response shape is
 * different (structured title+tags, not a single free-text summary), but
 * deliberately NOT a shared HTTP-client abstraction: ReverseGeocodingService/
 * GooglePlacesService already each own their curl call outright, this
 * follows the same established pattern rather than introducing a new one.
 */
final class AiTripMetaService
{
    public function __construct(private readonly Settings $settings)
    {
    }

    /**
     * @param array{country: ?string, sightNames: list<string>, dayTexts: list<string>} $context
     * @return array{title: ?string, tags: ?list<string>}|null
     */
    public function suggest(array $context): ?array
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

        $prompt = $this->buildPrompt($context);

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
                        'Du schlägst für ein privates Reisetagebuch einen kurzen, einprägsamen deutschen '
                        . 'Reisetitel und 3-6 kurze Tags (je 1-2 Wörter, Kleinschreibung) vor. '
                        . 'Antworte NUR mit JSON: {"title": "...", "tags": ["...", "..."]}. '
                        . 'Kein Fließtext, keine Erklärung, kein Markdown.'],
                    ['role' => 'user', 'content' => $prompt],
                ],
                'response_format' => ['type' => 'json_object'],
                'max_tokens' => 200,
                'temperature' => 0.8,
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

        return $this->parseSuggestion($content);
    }

    /**
     * @return array{title: ?string, tags: ?list<string>}|null
     */
    private function parseSuggestion(string $content): ?array
    {
        try {
            // Some OpenAI-compatible providers ignore response_format and
            // wrap the JSON in a markdown code fence anyway - strip it
            // before decoding rather than failing outright.
            $cleaned = trim(preg_replace('/^```(?:json)?|```$/m', '', trim($content)));
            $parsed = json_decode($cleaned, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        if (!is_array($parsed)) {
            return null;
        }

        $title = $parsed['title'] ?? null;
        $title = (is_string($title) && trim($title) !== '') ? mb_substr(trim($title), 0, 190) : null;

        $tags = $parsed['tags'] ?? null;
        $tagList = null;
        if (is_array($tags)) {
            $tagList = array_values(array_filter(array_map(
                static fn (mixed $t): string => is_string($t) ? trim(mb_strtolower($t)) : '',
                $tags,
            ), static fn (string $t): bool => $t !== ''));
        }

        if ($title === null && $tagList === null) {
            return null;
        }

        return ['title' => $title, 'tags' => $tagList];
    }

    /**
     * @param array{country: ?string, sightNames: list<string>, dayTexts: list<string>} $context
     */
    private function buildPrompt(array $context): string
    {
        $lines = [];
        if ($context['country'] !== null && $context['country'] !== '') {
            $lines[] = 'Land: ' . $context['country'];
        }
        if ($context['sightNames'] !== []) {
            $lines[] = 'Besuchte Sehenswürdigkeiten/Orte: ' . implode(', ', $context['sightNames']);
        }
        if ($context['dayTexts'] !== []) {
            $lines[] = '';
            $lines[] = 'Auszüge aus dem Reisetagebuch:';
            foreach ($context['dayTexts'] as $text) {
                $lines[] = '- ' . $text;
            }
        }

        return implode("\n", $lines);
    }
}
