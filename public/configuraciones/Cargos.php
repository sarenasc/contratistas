<?php
require_once __DIR__ . '/../_bootstrap.php';
require_once __DIR__ . '/../../vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\IOFactory;

$flash_error = null;
$flash_ok    = null;

// Agregar un nuevo registro
if (isset($_POST['guardar'])) {
    $cargo    = strtoupper(trim($_POST['cargo'] ?? ''));
    $id_mo    = (int)($_POST['id_mo'] ?? 0) ?: null;
    $cod_fact = trim($_POST['cod_fact'] ?? '');
    if ($cargo === '') {
        $flash_error = "El nombre de la labor no puede estar vacío.";
    } else {
        $sql = "INSERT INTO [dbo].[Dota_Cargo] ([cargo],[id_mo],[cod_fact],[fecha_ingreso])
                VALUES (?, ?, ?, GETDATE())";
        $r = sqlsrv_query($conn, $sql, [$cargo, $id_mo, $cod_fact]);
        if ($r === false) $flash_error = "Error al guardar la labor.";
        else $flash_ok = "Labor guardada correctamente.";
    }
}

// Editar un registro existente
if (isset($_POST['editar'])) {
    $id_cargo = (int)$_POST['id_cargo'];
    $cargo    = strtoupper(trim($_POST['cargo'] ?? ''));
    $id_mo    = (int)($_POST['id_mo'] ?? 0) ?: null;
    $cod_fact = trim($_POST['cod_fact'] ?? '');
    if ($cargo === '') {
        $flash_error = "El nombre de la labor no puede estar vacío.";
    } else {
        $sql = "UPDATE [dbo].[Dota_Cargo] SET cargo = ?, id_mo = ?, cod_fact = ? WHERE id_cargo = ?";
        $r = sqlsrv_query($conn, $sql, [$cargo, $id_mo, $cod_fact, $id_cargo]);
        if ($r === false) $flash_error = "Error al actualizar la labor.";
        else $flash_ok = "Labor actualizada correctamente.";
    }
}

// Eliminar un registro
if (isset($_POST['eliminar'])) {
    $id_cargo = (int)$_POST['id_cargo'];
    $sql = "DELETE FROM [dbo].[Dota_Cargo] WHERE id_cargo = ?";
    $r = sqlsrv_query($conn, $sql, [$id_cargo]);
    if ($r === false) $flash_error = "Error al eliminar la labor.";
    else $flash_ok = "Labor eliminada.";
}

