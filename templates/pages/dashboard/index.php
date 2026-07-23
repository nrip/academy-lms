<?php

declare(strict_types=1);

/** @var \Academy\Infrastructure\View\Escaper $e */
/** @var string $title */
/** @var string $csrf */
/** @var \Academy\Application\Dashboard\LearnerDashboardView $view */

$badge = static function (string $severity): string {
    return match ($severity) {
        'success' => 'success',
        'warning' => 'warning',
        'danger' => 'danger',
        'info' => 'info',
        default => 'secondary',
    };
};

ob_start();
?>
<div class="acad-dashboard">
    <p class="acad-eyebrow mb-2"><?= $e->html('Learner') ?></p>
    <h1 class="h3 mb-4"><?= $e->html('Dashboard') ?></h1>

    <?php if ($view->requiredActions !== []): ?>
        <section class="mb-4" aria-labelledby="required-actions-heading">
            <h2 id="required-actions-heading" class="h5"><?= $e->html('Required actions') ?></h2>
            <ul class="list-group">
                <?php foreach ($view->requiredActions as $action): ?>
                    <li class="list-group-item d-flex justify-content-between align-items-center">
                        <span><?= $e->html($action['label']) ?></span>
                        <a class="btn btn-sm btn-primary" href="<?= $e->attr($action['href']) ?>"><?= $e->html('Continue') ?></a>
                    </li>
                <?php endforeach; ?>
            </ul>
        </section>
    <?php endif; ?>

    <section class="mb-4" aria-labelledby="applications-heading">
        <h2 id="applications-heading" class="h5"><?= $e->html('Applications') ?></h2>
        <?php if ($view->cards === []): ?>
            <p class="text-muted"><?= $e->html('You have no applications yet.') ?>
                <a href="/courses"><?= $e->html('Browse courses') ?></a>
            </p>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-sm align-middle">
                    <thead>
                    <tr>
                        <th><?= $e->html('Application') ?></th>
                        <th><?= $e->html('Course / batch') ?></th>
                        <th><?= $e->html('Status') ?></th>
                        <th><?= $e->html('Next step') ?></th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($view->cards as $card): ?>
                        <tr>
                            <td>
                                <a href="/applications/<?= $e->attr($card->applicationId) ?>">
                                    <?= $e->html($card->applicationNumber) ?>
                                </a>
                                <?php if ($card->submittedAt !== null): ?>
                                    <div class="small text-muted"><?= $e->html('Submitted ' . $card->submittedAt) ?></div>
                                <?php endif; ?>
                            </td>
                            <td>
                                <div><?= $e->html($card->courseTitle) ?></div>
                                <div class="small text-muted"><?= $e->html($card->batchName) ?></div>
                            </td>
                            <td>
                                <span class="badge text-bg-<?= $e->attr($badge($card->applicationPresentation->severity)) ?>">
                                    <?= $e->html($card->applicationPresentation->label) ?>
                                </span>
                                <div class="small text-muted"><?= $e->html($card->applicationPresentation->explanation) ?></div>
                            </td>
                            <td>
                                <?php if ($card->primaryAction !== null): ?>
                                    <a href="<?= $e->attr($card->primaryAction['href']) ?>">
                                        <?= $e->html($card->primaryAction['label']) ?>
                                    </a>
                                <?php else: ?>
                                    <span class="text-muted"><?= $e->html('None') ?></span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </section>

    <section class="mb-4" aria-labelledby="payments-heading">
        <h2 id="payments-heading" class="h5"><?= $e->html('Payments') ?></h2>
        <?php
        $paymentCards = array_values(array_filter(
            $view->cards,
            static fn ($c) => $c->paymentId !== null,
        ));
        ?>
        <?php if ($paymentCards === []): ?>
            <p class="text-muted mb-0"><?= $e->html('No payment attempts yet.') ?></p>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-sm align-middle">
                    <thead>
                    <tr>
                        <th><?= $e->html('Application') ?></th>
                        <th><?= $e->html('Amount') ?></th>
                        <th><?= $e->html('Status') ?></th>
                        <th><?= $e->html('Action') ?></th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($paymentCards as $card): ?>
                        <tr>
                            <td><?= $e->html($card->applicationNumber) ?></td>
                            <td>
                                <?php if ($card->paymentAmountDisplay !== null && $card->paymentCurrency !== null): ?>
                                    <?= $e->html($card->paymentCurrency . ' ' . $card->paymentAmountDisplay) ?>
                                <?php else: ?>
                                    —
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($card->paymentPresentation !== null): ?>
                                    <span class="badge text-bg-<?= $e->attr($badge($card->paymentPresentation->severity)) ?>">
                                        <?= $e->html($card->paymentPresentation->label) ?>
                                    </span>
                                    <div class="small text-muted"><?= $e->html($card->paymentPresentation->explanation) ?></div>
                                <?php endif; ?>
                            </td>
                            <td>
                                <a href="/applications/<?= $e->attr($card->applicationId) ?>/payment-result">
                                    <?= $e->html('View status') ?>
                                </a>
                                <?php if ($card->paymentRetryAllowed): ?>
                                    · <a href="/applications/<?= $e->attr($card->applicationId) ?>/payment">
                                        <?= $e->html('Retry') ?>
                                    </a>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </section>

    <section aria-labelledby="enrolments-heading">
        <h2 id="enrolments-heading" class="h5"><?= $e->html('Enrolments') ?></h2>
        <?php
        $enrolmentCards = array_values(array_filter(
            $view->cards,
            static fn ($c) => $c->enrolmentId !== null,
        ));
        ?>
        <?php if ($enrolmentCards === []): ?>
            <p class="text-muted mb-0"><?= $e->html('No enrolments yet. Enrolment is created only after admission.') ?></p>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-sm align-middle">
                    <thead>
                    <tr>
                        <th><?= $e->html('Course') ?></th>
                        <th><?= $e->html('Version / batch') ?></th>
                        <th><?= $e->html('Status') ?></th>
                        <th><?= $e->html('Dates') ?></th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($enrolmentCards as $card): ?>
                        <tr>
                            <td>
                                <div><?= $e->html($card->courseTitle) ?></div>
                                <?php if ($card->enrolmentReference !== null): ?>
                                    <div class="small text-muted"><?= $e->html($card->enrolmentReference) ?></div>
                                <?php endif; ?>
                            </td>
                            <td>
                                <div><?= $e->html((string) $card->courseVersionLabel) ?></div>
                                <div class="small text-muted"><?= $e->html($card->batchName) ?></div>
                            </td>
                            <td>
                                <?php if ($card->enrolmentPresentation !== null): ?>
                                    <span class="badge text-bg-<?= $e->attr($badge($card->enrolmentPresentation->severity)) ?>">
                                        <?= $e->html($card->enrolmentPresentation->label) ?>
                                    </span>
                                    <div class="small text-muted"><?= $e->html($card->enrolmentPresentation->explanation) ?></div>
                                <?php endif; ?>
                            </td>
                            <td class="small">
                                <?php if ($card->enrolmentAdmittedAt !== null): ?>
                                    <div><?= $e->html('Admitted ' . $card->enrolmentAdmittedAt) ?></div>
                                <?php endif; ?>
                                <?php if ($card->batchStartsAt !== null): ?>
                                    <div><?= $e->html('Batch ' . $card->batchStartsAt) ?>
                                        <?php if ($card->batchEndsAt !== null): ?>
                                            <?= $e->html(' – ' . $card->batchEndsAt) ?>
                                        <?php endif; ?>
                                    </div>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </section>
</div>
<?php
$content = ob_get_clean();
require dirname(__DIR__, 2) . '/layouts/base.php';
