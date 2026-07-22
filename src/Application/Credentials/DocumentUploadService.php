<?php

declare(strict_types=1);

namespace Academy\Application\Credentials;

use Academy\Application\Audit\AuditService;
use Academy\Application\RBAC\AuthorizationService;
use Academy\Domain\Admissions\ApplicationRepository;
use Academy\Domain\Audit\DocumentAuditPayload;
use Academy\Domain\Courses\CourseDocumentRequirementRepository;
use Academy\Domain\Credentials\DocumentFileValidator;
use Academy\Domain\Credentials\DocumentObjectKeyGenerator;
use Academy\Domain\Credentials\DocumentScanStatus;
use Academy\Domain\Credentials\DocumentSubmission;
use Academy\Domain\Credentials\DocumentSubmissionRepository;
use Academy\Domain\Credentials\DocumentSubmissionStatus;
use Academy\Domain\Credentials\DocumentSubmissionWrite;
use Academy\Domain\Credentials\DocumentUploadAuthorizationRepository;
use Academy\Domain\Exception\AuthenticationException;
use Academy\Domain\Exception\ConflictException;
use Academy\Domain\Exception\DomainRuleException;
use Academy\Domain\Exception\NotFoundException;
use Academy\Domain\Exception\ValidationException;
use Academy\Domain\Outbox\DocumentOutboxEventTypes;
use Academy\Domain\Outbox\OutboxWriter;
use Academy\Domain\Security\AuthContext;
use Academy\Domain\Storage\ObjectStorage;
use Academy\Infrastructure\Database\TransactionManager;
use DateTimeImmutable;
use DateTimeZone;
use PDOException;

final class DocumentUploadService
{
    public function __construct(
        private readonly TransactionManager $transactions,
        private readonly AuthorizationService $authorization,
        private readonly ApplicationRepository $applications,
        private readonly CourseDocumentRequirementRepository $requirements,
        private readonly DocumentSubmissionRepository $submissions,
        private readonly DocumentUploadAuthorizationRepository $authorizations,
        private readonly ObjectStorage $storage,
        private readonly DocumentFileValidator $fileValidator,
        private readonly DocumentObjectKeyGenerator $objectKeyGenerator,
        private readonly OutboxWriter $outbox,
        private readonly AuditService $audit,
        private readonly int $uploadTtlSeconds,
        private readonly bool $localUploadUrlOverride,
    ) {
    }

