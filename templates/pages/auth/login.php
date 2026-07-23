<?php

declare(strict_types=1);

/** @var \Academy\Infrastructure\View\Escaper $e */
/** @var string $title */
/** @var string $csrf */
/** @var string|null $error */
/** @var string|null $return_to */

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
    <form method="post" action="/login">
        <input type="hidden" name="_csrf" value="<?= $e->attr($csrf) ?>">
        <?php if (isset($return_to) && is_string($return_to) && $return_to !== ''): ?>
            <input type="hidden" name="return_to" value="<?= $e->attr($return_to) ?>">
        <?php endif; ?>
        <p>
            <label for="email">Email</label><br>
            <input type="email" id="email" name="email" required autocomplete="username">
        </p>
        <p>
            <label for="password">Password</label><br>
            <input type="password" id="password" name="password" required autocomplete="current-password">
        </p>
        <button type="submit">Sign in</button>
    </form>
    <p><a href="/forgot-password">Forgot password?</a></p>
</main>
</body>
</html>
