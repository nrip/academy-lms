<?php

declare(strict_types=1);

namespace Academy\Application\Courses;

use Academy\Domain\Courses\ContentItem;
use Academy\Domain\Courses\ContentItemRepository;
use Academy\Domain\Courses\Course;
use Academy\Domain\Courses\CourseVersion;
use Academy\Domain\Courses\Module;
use Academy\Domain\Courses\ModuleRepository;
use Academy\Domain\Security\AuthContext;

final class CurriculumQueryService
{
    public function __construct(
        private readonly CourseAdminAccessGuard $access,
        private readonly ModuleRepository $modules,
        private readonly ContentItemRepository $contentItems,
    ) {
    }

    /**
     * @return array{
     *   course: Course,
     *   version: CourseVersion,
     *   modules: list<array{module: Module, content_items: list<ContentItem>}>,
     *   editable: bool
     * }
     */
    public function getCurriculum(AuthContext $auth, int $courseId, int $versionId): array
    {
        $at = $this->access->nowUtc();
        $course = $this->access->requireCourseInScope($auth, $courseId, $at);
        $version = $this->access->requireVersionViewable($auth, $courseId, $versionId, $at);

        $tree = [];
        foreach ($this->modules->listByCourseVersionId($versionId) as $module) {
            $tree[] = [
                'module' => $module,
                'content_items' => $this->contentItems->listByModuleId($module->moduleId),
            ];
        }

        return [
            'course' => $course,
            'version' => $version,
            'modules' => $tree,
            'editable' => !$version->isLocked(),
        ];
    }
}
