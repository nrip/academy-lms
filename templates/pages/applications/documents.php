<?php

declare(strict_types=1);

use Academy\Domain\Credentials\DocumentFileValidator;

/** @var \Academy\Infrastructure\View\Escaper $e */
/** @var string $title */
/** @var string $csrf */
/** @var \Academy\Application\Admissions\ApplicationWorkspaceView $view */
/** @var string|null $uploadError */
/** @var string|null $uploadSuccess */
/** @var int|null $focusRequirementId */

$application = $view->application;
$correctionMode = $application->allowsLearnerDocumentCorrection();
$uploadError = $uploadError ?? null;
$uploadSuccess = $uploadSuccess ?? null;
$focusRequirementId = $focusRequirementId ?? null;

$statusLabel = static function (string $status): string {
    return match ($status) {
        'uploaded' => 'Uploaded',
        'under_review' => 'Under review',
        'verified' => 'Verified',
        'rejected' => 'Rejected',
        'resubmission_requested' => 'Correction required',
        'superseded' => 'Superseded',
        default => $status,
    };
};

$scanLabel = static function (string $scanStatus): string {
    return match ($scanStatus) {
        'pending' => 'Scan pending',
        'clean' => 'Scan clean',
        'infected' => 'Scan failed',
        'error' => 'Scan error',
        default => 'Scan: ' . $scanStatus,
    };
};

ob_start();
?>
<div
    class="acad-application-documents"
    data-application-id="<?= $e->attr($application->applicationId) ?>"
    data-csrf="<?= $e->attr($csrf) ?>"
    data-correction-mode="<?= $correctionMode ? '1' : '0' ?>"
>
    <p class="acad-eyebrow mb-2"><?= $e->html('Application documents') ?></p>
    <h1 class="h3 mb-4"><?= $e->html($application->applicationNumber) ?></h1>

    <?php if ($uploadSuccess !== null && $uploadSuccess !== ''): ?>
        <div class="alert alert-success" role="status"><?= $e->html($uploadSuccess) ?></div>
    <?php endif; ?>
    <?php if ($uploadError !== null && $uploadError !== ''): ?>
        <div class="alert alert-danger" role="alert"><?= $e->html($uploadError) ?></div>
    <?php endif; ?>

    <?php if ($correctionMode): ?>
        <div class="alert alert-warning" role="status">
            <?= $e->html('Correction mode — replace the documents flagged on the corrections page, then resubmit.') ?>
            <a class="alert-link" href="/applications/<?= $e->attr($application->applicationId) ?>/corrections"><?= $e->html('View corrections') ?></a>
        </div>
    <?php endif; ?>

    <?php foreach ($view->requirements as $requirement): ?>
        <?php
        $current = $view->currentDocumentsByRequirementId[$requirement->requirementId] ?? null;
        $accept = '.pdf,.jpg,.jpeg,.png,application/pdf,image/jpeg,image/png';
        $inputId = 'document-file-' . $requirement->requirementId;
        $focused = $focusRequirementId === $requirement->requirementId;
        ?>
        <div
            class="card mb-3 acad-document-requirement<?= $focused ? ' border-primary' : '' ?>"
            data-requirement-id="<?= $e->attr($requirement->requirementId) ?>"
            id="requirement-<?= $e->attr($requirement->requirementId) ?>"
        >
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
                <p class="text-muted small mb-3">
                    <?= $e->html('Accepted: PDF, JPG, PNG. Max size ' . (string) (int) (min((int) $requirement->maxSizeBytes, DocumentFileValidator::PLATFORM_MAX_BYTES) / 1048576) . ' MB.') ?>
                </p>

                <?php if ($current !== null): ?>
                    <p class="mb-2">
                        <strong><?= $e->html($current->displayFilename) ?></strong>
                        &mdash;
                        <span class="badge bg-info"><?= $e->html($statusLabel($current->status)) ?></span>
                        <span class="badge bg-light text-dark"><?= $e->html($scanLabel($current->scanStatus)) ?></span>
                    </p>
                    <a class="btn btn-sm btn-outline-secondary me-2" href="/applications/<?= $e->attr($application->applicationId) ?>/documents/<?= $e->attr($current->documentSubmissionId) ?>/download"><?= $e->html('Download') ?></a>
                <?php else: ?>
                    <p class="mb-3 text-muted"><?= $e->html('No document uploaded yet.') ?></p>
                <?php endif; ?>

                <form
                    method="post"
                    action="/applications/<?= $e->attr($application->applicationId) ?>/documents/upload"
                    enctype="multipart/form-data"
                    class="acad-document-upload-form mt-2"
                >
                    <input type="hidden" name="_csrf" value="<?= $e->attr($csrf) ?>">
                    <input type="hidden" name="requirement_id" value="<?= $e->attr($requirement->requirementId) ?>">
                    <?php if ($current !== null): ?>
                        <input type="hidden" name="replace_submission_id" value="<?= $e->attr($current->documentSubmissionId) ?>">
                    <?php endif; ?>
                    <div class="mb-2">
                        <label class="form-label" for="<?= $e->attr($inputId) ?>">
                            <?= $e->html($current === null ? 'Choose file' : 'Replace with a new file') ?>
                        </label>
                        <input
                            class="form-control"
                            type="file"
                            name="document"
                            id="<?= $e->attr($inputId) ?>"
                            accept="<?= $e->attr($accept) ?>"
                            required
                        >
                    </div>
                    <button type="submit" class="btn btn-sm btn-primary"><?= $e->html($current === null ? 'Upload' : 'Replace upload') ?></button>
                </form>
            </div>
        </div>
    <?php endforeach; ?>

    <p class="mt-3"><a href="/applications/<?= $e->attr($application->applicationId) ?>"><?= $e->html('Back to application') ?></a></p>
</div>
<?php
$content = (string) ob_get_clean();
require dirname(__DIR__, 2) . '/layouts/base.php';
