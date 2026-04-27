<?php
require_once __DIR__ . '/../_bootstrap.php';
if (!puede_modulo('reloj')) { header('Location: ' . BASE_URL . '/Inicio.php'); exit; }

require_once __DIR__ . '/../../scripts/reloj/_rutas.php';
require_once __DIR__ . '/../../vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\IOFactory;

$flash_ok = $flash_error = null;

if (!function_exists('ensure_reloj_turno_column')) {
    function ensure_reloj_turno_column($conn): void {
        $sql = "
IF COL_LENGTH('dbo.reloj_trabajador', 'id_turno') IS NULL
BEGIN
    ALTER TABLE dbo.reloj_trabajador ADD id_turno INT NULL;
END
";
        sqlsrv_query($conn, $sql);
    }
}

ensure_reloj_turno_column($conn);

if (!function_exists('ensure_reloj_trabajador_nullable')) {
    function ensure_reloj_trabajador_nullable($conn): void {
        // Permite guardar trabajadores sin RUT ni número de badge
        sqlsrv_query($conn, "
            IF COL_LENGTH('dbo.reloj_trabajador','id_numero') IS NOT NULL
               AND COLUMNPROPERTY(OBJECT_ID('dbo.reloj_trabajador'),'id_numero','AllowsNull') = 0
            BEGIN
                ALTER TABLE dbo.reloj_trabajador ALTER COLUMN id_numero INT NULL;
            END
            IF COL_LENGTH('dbo.reloj_trabajador','rut') IS NOT NULL
               AND COLUMNPROPERTY(OBJECT_ID('dbo.reloj_trabajador'),'rut','AllowsNull') = 0
            BEGIN
                ALTER TABLE dbo.reloj_trabajador ALTER COLUMN rut NVARCHAR(20) NULL;
            END
        ");
    }
}
ensure_reloj_trabajador_nullable($conn);

if (!function_exists('ensure_huella_cache_table')) {
    function ensure_huella_cache_table($conn): void {
        sqlsrv_query($conn, "
IF OBJECT_ID('dbo.reloj_huella_cache', 'U') IS NULL
BEGIN
    CREATE TABLE dbo.reloj_huella_cache (
        id INT IDENTITY(1,1) NOT NULL PRIMARY KEY,
        id_numero NVARCHAR(50) NOT NULL,
        reloj_id INT NOT NULL,
        reloj_nombre NVARCHAR(150) NOT NULL,
        ip NVARCHAR(50) NOT NULL,
        uid INT NOT NULL,
        fid INT NOT NULL,
        template_bytes INT NOT NULL,
        fecha_sync DATETIME NOT NULL DEFAULT GETDATE()
    );
    CREATE INDEX IX_reloj_huella_cache_id_numero ON dbo.reloj_huella_cache(id_numero);
    CREATE INDEX IX_reloj_huella_cache_reloj ON dbo.reloj_huella_cache(reloj_id, id_numero);
END
");
    }
}
ensure_huella_cache_table($conn);

const RELOJ_IMPORT_SESSION_KEY = 'reloj_worker_import';
const RELOJ_IMPORT_STATE_KEY = 'reloj_worker_import_state';
const RELOJ_IMPORT_PROGRESS_KEY = 'reloj_worker_import_progress';

function worker_norm_txt($s): string {
    $s = trim((string)$s);
    $s = preg_replace('/\s+/', ' ', $s);
    return mb_strtoupper($s, 'UTF-8');
}

function worker_extract_rut_digits($rut): int {
    return (int)preg_replace('/[^0-9]/', '', (string)$rut);
}

function worker_suggest_id(string $valueFromExcel, array $catalog): ?int {
    $needle = worker_norm_txt($valueFromExcel);
    if ($needle === '') return null;
    foreach ($catalog as $row) {
        if (worker_norm_txt($row['name']) === $needle) return (int)$row['id'];
    }
    return null;
}

function worker_import_dir(): string {
    $dir = __DIR__ . '/../../storage/reloj_import/';
    if (!is_dir($dir)) mkdir($dir, 0777, true);
    return $dir;
}

// ── Cargar áreas disponibles ──────────────────────────────────────
$areas_q = sqlsrv_query($conn, "SELECT id_area, Area FROM dbo.Area ORDER BY Area");
$areas   = [];
while ($a = sqlsrv_fetch_array($areas_q, SQLSRV_FETCH_ASSOC))
    $areas[$a['id_area']] = $a['Area'];
$areas_catalog = array_map(fn($id, $name) => ['id' => (int)$id, 'name' => (string)$name], array_keys($areas), array_values($areas));

$turnos_q = sqlsrv_query($conn, "SELECT id, nombre_turno FROM dbo.dota_turno ORDER BY nombre_turno");
$turnos   = [];
while ($t = sqlsrv_fetch_array($turnos_q, SQLSRV_FETCH_ASSOC))
    $turnos[$t['id']] = $t['nombre_turno'];
$turnos_catalog = array_map(fn($id, $name) => ['id' => (int)$id, 'name' => (string)$name], array_keys($turnos), array_values($turnos));

$contratistas_q = sqlsrv_query($conn, "SELECT id, nombre FROM dbo.dota_contratista ORDER BY nombre");
$contratistas   = [];
while ($c = sqlsrv_fetch_array($contratistas_q, SQLSRV_FETCH_ASSOC))
    $contratistas[$c['id']] = $c['nombre'];
$contratistas_catalog = array_map(fn($id, $name) => ['id' => (int)$id, 'name' => (string)$name], array_keys($contratistas), array_values($contratistas));

$cargos_q = sqlsrv_query($conn, "SELECT id_cargo, cargo FROM dbo.Dota_Cargo ORDER BY cargo");
$cargos   = [];
while ($c = sqlsrv_fetch_array($cargos_q, SQLSRV_FETCH_ASSOC))
    $cargos[$c['id_cargo']] = $c['cargo'];
$cargos_catalog = array_map(fn($id, $name) => ['id' => (int)$id, 'name' => (string)$name], array_keys($cargos), array_values($cargos));

$saved_maps = [];
$chkMapa = sqlsrv_query($conn, "SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA='dbo' AND TABLE_NAME='dota_asistencia_mapa'");
if ($chkMapa && sqlsrv_fetch($chkMapa)) {
    $smStmt = sqlsrv_query($conn, "SELECT tipo, valor_excel, id_sistema FROM dbo.dota_asistencia_mapa");
    if ($smStmt) {
        while ($sm = sqlsrv_fetch_array($smStmt, SQLSRV_FETCH_ASSOC)) {
            $saved_maps[$sm['tipo']][$sm['valor_excel']] = (int)$sm['id_sistema'];
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['accion'] ?? '') === 'reset_excel_import') {
    $data = $_SESSION[RELOJ_IMPORT_SESSION_KEY] ?? null;
    $state = $_SESSION[RELOJ_IMPORT_STATE_KEY] ?? null;
    $possibleFiles = [
        $data['file'] ?? null,
        $state['file'] ?? null,
        $state['ruta'] ?? null,
    ];
    foreach ($possibleFiles as $file) {
        if (!empty($file) && is_string($file) && file_exists($file)) {
            @unlink($file);
        }
    }
    unset($_SESSION[RELOJ_IMPORT_SESSION_KEY]);
    unset($_SESSION[RELOJ_IMPORT_STATE_KEY], $_SESSION[RELOJ_IMPORT_PROGRESS_KEY]);
    $flash_ok = "Importación Excel reiniciada.";
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['accion'] ?? '') === 'excel_import_save') {
    $importData = $_SESSION[RELOJ_IMPORT_SESSION_KEY] ?? null;
    if (!$importData || empty($importData['people'])) {
        $flash_error = "No hay datos del Excel en sesión. Vuelve a cargar el archivo.";
    } else {
        $map_area      = $_POST['map_area'] ?? [];
        $map_empleador = $_POST['map_empleador'] ?? [];
        $map_cargo     = $_POST['map_cargo'] ?? [];
        $map_turno     = $_POST['map_turno'] ?? [];

        try {
            foreach ($importData['uniques']['area'] as $val) if (!empty($val) && empty($map_area[worker_norm_txt($val)])) throw new RuntimeException("Falta mapear Área: $val");
            foreach ($importData['uniques']['empleador'] as $val) if (!empty($val) && empty($map_empleador[worker_norm_txt($val)])) throw new RuntimeException("Falta mapear Empleador: $val");
            foreach ($importData['uniques']['cargo'] as $val) if (!empty($val) && empty($map_cargo[worker_norm_txt($val)])) throw new RuntimeException("Falta mapear Cargo: $val");
            foreach ($importData['uniques']['turno'] as $val) if (!empty($val) && empty($map_turno[worker_norm_txt($val)])) throw new RuntimeException("Falta mapear Turno: $val");

            $created = 0;
            $updated = 0;
            $skipped = [];

            foreach ($importData['people'] as $person) {
                $areaId = (int)($map_area[worker_norm_txt($person['area_excel'] ?? '')] ?? 0) ?: null;
                $emplId = (int)($map_empleador[worker_norm_txt($person['empl_excel'] ?? '')] ?? 0) ?: null;
                $cargoId = (int)($map_cargo[worker_norm_txt($person['cargo_excel'] ?? '')] ?? 0) ?: null;
                $turnoId = (int)($map_turno[worker_norm_txt($person['turno_excel'] ?? '')] ?? 0) ?: null;
                $rutDigits = (int)($person['rut_digits'] ?? 0);
                $existing = $person['existing'] ?? null;

                if ($existing) {
                    $fields = [];
                    $params = [];
                    if (empty($existing['rut']) && !empty($person['rut'])) {
                        $fields[] = "rut = ?";
                        $params[] = $person['rut'];
                    }
                    if (($existing['id_numero'] ?? 0) <= 0 && $rutDigits > 0) {
                        $fields[] = "id_numero = ?";
                        $params[] = $rutDigits;
                    }
                    if (($existing['id_area'] ?? 0) <= 0 && $areaId) {
                        $fields[] = "id_area = ?";
                        $params[] = $areaId;
                    }
                    if (($existing['id_contratista'] ?? 0) <= 0 && $emplId) {
                        $fields[] = "id_contratista = ?";
                        $params[] = $emplId;
                    }
                    if (($existing['id_cargo'] ?? 0) <= 0 && $cargoId) {
                        $fields[] = "id_cargo = ?";
                        $params[] = $cargoId;
                    }
                    if (($existing['id_turno'] ?? 0) <= 0 && $turnoId) {
                        $fields[] = "id_turno = ?";
                        $params[] = $turnoId;
                    }
                    if ($fields) {
                        $params[] = (int)$existing['id'];
                        sqlsrv_query($conn, "UPDATE dbo.reloj_trabajador SET " . implode(', ', $fields) . " WHERE id = ?", $params);
                        $updated++;
                    }
                } else {
                    $ins = sqlsrv_query(
                        $conn,
                        "INSERT INTO dbo.reloj_trabajador (id_numero, rut, nombre, id_area, id_contratista, id_cargo, id_turno, activo)
                         VALUES (?, ?, ?, ?, ?, ?, ?, 1)",
                        [$rutDigits > 0 ? $rutDigits : null, $person['rut'] ?: null, $person['nombre'], $areaId, $emplId, $cargoId, $turnoId]
                    );
                    if ($ins) {
                        $created++;
                    } else {
                        $skipped[] = $person['nombre'];
                    }
                }
            }

            $msg = "Importación completada. Nuevos: {$created}. Actualizados: {$updated}.";
            if ($skipped) {
                $msg .= " Omitidos: " . count($skipped) . " (" . implode(', ', array_slice($skipped, 0, 5));
                if (count($skipped) > 5) $msg .= ', ...';
                $msg .= ").";
            }
            $flash_ok = $msg;
        } catch (Throwable $e) {
            $flash_error = $e->getMessage();
        }
    }
}

$workerImport = $_SESSION[RELOJ_IMPORT_SESSION_KEY] ?? null;
$workerImportProgress = $_SESSION[RELOJ_IMPORT_PROGRESS_KEY] ?? null;

// ── Nuevo trabajador ──────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['accion'] ?? '') === 'nuevo') {
    $rut    = trim($_POST['rut']    ?? '');
    $nombre = strtoupper(trim($_POST['nombre'] ?? ''));
    $id_area = (int)($_POST['id_area'] ?? 0) ?: null;
    $id_turno = (int)($_POST['id_turno'] ?? 0) ?: null;
    $id_num = $rut !== '' ? (int)preg_replace('/[^0-9]/', '', $rut) : null;

    if ($nombre === '') {
        $flash_error = "El nombre es obligatorio.";
    } elseif ($id_num !== null && $id_num === 0) {
        $flash_error = "El RUT ingresado no contiene dígitos válidos.";
    } else {
        // Solo verificar unicidad si tiene número de badge
        $duplicado = false;
        if ($id_num > 0) {
            $chk = sqlsrv_query($conn,
                "SELECT id FROM dbo.reloj_trabajador WHERE id_numero=?", [$id_num]);
            $duplicado = (bool)sqlsrv_fetch($chk);
        }
        if ($duplicado) {
            $flash_error = "Ya existe un trabajador con ese RUT.";
        } else {
            $ins = sqlsrv_query($conn,
                "INSERT INTO dbo.reloj_trabajador (id_numero,rut,nombre,id_area,id_turno) VALUES (?,?,?,?,?)",
                [$id_num ?: null, $rut ?: null, $nombre, $id_area, $id_turno]);

            if (!$ins) {
                $flash_error = "Error al guardar en base de datos.";
            } else {
                $detalle = '';
                if ($id_num > 0) {
                    // Solo registrar en relojes físicos si tiene número de badge
                    $group  = $id_area ?? '';
                    $cmd    = '"' . PYTHON_BIN . '" "' . PY_REGISTRAR . '" ' . $id_num . ' ' . escapeshellarg($nombre) . ' ' . $group . ' 2>&1';
                    $output = []; $rc = 0;
                    exec($cmd, $output, $rc);
                    $detalle = implode(' | ', array_filter($output));
                }
                $flash_ok = "Trabajador registrado." . ($detalle ? " $detalle" : '');
            }
        }
    }
}

// ── Editar trabajador ─────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['accion'] ?? '') === 'editar') {
    $id      = (int)$_POST['id'];
    $rut     = trim($_POST['rut']    ?? '');
    $nombre  = strtoupper(trim($_POST['nombre'] ?? ''));
    $id_area = (int)($_POST['id_area'] ?? 0) ?: null;
    $id_turno = (int)($_POST['id_turno'] ?? 0) ?: null;
    $id_num  = (int)preg_replace('/[^0-9]/', '', $rut);

    if ($rut === '' || $nombre === '' || $id_num === 0) {
        $flash_error = "RUT y nombre son obligatorios.";
    } else {
        $actual = null;
        $qActual = sqlsrv_query($conn,
            "SELECT id_numero, nombre, id_area, id_turno FROM dbo.reloj_trabajador WHERE id = ?", [$id]);
        if ($qActual) {
            $actual = sqlsrv_fetch_array($qActual, SQLSRV_FETCH_ASSOC);
        }

        $chk = sqlsrv_query($conn,
            "SELECT id FROM dbo.reloj_trabajador WHERE id_numero=? AND id<>?", [$id_num, $id]);
        if (sqlsrv_fetch($chk)) {
            $flash_error = "Ese RUT ya está asignado a otro trabajador.";
        } else {
            $upd = sqlsrv_query($conn,
                "UPDATE dbo.reloj_trabajador SET id_numero=?,rut=?,nombre=?,id_area=?,id_turno=? WHERE id=?",
                [$id_num, $rut, $nombre, $id_area, $id_turno, $id]);
            if (!$upd) {
                $flash_error = "Error al actualizar en base de datos.";
            } else {
                $sync_reloj = false;
                if ($actual) {
                    $nombre_actual = strtoupper(trim((string)($actual['nombre'] ?? '')));
                    $id_num_actual = (int)($actual['id_numero'] ?? 0);
                    $sync_reloj = ($id_num_actual !== $id_num) || ($nombre_actual !== $nombre);
                } else {
                    $sync_reloj = true;
                }

                if ($sync_reloj) {
                    $group  = $id_area ?? '';
                    $cmd    = '"' . PYTHON_BIN . '" "' . PY_REGISTRAR . '" ' . $id_num . ' ' . escapeshellarg($nombre) . ' ' . $group . ' 2>&1';
                    $output = []; $rc = 0;
                    exec($cmd, $output, $rc);
                    $detalle = implode(' | ', array_filter($output));
                    $flash_ok = "Trabajador actualizado en BD y relojes. $detalle";
                } else {
                    $flash_ok = "Trabajador actualizado en BD. Si cambiaste el area, usa 'Enviar areas a relojes' para reflejarlo en los dispositivos.";
                }
            }
        }
    }
}

