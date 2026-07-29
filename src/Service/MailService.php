<?php

declare(strict_types=1);

namespace App\Service;

use App\Support\Env;
use Psr\Log\LoggerInterface;

/**
 * Sends via PHP mail() – sendmail is configured on the hosting.
 * In the development environment (APP_ENV=development), mail is only
 * logged, so password reset & co. are testable without a mail server.
 *
 * Deliberately kept narrow; if HTML mail or SMTP is needed later, the
 * implementation gets swapped for Symfony Mailer – callers only know send().
 */
final class MailService
{
    public function __construct(private readonly LoggerInterface $logger)
    {
    }

    public function send(string $to, string $subject, string $textBody): bool
    {
        if (Env::get('APP_ENV', 'production') === 'development') {
            $this->logger->info('Mail (logged only, development)', [
                'to' => $to,
                'subject' => $subject,
                'body' => $textBody,
            ]);
            return true;
        }

        $fromAddress = Env::require('MAIL_FROM');
        $fromName = Env::get('MAIL_FROM_NAME', 'Travel Companion');

        $encodedSubject = '=?UTF-8?B?' . base64_encode($subject) . '?=';
        $encodedFromName = '=?UTF-8?B?' . base64_encode((string) $fromName) . '?=';

        $headers = implode("\r\n", [
            'From: ' . $encodedFromName . ' <' . $fromAddress . '>',
            'MIME-Version: 1.0',
            'Content-Type: text/plain; charset=UTF-8',
            'Content-Transfer-Encoding: 8bit',
        ]);

        $ok = mail($to, $encodedSubject, $textBody, $headers);
        if (!$ok) {
            $this->logger->error('Failed to send mail', ['to' => $to, 'subject' => $subject]);
        }
        return $ok;
    }
}
