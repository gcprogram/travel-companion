<?php

declare(strict_types=1);

namespace App\Service;

use App\Repository\RegistrationAttemptRepository;

/**
 * Anti-abuse rules for self-registration, independent of the normal
 * validation errors (bad email format etc.) which stay immediate/visible.
 * These checks are rate-limit feedback, not enumeration-sensitive, so
 * unlike "email taken" they can be shown directly.
 */
final class RegistrationGuard
{
    // >3 failed (never-confirmed) attempts in this window blocks the IP -
    // and since failures only age out of the window over time, this window
    // doubles as the de-facto block duration from the most recent failure.
    private const MAX_IP_FAILURES = 3;
    private const IP_FAIL_WINDOW_SECONDS = 25 * 3600;

    private const MAX_DISTINCT_EMAILS_PER_IP = 5;
    private const DISTINCT_EMAIL_WINDOW_SECONDS = 24 * 3600;

    private const EMAIL_COOLDOWN_SECONDS = 300;

    public function __construct(
        private readonly RegistrationAttemptRepository $attempts,
        private readonly Settings $settings,
    ) {
    }

    /**
     * @return string|null translated error message, or null if allowed
     */
    public function checkBlocked(string $ip, string $email): ?string
    {
        $ttl = $this->settings->getInt('registration.token_ttl_seconds');

        if ($this->attempts->countFailedForIp($ip, $ttl, self::IP_FAIL_WINDOW_SECONDS) > self::MAX_IP_FAILURES) {
            return t('validation.registration_ip_blocked');
        }
        if ($this->attempts->countDistinctEmailsForIp($ip, self::DISTINCT_EMAIL_WINDOW_SECONDS) >= self::MAX_DISTINCT_EMAILS_PER_IP) {
            return t('validation.registration_ip_blocked');
        }
        if ($this->attempts->hasRecentStartForEmail($email, self::EMAIL_COOLDOWN_SECONDS)) {
            return t('validation.registration_email_cooldown');
        }
        return null;
    }

    public function recordStarted(string $ip, string $email): void
    {
        $this->attempts->record($ip, $email, 'started');
    }

    public function recordConfirmed(string $ip, string $email): void
    {
        $this->attempts->record($ip, $email, 'confirmed');
    }
}
