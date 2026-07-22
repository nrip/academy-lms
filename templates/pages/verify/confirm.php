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
    <meta name="referrer" content="no-referrer">
    <title><?= $e->html($title) ?></title>
</head>
<body>
<main>
    <h1><?= $e->html($title) ?></h1>
    <?php if ($hasCookie): ?>
        <form method="post" action="<?= $e->attr($formAction) ?>">
            <input type="hidden" name="_csrf" value="<?= $e->attr($csrf) ?>">
            <button type="submit">Confirm</button>
        </form>
    <?php else: ?>
        <p>This confirmation link is no longer valid.</p>
    <?php endif; ?>
</main>
</body>
</html>
