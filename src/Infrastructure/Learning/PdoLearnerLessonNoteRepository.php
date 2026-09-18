<?php

declare(strict_types=1);

namespace Academy\Infrastructure\Learning;

use Academy\Domain\Learning\LearnerLessonNote;
use Academy\Domain\Learning\LearnerLessonNoteRepository;
use Academy\Infrastructure\Database\ConnectionFactory;
use DateTimeImmutable;
use DateTimeZone;
use PDO;

final class PdoLearnerLessonNoteRepository implements LearnerLessonNoteRepository
{
    public function __construct(
        private readonly ConnectionFactory $connections,
    ) {
    }

    public function findForEnrolmentAndContent(int $enrolmentId, int $contentId): ?LearnerLessonNote
    {
        $pdo = $this->connections->connection();
        $stmt = $pdo->prepare(
            'SELECT note_id, enrolment_id, content_id, user_id, body, created_at, updated_at
             FROM learner_lesson_notes
             WHERE enrolment_id = :enrolment_id AND content_id = :content_id
             LIMIT 1',
        );
        $stmt->execute(['enrolment_id' => $enrolmentId, 'content_id' => $contentId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $this->map($row) : null;
    }

    public function upsert(int $enrolmentId, int $contentId, int $userId, string $body, DateTimeImmutable $at): int
    {
        $pdo = $this->connections->connection();
        $ts = $at->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
        $existing = $this->findForEnrolmentAndContent($enrolmentId, $contentId);
        if ($existing !== null) {
            $stmt = $pdo->prepare(
                'UPDATE learner_lesson_notes
                 SET body = :body, updated_at = :updated_at
                 WHERE note_id = :note_id AND user_id = :user_id',
            );
            $stmt->execute([
                'body' => $body,
                'updated_at' => $ts,
                'note_id' => $existing->noteId,
                'user_id' => $userId,
            ]);

            return $existing->noteId;
        }

        $stmt = $pdo->prepare(
            'INSERT INTO learner_lesson_notes (
                enrolment_id, content_id, user_id, body, created_at, updated_at
             ) VALUES (
                :enrolment_id, :content_id, :user_id, :body, :created_at, :updated_at
             )',
        );
        $stmt->execute([
            'enrolment_id' => $enrolmentId,
            'content_id' => $contentId,
            'user_id' => $userId,
            'body' => $body,
            'created_at' => $ts,
            'updated_at' => $ts,
        ]);

        return (int) $pdo->lastInsertId();
    }

    public function deleteForEnrolmentAndContent(int $enrolmentId, int $contentId, int $userId): bool
    {
        $pdo = $this->connections->connection();
        $stmt = $pdo->prepare(
            'DELETE FROM learner_lesson_notes
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
    private function map(array $row): LearnerLessonNote
    {
        return new LearnerLessonNote(
            noteId: (int) $row['note_id'],
            enrolmentId: (int) $row['enrolment_id'],
            contentId: (int) $row['content_id'],
            userId: (int) $row['user_id'],
            body: (string) $row['body'],
            createdAt: new DateTimeImmutable((string) $row['created_at'], new DateTimeZone('UTC')),
            updatedAt: new DateTimeImmutable((string) $row['updated_at'], new DateTimeZone('UTC')),
        );
    }
}
