<?php

declare(strict_types=1);

namespace Academy\Application\Courses;

use Academy\Application\Audit\AuditService;
use Academy\Domain\Audit\CoursesAuditPayload;
use Academy\Domain\Courses\Batch;
use Academy\Domain\Courses\BatchDateValidator;
use Academy\Domain\Courses\BatchRepository;
use Academy\Domain\Courses\BatchStatus;
use Academy\Domain\Courses\CourseVersionStatus;
use Academy\Domain\Exception\ConflictException;
use Academy\Domain\Exception\DomainRuleException;
use Academy\Domain\Exception\ValidationException;
use Academy\Domain\Security\AuthContext;
use Academy\Infrastructure\Database\ConnectionFactory;
use DateTimeImmutable;
use DateTimeZone;
use PDOException;
use Throwable;

final class CreateBatchForPublishedVersionService
{
    public function __construct(
        private readonly CourseAdminAccessGuard $access,
        private readonly BatchRepository $batches,
        private readonly BatchDateValidator $dateValidator,
        private readonly ConnectionFactory $connections,
        private readonly AuditService $audit,
    ) {
    }

    /**
     * @param array<string, mixed> $input
     */
    public function create(AuthContext $auth, int $courseId, int $versionId, array $input): Batch
    {
        $at = $this->access->nowUtc();
        $actorUserId = $this->access->requireUserId($auth);
        $version = $this->access->requireVersionWithPermission(
            $auth,
            $courseId,
            $versionId,
            'batch.create',
            $at,
        );

        if ($version->status !== CourseVersionStatus::PUBLISHED || !$version->isLocked()) {
            throw new DomainRuleException('Batches can only be created on published, locked CourseVersions.');
        }

        $batchCode = strtoupper(trim((string) ($input['batch_code'] ?? '')));
        $name = trim((string) ($input['name'] ?? ''));
        $deliveryMode = trim((string) ($input['delivery_mode'] ?? 'online'));
        $venue = trim((string) ($input['venue_or_online_details'] ?? 'Online sessions.'));
        $timezone = trim((string) ($input['timezone'] ?? 'Asia/Kolkata'));
        $currency = strtoupper(trim((string) ($input['currency'] ?? $version->currency)));
        $minCapacity = (int) ($input['min_capacity'] ?? 1);
        $maxCapacity = (int) ($input['max_capacity'] ?? 30);

        if ($batchCode === '' || strlen($batchCode) > 64 || !preg_match('/^[A-Z0-9][A-Z0-9\-_]*$/', $batchCode)) {
            throw new ValidationException('Batch code must be 1–64 characters (A–Z, 0–9, hyphen, underscore).');
        }
        if ($name === '' || strlen($name) > 255) {
            throw new ValidationException('Batch name is required (max 255 characters).');
        }
        if ($deliveryMode === '' || strlen($deliveryMode) > 64) {
            throw new ValidationException('Delivery mode is required.');
        }
        if ($venue === '') {
            throw new ValidationException('Venue or online details are required.');
        }
        if ($timezone === '') {
            throw new ValidationException('Timezone is required.');
        }
        if ($currency === '' || strlen($currency) !== 3) {
            throw new ValidationException('Currency must be a 3-letter code.');
        }

        $startsAt = $this->parseDateTime((string) ($input['starts_at'] ?? ''), 'starts_at');
        $endsAt = $this->parseDateTime((string) ($input['ends_at'] ?? ''), 'ends_at');
        $applicationsOpenAt = $this->parseDateTime((string) ($input['applications_open_at'] ?? ''), 'applications_open_at');
        $applicationsCloseAt = $this->parseDateTime((string) ($input['applications_close_at'] ?? ''), 'applications_close_at');

        $this->dateValidator->validate(
            $startsAt,
            $endsAt,
            $applicationsOpenAt,
            $applicationsCloseAt,
            $minCapacity,
            $maxCapacity,
        );

        $feeOverride = null;
        if (isset($input['fee_override']) && trim((string) $input['fee_override']) !== '') {
            $feeOverride = $this->normalizeMoney((string) $input['fee_override']);
        }

        $pdo = $this->connections->connection();
        $pdo->beginTransaction();
        $batchId = 0;
        try {
            $batchId = $this->batches->insert([
                'course_version_id' => $versionId,
                'batch_code' => $batchCode,
                'name' => $name,
                'starts_at' => $startsAt,
                'ends_at' => $endsAt,
                'applications_open_at' => $applicationsOpenAt,
                'applications_close_at' => $applicationsCloseAt,
                'min_capacity' => $minCapacity,
                'max_capacity' => $maxCapacity,
                'delivery_mode' => $deliveryMode,
                'venue_or_online_details' => $venue,
                'timezone' => $timezone,
                'fee_override' => $feeOverride,
                'currency' => $currency,
                'status' => BatchStatus::OPEN_FOR_APPLICATIONS,
                'access_expires_at' => null,
            ]);

            $this->audit->record(
                new CoursesAuditPayload(
                    action: 'batch.created',
                    entityType: 'batch',
                    entityId: (string) $batchId,
                    next: [
                        'batch_id' => $batchId,
                        'batch_code' => $batchCode,
                        'name' => $name,
                        'version_id' => $versionId,
                        'course_id' => $courseId,
                        'status' => BatchStatus::OPEN_FOR_APPLICATIONS,
                        'delivery_mode' => $deliveryMode,
                        'min_capacity' => $minCapacity,
                        'max_capacity' => $maxCapacity,
                    ],
                ),
                actorType: 'user',
                actorUserId: $actorUserId,
                source: 'course_admin',
            );

            $pdo->commit();
        } catch (PDOException $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            if ($this->isUniqueViolation($exception)) {
                throw new ConflictException('A batch with this batch code already exists.');
            }
            throw $exception;
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $exception;
        }

        $batch = $this->batches->findById($batchId);
        if ($batch === null) {
            throw new ConflictException('Batch could not be reloaded after create.');
        }

        return $batch;
    }

    private function parseDateTime(string $raw, string $field): DateTimeImmutable
    {
        $raw = trim($raw);
        if ($raw === '') {
            throw new ValidationException('Missing required date field: ' . $field, [
                $field => ['This date is required.'],
            ]);
        }

        // Accept HTML datetime-local (YYYY-MM-DDTHH:MM) as Asia/Kolkata wall time → UTC.
        if (preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/', $raw) === 1) {
            $local = DateTimeImmutable::createFromFormat('Y-m-d\TH:i', $raw, new DateTimeZone('Asia/Kolkata'));
            if ($local === false) {
                throw new ValidationException('Invalid date for ' . $field . '.', [
                    $field => ['Use a valid date and time.'],
                ]);
            }

            return $local->setTimezone(new DateTimeZone('UTC'));
        }

        try {
            return (new DateTimeImmutable($raw))->setTimezone(new DateTimeZone('UTC'));
        } catch (\Exception) {
            throw new ValidationException('Invalid date for ' . $field . '.', [
                $field => ['Use a valid date and time.'],
            ]);
        }
    }

    private function normalizeMoney(string $value): string
    {
        $value = trim($value);
        if (!preg_match('/^\d+(\.\d{1,2})?$/', $value)) {
            throw new ValidationException('Fee override must be a valid amount.');
        }

        return number_format((float) $value, 2, '.', '');
    }

    private function isUniqueViolation(PDOException $exception): bool
    {
        return $exception->getCode() === '23000' || str_contains($exception->getMessage(), 'Duplicate');
    }
}
