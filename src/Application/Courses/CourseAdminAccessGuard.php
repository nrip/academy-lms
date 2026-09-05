<?php

declare(strict_types=1);

namespace Academy\Application\Courses;

use Academy\Application\RBAC\AuthorizationService;
use Academy\Domain\Courses\Course;
use Academy\Domain\Courses\CourseAdminScopePolicy;
use Academy\Domain\Courses\CourseRepository;
use Academy\Domain\Courses\CourseVersion;
use Academy\Domain\Courses\CourseVersionImmutabilityGuard;
use Academy\Domain\Courses\CourseVersionRepository;
use Academy\Domain\Exception\AuthenticationException;
use Academy\Domain\Exception\AuthorizationException;
use Academy\Domain\Exception\NotFoundException;
use Academy\Domain\Security\AuthContext;
use DateTimeImmutable;
use DateTimeZone;

final class CourseAdminAccessGuard
{
    public function __construct(
        private readonly AuthorizationService $authorization,
        private readonly CourseAdminScopePolicy $scopePolicy,
        private readonly CourseRepository $courses,
        private readonly CourseVersionRepository $courseVersions,
        private readonly CourseVersionImmutabilityGuard $immutabilityGuard,
    ) {
    }

    public function requireUserId(AuthContext $auth): int
    {
        if ($auth->userId === null) {
            throw new AuthenticationException('Authentication required.');
        }

        return $auth->userId;
    }

    public function requirePermission(AuthContext $auth, string $permissionKey): void
    {
        $this->authorization->require($auth, $permissionKey);
    }

    public function requireCourseInScope(AuthContext $auth, int $courseId, DateTimeImmutable $at): Course
    {
        $adminUserId = $this->requireUserId($auth);
        $this->requirePermission($auth, 'course.view_assigned');

        $course = $this->courses->findById($courseId);
        if ($course === null) {
            throw new NotFoundException('Course not found.');
        }

        if (!$this->scopePolicy->isCourseInScope($adminUserId, $courseId, $at)) {
            throw new AuthorizationException('Course is outside Course Admin scope.');
        }

        return $course;
    }

    public function requireVersionEditable(AuthContext $auth, int $courseId, int $versionId, DateTimeImmutable $at): CourseVersion
    {
        return $this->requireVersionMutableWithPermission($auth, $courseId, $versionId, 'course.version.edit', $at);
    }

    /**
     * Curriculum mutations (modules / content) require a dedicated permission plus an unlocked version in scope.
     */
    public function requireVersionMutableWithPermission(
        AuthContext $auth,
        int $courseId,
        int $versionId,
        string $permissionKey,
        DateTimeImmutable $at,
    ): CourseVersion {
        $adminUserId = $this->requireUserId($auth);
        $this->requirePermission($auth, $permissionKey);
        $this->requireCourseInScope($auth, $courseId, $at);

        $version = $this->courseVersions->findById($versionId);
        if ($version === null || $version->courseId !== $courseId) {
            throw new NotFoundException('Course version not found.');
        }

        if (!$this->scopePolicy->isVersionInScope($adminUserId, $courseId, $versionId, $at)) {
            throw new AuthorizationException('Course version is outside Course Admin scope.');
        }

        $this->immutabilityGuard->assertMutable($version);

        return $version;
    }

    public function requireVersionViewable(AuthContext $auth, int $courseId, int $versionId, DateTimeImmutable $at): CourseVersion
    {
        $adminUserId = $this->requireUserId($auth);
        $this->requireCourseInScope($auth, $courseId, $at);

        $version = $this->courseVersions->findById($versionId);
        if ($version === null || $version->courseId !== $courseId) {
            throw new NotFoundException('Course version not found.');
        }

        if (!$this->scopePolicy->isVersionInScope($adminUserId, $courseId, $versionId, $at)) {
            throw new AuthorizationException('Course version is outside Course Admin scope.');
        }

        return $version;
    }

    public function nowUtc(): DateTimeImmutable
    {
        return new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }
}