// ── Eliminar trabajador ───────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['accion'] ?? '') === 'eliminar') {
    $id      = (int)$_POST['id'];
    $id_num  = (int)$_POST['id_numero'];

    $del = sqlsrv_query($conn, "DELETE FROM dbo.reloj_trabajador WHERE id=?", [$id]);
    if (!$del) {
        $errs = sqlsrv_errors();
        $flash_error = "Error al eliminar: " . ($errs[0]['message'] ?? 'desconocido');
    } else {
        $cmd    = '"' . PYTHON_BIN . '" "' . PY_ELIMINAR . '" ' . $id_num . ' 2>&1';
        $output = []; $rc = 0;
        exec($cmd, $output, $rc);
        $detalle = implode(' | ', array_filter($output));
        $flash_ok = "Trabajador eliminado de BD y relojes." . ($detalle ? " $detalle" : '');
    }
}

// ── Paginación ────────────────────────────────────────────────────
$buscar   = trim($_GET['q'] ?? '');
$pp_raw   = (int)($_GET['pp'] ?? 25);
$per_page = in_array($pp_raw, [10, 25, 50, 100]) ? $pp_raw : 25;
$page     = max(1, (int)($_GET['page'] ?? 1));
$offset   = ($page - 1) * $per_page;

$where_params = [];
$where = "";
if ($buscar) {
    $where = "WHERE (t.nombre LIKE ? OR t.rut LIKE ?)";
    $where_params = ["%$buscar%", "%$buscar%"];
}

