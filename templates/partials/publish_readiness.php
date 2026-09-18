<?php

declare(strict_types=1);

/**
 * @var \Academy\Infrastructure\View\Escaper $e
 * @var \Academy\Application\Courses\CoursePublishReadinessChecklist $readiness
 * @var bool $showPublishAction
 * @var string $publishAction
 * @var string $csrf
 * @var bool $locked
 */

$showPublishAction = $showPublishAction ?? false;
$locked = $locked ?? false;
?>
<section class="acad-panel mb-4" id="publish">
    <h2 class="h5"><?= $e->html('Review & publish') ?></h2>
    <p class="mb-3">
        <?= $e->html('Publishing locks this edition. After that, chapters, lessons, eligibility, and the fee cannot be changed. Create the next edition to revise a published course.') ?>
    </p>

    <h3 class="h6"><?= $e->html('Readiness checklist') ?></h3>
    <p class="small text-muted mb-2">
        <?= $e->html($readiness->doneCount() . ' of ' . $readiness->totalCount() . ' checks complete') ?>
        <?= $e->html($readiness->requiredComplete() ? ' · Required items ready' : ' · Finish required items before publishing') ?>
    </p>
    <ul class="list-unstyled acad-readiness mb-3">
        <?php foreach ($readiness->items as $item): ?>
            <li class="acad-readiness__item<?= $item['done'] ? ' acad-readiness__item--done' : '' ?>">
                <span class="acad-readiness__mark" aria-hidden="true"><?= $e->html($item['done'] ? '✓' : '○') ?></span>
                <div>
                    <div class="fw-semibold">
                        <?= $e->html($item['label']) ?>
                        <?php if ($item['required']): ?>
                            <span class="badge text-bg-light border"><?= $e->html('Required') ?></span>
                        <?php else: ?>
                            <span class="badge text-bg-light border"><?= $e->html('Recommended') ?></span>
                        <?php endif; ?>
                    </div>
                    <div class="small text-muted">
                        <?= $e->html($item['help']) ?>
                        <?php if (!$item['done'] && $item['href'] !== null): ?>
                            · <a href="<?= $e->attr($item['href']) ?>"><?= $e->html('Fix this') ?></a>
                        <?php endif; ?>
                    </div>
                </div>
            </li>
        <?php endforeach; ?>
    </ul>

    <?php if ($locked): ?>
        <p class="mb-0"><?= $e->html('This edition is already published or otherwise locked.') ?></p>
    <?php elseif ($showPublishAction): ?>
        <form method="post" action="<?= $e->attr($publishAction) ?>">
            <input type="hidden" name="_csrf" value="<?= $e->attr($csrf) ?>">
            <button class="btn btn-success" type="submit">
                <?= $e->html('Publish this edition') ?>
            </button>
            <?php if (!$readiness->requiredComplete()): ?>
                <div class="form-text text-warning">
                    <?= $e->html('Some recommended preparation is incomplete. Publishing still uses the existing server rules.') ?>
                </div>
            <?php endif; ?>
        </form>
    <?php endif; ?>
</section>
