<?php

declare(strict_types=1);

/** @var \Academy\Infrastructure\View\Escaper $e */
/** @var string $title */
/** @var string $csrf */
/** @var \Academy\Application\Learning\FacultyQuestionDetailView $detail */
/** @var ?string $flash */
/** @var ?string $error */

$india = new DateTimeZone('Asia/Kolkata');

ob_start();
?>
<div class="acad-faculty-question">
    <p class="mb-2"><a href="/faculty"><?= $e->html('← Faculty') ?></a></p>
    <p class="acad-eyebrow mb-1"><?= $e->html('Question') ?></p>
    <h1 class="h3 mb-1"><?= $e->html($detail->lessonTitle) ?></h1>
    <p class="text-muted mb-3">
        <?= $e->html($detail->courseTitle . ' · ' . $detail->chapterTitle) ?>
        · <?= $e->html($detail->learnerName) ?>
        · <?= $e->html($detail->askedAt->setTimezone($india)->format('j M Y, g:i a') . ' IST') ?>
    </p>
    <p class="mb-3"><span class="badge text-bg-light border"><?= $e->html($detail->statusLabel) ?></span></p>

    <?php if ($flash !== null): ?>
        <div class="alert alert-success"><?= $e->html($flash) ?></div>
    <?php endif; ?>
    <?php if ($error !== null): ?>
        <div class="alert alert-danger"><?= $e->html($error) ?></div>
    <?php endif; ?>

    <section class="acad-panel mb-4">
        <h2 class="h6"><?= $e->html('Learner question') ?></h2>
        <p class="mb-0"><?= nl2br($e->html($detail->body), false) ?></p>
    </section>

    <section class="mb-4" aria-labelledby="responses-heading">
        <h2 id="responses-heading" class="h5"><?= $e->html('Responses') ?></h2>
        <?php if ($detail->responses === []): ?>
            <p class="text-muted"><?= $e->html('No response yet.') ?></p>
        <?php else: ?>
            <?php foreach ($detail->responses as $response): ?>
                <div class="border rounded p-3 mb-3">
                    <div class="small text-muted mb-1">
                        <?= $e->html($response->responderName) ?>
                        · <?= $e->html($response->respondedAt->setTimezone($india)->format('j M Y, g:i a') . ' IST') ?>
                    </div>
                    <p class="mb-0"><?= nl2br($e->html($response->body), false) ?></p>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </section>

    <?php if ($detail->canRespond): ?>
        <form method="post" action="/faculty/questions/<?= $e->attr((string) $detail->questionId) ?>/responses" class="mb-3">
            <input type="hidden" name="_csrf" value="<?= $e->attr($csrf) ?>">
            <label class="form-label" for="response-body"><?= $e->html('Response') ?></label>
            <textarea class="form-control mb-2" id="response-body" name="body" rows="5" maxlength="2000" required></textarea>
            <button type="submit" class="btn btn-primary"><?= $e->html('Post response') ?></button>
        </form>
    <?php endif; ?>

    <?php if ($detail->canClose): ?>
        <form method="post" action="/faculty/questions/<?= $e->attr((string) $detail->questionId) ?>/close">
            <input type="hidden" name="_csrf" value="<?= $e->attr($csrf) ?>">
            <button type="submit" class="btn btn-outline-secondary"><?= $e->html('Close question') ?></button>
        </form>
    <?php endif; ?>
</div>
<?php
$content = ob_get_clean();
require dirname(__DIR__, 3) . '/layouts/base.php';
