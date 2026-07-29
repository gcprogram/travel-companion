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
            return ['ok' => false, 'errors' => ['Die Registrierung ist derzeit geschlossen.']];
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Bitte eine gültige E-Mail-Adresse angeben.';
        }
        if (mb_strlen(trim($name)) < 2) {
            $errors[] = 'Bitte einen Namen angeben (mindestens 2 Zeichen).';
        }
        if (mb_strlen($password) < self::MIN_PASSWORD_LENGTH) {
            $errors[] = sprintf('Das Passwort braucht mindestens %d Zeichen.', self::MIN_PASSWORD_LENGTH);
        }
        if ($password !== $passwordRepeat) {
            $errors[] = 'Die Passwörter stimmen nicht überein.';
        }
        if ($errors === []) {
            if ($this->users->findByEmail($email) !== null) {
                $errors[] = 'Für diese E-Mail-Adresse existiert bereits ein Konto.';
            }
        }
        if ($errors !== []) {
            return ['ok' => false, 'errors' => $errors];
        }

        // Der allererste Benutzer wird Admin, alle weiteren bekommen die Standardrolle.
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
            // Timing-Angriffe erschweren und Brute-Force verlangsamen.
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
     * Verschickt bei bekannter Adresse einen Reset-Link. Nach außen ist die
     * Antwort immer gleich, damit sich keine Konten "erraten" lassen.
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

        $link = rtrim((string) Env::get('APP_URL', ''), '/') . '/passwort-reset?token=' . $token;
        $this->mail->send(
            (string) $user['email'],
            'Passwort zurücksetzen',
            "Hallo {$user['name']},\n\n"
            . "für dein Konto wurde ein neues Passwort angefordert. Über diesen Link kannst du eines setzen (1 Stunde gültig):\n\n"
            . $link . "\n\n"
            . "Falls du das nicht warst, kannst du diese E-Mail ignorieren.\n",
        );
    }

    /**
     * @return array{ok: bool, errors: list<string>}
     */
    public function resetPassword(string $token, string $password, string $passwordRepeat): array
    {
        $errors = [];
        if (mb_strlen($password) < self::MIN_PASSWORD_LENGTH) {
            $errors[] = sprintf('Das Passwort braucht mindestens %d Zeichen.', self::MIN_PASSWORD_LENGTH);
        }
        if ($password !== $passwordRepeat) {
            $errors[] = 'Die Passwörter stimmen nicht überein.';
        }
        if ($errors !== []) {
            return ['ok' => false, 'errors' => $errors];
        }

        $reset = $this->resets->findValidByTokenHash(hash('sha256', $token));
        if ($reset === null) {
            return ['ok' => false, 'errors' => ['Der Link ist ungültig oder abgelaufen. Bitte fordere einen neuen an.']];
        }

        $userId = (int) $reset['user_id'];
        $this->users->updatePassword($userId, password_hash($password, PASSWORD_DEFAULT));
        $this->resets->deleteForUser($userId);

        return ['ok' => true, 'errors' => []];
    }

    private function loginSession(int $userId): void
    {
        session_regenerate_id(true); // Session-Fixation verhindern
        $_SESSION[self::SESSION_USER_KEY] = $userId;
    }
}
