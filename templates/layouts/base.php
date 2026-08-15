<?php

declare(strict_types=1);

/** @var \Academy\Infrastructure\View\Escaper $e */
/** @var string $title */
/** @var string $content */
/** @var list<array{label: string, href: string, method?: string}>|null $navItems */
/** @var string|null $navCsrf */
/** @var string|null $csrf */

$nav = $navItems ?? [
    ['label' => 'Courses', 'href' => '/courses'],
    ['label' => 'Sign in', 'href' => '/login'],
];
$csrfToken = is_string($navCsrf ?? null) && $navCsrf !== ''
    ? $navCsrf
    : (is_string($csrf ?? null) ? $csrf : '');

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
        <a class="acad-shell__brand" href="/dashboard">Academy LMS</a>
        <nav class="acad-shell__nav" aria-label="Primary">
            <?php foreach ($nav as $item): ?>
                <?php if (($item['method'] ?? 'get') === 'post'): ?>
                    <form method="post" action="<?= $e->attr($item['href']) ?>" class="d-inline">
                        <input type="hidden" name="_csrf" value="<?= $e->attr($csrfToken) ?>">
                        <button type="submit" class="btn btn-link acad-shell__nav-button p-0 align-baseline"><?= $e->html($item['label']) ?></button>
                    </form>
                <?php else: ?>
                    <a href="<?= $e->attr($item['href']) ?>"><?= $e->html($item['label']) ?></a>
                <?php endif; ?>
            <?php endforeach; ?>
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
