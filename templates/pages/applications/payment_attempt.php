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

ob_start();
?>
<div class="acad-payment-attempt" data-application-id="<?= $e->attr($application->applicationId) ?>" data-payment-id="<?= $e->attr($payment->paymentId) ?>">
    <p class="acad-eyebrow mb-2"><?= $e->html('Payment attempt') ?></p>
    <h1 class="h3 mb-3"><?= $e->html($payment->publicReference) ?></h1>
    <p>
        <span class="badge bg-secondary text-uppercase"><?= $e->html($payment->status) ?></span>
    </p>

    <dl class="row mb-4">
        <dt class="col-sm-4"><?= $e->html('Total') ?></dt>
        <dd class="col-sm-8"><?= $e->html($payment->currency . ' ' . PaymentAmountSnapshot::minorToDecimal($payment->amountMinor)) ?></dd>
        <dt class="col-sm-4"><?= $e->html('Provider order') ?></dt>
        <dd class="col-sm-8"><?= $e->html($payment->providerOrderId ?? '—') ?></dd>
    </dl>

    <?php if ($payment->status === PaymentStatus::PENDING && $payment->providerOrderId !== null && $gatewayPublicKeyId !== null): ?>
        <div class="alert alert-info" role="status">
            <?= $e->html('Complete checkout with the payment provider. Closing the window does not confirm payment — confirmation is server-side.') ?>
        </div>
        <div id="acad-razorpay-checkout"
             data-key="<?= $e->attr($gatewayPublicKeyId) ?>"
             data-order="<?= $e->attr($payment->providerOrderId) ?>"
             data-amount="<?= $e->attr($payment->amountMinor) ?>"
             data-currency="<?= $e->attr($payment->currency) ?>"
             data-name="<?= $e->attr('Academy LMS') ?>"
             data-description="<?= $e->attr($payment->publicReference) ?>"
             data-return-action="/applications/<?= $e->attr($application->applicationId) ?>/payments/<?= $e->attr($payment->paymentId) ?>/checkout-return"
             data-csrf="<?= $e->attr($csrf) ?>">
        </div>
        <button type="button" class="btn btn-primary" id="acad-pay-launch"><?= $e->html('Open checkout') ?></button>
        <script src="https://checkout.razorpay.com/v1/checkout.js" defer></script>
        <script src="/assets/js/payment-checkout.js" defer></script>
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
        <a href="/applications/<?= $e->attr($application->applicationId) ?>/payment-result"><?= $e->html('Payment status') ?></a>
    </p>
</div>
<?php
$content = ob_get_clean();
require dirname(__DIR__, 2) . '/layouts/base.php';
