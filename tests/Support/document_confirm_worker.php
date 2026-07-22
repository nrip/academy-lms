<?php

declare(strict_types=1);

/**
 * Concurrent document-confirm worker.
 * Args: userId authVersion applicationId requirementId objectKey checksumSha256
 * Prints: confirmed:<document_submission_id> | conflict | error:<class>
 */

use Academy\Application\Credentials\DocumentUploadService;
use Academy\Domain\Exception\ConflictException;
use Academy\Domain\Identity\AccountStatus;
use Academy\Domain\Identity\AuthStage;
use Academy\Domain\Security\AuthContext;
use Academy\Tests\Support\ApplicationFactory;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

$userId = (int) ($argv[1] ?? 0);
$authVersion = (int) ($argv[2] ?? 0);
$applicationId = (int) ($argv[3] ?? 0);
$requirementId = (int) ($argv[4] ?? 0);
$objectKey = (string) ($argv[5] ?? '');
$checksumSha256 = (string) ($argv[6] ?? '');

if ($userId <= 0 || $applicationId <= 0 || $requirementId <= 0 || $objectKey === '' || $checksumSha256 === '') {
    fwrite(STDERR, "usage: document_confirm_worker.php <userId> <authVersion> <applicationId> <requirementId> <objectKey> <checksumSha256>\n");
    exit(1);
}

putenv('APP_ENV=testing');
$_ENV['APP_ENV'] = 'testing';
$_SERVER['APP_ENV'] = 'testing';

try {
    $container = ApplicationFactory::container('testing');
    /** @var DocumentUploadService $service */
    $service = $container->get(DocumentUploadService::class);

    $auth = AuthContext::authenticated(
        userId: $userId,
        sessionId: 1,
        authStage: AuthStage::FULLY_AUTHENTICATED,
        authVersion: $authVersion,
        hasPrivilegedRole: false,
        accountStatus: AccountStatus::ACTIVE,
    );

    $submission = $service->confirmUpload(
        $auth,
        $applicationId,
        $requirementId,
        $objectKey,
        $checksumSha256,
    );
    echo 'confirmed:' . $submission->documentSubmissionId;
    exit(0);
} catch (ConflictException) {
    echo 'conflict';
    exit(0);
} catch (Throwable $exception) {
    echo 'error:' . $exception::class . ':' . $exception->getMessage();
    exit(1);
}
