<?php

declare(strict_types=1);

namespace Academy\Infrastructure\Notifications;

use Academy\Domain\Notifications\EmailDeliveryMessage;

/**
 * Builds the SMTP DATA payload. HTML is an alternative part, not a second send.
 */
final class AcademyEmailMime
{
    public static function data(
        string $fromName,
        string $fromAddress,
        string $to,
        EmailDeliveryMessage $message,
    ): string {
        $headers = 'From: ' . self::encodeHeader($fromName !== '' ? $fromName : $fromAddress)
            . ' <' . $fromAddress . ">\r\n"
            . 'To: <' . $to . ">\r\n"
            . 'Subject: ' . self::encodeHeader($message->subject) . "\r\n"
            . "MIME-Version: 1.0\r\n"
            . 'X-Template-Key: ' . self::headerToken($message->templateKey) . "\r\n"
            . 'X-Idempotency-Key: ' . self::headerToken($message->idempotencyKey) . "\r\n";

        $plain = self::normalise($message->bodyText);
        $html = $message->bodyHtml;
        if ($html === null || trim($html) === '') {
            return self::stuff(
                $headers
                . "Content-Type: text/plain; charset=UTF-8\r\n"
                . "Content-Transfer-Encoding: 8bit\r\n"
                . "\r\n"
                . $plain,
            );
        }

        $boundary = 'acad-' . bin2hex(random_bytes(12));

        return self::stuff(
            $headers
            . 'Content-Type: multipart/alternative; boundary="' . $boundary . "\"\r\n"
            . "\r\n"
            . '--' . $boundary . "\r\n"
            . "Content-Type: text/plain; charset=UTF-8\r\n"
            . "Content-Transfer-Encoding: 8bit\r\n"
            . "\r\n"
            . $plain . "\r\n"
            . '--' . $boundary . "\r\n"
            . "Content-Type: text/html; charset=UTF-8\r\n"
            . "Content-Transfer-Encoding: 8bit\r\n"
            . "\r\n"
            . self::normalise($html) . "\r\n"
            . '--' . $boundary . "--\r\n",
        );
    }

    private static function normalise(string $body): string
    {
        $body = str_replace(["\r\n", "\r"], "\n", $body);

        return str_replace("\n", "\r\n", $body);
    }

    private static function stuff(string $payload): string
    {
        return preg_replace('/^\./m', '..', $payload) ?? $payload;
    }

    private static function encodeHeader(string $value): string
    {
        if (preg_match('/^[\x20-\x7E]*$/', $value) === 1) {
            return $value;
        }

        return '=?UTF-8?B?' . base64_encode($value) . '?=';
    }

    private static function headerToken(string $value): string
    {
        return preg_replace('/[\r\n]+/', '', $value) ?? '';
    }
}