// Carga masiva desde Excel
if (isset($_POST['cargar_excel']) && isset($_FILES['archivo_excel']) && $_FILES['archivo_excel']['error'] === UPLOAD_ERR_OK) {
    try {
        $tmp = $_FILES['archivo_excel']['tmp_name'];
        $ext = strtolower(pathinfo($_FILES['archivo_excel']['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, ['xlsx', 'xls'], true)) {
            throw new RuntimeException("Formato no permitido. Use .xlsx o .xls");
        }

        // Cargar hoja "TIPO CARGO" preferentemente; si no existe, usar la activa
        $reader = IOFactory::createReaderForFile($tmp);
        $reader->setReadDataOnly(true);
        $wsNames   = $reader->listWorksheetNames($tmp);
        $sheetName = in_array('TIPO CARGO', $wsNames, true) ? 'TIPO CARGO' : null;
        if ($sheetName) $reader->setLoadSheetsOnly([$sheetName]);

        $spreadsheet = $reader->load($tmp);
        $sheet = $spreadsheet->getActiveSheet();
        $rows  = $sheet->toArray(null, true, false, false);

        if (empty($rows)) throw new RuntimeException("El archivo está vacío.");

        // Detectar encabezados en fila 1 — elimina todo whitespace (incluye no-rompibles)
        $headers  = array_map(fn($h) => preg_replace('/[\s\x{00A0}]+/u', '', mb_strtolower(trim((string)$h), 'UTF-8')), $rows[0]);
        $iCargo   = array_search('cargo',    $headers);
        $iIdMo    = array_search('id_mo',    $headers);
        $iCodFact = array_search('cod_fact', $headers);

        if ($iCargo === false) throw new RuntimeException(
            "Columna 'cargo' no encontrada. Encabezados detectados: " . implode(', ', $headers)
            . ($sheetName ? " (hoja: {$sheetName})" : " (hoja activa)")
        );

        // Verificar si id_mo existe en la tabla
        $chkIdMo = sqlsrv_query($conn, "SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_NAME='Dota_Cargo' AND COLUMN_NAME='id_mo'");
        $tiene_id_mo = $chkIdMo && sqlsrv_fetch($chkIdMo);

        $sql_ins = "INSERT INTO [dbo].[Dota_Cargo] ([cargo],[id_mo],[cod_fact],[fecha_ingreso]) VALUES (?,?,?,GETDATE())";

        $insertados = 0;
        $vacios     = 0;
        $errores    = 0;

        foreach (array_slice($rows, 1) as $row) {
            $cargo    = strtoupper(trim((string)($row[$iCargo] ?? '')));
            $cod_fact = $iCodFact !== false ? trim((string)($row[$iCodFact] ?? '')) : '';

            if ($cargo === '') { $vacios++; continue; }

            // id_mo: viene como número directo en la hoja TIPO CARGO (1 o 2)
            $id_mo = null;
            if ($iIdMo !== false) {
                $rawMo = trim((string)($row[$iIdMo] ?? ''));
                if (is_numeric($rawMo) && (int)$rawMo > 0) $id_mo = (int)$rawMo;
            }

            $params = [$cargo, $id_mo, $cod_fact];

            $res = sqlsrv_query($conn, $sql_ins, $params);
            if ($res === false) { $errores++; continue; }
            $insertados++;
        }

        $spreadsheet->disconnectWorksheets();
        unset($spreadsheet);

        $msg = "Carga completada desde hoja <b>" . htmlspecialchars($sheetName ?? 'activa') . "</b>:";
        $msg .= " <b>{$insertados}</b> labores ingresadas";
        if ($vacios)  $msg .= ", <b>{$vacios}</b> filas vacías ignoradas";
        if ($errores) $msg .= ", <b>{$errores}</b> con error";
        $flash_ok = $msg;

    } catch (Throwable $e) {
        $flash_error = "Error al procesar el archivo: " . htmlspecialchars($e->getMessage());
    }
}

// Catálogo de tipos MO
$lista_mo    = [];
$lista_mo_by = []; // id_mo => nombre_mo
$q_mo = sqlsrv_query($conn, "SELECT id_mo, nombre_mo, abrev FROM [dbo].[dota_tipo_mo] ORDER BY nombre_mo");
if ($q_mo) {
    while ($r = sqlsrv_fetch_array($q_mo, SQLSRV_FETCH_ASSOC)) {
        $lista_mo[] = $r;
        $lista_mo_by[(int)$r['id_mo']] = $r['nombre_mo'];
    }
}

// Paginación y búsqueda
$registros_por_pagina = 10;
$pagina_actual = isset($_GET['pagina']) ? max(1, (int)$_GET['pagina']) : 1;
$busqueda      = isset($_GET['busqueda']) ? trim($_GET['busqueda']) : '';
$filtro        = $busqueda ? "WHERE cargo LIKE ?" : '';
$search_params = $busqueda ? ["%$busqueda%"] : null;

$q_total = sqlsrv_query($conn, "SELECT COUNT(*) AS total FROM [dbo].[Dota_Cargo] $filtro", $search_params);
$total_registros = sqlsrv_fetch_array($q_total, SQLSRV_FETCH_ASSOC)['total'];
$total_paginas   = max(1, (int)ceil($total_registros / $registros_por_pagina));
$offset          = ($pagina_actual - 1) * $registros_por_pagina;

// Verificar si la columna fecha_ingreso ya existe
$col_check = sqlsrv_query($conn,
    "SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
     WHERE TABLE_NAME = 'Dota_Cargo' AND COLUMN_NAME = 'fecha_ingreso'");
$tiene_fecha = $col_check && sqlsrv_fetch_array($col_check, SQLSRV_FETCH_ASSOC);

$select_cols = $tiene_fecha
    ? "[id_cargo],[cargo],[id_mo],[cod_fact],[fecha_ingreso]"
    : "[id_cargo],[cargo],[id_mo],[cod_fact]";

$query = sqlsrv_query(
    $conn,
    "SELECT $select_cols
     FROM [dbo].[Dota_Cargo] $filtro
     ORDER BY cargo
     OFFSET $offset ROWS FETCH NEXT $registros_por_pagina ROWS ONLY",
    $search_params
);

if ($query === false) {
    $flash_error = 'Error al consultar labores: ' . htmlspecialchars(sqlsrv_errors()[0]['message'] ?? 'desconocido');
}

$title = "Labores";
include __DIR__ . '/../partials/header.php';
include __DIR__ . '/../partials/navbar_wrapper.php';
?>

<main class="container py-4">

<?php if ($flash_error): ?>
  <div class="alert alert-danger"><?= $flash_error ?></div>
<?php endif; ?>
<?php if ($flash_ok): ?>
  <div class="alert alert-success"><?= $flash_ok ?></div>
<?php endif; ?>

<div class="text-center my-4">
    <h1 class="display-4">Gestión de Labores</h1>
</div>

<!-- Formulario agregar -->
<div class="card mb-3">
    <div class="card-header">Agregar Nueva Labor</div>
    <div class="card-body">
        <form method="POST">
            <div class="row g-2">
                <div class="col-md-4">
                    <label class="form-label">Labor</label>
                    <input type="text" class="form-control" name="cargo" placeholder="Nombre de la labor" required>
                </div>
                <div class="col-md-4">
                    <label class="form-label">Tipo MO</label>
                    <select class="form-select" name="id_mo" required>
                        <option value="">-- Seleccione --</option>
                        <?php foreach ($lista_mo as $mo): ?>
                        <option value="<?= (int)$mo['id_mo'] ?>"><?= htmlspecialchars($mo['nombre_mo']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label">Código Facturador</label>
                    <input type="text" class="form-control" name="cod_fact" placeholder="Código">
                </div>
            </div>
            <button type="submit" name="guardar" class="btn btn-primary mt-3">Guardar</button>
        </form>
    </div>
</div>

<!-- Carga masiva Excel -->
<div class="card mb-4">
    <div class="card-header d-flex justify-content-between align-items-center">
        <span>Carga Masiva desde Excel</span>
        <button class="btn btn-sm btn-outline-secondary" type="button"
                data-bs-toggle="collapse" data-bs-target="#panelExcel">
            Mostrar / Ocultar
        </button>
    </div>
    <div class="collapse" id="panelExcel">
        <div class="card-body">
            <p class="text-muted small mb-2">
                Sube el archivo <strong>Labores.xlsx</strong> — se leerá automáticamente la hoja <code>TIPO CARGO</code>.<br>
                Columna obligatoria: <code>cargo</code>. Columnas opcionales: <code>id_mo</code> (número 1 o 2), <code>cod_fact</code>.<br>
                Cada fila no vacía se inserta como un registro nuevo (se permiten cargos repetidos).
            </p>

            <?php if (!empty($lista_mo)): ?>
            <p class="small fw-semibold mb-1">IDs disponibles para <code>id_mo</code>:</p>
            <table class="table table-sm table-bordered w-auto mb-3" style="font-size:.82rem">
                <thead class="table-secondary">
                    <tr><th>id_mo</th><th>Nombre</th><th>Abrev.</th></tr>
                </thead>
                <tbody>
                    <?php foreach ($lista_mo as $mo): ?>
                    <tr>
                        <td><?= (int)$mo['id_mo'] ?></td>
                        <td><?= htmlspecialchars($mo['nombre_mo']) ?></td>
                        <td><?= htmlspecialchars($mo['abrev']) ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <?php else: ?>
            <div class="alert alert-warning small py-2">No hay tipos de MO registrados aún. Agrégalos primero en <a href="tipo_mo.php">Tipo MO</a>.</div>
            <?php endif; ?>

            <form method="POST" enctype="multipart/form-data" class="d-flex gap-2 align-items-end">
                <div>
                    <label class="form-label mb-1">Archivo Excel</label>
                    <input type="file" name="archivo_excel" class="form-control" accept=".xlsx,.xls" required>
                </div>
                <div>
                    <button type="submit" name="cargar_excel" class="btn btn-success">Cargar</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Búsqueda -->
<div class="mb-3">
    <form method="GET">
        <div class="input-group">
            <input type="text" name="busqueda" class="form-control"
                   placeholder="Buscar por labor" value="<?= htmlspecialchars($busqueda) ?>">
            <button class="btn btn-primary" type="submit">Buscar</button>
            <?php if ($busqueda): ?>
            <a href="Cargos.php" class="btn btn-outline-secondary">Limpiar</a>
            <?php endif; ?>
        </div>
    </form>
</div>

<!-- Form oculto para editar / eliminar -->
<form id="form-accion" method="POST">
    <input type="hidden" name="id_cargo" id="f-id_cargo">
    <input type="hidden" name="cargo"    id="f-cargo">
    <input type="hidden" name="id_mo"    id="f-id_mo">
    <input type="hidden" name="cod_fact" id="f-cod_fact">
    <button type="submit" name="editar"   id="f-btn-editar"   style="display:none"></button>
    <button type="submit" name="eliminar" id="f-btn-eliminar" style="display:none"></button>
</form>

<!-- Tabla -->
<h2 class="text-center">Lista de Labores</h2>
<div class="table-responsive">
    <table class="table table-bordered table-hover mt-3">
        <thead class="table-dark">
            <tr>
                <th>ID</th>
                <th>Labor</th>
                <th>Tipo MO</th>
                <th>Cód. Facturador</th>
                <?php if ($tiene_fecha): ?><th>Fecha Ingreso</th><?php endif; ?>
                <th>Acciones</th>
            </tr>
        </thead>
        <tbody>
            <?php while ($query && $row = sqlsrv_fetch_array($query, SQLSRV_FETCH_ASSOC)):
                $fecha    = isset($row['fecha_ingreso']) && $row['fecha_ingreso'] instanceof DateTime
                          ? $row['fecha_ingreso']->format('d/m/Y H:i') : '—';
                $curr_mo  = (int)($row['id_mo'] ?? 0);
            ?>
            <tr>
                <td><?= (int)$row['id_cargo'] ?></td>
                <td><input type="text" class="form-control form-control-sm inp-cargo"
                           value="<?= htmlspecialchars($row['cargo']) ?>"></td>
                <td>
                    <select class="form-select form-select-sm inp-id_mo">
                        <option value="0">--</option>
                        <?php foreach ($lista_mo as $mo): ?>
                        <option value="<?= (int)$mo['id_mo'] ?>"
                            <?= $curr_mo === (int)$mo['id_mo'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($mo['abrev']) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </td>
                <td><input type="text" class="form-control form-control-sm inp-cod_fact"
                           value="<?= htmlspecialchars($row['cod_fact'] ?? '') ?>"></td>
                <?php if ($tiene_fecha): ?>
                <td class="text-nowrap text-muted small"><?= $fecha ?></td>
                <?php endif; ?>
                <td>
                    <button type="button" class="btn btn-warning btn-sm"
                            onclick="accionCargo('editar', <?= (int)$row['id_cargo'] ?>, this)">Editar</button>
                    <button type="button" class="btn btn-danger btn-sm"
                            onclick="accionCargo('eliminar', <?= (int)$row['id_cargo'] ?>, this)">Eliminar</button>
                </td>
            </tr>
            <?php endwhile; ?>
        </tbody>
    </table>
</div>

<!-- Paginación -->
<nav>
    <ul class="pagination justify-content-center">
        <?php if ($pagina_actual > 1): ?>
        <li class="page-item">
            <a class="page-link" href="?pagina=<?= $pagina_actual - 1 ?>&busqueda=<?= urlencode($busqueda) ?>">Anterior</a>
        </li>
        <?php endif; ?>

        <?php for ($i = 1; $i <= $total_paginas; $i++): ?>
        <li class="page-item <?= $i === $pagina_actual ? 'active' : '' ?>">
            <a class="page-link" href="?pagina=<?= $i ?>&busqueda=<?= urlencode($busqueda) ?>"><?= $i ?></a>
        </li>
        <?php endfor; ?>

        <?php if ($pagina_actual < $total_paginas): ?>
        <li class="page-item">
            <a class="page-link" href="?pagina=<?= $pagina_actual + 1 ?>&busqueda=<?= urlencode($busqueda) ?>">Siguiente</a>
        </li>
        <?php endif; ?>
    </ul>
</nav>

<script>
function accionCargo(tipo, id, btn) {
    var tr = btn.closest('tr');
    document.getElementById('f-id_cargo').value = id;
    document.getElementById('f-cargo').value    = tr.querySelector('.inp-cargo').value;
    document.getElementById('f-id_mo').value    = tr.querySelector('.inp-id_mo').value;
    document.getElementById('f-cod_fact').value = tr.querySelector('.inp-cod_fact').value;
    if (tipo === 'eliminar' && !confirm('¿Eliminar esta labor?')) return;
    document.getElementById('f-btn-' + tipo).click();
}
</script>

</main>

<?php
include __DIR__ . '/../partials/footer.php';
sqlsrv_close($conn);
?>
