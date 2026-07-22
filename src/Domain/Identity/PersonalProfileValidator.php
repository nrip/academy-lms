<?php

declare(strict_types=1);

namespace Academy\Domain\Identity;

use Academy\Domain\Exception\ValidationException;
use DateTimeImmutable;
use DateTimeZone;

/**
 * Validates and normalizes the personal section of a learner profile.
 *
 * Names are validated by length only (Unicode-safe); no English-only character rules.
 */
final class PersonalProfileValidator
{
    /** @var array<string, int> */
    private const STRING_LIMITS = [
        'first_name' => 100,
        'middle_name' => 100,
        'last_name' => 100,
        'preferred_display_name' => 200,
        'certificate_name' => 200,
        'nationality' => 100,
        'address_line_1' => 255,
        'address_line_2' => 255,
        'city' => 100,
        'state' => 100,
        'postal_code' => 32,
        'country' => 100,
    ];

    /**
     * @param array<string, mixed> $input
     * @return array<string, scalar|null>
     */
    public function validate(array $input): array
    {
        $errors = [];
        $normalized = [];

        foreach (self::STRING_LIMITS as $key => $maxLength) {
            $normalized[$key] = $this->readString($input, $key, $maxLength, $errors);
        }

        $normalized['certificate_name'] = $normalized['certificate_name'] ?? null;
        $normalized['certificate_name_confirmed'] = $this->readBool($input, 'certificate_name_confirmed', $errors);

        $normalized['date_of_birth'] = $this->readPastDate($input, 'date_of_birth', $errors);

        try {
            $normalized['gender'] = ProfileGender::normalize($this->rawString($input, 'gender', $errors));
        } catch (ValidationException $exception) {
            $this->mergeErrors($errors, $exception->fields());
            $normalized['gender'] = null;
        }

        $normalized['alternate_mobile'] = $this->readAlternateMobile($input, $errors);

        $this->enforceIndianPostalCode($normalized, $errors);

        if ($errors !== []) {
            throw new ValidationException('Please correct the highlighted fields.', $errors);
        }

        return $normalized;
    }

    /**
     * @param array<string, mixed> $input
     * @param array<string, list<string>> $errors
     */
    private function readString(array $input, string $key, int $maxLength, array &$errors): ?string
    {
        if (!array_key_exists($key, $input)) {
            return null;
        }

        $value = $input[$key];
        if (is_array($value) || is_object($value) || is_bool($value)) {
            $errors[$key][] = 'This field is invalid.';

            return null;
        }
        if ($value === null) {
            return null;
        }

        $trimmed = trim((string) $value);
        if ($trimmed === '') {
            return null;
        }
        if (mb_strlen($trimmed) > $maxLength) {
            $errors[$key][] = sprintf('Must be at most %d characters.', $maxLength);

            return null;
        }

        return $trimmed;
    }

    /**
     * @param array<string, mixed> $input
     * @param array<string, list<string>> $errors
     */
    private function rawString(array $input, string $key, array &$errors): ?string
    {
        if (!array_key_exists($key, $input)) {
            return null;
        }

        $value = $input[$key];
        if (is_array($value) || is_object($value) || is_bool($value)) {
            $errors[$key][] = 'This field is invalid.';

            return null;
        }
        if ($value === null) {
            return null;
        }

        return (string) $value;
    }

    /**
     * @param array<string, mixed> $input
     * @param array<string, list<string>> $errors
     */
    private function readBool(array $input, string $key, array &$errors): bool
    {
        if (!array_key_exists($key, $input)) {
            return false;
        }

        $value = $input[$key];
        if (is_array($value) || is_object($value)) {
            $errors[$key][] = 'This field is invalid.';

            return false;
        }
        if (is_bool($value)) {
            return $value;
        }

        return in_array((string) $value, ['1', 'true', 'on', 'yes'], true);
    }

    /**
     * @param array<string, mixed> $input
     * @param array<string, list<string>> $errors
     */
    private function readPastDate(array $input, string $key, array &$errors): ?string
    {
        $raw = $this->readString($input, $key, 10, $errors);
        if ($raw === null) {
            return null;
        }

        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $raw, new DateTimeZone('UTC'));
        $parseErrors = DateTimeImmutable::getLastErrors();
        if ($date === false || ($parseErrors !== false && ($parseErrors['warning_count'] > 0 || $parseErrors['error_count'] > 0))) {
            $errors[$key][] = 'Enter a valid date in YYYY-MM-DD format.';

            return null;
        }

        $today = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        if ($date > $today) {
            $errors[$key][] = 'Date cannot be in the future.';

            return null;
        }

        return $date->format('Y-m-d');
    }

    /**
     * @param array<string, mixed> $input
     * @param array<string, list<string>> $errors
     */
    private function readAlternateMobile(array $input, array &$errors): ?string
    {
        $raw = $this->readString($input, 'alternate_mobile', 20, $errors);
        if ($raw === null || isset($errors['alternate_mobile'])) {
            return $raw;
        }

        try {
            return MobileE164Normalizer::normalize($raw);
        } catch (ValidationException) {
            $errors['alternate_mobile'][] = 'Enter a valid mobile number in E.164 form, or a 10-digit Indian mobile.';

            return null;
        }
    }

    /**
     * @param array<string, scalar|null> $normalized
     * @param array<string, list<string>> $errors
     */
    private function enforceIndianPostalCode(array $normalized, array &$errors): void
    {
        if (isset($errors['postal_code']) || isset($errors['country'])) {
            return;
        }

        $country = $normalized['country'] ?? null;
        if (!is_string($country)) {
            return;
        }

        $countryKey = strtolower(trim($country));
        if ($countryKey !== 'in' && $countryKey !== 'india') {
            return;
        }

        $postalCode = $normalized['postal_code'] ?? null;
        if (!is_string($postalCode) || preg_match('/^\d{6}$/', $postalCode) !== 1) {
            $errors['postal_code'][] = 'Indian postal code must be exactly 6 digits.';
        }
    }

    /**
     * @param array<string, list<string>> $errors
     * @param array<string, list<string>> $incoming
     */
    private function mergeErrors(array &$errors, array $incoming): void
    {
        foreach ($incoming as $field => $messages) {
            foreach ($messages as $message) {
                $errors[$field][] = $message;
            }
        }
    }
}
