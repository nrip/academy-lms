<?php

declare(strict_types=1);

namespace Academy\Domain\Review;

interface VerificationAuditLogRepository
{
    public function append(VerificationAuditLogWrite $write): VerificationAuditLog;

    /**
     * @return list<VerificationAuditLog>
     */
    public function listByApplication(int $applicationId): array;

    /**
     * @return list<VerificationAuditLog>
     */
    public function listByDocumentSubmission(int $documentSubmissionId): array;
}
