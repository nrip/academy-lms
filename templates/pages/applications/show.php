<?php

declare(strict_types=1);

/** @var \Academy\Infrastructure\View\Escaper $e */
/** @var string $title */
/** @var string $csrf */
/** @var \Academy\Application\Admissions\ApplicationWorkspaceView $view */

$application = $view->application;

ob_start();
?>
<div class="acad-application-detail">
    <p class="acad-eyebrow mb-2"><?= $e->html('My application') ?></p>
    <h1 class="h3 mb-4">
        <?= $e->html($application->applicationNumber) ?>
        <span class="badge bg-secondary text-uppercase"><?= $e->html($application->status) ?></span>
    </h1>

    <?php if ($view->blockers !== []): ?>
        <div class="alert alert-warning" role="status">
            <p class="mb-2"><?= $e->html('Before you can submit, please resolve:') ?></p>
            <ul class="mb-0">
                <?php foreach ($view->blockers as $blocker): ?>
                    <li><?= $e->html($blocker) ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php else: ?>
        <div class="alert alert-success" role="status"><?= $e->html('All submission requirements are met.') ?></div>
    <?php endif; ?>

    <div class="card mb-3">
        <div class="card-body">
            <dl class="row mb-0">
                <dt class="col-sm-4"><?= $e->html('Batch') ?></dt>
                <dd class="col-sm-8">#<?= $e->html($application->batchId) ?></dd>

                <dt class="col-sm-4"><?= $e->html('Course version') ?></dt>
                <dd class="col-sm-8">#<?= $e->html($application->courseVersionId) ?></dd>

                <dt class="col-sm-4"><?= $e->html('Declaration accepted') ?></dt>
                <dd class="col-sm-8"><?= $e->html($view->declarationAccepted ? 'Yes' : 'No') ?></dd>

                <dt class="col-sm-4"><?= $e->html('Profile completeness') ?></dt>
                <dd class="col-sm-8"><?= $e->html($view->profileCompleteness->percentage) ?>%</dd>

                <dt class="col-sm-4"><?= $e->html('Created') ?></dt>
                <dd class="col-sm-8"><?= $e->html($application->createdAt->format('d M Y H:i')) ?> UTC</dd>
            </dl>
        </div>
    </div>

    <?php if ($application->isDraft()): ?>
        <a class="btn btn-outline-primary me-2" href="/applications/<?= $e->attr($application->applicationId) ?>/edit"><?= $e->html('Edit declaration') ?></a>
        <a class="btn btn-outline-primary me-2" href="/applications/<?= $e->attr($application->applicationId) ?>/documents"><?= $e->html('Manage documents') ?></a>
        <?php if ($view->canSubmit()): ?>
            <form method="post" action="/applications/<?= $e->attr($application->applicationId) ?>/submit" class="d-inline">
                <input type="hidden" name="_csrf" value="<?= $e->attr($csrf) ?>">
                <button type="submit" class="btn btn-primary"><?= $e->html('Submit application') ?></button>
            </form>
        <?php endif; ?>
    <?php endif; ?>
</div>
<?php
$content = (string) ob_get_clean();
require dirname(__DIR__, 2) . '/layouts/base.php';
