<?php

declare(strict_types=1);

namespace Academy\Tests\Unit\Domain\Identity;

use Academy\Domain\Identity\LearnerProfile;
use Academy\Domain\Identity\LearnerQualification;
use Academy\Domain\Identity\ProfileCompletenessCalculator;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;

final class ProfileCompletenessCalculatorTest extends TestCase
{
    private ProfileCompletenessCalculator $calculator;

    protected function setUp(): void
    {
        $this->calculator = new ProfileCompletenessCalculator();
    }

    public function testEmptyProfileIsZeroPercent(): void
    {
        $result = $this->calculator->calculate($this->profile(), []);

        self::assertSame(0, $result->percentage);
        self::assertSame([], $result->completedSections);
        self::assertCount(5, $result->incompleteSections);
        self::assertCount(22, $result->missingRequiredFieldKeys);
    }

    public function testFullyCompleteProfileIsHundredPercent(): void
    {
        $result = $this->calculator->calculate($this->completeProfile(), [$this->qualification()]);

        self::assertSame(100, $result->percentage);
        self::assertSame([], $result->incompleteSections);
        self::assertSame([], $result->missingRequiredFieldKeys);
        self::assertContains('qualifications', $result->completedSections);
    }

    public function testCertificateNameConfirmationRequiredTrue(): void
    {
        $profile = $this->completeProfile(certificateNameConfirmed: false);
        $result = $this->calculator->calculate($profile, [$this->qualification()]);

        self::assertContains('certificate_name_confirmed', $result->missingRequiredFieldKeys);
        self::assertContains('core_personal', $result->incompleteSections);
        self::assertNotContains('core_personal', $result->completedSections);
    }

    public function testPercentageUsesFloorOfCompletedOverTotal(): void
    {
        // Fill core_personal (6) + contact_address (5) = 11 of 22 required keys.
        $profile = $this->profile(
            firstName: 'Asha',
            lastName: 'Rao',
            preferredDisplayName: 'Dr Rao',
            certificateName: 'Dr Asha Rao',
            certificateNameConfirmed: true,
            dateOfBirth: '1990-01-01',
            addressLine1: '1 Road',
            city: 'Bengaluru',
            state: 'Karnataka',
            postalCode: '560001',
            country: 'India',
        );

        $result = $this->calculator->calculate($profile, []);

        self::assertSame(50, $result->percentage);
        self::assertContains('core_personal', $result->completedSections);
        self::assertContains('contact_address', $result->completedSections);
    }

    public function testQualificationsSectionNeedsAtLeastOneCompleteRow(): void
    {
        $result = $this->calculator->calculate($this->completeProfile(), []);

        self::assertContains('qualifications', $result->missingRequiredFieldKeys);
        self::assertContains('qualifications', $result->incompleteSections);
    }

    public function testExperienceZeroCountsAsProvided(): void
    {
        $profile = $this->completeProfile(yearsOfExperience: 0);
        $result = $this->calculator->calculate($profile, [$this->qualification()]);

        self::assertNotContains('years_of_experience', $result->missingRequiredFieldKeys);
        self::assertContains('professional', $result->completedSections);
    }

    private function completeProfile(bool $certificateNameConfirmed = true, ?int $yearsOfExperience = 10): LearnerProfile
    {
        return $this->profile(
            firstName: 'Asha',
            lastName: 'Rao',
            preferredDisplayName: 'Dr Rao',
            certificateName: 'Dr Asha Rao',
            certificateNameConfirmed: $certificateNameConfirmed,
            dateOfBirth: '1990-01-01',
            addressLine1: '1 Road',
            city: 'Bengaluru',
            state: 'Karnataka',
            postalCode: '560001',
            country: 'India',
            profession: 'Physician',
            currentDesignation: 'Consultant',
            organizationName: 'City Hospital',
            yearsOfExperience: $yearsOfExperience,
            speciality: 'Endocrinology',
            medicalCouncilName: 'NMC',
            medicalCouncilRegistrationNumber: 'REG123',
            medicalCouncilRegistrationState: 'Karnataka',
            registrationValidFrom: '2020-01-01',
            registrationValidUntil: '2030-01-01',
        );
    }

    private function profile(
        ?string $firstName = null,
        ?string $lastName = null,
        ?string $preferredDisplayName = null,
        ?string $certificateName = null,
        bool $certificateNameConfirmed = false,
        ?string $dateOfBirth = null,
        ?string $addressLine1 = null,
        ?string $city = null,
        ?string $state = null,
        ?string $postalCode = null,
        ?string $country = null,
        ?string $profession = null,
        ?string $currentDesignation = null,
        ?string $organizationName = null,
        ?int $yearsOfExperience = null,
        ?string $speciality = null,
        ?string $medicalCouncilName = null,
        ?string $medicalCouncilRegistrationNumber = null,
        ?string $medicalCouncilRegistrationState = null,
        ?string $registrationValidFrom = null,
        ?string $registrationValidUntil = null,
    ): LearnerProfile {
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));

        return new LearnerProfile(
            learnerProfileId: 1,
            userId: 1,
            firstName: $firstName,
            middleName: null,
            lastName: $lastName,
            preferredDisplayName: $preferredDisplayName,
            certificateName: $certificateName,
            certificateNameConfirmed: $certificateNameConfirmed,
            dateOfBirth: $dateOfBirth,
            gender: null,
            nationality: null,
            addressLine1: $addressLine1,
            addressLine2: null,
            city: $city,
            state: $state,
            postalCode: $postalCode,
            country: $country,
            alternateMobile: null,
            profession: $profession,
            speciality: $speciality,
            currentDesignation: $currentDesignation,
            organizationName: $organizationName,
            yearsOfExperience: $yearsOfExperience,
            medicalCouncilName: $medicalCouncilName,
            medicalCouncilRegistrationNumber: $medicalCouncilRegistrationNumber,
            medicalCouncilRegistrationState: $medicalCouncilRegistrationState,
            registrationValidFrom: $registrationValidFrom,
            registrationValidUntil: $registrationValidUntil,
            rowVersion: 1,
            createdAt: $now,
            updatedAt: $now,
        );
    }

    private function qualification(): LearnerQualification
    {
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));

        return new LearnerQualification(
            learnerQualificationId: 1,
            learnerProfileId: 1,
            qualificationType: 'Degree',
            qualificationName: 'MBBS',
            institutionName: 'AIIMS',
            universityOrBoard: null,
            country: null,
            completionYear: 2010,
            registrationOrCertificateNumber: null,
            displayOrder: 1,
            rowVersion: 1,
            createdAt: $now,
            updatedAt: $now,
        );
    }
}
