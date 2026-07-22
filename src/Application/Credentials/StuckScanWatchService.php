<?php

declare(strict_types=1);

namespace Academy\Application\Credentials;

use Academy\Application\Audit\AuditService;
use Academy\Domain\Audit\DocumentAuditPayload;
use Academy\Domain\Credentials\DocumentSubmission;
use Academy\Domain\Credentials\DocumentSubmissionRepository;
use Academy\Domain\Credentials\StuckScanPolicy;
use Academy\Domain\Outbox\DocumentOutboxEventTypes;
use Academy\Domain\Outbox\OutboxWriter;
use DateTimeImmutable;
use DateTimeZone;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Never marks a stuck scan clean — that would defeat the malware gate. Below
 * the retry ceiling it silently resets the lease so claimPendingScan() picks
 * the row up again; once retries are exhausted it alerts operators and stops
 * auto-retrying (idempotent per (submission, attempt count) so the alert is
 * not re-sent every poll cycle for the same exhaustion point).
 */
final class StuckScanWatchService
{
    public function __construct(
        private readonly DocumentSubmissionRepository $documents,
        private readonly OutboxWriter $outbox,
        private readonly AuditService $audit,
        private readonly StuckScanPolicy $policy,
        private readonly LoggerInterface $logger,
        private readonly int $limit = 50,
    ) {
    }

    public function run(): int
    {
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $stuck = $this->documents->listStuckPending($now, $this->policy->slaSeconds(), $this->limit);

        $handled = 0;
        foreach ($stuck as $submission) {
            try {
                $this->handleOne($submission, $now);
                $handled++;
            } catch (Throwable $exception) {
                $this->logger->error('stuck scan watch failed for submission', [
                    'document_submission_id' => $submission->documentSubmissionId,
                    'exception' => $exception->getMessage(),
                ]);
            }
        }

        return $handled;
    }

    private function handleOne(DocumentSubmission $submission, DateTimeImmutable $now): void
    {
        if ($this->policy->retriesExhausted($submission->scanAttemptCount)) {
            $this->outbox->enqueue(
                DocumentOutboxEventTypes::DOCUMENT_SCAN_STUCK_ALERT,
                'document_submission',
                (string) $submission->documentSubmissionId,
                [
                    'document_submission_id' => $submission->documentSubmissionId,
                    'application_id' => $submission->applicationId,
                    'requirement_id' => $submission->requirementId,
                    'scan_attempt_count' => $submission->scanAttemptCount,
                ],
                'document.scan_stuck_alert:' . $submission->documentSubmissionId . ':' . $submission->scanAttemptCount,
            );

            $this->audit->record(
                new DocumentAuditPayload(
                    action: 'document.scan_stuck',
                    entityType: 'document_submission',
                    entityId: (string) $submission->documentSubmissionId,
                    next: [
                        'application_id' => $submission->applicationId,
                        'requirement_id' => $submission->requirementId,
                        'document_submission_id' => $submission->documentSubmissionId,
                        'scan_attempt_count' => $submission->scanAttemptCount,
                        'result' => 'stuck',
                    ],
                ),
                actorType: 'system',
                actorUserId: null,
                source: 'stuck_scan_watch',
            );

            return;
        }

        $this->documents->markScanRequeued($submission->documentSubmissionId, $submission->rowVersion, $now);
    }
}
