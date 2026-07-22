<?php

declare(strict_types=1);

/** @var \Academy\Infrastructure\View\Escaper $e */
/** @var string $title */
/** @var \Academy\Application\Admissions\ApplicationWorkspaceView $view */
/** @var bool $ok */

$application = $view->application;

ob_start();
?>
<div class="acad-submission-result">
    <?php if ($ok): ?>
        <div class="alert alert-success" role="status">
            <h1 class="h4 mb-2"><?= $e->html('Application submitted') ?></h1>
            <p class="mb-0">
                <?= $e->html('Your application ' . $application->applicationNumber . ' is now: ') ?>
                <span class="badge bg-secondary text-uppercase"><?= $e->html($application->status) ?></span>
            </p>
        </div>
    <?php else: ?>
        <div class="alert alert-danger" role="alert">
            <h1 class="h4 mb-2"><?= $e->html('Submission could not be completed') ?></h1>
            <?php if ($view->blockers !== []): ?>
                <ul class="mb-0">
                    <?php foreach ($view->blockers as $blocker): ?>
                        <li><?= $e->html($blocker) ?></li>
                    <?php endforeach; ?>
                </ul>
            <?php else: ?>
                <p class="mb-0"><?= $e->html('Please review your application and try again.') ?></p>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <p class="mt-3"><a href="/applications/<?= $e->attr($application->applicationId) ?>"><?= $e->html('Back to application') ?></a></p>
</div>
<?php
$content = (string) ob_get_clean();
require dirname(__DIR__, 2) . '/layouts/base.php';
