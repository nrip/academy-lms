<?php

declare(strict_types=1);

namespace Academy\Infrastructure\Courses;

use Academy\Domain\Courses\ContentItem;
use Academy\Domain\Courses\ContentItemRepository;
use Academy\Infrastructure\Database\ConnectionFactory;
use DateTimeImmutable;
use DateTimeZone;
use PDO;

final class PdoContentItemRepository implements ContentItemRepository
{
    private const COLUMNS = 'content_id, module_id, sequence, content_type, title, body_text, object_key,
        mandatory_flag, completion_rule, created_at, updated_at';

    public function __construct(
        private readonly ConnectionFactory $connections,
    ) {
    }

    public function findById(int $contentId): ?ContentItem
    {
        $pdo = $this->connections->connection();
        $stmt = $pdo->prepare('SELECT ' . self::COLUMNS . ' FROM content_items WHERE content_id = :id LIMIT 1');
        $stmt->execute(['id' => $contentId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $this->mapRow($row);
    }

    public function listByModuleId(int $moduleId): array
    {
        $pdo = $this->connections->connection();
        $stmt = $pdo->prepare(
            'SELECT ' . self::COLUMNS . ' FROM content_items
             WHERE module_id = :module_id
             ORDER BY sequence ASC, content_id ASC',
        );
        $stmt->execute(['module_id' => $moduleId]);

        $items = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $items[] = $this->mapRow($row);
        }

        return $items;
    }

    public function listByCourseVersionId(int $courseVersionId): array
    {
        $pdo = $this->connections->connection();
        $stmt = $pdo->prepare(
            'SELECT ci.content_id, ci.module_id, ci.sequence, ci.content_type, ci.title, ci.body_text,
                    ci.object_key, ci.mandatory_flag, ci.completion_rule, ci.created_at, ci.updated_at
             FROM content_items ci
             INNER JOIN modules m ON m.module_id = ci.module_id
             WHERE m.course_version_id = :version_id
             ORDER BY m.sequence ASC, ci.sequence ASC, ci.content_id ASC',
        );
        $stmt->execute(['version_id' => $courseVersionId]);

        $items = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $items[] = $this->mapRow($row);
        }

        return $items;
    }

    public function nextSequence(int $moduleId): int
    {
        $pdo = $this->connections->connection();
        $stmt = $pdo->prepare(
            'SELECT COALESCE(MAX(sequence), 0) + 1 FROM content_items WHERE module_id = :module_id',
        );
        $stmt->execute(['module_id' => $moduleId]);

        return (int) $stmt->fetchColumn();
    }

    public function insert(array $data): int
    {
        $now = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s.u');
        $pdo = $this->connections->connection();
        $stmt = $pdo->prepare(
            'INSERT INTO content_items (
                module_id, sequence, content_type, title, body_text, object_key,
                mandatory_flag, completion_rule, created_at, updated_at
             ) VALUES (
                :module_id, :sequence, :content_type, :title, :body_text, :object_key,
                :mandatory_flag, :completion_rule, :created_at, :updated_at
             )',
        );
        $stmt->execute([
            'module_id' => $data['module_id'],
            'sequence' => $data['sequence'],
            'content_type' => $data['content_type'],
            'title' => $data['title'],
            'body_text' => $data['body_text'],
            'object_key' => $data['object_key'],
            'mandatory_flag' => $data['mandatory_flag'] ? 1 : 0,
            'completion_rule' => $data['completion_rule'],
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return (int) $pdo->lastInsertId();
    }

    public function update(int $contentId, array $data): bool
    {
        $now = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s.u');
        $pdo = $this->connections->connection();
        $stmt = $pdo->prepare(
            'UPDATE content_items SET
                title = :title,
                body_text = :body_text,
                object_key = :object_key,
                mandatory_flag = :mandatory_flag,
                completion_rule = :completion_rule,
                updated_at = :updated_at
             WHERE content_id = :content_id',
        );
        $stmt->execute([
            'title' => $data['title'],
            'body_text' => $data['body_text'],
            'object_key' => $data['object_key'],
            'mandatory_flag' => $data['mandatory_flag'] ? 1 : 0,
            'completion_rule' => $data['completion_rule'],
            'updated_at' => $now,
            'content_id' => $contentId,
        ]);

        return $stmt->rowCount() > 0;
    }

    public function delete(int $contentId): bool
    {
        $pdo = $this->connections->connection();
        $stmt = $pdo->prepare('DELETE FROM content_items WHERE content_id = :content_id');
        $stmt->execute(['content_id' => $contentId]);

        return $stmt->rowCount() > 0;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function mapRow(array $row): ContentItem
    {
        $utc = new DateTimeZone('UTC');

        return new ContentItem(
            contentId: (int) $row['content_id'],
            moduleId: (int) $row['module_id'],
            sequence: (int) $row['sequence'],
            contentType: (string) $row['content_type'],
            title: (string) $row['title'],
            bodyText: $row['body_text'] === null ? null : (string) $row['body_text'],
            objectKey: $row['object_key'] === null ? null : (string) $row['object_key'],
            mandatoryFlag: (int) $row['mandatory_flag'] === 1,
            completionRule: (string) $row['completion_rule'],
            createdAt: new DateTimeImmutable((string) $row['created_at'], $utc),
            updatedAt: new DateTimeImmutable((string) $row['updated_at'], $utc),
        );
    }
}
