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
    <form method="post" action="/register">
        <input type="hidden" name="_csrf" value="<?= $e->attr($csrf) ?>">
        <p>
            <label for="email">Email</label><br>
            <input type="email" id="email" name="email" required autocomplete="email">
        </p>
        <p>
            <label for="mobile">Mobile</label><br>
            <input type="tel" id="mobile" name="mobile" required autocomplete="tel">
        </p>
        <p>
            <label for="password">Password</label><br>
            <input type="password" id="password" name="password" required autocomplete="new-password">
        </p>
        <p>
            <label>
                <input type="checkbox" name="terms_accepted" value="1">
                I accept the Terms of Use
            </label>
        </p>
        <p>
            <label>
                <input type="checkbox" name="privacy_accepted" value="1">
                I accept the Privacy Policy
            </label>
        </p>
        <button type="submit">Create account</button>
    </form>
</main>
</body>
</html>
