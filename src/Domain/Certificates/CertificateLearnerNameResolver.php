<?php

declare(strict_types=1);

namespace Academy\Domain\Certificates;

use Academy\Domain\Identity\LearnerProfile;

/**
 * Resolve display name for certificate snapshot without requiring profile module changes.
 */
final class CertificateLearnerNameResolver
{
    public function resolve(?LearnerProfile $profile): ?string
    {
        if ($profile === null) {
            return null;
        }

        $certificateName = $this->clean($profile->certificateName);
        if ($certificateName !== null) {
            return $certificateName;
        }

        $parts = array_values(array_filter([
            $this->clean($profile->firstName),
            $this->clean($profile->middleName),
            $this->clean($profile->lastName),
        ], static fn (?string $part): bool => $part !== null));

        if ($parts !== []) {
            return implode(' ', $parts);
        }

        return $this->clean($profile->preferredDisplayName);
    }

    private function clean(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }
}
