<?php

declare(strict_types=1);

namespace Academy\Domain\Identity;

use Academy\Domain\Exception\ValidationException;
use DateTimeImmutable;
use DateTimeZone;

final class ProfessionalProfileValidator
{
    private const MIN_EXPERIENCE = 0;
    private const MAX_EXPERIENCE = 70;

    /** @var array<string, int> */
    private const STRING_LIMITS = [
        'profession' => 120,
        'speciality' => 120,
        'current_designation' => 120,
        'organization_name' => 200,
        'medical_council_name' => 200,
        'medical_council_registration_number' => 100,
        'medical_council_registration_state' => 100,
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

        $normalized['years_of_experience'] = $this->readExperience($input, $errors);
        $normalized['registration_valid_from'] = $this->readDate($input, 'registration_valid_from', $errors);
        $normalized['registration_valid_until'] = $this->readDate($input, 'registration_valid_until', $errors);

        $from = $normalized['registration_valid_from'];
        $until = $normalized['registration_valid_until'];
        if (is_string($from) && is_string($until) && $until < $from) {
            $errors['registration_valid_until'][] = 'Valid-until date cannot be before the valid-from date.';
        }

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
    private function readExperience(array $input, array &$errors): ?int
    {
        if (!array_key_exists('years_of_experience', $input)) {
            return null;
        }

        $value = $input['years_of_experience'];
        if (is_array($value) || is_object($value) || is_bool($value)) {
            $errors['years_of_experience'][] = 'This field is invalid.';

            return null;
        }
        if ($value === null) {
            return null;
        }

        $trimmed = trim((string) $value);
        if ($trimmed === '') {
            return null;
        }
        if (preg_match('/^\d{1,3}$/', $trimmed) !== 1) {
            $errors['years_of_experience'][] = 'Enter years of experience as a whole number.';

            return null;
        }

        $years = (int) $trimmed;
        if ($years < self::MIN_EXPERIENCE || $years > self::MAX_EXPERIENCE) {
            $errors['years_of_experience'][] = sprintf('Enter a value between %d and %d.', self::MIN_EXPERIENCE, self::MAX_EXPERIENCE);

            return null;
        }

        return $years;
    }

    /**
     * @param array<string, mixed> $input
     * @param array<string, list<string>> $errors
     */
    private function readDate(array $input, string $key, array &$errors): ?string
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

        return $date->format('Y-m-d');
    }
}
