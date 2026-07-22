<?php

declare(strict_types=1);

/**
 * Concurrent application-submit worker.
 * Args: userId authVersion applicationId
 * Prints: submitted:<application_id> | conflict | error:<class>
 */

use Academy\Application\Admissions\ApplicationSubmitService;
use Academy\Domain\Exception\ConflictException;
use Academy\Domain\Identity\AccountStatus;
use Academy\Domain\Identity\AuthStage;
use Academy\Domain\Security\AuthContext;
use Academy\Tests\Support\ApplicationFactory;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

$userId = (int) ($argv[1] ?? 0);
$authVersion = (int) ($argv[2] ?? 0);
$applicationId = (int) ($argv[3] ?? 0);

if ($userId <= 0 || $applicationId <= 0) {
    fwrite(STDERR, "usage: document_submit_worker.php <userId> <authVersion> <applicationId>\n");
    exit(1);
}

putenv('APP_ENV=testing');
$_ENV['APP_ENV'] = 'testing';
$_SERVER['APP_ENV'] = 'testing';

try {
    $container = ApplicationFactory::container('testing');
    /** @var ApplicationSubmitService $service */
    $service = $container->get(ApplicationSubmitService::class);

    $auth = AuthContext::authenticated(
        userId: $userId,
        sessionId: 1,
        authStage: AuthStage::FULLY_AUTHENTICATED,
        authVersion: $authVersion,
        hasPrivilegedRole: false,
        accountStatus: AccountStatus::ACTIVE,
    );

    $application = $service->submit($auth, $applicationId);
    echo 'submitted:' . $application->applicationId;
    exit(0);
} catch (ConflictException) {
    echo 'conflict';
    exit(0);
} catch (Throwable $exception) {
    echo 'error:' . $exception::class . ':' . $exception->getMessage();
    exit(1);
}
