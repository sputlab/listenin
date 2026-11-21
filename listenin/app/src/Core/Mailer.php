<?php
declare(strict_types=1);

namespace ListenIn\Core;

/**
 * Invio email testuali. Driver: 'mail' (funzione mail di PHP) o 'log' (sviluppo).
 * Per SMTP autenticato si può sostituire send() con PHPMailer mantenendo la stessa firma.
 */
final class Mailer
{
    public static function send(string $to, string $subject, string $body): bool
    {
        // Difesa da header injection
        if (preg_match('/[\r\n]/', $to . $subject) || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
            return false;
        }
        $from = (string) Config::get('mail.from');
        $fromName = (string) Config::get('mail.from_name', 'ListenIn');
        $encodedSubject = '=?UTF-8?B?' . base64_encode($subject) . '?=';
        $headers = [
            'From' => '=?UTF-8?B?' . base64_encode($fromName) . '?= <' . $from . '>',
            'MIME-Version' => '1.0',
            'Content-Type' => 'text/plain; charset=UTF-8',
            'Content-Transfer-Encoding' => '8bit',
            'X-Mailer' => 'ListenIn',
        ];

        if (Config::get('mail.driver') === 'log') {
            $entry = sprintf("[%s] To: %s\nSubject: %s\n\n%s\n%s\n", date('c'), $to, $subject, $body, str_repeat('-', 60));
            return (bool) file_put_contents(LISTENIN_ROOT . '/storage/logs/mail.log', $entry, FILE_APPEND | LOCK_EX);
        }
        return mail($to, $encodedSubject, $body, $headers, '-f' . $from);
    }

    public static function link(string $hashRoute): string
    {
        return rtrim((string) Config::get('app.base_url'), '/') . '/#' . $hashRoute;
    }
}
