<?php

declare(strict_types=1);

namespace App\Service;

/**
 * "Generiere Tagesbeschreibung" (Stefan's ask): the deep, per-day
 * counterpart to AiTripDescriptionService's short trip-level overview -
 * built from a single day's weather, photos/videos (captions, addresses,
 * persons, assigned sight/geocache) and visited sights/geocaches, even when
 * no diary text exists yet for that day. Distinct from AiSummaryService's
 * day_entry.summarize (which only ever condenses an ALREADY-written body);
 * this one generates the body content itself, hence its own suggestion
 * column (day_entries.ai_description_suggestion) rather than reusing
 * ai_summary. $depth controls the OUTPUT's length/detail - the max_tokens
 * ceiling is admin-configurable (Settings, shared with the trip overview)
 * rather than hardcoded, so a verbose model has room to actually finish.
 */
final class AiDayDescriptionService
{
    private const DEPTHS = [
        'short' => 'Schreibe einen kurzen Absatz (ca. 80-120 Wörter).',
        'medium' => 'Schreibe 2-4 Absätze (ca. 250-400 Wörter).',
        'long' => 'Schreibe eine ausführliche, detailreiche Beschreibung mit mehreren Absätzen (ca. 500-900 Wörter).',
    ];

    public function __construct(
        private readonly AiProviderResolver $resolver,
        private readonly Settings $settings,
    ) {
    }

    /**
     * @param array<string, mixed> $context see DayEntrySuggestDescriptionHandler::handle()
     */
    public function suggest(array $context, string $depth): ?string
    {
        $instruction = self::DEPTHS[$depth] ?? self::DEPTHS['medium'];
        $prompt = $this->buildPrompt($context);
        $maxTokens = $this->settings->getInt('ai.description_max_tokens');

        foreach ($this->resolver->resolveChain('main') as $provider) {
            $result = $this->callProvider($provider, $prompt, $instruction, $maxTokens);
            if ($result !== null) {
                return $result;
            }
        }

        return null;
    }

    /**
     * @param array{baseUrl: string, model: string, apiKey: string} $provider
     */
    private function callProvider(array $provider, string $prompt, string $instruction, int $maxTokens): ?string
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
                        'Du schreibst für ein privates Reisetagebuch die Beschreibung EINES Reisetages auf '
                        . 'Deutsch, basierend NUR auf den gegebenen Fakten - nichts erfinden, keine Orte/'
                        . 'Ereignisse hinzudichten, die nicht genannt sind. Falls schon ein von einem Menschen '
                        . 'geschriebener Text für diesen Tag vorliegt, dessen Ton/Inhalt aufgreifen und '
                        . 'sinnvoll erweitern statt ihn zu ignorieren. Fließtext, keine Überschrift, keine '
                        . 'Aufzählung, keine Anführungszeichen. ' . $instruction],
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
        $lines[] = 'Datum: ' . $context['entryDate'];
        if ($context['locationName'] !== null && $context['locationName'] !== '') {
            $lines[] = 'Ort: ' . $context['locationName'];
        }
        if ($context['weather'] !== null) {
            $lines[] = 'Wetter: ' . $context['weather'];
        }
        if ($context['existingTitle'] !== null && $context['existingTitle'] !== '') {
            $lines[] = 'Bisheriger Titel: ' . $context['existingTitle'];
        }
        if ($context['existingBody'] !== null && $context['existingBody'] !== '') {
            $lines[] = '';
            $lines[] = 'Bisheriger Text (evtl. von einem Menschen geschrieben - Ton/Inhalt aufgreifen):';
            $lines[] = $context['existingBody'];
        }

        if ($context['sights'] !== []) {
            $lines[] = '';
            $lines[] = 'Besuchte Sehenswürdigkeiten/Geocaches an diesem Tag:';
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
