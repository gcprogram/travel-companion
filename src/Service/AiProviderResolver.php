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
    /**
     * Every slot name the settings page/AdminAiProviderController::delete()
     * need to enumerate (e.g. to clear a deleted config's assignment) -
     * resolve()/resolveChain() themselves work with any string, this list
     * only exists for the handful of places that need to know them all.
     *
     * @var list<string>
     */
    public const KNOWN_SLOTS = ['main', 'vision', 'translate'];

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

        return $this->toCandidate($configId);
    }

    /**
     * Same as resolve(), but followed by every other saved config (in id
     * order) as fallbacks - a slot with no assignment still returns an
     * empty list (an admin choosing "off" must stay off, not silently fall
     * back to whatever else happens to be configured). Used by callers that
     * want to retry a different model/provider on a rate limit or error
     * (Stefan's ask) instead of giving up after a single failed call.
     *
     * @return list<array{id: int, baseUrl: string, model: string, apiKey: string}>
     */
    public function resolveChain(string $slot): array
    {
        $primaryId = $this->settings->getInt('ai.slot.' . $slot);
        if ($primaryId <= 0) {
            return [];
        }

        $ids = [$primaryId];
        foreach ($this->configs->findAll() as $row) {
            $id = (int) $row['id'];
            if ($id !== $primaryId) {
                $ids[] = $id;
            }
        }

        $chain = [];
        foreach ($ids as $id) {
            $candidate = $this->toCandidate($id);
            if ($candidate !== null) {
                $chain[] = ['id' => $id, ...$candidate];
            }
        }
        return $chain;
    }

    /**
     * @return array{baseUrl: string, model: string, apiKey: string}|null
     */
    private function toCandidate(int $configId): ?array
    {
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
