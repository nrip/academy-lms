<?php

declare(strict_types=1);

use Academy\Domain\Review\ReviewerQueueFilter;

/** @var \Academy\Infrastructure\View\Escaper $e */
/** @var string $title */
/** @var string $csrf */
/** @var \Academy\Application\Review\ReviewerQueuePage $page */
/** @var list<string> $filters */
/** @var int|null $authUserId */

/** @var array<string, string> $filterLabels */
$filterLabels = [
    ReviewerQueueFilter::UNASSIGNED => 'Unassigned',
    ReviewerQueueFilter::ASSIGNED_TO_ME => 'Assigned to me',
    ReviewerQueueFilter::UNDER_REVIEW => 'Under review',
    ReviewerQueueFilter::RESUBMISSION_REQUESTED => 'Resubmission requested',
    ReviewerQueueFilter::READY_FOR_DECISION => 'Ready for decision',
    ReviewerQueueFilter::RECENTLY_DECIDED => 'Recently decided',
];

ob_start();
?>
<div class="acad-reviewer-queue">
    <p class="acad-eyebrow mb-2"><?= $e->html('Reviewer verification') ?></p>
    <h1 class="h3 mb-4"><?= $e->html('Application queue') ?></h1>

    <div class="mb-3 d-flex flex-wrap gap-2 align-items-center">
        <span class="text-muted small"><?= $e->html('Filter:') ?></span>
        <?php foreach ($filters as $filterKey): ?>
            <a
                class="btn btn-sm <?= $page->filter === $filterKey ? 'btn-primary' : 'btn-outline-secondary' ?>"
                href="/reviewer/applications?filter=<?= $e->attr($filterKey) ?>"
            ><?= $e->html($filterLabels[$filterKey] ?? $filterKey) ?></a>
        <?php endforeach; ?>
    </div>

    <?php if ($page->items === []): ?>
        <p class="text-muted"><?= $e->html('No applications match this filter.') ?></p>
    <?php else: ?>
        <div class="table-responsive">
            <table class="table table-sm align-middle">
                <thead>
                    <tr>
                        <th><?= $e->html('Application') ?></th>
                        <th><?= $e->html('Course') ?></th>
                        <th><?= $e->html('Batch') ?></th>
                        <th><?= $e->html('Submitted') ?></th>
                        <th><?= $e->html('Status') ?></th>
                        <th><?= $e->html('Assignment') ?></th>
                        <th><?= $e->html('SLA') ?></th>
                        <th><?= $e->html('Documents') ?></th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($page->items as $item): ?>
                        <tr>
                            <td>
                                <a href="/reviewer/applications/<?= $e->attr($item->applicationId) ?>">
                                    <?= $e->html($item->applicationNumber) ?>
                                </a>
                            </td>
                            <td><?= $e->html($item->courseTitle) ?></td>
                            <td><?= $e->html($item->batchLabel) ?></td>
                            <td>
                                <?= $item->submittedAt !== null
                                    ? $e->html($item->submittedAt->format('d M Y H:i'))
                                    : $e->html('—') ?>
                            </td>
                            <td><span class="badge bg-secondary text-uppercase"><?= $e->html($item->status) ?></span></td>
                            <td>
                                <?php if ($item->assignedReviewerUserId === null): ?>
                                    <?= $e->html('Unassigned') ?>
                                <?php elseif ($authUserId !== null && $item->assignedReviewerUserId === $authUserId): ?>
                                    <?= $e->html('Assigned to you') ?>
                                <?php else: ?>
                                    <?= $e->html('Assigned (#') ?><?= $e->html((string) $item->assignedReviewerUserId) ?><?= $e->html(')') ?>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($item->slaAgeBand !== null): ?>
                                    <span class="badge bg-light text-dark"><?= $e->html($item->slaAgeBand) ?></span>
                                <?php else: ?>
                                    <?= $e->html('—') ?>
                                <?php endif; ?>
                            </td>
                            <td class="small"><?= $e->html($item->documentCompletenessSummary) ?></td>
                            <td class="text-end">
                                <?php if ($item->assignedReviewerUserId === null): ?>
                                    <form method="post" action="/reviewer/applications/<?= $e->attr($item->applicationId) ?>/claim" class="d-inline">
                                        <input type="hidden" name="_csrf" value="<?= $e->attr($csrf) ?>">
                                        <button type="submit" class="btn btn-sm btn-outline-primary"><?= $e->html('Claim') ?></button>
                                    </form>
                                <?php endif; ?>
                                <a class="btn btn-sm btn-link" href="/reviewer/applications/<?= $e->attr($item->applicationId) ?>"><?= $e->html('Open') ?></a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <nav class="d-flex justify-content-between align-items-center mt-3" aria-label="Queue pagination">
            <?php if ($page->page > 1): ?>
                <a href="/reviewer/applications?filter=<?= $e->attr($page->filter) ?>&amp;page=<?= $e->attr((string) ($page->page - 1)) ?>"><?= $e->html('Previous') ?></a>
            <?php else: ?>
                <span></span>
            <?php endif; ?>
            <span class="text-muted small"><?= $e->html('Page ' . $page->page) ?></span>
            <?php if (count($page->items) >= $page->perPage): ?>
                <a href="/reviewer/applications?filter=<?= $e->attr($page->filter) ?>&amp;page=<?= $e->attr((string) ($page->page + 1)) ?>"><?= $e->html('Next') ?></a>
            <?php else: ?>
                <span></span>
            <?php endif; ?>
        </nav>
    <?php endif; ?>
</div>
<?php
$content = (string) ob_get_clean();
require dirname(__DIR__, 2) . '/layouts/base.php';
