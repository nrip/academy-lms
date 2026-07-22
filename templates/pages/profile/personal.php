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
    'first_name' => 'First name',
    'middle_name' => 'Middle name',
    'last_name' => 'Last name',
    'preferred_display_name' => 'Preferred display name',
    'certificate_name' => 'Name as it should appear on certificates',
    'nationality' => 'Nationality',
    'address_line_1' => 'Address line 1',
    'address_line_2' => 'Address line 2',
    'city' => 'City',
    'state' => 'State',
    'postal_code' => 'Postal code',
    'country' => 'Country',
    'alternate_mobile' => 'Alternate mobile',
];
$genders = [
    '' => 'Select…',
    'female' => 'Female',
    'male' => 'Male',
    'other' => 'Other',
    'prefer_not_to_say' => 'Prefer not to say',
];

ob_start();
?>
<div class="acad-profile-form">
    <p class="acad-eyebrow mb-2"><a href="/profile"><?= $e->html('My profile') ?></a></p>
    <h1 class="h3 mb-4"><?= $e->html($title) ?></h1>

    <?php if ($saved): ?>
        <div class="alert alert-success" role="status"><?= $e->html('Your personal details were saved.') ?></div>
    <?php endif; ?>
    <?php if ($conflict !== null): ?>
        <div class="alert alert-warning" role="alert"><?= $e->html($conflict) ?></div>
    <?php endif; ?>
    <?php if ($errors !== []): ?>
        <div class="alert alert-danger" role="alert"><?= $e->html('Please correct the highlighted fields.') ?></div>
    <?php endif; ?>

    <form method="post" action="/profile/personal" novalidate>
        <input type="hidden" name="_csrf" value="<?= $e->attr($csrf) ?>">
        <input type="hidden" name="row_version" value="<?= $e->attr($rowVersion) ?>">

        <?php
        $renderText = ['first_name', 'middle_name', 'last_name', 'preferred_display_name', 'certificate_name'];
        foreach ($renderText as $name):
            ?>
            <div class="mb-3">
                <label class="form-label" for="<?= $e->attr($name) ?>"><?= $e->html($textFields[$name]) ?></label>
                <input class="form-control" type="text" id="<?= $e->attr($name) ?>" name="<?= $e->attr($name) ?>" value="<?= $e->attr($field($name)) ?>">
                <?= $fieldErrors($name) ?>
            </div>
        <?php endforeach; ?>

        <div class="form-check mb-3">
            <input class="form-check-input" type="checkbox" id="certificate_name_confirmed" name="certificate_name_confirmed" value="1" <?= $field('certificate_name_confirmed') === '1' ? 'checked' : '' ?>>
            <label class="form-check-label" for="certificate_name_confirmed">
                <?= $e->html('I confirm my certificate name is spelled correctly.') ?>
            </label>
            <?= $fieldErrors('certificate_name_confirmed') ?>
        </div>

        <div class="mb-3">
            <label class="form-label" for="date_of_birth"><?= $e->html('Date of birth') ?></label>
            <input class="form-control" type="date" id="date_of_birth" name="date_of_birth" value="<?= $e->attr($field('date_of_birth')) ?>">
            <?= $fieldErrors('date_of_birth') ?>
        </div>

        <div class="mb-3">
            <label class="form-label" for="gender"><?= $e->html('Gender') ?></label>
            <select class="form-select" id="gender" name="gender">
                <?php foreach ($genders as $value => $label): ?>
                    <option value="<?= $e->attr($value) ?>" <?= $field('gender') === (string) $value ? 'selected' : '' ?>><?= $e->html($label) ?></option>
                <?php endforeach; ?>
            </select>
            <?= $fieldErrors('gender') ?>
        </div>

        <?php
        $remaining = ['nationality', 'address_line_1', 'address_line_2', 'city', 'state', 'postal_code', 'country', 'alternate_mobile'];
        foreach ($remaining as $name):
            ?>
            <div class="mb-3">
                <label class="form-label" for="<?= $e->attr($name) ?>"><?= $e->html($textFields[$name]) ?></label>
                <input class="form-control" type="text" id="<?= $e->attr($name) ?>" name="<?= $e->attr($name) ?>" value="<?= $e->attr($field($name)) ?>">
                <?= $fieldErrors($name) ?>
            </div>
        <?php endforeach; ?>

        <button class="btn btn-primary" type="submit"><?= $e->html('Save personal details') ?></button>
    </form>
</div>
<?php
$content = (string) ob_get_clean();
require dirname(__DIR__, 2) . '/layouts/base.php';
