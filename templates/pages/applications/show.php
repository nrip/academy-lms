<?php

declare(strict_types=1);

/** @var \Academy\Infrastructure\View\Escaper $e */
/** @var string $title */
/** @var \Academy\Domain\Admissions\Application $application */

ob_start();
?>
<div class="acad-application-detail">
    <p class="acad-eyebrow mb-2"><?= $e->html('My application') ?></p>
    <h1 class="h3 mb-4">
        <?= $e->html('Application #' . $application->applicationId) ?>
        <span class="badge bg-secondary text-uppercase"><?= $e->html($application->status) ?></span>
    </h1>

    <div class="alert alert-info" role="status">
        <?= $e->html('This is a draft application. Before you submit, you will need to verify your mobile number, upload required documents and complete payment.') ?>
    </div>

    <div class="card">
        <div class="card-body">
            <dl class="row mb-0">
                <dt class="col-sm-4"><?= $e->html('Batch') ?></dt>
                <dd class="col-sm-8">#<?= $e->html($application->batchId) ?></dd>

                <dt class="col-sm-4"><?= $e->html('Course version') ?></dt>
                <dd class="col-sm-8">#<?= $e->html($application->courseVersionId) ?></dd>

                <dt class="col-sm-4"><?= $e->html('Created') ?></dt>
                <dd class="col-sm-8"><?= $e->html($application->createdAt->format('d M Y H:i')) ?> UTC</dd>
            </dl>
        </div>
    </div>
</div>
<?php
$content = (string) ob_get_clean();
require dirname(__DIR__, 2) . '/layouts/base.php';
