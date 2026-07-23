<?php

declare(strict_types=1);

use Academy\Domain\Admissions\ApplicationStatus;
use Academy\Domain\Payments\PaymentAmountSnapshot;
use Academy\Domain\Payments\PaymentStatus;

/** @var \Academy\Infrastructure\View\Escaper $e */
/** @var string $title */
/** @var string $csrf */
/** @var \Academy\Application\Payments\PaymentCheckoutView $view */
/** @var string|null $error */

$application = $view->application;
$snapshot = $view->snapshotPreview;

ob_start();
?>
<div class="acad-payment-checkout">
    <p class="acad-eyebrow mb-2"><?= $e->html('Payment') ?></p>
    <h1 class="h3 mb-3"><?= $e->html('Checkout') ?></h1>
    <p class="text-muted mb-4">
        Application <?= $e->html($application->applicationNumber) ?>
        <span class="badge bg-secondary text-uppercase"><?= $e->html($application->status) ?></span>
    </p>

    <?php if ($error !== null && $error !== ''): ?>
        <div class="alert alert-danger" role="alert"><?= $e->html($error) ?></div>
    <?php endif; ?>

    <?php if ($application->status !== ApplicationStatus::PAYMENT_PENDING): ?>
        <div class="alert alert-warning" role="status">
            <?= $e->html('Payment is only available when your application is payment pending.') ?>
        </div>
    <?php elseif ($snapshot === null): ?>
        <div class="alert alert-danger" role="alert">
            <?= $e->html('Payable amount could not be calculated for this application.') ?>
        </div>
    <?php else: ?>
        <div class="mb-4">
            <h2 class="h5"><?= $e->html('Payable summary') ?></h2>
            <dl class="row">
                <dt class="col-sm-4"><?= $e->html('Course fee') ?></dt>
                <dd class="col-sm-8"><?= $e->html($snapshot->currency . ' ' . PaymentAmountSnapshot::minorToDecimal($snapshot->baseFeeMinor)) ?></dd>
                <dt class="col-sm-4"><?= $e->html('GST (' . $snapshot->gstRatePercent . '%)') ?></dt>
                <dd class="col-sm-8"><?= $e->html($snapshot->currency . ' ' . PaymentAmountSnapshot::minorToDecimal($snapshot->gstMinor)) ?></dd>
                <dt class="col-sm-4"><?= $e->html('Total payable') ?></dt>
                <dd class="col-sm-8 fw-semibold"><?= $e->html($snapshot->currency . ' ' . PaymentAmountSnapshot::minorToDecimal($snapshot->totalPayableMinor)) ?></dd>
            </dl>
            <p class="small text-muted mb-0"><?= $e->html('Amounts are fixed from your approved application. Client-supplied amounts are never used.') ?></p>
        </div>

        <?php if ($view->canInitiate): ?>
            <form method="post" action="/applications/<?= $e->attr($application->applicationId) ?>/payments" class="mb-4">
                <input type="hidden" name="_csrf" value="<?= $e->attr($csrf) ?>">
                <button type="submit" class="btn btn-primary"><?= $e->html('Pay now') ?></button>
            </form>
        <?php else: ?>
            <div class="alert alert-info" role="status">
                <?= $e->html('A payment attempt is already in progress, or a new attempt is not allowed yet.') ?>
                <a class="alert-link" href="/applications/<?= $e->attr($application->applicationId) ?>/payment-result"><?= $e->html('View payment status') ?></a>
            </div>
        <?php endif; ?>
    <?php endif; ?>

    <?php if ($view->attempts !== []): ?>
        <h2 class="h5 mt-4"><?= $e->html('Previous attempts') ?></h2>
        <ul class="list-unstyled">
            <?php foreach ($view->attempts as $attempt): ?>
                <li class="mb-2">
                    <a href="/applications/<?= $e->attr($application->applicationId) ?>/payments/<?= $e->attr($attempt->paymentId) ?>">
                        <?= $e->html($attempt->publicReference) ?>
                    </a>
                    <span class="badge bg-light text-dark text-uppercase"><?= $e->html($attempt->status) ?></span>
                    <?php if (PaymentStatus::isInFlight($attempt->status)): ?>
                        <span class="text-muted small"><?= $e->html('(in progress)') ?></span>
                    <?php endif; ?>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>

    <p class="mt-4 mb-0">
        <a href="/applications/<?= $e->attr($application->applicationId) ?>"><?= $e->html('Back to application') ?></a>
    </p>
</div>
<?php
$content = ob_get_clean();
require dirname(__DIR__, 2) . '/layouts/base.php';
