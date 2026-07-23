<?php

declare(strict_types=1);

/**
 * Process a capture webhook for an existing pending payment.
 * Args: paymentId eventId
 * Prints: ok:<enrolment_count> | duplicate_or_noop | error:...
 */

use Academy\Application\Payments\PaymentWebhookProcessor;
use Academy\Application\Payments\RazorpayWebhookIngressService;
use Academy\Domain\Payments\PaymentGateway;
use Academy\Domain\Payments\PaymentRepository;
use Academy\Infrastructure\Payments\FakePaymentGateway;
use Academy\Infrastructure\Payments\FakeWebhookSigner;
use Academy\Tests\Support\ApplicationFactory;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

$paymentId = (int) ($argv[1] ?? 0);
$eventId = (string) ($argv[2] ?? 'evt_x');

putenv('APP_ENV=testing');
$_ENV['APP_ENV'] = 'testing';
$_SERVER['APP_ENV'] = 'testing';
putenv('PAYMENTS_FAKE_GATEWAY=1');
$_ENV['PAYMENTS_FAKE_GATEWAY'] = '1';

try {
    $container = ApplicationFactory::container('testing');
    $payment = $container->get(PaymentRepository::class)->findById($paymentId);
    if ($payment === null || $payment->providerOrderId === null) {
        echo 'error:payment_missing';
        exit(1);
    }

    $gateway = $container->get(PaymentGateway::class);
    if ($gateway instanceof FakePaymentGateway) {
        try {
            $gateway->fetchPaymentsForOrder($payment->providerOrderId);
        } catch (\Throwable) {
            $gateway->simulateCapture(
                $payment->providerOrderId,
                $payment->amountMinor,
                $payment->currency,
                'pay_conc_' . $payment->paymentId,
            );
        }
    }

    $payload = [
        'id' => $eventId,
        'event' => 'payment.captured',
        'created_at' => time(),
        'payload' => [
            'payment' => [
                'entity' => [
                    'id' => 'pay_conc_' . $payment->paymentId,
                    'order_id' => $payment->providerOrderId,
                    'amount' => $payment->amountMinor,
                    'currency' => $payment->currency,
                    'status' => 'captured',
                    'captured' => true,
                ],
            ],
        ],
    ];
    $raw = json_encode($payload, JSON_THROW_ON_ERROR);
    $signature = $container->get(FakeWebhookSigner::class)->sign($raw);
    $ingress = $container->get(RazorpayWebhookIngressService::class);
    $result = $ingress->receive($raw, $signature, 'application/json');
    $container->get(PaymentWebhookProcessor::class)->run('worker-' . getmypid());

    $pdo = \Academy\Tests\Support\DatabaseTestCase::pdo();
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM enrolments WHERE application_id = ?');
    $stmt->execute([$payment->applicationId]);
    $count = (int) $stmt->fetchColumn();

    if ($result['duplicate'] && $count === 1) {
        echo 'duplicate_or_noop';
        exit(0);
    }

    echo 'ok:' . $count;
    exit(0);
} catch (Throwable $exception) {
    echo 'error:' . $exception::class . ':' . $exception->getMessage();
    exit(1);
}
