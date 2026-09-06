<?php

declare(strict_types=1);

namespace Academy\Application\Courses;

use Academy\Application\Audit\AuditService;
use Academy\Domain\Audit\CoursesAuditPayload;
use Academy\Domain\Courses\CourseVersion;
use Academy\Domain\Courses\CourseVersionRepository;
use Academy\Domain\Exception\ConflictException;
use Academy\Domain\Exception\ValidationException;
use Academy\Domain\Security\AuthContext;
use Academy\Infrastructure\Database\ConnectionFactory;

final class UpdateDraftCourseVersionService
{
    public function __construct(
        private readonly CourseAdminAccessGuard $access,
        private readonly CourseVersionRepository $courseVersions,
        private readonly ConnectionFactory $connections,
        private readonly AuditService $audit,
    ) {
    }

    /**
     * @param array<string, mixed> $input
     */
    public function update(AuthContext $auth, int $courseId, int $versionId, array $input): CourseVersion
    {
        $actorUserId = $this->access->requireUserId($auth);
        $at = $this->access->nowUtc();
        $before = $this->access->requireVersionEditable($auth, $courseId, $versionId, $at);

        $fields = $this->normalize($input);

        $pdo = $this->connections->connection();
        $pdo->beginTransaction();
        try {
            $updated = $this->courseVersions->updateDraftOverview($versionId, $fields);
            if (!$updated) {
                throw new ConflictException(
                    'This CourseVersion is locked and immutable. Create Version N+1 to make changes.',
                );
            }

            $this->audit->record(
                new CoursesAuditPayload(
                    action: 'course_version.draft_updated',
                    entityType: 'course_version',
                    entityId: (string) $versionId,
                    previous: [
                        'version_id' => $before->versionId,
                        'title' => $before->title,
                        'standard_fee' => $before->standardFee,
                        'gst_rate' => $before->gstRate,
                        'currency' => $before->currency,
                    ],
                    next: [
                        'version_id' => $versionId,
                        'title' => $fields['title'],
                        'standard_fee' => $fields['standard_fee'],
                        'gst_rate' => $fields['gst_rate'],
                        'currency' => $fields['currency'],
                    ],
                ),
                actorType: 'user',
                actorUserId: $actorUserId,
                source: 'course_admin',
            );

            $pdo->commit();
        } catch (\Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $exception;
        }

        $after = $this->courseVersions->findById($versionId);
        if ($after === null) {
            throw new ConflictException('Course version could not be loaded after update.');
        }

        return $after;
    }

    /**
     * @param array<string, mixed> $input
     * @return array{
     *   title: string,
     *   description: string,
     *   learning_objectives: string,
     *   intended_audience: string,
     *   syllabus_summary: string,
     *   delivery_type: string,
     *   duration_text: string,
     *   validity_period_days: ?int,
     *   standard_fee: string,
     *   gst_rate: string,
     *   currency: string,
     *   certificate_type: string
     * }
     */
    private function normalize(array $input): array
    {
        $title = trim((string) ($input['title'] ?? ''));
        $description = trim((string) ($input['description'] ?? ''));
        $objectives = trim((string) ($input['learning_objectives'] ?? ''));
        $audience = trim((string) ($input['intended_audience'] ?? ''));
        $syllabus = trim((string) ($input['syllabus_summary'] ?? ''));
        $delivery = trim((string) ($input['delivery_type'] ?? ''));
        $duration = trim((string) ($input['duration_text'] ?? ''));
        $certificateType = trim((string) ($input['certificate_type'] ?? ''));
        $currency = strtoupper(trim((string) ($input['currency'] ?? 'INR')));
        $fee = trim((string) ($input['standard_fee'] ?? ''));
        $gst = trim((string) ($input['gst_rate'] ?? ''));

        $validityRaw = $input['validity_period_days'] ?? null;
        $validity = null;
        if ($validityRaw !== null && $validityRaw !== '') {
            if (!is_numeric($validityRaw) || (int) $validityRaw < 1) {
                throw new ValidationException('Validity period days must be a positive integer or empty.');
            }
            $validity = (int) $validityRaw;
        }

        foreach (
            [
                'Title' => $title,
                'Description' => $description,
                'Learning objectives' => $objectives,
                'Intended audience' => $audience,
                'Syllabus summary' => $syllabus,
                'Delivery type' => $delivery,
                'Duration' => $duration,
                'Certificate type' => $certificateType,
            ] as $label => $value
        ) {
            if ($value === '') {
                throw new ValidationException($label . ' is required.');
            }
        }

        if (!preg_match('/^\d+(\.\d{1,2})?$/', $fee)) {
            throw new ValidationException('Standard fee must be a non-negative decimal amount.');
        }
        if (!preg_match('/^\d+(\.\d{1,2})?$/', $gst) || (float) $gst > 100) {
            throw new ValidationException('GST rate must be between 0 and 100.');
        }
        if (!preg_match('/^[A-Z]{3}$/', $currency)) {
            throw new ValidationException('Currency must be a 3-letter ISO code.');
        }

        return [
            'title' => $title,
            'description' => $description,
            'learning_objectives' => $objectives,
            'intended_audience' => $audience,
            'syllabus_summary' => $syllabus,
            'delivery_type' => $delivery,
            'duration_text' => $duration,
            'validity_period_days' => $validity,
            'standard_fee' => number_format((float) $fee, 2, '.', ''),
            'gst_rate' => number_format((float) $gst, 2, '.', ''),
            'currency' => $currency,
            'certificate_type' => $certificateType,
        ];
    }
}
