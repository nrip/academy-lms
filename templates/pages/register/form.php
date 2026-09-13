<?php

declare(strict_types=1);

/** @var \Academy\Infrastructure\View\Escaper $e */
/** @var string $title */
/** @var string $csrf */
/** @var \Academy\Application\Branding\AcademyBranding $branding */

ob_start();
?>
<div class="acad-login mx-auto" style="max-width: 28rem;">
    <p class="acad-eyebrow mb-2"><?= $e->html($branding->name) ?></p>
    <h1 class="h3 mb-3"><?= $e->html('Create account') ?></h1>
    <p class="text-muted mb-4"><?= $e->html('Register with your email and mobile to apply for courses.') ?></p>

    <form method="post" action="/register" class="card card-body shadow-sm">
        <input type="hidden" name="_csrf" value="<?= $e->attr($csrf) ?>">
        <div class="mb-3">
            <label class="form-label" for="email"><?= $e->html('Email') ?></label>
            <input class="form-control" type="email" id="email" name="email" required autocomplete="email">
        </div>
        <div class="mb-3">
            <label class="form-label" for="mobile"><?= $e->html('Mobile') ?></label>
            <input class="form-control" type="tel" id="mobile" name="mobile" required autocomplete="tel">
        </div>
        <div class="mb-3">
            <label class="form-label" for="password"><?= $e->html('Password') ?></label>
            <input class="form-control" type="password" id="password" name="password" required autocomplete="new-password">
        </div>
        <div class="form-check mb-2">
            <input class="form-check-input" type="checkbox" id="terms_accepted" name="terms_accepted" value="1" required>
            <label class="form-check-label" for="terms_accepted"><?= $e->html('I accept the Terms of Use') ?></label>
        </div>
        <div class="form-check mb-3">
            <input class="form-check-input" type="checkbox" id="privacy_accepted" name="privacy_accepted" value="1" required>
            <label class="form-check-label" for="privacy_accepted"><?= $e->html('I accept the Privacy Policy') ?></label>
        </div>
        <button type="submit" class="btn btn-primary w-100"><?= $e->html('Create account') ?></button>
    </form>
    <p class="mt-3 mb-0">
        <?= $e->html('Already registered?') ?>
        <a href="/login"><?= $e->html('Sign in') ?></a>
    </p>
    <?php if ($branding->supportEmail !== ''): ?>
        <p class="mt-2 mb-0 small text-muted">
            <?= $e->html('Need help? Contact ') ?>
            <a href="mailto:<?= $e->attr($branding->supportEmail) ?>"><?= $e->html($branding->supportEmail) ?></a>
        </p>
    <?php endif; ?>
</div>
<?php
$content = (string) ob_get_clean();
require dirname(__DIR__, 2) . '/layouts/base.php';
