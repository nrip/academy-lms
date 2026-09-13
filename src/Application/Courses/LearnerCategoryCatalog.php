<?php

declare(strict_types=1);

namespace Academy\Application\Courses;

/**
 * Customer-facing learner categories stored on the existing profession eligibility rule.
 * Labels are the only values shown in the product UI.
 */
final class LearnerCategoryCatalog
{
    public const DOCTOR = 'doctor';
    public const NURSE = 'nurse';
    public const ALLIED = 'allied_professional';

    /**
     * @return array<string, string> stored key => customer label
     */
    public static function options(): array
    {
        return [
            self::DOCTOR => 'Doctor',
            self::NURSE => 'Nurse',
            self::ALLIED => 'Allied medical professional',
        ];
    }

    /**
     * @return list<string>
     */
    public static function labels(): array
    {
        return array_values(self::options());
    }

    public static function keyForLabel(string $label): ?string
    {
        $key = array_search($label, self::options(), true);

        return is_string($key) ? $key : null;
    }

    public static function labelForKey(string $key): ?string
    {
        return self::options()[$key] ?? null;
    }

    /**
     * @param list<string> $keys
     */
    public static function summary(array $keys): string
    {
        $labels = [];
        foreach (self::options() as $key => $label) {
            if (in_array($key, $keys, true)) {
                $labels[] = $label;
            }
        }

        $count = count($labels);
        if ($count === 0) {
            return '';
        }
        if ($count === 1) {
            return $labels[0];
        }
        if ($count === 2) {
            return $labels[0] . ' or ' . $labels[1];
        }

        $last = array_pop($labels);

        return implode(', ', $labels) . ', or ' . $last;
    }
}
