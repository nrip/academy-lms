<?php

declare(strict_types=1);

namespace Academy\Infrastructure\Identity;

use Academy\Domain\Identity\LearnerProfileRepository;
use Academy\Infrastructure\Database\ConnectionFactory;
use DateTimeImmutable;
use DateTimeZone;

final class PdoLearnerProfileRepository implements LearnerProfileRepository
{
    public function __construct(
        private readonly ConnectionFactory $connections,
    ) {
    }

    public function insertStub(int $userId, DateTimeImmutable $now): int
    {
        $pdo = $this->connections->connection();
        $nowStr = $now->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');

        $stmt = $pdo->prepare(
            'INSERT INTO learner_profiles (user_id, row_version, created_at, updated_at)
             VALUES (:user_id, 1, :created_at, :updated_at)',
        );
        $stmt->execute([
            'user_id' => $userId,
            'created_at' => $nowStr,
            'updated_at' => $nowStr,
        ]);

        return (int) $pdo->lastInsertId();
    }
}
