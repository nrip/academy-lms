<?php

declare(strict_types=1);

namespace Academy\Infrastructure\Learning;

use Academy\Domain\Learning\LearnerLessonBookmark;
use Academy\Domain\Learning\LearnerLessonBookmarkRepository;
use Academy\Infrastructure\Database\ConnectionFactory;
use DateTimeImmutable;
use DateTimeZone;
use PDO;

final class PdoLearnerLessonBookmarkRepository implements LearnerLessonBookmarkRepository
{
    public function __construct(
        private readonly ConnectionFactory $connections,
    ) {
    }

    public function findForEnrolmentAndContent(int $enrolmentId, int $contentId): ?LearnerLessonBookmark
    {
        $pdo = $this->connections->connection();
        $stmt = $pdo->prepare(
            'SELECT bookmark_id, enrolment_id, content_id, user_id, created_at
             FROM learner_lesson_bookmarks
             WHERE enrolment_id = :enrolment_id AND content_id = :content_id
             LIMIT 1',
        );
        $stmt->execute(['enrolment_id' => $enrolmentId, 'content_id' => $contentId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $this->map($row) : null;
    }

    public function listForEnrolment(int $enrolmentId): array
    {
        $pdo = $this->connections->connection();
        $stmt = $pdo->prepare(
            'SELECT bookmark_id, enrolment_id, content_id, user_id, created_at
             FROM learner_lesson_bookmarks
             WHERE enrolment_id = :enrolment_id
             ORDER BY created_at DESC, bookmark_id DESC',
        );
        $stmt->execute(['enrolment_id' => $enrolmentId]);
        $items = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $items[] = $this->map($row);
        }

        return $items;
    }

    public function upsert(int $enrolmentId, int $contentId, int $userId, DateTimeImmutable $at): int
    {
        $pdo = $this->connections->connection();
        $existing = $this->findForEnrolmentAndContent($enrolmentId, $contentId);
        if ($existing !== null) {
            return $existing->bookmarkId;
        }
        $ts = $at->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
        $stmt = $pdo->prepare(
            'INSERT INTO learner_lesson_bookmarks (enrolment_id, content_id, user_id, created_at)
             VALUES (:enrolment_id, :content_id, :user_id, :created_at)',
        );
        $stmt->execute([
            'enrolment_id' => $enrolmentId,
            'content_id' => $contentId,
            'user_id' => $userId,
            'created_at' => $ts,
        ]);

        return (int) $pdo->lastInsertId();
    }

    public function deleteForEnrolmentAndContent(int $enrolmentId, int $contentId, int $userId): bool
    {
        $pdo = $this->connections->connection();
        $stmt = $pdo->prepare(
            'DELETE FROM learner_lesson_bookmarks
             WHERE enrolment_id = :enrolment_id AND content_id = :content_id AND user_id = :user_id',
        );
        $stmt->execute([
            'enrolment_id' => $enrolmentId,
            'content_id' => $contentId,
            'user_id' => $userId,
        ]);

        return $stmt->rowCount() > 0;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function map(array $row): LearnerLessonBookmark
    {
        return new LearnerLessonBookmark(
            bookmarkId: (int) $row['bookmark_id'],
            enrolmentId: (int) $row['enrolment_id'],
            contentId: (int) $row['content_id'],
            userId: (int) $row['user_id'],
            createdAt: new DateTimeImmutable((string) $row['created_at'], new DateTimeZone('UTC')),
        );
    }
}
