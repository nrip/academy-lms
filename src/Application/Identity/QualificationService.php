<?php

declare(strict_types=1);

namespace Academy\Application\Identity;

use Academy\Application\Audit\AuditService;
use Academy\Application\RBAC\AuthorizationService;
use Academy\Domain\Audit\IdentityProfileAuditPayload;
use Academy\Domain\Exception\AuthorizationException;
use Academy\Domain\Exception\DomainRuleException;
use Academy\Domain\Exception\NotFoundException;
use Academy\Domain\Identity\LearnerProfile;
use Academy\Domain\Identity\LearnerProfileRepository;
use Academy\Domain\Identity\LearnerQualification;
use Academy\Domain\Identity\LearnerQualificationRepository;
use Academy\Domain\Identity\QualificationValidator;
use Academy\Domain\Security\AuthContext;
use Academy\Infrastructure\Database\TransactionManager;
use DateTimeImmutable;
use DateTimeZone;
use PDO;

final class QualificationService
{
    public const MAX_QUALIFICATIONS = 20;

    public function __construct(
        private readonly TransactionManager $transactions,
        private readonly LearnerProfileRepository $profiles,
        private readonly LearnerQualificationRepository $qualifications,
        private readonly AuthorizationService $authorization,
        private readonly AuditService $audit,
        private readonly QualificationValidator $validator,
    ) {
    }

    /**
     * @return array{profile: LearnerProfile, qualifications: list<LearnerQualification>}
     */
    public function list(AuthContext $auth): array
    {
        $this->authorization->require($auth, 'profile.professional.view_own');
        $profile = $this->requireOwnProfile($auth);

        return [
            'profile' => $profile,
            'qualifications' => $this->qualifications->listByProfileId($profile->learnerProfileId),
        ];
    }

    /**
     * @param array<string, mixed> $input
     */
    public function add(AuthContext $auth, array $input): LearnerQualification
    {
        $this->authorization->require($auth, 'profile.professional.edit_own');
        $normalized = $this->validator->validate($input);

        return $this->transactions->run(function (PDO $pdo) use ($auth, $normalized): LearnerQualification {
            $profile = $this->requireOwnProfile($auth);

            if ($this->qualifications->countByProfileId($profile->learnerProfileId) >= self::MAX_QUALIFICATIONS) {
                throw new DomainRuleException(sprintf('You can add at most %d qualifications.', self::MAX_QUALIFICATIONS));
            }

            $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
            $displayOrder = $this->qualifications->nextDisplayOrder($profile->learnerProfileId);
            $qualificationId = $this->qualifications->insert($profile->learnerProfileId, $normalized, $displayOrder, $now);

            $this->recordAudit(
                'profile.qualification_added',
                $qualificationId,
                $profile->learnerProfileId,
                $auth->userId,
                is_string($normalized['qualification_type'] ?? null) ? $normalized['qualification_type'] : null,
            );

            $created = $this->qualifications->findById($qualificationId);
            if ($created === null) {
                throw new NotFoundException('Qualification not found.');
            }

            return $created;
        });
    }

    /**
     * @param array<string, mixed> $input
     */
    public function update(AuthContext $auth, int $qualificationId, int $expectedVersion, array $input): LearnerQualification
    {
        $this->authorization->require($auth, 'profile.professional.edit_own');
        $normalized = $this->validator->validate($input);

        return $this->transactions->run(function (PDO $pdo) use (
            $auth,
            $qualificationId,
            $expectedVersion,
            $normalized,
        ): LearnerQualification {
            $profile = $this->requireOwnProfile($auth);
            $qualification = $this->requireOwnedQualification($qualificationId, $profile);

            $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
            $newVersion = $this->qualifications->updateWithVersion($qualification->learnerQualificationId, $expectedVersion, $normalized, $now);

            $this->recordAudit(
                'profile.qualification_updated',
                $qualification->learnerQualificationId,
                $profile->learnerProfileId,
                $auth->userId,
                is_string($normalized['qualification_type'] ?? null) ? $normalized['qualification_type'] : null,
                $expectedVersion,
                $newVersion,
            );

            $updated = $this->qualifications->findById($qualification->learnerQualificationId);
            if ($updated === null) {
                throw new NotFoundException('Qualification not found.');
            }

            return $updated;
        });
    }

    public function delete(AuthContext $auth, int $qualificationId, int $expectedVersion): void
    {
        $this->authorization->require($auth, 'profile.professional.edit_own');

        $this->transactions->run(function (PDO $pdo) use ($auth, $qualificationId, $expectedVersion): void {
            $profile = $this->requireOwnProfile($auth);
            $qualification = $this->requireOwnedQualification($qualificationId, $profile);

            $this->qualifications->deleteWithVersion($qualification->learnerQualificationId, $expectedVersion);

            $this->recordAudit(
                'profile.qualification_removed',
                $qualification->learnerQualificationId,
                $profile->learnerProfileId,
                $auth->userId,
                $qualification->qualificationType,
                $expectedVersion,
            );
        });
    }

    private function requireOwnedQualification(int $qualificationId, LearnerProfile $profile): LearnerQualification
    {
        $qualification = $this->qualifications->findById($qualificationId);
        if ($qualification === null) {
            throw new NotFoundException('Qualification not found.');
        }
        if ($qualification->learnerProfileId !== $profile->learnerProfileId) {
            throw new AuthorizationException('Permission denied.');
        }

        return $qualification;
    }

    private function requireOwnProfile(AuthContext $auth): LearnerProfile
    {
        if ($auth->userId === null) {
            throw new NotFoundException('Profile not found.');
        }

        $profile = $this->profiles->findByUserId($auth->userId);
        if ($profile === null) {
            throw new NotFoundException('Profile not found.');
        }

        return $profile;
    }

    private function recordAudit(
        string $action,
        int $qualificationId,
        int $profileId,
        ?int $actorUserId,
        ?string $qualificationType,
        ?int $rowVersionBefore = null,
        ?int $rowVersionAfter = null,
    ): void {
        $next = [
            'user_id' => $actorUserId,
            'learner_profile_id' => $profileId,
            'learner_qualification_id' => $qualificationId,
            'result' => 'ok',
        ];
        if ($qualificationType !== null) {
            $next['qualification_type'] = $qualificationType;
        }
        if ($rowVersionBefore !== null) {
            $next['row_version_before'] = $rowVersionBefore;
        }
        if ($rowVersionAfter !== null) {
            $next['row_version_after'] = $rowVersionAfter;
        }

        $this->audit->record(
            new IdentityProfileAuditPayload(
                action: $action,
                entityType: 'learner_qualification',
                entityId: (string) $qualificationId,
                next: $next,
            ),
            actorType: 'user',
            actorUserId: $actorUserId,
            source: 'profile',
        );
    }
}
