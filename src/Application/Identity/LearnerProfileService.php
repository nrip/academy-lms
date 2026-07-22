<?php

declare(strict_types=1);

namespace Academy\Application\Identity;

use Academy\Application\Audit\AuditService;
use Academy\Application\RBAC\AuthorizationService;
use Academy\Domain\Audit\IdentityProfileAuditPayload;
use Academy\Domain\Exception\NotFoundException;
use Academy\Domain\Identity\LearnerProfile;
use Academy\Domain\Identity\LearnerProfileRepository;
use Academy\Domain\Identity\LearnerQualificationRepository;
use Academy\Domain\Identity\PersonalProfileValidator;
use Academy\Domain\Identity\ProfessionalProfileValidator;
use Academy\Domain\Identity\ProfileCompleteness;
use Academy\Domain\Identity\ProfileCompletenessCalculator;
use Academy\Domain\Security\AuthContext;
use Academy\Infrastructure\Database\TransactionManager;
use DateTimeImmutable;
use DateTimeZone;
use PDO;

final class LearnerProfileService
{
    public function __construct(
        private readonly TransactionManager $transactions,
        private readonly LearnerProfileRepository $profiles,
        private readonly LearnerQualificationRepository $qualifications,
        private readonly AuthorizationService $authorization,
        private readonly AuditService $audit,
        private readonly PersonalProfileValidator $personalValidator,
        private readonly ProfessionalProfileValidator $professionalValidator,
        private readonly ProfileCompletenessCalculator $completeness,
    ) {
    }

    /**
     * @return array{profile: LearnerProfile, completeness: ProfileCompleteness, qualifications_count: int}
     */
    public function overview(AuthContext $auth): array
    {
        $this->authorization->require($auth, 'profile.personal.view_own');

        $profile = $this->requireOwnProfile($auth);
        $qualifications = $this->qualifications->listByProfileId($profile->learnerProfileId);

        return [
            'profile' => $profile,
            'completeness' => $this->completeness->calculate($profile, $qualifications),
            'qualifications_count' => count($qualifications),
        ];
    }

    public function getPersonal(AuthContext $auth): LearnerProfile
    {
        $this->authorization->require($auth, 'profile.personal.view_own');

        return $this->requireOwnProfile($auth);
    }

    public function getProfessional(AuthContext $auth): LearnerProfile
    {
        $this->authorization->require($auth, 'profile.professional.view_own');

        return $this->requireOwnProfile($auth);
    }

    /**
     * @param array<string, mixed> $input
     */
    public function updatePersonal(AuthContext $auth, int $expectedVersion, array $input): LearnerProfile
    {
        $this->authorization->require($auth, 'profile.personal.edit_own');
        $normalized = $this->personalValidator->validate($input);

        return $this->applyUpdate(
            $auth,
            $expectedVersion,
            $normalized,
            'profile.personal_updated',
            fn (int $profileId, int $version, array $fields, DateTimeImmutable $now): int
                => $this->profiles->updatePersonal($profileId, $version, $fields, $now),
            fn (LearnerProfile $profile, string $column): string|bool|null
                => $this->currentPersonalValue($profile, $column),
        );
    }

    /**
     * @param array<string, mixed> $input
     */
    public function updateProfessional(AuthContext $auth, int $expectedVersion, array $input): LearnerProfile
    {
        $this->authorization->require($auth, 'profile.professional.edit_own');
        $normalized = $this->professionalValidator->validate($input);

        return $this->applyUpdate(
            $auth,
            $expectedVersion,
            $normalized,
            'profile.professional_updated',
            fn (int $profileId, int $version, array $fields, DateTimeImmutable $now): int
                => $this->profiles->updateProfessional($profileId, $version, $fields, $now),
            fn (LearnerProfile $profile, string $column): int|string|null
                => $this->currentProfessionalValue($profile, $column),
        );
    }

    /**
     * @param array<string, scalar|null> $normalized
     * @param callable(int, int, array<string, scalar|null>, DateTimeImmutable): int $persist
     * @param callable(LearnerProfile, string): (int|string|bool|null) $currentValue
     */
    private function applyUpdate(
        AuthContext $auth,
        int $expectedVersion,
        array $normalized,
        string $action,
        callable $persist,
        callable $currentValue,
    ): LearnerProfile {
        return $this->transactions->run(function (PDO $pdo) use (
            $auth,
            $expectedVersion,
            $normalized,
            $action,
            $persist,
            $currentValue,
        ): LearnerProfile {
            $profile = $this->requireOwnProfile($auth);
            $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));

            $changedKeys = [];
            foreach ($normalized as $column => $value) {
                if ($value !== $currentValue($profile, $column)) {
                    $changedKeys[] = $column;
                }
            }

            $newVersion = $persist($profile->learnerProfileId, $expectedVersion, $normalized, $now);

            $this->audit->record(
                new IdentityProfileAuditPayload(
                    action: $action,
                    entityType: 'learner_profile',
                    entityId: (string) $profile->learnerProfileId,
                    next: [
                        'user_id' => $auth->userId,
                        'learner_profile_id' => $profile->learnerProfileId,
                        'changed_field_keys' => implode(',', $changedKeys),
                        'row_version_before' => $expectedVersion,
                        'row_version_after' => $newVersion,
                        'result' => 'ok',
                    ],
                ),
                actorType: 'user',
                actorUserId: $auth->userId,
                source: 'profile',
            );

            $updated = $this->profiles->findById($profile->learnerProfileId);
            if ($updated === null) {
                throw new NotFoundException('Profile not found.');
            }

            return $updated;
        });
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

    private function currentPersonalValue(LearnerProfile $profile, string $column): string|bool|null
    {
        return match ($column) {
            'first_name' => $profile->firstName,
            'middle_name' => $profile->middleName,
            'last_name' => $profile->lastName,
            'preferred_display_name' => $profile->preferredDisplayName,
            'certificate_name' => $profile->certificateName,
            'certificate_name_confirmed' => $profile->certificateNameConfirmed,
            'date_of_birth' => $profile->dateOfBirth,
            'gender' => $profile->gender,
            'nationality' => $profile->nationality,
            'address_line_1' => $profile->addressLine1,
            'address_line_2' => $profile->addressLine2,
            'city' => $profile->city,
            'state' => $profile->state,
            'postal_code' => $profile->postalCode,
            'country' => $profile->country,
            'alternate_mobile' => $profile->alternateMobile,
            default => null,
        };
    }

    private function currentProfessionalValue(LearnerProfile $profile, string $column): int|string|null
    {
        return match ($column) {
            'profession' => $profile->profession,
            'speciality' => $profile->speciality,
            'current_designation' => $profile->currentDesignation,
            'organization_name' => $profile->organizationName,
            'years_of_experience' => $profile->yearsOfExperience,
            'medical_council_name' => $profile->medicalCouncilName,
            'medical_council_registration_number' => $profile->medicalCouncilRegistrationNumber,
            'medical_council_registration_state' => $profile->medicalCouncilRegistrationState,
            'registration_valid_from' => $profile->registrationValidFrom,
            'registration_valid_until' => $profile->registrationValidUntil,
            default => null,
        };
    }
}
