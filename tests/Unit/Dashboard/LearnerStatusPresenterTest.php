<?php

declare(strict_types=1);

namespace Academy\Tests\Unit\Dashboard;

use Academy\Application\Dashboard\LearnerStatusPresenter;
use Academy\Domain\Admissions\ApplicationStatus;
use Academy\Domain\Learning\EnrolmentLifecycleStatus;
use Academy\Domain\Payments\PaymentStatus;
use PHPUnit\Framework\TestCase;

final class LearnerStatusPresenterTest extends TestCase
{
    private LearnerStatusPresenter $presenter;

    protected function setUp(): void
    {
        $this->presenter = new LearnerStatusPresenter();
    }

    public function testDraftMapsToCompleteApplication(): void
    {
        $view = $this->presenter->applicationPresentation(ApplicationStatus::DRAFT);
        self::assertSame('Complete your application', $view->nextActionLabel);
        self::assertSame('Draft', $view->label);
        self::assertStringNotContainsString('lease', strtolower($view->explanation));
    }

    public function testUnderReviewAndPaymentPendingLabels(): void
    {
        self::assertSame('Under review', $this->presenter->applicationPresentation(ApplicationStatus::UNDER_REVIEW)->label);
        self::assertSame('Payment required', $this->presenter->applicationPresentation(ApplicationStatus::PAYMENT_PENDING)->label);
        self::assertSame('Corrections required', $this->presenter->applicationPresentation(ApplicationStatus::RESUBMISSION_REQUESTED)->label);
    }

    public function testPaymentPendingIsConfirmingNotPaid(): void
    {
        $view = $this->presenter->paymentPresentation(PaymentStatus::PENDING);
        self::assertSame('Confirming payment', $view->label);
        self::assertStringContainsString('not final', strtolower($view->explanation));
    }

    public function testReconciliationPendingSafeLabel(): void
    {
        $view = $this->presenter->paymentPresentation(PaymentStatus::RECONCILIATION_PENDING);
        self::assertSame('Payment under verification', $view->label);
        self::assertStringNotContainsString('reconcil', strtolower($view->label));
    }

    public function testEnrolmentScheduledAndActive(): void
    {
        self::assertSame('Scheduled', $this->presenter->enrolmentPresentation(EnrolmentLifecycleStatus::SCHEDULED)->label);
        self::assertSame('Active', $this->presenter->enrolmentPresentation(EnrolmentLifecycleStatus::ACTIVE)->label);
    }
}
