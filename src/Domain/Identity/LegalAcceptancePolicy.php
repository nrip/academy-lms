<?php

declare(strict_types=1);

namespace Academy\Domain\Identity;

use Academy\Domain\Exception\ValidationException;

final class LegalAcceptancePolicy
{
    public function __construct(
        private readonly string $termsVersion,
        private readonly string $privacyVersion,
    ) {
        if ($termsVersion === '' || $privacyVersion === '') {
            throw new \InvalidArgumentException('Terms and privacy versions must not be empty.');
        }
    }

    public function assertAccepted(bool $termsAccepted, bool $privacyAccepted): void
    {
        $fields = [];

        if (!$termsAccepted) {
            $fields['terms_accepted'] = ['You must accept the Terms of Use.'];
        }
        if (!$privacyAccepted) {
            $fields['privacy_accepted'] = ['You must accept the Privacy Policy.'];
        }

        if ($fields !== []) {
            throw new ValidationException('Please correct the highlighted fields.', $fields);
        }
    }

    public function currentTermsVersion(): string
    {
        return $this->termsVersion;
    }

    public function currentPrivacyVersion(): string
    {
        return $this->privacyVersion;
    }
}
