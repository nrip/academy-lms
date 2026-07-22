<?php

declare(strict_types=1);

/** @var \Academy\Infrastructure\View\Escaper $e */
/** @var string $title */
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
        <p>Your password has been updated. You can now sign in with your new password.</p>
        <p><a href="/login">Sign in</a></p>
    <?php else: ?>
        <p>This password reset link is no longer valid.</p>
        <p><a href="/forgot-password">Request a new link</a></p>
    <?php endif; ?>
</main>
</body>
</html>
