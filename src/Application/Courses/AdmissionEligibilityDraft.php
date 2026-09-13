<?php

declare(strict_types=1);

namespace Academy\Application\Courses;

use Academy\Domain\Courses\EligibilityRule;
use Academy\Domain\Exception\ValidationException;

/**
 * Maps the customer eligibility form onto existing eligibility rule rows.
 * Additional notes are display text. They are not a new evaluator.
 */
final class AdmissionEligibilityDraft
{
    public const NOTE_FIELD = 'note';
    public const PROFESSION_FIELD = 'profession';
    private const MAX_NOTES = 20;
    private const MAX_NOTE_LENGTH = 255;

    /**
     * @param list<string> $categoryKeys
     * @param list<string> $preservedCategoryKeys stored tokens that are not in the category list
     * @param list<string> $notes
     */
    public function __construct(
        public readonly array $categoryKeys,
        public readonly array $preservedCategoryKeys,
        public readonly array $notes,
        public readonly ?string $preservedProfessionLabel,
    ) {
    }

    /**
     * @param list<EligibilityRule> $rules
     */
    public static function fromRules(array $rules): self
    {
        $known = [];
        $preserved = [];
        $professionLabel = null;
        $notes = [];

        foreach ($rules as $rule) {
            if ($rule->field === self::PROFESSION_FIELD && $rule->operator === 'in') {
                $professionLabel = $rule->displayLabel;
                foreach (self::tokens($rule->value) as $token) {
                    if (LearnerCategoryCatalog::labelForKey($token) !== null) {
                        $known[$token] = true;
                    } else {
                        $preserved[$token] = true;
                    }
                }
                continue;
            }

            $label = trim($rule->displayLabel);
            if ($label !== '') {
                $notes[] = $label;
            }
        }

        $categoryKeys = [];
        foreach (array_keys(LearnerCategoryCatalog::options()) as $key) {
            if (isset($known[$key])) {
                $categoryKeys[] = $key;
            }
        }

        $summary = LearnerCategoryCatalog::summary($categoryKeys);
        if ($categoryKeys !== [] && $professionLabel !== null && $professionLabel !== $summary) {
            array_unshift($notes, $professionLabel);
        }

        return new self(
            categoryKeys: $categoryKeys,
            preservedCategoryKeys: array_keys($preserved),
            notes: $notes,
            preservedProfessionLabel: $professionLabel,
        );
    }

    /**
     * @param mixed $postedCategories
     */
    public static function fromPosted(mixed $postedCategories, string $notesText, self $existing): self
    {
        $labels = self::postedLabels($postedCategories);
        $categoryKeys = [];
        foreach ($labels as $label) {
            $key = LearnerCategoryCatalog::keyForLabel($label);
            if ($key === null) {
                throw new ValidationException('Choose a recognised learner category.');
            }
            $categoryKeys[$key] = true;
        }

        $ordered = [];
        foreach (array_keys(LearnerCategoryCatalog::options()) as $key) {
            if (isset($categoryKeys[$key])) {
                $ordered[] = $key;
            }
        }

        $notes = self::noteLines($notesText);
        $summary = LearnerCategoryCatalog::summary($ordered);
        $preservedLabel = $existing->preservedProfessionLabel;
        if ($ordered !== []) {
            $preservedLabel = $summary;
        } elseif ($existing->preservedCategoryKeys === []) {
            $preservedLabel = null;
        }

        return new self(
            categoryKeys: $ordered,
            preservedCategoryKeys: $existing->preservedCategoryKeys,
            notes: $notes,
            preservedProfessionLabel: $preservedLabel,
        );
    }

    /**
     * @return list<string>
     */
    public function selectedLabels(): array
    {
        $labels = [];
        foreach ($this->categoryKeys as $key) {
            $label = LearnerCategoryCatalog::labelForKey($key);
            if ($label !== null) {
                $labels[] = $label;
            }
        }

        return $labels;
    }

    public function notesText(): string
    {
        return implode("\n", $this->notes);
    }

    public function hasUnlistedCategories(): bool
    {
        return $this->preservedCategoryKeys !== [];
    }

    /**
     * @return list<array{
     *   field: string,
     *   operator: string,
     *   value: string,
     *   logic_group: string,
     *   display_label: string,
     *   sort_order: int
     * }>
     */
    public function persistenceRows(): array
    {
        $rows = [];
        $tokens = array_merge($this->categoryKeys, $this->preservedCategoryKeys);
        if ($tokens !== []) {
            $summary = LearnerCategoryCatalog::summary($this->categoryKeys);
            $label = $summary !== ''
                ? $summary
                : ($this->preservedProfessionLabel ?? 'Eligible learners');
            $rows[] = [
                'field' => self::PROFESSION_FIELD,
                'operator' => 'in',
                'value' => implode(',', $tokens),
                'logic_group' => 'AND',
                'display_label' => $label,
                'sort_order' => 1,
            ];
        }

        $sort = 2;
        foreach ($this->notes as $note) {
            $rows[] = [
                'field' => self::NOTE_FIELD,
                'operator' => 'shown',
                'value' => '1',
                'logic_group' => 'AND',
                'display_label' => $note,
                'sort_order' => $sort,
            ];
            ++$sort;
        }

        return $rows;
    }

    /**
     * @return list<string>
     */
    private static function tokens(string $value): array
    {
        $tokens = [];
        foreach (explode(',', $value) as $token) {
            $token = trim($token);
            if ($token !== '' && !in_array($token, $tokens, true)) {
                $tokens[] = $token;
            }
        }

        return $tokens;
    }

    /**
     * @return list<string>
     */
    private static function postedLabels(mixed $postedCategories): array
    {
        if ($postedCategories === null || $postedCategories === '' || $postedCategories === []) {
            return [];
        }
        if (!is_array($postedCategories)) {
            throw new ValidationException('Choose a recognised learner category.');
        }

        $labels = [];
        foreach ($postedCategories as $label) {
            if (!is_string($label)) {
                throw new ValidationException('Choose a recognised learner category.');
            }
            $label = trim($label);
            if ($label === '' || in_array($label, $labels, true)) {
                continue;
            }
            $labels[] = $label;
        }

        return $labels;
    }

    /**
     * @return list<string>
     */
    private static function noteLines(string $notesText): array
    {
        $normalised = str_replace(["\r\n", "\r"], "\n", $notesText);
        $notes = [];
        foreach (explode("\n", $normalised) as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            if (mb_strlen($line) > self::MAX_NOTE_LENGTH) {
                throw new ValidationException('Each eligibility note must be 255 characters or fewer.');
            }
            $notes[] = $line;
        }

        if (count($notes) > self::MAX_NOTES) {
            throw new ValidationException('Enter no more than 20 eligibility notes.');
        }

        return $notes;
    }
}
