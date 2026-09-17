<?php

declare(strict_types=1);

namespace Academy\Application\Courses;

/**
 * Guidance checklist for course creators. Does not change publish state-machine rules.
 */
final class CoursePublishReadinessChecklist
{
    /**
     * @param list<array{key: string, label: string, done: bool, required: bool, href: string|null, help: string}> $items
     */
    public function __construct(
        public readonly array $items,
    ) {
    }

    public function requiredComplete(): bool
    {
        foreach ($this->items as $item) {
            if ($item['required'] && !$item['done']) {
                return false;
            }
        }

        return true;
    }

    public function doneCount(): int
    {
        $n = 0;
        foreach ($this->items as $item) {
            if ($item['done']) {
                ++$n;
            }
        }

        return $n;
    }

    public function totalCount(): int
    {
        return count($this->items);
    }
}
