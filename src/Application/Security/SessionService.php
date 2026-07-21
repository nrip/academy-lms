<?php

declare(strict_types=1);

namespace Academy\Application\Security;

use Academy\Domain\Exception\ServiceUnavailableException;
use Academy\Domain\Security\SessionRecord;
use Academy\Domain\Security\SessionRepository;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Session persistence uses its own short repository transactions only.
 * Never joins unrelated domain transactions (WP-01A Q3).
 */
final class SessionService
{
    public const TIMEOUT_DEFAULT = 'default';
    public const TIMEOUT_PRIVILEGED = 'privileged';

    public function __construct(
        private readonly SessionRepository $sessions,
        private readonly CsrfTokenManager $csrf,
        private readonly LoggerInterface $logger,
        /** @var array{idle_seconds: int, absolute_seconds: int} */
        private readonly array $defaultTimeouts,
        /** @var array{idle_seconds: int, absolute_seconds: int} */
        private readonly array $privilegedTimeouts,
        private readonly int $activityWriteThrottleSeconds = 300,
    ) {
    }

    /**
     * @return array{record: SessionRecord, raw_token: string, raw_csrf: string, set_cookie: bool}
     */
    public function loadOrCreate(?string $rawToken, ?string $ipAddress, ?string $userAgent): array
    {
        try {
            $now = $this->now();
            if ($rawToken !== null && $rawToken !== '') {
                $hash = $this->hashToken($rawToken);
                $existing = $this->sessions->findByTokenHash($hash);
                if ($existing !== null && !$existing->isExpired($now)) {
                    $touched = $this->maybeTouch($existing, $now);

                    return [
                        'record' => $touched,
                        'raw_token' => $rawToken,
                        'raw_csrf' => '',
                        'set_cookie' => false,
                    ];
                }
            }

            return $this->createNew($ipAddress, $userAgent, $now);
        } catch (ServiceUnavailableException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            $this->logger->critical('Session store unavailable.', [
                'exception' => $exception::class,
            ]);
            throw new ServiceUnavailableException('Session store unavailable.');
        }
    }

    /**
     * @return array{record: SessionRecord, raw_token: string, raw_csrf: string}
     */
    public function regenerate(SessionRecord $session): array
    {
        try {
            $now = $this->now();
            $timeouts = $this->timeoutsForClass(self::TIMEOUT_DEFAULT);
            $rawToken = $this->generateToken();
            $rawCsrf = $this->csrf->generateRawToken();
            $this->sessions->regenerate(
                $session->sessionId,
                $this->hashToken($rawToken),
                $this->csrf->hash($rawCsrf),
                $now,
                $now->modify('+' . $timeouts['absolute_seconds'] . ' seconds'),
                $now->modify('+' . $timeouts['idle_seconds'] . ' seconds'),
            );

            $record = $this->sessions->findByTokenHash($this->hashToken($rawToken));
            if ($record === null) {
                throw new ServiceUnavailableException('Session store unavailable.');
            }

            return [
                'record' => $record,
                'raw_token' => $rawToken,
                'raw_csrf' => $rawCsrf,
            ];
        } catch (ServiceUnavailableException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            $this->logger->critical('Session regenerate failed.', ['exception' => $exception::class]);
            throw new ServiceUnavailableException('Session store unavailable.');
        }
    }

    /**
     * Re-issue CSRF synchronizer without rotating the session token (cookie loss recovery).
     *
     * @return array{record: SessionRecord, raw_csrf: string}
     */
    public function reissueCsrf(SessionRecord $session): array
    {
        try {
            $rawCsrf = $this->csrf->generateRawToken();
            $this->sessions->updateCsrfHash($session->sessionId, $this->csrf->hash($rawCsrf));
            $record = $this->sessions->findByTokenHash($session->tokenHash);
            if ($record === null) {
                throw new ServiceUnavailableException('Session store unavailable.');
            }

            return ['record' => $record, 'raw_csrf' => $rawCsrf];
        } catch (ServiceUnavailableException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            $this->logger->critical('CSRF reissue failed.', ['exception' => $exception::class]);
            throw new ServiceUnavailableException('Session store unavailable.');
        }
    }