    public function authorizeUpload(
        AuthContext $auth,
        int $applicationId,
        int $requirementId,
        string $filename,
        string $mimeType,
        int $sizeBytes,
    ): UploadAuthorizationResult {
        $this->authorization->require($auth, 'document.upload_own');
        $userId = $this->requireUserId($auth);
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));

        $application = $this->applications->findById($applicationId);
        if ($application === null || $application->userId !== $userId) {
            throw new NotFoundException('Application not found.');
        }
        $current = $this->submissions->findCurrentForRequirement($applicationId, $requirementId);
        $this->assertLearnerMayManageDocument($application, $current);

        $requirement = $this->findRequirementForApplication($application->courseVersionId, $requirementId);
        $sanitized = $this->fileValidator->assertAllowed($requirement, $mimeType, $sizeBytes, $filename);

        $objectKey = $this->objectKeyGenerator->generate($applicationId, $requirementId, $sanitized);
        $expiresAt = $now->modify('+' . $this->uploadTtlSeconds . ' seconds');

        $authorization = $this->authorizations->insert(
            $applicationId,
            $requirementId,
            $userId,
            $objectKey,
            $sanitized,
            strtolower($mimeType),
            min($sizeBytes, $requirement->maxSizeBytes),
            $expiresAt,
            $now,
        );

        $issued = $this->storage->issueUploadAuthorization(
            $objectKey,
            strtolower($mimeType),
            $authorization->maxSizeBytes,
            $expiresAt,
        );

        $uploadUrl = $this->localUploadUrlOverride
            ? '/applications/' . $applicationId . '/documents/local-upload/' . $authorization->authorizationId
            : $issued['upload_url'];

        $this->audit->record(
            new DocumentAuditPayload(
                action: 'document.upload_authorized',
                entityType: 'document_upload_authorization',
                entityId: (string) $authorization->authorizationId,
                next: [
                    'user_id' => $userId,
                    'application_id' => $applicationId,
                    'requirement_id' => $requirementId,
                    'authorization_id' => $authorization->authorizationId,
                    'result' => 'ok',
                ],
            ),
            actorType: 'user',
            actorUserId: $userId,
            source: 'documents',
        );

        return new UploadAuthorizationResult(
            authorizationId: $authorization->authorizationId,
            requirementId: $requirementId,
            objectKey: $objectKey,
            uploadUrl: $uploadUrl,
            method: $issued['method'],
            headers: $issued['headers'],
            expiresAt: $expiresAt,
        );
    }

    public function confirmUpload(
        AuthContext $auth,
        int $applicationId,
        int $requirementId,
        string $objectKey,
        string $checksumSha256,
    ): DocumentSubmission {
        $this->authorization->require($auth, 'document.upload_own');
        $userId = $this->requireUserId($auth);

        if (!preg_match('/^[a-f0-9]{64}$/', strtolower($checksumSha256))) {
            throw new ValidationException('Please provide valid document details.', [
                'checksum_sha256' => ['Checksum must be a SHA-256 hex digest.'],
            ]);
        }

        $metadata = $this->storage->objectExists($objectKey);
        if ($metadata === null) {
            throw new ValidationException('Please provide valid document details.', [
                'object_key' => ['Uploaded object was not found.'],
            ]);
        }
        if ($metadata->checksumSha256 !== null && strtolower($metadata->checksumSha256) !== strtolower($checksumSha256)) {
            throw new ValidationException('Please provide valid document details.', [
                'checksum_sha256' => ['Checksum mismatch.'],
            ]);
        }

        $sizeBytes = $metadata->sizeBytes;

        return $this->transactions->run(function () use (
            $userId,
            $applicationId,
            $requirementId,
            $objectKey,
            $checksumSha256,
            $sizeBytes,
        ): DocumentSubmission {
            $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));

            $application = $this->applications->findByIdForUpdate($applicationId);
            if ($application === null || $application->userId !== $userId) {
                throw new NotFoundException('Application not found.');
            }
            $authorization = $this->authorizations->findByObjectKeyForUpdate($objectKey);
            if ($authorization === null || $authorization->applicationId !== $applicationId
                || $authorization->userId !== $userId
            ) {
                throw new ValidationException('Please provide valid document details.', [
                    'object_key' => ['Upload authorization is invalid.'],
                ]);
            }
            if ($authorization->requirementId !== $requirementId) {
                throw new ValidationException('Please provide valid document details.', [
                    'requirement_id' => ['Requirement does not match upload authorization.'],
                ]);
            }
            if ($authorization->isConsumed()) {
                throw new ConflictException('Upload authorization was already consumed.');
            }
            if ($authorization->isExpired($now)) {
                throw new ValidationException('Please provide valid document details.', [
                    'object_key' => ['Upload authorization has expired.'],
                ]);
            }

            $mimeType = $authorization->declaredMimeType;
            if ($sizeBytes > $authorization->maxSizeBytes) {
                throw new ValidationException('Please provide valid document details.', [
                    'size_bytes' => ['File exceeds authorized size.'],
                ]);
            }

            $current = $this->submissions->lockCurrentForUpdate($applicationId, $authorization->requirementId);
            $this->assertLearnerMayManageDocument($application, $current);

            $requirement = $this->findRequirementForApplication(
                $application->courseVersionId,
                $authorization->requirementId,
            );
            $this->fileValidator->assertAllowed(
                $requirement,
                $mimeType,
                $sizeBytes,
                $authorization->displayFilename,
            );

            $oldId = null;
            if ($current !== null) {
                $oldId = $current->documentSubmissionId;
                if (!$this->submissions->supersedeCurrent($current->documentSubmissionId, $current->rowVersion, $now)) {
                    throw new ConflictException('Document was replaced concurrently.');
                }
            }

            try {
                $created = $this->submissions->insertCurrent(new DocumentSubmissionWrite(
                    applicationId: $applicationId,
                    requirementId: $authorization->requirementId,
                    objectKey: $objectKey,
                    displayFilename: $authorization->displayFilename,
                    mimeType: strtolower($mimeType),
                    sizeBytes: $sizeBytes,
                    checksumSha256: strtolower($checksumSha256),
                    status: DocumentSubmissionStatus::UPLOADED,
                    scanStatus: DocumentScanStatus::PENDING,
                    uploadedByUserId: $userId,
                    submittedAt: $now,
                    createdAt: $now,
                    scanQueuedAt: $now,
                ));
            } catch (PDOException $exception) {
                if ($this->isDuplicateKey($exception)) {
                    throw new ConflictException('A current document already exists for this requirement.');
                }
                throw $exception;
            }

            if (!$this->authorizations->markConsumed($authorization->authorizationId, $now)) {
                throw new ConflictException('Upload authorization was already consumed.');
            }

            $this->outbox->enqueue(
                DocumentOutboxEventTypes::DOCUMENT_SCAN_REQUESTED,
                'document_submission',
                (string) $created->documentSubmissionId,
                [
                    'document_submission_id' => $created->documentSubmissionId,
                    'application_id' => $applicationId,
                    'requirement_id' => $authorization->requirementId,
                ],
                DocumentOutboxEventTypes::DOCUMENT_SCAN_REQUESTED . ':' . $created->documentSubmissionId,
            );

            $this->audit->record(
                new DocumentAuditPayload(
                    action: $oldId === null ? 'document.upload_confirmed' : 'document.resubmitted',
                    entityType: 'document_submission',
                    entityId: (string) $created->documentSubmissionId,
                    previous: $oldId === null ? [] : [
                        'document_submission_id' => $oldId,
                        'status' => DocumentSubmissionStatus::SUPERSEDED,
                    ],
                    next: [
                        'user_id' => $userId,
                        'application_id' => $applicationId,
                        'requirement_id' => $authorization->requirementId,
                        'document_submission_id' => $created->documentSubmissionId,
                        'old_document_submission_id' => $oldId,
                        'status' => $created->status,
                        'scan_status' => $created->scanStatus,
                        'result' => 'ok',
                    ],
                ),
                actorType: 'user',
                actorUserId: $userId,
                source: 'documents',
            );

            $this->audit->record(
                new DocumentAuditPayload(
                    action: 'document.scan_queued',
                    entityType: 'document_submission',
                    entityId: (string) $created->documentSubmissionId,
                    next: [
                        'application_id' => $applicationId,
                        'requirement_id' => $authorization->requirementId,
                        'document_submission_id' => $created->documentSubmissionId,
                        'scan_status' => DocumentScanStatus::PENDING,
                        'result' => 'ok',
                    ],
                ),
                actorType: 'system',
                actorUserId: null,
                source: 'documents',
            );

            return $created;
        });
    }

    public function replaceUpload(
        AuthContext $auth,
        int $applicationId,
        int $requirementId,
        int $currentSubmissionId,
        string $filename,
        string $mimeType,
        int $sizeBytes,
    ): UploadAuthorizationResult {
        $this->authorization->require($auth, 'document.replace_own');
        $userId = $this->requireUserId($auth);

        $application = $this->applications->findById($applicationId);
        if ($application === null || $application->userId !== $userId) {
            throw new NotFoundException('Application not found.');
        }
        $existing = $this->submissions->findById($currentSubmissionId);
        if ($existing === null || $existing->applicationId !== $applicationId || !$existing->isCurrent()) {
            throw new NotFoundException('Document submission not found.');
        }
        if ($existing->requirementId !== $requirementId) {
            throw new ValidationException('Please provide valid document details.', [
                'requirement_id' => ['Requirement does not match the current submission.'],
            ]);
        }

        $this->assertLearnerMayManageDocument($application, $existing);

        return $this->authorizeUpload(
            $auth,
            $applicationId,
            $requirementId,
            $filename,
            $mimeType,
            $sizeBytes,
        );
    }

    /**
     * @return array{checksum_sha256: string, size_bytes: int, object_key: string}
     */
    public function receiveLocalUpload(
        AuthContext $auth,
        int $applicationId,
        int $authorizationId,
        string $contents,
    ): array {
        $this->authorization->require($auth, 'document.upload_own');
        $userId = $this->requireUserId($auth);

        $authorization = $this->transactions->run(function () use ($userId, $applicationId, $authorizationId, $contents): \Academy\Domain\Credentials\DocumentUploadAuthorization {
            $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));

            $application = $this->applications->findByIdForUpdate($applicationId);
            if ($application === null || $application->userId !== $userId) {
                throw new NotFoundException('Application not found.');
            }
            $authorization = $this->authorizations->findByIdForUpdate($authorizationId);
            if ($authorization === null || $authorization->applicationId !== $applicationId
                || $authorization->userId !== $userId
            ) {
                throw new NotFoundException('Upload authorization not found.');
            }
            if ($authorization->isConsumed()) {
                throw new ConflictException('Upload authorization was already consumed.');
            }
            if ($authorization->isExpired($now)) {
                throw new ValidationException('Please provide valid document details.', [
                    'authorization_id' => ['Upload authorization has expired.'],
                ]);
            }

            $current = $this->submissions->lockCurrentForUpdate($applicationId, $authorization->requirementId);
            $this->assertLearnerMayManageDocument($application, $current);

            $sizeBytes = strlen($contents);
            if ($sizeBytes <= 0) {
                throw new ValidationException('Please provide valid document details.', [
                    'size_bytes' => ['File size must be greater than zero.'],
                ]);
            }
            if ($sizeBytes > $authorization->maxSizeBytes) {
                throw new ValidationException('Please provide valid document details.', [
                    'size_bytes' => ['File exceeds authorized size.'],
                ]);
            }

            return $authorization;
        });

        $metadata = $this->storage->putObject(
            $authorization->objectKey,
            $contents,
            $authorization->declaredMimeType,
        );

        return [
            'checksum_sha256' => (string) $metadata->checksumSha256,
            'size_bytes' => $metadata->sizeBytes,
            'object_key' => $authorization->objectKey,
        ];
    }

    private function assertLearnerMayManageDocument(
        \Academy\Domain\Admissions\Application $application,
        ?DocumentSubmission $current,
    ): void {
        if ($application->isEditableByLearner()) {
            return;
        }

        if ($application->allowsLearnerDocumentCorrection()) {
            if ($current === null) {
                return;
            }

            if (in_array($current->status, [
                DocumentSubmissionStatus::REJECTED,
                DocumentSubmissionStatus::RESUBMISSION_REQUESTED,
            ], true)) {
                return;
            }

            throw new DomainRuleException(
                'Documents can only be replaced for requirements marked for correction.',
            );
        }

        throw new DomainRuleException(
            'Documents can only be uploaded while the application is a draft or when corrections are requested.',
        );
    }

    private function findRequirementForApplication(int $courseVersionId, int $requirementId): \Academy\Domain\Courses\CourseDocumentRequirement
    {
        foreach ($this->requirements->listByCourseVersionId($courseVersionId) as $requirement) {
            if ($requirement->requirementId === $requirementId) {
                return $requirement;
            }
        }

        throw new NotFoundException('Document requirement not found.');
    }

    private function requireUserId(AuthContext $auth): int
    {
        if ($auth->userId === null) {
            throw new AuthenticationException('Authentication required.');
        }

        return $auth->userId;
    }

    private function isDuplicateKey(PDOException $exception): bool
    {
        $sqlState = $exception->errorInfo[0] ?? null;
        $driverCode = $exception->errorInfo[1] ?? null;

        return $sqlState === '23000' || $driverCode === 1062;
    }
}
