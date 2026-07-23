<?php

declare(strict_types=1);

/** @var \Academy\Infrastructure\View\Escaper $e */
/** @var string $title */
/** @var string $csrf */
/** @var \Academy\Domain\Notifications\NotificationDelivery $delivery */
/** @var string|null $error */

ob_start();
?>
<div class="acad-admin-notification-detail">
    <p><a href="/admin/notifications"><?= $e->html('← Back to list') ?></a></p>
    <h1 class="h3 mb-3"><?= $e->html('Delivery #' . $delivery->notificationDeliveryId) ?></h1>
    <?php if ($error !== null): ?>
        <div class="alert alert-warning"><?= $e->html($error) ?></div>
    <?php endif; ?>
    <dl class="row">
        <dt class="col-sm-3"><?= $e->html('Event') ?></dt>
        <dd class="col-sm-9"><?= $e->html($delivery->sourceEventType) ?></dd>
        <dt class="col-sm-3"><?= $e->html('Template') ?></dt>
        <dd class="col-sm-9"><?= $e->html($delivery->templateKey . ' v' . $delivery->templateVersion) ?></dd>
        <dt class="col-sm-3"><?= $e->html('Channel') ?></dt>
        <dd class="col-sm-9"><?= $e->html($delivery->channel) ?></dd>
        <dt class="col-sm-3"><?= $e->html('Recipient') ?></dt>
        <dd class="col-sm-9"><?= $e->html($delivery->recipientMasked) ?></dd>
        <dt class="col-sm-3"><?= $e->html('Status') ?></dt>
        <dd class="col-sm-9 text-uppercase"><?= $e->html($delivery->status) ?></dd>
        <dt class="col-sm-3"><?= $e->html('Attempts') ?></dt>
        <dd class="col-sm-9"><?= $e->html((string) $delivery->attemptCount) ?></dd>
        <dt class="col-sm-3"><?= $e->html('Failure category') ?></dt>
        <dd class="col-sm-9"><?= $e->html($delivery->failureCategory ?? '—') ?></dd>
        <dt class="col-sm-3"><?= $e->html('Provider message ID') ?></dt>
        <dd class="col-sm-9"><?= $e->html($delivery->providerMessageId ?? '—') ?></dd>
        <dt class="col-sm-3"><?= $e->html('Outbox message ID') ?></dt>
        <dd class="col-sm-9"><?= $e->html((string) $delivery->outboxMessageId) ?></dd>
    </dl>
    <?php if (in_array($delivery->status, ['failed', 'dead'], true)): ?>
        <form method="post" action="/admin/notifications/<?= $e->attr($delivery->notificationDeliveryId) ?>/retry">
            <input type="hidden" name="_csrf" value="<?= $e->attr($csrf) ?>">
            <button type="submit" class="btn btn-primary"><?= $e->html('Retry delivery') ?></button>
        </form>
    <?php endif; ?>
</div>
<?php
$content = ob_get_clean();
require dirname(__DIR__, 3) . '/layouts/base.php';
