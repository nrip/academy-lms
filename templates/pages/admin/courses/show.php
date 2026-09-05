<?php

declare(strict_types=1);

/** @var \Academy\Infrastructure\View\Escaper $e */
/** @var string $title */
/** @var string $csrf */
/** @var \Academy\Application\Courses\CourseAdminCourseDetail $detail */
/** @var ?string $flash */

ob_start();
$course = $detail->course;
?>
<div class="acad-admin-course-show">
    <p class="mb-2"><a href="/admin/courses"><?= $e->html('← Course administration') ?></a></p>
    <h1 class="h3 mb-1"><?= $e->html($course->masterTitle) ?></h1>
    <p class="text-muted"><?= $e->html($course->courseCode) ?> · <?= $e->html($course->slug) ?></p>
    <p class="mb-3">
        <a class="btn btn-outline-primary btn-sm"
           href="/admin/courses/<?= $e->attr((string) $course->courseId) ?>/question-bank">
            <?= $e->html('Open question bank') ?>
        </a>
    </p>
    <?php if ($flash !== null): ?>
        <div class="alert alert-success"><?= $e->html($flash) ?></div>
    <?php endif; ?>
    <h2 class="h5 mt-4"><?= $e->html('Versions') ?></h2>
    <div class="table-responsive">
        <table class="table table-sm">
            <thead>
            <tr>
                <th><?= $e->html('Version') ?></th>
                <th><?= $e->html('Title') ?></th>
                <th><?= $e->html('Status') ?></th>
                <th><?= $e->html('Locked') ?></th>
                <th></th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($detail->versions as $version): ?>
                <tr>
                    <td><?= $e->html((string) $version->versionNumber) ?></td>
                    <td><?= $e->html($version->title) ?></td>
                    <td><?= $e->html($version->status) ?></td>
                    <td><?= $e->html($version->isLocked() ? 'yes' : 'no') ?></td>
                    <td class="text-end">
                        <a href="/admin/courses/<?= $e->attr((string) $course->courseId) ?>/versions/<?= $e->attr((string) $version->versionId) ?>">
                            <?= $e->html($version->isLocked() ? 'View' : 'Edit draft') ?>
                        </a>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php
$content = ob_get_clean();
require dirname(__DIR__, 3) . '/layouts/base.php';
