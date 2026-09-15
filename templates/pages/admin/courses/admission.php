<?php

declare(strict_types=1);

/** @var \Academy\Infrastructure\View\Escaper $e */
/** @var string $title */
/** @var string $csrf */
/** @var \Academy\Application\Courses\AdmissionConfigurationView $view */
/** @var ?string $error */
/** @var ?string $flash */
/** @var array<string, mixed>|null $postedEligibility */
/** @var array<string, mixed>|null $postedDocument */

$course = $view->course;
$version = $view->version;
$base = '/admin/courses/' . $course->courseId . '/versions/' . $version->versionId;
$postedEligibility = $postedEligibility ?? null;
$postedDocument = $postedDocument ?? null;

$selected = $view->selectedCategories;
$notes = $view->notes;
if (is_array($postedEligibility)) {
    $postedSelected = $postedEligibility['categories'] ?? [];
    $selected = [];
    if (is_array($postedSelected)) {
        foreach ($postedSelected as $label) {
            if (is_string($label) && trim($label) !== '') {
                $selected[] = trim($label);
            }
        }
    }
    $notes = (string) ($postedEligibility['eligibility_notes'] ?? '');
}

$addName = is_array($postedDocument) ? (string) ($postedDocument['name'] ?? '') : '';
$addDescription = is_array($postedDocument) ? (string) ($postedDocument['description'] ?? '') : '';
$addOrder = is_array($postedDocument) ? (string) ($postedDocument['display_order'] ?? '') : (string) $view->nextDisplayOrder;
$addMandatory = !is_array($postedDocument) || isset($postedDocument['mandatory']);

