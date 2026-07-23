<?php

declare(strict_types=1);

use Academy\Domain\Payments\PaymentAmountSnapshot;
use Academy\Domain\Payments\PaymentStatus;

/** @var \Academy\Infrastructure\View\Escaper $e */
/** @var string $title */
/** @var string $csrf */
/** @var \Academy\Application\Payments\PaymentResultView $view */

$application = $view->application;
$primary = $view->primaryPayment;

ob_start();
?>
<div class="acad-payment-result">
    <p class="acad-eyebrow mb-2"><?= $e->html('Payment status') ?></p>
    <h1 class="h3 mb-3"><?= $e->html($view->statusHeadline) ?></h1>
    <p class="text-muted"><?= $e->html('Application ' . $application->applicationNumber) ?></p>

    <?php if ($view->isConfirming): ?>
        <div class="alert alert-info" role="status">
            <?= $e->html('Confirming payment… Browser return is not final confirmation. Please wait while the server verifies the payment.') ?>
        </div>
    <?php elseif ($primary !== null && $primary->status === PaymentStatus::SUCCESSFUL): ?>
        <div class="alert alert-success" role="status">
            <?= $e->html('Payment recorded as successful.') ?>
            <?php if ($application->status === \Academy\Domain\Admissions\ApplicationStatus::ADMITTED): ?>
                <?= $e->html(' Your application is admitted.') ?>
                <?php if (isset($enrolmentLifecycleLabel) && is_string($enrolmentLifecycleLabel)): ?>
                    <?= $e->html(' Enrolment status: ' . $enrolmentLifecycleLabel . '.') ?>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    <?php elseif ($primary !== null && $primary->status === PaymentStatus::RECONCILIATION_PENDING): ?>
        <div class="alert alert-warning" role="status">
            <?= $e->html('Payment is under verification. Enrolment is not confirmed yet.') ?>
        </div>
    <?php elseif ($primary !== null && PaymentStatus::isRetryEligible($primary->status)): ?>
        <div class="alert alert-warning" role="status">
            <?= $e->html('This attempt did not complete. You may retry from the payment page.') ?>
            <a class="alert-link" href="/applications/<?= $e->attr($application->applicationId) ?>/payment"><?= $e->html('Retry payment') ?></a>
        </div>
    <?php endif; ?>

    <?php if ($primary !== null): ?>
        <dl class="row">
            <dt class="col-sm-4"><?= $e->html('Reference') ?></dt>
            <dd class="col-sm-8"><?= $e->html($primary->publicReference) ?></dd>
            <dt class="col-sm-4"><?= $e->html('Status') ?></dt>
            <dd class="col-sm-8 text-uppercase"><?= $e->html($primary->status) ?></dd>
            <dt class="col-sm-4"><?= $e->html('Amount') ?></dt>
            <dd class="col-sm-8"><?= $e->html($primary->currency . ' ' . PaymentAmountSnapshot::minorToDecimal($primary->amountMinor)) ?></dd>
        </dl>
    <?php endif; ?>

    <?php if (count($view->attempts) > 1): ?>
        <h2 class="h5 mt-4"><?= $e->html('All attempts') ?></h2>
        <ul>
            <?php foreach ($view->attempts as $attempt): ?>
                <li>
                    <?= $e->html($attempt->publicReference) ?>
                    — <span class="text-uppercase"><?= $e->html($attempt->status) ?></span>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>

    <p class="mt-4 mb-0">
        <a href="/dashboard"><?= $e->html('Go to dashboard') ?></a>
        ·
        <a href="/applications/<?= $e->attr($application->applicationId) ?>"><?= $e->html('Back to application') ?></a>
    </p>
</div>
<?php
$content = ob_get_clean();
require dirname(__DIR__, 2) . '/layouts/base.php';
