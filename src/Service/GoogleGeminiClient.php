<?php

declare(strict_types=1);

namespace App\Service;

/**
 * Google Gemini's OWN generateContent REST API (v1beta) - not the
 * OpenAI-compatibility shim every other provider in this app speaks
 * (AiProviderPresets). Needed because that shim silently drops/ignores the
 * `google_search` grounding tool, so real web search only works through
 * this native protocol - same finding already made and documented in
 * GCMystSolver's own LlmClient.kt (a sibling project, ported here
 * behaviourally, not code-for-code: different language, same wire dialect).
 *
 * Every call site in this app that wants to actually use a Google-provider
 * config (AiVisionCaptionService for vision, AdminAiProviderController for
 * "add provider"/"test", and the various text-completion services'
 * callProvider() for the plain-text case) goes through here rather than
 * duplicating the dialect - unlike the OpenAI-shaped services, which
 * deliberately each own a near-identical curl call (see AiTripMetaService's
 * docblock), the wire format here is different enough (auth header,
 * request shape, response path) that duplicating it five times would be
 * error-prone, especially the grounding-metadata extraction.
 */
final class GoogleGeminiClient
{
    /**
     * GET {base}/v1beta/models - used by the "add provider" form's "fetch
     * models" step, before anything is saved.
     *
     * @return list<string>|null model names with the "models/" prefix
     *         stripped (matches what AdminAiProviderController::create()
     *         expects an admin to then type/pick). `status`/`error` are
     *         only meaningful when `ok` is false - surfaced so the admin
     *         UI can show the real HTTP status/message instead of a blind
     *         "bad response" (this is the one troubleshooting-facing call
     *         site; every other method here stays best-effort/null like
     *         the rest of the app's AI services).
     *
     * @return array{ok: bool, models: list<string>, status: ?int, error: ?string}
     */
    public function listModels(string $baseUrl, string $apiKey): array
    {
        $ch = curl_init(rtrim($baseUrl, '/') . '/v1beta/models');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_HTTPHEADER => ['x-goog-api-key: ' . $apiKey],
        ]);
        $body = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($body === false) {
            return ['ok' => false, 'models' => [], 'status' => null, 'error' => $curlError];
        }
        if ($status !== 200) {
            return ['ok' => false, 'models' => [], 'status' => $status, 'error' => $this->extractErrorMessage($body)];
        }

        try {
            $data = json_decode((string) $body, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return ['ok' => false, 'models' => [], 'status' => $status, 'error' => null];
        }

        $entries = $data['models'] ?? null;
        if (!is_array($entries)) {
            return ['ok' => false, 'models' => [], 'status' => $status, 'error' => null];
        }

        $models = [];
        foreach ($entries as $entry) {
            $name = $entry['name'] ?? null;
            if (is_string($name) && $name !== '') {
                $models[] = str_starts_with($name, 'models/') ? substr($name, 7) : $name;
            }
        }
        return ['ok' => true, 'models' => array_values(array_unique($models)), 'status' => 200, 'error' => null];
    }

    /**
     * Google's error body is `{"error": {"message": "...", ...}}` - quite
     * different from the OpenAI dialect's shape, pulled out separately so
     * a real Gemini error (e.g. "API key not valid") reaches the admin
     * legibly instead of a generic "bad response".
     */
    private function extractErrorMessage(string $body): ?string
    {
        try {
            $data = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }
        $message = $data['error']['message'] ?? null;
        return is_string($message) && $message !== '' ? $message : null;
    }

    /**
     * Plain text completion - the Google-dialect equivalent of every other
     * service's OpenAI-shaped callProvider(). $systemPrompt is sent as a
     * top-level `system_instruction`, a sibling of `contents` rather than a
     * `role: "system"` content item (Gemini's own convention).
     */
    public function generateText(
        string $baseUrl,
        string $model,
        string $apiKey,
        string $systemPrompt,
        string $userPrompt,
        float $temperature,
        int $maxTokens,
        int $timeout = 25,
    ): ?string {
        $body = [
            'contents' => [
                ['role' => 'user', 'parts' => [['text' => $userPrompt]]],
            ],
            'generationConfig' => ['temperature' => $temperature, 'maxOutputTokens' => $maxTokens],
        ];
        if (trim($systemPrompt) !== '') {
            $body['system_instruction'] = ['parts' => [['text' => $systemPrompt]]];
        }

        $data = $this->callGenerateContent($baseUrl, $model, $apiKey, $body, $timeout);
        return $data !== null ? $this->extractText($data) : null;
    }

    /**
     * Vision - the image goes in as an `inline_data` part alongside the
     * text instruction, plain base64 (no "data:mime;base64," prefix - that
     * URI form is an OpenAI-dialect convention, not Gemini's).
     */
    public function describeImage(
        string $baseUrl,
        string $model,
        string $apiKey,
        string $imageBytes,
        string $mimeType,
        string $instruction,
        int $maxTokens = 500,
        int $timeout = 45,
    ): ?string {
        $body = [
            'contents' => [[
                'role' => 'user',
                'parts' => [
                    ['inline_data' => ['mime_type' => $mimeType, 'data' => base64_encode($imageBytes)]],
                    ['text' => $instruction],
                ],
            ]],
            'generationConfig' => ['temperature' => 0.4, 'maxOutputTokens' => $maxTokens],
        ];

        $data = $this->callGenerateContent($baseUrl, $model, $apiKey, $body, $timeout);
        return $data !== null ? $this->extractText($data) : null;
    }

    /**
     * Web search via Gemini's native grounding tool (admin "test web
     * search" button only, for now - no product feature consumes this yet).
     * `groundingMetadata` presence is the one unambiguous, documented proof
     * a search actually ran (per GCMystSolver's own finding) - a plain chat
     * call never sets this tool, only this method does.
     *
     * @return array{text: ?string, searched: bool, sources: list<string>}
     */
    public function searchGrounded(string $baseUrl, string $model, string $apiKey, string $prompt, int $timeout = 25): array
    {
        $body = [
            'contents' => [['role' => 'user', 'parts' => [['text' => $prompt]]]],
            'tools' => [['google_search' => new \stdClass()]],
            'generationConfig' => ['temperature' => 0.3, 'maxOutputTokens' => 500],
        ];

        $data = $this->callGenerateContent($baseUrl, $model, $apiKey, $body, $timeout);
        if ($data === null) {
            return ['text' => null, 'searched' => false, 'sources' => []];
        }

        $candidate = $data['candidates'][0] ?? null;
        $grounding = is_array($candidate) ? ($candidate['groundingMetadata'] ?? null) : null;

        $searched = is_array($grounding) && (
            (is_array($grounding['webSearchQueries'] ?? null) && $grounding['webSearchQueries'] !== [])
            || (is_array($grounding['groundingChunks'] ?? null) && $grounding['groundingChunks'] !== [])
        );

        $sources = [];
        if (is_array($grounding) && is_array($grounding['groundingChunks'] ?? null)) {
            foreach ($grounding['groundingChunks'] as $chunk) {
                $uri = $chunk['web']['uri'] ?? null;
                if (is_string($uri) && $uri !== '') {
                    $sources[] = $uri;
                }
            }
            $sources = array_values(array_unique($sources));
        }

        return ['text' => $this->extractText($data), 'searched' => $searched, 'sources' => $sources];
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>|null decoded response, or null on any
     *         transport/HTTP/parse failure - callers treat that uniformly
     *         as "this provider didn't work", same as the OpenAI dialect.
     */
    private function callGenerateContent(string $baseUrl, string $model, string $apiKey, array $body, int $timeout): ?array
    {
        $url = rtrim($baseUrl, '/') . '/v1beta/models/' . rawurlencode($model) . ':generateContent';
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'x-goog-api-key: ' . $apiKey,
            ],
            CURLOPT_POSTFIELDS => json_encode($body, JSON_THROW_ON_ERROR),
        ]);
        $responseBody = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($responseBody === false || $status !== 200) {
            return null;
        }

        try {
            $data = json_decode((string) $responseBody, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        return is_array($data) ? $data : null;
    }

    /**
     * candidates[0].content.parts[*].text, concatenated - Gemini can split
     * one answer across several parts.
     *
     * @param array<string, mixed> $data
     */
    private function extractText(array $data): ?string
    {
        $candidate = $data['candidates'][0] ?? null;
        $parts = is_array($candidate) ? ($candidate['content']['parts'] ?? null) : null;
        if (!is_array($parts)) {
            return null;
        }

        $text = '';
        foreach ($parts as $part) {
            if (is_array($part) && is_string($part['text'] ?? null)) {
                $text .= $part['text'];
            }
        }

        return trim($text) !== '' ? trim($text) : null;
    }
}
