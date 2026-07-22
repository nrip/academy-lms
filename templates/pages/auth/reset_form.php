<?php

declare(strict_types=1);

/** @var \Academy\Infrastructure\View\Escaper $e */
/** @var string $title */
/** @var string $csrf */
/** @var string|null $error */

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
    <?php if ($error !== null && $error !== ''): ?>
        <p role="alert"><?= $e->html($error) ?></p>
    <?php endif; ?>
    <form method="post" action="/reset-password">
        <input type="hidden" name="_csrf" value="<?= $e->attr($csrf) ?>">
        <p>
            <label for="password">New password</label><br>
            <input type="password" id="password" name="password" required autocomplete="new-password" minlength="12" maxlength="128">
        </p>
        <p>
            <label for="password_confirm">Confirm password</label><br>
            <input type="password" id="password_confirm" name="password_confirm" required autocomplete="new-password" minlength="12" maxlength="128">
        </p>
        <button type="submit">Update password</button>
    </form>
</main>
</body>
</html>
