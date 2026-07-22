#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Concurrent password-reset complete worker.
 *
 * Usage: php reset_complete_worker.php <authorization_secret> <new_password> <ip>
 */

use Academy\Application\Identity\PasswordResetService;
use Academy\Domain\Exception\DomainRuleException;
use Academy\Tests\Support\ApplicationFactory;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

$secret = $argv[1] ?? '';
$password = $argv[2] ?? '';
$ip = $argv[3] ?? '127.0.0.1';

putenv('APP_ENV=testing');
$_ENV['APP_ENV'] = 'testing';
$_SERVER['APP_ENV'] = 'testing';

$container = ApplicationFactory::container('testing');
/** @var PasswordResetService $reset */
$reset = $container->get(PasswordResetService::class);

try {
    $reset->complete($secret, $password, $ip);
    fwrite(STDOUT, "completed\n");
    exit(0);
} catch (DomainRuleException) {
    fwrite(STDOUT, "invalid\n");
    exit(0);
} catch (Throwable $exception) {
    fwrite(STDERR, $exception::class . "\n");
    exit(1);
}
