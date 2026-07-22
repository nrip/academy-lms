<?php

declare(strict_types=1);

namespace Academy\Infrastructure\Courses;

use Academy\Domain\Courses\CourseVersion;
use Academy\Domain\Courses\CourseVersionRepository;
use Academy\Infrastructure\Database\ConnectionFactory;
use DateTimeImmutable;
use DateTimeZone;
use PDO;

final class PdoCourseVersionRepository implements CourseVersionRepository
{
    private const COLUMNS = 'version_id, course_id, version_number, title, description,
        learning_objectives, intended_audience, syllabus_summary, admission_mode, delivery_type,
        duration_text, validity_period_days, standard_fee, gst_rate, currency, certificate_type,
        faq_json, status, published_at, locked_at, locked_reason, created_at, updated_at';

    public function __construct(
        private readonly ConnectionFactory $connections,
    ) {
    }

    public function findById(int $versionId): ?CourseVersion
    {
        $pdo = $this->connections->connection();
        $stmt = $pdo->prepare('SELECT ' . self::COLUMNS . ' FROM course_versions WHERE version_id = :id');
        $stmt->execute(['id' => $versionId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $this->mapRow($row);
    }

    public function listByCourseId(int $courseId): array
    {
        $pdo = $this->connections->connection();
        $stmt = $pdo->prepare(
            'SELECT ' . self::COLUMNS . ' FROM course_versions WHERE course_id = :course_id
             ORDER BY version_number DESC',
        );
        $stmt->execute(['course_id' => $courseId]);

        $versions = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $versions[] = $this->mapRow($row);
        }

        return $versions;
    }

    public function lock(int $versionId, string $lockedReason, DateTimeImmutable $lockedAt): void
    {
        $pdo = $this->connections->connection();
        $stmt = $pdo->prepare(
            'UPDATE course_versions
             SET locked_at = :locked_at, locked_reason = :locked_reason, updated_at = :updated_at
             WHERE version_id = :id AND locked_at IS NULL',
        );
        $stmt->execute([
            'locked_at' => $lockedAt->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u'),
            'locked_reason' => $lockedReason,
            'updated_at' => $lockedAt->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u'),
            'id' => $versionId,
        ]);
    }

    /**
     * @param array<string, mixed> $row
     */
    private function mapRow(array $row): CourseVersion
    {
        $utc = new DateTimeZone('UTC');
        $faq = null;
        if ($row['faq_json'] !== null) {
            /** @var array<string, mixed>|null $decoded */
            $decoded = json_decode((string) $row['faq_json'], true);
            $faq = is_array($decoded) ? $decoded : null;
        }

        return new CourseVersion(
            versionId: (int) $row['version_id'],
            courseId: (int) $row['course_id'],
            versionNumber: (int) $row['version_number'],
            title: (string) $row['title'],
            description: (string) $row['description'],
            learningObjectives: (string) $row['learning_objectives'],
            intendedAudience: (string) $row['intended_audience'],
            syllabusSummary: (string) $row['syllabus_summary'],
            admissionMode: (string) $row['admission_mode'],
            deliveryType: (string) $row['delivery_type'],
            durationText: (string) $row['duration_text'],
            validityPeriodDays: $row['validity_period_days'] === null ? null : (int) $row['validity_period_days'],
            standardFee: (string) $row['standard_fee'],
            gstRate: (string) $row['gst_rate'],
            currency: (string) $row['currency'],
            certificateType: (string) $row['certificate_type'],
            faq: $faq,
            status: (string) $row['status'],
            publishedAt: $row['published_at'] === null ? null : new DateTimeImmutable((string) $row['published_at'], $utc),
            lockedAt: $row['locked_at'] === null ? null : new DateTimeImmutable((string) $row['locked_at'], $utc),
            lockedReason: $row['locked_reason'] === null ? null : (string) $row['locked_reason'],
            createdAt: new DateTimeImmutable((string) $row['created_at'], $utc),
            updatedAt: new DateTimeImmutable((string) $row['updated_at'], $utc),
        );
    }
}
