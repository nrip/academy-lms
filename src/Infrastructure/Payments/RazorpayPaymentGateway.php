<?php

declare(strict_types=1);

namespace Academy\Infrastructure\Payments;

use Academy\Domain\Exception\ExternalServiceException;
use Academy\Domain\Payments\GatewayOrderResult;
use Academy\Domain\Payments\PaymentGateway;
use Academy\Domain\Payments\PaymentProvider;

/**
 * Razorpay Orders API adapter. Never logs key_id or key_secret.
 */
final class RazorpayPaymentGateway implements PaymentGateway
{
    private const BASE_URL = 'https://api.razorpay.com/v1';

    public function __construct(
        private readonly string $keyId,
        private readonly string $keySecret,
    ) {
        if (trim($this->keyId) === '' || trim($this->keySecret) === '') {
            throw new ExternalServiceException('Razorpay credentials are incomplete.');
        }
    }

    public function provider(): string
    {
        return PaymentProvider::RAZORPAY;
    }

    public function createOrder(
        int $amountMinor,
        string $currency,
        string $receipt,
        array $notes,
        string $idempotencyKey,
    ): GatewayOrderResult {
        $payload = [
            'amount' => $amountMinor,
            'currency' => strtoupper($currency),
            'receipt' => $receipt,
            'notes' => $notes,
        ];

        $decoded = $this->request(
            'POST',
            '/orders',
            $payload,
            $idempotencyKey,
        );

        return $this->mapOrder($decoded);
    }

    public function fetchOrder(string $providerOrderId): GatewayOrderResult
    {
        if ($providerOrderId === '' || !preg_match('/^[A-Za-z0-9_]+$/', $providerOrderId)) {
            throw new ExternalServiceException('Invalid Razorpay order id.');
        }

        $decoded = $this->request(
            'GET',
            '/orders/' . rawurlencode($providerOrderId),
            null,
            null,
        );

        return $this->mapOrder($decoded);
    }

    public function publicKeyId(): string
    {
        return $this->keyId;
    }

    /**
     * @param array<string, mixed>|null $body
     * @return array<string, mixed>
     */
    private function request(string $method, string $path, ?array $body, ?string $idempotencyKey): array
    {
        $url = self::BASE_URL . $path;
        $headers = [
            'Accept: application/json',
            'Authorization: Basic ' . base64_encode($this->keyId . ':' . $this->keySecret),
        ];
        if ($idempotencyKey !== null && $idempotencyKey !== '') {
            $headers[] = 'Idempotency-Key: ' . $idempotencyKey;
        }

        $encodedBody = null;
        if ($body !== null) {
            $headers[] = 'Content-Type: application/json';
            try {
                $encodedBody = json_encode($body, JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                throw new ExternalServiceException('Failed to encode Razorpay request.');
            }
        }

        try {
            if (function_exists('curl_init')) {
                $raw = $this->requestWithCurl($method, $url, $headers, $encodedBody);
            } else {
                $raw = $this->requestWithStreams($method, $url, $headers, $encodedBody);
            }
        } catch (ExternalServiceException $e) {
            throw $e;
        } catch (\Throwable) {
            throw new ExternalServiceException('Razorpay request failed.');
        }

        try {
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new ExternalServiceException('Razorpay returned invalid JSON.');
        }

        if (!is_array($decoded)) {
            throw new ExternalServiceException('Razorpay returned an unexpected response.');
        }

        if (isset($decoded['error'])) {
            throw new ExternalServiceException('Razorpay order API error.');
        }

        return $decoded;
    }

    /**
     * @param list<string> $headers
     */
    private function requestWithCurl(string $method, string $url, array $headers, ?string $body): string
    {
        $ch = curl_init($url);
        if ($ch === false) {
            throw new ExternalServiceException('Unable to initialise Razorpay HTTP client.');
        }

        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        $customRequest = match ($method) {
            'GET' => 'GET',
            'POST' => 'POST',
            default => throw new ExternalServiceException('Unsupported Razorpay HTTP method.'),
        };
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $customRequest);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }

        $response = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $errno = curl_errno($ch);
        curl_close($ch);

        if ($errno !== 0 || !is_string($response)) {
            throw new ExternalServiceException('Razorpay HTTP transport failed.');
        }
        if ($status < 200 || $status >= 300) {
            throw new ExternalServiceException('Razorpay HTTP status ' . $status . '.');
        }

        return $response;
    }

    /**
     * @param list<string> $headers
     */
    private function requestWithStreams(string $method, string $url, array $headers, ?string $body): string
    {
        $context = stream_context_create([
            'http' => [
                'method' => $method,
                'header' => implode("\r\n", $headers),
                'content' => $body ?? '',
                'timeout' => 30,
                'ignore_errors' => true,
            ],
        ]);

        $response = @file_get_contents($url, false, $context);
        if (!is_string($response)) {
            throw new ExternalServiceException('Razorpay HTTP transport failed.');
        }

        $statusLine = $http_response_header[0] ?? '';
        if (!preg_match('/\s(\d{3})\s/', $statusLine, $matches)) {
            throw new ExternalServiceException('Razorpay HTTP status unknown.');
        }
        $status = (int) $matches[1];
        if ($status < 200 || $status >= 300) {
            throw new ExternalServiceException('Razorpay HTTP status ' . $status . '.');
        }

        return $response;
    }

    /**
     * @param array<string, mixed> $decoded
     */
    private function mapOrder(array $decoded): GatewayOrderResult
    {
        if (!isset($decoded['id'], $decoded['amount'], $decoded['currency'], $decoded['status'])) {
            throw new ExternalServiceException('Razorpay order response missing required fields.');
        }

        return new GatewayOrderResult(
            providerOrderId: (string) $decoded['id'],
            amountMinor: (int) $decoded['amount'],
            currency: strtoupper((string) $decoded['currency']),
            providerStatus: (string) $decoded['status'],
        );
    }
}
