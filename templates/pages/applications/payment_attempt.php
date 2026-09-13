<?php

declare(strict_types=1);

use Academy\Domain\Payments\PaymentAmountSnapshot;
use Academy\Domain\Payments\PaymentStatus;

/** @var \Academy\Infrastructure\View\Escaper $e */
/** @var string $title */
/** @var string $csrf */
/** @var \Academy\Domain\Admissions\Application $application */
/** @var \Academy\Domain\Payments\Payment $payment */
/** @var string|null $gatewayPublicKeyId */
/** @var bool $demoPaymentAvailable */
/** @var \Academy\Application\Branding\AcademyBranding $branding */

$demoPaymentAvailable = $demoPaymentAvailable ?? false;
$statusLabel = match ($payment->status) {
    PaymentStatus::PENDING, PaymentStatus::CREATED => 'Awaiting checkout',
    PaymentStatus::SUCCESSFUL => 'Successful',
    PaymentStatus::RECONCILIATION_PENDING => 'Under verification',
    PaymentStatus::FAILED => 'Unsuccessful',
    PaymentStatus::CANCELLED => 'Cancelled',
    PaymentStatus::EXPIRED => 'Expired',
    default => $payment->status,
};

ob_start();
?>
<div class="acad-payment-attempt" data-application-id="<?= $e->attr($application->applicationId) ?>" data-payment-id="<?= $e->attr($payment->paymentId) ?>">
    <p class="acad-eyebrow mb-2"><?= $e->html('Complete payment') ?></p>
    <h1 class="h3 mb-3"><?= $e->html('Pay for your application') ?></h1>
    <p class="text-muted"><?= $e->html('Reference: ' . $payment->publicReference) ?></p>
    <p>
        <span class="badge bg-secondary"><?= $e->html($statusLabel) ?></span>
    </p>

    <dl class="row mb-4">
        <dt class="col-sm-4"><?= $e->html('Total payable') ?></dt>
        <dd class="col-sm-8"><?= $e->html($payment->currency . ' ' . PaymentAmountSnapshot::minorToDecimal($payment->amountMinor)) ?></dd>
        <dt class="col-sm-4"><?= $e->html('Provider order') ?></dt>
        <dd class="col-sm-8"><?= $e->html($payment->providerOrderId ?? '—') ?></dd>
    </dl>

    <?php if ($payment->status === PaymentStatus::PENDING && $payment->providerOrderId !== null): ?>
        <?php if ($demoPaymentAvailable): ?>
            <div class="alert alert-info" role="status">
                <?= $e->html('Demo mode: use the button below to simulate a successful Razorpay capture. You will see “Confirming payment…” briefly; the server then verifies via the same webhook path used in production. The browser never marks payment successful by itself.') ?>
            </div>
            <form method="post" action="/applications/<?= $e->attr($application->applicationId) ?>/payments/<?= $e->attr($payment->paymentId) ?>/demo-capture" class="mb-3">
                <input type="hidden" name="_csrf" value="<?= $e->attr($csrf) ?>">
                <button type="submit" class="btn btn-primary"><?= $e->html('Complete demo payment') ?></button>
            </form>
        <?php elseif ($gatewayPublicKeyId !== null): ?>
            <div class="alert alert-info" role="status">
                <?= $e->html('Complete checkout with the payment provider. Closing the window does not confirm payment — confirmation is server-side.') ?>
            </div>
            <div id="acad-razorpay-checkout"
                 data-key="<?= $e->attr($gatewayPublicKeyId) ?>"
                 data-order="<?= $e->attr($payment->providerOrderId) ?>"
                 data-amount="<?= $e->attr($payment->amountMinor) ?>"
                 data-currency="<?= $e->attr($payment->currency) ?>"
                 data-name="<?= $e->attr($branding->name) ?>"
                 data-description="<?= $e->attr($payment->publicReference) ?>"
                 data-return-action="/applications/<?= $e->attr($application->applicationId) ?>/payments/<?= $e->attr($payment->paymentId) ?>/checkout-return"
                 data-csrf="<?= $e->attr($csrf) ?>">
            </div>
            <button type="button" class="btn btn-primary" id="acad-pay-launch"><?= $e->html('Open checkout') ?></button>
            <script src="https://checkout.razorpay.com/v1/checkout.js" defer></script>
            <script src="/assets/js/payment-checkout.js" defer></script>
        <?php else: ?>
            <div class="alert alert-warning" role="status">
                <?= $e->html('Payment provider is not available in this environment.') ?>
            </div>
        <?php endif; ?>
    <?php elseif (PaymentStatus::isInFlight($payment->status)): ?>
        <div class="alert alert-warning" role="status">
            <?= $e->html('This attempt is not ready for checkout yet, or the payment provider is unavailable.') ?>
        </div>
    <?php endif; ?>

    <form method="post" action="/applications/<?= $e->attr($application->applicationId) ?>/payments/<?= $e->attr($payment->paymentId) ?>/checkout-return" class="mt-3">
        <input type="hidden" name="_csrf" value="<?= $e->attr($csrf) ?>">
        <button type="submit" class="btn btn-outline-secondary"><?= $e->html('I have completed or closed checkout') ?></button>
    </form>

    <p class="mt-4 mb-0">
        <a href="/applications/<?= $e->attr($application->applicationId) ?>/payment-result"><?= $e->html('View payment status') ?></a>
    </p>
</div>
<?php
$content = ob_get_clean();
require dirname(__DIR__, 2) . '/layouts/base.php';
