<?php

declare(strict_types=1);

/** @var \Academy\Infrastructure\View\Escaper $e */
/** @var string $title */
/** @var string $csrf */
/** @var \Academy\Application\Learning\LearnerPlayerOutlineView $outline */
/** @var ?string $flash */
/** @var ?string $error */

$base = '/learning/enrolments/' . $outline->enrolment->enrolmentId;

ob_start();
?>
<div class="acad-learner-outline">
    <p class="mb-2">
        <a href="/dashboard"><?= $e->html('← Dashboard') ?></a>
        · <a href="<?= $e->attr($base . '/certificates') ?>"><?= $e->html('Certificates') ?></a>
    </p>
    <h1 class="h3 mb-1"><?= $e->html($outline->courseTitle) ?></h1>
    <p class="text-muted mb-3">
        <?= $e->html($outline->versionTitle) ?>
        · <?= $e->html('Enrolment ' . $outline->enrolment->publicReference) ?>
        · <?= $e->html(ucfirst($outline->enrolment->lifecycleStatus)) ?>
    </p>

    <div class="mb-3">
        <div class="d-flex justify-content-between small mb-1">
            <span><?= $e->html('Progress') ?></span>
            <span><?= $e->html((string) $outline->completedCount . ' / ' . (string) $outline->totalCount . ' complete') ?></span>
        </div>
        <?php
        $pct = $outline->totalCount === 0
            ? 0
            : (int) floor(($outline->completedCount / $outline->totalCount) * 100);
        ?>
        <div class="progress" role="progressbar" aria-valuenow="<?= $e->attr((string) $pct) ?>" aria-valuemin="0" aria-valuemax="100">
            <div class="progress-bar" style="width: <?= $e->attr((string) $pct) ?>%"></div>
        </div>
    </div>

    <?php if ($flash !== null): ?>
        <div class="alert alert-success"><?= $e->html($flash) ?></div>
    <?php endif; ?>
    <?php if ($error !== null): ?>
        <div class="alert alert-danger"><?= $e->html($error) ?></div>
    <?php endif; ?>
    <?php if ($outline->accessMessage !== null): ?>
        <div class="alert alert-info"><?= $e->html($outline->accessMessage) ?></div>
    <?php endif; ?>

    <?php if ($outline->modules === []): ?>
        <p class="text-muted"><?= $e->html('No curriculum is available for this course version yet.') ?></p>
    <?php endif; ?>

    <?php foreach ($outline->modules as $moduleView): ?>
        <section class="mb-4">
            <h2 class="h5 mb-2">
                <?= $e->html($moduleView->module->title) ?>
                <?php if (!$moduleView->unlocked): ?>
                    <span class="badge text-bg-secondary"><?= $e->html('Locked') ?></span>
                <?php endif; ?>
            </h2>
            <?php if ($moduleView->module->description !== ''): ?>
                <p class="text-muted small"><?= $e->html($moduleView->module->description) ?></p>
            <?php endif; ?>
            <ul class="list-group">
                <?php foreach ($moduleView->items as $itemView): ?>
                    <li class="list-group-item d-flex justify-content-between align-items-center">
                        <div>
                            <?php if ($itemView->accessible): ?>
                                <a href="<?= $e->attr($base . '/items/' . (string) $itemView->item->contentId) ?>">
                                    <?= $e->html($itemView->item->title) ?>
                                </a>
                            <?php else: ?>
                                <span class="text-muted"><?= $e->html($itemView->item->title) ?></span>
                                <span class="badge text-bg-light border ms-1"><?= $e->html('Locked') ?></span>
                            <?php endif; ?>
                            <div class="small text-muted"><?= $e->html($itemView->item->contentType) ?></div>
                        </div>
                        <span class="badge text-bg-<?= $e->attr($itemView->completed ? 'success' : 'secondary') ?>">
                            <?= $e->html($itemView->completed ? 'Completed' : str_replace('_', ' ', $itemView->completionStatus)) ?>
                        </span>
                    </li>
                <?php endforeach; ?>
            </ul>
        </section>
    <?php endforeach; ?>
</div>
<?php
$content = ob_get_clean();
require dirname(__DIR__, 2) . '/layouts/base.php';
