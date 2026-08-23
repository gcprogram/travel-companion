<?php

declare(strict_types=1);

namespace App\Service;

/**
 * Provider presets for the "add AI config" form - picking one fills in a
 * sensible base URL, left editable, same idea as GCToolkit-android's own
 * provider dropdown. Every preset here is an OpenAI-compatible chat-
 * completions endpoint (Bearer-token auth, GET {base}/models, POST
 * {base}/chat/completions): that's the only dialect AiSummaryService/
 * AiTripMetaService actually speak (CLAUDE.md's documented decision -
 * native Anthropic/Gemini adapters are deferred until a feature genuinely
 * needs their vision/websearch abilities, e.g. a future "vision" slot).
 * Anthropic/Google are deliberately NOT in this list - listing them here
 * would let an admin pick a config that then silently fails wherever it's
 * actually used, which is worse than not offering the choice yet.
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
        'custom' => ['label' => 'Eigener Anbieter (OpenAI-kompatibel)', 'baseUrl' => ''],
    ];

    public static function labelFor(string $provider): string
    {
        return self::PRESETS[$provider]['label'] ?? $provider;
    }
}
