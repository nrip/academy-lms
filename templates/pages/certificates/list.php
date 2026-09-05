<?php

declare(strict_types=1);

/** @var \Academy\Infrastructure\View\Escaper $e */
/** @var string $title */
/** @var string $csrf */
/** @var \Academy\Application\Certificates\CertificateListView $view */

ob_start();
?>
<div class="acad-certificates">
    <p class="mb-2">
        <a href="/learning/enrolments/<?= $e->attr((string) $view->enrolmentId) ?>"><?= $e->html('← Course outline') ?></a>
    </p>
    <h1 class="h3 mb-3"><?= $e->html('Certificates') ?></h1>

    <?php if ($view->message !== null): ?>
        <div class="alert alert-info"><?= $e->html($view->message) ?></div>
    <?php endif; ?>

    <?php if ($view->incompleteTitles !== []): ?>
        <div class="alert alert-secondary">
            <p class="mb-1"><?= $e->html('Still required:') ?></p>
            <ul class="mb-0">
                <?php foreach ($view->incompleteTitles as $titleItem): ?>
                    <li><?= $e->html($titleItem) ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <?php if ($view->certificates === []): ?>
        <p class="text-muted"><?= $e->html('No certificates issued yet.') ?></p>
    <?php else: ?>
        <ul class="list-group">
            <?php foreach ($view->certificates as $certificate): ?>
                <li class="list-group-item d-flex justify-content-between align-items-center">
                    <div>
                        <div class="fw-semibold"><?= $e->html($certificate->certificateLabel) ?></div>
                        <div class="small text-muted">
                            <?= $e->html($certificate->certificateNumber) ?>
                            · <?= $e->html(ucfirst($certificate->status)) ?>
                            · <?= $e->html($certificate->issuedAt->format('d M Y')) ?>
                        </div>
                    </div>
                    <a class="btn btn-sm btn-outline-primary"
                       href="/certificates/<?= $e->attr((string) $certificate->certificateId) ?>">
                        <?= $e->html('View') ?>
                    </a>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
</div>
<?php
$content = ob_get_clean();
require dirname(__DIR__, 2) . '/layouts/base.php';
