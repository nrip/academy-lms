<?php

declare(strict_types=1);

namespace Academy\Application\Learning;

use Academy\Domain\Learning\Enrolment;

final class LearnerPlayerOutlineView
{
    /**
     * @param list<LearnerPlayerModuleView> $modules
     */
    public function __construct(
        public readonly Enrolment $enrolment,
        public readonly string $courseTitle,
        public readonly string $versionTitle,
        public readonly bool $contentAccessible,
        public readonly ?string $accessMessage,
        public readonly int $completedCount,
        public readonly int $totalCount,
        public readonly array $modules,
    ) {
    }
}
