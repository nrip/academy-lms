<?php

declare(strict_types=1);

use Academy\Domain\Identity\PasswordPolicy;

/** @var \Academy\Infrastructure\View\Escaper $e */
/** @var string $title */
/** @var string $csrf */
/** @var \Academy\Application\Branding\AcademyBranding $branding */
/** @var array<string, string> $values */
/** @var array<string, list<string>> $errors */

$values = $values ?? ['email' => '', 'mobile' => ''];
$errors = $errors ?? [];
$passwordMin = PasswordPolicy::minLength();
$passwordMax = PasswordPolicy::maxLength();

$fieldErrors = static function (string $name) use ($errors, $e): string {
    if (!isset($errors[$name]) || $errors[$name] === []) {
        return '';
    }
    $out = '<div class="invalid-feedback d-block" role="alert">';
    foreach ($errors[$name] as $message) {
        $out .= '<div>' . $e->html($message) . '</div>';
    }

    return $out . '</div>';
};

ob_start();
?>
<div class="acad-login acad-onboard mx-auto" style="max-width: 28rem;">
    <p class="acad-eyebrow mb-2"><?= $e->html($branding->name) ?></p>
    <h1 class="h3 mb-2"><?= $e->html('Join the academy') ?></h1>
    <p class="text-muted mb-3">
        <?= $e->html('Create your account with email and mobile. You can add professional details later when you apply.') ?>
    </p>

    <ol class="acad-onboard-steps list-unstyled mb-4" aria-label="Registration steps">
        <li class="acad-onboard-steps__item acad-onboard-steps__item--current">
            <span class="acad-onboard-steps__num">1</span>
            <span><?= $e->html('Create account') ?></span>
        </li>
        <li class="acad-onboard-steps__item">
            <span class="acad-onboard-steps__num">2</span>
            <span><?= $e->html('Verify email') ?></span>
        </li>
        <li class="acad-onboard-steps__item">
            <span class="acad-onboard-steps__num">3</span>
            <span><?= $e->html('Sign in') ?></span>
        </li>
    </ol>

    <?php if ($errors !== []): ?>
        <div class="alert alert-danger" role="alert">
            <?= $e->html('Please check the details below and try again.') ?>
        </div>
    <?php endif; ?>

    <form method="post" action="/register" class="card card-body shadow-sm acad-onboard__card" novalidate>
        <input type="hidden" name="_csrf" value="<?= $e->attr($csrf) ?>">
        <div class="mb-3">
            <label class="form-label" for="email"><?= $e->html('Email') ?></label>
            <input class="form-control<?= isset($errors['email']) ? ' is-invalid' : '' ?>"
                   type="email" id="email" name="email" required autocomplete="email"
                   value="<?= $e->attr($values['email'] ?? '') ?>">
            <?= $fieldErrors('email') ?>
        </div>
        <div class="mb-3">
            <label class="form-label" for="mobile"><?= $e->html('Mobile') ?></label>
            <input class="form-control<?= isset($errors['mobile']) ? ' is-invalid' : '' ?>"
                   type="tel" id="mobile" name="mobile" required autocomplete="tel"
                   value="<?= $e->attr($values['mobile'] ?? '') ?>"
                   placeholder="+91…">
            <div class="form-text"><?= $e->html('Use an international format including country code (for example +91).') ?></div>
            <?= $fieldErrors('mobile') ?>
        </div>
        <div class="mb-3">
            <label class="form-label" for="password"><?= $e->html('Password') ?></label>
            <div class="input-group">
                <input class="form-control<?= isset($errors['password']) ? ' is-invalid' : '' ?>"
                       type="password" id="password" name="password" required
                       autocomplete="new-password"
                       minlength="<?= $e->attr((string) $passwordMin) ?>"
                       maxlength="<?= $e->attr((string) $passwordMax) ?>"
                       data-acad-password-input>
                <button class="btn btn-outline-secondary" type="button" data-acad-password-toggle
                        aria-controls="password" aria-pressed="false">
                    <?= $e->html('Show') ?>
                </button>
            </div>
            <div class="form-text"><?= $e->html(PasswordPolicy::guidanceText()) ?></div>
            <?= $fieldErrors('password') ?>
        </div>
        <div class="form-check mb-2">
            <input class="form-check-input<?= isset($errors['terms_accepted']) ? ' is-invalid' : '' ?>"
                   type="checkbox" id="terms_accepted" name="terms_accepted" value="1" required>
            <label class="form-check-label" for="terms_accepted"><?= $e->html('I accept the Terms of Use') ?></label>
            <?= $fieldErrors('terms_accepted') ?>
        </div>
        <div class="form-check mb-3">
            <input class="form-check-input<?= isset($errors['privacy_accepted']) ? ' is-invalid' : '' ?>"
                   type="checkbox" id="privacy_accepted" name="privacy_accepted" value="1" required>
            <label class="form-check-label" for="privacy_accepted"><?= $e->html('I accept the Privacy Policy') ?></label>
            <?= $fieldErrors('privacy_accepted') ?>
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
