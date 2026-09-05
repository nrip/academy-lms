<?php

declare(strict_types=1);

namespace Academy\Application\Learning;

use Academy\Application\RBAC\AuthorizationService;
use Academy\Domain\Courses\ContentItemRepository;
use Academy\Domain\Courses\ContentItemType;
use Academy\Domain\Courses\CourseRepository;
use Academy\Domain\Courses\CourseVersionRepository;
use Academy\Domain\Courses\ModuleRepository;
use Academy\Domain\Exception\AuthenticationException;
use Academy\Domain\Exception\NotFoundException;
use Academy\Domain\Learning\ContentProgress;
use Academy\Domain\Learning\ContentProgressCompletionStatus;
use Academy\Domain\Learning\ContentProgressRepository;
use Academy\Domain\Learning\EnrolmentLifecycleStatus;
use Academy\Domain\Learning\EnrolmentRepository;
use Academy\Domain\Learning\ModuleReleasePolicy;
use Academy\Domain\Learning\PlayerAccessPolicy;
use Academy\Domain\Security\AuthContext;
use DateTimeImmutable;
use DateTimeZone;

final class LearnerPlayerQueryService
{
    public function __construct(
        private readonly AuthorizationService $authorization,
        private readonly EnrolmentRepository $enrolments,
        private readonly CourseRepository $courses,
        private readonly CourseVersionRepository $courseVersions,
        private readonly ModuleRepository $modules,
        private readonly ContentItemRepository $contentItems,
        private readonly ContentProgressRepository $progress,
        private readonly PlayerAccessPolicy $accessPolicy,
        private readonly ModuleReleasePolicy $releasePolicy,
    ) {
    }

    public function outline(AuthContext $auth, int $enrolmentId): LearnerPlayerOutlineView
    {
        $userId = $this->requireUser($auth);
        $this->authorization->require($auth, 'learning.content.access');

        $enrolment = $this->enrolments->findById($enrolmentId);
        if ($enrolment === null) {
            throw new NotFoundException('Enrolment not found.');
        }
        $this->accessPolicy->assertCanViewOutline($enrolment, $userId);

        $course = $this->courses->findById($enrolment->courseId);
        $version = $this->courseVersions->findById($enrolment->courseVersionId);
        if ($course === null || $version === null) {
            throw new NotFoundException('Course version not found for enrolment.');
        }

        $modules = $this->modules->listByCourseVersionId($enrolment->courseVersionId);
        $items = $this->contentItems->listByCourseVersionId($enrolment->courseVersionId);
        $progressByContentId = $this->progressMap($enrolmentId);

        $contentAccessible = $enrolment->lifecycleStatus === EnrolmentLifecycleStatus::ACTIVE;
        $accessMessage = null;
        if ($enrolment->lifecycleStatus === EnrolmentLifecycleStatus::SCHEDULED) {
            $accessMessage = 'Your enrolment is scheduled. Content unlocks when the batch starts and the enrolment becomes Active.';
        }

        $moduleViews = [];
        $completedCount = 0;
        $totalCount = count($items);

        foreach ($modules as $module) {
            $unlocked = $contentAccessible
                && $this->releasePolicy->isModuleUnlocked($module, $modules, $items, $progressByContentId);
            $itemViews = [];
            foreach ($items as $item) {
                if ($item->moduleId !== $module->moduleId) {
                    continue;
                }
                $progress = $progressByContentId[$item->contentId] ?? null;
                $completed = $progress !== null && $progress->isCompleted();
                if ($completed) {
                    ++$completedCount;
                }
                $accessible = $contentAccessible
                    && $this->releasePolicy->isContentAccessible($item, $modules, $items, $progressByContentId);
                $itemViews[] = new LearnerPlayerItemView(
                    item: $item,
                    accessible: $accessible,
                    completionStatus: $progress?->completionStatus ?? ContentProgressCompletionStatus::NOT_STARTED,
                    completed: $completed,
                );
            }
            $moduleViews[] = new LearnerPlayerModuleView($module, $unlocked, $itemViews);
        }

        return new LearnerPlayerOutlineView(
            enrolment: $enrolment,
            courseTitle: $course->masterTitle,
            versionTitle: $version->title,
            contentAccessible: $contentAccessible,
            accessMessage: $accessMessage,
            completedCount: $completedCount,
            totalCount: $totalCount,
            modules: $moduleViews,
        );
    }

