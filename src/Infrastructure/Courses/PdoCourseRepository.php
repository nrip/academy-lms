<?php

declare(strict_types=1);

namespace Academy\Infrastructure\Courses;

use Academy\Domain\Courses\Course;
use Academy\Domain\Courses\CourseRepository;
use Academy\Infrastructure\Database\ConnectionFactory;
use DateTimeImmutable;
use DateTimeZone;
use PDO;

final class PdoCourseRepository implements CourseRepository
{
    private const COLUMNS = 'course_id, course_code, slug, master_title, status,
        current_published_version_id, created_at, updated_at';

    public function __construct(
        private readonly ConnectionFactory $connections,
    ) {
    }

    public function findById(int $courseId): ?Course
    {
        $pdo = $this->connections->connection();
        $stmt = $pdo->prepare('SELECT ' . self::COLUMNS . ' FROM courses WHERE course_id = :id');
        $stmt->execute(['id' => $courseId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $this->mapRow($row);
    }

    public function findBySlug(string $slug): ?Course
    {
        $pdo = $this->connections->connection();
        $stmt = $pdo->prepare('SELECT ' . self::COLUMNS . ' FROM courses WHERE slug = :slug');
        $stmt->execute(['slug' => $slug]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $this->mapRow($row);
    }

    public function findByCourseCode(string $courseCode): ?Course
    {
        $pdo = $this->connections->connection();
        $stmt = $pdo->prepare('SELECT ' . self::COLUMNS . ' FROM courses WHERE course_code = :course_code');
        $stmt->execute(['course_code' => $courseCode]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $this->mapRow($row);
    }

    public function listActive(): array
    {
        $pdo = $this->connections->connection();
        $stmt = $pdo->prepare(
            'SELECT ' . self::COLUMNS . ' FROM courses WHERE status = :status ORDER BY master_title ASC',
        );
        $stmt->execute(['status' => \Academy\Domain\Courses\CourseStatus::ACTIVE]);

        $courses = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $courses[] = $this->mapRow($row);
        }

        return $courses;
    }

    public function setCurrentPublishedVersionId(int $courseId, int $versionId): void
    {
        $pdo = $this->connections->connection();
        $stmt = $pdo->prepare(
            'UPDATE courses SET current_published_version_id = :version_id, updated_at = :updated_at
             WHERE course_id = :course_id',
        );
        $stmt->execute([
            'version_id' => $versionId,
            'updated_at' => (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s.u'),
            'course_id' => $courseId,
        ]);
    }

    /**
     * @param array<string, mixed> $row
     */
    private function mapRow(array $row): Course
    {
        $utc = new DateTimeZone('UTC');

        return new Course(
            courseId: (int) $row['course_id'],
            courseCode: (string) $row['course_code'],
            slug: (string) $row['slug'],
            masterTitle: (string) $row['master_title'],
            status: (string) $row['status'],
            currentPublishedVersionId: $row['current_published_version_id'] === null
                ? null
                : (int) $row['current_published_version_id'],
            createdAt: new DateTimeImmutable((string) $row['created_at'], $utc),
            updatedAt: new DateTimeImmutable((string) $row['updated_at'], $utc),
        );
    }
}
