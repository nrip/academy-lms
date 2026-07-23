<?php

declare(strict_types=1);

/** @var \Academy\Infrastructure\View\Escaper $e */
/** @var string $title */
/** @var string $csrf */
/** @var \Academy\Domain\Payments\Payment $payment */
/** @var list<array<string, mixed>> $history */
/** @var string $amountDisplay */
/** @var string $baseDisplay */
/** @var string $gstDisplay */

ob_start();
?>
<div class="acad-finance-payment-detail">
    <p class="acad-eyebrow mb-2"><?= $e->html('Finance') ?></p>
    <h1 class="h3 mb-3"><?= $e->html($payment->publicReference) ?></h1>

    <dl class="row">
        <dt class="col-sm-3"><?= $e->html('Status') ?></dt>
        <dd class="col-sm-9 text-uppercase"><?= $e->html($payment->status) ?></dd>
        <dt class="col-sm-3"><?= $e->html('Application') ?></dt>
        <dd class="col-sm-9"><?= $e->html((string) $payment->applicationId) ?></dd>
        <dt class="col-sm-3"><?= $e->html('Provider') ?></dt>
        <dd class="col-sm-9"><?= $e->html($payment->provider) ?></dd>
        <dt class="col-sm-3"><?= $e->html('Provider order') ?></dt>
        <dd class="col-sm-9"><?= $e->html($payment->providerOrderId ?? '—') ?></dd>
        <dt class="col-sm-3"><?= $e->html('Base / GST / Total') ?></dt>
        <dd class="col-sm-9">
            <?= $e->html($payment->currency . ' ' . $baseDisplay . ' + ' . $gstDisplay . ' = ' . $amountDisplay) ?>
        </dd>
        <dt class="col-sm-3"><?= $e->html('Attempt') ?></dt>
        <dd class="col-sm-9"><?= $e->html((string) $payment->attemptNumber) ?></dd>
        <dt class="col-sm-3"><?= $e->html('Failure category') ?></dt>
        <dd class="col-sm-9"><?= $e->html($payment->failureCategory ?? '—') ?></dd>
    </dl>

    <h2 class="h5 mt-4"><?= $e->html('Status history') ?></h2>
    <ul>
        <?php foreach ($history as $row): ?>
            <li>
                <?= $e->html((string) $row['created_at']) ?>:
                <?= $e->html((string) $row['status_before']) ?>
                → <?= $e->html((string) $row['status_after']) ?>
                (<?= $e->html((string) $row['source']) ?>)
            </li>
        <?php endforeach; ?>
        <?php if ($history === []): ?>
            <li><?= $e->html('No history.') ?></li>
        <?php endif; ?>
    </ul>

    <p class="mt-4 mb-0">
        <a href="/finance/payments"><?= $e->html('Back to payments') ?></a>
    </p>
</div>
<?php
$content = ob_get_clean();
require dirname(__DIR__, 2) . '/layouts/base.php';
