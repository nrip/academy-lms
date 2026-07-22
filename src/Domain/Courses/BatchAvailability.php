<?php

declare(strict_types=1);

namespace Academy\Domain\Courses;

/**
 * Deterministic view-time availability result. Carries no side effects — no
 * capacity is reserved by evaluating this. See BatchAvailabilityEvaluator.
 */
final class BatchAvailability
{
    public const REASON_SELECTABLE = 'selectable';
    public const REASON_COURSE_NOT_ACTIVE = 'course_not_active';
    public const REASON_VERSION_NOT_PUBLISHED = 'version_not_published';
    public const REASON_BATCH_NOT_OPEN = 'batch_not_open';
    public const REASON_BEFORE_WINDOW = 'before_application_window';
    public const REASON_AFTER_WINDOW = 'after_application_window';

    /** @var array<string, string> */
    private const LABELS = [
        self::REASON_SELECTABLE => 'Applications open',
        self::REASON_COURSE_NOT_ACTIVE => 'This course is not currently offered.',
        self::REASON_VERSION_NOT_PUBLISHED => 'This course is not yet published.',
        self::REASON_BATCH_NOT_OPEN => 'Applications are not open for this batch.',
        self::REASON_BEFORE_WINDOW => 'Applications open soon.',
        self::REASON_AFTER_WINDOW => 'Applications have closed for this batch.',
    ];

    private function __construct(
        public readonly bool $selectable,
        public readonly string $reasonCode,
    ) {
    }

    public static function selectable(): self
    {
        return new self(true, self::REASON_SELECTABLE);
    }

    public static function notSelectable(string $reasonCode): self
    {
        if (!isset(self::LABELS[$reasonCode])) {
            throw new \InvalidArgumentException('Unknown BatchAvailability reason code.');
        }

        return new self(false, $reasonCode);
    }

    public function label(): string
    {
        return self::LABELS[$this->reasonCode];
    }
}
