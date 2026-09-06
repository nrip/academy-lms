<?php

declare(strict_types=1);

namespace Academy\Application\Certificates;

use Academy\Application\RBAC\AuthorizationService;
use Academy\Domain\Certificates\Certificate;
use Academy\Domain\Certificates\CertificateLearnerNameResolver;
use Academy\Domain\Certificates\CertificateRepository;
use Academy\Domain\Certificates\CertificateType;
use Academy\Domain\Certificates\CompletionEligibilityPolicy;
use Academy\Domain\Courses\ContentItemRepository;
use Academy\Domain\Exception\AuthenticationException;
use Academy\Domain\Exception\AuthorizationException;
use Academy\Domain\Exception\NotFoundException;
use Academy\Domain\Identity\LearnerProfileRepository;
use Academy\Domain\Learning\ContentProgressRepository;
use Academy\Domain\Learning\EnrolmentRepository;
use Academy\Domain\Learning\PlayerAccessPolicy;
use Academy\Domain\Security\AuthContext;

final class CertificateQueryService
{
    public function __construct(
        private readonly AuthorizationService $authorization,
        private readonly EnrolmentRepository $enrolments,
        private readonly CertificateRepository $certificates,
        private readonly ContentItemRepository $contentItems,
        private readonly ContentProgressRepository $progress,
        private readonly LearnerProfileRepository $profiles,
        private readonly CompletionEligibilityPolicy $eligibility,
        private readonly CertificateLearnerNameResolver $nameResolver,
        private readonly CertificateIssuanceService $issuance,
        private readonly PlayerAccessPolicy $playerAccess,
    ) {
    }

    public function listForEnrolment(AuthContext $auth, int $enrolmentId): CertificateListView
    {
        $userId = $this->requireUser($auth);
        $this->authorization->require($auth, 'certificate.view_own');
        $enrolment = $this->enrolments->findById($enrolmentId);
        if ($enrolment === null) {
            throw new NotFoundException('Enrolment not found.');
        }
        $this->playerAccess->assertCanViewOutline($enrolment, $userId);

        $issued = $this->issuance->issueCompletionIfEligible($enrolmentId, $userId);
        $certificates = $this->certificates->listByEnrolmentId($enrolmentId);

        $items = $this->contentItems->listByCourseVersionId($enrolment->courseVersionId);
        $progressMap = [];
        foreach ($this->progress->listByEnrolmentId($enrolmentId) as $row) {
            $progressMap[$row->contentId] = $row;
        }
        $eligible = $this->eligibility->isEligible($items, $progressMap);
        $incomplete = $this->eligibility->incompleteTitles($items, $progressMap);
        $name = $this->nameResolver->resolve($this->profiles->findByUserId($enrolment->userId));

        $message = null;
        if ($issued === null && $eligible && $name === null) {
            $message = 'Course requirements are complete, but a certificate name is missing. Add your name on your profile, then return here.';
        } elseif ($issued === null && !$eligible) {
            $message = 'Certificate unlocks when all mandatory content and assessments are completed.';
        }

        return new CertificateListView(
            enrolmentId: $enrolmentId,
            certificates: $certificates,
            eligible: $eligible,
            incompleteTitles: $incomplete,
            message: $message,
        );
    }

    public function getOwnedCertificate(AuthContext $auth, int $certificateId): Certificate
    {
        $userId = $this->requireUser($auth);
        $this->authorization->require($auth, 'certificate.view_own');
        $certificate = $this->certificates->findById($certificateId);
        if ($certificate === null) {
            throw new NotFoundException('Certificate not found.');
        }
        $enrolment = $this->enrolments->findById($certificate->enrolmentId);
        if ($enrolment === null) {
            throw new NotFoundException('Enrolment not found.');
        }
        if (!$enrolment->belongsToUser($userId)) {
            throw new AuthorizationException('Certificate is not owned by the authenticated user.');
        }

        return $certificate;
    }

    public function findPublicByNumber(string $certificateNumber): ?PublicCertificateView
    {
        $certificate = $this->certificates->findByNumber($certificateNumber);
        if ($certificate === null) {
            return null;
        }

        return new PublicCertificateView(
            certificateNumber: $certificate->certificateNumber,
            learnerName: $certificate->learnerNameSnapshot,
            courseTitle: $certificate->courseTitleSnapshot,
            certificateType: $certificate->certificateType,
            certificateLabel: $certificate->certificateLabel,
            status: $certificate->status,
            issuedAt: $certificate->issuedAt,
            isValid: $certificate->isActive(),
        );
    }

    public function hasCurrentCompletion(int $enrolmentId): bool
    {
        return $this->certificates->findCurrentByEnrolmentAndType(
            $enrolmentId,
            CertificateType::COMPLETION,
        ) !== null;
    }

    private function requireUser(AuthContext $auth): int
    {
        if ($auth->userId === null) {
            throw new AuthenticationException('Authentication required.');
        }

        return $auth->userId;
    }
}
