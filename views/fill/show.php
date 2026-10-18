<?php
use App\Core\View;
$old = isset($old) ? $old : [];
$settings = $settings ?? [];
// Vista previa (modelo Elementor): cuando el controlador manda `$previewAction`,
// el form postea a esa ruta (que valida pero no persiste) en vez de a /f/{slug}.
$previewAction = $previewAction ?? null;
$previewBanner = $previewAction !== null;
$fieldTypes = ['text', 'textarea', 'email', 'number', 'date', 'select', 'radio', 'checkbox', 'scale', 'url', 'tel', 'time', 'datetime-local', 'range', 'section', 'matrix', 'nps', 'star', 'ranking', 'file'];

// Q6 — i18n del fill: override del request (?lang= / campo oculto lang en el
// POST) > settings.locale > Accept-Language (si el form no fija idioma) >
// app.locale > es. `forRequest()` es la única puerta para GET y POST.
$locale = \App\Core\I18n::forRequest($settings);
$t = static fn (string $key, array $params = []): string => \App\Core\I18n::t($key, $locale, $params);

// Subcatalog para el cliente (fill.js): las claves ui./val./js. visibles.
$vfI18n = array_filter(
    \App\Core\I18n::allFor($locale),
    static fn (string $k): bool => str_starts_with($k, 'ui.') || str_starts_with($k, 'val.') || str_starts_with($k, 'js.'),
    ARRAY_FILTER_USE_KEY
);
$vfI18nJson = json_encode($vfI18n, JSON_UNESCAPED_UNICODE);

// Gap sin JavaScript: visibilidad resuelta en el servidor (FillController
// ::initialVisibility). Se marca con `vf-hidden`, la misma clase que usa
// fill.js, de modo que el cliente puede revelar en vivo al cambiar una
// respuesta sin tener que distinguir el origen del ocultado.
$hiddenMap = is_array($hidden ?? null) ? $hidden : [];
$hiddenAttr = static function (array $field) use ($hiddenMap): string {
    $id = (int) ($field['id'] ?? 0);
    return (isset($hiddenMap[$id]) && $hiddenMap[$id] === false) ? ' vf-hidden' : '';
};

$logicAttr = static function (array $field): string {
    $logic = $field['logic'] ?? null;
    if (!is_array($logic) || empty($logic['rules']) || !is_array($logic['rules'])) {
        return '';
    }
    return ' data-vf-logic="' . View::escape(json_encode($logic, JSON_UNESCAPED_UNICODE)) . '"';
};

