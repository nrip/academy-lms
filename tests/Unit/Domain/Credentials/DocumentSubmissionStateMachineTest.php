<?php

declare(strict_types=1);

namespace Academy\Tests\Unit\Domain\Credentials;

use Academy\Domain\Credentials\DocumentScanStatus;
use Academy\Domain\Credentials\DocumentSubmissionStateMachine;
use Academy\Domain\Credentials\DocumentSubmissionStatus;
use Academy\Domain\Exception\DomainRuleException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DocumentSubmissionStateMachineTest extends TestCase
{
    #[DataProvider('allowedTransitions')]
    public function testAllowedTransitionsAreAccepted(string $from, string $to): void
    {
        $machine = new DocumentSubmissionStateMachine();

        $machine->assertCanTransition($from, $to, $this->scanStatusFor($to), $this->reasonFor($to));

        self::assertTrue(true);
    }

    #[DataProvider('disallowedTransitions')]
    public function testDisallowedTransitionsAreRejected(string $from, string $to): void
    {
        $machine = new DocumentSubmissionStateMachine();

        $this->expectException(DomainRuleException::class);
        $machine->assertCanTransition($from, $to, DocumentScanStatus::CLEAN, 'reason');
    }

    public function testUploadedToUnderReviewRequiresCleanScan(): void
    {
        $machine = new DocumentSubmissionStateMachine();

        $this->expectException(DomainRuleException::class);
        $machine->assertCanTransition(
            DocumentSubmissionStatus::UPLOADED,
            DocumentSubmissionStatus::UNDER_REVIEW,
            DocumentScanStatus::PENDING,
        );
    }

    public function testUploadedToFailedSecurityScanRequiresFailedScan(): void
    {
        $machine = new DocumentSubmissionStateMachine();

        $this->expectException(DomainRuleException::class);
        $machine->assertCanTransition(
            DocumentSubmissionStatus::UPLOADED,
            DocumentSubmissionStatus::FAILED_SECURITY_SCAN,
            DocumentScanStatus::CLEAN,
        );
    }

    public function testRejectedRequiresReasonCode(): void
    {
        $machine = new DocumentSubmissionStateMachine();

        $this->expectException(DomainRuleException::class);
        $machine->assertCanTransition(
            DocumentSubmissionStatus::UNDER_REVIEW,
            DocumentSubmissionStatus::REJECTED,
            DocumentScanStatus::CLEAN,
        );
    }

    public function testResubmissionRequestedRequiresReasonCode(): void
    {
        $machine = new DocumentSubmissionStateMachine();

        $this->expectException(DomainRuleException::class);
        $machine->assertCanTransition(
            DocumentSubmissionStatus::UNDER_REVIEW,
            DocumentSubmissionStatus::RESUBMISSION_REQUESTED,
            DocumentScanStatus::CLEAN,
        );
    }

    public function testAllowedPairsAndDisallowedPairsPartitionTheFullMatrix(): void
    {
        $allowed = DocumentSubmissionStateMachine::allowedPairs();
        $disallowed = DocumentSubmissionStateMachine::disallowedPairs();

        $expectedTotal = count(DocumentSubmissionStatus::ALL) * (count(DocumentSubmissionStatus::ALL) - 1);
        self::assertSame($expectedTotal, count($allowed) + count($disallowed));
    }

    /**
     * @return list<array{0: string, 1: string}>
     */
    public static function allowedTransitions(): array
    {
        return DocumentSubmissionStateMachine::allowedPairs();
    }

    /**
     * @return list<array{0: string, 1: string}>
     */
    public static function disallowedTransitions(): array
    {
        return DocumentSubmissionStateMachine::disallowedPairs();
    }

    private function scanStatusFor(string $to): string
    {
        if ($to === DocumentSubmissionStatus::UNDER_REVIEW) {
            return DocumentScanStatus::CLEAN;
        }
        if ($to === DocumentSubmissionStatus::FAILED_SECURITY_SCAN) {
            return DocumentScanStatus::FAILED;
        }

        return DocumentScanStatus::CLEAN;
    }

    private function reasonFor(string $to): ?string
    {
        return in_array($to, [DocumentSubmissionStatus::REJECTED, DocumentSubmissionStatus::RESUBMISSION_REQUESTED], true)
            ? 'reason_code'
            : null;
    }
}
