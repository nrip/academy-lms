<?php

declare(strict_types=1);

namespace Academy\Domain\Credentials;

use Academy\Domain\Exception\DomainRuleException;
use DateTimeImmutable;

/**
 * DocumentSubmission business-status machine per STATE_MACHINE_ADDENDUM §3.
 * Scan status is updated separately; Uploaded→Under Review requires clean scan.
 */
final class DocumentSubmissionStateMachine
{
    /** @var array<string, list<string>> */
    private const ALLOWED = [
        DocumentSubmissionStatus::UPLOADED => [
            DocumentSubmissionStatus::UNDER_REVIEW,
            DocumentSubmissionStatus::FAILED_SECURITY_SCAN,
        ],
        DocumentSubmissionStatus::UNDER_REVIEW => [
            DocumentSubmissionStatus::APPROVED,
            DocumentSubmissionStatus::REJECTED,
            DocumentSubmissionStatus::RESUBMISSION_REQUESTED,
        ],
        DocumentSubmissionStatus::APPROVED => [
            DocumentSubmissionStatus::SUPERSEDED,
            DocumentSubmissionStatus::EXPIRED,
        ],
        DocumentSubmissionStatus::REJECTED => [
            DocumentSubmissionStatus::SUPERSEDED,
        ],
        DocumentSubmissionStatus::RESUBMISSION_REQUESTED => [
            DocumentSubmissionStatus::EXPIRED,
            DocumentSubmissionStatus::SUPERSEDED,
        ],
        DocumentSubmissionStatus::FAILED_SECURITY_SCAN => [
            DocumentSubmissionStatus::SUPERSEDED,
        ],
        DocumentSubmissionStatus::EXPIRED => [],
        DocumentSubmissionStatus::SUPERSEDED => [],
    ];

    public function assertCanTransition(
        string $from,
        string $to,
        string $scanStatus,
        ?string $reasonCode = null,
    ): void {
        DocumentSubmissionStatus::assertValid($from);
        DocumentSubmissionStatus::assertValid($to);
        DocumentScanStatus::assertValid($scanStatus);

        $allowed = self::ALLOWED[$from];
        if (!in_array($to, $allowed, true)) {
            throw new DomainRuleException(sprintf(
                'Document transition from %s to %s is not allowed.',
                $from,
                $to,
            ));
        }

        if ($to === DocumentSubmissionStatus::UNDER_REVIEW && $scanStatus !== DocumentScanStatus::CLEAN) {
            throw new DomainRuleException('Document cannot enter Under Review without a clean scan.');
        }

        if ($to === DocumentSubmissionStatus::FAILED_SECURITY_SCAN && $scanStatus !== DocumentScanStatus::FAILED) {
            throw new DomainRuleException('Failed Security Scan requires scan_status=failed.');
        }

        if (in_array($to, [DocumentSubmissionStatus::REJECTED, DocumentSubmissionStatus::RESUBMISSION_REQUESTED], true)
            && ($reasonCode === null || trim($reasonCode) === '')
        ) {
            throw new DomainRuleException('A rejection reason code is required.');
        }
    }

    public function transition(
        string $from,
        string $to,
        string $scanStatus,
        DateTimeImmutable $at,
        ?string $reasonCode = null,
    ): DocumentSubmissionTransitionResult {
        $this->assertCanTransition($from, $to, $scanStatus, $reasonCode);

        return new DocumentSubmissionTransitionResult(
            fromStatus: $from,
            toStatus: $to,
            scanStatus: $scanStatus,
            transitionedAt: $at,
            reasonCode: $reasonCode,
        );
    }

    /**
     * @return array<string, list<string>>
     */
    public static function allowedMatrix(): array
    {
        return self::ALLOWED;
    }

    /**
     * @return list<array{0: string, 1: string}>
     */
    public static function allowedPairs(): array
    {
        $pairs = [];
        foreach (self::ALLOWED as $from => $tos) {
            foreach ($tos as $to) {
                $pairs[] = [$from, $to];
            }
        }

        return $pairs;
    }

    /**
     * @return list<array{0: string, 1: string}>
     */
    public static function disallowedPairs(): array
    {
        $disallowed = [];
        foreach (DocumentSubmissionStatus::ALL as $from) {
            foreach (DocumentSubmissionStatus::ALL as $to) {
                if ($from === $to) {
                    continue;
                }
                if (!in_array($to, self::ALLOWED[$from], true)) {
                    $disallowed[] = [$from, $to];
                }
            }
        }

        return $disallowed;
    }
}
