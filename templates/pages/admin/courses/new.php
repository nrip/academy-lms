<?php

declare(strict_types=1);

/** @var \Academy\Infrastructure\View\Escaper $e */
/** @var string $title */
/** @var string $csrf */
/** @var array{course_code: string, slug: string, master_title: string} $values */
/** @var ?string $error */

ob_start();
?>
<div class="acad-admin-courses-new">
    <p class="mb-2"><a href="/admin/courses"><?= $e->html('← Course administration') ?></a></p>
    <h1 class="h3 mb-3"><?= $e->html('New course') ?></h1>
    <?php if ($error !== null): ?>
        <div class="alert alert-danger"><?= $e->html($error) ?></div>
    <?php endif; ?>
    <form method="post" action="/admin/courses" class="row g-3" style="max-width: 40rem;">
        <input type="hidden" name="_csrf" value="<?= $e->attr($csrf) ?>">
        <div class="col-12">
            <label class="form-label" for="course_code"><?= $e->html('Course code') ?></label>
            <input class="form-control" id="course_code" name="course_code" required maxlength="64"
                   value="<?= $e->attr($values['course_code']) ?>">
        </div>
        <div class="col-12">
            <label class="form-label" for="slug"><?= $e->html('Public slug') ?></label>
            <input class="form-control" id="slug" name="slug" required maxlength="128"
                   value="<?= $e->attr($values['slug']) ?>">
            <div class="form-text"><?= $e->html('Lowercase kebab-case, e.g. obesity-foundations-2027') ?></div>
        </div>
        <div class="col-12">
            <label class="form-label" for="master_title"><?= $e->html('Master title') ?></label>
            <input class="form-control" id="master_title" name="master_title" required maxlength="255"
                   value="<?= $e->attr($values['master_title']) ?>">
        </div>
        <div class="col-12">
            <button class="btn btn-primary" type="submit"><?= $e->html('Create draft course') ?></button>
        </div>
    </form>
</div>
<?php
$content = ob_get_clean();
require dirname(__DIR__, 3) . '/layouts/base.php';
