<?php

declare(strict_types=1);

namespace Academy\Infrastructure\Assessments;

use Academy\Domain\Assessments\AssessmentQuestionLink;
use Academy\Domain\Assessments\AssessmentQuestionLinkRepository;
use Academy\Infrastructure\Database\ConnectionFactory;
use DateTimeImmutable;
use DateTimeZone;
use PDO;

final class PdoAssessmentQuestionLinkRepository implements AssessmentQuestionLinkRepository
{
    private const COLUMNS = 'link_id, assessment_id, question_id, sequence, created_at, updated_at';

    public function __construct(
        private readonly ConnectionFactory $connections,
    ) {
    }

    public function listByAssessmentId(int $assessmentId): array
    {
        $pdo = $this->connections->connection();
        $stmt = $pdo->prepare(
            'SELECT ' . self::COLUMNS . ' FROM assessment_question_links
             WHERE assessment_id = :assessment_id
             ORDER BY sequence ASC, link_id ASC',
        );
        $stmt->execute(['assessment_id' => $assessmentId]);

        $links = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $links[] = $this->mapRow($row);
        }

        return $links;
    }

    public function deleteByAssessmentId(int $assessmentId): void
    {
        $pdo = $this->connections->connection();
        $stmt = $pdo->prepare('DELETE FROM assessment_question_links WHERE assessment_id = :assessment_id');
        $stmt->execute(['assessment_id' => $assessmentId]);
    }

    public function insert(array $data): int
    {
        $now = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s.u');
        $pdo = $this->connections->connection();
        $stmt = $pdo->prepare(
            'INSERT INTO assessment_question_links (
                assessment_id, question_id, sequence, created_at, updated_at
             ) VALUES (
                :assessment_id, :question_id, :sequence, :created_at, :updated_at
             )',
        );
        $stmt->execute([
            'assessment_id' => $data['assessment_id'],
            'question_id' => $data['question_id'],
            'sequence' => $data['sequence'],
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return (int) $pdo->lastInsertId();
    }

    /**
     * @param array<string, mixed> $row
     */
    private function mapRow(array $row): AssessmentQuestionLink
    {
        $utc = new DateTimeZone('UTC');

        return new AssessmentQuestionLink(
            linkId: (int) $row['link_id'],
            assessmentId: (int) $row['assessment_id'],
            questionId: (int) $row['question_id'],
            sequence: (int) $row['sequence'],
            createdAt: new DateTimeImmutable((string) $row['created_at'], $utc),
            updatedAt: new DateTimeImmutable((string) $row['updated_at'], $utc),
        );
    }
}
