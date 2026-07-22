<?php

declare(strict_types=1);

/**
 * Concurrent POST confirm worker.
 * Args: confirmation_secret purpose
 * Prints: ok | conflict | error:<class>
 */

use Academy\Application\Identity\TokenConfirmationService;
use Academy\Domain\Exception\DomainRuleException;
use Academy\Domain\Identity\TokenPurpose;
use Academy\Tests\Support\ApplicationFactory;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

$secret = (string) ($argv[1] ?? '');
$purpose = (string) ($argv[2] ?? TokenPurpose::EMAIL_VERIFY);
if ($secret === '') {
    fwrite(STDERR, "usage: token_confirm_post_worker.php <confirmation_secret> [purpose]\n");
    exit(1);
}

putenv('APP_ENV=testing');
$_ENV['APP_ENV'] = 'testing';
$_SERVER['APP_ENV'] = 'testing';

try {
    $container = ApplicationFactory::container('testing');
    /** @var TokenConfirmationService $service */
    $service = $container->get(TokenConfirmationService::class);
    $service->confirm($secret, $purpose);
    echo 'ok';
    exit(0);
} catch (DomainRuleException) {
    echo 'conflict';
    exit(0);
} catch (Throwable $exception) {
    echo 'error:' . $exception::class;
    exit(1);
}
