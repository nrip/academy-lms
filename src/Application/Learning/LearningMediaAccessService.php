<?php

declare(strict_types=1);

namespace Academy\Application\Learning;

use Academy\Application\RBAC\AuthorizationService;
use Academy\Domain\Courses\ContentItemRepository;
use Academy\Domain\Courses\ContentItemType;
use Academy\Domain\Courses\ModuleRepository;
use Academy\Domain\Exception\AuthenticationException;
use Academy\Domain\Exception\ConflictException;
use Academy\Domain\Exception\NotFoundException;
use Academy\Domain\Exception\ValidationException;
use Academy\Domain\Learning\ContentProgressRepository;
use Academy\Domain\Learning\EnrolmentRepository;
use Academy\Domain\Learning\ModuleReleasePolicy;
use Academy\Domain\Learning\PlayerAccessPolicy;
use Academy\Domain\Security\AuthContext;
use Academy\Infrastructure\Storage\LearningMediaStorage;
use DateTimeImmutable;
use DateTimeZone;

/**
 * Issues a short-lived playback URL after enrolment and release checks.
 * The URL is not stored. Credential-document keys are rejected.
 */
final class LearningMediaAccessService
{
    public function __construct(
        private readonly AuthorizationService $authorization,
        private readonly EnrolmentRepository $enrolments,
        private readonly ModuleRepository $modules,
        private readonly ContentItemRepository $contentItems,
        private readonly ContentProgressRepository $progress,
        private readonly PlayerAccessPolicy $accessPolicy,
        private readonly ModuleReleasePolicy $releasePolicy,
        private readonly LearningMediaStorage $storage,
    ) {
    }

    /**
     * @return array{download_url: string, expires_at: DateTimeImmutable}
     */
    public function issuePlaybackUrl(AuthContext $auth, int $enrolmentId, int $contentId): array
    {
        if ($auth->userId === null) {
            throw new AuthenticationException('Authentication required.');
        }
        $this->authorization->require($auth, 'learning.content.access');

        $enrolment = $this->enrolments->findById($enrolmentId);
        if ($enrolment === null) {
            throw new NotFoundException('Enrolment not found.');
        }
        $this->accessPolicy->assertCanAccessContent($enrolment, $auth->userId);

        $context = $this->contentItems->findContextById($contentId);
        if ($context === null || $context->courseVersionId !== $enrolment->courseVersionId) {
            throw new NotFoundException('Content item not found on this CourseVersion.');
        }
        $item = $context->contentItem;
        if (!in_array($item->contentType, [ContentItemType::PDF, ContentItemType::VIDEO, ContentItemType::AUDIO], true)) {
            throw new ValidationException('This lesson has no private media file.');
        }
        if ($item->objectKey === null || !str_starts_with($item->objectKey, 'learning/media/')) {
            throw new ValidationException('This lesson media is not in learning storage.');
        }

        $modules = $this->modules->listByCourseVersionId($enrolment->courseVersionId);
        $items = $this->contentItems->listByCourseVersionId($enrolment->courseVersionId);
        $progressByContentId = [];
        foreach ($this->progress->listByEnrolmentId($enrolmentId) as $row) {
            $progressByContentId[$row->contentId] = $row;
        }
        if (!$this->releasePolicy->isContentAccessible($item, $modules, $items, $progressByContentId)) {
            throw new ConflictException('This content is locked until prior mandatory items are completed.');
        }

        $expiresAt = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->modify('+15 minutes');

        return $this->storage->issueDownloadUrl($item->objectKey, $expiresAt);
    }
}