// Total
$q_total = sqlsrv_query($conn,
    "SELECT COUNT(*) AS cnt FROM dbo.reloj_trabajador t $where",
    $where_params ?: null);
$r_total  = $q_total ? sqlsrv_fetch_array($q_total, SQLSRV_FETCH_ASSOC) : null;
$total    = (int)($r_total['cnt'] ?? 0);
$total_pages = max(1, (int)ceil($total / $per_page));
$page     = min($page, $total_pages);

$q_relojes_total = sqlsrv_query($conn, "SELECT COUNT(*) AS cnt FROM dbo.reloj_dispositivo WHERE activo = 1");
$r_relojes_total = $q_relojes_total ? sqlsrv_fetch_array($q_relojes_total, SQLSRV_FETCH_ASSOC) : null;
$relojes_activos_total = (int)($r_relojes_total['cnt'] ?? 0);

// Listado paginado
$rows = sqlsrv_query($conn,
    "SELECT t.id, t.id_numero, t.rut, t.nombre, t.activo, t.fecha_reg,
            t.id_area, a.Area AS nombre_area, t.id_turno, tr.nombre_turno,
            t.id_contratista, dc.nombre AS nombre_contratista,
            t.id_cargo, c.cargo AS nombre_cargo,
            ISNULL(hc.relojes_con_huella, 0) AS relojes_con_huella,
            ISNULL(hc.huellas_reloj, 0) AS huellas_reloj,
            hc.huella_reloj_sync
     FROM dbo.reloj_trabajador t
      LEFT JOIN dbo.Area          a  ON a.id_area    = t.id_area
      LEFT JOIN dbo.dota_turno    tr ON tr.id        = t.id_turno
      LEFT JOIN dbo.dota_contratista dc ON dc.id     = t.id_contratista
      LEFT JOIN dbo.Dota_Cargo    c  ON c.id_cargo   = t.id_cargo
      OUTER APPLY (
        SELECT COUNT(DISTINCT h.reloj_id) AS relojes_con_huella,
               COUNT(*) AS huellas_reloj,
               MAX(h.fecha_sync) AS huella_reloj_sync
        FROM dbo.reloj_huella_cache h
        WHERE h.id_numero = CONVERT(NVARCHAR(50), t.id_numero)
      ) hc
     $where
     ORDER BY t.nombre
     OFFSET ? ROWS FETCH NEXT ? ROWS ONLY",
     array_merge($where_params, [$offset, $per_page]));
