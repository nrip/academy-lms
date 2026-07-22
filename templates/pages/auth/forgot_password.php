<?php

declare(strict_types=1);

/** @var \Academy\Infrastructure\View\Escaper $e */
/** @var string $title */
/** @var string $csrf */

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
    <p>Enter your email and we will send reset instructions if an account exists.</p>
    <form method="post" action="/forgot-password">
        <input type="hidden" name="_csrf" value="<?= $e->attr($csrf) ?>">
        <p>
            <label for="email">Email</label><br>
            <input type="email" id="email" name="email" required autocomplete="email">
        </p>
        <button type="submit">Send reset link</button>
    </form>
    <p><a href="/login">Back to sign in</a></p>
</main>
</body>
</html>
