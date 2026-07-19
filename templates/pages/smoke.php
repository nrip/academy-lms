<?php

declare(strict_types=1);

/** @var \Academy\Infrastructure\View\Escaper $e */
/** @var string $title */

ob_start();
?>
<div class="acad-smoke-panel">
    <p class="acad-eyebrow mb-2">Phase 0</p>
    <h1 class="h3 mb-3"><?= $e->html('Repository foundation smoke page') ?></h1>
    <p class="mb-0 text-secondary">
        <?= $e->html('Bootstrap, design tokens, and the Academy JavaScript entry are loaded for visual verification only.') ?>
    </p>
</div>
<?php
$content = (string) ob_get_clean();
require dirname(__DIR__) . '/layouts/base.php';
