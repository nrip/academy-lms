<?php

declare(strict_types=1);

/** @var \Academy\Infrastructure\View\Escaper $e */
/** @var string $title */
/** @var string $csrf */
/** @var array<string, string> $values */
/** @var int $rowVersion */
/** @var array<string, list<string>> $errors */
/** @var string|null $conflict */
/** @var bool $saved */

$field = static function (string $name) use ($values): string {
    return $values[$name] ?? '';
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
$textFields = [
    'profession' => 'Profession',
    'speciality' => 'Speciality',
    'current_designation' => 'Current designation',
    'organization_name' => 'Organization name',
    'medical_council_name' => 'Medical council name',
    'medical_council_registration_number' => 'Medical council registration number',
    'medical_council_registration_state' => 'Medical council registration state',
];

ob_start();
?>
<div class="acad-profile-form">
    <p class="acad-eyebrow mb-2"><a href="/profile"><?= $e->html('My profile') ?></a></p>
    <h1 class="h3 mb-4"><?= $e->html($title) ?></h1>

    <?php if ($saved): ?>
        <div class="alert alert-success" role="status"><?= $e->html('Your professional details were saved.') ?></div>
    <?php endif; ?>
    <?php if ($conflict !== null): ?>
        <div class="alert alert-warning" role="alert"><?= $e->html($conflict) ?></div>
    <?php endif; ?>
    <?php if ($errors !== []): ?>
        <div class="alert alert-danger" role="alert"><?= $e->html('Please correct the highlighted fields.') ?></div>
    <?php endif; ?>

    <form method="post" action="/profile/professional" novalidate>
        <input type="hidden" name="_csrf" value="<?= $e->attr($csrf) ?>">
        <input type="hidden" name="row_version" value="<?= $e->attr($rowVersion) ?>">

        <?php foreach (['profession', 'speciality', 'current_designation', 'organization_name'] as $name): ?>
            <div class="mb-3">
                <label class="form-label" for="<?= $e->attr($name) ?>"><?= $e->html($textFields[$name]) ?></label>
                <input class="form-control" type="text" id="<?= $e->attr($name) ?>" name="<?= $e->attr($name) ?>" value="<?= $e->attr($field($name)) ?>">
                <?= $fieldErrors($name) ?>
            </div>
        <?php endforeach; ?>

        <div class="mb-3">
            <label class="form-label" for="years_of_experience"><?= $e->html('Years of experience') ?></label>
            <input class="form-control" type="number" min="0" max="70" id="years_of_experience" name="years_of_experience" value="<?= $e->attr($field('years_of_experience')) ?>">
            <?= $fieldErrors('years_of_experience') ?>
        </div>

        <?php foreach (['medical_council_name', 'medical_council_registration_number', 'medical_council_registration_state'] as $name): ?>
            <div class="mb-3">
                <label class="form-label" for="<?= $e->attr($name) ?>"><?= $e->html($textFields[$name]) ?></label>
                <input class="form-control" type="text" id="<?= $e->attr($name) ?>" name="<?= $e->attr($name) ?>" value="<?= $e->attr($field($name)) ?>">
                <?= $fieldErrors($name) ?>
            </div>
        <?php endforeach; ?>

        <div class="mb-3">
            <label class="form-label" for="registration_valid_from"><?= $e->html('Registration valid from') ?></label>
            <input class="form-control" type="date" id="registration_valid_from" name="registration_valid_from" value="<?= $e->attr($field('registration_valid_from')) ?>">
            <?= $fieldErrors('registration_valid_from') ?>
        </div>

        <div class="mb-3">
            <label class="form-label" for="registration_valid_until"><?= $e->html('Registration valid until') ?></label>
            <input class="form-control" type="date" id="registration_valid_until" name="registration_valid_until" value="<?= $e->attr($field('registration_valid_until')) ?>">
            <?= $fieldErrors('registration_valid_until') ?>
        </div>

        <button class="btn btn-primary" type="submit"><?= $e->html('Save professional details') ?></button>
    </form>
</div>
<?php
$content = (string) ob_get_clean();
require dirname(__DIR__, 2) . '/layouts/base.php';
