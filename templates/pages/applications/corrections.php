<?php

declare(strict_types=1);

/** @var \Academy\Infrastructure\View\Escaper $e */
/** @var string $title */
/** @var string $csrf */
/** @var \Academy\Application\Admissions\ApplicationWorkspaceView $view */
/** @var list<array{requirement: \Academy\Domain\Courses\CourseDocumentRequirement, document: \Academy\Domain\Credentials\DocumentSubmission, reasonLabel: string}> $correctionItems */

$application = $view->application;

ob_start();
?>
<div class="acad-application-corrections">
    <p class="acad-eyebrow mb-2"><?= $e->html('Correction required') ?></p>
    <h1 class="h3 mb-4"><?= $e->html($application->applicationNumber) ?></h1>

    <div class="alert alert-warning" role="status">
        <?= $e->html('Your reviewer has requested corrections to the documents below. Replace each document, then resubmit when ready.') ?>
    </div>

    <?php if ($correctionItems === []): ?>
        <p class="text-muted"><?= $e->html('No documents currently require correction.') ?></p>
    <?php else: ?>
        <?php foreach ($correctionItems as $item): ?>
            <div class="card mb-3">
                <div class="card-body">
                    <h2 class="h6"><?= $e->html($item['requirement']->documentName) ?></h2>
                    <?php if ($item['reasonLabel'] !== ''): ?>
                        <p class="small mb-1">
                            <strong><?= $e->html('Reason:') ?></strong>
                            <?= $e->html($item['reasonLabel']) ?>
                        </p>
                    <?php endif; ?>
                    <?php if ($item['document']->learnerVisibleMessage !== null): ?>
                        <p class="mb-2"><?= $e->html($item['document']->learnerVisibleMessage) ?></p>
                    <?php endif; ?>
                    <a
                        class="btn btn-sm btn-outline-primary"
                        href="/applications/<?= $e->attr($application->applicationId) ?>/documents"
                    ><?= $e->html('Replace document') ?></a>
                </div>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>

    <div class="card mt-4">
        <div class="card-body">
            <h2 class="h6"><?= $e->html('Resubmit corrections') ?></h2>
            <p class="small text-muted"><?= $e->html('All corrected documents must be uploaded and scanned before you can resubmit.') ?></p>
            <form method="post" action="/applications/<?= $e->attr($application->applicationId) ?>/resubmit-corrections">
                <input type="hidden" name="_csrf" value="<?= $e->attr($csrf) ?>">
                <input type="hidden" name="state_version" value="<?= $e->attr($application->stateVersion) ?>">
                <button type="submit" class="btn btn-primary"><?= $e->html('Resubmit for review') ?></button>
            </form>
        </div>
    </div>

    <p class="mt-3">
        <a href="/applications/<?= $e->attr($application->applicationId) ?>"><?= $e->html('Back to application') ?></a>
    </p>
</div>
<?php
$content = (string) ob_get_clean();
require dirname(__DIR__, 2) . '/layouts/base.php';
