<?php

declare(strict_types=1);

namespace Academy\Infrastructure\Learning;

use Academy\Domain\Learning\LearningQuestion;
use Academy\Domain\Learning\LearningQuestionRepository;
use Academy\Domain\Learning\LearningQuestionStatus;
use Academy\Infrastructure\Database\ConnectionFactory;
use DateTimeImmutable;
use DateTimeZone;
use PDO;

final class PdoLearningQuestionRepository implements LearningQuestionRepository
{
    private const COLUMNS = 'question_id, enrolment_id, content_id, course_id, course_version_id, batch_id,
        module_id, asked_by_user_id, body, status, asked_at, first_responded_at, closed_at,
        closed_by_user_id, created_at, updated_at';

    public function __construct(
        private readonly ConnectionFactory $connections,
    ) {
    }

    public function insert(array $data): int
    {
        $pdo = $this->connections->connection();
        $at = $data['asked_at']->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
        $stmt = $pdo->prepare(
            'INSERT INTO learning_questions (
                enrolment_id, content_id, course_id, course_version_id, batch_id, module_id,
                asked_by_user_id, body, status, asked_at, first_responded_at, closed_at,
                closed_by_user_id, created_at, updated_at
             ) VALUES (
                :enrolment_id, :content_id, :course_id, :course_version_id, :batch_id, :module_id,
                :asked_by_user_id, :body, :status, :asked_at, NULL, NULL,
                NULL, :created_at, :updated_at
             )',
        );
        $stmt->execute([
            'enrolment_id' => $data['enrolment_id'],
            'content_id' => $data['content_id'],
            'course_id' => $data['course_id'],
            'course_version_id' => $data['course_version_id'],
            'batch_id' => $data['batch_id'],
            'module_id' => $data['module_id'],
            'asked_by_user_id' => $data['asked_by_user_id'],
            'body' => $data['body'],
            'status' => LearningQuestionStatus::OPEN,
            'asked_at' => $at,
            'created_at' => $at,
            'updated_at' => $at,
        ]);

        return (int) $pdo->lastInsertId();
    }

    public function findById(int $questionId): ?LearningQuestion
    {
        $pdo = $this->connections->connection();
        $stmt = $pdo->prepare('SELECT ' . self::COLUMNS . ' FROM learning_questions WHERE question_id = :id LIMIT 1');
        $stmt->execute(['id' => $questionId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $this->map($row) : null;
    }

    public function listForEnrolmentAndContent(int $enrolmentId, int $contentId): array
    {
        $pdo = $this->connections->connection();
        $stmt = $pdo->prepare(
            'SELECT ' . self::COLUMNS . ' FROM learning_questions
             WHERE enrolment_id = :enrolment_id AND content_id = :content_id
             ORDER BY asked_at ASC, question_id ASC',
        );
        $stmt->execute([
            'enrolment_id' => $enrolmentId,
            'content_id' => $contentId,
        ]);

        return array_map(fn (array $row): LearningQuestion => $this->map($row), $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    public function listOpenForCourses(array $courseIds, int $limit = 50): array
    {
        return $this->listForCoursesFiltered($courseIds, LearningQuestionStatus::OPEN, $limit);
    }

    public function listForCourses(array $courseIds, int $limit = 50): array
    {
        return $this->listForCoursesFiltered($courseIds, null, $limit);
    }

    public function markAnswered(int $questionId, DateTimeImmutable $at): bool
    {
        $pdo = $this->connections->connection();
        $atUtc = $at->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
        $stmt = $pdo->prepare(
            'UPDATE learning_questions
             SET status = :answered,
                 first_responded_at = COALESCE(first_responded_at, :at),
                 updated_at = :updated_at
             WHERE question_id = :question_id
               AND status = :open',
        );
        $stmt->execute([
            'answered' => LearningQuestionStatus::ANSWERED,
            'at' => $atUtc,
            'updated_at' => $atUtc,
            'question_id' => $questionId,
            'open' => LearningQuestionStatus::OPEN,
        ]);

        return $stmt->rowCount() > 0;
    }

    public function markClosed(int $questionId, int $closedByUserId, DateTimeImmutable $at): bool
    {
        $pdo = $this->connections->connection();
        $atUtc = $at->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
        $stmt = $pdo->prepare(
            'UPDATE learning_questions
             SET status = :closed,
                 closed_at = :closed_at,
                 closed_by_user_id = :closed_by_user_id,
                 updated_at = :updated_at
             WHERE question_id = :question_id
               AND status IN (\'' . LearningQuestionStatus::OPEN . '\', \'' . LearningQuestionStatus::ANSWERED . '\')',
        );
        $stmt->execute([
            'closed' => LearningQuestionStatus::CLOSED,
            'closed_at' => $atUtc,
            'closed_by_user_id' => $closedByUserId,
            'updated_at' => $atUtc,
            'question_id' => $questionId,
        ]);

        return $stmt->rowCount() > 0;
    }

    /**
     * @param list<int> $courseIds
     * @return list<LearningQuestion>
     */
    private function listForCoursesFiltered(array $courseIds, ?string $status, int $limit): array
    {
        if ($courseIds === []) {
            return [];
        }
        $pdo = $this->connections->connection();
        $placeholders = implode(',', array_fill(0, count($courseIds), '?'));
        $sql = 'SELECT ' . self::COLUMNS . ' FROM learning_questions WHERE course_id IN (' . $placeholders . ')';
        $params = array_values($courseIds);
        if ($status !== null) {
            $sql .= ' AND status = ?';
            $params[] = $status;
        }
        $sql .= ' ORDER BY CASE status WHEN \'open\' THEN 0 WHEN \'answered\' THEN 1 ELSE 2 END, asked_at DESC, question_id DESC LIMIT '
            . max(1, min(100, $limit));
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);

        return array_map(fn (array $row): LearningQuestion => $this->map($row), $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * @param array<string, mixed> $row
     */
    private function map(array $row): LearningQuestion
    {
        return new LearningQuestion(
            questionId: (int) $row['question_id'],
            enrolmentId: (int) $row['enrolment_id'],
            contentId: (int) $row['content_id'],
            courseId: (int) $row['course_id'],
            courseVersionId: (int) $row['course_version_id'],
            batchId: (int) $row['batch_id'],
            moduleId: (int) $row['module_id'],
            askedByUserId: (int) $row['asked_by_user_id'],
            body: (string) $row['body'],
            status: (string) $row['status'],
            askedAt: new DateTimeImmutable((string) $row['asked_at'], new DateTimeZone('UTC')),
            firstRespondedAt: $row['first_responded_at'] !== null
                ? new DateTimeImmutable((string) $row['first_responded_at'], new DateTimeZone('UTC'))
                : null,
            closedAt: $row['closed_at'] !== null
                ? new DateTimeImmutable((string) $row['closed_at'], new DateTimeZone('UTC'))
                : null,
            closedByUserId: $row['closed_by_user_id'] !== null ? (int) $row['closed_by_user_id'] : null,
            createdAt: new DateTimeImmutable((string) $row['created_at'], new DateTimeZone('UTC')),
            updatedAt: new DateTimeImmutable((string) $row['updated_at'], new DateTimeZone('UTC')),
        );
    }
}
