<?php

declare(strict_types=1);

namespace Academy\Infrastructure\Storage;

use DateTimeImmutable;
use DateTimeZone;

/**
 * AWS Signature Version 4 for S3 Put/Get/Head/Delete and presigned GET/PUT.
 */
final class AwsSigV4Signer
{
    public function __construct(
        private readonly string $accessKeyId,
        private readonly string $secretAccessKey,
        private readonly string $region,
        private readonly string $service = 's3',
    ) {
    }

    /**
     * @param array<string, string> $headers lower-case names
     * @param array<string, string> $query
     */
    public function authorizationHeader(
        string $method,
        string $canonicalUri,
        array $query,
        array $headers,
        string $payloadHash,
        DateTimeImmutable $now,
    ): string {
        $amzDate = $this->amzDate($now);
        $scope = $this->credentialScope($now);
        $signedHeaders = $this->signedHeaderNames($headers);
        $canonical = $this->canonicalRequest($method, $canonicalUri, $query, $headers, $signedHeaders, $payloadHash);
        $signature = $this->signature($canonical, $now);

        return 'AWS4-HMAC-SHA256 Credential=' . $this->accessKeyId . '/' . $scope
            . ', SignedHeaders=' . $signedHeaders
            . ', Signature=' . $signature;
    }

    /**
     * @param array<string, string> $extraQuery
     */
    public function presign(
        string $method,
        string $canonicalUri,
        string $host,
        DateTimeImmutable $now,
        int $expiresSeconds,
        array $extraQuery = [],
    ): string {
        $amzDate = $this->amzDate($now);
        $scope = $this->credentialScope($now);
        $query = $extraQuery + [
            'X-Amz-Algorithm' => 'AWS4-HMAC-SHA256',
            'X-Amz-Credential' => $this->accessKeyId . '/' . $scope,
            'X-Amz-Date' => $amzDate,
            'X-Amz-Expires' => (string) $expiresSeconds,
            'X-Amz-SignedHeaders' => 'host',
        ];
        ksort($query);
        $canonicalQuery = $this->canonicalQuery($query);
        $headers = ['host' => $host];
        $canonical = $this->canonicalRequest($method, $canonicalUri, $query, $headers, 'host', 'UNSIGNED-PAYLOAD');
        $signature = $this->signature($canonical, $now);

        return 'https://' . $host . $canonicalUri . '?' . $canonicalQuery . '&X-Amz-Signature=' . $signature;
    }

    public function amzDate(DateTimeImmutable $now): string
    {
        return $now->setTimezone(new DateTimeZone('UTC'))->format('Ymd\THis\Z');
    }

    public function credentialScope(DateTimeImmutable $now): string
    {
        $date = $now->setTimezone(new DateTimeZone('UTC'))->format('Ymd');

        return $date . '/' . $this->region . '/' . $this->service . '/aws4_request';
    }

    /**
     * @param array<string, string> $query
     * @param array<string, string> $headers
     */
    public function canonicalRequest(
        string $method,
        string $canonicalUri,
        array $query,
        array $headers,
        string $signedHeaders,
        string $payloadHash,
    ): string {
        $canonicalHeaders = '';
        foreach (explode(';', $signedHeaders) as $name) {
            $canonicalHeaders .= $name . ':' . trim($headers[$name]) . "\n";
        }

        return strtoupper($method) . "\n"
            . $canonicalUri . "\n"
            . $this->canonicalQuery($query) . "\n"
            . $canonicalHeaders . "\n"
            . $signedHeaders . "\n"
            . $payloadHash;
    }

    /**
     * @param array<string, string> $query
     */
    public function canonicalQuery(array $query): string
    {
        ksort($query);
        $parts = [];
        foreach ($query as $key => $value) {
            if ($key === 'X-Amz-Signature') {
                continue;
            }
            $parts[] = rawurlencode($key) . '=' . rawurlencode($value);
        }

        return implode('&', $parts);
    }

    /**
     * @param array<string, string> $headers
     */
    private function signedHeaderNames(array $headers): string
    {
        $names = array_keys($headers);
        sort($names);

        return implode(';', $names);
    }

    private function signature(string $canonicalRequest, DateTimeImmutable $now): string
    {
        $utc = $now->setTimezone(new DateTimeZone('UTC'));
        $stringToSign = "AWS4-HMAC-SHA256\n"
            . $this->amzDate($utc) . "\n"
            . $this->credentialScope($utc) . "\n"
            . hash('sha256', $canonicalRequest);
        $dateKey = hash_hmac('sha256', $utc->format('Ymd'), 'AWS4' . $this->secretAccessKey, true);
        $regionKey = hash_hmac('sha256', $this->region, $dateKey, true);
        $serviceKey = hash_hmac('sha256', $this->service, $regionKey, true);
        $signingKey = hash_hmac('sha256', 'aws4_request', $serviceKey, true);

        return hash_hmac('sha256', $stringToSign, $signingKey);
    }
}
