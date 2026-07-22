<?php

declare(strict_types=1);

namespace Academy\Domain\Identity;

/**
 * Deterministic profile-completeness scoring from the WP-01B-2d allow-list matrix.
 */
final class ProfileCompletenessCalculator
{
    /** @var array<string, list<string>> */
    private const SECTIONS = [
        'core_personal' => [
            'first_name',
            'last_name',
            'preferred_display_name',
            'date_of_birth',
            'certificate_name',
            'certificate_name_confirmed',
        ],
        'contact_address' => [
            'address_line_1',
            'city',
            'state',
            'postal_code',
            'country',
        ],
        'professional' => [
            'profession',
            'current_designation',
            'organization_name',
            'years_of_experience',
            'speciality',
        ],
        'medical_registration' => [
            'medical_council_name',
            'medical_council_registration_number',
            'medical_council_registration_state',
            'registration_valid_from',
            'registration_valid_until',
        ],
        'qualifications' => [
            'qualifications',
        ],
    ];

    /**
     * @param list<LearnerQualification> $qualifications
     */
    public function calculate(LearnerProfile $profile, array $qualifications): ProfileCompleteness
    {
        $hasCompleteQualification = $this->hasCompleteQualification($qualifications);

        $totalRequired = 0;
        $completedRequired = 0;
        $completedSections = [];
        $incompleteSections = [];
        $missingRequiredFieldKeys = [];

        foreach (self::SECTIONS as $section => $fieldKeys) {
            $sectionComplete = true;
            foreach ($fieldKeys as $fieldKey) {
                $totalRequired++;
                if ($this->isSatisfied($profile, $fieldKey, $hasCompleteQualification)) {
                    $completedRequired++;
                } else {
                    $sectionComplete = false;
                    $missingRequiredFieldKeys[] = $fieldKey;
                }
            }

            if ($sectionComplete) {
                $completedSections[] = $section;
            } else {
                $incompleteSections[] = $section;
            }
        }

        $percentage = (int) floor(100 * $completedRequired / $totalRequired);

        return new ProfileCompleteness(
            $percentage,
            $completedSections,
            $incompleteSections,
            $missingRequiredFieldKeys,
        );
    }

    private function isSatisfied(LearnerProfile $profile, string $fieldKey, bool $hasCompleteQualification): bool
    {
        return match ($fieldKey) {
            'certificate_name_confirmed' => $profile->certificateNameConfirmed === true,
            'years_of_experience' => $profile->yearsOfExperience !== null,
            'qualifications' => $hasCompleteQualification,
            default => $this->nonEmpty($this->stringValue($profile, $fieldKey)),
        };
    }

    private function stringValue(LearnerProfile $profile, string $fieldKey): ?string
    {
        return match ($fieldKey) {
            'first_name' => $profile->firstName,
            'last_name' => $profile->lastName,
            'preferred_display_name' => $profile->preferredDisplayName,
            'date_of_birth' => $profile->dateOfBirth,
            'certificate_name' => $profile->certificateName,
            'address_line_1' => $profile->addressLine1,
            'city' => $profile->city,
            'state' => $profile->state,
            'postal_code' => $profile->postalCode,
            'country' => $profile->country,
            'profession' => $profile->profession,
            'current_designation' => $profile->currentDesignation,
            'organization_name' => $profile->organizationName,
            'speciality' => $profile->speciality,
            'medical_council_name' => $profile->medicalCouncilName,
            'medical_council_registration_number' => $profile->medicalCouncilRegistrationNumber,
            'medical_council_registration_state' => $profile->medicalCouncilRegistrationState,
            'registration_valid_from' => $profile->registrationValidFrom,
            'registration_valid_until' => $profile->registrationValidUntil,
            default => null,
        };
    }

    /**
     * @param list<LearnerQualification> $qualifications
     */
    private function hasCompleteQualification(array $qualifications): bool
    {
        foreach ($qualifications as $qualification) {
            if ($this->nonEmpty($qualification->qualificationType)
                && $this->nonEmpty($qualification->qualificationName)
                && $this->nonEmpty($qualification->institutionName)
                && $qualification->completionYear > 0
            ) {
                return true;
            }
        }

        return false;
    }

    private function nonEmpty(?string $value): bool
    {
        return $value !== null && trim($value) !== '';
    }
}
