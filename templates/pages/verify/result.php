<?php

declare(strict_types=1);

/** @var \Academy\Infrastructure\View\Escaper $e */
/** @var string $title */
/** @var string $status */
/** @var \Academy\Application\Branding\AcademyBranding $branding */

$success = $status === 'success';

ob_start();
?>
<div class="acad-login acad-onboard mx-auto" style="max-width: 28rem;">
    <p class="acad-eyebrow mb-2"><?= $e->html($branding->name) ?></p>
    <?php if ($success): ?>
        <h1 class="h3 mb-2"><?= $e->html('Welcome to the academy') ?></h1>
        <p class="text-muted mb-3">
            <?= $e->html('Your email is verified. Sign in to browse courses and start your learning journey.') ?>
        </p>

        <ol class="acad-onboard-steps list-unstyled mb-4" aria-label="Next steps">
            <li class="acad-onboard-steps__item acad-onboard-steps__item--done">
                <span class="acad-onboard-steps__num">1</span>
                <span><?= $e->html('Account created') ?></span>
            </li>
            <li class="acad-onboard-steps__item acad-onboard-steps__item--done">
                <span class="acad-onboard-steps__num">2</span>
                <span><?= $e->html('Email verified') ?></span>
            </li>
            <li class="acad-onboard-steps__item acad-onboard-steps__item--current">
                <span class="acad-onboard-steps__num">3</span>
                <span><?= $e->html('Sign in') ?></span>
            </li>
        </ol>

        <div class="card card-body shadow-sm acad-onboard__card mb-3">
            <p class="mb-3"><?= $e->html('Professional profile details are optional for now. You can add them later from My profile when you apply.') ?></p>
            <div class="d-grid gap-2">
                <a class="btn btn-primary" href="/login"><?= $e->html('Sign in') ?></a>
                <a class="btn btn-outline-secondary" href="/verify-mobile"><?= $e->html('Verify mobile number') ?></a>
                <a class="btn btn-link" href="/courses"><?= $e->html('Browse courses') ?></a>
            </div>
        </div>
    <?php else: ?>
        <h1 class="h3 mb-3"><?= $e->html('Email verification') ?></h1>
        <div class="card card-body shadow-sm acad-onboard__card mb-3">
            <p class="mb-3"><?= $e->html('This confirmation link is no longer valid. Request a new verification email to continue.') ?></p>
            <a class="btn btn-outline-primary w-100" href="/verify-email/resend"><?= $e->html('Resend verification email') ?></a>
        </div>
        <p class="mb-0"><a href="/login"><?= $e->html('Back to sign in') ?></a></p>
    <?php endif; ?>
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
