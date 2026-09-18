<?php

declare(strict_types=1);

/** @var \Academy\Infrastructure\View\Escaper $e */
/** @var string $title */
/** @var string $csrf */
/** @var \Academy\Application\Courses\CourseAdminCourseDetail $detail */
/** @var ?string $flash */
/** @var ?string $error */
/** @var bool $canUploadCover */
/** @var string $coverLimit */

ob_start();
$course = $detail->course;
?>
<div class="acad-admin-course-show">
    <p class="mb-2"><a href="/admin/courses"><?= $e->html('← Courses') ?></a></p>
    <h1 class="h3 mb-1"><?= $e->html($course->masterTitle) ?></h1>
    <p class="text-muted"><?= $e->html($course->courseCode) ?></p>
    <?php if ($error !== null): ?>
        <div class="alert alert-danger"><?= $e->html($error) ?></div>
    <?php endif; ?>
    <section class="acad-panel mb-4">
        <h2 class="h5"><?= $e->html('Course image') ?></h2>
        <?php if ($detail->course->hasCover()): ?>
            <img class="acad-cover-preview mb-3" src="<?= $e->attr('/admin/courses/' . (string) $course->courseId . '/cover') ?>" alt="">
        <?php else: ?>
            <p class="text-muted"><?= $e->html('No image yet. The public page shows a letter until you add one.') ?></p>
        <?php endif; ?>
        <?php if ($canUploadCover): ?>
            <form method="post" enctype="multipart/form-data"
                  action="<?= $e->attr('/admin/courses/' . (string) $course->courseId . '/cover') ?>">
                <input type="hidden" name="_csrf" value="<?= $e->attr($csrf) ?>">
                <label class="form-label" for="cover_image"><?= $e->html('JPG, PNG, or WebP') ?></label>
                <input class="form-control mb-2" id="cover_image" name="cover_image" type="file" accept="image/jpeg,image/png,image/webp" required>
                <p class="form-text"><?= $e->html('Maximum ' . $coverLimit . ' MB. Replacing the image does not change a published course edition.') ?></p>
                <button class="btn btn-primary btn-sm" type="submit"><?= $e->html('Save image') ?></button>
            </form>
        <?php endif; ?>
    </section>
    <p class="mb-3">
        <a class="btn btn-outline-primary btn-sm"
           href="/admin/courses/<?= $e->attr((string) $course->courseId) ?>/analytics">
            <?= $e->html('Course insights') ?>
        </a>
        <a class="btn btn-outline-primary btn-sm ms-1"
           href="/admin/courses/<?= $e->attr((string) $course->courseId) ?>/question-bank">
            <?= $e->html('Open question bank') ?>
        </a>
    </p>
    <?php if ($flash !== null): ?>
        <div class="alert alert-success"><?= $e->html($flash) ?></div>
    <?php endif; ?>
    <h2 class="h5 mt-4"><?= $e->html('Editions') ?></h2>
    <?php if ($detail->versions === []): ?>
        <div class="acad-empty">
            <p class="fw-semibold mb-1"><?= $e->html('No editions yet') ?></p>
            <p class="text-muted mb-0"><?= $e->html('A new course usually starts with Edition 1. Open it from the course list after creation.') ?></p>
        </div>
    <?php else: ?>
    <div class="table-responsive">
        <table class="table table-sm">
            <thead>
            <tr>
                <th><?= $e->html('Edition') ?></th>
                <th><?= $e->html('Title') ?></th>
                <th><?= $e->html('State') ?></th>
                <th></th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($detail->versions as $version): ?>
                <tr>
                    <td><?= $e->html((string) $version->versionNumber) ?></td>
                    <td><?= $e->html($version->title) ?></td>
                    <td><?= $e->html($version->isPublished() ? 'Published' : ($version->isLocked() ? 'Locked' : 'Draft')) ?></td>
                    <td class="text-end">
                        <a href="/admin/courses/<?= $e->attr((string) $course->courseId) ?>/versions/<?= $e->attr((string) $version->versionId) ?>">
                            <?= $e->html($version->isLocked() ? 'View' : 'Edit draft') ?>
                        </a>
                        <a class="ms-2" href="/admin/courses/<?= $e->attr((string) $course->courseId) ?>/versions/<?= $e->attr((string) $version->versionId) ?>/admission">
                            <?= $e->html('Eligibility') ?>
                        </a>
                        <a class="ms-2" href="/admin/courses/<?= $e->attr((string) $course->courseId) ?>/versions/<?= $e->attr((string) $version->versionId) ?>/preview">
                            <?= $e->html('Preview') ?>
                        </a>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>
<?php
$content = ob_get_clean();
require dirname(__DIR__, 3) . '/layouts/base.php';
