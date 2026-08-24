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
 *
 * throttle() enforces a floor between calls, same idea as
 * ReverseGeocodingService's Overpass/Nominatim throttle - discovered
 * against one of Stefan's real trips (50 sights, one Overpass discovery
 * run): a cluster of non-Latin-script names sitting next to each other in
 * the Overpass results fired translate() back-to-back with zero delay,
 * bursting well past Google's documented 5 req/s cap. The failing calls
 * degrade silently (see translate()'s own null-on-failure contract) to the
 * untranslated local name, indistinguishable from "translation genuinely
 * unavailable" - several monuments on that trip kept their raw Cyrillic
 * name this way even though most others translated fine in the same run.
 */
final class GoogleTranslateService
{
    private const ENDPOINT = 'https://translation.googleapis.com/language/translate2';

    // Google's own documented default is 5 requests/second/user - 300ms
    // keeps every call safely under that even with some timing jitter,
    // without adding meaningful delay for a normal trip's sight count.
    private const MIN_CALL_INTERVAL_SECONDS = 0.3;

    // Static rather than an instance property: needs to hold across every
    // translate() call within one discovery run, not just calls made
    // through whichever single instance happens to receive them (mirrors
    // ReverseGeocodingService::$lastExternalCallAt).
    private static float $lastCallAt = 0.0;

    public function __construct(private readonly Settings $settings)
    {
    }

    public function translate(string $text, string $targetLang = 'en'): ?string
    {
        $apiKey = $this->settings->getSecret('google.translate_api_key');
        if ($apiKey === null) {
            return null;
        }

        $this->throttle();

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

    private function throttle(): void
    {
        $now = microtime(true);
        $elapsed = $now - self::$lastCallAt;
        if ($elapsed < self::MIN_CALL_INTERVAL_SECONDS) {
            usleep((int) round((self::MIN_CALL_INTERVAL_SECONDS - $elapsed) * 1_000_000));
        }
        self::$lastCallAt = microtime(true);
    }
}
