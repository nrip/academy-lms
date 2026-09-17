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
    <h1 class="h3 mb-3"><?= $e->html('Resend verification email') ?></h1>

    <?php if ($status === 'sent'): ?>
        <div class="alert alert-info" role="status">
            <?= $e->html('If an account matches your request, a new verification email has been sent.') ?>
        </div>
    <?php endif; ?>

    <div class="card card-body shadow-sm acad-onboard__card mb-3">
        <?php if ($hasPendingMarker): ?>
            <p class="mb-3"><?= $e->html('You recently registered. Leave the email blank to resend using your current session, or enter an address.') ?></p>
        <?php else: ?>
            <p class="mb-3"><?= $e->html('Enter the email you used when creating your account.') ?></p>
        <?php endif; ?>
        <form method="post" action="/verify-email/resend">
            <input type="hidden" name="_csrf" value="<?= $e->attr($csrf) ?>">
            <div class="mb-3">
                <label class="form-label" for="email">
                    <?= $e->html($hasPendingMarker ? 'Email (optional)' : 'Email') ?>
                </label>
                <input class="form-control" type="email" id="email" name="email"
                       autocomplete="email"<?= $hasPendingMarker ? '' : ' required' ?>>
            </div>
            <button type="submit" class="btn btn-primary w-100"><?= $e->html('Resend verification email') ?></button>
        </form>
    </div>
    <p class="mb-0"><a href="/login"><?= $e->html('Back to sign in') ?></a></p>
</div>
<?php
$content = (string) ob_get_clean();
require dirname(__DIR__, 2) . '/layouts/base.php';
