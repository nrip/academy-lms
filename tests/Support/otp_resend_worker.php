<?php

declare(strict_types=1);

/**
 * Concurrent OTP resend worker.
 * Args: user_id ip
 * Prints: ok | unavailable | rate_limited | error:<class>
 */

use Academy\Application\Identity\MobileOtpResendService;
use Academy\Domain\Exception\RateLimitExceededException;
use Academy\Domain\Exception\ServiceUnavailableException;
use Academy\Tests\Support\ApplicationFactory;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

$userId = (int) ($argv[1] ?? 0);
$ip = (string) ($argv[2] ?? '127.0.0.1');
if ($userId < 1) {
    fwrite(STDERR, "usage: otp_resend_worker.php <user_id> [ip]\n");
    exit(1);
}

putenv('APP_ENV=testing');
$_ENV['APP_ENV'] = 'testing';
$_SERVER['APP_ENV'] = 'testing';

try {
    $container = ApplicationFactory::container('testing');
    /** @var MobileOtpResendService $service */
    $service = $container->get(MobileOtpResendService::class);
    $service->resend($userId, null, $ip);
    echo 'ok';
    exit(0);
} catch (ServiceUnavailableException) {
    echo 'unavailable';
    exit(0);
} catch (RateLimitExceededException) {
    echo 'rate_limited';
    exit(0);
} catch (Throwable $exception) {
    echo 'error:' . $exception::class;
    exit(1);
}