$renderCard = static function (array $field) use ($old, $fieldTypes, $logicAttr, $hiddenAttr, $t): void {
    $fieldKey = 'field_' . $field['id'];
    $inputId = 'field_' . (int) $field['id'];
    $oldValue = isset($old[$fieldKey]) ? $old[$fieldKey] : '';
    $required = (bool) $field['is_required'];
    $type = in_array($field['type'], $fieldTypes, true) ? $field['type'] : 'text';
    $options = is_array($field['options']) ? $field['options'] : [];
    $validations = is_array($field['validations']) ? $field['validations'] : [];
    // Las opciones pueden venir como objetos {value, group} del builder o como
    // lista simple de strings; normalizarlas evita errores al filtrar por grupo.
    $normOpts = array_map(static fn($o) => is_array($o) ? $o : ['value' => $o], $options);
    $isGroup = in_array($type, ['radio', 'checkbox', 'scale', 'nps', 'star'], true);
    $isMatrix = $type === 'matrix';
    $isRanking = $type === 'ranking';
    $matrixRows = array_map(static fn($o) => (string) $o['value'], array_filter($normOpts, static fn($o) => ($o['group'] ?? '') === 'rows'));
    $matrixCols = array_map(static fn($o) => (string) $o['value'], array_filter($normOpts, static fn($o) => ($o['group'] ?? '') === 'cols'));
    $rankItems = array_map(static fn($o) => (string) $o['value'], $normOpts);
    $isSingleInput = in_array($type, ['text', 'textarea', 'email', 'number', 'date', 'select', 'url', 'tel', 'time', 'datetime-local', 'range'], true);
    // P10 — Campo de archivo: límites que el cliente replica antes de enviar
    // (el servidor los vuelve a validar; el cliente es solo UX).
    $isFile = $type === 'file';
    $fileMaxMb = $isFile ? \App\Services\Uploader::maxSizeMb($validations) : 0;
    $fileMaxFiles = $isFile ? \App\Services\Uploader::maxFiles($validations) : 0;
    $fileAccept = $isFile ? \App\Services\Uploader::acceptAttr($validations) : '';
    $hasMaxLen = !empty($validations['max_length']);
    // data-vf-min/max: el cliente replica las cotas antes de enviar (UX); el
    // servidor las vuelve a validar. `scale` siempre mide 1..max aunque el
    // validador no haya persistido `min`.
    $vfMin = $vfMax = null;
    if ($type === 'scale') {
        $vfMin = 1;
        $vfMax = (int) ($validations['max'] ?? 5);
    } elseif (in_array($type, ['number', 'range', 'nps', 'star'], true)) {
        $vfMin = isset($validations['min']) ? (float) $validations['min'] : null;
        $vfMax = isset($validations['max']) ? (float) $validations['max'] : null;
    }
    ?>
            <section class="vf-field-card flip-in<?= $hiddenAttr($field) ?>" data-vf-key="<?= View::escape($fieldKey) ?>"
                     data-vf-field-id="<?= (int) $field['id'] ?>"
                     data-vf-type="<?= View::escape($type) ?>"
                     data-vf-required="<?= $required ? '1' : '0' ?>"
                     <?= $logicAttr($field) ?>
                     <?= $vfMin !== null ? 'data-vf-min="' . $vfMin . '"' : '' ?>
                     <?= $vfMax !== null ? 'data-vf-max="' . $vfMax . '"' : '' ?>
                     <?= isset($validations['min_length']) ? 'data-vf-min-len="' . (int) $validations['min_length'] . '"' : '' ?>
                     <?= isset($validations['max_length']) ? 'data-vf-max-len="' . (int) $validations['max_length'] . '"' : '' ?>
                     <?= $isFile ? 'data-vf-file-max-size="' . ($fileMaxMb * 1024 * 1024) . '"' : '' ?>
                     <?= $isFile && \App\Services\Uploader::isMultiple($validations) ? 'data-vf-file-multiple="1"' : '' ?>
                     <?= $isFile ? 'data-vf-file-max-files="' . $fileMaxFiles . '"' : '' ?>>

                <?php if ($isFile): ?>
                    <fieldset class="vf-fieldset">
                        <legend class="vf-label">
                            <span><?= View::escape($field['label']) ?><?= $required ? ' <span class="text-danger">*</span>' : '' ?></span>
                            <span class="vf-valid-mark" aria-hidden="true"><i class="fa-solid fa-check"></i></span>
                        </legend>
                        <?php if ($field['help_text']): ?>
                            <div class="vf-help"><?= View::escape($field['help_text']) ?></div>
                        <?php endif; ?>
                        <input type="file" class="form-control vf-file-input" id="<?= $inputId ?>"
                            name="<?= View::escape($fieldKey) ?>[]"
                            <?= \App\Services\Uploader::isMultiple($validations) ? 'multiple' : '' ?>
                            <?= $fileAccept !== '' ? 'accept="' . View::escape($fileAccept) . '"' : '' ?>
                            <?= $required ? 'required data-required="1"' : '' ?>>
                        <div class="vf-file-list small text-muted mt-2" data-vf-file-list hidden></div>
                        <p class="vf-file-hint text-muted small mb-0 mt-1">
                            <i class="fa-solid fa-circle-info me-1"></i><?= $t('ui.file_hint', [
                                'size'  => $fileMaxMb,
                                'count' => $fileMaxFiles,
                                'types' => $fileAccept !== '' ? $fileAccept : $t('ui.file_types_all'),
                            ]) ?>
                        </p>
                        <div class="vf-error" id="vf_err_<?= View::escape($fieldKey) ?>" role="alert"></div>
                    </fieldset>

                <?php elseif ($isMatrix): ?>
                    <fieldset class="vf-fieldset">
                        <legend class="vf-label">
                            <span><?= View::escape($field['label']) ?><?= $required ? ' <span class="text-danger">*</span>' : '' ?></span>
                            <span class="vf-valid-mark" aria-hidden="true"><i class="fa-solid fa-check"></i></span>
                        </legend>
                        <?php if ($field['help_text']): ?>
                            <div class="vf-help"><?= View::escape($field['help_text']) ?></div>
                        <?php endif; ?>
                        <div class="vf-matrix-table-wrap table-responsive" <?= $required ? 'data-required="1"' : '' ?>>
                            <table class="table table-sm mb-0 vf-matrix-table">
                                <thead>
                                    <tr>
                                        <th class="vf-matrix-empty"></th>
                                        <?php foreach ($matrixCols as $col): ?>
                                            <th scope="col" class="small fw-semibold text-center"><?= View::escape($col) ?></th>
                                        <?php endforeach; ?>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($matrixRows as $ri => $row): ?>
                                        <tr data-vf-row="<?= View::escape($row) ?>">
                                            <th scope="row" class="small fw-normal text-start"><?= View::escape($row) ?></th>
                                            <?php foreach ($matrixCols as $col): ?>
                                                <td class="text-center">
                                                    <label class="vf-matrix-cell" title="<?= View::escape($col) ?>">
                                                        <input type="radio" name="<?= View::escape($fieldKey) ?>[<?= $ri ?>]" value="<?= View::escape($col) ?>"
                                                            <?php $oldRow = is_array($oldValue) ? $oldValue : []; ?>
                                                            <?= (string) ($oldRow[$ri] ?? '') === (string) $col ? 'checked' : '' ?>>
                                                    </label>
                                                </td>
                                            <?php endforeach; ?>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        <div class="vf-error" id="vf_err_<?= View::escape($fieldKey) ?>" role="alert"></div>
                    </fieldset>

                <?php elseif ($isRanking): ?>
                    <fieldset class="vf-fieldset">
                        <legend class="vf-label">
                            <span><?= View::escape($field['label']) ?><?= $required ? ' <span class="text-danger">*</span>' : '' ?></span>
                            <span class="vf-valid-mark" aria-hidden="true"><i class="fa-solid fa-check"></i></span>
                        </legend>
                        <?php if ($field['help_text']): ?>
                            <div class="vf-help"><?= View::escape($field['help_text']) ?></div>
                        <?php endif; ?>
                        <div class="vf-ranking-list d-flex flex-column gap-2" data-vf-ranking
                             data-vf-count="<?= count($rankItems) ?>" <?= $required ? 'data-required="1"' : '' ?>>
                            <?php foreach ($rankItems as $i => $opt): ?>
                                <div class="d-flex align-items-center gap-2" data-vf-rank="<?= View::escape($opt) ?>">
                                    <span class="badge text-bg-secondary vf-ranking-pos"><?= $i + 1 ?></span>
                                    <label class="small fw-semibold flex-grow-1 mb-0" for="f<?= (int) $field['id'] ?>_r<?= $i ?>"><?= View::escape($opt) ?></label>
                                    <input type="number" class="form-control form-control-sm vf-ranking-input" id="f<?= (int) $field['id'] ?>_r<?= $i ?>"
                                        name="<?= View::escape($fieldKey) ?>[<?= $i ?>]" min="1" max="<?= count($rankItems) ?>" placeholder="1..<?= count($rankItems) ?>">
                                </div>
                            <?php endforeach; ?>
                        </div>
                        <p class="text-muted small mt-2 mb-0"><i class="fa-solid fa-list-ol me-1"></i><?= $t('ui.ranking_hint', ['max' => count($rankItems)]) ?></p>
                        <div class="vf-error" id="vf_err_<?= View::escape($fieldKey) ?>" role="alert"></div>
                    </fieldset>

                <?php elseif ($isGroup): ?>
                    <fieldset class="vf-fieldset">
                        <legend class="vf-label">
                            <span><?= View::escape($field['label']) ?><?= $required ? ' <span class="text-danger">*</span>' : '' ?></span>
                            <span class="vf-valid-mark" aria-hidden="true"><i class="fa-solid fa-check"></i></span>
                        </legend>
                        <?php if ($field['help_text']): ?>
                            <div class="vf-help"><?= View::escape($field['help_text']) ?></div>
                        <?php endif; ?>

                        <?php if ($type === 'radio' || $type === 'checkbox'): ?>
                            <div class="vf-options">
                                <?php foreach ($options as $opt): ?>
                                    <?php $optVal = $opt['value'] ?? $opt; ?>
                                    <?php $checkedW = $optVal; ?>
                                    <div class="vf-option form-check">
                                        <input class="form-check-input" type="<?= View::escape($type) ?>"
                                               name="<?= View::escape($fieldKey) ?><?= $type === 'checkbox' ? '[]' : '' ?>"
                                               id="f<?= (int) $field['id'] ?>_<?= View::escape(md5($optVal)) ?>"
                                               value="<?= View::escape($optVal) ?>"
                                            <?php if ($type === 'radio'): ?>
                                                <?= is_string($oldValue) && $oldValue === $optVal ? 'checked' : '' ?>
                                            <?php else: ?>
                                                <?php $oldArr = is_array($oldValue) ? $oldValue : (is_string($oldValue) ? json_decode($oldValue, true) ?? [] : []); ?>
                                                <?= in_array($optVal, $oldArr, true) ? 'checked' : '' ?>
                                            <?php endif; ?>>
                                        <label class="form-check-label" for="f<?= (int) $field['id'] ?>_<?= View::escape(md5($optVal)) ?>"><?= View::escape($optVal) ?></label>
                                    </div>
                                <?php endforeach; ?>
                            </div>

                        <?php // `visually-hidden`, no `d-none`: el radio tiene que SEGUIR siendo
                              // enfocable. Con `d-none` (`display:none`) el control
                              // entero se quedaba sin un solo tabulador —el input
                              // escondido y el `<span>` con `tabindex="-1"`—,
                              // así que desde el teclado no se podía responder una
                              // escala, un NPS ni unas estrellas. El CSS de
                              // app.css ya tenía las reglas `input:focus-visible +
                              // .scale-btn` / `.star-btn`; solo esperaban a que el
                              // input dejara de estar en `display:none`. Lo mismo
                              // para los tres tipos: scale, nps y star. ?>
                        <?php elseif ($type === 'scale'): ?>
                            <?php $max = (int) ($validations['max'] ?? 5); ?>
                            <div class="scale-selector">
                                <?php for ($i = 1; $i <= $max; $i++): ?>
                                    <label class="scale-option">
                                        <input type="radio" name="<?= View::escape($fieldKey) ?>" value="<?= $i ?>"
                                               class="visually-hidden" <?= $required ? 'data-required="1"' : '' ?>
                                            <?= is_string($oldValue) && (string) $oldValue === (string) $i ? 'checked' : '' ?>>
                                        <span class="scale-btn" tabindex="-1"><?= $i ?></span>
                                    </label>
                                <?php endfor; ?>
                            </div>

                        <?php elseif ($type === 'nps'): ?>
                            <div class="nps-selector d-flex flex-wrap gap-1" data-vf-min="0" data-vf-max="10">
                                <?php for ($i = 0; $i <= 10; $i++): ?>
                                    <label class="nps-option">
                                        <input type="radio" name="<?= View::escape($fieldKey) ?>" value="<?= $i ?>"
                                               class="visually-hidden" <?= $required ? 'data-required="1"' : '' ?>
                                            <?= is_string($oldValue) && (string) $oldValue === (string) $i ? 'checked' : '' ?>>
                                        <span class="scale-btn" tabindex="-1"><?= $i ?></span>
                                    </label>
                                <?php endfor; ?>
                            </div>
                            <div class="d-flex justify-content-between small text-muted mt-1">
                                <span><?= $t('ui.nada_probable') ?></span>
                                <span><?= $t('ui.muy_probable') ?></span>
                            </div>

                        <?php elseif ($type === 'star'): ?>
                            <?php $smax = (int) ($validations['max'] ?? 5); ?>
                            <div class="star-selector d-flex gap-1" data-vf-min="1" data-vf-max="<?= $smax ?>">
                                <?php for ($i = 1; $i <= $smax; $i++): ?>
                                    <label class="star-option">
                                        <input type="radio" name="<?= View::escape($fieldKey) ?>" value="<?= $i ?>"
                                               class="visually-hidden" <?= $required ? 'data-required="1"' : '' ?>
                                            <?= is_string($oldValue) && (string) $oldValue === (string) $i ? 'checked' : '' ?>>
                                        <i class="fa-solid fa-star star-btn" tabindex="-1"></i>
                                    </label>
                                <?php endfor; ?>
                            </div>
                        <?php endif; ?>

                        <div class="vf-error" id="vf_err_<?= View::escape($fieldKey) ?>" role="alert"></div>
                    </fieldset>

                <?php else: ?>
                    <div class="vf-label">
                        <label class="form-label fw-semibold" for="<?= $inputId ?>">
                            <?= View::escape($field['label']) ?><?= $required ? ' <span class="text-danger">*</span>' : '' ?>
                        </label>
                        <span class="vf-valid-mark" aria-hidden="true"><i class="fa-solid fa-check"></i></span>
                    </div>
                    <?php if ($field['help_text']): ?>
                        <div class="vf-help"><?= View::escape($field['help_text']) ?></div>
                    <?php endif; ?>

                    <div class="vf-control-wrap">
                        <?php if ($type === 'textarea'): ?>
                            <textarea class="form-control" id="<?= $inputId ?>" name="<?= View::escape($fieldKey) ?>"
                                placeholder="<?= View::escape($field['placeholder']) ?>" rows="4"
                                <?= $required ? 'required data-required="1"' : '' ?>
                                <?= !empty($validations['min_length']) ? ' minlength="' . (int) $validations['min_length'] . '"' : '' ?>
                                <?= !empty($validations['max_length']) ? ' maxlength="' . (int) $validations['max_length'] . '"' : '' ?>><?= View::escape(is_string($oldValue) ? $oldValue : '') ?></textarea>

                        <?php elseif ($type === 'email'): ?>
                            <input type="email" class="form-control" id="<?= $inputId ?>" name="<?= View::escape($fieldKey) ?>"
                                placeholder="<?= View::escape($field['placeholder']) ?>" value="<?= View::escape(is_string($oldValue) ? $oldValue : '') ?>"
                                <?= $required ? 'required data-required="1"' : '' ?>>

                        <?php elseif ($type === 'number'): ?>
                            <input type="number" class="form-control" id="<?= $inputId ?>" name="<?= View::escape($fieldKey) ?>"
                                placeholder="<?= View::escape($field['placeholder']) ?>" value="<?= View::escape(is_string($oldValue) ? $oldValue : '') ?>"
                                step="any"
                                <?= isset($validations['min']) ? ' min="' . (float) $validations['min'] . '"' : '' ?>
                                <?= isset($validations['max']) ? ' max="' . (float) $validations['max'] . '"' : '' ?>
                                <?= $required ? 'required data-required="1"' : '' ?>>

                        <?php elseif ($type === 'date'): ?>
                            <input type="date" class="form-control" id="<?= $inputId ?>" name="<?= View::escape($fieldKey) ?>"
                                value="<?= View::escape(is_string($oldValue) ? $oldValue : '') ?>"
                                <?= $required ? 'required data-required="1"' : '' ?>>

                        <?php elseif ($type === 'url'): ?>
                            <input type="url" class="form-control" id="<?= $inputId ?>" name="<?= View::escape($fieldKey) ?>"
                                placeholder="<?= View::escape($field['placeholder'] ?: 'https://') ?>" value="<?= View::escape(is_string($oldValue) ? $oldValue : '') ?>"
                                <?= $required ? 'required data-required="1"' : '' ?>>

                        <?php elseif ($type === 'tel'): ?>
                            <input type="tel" class="form-control" id="<?= $inputId ?>" name="<?= View::escape($fieldKey) ?>"
                                placeholder="<?= View::escape($field['placeholder']) ?>" value="<?= View::escape(is_string($oldValue) ? $oldValue : '') ?>"
                                <?= $required ? 'required data-required="1"' : '' ?>>

                        <?php elseif ($type === 'time'): ?>
                            <input type="time" class="form-control" id="<?= $inputId ?>" name="<?= View::escape($fieldKey) ?>"
                                value="<?= View::escape(is_string($oldValue) ? $oldValue : '') ?>"
                                <?= $required ? 'required data-required="1"' : '' ?>>

                        <?php elseif ($type === 'datetime-local'): ?>
                            <input type="datetime-local" class="form-control" id="<?= $inputId ?>" name="<?= View::escape($fieldKey) ?>"
                                value="<?= View::escape(is_string($oldValue) ? $oldValue : '') ?>"
                                <?= $required ? 'required data-required="1"' : '' ?>>

                        <?php elseif ($type === 'range'): ?>
                            <?php $rmin = isset($validations['min']) ? (float) $validations['min'] : 0; ?>
                            <?php $rmax = isset($validations['max']) ? (float) $validations['max'] : 100; ?>
                            <?php $rcur = is_string($oldValue) && $oldValue !== '' ? $oldValue : (string) $rmin; ?>
                            <div class="d-flex align-items-center gap-3 vf-range-wrap">
                                <input type="range" class="form-range flex-grow-1 vf-range-input" id="<?= $inputId ?>" name="<?= View::escape($fieldKey) ?>"
                                    min="<?= $rmin ?>" max="<?= $rmax ?>" step="1" value="<?= View::escape($rcur) ?>">
                                <output class="vf-range-val" for="<?= $inputId ?>"><?= View::escape($rcur) ?></output>
                            </div>

                        <?php elseif ($type === 'select'): ?>
                            <select class="form-select" id="<?= $inputId ?>" name="<?= View::escape($fieldKey) ?>" <?= $required ? 'required data-required="1"' : '' ?>>
                                <option value=""><?= $t('ui.select_option') ?></option>
                                <?php foreach ($options as $opt): ?>
                                    <?php $optVal = $opt['value'] ?? $opt; ?>
                                    <option value="<?= View::escape($optVal) ?>" <?= is_string($oldValue) && $oldValue === $optVal ? 'selected' : '' ?>><?= View::escape($optVal) ?></option>
                                <?php endforeach; ?>
                            </select>

                        <?php else: /* text */ ?>
                            <input type="text" class="form-control" id="<?= $inputId ?>" name="<?= View::escape($fieldKey) ?>"
                                placeholder="<?= View::escape($field['placeholder']) ?>" value="<?= View::escape(is_string($oldValue) ? $oldValue : '') ?>"
                                <?= $required ? 'required data-required="1"' : '' ?>
                                <?= !empty($validations['min_length']) ? ' minlength="' . (int) $validations['min_length'] . '"' : '' ?>
                                <?= !empty($validations['max_length']) ? ' maxlength="' . (int) $validations['max_length'] . '"' : '' ?>>
                        <?php endif; ?>

                        <div class="vf-error" id="vf_err_<?= View::escape($fieldKey) ?>" role="alert"></div>
                        <?php if (in_array($type, ['text', 'textarea'], true) && $hasMaxLen): ?>
                            <span class="vf-charcount" aria-live="polite" data-vf-charcount-for="<?= View::escape($fieldKey) ?>" data-vf-max-len="<?= (int) $validations['max_length'] ?>"></span>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </section>
    <?php
};
?>
<!DOCTYPE html>
<html lang="<?= $locale ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= View::escape($form['title']) ?></title>
    <link rel="icon" type="image/svg+xml" href="/assets/favicon.svg">
    <link rel="stylesheet" href="<?= View::asset('css/bootstrap.min.css') ?>">
    <link rel="stylesheet" href="<?= View::asset('css/all.min.css') ?>">
    <link rel="stylesheet" href="<?= View::asset('css/app.css') ?>">
    <?php
    // P7 — Branding por formulario: las variables CSS del tema (inline) ganan
    // a las de app.css por orden de carga.
    $themeCss = \App\Services\FormSettings::themeCss($settings);
    if ($themeCss !== ''): ?><style><?= $themeCss ?></style><?php endif; ?>
