<?php

declare(strict_types=1);

namespace Academy\Infrastructure\Review;

use Academy\Domain\Review\VerificationAuditLog;
use Academy\Domain\Review\VerificationAuditLogRepository;
use Academy\Domain\Review\VerificationAuditLogWrite;
use Academy\Infrastructure\Database\ConnectionFactory;
use DateTimeImmutable;
use DateTimeZone;
use PDO;

final class PdoVerificationAuditLogRepository implements VerificationAuditLogRepository
{
    private const COLUMNS = 'verification_audit_id, application_id, document_submission_id, requirement_id,
        reviewer_user_id, action, status_before, status_after, reason_code, learner_visible_message,
        internal_note, state_version, row_version, created_at';

    public function __construct(
        private readonly ConnectionFactory $connections,
    ) {
    }

    public function append(VerificationAuditLogWrite $write): VerificationAuditLog
    {
        $pdo = $this->connections->connection();
        $createdStr = $write->createdAt->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
        $stmt = $pdo->prepare(
            'INSERT INTO verification_audit_log (
                application_id, document_submission_id, requirement_id, reviewer_user_id, action,
                status_before, status_after, reason_code, learner_visible_message, internal_note,
                state_version, row_version, created_at
            ) VALUES (
                :application_id, :document_submission_id, :requirement_id, :reviewer_user_id, :action,
                :status_before, :status_after, :reason_code, :learner_visible_message, :internal_note,
                :state_version, :row_version, :created_at
            )',
        );
        $stmt->execute([
            'application_id' => $write->applicationId,
            'document_submission_id' => $write->documentSubmissionId,
            'requirement_id' => $write->requirementId,
            'reviewer_user_id' => $write->reviewerUserId,
            'action' => $write->action,
            'status_before' => $write->statusBefore,
            'status_after' => $write->statusAfter,
            'reason_code' => $write->reasonCode,
            'learner_visible_message' => $write->learnerVisibleMessage,
            'internal_note' => $write->internalNote,
            'state_version' => $write->stateVersion,
            'row_version' => $write->rowVersion,
            'created_at' => $createdStr,
        ]);

        $id = (int) $pdo->lastInsertId();
        $created = $this->findById($id);
        if ($created === null) {
            throw new \RuntimeException('Failed to load inserted verification audit log row.');
        }

        return $created;
    }

    public function listByApplication(int $applicationId): array
    {
        $pdo = $this->connections->connection();
        $stmt = $pdo->prepare(
            'SELECT ' . self::COLUMNS . ' FROM verification_audit_log
             WHERE application_id = :application_id
             ORDER BY created_at ASC, verification_audit_id ASC',
        );
        $stmt->execute(['application_id' => $applicationId]);

        return array_values(array_map($this->mapRow(...), $stmt->fetchAll(PDO::FETCH_ASSOC)));
    }

    public function listByDocumentSubmission(int $documentSubmissionId): array
    {
        $pdo = $this->connections->connection();
        $stmt = $pdo->prepare(
            'SELECT ' . self::COLUMNS . ' FROM verification_audit_log
             WHERE document_submission_id = :document_submission_id
             ORDER BY created_at ASC, verification_audit_id ASC',
        );
        $stmt->execute(['document_submission_id' => $documentSubmissionId]);

        return array_values(array_map($this->mapRow(...), $stmt->fetchAll(PDO::FETCH_ASSOC)));
    }

    private function findById(int $verificationAuditId): ?VerificationAuditLog
    {
        $pdo = $this->connections->connection();
        $stmt = $pdo->prepare(
            'SELECT ' . self::COLUMNS . ' FROM verification_audit_log WHERE verification_audit_id = :id',
        );
        $stmt->execute(['id' => $verificationAuditId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $this->mapRow($row);
    }

    /**
     * @param array<string, mixed> $row
     */
    private function mapRow(array $row): VerificationAuditLog
    {
        $utc = new DateTimeZone('UTC');

        return new VerificationAuditLog(
            verificationAuditId: (int) $row['verification_audit_id'],
            applicationId: (int) $row['application_id'],
            documentSubmissionId: $row['document_submission_id'] === null ? null : (int) $row['document_submission_id'],
            requirementId: $row['requirement_id'] === null ? null : (int) $row['requirement_id'],
            reviewerUserId: (int) $row['reviewer_user_id'],
            action: (string) $row['action'],
            statusBefore: $row['status_before'] === null ? null : (string) $row['status_before'],
            statusAfter: $row['status_after'] === null ? null : (string) $row['status_after'],
            reasonCode: $row['reason_code'] === null ? null : (string) $row['reason_code'],
            learnerVisibleMessage: $row['learner_visible_message'] === null ? null : (string) $row['learner_visible_message'],
            internalNote: $row['internal_note'] === null ? null : (string) $row['internal_note'],
            stateVersion: $row['state_version'] === null ? null : (int) $row['state_version'],
            rowVersion: $row['row_version'] === null ? null : (int) $row['row_version'],
            createdAt: new DateTimeImmutable((string) $row['created_at'], $utc),
        );
    }
}
