<?php

declare(strict_types=1);

/** @var \Academy\Infrastructure\View\Escaper $e */
/** @var string $title */
/** @var bool $hasPendingMarker */
/** @var \Academy\Application\Branding\AcademyBranding $branding */

ob_start();
?>
<div class="acad-login acad-onboard mx-auto" style="max-width: 28rem;">
    <p class="acad-eyebrow mb-2"><?= $e->html($branding->name) ?></p>
    <h1 class="h3 mb-2"><?= $e->html('Check your email') ?></h1>
    <p class="text-muted mb-3"><?= $e->html('Almost there — verify your email to activate your account.') ?></p>

    <ol class="acad-onboard-steps list-unstyled mb-4" aria-label="Registration steps">
        <li class="acad-onboard-steps__item acad-onboard-steps__item--done">
            <span class="acad-onboard-steps__num">1</span>
            <span><?= $e->html('Create account') ?></span>
        </li>
        <li class="acad-onboard-steps__item acad-onboard-steps__item--current">
            <span class="acad-onboard-steps__num">2</span>
            <span><?= $e->html('Verify email') ?></span>
        </li>
        <li class="acad-onboard-steps__item">
            <span class="acad-onboard-steps__num">3</span>
            <span><?= $e->html('Sign in') ?></span>
        </li>
    </ol>

    <div class="card card-body shadow-sm acad-onboard__card mb-3">
        <?php if ($hasPendingMarker): ?>
            <p class="mb-2"><?= $e->html('Your account has been created. We sent a verification link to your email address.') ?></p>
            <p class="text-muted mb-0">
                <?= $e->html('Open the message and follow the link. You can verify your mobile number next — it is recommended, but you can finish that after you sign in.') ?>
            </p>
        <?php else: ?>
            <p class="mb-2"><?= $e->html('If an account was created, a verification link has been sent to the email address provided.') ?></p>
            <p class="text-muted mb-0"><?= $e->html('Check your inbox (and spam folder) for a message from the academy.') ?></p>
        <?php endif; ?>
    </div>

    <div class="d-grid gap-2">
        <a class="btn btn-outline-primary" href="/verify-email/resend"><?= $e->html('Resend verification email') ?></a>
        <a class="btn btn-outline-secondary" href="/verify-mobile"><?= $e->html('Verify mobile number') ?></a>
        <a class="btn btn-link" href="/login"><?= $e->html('Back to sign in') ?></a>
    </div>
    <?php if ($branding->supportEmail !== ''): ?>
        <p class="mt-3 mb-0 small text-muted">
            <?= $e->html('Need help? Contact ') ?>
            <a href="mailto:<?= $e->attr($branding->supportEmail) ?>"><?= $e->html($branding->supportEmail) ?></a>
        </p>
    <?php endif; ?>
</div>
<?php
$content = (string) ob_get_clean();
require dirname(__DIR__, 2) . '/layouts/base.php';
