<?php

declare(strict_types=1);

/**
 * Concurrent reviewer decision worker.
 * Args: reviewerUserId authVersion applicationId stateVersion action(approve|reject)
 * Prints: decided:<status> | conflict | domain:<message> | error:<class>
 */

use Academy\Application\Review\ApplicationDecisionService;
use Academy\Domain\Credentials\DocumentRejectionReasonCode;
use Academy\Domain\Exception\ConflictException;
use Academy\Domain\Exception\DomainRuleException;
use Academy\Domain\Identity\AccountStatus;
use Academy\Domain\Identity\AuthStage;
use Academy\Domain\Security\AuthContext;
use Academy\Tests\Support\ApplicationFactory;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

$reviewerUserId = (int) ($argv[1] ?? 0);
$authVersion = (int) ($argv[2] ?? 0);
$applicationId = (int) ($argv[3] ?? 0);
$stateVersion = (int) ($argv[4] ?? 0);
$action = (string) ($argv[5] ?? '');

if ($reviewerUserId <= 0 || $applicationId <= 0 || $stateVersion <= 0 || ($action !== 'approve' && $action !== 'reject')) {
    fwrite(STDERR, "usage: reviewer_decide_worker.php <reviewerUserId> <authVersion> <applicationId> <stateVersion> <approve|reject>\n");
    exit(1);
}

putenv('APP_ENV=testing');
$_ENV['APP_ENV'] = 'testing';
$_SERVER['APP_ENV'] = 'testing';

try {
    $container = ApplicationFactory::container('testing');
    /** @var ApplicationDecisionService $service */
    $service = $container->get(ApplicationDecisionService::class);

    $auth = AuthContext::authenticated(
        userId: $reviewerUserId,
        sessionId: 1,
        authStage: AuthStage::FULLY_AUTHENTICATED,
        authVersion: $authVersion,
        hasPrivilegedRole: true,
        accountStatus: AccountStatus::ACTIVE,
    );

    if ($action === 'approve') {
        $application = $service->approve($auth, $applicationId, $stateVersion);
    } else {
        $application = $service->reject(
            $auth,
            $applicationId,
            DocumentRejectionReasonCode::OTHER,
            null,
            null,
            $stateVersion,
        );
    }

    echo 'decided:' . $application->status;
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
