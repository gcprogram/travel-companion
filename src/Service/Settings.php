<?php

declare(strict_types=1);

namespace App\Service;

use App\Repository\SettingsRepository;

/**
 * Admin-changeable runtime configuration, DB-backed with hardcoded
 * defaults. Unlike Env (deploy-time, file-only), these can be edited from
 * the admin UI without server access. Reads are cached per request.
 *
 * Note: always resolve values inside services at call time, never in
 * container factories - the compiled DI container would freeze them.
 */
final class Settings
{
    public const REGISTRATION_MODE_EMAIL = 'email';
    public const REGISTRATION_MODE_ADMIN_APPROVAL = 'admin_approval';

    /** @var array<string, string> */
    private const DEFAULTS = [
        // Storage quota per role, in bytes. Admins are uncapped (no key).
        'quota.storage.user' => '52428800',        // 50 MB
        'quota.storage.ai_user' => '52428800',     // 50 MB
        'quota.storage.manager' => '524288000',    // 500 MB
        // AI token budget per month (enforced once phase 5 lands).
        'quota.ai.ai_user' => '200000',
        'quota.ai.manager' => '1000000',
        // 'email': confirming the address activates the account.
        // 'admin_approval': confirmed accounts additionally wait for an admin.
        'registration.mode' => self::REGISTRATION_MODE_EMAIL,
        // Confirmation-link validity. Kept short by design (an unconfirmed
        // registration counts as a failed attempt for IP blocking).
        'registration.token_ttl_seconds' => '300',
    ];

    /** @var array<string, string>|null */
    private ?array $cache = null;

    public function __construct(private readonly SettingsRepository $repository)
    {
    }

    public function get(string $key): string
    {
        $this->cache ??= $this->repository->all();
        return $this->cache[$key] ?? self::DEFAULTS[$key] ?? '';
    }

    public function getInt(string $key): int
    {
        return (int) $this->get($key);
    }

    public function set(string $key, string $value): void
    {
        $this->repository->set($key, $value);
        $this->cache = null;
    }

    /**
     * @return array<string, string> effective values for every known key
     */
    public function allEffective(): array
    {
        $out = [];
        foreach (array_keys(self::DEFAULTS) as $key) {
            $out[$key] = $this->get($key);
        }
        return $out;
    }
}
