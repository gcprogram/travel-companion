<?php

declare(strict_types=1);

namespace App\Service;

/**
 * Translates text via Google Cloud Translation API v2 - used for sight
 * names discovered in a non-Latin script (PoiNameTranslationService). A
 * dedicated translation API rather than routing through the chat-completion
 * AI provider (ai.* settings): broader script/language coverage than DeepL
 * (Thai included), a generous free tier (500k chars/month) that's
 * effectively unlimited for short place names, and - the actual reason
 * Stefan asked for this split - its own quota entirely separate from
 * whatever budget the "real" AI features (day-summaries, trip-title
 * suggestions, ...) are working with.
 *
 * Needs an admin-configured API key (Settings::getSecret('google.translate_api_key'),
 * /admin/settings) - returns null without one, same "never silently
 * attempted" rule as GooglePlacesService for the sibling Google API.
 */
final class GoogleTranslateService
{
    private const ENDPOINT = 'https://translation.googleapis.com/language/translate2';

    public function __construct(private readonly Settings $settings)
    {
    }

    public function translate(string $text, string $targetLang = 'en'): ?string
    {
        $apiKey = $this->settings->getSecret('google.translate_api_key');
        if ($apiKey === null) {
            return null;
        }

        $ch = curl_init(self::ENDPOINT . '?key=' . urlencode($apiKey));
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'User-Agent: travel-companion (sight name translation, contact via app owner)',
            ],
            CURLOPT_POSTFIELDS => json_encode([
                'q' => $text,
                'target' => $targetLang,
                'format' => 'text',
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

        $translated = $data['data']['translations'][0]['translatedText'] ?? null;
        if (!is_string($translated) || trim($translated) === '') {
            return null;
        }

        // The API HTML-entity-escapes its output (e.g. "&#39;" for an
        // apostrophe) even with format=text - decode before this goes
        // anywhere near a name field that's already HTML-escaped on output.
        return mb_substr(html_entity_decode(trim($translated), ENT_QUOTES | ENT_HTML5), 0, 190);
    }
}
