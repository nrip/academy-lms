<?php

declare(strict_types=1);

namespace Academy\Infrastructure\Learning;

use Academy\Domain\Learning\ContentProgress;
use Academy\Domain\Learning\ContentProgressCompletionStatus;
use Academy\Domain\Learning\ContentProgressRepository;
use Academy\Infrastructure\Database\ConnectionFactory;
use DateTimeImmutable;
use DateTimeZone;
use PDO;

final class PdoContentProgressRepository implements ContentProgressRepository
{
    private const COLUMNS = 'progress_id, enrolment_id, content_id, completion_status, resume_position,
        watch_percentage, first_accessed_at, last_accessed_at, completed_at, manual_override_flag,
        completion_source, row_version, created_at, updated_at';

    public function __construct(
        private readonly ConnectionFactory $connections,
    ) {
    }

    public function findByEnrolmentAndContent(int $enrolmentId, int $contentId): ?ContentProgress
    {
        $pdo = $this->connections->connection();
        $stmt = $pdo->prepare(
            'SELECT ' . self::COLUMNS . ' FROM content_progress
             WHERE enrolment_id = :enrolment_id AND content_id = :content_id',
        );
        $stmt->execute([
            'enrolment_id' => $enrolmentId,
            'content_id' => $contentId,
        ]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $this->mapRow($row);
    }

    public function listByEnrolmentId(int $enrolmentId): array
    {
        $pdo = $this->connections->connection();
        $stmt = $pdo->prepare(
            'SELECT ' . self::COLUMNS . ' FROM content_progress
             WHERE enrolment_id = :enrolment_id ORDER BY content_id ASC',
        );
        $stmt->execute(['enrolment_id' => $enrolmentId]);

        $rows = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $rows[] = $this->mapRow($row);
        }

        return $rows;
    }

    public function recordAccess(int $enrolmentId, int $contentId, DateTimeImmutable $at): ContentProgress
    {
        $pdo = $this->connections->connection();
        $stamp = $at->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
        $existing = $this->findByEnrolmentAndContent($enrolmentId, $contentId);

        if ($existing === null) {
            $stmt = $pdo->prepare(
                'INSERT INTO content_progress (
                    enrolment_id, content_id, completion_status, resume_position, watch_percentage,
                    first_accessed_at, last_accessed_at, completed_at, manual_override_flag,
                    completion_source, row_version, created_at, updated_at
                 ) VALUES (
                    :enrolment_id, :content_id, :completion_status, NULL, NULL,
                    :first_accessed_at, :last_accessed_at, NULL, 0,
                    NULL, 1, :created_at, :updated_at
                 )',
            );
            $stmt->execute([
                'enrolment_id' => $enrolmentId,
                'content_id' => $contentId,
                'completion_status' => ContentProgressCompletionStatus::IN_PROGRESS,
                'first_accessed_at' => $stamp,
                'last_accessed_at' => $stamp,
                'created_at' => $stamp,
                'updated_at' => $stamp,
            ]);
        } elseif (!$existing->isCompleted()) {
            $stmt = $pdo->prepare(
                'UPDATE content_progress SET
                    completion_status = CASE
                        WHEN completion_status = :not_started THEN :in_progress
                        ELSE completion_status
                    END,
                    last_accessed_at = :last_accessed_at,
                    first_accessed_at = COALESCE(first_accessed_at, :first_accessed_at),
                    row_version = row_version + 1,
                    updated_at = :updated_at
                 WHERE progress_id = :progress_id',
            );
            $stmt->execute([
                'not_started' => ContentProgressCompletionStatus::NOT_STARTED,
                'in_progress' => ContentProgressCompletionStatus::IN_PROGRESS,
                'last_accessed_at' => $stamp,
                'first_accessed_at' => $stamp,
                'updated_at' => $stamp,
                'progress_id' => $existing->progressId,
            ]);
        } else {
            $stmt = $pdo->prepare(
                'UPDATE content_progress SET
                    last_accessed_at = :last_accessed_at,
                    row_version = row_version + 1,
                    updated_at = :updated_at
                 WHERE progress_id = :progress_id',
            );
            $stmt->execute([
                'last_accessed_at' => $stamp,
                'updated_at' => $stamp,
                'progress_id' => $existing->progressId,
            ]);
        }

