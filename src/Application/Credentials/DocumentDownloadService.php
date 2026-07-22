<?php

declare(strict_types=1);

namespace Academy\Application\Credentials;

use Academy\Application\RBAC\AuthorizationService;
use Academy\Domain\Admissions\ApplicationRepository;
use Academy\Domain\Credentials\DocumentScanStatus;
use Academy\Domain\Credentials\DocumentSubmissionRepository;
use Academy\Domain\Credentials\DocumentSubmissionStatus;
use Academy\Domain\Exception\AuthenticationException;
use Academy\Domain\Exception\AuthorizationException;
use Academy\Domain\Exception\DomainRuleException;
use Academy\Domain\Exception\NotFoundException;
use Academy\Domain\Security\AuthContext;
use Academy\Domain\Storage\ObjectStorage;
use DateTimeImmutable;
use DateTimeZone;

final class DocumentDownloadService
{
    public function __construct(
        private readonly AuthorizationService $authorization,
        private readonly ApplicationRepository $applications,
        private readonly DocumentSubmissionRepository $submissions,
        private readonly ObjectStorage $storage,
        private readonly int $downloadTtlSeconds,
    ) {
    }

    /**
     * @return array{url: string}
     */
    public function getOwnSignedDownloadUrl(AuthContext $auth, int $applicationId, int $submissionId): array
    {
        if ($this->authorization->check($auth, 'finance.payment.view')
            && !$this->authorization->check($auth, 'document.view_own')
            && !$this->authorization->check($auth, 'document.signed_url.generate')
        ) {
            throw new AuthorizationException('Forbidden.');
        }

        $this->authorization->require($auth, 'document.view_own');
        $userId = $this->requireUserId($auth);

        $application = $this->applications->findById($applicationId);
        if ($application === null || $application->userId !== $userId) {
            throw new NotFoundException('Document not found.');
        }

        $submission = $this->submissions->findById($submissionId);
        if ($submission === null || $submission->applicationId !== $applicationId) {
            throw new NotFoundException('Document not found.');
        }

        if ($submission->status === DocumentSubmissionStatus::FAILED_SECURITY_SCAN
            || $submission->scanStatus === DocumentScanStatus::FAILED
        ) {
            throw new DomainRuleException('This document is quarantined and cannot be downloaded.');
        }

        if ($submission->scanStatus !== DocumentScanStatus::CLEAN) {
            throw new DomainRuleException('Document is not available until the security scan completes.');
        }

        $expiresAt = (new DateTimeImmutable('now', new DateTimeZone('UTC')))
            ->modify('+' . $this->downloadTtlSeconds . ' seconds');
        $issued = $this->storage->issueDownloadUrl($submission->objectKey, $expiresAt);

        return [
            'url' => $issued['download_url'],
        ];
    }

    private function requireUserId(AuthContext $auth): int
    {
        if ($auth->userId === null) {
            throw new AuthenticationException('Authentication required.');
        }

        return $auth->userId;
    }
}
