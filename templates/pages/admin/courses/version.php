<?php

declare(strict_types=1);

/** @var \Academy\Infrastructure\View\Escaper $e */
/** @var string $title */
/** @var string $csrf */
/** @var \Academy\Domain\Courses\Course $course */
/** @var \Academy\Domain\Courses\CourseVersion $version */
/** @var list<\Academy\Domain\Courses\Batch> $batches */
/** @var ?string $error */
/** @var ?string $flash */
/** @var array<string, mixed>|null $posted */

$posted = $posted ?? null;
$batches = $batches ?? [];
$val = static function (string $key, string $fallback) use ($posted, $version): string {
    if (is_array($posted) && array_key_exists($key, $posted)) {
        return (string) $posted[$key];
    }

    return match ($key) {
        'title' => $version->title,
        'description' => $version->description,
        'learning_objectives' => $version->learningObjectives,
        'intended_audience' => $version->intendedAudience,
        'syllabus_summary' => $version->syllabusSummary,
        'delivery_type' => $version->deliveryType,
        'duration_text' => $version->durationText,
        'validity_period_days' => $version->validityPeriodDays === null ? '' : (string) $version->validityPeriodDays,
        'standard_fee' => $version->standardFee,
        'gst_rate' => $version->gstRate,
        'currency' => $version->currency,
        'certificate_type' => $version->certificateType,
        default => $fallback,
    };
};

$versionBase = '/admin/courses/' . $course->courseId . '/versions/' . $version->versionId;

