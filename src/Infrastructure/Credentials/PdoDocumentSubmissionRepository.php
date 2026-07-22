<?php

declare(strict_types=1);

namespace Academy\Infrastructure\Credentials;

use Academy\Domain\Credentials\DocumentSubmission;
use Academy\Domain\Credentials\DocumentSubmissionRepository;
use Academy\Domain\Credentials\DocumentSubmissionWrite;
use Academy\Infrastructure\Database\ConnectionFactory;
use DateTimeImmutable;
use DateTimeZone;
use PDO;

final class PdoDocumentSubmissionRepository implements DocumentSubmissionRepository
{
    private const COLUMNS = 'document_submission_id, application_id, requirement_id, object_key, display_filename,
        mime_type, size_bytes, checksum_sha256, status, scan_status, rejection_reason_code, uploaded_by_user_id,
        submitted_at, superseded_at, current_marker, row_version, scan_attempt_count, scan_queued_at,
        scan_completed_at, scan_lease_owner, scan_lease_token, scan_lease_expires_at, created_at, updated_at';

    public function __construct(
        private readonly ConnectionFactory $connections,
    ) {
    }

    public function findById(int $documentSubmissionId): ?DocumentSubmission
    {
        $pdo = $this->connections->connection();
        $stmt = $pdo->prepare('SELECT ' . self::COLUMNS . ' FROM document_submissions WHERE document_submission_id = :id');
        $stmt->execute(['id' => $documentSubmissionId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $this->mapRow($row);
    }

    public function findCurrentForRequirement(int $applicationId, int $requirementId): ?DocumentSubmission
    {
        $pdo = $this->connections->connection();
        $stmt = $pdo->prepare(
            'SELECT ' . self::COLUMNS . ' FROM document_submissions
             WHERE application_id = :application_id AND requirement_id = :requirement_id AND current_marker = 1',
        );
        $stmt->execute(['application_id' => $applicationId, 'requirement_id' => $requirementId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $this->mapRow($row);
    }

    public function listCurrentForApplication(int $applicationId): array
    {
        $pdo = $this->connections->connection();
        $stmt = $pdo->prepare(
            'SELECT ' . self::COLUMNS . ' FROM document_submissions
             WHERE application_id = :application_id AND current_marker = 1
             ORDER BY requirement_id ASC',
        );
        $stmt->execute(['application_id' => $applicationId]);

        return array_values(array_map($this->mapRow(...), $stmt->fetchAll(PDO::FETCH_ASSOC)));
    }

    public function listHistoryForRequirement(int $applicationId, int $requirementId): array
    {
        $pdo = $this->connections->connection();
        $stmt = $pdo->prepare(
            'SELECT ' . self::COLUMNS . ' FROM document_submissions
             WHERE application_id = :application_id AND requirement_id = :requirement_id
             ORDER BY document_submission_id DESC',
        );
        $stmt->execute(['application_id' => $applicationId, 'requirement_id' => $requirementId]);

        return array_values(array_map($this->mapRow(...), $stmt->fetchAll(PDO::FETCH_ASSOC)));
    }

    public function lockCurrentForUpdate(int $applicationId, int $requirementId): ?DocumentSubmission
    {
        $pdo = $this->connections->connection();
        $stmt = $pdo->prepare(
            'SELECT ' . self::COLUMNS . ' FROM document_submissions
             WHERE application_id = :application_id AND requirement_id = :requirement_id AND current_marker = 1
             FOR UPDATE',
        );
        $stmt->execute(['application_id' => $applicationId, 'requirement_id' => $requirementId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $this->mapRow($row);
    }

    public function lockAllCurrentForApplication(int $applicationId): array
    {
        $pdo = $this->connections->connection();
        $stmt = $pdo->prepare(
            'SELECT ' . self::COLUMNS . ' FROM document_submissions
             WHERE application_id = :application_id AND current_marker = 1
             ORDER BY requirement_id ASC
             FOR UPDATE',
        );
        $stmt->execute(['application_id' => $applicationId]);

        return array_values(array_map($this->mapRow(...), $stmt->fetchAll(PDO::FETCH_ASSOC)));
    }

    public function supersedeCurrent(
        int $documentSubmissionId,
        int $expectedRowVersion,
        DateTimeImmutable $now,
    ): bool {
        $pdo = $this->connections->connection();
        $stmt = $pdo->prepare(
            "UPDATE document_submissions
             SET status = 'superseded',
                 current_marker = NULL,
                 superseded_at = :superseded_at,
                 row_version = row_version + 1,
                 updated_at = :updated_at
             WHERE document_submission_id = :id
               AND current_marker = 1
               AND row_version = :row_version",
        );
        $stmt->execute([
            'superseded_at' => $now->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u'),
            'updated_at' => $now->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u'),
            'id' => $documentSubmissionId,
            'row_version' => $expectedRowVersion,
        ]);

        return $stmt->rowCount() === 1;
    }

    public function insertCurrent(DocumentSubmissionWrite $write): DocumentSubmission
    {
        $pdo = $this->connections->connection();
        $nowStr = $write->createdAt->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
        $submittedStr = $write->submittedAt->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
        $queuedStr = $write->scanQueuedAt?->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');

        $stmt = $pdo->prepare(
            'INSERT INTO document_submissions (
                application_id, requirement_id, object_key, display_filename, mime_type, size_bytes,
                checksum_sha256, status, scan_status, rejection_reason_code, uploaded_by_user_id,
                submitted_at, superseded_at, current_marker, row_version, scan_attempt_count,
                scan_queued_at, scan_completed_at, scan_lease_owner, scan_lease_token,
                scan_lease_expires_at, created_at, updated_at
            ) VALUES (
                :application_id, :requirement_id, :object_key, :display_filename, :mime_type, :size_bytes,
                :checksum_sha256, :status, :scan_status, NULL, :uploaded_by_user_id,
                :submitted_at, NULL, 1, 1, 0,
                :scan_queued_at, NULL, NULL, NULL,
                NULL, :created_at, :updated_at
            )',
        );
        $stmt->execute([
            'application_id' => $write->applicationId,
            'requirement_id' => $write->requirementId,
            'object_key' => $write->objectKey,
            'display_filename' => $write->displayFilename,
            'mime_type' => $write->mimeType,
            'size_bytes' => $write->sizeBytes,
            'checksum_sha256' => $write->checksumSha256,
            'status' => $write->status,
            'scan_status' => $write->scanStatus,
            'uploaded_by_user_id' => $write->uploadedByUserId,
            'submitted_at' => $submittedStr,
            'scan_queued_at' => $queuedStr,
            'created_at' => $nowStr,
            'updated_at' => $nowStr,
        ]);

        $id = (int) $pdo->lastInsertId();
        $created = $this->findById($id);
        if ($created === null) {
            throw new \RuntimeException('Failed to load inserted document submission.');
        }

        return $created;
    }

    public function applyScanResult(
        int $documentSubmissionId,
        int $expectedRowVersion,
        string $businessStatus,
        string $scanStatus,
        DateTimeImmutable $now,
        string $leaseOwner,
        string $leaseToken,
    ): bool {
        $pdo = $this->connections->connection();
        $stmt = $pdo->prepare(
            'UPDATE document_submissions
             SET status = :status,
                 scan_status = :scan_status,
                 scan_completed_at = :completed_at,
                 scan_lease_owner = NULL,
                 scan_lease_token = NULL,
                 scan_lease_expires_at = NULL,
                 row_version = row_version + 1,
                 updated_at = :updated_at
             WHERE document_submission_id = :id
               AND current_marker = 1
               AND scan_status = \'pending\'
               AND row_version = :row_version
               AND scan_lease_owner = :lease_owner
               AND scan_lease_token = :lease_token
               AND scan_lease_expires_at IS NOT NULL
               AND scan_lease_expires_at >= :now',
        );
        $nowStr = $now->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
        $stmt->execute([
            'status' => $businessStatus,
            'scan_status' => $scanStatus,
            'completed_at' => $nowStr,
            'updated_at' => $nowStr,
            'id' => $documentSubmissionId,
            'row_version' => $expectedRowVersion,
            'lease_owner' => $leaseOwner,
            'lease_token' => $leaseToken,
            'now' => $nowStr,
        ]);

        return $stmt->rowCount() === 1;
    }

    public function claimPendingScan(
        string $leaseOwner,
        string $leaseToken,
        DateTimeImmutable $now,
        int $leaseSeconds,
        int $limit = 10,
    ): array {
        $pdo = $this->connections->connection();
        $nowStr = $now->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
        $expires = $now->modify('+' . $leaseSeconds . ' seconds')
            ->setTimezone(new DateTimeZone('UTC'))
            ->format('Y-m-d H:i:s.u');

        $select = $pdo->prepare(
            'SELECT document_submission_id FROM document_submissions
             WHERE scan_status = \'pending\'
               AND current_marker = 1
               AND (
                    scan_lease_expires_at IS NULL
                    OR scan_lease_expires_at < :now
               )
             ORDER BY scan_queued_at ASC, document_submission_id ASC
             LIMIT ' . (int) $limit . '
             FOR UPDATE SKIP LOCKED',
        );
        $select->execute(['now' => $nowStr]);
        $ids = array_map(static fn (array $r): int => (int) $r['document_submission_id'], $select->fetchAll(PDO::FETCH_ASSOC));

        $claimed = [];
        foreach ($ids as $id) {
            $rowToken = $this->uuidV4();
            $update = $pdo->prepare(
                'UPDATE document_submissions
                 SET scan_lease_owner = :owner,
                     scan_lease_token = :token,
                     scan_lease_expires_at = :expires,
                     scan_attempt_count = scan_attempt_count + 1,
                     row_version = row_version + 1,
                     updated_at = :updated_at
                 WHERE document_submission_id = :id
                   AND scan_status = \'pending\'
                   AND current_marker = 1',
            );
            $update->execute([
                'owner' => $leaseOwner,
                'token' => $rowToken,
                'expires' => $expires,
                'updated_at' => $nowStr,
                'id' => $id,
            ]);
            if ($update->rowCount() === 1) {
                $row = $this->findById($id);
                if ($row !== null) {
                    $claimed[] = $row;
                }
            }
        }

        return $claimed;
    }

    public function listStuckPending(DateTimeImmutable $now, int $slaSeconds, int $limit = 50): array
    {
        $pdo = $this->connections->connection();
        $cutoff = $now->modify('-' . $slaSeconds . ' seconds')
            ->setTimezone(new DateTimeZone('UTC'))
            ->format('Y-m-d H:i:s.u');
        $stmt = $pdo->prepare(
            'SELECT ' . self::COLUMNS . ' FROM document_submissions
             WHERE scan_status = \'pending\'
               AND current_marker = 1
               AND scan_queued_at IS NOT NULL
               AND scan_queued_at <= :cutoff
             ORDER BY scan_queued_at ASC
             LIMIT ' . (int) $limit,
        );
        $stmt->execute(['cutoff' => $cutoff]);

        return array_values(array_map($this->mapRow(...), $stmt->fetchAll(PDO::FETCH_ASSOC)));
    }

    public function markScanRequeued(
        int $documentSubmissionId,
        int $expectedRowVersion,
        DateTimeImmutable $now,
    ): bool {
        $pdo = $this->connections->connection();
        $nowStr = $now->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
        $stmt = $pdo->prepare(
            'UPDATE document_submissions
             SET scan_queued_at = :queued_at,
                 scan_lease_owner = NULL,
                 scan_lease_token = NULL,
                 scan_lease_expires_at = NULL,
                 row_version = row_version + 1,
                 updated_at = :updated_at
             WHERE document_submission_id = :id
               AND current_marker = 1
               AND scan_status = \'pending\'
               AND row_version = :row_version',
        );
        $stmt->execute([
            'queued_at' => $nowStr,
            'updated_at' => $nowStr,
            'id' => $documentSubmissionId,
            'row_version' => $expectedRowVersion,
        ]);

        return $stmt->rowCount() === 1;
    }

    public function promoteUploadedToUnderReview(
        int $documentSubmissionId,
        int $expectedRowVersion,
        DateTimeImmutable $now,
    ): bool {
        $pdo = $this->connections->connection();
        $nowStr = $now->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
        $stmt = $pdo->prepare(
            "UPDATE document_submissions
             SET status = 'under_review',
                 row_version = row_version + 1,
                 updated_at = :updated_at
             WHERE document_submission_id = :id
               AND current_marker = 1
               AND status = 'uploaded'
               AND scan_status = 'clean'
               AND row_version = :row_version",
        );
        $stmt->execute([
            'updated_at' => $nowStr,
            'id' => $documentSubmissionId,
            'row_version' => $expectedRowVersion,
        ]);

        return $stmt->rowCount() === 1;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function mapRow(array $row): DocumentSubmission
    {
        $utc = new DateTimeZone('UTC');

        return new DocumentSubmission(
            documentSubmissionId: (int) $row['document_submission_id'],
            applicationId: (int) $row['application_id'],
            requirementId: (int) $row['requirement_id'],
            objectKey: (string) $row['object_key'],
            displayFilename: (string) $row['display_filename'],
            mimeType: (string) $row['mime_type'],
            sizeBytes: (int) $row['size_bytes'],
            checksumSha256: (string) $row['checksum_sha256'],
            status: (string) $row['status'],
            scanStatus: (string) $row['scan_status'],
            rejectionReasonCode: $row['rejection_reason_code'] === null ? null : (string) $row['rejection_reason_code'],
            uploadedByUserId: (int) $row['uploaded_by_user_id'],
            submittedAt: new DateTimeImmutable((string) $row['submitted_at'], $utc),
            supersededAt: $row['superseded_at'] === null ? null : new DateTimeImmutable((string) $row['superseded_at'], $utc),
            currentMarker: $row['current_marker'] === null ? null : (int) $row['current_marker'],
            rowVersion: (int) $row['row_version'],
            scanAttemptCount: (int) $row['scan_attempt_count'],
            scanQueuedAt: $row['scan_queued_at'] === null ? null : new DateTimeImmutable((string) $row['scan_queued_at'], $utc),
            scanCompletedAt: $row['scan_completed_at'] === null ? null : new DateTimeImmutable((string) $row['scan_completed_at'], $utc),
            scanLeaseOwner: $row['scan_lease_owner'] === null ? null : (string) $row['scan_lease_owner'],
            scanLeaseToken: $row['scan_lease_token'] === null ? null : (string) $row['scan_lease_token'],
            scanLeaseExpiresAt: $row['scan_lease_expires_at'] === null
                ? null
                : new DateTimeImmutable((string) $row['scan_lease_expires_at'], $utc),
            createdAt: new DateTimeImmutable((string) $row['created_at'], $utc),
            updatedAt: new DateTimeImmutable((string) $row['updated_at'], $utc),
        );
    }

    private function uuidV4(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }
}
