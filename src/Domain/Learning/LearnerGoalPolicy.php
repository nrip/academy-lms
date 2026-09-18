<?php

declare(strict_types=1);

namespace Academy\Domain\Learning;

use Academy\Domain\Exception\ValidationException;
use DateTimeImmutable;
use DateTimeZone;

final class LearnerGoalPolicy
{
    public const MAX_LABEL_LENGTH = 120;
    public const DEFAULT_LABEL = 'Target completion date';

    /**
     * @return array{label: string, target_date: string}
     */
    public static function normalize(string $label, string $targetDate, DateTimeImmutable $nowUtc): array
    {
        $cleanLabel = trim(str_replace("\0", '', $label));
        if ($cleanLabel === '') {
            $cleanLabel = self::DEFAULT_LABEL;
        }
        if (mb_strlen($cleanLabel) > self::MAX_LABEL_LENGTH) {
            throw new ValidationException('Goal labels cannot exceed ' . self::MAX_LABEL_LENGTH . ' characters.');
        }

        $cleanDate = trim($targetDate);
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $cleanDate, new DateTimeZone('UTC'));
        $errors = DateTimeImmutable::getLastErrors();
        if (
            $parsed === false
            || ($errors !== false && (($errors['warning_count'] ?? 0) > 0 || ($errors['error_count'] ?? 0) > 0))
        ) {
            throw new ValidationException('Enter a valid target date.');
        }

        $today = $nowUtc->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d');
        if ($parsed->format('Y-m-d') < $today) {
            throw new ValidationException('Choose today or a future date for your goal.');
        }

        return [
            'label' => $cleanLabel,
            'target_date' => $parsed->format('Y-m-d'),
        ];
    }
}