$sql_error = null;
if ($rows === false) {
    $errs = sqlsrv_errors();
    $sql_error = $errs[0]['message'] ?? 'Error al consultar trabajadores.';
}

// Helper URL para paginacion
function pagUrl(int $p, int $pp, string $q): string {
    $params = ['page' => $p, 'pp' => $pp];
    if ($q !== '') $params['q'] = $q;
    return 'trabajadores.php?' . http_build_query($params);
}

$title = "Reloj — Trabajadores";
include __DIR__ . '/../partials/header.php';
include __DIR__ . '/../partials/navbar_wrapper.php';
?>
<main class="container py-4">

  <div class="d-flex flex-wrap justify-content-between align-items-center mb-3 gap-2">
    <h4 class="mb-0">Trabajadores del Reloj
      <span class="badge bg-secondary"><?= $total ?></span>
    </h4>
    <div class="d-flex gap-2">
      <a href="asignacion.php" class="btn btn-outline-success btn-sm">
        &#128203; Asignar a contratista
      </a>
      <button class="btn btn-outline-secondary btn-sm" onclick="pushAreas()">
        &#8593; Enviar áreas a relojes
      </button>
      <button class="btn btn-outline-secondary btn-sm" onclick="syncHuellas()">
        &#8635; Actualizar huellas relojes
      </button>
      <button class="btn btn-outline-primary btn-sm" onclick="abrirImportar()">
        &#8659; Importar desde relojes
      </button>
      <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#modalNuevo">
        + Registrar trabajador
      </button>
    </div>
  </div>

  <?php if ($flash_ok):    ?><div class="alert alert-success"><?= htmlspecialchars($flash_ok) ?></div><?php endif; ?>
  <?php if ($flash_error): ?><div class="alert alert-danger"><?= htmlspecialchars($flash_error) ?></div><?php endif; ?>

  <div class="card shadow-sm mb-4">
    <div class="card-header fw-bold">Importar trabajadores desde Excel</div>
    <div class="card-body">
      <div class="row g-3 align-items-end">
      <form id="excelImportForm" enctype="multipart/form-data" class="col-lg-10 row g-3 align-items-end">
        <div class="col-lg-7">
          <label class="form-label">Archivo Excel</label>
          <input type="file" id="archivo_excel" name="archivo_excel" class="form-control" accept=".xlsx,.xls" required>
          <div class="form-text">Lee la hoja <strong>Matriz</strong> y toma Área, Empleador, Cargo, Rut, Nombre y Turno. Agrupa por nombre.</div>
        </div>
        <div class="col-lg-3">
          <button type="submit" id="btnLeerExcel" class="btn btn-outline-primary w-100">Leer Excel</button>
        </div>
      </form>
      <?php if ($workerImport): ?>
      <form method="post" class="col-lg-2">
        <input type="hidden" name="accion" value="reset_excel_import">
        <button class="btn btn-outline-secondary w-100">Limpiar</button>
      </form>
      <?php endif; ?>
      </div>
      <div id="excelImportProgressWrap" class="mt-3 <?= $workerImportProgress ? '' : 'd-none' ?>">
        <div class="d-flex justify-content-between align-items-center mb-1">
          <small class="text-muted" id="excelImportStatus"><?= htmlspecialchars((string)($workerImportProgress['msg'] ?? 'Preparando lectura...')) ?></small>
          <small class="text-muted" id="excelImportPct"><?= (int)($workerImportProgress['pct'] ?? 0) ?>%</small>
        </div>
        <div class="progress" style="height: 22px;">
          <div
            id="excelImportBar"
            class="progress-bar progress-bar-striped <?= !empty($workerImportProgress) && empty($workerImportProgress['done']) ? 'progress-bar-animated' : '' ?>"
            role="progressbar"
            style="width: <?= (int)($workerImportProgress['pct'] ?? 0) ?>%;"
          ><?= (int)($workerImportProgress['pct'] ?? 0) ?>%</div>
        </div>
      </div>
      <div id="excelImportError" class="alert alert-danger mt-3 d-none"></div>
    </div>
  </div>

  <?php if ($workerImport): ?>
  <div class="card shadow-sm mb-4 border-primary">
    <div class="card-header bg-primary text-white fw-bold">
      Vista previa Excel agrupada
      <span class="badge bg-light text-dark ms-2"><?= (int)($workerImport['preview_count'] ?? 0) ?></span>
    </div>
    <div class="card-body">
      <form method="post">
        <input type="hidden" name="accion" value="excel_import_save">

        <div class="row g-4">
          <div class="col-lg-6">
            <h6>Mapeo de áreas</h6>
            <div class="table-responsive">
              <table class="table table-sm table-bordered align-middle">
                <thead class="table-light"><tr><th>Excel</th><th>Sistema</th></tr></thead>
                <tbody>
                <?php foreach (($workerImport['uniques']['area'] ?? []) as $val): ?>
                  <?php $key = worker_norm_txt($val); $sel = $saved_maps['area'][$val] ?? worker_suggest_id($val, $areas_catalog); ?>
                  <tr>
                    <td><?= htmlspecialchars($val) ?></td>
                    <td>
                      <select name="map_area[<?= htmlspecialchars($key) ?>]" class="form-select form-select-sm">
                        <option value="">-- Seleccionar --</option>
                        <?php foreach ($areas as $id => $name): ?>
                          <option value="<?= $id ?>" <?= (int)$sel === (int)$id ? 'selected' : '' ?>><?= htmlspecialchars($name) ?></option>
                        <?php endforeach; ?>
                      </select>
                    </td>
                  </tr>
                <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          </div>

          <div class="col-lg-6">
            <h6>Mapeo de empleadores</h6>
            <div class="table-responsive">
              <table class="table table-sm table-bordered align-middle">
                <thead class="table-light"><tr><th>Excel</th><th>Sistema</th></tr></thead>
                <tbody>
                <?php foreach (($workerImport['uniques']['empleador'] ?? []) as $val): ?>
                  <?php $key = worker_norm_txt($val); $sel = $saved_maps['empleador'][$val] ?? worker_suggest_id($val, $contratistas_catalog); ?>
                  <tr>
                    <td><?= htmlspecialchars($val) ?></td>
                    <td>
                      <select name="map_empleador[<?= htmlspecialchars($key) ?>]" class="form-select form-select-sm">
                        <option value="">-- Seleccionar --</option>
                        <?php foreach ($contratistas as $id => $name): ?>
                          <option value="<?= $id ?>" <?= (int)$sel === (int)$id ? 'selected' : '' ?>><?= htmlspecialchars($name) ?></option>
                        <?php endforeach; ?>
                      </select>
                    </td>
                  </tr>
                <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          </div>

          <div class="col-lg-6">
            <h6>Mapeo de cargos</h6>
            <div class="table-responsive">
              <table class="table table-sm table-bordered align-middle">
                <thead class="table-light"><tr><th>Excel</th><th>Sistema</th></tr></thead>
                <tbody>
                <?php foreach (($workerImport['uniques']['cargo'] ?? []) as $val): ?>
                  <?php $key = worker_norm_txt($val); $sel = $saved_maps['cargo'][$val] ?? worker_suggest_id($val, $cargos_catalog); ?>
                  <tr>
                    <td><?= htmlspecialchars($val) ?></td>
                    <td>
                      <select name="map_cargo[<?= htmlspecialchars($key) ?>]" class="form-select form-select-sm">
                        <option value="">-- Seleccionar --</option>
                        <?php foreach ($cargos as $id => $name): ?>
                          <option value="<?= $id ?>" <?= (int)$sel === (int)$id ? 'selected' : '' ?>><?= htmlspecialchars($name) ?></option>
                        <?php endforeach; ?>
                      </select>
                    </td>
                  </tr>
                <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          </div>

          <div class="col-lg-6">
            <h6>Mapeo de turnos</h6>
            <div class="table-responsive">
              <table class="table table-sm table-bordered align-middle">
                <thead class="table-light"><tr><th>Excel</th><th>Sistema</th></tr></thead>
                <tbody>
                <?php foreach (($workerImport['uniques']['turno'] ?? []) as $val): ?>
                  <?php $key = worker_norm_txt($val); $sel = $saved_maps['turno'][$val] ?? worker_suggest_id($val, $turnos_catalog); ?>
                  <tr>
                    <td><?= htmlspecialchars($val) ?></td>
                    <td>
                      <select name="map_turno[<?= htmlspecialchars($key) ?>]" class="form-select form-select-sm">
                        <option value="">-- Seleccionar --</option>
                        <?php foreach ($turnos as $id => $name): ?>
                          <option value="<?= $id ?>" <?= (int)$sel === (int)$id ? 'selected' : '' ?>><?= htmlspecialchars($name) ?></option>
                        <?php endforeach; ?>
                      </select>
                    </td>
                  </tr>
                <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          </div>
        </div>

        <h6 class="mt-4">Personas agrupadas del Excel</h6>
        <div class="table-responsive">
          <table class="table table-sm table-bordered align-middle">
            <thead class="table-dark">
              <tr>
                <th>Nombre</th><th>RUT</th><th>Área</th><th>Empleador</th><th>Cargo</th><th>Turno</th><th>Estado BD</th>
              </tr>
            </thead>
            <tbody>
            <?php foreach (($workerImport['people'] ?? []) as $person): ?>
              <tr>
                <td><?= htmlspecialchars($person['nombre']) ?></td>
                <td><?= htmlspecialchars($person['rut'] ?: '—') ?></td>
                <td><?= htmlspecialchars($person['area_excel'] ?: '—') ?></td>
                <td><?= htmlspecialchars($person['empl_excel'] ?: '—') ?></td>
                <td><?= htmlspecialchars($person['cargo_excel'] ?: '—') ?></td>
                <td><?= htmlspecialchars((string)($person['turno_excel'] ?: '—')) ?></td>
                <td>
                  <?php if (!empty($person['existing'])): ?>
                    <?php if (!empty($person['is_incomplete_existing'])): ?>
                      <span class="badge bg-warning text-dark">Existe y le faltan datos</span>
                    <?php else: ?>
                      <span class="badge bg-secondary">Ya existe completo</span>
                    <?php endif; ?>
                  <?php else: ?>
                    <span class="badge bg-success">Nuevo</span>
                  <?php endif; ?>
                </td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>

        <div class="d-flex justify-content-end mt-3">
          <button class="btn btn-primary">Guardar importación en trabajadores</button>
        </div>
      </form>
    </div>
  </div>
  <?php endif; ?>

  <!-- Barra de búsqueda + selector de filas -->
  <form method="get" class="d-flex flex-wrap gap-2 mb-3 align-items-center">
    <input type="text" name="q" class="form-control form-control-sm" style="width:220px"
           placeholder="Buscar nombre o RUT..." value="<?= htmlspecialchars($buscar) ?>">
    <select name="pp" class="form-select form-select-sm" style="width:auto"
            onchange="this.form.submit()">
      <?php foreach ([10,25,50,100] as $opt): ?>
        <option value="<?= $opt ?>" <?= $per_page===$opt ? 'selected':'' ?>><?= $opt ?> por página</option>
      <?php endforeach; ?>
    </select>
    <input type="hidden" name="page" value="1">
    <button class="btn btn-sm btn-outline-secondary">Buscar</button>
    <?php if ($buscar): ?>
      <a href="trabajadores.php?pp=<?= $per_page ?>" class="btn btn-sm btn-outline-danger">&#10005;</a>
    <?php endif; ?>
  </form>

  <?php if ($total === 0 && !$buscar): ?>
  <div class="alert alert-info">
    No hay trabajadores registrados aún.
    Usa <strong>"Importar desde relojes"</strong> para traer automáticamente
    todos los usuarios registrados en los dispositivos.
  </div>
  <?php endif; ?>

  <div class="table-responsive">
    <?php if ($sql_error): ?>
      <div class="alert alert-danger"><?= htmlspecialchars($sql_error) ?></div>
    <?php else: ?>
    <table class="table table-bordered table-hover align-middle table-sm">
      <thead class="table-dark">
        <tr>
          <th>ID Reloj</th><th>RUT</th><th>Nombre</th><th>Área</th>
          <th>Turno</th><th>Contratista</th><th>Cargo</th>
          <th>Huella</th><th>Estado</th><th>Registro</th><th class="text-center">Acciones</th>
        </tr>
      </thead>
      <tbody>
      <?php while ($t = sqlsrv_fetch_array($rows, SQLSRV_FETCH_ASSOC)): ?>
        <tr class="<?= $t['activo'] ? '' : 'table-secondary text-muted' ?>">
          <td><code><?= $t['id_numero'] ?></code></td>
          <td><?= htmlspecialchars($t['rut']) ?></td>
          <td><?= htmlspecialchars($t['nombre']) ?></td>
          <td><?= $t['nombre_area'] ? htmlspecialchars($t['nombre_area']) : '<span class="text-muted">—</span>' ?></td>
          <td><?= $t['nombre_turno'] ? '<small>'.htmlspecialchars($t['nombre_turno']).'</small>' : '<span class="text-muted">—</span>' ?></td>
          <td><?= $t['nombre_contratista'] ? '<small>'.htmlspecialchars($t['nombre_contratista']).'</small>' : '<span class="text-muted">—</span>' ?></td>
          <td><?= $t['nombre_cargo'] ? '<small>'.htmlspecialchars($t['nombre_cargo']).'</small>' : '<span class="text-muted">—</span>' ?></td>
          <td>
            <div class="d-flex flex-wrap gap-1">
              <?php
                $relojesConHuella = (int)($t['relojes_con_huella'] ?? 0);
                $huellasReloj = (int)($t['huellas_reloj'] ?? 0);
                $syncTitle = $t['huella_reloj_sync'] instanceof DateTime
                    ? 'Ultima lectura: ' . $t['huella_reloj_sync']->format('d/m/Y H:i')
                    : 'Sin lectura de huellas desde relojes';
                $badgeClass = $relojesConHuella > 0 ? 'bg-primary' : 'bg-light text-muted border';
              ?>
              <span class="badge <?= $badgeClass ?>"
                    title="<?= htmlspecialchars($syncTitle . ' - templates: ' . $huellasReloj, ENT_QUOTES) ?>">
                Reloj <?= $relojesConHuella ?>/<?= $relojes_activos_total ?>
              </span>
            </div>
          </td>
          <td><?= $t['activo']
                ? '<span class="badge bg-success">Activo</span>'
                : '<span class="badge bg-secondary">Inactivo</span>' ?></td>
          <td><?= $t['fecha_reg'] instanceof DateTime
                ? $t['fecha_reg']->format('d/m/Y') : '' ?></td>
          <td class="text-center d-flex gap-1 justify-content-center">
            <!-- Editar -->
            <button class="btn btn-sm btn-outline-secondary btn-editar"
              data-id="<?= $t['id'] ?>"
              data-id_numero="<?= $t['id_numero'] ?>"
              data-rut="<?= htmlspecialchars($t['rut'], ENT_QUOTES) ?>"
              data-nombre="<?= htmlspecialchars($t['nombre'], ENT_QUOTES) ?>"
              data-id_area="<?= $t['id_area'] ?? '' ?>"
              data-id_turno="<?= $t['id_turno'] ?? '' ?>"
              title="Editar"
              data-bs-toggle="modal" data-bs-target="#modalEditar">
              &#9998;
            </button>
            <!-- Eliminar -->
            <form method="post" class="d-inline"
              onsubmit="return confirm('¿Eliminar a <?= htmlspecialchars(addslashes($t['nombre'])) ?>?\nSe borrará de la BD y de todos los relojes.\nEsta acción no se puede deshacer.')">
              <input type="hidden" name="accion" value="eliminar">
              <input type="hidden" name="id" value="<?= $t['id'] ?>">
              <input type="hidden" name="id_numero" value="<?= $t['id_numero'] ?>">
              <button class="btn btn-sm btn-outline-danger" title="Eliminar trabajador">&#128465;</button>
            </form>
          </td>
        </tr>
      <?php endwhile; ?>
      <?php if ($total === 0 && $buscar): ?>
        <tr><td colspan="11" class="text-center text-muted py-3">Sin resultados para "<?= htmlspecialchars($buscar) ?>".</td></tr>
      <?php endif; ?>
      </tbody>
    </table>
    <?php endif; ?>
  </div>

  <!-- Paginación -->
  <?php if ($total_pages > 1): ?>
  <nav class="d-flex justify-content-between align-items-center mt-2">
    <small class="text-muted">
      Mostrando <?= $offset+1 ?>–<?= min($offset+$per_page,$total) ?> de <?= $total ?>
    </small>
    <ul class="pagination pagination-sm mb-0">
      <li class="page-item <?= $page<=1 ? 'disabled':'' ?>">
        <a class="page-link" href="<?= pagUrl($page-1,$per_page,$buscar) ?>">&#8249;</a>
      </li>
      <?php
      $rango_inicio = max(1, $page - 2);
      $rango_fin    = min($total_pages, $page + 2);
      if ($rango_inicio > 1): ?>
        <li class="page-item"><a class="page-link" href="<?= pagUrl(1,$per_page,$buscar) ?>">1</a></li>
        <?php if ($rango_inicio > 2): ?><li class="page-item disabled"><span class="page-link">…</span></li><?php endif; ?>
      <?php endif; ?>
      <?php for ($p = $rango_inicio; $p <= $rango_fin; $p++): ?>
        <li class="page-item <?= $p===$page ? 'active':'' ?>">
          <a class="page-link" href="<?= pagUrl($p,$per_page,$buscar) ?>"><?= $p ?></a>
        </li>
      <?php endfor; ?>
      <?php if ($rango_fin < $total_pages): ?>
        <?php if ($rango_fin < $total_pages - 1): ?><li class="page-item disabled"><span class="page-link">…</span></li><?php endif; ?>
        <li class="page-item"><a class="page-link" href="<?= pagUrl($total_pages,$per_page,$buscar) ?>"><?= $total_pages ?></a></li>
      <?php endif; ?>
      <li class="page-item <?= $page>=$total_pages ? 'disabled':'' ?>">
        <a class="page-link" href="<?= pagUrl($page+1,$per_page,$buscar) ?>">&#8250;</a>
      </li>
    </ul>
  </nav>
  <?php endif; ?>

