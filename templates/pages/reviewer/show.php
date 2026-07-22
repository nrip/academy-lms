<?php

declare(strict_types=1);

use Academy\Domain\Admissions\ApplicationStatus;
use Academy\Domain\Credentials\DocumentRejectionReasonCode;
use Academy\Domain\Credentials\DocumentScanStatus;
use Academy\Domain\Credentials\DocumentSubmissionStatus;

/** @var \Academy\Infrastructure\View\Escaper $e */
/** @var string $title */
/** @var string $csrf */
/** @var \Academy\Application\Review\ReviewerApplicationDetailView $view */
/** @var int|null $authUserId */
/** @var string|null $flashOk */
/** @var list<string> $documentReasonCodes */
/** @var list<string> $applicationReasonCodes */

$application = $view->application;
$profile = $view->profileSummary;
$assignment = $view->activeAssignment;
$ownsClaim = $assignment !== null
    && $assignment->isActive()
    && $authUserId !== null
    && $assignment->reviewerUserId === $authUserId;
$canClaim = $assignment === null || !$assignment->isActive();
$displayName = $profile?->preferredDisplayName
    ?? trim(implode(' ', array_filter([$profile?->firstName, $profile?->lastName])));

/** @var array<string, string> $flashMessages */
$flashMessages = [
    'claimed' => 'Application claimed.',
    'released' => 'Claim released.',
    'verified' => 'Document verified.',
    'rejected' => 'Document rejected.',
    'resubmission_requested' => 'Document resubmission requested.',
    'correction_requested' => 'Correction request sent to learner.',
    'approved' => 'Application approved — payment pending.',
    'application_rejected' => 'Application rejected.',
];

