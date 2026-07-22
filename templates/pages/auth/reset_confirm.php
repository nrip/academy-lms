<?php

declare(strict_types=1);

/** @var \Academy\Infrastructure\View\Escaper $e */
/** @var string $title */
/** @var string $formAction */
/** @var string $csrf */
/** @var bool $hasCookie */

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
    <?php if ($hasCookie): ?>
        <p>Click confirm to continue resetting your password.</p>
        <form method="post" action="<?= $e->attr($formAction) ?>">
            <input type="hidden" name="_csrf" value="<?= $e->attr($csrf) ?>">
            <button type="submit">Confirm password reset</button>
        </form>
    <?php else: ?>
        <p>This confirmation link is no longer valid.</p>
        <p><a href="/forgot-password">Request a new link</a></p>
    <?php endif; ?>
</main>
</body>
</html>
