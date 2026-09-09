<?php

declare(strict_types=1);

/** @var \Academy\Infrastructure\View\Escaper $e */
/** @var string $title */
/** @var string $content */
/** @var list<array{label: string, href: string, method?: string}>|null $navItems */
/** @var string|null $navCsrf */
/** @var string|null $csrf */
/** @var \Academy\Application\Branding\AcademyBranding $branding */

$nav = $navItems ?? [
    ['label' => 'Courses', 'href' => '/courses'],
    ['label' => 'Create account', 'href' => '/register'],
    ['label' => 'Sign in', 'href' => '/login'],
];
$csrfToken = is_string($navCsrf ?? null) && $navCsrf !== ''
    ? $navCsrf
    : (is_string($csrf ?? null) ? $csrf : '');

$authenticated = false;
foreach ($nav as $navItem) {
    if (($navItem['method'] ?? 'get') === 'post' || ($navItem['label'] ?? '') === 'Logout') {
        $authenticated = true;
        break;
    }
}

// Guest acquisition: ensure Create account is visible next to Sign in (templates only).
if (!$authenticated) {
    $hasRegister = false;
    foreach ($nav as $navItem) {
        if (($navItem['href'] ?? '') === '/register') {
            $hasRegister = true;
            break;
        }
    }
    if (!$hasRegister) {
        $withRegister = [];
        $inserted = false;
        foreach ($nav as $navItem) {
            if (!$inserted && ($navItem['href'] ?? '') === '/login') {
                $withRegister[] = ['label' => 'Create account', 'href' => '/register'];
                $inserted = true;
            }
            $withRegister[] = $navItem;
        }
        if (!$inserted) {
            $withRegister[] = ['label' => 'Create account', 'href' => '/register'];
        }
        $nav = $withRegister;
    }
}

$brandHref = $authenticated ? '/dashboard' : '/courses';

?><!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= $e->html($title) ?></title>
    <link rel="stylesheet" href="/assets/vendor/bootstrap/css/bootstrap.min.css">
    <link rel="stylesheet" href="/assets/css/acad-tokens.css">
    <link rel="stylesheet" href="/assets/css/acad-app.css">
    <style>
        :root {
            --acad-teal: <?= $e->attr($branding->primaryColor) ?>;
            --bs-primary: var(--acad-teal);
            --bs-primary-rgb: <?= $e->html($branding->primaryColorRgb()) ?>;
        }
    </style>
</head>
<body>
<div class="acad-shell">
    <header class="acad-shell__header">
        <a class="acad-shell__brand" href="<?= $e->attr($brandHref) ?>">
            <img class="acad-shell__logo" src="<?= $e->attr($branding->logoUrl) ?>" width="36" height="36" alt="">
            <span class="acad-shell__brand-text"><?= $e->html($branding->name) ?></span>
        </a>
        <nav class="acad-shell__nav" aria-label="Primary">
            <?php foreach ($nav as $item): ?>
                <?php if (($item['method'] ?? 'get') === 'post'): ?>
                    <form method="post" action="<?= $e->attr($item['href']) ?>" class="acad-shell__nav-form">
                        <input type="hidden" name="_csrf" value="<?= $e->attr($csrfToken) ?>">
                        <button type="submit" class="acad-shell__nav-button"><?= $e->html($item['label']) ?></button>
                    </form>
                <?php else: ?>
                    <?php
                    $navHref = (string) ($item['href'] ?? '');
                    $navIsCta = $navHref === '/register'
                        || ($item['label'] ?? '') === 'Create account'
                        || ($item['label'] ?? '') === 'Register';
                    $navClass = $navIsCta
                        ? 'acad-shell__nav-link acad-shell__nav-link--cta'
                        : 'acad-shell__nav-link';
                    ?>
                    <a class="<?= $e->attr($navClass) ?>" href="<?= $e->attr($navHref) ?>"><?= $e->html((string) ($item['label'] ?? '')) ?></a>
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
