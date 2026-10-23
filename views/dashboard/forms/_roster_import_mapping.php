<?php

declare(strict_types=1);

use App\Core\Csrf;
use App\Core\View;

$importMapping = $importMapping ?? [];
$cabeceras = is_array($importMapping['cabeceras'] ?? null) ? $importMapping['cabeceras'] : [];
$sugerencia = is_array($importMapping['sugerencia'] ?? null) ? $importMapping['sugerencia'] : [];
$token = (string) ($importMapping['token'] ?? '');
$total = (int) ($importMapping['total'] ?? 0);
$modo = (string) ($importMapping['modo'] ?? 'fusionar');
?>
<form method="post" action="/forms/<?= (int) $formId ?>/participants/import/confirm" class="mt-2" data-vf-async="slots" data-vf-error="rosterImportMapError">
    <?= Csrf::field() ?>
    <input type="hidden" name="roster_import_token" value="<?= View::escape($token) ?>">
    <input type="hidden" name="rosterQ" value="<?= View::escape((string) ($roster['q'] ?? '')) ?>">
    <input type="hidden" name="rosterPage" value="<?= (int) ($roster['page'] ?? 1) ?>">
    <input type="hidden" name="rosterSort" value="<?= View::escape((string) ($roster['sort'] ?? 'cedula')) ?>">

    <p class="small text-muted mb-2">Mapea las columnas del archivo. Las columnas fijas son Cédula, Nombre completo, Correo y Teléfono.</p>

    <div class="row g-2 mb-2">
        <div class="col-12 col-md-6">
            <label class="form-label small mb-1" for="mapIdent">Cédula</label>
            <select class="form-select form-select-sm" id="mapIdent" name="map_identificador">
                <option value="">Seleccionar...</option>
                <?php foreach ($cabeceras as $i => $c): ?>
                    <option value="<?= $i ?>" <?= ($sugerencia['identificador'] === $i) ? 'selected' : '' ?>><?= View::escape((string) $c) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-12 col-md-6">
            <label class="form-label small mb-1" for="mapNombre">Nombre completo</label>
            <select class="form-select form-select-sm" id="mapNombre" name="map_nombre">
                <option value="">No importar</option>
                <?php foreach ($cabeceras as $i => $c): ?>
                    <option value="<?= $i ?>" <?= ($sugerencia['nombre'] === $i) ? 'selected' : '' ?>><?= View::escape((string) $c) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-12 col-md-6">
            <label class="form-label small mb-1" for="mapCorreo">Correo</label>
            <select class="form-select form-select-sm" id="mapCorreo" name="map_correo">
                <option value="">No importar</option>
                <?php foreach ($cabeceras as $i => $c): ?>
                    <option value="<?= $i ?>" <?= ($sugerencia['correo'] === $i) ? 'selected' : '' ?>><?= View::escape((string) $c) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-12 col-md-6">
            <label class="form-label small mb-1" for="mapTelefono">Teléfono</label>
            <select class="form-select form-select-sm" id="mapTelefono" name="map_telefono">
                <option value="">No importar</option>
                <?php foreach ($cabeceras as $i => $c): ?>
                    <option value="<?= $i ?>" <?= ($sugerencia['telefono'] === $i) ? 'selected' : '' ?>><?= View::escape((string) $c) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
    </div>

    <div class="mb-2">
        <div class="small fw-semibold mb-1">Otras columnas (atributos)</div>
        <div class="d-flex flex-column gap-1">
            <?php foreach ($cabeceras as $i => $c): 
                $n = strtolower((string) $c);
                $esFijo = in_array($n, ['cedula','documento','nombre','nombres','nombre completo','correo','email','telefono','telefonico','tel','movil','celular','codigo'], true);
            ?>
                <?php if (!$esFijo): ?>
                    <label class="form-check small mb-0">
                        <input class="form-check-input" type="checkbox" name="map_ignorar[]" value="<?= $i ?>">
                        <span class="form-check-label">Ignorar «<?= View::escape((string) $c) ?>» (se guardará como atributo si no se ignora)</span>
                    </label>
                <?php endif; ?>
            <?php endforeach; ?>
        </div>
    </div>

    <input type="hidden" name="modo" value="<?= View::escape($modo) ?>">

    <div class="d-flex gap-2">
        <button type="submit" class="btn btn-primary app-form-cta">
            <i class="fa-solid fa-check me-1"></i>Importar <?= $total ?> fila(s)
        </button>
    </div>
    <p class="small mb-0 mt-1 d-none text-danger fw-semibold" id="rosterImportMapError" role="alert"></p>
</form>

<form method="post" action="/forms/<?= (int) $formId ?>/participants/import/cancel" class="mt-2" data-vf-async="slots">
    <?= Csrf::field() ?>
    <input type="hidden" name="roster_import_token" value="<?= View::escape($token) ?>">
    <input type="hidden" name="rosterQ" value="<?= View::escape((string) ($roster['q'] ?? '')) ?>">
    <input type="hidden" name="rosterPage" value="<?= (int) ($roster['page'] ?? 1) ?>">
    <input type="hidden" name="rosterSort" value="<?= View::escape((string) ($roster['sort'] ?? 'cedula')) ?>">
    <button type="submit" class="btn btn-link btn-sm text-muted p-0">Cancelar importación</button>
</form>
