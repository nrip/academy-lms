<?php

declare(strict_types=1);

namespace Academy\Application\Courses;

use Academy\Application\Audit\AuditService;
use Academy\Application\Assessments\QuestionBankService;
use Academy\Domain\Audit\CoursesAuditPayload;
use Academy\Domain\Courses\Course;
use Academy\Domain\Courses\CourseAdminScopeAssignmentRepository;
use Academy\Domain\Courses\CourseRepository;
use Academy\Domain\Courses\CourseStatus;
use Academy\Domain\Courses\CourseVersionRepository;
use Academy\Domain\Exception\ConflictException;
use Academy\Domain\Exception\ValidationException;
use Academy\Domain\Security\AuthContext;
use Academy\Infrastructure\Database\ConnectionFactory;
use DateTimeImmutable;
use DateTimeZone;
use PDO;
use PDOException;
use Throwable;

final class CreateCourseService
{
    public function __construct(
        private readonly CourseAdminAccessGuard $access,
        private readonly CourseRepository $courses,
        private readonly CourseVersionRepository $courseVersions,
        private readonly CourseAdminScopeAssignmentRepository $scopes,
        private readonly QuestionBankService $questionBanks,
        private readonly ConnectionFactory $connections,
        private readonly AuditService $audit,
    ) {
    }

    /**
     * @return array{course: Course, version_id: int}
     */
    public function create(
        AuthContext $auth,
        string $courseCode,
        string $slug,
        string $masterTitle,
    ): array {
        $actorUserId = $this->access->requireUserId($auth);
        $this->access->requirePermission($auth, 'course.create');

        $courseCode = $this->normalizeCode($courseCode);
        $slug = $this->normalizeSlug($slug);
        $masterTitle = trim($masterTitle);
        if ($masterTitle === '' || strlen($masterTitle) > 255) {
            throw new ValidationException('Master title is required (max 255 characters).');
        }

        if ($this->courses->findByCourseCode($courseCode) !== null) {
            throw new ConflictException('A course with this course code already exists.');
        }
        if ($this->courses->findBySlug($slug) !== null) {
            throw new ConflictException('A course with this slug already exists.');
        }

        $pdo = $this->connections->connection();
        $pdo->beginTransaction();
        $courseId = 0;
        $versionId = 0;
        try {
            $inserted = $this->courses->insert($courseCode, $slug, $masterTitle, CourseStatus::ACTIVE);
            $courseId = $inserted['course_id'];

            $versionId = $this->courseVersions->insertDraft([
                'course_id' => $courseId,
                'version_number' => 1,
                'title' => $masterTitle,
                'description' => 'Draft — update before publish.',
                'learning_objectives' => 'Draft — update before publish.',
                'intended_audience' => 'Draft — update before publish.',
                'syllabus_summary' => 'Draft — update before publish.',
                'admission_mode' => 'A',
                'delivery_type' => 'online',
                'duration_text' => 'To be confirmed',
                'validity_period_days' => null,
                'standard_fee' => '0.00',
                'gst_rate' => '18.00',
                'currency' => 'INR',
                'certificate_type' => 'Certificate of Completion',
                'faq' => null,
            ]);

            $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
            // Creator owns this course and future versions (demo-safe; assignment default remains false elsewhere).
            $this->scopes->insertCourseScope(
                $actorUserId,
                $courseId,
                true,
                $now,
                $actorUserId,
            );

            $this->questionBanks->ensureBankForCourse($courseId, $masterTitle);

            $this->audit->record(
                new CoursesAuditPayload(
                    action: 'course.created',
                    entityType: 'course',
                    entityId: (string) $courseId,
                    next: [
                        'course_id' => $courseId,
                        'course_code' => $courseCode,
                        'slug' => $slug,
                        'master_title' => $masterTitle,
                        'version_id' => $versionId,
                        'version_number' => 1,
                        'admission_mode' => 'A',
                    ],
                ),
                actorType: 'user',
                actorUserId: $actorUserId,
                source: 'course_admin',
            );

            $pdo->commit();
        } catch (PDOException $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            if ($this->isUniqueViolation($exception)) {
                throw new ConflictException('A course with this course code or slug already exists.');
            }
            throw $exception;
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $exception;
        }

        $course = $this->courses->findById($courseId);
        if ($course === null) {
            throw new ConflictException('Course could not be loaded after create.');
        }

        return ['course' => $course, 'version_id' => $versionId];
    }

    private function normalizeCode(string $courseCode): string
    {
        $courseCode = strtoupper(trim($courseCode));
        if ($courseCode === '' || strlen($courseCode) > 64 || !preg_match('/^[A-Z0-9][A-Z0-9\-_]*$/', $courseCode)) {
            throw new ValidationException('Course code must be 1–64 characters (A–Z, 0–9, hyphen, underscore).');
        }

        return $courseCode;
    }

    private function normalizeSlug(string $slug): string
    {
        $slug = strtolower(trim($slug));
        if ($slug === '' || strlen($slug) > 128 || !preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $slug)) {
            throw new ValidationException('Slug must be lowercase kebab-case (max 128 characters).');
        }

        return $slug;
    }

    private function isUniqueViolation(PDOException $exception): bool
    {
        return $exception->getCode() === '23000' || str_contains($exception->getMessage(), 'Duplicate');
    }
}
