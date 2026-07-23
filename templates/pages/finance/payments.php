<?php

declare(strict_types=1);

use Academy\Domain\Payments\PaymentAmountSnapshot;

/** @var \Academy\Infrastructure\View\Escaper $e */
/** @var string $title */
/** @var string $csrf */
/** @var array{items: list<\Academy\Domain\Payments\Payment>, total: int, limit: int, offset: int} $page */
/** @var array{status: ?string, public_reference: ?string, provider_order_id: ?string, application_id: ?int} $filters */

ob_start();
?>
<div class="acad-finance-payments">
    <p class="acad-eyebrow mb-2"><?= $e->html('Finance') ?></p>
    <h1 class="h3 mb-4"><?= $e->html('Payments') ?></h1>

    <form method="get" action="/finance/payments" class="row g-2 mb-4">
        <div class="col-md-3">
            <label class="form-label" for="status"><?= $e->html('Status') ?></label>
            <input class="form-control" id="status" name="status" value="<?= $e->attr((string) ($filters['status'] ?? '')) ?>">
        </div>
        <div class="col-md-3">
            <label class="form-label" for="public_reference"><?= $e->html('Public reference') ?></label>
            <input class="form-control" id="public_reference" name="public_reference" value="<?= $e->attr((string) ($filters['public_reference'] ?? '')) ?>">
        </div>
        <div class="col-md-3">
            <label class="form-label" for="provider_order_id"><?= $e->html('Provider order') ?></label>
            <input class="form-control" id="provider_order_id" name="provider_order_id" value="<?= $e->attr((string) ($filters['provider_order_id'] ?? '')) ?>">
        </div>
        <div class="col-md-2">
            <label class="form-label" for="application_id"><?= $e->html('Application ID') ?></label>
            <input class="form-control" id="application_id" name="application_id" value="<?= $e->attr($filters['application_id'] !== null ? (string) $filters['application_id'] : '') ?>">
        </div>
        <div class="col-md-1 d-flex align-items-end">
            <button type="submit" class="btn btn-primary w-100"><?= $e->html('Filter') ?></button>
        </div>
    </form>

    <p class="text-muted"><?= $e->html('Showing ' . count($page['items']) . ' of ' . $page['total']) ?></p>

    <div class="table-responsive">
        <table class="table table-sm align-middle">
            <thead>
                <tr>
                    <th><?= $e->html('Reference') ?></th>
                    <th><?= $e->html('Application') ?></th>
                    <th><?= $e->html('Status') ?></th>
                    <th><?= $e->html('Amount') ?></th>
                    <th><?= $e->html('Provider order') ?></th>
                    <th><?= $e->html('Attempt') ?></th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($page['items'] as $payment): ?>
                <tr>
                    <td>
                        <a href="/finance/payments/<?= $e->attr($payment->paymentId) ?>">
                            <?= $e->html($payment->publicReference) ?>
                        </a>
                    </td>
                    <td><?= $e->html((string) $payment->applicationId) ?></td>
                    <td class="text-uppercase"><?= $e->html($payment->status) ?></td>
                    <td><?= $e->html($payment->currency . ' ' . PaymentAmountSnapshot::minorToDecimal($payment->amountMinor)) ?></td>
                    <td><?= $e->html($payment->providerOrderId ?? '—') ?></td>
                    <td><?= $e->html((string) $payment->attemptNumber) ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if ($page['items'] === []): ?>
                <tr><td colspan="6"><?= $e->html('No payments found.') ?></td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
<?php
$content = ob_get_clean();
require dirname(__DIR__, 2) . '/layouts/base.php';
