<?php

declare(strict_types=1);

/**
 * Concurrent reviewer-claim worker.
 * Args: reviewerUserId authVersion applicationId
 * Prints: claimed:<assignment_id> | conflict | error:<class>
 */

use Academy\Application\Review\ReviewerClaimService;
use Academy\Domain\Exception\ConflictException;
use Academy\Domain\Identity\AccountStatus;
use Academy\Domain\Identity\AuthStage;
use Academy\Domain\Security\AuthContext;
use Academy\Tests\Support\ApplicationFactory;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

$reviewerUserId = (int) ($argv[1] ?? 0);
$authVersion = (int) ($argv[2] ?? 0);
$applicationId = (int) ($argv[3] ?? 0);

if ($reviewerUserId <= 0 || $applicationId <= 0) {
    fwrite(STDERR, "usage: reviewer_claim_worker.php <reviewerUserId> <authVersion> <applicationId>\n");
    exit(1);
}

putenv('APP_ENV=testing');
$_ENV['APP_ENV'] = 'testing';
$_SERVER['APP_ENV'] = 'testing';

try {
    $container = ApplicationFactory::container('testing');
    /** @var ReviewerClaimService $service */
    $service = $container->get(ReviewerClaimService::class);

    $auth = AuthContext::authenticated(
        userId: $reviewerUserId,
        sessionId: 1,
        authStage: AuthStage::FULLY_AUTHENTICATED,
        authVersion: $authVersion,
        hasPrivilegedRole: true,
        accountStatus: AccountStatus::ACTIVE,
    );

    $assignment = $service->claim($auth, $applicationId);
    echo 'claimed:' . $assignment->assignmentId;
    exit(0);
} catch (ConflictException) {
    echo 'conflict';
    exit(0);
} catch (Throwable $exception) {
    echo 'error:' . $exception::class . ':' . $exception->getMessage();
    exit(1);
}
