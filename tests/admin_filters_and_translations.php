<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$editBegin = (string)file_get_contents($root . '/tpl/edit.begin.twig');
$translationTemplate = (string)file_get_contents($root . '/tpl/translations/admin/index.twig');
$translationController = (string)file_get_contents($root . '/module/translations/admin/index.php');

if (!preg_match('/edit_is_filter[^\n]+mb-8/', $editBegin)) {
    throw new RuntimeException('Administrative filter forms do not restore spacing before result tables.');
}

foreach (array(
    'translation_add_form',
    'Value[{{ lang }}]',
    'translation-row-',
    'form="{{ row_form_id }}"',
    'translation_form }}_btnsave',
    'translation_add_form }}_btn',
) as $fragment) {
    if (!str_contains($translationTemplate, $fragment)) {
        throw new RuntimeException("Translation matrix is missing: $fragment.");
    }
}

foreach (array(
    "\$add_form = 'translations_add'",
    "View::checkFormSecurity(\$add_form)",
    "View::sendedForm('save', \$form)",
    'foreach ($langs as $lang)',
    "View::showInfo('Added')",
) as $fragment) {
    if (!str_contains($translationController, $fragment)) {
        throw new RuntimeException("Translation controller is missing: $fragment.");
    }
}

if (str_contains($translationTemplate, 'translation_edit_lang')) {
    throw new RuntimeException('Translation editor still limits the matrix to one language.');
}

if (str_contains($translationTemplate, 'name="tr[')) {
    throw new RuntimeException('Translation editor still submits the whole catalog and can exceed max_input_vars.');
}

echo "Admin filter spacing and translation matrix tests passed.\n";
