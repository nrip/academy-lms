<?php

declare(strict_types=1);

namespace Academy\Application\Payments;

use Academy\Application\RBAC\AuthorizationService;
use Academy\Domain\Exception\NotFoundException;
use Academy\Domain\Payments\Payment;
use Academy\Domain\Payments\PaymentRepository;
use Academy\Domain\Payments\PaymentStatusHistoryRepository;
use Academy\Domain\Security\AuthContext;

/**
 * Finance payment list/detail. Must not touch DocumentSubmission repositories.
 */
final class FinancePaymentQueryService
{
    public function __construct(
        private readonly AuthorizationService $authorization,
        private readonly PaymentRepository $payments,
        private readonly PaymentStatusHistoryRepository $paymentHistory,
    ) {
    }

    /**
     * @return array{
     *   items: list<Payment>,
     *   total: int,
     *   limit: int,
     *   offset: int
     * }
     */
    public function list(
        AuthContext $auth,
        ?string $status = null,
        ?string $publicReference = null,
        ?string $providerOrderId = null,
        ?int $applicationId = null,
        int $limit = 50,
        int $offset = 0,
    ): array {
        $this->authorization->require($auth, 'finance.payment.view');

        $limit = max(1, min(100, $limit));
        $offset = max(0, $offset);

        return [
            'items' => $this->payments->listForFinance(
                $status,
                $publicReference,
                $providerOrderId,
                $applicationId,
                $limit,
                $offset,
            ),
            'total' => $this->payments->countForFinance(
                $status,
                $publicReference,
                $providerOrderId,
                $applicationId,
            ),
            'limit' => $limit,
            'offset' => $offset,
        ];
    }

    /**
     * @return array{
     *   payment: Payment,
     *   history: list<array{
     *     history_id: int,
     *     payment_id: int,
     *     application_id: int,
     *     status_before: string,
     *     status_after: string,
     *     source: string,
     *     provider_event_reference: ?string,
     *     reason: ?string,
     *     failure_category: ?string,
     *     actor_user_id: ?int,
     *     created_at: string
     *   }>
     * }
     */
    public function detail(AuthContext $auth, int $paymentId): array
    {
        $this->authorization->require($auth, 'finance.payment.view');

        $payment = $this->payments->findById($paymentId);
        if ($payment === null) {
            throw new NotFoundException('Payment not found.');
        }

        return [
            'payment' => $payment,
            'history' => $this->paymentHistory->listByPaymentId($paymentId),
        ];
    }
}
