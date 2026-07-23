<?php

declare(strict_types=1);

/**
 * Attempt reconciliation with a pre-claimed stale lease snapshot.
 * Args: paymentId leaseOwner leaseToken expectedRowVersion
 * Prints: stale_version | stale_lease | mutated | error:...
 */

use Academy\Application\Payments\SuccessfulPaymentAcceptanceService;
use Academy\Domain\Payments\GatewayPaymentResult;
use Academy\Domain\Payments\PaymentGateway;
use Academy\Domain\Payments\PaymentRepository;
use Academy\Infrastructure\Database\TransactionManager;
use Academy\Infrastructure\Payments\FakePaymentGateway;
use Academy\Tests\Support\ApplicationFactory;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

$paymentId = (int) ($argv[1] ?? 0);
$leaseOwner = (string) ($argv[2] ?? '');
$leaseToken = (string) ($argv[3] ?? '');
$expectedRowVersion = (int) ($argv[4] ?? 0);

putenv('APP_ENV=testing');
$_ENV['APP_ENV'] = 'testing';
$_SERVER['APP_ENV'] = 'testing';
putenv('PAYMENTS_FAKE_GATEWAY=1');
$_ENV['PAYMENTS_FAKE_GATEWAY'] = '1';

try {
    $container = ApplicationFactory::container('testing');
    $payments = $container->get(PaymentRepository::class);
    $payment = $payments->findById($paymentId);
    if ($payment === null || $payment->providerOrderId === null) {
        echo 'error:payment_missing';
        exit(1);
    }

    $gateway = $container->get(PaymentGateway::class);
    $captured = null;
    if ($gateway instanceof FakePaymentGateway) {
        try {
            $results = $gateway->fetchPaymentsForOrder($payment->providerOrderId);
            foreach ($results as $result) {
                if ($result->isCapturedSuccess()) {
                    $captured = $result;
                    break;
                }
            }
        } catch (Throwable) {
            $captured = $gateway->simulateCapture(
                $payment->providerOrderId,
                $payment->amountMinor,
                $payment->currency,
                'pay_stale_' . $payment->paymentId,
            );
        }
    }
    if ($captured === null) {
        $captured = new GatewayPaymentResult(
            providerPaymentId: 'pay_stale_' . $payment->paymentId,
            providerOrderId: $payment->providerOrderId,
            amountMinor: $payment->amountMinor,
            currency: $payment->currency,
            providerStatus: 'captured',
            captured: true,
        );
    }

    $outcome = $container->get(TransactionManager::class)->run(
        static function () use ($container, $payments, $paymentId, $leaseOwner, $leaseToken, $expectedRowVersion, $captured): string {
            $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
            if (!$payments->hasActiveReconcileLease($paymentId, $leaseOwner, $leaseToken, $now)) {
                return 'stale_lease';
            }

            $locked = $payments->findByIdForUpdate($paymentId);
            if ($locked === null || $locked->rowVersion !== $expectedRowVersion) {
                $payments->clearReconcileLease($paymentId, $leaseOwner, $leaseToken, $now);

                return 'stale_version';
            }

            $container->get(SuccessfulPaymentAcceptanceService::class)->accept(
                $paymentId,
                $captured,
                'stale_reconcile_worker',
                $captured->providerPaymentId,
            );
            $payments->clearReconcileLease($paymentId, $leaseOwner, $leaseToken, $now);

            return 'mutated';
        },
    );

    echo $outcome;
    exit(0);
} catch (Throwable $exception) {
    echo 'error:' . $exception::class . ':' . $exception->getMessage();
    exit(1);
}
