<?php

declare(strict_types=1);

namespace App\Service;

/**
 * Role model and role-derived limits. Roles are stored as the users.role
 * enum; this class is the single place that knows what each role may do,
 * so checks can't drift apart across controllers (same idea as TripAccess).
 */
final class UserRole
{
    public const ADMIN = 'admin';
    public const MANAGER = 'manager';
    public const AI_USER = 'ai_user';
    public const USER = 'user';

    public const ALL = [self::ADMIN, self::MANAGER, self::AI_USER, self::USER];

    public function __construct(private readonly Settings $settings)
    {
    }

    public function isValid(string $role): bool
    {
        return in_array($role, self::ALL, true);
    }

    /**
     * @param array<string, mixed> $user
     */
    public function canUseAi(array $user): bool
    {
        return in_array($user['role'], [self::ADMIN, self::MANAGER, self::AI_USER], true);
    }

    /**
     * Effective storage quota in bytes; null = unlimited (admins).
     * A per-user override beats the role default.
     *
     * @param array<string, mixed> $user
     */
    public function storageQuotaBytes(array $user): ?int
    {
        if ($user['storage_quota_override_bytes'] !== null) {
            return (int) $user['storage_quota_override_bytes'];
        }
        if ($user['role'] === self::ADMIN) {
            return null;
        }
        return $this->settings->getInt('quota.storage.' . $user['role']);
    }

    /**
     * Monthly AI token budget; null = unlimited (admins), 0 = no AI access.
     *
     * @param array<string, mixed> $user
     */
    public function aiTokenBudget(array $user): ?int
    {
        if ($user['role'] === self::ADMIN) {
            return null;
        }
        if (!$this->canUseAi($user)) {
            return 0;
        }
        return $this->settings->getInt('quota.ai.' . $user['role']);
    }
}
