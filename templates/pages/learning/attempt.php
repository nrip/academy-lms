<?php

declare(strict_types=1);

/** @var \Academy\Infrastructure\View\Escaper $e */
/** @var string $title */
/** @var string $csrf */
/** @var \Academy\Application\Assessments\AssessmentAttemptView $view */
/** @var ?string $flash */
/** @var ?string $error */

$attempt = $view->attempt;
$outlineUrl = '/learning/enrolments/' . $attempt->enrolmentId;
$itemUrl = $outlineUrl . '/items/' . $attempt->contentId;

ob_start();
?>
<div class="acad-learner-attempt">
    <p class="mb-2">
        <a href="<?= $e->attr($itemUrl) ?>"><?= $e->html('← Assessment') ?></a>
        · <a href="<?= $e->attr($outlineUrl) ?>"><?= $e->html('Outline') ?></a>
    </p>
    <h1 class="h3 mb-1"><?= $e->html($view->assessment->title) ?></h1>
    <p class="text-muted mb-3">
        <?= $e->html('Attempt ' . (string) $attempt->attemptNumber) ?>
        · <?= $e->html(ucfirst(str_replace('_', ' ', $attempt->status))) ?>
        · <?= $e->html('Pass mark ' . $attempt->passThresholdPercent . '%') ?>
    </p>

    <?php if ($flash !== null): ?>
        <div class="alert alert-success"><?= $e->html($flash) ?></div>
    <?php endif; ?>
    <?php if ($error !== null): ?>
        <div class="alert alert-danger"><?= $e->html($error) ?></div>
    <?php endif; ?>

    <?php if ($view->showResults): ?>
        <div class="alert alert-<?= $e->attr($attempt->passedFlag ? 'success' : 'warning') ?>">
            <?= $e->html(
                'Score: ' . (string) $attempt->scorePercent . '% ('
                . (string) $attempt->marksAwarded . ' / ' . (string) $attempt->marksAvailable . '). '
                . ($attempt->passedFlag ? 'Passed.' : 'Not passed.'),
            ) ?>
        </div>
        <?php if ($view->completionMessage !== null): ?>
            <div class="alert alert-<?= $e->attr($attempt->passedFlag ? 'success' : 'secondary') ?>">
                <?= $e->html($view->completionMessage) ?>
            </div>
        <?php endif; ?>
    <?php endif; ?>

    <form method="post" action="/learning/attempts/<?= $e->attr((string) $attempt->attemptId) ?>/responses" id="attempt-form">
        <input type="hidden" name="_csrf" value="<?= $e->attr($csrf) ?>">
        <?php foreach ($view->questions as $qView): ?>
            <?php $q = $qView->question; ?>
            <fieldset class="mb-4">
                <legend class="h6">
                    <?= $e->html('Q' . (string) $q->sequence . '. ' . $q->stem) ?>
                    <span class="text-muted small"><?= $e->html('(' . $q->marks . ' marks)') ?></span>
                </legend>
                <?php foreach ($q->options as $option): ?>
                    <?php
                    $oid = (int) $option['option_id'];
                    $checked = $qView->selectedOptionId === $oid;
                    $revealCorrect = in_array($oid, $qView->revealCorrectOptionIds, true);
                    ?>
                    <div class="form-check">
                        <input class="form-check-input" type="radio"
                               name="answers[<?= $e->attr((string) $q->attemptQuestionId) ?>]"
                               id="opt-<?= $e->attr((string) $oid) ?>"
                               value="<?= $e->attr((string) $oid) ?>"
                               <?= $checked ? 'checked' : '' ?>
                               <?= $view->showResults ? 'disabled' : '' ?>>
                        <label class="form-check-label" for="opt-<?= $e->attr((string) $oid) ?>">
                            <?= $e->html((string) $option['option_text']) ?>
                            <?php if ($view->showResults && $revealCorrect): ?>
                                <span class="badge text-bg-success"><?= $e->html('Correct') ?></span>
                            <?php endif; ?>
                        </label>
                    </div>
                <?php endforeach; ?>
                <?php if ($view->showResults && $qView->isCorrect !== null): ?>
                    <p class="small mt-1 <?= $qView->isCorrect ? 'text-success' : 'text-danger' ?>">
                        <?= $e->html($qView->isCorrect ? 'Marked correct' : 'Marked incorrect') ?>
                        <?php if ($qView->marksAwarded !== null): ?>
                            <?= $e->html(' · ' . $qView->marksAwarded . ' marks') ?>
                        <?php endif; ?>
                    </p>
                <?php endif; ?>
            </fieldset>
        <?php endforeach; ?>

        <?php if (!$view->showResults): ?>
            <div class="d-flex flex-wrap gap-2">
                <button class="btn btn-outline-secondary" type="submit"><?= $e->html('Save answers') ?></button>
                <button class="btn btn-primary" type="submit"
                        formaction="/learning/attempts/<?= $e->attr((string) $attempt->attemptId) ?>/submit">
                    <?= $e->html('Submit attempt') ?>
                </button>
            </div>
        <?php else: ?>
            <div class="d-flex flex-wrap gap-2">
                <?php if ($view->certificateId !== null): ?>
                    <a class="btn btn-primary" href="/certificates/<?= $e->attr((string) $view->certificateId) ?>">
                        <?= $e->html('View certificate') ?>
                    </a>
                <?php endif; ?>
                <?php if ($view->certificatesListUrl !== null): ?>
                    <a class="btn btn-outline-primary" href="<?= $e->attr($view->certificatesListUrl) ?>">
                        <?= $e->html('Certificates') ?>
                    </a>
                <?php endif; ?>
                <a class="btn btn-outline-secondary" href="<?= $e->attr($outlineUrl) ?>"><?= $e->html('Back to outline') ?></a>
            </div>
        <?php endif; ?>
    </form>
</div>
<?php
$content = ob_get_clean();
require dirname(__DIR__, 2) . '/layouts/base.php';
