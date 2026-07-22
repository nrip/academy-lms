<?php

declare(strict_types=1);

/**
 * Concurrent GET beginConfirmationFromRawToken worker.
 * Args: raw_token purpose client_ip
 * Prints: ok:<confirmation_secret> | invalid | error:<class>
 */

use Academy\Application\Identity\TokenConfirmationService;
use Academy\Domain\Identity\TokenPurpose;
use Academy\Tests\Support\ApplicationFactory;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

$rawToken = (string) ($argv[1] ?? '');
$purpose = (string) ($argv[2] ?? TokenPurpose::EMAIL_VERIFY);
$clientIp = (string) ($argv[3] ?? '127.0.0.1');
if ($rawToken === '') {
    fwrite(STDERR, "usage: token_confirm_get_worker.php <raw_token> [purpose] [ip]\n");
    exit(1);
}

putenv('APP_ENV=testing');
$_ENV['APP_ENV'] = 'testing';
$_SERVER['APP_ENV'] = 'testing';

try {
    $container = ApplicationFactory::container('testing');
    /** @var TokenConfirmationService $service */
    $service = $container->get(TokenConfirmationService::class);
    $result = $service->beginConfirmationFromRawToken($rawToken, $purpose, $clientIp, 900);
    if (($result['status'] ?? '') === 'ok') {
        echo 'ok:' . ($result['confirmation_secret'] ?? '');
        exit(0);
    }
    echo 'invalid';
    exit(0);
} catch (Throwable $exception) {
    echo 'error:' . $exception::class;
    exit(1);
}
