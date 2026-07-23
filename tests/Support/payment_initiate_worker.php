<?php

declare(strict_types=1);

/**
 * Concurrent payment-initiate worker.
 * Args: applicantUserId authVersion applicationId
 * Prints: pending:<payment_id> | conflict | error:<class>:<message>
 */

use Academy\Application\Payments\PaymentCheckoutService;
use Academy\Domain\Exception\ConflictException;
use Academy\Domain\Identity\AccountStatus;
use Academy\Domain\Identity\AuthStage;
use Academy\Domain\Payments\PaymentStatus;
use Academy\Domain\Security\AuthContext;
use Academy\Tests\Support\ApplicationFactory;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

$applicantUserId = (int) ($argv[1] ?? 0);
$authVersion = (int) ($argv[2] ?? 0);
$applicationId = (int) ($argv[3] ?? 0);

if ($applicantUserId <= 0 || $applicationId <= 0) {
    fwrite(STDERR, "usage: payment_initiate_worker.php <applicantUserId> <authVersion> <applicationId>\n");
    exit(1);
}

putenv('APP_ENV=testing');
$_ENV['APP_ENV'] = 'testing';
$_SERVER['APP_ENV'] = 'testing';

$auth = AuthContext::authenticated(
    userId: $applicantUserId,
    sessionId: 1,
    authStage: AuthStage::FULLY_AUTHENTICATED,
    authVersion: $authVersion,
    hasPrivilegedRole: false,
    accountStatus: AccountStatus::ACTIVE,
);

$attempts = 0;
$maxAttempts = 8;

while ($attempts < $maxAttempts) {
    ++$attempts;
    try {
        $container = ApplicationFactory::container('testing');
        /** @var PaymentCheckoutService $service */
        $service = $container->get(PaymentCheckoutService::class);

        $payment = $service->initiate($auth, $applicationId);
        if ($payment->status !== PaymentStatus::PENDING) {
            echo 'pending_unexpected:' . $payment->status;
            exit(1);
        }

        echo 'pending:' . $payment->paymentId;
        exit(0);
    } catch (ConflictException) {
        echo 'conflict';
        exit(0);
    } catch (\PDOException $exception) {
        // InnoDB deadlock / lock wait — retry; final failure treated as conflict for the loser.
        $sqlState = $exception->errorInfo[0] ?? '';
        $driverCode = (int) ($exception->errorInfo[1] ?? 0);
        $isDeadlock = $sqlState === '40001' || $driverCode === 1213 || $driverCode === 1205;
        if ($isDeadlock && $attempts < $maxAttempts) {
            usleep(random_int(5_000, 40_000));
            continue;
        }
        if ($isDeadlock) {
            echo 'conflict';
            exit(0);
        }
        echo 'error:' . $exception::class . ':' . $exception->getMessage();
        exit(1);
    } catch (Throwable $exception) {
        echo 'error:' . $exception::class . ':' . $exception->getMessage();
        exit(1);
    }
}

echo 'conflict';
exit(0);
