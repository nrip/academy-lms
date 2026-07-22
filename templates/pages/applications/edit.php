<?php

declare(strict_types=1);

/** @var \Academy\Infrastructure\View\Escaper $e */
/** @var string $title */
/** @var string $csrf */
/** @var \Academy\Application\Admissions\ApplicationWorkspaceView $view */
/** @var string|null $error */

$application = $view->application;

ob_start();
?>
<div class="acad-application-edit">
    <p class="acad-eyebrow mb-2"><?= $e->html('Edit application') ?></p>
    <h1 class="h3 mb-4"><?= $e->html($application->applicationNumber) ?></h1>

    <?php if ($error !== null): ?>
        <div class="alert alert-danger" role="alert"><?= $e->html($error) ?></div>
    <?php endif; ?>

    <div class="card">
        <div class="card-body">
            <h2 class="h5"><?= $e->html('Declaration') ?></h2>
            <p><?= $e->html('Declaration version: ' . $view->requiredDeclarationVersion) ?></p>

            <?php if ($view->declarationAccepted): ?>
                <p class="text-success mb-0"><?= $e->html('You have already accepted this declaration.') ?></p>
            <?php else: ?>
                <form method="post" action="/applications/<?= $e->attr($application->applicationId) ?>">
                    <input type="hidden" name="_csrf" value="<?= $e->attr($csrf) ?>">
                    <div class="form-check mb-3">
                        <input class="form-check-input" type="checkbox" id="declaration" required>
                        <label class="form-check-label" for="declaration">
                            <?= $e->html('I confirm the information I have provided is accurate and I accept the terms of this declaration.') ?>
                        </label>
                    </div>
                    <button type="submit" class="btn btn-primary"><?= $e->html('Accept declaration') ?></button>
                </form>
            <?php endif; ?>
        </div>
    </div>

    <p class="mt-3"><a href="/applications/<?= $e->attr($application->applicationId) ?>"><?= $e->html('Back to application') ?></a></p>
</div>
<?php
$content = (string) ob_get_clean();
require dirname(__DIR__, 2) . '/layouts/base.php';
