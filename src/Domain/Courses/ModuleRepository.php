<?php

declare(strict_types=1);

namespace Academy\Domain\Courses;

interface ModuleRepository
{
    public function findById(int $moduleId): ?Module;

    /** @return list<Module> */
    public function listByCourseVersionId(int $courseVersionId): array;

    public function nextSequence(int $courseVersionId): int;

    /**
     * @param array{
     *   course_version_id: int,
     *   sequence: int,
     *   title: string,
     *   description: string,
     *   mandatory_flag: bool,
     *   release_rule: string,
     *   prerequisite_module_id: ?int
     * } $data
     */
    public function insert(array $data): int;

    /**
     * @param array{
     *   title: string,
     *   description: string,
     *   mandatory_flag: bool,
     *   release_rule: string,
     *   prerequisite_module_id: ?int
     * } $data
     */
    public function update(int $moduleId, array $data): bool;

    public function delete(int $moduleId): bool;

    public function countContentItems(int $moduleId): int;
}
