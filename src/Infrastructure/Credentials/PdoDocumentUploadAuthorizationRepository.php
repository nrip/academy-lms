<?php

declare(strict_types=1);

namespace Academy\Infrastructure\Credentials;

use Academy\Domain\Credentials\DocumentUploadAuthorization;
use Academy\Domain\Credentials\DocumentUploadAuthorizationRepository;
use Academy\Infrastructure\Database\ConnectionFactory;
use DateTimeImmutable;
use DateTimeZone;
use PDO;

final class PdoDocumentUploadAuthorizationRepository implements DocumentUploadAuthorizationRepository
{
    private const COLUMNS = 'authorization_id, application_id, requirement_id, user_id, object_key,
        display_filename, declared_mime_type, max_size_bytes, expires_at, consumed_at, created_at';

    public function __construct(
        private readonly ConnectionFactory $connections,
    ) {
    }

    public function insert(
        int $applicationId,
        int $requirementId,
        int $userId,
        string $objectKey,
        string $displayFilename,
        string $declaredMimeType,
        int $maxSizeBytes,
        DateTimeImmutable $expiresAt,
        DateTimeImmutable $now,
    ): DocumentUploadAuthorization {
        $pdo = $this->connections->connection();
        $stmt = $pdo->prepare(
            'INSERT INTO document_upload_authorizations (
                application_id, requirement_id, user_id, object_key, display_filename,
                declared_mime_type, max_size_bytes, expires_at, consumed_at, created_at
            ) VALUES (
                :application_id, :requirement_id, :user_id, :object_key, :display_filename,
                :declared_mime_type, :max_size_bytes, :expires_at, NULL, :created_at
            )',
        );
        $stmt->execute([
            'application_id' => $applicationId,
            'requirement_id' => $requirementId,
            'user_id' => $userId,
            'object_key' => $objectKey,
            'display_filename' => $displayFilename,
            'declared_mime_type' => $declaredMimeType,
            'max_size_bytes' => $maxSizeBytes,
            'expires_at' => $expiresAt->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u'),
            'created_at' => $now->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u'),
        ]);

        return new DocumentUploadAuthorization(
            authorizationId: (int) $pdo->lastInsertId(),
            applicationId: $applicationId,
            requirementId: $requirementId,
            userId: $userId,
            objectKey: $objectKey,
            displayFilename: $displayFilename,
            declaredMimeType: $declaredMimeType,
            maxSizeBytes: $maxSizeBytes,
            expiresAt: $expiresAt,
            consumedAt: null,
            createdAt: $now,
        );
    }

    public function findByObjectKeyForUpdate(string $objectKey): ?DocumentUploadAuthorization
    {
        $pdo = $this->connections->connection();
        $stmt = $pdo->prepare(
            'SELECT ' . self::COLUMNS . ' FROM document_upload_authorizations
             WHERE object_key = :object_key FOR UPDATE',
        );
        $stmt->execute(['object_key' => $objectKey]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $this->mapRow($row);
    }

    public function findByIdForUpdate(int $authorizationId): ?DocumentUploadAuthorization
    {
        $pdo = $this->connections->connection();
        $stmt = $pdo->prepare(
            'SELECT ' . self::COLUMNS . ' FROM document_upload_authorizations
             WHERE authorization_id = :id FOR UPDATE',
        );
        $stmt->execute(['id' => $authorizationId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $this->mapRow($row);
    }

    public function markConsumed(int $authorizationId, DateTimeImmutable $now): bool
    {
        $pdo = $this->connections->connection();
        $stmt = $pdo->prepare(
            'UPDATE document_upload_authorizations
             SET consumed_at = :consumed_at
             WHERE authorization_id = :id AND consumed_at IS NULL',
        );
        $stmt->execute([
            'consumed_at' => $now->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u'),
            'id' => $authorizationId,
        ]);

        return $stmt->rowCount() === 1;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function mapRow(array $row): DocumentUploadAuthorization
    {
        $utc = new DateTimeZone('UTC');

        return new DocumentUploadAuthorization(
            authorizationId: (int) $row['authorization_id'],
            applicationId: (int) $row['application_id'],
            requirementId: (int) $row['requirement_id'],
            userId: (int) $row['user_id'],
            objectKey: (string) $row['object_key'],
            displayFilename: (string) $row['display_filename'],
            declaredMimeType: (string) $row['declared_mime_type'],
            maxSizeBytes: (int) $row['max_size_bytes'],
            expiresAt: new DateTimeImmutable((string) $row['expires_at'], $utc),
            consumedAt: $row['consumed_at'] === null ? null : new DateTimeImmutable((string) $row['consumed_at'], $utc),
            createdAt: new DateTimeImmutable((string) $row['created_at'], $utc),
        );
    }
}
