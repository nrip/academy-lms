<?php

declare(strict_types=1);

namespace Academy\Application\Assessments;

use Academy\Application\RBAC\AuthorizationService;
use Academy\Domain\Assessments\Assessment;
use Academy\Domain\Assessments\AssessmentRepository;
use Academy\Domain\Courses\ContentItem;
use Academy\Domain\Courses\ContentItemRepository;
use Academy\Domain\Courses\ContentItemType;
use Academy\Domain\Courses\ModuleRepository;
use Academy\Domain\Exception\AuthenticationException;
use Academy\Domain\Exception\ConflictException;
use Academy\Domain\Exception\NotFoundException;
use Academy\Domain\Learning\ContentProgressRepository;
use Academy\Domain\Learning\Enrolment;
use Academy\Domain\Learning\EnrolmentRepository;
use Academy\Domain\Learning\ModuleReleasePolicy;
use Academy\Domain\Learning\PlayerAccessPolicy;
use Academy\Domain\Security\AuthContext;

/**
 * Shared Active-enrolment + curriculum gates for assessment runtime.
 */
final class AssessmentAttemptAccessGuard
{
    public function __construct(
        private readonly AuthorizationService $authorization,
        private readonly EnrolmentRepository $enrolments,
        private readonly AssessmentRepository $assessments,
        private readonly ContentItemRepository $contentItems,
        private readonly ModuleRepository $modules,
        private readonly ContentProgressRepository $progress,
        private readonly PlayerAccessPolicy $playerAccess,
        private readonly ModuleReleasePolicy $releasePolicy,
    ) {
    }

    /**
     * @return array{enrolment: Enrolment, assessment: Assessment, content: ContentItem, user_id: int}
     */
    public function requireAccessibleAssessment(
        AuthContext $auth,
        int $enrolmentId,
        int $assessmentId,
    ): array {
        $userId = $this->requireUser($auth);
        $this->authorization->require($auth, 'learning.content.access');
        $this->authorization->require($auth, 'assessment.attempt.own');

        $enrolment = $this->enrolments->findById($enrolmentId);
        if ($enrolment === null) {
            throw new NotFoundException('Enrolment not found.');
        }
        $this->playerAccess->assertCanAccessContent($enrolment, $userId);

        $assessment = $this->assessments->findById($assessmentId);
        if ($assessment === null) {
            throw new NotFoundException('Assessment not found.');
        }

        $content = $this->contentItems->findById($assessment->contentId);
        if ($content === null || $content->contentType !== ContentItemType::MCQ_ASSESSMENT) {
            throw new NotFoundException('Assessment content item not found.');
        }

        $context = $this->contentItems->findContextById($content->contentId);
        if ($context === null || $context->courseVersionId !== $enrolment->courseVersionId) {
            throw new NotFoundException('Assessment is not part of this enrolment CourseVersion.');
        }

        $modules = $this->modules->listByCourseVersionId($enrolment->courseVersionId);
        $items = $this->contentItems->listByCourseVersionId($enrolment->courseVersionId);
        $progressMap = [];
        foreach ($this->progress->listByEnrolmentId($enrolmentId) as $row) {
            $progressMap[$row->contentId] = $row;
        }
        if (!$this->releasePolicy->isContentAccessible($content, $modules, $items, $progressMap)) {
            throw new ConflictException('This assessment is locked until prior mandatory items are completed.');
        }

        return [
            'enrolment' => $enrolment,
            'assessment' => $assessment,
            'content' => $content,
            'user_id' => $userId,
        ];
    }

    /**
     * @return array{enrolment: Enrolment, user_id: int}
     */
    public function requireOwnedEnrolmentForAttempt(AuthContext $auth, int $enrolmentId): array
    {
        $userId = $this->requireUser($auth);
        $this->authorization->require($auth, 'learning.content.access');
        $this->authorization->require($auth, 'assessment.attempt.own');

        $enrolment = $this->enrolments->findById($enrolmentId);
        if ($enrolment === null) {
            throw new NotFoundException('Enrolment not found.');
        }
        $this->playerAccess->assertCanAccessContent($enrolment, $userId);

        return ['enrolment' => $enrolment, 'user_id' => $userId];
    }

    private function requireUser(AuthContext $auth): int
    {
        if ($auth->userId === null) {
            throw new AuthenticationException('Authentication required.');
        }

        return $auth->userId;
    }
}
