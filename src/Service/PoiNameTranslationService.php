<?php

declare(strict_types=1);

namespace App\Service;

use App\Repository\PoiNameTranslationRepository;

/**
 * Translates a non-Latin-script sight name (Thai, Cyrillic, CJK, Arabic, ...)
 * into English via the same OpenAI-compatible AI provider AiSummaryService
 * uses (config in /admin/settings) - see PoiDiscoveryService for why this
 * exists: an OSM element without a name:de/name:en tag would otherwise show
 * up in the sights list in a script most travellers can't read at all,
 * which used to mean silently keeping the unreadable local name (never
 * discarding the sight itself - that's still true here, just no longer the
 * ONLY option).
 *
 * Best-effort like AiSummaryService: returns null on any failure (no key
 * configured, network, bad response) rather than throwing - the caller
 * already knows to fall back to the raw local name, same "cosmetic gap,
 * never worth failing over" reasoning as everywhere else this pattern is
 * used.
 */
final class PoiNameTranslationService
{
    public function __construct(
        private readonly Settings $settings,
        private readonly PoiNameTranslationRepository $cache,
    ) {
    }

    public function translate(string $localName): ?string
    {
        $cached = $this->cache->find($localName);
        if ($cached !== null) {
            return $cached;
        }

        $apiKey = $this->settings->getSecret('ai.api_key');
        if ($apiKey === null) {
            return null;
        }
        $baseUrl = rtrim($this->settings->get('ai.base_url'), '/');
        $model = $this->settings->get('ai.model');
        if ($baseUrl === '' || $model === '') {
            return null;
        }

        $ch = curl_init($baseUrl . '/chat/completions');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $apiKey,
            ],
            CURLOPT_POSTFIELDS => json_encode([
                'model' => $model,
                'messages' => [
                    ['role' => 'system', 'content' =>
                        'Du übersetzt Namen von Sehenswürdigkeiten und Orten für Reisende, die die '
                        . 'Originalschrift nicht lesen können, ins Englische. Antworte NUR mit dem übersetzten '
                        . 'Namen - keine Anführungszeichen, keine Erklärung, kein Zusatz. Bei Eigennamen ohne '
                        . 'sinnvolle wörtliche Übersetzung (z. B. Personennamen) eine latinisierte/transkribierte '
                        . 'Form verwenden statt wörtlich zu übersetzen.'],
                    ['role' => 'user', 'content' => $localName],
                ],
                'max_tokens' => 60,
                'temperature' => 0.2,
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

        $translated = mb_substr(trim($content, " \t\n\r\0\x0B\"'"), 0, 190);
        if ($translated === '') {
            return null;
        }

        $this->cache->store($localName, $translated);
        return $translated;
    }
}