        $reloaded = $this->findByEnrolmentAndContent($enrolmentId, $contentId);
        if ($reloaded === null) {
            throw new \RuntimeException('ContentProgress missing after recordAccess.');
        }

        return $reloaded;
    }

    public function markCompleted(
        int $enrolmentId,
        int $contentId,
        string $completionSource,
        DateTimeImmutable $at,
        ?int $expectedRowVersion = null,
    ): ContentProgress {
        $pdo = $this->connections->connection();
        $stamp = $at->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
        $existing = $this->findByEnrolmentAndContent($enrolmentId, $contentId);

        if ($existing === null) {
            $stmt = $pdo->prepare(
                'INSERT INTO content_progress (
                    enrolment_id, content_id, completion_status, resume_position, watch_percentage,
                    first_accessed_at, last_accessed_at, completed_at, manual_override_flag,
                    completion_source, row_version, created_at, updated_at
                 ) VALUES (
                    :enrolment_id, :content_id, :completion_status, NULL, NULL,
                    :first_accessed_at, :last_accessed_at, :completed_at, 0,
                    :completion_source, 1, :created_at, :updated_at
                 )',
            );
            $stmt->execute([
                'enrolment_id' => $enrolmentId,
                'content_id' => $contentId,
                'completion_status' => ContentProgressCompletionStatus::COMPLETED,
                'first_accessed_at' => $stamp,
                'last_accessed_at' => $stamp,
                'completed_at' => $stamp,
                'completion_source' => $completionSource,
                'created_at' => $stamp,
                'updated_at' => $stamp,
            ]);
        } elseif ($existing->isCompleted()) {
            return $existing;
        } else {
            $sql = 'UPDATE content_progress SET
                completion_status = :completion_status,
                completed_at = :completed_at,
                last_accessed_at = :last_accessed_at,
                first_accessed_at = COALESCE(first_accessed_at, :first_accessed_at),
                completion_source = :completion_source,
                row_version = row_version + 1,
                updated_at = :updated_at
             WHERE progress_id = :progress_id';
            $params = [
                'completion_status' => ContentProgressCompletionStatus::COMPLETED,
                'completed_at' => $stamp,
                'last_accessed_at' => $stamp,
                'first_accessed_at' => $stamp,
                'completion_source' => $completionSource,
                'updated_at' => $stamp,
                'progress_id' => $existing->progressId,
            ];
            if ($expectedRowVersion !== null) {
                $sql .= ' AND row_version = :row_version';
                $params['row_version'] = $expectedRowVersion;
            }
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            if ($stmt->rowCount() === 0 && $expectedRowVersion !== null) {
                throw new \Academy\Domain\Exception\ConflictException('Content progress was updated concurrently. Refresh and try again.');
            }
        }

        $reloaded = $this->findByEnrolmentAndContent($enrolmentId, $contentId);
        if ($reloaded === null) {
            throw new \RuntimeException('ContentProgress missing after markCompleted.');
        }

        return $reloaded;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function mapRow(array $row): ContentProgress
    {
        $utc = new DateTimeZone('UTC');

        return new ContentProgress(
            progressId: (int) $row['progress_id'],
            enrolmentId: (int) $row['enrolment_id'],
            contentId: (int) $row['content_id'],
            completionStatus: (string) $row['completion_status'],
            resumePosition: $row['resume_position'] === null ? null : (string) $row['resume_position'],
            watchPercentage: $row['watch_percentage'] === null ? null : (string) $row['watch_percentage'],
            firstAccessedAt: $row['first_accessed_at'] === null ? null : new DateTimeImmutable((string) $row['first_accessed_at'], $utc),
            lastAccessedAt: $row['last_accessed_at'] === null ? null : new DateTimeImmutable((string) $row['last_accessed_at'], $utc),
            completedAt: $row['completed_at'] === null ? null : new DateTimeImmutable((string) $row['completed_at'], $utc),
            manualOverrideFlag: (int) $row['manual_override_flag'] === 1,
            completionSource: $row['completion_source'] === null ? null : (string) $row['completion_source'],
            rowVersion: (int) $row['row_version'],
            createdAt: new DateTimeImmutable((string) $row['created_at'], $utc),
            updatedAt: new DateTimeImmutable((string) $row['updated_at'], $utc),
        );
    }
}