    public function revoke(SessionRecord $session): void
    {
        try {
            $this->sessions->revoke($session->sessionId, $this->now());
        } catch (Throwable $exception) {
            $this->logger->critical('Session revoke failed.', ['exception' => $exception::class]);
            throw new ServiceUnavailableException('Session store unavailable.');
        }
    }

    /**
     * Bind a user to the session outside any ambient domain transaction.
     *
     * @param array<string, mixed> $payloadMerge
     */
    public function bindUser(SessionRecord $session, int $userId, int $authVersion, array $payloadMerge = []): SessionRecord
    {
        try {
            $this->sessions->bindUser($session->sessionId, $userId, $authVersion, $payloadMerge);
            $record = $this->sessions->findByTokenHash($session->tokenHash);
            if ($record === null) {
                throw new ServiceUnavailableException('Session store unavailable.');
            }

            return $record;
        } catch (ServiceUnavailableException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            $this->logger->critical('Session bind failed.', ['exception' => $exception::class]);
            throw new ServiceUnavailableException('Session store unavailable.');
        }
    }

    /**
     * Best-effort physical revocation after a committed role mutation.
     * Failures must not roll back the role mutation; auth_version remains the logical backstop.
     */
    public function revokeAllForUser(int $userId): int
    {
        try {
            return $this->sessions->revokeAllForUser($userId, $this->now());
        } catch (Throwable $exception) {
            $this->logger->critical('Failed to revoke sessions after role mutation.', [
                'exception' => $exception::class,
                'user_id' => $userId,
            ]);

            return 0;
        }
    }

    public function hashToken(string $rawToken): string
    {
        return hash('sha256', $rawToken);
    }

    public function validateCsrf(SessionRecord $session, ?string $submittedRaw): bool
    {
        if ($session->csrfTokenHash === null || $submittedRaw === null || $submittedRaw === '') {
            return false;
        }

        return $this->csrf->validate($submittedRaw, $session->csrfTokenHash);
    }

    /**
     * @return array{idle_seconds: int, absolute_seconds: int}
     */
    public function timeoutsForClass(string $class): array
    {
        // Privileged exists for config/tests; production activation requires server-side roles (Q6).
        if ($class === self::TIMEOUT_PRIVILEGED) {
            return $this->privilegedTimeouts;
        }

        return $this->defaultTimeouts;
    }

    public function activityWriteThrottleSeconds(): int
    {
        return $this->activityWriteThrottleSeconds;
    }

    /**
     * @return array{record: SessionRecord, raw_token: string, raw_csrf: string, set_cookie: bool}
     */
    private function createNew(?string $ipAddress, ?string $userAgent, \DateTimeImmutable $now): array
    {
        $timeouts = $this->timeoutsForClass(self::TIMEOUT_DEFAULT);
        $rawToken = $this->generateToken();
        $rawCsrf = $this->csrf->generateRawToken();
        $record = $this->sessions->create(
            $this->hashToken($rawToken),
            $this->csrf->hash($rawCsrf),
            [],
            $now,
            $now->modify('+' . $timeouts['absolute_seconds'] . ' seconds'),
            $now->modify('+' . $timeouts['idle_seconds'] . ' seconds'),
            $ipAddress,
            $userAgent !== null && $userAgent !== '' ? hash('sha256', $userAgent) : null,
        );

        return [
            'record' => $record,
            'raw_token' => $rawToken,
            'raw_csrf' => $rawCsrf,
            'set_cookie' => true,
        ];
    }

    private function maybeTouch(SessionRecord $session, \DateTimeImmutable $now): SessionRecord
    {
        $elapsed = $now->getTimestamp() - $session->lastActivityAt->getTimestamp();
        $timeouts = $this->timeoutsForClass(self::TIMEOUT_DEFAULT);
        $idleExpires = $now->modify('+' . $timeouts['idle_seconds'] . ' seconds');

        if ($elapsed >= $this->activityWriteThrottleSeconds) {
            $this->sessions->touch($session->sessionId, $now, $idleExpires);

            return new SessionRecord(
                $session->sessionId,
                $session->tokenHash,
                $session->userId,
                $session->payload,
                $session->csrfTokenHash,
                $session->createdAt,
                $now,
                $session->absoluteExpiresAt,
                $idleExpires,
                $session->revokedAt,
                $session->authVersion,
            );
        }

        return $session;
    }

    private function generateToken(): string
    {
        return bin2hex(random_bytes(32));
    }

    private function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }
}
