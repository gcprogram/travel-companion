<?php

declare(strict_types=1);

namespace App\Service;

/**
 * Provider presets for the "add AI config" form - picking one fills in a
 * sensible base URL, left editable, same idea as GCToolkit-android's own
 * provider dropdown. Every preset except 'google' is an OpenAI-compatible
 * chat-completions endpoint (Bearer-token auth, GET {base}/models, POST
 * {base}/chat/completions) - that dialect is still what
 * AiSummaryService/AiTripMetaService/etc. speak by default.
 *
 * 'google' is Gemini's OWN native REST dialect (GoogleGeminiClient), added
 * once vision (AiVisionCaptionService/PhotoCaptionHandler) and web-search
 * grounding genuinely needed it - Google's OpenAI-compatibility shim
 * silently drops the search-grounding tool, so native support was the only
 * way to get real web search at all (see GoogleGeminiClient's docblock).
 * Every OpenAI-dialect service also branches on provider==='google' in its
 * own callProvider() so assigning a Google config to ANY slot (not just
 * 'vision') still works instead of silently sending the wrong wire format.
 * Anthropic is still deliberately NOT in this list - no feature needs its
 * native abilities yet, so offering it would just let an admin pick a
 * config that silently fails wherever it's used.
 */
final class AiProviderPresets
{
    /**
     * @var array<string, array{label: string, baseUrl: string}>
     */
    public const PRESETS = [
        'openai' => ['label' => 'OpenAI', 'baseUrl' => 'https://api.openai.com/v1'],
        // DeepSeek's API also accepts a bare (no /v1) base URL, but every
        // call site in this app (chat completions, models-fetch) treats
        // base_url as "the exact parent of /chat/completions and /models"
        // with no normalization - the /v1 variant keeps that one invariant
        // true for every preset instead of special-casing this one.
        'deepseek' => ['label' => 'DeepSeek', 'baseUrl' => 'https://api.deepseek.com/v1'],
        'openrouter' => ['label' => 'OpenRouter', 'baseUrl' => 'https://openrouter.ai/api/v1'],
        'ollama' => ['label' => 'Ollama (lokal/eigener Server)', 'baseUrl' => 'http://localhost:11434/v1'],
        'nvidia' => ['label' => 'NVIDIA', 'baseUrl' => 'https://integrate.api.nvidia.com/v1'],
        // No /v1 suffix - GoogleGeminiClient builds the full
        // /v1beta/models/{model}:generateContent path itself.
        'google' => ['label' => 'Google Gemini', 'baseUrl' => 'https://generativelanguage.googleapis.com'],
        'custom' => ['label' => 'Eigener Anbieter (OpenAI-kompatibel)', 'baseUrl' => ''],
    ];

    public static function labelFor(string $provider): string
    {
        return self::PRESETS[$provider]['label'] ?? $provider;
    }
}