</main>

<!-- Modal Nuevo -->
<div class="modal fade" id="modalNuevo" tabindex="-1">
  <div class="modal-dialog"><div class="modal-content">
    <form method="post">
      <input type="hidden" name="accion" value="nuevo">
      <div class="modal-header">
        <h5 class="modal-title">Registrar Trabajador</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <div class="mb-3">
          <label class="form-label">RUT</label>
          <input type="text" name="rut" class="form-control" placeholder="12.345.678-9" required>
          <div class="form-text">El número del RUT (sin dígito verificador) se usará como ID en el reloj.</div>
        </div>
        <div class="mb-3">
          <label class="form-label">Nombre completo</label>
          <input type="text" name="nombre" class="form-control" required maxlength="24">
        </div>
        <div class="mb-3">
          <label class="form-label">Área</label>
          <select name="id_area" class="form-select">
            <option value="">— Sin asignar —</option>
            <?php foreach ($areas as $aid => $anombre): ?>
              <option value="<?= $aid ?>"><?= htmlspecialchars($anombre) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="mb-3">
          <label class="form-label">Turno</label>
          <select name="id_turno" class="form-select">
            <option value="">— Sin asignar —</option>
            <?php foreach ($turnos as $tid => $tnombre): ?>
              <option value="<?= $tid ?>"><?= htmlspecialchars($tnombre) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>
      <div class="modal-footer">
        <button class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
        <button class="btn btn-primary">Registrar en BD y relojes</button>
      </div>
    </form>
  </div></div>
