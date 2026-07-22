<?php

declare(strict_types=1);

/** @var \Academy\Infrastructure\View\Escaper $e */
/** @var string $title */
/** @var bool $hasPendingMarker */

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
    <?php if ($hasPendingMarker): ?>
        <p>Your account has been created. We sent a verification link to your email address.</p>
        <p>Please check your inbox and follow the link to verify your email. You may also need to verify your mobile number.</p>
    <?php else: ?>
        <p>If an account was created, a verification link has been sent to the email address provided.</p>
        <p>Please check your inbox and follow the link to continue.</p>
    <?php endif; ?>
    <p><a href="/verify-email/resend">Resend verification email</a></p>
    <p><a href="/verify-mobile">Verify mobile number</a></p>
</main>
</body>
</html>
