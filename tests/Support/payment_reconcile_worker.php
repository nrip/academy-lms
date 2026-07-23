<?php

declare(strict_types=1);

/**
 * Run payment reconciliation for captured provider state.
 * Args: paymentId
 * Prints: ok:<processed_count> | noop | error:...
 */

use Academy\Application\Payments\PaymentReconciliationService;
use Academy\Domain\Payments\PaymentGateway;
use Academy\Domain\Payments\PaymentRepository;
use Academy\Infrastructure\Payments\FakePaymentGateway;
use Academy\Tests\Support\ApplicationFactory;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

$paymentId = (int) ($argv[1] ?? 0);

putenv('APP_ENV=testing');
$_ENV['APP_ENV'] = 'testing';
$_SERVER['APP_ENV'] = 'testing';
putenv('PAYMENTS_FAKE_GATEWAY=1');
$_ENV['PAYMENTS_FAKE_GATEWAY'] = '1';
putenv('PAYMENTS_RECONCILE_PENDING_STALE_SECONDS=0');
$_ENV['PAYMENTS_RECONCILE_PENDING_STALE_SECONDS'] = '0';

try {
    $container = ApplicationFactory::container('testing');
    $payments = $container->get(PaymentRepository::class);
    $payment = $payments->findById($paymentId);
    if ($payment === null || $payment->providerOrderId === null) {
        echo 'error:payment_missing';
        exit(1);
    }

    $gateway = $container->get(PaymentGateway::class);
    if ($gateway instanceof FakePaymentGateway) {
        try {
            $gateway->fetchPaymentsForOrder($payment->providerOrderId);
        } catch (Throwable) {
            $gateway->simulateCapture(
                $payment->providerOrderId,
                $payment->amountMinor,
                $payment->currency,
                'pay_recon_' . $payment->paymentId,
            );
        }
    }

    $processed = $container->get(PaymentReconciliationService::class)->run(
        'reconcile-worker-' . getmypid(),
        25,
    );

    echo $processed > 0 ? 'ok:' . $processed : 'noop';
    exit(0);
} catch (Throwable $exception) {
    echo 'error:' . $exception::class . ':' . $exception->getMessage();
    exit(1);
}
