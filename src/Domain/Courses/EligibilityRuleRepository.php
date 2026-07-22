<?php

declare(strict_types=1);

namespace Academy\Domain\Courses;

interface EligibilityRuleRepository
{
    /**
     * @return list<EligibilityRule>
     */
    public function listByCourseVersionId(int $courseVersionId): array;
}
