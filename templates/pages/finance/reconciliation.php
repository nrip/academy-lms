<?php

declare(strict_types=1);

/** @var \Academy\Infrastructure\View\Escaper $e */
/** @var string $title */
/** @var string $csrf */
/** @var array{
 *   payments: list<\Academy\Domain\Payments\Payment>,
 *   payment_total: int,
 *   webhook_events: list<\Academy\Domain\Payments\Webhook\PaymentWebhookEvent>,
 *   webhook_total: int
 * } $overview */

ob_start();
?>
<div class="acad-finance-reconciliation">
    <h1 class="h3 mb-3"><?= $e->html('Payment reconciliation') ?></h1>

    <h2 class="h5"><?= $e->html('Reconciliation pending payments') ?></h2>
    <p class="text-muted"><?= $e->html((string) $overview['payment_total'] . ' payment(s)') ?></p>
    <ul>
        <?php foreach ($overview['payments'] as $payment): ?>
            <li>
                <a href="/finance/payments/<?= $e->attr($payment->paymentId) ?>">
                    <?= $e->html($payment->publicReference) ?>
                </a>
                — <?= $e->html($payment->status) ?>
            </li>
        <?php endforeach; ?>
    </ul>

    <h2 class="h5 mt-4"><?= $e->html('Failed / dead webhook receipts') ?></h2>
    <p class="text-muted"><?= $e->html((string) $overview['webhook_total'] . ' event(s)') ?></p>
    <ul>
        <?php foreach ($overview['webhook_events'] as $event): ?>
            <li>
                <?= $e->html($event->providerEventId) ?>
                — <?= $e->html($event->eventType) ?>
                — <?= $e->html($event->processingStatus) ?>
                <?php if ($event->failureCategoryProcessing !== null): ?>
                    (<?= $e->html($event->failureCategoryProcessing) ?>)
                <?php endif; ?>
            </li>
        <?php endforeach; ?>
    </ul>
</div>
<?php
$content = ob_get_clean();
require dirname(__DIR__, 2) . '/layouts/base.php';
