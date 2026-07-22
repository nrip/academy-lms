<?php

declare(strict_types=1);

/** @var \Academy\Infrastructure\View\Escaper $e */
/** @var string $title */
/** @var string $csrf */
/** @var list<\Academy\Domain\Identity\LearnerQualification> $qualifications */
/** @var int $maxQualifications */
/** @var array<string, list<string>> $errors */
/** @var array<string, string> $addValues */
/** @var string|null $conflict */
/** @var bool $saved */

$addValue = static function (string $name) use ($addValues): string {
    return $addValues[$name] ?? '';
};
$fieldErrors = static function (string $name) use ($errors, $e): string {
    if (!isset($errors[$name])) {
        return '';
    }
    $out = '<div class="invalid-feedback d-block">';
    foreach ($errors[$name] as $message) {
        $out .= '<div>' . $e->html($message) . '</div>';
    }

    return $out . '</div>';
};
$addFields = [
    'qualification_type' => 'Qualification type',
    'qualification_name' => 'Qualification name',
    'institution_name' => 'Institution name',
    'university_or_board' => 'University or board',
    'country' => 'Country',
    'registration_or_certificate_number' => 'Registration / certificate number',
];
$atLimit = count($qualifications) >= $maxQualifications;

ob_start();
?>
<div class="acad-profile-qualifications">
    <p class="acad-eyebrow mb-2"><a href="/profile"><?= $e->html('My profile') ?></a></p>
    <h1 class="h3 mb-4"><?= $e->html($title) ?></h1>

    <?php if ($saved): ?>
        <div class="alert alert-success" role="status"><?= $e->html('Your qualifications were updated.') ?></div>
    <?php endif; ?>
    <?php if ($conflict !== null): ?>
        <div class="alert alert-warning" role="alert"><?= $e->html($conflict) ?></div>
    <?php endif; ?>
    <?php if ($errors !== []): ?>
        <div class="alert alert-danger" role="alert"><?= $e->html('Please correct the highlighted fields.') ?></div>
    <?php endif; ?>

    <h2 class="h5 mb-3"><?= $e->html('Existing qualifications') ?></h2>
    <?php if ($qualifications === []): ?>
        <p class="text-secondary"><?= $e->html('No qualifications added yet.') ?></p>
    <?php else: ?>
        <?php foreach ($qualifications as $qualification): ?>
            <div class="card mb-3">
                <div class="card-body">
                    <form method="post" action="/profile/qualifications/<?= $e->attr($qualification->learnerQualificationId) ?>/update" class="mb-2" novalidate>
                        <input type="hidden" name="_csrf" value="<?= $e->attr($csrf) ?>">
                        <input type="hidden" name="row_version" value="<?= $e->attr($qualification->rowVersion) ?>">
                        <div class="row g-2">
                            <div class="col-md-4">
                                <label class="form-label"><?= $e->html('Qualification type') ?></label>
                                <input class="form-control" type="text" name="qualification_type" value="<?= $e->attr($qualification->qualificationType) ?>">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label"><?= $e->html('Qualification name') ?></label>
                                <input class="form-control" type="text" name="qualification_name" value="<?= $e->attr($qualification->qualificationName) ?>">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label"><?= $e->html('Institution name') ?></label>
                                <input class="form-control" type="text" name="institution_name" value="<?= $e->attr($qualification->institutionName) ?>">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label"><?= $e->html('University or board') ?></label>
                                <input class="form-control" type="text" name="university_or_board" value="<?= $e->attr((string) $qualification->universityOrBoard) ?>">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label"><?= $e->html('Country') ?></label>
                                <input class="form-control" type="text" name="country" value="<?= $e->attr((string) $qualification->country) ?>">
                            </div>
                            <div class="col-md-2">
                                <label class="form-label"><?= $e->html('Completion year') ?></label>
                                <input class="form-control" type="number" name="completion_year" value="<?= $e->attr($qualification->completionYear) ?>">
                            </div>
                            <div class="col-md-2">
                                <label class="form-label"><?= $e->html('Reg. / cert. no.') ?></label>
                                <input class="form-control" type="text" name="registration_or_certificate_number" value="<?= $e->attr((string) $qualification->registrationOrCertificateNumber) ?>">
                            </div>
                        </div>
                        <button class="btn btn-outline-primary btn-sm mt-2" type="submit"><?= $e->html('Save changes') ?></button>
                    </form>
                    <form method="post" action="/profile/qualifications/<?= $e->attr($qualification->learnerQualificationId) ?>/delete">
                        <input type="hidden" name="_csrf" value="<?= $e->attr($csrf) ?>">
                        <input type="hidden" name="row_version" value="<?= $e->attr($qualification->rowVersion) ?>">
                        <button class="btn btn-outline-danger btn-sm" type="submit"><?= $e->html('Remove') ?></button>
                    </form>
                </div>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>

    <hr class="my-4">

    <h2 class="h5 mb-3"><?= $e->html('Add a qualification') ?></h2>
    <?php if ($atLimit): ?>
        <p class="text-secondary"><?= $e->html(sprintf('You have reached the maximum of %d qualifications.', $maxQualifications)) ?></p>
    <?php else: ?>
        <form method="post" action="/profile/qualifications" novalidate>
            <input type="hidden" name="_csrf" value="<?= $e->attr($csrf) ?>">
            <?php foreach ($addFields as $name => $label): ?>
                <div class="mb-3">
                    <label class="form-label" for="add_<?= $e->attr($name) ?>"><?= $e->html($label) ?></label>
                    <input class="form-control" type="text" id="add_<?= $e->attr($name) ?>" name="<?= $e->attr($name) ?>" value="<?= $e->attr($addValue($name)) ?>">
                    <?= $fieldErrors($name) ?>
                </div>
            <?php endforeach; ?>
            <div class="mb-3">
                <label class="form-label" for="add_completion_year"><?= $e->html('Completion year') ?></label>
                <input class="form-control" type="number" id="add_completion_year" name="completion_year" value="<?= $e->attr($addValue('completion_year')) ?>">
                <?= $fieldErrors('completion_year') ?>
            </div>
            <button class="btn btn-primary" type="submit"><?= $e->html('Add qualification') ?></button>
        </form>
    <?php endif; ?>
</div>
<?php
$content = (string) ob_get_clean();
require dirname(__DIR__, 2) . '/layouts/base.php';
