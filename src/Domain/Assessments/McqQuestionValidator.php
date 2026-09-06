<?php

declare(strict_types=1);

namespace Academy\Domain\Assessments;

use Academy\Domain\Exception\ValidationException;

/**
 * Authoring-side validation for MCQ single-answer questions.
 * Learner DTOs must never include is_correct (enforced by omitting it from player serializers later).
 */
final class McqQuestionValidator
{
    /**
     * @param list<array{option_text: string, is_correct: bool}> $options
     */
    public static function assertValidOptions(array $options): void
    {
        if (count($options) < 2) {
            throw new ValidationException('An MCQ requires at least two answer options.');
        }
        if (count($options) > 10) {
            throw new ValidationException('An MCQ may have at most 10 answer options.');
        }

        $correctCount = 0;
        foreach ($options as $index => $option) {
            $text = trim($option['option_text']);
            if ($text === '') {
                throw new ValidationException('Option ' . (string) ($index + 1) . ' text is required.');
            }
            if (mb_strlen($text) > 1000) {
                throw new ValidationException('Option text must be 1000 characters or fewer.');
            }
            if ($option['is_correct']) {
                $correctCount++;
            }
        }

        if ($correctCount < 1) {
            throw new ValidationException('Mark at least one option as correct.');
        }
        if ($correctCount > 1) {
            throw new ValidationException('mcq_single allows exactly one correct option.');
        }
    }
}
