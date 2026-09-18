<?php

declare(strict_types=1);

/** @var \Academy\Infrastructure\View\Escaper $e */
/** @var string $title */
/** @var string $csrf */
/** @var bool $hasPendingMarker */
/** @var string $status */
/** @var \Academy\Application\Branding\AcademyBranding $branding */

ob_start();
?>
<div class="acad-login acad-onboard mx-auto" style="max-width: 28rem;">
    <p class="acad-eyebrow mb-2"><?= $e->html($branding->name) ?></p>
    <h1 class="h3 mb-3"><?= $e->html('Verify your mobile') ?></h1>
    <p class="text-muted mb-3">
        <?= $e->html('Enter the one-time code we sent by SMS. You can do this now or after you sign in.') ?>
    </p>

    <?php if ($status === 'success'): ?>
        <div class="alert alert-success" role="status"><?= $e->html('Your mobile number has been verified.') ?></div>
        <div class="d-grid gap-2 mb-3">
            <a class="btn btn-primary" href="/login"><?= $e->html('Continue to sign in') ?></a>
            <a class="btn btn-outline-secondary" href="/courses"><?= $e->html('Browse courses') ?></a>
        </div>
    <?php elseif ($status === 'invalid'): ?>
        <div class="alert alert-danger" role="alert"><?= $e->html('That code is not valid. Check the SMS and try again, or request a new code.') ?></div>
    <?php elseif ($status === 'resent'): ?>
        <div class="alert alert-info" role="status"><?= $e->html('If your account is eligible, a new verification code has been sent.') ?></div>
    <?php endif; ?>

    <?php if ($status !== 'success'): ?>
        <form method="post" action="/verify-mobile" class="card card-body shadow-sm acad-onboard__card mb-3">
            <input type="hidden" name="_csrf" value="<?= $e->attr($csrf) ?>">
            <div class="mb-3">
                <label class="form-label" for="otp"><?= $e->html('Verification code') ?></label>
                <input class="form-control" type="text" id="otp" name="otp" inputmode="numeric"
                       pattern="\d{6}" maxlength="6" required autocomplete="one-time-code">
            </div>
            <div class="mb-3">
                <label class="form-label" for="mobile">
                    <?= $e->html($hasPendingMarker ? 'Mobile (optional)' : 'Mobile') ?>
                </label>
                <input class="form-control" type="tel" id="mobile" name="mobile"
                       autocomplete="tel"<?= $hasPendingMarker ? '' : ' required' ?>>
                <?php if ($hasPendingMarker): ?>
                    <div class="form-text"><?= $e->html('Leave blank to use the mobile from your recent registration.') ?></div>
                <?php endif; ?>
            </div>
            <button type="submit" class="btn btn-primary w-100"><?= $e->html('Verify mobile') ?></button>
        </form>

        <form method="post" action="/verify-mobile/resend" class="card card-body shadow-sm">
            <input type="hidden" name="_csrf" value="<?= $e->attr($csrf) ?>">
            <p class="small text-muted mb-2"><?= $e->html('Did not receive a code?') ?></p>
            <div class="mb-3">
                <label class="form-label" for="resend_mobile">
                    <?= $e->html($hasPendingMarker ? 'Mobile (optional)' : 'Mobile') ?>
                </label>
                <input class="form-control" type="tel" id="resend_mobile" name="mobile"
                       autocomplete="tel"<?= $hasPendingMarker ? '' : ' required' ?>>
            </div>
            <button type="submit" class="btn btn-outline-secondary w-100"><?= $e->html('Resend code') ?></button>
        </form>
    <?php endif; ?>

    <p class="mt-3 mb-0"><a href="/login"><?= $e->html('Back to sign in') ?></a></p>
</div>
<?php
$content = (string) ob_get_clean();
require dirname(__DIR__, 2) . '/layouts/base.php';
