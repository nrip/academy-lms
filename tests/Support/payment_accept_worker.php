<?php

declare(strict_types=1);

/**
 * Accept a captured Payment via SuccessfulPaymentAcceptanceService.
 * Args: paymentId [providerPaymentId] [amountMinor] [currency]
 * Prints: ok:<outcome> | error:...
 */

use Academy\Application\Payments\SuccessfulPaymentAcceptanceService;
use Academy\Domain\Payments\GatewayPaymentResult;
use Academy\Domain\Payments\PaymentRepository;
use Academy\Infrastructure\Database\TransactionManager;
use Academy\Tests\Support\ApplicationFactory;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

$paymentId = (int) ($argv[1] ?? 0);
$providerPaymentId = (string) ($argv[2] ?? '');
$amountMinor = (int) ($argv[3] ?? 0);
$currency = (string) ($argv[4] ?? 'INR');

putenv('APP_ENV=testing');
$_ENV['APP_ENV'] = 'testing';
$_SERVER['APP_ENV'] = 'testing';
putenv('PAYMENTS_FAKE_GATEWAY=1');
$_ENV['PAYMENTS_FAKE_GATEWAY'] = '1';

$attempts = 0;
$maxAttempts = 8;

while ($attempts < $maxAttempts) {
    ++$attempts;
    try {
        $container = ApplicationFactory::container('testing');
        $payment = $container->get(PaymentRepository::class)->findById($paymentId);
        if ($payment === null || $payment->providerOrderId === null) {
            echo 'error:payment_missing';
            exit(1);
        }

        $provider = new GatewayPaymentResult(
            providerPaymentId: $providerPaymentId !== '' ? $providerPaymentId : ('pay_race_' . $paymentId),
            providerOrderId: $payment->providerOrderId,
            amountMinor: $amountMinor > 0 ? $amountMinor : $payment->amountMinor,
            currency: $currency !== '' ? $currency : $payment->currency,
            providerStatus: 'captured',
            captured: true,
        );

        $result = $container->get(TransactionManager::class)->runWithDeadlockRetry(
            static fn () => $container->get(SuccessfulPaymentAcceptanceService::class)->accept(
                $paymentId,
                $provider,
                'concurrency_worker',
                'evt_race_' . $paymentId,
            ),
        );

        echo 'ok:' . $result->outcome;
        exit(0);
    } catch (PDOException $exception) {
        $sqlState = $exception->errorInfo[0] ?? '';
        $driverCode = (int) ($exception->errorInfo[1] ?? 0);
        $isDeadlock = $sqlState === '40001' || $driverCode === 1213 || $driverCode === 1205;
        if ($isDeadlock && $attempts < $maxAttempts) {
            usleep(random_int(5_000, 40_000));
            continue;
        }
        echo 'error:' . $exception::class . ':' . $exception->getMessage();
        exit(1);
    } catch (Throwable $exception) {
        echo 'error:' . $exception::class . ':' . $exception->getMessage();
        exit(1);
    }
}

echo 'error:deadlock_retry_exhausted';
exit(1);
