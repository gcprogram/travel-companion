<?php

declare(strict_types=1);

namespace App\Service;

/**
 * Translates text via a chat-completion AI provider (the 'translate' slot,
 * AiProviderResolver) - the alternative Stefan asked for once it turned out
 * GoogleTranslateService is a dead end for anyone (including him) without a
 * billed Google Cloud account: Cloud Translation's key-based v2 API only
 * works on a project with billing enabled, even within the "free" 500k
 * chars/month tier. PoiNameTranslationService tries Google first (for
 * whoever does have billing set up) and falls back to this when that's
 * unavailable or fails - see that class for the orchestration.
 *
 * This service is just an HTTP client, like every other AiProviderResolver
 * caller - it never runs a model itself. This app's own hosting (Bitpalast)
 * can't execute any model regardless of size (exec/shell_exec/proc_open
 * disabled), so whatever 'translate' points at - one of the existing
 * general-purpose chat providers (NVIDIA, DeepSeek, ...; a simple/cheap
 * model is plenty) or a dedicated translation model like TranslateGemma -
 * has to already be reachable over HTTP from somewhere, e.g. a cloud API or
 * something self-hosted on Stefan's own machine.
 *
 * Prompt (professional-translator framing + "produce only the translation"
 * instruction + two blank lines before the text) intentionally matches the
 * documented format TranslateGemma (Stefan's own suggestion) expects to
 * perform well, rather than a plain "translate this" one-liner. It doesn't
 * name a specific source language (OSM tags only tell us the script is
 * non-Latin, never the actual language), which is a simplification of
 * TranslateGemma's own template, but the same prompt still reads as a
 * perfectly ordinary instruction to any other general-purpose chat model in
 * the fallback chain - no per-model special-casing needed.
 */
final class AiTranslationService
{
    public function __construct(private readonly AiProviderResolver $resolver)
    {
    }

    public function translate(string $text, string $targetLang = 'en'): ?string
    {
        $prompt = $this->buildPrompt($text, $targetLang);

        foreach ($this->resolver->resolveChain('translate') as $provider) {
            $result = $this->callProvider($provider, $prompt);
            if ($result !== null) {
                return $result;
            }
        }

        return null;
    }

    private function buildPrompt(string $text, string $targetLang): string
    {
        $targetName = self::LANGUAGE_NAMES[$targetLang] ?? $targetLang;

        return "You are a professional translator. Your goal is to accurately convey the meaning "
            . "and nuances of the original text while adhering to {$targetName} ({$targetLang}) grammar, "
            . "vocabulary, and cultural sensitivities.\n"
            . "Produce only the {$targetName} translation, without any additional explanations, "
            . "quotation marks, or commentary. Please translate the following text into {$targetName} ({$targetLang}):"
            . "\n\n\n{$text}";
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
                    ['role' => 'user', 'content' => $prompt],
                ],
                // A short place name plus, for a reasoning model, room for
                // its internal chain-of-thought before the answer - same
                // issue observed live against a real NVIDIA reasoning
                // model in AdminAiProviderController::test().
                'max_tokens' => 300,
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

        return $this->clean($content);
    }

    /**
     * Some models wrap a short answer in quotes or a markdown code fence
     * even when told not to (same defensive cleanup as AiTripMetaService's
     * JSON parsing) - stripped rather than left in a place name.
     */
    private function clean(string $content): string
    {
        $cleaned = trim($content);
        $cleaned = trim(preg_replace('/^```[a-z]*|```$/mi', '', $cleaned) ?? $cleaned);
        $cleaned = trim($cleaned, "\"'“”„ \t\n\r");
        return mb_substr($cleaned, 0, 190);
    }

    /**
     * @var array<string, string>
     */
    private const LANGUAGE_NAMES = [
        'en' => 'English',
        'de' => 'German',
    ];
}
