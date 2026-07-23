<?php

declare(strict_types=1);

/** @var \Academy\Infrastructure\View\Escaper $e */
/** @var string $title */
/** @var string $csrf */
/** @var array{items: list<\Academy\Domain\Notifications\NotificationDelivery>, total: int, status: ?string} $page */

ob_start();
?>
<div class="acad-admin-notifications">
    <h1 class="h3 mb-3"><?= $e->html('Notification deliveries') ?></h1>
    <form method="get" class="row g-2 mb-3">
        <div class="col-auto">
            <label class="form-label" for="status"><?= $e->html('Status') ?></label>
            <select class="form-select" id="status" name="status">
                <option value=""><?= $e->html('All') ?></option>
                <?php foreach (['pending', 'processing', 'delivered', 'failed', 'dead'] as $status): ?>
                    <option value="<?= $e->attr($status) ?>" <?= ($page['status'] ?? null) === $status ? 'selected' : '' ?>>
                        <?= $e->html($status) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-auto align-self-end">
            <button class="btn btn-outline-primary" type="submit"><?= $e->html('Filter') ?></button>
        </div>
    </form>
    <p class="text-muted"><?= $e->html('Total: ' . (string) $page['total']) ?></p>
    <div class="table-responsive">
        <table class="table table-sm">
            <thead>
            <tr>
                <th><?= $e->html('ID') ?></th>
                <th><?= $e->html('Event') ?></th>
                <th><?= $e->html('Template') ?></th>
                <th><?= $e->html('Recipient') ?></th>
                <th><?= $e->html('Status') ?></th>
                <th><?= $e->html('Attempts') ?></th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($page['items'] as $item): ?>
                <tr>
                    <td>
                        <a href="/admin/notifications/<?= $e->attr($item->notificationDeliveryId) ?>">
                            <?= $e->html((string) $item->notificationDeliveryId) ?>
                        </a>
                    </td>
                    <td><?= $e->html($item->sourceEventType) ?></td>
                    <td><?= $e->html($item->templateKey . ' v' . $item->templateVersion) ?></td>
                    <td><?= $e->html($item->recipientMasked) ?></td>
                    <td class="text-uppercase"><?= $e->html($item->status) ?></td>
                    <td><?= $e->html((string) $item->attemptCount) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php
$content = ob_get_clean();
require dirname(__DIR__, 3) . '/layouts/base.php';
