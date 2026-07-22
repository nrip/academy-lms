<?php

declare(strict_types=1);

/**
 * Concurrent draft-application-creation worker.
 * Args: userId authVersion batchId
 * Prints: created:<application_id> | conflict | error:<class>
 */

use Academy\Application\Admissions\DraftApplicationService;
use Academy\Domain\Identity\AccountStatus;
use Academy\Domain\Identity\AuthStage;
use Academy\Domain\Security\AuthContext;
use Academy\Tests\Support\ApplicationFactory;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

$userId = (int) ($argv[1] ?? 0);
$authVersion = (int) ($argv[2] ?? 0);
$batchId = (int) ($argv[3] ?? 0);
if ($userId <= 0 || $batchId <= 0) {
    fwrite(STDERR, "usage: draft_application_worker.php <userId> <authVersion> <batchId>\n");
    exit(1);
}

putenv('APP_ENV=testing');
$_ENV['APP_ENV'] = 'testing';
$_SERVER['APP_ENV'] = 'testing';

try {
    $container = ApplicationFactory::container('testing');
    /** @var DraftApplicationService $service */
    $service = $container->get(DraftApplicationService::class);

    $auth = AuthContext::authenticated(
        userId: $userId,
        sessionId: 1,
        authStage: AuthStage::FULLY_AUTHENTICATED,
        authVersion: $authVersion,
        hasPrivilegedRole: false,
        accountStatus: AccountStatus::ACTIVE,
    );

    $application = $service->createDraft($auth, $batchId);
    echo 'created:' . $application->applicationId;
    exit(0);
} catch (\Academy\Domain\Exception\ConflictException) {
    echo 'conflict';
    exit(0);
} catch (Throwable $exception) {
    echo 'error:' . $exception::class . ':' . $exception->getMessage();
    exit(1);
}
