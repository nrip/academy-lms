<?php

declare(strict_types=1);

namespace Academy\Infrastructure\Storage;

use Academy\Domain\Exception\ExternalServiceException;
use Academy\Domain\Exception\ServiceUnavailableException;
use Academy\Domain\Exception\ValidationException;
use Academy\Domain\Storage\ObjectMetadata;
use Academy\Domain\Storage\ObjectStorage;
use DateTimeImmutable;
use DateTimeZone;

/**
 * Private S3 bucket adapter. No public ACL. No transcoding.
 */
final class S3ObjectStorage implements ObjectStorage
{
    private readonly AwsSigV4Signer $signer;

    public function __construct(
        private readonly string $bucket,
        private readonly string $region,
        string $accessKeyId,
        string $secretAccessKey,
        private readonly ?string $endpoint = null,
    ) {
        if ($this->bucket === '' || $accessKeyId === '' || $secretAccessKey === '' || $this->region === '') {
            throw new ServiceUnavailableException('Learning media S3 is not configured.');
        }
        $this->signer = new AwsSigV4Signer($accessKeyId, $secretAccessKey, $this->region);
    }

    public function issueUploadAuthorization(
        string $objectKey,
        string $mimeType,
        int $maxSizeBytes,
        DateTimeImmutable $expiresAt,
    ): array {
        $this->assertKey($objectKey);
        $seconds = max(1, $expiresAt->getTimestamp() - time());
        $url = $this->signer->presign(
            'PUT',
            $this->canonicalUri($objectKey),
            $this->host(),
            new DateTimeImmutable('now', new DateTimeZone('UTC')),
            $seconds,
        );

        return [
            'upload_url' => $url,
            'method' => 'PUT',
            'headers' => [
                'Content-Type' => $mimeType,
            ],
            'expires_at' => $expiresAt,
        ];
    }

    public function objectExists(string $objectKey): ?ObjectMetadata
    {
        $this->assertKey($objectKey);
        $response = $this->signedRequest('HEAD', $objectKey, '', 'application/octet-stream');
        if ($response['status'] === 404) {
            return null;
        }
        if ($response['status'] < 200 || $response['status'] >= 300) {
            throw new ExternalServiceException('Learning media storage could not be read.');
        }
        $size = 0;
        if (preg_match('/^content-length:\s*(\d+)/im', $response['headers'], $match) === 1) {
            $size = (int) $match[1];
        }
        $mime = null;
        if (preg_match('/^content-type:\s*([^\r\n;]+)/im', $response['headers'], $match) === 1) {
            $mime = trim($match[1]);
        }

        return new ObjectMetadata($size, $mime);
    }

    public function issueDownloadUrl(string $objectKey, DateTimeImmutable $expiresAt): array
    {
        $this->assertKey($objectKey);
        $seconds = $expiresAt->getTimestamp() - time();
        if ($seconds < 1) {
            throw new ValidationException('Download URL expiry is in the past.');
        }
        $url = $this->signer->presign(
            'GET',
            $this->canonicalUri($objectKey),
            $this->host(),
            new DateTimeImmutable('now', new DateTimeZone('UTC')),
            $seconds,
        );

        return [
            'download_url' => $url,
            'expires_at' => $expiresAt,
        ];
    }

    public function putObject(string $objectKey, string $contents, string $mimeType): ObjectMetadata
    {
        $this->assertKey($objectKey);
        $response = $this->signedRequest('PUT', $objectKey, $contents, $mimeType);
        if ($response['status'] < 200 || $response['status'] >= 300) {
            throw new ExternalServiceException('Learning media could not be stored.');
        }

        return new ObjectMetadata(strlen($contents), $mimeType, hash('sha256', $contents));
    }

    public function deleteObject(string $objectKey): void
    {
        $this->assertKey($objectKey);
        $response = $this->signedRequest('DELETE', $objectKey, '', 'application/octet-stream');
        if ($response['status'] === 404) {
            return;
        }
        if ($response['status'] < 200 || $response['status'] >= 300) {
            throw new ExternalServiceException('Learning media could not be deleted.');
        }
    }

    public function host(): string
    {
        if ($this->endpoint !== null && $this->endpoint !== '') {
            $host = parse_url($this->endpoint, PHP_URL_HOST);
            if (!is_string($host) || $host === '') {
                throw new ServiceUnavailableException('Learning media S3 endpoint is invalid.');
            }

            return $host;
        }

        return $this->bucket . '.s3.' . $this->region . '.amazonaws.com';
    }

    private function canonicalUri(string $objectKey): string
    {
        $segments = array_map(rawurlencode(...), explode('/', $objectKey));
        $path = '/' . implode('/', $segments);
        if ($this->endpoint !== null && $this->endpoint !== '') {
            return '/' . rawurlencode($this->bucket) . $path;
        }

        return $path;
    }

    /**
     * @return array{status: int, headers: string, body: string}
     */
    private function signedRequest(string $method, string $objectKey, string $body, string $mimeType): array
    {
        if (!function_exists('curl_init')) {
            throw new ServiceUnavailableException('Learning media storage requires the curl extension.');
        }
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $payloadHash = hash('sha256', $body);
        $host = $this->host();
        $headers = [
            'host' => $host,
            'x-amz-content-sha256' => $payloadHash,
            'x-amz-date' => $this->signer->amzDate($now),
        ];
        if ($method === 'PUT') {
            $headers['content-type'] = $mimeType;
        }
        $authorization = $this->signer->authorizationHeader(
            $method,
            $this->canonicalUri($objectKey),
            [],
            $headers,
            $payloadHash,
            $now,
        );
        $headerLines = [
            'Authorization: ' . $authorization,
            'x-amz-content-sha256: ' . $payloadHash,
            'x-amz-date: ' . $headers['x-amz-date'],
        ];
        if ($method === 'PUT') {
            $headerLines[] = 'Content-Type: ' . $mimeType;
        }

        $url = 'https://' . $host . $this->canonicalUri($objectKey);
        $ch = curl_init($url);
        if ($ch === false) {
            throw new ExternalServiceException('Learning media storage could not be reached.');
        }
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $headerLines,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => true,
            CURLOPT_POSTFIELDS => $method === 'PUT' ? $body : null,
            CURLOPT_NOBODY => $method === 'HEAD',
            CURLOPT_TIMEOUT => 30,
        ]);
        $raw = curl_exec($ch);
        if ($raw === false) {
            curl_close($ch);
            throw new ExternalServiceException('Learning media storage could not be reached.');
        }
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $headerSize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        curl_close($ch);

        return [
            'status' => $status,
            'headers' => substr((string) $raw, 0, $headerSize),
            'body' => substr((string) $raw, $headerSize),
        ];
    }

    private function assertKey(string $objectKey): void
    {
        if ($objectKey === '' || str_contains($objectKey, '..') || str_starts_with($objectKey, '/')) {
            throw new ValidationException('Invalid object key.');
        }
    }
}
