<?php

declare(strict_types=1);

namespace Academy\Application\Courses;

use Academy\Domain\Courses\Course;
use Academy\Domain\Courses\CourseAdminScopeAssignmentRepository;
use Academy\Domain\Courses\CourseAdminScopePolicy;
use Academy\Domain\Courses\CourseAdminScopeType;
use Academy\Domain\Courses\CourseRepository;
use Academy\Domain\Courses\CourseVersionRepository;
use Academy\Domain\Security\AuthContext;

final class CourseAdminQueryService
{
    public function __construct(
        private readonly CourseAdminAccessGuard $access,
        private readonly CourseAdminScopeAssignmentRepository $scopes,
        private readonly CourseAdminScopePolicy $scopePolicy,
        private readonly CourseRepository $courses,
        private readonly CourseVersionRepository $courseVersions,
    ) {
    }

    /**
     * @return list<Course>
     */
    public function listAssignedCourses(AuthContext $auth): array
    {
        $adminUserId = $this->access->requireUserId($auth);
        $this->access->requirePermission($auth, 'course.view_assigned');
        $at = $this->access->nowUtc();

        $courseIds = [];
        foreach ($this->scopes->listActiveForAdmin($adminUserId, $at) as $assignment) {
            if ($assignment->scopeType === CourseAdminScopeType::COURSE && $assignment->courseId !== null) {
                $courseIds[] = $assignment->courseId;
                continue;
            }
            if ($assignment->scopeType === CourseAdminScopeType::COURSE_VERSION
                && $assignment->courseVersionId !== null
            ) {
                $version = $this->courseVersions->findById($assignment->courseVersionId);
                if ($version !== null) {
                    $courseIds[] = $version->courseId;
                }
            }
        }

        return $this->courses->listByIds($courseIds);
    }

    public function courseDetail(AuthContext $auth, int $courseId): CourseAdminCourseDetail
    {
        $at = $this->access->nowUtc();
        $course = $this->access->requireCourseInScope($auth, $courseId, $at);
        $adminUserId = $this->access->requireUserId($auth);

        $versions = [];
        foreach ($this->courseVersions->listByCourseId($courseId) as $version) {
            if ($this->scopePolicy->isVersionInScope($adminUserId, $courseId, $version->versionId, $at)) {
                $versions[] = $version;
            }
        }

        return new CourseAdminCourseDetail($course, $versions);
    }
}