ob_start();
?>
<div class="acad-admin-admission">
    <p class="mb-2">
        <a href="<?= $e->attr($base) ?>"><?= $e->html('← Course details') ?></a>
    </p>
    <h1 class="h3 mb-1"><?= $e->html('Eligibility and documents') ?></h1>
    <p class="text-muted mb-3">
        <?= $e->html($course->masterTitle) ?>
        · <?= $e->html('Edition ' . (string) $version->versionNumber) ?>
        · <?= $e->html($version->isLocked() ? 'Published — cannot be changed' : 'Draft (editable)') ?>
    </p>
    <nav class="acad-author-steps mb-3" aria-label="Course setup">
        <a class="acad-author-steps__item" href="<?= $e->attr($base) ?>"><?= $e->html('1. Course details') ?></a>
        <a class="acad-author-steps__item" href="<?= $e->attr($base) ?>/curriculum"><?= $e->html('2. Chapters') ?></a>
        <span class="acad-author-steps__item acad-author-steps__item--current"><?= $e->html('3. Eligibility') ?></span>
        <a class="acad-author-steps__item" href="<?= $e->attr($base) ?>#publish"><?= $e->html('4. Publish') ?></a>
    </nav>

    <?php if ($flash !== null): ?>
        <div class="alert alert-success"><?= $e->html($flash) ?></div>
    <?php endif; ?>
    <?php if ($error !== null): ?>
        <div class="alert alert-danger"><?= $e->html($error) ?></div>
    <?php endif; ?>

    <?php if ($version->isLocked()): ?>
        <div class="alert alert-warning">
            <?= $e->html('This edition cannot be changed. Create the next edition to update eligibility or required documents.') ?>
        </div>
    <?php endif; ?>

    <section class="acad-panel mb-4">
        <h2 class="h5"><?= $e->html('Eligibility') ?></h2>
        <p class="text-muted"><?= $e->html('Learners see this on the public course page. It does not change admission decisions by itself.') ?></p>
        <?php if ($view->editable): ?>
            <form method="post" action="<?= $e->attr($base . '/admission/eligibility') ?>" class="row g-3">
                <input type="hidden" name="_csrf" value="<?= $e->attr($csrf) ?>">
                <div class="col-12">
                    <div class="form-label"><?= $e->html('Eligible learner categories') ?></div>
                    <?php foreach ($view->categoryOptions as $label): ?>
                        <?php $id = 'category-' . substr(sha1($label), 0, 8); ?>
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" name="categories[]"
                                   id="<?= $e->attr($id) ?>" value="<?= $e->attr($label) ?>"
                                <?= in_array($label, $selected, true) ? 'checked' : '' ?>>
                            <label class="form-check-label" for="<?= $e->attr($id) ?>"><?= $e->html($label) ?></label>
                        </div>
                    <?php endforeach; ?>
                    <?php if ($view->hasUnlistedCategories): ?>
                        <p class="form-text"><?= $e->html('This edition also keeps eligibility that is not one of these categories. Saving will keep it.') ?></p>
                    <?php endif; ?>
                </div>
                <div class="col-12">
                    <label class="form-label" for="eligibility_notes"><?= $e->html('Additional eligibility notes') ?></label>
                    <textarea class="form-control" id="eligibility_notes" name="eligibility_notes" rows="4"
                              placeholder="One note per line"><?= $e->html($notes) ?></textarea>
                    <div class="form-text"><?= $e->html('One note per line. Each line can be up to 255 characters.') ?></div>
                </div>
                <div class="col-12">
                    <button class="btn btn-primary" type="submit"><?= $e->html('Save eligibility') ?></button>
                </div>
            </form>
        <?php else: ?>
            <?php if ($selected === [] && trim($notes) === ''): ?>
                <p class="mb-0"><?= $e->html('Eligibility has not been set.') ?></p>
            <?php else: ?>
                <?php if ($selected !== []): ?>
                    <p class="mb-2"><?= $e->html(implode(', ', $selected)) ?></p>
                <?php endif; ?>
                <?php if (trim($notes) !== ''): ?>
                    <ul class="mb-0">
                        <?php foreach (preg_split("/\r\n|\n|\r/", $notes) ?: [] as $line): ?>
                            <?php if (trim((string) $line) === '') {
                                continue;
                            } ?>
                            <li><?= $e->html(trim((string) $line)) ?></li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            <?php endif; ?>
        <?php endif; ?>
    </section>

    <section class="acad-panel">
        <h2 class="h5"><?= $e->html('Required documents') ?></h2>
        <p class="text-muted"><?= $e->html('Learners upload these during the application. Reviewers verify the same list.') ?></p>
        <?php if ($view->documents === []): ?>
            <p class="text-muted"><?= $e->html('No documents yet.') ?></p>
        <?php else: ?>
            <?php foreach ($view->documents as $document): ?>
                <div class="border rounded p-3 mb-3">
                    <?php if ($view->editable): ?>
                        <form method="post" action="<?= $e->attr($base . '/admission/documents/' . $document->requirementId) ?>" class="row g-2">
                            <input type="hidden" name="_csrf" value="<?= $e->attr($csrf) ?>">
                            <div class="col-md-6">
                                <label class="form-label"><?= $e->html('Name') ?></label>
                                <input class="form-control" name="name" required maxlength="128" value="<?= $e->attr($document->documentName) ?>">
                            </div>
                            <div class="col-md-3">
                                <label class="form-label"><?= $e->html('Display order') ?></label>
                                <input class="form-control" name="display_order" inputmode="numeric" required value="<?= $e->attr((string) $document->sortOrder) ?>">
                            </div>
                            <div class="col-md-3 d-flex align-items-end">
                                <div class="form-check mb-2">
                                    <input class="form-check-input" type="checkbox" name="mandatory" value="1"
                                           id="mandatory-<?= $e->attr((string) $document->requirementId) ?>"
                                        <?= $document->mandatory ? 'checked' : '' ?>>
                                    <label class="form-check-label" for="mandatory-<?= $e->attr((string) $document->requirementId) ?>"><?= $e->html('Mandatory') ?></label>
                                </div>
                            </div>
                            <div class="col-12">
                                <label class="form-label"><?= $e->html('Description') ?></label>
                                <textarea class="form-control" name="description" rows="2"><?= $e->html($document->description) ?></textarea>
                            </div>
                            <div class="col-12">
                                <button class="btn btn-outline-primary btn-sm" type="submit"><?= $e->html('Save document') ?></button>
                            </div>
                        </form>
                        <form method="post" class="mt-2" action="<?= $e->attr($base . '/admission/documents/' . $document->requirementId . '/delete') ?>"
                              onsubmit="return confirm('Remove this document? Learners will not be asked for it on new applications.');">
                            <input type="hidden" name="_csrf" value="<?= $e->attr($csrf) ?>">
                            <button class="btn btn-outline-danger btn-sm" type="submit"><?= $e->html('Remove') ?></button>
                        </form>
                    <?php else: ?>
                        <div class="fw-semibold">
                            <?= $e->html($document->documentName) ?>
                            <span class="text-muted small"><?= $e->html($document->mandatory ? 'Mandatory' : 'Optional') ?></span>
                        </div>
                        <?php if ($document->description !== ''): ?>
                            <p class="mb-0 small"><?= $e->html($document->description) ?></p>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>

        <?php if ($view->editable): ?>
            <h3 class="h6 mt-4"><?= $e->html('Add a document') ?></h3>
            <form method="post" action="<?= $e->attr($base . '/admission/documents') ?>" class="row g-3">
                <input type="hidden" name="_csrf" value="<?= $e->attr($csrf) ?>">
                <div class="col-md-6">
                    <label class="form-label" for="document_name"><?= $e->html('Name') ?></label>
                    <input class="form-control" id="document_name" name="name" required maxlength="128" value="<?= $e->attr($addName) ?>">
                </div>
                <div class="col-md-3">
                    <label class="form-label" for="display_order"><?= $e->html('Display order') ?></label>
                    <input class="form-control" id="display_order" name="display_order" inputmode="numeric" required value="<?= $e->attr($addOrder) ?>">
                </div>
                <div class="col-md-3 d-flex align-items-end">
                    <div class="form-check mb-2">
                        <input class="form-check-input" type="checkbox" name="mandatory" value="1" id="document_mandatory"
                            <?= $addMandatory ? 'checked' : '' ?>>
                        <label class="form-check-label" for="document_mandatory"><?= $e->html('Mandatory') ?></label>
                    </div>
                </div>
                <div class="col-12">
                    <label class="form-label" for="document_description"><?= $e->html('Description') ?></label>
                    <textarea class="form-control" id="document_description" name="description" rows="2"><?= $e->html($addDescription) ?></textarea>
                </div>
                <div class="col-12">
                    <button class="btn btn-primary" type="submit"><?= $e->html('Add document') ?></button>
                </div>
            </form>
        <?php endif; ?>
    </section>
</div>
<?php
$content = ob_get_clean();
require dirname(__DIR__, 3) . '/layouts/base.php';
