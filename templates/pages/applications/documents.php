<?php

declare(strict_types=1);

/** @var \Academy\Infrastructure\View\Escaper $e */
/** @var string $title */
/** @var string $csrf */
/** @var \Academy\Application\Admissions\ApplicationWorkspaceView $view */

$application = $view->application;

ob_start();
?>
<div class="acad-application-documents" data-application-id="<?= $e->attr($application->applicationId) ?>" data-csrf="<?= $e->attr($csrf) ?>">
    <p class="acad-eyebrow mb-2"><?= $e->html('Application documents') ?></p>
    <h1 class="h3 mb-4"><?= $e->html($application->applicationNumber) ?></h1>

    <?php foreach ($view->requirements as $requirement): ?>
        <?php $current = $view->currentDocumentsByRequirementId[$requirement->requirementId] ?? null; ?>
        <div class="card mb-3 acad-document-requirement" data-requirement-id="<?= $e->attr($requirement->requirementId) ?>">
            <div class="card-body">
                <h2 class="h6">
                    <?= $e->html($requirement->documentName) ?>
                    <?php if ($requirement->mandatory): ?>
                        <span class="badge bg-danger"><?= $e->html('Mandatory') ?></span>
                    <?php else: ?>
                        <span class="badge bg-secondary"><?= $e->html('Optional') ?></span>
                    <?php endif; ?>
                </h2>
                <p class="text-muted small mb-2"><?= $e->html($requirement->description) ?></p>

                <?php if ($current === null): ?>
                    <p class="mb-2"><?= $e->html('No document uploaded yet.') ?></p>
                    <button type="button" class="btn btn-sm btn-outline-primary acad-document-upload-btn"><?= $e->html('Upload') ?></button>
                <?php else: ?>
                    <p class="mb-2">
                        <?= $e->html($current->displayFilename) ?>
                        &mdash;
                        <span class="badge bg-info text-uppercase"><?= $e->html($current->status) ?></span>
                        <span class="badge bg-light text-dark text-uppercase"><?= $e->html('scan: ' . $current->scanStatus) ?></span>
                    </p>
                    <a class="btn btn-sm btn-outline-secondary" href="/applications/<?= $e->attr($application->applicationId) ?>/documents/<?= $e->attr($current->documentSubmissionId) ?>/download"><?= $e->html('Download') ?></a>
                    <button type="button" class="btn btn-sm btn-outline-primary acad-document-replace-btn" data-submission-id="<?= $e->attr($current->documentSubmissionId) ?>"><?= $e->html('Replace') ?></button>
                <?php endif; ?>
            </div>
        </div>
    <?php endforeach; ?>

    <p class="mt-3"><a href="/applications/<?= $e->attr($application->applicationId) ?>"><?= $e->html('Back to application') ?></a></p>
</div>
<script src="/assets/js/acad/documents.js"></script>
<?php
$content = (string) ob_get_clean();
require dirname(__DIR__, 2) . '/layouts/base.php';
