<?php

declare(strict_types=1);

namespace Academy\Application\Courses;

use Academy\Application\Audit\AuditService;
use Academy\Domain\Audit\CoursesAuditPayload;
use Academy\Domain\Courses\CourseCoverPolicy;
use Academy\Domain\Exception\NotFoundException;
use Academy\Domain\Security\AuthContext;
use Academy\Infrastructure\Storage\CourseCoverStorage;
use DateTimeImmutable;
use DateTimeZone;

/**
 * Stores and reads course cover images. Public reads never expose the object key.
 */
final class CourseCoverService
{
    public function __construct(
        private readonly CourseAdminAccessGuard $access,
        private readonly CatalogueService $catalogue,
        private readonly \Academy\Domain\Courses\CourseRepository $courses,
        private readonly CourseCoverStorage $storage,
        private readonly CourseCoverPolicy $policy,
        private readonly AuditService $audit,
    ) {
    }

    public function limitMegabytes(): string
    {
        return $this->policy->limitMegabytes();
    }

    public function uploadLimitMessage(): string
    {
        return $this->policy->uploadLimitMessage();
    }

    public function replace(AuthContext $auth, int $courseId, string $bytes, ?string $originalFilename): void
    {
        $at = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $course = $this->access->requireCourseInScope($auth, $courseId, $at);
        $this->access->requirePermission($auth, 'course.version.edit');

        $checked = $this->policy->assertImage($bytes);
        $filename = $this->policy->displayFilename($originalFilename);
        $objectKey = CourseCoverPolicy::PREFIX . bin2hex(random_bytes(16));
        $previousPresent = $course->hasCover() ? 1 : 0;
        $previousKey = $course->coverObjectKey;

        $this->storage->putObject($objectKey, $bytes, $checked['mime']);
        try {
            $this->courses->updateCover($courseId, $objectKey, $filename, $checked['mime'], $checked['bytes']);
        } catch (\Throwable $exception) {
            $this->storage->deleteObject($objectKey);
            throw $exception;
        }

        $this->audit->record(
            new CoursesAuditPayload(
                action: 'course.cover_updated',
                entityType: 'course',
                entityId: (string) $courseId,
                previous: ['cover_present' => $previousPresent],
                next: [
                    'cover_present' => 1,
                    'cover_mime' => $checked['mime'],
                    'cover_bytes' => $checked['bytes'],
                ],
            ),
            actorType: 'user',
            actorUserId: $auth->userId,
            source: 'course_admin',
        );

        if ($previousKey !== null && $previousKey !== $objectKey) {
            try {
                $this->storage->deleteObject($previousKey);
            } catch (\Throwable) {
                // The new image is already the public cover. A leftover object is not shown.
            }
        }
    }

    /**
     * @return array{mime: string, bytes: string}
     */
    public function readPublic(string $slug): array
    {
        try {
            $found = $this->catalogue->getPublishedCourseBySlug($slug);
        } catch (NotFoundException) {
            throw new NotFoundException('Course image not found.');
        }

        return $this->readStored($found['course']);
    }

    /**
     * @return array{mime: string, bytes: string}
     */
    public function readForAdmin(AuthContext $auth, int $courseId): array
    {
        $course = $this->access->requireCourseInScope($auth, $courseId, new DateTimeImmutable('now', new DateTimeZone('UTC')));

        return $this->readStored($course);
    }

    /**
     * @return array{mime: string, bytes: string}
     */
    private function readStored(\Academy\Domain\Courses\Course $course): array
    {
        if (!$course->hasCover() || $course->coverObjectKey === null || $course->coverMime === null) {
            throw new NotFoundException('Course image not found.');
        }
        if (!in_array($course->coverMime, CourseCoverPolicy::ALLOWED_MIMES, true)) {
            throw new NotFoundException('Course image not found.');
        }

        try {
            $bytes = $this->storage->readObject($course->coverObjectKey);
        } catch (\Throwable) {
            throw new NotFoundException('Course image not found.');
        }
        if ($bytes === '') {
            throw new NotFoundException('Course image not found.');
        }

        return ['mime' => $course->coverMime, 'bytes' => $bytes];
    }
}
