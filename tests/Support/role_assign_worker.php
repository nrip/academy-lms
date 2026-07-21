<?php

declare(strict_types=1);

/**
 * Multi-process concurrent role assignment worker.
 * Prints: ok|conflict|error:<class>
 */

use Academy\Application\Audit\AuditRedactor;
use Academy\Application\Audit\AuditService;
use Academy\Application\RBAC\RoleAssignmentService;
use Academy\Application\Security\CsrfTokenManager;
use Academy\Application\Security\SessionService;
use Academy\Domain\Exception\ConflictException;
use Academy\Infrastructure\Audit\PdoAuditWriter;
use Academy\Infrastructure\Database\TransactionManager;
use Academy\Infrastructure\RBAC\PdoRoleRepository;
use Academy\Infrastructure\Session\PdoSessionRepository;
use Academy\Tests\Support\DatabaseTestCase;
use Psr\Log\NullLogger;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

$userId = (int) ($argv[1] ?? 0);
$roleKey = (string) ($argv[2] ?? '');
if ($userId < 1 || $roleKey === '') {
    fwrite(STDERR, "usage: role_assign_worker.php <user_id> <role_key>\n");
    exit(1);
}

$factory = DatabaseTestCase::connectionFactory();
$sessions = new SessionService(
    new PdoSessionRepository($factory),
    new CsrfTokenManager(),
    new NullLogger(),
    ['idle_seconds' => 1800, 'absolute_seconds' => 43200],
    ['idle_seconds' => 900, 'absolute_seconds' => 28800],
    300,
);
$service = new RoleAssignmentService(
    new TransactionManager($factory),
    new PdoRoleRepository($factory),
    new AuditService(new PdoAuditWriter($factory), new AuditRedactor()),
    $sessions,
);

try {
    $service->assign($userId, $roleKey, null, 'concurrency-worker');
    echo 'ok';
    exit(0);
} catch (ConflictException) {
    echo 'conflict';
    exit(0);
} catch (Throwable $exception) {
    echo 'error:' . $exception::class;
    exit(1);
}
