<?php

declare(strict_types=1);

namespace Academy\Domain\Identity;

use DateTimeImmutable;

final class LearnerProfile
{
    public function __construct(
        public readonly int $learnerProfileId,
        public readonly int $userId,
        public readonly ?string $firstName,
        public readonly ?string $middleName,
        public readonly ?string $lastName,
        public readonly ?string $preferredDisplayName,
        public readonly ?string $certificateName,
        public readonly bool $certificateNameConfirmed,
        public readonly ?string $dateOfBirth,
        public readonly ?string $gender,
        public readonly ?string $nationality,
        public readonly ?string $addressLine1,
        public readonly ?string $addressLine2,
        public readonly ?string $city,
        public readonly ?string $state,
        public readonly ?string $postalCode,
        public readonly ?string $country,
        public readonly ?string $alternateMobile,
        public readonly ?string $profession,
        public readonly ?string $speciality,
        public readonly ?string $currentDesignation,
        public readonly ?string $organizationName,
        public readonly ?int $yearsOfExperience,
        public readonly ?string $medicalCouncilName,
        public readonly ?string $medicalCouncilRegistrationNumber,
        public readonly ?string $medicalCouncilRegistrationState,
        public readonly ?string $registrationValidFrom,
        public readonly ?string $registrationValidUntil,
        public readonly int $rowVersion,
        public readonly DateTimeImmutable $createdAt,
        public readonly DateTimeImmutable $updatedAt,
    ) {
    }
}
