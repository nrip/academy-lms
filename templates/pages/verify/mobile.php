<?php

declare(strict_types=1);

/** @var \Academy\Infrastructure\View\Escaper $e */
/** @var string $title */
/** @var string $csrf */
/** @var bool $hasPendingMarker */
/** @var string $status */

?><!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= $e->html($title) ?></title>
</head>
<body>
<main>
    <h1><?= $e->html($title) ?></h1>
    <?php if ($status === 'success'): ?>
        <p>Your mobile number has been verified.</p>
    <?php elseif ($status === 'invalid'): ?>
        <p>The verification code is not valid. Please try again.</p>
    <?php elseif ($status === 'resent'): ?>
        <p>If your account is eligible, a new verification code has been sent.</p>
    <?php endif; ?>
    <form method="post" action="/verify-mobile">
        <input type="hidden" name="_csrf" value="<?= $e->attr($csrf) ?>">
        <p>
            <label for="otp">Verification code</label><br>
            <input type="text" id="otp" name="otp" inputmode="numeric" pattern="\d{6}" maxlength="6" required autocomplete="one-time-code">
        </p>
        <?php if (!$hasPendingMarker): ?>
        <p>
            <label for="mobile">Mobile</label><br>
            <input type="tel" id="mobile" name="mobile" required autocomplete="tel">
        </p>
        <?php else: ?>
        <p>
            <label for="mobile">Mobile (optional)</label><br>
            <input type="tel" id="mobile" name="mobile" autocomplete="tel">
        </p>
        <?php endif; ?>
        <button type="submit">Verify</button>
    </form>
    <form method="post" action="/verify-mobile/resend">
        <input type="hidden" name="_csrf" value="<?= $e->attr($csrf) ?>">
        <?php if (!$hasPendingMarker): ?>
        <p>
            <label for="resend_mobile">Mobile (for resend)</label><br>
            <input type="tel" id="resend_mobile" name="mobile" required autocomplete="tel">
        </p>
        <?php else: ?>
        <p>
            <label for="resend_mobile">Mobile (optional, for resend)</label><br>
            <input type="tel" id="resend_mobile" name="mobile" autocomplete="tel">
        </p>
        <?php endif; ?>
        <button type="submit">Resend code</button>
    </form>
</main>
</body>
</html>
