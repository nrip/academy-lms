<?php

declare(strict_types=1);

namespace Academy\Application\Courses;

use Academy\Application\Audit\AuditService;
use Academy\Domain\Audit\CoursesAuditPayload;
use Academy\Domain\Courses\CourseAdminScopeAssignmentRepository;
use Academy\Domain\Courses\CourseRepository;
use Academy\Domain\Exception\ConflictException;
use Academy\Domain\Exception\NotFoundException;
use Academy\Domain\Exception\ValidationException;
use Academy\Domain\Identity\EmailNormalizer;
use Academy\Domain\RBAC\RoleKeys;
use Academy\Domain\RBAC\RoleRepository;
use Academy\Domain\Security\AuthContext;
use Academy\Infrastructure\Database\ConnectionFactory;
use DateTimeImmutable;
use DateTimeZone;
use PDO;

/**
 * Minimal Super Admin scope assignment for Course Admin demo staffing.
 */
final class AssignCourseAdminScopeService
{
    public function __construct(
        private readonly CourseAdminAccessGuard $access,
        private readonly CourseAdminScopeAssignmentRepository $scopes,
        private readonly CourseRepository $courses,
        private readonly RoleRepository $roles,
        private readonly ConnectionFactory $connections,
        private readonly AuditService $audit,
    ) {
    }

    public function assignCourseScope(
        AuthContext $auth,
        string $adminEmail,
        int $courseId,
        bool $includeFutureVersions = false,
    ): int {
        $actorUserId = $this->access->requireUserId($auth);
        $this->access->requirePermission($auth, 'course.admin.scope.assign');

        $email = EmailNormalizer::normalize($adminEmail);
        $adminUserId = $this->findUserIdByEmail($email);
        if ($adminUserId === null) {
            throw new NotFoundException('User not found for that email.');
        }

        if (!$this->roles->userHasActiveRole($adminUserId, RoleKeys::COURSE_ADMIN)
            && !$this->roles->userHasActiveRole($adminUserId, RoleKeys::SUPER_ADMIN)
        ) {
            throw new ValidationException('Target user must hold the Course Administrator or Super Admin role.');
        }

        $course = $this->courses->findById($courseId);
        if ($course === null) {
            throw new NotFoundException('Course not found.');
        }

        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        if ($this->scopes->findActiveCourseScope($adminUserId, $courseId, $now) !== null) {
            throw new ConflictException('An active course scope already exists for this user and course.');
        }

        $scopeId = $this->scopes->insertCourseScope(
            $adminUserId,
            $courseId,
            $includeFutureVersions,
            $now,
            $actorUserId,
        );

        $this->audit->record(
            new CoursesAuditPayload(
                action: 'course_admin.scope_assigned',
                entityType: 'course_admin_scope_assignment',
                entityId: (string) $scopeId,
                next: [
                    'scope_assignment_id' => $scopeId,
                    'admin_user_id' => $adminUserId,
                    'course_id' => $courseId,
                    'scope_type' => 'course',
                    'include_future_versions' => $includeFutureVersions ? 1 : 0,
                ],
            ),
            actorType: 'user',
            actorUserId: $actorUserId,
            source: 'course_admin',
        );

        return $scopeId;
    }

    private function findUserIdByEmail(string $email): ?int
    {
        $pdo = $this->connections->connection();
        $stmt = $pdo->prepare('SELECT user_id FROM users WHERE email = :email LIMIT 1');
        $stmt->execute(['email' => $email]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : (int) $row['user_id'];
    }
}
