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
    <?php if ($status === 'sent'): ?>
        <p>If an account matches your request, a new verification email has been sent.</p>
    <?php endif; ?>
    <?php if ($hasPendingMarker): ?>
        <p>You recently registered. You can resend the verification email below, or leave the email field blank to use your current session.</p>
    <?php endif; ?>
    <form method="post" action="/verify-email/resend">
        <input type="hidden" name="_csrf" value="<?= $e->attr($csrf) ?>">
        <p>
            <label for="email">Email<?= $hasPendingMarker ? ' (optional)' : '' ?></label><br>
            <input type="email" id="email" name="email" autocomplete="email"<?= $hasPendingMarker ? '' : ' required' ?>>
        </p>
        <button type="submit">Resend verification email</button>
    </form>
</main>
</body>
</html>
