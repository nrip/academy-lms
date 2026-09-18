<?php

declare(strict_types=1);

/** @var \Academy\Infrastructure\View\Escaper $e */
/** @var string $title */
/** @var string $formAction */
/** @var string $csrf */
/** @var bool $hasCookie */
/** @var \Academy\Application\Branding\AcademyBranding $branding */

ob_start();
?>
<div class="acad-login acad-onboard mx-auto" style="max-width: 28rem;">
    <p class="acad-eyebrow mb-2"><?= $e->html($branding->name) ?></p>
    <h1 class="h3 mb-3"><?= $e->html('Confirm your email') ?></h1>
    <div class="card card-body shadow-sm acad-onboard__card">
        <?php if ($hasCookie): ?>
            <p class="mb-3"><?= $e->html('Click confirm to finish verifying this email address. This step protects your account from automated link scanners.') ?></p>
            <form method="post" action="<?= $e->attr($formAction) ?>">
                <input type="hidden" name="_csrf" value="<?= $e->attr($csrf) ?>">
                <button type="submit" class="btn btn-primary w-100"><?= $e->html('Confirm email') ?></button>
            </form>
        <?php else: ?>
            <p class="mb-3"><?= $e->html('This confirmation link is no longer valid. Request a new verification email and try again.') ?></p>
            <a class="btn btn-outline-primary w-100" href="/verify-email/resend"><?= $e->html('Resend verification email') ?></a>
        <?php endif; ?>
    </div>
</div>
<?php
$content = (string) ob_get_clean();
require dirname(__DIR__, 2) . '/layouts/base.php';