ob_start();
?>
<div class="acad-admin-course-version">
    <p class="mb-2">
        <a href="/admin/courses/<?= $e->attr((string) $course->courseId) ?>">
            <?= $e->html('← ' . $course->masterTitle) ?>
        </a>
    </p>
    <h1 class="h3 mb-1"><?= $e->html('Version ' . (string) $version->versionNumber) ?></h1>
    <p class="text-muted mb-3">
        <?= $e->html('Status: ' . $version->status) ?>
        · <?= $e->html($version->isLocked() ? 'Locked (immutable)' : 'Draft (editable)') ?>
        · <?= $e->html('Admission mode: ' . $version->admissionMode) ?>
        <?php if ($version->clonedFromVersionId !== null): ?>
            · <?= $e->html('Cloned from version id ' . (string) $version->clonedFromVersionId) ?>
        <?php endif; ?>
    </p>
    <p class="mb-3 d-flex flex-wrap gap-2">
        <a class="btn btn-outline-primary btn-sm"
           href="<?= $e->attr($versionBase) ?>/curriculum">
            <?= $e->html('Open curriculum') ?>
        </a>
        <?php if (!$version->isLocked()): ?>
            <form method="post" action="<?= $e->attr($versionBase) ?>/publish" class="d-inline">
                <input type="hidden" name="_csrf" value="<?= $e->attr($csrf) ?>">
                <button class="btn btn-success btn-sm" type="submit"><?= $e->html('Publish version') ?></button>
            </form>
        <?php else: ?>
            <a class="btn btn-outline-success btn-sm"
               href="<?= $e->attr($versionBase) ?>/batches/new">
                <?= $e->html('Create batch') ?>
            </a>
        <?php endif; ?>
        <form method="post" action="<?= $e->attr($versionBase) ?>/clone" class="d-inline">
            <input type="hidden" name="_csrf" value="<?= $e->attr($csrf) ?>">
            <button class="btn btn-outline-secondary btn-sm" type="submit"><?= $e->html('Clone to Version N+1') ?></button>
        </form>
        <?php if ($course->slug !== '' && $version->isPublished()): ?>
            <a class="btn btn-link btn-sm" href="/courses/<?= $e->attr($course->slug) ?>" target="_blank" rel="noopener">
                <?= $e->html('View catalogue page') ?>
            </a>
        <?php endif; ?>
    </p>
    <?php if ($flash !== null): ?>
        <div class="alert alert-success"><?= $e->html($flash) ?></div>
    <?php endif; ?>
    <?php if ($error !== null): ?>
        <div class="alert alert-danger"><?= $e->html($error) ?></div>
    <?php endif; ?>

    <?php if ($batches !== []): ?>
        <div class="mb-4">
            <h2 class="h5"><?= $e->html('Batches') ?></h2>
            <ul class="list-group">
                <?php foreach ($batches as $batch): ?>
                    <li class="list-group-item d-flex justify-content-between align-items-center">
                        <span>
                            <?= $e->html($batch->batchCode . ' — ' . $batch->name) ?>
                            <span class="text-muted small"><?= $e->html('(' . $batch->status . ')') ?></span>
                        </span>
                    </li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <?php if ($version->isLocked()): ?>
        <div class="alert alert-warning">
            <?= $e->html('This CourseVersion is locked. Use Clone to Version N+1 to make curriculum or fee changes.') ?>
        </div>
        <dl class="row">
            <dt class="col-sm-3"><?= $e->html('Title') ?></dt><dd class="col-sm-9"><?= $e->html($version->title) ?></dd>
            <dt class="col-sm-3"><?= $e->html('Fee') ?></dt>
            <dd class="col-sm-9"><?= $e->html($version->currency . ' ' . $version->standardFee . ' + GST ' . $version->gstRate . '%') ?></dd>
            <dt class="col-sm-3"><?= $e->html('Description') ?></dt><dd class="col-sm-9"><?= $e->html($version->description) ?></dd>
        </dl>
    <?php else: ?>
        <form method="post"
              action="<?= $e->attr($versionBase) ?>"
              class="row g-3">
            <input type="hidden" name="_csrf" value="<?= $e->attr($csrf) ?>">
            <div class="col-12">
                <label class="form-label" for="title"><?= $e->html('Title') ?></label>
                <input class="form-control" id="title" name="title" required value="<?= $e->attr($val('title', '')) ?>">
            </div>
            <div class="col-12">
                <label class="form-label" for="description"><?= $e->html('Description') ?></label>
                <textarea class="form-control" id="description" name="description" rows="4" required><?= $e->html($val('description', '')) ?></textarea>
            </div>
            <div class="col-12">
                <label class="form-label" for="learning_objectives"><?= $e->html('Learning objectives') ?></label>
                <textarea class="form-control" id="learning_objectives" name="learning_objectives" rows="3" required><?= $e->html($val('learning_objectives', '')) ?></textarea>
            </div>
            <div class="col-12">
                <label class="form-label" for="intended_audience"><?= $e->html('Intended audience') ?></label>
                <textarea class="form-control" id="intended_audience" name="intended_audience" rows="2" required><?= $e->html($val('intended_audience', '')) ?></textarea>
            </div>
            <div class="col-12">
                <label class="form-label" for="syllabus_summary"><?= $e->html('Syllabus summary') ?></label>
                <textarea class="form-control" id="syllabus_summary" name="syllabus_summary" rows="3" required><?= $e->html($val('syllabus_summary', '')) ?></textarea>
            </div>
            <div class="col-md-6">
                <label class="form-label" for="delivery_type"><?= $e->html('Delivery type') ?></label>
                <input class="form-control" id="delivery_type" name="delivery_type" required value="<?= $e->attr($val('delivery_type', '')) ?>">
            </div>
            <div class="col-md-6">
                <label class="form-label" for="duration_text"><?= $e->html('Duration') ?></label>
                <input class="form-control" id="duration_text" name="duration_text" required value="<?= $e->attr($val('duration_text', '')) ?>">
            </div>
            <div class="col-md-4">
                <label class="form-label" for="standard_fee"><?= $e->html('Standard fee') ?></label>
                <input class="form-control" id="standard_fee" name="standard_fee" required value="<?= $e->attr($val('standard_fee', '')) ?>">
            </div>
            <div class="col-md-4">
                <label class="form-label" for="gst_rate"><?= $e->html('GST rate %') ?></label>
                <input class="form-control" id="gst_rate" name="gst_rate" required value="<?= $e->attr($val('gst_rate', '')) ?>">
            </div>
            <div class="col-md-4">
                <label class="form-label" for="currency"><?= $e->html('Currency') ?></label>
                <input class="form-control" id="currency" name="currency" required maxlength="3" value="<?= $e->attr($val('currency', 'INR')) ?>">
            </div>
            <div class="col-md-6">
                <label class="form-label" for="validity_period_days"><?= $e->html('Validity period (days)') ?></label>
                <input class="form-control" id="validity_period_days" name="validity_period_days" value="<?= $e->attr($val('validity_period_days', '')) ?>">
            </div>
            <div class="col-md-6">
                <label class="form-label" for="certificate_type"><?= $e->html('Certificate type') ?></label>
                <input class="form-control" id="certificate_type" name="certificate_type" required value="<?= $e->attr($val('certificate_type', '')) ?>">
            </div>
            <div class="col-12">
                <button class="btn btn-primary" type="submit"><?= $e->html('Save draft') ?></button>
            </div>
        </form>
    <?php endif; ?>
</div>
<?php
$content = ob_get_clean();
require dirname(__DIR__, 3) . '/layouts/base.php';
