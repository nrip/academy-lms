<?php

declare(strict_types=1);

namespace Academy\Application\Credentials;

use Academy\Application\Audit\AuditService;
use Academy\Domain\Audit\DocumentAuditPayload;
use Academy\Domain\Credentials\DocumentScanStatus;
use Academy\Domain\Credentials\DocumentSubmission;
use Academy\Domain\Credentials\DocumentSubmissionRepository;
use Academy\Domain\Credentials\DocumentSubmissionStateMachine;
use Academy\Domain\Credentials\DocumentSubmissionStatus;
use Academy\Domain\Credentials\MalwareScanner;
use Academy\Domain\Credentials\ScanOutcome;
use Academy\Domain\Outbox\DocumentOutboxEventTypes;
use Academy\Domain\Outbox\OutboxRepository;
use Academy\Infrastructure\Database\TransactionManager;
use DateTimeImmutable;
use DateTimeZone;
use Psr\Log\LoggerInterface;
use Throwable;

final class DocumentScanWorker
{
    public function __construct(
        private readonly TransactionManager $transactions,
        private readonly DocumentSubmissionRepository $submissions,
        private readonly OutboxRepository $outbox,
        private readonly MalwareScanner $scanner,
        private readonly DocumentSubmissionStateMachine $stateMachine,
        private readonly AuditService $audit,
        private readonly LoggerInterface $logger,
        private readonly int $outboxLeaseSeconds,
        private readonly int $outboxMaxAttempts,
        private readonly int $scanLeaseSeconds,
    ) {
    }

    public function run(string $workerId, int $limit = 10): int
    {
        // Outbox lease/max are reserved for the outbox-claim path; submission
        // row leases use scanLeaseSeconds. Keep both aligned with container config.
        if ($this->outboxLeaseSeconds < 1 || $this->outboxMaxAttempts < 1) {
            throw new \InvalidArgumentException('Invalid outbox lease configuration for document scan worker.');
        }

        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $claimed = $this->transactions->run(
            fn (): array => $this->submissions->claimPendingScan($workerId, '', $now, $this->scanLeaseSeconds, $limit),
        );

        $processed = 0;
        foreach ($claimed as $submission) {
            try {
                $outcome = $this->scanner->scan($submission->objectKey, $submission->displayFilename);
                $applied = $this->transactions->run(function () use ($submission, $outcome, $workerId, $now): bool {
                    return $this->applyScanOutcome($submission, $outcome, $workerId, $now);
                });
                if ($applied) {
                    ++$processed;
                }
            } catch (Throwable $exception) {
                $this->logger->error('document.scan.failed', [
                    'document_submission_id' => $submission->documentSubmissionId,
                    'error' => $exception->getMessage(),
                ]);
            }
        }

        return $processed;
    }

    private function applyScanOutcome(
        DocumentSubmission $submission,
        ScanOutcome $outcome,
        string $workerId,
        DateTimeImmutable $now,
    ): bool {
        if ($outcome->isClean()) {
            $this->stateMachine->assertCanTransition(
                DocumentSubmissionStatus::UPLOADED,
                DocumentSubmissionStatus::UNDER_REVIEW,
                DocumentScanStatus::CLEAN,
            );
            $applied = $this->submissions->applyScanResult(
                $submission->documentSubmissionId,
                $submission->rowVersion,
                DocumentSubmissionStatus::UNDER_REVIEW,
                DocumentScanStatus::CLEAN,
                $now,
                $workerId,
                (string) $submission->scanLeaseToken,
            );
            if (!$applied) {
                return false;
            }

            $this->outbox->markPublishedForPendingAggregate(
                DocumentOutboxEventTypes::DOCUMENT_SCAN_REQUESTED,
                'document_submission',
                (string) $submission->documentSubmissionId,
                $now,
            );

            $this->audit->record(
                new DocumentAuditPayload(
                    action: 'document.scan_completed',
                    entityType: 'document_submission',
                    entityId: (string) $submission->documentSubmissionId,
                    next: [
                        'application_id' => $submission->applicationId,
                        'requirement_id' => $submission->requirementId,
                        'document_submission_id' => $submission->documentSubmissionId,
                        'status' => DocumentSubmissionStatus::UNDER_REVIEW,
                        'scan_status' => DocumentScanStatus::CLEAN,
                        'row_version' => $submission->rowVersion + 1,
                        'result' => 'ok',
                    ],
                ),
                actorType: 'system',
                actorUserId: null,
                source: 'document_scan',
            );

            return true;
        }

        $this->stateMachine->assertCanTransition(
            DocumentSubmissionStatus::UPLOADED,
            DocumentSubmissionStatus::FAILED_SECURITY_SCAN,
            DocumentScanStatus::FAILED,
        );
        $applied = $this->submissions->applyScanResult(
            $submission->documentSubmissionId,
            $submission->rowVersion,
            DocumentSubmissionStatus::FAILED_SECURITY_SCAN,
            DocumentScanStatus::FAILED,
            $now,
            $workerId,
            (string) $submission->scanLeaseToken,
        );
        if (!$applied) {
            return false;
        }

        $this->outbox->markPublishedForPendingAggregate(
            DocumentOutboxEventTypes::DOCUMENT_SCAN_REQUESTED,
            'document_submission',
            (string) $submission->documentSubmissionId,
            $now,
        );

        $this->audit->record(
            new DocumentAuditPayload(
                action: 'document.scan_failed',
                entityType: 'document_submission',
                entityId: (string) $submission->documentSubmissionId,
                next: [
                    'application_id' => $submission->applicationId,
                    'requirement_id' => $submission->requirementId,
                    'document_submission_id' => $submission->documentSubmissionId,
                    'status' => DocumentSubmissionStatus::FAILED_SECURITY_SCAN,
                    'scan_status' => DocumentScanStatus::FAILED,
                    'reason_code' => $outcome->reasonCode,
                    'result' => 'failed',
                ],
            ),
            actorType: 'system',
            actorUserId: null,
            source: 'document_scan',
        );

        return true;
    }
}
