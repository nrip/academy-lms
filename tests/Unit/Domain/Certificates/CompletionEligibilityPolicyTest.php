<?php

declare(strict_types=1);

namespace Academy\Tests\Unit\Domain\Certificates;

use Academy\Domain\Certificates\CertificateLearnerNameResolver;
use Academy\Domain\Certificates\CompletionEligibilityPolicy;
use Academy\Domain\Courses\ContentCompletionRule;
use Academy\Domain\Courses\ContentItem;
use Academy\Domain\Courses\ContentItemType;
use Academy\Domain\Identity\LearnerProfile;
use Academy\Domain\Learning\ContentProgress;
use Academy\Domain\Learning\ContentProgressCompletionStatus;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;

final class CompletionEligibilityPolicyTest extends TestCase
{
    public function testRequiresAllMandatoryItemsCompleted(): void
    {
        $policy = new CompletionEligibilityPolicy();
        $items = [
            $this->item(1, true),
            $this->item(2, true),
            $this->item(3, false),
        ];
        $progress = [
            1 => $this->progress(1, ContentProgressCompletionStatus::COMPLETED),
            2 => $this->progress(2, ContentProgressCompletionStatus::IN_PROGRESS),
        ];

        self::assertFalse($policy->isEligible($items, $progress));
        self::assertSame(['Lesson 2'], $policy->incompleteTitles($items, $progress));

        $progress[2] = $this->progress(2, ContentProgressCompletionStatus::COMPLETED);
        self::assertTrue($policy->isEligible($items, $progress));
    }

    public function testEmptyMandatoryIsNotEligible(): void
    {
        $policy = new CompletionEligibilityPolicy();
        self::assertFalse($policy->isEligible([$this->item(1, false)], []));
    }

    public function testNameResolverPrefersCertificateNameThenFullName(): void
    {
        $resolver = new CertificateLearnerNameResolver();
        $at = new DateTimeImmutable('now', new DateTimeZone('UTC'));

        $withCert = new LearnerProfile(
            learnerProfileId: 1,
            userId: 1,
            firstName: 'A',
            middleName: null,
            lastName: 'B',
            preferredDisplayName: 'Nick',
            certificateName: 'Dr Cert Name',
            certificateNameConfirmed: false,
            dateOfBirth: null,
            gender: null,
            nationality: null,
            addressLine1: null,
            addressLine2: null,
            city: null,
            state: null,
            postalCode: null,
            country: null,
            alternateMobile: null,
            profession: null,
            speciality: null,
            currentDesignation: null,
            organizationName: null,
            yearsOfExperience: null,
            medicalCouncilName: null,
            medicalCouncilRegistrationNumber: null,
            medicalCouncilRegistrationState: null,
            registrationValidFrom: null,
            registrationValidUntil: null,
            rowVersion: 1,
            createdAt: $at,
            updatedAt: $at,
        );
        self::assertSame('Dr Cert Name', $resolver->resolve($withCert));

        $fullOnly = new LearnerProfile(
            learnerProfileId: 2,
            userId: 2,
            firstName: 'Jane',
            middleName: 'Q',
            lastName: 'Public',
            preferredDisplayName: null,
            certificateName: null,
            certificateNameConfirmed: false,
            dateOfBirth: null,
            gender: null,
            nationality: null,
            addressLine1: null,
            addressLine2: null,
            city: null,
            state: null,
            postalCode: null,
            country: null,
            alternateMobile: null,
            profession: null,
            speciality: null,
            currentDesignation: null,
            organizationName: null,
            yearsOfExperience: null,
            medicalCouncilName: null,
            medicalCouncilRegistrationNumber: null,
            medicalCouncilRegistrationState: null,
            registrationValidFrom: null,
            registrationValidUntil: null,
            rowVersion: 1,
            createdAt: $at,
            updatedAt: $at,
        );
        self::assertSame('Jane Q Public', $resolver->resolve($fullOnly));
        self::assertNull($resolver->resolve(null));
    }

    private function item(int $id, bool $mandatory): ContentItem
    {
        $at = new DateTimeImmutable('now', new DateTimeZone('UTC'));

        return new ContentItem(
            contentId: $id,
            moduleId: 1,
            sequence: $id,
            contentType: ContentItemType::TEXT_LESSON,
            title: 'Lesson ' . $id,
            bodyText: 'Body',
            objectKey: null,
            videoUrl: null,
            videoDeliveryMode: null,
            videoProvider: null,
            mandatoryFlag: $mandatory,
            completionRule: ContentCompletionRule::MARK_COMPLETE,
            createdAt: $at,
            updatedAt: $at,
        );
    }

    private function progress(int $contentId, string $status): ContentProgress
    {
        $at = new DateTimeImmutable('now', new DateTimeZone('UTC'));

        return new ContentProgress(
            progressId: $contentId,
            enrolmentId: 1,
            contentId: $contentId,
            completionStatus: $status,
            resumePosition: null,
            watchPercentage: null,
            firstAccessedAt: $at,
            lastAccessedAt: $at,
            completedAt: $status === ContentProgressCompletionStatus::COMPLETED ? $at : null,
            manualOverrideFlag: false,
            completionSource: null,
            rowVersion: 1,
            createdAt: $at,
            updatedAt: $at,
        );
    }
}
