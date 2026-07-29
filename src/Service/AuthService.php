<?php

declare(strict_types=1);

namespace App\Service;

use App\Repository\PasswordResetRepository;
use App\Repository\UserRepository;
use App\Support\Env;

final class AuthService
{
    public const MIN_PASSWORD_LENGTH = 10;
    private const SESSION_USER_KEY = 'user_id';

    public function __construct(
        private readonly UserRepository $users,
        private readonly PasswordResetRepository $resets,
        private readonly MailService $mail,
    ) {
    }

    /**
     * @return array{ok: bool, errors: list<string>}
     */
    public function register(string $email, string $name, string $password, string $passwordRepeat): array
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
        if ($errors === []) {
            if ($this->users->findByEmail($email) !== null) {
                $errors[] = t('validation.email_taken');
            }
        }
        if ($errors !== []) {
            return ['ok' => false, 'errors' => $errors];
        }

        // The very first user becomes admin, everyone after that gets the default role.
        $role = $this->users->countAll() === 0
            ? 'admin'
            : (in_array(Env::get('DEFAULT_ROLE', 'author'), ['author', 'visitor'], true)
                ? (string) Env::get('DEFAULT_ROLE', 'author')
                : 'author');

        $userId = $this->users->create(
            $email,
            $name,
            password_hash($password, PASSWORD_DEFAULT),
            $role,
        );
        $this->loginSession($userId);

        return ['ok' => true, 'errors' => []];
    }

    public function attemptLogin(string $email, string $password): bool
    {
        $user = $this->users->findByEmail($email);

        if ($user === null || !(bool) $user['is_active'] || !password_verify($password, (string) $user['password_hash'])) {
            // Slow down timing attacks and brute-forcing.
            usleep(300_000);
            return false;
        }

        if (password_needs_rehash((string) $user['password_hash'], PASSWORD_DEFAULT)) {
            $this->users->updatePassword((int) $user['id'], password_hash($password, PASSWORD_DEFAULT));
        }

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
