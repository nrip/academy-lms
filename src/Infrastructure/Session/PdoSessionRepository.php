<?php

declare(strict_types=1);

namespace Academy\Infrastructure\Session;

use Academy\Domain\Security\SessionRecord;
use Academy\Domain\Security\SessionRepository;
use Academy\Infrastructure\Database\ConnectionFactory;
use LogicException;
use PDO;

/**
 * Each method uses its own short transaction. Must not join domain transactions (Q3).
 */
final class PdoSessionRepository implements SessionRepository
{
    public function __construct(
        private readonly ConnectionFactory $connections,
    ) {
    }

    public function findByTokenHash(string $tokenHash): ?SessionRecord
    {
        $pdo = $this->connections->connection();
        $stmt = $pdo->prepare(
            'SELECT session_id, session_token_hash, user_id, payload, csrf_token_hash,
                    created_at, last_activity_at, absolute_expires_at, idle_expires_at, revoked_at
             FROM sessions WHERE session_token_hash = :hash LIMIT 1',
        );
        $stmt->execute(['hash' => $tokenHash]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }

        return $this->map($row);
    }

    public function create(
        string $tokenHash,
        ?string $csrfTokenHash,
        array $payload,
        \DateTimeImmutable $createdAt,
        \DateTimeImmutable $absoluteExpiresAt,
        \DateTimeImmutable $idleExpiresAt,
        ?string $ipAddress,
        ?string $userAgentHash,
    ): SessionRecord {
        $pdo = $this->connections->connection();
        $this->assertNoAmbientTransaction($pdo);
        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare(
                'INSERT INTO sessions (
                    session_token_hash, user_id, payload, csrf_token_hash, ip_address, user_agent_hash,
                    created_at, last_activity_at, absolute_expires_at, idle_expires_at, revoked_at, updated_at
                ) VALUES (
                    :token_hash, NULL, :payload, :csrf_hash, :ip, :ua_hash,
                    :created_at, :last_activity_at, :absolute_expires_at, :idle_expires_at, NULL, :updated_at
                )',
            );
            $ts = $createdAt->format('Y-m-d H:i:s.u');
            $stmt->execute([
                'token_hash' => $tokenHash,
                'payload' => json_encode($payload, JSON_THROW_ON_ERROR),
                'csrf_hash' => $csrfTokenHash,
                'ip' => $ipAddress !== null ? inet_pton($ipAddress) : null,
                'ua_hash' => $userAgentHash,
                'created_at' => $ts,
                'last_activity_at' => $ts,
                'absolute_expires_at' => $absoluteExpiresAt->format('Y-m-d H:i:s.u'),
                'idle_expires_at' => $idleExpiresAt->format('Y-m-d H:i:s.u'),
                'updated_at' => $ts,
            ]);
            $pdo->commit();
        } catch (\Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $exception;
        }

        $record = $this->findByTokenHash($tokenHash);
        if ($record === null) {
            throw new \RuntimeException('Failed to load created session.');
        }

        return $record;
    }

    public function regenerate(
        int $sessionId,
        string $newTokenHash,
        ?string $newCsrfTokenHash,
        \DateTimeImmutable $now,
        \DateTimeImmutable $absoluteExpiresAt,
        \DateTimeImmutable $idleExpiresAt,
    ): void {
        $pdo = $this->connections->connection();
        $this->assertNoAmbientTransaction($pdo);
        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare(
                'UPDATE sessions SET
                    session_token_hash = ?,
                    csrf_token_hash = ?,
                    last_activity_at = ?,
                    absolute_expires_at = ?,
                    idle_expires_at = ?,
                    updated_at = ?
                 WHERE session_id = ? AND revoked_at IS NULL',
            );
            $nowStr = $now->format('Y-m-d H:i:s.u');
            $stmt->execute([
                $newTokenHash,
                $newCsrfTokenHash,
                $nowStr,
                $absoluteExpiresAt->format('Y-m-d H:i:s.u'),
                $idleExpiresAt->format('Y-m-d H:i:s.u'),
                $nowStr,
                $sessionId,
            ]);
            $pdo->commit();
        } catch (\Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $exception;
        }
    }

    public function updateCsrfHash(int $sessionId, string $csrfTokenHash): void
    {
        $pdo = $this->connections->connection();
        $this->assertNoAmbientTransaction($pdo);
        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare(
                'UPDATE sessions SET csrf_token_hash = :csrf_hash, updated_at = :now
                 WHERE session_id = :id AND revoked_at IS NULL',
            );
            $stmt->execute([
                'csrf_hash' => $csrfTokenHash,
                'now' => (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s.u'),
                'id' => $sessionId,
            ]);
            $pdo->commit();
        } catch (\Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $exception;
        }
    }

    public function touch(
        int $sessionId,
        \DateTimeImmutable $lastActivityAt,
        \DateTimeImmutable $idleExpiresAt,
    ): void {
        $pdo = $this->connections->connection();
        $this->assertNoAmbientTransaction($pdo);
        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare(
                'UPDATE sessions SET last_activity_at = ?, idle_expires_at = ?, updated_at = ?
                 WHERE session_id = ? AND revoked_at IS NULL',
            );
            $stmt->execute([
                $lastActivityAt->format('Y-m-d H:i:s.u'),
                $idleExpiresAt->format('Y-m-d H:i:s.u'),
                $lastActivityAt->format('Y-m-d H:i:s.u'),
                $sessionId,
            ]);
            $pdo->commit();
        } catch (\Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $exception;
        }
    }

    public function revoke(int $sessionId, \DateTimeImmutable $revokedAt): void
    {
        $pdo = $this->connections->connection();
        $this->assertNoAmbientTransaction($pdo);
        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare(
                'UPDATE sessions SET revoked_at = ?, updated_at = ?
                 WHERE session_id = ? AND revoked_at IS NULL',
            );
            $ts = $revokedAt->format('Y-m-d H:i:s.u');
            $stmt->execute([$ts, $ts, $sessionId]);
            $pdo->commit();
        } catch (\Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $exception;
        }
    }

    public function bindUser(int $sessionId, int $userId): void
    {
        $pdo = $this->connections->connection();
        $this->assertNoAmbientTransaction($pdo);
        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare(
                'UPDATE sessions SET user_id = :user_id, updated_at = :now WHERE session_id = :id AND revoked_at IS NULL',
            );
            $stmt->execute([
                'user_id' => $userId,
                'now' => (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s.u'),
                'id' => $sessionId,
            ]);
            $pdo->commit();
        } catch (\Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $exception;
        }
    }

    public function deleteExpired(\DateTimeImmutable $now, int $limit = 1000): int
    {
        $pdo = $this->connections->connection();
        $stmt = $pdo->prepare(
            'DELETE FROM sessions
             WHERE revoked_at IS NOT NULL
                OR absolute_expires_at < ?
                OR idle_expires_at < ?
             LIMIT ' . (int) $limit,
        );
        $nowStr = $now->format('Y-m-d H:i:s.u');
        $stmt->execute([$nowStr, $nowStr]);

        return $stmt->rowCount();
    }

    private function assertNoAmbientTransaction(PDO $pdo): void
    {
        if ($pdo->inTransaction()) {
            throw new LogicException(
                'Session write refused: PDO connection is already inside an ambient transaction.',
            );
        }
    }

    /**
     * @param array<string, mixed> $row
     */
    private function map(array $row): SessionRecord
    {
        /** @var array<string, mixed> $payload */
        $payload = json_decode((string) $row['payload'], true, 512, JSON_THROW_ON_ERROR);

        return new SessionRecord(
            sessionId: (int) $row['session_id'],
            tokenHash: (string) $row['session_token_hash'],
            userId: $row['user_id'] !== null ? (int) $row['user_id'] : null,
            payload: $payload,
            csrfTokenHash: $row['csrf_token_hash'] !== null ? (string) $row['csrf_token_hash'] : null,
            createdAt: new \DateTimeImmutable((string) $row['created_at'], new \DateTimeZone('UTC')),
            lastActivityAt: new \DateTimeImmutable((string) $row['last_activity_at'], new \DateTimeZone('UTC')),
            absoluteExpiresAt: new \DateTimeImmutable((string) $row['absolute_expires_at'], new \DateTimeZone('UTC')),
            idleExpiresAt: new \DateTimeImmutable((string) $row['idle_expires_at'], new \DateTimeZone('UTC')),
            revokedAt: $row['revoked_at'] !== null
                ? new \DateTimeImmutable((string) $row['revoked_at'], new \DateTimeZone('UTC'))
                : null,
        );
    }
}
