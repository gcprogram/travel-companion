<?php

declare(strict_types=1);

namespace App\Job;

use App\Service\MailService;
use App\Support\Env;
use App\Support\Translator;
use Psr\Log\LoggerInterface;

/**
 * Job type "mail.admin_notify". Payload: {"email": string, "name": string}.
 * Dispatched on every registration attempt so ADMIN_EMAIL (.env) gets
 * notified, mirroring the confirmation mail rather than blocking the
 * registration request on it (mail() is synchronous and the host caps
 * request time at 30s).
 *
 * Jobs run outside any HTTP request, so LocaleMiddleware never sets the
 * translator's locale - t() would otherwise silently return raw keys
 * (Translator's catalog is only loaded via setLocale()). Admin mail is
 * always German since ADMIN_EMAIL is Stefan's own address.
 */
final class AdminNotifyHandler implements JobHandlerInterface
{
    public function __construct(
        private readonly MailService $mail,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function handle(array $payload): void
    {
        $adminEmail = (string) Env::get('ADMIN_EMAIL', '');
        if ($adminEmail === '') {
            $this->logger->info('Registration notification skipped: ADMIN_EMAIL not configured');
            return;
        }

        Translator::setLocale('de');

        $email = (string) ($payload['email'] ?? '');
        $name = (string) ($payload['name'] ?? '');

        $this->mail->send(
            $adminEmail,
            t('mail.admin_notify_subject'),
            t('mail.admin_notify_body', ['name' => $name, 'email' => $email]),
        );
    }
}
