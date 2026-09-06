<?php

declare(strict_types=1);

namespace Academy\Application\Ops;

use Academy\Application\Credentials\DocumentScanWorker;
use Academy\Application\Notifications\IdentityNotificationDeliveryWorker;
use Academy\Application\Notifications\TransactionalNotificationDeliveryWorker;
use Academy\Application\Outbox\OutboxRelayService;
use Academy\Application\Payments\PaymentReconciliationService;
use Academy\Application\Payments\PaymentWebhookProcessor;
use RuntimeException;

/**
 * Bounded one-shot processing of demo workers in the correct order.
 * Environment-gated; not exposed as an HTTP endpoint.
 */
final class DemoProcessService
{
    private const DEFAULT_LIMIT = 25;

    public function __construct(
        private readonly EnvironmentCapability $capability,
        private readonly DocumentScanWorker $documentScan,
        private readonly OutboxRelayService $outboxRelay,
        private readonly PaymentWebhookProcessor $webhookProcessor,
        private readonly PaymentReconciliationService $reconciliation,
        private readonly IdentityNotificationDeliveryWorker $identityNotifications,
        private readonly TransactionalNotificationDeliveryWorker $transactionalNotifications,
    ) {
    }

    /**
     * @return array{worker_id: string, steps: list<array{step: string, processed: int|string}>}
     */
    public function process(string $workerId, int $limit = self::DEFAULT_LIMIT): array
    {
        $this->assertAllowed();
        if ($limit < 1 || $limit > 100) {
            throw new RuntimeException('demo:process limit must be between 1 and 100.');
        }

        $steps = [];

        $scanned = $this->documentScan->run($workerId, $limit);
        $steps[] = ['step' => 'document:scan', 'processed' => $scanned];

        if ($this->outboxRelay->transportConfigured()) {
            $relayed = $this->outboxRelay->run($workerId, $limit);
            $steps[] = ['step' => 'outbox:relay', 'processed' => $relayed];
        } else {
            $steps[] = ['step' => 'outbox:relay', 'processed' => 'skipped (transport not configured)'];
        }

        $webhooks = $this->webhookProcessor->run($workerId, $limit);
        $steps[] = ['step' => 'payment:webhook-process', 'processed' => $webhooks];

        $reconciled = $this->reconciliation->run($workerId, $limit);
        $steps[] = ['step' => 'payment:reconcile', 'processed' => $reconciled];

        $identity = $this->identityNotifications->run($workerId, $limit);
        $steps[] = ['step' => 'notification:identity', 'processed' => $identity];

        $transactional = $this->transactionalNotifications->run($workerId, $limit);
        $steps[] = ['step' => 'notification:transactional', 'processed' => $transactional];

        return [
            'worker_id' => $workerId,
            'steps' => $steps,
        ];
    }

    private function assertAllowed(): void
    {
        if (!$this->capability->allowsUatSeedAndReset()) {
            throw new RuntimeException(
                'demo:process refused for APP_ENV=' . $this->capability->name()
                . ' (allowed: local|testing|ci|uat).',
            );
        }
    }
}
