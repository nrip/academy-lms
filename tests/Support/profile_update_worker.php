<?php

declare(strict_types=1);

/**
 * Multi-process optimistic-lock worker for learner profile personal updates.
 * Prints: ok|conflict|error:<class>
 */

use Academy\Application\Audit\AuditRedactor;
use Academy\Application\Audit\AuditService;
use Academy\Application\Identity\LearnerProfileService;
use Academy\Application\RBAC\AuthorizationService;
use Academy\Domain\Exception\ConflictException;
use Academy\Domain\Identity\AccountStatus;
use Academy\Domain\Identity\AuthStage;
use Academy\Domain\Identity\PersonalProfileValidator;
use Academy\Domain\Identity\ProfessionalProfileValidator;
use Academy\Domain\Identity\ProfileCompletenessCalculator;
use Academy\Domain\Security\AuthContext;
use Academy\Infrastructure\Audit\PdoAuditWriter;
use Academy\Infrastructure\Database\TransactionManager;
use Academy\Infrastructure\Identity\PdoLearnerProfileRepository;
use Academy\Infrastructure\Identity\PdoLearnerQualificationRepository;
use Academy\Infrastructure\RBAC\PdoPermissionRepository;
use Academy\Tests\Support\DatabaseTestCase;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

$userId = (int) ($argv[1] ?? 0);
$expectedVersion = (int) ($argv[2] ?? 0);
$firstName = (string) ($argv[3] ?? 'Worker');
if ($userId < 1 || $expectedVersion < 1) {
    fwrite(STDERR, "usage: profile_update_worker.php <user_id> <expected_version> <first_name>\n");
    exit(1);
}

$factory = DatabaseTestCase::connectionFactory();

$authVersionStmt = $factory->connection()->prepare('SELECT auth_version FROM users WHERE user_id = :id');
$authVersionStmt->execute(['id' => $userId]);
$authVersion = (int) $authVersionStmt->fetchColumn();

$service = new LearnerProfileService(
    new TransactionManager($factory),
    new PdoLearnerProfileRepository($factory),
    new PdoLearnerQualificationRepository($factory),
    new AuthorizationService(new PdoPermissionRepository($factory)),
    new AuditService(new PdoAuditWriter($factory), new AuditRedactor()),
    new PersonalProfileValidator(),
    new ProfessionalProfileValidator(),
    new ProfileCompletenessCalculator(),
);

$auth = AuthContext::authenticated(
    userId: $userId,
    sessionId: 1,
    authStage: AuthStage::FULLY_AUTHENTICATED,
    authVersion: $authVersion,
    hasPrivilegedRole: false,
    accountStatus: AccountStatus::ACTIVE,
);

try {
    $service->updatePersonal($auth, $expectedVersion, ['first_name' => $firstName]);
    echo 'ok';
    exit(0);
} catch (ConflictException) {
    echo 'conflict';
    exit(0);
} catch (Throwable $exception) {
    echo 'error:' . $exception::class;
    exit(1);
}
