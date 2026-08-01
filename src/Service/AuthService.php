<?php

declare(strict_types=1);

namespace App\Service;

use App\Repository\EmailConfirmationRepository;
use App\Repository\JobRepository;
use App\Repository\LoginAttemptRepository;
use App\Repository\PasswordResetRepository;
use App\Repository\UserRepository;
use App\Support\Env;

final class AuthService
{
    public const MIN_PASSWORD_LENGTH = 10;
    private const SESSION_USER_KEY = 'user_id';

    // Basic brute-force throttle: this many failed attempts per IP within
    // the window blocks further tries, independent of which email was used.
    private const MAX_LOGIN_ATTEMPTS = 8;
    private const LOGIN_WINDOW_SECONDS = 900;

    public function __construct(
        private readonly UserRepository $users,
        private readonly PasswordResetRepository $resets,
        private readonly EmailConfirmationRepository $confirmations,
        private readonly LoginAttemptRepository $attempts,
        private readonly RegistrationGuard $registrationGuard,
        private readonly Settings $settings,
        private readonly MailService $mail,
        private readonly JobRepository $jobs,
    ) {
    }

    /**
     * Always returns the same shape/message for "validation passed" whether
     * or not the email is already registered - only real anti-abuse
     * rejections (rate limits) and input errors (bad format, weak password)
     * are distinguishable from the outside. No auto-login: an account only
     * becomes usable after the confirmation link is clicked (and, in
     * admin_approval mode, after an admin approves it too).
     *
     * @return array{ok: bool, errors: list<string>}
     */
    public function register(string $email, string $name, string $password, string $passwordRepeat, string $ip): array
    {
        $errors = [];

        if (!Env::bool('REGISTRATION_OPEN', true)) {
            return ['ok' => false, 'errors' => [t('flash.registration_closed')]];
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = t('validation.email_invalid');
        }
        if (mb_strlen(trim($name)) < 2) {
            $errors[] = t('validation.name_required');
        }
        if (mb_strlen($password) < self::MIN_PASSWORD_LENGTH) {
            $errors[] = t('validation.password_min_length', ['min' => self::MIN_PASSWORD_LENGTH]);
        }
        if ($password !== $passwordRepeat) {
            $errors[] = t('validation.password_mismatch');
        }
        if ($errors !== []) {
            return ['ok' => false, 'errors' => $errors];
        }

        $normalizedEmail = mb_strtolower(trim($email));
        $blockReason = $this->registrationGuard->checkBlocked($ip, $normalizedEmail);
        if ($blockReason !== null) {
            return ['ok' => false, 'errors' => [$blockReason]];
        }

        // Recorded regardless of whether the email is taken, so the rate
        // limits apply uniformly and can't be probed around.
        $this->registrationGuard->recordStarted($ip, $normalizedEmail);

        if ($this->users->findByEmail($normalizedEmail) !== null) {
            return ['ok' => true, 'errors' => []];
        }

        // The very first user becomes admin, everyone after that gets the default role.
        $role = $this->users->countAll() === 0
            ? UserRole::ADMIN
            : (in_array(Env::get('DEFAULT_ROLE', UserRole::USER), [UserRole::USER, UserRole::AI_USER, UserRole::MANAGER], true)
                ? (string) Env::get('DEFAULT_ROLE', UserRole::USER)
                : UserRole::USER);

        $userId = $this->users->create(
            $normalizedEmail,
            $name,
            password_hash($password, PASSWORD_DEFAULT),
            $role,
            active: false,
        );

        $token = bin2hex(random_bytes(32));
        $ttlSeconds = $this->settings->getInt('registration.token_ttl_seconds');
        $this->confirmations->create(
            $userId,
            hash('sha256', $token),
            new \DateTimeImmutable('+' . $ttlSeconds . ' seconds', new \DateTimeZone('UTC')),
        );

        $link = rtrim((string) Env::get('APP_URL', ''), '/') . '/confirm-email?token=' . $token;
        $this->mail->send(
            $normalizedEmail,
            t('mail.confirm_email_subject'),
            t('mail.confirm_email_body', ['name' => $name, 'link' => $link]),
        );

        $this->jobs->dispatch('mail.admin_notify', ['email' => $normalizedEmail, 'name' => $name]);

        return ['ok' => true, 'errors' => []];
    }

