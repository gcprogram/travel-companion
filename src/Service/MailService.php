<?php

declare(strict_types=1);

namespace App\Service;

use App\Support\Env;
use Psr\Log\LoggerInterface;

/**
 * Versand über PHP mail() – auf dem Hosting ist sendmail konfiguriert.
 * In der Entwicklungsumgebung (APP_ENV=development) werden Mails nur geloggt,
 * damit Passwort-Reset & Co. ohne Mailserver testbar sind.
 *
 * Bewusst schmal gehalten; sollte später HTML-Mail oder SMTP nötig werden,
 * wird die Implementierung gegen Symfony Mailer getauscht – die Aufrufer
 * kennen nur send().
 */
final class MailService
{
    public function __construct(private readonly LoggerInterface $logger)
    {
    }

    public function send(string $to, string $subject, string $textBody): bool
    {
        if (Env::get('APP_ENV', 'production') === 'development') {
            $this->logger->info('Mail (nur geloggt, development)', [
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
            $this->logger->error('Mailversand fehlgeschlagen', ['to' => $to, 'subject' => $subject]);
        }
        return $ok;
    }
}