</head>
<body class="fill-body" data-has-errors="<?= !empty($errors) ? '1' : '0' ?>">
<div class="fill-container">

    <div class="vf-header flip-in">
        <div class="vf-header-top">
            <span class="app-logo-sm"><i class="fa-solid fa-list-check"></i></span>
            <span class="small"><?= View::escape(\App\Core\Config::get('app.name')) ?></span>
        </div>
        <h1><?= View::escape($form['title']) ?></h1>
        <?php if ($form['description']): ?>
            <div class="vf-desc"><?= nl2br(View::escape($form['description'])) ?></div>
        <?php endif; ?>

        <?php
        // Q1 — Meta del formulario: nº de preguntas contestables y duración
        // estimada (una pregunta ≈ 12 s).
        $answerableTypes = ['text', 'textarea', 'email', 'number', 'date', 'select', 'radio', 'checkbox', 'scale', 'url', 'tel', 'time', 'datetime-local', 'range', 'matrix', 'nps', 'star', 'ranking', 'file'];
        $answerableCount = 0;
        foreach ($fields as $fld) {
            if (in_array(($fld['type'] ?? ''), $answerableTypes, true)) {
                $answerableCount++;
            }
        }
        $estMinutes = max(1, (int) ceil($answerableCount / 5));
        ?>
        <?php if ($answerableCount > 0): ?>
            <div class="vf-header-meta d-flex flex-wrap gap-3 small text-muted mt-2">
                <span><i class="fa-solid fa-list-check me-1"></i><?= $t('ui.questions_count', ['n' => $answerableCount]) ?></span>
                <span><i class="fa-solid fa-clock me-1"></i><?= $t('ui.est_minutes', ['n' => $estMinutes]) ?></span>
            </div>
        <?php endif; ?>

        <?php
        // V1 — Multi-paso: los marcadores de página se resuelven antes que el
        // wrap de progreso para que la barra sea sticky solo en modo multi-paso.
        $pageMarkersV1 = 0;
        foreach ($fields as $fld) {
            if ($fld['type'] === 'page') {
                $pageMarkersV1++;
            }
        }
        $hasPages = $pageMarkersV1 > 0;
        ?>
        <div class="vf-progress-wrap<?= $hasPages ? ' vf-sticky' : '' ?>" id="vfProgressWrap">
            <div class="vf-progress-meta">
                <span><i class="fa-solid fa-circle-half-stroke me-1"></i><?= $t('ui.avance') ?></span>
                <span id="vfProgressLabel" aria-live="polite">0%</span>
            </div>
            <div class="vf-progress">
                <div class="vf-progress-bar" id="vfProgressBar" style="width:0%"></div>
            </div>
        </div>
    </div>

    <?php if (!empty($errors)): ?>
        <div class="alert alert-danger border-0 shadow-sm app-fill-alert flip-in" role="alert">
            <h4 class="h6 fw-bold mb-1"><i class="fa-solid fa-triangle-exclamation me-1"></i><?= $t('ui.corrige_errores') ?></h4>
            <ul class="mb-0 small">
                <?php foreach ($errors as $error): ?>
                    <li><?= View::escape($error) ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <?php if ($previewBanner): ?>
        <div class="alert alert-info border-0 shadow-sm app-fill-alert flip-in" role="status">
            <h4 class="h6 fw-bold mb-1"><i class="fa-solid fa-eye me-1"></i>Vista previa</h4>
            <p class="mb-0 small">Así se verá el formulario. Puedes probarlo, pero <strong>no se guardará ninguna respuesta</strong>.</p>
        </div>
    <?php endif; ?>

    <?php // P10 — multipart solo si el formulario tiene campos de archivo. ?>
    <?php $hasFileField = false; foreach ($fields as $fld) { if (($fld['type'] ?? '') === 'file') { $hasFileField = true; break; } } ?>
    <?php // F4 — El token de acceso viaja también en el action del formulario, no
          // solo en la sesión: sin cookies el GET se sirve igual (el token venía
          // en la URL) pero el POST se caería en un 403 sin explicación, y dentro
          // de un iframe la cookie es de terceros y muchos navegadores la
          // bloquean. Es la misma credencial que ya está en la barra de
          // direcciones, en el historial y en el Referer que el navegador no envía
          // desde un action: no amplia la exposición, evita un formulario
          // irrellenable. ?>
    <form method="post" action="<?= View::escape($previewAction ?? ('/f/' . $form['slug'] . (!empty($accessToken) ? '?t=' . rawurlencode((string) $accessToken) : ''))) ?>" id="vfForm" data-slug="<?= View::escape($form['slug']) ?>"<?= !empty($settings['advance']['auto']) ? ' data-vf-auto-advance="1"' : '' ?> data-vf-i18n="<?= View::escape($vfI18nJson) ?>" class="d-flex flex-column" novalidate<?= $hasFileField ? ' enctype="multipart/form-data"' : '' ?>>
        <?= \App\Core\Csrf::field() ?>

        <?php // Q6 — El idioma renderizado viaja en el POST para que el re-render
        // con errores y el submit no cambien de idioma en mitad del flujo. ?>
        <input type="hidden" name="lang" value="<?= View::escape($locale) ?>">

        <?php // P7 — Honeypot: trampa invisible para bots (CSS lo saca de la vista). ?>
        <?php if (!empty($settings['honeypot']['enabled'])): ?>
            <div class="vf-honeypot" aria-hidden="true">
                <label for="_vf_hp"><?= $t('ui.leave_empty') ?></label>
                <input type="text" name="_vf_hp" id="_vf_hp" value="" tabindex="-1" autocomplete="off" aria-hidden="true">
            </div>
        <?php endif; ?>

        <?php
        // P4 — Multi-paso (resto de la factoría; $hasPages ya se calculó antes
        // del wrap de progreso para la clase sticky).
        $firstField = $fields[0] ?? null;
        $hasImplicitFirst = $hasPages && $firstField !== null && $firstField['type'] !== 'page';
        // Bloques de página: implícito (si hay contenido previo) + 1 por cada page.
        $pageBlockIdx = 0;
        $totalBlockCount = $pageMarkersV1 + ($hasImplicitFirst ? 1 : 0);
        $blockOpen = false;

        $emitPageHeader = static function (?array $pageField) use (&$pageBlockIdx, &$blockOpen, $t, $totalBlockCount): void {
            $pageBlockIdx++;
            $blockOpen = true;
            // F5 — `tabindex="-1"` NO pone el bloque en el orden de tabulación:
            // solo lo hace focusable porscript, que es justo lo que hace falta
            // para dejar el foco en el paso nuevo tras un salto automático. Sin
            // esto el foco se queda en un campo del paso anterior (ya oculto) y
            // quien navega con teclado o con lector de pantalla no tiene ni idea
            // de que ha cambiado de página. El `aria-label` da nombre a la región
            // («Paso 2 de 3») sin pintar una etiqueta que estorbe.
            $blockAttrs = ' tabindex="-1" aria-label="'
                . View::escape($t('ui.paso') . ' ' . $pageBlockIdx . ' ' . $t('ui.de') . ' ' . $totalBlockCount)
                . '"';
            if ($pageField !== null) {
                $pageLogic = $pageField['logic'] ?? null;
                if (is_array($pageLogic) && !empty($pageLogic['rules'])) {
                    $blockAttrs .= ' data-vf-page-logic="' . View::escape(json_encode($pageLogic, JSON_UNESCAPED_UNICODE)) . '"';
                }
            }
            echo '<section class="vf-page-block flip-in"' . $blockAttrs . '>';
            // Un salto de página SIN etiqueta no inventa un título. Antes caía en
            // «Página N» (`ui.page_title`), que es un encabezado que no dice nada
            // de la página cuando el pie ya lleva «Paso 2 de 3» y el `aria-label`
            // del bloque lo nombra igual: dos veces lo mismo y ninguna vez el
            // contenido. Con descripción, la cabecera se queda con ella; sin
            // título ni descripción, no se pinta cabecera.
            //
            // Y `fields.show_header` (0013) apaga la caja entera cuando quien
            // edita decide que ese paso no necesita una: el bloque, su
            // `aria-label`, la barra de progreso y el pie se siguen pintando, así
            // que el paso no desaparece, solo su cabecera.
            if ($pageField !== null && (int) ($pageField['show_header'] ?? 1) === 1) {
                $title = trim((string) ($pageField['label'] ?? ''));
                $desc = trim((string) ($pageField['help_text'] ?? ''));
                if ($title !== '' || $desc !== '') {
                    echo '<header class="vf-page-head">';
                    // Título primero y icono al final, como el encabezado de la
                    // sección: la fila reparte el sobrante con `space-between` y
                    // el icono cae a la derecha sin necesitar un margen propio. Es
                    // el mismo criterio en los dos bloques (`vf-section-inner` y
                    // `vf-page-head`), para que un paso y una sección se lean
                    // como el mismo tipo de separador.
                    echo '<div>';
                    if ($title !== '') {
                        echo '<h2 class="vf-page-title h4 fw-bold mb-0">' . View::escape($title) . '</h2>';
                    }
                    if ($desc !== '') {
                        echo '<p class="vf-page-desc text-muted mt-1 mb-0">' . nl2br(View::escape($desc)) . '</p>';
                    }
                    echo '</div>';
                    echo '<span class="vf-page-icon"><i class="fa-solid fa-file-lines"></i></span>';
                    echo '</header>';
                }
            }
        };

        // OJO: $turnstile tiene que estar en la lista `use`. Una closure solo ve
        // las variables que declara: sin él, `!empty($turnstile['enabled'])` leía
        // una variable INDEFINIDA, así que en un formulario multipágina el
        // widget de Turnstile no se pintaba nunca en el pie del último paso y el
        // token no llegaba al submit (que sí lo exigía) → el formulario se
        // rechazaba a sí mismo sin control anti-bot visible.
        $emitPageFooter = static function () use (&$pageBlockIdx, &$blockOpen, $totalBlockCount, $t, $turnstile): void {
            echo '<div class="vf-page-footer d-flex align-items-center justify-content-between gap-3 flip-in">';
            if ($pageBlockIdx > 1) {
                echo '<button type="button" class="btn vf-nav-btn" data-vf-action="prev"><i class="fa-solid fa-arrow-left me-2"></i>' . $t('ui.anterior') . '</button>';
            } else {
                // La clase importa: `ensureFooterControls()` retira los controles
                // que traer el servidor por `button[data-vf-action], #vfSubmit,
                // .vf-footer-spacer`. Un hueco sin clase no entraba en ese
                // selector, así que el primer paso acababa con DOS separadores
                // (el del servidor y el que crea el JS) y el badge descentrado.
                echo '<span class="vf-footer-spacer"></span>';
            }
            echo '<span class="vf-step-badge" aria-live="polite">' . $t('ui.paso') . ' <span class="vf-step-num">' . $pageBlockIdx . '</span> ' . $t('ui.de') . ' <span class="vf-step-total">' . $totalBlockCount . '</span></span>';
            if ($pageBlockIdx < $totalBlockCount) {
                echo '<button type="button" class="btn vf-nav-btn vf-nav-next" data-vf-action="next">' . $t('ui.siguiente') . '<i class="fa-solid fa-arrow-right ms-2"></i></button>';
            } else {
                if (!empty($turnstile['enabled'])) {
                    echo '<div class="cf-turnstile d-flex justify-content-center" data-sitekey="' . View::escape((string) ($turnstile['site_key'] ?? '')) . '"></div>';
                }
                echo '<button type="submit" id="vfSubmit" class="vf-submit"><span class="vf-submit-label"><i class="fa-solid fa-paper-plane me-2"></i>' . $t('ui.enviar') . '</span></button>';
            }
            echo '</div>';
            echo '</section>';
            $blockOpen = false;
        };
        ?>

        <?php $i = 0; $n = count($fields); ?>
        <?php while ($i < $n): $field = $fields[$i]; $i++; ?>
            <?php if ($field['type'] === 'page'): ?>
                <?php if ($blockOpen) { $emitPageFooter(); } ?>
                <?php $emitPageHeader($field); ?>
            <?php elseif ($field['type'] === 'section'): ?>
                <?php if ($hasPages && !$blockOpen) { $emitPageHeader(null); } ?>
                <section class="vf-section-block flip-in<?= $hiddenAttr($field) ?>" data-vf-field-id="<?= (int) $field['id'] ?>"<?= $logicAttr($field) ?>>
                    <div class="vf-section-inner">
                        <?php // Título antes que icono, como en el preview: la fila
                              // reparte el sobrante con `space-between` y así el
                              // icono queda a la derecha sin que ninguno de los dos
                              // tenga que empujarse con un margen. ?>
                        <h2 class="vf-section-title h4 fw-bold mb-0"><?= View::escape($field['label']) ?></h2>
                        <span class="vf-section-icon"><i class="fa-solid fa-heading"></i></span>
                    </div>
                    <?php if ($field['help_text']): ?>
                        <p class="vf-section-desc text-muted mt-1 mb-0"><?= nl2br(View::escape($field['help_text'])) ?></p>
                    <?php endif; ?>
                    <div class="vf-section-children">
                        <?php while ($i < $n && (int) $fields[$i]['parent_id'] === (int) $field['id']): ?>
                            <?php $renderCard($fields[$i]); $i++; ?>
                        <?php endwhile; ?>
                    </div>
                </section>
            <?php else: ?>
                <?php if ($hasPages && !$blockOpen) { $emitPageHeader(null); } ?>
                <?php $renderCard($field); ?>
            <?php endif; ?>
        <?php endwhile; ?>
        <?php if ($blockOpen) { $emitPageFooter(); } ?>

        <?php if (!$hasPages): ?>
        <div class="vf-submit-row flip-in">
            <?php if (!empty($turnstile['enabled'])): ?>
            <!-- B2 — Turnstile: el widget inyecta su token en cf-turnstile-response
                 al resolver. El script y el iframe de Cloudflare solo se cargan
                 cuando el formulario lo pide (la CSP de esta página ya lo permite). -->
            <div class="cf-turnstile mb-3 d-flex justify-content-center" data-sitekey="<?= View::escape((string) ($turnstile['site_key'] ?? '')) ?>"></div>
            <?php endif; ?>
            <button type="submit" id="vfSubmit" class="vf-submit">
                <span class="vf-submit-label"><i class="fa-solid fa-paper-plane me-2"></i><?= $t('ui.enviar') ?></span>
            </button>
        </div>
        <?php endif; ?>
    </form>
</div>

<div id="vfAutosave" class="vf-autosave" role="status" aria-live="polite"></div>

<?php if (!empty($turnstile['enabled'])): ?>
<script src="https://challenges.cloudflare.com/turnstile/v0/api.js" defer async></script>
<?php endif; ?>
<script src="<?= View::asset('js/bootstrap.bundle.min.js') ?>"></script>
<script src="<?= View::asset('js/fill.js') ?>"></script>
</body>
</html>