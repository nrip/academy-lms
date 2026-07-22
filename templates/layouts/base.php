<?php

declare(strict_types=1);

/** @var \Academy\Infrastructure\View\Escaper $e */
/** @var string $title */
/** @var string $content */

?><!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= $e->html($title) ?></title>
    <link rel="stylesheet" href="/assets/vendor/bootstrap/css/bootstrap.min.css">
    <link rel="stylesheet" href="/assets/css/acad-tokens.css">
    <link rel="stylesheet" href="/assets/css/acad-app.css">
</head>
<body>
<div class="acad-shell">
    <header class="acad-shell__header">
        <a class="acad-shell__brand" href="/smoke">Academy LMS</a>
        <nav class="acad-shell__nav">
            <a href="/courses">Courses</a>
        </nav>
    </header>
    <main class="acad-shell__main">
        <?= $content /* pre-rendered escaped fragments from child templates */ ?>
    </main>
</div>
<script src="/assets/vendor/jquery/jquery.min.js"></script>
<script src="/assets/vendor/bootstrap/js/bootstrap.bundle.min.js"></script>
<script src="/assets/js/acad/app.js"></script>
</body>
</html>