</div>

<!-- Modal Editar -->
<div class="modal fade" id="modalEditar" tabindex="-1">
  <div class="modal-dialog"><div class="modal-content">
    <form method="post">
      <input type="hidden" name="accion" value="editar">
      <input type="hidden" name="id" id="edit_id">
      <div class="modal-header">
        <h5 class="modal-title">Editar Trabajador</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <div class="mb-3">
          <label class="form-label">RUT</label>
          <input type="text" name="rut" id="edit_rut" class="form-control" required>
          <div class="form-text">Cambiar el RUT actualizará el ID en los relojes físicos.</div>
        </div>
        <div class="mb-3">
          <label class="form-label">Nombre completo</label>
          <input type="text" name="nombre" id="edit_nombre" class="form-control" required maxlength="24">
        </div>
        <div class="mb-3">
          <label class="form-label">Área</label>
          <select name="id_area" id="edit_id_area" class="form-select">
            <option value="">— Sin asignar —</option>
            <?php foreach ($areas as $aid => $anombre): ?>
              <option value="<?= $aid ?>"><?= htmlspecialchars($anombre) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="mb-3">
          <label class="form-label">Turno</label>
          <select name="id_turno" id="edit_id_turno" class="form-select">
            <option value="">— Sin asignar —</option>
            <?php foreach ($turnos as $tid => $tnombre): ?>
              <option value="<?= $tid ?>"><?= htmlspecialchars($tnombre) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>
      <div class="modal-footer">
        <button class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
        <button class="btn btn-primary">Guardar cambios</button>
      </div>
    </form>
  </div></div>
