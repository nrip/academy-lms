<?php

declare(strict_types=1);

/** @var \Academy\Infrastructure\View\Escaper $e */
/** @var string $title */
/** @var bool $hasPendingMarker */
/** @var \Academy\Application\Branding\AcademyBranding $branding */

ob_start();
?>
<div class="acad-login mx-auto" style="max-width: 28rem;">
    <p class="acad-eyebrow mb-2"><?= $e->html($branding->name) ?></p>
    <h1 class="h3 mb-3"><?= $e->html('Check your email') ?></h1>

    <div class="card card-body shadow-sm mb-3">
        <?php if ($hasPendingMarker): ?>
            <p class="mb-2"><?= $e->html('Your account has been created. We sent a verification link to your email address.') ?></p>
            <p class="text-muted mb-0"><?= $e->html('Please check your inbox and follow the link to verify your email. You may also need to verify your mobile number.') ?></p>
        <?php else: ?>
            <p class="mb-2"><?= $e->html('If an account was created, a verification link has been sent to the email address provided.') ?></p>
            <p class="text-muted mb-0"><?= $e->html('Please check your inbox and follow the link to continue.') ?></p>
        <?php endif; ?>
    </div>

    <p class="mb-2"><a href="/verify-email/resend"><?= $e->html('Resend verification email') ?></a></p>
    <p class="mb-0"><a href="/verify-mobile"><?= $e->html('Verify mobile number') ?></a></p>
    <p class="mt-3 mb-0"><a href="/login"><?= $e->html('Back to sign in') ?></a></p>
</div>
<?php
$content = (string) ob_get_clean();
require dirname(__DIR__, 2) . '/layouts/base.php';
