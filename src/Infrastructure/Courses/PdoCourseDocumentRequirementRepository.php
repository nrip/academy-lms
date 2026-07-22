<?php

declare(strict_types=1);

namespace Academy\Infrastructure\Courses;

use Academy\Domain\Courses\CourseDocumentRequirement;
use Academy\Domain\Courses\CourseDocumentRequirementRepository;
use Academy\Infrastructure\Database\ConnectionFactory;
use DateTimeImmutable;
use DateTimeZone;
use PDO;

final class PdoCourseDocumentRequirementRepository implements CourseDocumentRequirementRepository
{
    private const COLUMNS = 'requirement_id, course_version_id, document_name, description,
        mandatory_flag, accepted_file_types, max_size_bytes, single_or_multiple, reuse_allowed,
        reviewer_instructions, sort_order, created_at, updated_at';

    public function __construct(
        private readonly ConnectionFactory $connections,
    ) {
    }

    public function listByCourseVersionId(int $courseVersionId): array
    {
        $pdo = $this->connections->connection();
        $stmt = $pdo->prepare(
            'SELECT ' . self::COLUMNS . ' FROM course_document_requirements WHERE course_version_id = :version_id
             ORDER BY sort_order ASC, requirement_id ASC',
        );
        $stmt->execute(['version_id' => $courseVersionId]);

        $requirements = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $requirements[] = $this->mapRow($row);
        }

        return $requirements;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function mapRow(array $row): CourseDocumentRequirement
    {
        $utc = new DateTimeZone('UTC');

        return new CourseDocumentRequirement(
            requirementId: (int) $row['requirement_id'],
            courseVersionId: (int) $row['course_version_id'],
            documentName: (string) $row['document_name'],
            description: (string) $row['description'],
            mandatory: (int) $row['mandatory_flag'] === 1,
            acceptedFileTypes: (string) $row['accepted_file_types'],
            maxSizeBytes: (int) $row['max_size_bytes'],
            singleOrMultiple: (string) $row['single_or_multiple'],
            reuseAllowed: (int) $row['reuse_allowed'] === 1,
            reviewerInstructions: $row['reviewer_instructions'] === null ? null : (string) $row['reviewer_instructions'],
            sortOrder: (int) $row['sort_order'],
            createdAt: new DateTimeImmutable((string) $row['created_at'], $utc),
            updatedAt: new DateTimeImmutable((string) $row['updated_at'], $utc),
        );
    }
}