</div>

<!-- Modal Push Áreas -->
<div class="modal fade" id="modalPushAreas" tabindex="-1" data-bs-backdrop="static">
  <div class="modal-dialog modal-lg"><div class="modal-content">
    <div class="modal-header"><h5 class="modal-title">Enviando áreas a los relojes...</h5></div>
    <div class="modal-body">
      <p class="text-muted small mb-2">Actualiza el campo group_id de cada usuario en los dispositivos según el área asignada en la BD.</p>
      <div class="progress mb-3" style="height:22px;">
        <div id="pushBar" class="progress-bar progress-bar-striped progress-bar-animated
             bg-info" style="width:5%">5%</div>
      </div>
      <pre id="pushLog" class="bg-dark text-light p-3 rounded"
           style="max-height:300px;overflow-y:auto;font-size:.8rem;"></pre>
    </div>
    <div class="modal-footer">
      <button class="btn btn-secondary d-none" id="btnCerrarPush"
              data-bs-dismiss="modal">Cerrar</button>
    </div>
  </div></div>
</div>

<!-- Modal Importar -->
<div class="modal fade" id="modalImportar" tabindex="-1" data-bs-backdrop="static">
  <div class="modal-dialog modal-lg"><div class="modal-content">
    <div class="modal-header"><h5 class="modal-title">Importando usuarios desde relojes...</h5></div>
    <div class="modal-body">
      <div class="progress mb-3" style="height:22px;">
        <div id="importBar" class="progress-bar progress-bar-striped progress-bar-animated
             bg-primary" style="width:5%">5%</div>
      </div>
      <pre id="importLog" class="bg-dark text-light p-3 rounded"
           style="max-height:300px;overflow-y:auto;font-size:.8rem;"></pre>
    </div>
    <div class="modal-footer">
      <button class="btn btn-secondary d-none" id="btnCerrarImportar"
              data-bs-dismiss="modal" onclick="location.reload()">Cerrar y recargar</button>
    </div>
  </div></div>
</div>

<!-- Modal Huellas Relojes -->
<div class="modal fade" id="modalSyncHuellas" tabindex="-1" data-bs-backdrop="static">
  <div class="modal-dialog modal-lg"><div class="modal-content">
    <div class="modal-header"><h5 class="modal-title">Actualizando huellas desde relojes...</h5></div>
    <div class="modal-body">
      <div class="progress mb-3" style="height:22px;">
        <div id="huellasBar" class="progress-bar progress-bar-striped progress-bar-animated bg-secondary" style="width:5%">5%</div>
      </div>
      <pre id="huellasLog" class="bg-dark text-light p-3 rounded"
           style="max-height:300px;overflow-y:auto;font-size:.8rem;"></pre>
    </div>
    <div class="modal-footer">
      <button class="btn btn-secondary d-none" id="btnCerrarHuellas"
              data-bs-dismiss="modal" onclick="location.reload()">Cerrar y recargar</button>
    </div>
  </div></div>
</div>

<?php include __DIR__ . '/../partials/footer.php'; ?>
<script>
// Editar: poblar modal
document.querySelectorAll('.btn-editar').forEach(btn => {
    btn.addEventListener('click', () => {
        document.getElementById('edit_id').value       = btn.dataset.id;
        document.getElementById('edit_rut').value      = btn.dataset.rut;
        document.getElementById('edit_nombre').value   = btn.dataset.nombre;
        document.getElementById('edit_id_area').value  = btn.dataset.id_area  || '';
        document.getElementById('edit_id_turno').value = btn.dataset.id_turno || '';
    });
});

