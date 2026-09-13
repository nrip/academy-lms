<?php

declare(strict_types=1);

namespace Academy\Infrastructure\Notifications;

use Academy\Domain\Exception\ExternalServiceException;
use Academy\Domain\Notifications\EmailDeliveryMessage;
use Academy\Domain\Notifications\EmailDeliveryPort;
use Academy\Domain\Notifications\ProviderReceipt;

/**
 * Configurable SMTP delivery (Amazon SES SMTP or any SMTP host).
 * Never logs username/password.
 */
final class SmtpEmailAdapter implements EmailDeliveryPort
{
    /**
     * @param (callable(string,int,float):resource)|null $socketFactory
     *        Optional override for unit tests: (remote, timeout) → socket resource.
     */
    public function __construct(
        private readonly string $host,
        private readonly int $port,
        private readonly string $username,
        private readonly string $password,
        private readonly string $fromAddress,
        private readonly string $fromName,
        private readonly string $encryption,
        private readonly mixed $socketFactory = null,
    ) {
        if (trim($this->host) === '' || trim($this->fromAddress) === '') {
            throw new ExternalServiceException('SMTP mail host and from address are required.');
        }
        if ($this->port < 1 || $this->port > 65535) {
            throw new ExternalServiceException('SMTP mail port is invalid.');
        }
        if (!in_array($this->encryption, ['tls', 'ssl', 'none'], true)) {
            throw new ExternalServiceException('SMTP encryption must be tls, ssl, or none.');
        }
        if ($this->socketFactory !== null && !is_callable($this->socketFactory)) {
            throw new ExternalServiceException('SMTP socket factory is invalid.');
        }
    }

    public function send(EmailDeliveryMessage $message): ProviderReceipt
    {
        $to = trim($message->toAddress);
        if ($to === '' || filter_var($to, FILTER_VALIDATE_EMAIL) === false) {
            throw new ExternalServiceException('SMTP recipient address is invalid.');
        }

        $socket = $this->connect();
        try {
            $this->expect($socket, [220]);
            $this->command($socket, 'EHLO academy-lms', [250]);

            if ($this->encryption === 'tls') {
                $this->command($socket, 'STARTTLS', [220]);
                if (!stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                    throw new ExternalServiceException('SMTP STARTTLS negotiation failed.');
                }
                $this->command($socket, 'EHLO academy-lms', [250]);
            }

            if ($this->username !== '') {
                $this->command($socket, 'AUTH LOGIN', [334]);
                $this->command($socket, base64_encode($this->username), [334]);
                $this->command($socket, base64_encode($this->password), [235]);
            }

            $this->command($socket, 'MAIL FROM:<' . $this->fromAddress . '>', [250]);
            $this->command($socket, 'RCPT TO:<' . $to . '>', [250, 251]);
            $this->command($socket, 'DATA', [354]);

            $data = $this->buildMime($message, $to);
            fwrite($socket, $data . "\r\n.\r\n");
            $this->expect($socket, [250]);
            $this->command($socket, 'QUIT', [221]);
        } finally {
            fclose($socket);
        }

        return new ProviderReceipt('smtp-' . hash('sha256', $message->idempotencyKey));
    }

    /**
     * @return resource
     */
    private function connect()
    {
        $remote = ($this->encryption === 'ssl' ? 'ssl://' : '') . $this->host . ':' . $this->port;
        $timeout = 30.0;

        if (is_callable($this->socketFactory)) {
            $socket = ($this->socketFactory)($remote, $timeout);
            if (!is_resource($socket)) {
                throw new ExternalServiceException('SMTP connection factory returned an invalid socket.');
            }

            return $socket;
        }

        $errno = 0;
        $errstr = '';
        $socket = @stream_socket_client(
            $remote,
            $errno,
            $errstr,
            $timeout,
            STREAM_CLIENT_CONNECT,
        );
        if ($socket === false) {
            throw new ExternalServiceException('SMTP connection failed.');
        }
        stream_set_timeout($socket, (int) $timeout);

        return $socket;
    }

    /**
     * @param resource $socket
     * @param list<int> $okCodes
     */
    private function command($socket, string $line, array $okCodes): void
    {
        fwrite($socket, $line . "\r\n");
        $this->expect($socket, $okCodes);
    }

    /**
     * @param resource $socket
     * @param list<int> $okCodes
     */
    private function expect($socket, array $okCodes): void
    {
        $response = '';
        while (($line = fgets($socket, 515)) !== false) {
            $response .= $line;
            if (isset($line[3]) && $line[3] === ' ') {
                break;
            }
            if (!isset($line[3])) {
                break;
            }
        }
        $code = (int) substr($response, 0, 3);
        if (!in_array($code, $okCodes, true)) {
            throw new ExternalServiceException('SMTP server rejected the command.');
        }
    }

    private function buildMime(EmailDeliveryMessage $message, string $to): string
    {
        $fromName = $this->encodeHeader($this->fromName !== '' ? $this->fromName : $this->fromAddress);
        $subject = $this->encodeHeader($message->subject);
        $body = str_replace(["\r\n", "\r"], "\n", $message->bodyText);
        $body = str_replace("\n", "\r\n", $body);
        // Dot-stuffing for SMTP DATA.
        $body = preg_replace('/^\./m', '..', $body) ?? $body;

        return 'From: ' . $fromName . ' <' . $this->fromAddress . ">\r\n"
            . 'To: <' . $to . ">\r\n"
            . 'Subject: ' . $subject . "\r\n"
            . "MIME-Version: 1.0\r\n"
            . "Content-Type: text/plain; charset=UTF-8\r\n"
            . "Content-Transfer-Encoding: 8bit\r\n"
            . 'X-Template-Key: ' . $this->headerToken($message->templateKey) . "\r\n"
            . 'X-Idempotency-Key: ' . $this->headerToken($message->idempotencyKey) . "\r\n"
            . "\r\n"
            . $body;
    }

    private function encodeHeader(string $value): string
    {
        if (preg_match('/^[\x20-\x7E]*$/', $value) === 1) {
            return $value;
        }

        return '=?UTF-8?B?' . base64_encode($value) . '?=';
    }

    private function headerToken(string $value): string
    {
        return preg_replace('/[\r\n]+/', '', $value) ?? '';
    }
}
