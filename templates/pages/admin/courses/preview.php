<?php

declare(strict_types=1);

use Academy\Domain\Courses\LessonKind;

/** @var \Academy\Infrastructure\View\Escaper $e */
/** @var string $title */
/** @var string $csrf */
/** @var \Academy\Domain\Courses\Course $course */
/** @var \Academy\Domain\Courses\CourseVersion $version */
/** @var list<array{module: \Academy\Domain\Courses\Module, content_items: list<\Academy\Domain\Courses\ContentItem>}> $modules */

$base = '/admin/courses/' . $course->courseId . '/versions/' . $version->versionId;

ob_start();
?>
<div class="acad-admin-preview">
    <p class="mb-2">
        <a href="<?= $e->attr($base) ?>"><?= $e->html('← Back to edition') ?></a>
        · <a href="<?= $e->attr($base . '/curriculum') ?>"><?= $e->html('Edit chapters') ?></a>
    </p>
    <div class="alert alert-info mb-3">
        <?= $e->html('Preview as learner — read-only outline. No enrolment is created and progress is not written.') ?>
    </div>
    <h1 class="h3 mb-1"><?= $e->html($course->masterTitle) ?></h1>
    <p class="text-muted mb-4">
        <?= $e->html('Edition ' . (string) $version->versionNumber . ' · ' . $version->title) ?>
    </p>

    <?php if ($modules === []): ?>
        <div class="acad-empty">
            <p class="fw-semibold mb-1"><?= $e->html('No chapters yet') ?></p>
            <p class="text-muted mb-3"><?= $e->html('Add chapters and lessons before previewing the learner journey.') ?></p>
            <a class="btn btn-primary" href="<?= $e->attr($base . '/curriculum') ?>"><?= $e->html('Create your first chapter') ?></a>
        </div>
    <?php else: ?>
        <?php
        $chapterNumber = 0;
        foreach ($modules as $node):
            ++$chapterNumber;
            $module = $node['module'];
            ?>
            <section class="mb-4">
                <p class="small text-muted mb-1"><?= $e->html('Chapter ' . (string) $chapterNumber) ?></p>
                <h2 class="h5"><?= $e->html($module->title) ?></h2>
                <?php if ($module->description !== ''): ?>
                    <p class="text-muted small"><?= $e->html($module->description) ?></p>
                <?php endif; ?>
                <?php if ($node['content_items'] === []): ?>
                    <p class="text-muted small"><?= $e->html('No lessons in this chapter yet.') ?></p>
                <?php else: ?>
                    <ul class="list-group">
                        <?php foreach ($node['content_items'] as $item): ?>
                            <li class="list-group-item d-flex justify-content-between align-items-center">
                                <span><?= $e->html($item->title) ?></span>
                                <span class="badge text-bg-light border"><?= $e->html(LessonKind::labelForItem($item)) ?></span>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </section>
        <?php endforeach; ?>
    <?php endif; ?>
</div>
<?php
$content = ob_get_clean();
require dirname(__DIR__, 3) . '/layouts/base.php';
