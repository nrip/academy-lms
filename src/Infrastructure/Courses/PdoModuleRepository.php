<?php

declare(strict_types=1);

namespace Academy\Infrastructure\Courses;

use Academy\Domain\Courses\Module;
use Academy\Domain\Courses\ModuleRepository;
use Academy\Infrastructure\Database\ConnectionFactory;
use DateTimeImmutable;
use DateTimeZone;
use PDO;

final class PdoModuleRepository implements ModuleRepository
{
    private const COLUMNS = 'module_id, course_version_id, sequence, title, description, mandatory_flag,
        release_rule, prerequisite_module_id, created_at, updated_at';

    public function __construct(
        private readonly ConnectionFactory $connections,
    ) {
    }

    public function findById(int $moduleId): ?Module
    {
        $pdo = $this->connections->connection();
        $stmt = $pdo->prepare('SELECT ' . self::COLUMNS . ' FROM modules WHERE module_id = :id LIMIT 1');
        $stmt->execute(['id' => $moduleId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $this->mapRow($row);
    }

    public function listByCourseVersionId(int $courseVersionId): array
    {
        $pdo = $this->connections->connection();
        $stmt = $pdo->prepare(
            'SELECT ' . self::COLUMNS . ' FROM modules
             WHERE course_version_id = :version_id
             ORDER BY sequence ASC, module_id ASC',
        );
        $stmt->execute(['version_id' => $courseVersionId]);

        $modules = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $modules[] = $this->mapRow($row);
        }

        return $modules;
    }

    public function nextSequence(int $courseVersionId): int
    {
        $pdo = $this->connections->connection();
        $stmt = $pdo->prepare(
            'SELECT COALESCE(MAX(sequence), 0) + 1 FROM modules WHERE course_version_id = :version_id',
        );
        $stmt->execute(['version_id' => $courseVersionId]);

        return (int) $stmt->fetchColumn();
    }

    public function insert(array $data): int
    {
        $now = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s.u');
        $pdo = $this->connections->connection();
        $stmt = $pdo->prepare(
            'INSERT INTO modules (
                course_version_id, sequence, title, description, mandatory_flag,
                release_rule, prerequisite_module_id, created_at, updated_at
             ) VALUES (
                :course_version_id, :sequence, :title, :description, :mandatory_flag,
                :release_rule, :prerequisite_module_id, :created_at, :updated_at
             )',
        );
        $stmt->execute([
            'course_version_id' => $data['course_version_id'],
            'sequence' => $data['sequence'],
            'title' => $data['title'],
            'description' => $data['description'],
            'mandatory_flag' => $data['mandatory_flag'] ? 1 : 0,
            'release_rule' => $data['release_rule'],
            'prerequisite_module_id' => $data['prerequisite_module_id'],
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return (int) $pdo->lastInsertId();
    }

    public function update(int $moduleId, array $data): bool
    {
        $now = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s.u');
        $pdo = $this->connections->connection();
        $stmt = $pdo->prepare(
            'UPDATE modules SET
                title = :title,
                description = :description,
                mandatory_flag = :mandatory_flag,
                release_rule = :release_rule,
                prerequisite_module_id = :prerequisite_module_id,
                updated_at = :updated_at
             WHERE module_id = :module_id',
        );
        $stmt->execute([
            'title' => $data['title'],
            'description' => $data['description'],
            'mandatory_flag' => $data['mandatory_flag'] ? 1 : 0,
            'release_rule' => $data['release_rule'],
            'prerequisite_module_id' => $data['prerequisite_module_id'],
            'updated_at' => $now,
            'module_id' => $moduleId,
        ]);

        return $stmt->rowCount() > 0;
    }

    public function delete(int $moduleId): bool
    {
        $pdo = $this->connections->connection();
        $stmt = $pdo->prepare('DELETE FROM modules WHERE module_id = :module_id');
        $stmt->execute(['module_id' => $moduleId]);

        return $stmt->rowCount() > 0;
    }

    public function countContentItems(int $moduleId): int
    {
        $pdo = $this->connections->connection();
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM content_items WHERE module_id = :module_id');
        $stmt->execute(['module_id' => $moduleId]);

        return (int) $stmt->fetchColumn();
    }

    /**
     * @param array<string, mixed> $row
     */
    private function mapRow(array $row): Module
    {
        $utc = new DateTimeZone('UTC');

        return new Module(
            moduleId: (int) $row['module_id'],
            courseVersionId: (int) $row['course_version_id'],
            sequence: (int) $row['sequence'],
            title: (string) $row['title'],
            description: (string) $row['description'],
            mandatoryFlag: (int) $row['mandatory_flag'] === 1,
            releaseRule: (string) $row['release_rule'],
            prerequisiteModuleId: $row['prerequisite_module_id'] === null ? null : (int) $row['prerequisite_module_id'],
            createdAt: new DateTimeImmutable((string) $row['created_at'], $utc),
            updatedAt: new DateTimeImmutable((string) $row['updated_at'], $utc),
        );
    }
}
