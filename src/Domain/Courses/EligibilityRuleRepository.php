<?php

declare(strict_types=1);

namespace Academy\Domain\Courses;

interface EligibilityRuleRepository
{
    /**
     * @return list<EligibilityRule>
     */
    public function listByCourseVersionId(int $courseVersionId): array;

    /**
     * @param array{
     *   course_version_id: int,
     *   field: string,
     *   operator: string,
     *   value: string,
     *   logic_group: string,
     *   display_label: string,
     *   sort_order: int
     * } $data
     */
    public function insert(array $data): int;
}
