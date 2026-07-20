<?php

declare(strict_types=1);

namespace Academy\Application\Outbox;

use Academy\Domain\Outbox\OutboxRepository;
use Academy\Domain\Outbox\OutboxTransport;
use Psr\Log\LoggerInterface;

final class OutboxRelayService
{
    public function __construct(
        private readonly OutboxRepository $repository,
        private readonly OutboxTransport $transport,
        private readonly LoggerInterface $logger,
        private readonly int $leaseSeconds,
        private readonly int $maxAttempts,
        private readonly int $backoffBaseSeconds,
        private readonly int $backoffCapSeconds,
    ) {
    }

    public function transportConfigured(): bool
    {
        return $this->transport->isConfigured();
    }

    /**
     * @return int Number of messages processed
     */
    public function run(string $workerId, int $limit = 10): int
    {
        if (!$this->transport->isConfigured()) {
            $this->logger->warning('Outbox relay skipped: transport not configured.');

            return 0;
        }

        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $claimed = $this->repository->claim($workerId, $now, $this->leaseSeconds, $limit);
        $processed = 0;

        foreach ($claimed as $message) {
            try {
                $this->transport->publish($message->eventType, $message->payload, $message->idempotencyKey);
                if ($this->repository->markPublished(
                    $message->id,
                    $message->lockedBy,
                    $message->claimToken,
                    new \DateTimeImmutable('now', new \DateTimeZone('UTC')),
                )) {
                    ++$processed;
                } else {
                    $this->logger->warning('Outbox markPublished skipped: lease ownership lost.', [
                        'outbox_message_id' => $message->id,
                        'locked_by' => $message->lockedBy,
                    ]);
                }
            } catch (\Throwable $exception) {
                $backoff = $this->backoffSeconds($message->attemptCount);
                $accepted = $this->repository->markRetryOrDead(
                    $message->id,
                    $message->lockedBy,
                    $message->claimToken,
                    $message->attemptCount,
                    $this->maxAttempts,
                    $exception->getMessage(),
                    new \DateTimeImmutable('now', new \DateTimeZone('UTC')),
                    $backoff,
                );
                if (!$accepted) {
                    $this->logger->warning('Outbox markRetryOrDead skipped: lease ownership lost.', [
                        'outbox_message_id' => $message->id,
                        'locked_by' => $message->lockedBy,
                    ]);
                } elseif ($message->attemptCount >= $this->maxAttempts) {
                    $this->logger->critical('Outbox message moved to dead letter.', [
                        'outbox_message_id' => $message->id,
                        'event_type' => $message->eventType,
                    ]);
                }
            }
        }

        return $processed;
    }

    private function backoffSeconds(int $attemptCount): int
    {
        $exp = (int) min($this->backoffCapSeconds, $this->backoffBaseSeconds * (2 ** max(0, $attemptCount - 1)));
        $jitter = random_int(0, max(1, (int) floor($exp * 0.2)));

        return min($this->backoffCapSeconds, $exp + $jitter);
    }
}
