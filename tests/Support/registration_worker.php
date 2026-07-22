<?php

declare(strict_types=1);

/**
 * Concurrent registration worker.
 * Args: email mobile password ip
 * Prints: created:<user_id> | duplicate | error:<class>
 */

use Academy\Application\Identity\RegistrationService;
use Academy\Tests\Support\ApplicationFactory;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

$email = (string) ($argv[1] ?? '');
$mobile = (string) ($argv[2] ?? '');
$password = (string) ($argv[3] ?? '');
$ip = (string) ($argv[4] ?? '127.0.0.1');
if ($email === '' || $mobile === '' || $password === '') {
    fwrite(STDERR, "usage: registration_worker.php <email> <mobile> <password> [ip]\n");
    exit(1);
}

putenv('APP_ENV=testing');
$_ENV['APP_ENV'] = 'testing';
$_SERVER['APP_ENV'] = 'testing';

try {
    $container = ApplicationFactory::container('testing');
    /** @var RegistrationService $service */
    $service = $container->get(RegistrationService::class);
    $result = $service->register($email, $mobile, $password, true, true, $ip);
    if ($result->created) {
        echo 'created:' . $result->userId;
        exit(0);
    }
    echo 'duplicate';
    exit(0);
} catch (Throwable $exception) {
    echo 'error:' . $exception::class;
    exit(1);
}
