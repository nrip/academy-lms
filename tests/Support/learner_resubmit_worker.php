<?php

declare(strict_types=1);

/**
 * Concurrent learner correction resubmit worker.
 * Args: applicantUserId authVersion applicationId stateVersion
 * Prints: resubmitted:<application_id> | conflict | domain:<message> | error:<class>
 */

use Academy\Application\Review\LearnerCorrectionResubmitService;
use Academy\Domain\Exception\ConflictException;
use Academy\Domain\Exception\DomainRuleException;
use Academy\Domain\Identity\AccountStatus;
use Academy\Domain\Identity\AuthStage;
use Academy\Domain\Security\AuthContext;
use Academy\Tests\Support\ApplicationFactory;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

$userId = (int) ($argv[1] ?? 0);
$authVersion = (int) ($argv[2] ?? 0);
$applicationId = (int) ($argv[3] ?? 0);
$stateVersion = (int) ($argv[4] ?? 0);

if ($userId <= 0 || $applicationId <= 0 || $stateVersion <= 0) {
    fwrite(STDERR, "usage: learner_resubmit_worker.php <userId> <authVersion> <applicationId> <stateVersion>\n");
    exit(1);
}

putenv('APP_ENV=testing');
$_ENV['APP_ENV'] = 'testing';
$_SERVER['APP_ENV'] = 'testing';

try {
    $container = ApplicationFactory::container('testing');
    /** @var LearnerCorrectionResubmitService $service */
    $service = $container->get(LearnerCorrectionResubmitService::class);

    $auth = AuthContext::authenticated(
        userId: $userId,
        sessionId: 1,
        authStage: AuthStage::FULLY_AUTHENTICATED,
        authVersion: $authVersion,
        hasPrivilegedRole: false,
        accountStatus: AccountStatus::ACTIVE,
    );

    $application = $service->resubmit($auth, $applicationId, $stateVersion);
    echo 'resubmitted:' . $application->applicationId;
    exit(0);
} catch (ConflictException) {
    echo 'conflict';
    exit(0);
} catch (DomainRuleException $exception) {
    echo 'domain:' . $exception->getMessage();
    exit(0);
} catch (Throwable $exception) {
    echo 'error:' . $exception::class . ':' . $exception->getMessage();
    exit(1);
}