ob_start();
?>
<div class="acad-reviewer-detail">
    <p class="acad-eyebrow mb-2">
        <a href="/reviewer/applications"><?= $e->html('Reviewer queue') ?></a>
    </p>
    <h1 class="h3 mb-2">
        <?= $e->html($application->applicationNumber) ?>
        <span class="badge bg-secondary text-uppercase"><?= $e->html($application->status) ?></span>
    </h1>
    <p class="text-muted mb-4"><?= $e->html($view->courseTitle) ?> &mdash; <?= $e->html($view->batchLabel) ?></p>

    <?php if ($flashOk !== null && isset($flashMessages[$flashOk])): ?>
        <div class="alert alert-success" role="status"><?= $e->html($flashMessages[$flashOk]) ?></div>
    <?php endif; ?>

    <div class="row g-3 mb-4">
        <div class="col-lg-6">
            <div class="card h-100">
                <div class="card-body">
                    <h2 class="h6"><?= $e->html('Application summary') ?></h2>
                    <dl class="row mb-0 small">
                        <dt class="col-sm-5"><?= $e->html('Submitted') ?></dt>
                        <dd class="col-sm-7">
                            <?= $view->submittedAt !== null
                                ? $e->html($view->submittedAt->format('d M Y H:i')) . ' UTC'
                                : $e->html('—') ?>
                        </dd>
                        <dt class="col-sm-5"><?= $e->html('State version') ?></dt>
                        <dd class="col-sm-7"><?= $e->html((string) $application->stateVersion) ?></dd>
                        <dt class="col-sm-5"><?= $e->html('Assignment') ?></dt>
                        <dd class="col-sm-7">
                            <?php if ($assignment !== null && $assignment->isActive()): ?>
                                <?= $e->html('Reviewer #') ?><?= $e->html((string) $assignment->reviewerUserId) ?>
                            <?php else: ?>
                                <?= $e->html('Unassigned') ?>
                            <?php endif; ?>
                        </dd>
                    </dl>
                </div>
            </div>
        </div>
        <div class="col-lg-6">
            <div class="card h-100">
                <div class="card-body">
                    <h2 class="h6"><?= $e->html('Learner profile') ?></h2>
                    <?php if ($profile === null): ?>
                        <p class="text-muted mb-0"><?= $e->html('Profile not available.') ?></p>
                    <?php else: ?>
                        <dl class="row mb-0 small">
                            <dt class="col-sm-5"><?= $e->html('Name') ?></dt>
                            <dd class="col-sm-7"><?= $e->html($displayName !== '' ? $displayName : '—') ?></dd>
                            <dt class="col-sm-5"><?= $e->html('Profession') ?></dt>
                            <dd class="col-sm-7"><?= $e->html($profile->profession ?? '—') ?></dd>
                            <dt class="col-sm-5"><?= $e->html('Registration no.') ?></dt>
                            <dd class="col-sm-7"><?= $e->html($profile->medicalCouncilRegistrationNumber ?? '—') ?></dd>
                        </dl>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <div class="d-flex flex-wrap gap-2 mb-4">
        <?php if ($canClaim): ?>
            <form method="post" action="/reviewer/applications/<?= $e->attr($application->applicationId) ?>/claim">
                <input type="hidden" name="_csrf" value="<?= $e->attr($csrf) ?>">
                <button type="submit" class="btn btn-outline-primary"><?= $e->html('Claim application') ?></button>
            </form>
        <?php endif; ?>
        <?php if ($ownsClaim && $assignment !== null): ?>
            <form method="post" action="/reviewer/applications/<?= $e->attr($application->applicationId) ?>/release">
                <input type="hidden" name="_csrf" value="<?= $e->attr($csrf) ?>">
                <input type="hidden" name="assignment_row_version" value="<?= $e->attr($assignment->rowVersion) ?>">
                <button type="submit" class="btn btn-outline-secondary"><?= $e->html('Release claim') ?></button>
            </form>
        <?php endif; ?>
    </div>

    <h2 class="h5 mb-3"><?= $e->html('Document checklist') ?></h2>
    <?php foreach ($view->documentChecklist as $item): ?>
        <?php
        $canReview = $ownsClaim
            && $item->status === DocumentSubmissionStatus::UNDER_REVIEW
            && $item->scanStatus === DocumentScanStatus::CLEAN
            && $item->documentSubmissionId !== null;
        ?>
        <div class="card mb-3">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-start flex-wrap gap-2 mb-2">
                    <h3 class="h6 mb-0">
                        <?= $e->html($item->documentName) ?>
                        <?php if ($item->mandatory): ?>
                            <span class="badge bg-danger"><?= $e->html('Mandatory') ?></span>
                        <?php endif; ?>
                    </h3>
                    <?php if ($item->status !== null): ?>
                        <span class="badge bg-info text-uppercase"><?= $e->html($item->status) ?></span>
                    <?php endif; ?>
                </div>

                <?php if ($item->documentSubmissionId === null): ?>
                    <p class="text-muted mb-0"><?= $e->html('No current submission.') ?></p>
                <?php else: ?>
                    <p class="small mb-2">
                        <?= $e->html($item->displayFilename ?? 'Document') ?>
                        <?php if ($item->scanStatus !== null): ?>
                            <span class="badge bg-light text-dark text-uppercase"><?= $e->html('scan: ' . $item->scanStatus) ?></span>
                        <?php endif; ?>
                    </p>
                    <?php if ($item->learnerVisibleMessage !== null): ?>
                        <p class="small text-muted"><?= $e->html($item->learnerVisibleMessage) ?></p>
                    <?php endif; ?>

                    <a
                        class="btn btn-sm btn-outline-secondary me-2"
                        href="/reviewer/applications/<?= $e->attr($application->applicationId) ?>/documents/<?= $e->attr($item->documentSubmissionId) ?>/download"
                    ><?= $e->html('Download') ?></a>

                    <?php if ($canReview): ?>
                        <form method="post" action="/reviewer/applications/<?= $e->attr($application->applicationId) ?>/documents/<?= $e->attr($item->documentSubmissionId) ?>/verify" class="d-inline">
                            <input type="hidden" name="_csrf" value="<?= $e->attr($csrf) ?>">
                            <input type="hidden" name="internal_note" value="">
                            <button type="submit" class="btn btn-sm btn-success"><?= $e->html('Verify') ?></button>
                        </form>

                        <details class="mt-2">
                            <summary class="small"><?= $e->html('Reject document') ?></summary>
                            <form method="post" action="/reviewer/applications/<?= $e->attr($application->applicationId) ?>/documents/<?= $e->attr($item->documentSubmissionId) ?>/reject" class="mt-2">
                                <input type="hidden" name="_csrf" value="<?= $e->attr($csrf) ?>">
                                <div class="mb-2">
                                    <label class="form-label small"><?= $e->html('Reason') ?></label>
                                    <select name="reason_code" class="form-select form-select-sm" required>
                                        <?php foreach ($documentReasonCodes as $code): ?>
                                            <option value="<?= $e->attr($code) ?>"><?= $e->html(DocumentRejectionReasonCode::label($code)) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="mb-2">
                                    <label class="form-label small"><?= $e->html('Message to learner') ?></label>
                                    <textarea name="learner_visible_message" class="form-control form-control-sm" rows="2" maxlength="500"></textarea>
                                </div>
                                <div class="mb-2">
                                    <label class="form-label small"><?= $e->html('Internal note') ?></label>
                                    <textarea name="internal_note" class="form-control form-control-sm" rows="2" maxlength="1000"></textarea>
                                </div>
                                <button type="submit" class="btn btn-sm btn-outline-danger"><?= $e->html('Reject') ?></button>
                            </form>
                        </details>

                        <details class="mt-2">
                            <summary class="small"><?= $e->html('Request resubmission') ?></summary>
                            <form method="post" action="/reviewer/applications/<?= $e->attr($application->applicationId) ?>/documents/<?= $e->attr($item->documentSubmissionId) ?>/request-resubmission" class="mt-2">
                                <input type="hidden" name="_csrf" value="<?= $e->attr($csrf) ?>">
                                <div class="mb-2">
                                    <label class="form-label small"><?= $e->html('Reason') ?></label>
                                    <select name="reason_code" class="form-select form-select-sm" required>
                                        <?php foreach ($documentReasonCodes as $code): ?>
                                            <option value="<?= $e->attr($code) ?>"><?= $e->html(DocumentRejectionReasonCode::label($code)) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="mb-2">
                                    <label class="form-label small"><?= $e->html('Message to learner') ?></label>
                                    <textarea name="learner_visible_message" class="form-control form-control-sm" rows="2" maxlength="500"></textarea>
                                </div>
                                <div class="mb-2">
                                    <label class="form-label small"><?= $e->html('Internal note') ?></label>
                                    <textarea name="internal_note" class="form-control form-control-sm" rows="2" maxlength="1000"></textarea>
                                </div>
                                <button type="submit" class="btn btn-sm btn-outline-warning"><?= $e->html('Request resubmission') ?></button>
                            </form>
                        </details>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        </div>
    <?php endforeach; ?>

    <?php if ($ownsClaim && $application->status === ApplicationStatus::UNDER_REVIEW): ?>
        <div class="card mb-3">
            <div class="card-body">
                <h2 class="h6"><?= $e->html('Request correction (multiple documents)') ?></h2>
                <form method="post" action="/reviewer/applications/<?= $e->attr($application->applicationId) ?>/request-correction">
                    <input type="hidden" name="_csrf" value="<?= $e->attr($csrf) ?>">
                    <input type="hidden" name="state_version" value="<?= $e->attr($application->stateVersion) ?>">
                    <div class="mb-2">
                        <?php foreach ($view->documentChecklist as $item): ?>
                            <?php if ($item->documentSubmissionId === null) {
                                continue;
                            } ?>
                            <div class="form-check">
                                <input
                                    class="form-check-input"
                                    type="checkbox"
                                    name="requirement_ids[]"
                                    value="<?= $e->attr($item->requirementId) ?>"
                                    id="req-<?= $e->attr($item->requirementId) ?>"
                                >
                                <label class="form-check-label" for="req-<?= $e->attr($item->requirementId) ?>">
                                    <?= $e->html($item->documentName) ?>
                                </label>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <div class="mb-2">
                        <label class="form-label small"><?= $e->html('Reason') ?></label>
                        <select name="reason_code" class="form-select form-select-sm" required>
                            <?php foreach ($documentReasonCodes as $code): ?>
                                <option value="<?= $e->attr($code) ?>"><?= $e->html(DocumentRejectionReasonCode::label($code)) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-2">
                        <label class="form-label small"><?= $e->html('Message to learner') ?></label>
                        <textarea name="learner_visible_message" class="form-control form-control-sm" rows="2" maxlength="500"></textarea>
                    </div>
                    <div class="mb-2">
                        <label class="form-label small"><?= $e->html('Internal note') ?></label>
                        <textarea name="internal_note" class="form-control form-control-sm" rows="2" maxlength="1000"></textarea>
                    </div>
                    <button type="submit" class="btn btn-sm btn-outline-warning"><?= $e->html('Send correction request') ?></button>
                </form>
            </div>
        </div>

        <div class="row g-3 mb-4">
            <div class="col-md-6">
                <div class="card border-success">
                    <div class="card-body">
                        <h2 class="h6"><?= $e->html('Approve application') ?></h2>
                        <p class="small text-muted"><?= $e->html('Moves application to payment pending. No payment is created.') ?></p>
                        <form method="post" action="/reviewer/applications/<?= $e->attr($application->applicationId) ?>/approve">
                            <input type="hidden" name="_csrf" value="<?= $e->attr($csrf) ?>">
                            <input type="hidden" name="state_version" value="<?= $e->attr($application->stateVersion) ?>">
                            <button type="submit" class="btn btn-success"><?= $e->html('Approve') ?></button>
                        </form>
                    </div>
                </div>
            </div>
            <div class="col-md-6">
                <div class="card border-danger">
                    <div class="card-body">
                        <h2 class="h6"><?= $e->html('Reject application') ?></h2>
                        <form method="post" action="/reviewer/applications/<?= $e->attr($application->applicationId) ?>/reject">
                            <input type="hidden" name="_csrf" value="<?= $e->attr($csrf) ?>">
                            <input type="hidden" name="state_version" value="<?= $e->attr($application->stateVersion) ?>">
                            <div class="mb-2">
                                <label class="form-label small"><?= $e->html('Reason') ?></label>
                                <select name="reason_code" class="form-select form-select-sm" required>
                                    <?php foreach ($applicationReasonCodes as $code): ?>
                                        <option value="<?= $e->attr($code) ?>"><?= $e->html(DocumentRejectionReasonCode::label($code)) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="mb-2">
                                <label class="form-label small"><?= $e->html('Message to learner') ?></label>
                                <textarea name="learner_visible_message" class="form-control form-control-sm" rows="2" maxlength="500"></textarea>
                            </div>
                            <div class="mb-2">
                                <label class="form-label small"><?= $e->html('Internal note') ?></label>
                                <textarea name="internal_note" class="form-control form-control-sm" rows="2" maxlength="1000"></textarea>
                            </div>
                            <button type="submit" class="btn btn-outline-danger"><?= $e->html('Reject application') ?></button>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <h2 class="h5 mb-3"><?= $e->html('Verification history') ?></h2>
    <?php if ($view->verificationHistory === []): ?>
        <p class="text-muted"><?= $e->html('No verification events yet.') ?></p>
    <?php else: ?>
        <ul class="list-group mb-4">
            <?php foreach ($view->verificationHistory as $entry): ?>
                <li class="list-group-item small">
                    <strong><?= $e->html($entry->action) ?></strong>
                    <?= $e->html(' — ') ?>
                    <?= $e->html($entry->createdAt->format('d M Y H:i')) ?> UTC
                    <?php if ($entry->statusBefore !== null || $entry->statusAfter !== null): ?>
                        <span class="text-muted">
                            <?= $e->html('(' . ($entry->statusBefore ?? '—') . ' → ' . ($entry->statusAfter ?? '—') . ')') ?>
                        </span>
                    <?php endif; ?>
                    <?php if ($entry->reasonCode !== null): ?>
                        <span class="d-block"><?= $e->html(DocumentRejectionReasonCode::label($entry->reasonCode)) ?></span>
                    <?php endif; ?>
                    <?php if ($entry->learnerVisibleMessage !== null): ?>
                        <span class="d-block text-muted"><?= $e->html($entry->learnerVisibleMessage) ?></span>
                    <?php endif; ?>
                    <?php if ($entry->internalNote !== null): ?>
                        <span class="d-block"><em><?= $e->html($entry->internalNote) ?></em></span>
                    <?php endif; ?>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
</div>
<?php
$content = (string) ob_get_clean();
require dirname(__DIR__, 2) . '/layouts/base.php';
