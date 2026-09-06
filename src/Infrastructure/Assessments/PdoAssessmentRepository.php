<?php

declare(strict_types=1);

namespace Academy\Infrastructure\Assessments;

use Academy\Domain\Assessments\Assessment;
use Academy\Domain\Assessments\AssessmentRepository;
use Academy\Infrastructure\Database\ConnectionFactory;
use DateTimeImmutable;
use DateTimeZone;
use PDO;

final class PdoAssessmentRepository implements AssessmentRepository
{
    private const COLUMNS = 'assessment_id, content_id, title, questions_per_attempt, pass_threshold_percent,
        time_limit_seconds, max_attempts, cooldown_seconds, randomise_questions, randomise_options,
        created_at, updated_at';

    public function __construct(
        private readonly ConnectionFactory $connections,
    ) {
    }

    public function findById(int $assessmentId): ?Assessment
    {
        $pdo = $this->connections->connection();
        $stmt = $pdo->prepare('SELECT ' . self::COLUMNS . ' FROM assessments WHERE assessment_id = :id LIMIT 1');
        $stmt->execute(['id' => $assessmentId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $this->mapRow($row);
    }

    public function findByContentId(int $contentId): ?Assessment
    {
        $pdo = $this->connections->connection();
        $stmt = $pdo->prepare('SELECT ' . self::COLUMNS . ' FROM assessments WHERE content_id = :content_id LIMIT 1');
        $stmt->execute(['content_id' => $contentId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $this->mapRow($row);
    }

    public function insert(array $data): int
    {
        $now = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s.u');
        $pdo = $this->connections->connection();
        $stmt = $pdo->prepare(
            'INSERT INTO assessments (
                content_id, title, questions_per_attempt, pass_threshold_percent, time_limit_seconds,
                max_attempts, cooldown_seconds, randomise_questions, randomise_options, created_at, updated_at
             ) VALUES (
                :content_id, :title, :questions_per_attempt, :pass_threshold_percent, :time_limit_seconds,
                :max_attempts, :cooldown_seconds, :randomise_questions, :randomise_options, :created_at, :updated_at
             )',
        );
        $stmt->execute([
            'content_id' => $data['content_id'],
            'title' => $data['title'],
            'questions_per_attempt' => $data['questions_per_attempt'],
            'pass_threshold_percent' => $data['pass_threshold_percent'],
            'time_limit_seconds' => $data['time_limit_seconds'],
            'max_attempts' => $data['max_attempts'],
            'cooldown_seconds' => $data['cooldown_seconds'],
            'randomise_questions' => $data['randomise_questions'] ? 1 : 0,
            'randomise_options' => $data['randomise_options'] ? 1 : 0,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return (int) $pdo->lastInsertId();
    }

    public function update(int $assessmentId, array $data): bool
    {
        $now = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s.u');
        $pdo = $this->connections->connection();
        $stmt = $pdo->prepare(
            'UPDATE assessments SET
                title = :title,
                questions_per_attempt = :questions_per_attempt,
                pass_threshold_percent = :pass_threshold_percent,
                time_limit_seconds = :time_limit_seconds,
                max_attempts = :max_attempts,
                cooldown_seconds = :cooldown_seconds,
                randomise_questions = :randomise_questions,
                randomise_options = :randomise_options,
                updated_at = :updated_at
             WHERE assessment_id = :assessment_id',
        );
        $stmt->execute([
            'title' => $data['title'],
            'questions_per_attempt' => $data['questions_per_attempt'],
            'pass_threshold_percent' => $data['pass_threshold_percent'],
            'time_limit_seconds' => $data['time_limit_seconds'],
            'max_attempts' => $data['max_attempts'],
            'cooldown_seconds' => $data['cooldown_seconds'],
            'randomise_questions' => $data['randomise_questions'] ? 1 : 0,
            'randomise_options' => $data['randomise_options'] ? 1 : 0,
            'updated_at' => $now,
            'assessment_id' => $assessmentId,
        ]);

        return $stmt->rowCount() > 0;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function mapRow(array $row): Assessment
    {
        $utc = new DateTimeZone('UTC');

        return new Assessment(
            assessmentId: (int) $row['assessment_id'],
            contentId: (int) $row['content_id'],
            title: (string) $row['title'],
            questionsPerAttempt: (int) $row['questions_per_attempt'],
            passThresholdPercent: number_format((float) $row['pass_threshold_percent'], 2, '.', ''),
            timeLimitSeconds: $row['time_limit_seconds'] === null ? null : (int) $row['time_limit_seconds'],
            maxAttempts: (int) $row['max_attempts'],
            cooldownSeconds: $row['cooldown_seconds'] === null ? null : (int) $row['cooldown_seconds'],
            randomiseQuestions: (int) $row['randomise_questions'] === 1,
            randomiseOptions: (int) $row['randomise_options'] === 1,
            createdAt: new DateTimeImmutable((string) $row['created_at'], $utc),
            updatedAt: new DateTimeImmutable((string) $row['updated_at'], $utc),
        );
    }
}
