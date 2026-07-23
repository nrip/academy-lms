<?php

declare(strict_types=1);

/**
 * Concurrent reviewer document verify worker.
 * Args: reviewerUserId authVersion applicationId submissionId
 * Prints: verified:<submission_id> | conflict | domain:<message> | error:<class>
 */

use Academy\Application\Review\DocumentReviewService;
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
$submissionId = (int) ($argv[4] ?? 0);

if ($reviewerUserId <= 0 || $applicationId <= 0 || $submissionId <= 0) {
    fwrite(STDERR, "usage: reviewer_verify_worker.php <reviewerUserId> <authVersion> <applicationId> <submissionId>\n");
    exit(1);
}

putenv('APP_ENV=testing');
$_ENV['APP_ENV'] = 'testing';
$_SERVER['APP_ENV'] = 'testing';

try {
    $container = ApplicationFactory::container('testing');
    /** @var DocumentReviewService $service */
    $service = $container->get(DocumentReviewService::class);

    $auth = AuthContext::authenticated(
        userId: $reviewerUserId,
        sessionId: 1,
        authStage: AuthStage::FULLY_AUTHENTICATED,
        authVersion: $authVersion,
        hasPrivilegedRole: true,
        accountStatus: AccountStatus::ACTIVE,
    );

    $submission = $service->verify($auth, $applicationId, $submissionId);
    echo 'verified:' . $submission->documentSubmissionId;
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