    public function item(AuthContext $auth, int $enrolmentId, int $contentId): LearnerPlayerItemDetailView
    {
        $userId = $this->requireUser($auth);
        $this->authorization->require($auth, 'learning.content.access');

        $enrolment = $this->enrolments->findById($enrolmentId);
        if ($enrolment === null) {
            throw new NotFoundException('Enrolment not found.');
        }
        $this->accessPolicy->assertCanAccessContent($enrolment, $userId);

        $course = $this->courses->findById($enrolment->courseId);
        $version = $this->courseVersions->findById($enrolment->courseVersionId);
        if ($course === null || $version === null) {
            throw new NotFoundException('Course version not found for enrolment.');
        }

        $modules = $this->modules->listByCourseVersionId($enrolment->courseVersionId);
        $items = $this->contentItems->listByCourseVersionId($enrolment->courseVersionId);
        $target = null;
        foreach ($items as $item) {
            if ($item->contentId === $contentId) {
                $target = $item;
                break;
            }
        }
        if ($target === null) {
            throw new NotFoundException('Content item not found on this CourseVersion.');
        }

        $progressByContentId = $this->progressMap($enrolmentId);
        if (!$this->releasePolicy->isContentAccessible($target, $modules, $items, $progressByContentId)) {
            throw new \Academy\Domain\Exception\ConflictException(
                'This content is locked until prior mandatory items are completed.',
            );
        }

        $module = null;
        foreach ($modules as $candidate) {
            if ($candidate->moduleId === $target->moduleId) {
                $module = $candidate;
                break;
            }
        }
        if ($module === null) {
            throw new NotFoundException('Module not found for content item.');
        }

        $at = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $progress = $this->progress->recordAccess($enrolmentId, $contentId, $at);

        $canMarkComplete = in_array($target->contentType, [ContentItemType::TEXT_LESSON, ContentItemType::PDF], true)
            && !$progress->isCompleted();
        $blockedReason = null;
        if ($target->contentType === ContentItemType::MCQ_ASSESSMENT) {
            $blockedReason = 'Assessment attempts are not available in this work package.';
            $canMarkComplete = false;
        } elseif ($progress->isCompleted()) {
            $blockedReason = 'Already marked complete.';
            $canMarkComplete = false;
        }

        $previousContentId = null;
        $nextContentId = null;
        $found = false;
        foreach ($items as $item) {
            if ($item->contentId === $contentId) {
                $found = true;
                continue;
            }
            if (!$found) {
                $previousContentId = $item->contentId;
            } elseif ($nextContentId === null) {
                $nextContentId = $item->contentId;
            }
        }

        return new LearnerPlayerItemDetailView(
            enrolment: $enrolment,
            courseTitle: $course->masterTitle,
            versionTitle: $version->title,
            module: $module,
            item: $target,
            progress: $progress,
            canMarkComplete: $canMarkComplete,
            markCompleteBlockedReason: $blockedReason,
            previousContentId: $previousContentId,
            nextContentId: $nextContentId,
        );
    }

    /**
     * @return array<int, ContentProgress|null>
     */
    private function progressMap(int $enrolmentId): array
    {
        $map = [];
        foreach ($this->progress->listByEnrolmentId($enrolmentId) as $row) {
            $map[$row->contentId] = $row;
        }

        return $map;
    }

    private function requireUser(AuthContext $auth): int
    {
        if ($auth->userId === null) {
            throw new AuthenticationException('Authentication required.');
        }

        return $auth->userId;
    }
}
