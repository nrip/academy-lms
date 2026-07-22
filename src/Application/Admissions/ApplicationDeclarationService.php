<?php

declare(strict_types=1);

namespace Academy\Application\Admissions;

use Academy\Application\Audit\AuditService;
use Academy\Application\RBAC\AuthorizationService;
use Academy\Domain\Admissions\Application;
use Academy\Domain\Admissions\ApplicationRepository;
use Academy\Domain\Audit\AdmissionsAuditPayload;
use Academy\Domain\Exception\AuthenticationException;
use Academy\Domain\Exception\ConflictException;
use Academy\Domain\Exception\DomainRuleException;
use Academy\Domain\Exception\NotFoundException;
use Academy\Domain\Security\AuthContext;
use Academy\Infrastructure\Database\TransactionManager;
use DateTimeImmutable;
use DateTimeZone;

final class ApplicationDeclarationService
{
    public function __construct(
        private readonly TransactionManager $transactions,
        private readonly AuthorizationService $authorization,
        private readonly ApplicationRepository $applications,
        private readonly AuditService $audit,
        private readonly string $declarationVersion,
    ) {
    }

    public function acceptOnDraft(AuthContext $auth, int $applicationId): Application
    {
        $this->authorization->require($auth, 'application.edit_own');
        $userId = $this->requireUserId($auth);

        return $this->transactions->run(function () use ($userId, $applicationId): Application {
            $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
            $application = $this->applications->findByIdForUpdate($applicationId);
            if ($application === null || $application->userId !== $userId) {
                throw new NotFoundException('Application not found.');
            }
            if (!$application->isEditableByLearner()) {
                throw new DomainRuleException('Submitted applications cannot be edited.');
            }

            $expectedStateVersion = $application->stateVersion;

            if (!$this->applications->updateDeclaration(
                $applicationId,
                $this->declarationVersion,
                $now,
                $expectedStateVersion,
                $now,
            )) {
                throw new ConflictException('Application was updated concurrently. Refresh and try again.');
            }

            $this->audit->record(
                new AdmissionsAuditPayload(
                    action: 'application.updated',
                    entityType: 'application',
                    entityId: (string) $applicationId,
                    previous: [
                        'declaration_version' => $application->declarationAcceptedVersion,
                        'state_version' => $application->stateVersion,
                    ],
                    next: [
                        'user_id' => $userId,
                        'application_id' => $applicationId,
                        'declaration_version' => $this->declarationVersion,
                        'state_version' => $expectedStateVersion + 1,
                        'result' => 'ok',
                    ],
                ),
                actorType: 'user',
                actorUserId: $userId,
                source: 'admissions',
            );

            $updated = $this->applications->findById($applicationId);
            if ($updated === null) {
                throw new NotFoundException('Application not found.');
            }

            return $updated;
        });
    }

    private function requireUserId(AuthContext $auth): int
    {
        if ($auth->userId === null) {
            throw new AuthenticationException('Authentication required.');
        }

        return $auth->userId;
    }
}
