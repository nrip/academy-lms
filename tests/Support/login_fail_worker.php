#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Concurrent failed-login worker.
 *
 * Usage: php login_fail_worker.php <email> <ip> <attempt_id>
 */

use Academy\Application\Identity\LoginService;
use Academy\Domain\Exception\AuthenticationException;
use Academy\Tests\Support\ApplicationFactory;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

$email = $argv[1] ?? '';
$ip = $argv[2] ?? '127.0.0.1';
$attemptId = $argv[3] ?? '0';

putenv('APP_ENV=testing');
$_ENV['APP_ENV'] = 'testing';
$_SERVER['APP_ENV'] = 'testing';

$container = ApplicationFactory::container('testing');
/** @var LoginService $login */
$login = $container->get(LoginService::class);

try {
    $login->authenticate($email, 'wrong-password-worker-' . $attemptId, $ip);
    fwrite(STDOUT, "unexpected_success\n");
    exit(2);
} catch (AuthenticationException) {
    fwrite(STDOUT, "failed\n");
    exit(0);
} catch (Throwable $exception) {
    fwrite(STDERR, $exception::class . "\n");
    exit(1);
}
