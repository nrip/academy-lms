<?php

declare(strict_types=1);

/** @var \Academy\Infrastructure\View\Escaper $e */
/** @var string $title */
/** @var string $csrf */
/** @var list<\Academy\Domain\Courses\Course> $courses */
/** @var ?string $flash */

ob_start();
?>
<div class="acad-admin-courses">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h1 class="h3 mb-0"><?= $e->html('Course administration') ?></h1>
        <a class="btn btn-primary" href="/admin/courses/new"><?= $e->html('New course') ?></a>
    </div>
    <?php if ($flash !== null): ?>
        <div class="alert alert-success"><?= $e->html($flash) ?></div>
    <?php endif; ?>
    <p class="text-muted"><?= $e->html('Courses in your Course Admin scope. Curriculum, publish, and batches arrive in later work packages.') ?></p>
    <?php if ($courses === []): ?>
        <div class="alert alert-secondary"><?= $e->html('No courses in scope yet. Create a course to get started.') ?></div>
    <?php else: ?>
        <div class="table-responsive">
            <table class="table table-sm align-middle">
                <thead>
                <tr>
                    <th><?= $e->html('Code') ?></th>
                    <th><?= $e->html('Title') ?></th>
                    <th><?= $e->html('Status') ?></th>
                    <th></th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($courses as $course): ?>
                    <tr>
                        <td><?= $e->html($course->courseCode) ?></td>
                        <td><?= $e->html($course->masterTitle) ?></td>
                        <td><?= $e->html($course->status) ?></td>
                        <td class="text-end">
                            <a href="/admin/courses/<?= $e->attr((string) $course->courseId) ?>">
                                <?= $e->html('Open') ?>
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
