<?php

declare(strict_types=1);

/** @var \Academy\Infrastructure\View\Escaper $e */
/** @var string $title */
/** @var string $message */
/** @var string $requestId */
/** @var int $status */
/** @var string|null $debugDetail */

ob_start();
?>
<div class="acad-smoke-panel">
    <p class="acad-eyebrow mb-2"><?= $e->html((string) $status) ?></p>
    <h1 class="h3 mb-3"><?= $e->html($title) ?></h1>
    <p><?= $e->html($message) ?></p>
    <?php if ($requestId !== ''): ?>
        <p class="small text-secondary mb-0">Request ID: <?= $e->html($requestId) ?></p>
    <?php endif; ?>
    <?php if ($debugDetail !== null): ?>
        <p class="small text-secondary mt-3 mb-0"><?= $e->html($debugDetail) ?></p>
    <?php endif; ?>
</div>
<?php
$content = (string) ob_get_clean();
require dirname(__DIR__) . '/layouts/base.php';
