<?php

declare(strict_types=1);

/** @var \Academy\Infrastructure\View\Escaper $e */
/** @var string $title */
/** @var string $csrf */
/** @var array{admin_email: string, course_id: string, include_future_versions: string} $values */
/** @var ?string $error */
/** @var ?string $flash */

ob_start();
?>
<div class="acad-admin-course-scope">
    <p class="mb-2"><a href="/admin/courses"><?= $e->html('← Course administration') ?></a></p>
    <h1 class="h3 mb-3"><?= $e->html('Assign Course Admin scope') ?></h1>
    <p class="text-muted"><?= $e->html('Super Admin only. Grants course-level object scope (include_future_versions optional).') ?></p>
    <?php if ($flash !== null): ?>
        <div class="alert alert-success"><?= $e->html($flash) ?></div>
    <?php endif; ?>
    <?php if ($error !== null): ?>
        <div class="alert alert-danger"><?= $e->html($error) ?></div>
    <?php endif; ?>
    <form method="post" action="/admin/course-admin-scopes" class="row g-3" style="max-width: 36rem;">
        <input type="hidden" name="_csrf" value="<?= $e->attr($csrf) ?>">
        <div class="col-12">
            <label class="form-label" for="admin_email"><?= $e->html('Course Admin email') ?></label>
            <input class="form-control" id="admin_email" name="admin_email" type="email" required
                   value="<?= $e->attr($values['admin_email']) ?>">
        </div>
        <div class="col-12">
            <label class="form-label" for="course_id"><?= $e->html('Course ID') ?></label>
            <input class="form-control" id="course_id" name="course_id" type="number" min="1" required
                   value="<?= $e->attr($values['course_id']) ?>">
        </div>
        <div class="col-12 form-check">
            <input class="form-check-input" type="checkbox" id="include_future_versions" name="include_future_versions" value="1"
                <?= $values['include_future_versions'] === '1' ? 'checked' : '' ?>>
            <label class="form-check-label" for="include_future_versions">
                <?= $e->html('Include future CourseVersions') ?>
            </label>
        </div>
        <div class="col-12">
            <button class="btn btn-primary" type="submit"><?= $e->html('Assign scope') ?></button>
        </div>
    </form>
</div>
<?php
$content = ob_get_clean();
require dirname(__DIR__, 3) . '/layouts/base.php';