// Push areas a relojes
function pushAreas() {
    if (!confirm('Enviará el área asignada de cada trabajador al campo group_id de los relojes físicos.\n\n¿Continuar?'))
        return;
    document.getElementById('pushLog').textContent = '';
    const bar = document.getElementById('pushBar');
    bar.style.width = '5%'; bar.textContent = '5%';
    bar.classList.add('progress-bar-animated');
    document.getElementById('btnCerrarPush').classList.add('d-none');
    new bootstrap.Modal(document.getElementById('modalPushAreas')).show();
    let pct = 5;
    const source = new EventSource('sync_stream.php?accion=push_areas');
    source.onmessage = function(e) {
        const msg = JSON.parse(e.data);
        if (msg === '__DONE__') {
            source.close();
            bar.style.width = '100%'; bar.textContent = '100%';
            bar.classList.remove('progress-bar-animated');
            document.getElementById('btnCerrarPush').classList.remove('d-none');
        } else {
            document.getElementById('pushLog').textContent += msg + '\n';
            document.getElementById('pushLog').scrollTop = 9999;
            pct = Math.min(pct + 20, 90);
            bar.style.width = pct + '%'; bar.textContent = pct + '%';
        }
    };
    source.onerror = function() {
        source.close();
        document.getElementById('pushLog').textContent += '\nError de conexion.';
        document.getElementById('btnCerrarPush').classList.remove('d-none');
    };
}

// Importar con SSE
function abrirImportar() {
    document.getElementById('importLog').textContent = '';
    const bar = document.getElementById('importBar');
    bar.style.width = '5%'; bar.textContent = '5%';
    bar.classList.add('progress-bar-animated');
    document.getElementById('btnCerrarImportar').classList.add('d-none');

    new bootstrap.Modal(document.getElementById('modalImportar')).show();

    let pct = 5;
    const source = new EventSource('sync_stream.php?accion=importar');

    source.onmessage = function(e) {
        const msg = JSON.parse(e.data);
        if (msg === '__DONE__') {
            source.close();
            bar.style.width = '100%'; bar.textContent = '100%';
            bar.classList.remove('progress-bar-animated');
            document.getElementById('btnCerrarImportar').classList.remove('d-none');
        } else {
            const log = document.getElementById('importLog');
            log.textContent += msg + '\n';
            log.scrollTop = log.scrollHeight;
            pct = Math.min(pct + 10, 90);
            bar.style.width = pct + '%'; bar.textContent = pct + '%';
        }
    };

    source.onerror = function() {
        source.close();
        document.getElementById('importLog').textContent += '\nError de conexion.';
        document.getElementById('btnCerrarImportar').classList.remove('d-none');
    };
}

function syncHuellas() {
    if (!confirm('Leerá las huellas registradas en los relojes y actualizará la columna Huella.\n\n¿Continuar?'))
        return;
    document.getElementById('huellasLog').textContent = '';
    const bar = document.getElementById('huellasBar');
    bar.style.width = '5%'; bar.textContent = '5%';
    bar.classList.add('progress-bar-animated');
    document.getElementById('btnCerrarHuellas').classList.add('d-none');

    new bootstrap.Modal(document.getElementById('modalSyncHuellas')).show();

    let pct = 5;
    const source = new EventSource('sync_stream.php?accion=sync_huellas');

    source.onmessage = function(e) {
        const msg = JSON.parse(e.data);
        if (msg === '__DONE__') {
            source.close();
            bar.style.width = '100%'; bar.textContent = '100%';
            bar.classList.remove('progress-bar-animated');
            document.getElementById('btnCerrarHuellas').classList.remove('d-none');
        } else {
            const log = document.getElementById('huellasLog');
            log.textContent += msg + '\n';
            log.scrollTop = log.scrollHeight;
            pct = Math.min(pct + 15, 90);
            bar.style.width = pct + '%'; bar.textContent = pct + '%';
        }
    };

    source.onerror = function() {
        source.close();
        document.getElementById('huellasLog').textContent += '\nError de conexion.';
        document.getElementById('btnCerrarHuellas').classList.remove('d-none');
    };
}

const excelImportForm = document.getElementById('excelImportForm');
if (excelImportForm) {
    const excelInput = document.getElementById('archivo_excel');
    const excelButton = document.getElementById('btnLeerExcel');
    const progressWrap = document.getElementById('excelImportProgressWrap');
    const progressBar = document.getElementById('excelImportBar');
    const progressPct = document.getElementById('excelImportPct');
    const progressStatus = document.getElementById('excelImportStatus');
    const progressError = document.getElementById('excelImportError');

    const setExcelProgress = (pct, message, done = false) => {
        const safePct = Math.max(0, Math.min(100, parseInt(pct || 0, 10)));
        progressWrap.classList.remove('d-none');
        progressBar.style.width = safePct + '%';
        progressBar.textContent = safePct + '%';
        progressPct.textContent = safePct + '%';
        progressStatus.textContent = message || '';
        if (done) {
            progressBar.classList.remove('progress-bar-animated');
            progressBar.classList.add('bg-success');
        } else {
            progressBar.classList.add('progress-bar-animated');
            progressBar.classList.remove('bg-success');
        }
    };

    const setExcelError = (message) => {
        progressError.textContent = message;
        progressError.classList.remove('d-none');
        excelButton.disabled = false;
        progressBar.classList.remove('progress-bar-animated');
    };

    const runExcelChunk = async () => {
        const response = await fetch('trabajadores_excel_chunk.php', {
            method: 'POST',
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        });
        const data = await response.json();
        if (!data.ok) {
            throw new Error(data.error || 'Error al procesar el Excel.');
        }
        setExcelProgress(data.pct || 0, data.msg || 'Leyendo Excel...', !!data.done);
        if (data.done) {
            setTimeout(() => window.location.reload(), 700);
            return;
        }
        await runExcelChunk();
    };

    excelImportForm.addEventListener('submit', async (event) => {
        event.preventDefault();
        progressError.classList.add('d-none');
        if (!excelInput.files.length) {
            setExcelError('Selecciona un archivo Excel válido.');
            return;
        }

        const formData = new FormData();
        formData.append('archivo_excel', excelInput.files[0]);

        excelButton.disabled = true;
        setExcelProgress(2, 'Subiendo archivo...', false);

        try {
            const response = await fetch('trabajadores_excel_start.php', {
                method: 'POST',
                body: formData,
                headers: { 'X-Requested-With': 'XMLHttpRequest' }
            });
            const data = await response.json();
            if (!data.ok) {
                throw new Error(data.error || 'No se pudo iniciar la lectura del Excel.');
            }

            setExcelProgress(5, data.msg || 'Archivo subido. Iniciando lectura...', false);
            await runExcelChunk();
        } catch (error) {
            setExcelError(error.message || 'Error al importar el Excel.');
        } finally {
            excelButton.disabled = false;
        }
    });
}
</script>
