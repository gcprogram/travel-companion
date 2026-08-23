<?php

declare(strict_types=1);

namespace App\Service;

use App\Repository\AiProviderConfigRepository;

/**
 * Resolves a named purpose slot (e.g. 'main' - day-entry summaries, trip-
 * title/tags suggestions; more slots like 'vision' expected later) to the
 * saved ai_provider_configs row an admin assigned it, plus its decrypted
 * API key. The slot assignment itself is a plain Settings value
 * ('ai.slot.<name>' = config id) rather than a column anywhere, so adding a
 * new slot is just a new dropdown on the settings page, no schema change.
 *
 * Best-effort like everywhere else this pattern appears: no slot assigned,
 * no config found, or no key stored all return null the same way - the
 * caller already knows how to treat "AI unavailable" as a cosmetic gap.
 */
final class AiProviderResolver
{
    public function __construct(
        private readonly Settings $settings,
        private readonly AiProviderConfigRepository $configs,
    ) {
    }

    /**
     * @return array{baseUrl: string, model: string, apiKey: string}|null
     */
    public function resolve(string $slot): ?array
    {
        $configId = $this->settings->getInt('ai.slot.' . $slot);
        if ($configId <= 0) {
            return null;
        }

        $config = $this->configs->findById($configId);
        if ($config === null) {
            return null;
        }

        $apiKey = $this->settings->getSecret('ai.provider.' . $configId . '.api_key');
        if ($apiKey === null) {
            return null;
        }

        return [
            'baseUrl' => rtrim((string) $config['base_url'], '/'),
            'model' => (string) $config['model'],
            'apiKey' => $apiKey,
        ];
    }
}
