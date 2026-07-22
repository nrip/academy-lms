<?php

declare(strict_types=1);

namespace Academy\Domain\Identity;

use Academy\Domain\Exception\ValidationException;
use DateTimeImmutable;
use DateTimeZone;

final class QualificationValidator
{
    private const MIN_COMPLETION_YEAR = 1950;

    /** @var array<string, int> */
    private const REQUIRED_STRINGS = [
        'qualification_type' => 80,
        'qualification_name' => 200,
        'institution_name' => 200,
    ];

    /** @var array<string, int> */
    private const OPTIONAL_STRINGS = [
        'university_or_board' => 200,
        'country' => 100,
        'registration_or_certificate_number' => 100,
    ];

    /**
     * @param array<string, mixed> $input
     * @return array<string, scalar|null>
     */
    public function validate(array $input): array
    {
        $errors = [];
        $normalized = [];

        foreach (self::REQUIRED_STRINGS as $key => $maxLength) {
            $value = $this->readString($input, $key, $maxLength, $errors);
            if ($value === null && !isset($errors[$key])) {
                $errors[$key][] = 'This field is required.';
            }
            $normalized[$key] = $value;
        }

        foreach (self::OPTIONAL_STRINGS as $key => $maxLength) {
            $normalized[$key] = $this->readString($input, $key, $maxLength, $errors);
        }

        $normalized['completion_year'] = $this->readCompletionYear($input, $errors);

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
    private function readCompletionYear(array $input, array &$errors): ?int
    {
        $maxYear = (int) (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y') + 1;

        if (!array_key_exists('completion_year', $input)) {
            $errors['completion_year'][] = 'This field is required.';

            return null;
        }

        $value = $input['completion_year'];
        if (is_array($value) || is_object($value) || is_bool($value)) {
            $errors['completion_year'][] = 'This field is invalid.';

            return null;
        }

        $trimmed = trim((string) ($value ?? ''));
        if ($trimmed === '') {
            $errors['completion_year'][] = 'This field is required.';

            return null;
        }
        if (preg_match('/^\d{4}$/', $trimmed) !== 1) {
            $errors['completion_year'][] = 'Enter a valid four-digit year.';

            return null;
        }

        $year = (int) $trimmed;
        if ($year < self::MIN_COMPLETION_YEAR || $year > $maxYear) {
            $errors['completion_year'][] = sprintf('Enter a year between %d and %d.', self::MIN_COMPLETION_YEAR, $maxYear);

            return null;
        }

        return $year;
    }
}
