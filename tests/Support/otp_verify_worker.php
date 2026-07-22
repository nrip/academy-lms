<?php

declare(strict_types=1);

/**
 * Concurrent OTP verification worker.
 * Args: user_id otp ip
 * Prints: ok | conflict | error:<class>
 */

use Academy\Application\Identity\MobileOtpVerificationService;
use Academy\Domain\Exception\DomainRuleException;
use Academy\Tests\Support\ApplicationFactory;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

$userId = (int) ($argv[1] ?? 0);
$otp = (string) ($argv[2] ?? '');
$ip = (string) ($argv[3] ?? '127.0.0.1');
if ($userId < 1 || $otp === '') {
    fwrite(STDERR, "usage: otp_verify_worker.php <user_id> <otp> [ip]\n");
    exit(1);
}

putenv('APP_ENV=testing');
$_ENV['APP_ENV'] = 'testing';
$_SERVER['APP_ENV'] = 'testing';

try {
    $container = ApplicationFactory::container('testing');
    /** @var MobileOtpVerificationService $service */
    $service = $container->get(MobileOtpVerificationService::class);
    $service->verify($userId, null, $otp, $ip);
    echo 'ok';
    exit(0);
} catch (DomainRuleException) {
    echo 'conflict';
    exit(0);
} catch (Throwable $exception) {
    echo 'error:' . $exception::class;
    exit(1);
}
