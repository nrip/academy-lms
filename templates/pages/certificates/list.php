<?php

declare(strict_types=1);

/** @var \Academy\Infrastructure\View\Escaper $e */
/** @var string $title */
/** @var string $csrf */
/** @var \Academy\Application\Certificates\CertificateListView $view */

$india = new DateTimeZone('Asia/Kolkata');

ob_start();
?>
<div class="acad-certificates">
    <p class="mb-2">
        <a href="/learning/enrolments/<?= $e->attr((string) $view->enrolmentId) ?>"><?= $e->html('← Course outline') ?></a>
    </p>
    <h1 class="h3 mb-2"><?= $e->html('Certificates') ?></h1>
    <p class="text-muted mb-4"><?= $e->html('Recognition for completing this course.') ?></p>

    <?php if ($view->message !== null): ?>
        <div class="alert alert-info"><?= $e->html($view->message) ?></div>
    <?php endif; ?>

    <?php if ($view->incompleteTitles !== []): ?>
        <div class="alert alert-secondary">
            <p class="mb-1"><?= $e->html('Still required before a certificate can be issued:') ?></p>
            <ul class="mb-0">
                <?php foreach ($view->incompleteTitles as $titleItem): ?>
                    <li><?= $e->html($titleItem) ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <?php if ($view->certificates === []): ?>
        <div class="acad-panel">
            <p class="mb-2 fw-semibold"><?= $e->html('No certificate yet') ?></p>
            <p class="text-muted mb-0">
                <?= $e->html('When you complete the required lessons (and any assessments), your certificate will appear here.') ?>
            </p>
        </div>
    <?php else: ?>
        <div class="row g-3">
            <?php foreach ($view->certificates as $certificate): ?>
                <?php $issued = $certificate->issuedAt->setTimezone($india)->format('j M Y'); ?>
                <div class="col-md-6">
                    <article class="acad-certificate-achievement h-100">
                        <p class="acad-eyebrow mb-2"><?= $e->html('Achievement') ?></p>
                        <h2 class="h5 mb-1"><?= $e->html($certificate->certificateLabel) ?></h2>
                        <p class="text-muted small mb-3">
                            <?= $e->html('Issued ' . $issued . ' · No. ' . $certificate->certificateNumber) ?>
                        </p>
                        <p class="small mb-3"><?= $e->html(ucfirst($certificate->status)) ?></p>
                        <div class="d-flex flex-wrap gap-2">
                            <a class="btn btn-sm btn-primary"
                               href="/certificates/<?= $e->attr((string) $certificate->certificateId) ?>">
                                <?= $e->html('View certificate') ?>
                            </a>
                            <a class="btn btn-sm btn-outline-secondary"
                               href="/certificates/<?= $e->attr((string) $certificate->certificateId) ?>/pdf">
                                <?= $e->html('Download PDF') ?>
                            </a>
                        </div>
                    </article>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>
<?php
$content = ob_get_clean();
require dirname(__DIR__, 2) . '/layouts/base.php';