    /**
     * @return array{ok: bool, error: string, loggedIn: bool}
     */
    public function confirmEmail(string $token, string $ip): array
    {
        $confirmation = $this->confirmations->findValidByTokenHash(hash('sha256', $token));
        if ($confirmation === null) {
            return ['ok' => false, 'error' => t('validation.confirm_link_invalid'), 'loggedIn' => false];
        }

        $userId = (int) $confirmation['user_id'];
        $user = $this->users->findById($userId);
        if ($user === null) {
            return ['ok' => false, 'error' => t('validation.confirm_link_invalid'), 'loggedIn' => false];
        }

        $this->users->markEmailConfirmed($userId);
        $this->confirmations->deleteForUser($userId);
        $this->registrationGuard->recordConfirmed($ip, (string) $user['email']);

        if ($this->settings->get('registration.mode') === Settings::REGISTRATION_MODE_ADMIN_APPROVAL) {
            return ['ok' => true, 'error' => '', 'loggedIn' => false];
        }

        $this->users->markApprovedAndActive($userId);
        $this->loginSession($userId);
        return ['ok' => true, 'error' => '', 'loggedIn' => true];
    }

    public function isLockedOut(string $ip): bool
    {
        return $this->attempts->countRecent($ip, self::LOGIN_WINDOW_SECONDS) >= self::MAX_LOGIN_ATTEMPTS;
    }

    public function attemptLogin(string $email, string $password, string $ip): bool
    {
        $user = $this->users->findByEmail($email);

        if ($user === null || !(bool) $user['is_active'] || !password_verify($password, (string) $user['password_hash'])) {
            $this->attempts->record($ip, $email !== '' ? $email : null);
            usleep(300_000); // Slow down timing attacks and brute-forcing.
            return false;
        }

        if (password_needs_rehash((string) $user['password_hash'], PASSWORD_DEFAULT)) {
            $this->users->updatePassword((int) $user['id'], password_hash($password, PASSWORD_DEFAULT));
        }

        $this->users->recordLogin((int) $user['id']);
        $this->loginSession((int) $user['id']);
        return true;
    }

    public function logout(): void
    {
        $_SESSION = [];
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
            session_destroy();
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    public function currentUser(): ?array
    {
        $id = $_SESSION[self::SESSION_USER_KEY] ?? null;
        if (!is_int($id)) {
            return null;
        }
        $user = $this->users->findById($id);
        return ($user !== null && (bool) $user['is_active']) ? $user : null;
    }

    /**
     * Sends a reset link if the address is known. The response to the caller
     * is always the same, so accounts can't be "guessed" from the outside.
     */
    public function requestPasswordReset(string $email): void
    {
        $user = $this->users->findByEmail($email);
        if ($user === null) {
            return;
        }

        $token = bin2hex(random_bytes(32));
        $this->resets->create(
            (int) $user['id'],
            hash('sha256', $token),
            new \DateTimeImmutable('+1 hour', new \DateTimeZone('UTC')),
        );

        $link = rtrim((string) Env::get('APP_URL', ''), '/') . '/reset-password?token=' . $token;
        $this->mail->send(
            (string) $user['email'],
            t('mail.password_reset_subject'),
            t('mail.password_reset_body', ['name' => (string) $user['name'], 'link' => $link]),
        );
    }

    /**
     * @return array{ok: bool, errors: list<string>}
     */
    public function resetPassword(string $token, string $password, string $passwordRepeat): array
    {
        $errors = [];
        if (mb_strlen($password) < self::MIN_PASSWORD_LENGTH) {
            $errors[] = t('validation.password_min_length', ['min' => self::MIN_PASSWORD_LENGTH]);
        }
        if ($password !== $passwordRepeat) {
            $errors[] = t('validation.password_mismatch');
        }
        if ($errors !== []) {
            return ['ok' => false, 'errors' => $errors];
        }

        $reset = $this->resets->findValidByTokenHash(hash('sha256', $token));
        if ($reset === null) {
            return ['ok' => false, 'errors' => [t('validation.reset_link_invalid')]];
        }

        $userId = (int) $reset['user_id'];
        $this->users->updatePassword($userId, password_hash($password, PASSWORD_DEFAULT));
        $this->resets->deleteForUser($userId);

        return ['ok' => true, 'errors' => []];
    }

    private function loginSession(int $userId): void
    {
        session_regenerate_id(true); // Prevent session fixation
        $_SESSION[self::SESSION_USER_KEY] = $userId;
    }
}
