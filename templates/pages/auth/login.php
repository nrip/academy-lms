<?php

declare(strict_types=1);

/** @var \Academy\Infrastructure\View\Escaper $e */
/** @var string $title */
/** @var string $csrf */
/** @var string|null $error */
/** @var string|null $return_to */

ob_start();
?>
<div class="acad-login mx-auto" style="max-width: 28rem;">
    <p class="acad-eyebrow mb-2"><?= $e->html('Academy LMS') ?></p>
    <h1 class="h3 mb-3"><?= $e->html('Sign in') ?></h1>
    <p class="text-muted mb-4"><?= $e->html('Use your demo or UAT account credentials to continue.') ?></p>

    <?php if ($error !== null && $error !== ''): ?>
        <div class="alert alert-danger" role="alert"><?= $e->html($error) ?></div>
    <?php endif; ?>

    <form method="post" action="/login" class="card card-body shadow-sm">
        <input type="hidden" name="_csrf" value="<?= $e->attr($csrf) ?>">
        <?php if (isset($return_to) && is_string($return_to) && $return_to !== ''): ?>
            <input type="hidden" name="return_to" value="<?= $e->attr($return_to) ?>">
        <?php endif; ?>
        <div class="mb-3">
            <label class="form-label" for="email"><?= $e->html('Email') ?></label>
            <input class="form-control" type="email" id="email" name="email" required autocomplete="username">
        </div>
        <div class="mb-3">
            <label class="form-label" for="password"><?= $e->html('Password') ?></label>
            <input class="form-control" type="password" id="password" name="password" required autocomplete="current-password">
        </div>
        <button type="submit" class="btn btn-primary w-100"><?= $e->html('Sign in') ?></button>
    </form>
    <p class="mt-3 mb-0"><a href="/forgot-password"><?= $e->html('Forgot password?') ?></a></p>
</div>
<?php
$content = ob_get_clean();
require dirname(__DIR__, 2) . '/layouts/base.php';
