<?php

declare(strict_types=1);

/**
 * Prepare a pending payment and simulate gateway capture.
 * Args: applicantUserId authVersion applicationId
 * Prints: ready:<payment_id>
 */

use Academy\Application\Payments\PaymentCheckoutService;
use Academy\Domain\Identity\AccountStatus;
use Academy\Domain\Identity\AuthStage;
use Academy\Domain\Payments\PaymentGateway;
use Academy\Domain\Security\AuthContext;
use Academy\Infrastructure\Payments\FakePaymentGateway;
use Academy\Tests\Support\ApplicationFactory;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

$applicantUserId = (int) ($argv[1] ?? 0);
$authVersion = (int) ($argv[2] ?? 0);
$applicationId = (int) ($argv[3] ?? 0);

putenv('APP_ENV=testing');
$_ENV['APP_ENV'] = 'testing';
$_SERVER['APP_ENV'] = 'testing';
putenv('PAYMENTS_FAKE_GATEWAY=1');
$_ENV['PAYMENTS_FAKE_GATEWAY'] = '1';

$auth = AuthContext::authenticated(
    userId: $applicantUserId,
    sessionId: 1,
    authStage: AuthStage::FULLY_AUTHENTICATED,
    authVersion: $authVersion,
    hasPrivilegedRole: false,
    accountStatus: AccountStatus::ACTIVE,
);

$container = ApplicationFactory::container('testing');
$payment = $container->get(PaymentCheckoutService::class)->initiate($auth, $applicationId);
$gateway = $container->get(PaymentGateway::class);
if (!$gateway instanceof FakePaymentGateway) {
    fwrite(STDERR, "expected FakePaymentGateway\n");
    exit(1);
}
$gateway->simulateCapture(
    (string) $payment->providerOrderId,
    $payment->amountMinor,
    $payment->currency,
    'pay_conc_' . $payment->paymentId,
);

echo 'ready:' . $payment->paymentId;
exit(0);
