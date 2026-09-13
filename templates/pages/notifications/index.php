<?php

declare(strict_types=1);

/** @var \Academy\Infrastructure\View\Escaper $e */
/** @var string $title */
/** @var string $csrf */
/** @var list<\Academy\Domain\Notifications\InAppNotification> $items */
/** @var int $unread */

$india = new DateTimeZone('Asia/Kolkata');

ob_start();
?>
<div class="acad-inbox">
    <p class="mb-2"><a href="/dashboard"><?= $e->html('← My learning') ?></a></p>
    <p class="acad-eyebrow mb-2"><?= $e->html('Learner') ?></p>
    <h1 class="h3 mb-2"><?= $e->html('Updates') ?></h1>
    <p class="text-muted mb-4">
        <?= $e->html($unread === 0 ? 'No unread updates.' : ($unread === 1 ? '1 unread update.' : $unread . ' unread updates.')) ?>
    </p>

    <?php if ($items === []): ?>
        <p class="text-muted mb-0"><?= $e->html('Messages from the academy appear here after they are sent. There is nothing yet.') ?></p>
    <?php else: ?>
        <ul class="list-unstyled mb-0">
            <?php foreach ($items as $item): ?>
                <li class="acad-inbox__item<?= $item->isRead() ? '' : ' acad-inbox__item--unread' ?>">
                    <div class="d-flex justify-content-between gap-3 flex-wrap">
                        <h2 class="h6 mb-1"><?= $e->html($item->title) ?></h2>
                        <time class="small text-muted" datetime="<?= $e->attr($item->createdAt->format(DateTimeInterface::ATOM)) ?>">
                            <?= $e->html($item->createdAt->setTimezone($india)->format('j M Y, g:i a') . ' IST') ?>
                        </time>
                    </div>
                    <p class="acad-inbox__body mb-3"><?= $e->html($item->body) ?></p>
                    <div class="d-flex flex-wrap gap-2">
                        <a class="btn btn-sm btn-outline-primary" href="<?= $e->attr($item->href) ?>"><?= $e->html('Open') ?></a>
                        <?php if (!$item->isRead()): ?>
                            <form method="post" action="/notifications/<?= $e->attr((string) $item->id) ?>/read" class="d-inline">
                                <input type="hidden" name="_csrf" value="<?= $e->attr($csrf) ?>">
                                <button type="submit" class="btn btn-sm btn-link"><?= $e->html('Mark read') ?></button>
                            </form>
                        <?php endif; ?>
                    </div>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
</div>
<?php
$content = ob_get_clean();
require dirname(__DIR__, 2) . '/layouts/base.php';
