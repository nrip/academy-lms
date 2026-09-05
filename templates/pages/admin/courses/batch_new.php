<?php

declare(strict_types=1);

/** @var \Academy\Infrastructure\View\Escaper $e */
/** @var string $title */
/** @var string $csrf */
/** @var \Academy\Domain\Courses\Course $course */
/** @var \Academy\Domain\Courses\CourseVersion $version */
/** @var array<string, string> $values */
/** @var ?string $error */

$versionBase = '/admin/courses/' . $course->courseId . '/versions/' . $version->versionId;

ob_start();
?>
<div class="acad-admin-batch-new">
    <p class="mb-2">
        <a href="<?= $e->attr($versionBase) ?>">
            <?= $e->html('← Version ' . (string) $version->versionNumber) ?>
        </a>
    </p>
    <h1 class="h3 mb-1"><?= $e->html('Create batch') ?></h1>
    <p class="text-muted mb-3">
        <?= $e->html($course->masterTitle . ' · ' . $version->title) ?>
        · <?= $e->html('Status will be open for applications') ?>
    </p>
    <?php if ($error !== null): ?>
        <div class="alert alert-danger"><?= $e->html($error) ?></div>
    <?php endif; ?>

    <form method="post" action="<?= $e->attr($versionBase) ?>/batches" class="row g-3">
        <input type="hidden" name="_csrf" value="<?= $e->attr($csrf) ?>">
        <div class="col-md-6">
            <label class="form-label" for="batch_code"><?= $e->html('Batch code') ?></label>
            <input class="form-control" id="batch_code" name="batch_code" required
                   value="<?= $e->attr($values['batch_code']) ?>">
        </div>
        <div class="col-md-6">
            <label class="form-label" for="name"><?= $e->html('Name') ?></label>
            <input class="form-control" id="name" name="name" required
                   value="<?= $e->attr($values['name']) ?>">
        </div>
        <div class="col-md-6">
            <label class="form-label" for="starts_at"><?= $e->html('Starts at') ?></label>
            <input class="form-control" type="datetime-local" id="starts_at" name="starts_at" required
                   value="<?= $e->attr($values['starts_at']) ?>">
        </div>
        <div class="col-md-6">
            <label class="form-label" for="ends_at"><?= $e->html('Ends at') ?></label>
            <input class="form-control" type="datetime-local" id="ends_at" name="ends_at" required
                   value="<?= $e->attr($values['ends_at']) ?>">
        </div>
        <div class="col-md-6">
            <label class="form-label" for="applications_open_at"><?= $e->html('Applications open') ?></label>
            <input class="form-control" type="datetime-local" id="applications_open_at" name="applications_open_at" required
                   value="<?= $e->attr($values['applications_open_at']) ?>">
        </div>
        <div class="col-md-6">
            <label class="form-label" for="applications_close_at"><?= $e->html('Applications close') ?></label>
            <input class="form-control" type="datetime-local" id="applications_close_at" name="applications_close_at" required
                   value="<?= $e->attr($values['applications_close_at']) ?>">
        </div>
        <div class="col-md-3">
            <label class="form-label" for="min_capacity"><?= $e->html('Min capacity') ?></label>
            <input class="form-control" type="number" min="0" id="min_capacity" name="min_capacity" required
                   value="<?= $e->attr($values['min_capacity']) ?>">
        </div>
        <div class="col-md-3">
            <label class="form-label" for="max_capacity"><?= $e->html('Max capacity') ?></label>
            <input class="form-control" type="number" min="1" id="max_capacity" name="max_capacity" required
                   value="<?= $e->attr($values['max_capacity']) ?>">
        </div>
        <div class="col-md-3">
            <label class="form-label" for="delivery_mode"><?= $e->html('Delivery mode') ?></label>
            <input class="form-control" id="delivery_mode" name="delivery_mode" required
                   value="<?= $e->attr($values['delivery_mode']) ?>">
        </div>
        <div class="col-md-3">
            <label class="form-label" for="timezone"><?= $e->html('Timezone') ?></label>
            <input class="form-control" id="timezone" name="timezone" required
                   value="<?= $e->attr($values['timezone']) ?>">
        </div>
        <div class="col-12">
            <label class="form-label" for="venue_or_online_details"><?= $e->html('Venue / online details') ?></label>
            <textarea class="form-control" id="venue_or_online_details" name="venue_or_online_details" rows="2" required><?= $e->html($values['venue_or_online_details']) ?></textarea>
        </div>
        <div class="col-md-4">
            <label class="form-label" for="currency"><?= $e->html('Currency') ?></label>
            <input class="form-control" id="currency" name="currency" maxlength="3" required
                   value="<?= $e->attr($values['currency']) ?>">
        </div>
        <div class="col-md-4">
            <label class="form-label" for="fee_override"><?= $e->html('Fee override (optional)') ?></label>
            <input class="form-control" id="fee_override" name="fee_override"
                   value="<?= $e->attr($values['fee_override']) ?>">
        </div>
        <div class="col-12">
            <button class="btn btn-primary" type="submit"><?= $e->html('Create batch') ?></button>
        </div>
    </form>
</div>
<?php
$content = ob_get_clean();
require dirname(__DIR__, 3) . '/layouts/base.php';
