<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Outgoing email. MAIL_DRIVER:
 *   mail  → PHP mail() (configure sendmail/postfix on the VPS)
 *   file  → writes .eml files to storage/mail (development only; never in production)
 * An SMTP driver is listed as a planned integration in docs/FEATURES.md.
 */
final class Mailer
{
    public function send(string $to, string $subject, string $textBody): bool
    {
        $from = (string) env('MAIL_FROM', 'no-reply@localhost');
        $driver = (string) env('MAIL_DRIVER', 'file');
        $subject = str_replace(["\r", "\n"], ' ', $subject);
        if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
            return false;
        }
        if ($driver === 'mail') {
            return mail($to, $subject, $textBody, ['From' => $from, 'Content-Type' => 'text/plain; charset=utf-8']);
        }
        $dir = base_path('storage/mail');
        if (!is_dir($dir)) {
            @mkdir($dir, 0700, true);
        }
        $file = $dir . '/' . gmdate('Ymd-His') . '-' . bin2hex(random_bytes(4)) . '.eml';
        return (bool) file_put_contents($file, "From: $from\nTo: $to\nSubject: $subject\n\n$textBody\n");
    }
}
