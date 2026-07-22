<?php

declare(strict_types=1);

namespace Academy\Application\Courses;

use Academy\Domain\Courses\Batch;
use Academy\Domain\Courses\BatchAvailabilityEvaluator;
use Academy\Domain\Courses\BatchRepository;
use Academy\Domain\Courses\Course;
use Academy\Domain\Courses\CourseDocumentRequirementRepository;
use Academy\Domain\Courses\CourseRepository;
use Academy\Domain\Courses\CourseVersion;
use Academy\Domain\Courses\CourseVersionRepository;
use Academy\Domain\Courses\EligibilityRuleRepository;
use Academy\Domain\Exception\NotFoundException;

/**
 * Public read-only catalogue (WP-02). No authentication/authorization —
 * catalogue, course detail and batch list are public per
 * docs/product/WP02_IMPLEMENTATION_NOTE.md "Public routes". Unpublished /
 * unlocked / retired content is filtered out rather than surfaced as 403,
 * so it is indistinguishable from "does not exist" (no leak of draft state).
 */
final class CatalogueService
{
    public function __construct(
        private readonly CourseRepository $courses,
        private readonly CourseVersionRepository $versions,
        private readonly BatchRepository $batches,
        private readonly EligibilityRuleRepository $eligibilityRules,
        private readonly CourseDocumentRequirementRepository $documentRequirements,
        private readonly BatchAvailabilityEvaluator $availability,
    ) {
    }

    /**
     * @return list<array{course: Course, version: CourseVersion}>
     */
    public function listPublishedCourses(): array
    {
        $result = [];
        foreach ($this->courses->listActive() as $course) {
            $version = $this->publishedVersionFor($course);
            if ($version === null) {
                continue;
            }
            $result[] = ['course' => $course, 'version' => $version];
        }

        return $result;
    }

    /**
     * @return array{course: Course, version: CourseVersion}
     * @throws NotFoundException
     */
    public function getPublishedCourseBySlug(string $slug): array
    {
        $course = $this->courses->findBySlug($slug);
        if ($course === null || !$course->isActive()) {
            throw new NotFoundException('Course not found.');
        }

        $version = $this->publishedVersionFor($course);
        if ($version === null) {
            throw new NotFoundException('Course not found.');
        }

        return ['course' => $course, 'version' => $version];
    }

    /**
     * @return array{
     *   course: Course,
     *   version: CourseVersion,
     *   eligibilityRules: list<\Academy\Domain\Courses\EligibilityRule>,
     *   documentRequirements: list<\Academy\Domain\Courses\CourseDocumentRequirement>,
     *   batches: list<array{batch: Batch, availability: \Academy\Domain\Courses\BatchAvailability}>
     * }
     * @throws NotFoundException
     */
    public function getCourseDetail(string $slug): array
    {
        $found = $this->getPublishedCourseBySlug($slug);

        return [
            'course' => $found['course'],
            'version' => $found['version'],
            'eligibilityRules' => $this->eligibilityRules->listByCourseVersionId($found['version']->versionId),
            'documentRequirements' => $this->documentRequirements->listByCourseVersionId($found['version']->versionId),
            'batches' => $this->batchesWithAvailability($found['course'], $found['version']),
        ];
    }

    /**
     * @return array{
     *   course: Course,
     *   version: CourseVersion,
     *   batches: list<array{batch: Batch, availability: \Academy\Domain\Courses\BatchAvailability}>
     * }
     * @throws NotFoundException
     */
    public function listBatchesForCourseSlug(string $slug): array
    {
        $found = $this->getPublishedCourseBySlug($slug);

        return [
            'course' => $found['course'],
            'version' => $found['version'],
            'batches' => $this->batchesWithAvailability($found['course'], $found['version']),
        ];
    }

    /**
     * @return array{
     *   batch: Batch,
     *   version: CourseVersion,
     *   course: Course,
     *   availability: \Academy\Domain\Courses\BatchAvailability
     * }
     * @throws NotFoundException
     */
    public function getBatch(int $batchId): array
    {
        $batch = $this->batches->findById($batchId);
        if ($batch === null) {
            throw new NotFoundException('Batch not found.');
        }

        $version = $this->versions->findById($batch->courseVersionId);
        if ($version === null || !$version->isPublished() || !$version->isLocked()) {
            throw new NotFoundException('Batch not found.');
        }

        $course = $this->courses->findById($version->courseId);
        if ($course === null || !$course->isActive()) {
            throw new NotFoundException('Batch not found.');
        }

        return [
            'batch' => $batch,
            'version' => $version,
            'course' => $course,
            'availability' => $this->availability->evaluate($course, $version, $batch),
        ];
    }

    private function publishedVersionFor(Course $course): ?CourseVersion
    {
        if ($course->currentPublishedVersionId === null) {
            return null;
        }

        $version = $this->versions->findById($course->currentPublishedVersionId);
        if ($version === null || !$version->isPublished() || !$version->isLocked()) {
            return null;
        }

        return $version;
    }

    /**
     * @return list<array{batch: Batch, availability: \Academy\Domain\Courses\BatchAvailability}>
     */
    private function batchesWithAvailability(Course $course, CourseVersion $version): array
    {
        $result = [];
        foreach ($this->batches->listByCourseVersionId($version->versionId) as $batch) {
            $result[] = [
                'batch' => $batch,
                'availability' => $this->availability->evaluate($course, $version, $batch),
            ];
        }

        return $result;
    }
}
