<?php

declare(strict_types=1);

namespace Academy\Application\Notifications;

use Academy\Application\Audit\AuditService;
use Academy\Domain\Audit\NotificationAuditPayload;
use Academy\Domain\Exception\DomainRuleException;
use Academy\Domain\Exception\ValidationException;
use Academy\Domain\Identity\VerificationChallengeRepository;
use Academy\Domain\Identity\VerificationTokenRepository;
use Academy\Domain\Outbox\OutboxMessage;
use Academy\Domain\Outbox\OutboxRepository;
use Academy\Infrastructure\Database\TransactionManager;
use PDO;

/**
 * Single ambient TX: outbox fencing + delivery status clear + audit.
 */
final class DeliveryFinaliser
{
    public function __construct(
        private readonly TransactionManager $transactions,
        private readonly OutboxRepository $outbox,
        private readonly VerificationTokenRepository $tokens,
        private readonly VerificationChallengeRepository $challenges,
        private readonly AuditService $audit,
        private readonly int $maxAttempts,
    ) {
    }

    public function finalizeDelivered(
        string $kind,
        int $recordId,
        OutboxMessage $message,
        ?string $providerMessageId,
    ): bool {
        $this->assertKind($kind);

        return $this->transactions->run(function (PDO $pdo) use ($kind, $recordId, $message, $providerMessageId): bool {
            unset($pdo);
            $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

            if (!$this->outbox->markPublished(
                $message->id,
                $message->lockedBy,
                $message->claimToken,
                $now,
            )) {
                throw new DomainRuleException('Delivery finalisation is stale.');
            }

            $purposeOrChannel = $this->purposeOrChannel($kind, $recordId);

            $ok = $kind === 'token'
                ? $this->tokens->markDelivered($recordId, $providerMessageId, $now)
                : $this->challenges->markDelivered($recordId, $providerMessageId, $now);

            if (!$ok) {
                throw new DomainRuleException('Delivery finalisation is stale.');
            }

            $this->audit->record(
                new NotificationAuditPayload(
                    'notification.delivery_succeeded',
                    $kind === 'token' ? 'verification_token' : 'verification_challenge',
                    (string) $recordId,
                    next: [
                        'record_id' => $recordId,
                        'purpose_or_channel' => $purposeOrChannel,
                        'outbox_message_id' => $message->id,
                        'provider_message_id' => $providerMessageId,
                    ],
                ),
                'system',
                null,
                'worker',
            );

            return true;
        });
    }

    public function finalizeTerminal(
        string $kind,
        int $recordId,
        OutboxMessage $message,
        string $redactedError,
    ): bool {
        $this->assertKind($kind);

        return $this->transactions->run(function (PDO $pdo) use ($kind, $recordId, $message, $redactedError): bool {
            unset($pdo);
            $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

            // Force dead-letter: attemptCount >= maxAttempts → dead.
            if (!$this->outbox->markRetryOrDead(
                $message->id,
                $message->lockedBy,
                $message->claimToken,
                $this->maxAttempts,
                $this->maxAttempts,
                $redactedError,
                $now,
                0,
            )) {
                throw new DomainRuleException('Delivery finalisation is stale.');
            }

            $ok = $kind === 'token'
                ? $this->tokens->markTerminal($recordId, $redactedError, $now)
                : $this->challenges->markTerminal($recordId, $redactedError, $now);

            if (!$ok) {
                throw new DomainRuleException('Delivery finalisation is stale.');
            }

            $this->audit->record(
                new NotificationAuditPayload(
                    'notification.delivery_terminal',
                    $kind === 'token' ? 'verification_token' : 'verification_challenge',
                    (string) $recordId,
                    next: [
                        'record_id' => $recordId,
                        'outbox_message_id' => $message->id,
                        'attempt_count' => $message->attemptCount,
                    ],
                ),
                'system',
                null,
                'worker',
            );

            return true;
        });
    }

    private function assertKind(string $kind): void
    {
        if ($kind !== 'token' && $kind !== 'challenge') {
            throw new ValidationException('Invalid delivery record kind.');
        }
    }

    private function purposeOrChannel(string $kind, int $recordId): string
    {
        if ($kind === 'token') {
            $record = $this->tokens->findById($recordId);
            if ($record === null) {
                throw new DomainRuleException('Delivery finalisation is stale.');
            }

            return $record->purpose;
        }

        $record = $this->challenges->findById($recordId);
        if ($record === null) {
            throw new DomainRuleException('Delivery finalisation is stale.');
        }

        return $record->channel;
    }
}
