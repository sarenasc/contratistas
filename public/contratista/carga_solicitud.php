<?php
require_once __DIR__ . '/../_bootstrap.php';
require_once __DIR__ . '/../../app/lib/db.php';
require_once __DIR__ . '/../../vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;

$title       = "Carga Dotación — Excel";
$flash_error = null;
$flash_ok    = null;

// ── Helpers ──────────────────────────────────────────────────────────────
function nrm(string $s): string {
    $s = mb_strtolower(trim($s));
    return strtr($s, [
        'á'=>'a','é'=>'e','í'=>'i','ó'=>'o','ú'=>'u','ñ'=>'n','ü'=>'u',
        'Á'=>'a','É'=>'e','Í'=>'i','Ó'=>'o','Ú'=>'u','Ñ'=>'n',
    ]);
}
function xlv($ws, int $col, int $row): string {
    return trim((string)$ws->getCell(Coordinate::stringFromColumnIndex($col).$row)->getValue());
}
function suggest_id(string $val, array $catalog): ?int {
    $needle = nrm($val);
    if ($needle === '') return null;
    foreach ($catalog as $it) {
        $candidate = nrm((string)$it['name']);
        if ($candidate === $needle) return (int)$it['id'];
    }
    foreach ($catalog as $it) {
        $candidate = nrm((string)$it['name']);
        if ($candidate !== '' && (strpos($candidate, $needle) !== false || strpos($needle, $candidate) !== false)) {
            return (int)$it['id'];
        }
    }
    return null;
}

function find_header_row($ws, int $maxR, int $maxC): ?array {
    for ($row = 1; $row <= min($maxR, 10); $row++) {
        $headers = [];
        for ($col = 1; $col <= $maxC; $col++) {
            $val = nrm(xlv($ws, $col, $row));
            if ($val !== '') $headers[$val] = $col;
        }
        if (isset($headers['area'], $headers['cargo'])) {
            return [$row, $headers];
        }
    }
    return null;
}

function solicitud_fechas_periodo(string $fecha, string $periodo): array {
    $base = DateTime::createFromFormat('Y-m-d', $fecha);
    if (!$base) return [];
    if ($periodo !== 'semana') return [$base];

    $inicio = clone $base;
    $inicio->modify('monday this week');
    $fechas = [];
    for ($i = 0; $i < 7; $i++) {
        $dia = clone $inicio;
        $dia->modify("+{$i} days");
        $fechas[] = $dia;
    }
    return $fechas;
}

// ── Reset ────────────────────────────────────────────────────────────────
if (isset($_POST['reset'])) {
    unset($_SESSION['solicitud_carga']);
    header('Location: carga_solicitud.php');
    exit;
}

// ── Catálogos ────────────────────────────────────────────────────────────
$cargos = $areas = $turnos = $contratistas = $especies_bd = [];

$q = sqlsrv_query($conn, "SELECT id_cargo AS id, cargo AS name FROM dbo.Dota_Cargo ORDER BY cargo");
if ($q) while ($r = sqlsrv_fetch_array($q, SQLSRV_FETCH_ASSOC)) $cargos[] = $r;

$q = sqlsrv_query($conn, "SELECT id_area AS id, Area AS name FROM dbo.Area ORDER BY Area");
if ($q) while ($r = sqlsrv_fetch_array($q, SQLSRV_FETCH_ASSOC)) $areas[] = $r;

$q = sqlsrv_query($conn, "SELECT id, nombre_turno AS name FROM dbo.dota_turno ORDER BY nombre_turno");
if ($q) while ($r = sqlsrv_fetch_array($q, SQLSRV_FETCH_ASSOC)) $turnos[] = $r;

$q = sqlsrv_query($conn, "SELECT id, nombre AS name FROM dbo.dota_contratista ORDER BY nombre");
if ($q) while ($r = sqlsrv_fetch_array($q, SQLSRV_FETCH_ASSOC)) $contratistas[] = $r;

$q = sqlsrv_query($conn, "SELECT id_especie AS id, especie AS name FROM dbo.especie ORDER BY especie");
if ($q) while ($r = sqlsrv_fetch_array($q, SQLSRV_FETCH_ASSOC)) $especies_bd[] = $r;

$cargo_names_by_id   = array_column($cargos, 'name', 'id');
$area_names_by_id    = array_column($areas,  'name', 'id');
$especie_names_by_id = array_column($especies_bd, 'name', 'id');

// ════════════════════════════════════════════════════════════════════════
//  PASO 1 — Cargar Excel
// ════════════════════════════════════════════════════════════════════════
if (isset($_POST['cargar_excel'])
    && !empty($_FILES['archivo'])
    && $_FILES['archivo']['error'] === UPLOAD_ERR_OK) {
    try {
        $fecha    = trim($_POST['fecha'] ?? '');
        $periodo  = ($_POST['periodo'] ?? 'dia') === 'semana' ? 'semana' : 'dia';
        $id_turno = (int)($_POST['id_turno'] ?? 0) ?: null;
        if (!$fecha || !DateTime::createFromFormat('Y-m-d', $fecha))
            throw new RuntimeException("Ingrese una fecha válida.");

        $wb = IOFactory::load($_FILES['archivo']['tmp_name']);
        $sheetName = 'Dotaciones 25-26';
        if (!$wb->sheetNameExists($sheetName))
            throw new RuntimeException(
                "No se encontró la hoja \"$sheetName\". "
               ."Disponibles: ".implode(', ', $wb->getSheetNames())
            );

        $ws   = $wb->getSheetByName($sheetName);
        $maxR = $ws->getHighestRow();
        $maxC = Coordinate::columnIndexFromString($ws->getHighestColumn());

        $headerInfo = find_header_row($ws, $maxR, $maxC);
        if (!$headerInfo) {
            throw new RuntimeException('No se encontró una fila de encabezados con las columnas Área y Cargo.');
        }

        [$headerRow, $headers] = $headerInfo;
        $areaCol  = $headers['area'];
        $cargoCol = $headers['cargo'];
        $generoCol = $headers['genero'] ?? 0;
        $tipoContratoCol = $headers['tipo contrato'] ?? 0;
        $dataStart = $generoCol ? $generoCol + 1 : max($areaCol, $cargoCol, $tipoContratoCol) + 1;

        $col_especies = [];
        $especies_map = [];
        for ($c = $dataStart; $c <= $maxC; $c++) {
            $nombre = xlv($ws, $c, $headerRow);
            if ($nombre === '') continue;
            $key = nrm(preg_replace('/\bkiwis\b/i', 'kiwi', $nombre));
            if ($key === '') continue;
            $col_especies[] = ['col' => $c, 'key' => $key, 'excel' => $nombre];
            if (!isset($especies_map[$key])) {
                $especies_map[$key] = $nombre;
            }
        }
        if (empty($col_especies)) {
            throw new RuntimeException('No se encontraron columnas de especies desde la columna posterior a Género.');
        }

        // Leer filas con cantidades. Este Excel describe dotación requerida, no contratista ya asignado.
        $skip_keys  = ['total contratista','total planta','requerimiento','total turno','total mod','total moi','kg/hra','kg/hh'];
        $filas_raw  = [];
        $cargos_map = [];  // nrm → excel_name
        $areas_map  = [];

        for ($row = $headerRow + 1; $row <= $maxR; $row++) {
            $cargo_excel = xlv($ws, $cargoCol, $row);
            if ($cargo_excel===''||in_array(nrm($cargo_excel),$skip_keys)) continue;

            $cantidades = [];
            $hay_val    = false;
            foreach ($col_especies as $i => $colEsp) {
                $v = xlv($ws, (int)$colEsp['col'], $row);
                $q = is_numeric($v) ? max(0,(int)$v) : 0;
                $cantidades[$i] = $q;
                if ($q>0) $hay_val=true;
            }
            if (!$hay_val) continue;

            $area_excel = xlv($ws, $areaCol, $row);
            $filas_raw[] = ['cargo_excel'=>$cargo_excel,'area_excel'=>$area_excel,'cantidades'=>$cantidades];
            $cargos_map[nrm($cargo_excel)] = $cargo_excel;
            $areas_map[nrm($area_excel)]   = $area_excel;
        }

        if (empty($filas_raw))
            throw new RuntimeException("No se encontraron filas de dotación con cantidad > 0.");

        // Columnas activas
        $cols_activas = [];
        foreach ($col_especies as $i => $_) {
            foreach ($filas_raw as $f) if(($f['cantidades'][$i]??0)>0){ $cols_activas[]=$i; break; }
        }
        $especies = [];
        foreach ($cols_activas as $i) {
            $colEsp = $col_especies[$i];
            $especies[] = [
                'key'    => $colEsp['key'],
                'excel'  => $colEsp['excel'],
                'id'     => suggest_id($colEsp['excel'], $especies_bd),
                'nombre' => $colEsp['excel'],
            ];
        }

        // Reconstruir cantidades solo con cols activas
        foreach ($filas_raw as &$f)
            $f['cantidades'] = array_values(array_map(fn($i)=>$f['cantidades'][$i]??0, $cols_activas));
        unset($f);

        // Listas únicas con sugerencia auto
        $cargos_list = [];
        foreach ($cargos_map as $key=>$excel_name)
            $cargos_list[] = ['excel'=>$excel_name,'key'=>$key,'id_bd'=>suggest_id($excel_name,$cargos)];

        $areas_list = [];
        foreach ($areas_map as $key=>$excel_name)
            $areas_list[] = ['excel'=>$excel_name,'key'=>$key,'id_bd'=>suggest_id($excel_name,$areas)];

        $especies_list = [];
        foreach ($especies as $e)
            $especies_list[] = ['excel'=>$e['excel'],'key'=>$e['key'],'id_bd'=>$e['id']];

        $_SESSION['solicitud_carga'] = [
            'step'          => 'mapeo',
            'fecha'         => $fecha,
            'periodo'       => $periodo,
            'id_turno'      => $id_turno,
            'especies'      => $especies,
            'filas_raw'     => $filas_raw,
            'cargos_list'   => $cargos_list,
            'areas_list'    => $areas_list,
            'especies_list' => $especies_list,
        ];
        $flash_ok = count($filas_raw)." cargo(s) detectados con ".count($especies)." especie(s) activas.";

    } catch (Throwable $e) {
        $flash_error = $e->getMessage();
    }
}

// ════════════════════════════════════════════════════════════════════════
//  PASO 2 — Confirmar mapeo
// ════════════════════════════════════════════════════════════════════════
if (isset($_POST['confirmar_mapeo'])) {
    $data = $_SESSION['solicitud_carga'] ?? null;
    if (!$data) {
        $flash_error = "Sesión expirada. Vuelva a cargar el archivo.";
    } else {
        $map_cargo   = $_POST['map_cargo']   ?? [];
        $map_area    = $_POST['map_area']    ?? [];
        $map_especie = $_POST['map_especie'] ?? [];
        $err = [];

        foreach ($data['cargos_list'] as $c)
            if (empty($map_cargo[$c['key']])) $err[] = "Cargo sin asignar: &laquo;{$c['excel']}&raquo;";
        foreach ($data['areas_list'] as $a)
            if (empty($map_area[$a['key']]))  $err[] = "Área sin asignar: &laquo;{$a['excel']}&raquo;";
        foreach (($data['especies_list'] ?? []) as $e)
            if (empty($map_especie[$e['key']])) $err[] = "Especie sin asignar: &laquo;{$e['excel']}&raquo;";

        if ($err) {
            $flash_error = implode('<br>', $err);
        } else {
            $cargo_id_by_key   = array_map('intval', $map_cargo);
            $area_id_by_key    = array_map('intval', $map_area);
            $especie_id_by_key = array_map('intval', $map_especie);

            $especies_resueltas = [];
            foreach ($data['especies'] as $e) {
                $id_especie = (int)($especie_id_by_key[$e['key']] ?? 0);
                if (!$id_especie) continue;
                $especies_resueltas[] = [
                    'id'     => $id_especie,
                    'nombre' => $especie_names_by_id[$id_especie] ?? $e['excel'],
                    'excel'  => $e['excel'],
                ];
            }

            $filas_resueltas = [];
            foreach ($data['filas_raw'] as $f) {
                $id_cargo = $cargo_id_by_key[nrm($f['cargo_excel'])] ?? null;
                $id_area  = $area_id_by_key[nrm($f['area_excel'])]   ?? null;
                if (!$id_cargo || !$id_area) continue;
                $filas_resueltas[] = [
                    'id_cargo'    => $id_cargo,
                    'id_area'     => $id_area,
                    'cargo_nombre'=> $cargo_names_by_id[$id_cargo] ?? $f['cargo_excel'],
                    'area_nombre' => $area_names_by_id[$id_area]   ?? $f['area_excel'],
                    'cantidades'  => $f['cantidades'],
                ];
            }

            $_SESSION['solicitud_carga']['especies']        = $especies_resueltas;
            $_SESSION['solicitud_carga']['step']            = 'asignar';
            $_SESSION['solicitud_carga']['filas_resueltas'] = $filas_resueltas;
        }
    }
}

// ════════════════════════════════════════════════════════════════════════
//  PASO 3 — Guardar asignaciones
// ════════════════════════════════════════════════════════════════════════
if (isset($_POST['guardar'])) {
    $data = $_SESSION['solicitud_carga'] ?? null;
    if (!$data) {
        $flash_error = "Sesión expirada. Vuelva a cargar el archivo.";
    } else {
        $fechas   = solicitud_fechas_periodo($data['fecha'], $data['periodo'] ?? 'dia');
        $id_turno = $data['id_turno'];
        $especies = $data['especies'];
        $filas    = $data['filas_resueltas'] ?? [];
        // rows es la lista plana de sub-filas: [{fila_idx, id_contratista, cantidades:[]}]
        $rows     = $_POST['rows'] ?? [];

        $guardados = 0;
        $errores   = 0;

        foreach ($rows as $sub) {
            $fi             = (int)($sub['fila_idx']      ?? -1);
            $id_contratista = (int)($sub['id_contratista'] ?? 0);
            $cantidades_sub = $sub['cantidades'] ?? [];

            if ($fi < 0 || !isset($filas[$fi]) || !$id_contratista) continue;

            $id_cargo = (int)$filas[$fi]['id_cargo'];
            $id_area  = (int)$filas[$fi]['id_area'];

            foreach ($especies as $ei => $esp) {
                $cantidad = (int)($cantidades_sub[$ei] ?? 0);
                if ($cantidad <= 0) continue;
                $id_especie = (int)$esp['id'] ?: null;

                foreach ($fechas as $dt) {
                    $stmtV = sqlsrv_query($conn,
                        "SELECT ISNULL(MAX(version),0) AS max_v FROM dbo.dota_solicitud_contratista
                         WHERE contratista=? AND cargo=? AND area=? AND ISNULL(id_especie,-1)=ISNULL(?,-1)",
                        [$id_contratista,$id_cargo,$id_area,$id_especie]);
                    $rowV    = $stmtV ? sqlsrv_fetch_array($stmtV, SQLSRV_FETCH_ASSOC) : ['max_v'=>0];
                    $version = (int)$rowV['max_v'] + 1;

                    $res = sqlsrv_query($conn,
                        "INSERT INTO dbo.dota_solicitud_contratista
                           (contratista,cargo,area,cantidad,version,fecha,id_turno,id_especie)
                         VALUES (?,?,?,?,?,?,?,?)",
                        [$id_contratista,$id_cargo,$id_area,$cantidad,$version,$dt,$id_turno,$id_especie]);
                    $res !== false ? $guardados++ : $errores++;
                }
            }
        }

        unset($_SESSION['solicitud_carga']);
        $flash_ok = "{$guardados} registro(s) guardado(s)".($errores ? " ({$errores} error(es))." : " correctamente.");
    }
}

$data = $_SESSION['solicitud_carga'] ?? null;
$step = $data['step'] ?? null;

include __DIR__ . '/../partials/header.php';
include __DIR__ . '/../partials/navbar_wrapper.php';
?>

<style>
.table-asign th, .table-asign td { font-size:.82rem; padding:4px 6px; white-space:nowrap; }
.qty-cell input { width:60px; padding:2px 4px; font-size:.8rem; text-align:center; }
.cargo-label { font-weight:600; font-size:.85rem; }
.sub-row { background:#f8f9fa; }
</style>

<main class="container-fluid py-4" style="max-width:1400px">

<div class="d-flex align-items-center gap-3 mb-4">
  <a href="Solicitud_Contra.php" class="btn btn-outline-secondary btn-sm">← Solicitudes</a>
  <h1 class="h4 mb-0">Carga de Dotación desde Excel</h1>
  <?php if ($step): ?>
  <form method="POST" class="ms-auto">
    <button type="submit" name="reset" class="btn btn-outline-danger btn-sm"
            onclick="return confirm('¿Reiniciar la carga? Se perderán los datos no guardados.')">
      ↺ Reiniciar
    </button>
  </form>
  <?php endif; ?>
</div>

<?php if ($flash_error): ?>
<div class="alert alert-danger"><?= $flash_error ?></div>
<?php endif; ?>
<?php if ($flash_ok): ?>
<div class="alert alert-success"><?= htmlspecialchars($flash_ok) ?></div>
<?php endif; ?>

<!-- Indicador de pasos -->
<div class="d-flex gap-2 mb-4">
  <?php
  $steps = ['1. Subir Excel', '2. Mapeo', '3. Asignar contratista'];
  $active = ['mapeo'=>1,'asignar'=>2][$step] ?? 0;
  foreach ($steps as $si=>$sl):
      $cls = $si<$active ? 'bg-success text-white' : ($si===$active ? 'bg-primary text-white' : 'bg-light text-muted');
  ?>
  <span class="badge rounded-pill px-3 py-2 <?= $cls ?>" style="font-size:.85rem"><?= $sl ?></span>
  <?php if ($si<2): ?><span class="text-muted align-self-center">→</span><?php endif; ?>
  <?php endforeach; ?>
</div>

<!-- ═════════════════════════════════════════════
     PASO 1 — Subir Excel
════════════════════════════════════════════ -->
<?php if (!$step): ?>
<div class="card shadow-sm">
  <div class="card-header fw-semibold">1. Seleccionar archivo Excel</div>
  <div class="card-body">
    <p class="text-muted small mb-3">
      Se leerá la hoja <code>Dotaciones 25-26</code>. Se importan las filas con cargo
      y al menos una cantidad &gt; 0; luego podrás mapear cargos, áreas y especies.
    </p>
    <form method="POST" enctype="multipart/form-data">
      <div class="row g-3 align-items-end">
        <div class="col-md-3">
          <label class="form-label fw-semibold">Fecha de referencia <span class="text-danger">*</span></label>
          <input type="date" name="fecha" class="form-control" value="<?= date('Y-m-d') ?>" required>
        </div>
        <div class="col-md-2">
          <label class="form-label fw-semibold">Periodo</label>
          <select name="periodo" class="form-select">
            <option value="dia">Por día</option>
            <option value="semana">Semana completa</option>
          </select>
        </div>
        <div class="col-md-3">
          <label class="form-label fw-semibold">Turno</label>
          <select name="id_turno" class="form-select">
            <option value="">-- Sin turno --</option>
            <?php foreach ($turnos as $t): ?>
            <option value="<?= (int)$t['id'] ?>"><?= htmlspecialchars($t['name']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-md-4">
          <label class="form-label fw-semibold">Archivo <span class="text-danger">*</span></label>
          <input type="file" name="archivo" class="form-control" accept=".xlsx,.xls" required>
        </div>
        <div class="col-md-12 col-lg-2">
          <button type="submit" name="cargar_excel" class="btn btn-primary w-100">
            Cargar y agrupar
          </button>
        </div>
      </div>
    </form>
  </div>
</div>

<!-- ═════════════════════════════════════════════
     PASO 2 — Mapeo
════════════════════════════════════════════ -->
<?php elseif ($step === 'mapeo'):
  $cargos_list   = $data['cargos_list'];
  $areas_list    = $data['areas_list'];
  $especies_list = $data['especies_list'] ?? [];
  $n_sin_cargo = count(array_filter($cargos_list, fn($c) => $c['id_bd'] === null));
  $n_sin_area  = count(array_filter($areas_list,  fn($a) => $a['id_bd'] === null));
  $n_sin_esp   = count(array_filter($especies_list, fn($e) => $e['id_bd'] === null));
?>
<div class="card shadow-sm">
  <div class="card-header fw-semibold">
    2. Mapeo de Cargos, Áreas y Especies
    <?php if ($n_sin_cargo||$n_sin_area||$n_sin_esp): ?>
    <span class="badge bg-warning text-dark ms-2">
      <?= $n_sin_cargo+$n_sin_area+$n_sin_esp ?> sin coincidencia automática
    </span>
    <?php endif; ?>
  </div>
  <div class="card-body">
    <p class="text-muted small mb-3">
      Archivo: <strong><?= date('d/m/Y', strtotime($data['fecha'])) ?></strong> &nbsp;|&nbsp;
      Periodo: <strong><?= (($data['periodo'] ?? 'dia') === 'semana') ? 'Semana completa' : 'Por día' ?></strong> &nbsp;|&nbsp;
      <?= count($data['filas_raw']) ?> cargos &nbsp;|&nbsp;
      <?= count($data['especies']) ?> especies activas.<br>
      Las filas marcadas con <span class="badge bg-warning text-dark">!</span> no se encontraron
      automáticamente. Selecciona el elemento correspondiente del sistema.
    </p>

    <form method="POST">

      <?php if (!empty($cargos_list)): ?>
      <h5 class="mt-2">Cargos del Excel → Cargo en sistema</h5>
      <div class="table-responsive mb-4">
        <table class="table table-sm table-bordered">
          <thead class="table-dark">
            <tr><th>Cargo en Excel</th><th>Cargo en Sistema</th></tr>
          </thead>
          <tbody>
          <?php foreach ($cargos_list as $c): ?>
            <?php $auto = $c['id_bd'] !== null; ?>
            <tr <?= $auto ? '' : 'class="table-warning"' ?>>
              <td>
                <?= $auto ? '' : '<span class="badge bg-warning text-dark me-1">!</span>' ?>
                <?= htmlspecialchars($c['excel']) ?>
              </td>
              <td>
                <select name="map_cargo[<?= htmlspecialchars($c['key']) ?>]"
                        class="form-select form-select-sm select2-cargo" required>
                  <option value="">-- sin asignar --</option>
                  <?php foreach ($cargos as $bd): ?>
                  <option value="<?= (int)$bd['id'] ?>"
                    <?= (int)$bd['id'] === (int)($c['id_bd']??0) ? 'selected' : '' ?>>
                    <?= (int)$bd['id'] ?> — <?= htmlspecialchars($bd['name']) ?>
                  </option>
                  <?php endforeach; ?>
                </select>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <?php endif; ?>

      <?php if (!empty($areas_list)): ?>
      <h5>Áreas del Excel → Área en sistema</h5>
      <div class="table-responsive mb-4">
        <table class="table table-sm table-bordered">
          <thead class="table-dark">
            <tr><th>Área en Excel</th><th>Área en Sistema</th></tr>
          </thead>
          <tbody>
          <?php foreach ($areas_list as $a): ?>
            <?php $auto = $a['id_bd'] !== null; ?>
            <tr <?= $auto ? '' : 'class="table-warning"' ?>>
              <td>
                <?= $auto ? '' : '<span class="badge bg-warning text-dark me-1">!</span>' ?>
                <?= htmlspecialchars($a['excel']) ?>
              </td>
              <td>
                <select name="map_area[<?= htmlspecialchars($a['key']) ?>]"
                        class="form-select form-select-sm" required>
                  <option value="">-- sin asignar --</option>
                  <?php foreach ($areas as $bd): ?>
                  <option value="<?= (int)$bd['id'] ?>"
                    <?= (int)$bd['id'] === (int)($a['id_bd']??0) ? 'selected' : '' ?>>
                    <?= (int)$bd['id'] ?> — <?= htmlspecialchars($bd['name']) ?>
                  </option>
                  <?php endforeach; ?>
                </select>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <?php endif; ?>

      <?php if (!empty($especies_list)): ?>
      <h5>Especies del Excel → Especie en sistema</h5>
      <div class="table-responsive mb-4">
        <table class="table table-sm table-bordered">
          <thead class="table-dark">
            <tr><th>Especie en Excel</th><th>Especie en Sistema</th></tr>
          </thead>
          <tbody>
          <?php foreach ($especies_list as $e): ?>
            <?php $auto = $e['id_bd'] !== null; ?>
            <tr <?= $auto ? '' : 'class="table-warning"' ?>>
              <td>
                <?= $auto ? '' : '<span class="badge bg-warning text-dark me-1">!</span>' ?>
                <?= htmlspecialchars($e['excel']) ?>
              </td>
              <td>
                <select name="map_especie[<?= htmlspecialchars($e['key']) ?>]"
                        class="form-select form-select-sm" required>
                  <option value="">-- sin asignar --</option>
                  <?php foreach ($especies_bd as $bd): ?>
                  <option value="<?= (int)$bd['id'] ?>"
                    <?= (int)$bd['id'] === (int)($e['id_bd']??0) ? 'selected' : '' ?>>
                    <?= (int)$bd['id'] ?> — <?= htmlspecialchars($bd['name']) ?>
                  </option>
                  <?php endforeach; ?>
                </select>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <?php endif; ?>

      <button type="submit" name="confirmar_mapeo" class="btn btn-primary">
        Continuar → Asignar Contratistas
      </button>
    </form>
  </div>
</div>

<!-- ═════════════════════════════════════════════
     PASO 3 — Asignación de contratistas
════════════════════════════════════════════ -->
<?php elseif ($step === 'asignar'):
  $filas   = $data['filas_resueltas'];
  $especies = $data['especies'];
  $n_esp   = count($especies);
?>
<div class="card shadow-sm">
  <div class="card-header fw-semibold">
    3. Asignar Contratista por Cargo
  </div>
  <div class="card-body">
    <p class="text-muted small mb-3">
      Fecha: <strong><?= date('d/m/Y', strtotime($data['fecha'])) ?></strong>
      &nbsp;|&nbsp; Periodo: <strong><?= (($data['periodo'] ?? 'dia') === 'semana') ? 'Semana completa' : 'Por día' ?></strong>
      <?php if ($data['id_turno']): ?>
        &nbsp;|&nbsp; Turno: <strong><?= htmlspecialchars(array_column($turnos,'name','id')[$data['id_turno']] ?? '') ?></strong>
      <?php endif; ?>
      &nbsp;|&nbsp; <?= count($filas) ?> cargos &nbsp;|&nbsp; <?= $n_esp ?> especies activas.<br>
      Cada especie se asigna por separado. Para dividir un cargo entre contratistas, usa el botón
      <strong>+</strong> y reparte la cantidad.
    </p>

    <!-- Asignación rápida a todos -->
    <div class="d-flex gap-2 align-items-center mb-3 p-2 bg-light rounded border">
      <label class="form-label mb-0 fw-semibold small">Asignar a todas las líneas visibles:</label>
      <select id="contra-global" class="form-select form-select-sm" style="max-width:220px"
              onchange="asignarATodos(this.value)">
        <option value="">-- Selecciona --</option>
        <?php foreach ($contratistas as $c): ?>
        <option value="<?= (int)$c['id'] ?>"><?= htmlspecialchars($c['name']) ?></option>
        <?php endforeach; ?>
      </select>
      <span class="text-muted small">(solo aplica a líneas sin contratista asignado)</span>
    </div>

    <form method="POST" id="form-asignar">
      <?php foreach ($especies as $ei => $esp): ?>
        <?php
          $filasEsp = [];
          foreach ($filas as $fi => $fila) {
              $qty = (int)($fila['cantidades'][$ei] ?? 0);
              if ($qty > 0) $filasEsp[] = [$fi, $fila, $qty];
          }
          if (!$filasEsp) continue;
        ?>
        <section class="mb-4 border rounded">
          <div class="bg-light border-bottom px-3 py-2 d-flex justify-content-between align-items-center">
            <h5 class="h6 mb-0"><?= htmlspecialchars($esp['nombre']) ?></h5>
            <span class="badge bg-secondary"><?= count($filasEsp) ?> cargo(s)</span>
          </div>
          <div class="table-responsive">
            <table class="table table-bordered table-sm table-asign mb-0 species-table" data-ei="<?= $ei ?>">
              <thead class="table-dark sticky-top">
                <tr>
                  <th>Cargo</th>
                  <th>Área</th>
                  <th class="text-center" style="width:90px">Total</th>
                  <th style="min-width:220px">Contratista <span class="text-danger">*</span></th>
                  <th class="text-center" style="width:110px">Cantidad</th>
                  <th class="text-center" style="width:60px">+/-</th>
                </tr>
              </thead>
              <tbody>
              <?php foreach ($filasEsp as [$fi, $fila, $qty]): ?>
                <?php $key = $fi . '_' . $ei . '_0'; ?>
                <tr data-fi="<?= $fi ?>" data-ei="<?= $ei ?>" class="fila-principal">
                  <td class="cargo-label" rowspan="1" id="td-cargo-<?= $fi ?>-<?= $ei ?>">
                    <?= htmlspecialchars($fila['cargo_nombre']) ?>
                  </td>
                  <td class="text-muted" rowspan="1" id="td-area-<?= $fi ?>-<?= $ei ?>">
                    <?= htmlspecialchars($fila['area_nombre']) ?>
                  </td>
                  <td class="text-center fw-semibold total-qty" rowspan="1" id="td-total-<?= $fi ?>-<?= $ei ?>"
                      data-total="<?= $qty ?>">
                    <?= $qty ?>
                  </td>
                  <td>
                    <input type="hidden" name="rows[<?= $key ?>][fila_idx]" value="<?= $fi ?>">
                    <select name="rows[<?= $key ?>][id_contratista]"
                            class="form-select form-select-sm contra-sel" data-fi="<?= $fi ?>" data-ei="<?= $ei ?>">
                      <option value="">-- Asignar --</option>
                      <?php foreach ($contratistas as $c): ?>
                      <option value="<?= (int)$c['id'] ?>"><?= htmlspecialchars($c['name']) ?></option>
                      <?php endforeach; ?>
                    </select>
                  </td>
                  <td class="qty-cell">
                    <input type="number" name="rows[<?= $key ?>][cantidades][<?= $ei ?>]"
                           class="form-control form-control-sm split-qty"
                           data-fi="<?= $fi ?>" data-ei="<?= $ei ?>"
                           min="0" value="<?= $qty ?>">
                  </td>
                  <td class="text-center">
                    <button type="button" class="btn btn-outline-success btn-sm px-1 py-0"
                            onclick="agregarSubFila(<?= $fi ?>, <?= $ei ?>, this)"
                            title="Agregar otro contratista para este cargo">+</button>
                  </td>
                </tr>
              <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        </section>
      <?php endforeach; ?>

      <div class="mt-3 d-flex gap-2 align-items-center">
        <button type="submit" name="guardar" class="btn btn-success"
                onclick="return validarFormulario()">
          Guardar Solicitudes
        </button>
        <span class="text-muted small">Solo se guardan líneas con contratista asignado y cantidad &gt; 0.</span>
      </div>
    </form>
  </div>
</div>

<script>
/* Datos de especies para las sub-filas */
var ESPECIES   = <?= json_encode($especies, JSON_UNESCAPED_UNICODE) ?>;
var N_ESP      = ESPECIES.length;
var CONTRATISTAS = <?= json_encode(array_values($contratistas), JSON_UNESCAPED_UNICODE) ?>;
var FILAS      = <?= json_encode($filas, JSON_UNESCAPED_UNICODE) ?>;

/* Contador de sub-filas por cargo y especie */
var subCount = {};
<?php foreach ($filas as $fi => $fila): ?>
<?php foreach ($especies as $ei => $_): ?>
<?php if ((int)($fila['cantidades'][$ei] ?? 0) > 0): ?>
subCount['<?= $fi ?>_<?= $ei ?>'] = 1;
<?php endif; ?>
<?php endforeach; ?>
<?php endforeach; ?>

function buildContraOptions(selValue) {
    var html = '<option value="">-- Asignar --</option>';
    CONTRATISTAS.forEach(function(c) {
        var sel = (String(c.id) === String(selValue)) ? ' selected' : '';
        html += '<option value="' + c.id + '"' + sel + '>' + c.name + '</option>';
    });
    return html;
}

function agregarSubFila(fi, ei, btn) {
    var counterKey = fi + '_' + ei;
    if (subCount[counterKey] === undefined) subCount[counterKey] = 1;
    var si = subCount[counterKey]++;
    var key = fi + '_' + ei + '_' + si;

    var refRow = document.querySelector('tr[data-fi="' + fi + '"][data-ei="' + ei + '"]');
    if (!refRow) return;

    var tr = document.createElement('tr');
    tr.className = 'sub-row';
    tr.dataset.fi = fi;
    tr.dataset.ei = ei;

    var html = '<td>'
         +  '<input type="hidden" name="rows[' + key + '][fila_idx]" value="' + fi + '">'
         +  '<select name="rows[' + key + '][id_contratista]" class="form-select form-select-sm contra-sel" data-fi="' + fi + '" data-ei="' + ei + '">'
         +  buildContraOptions('')
         +  '</select></td>';
    html += '<td class="qty-cell"><input type="number" name="rows[' + key + '][cantidades][' + ei + ']"'
         +  ' class="form-control form-control-sm split-qty" data-fi="' + fi + '" data-ei="' + ei + '" min="0" value="0"></td>';
    html += '<td class="text-center">'
         +  '<button type="button" class="btn btn-outline-danger btn-sm px-1 py-0"'
         +  ' onclick="quitarSubFila(this)" title="Quitar">−</button></td>';

    tr.innerHTML = html;

    // Insertar después de la última sub-fila de este cargo/especie
    var allRows = Array.from(document.querySelectorAll('tr[data-fi="' + fi + '"][data-ei="' + ei + '"]'));
    var lastRow = allRows[allRows.length - 1];
    lastRow.parentNode.insertBefore(tr, lastRow.nextSibling);
    actualizarRowspan(fi, ei);
}

function quitarSubFila(btn) {
    var tr = btn.closest('tr');
    var fi = tr ? tr.dataset.fi : null;
    var ei = tr ? tr.dataset.ei : null;
    if (tr) tr.remove();
    if (fi !== null && ei !== null) actualizarRowspan(fi, ei);
}

function actualizarRowspan(fi, ei) {
    var total = document.querySelectorAll('tr[data-fi="' + fi + '"][data-ei="' + ei + '"]').length;
    var tdCargo = document.getElementById('td-cargo-' + fi + '-' + ei);
    var tdArea = document.getElementById('td-area-' + fi + '-' + ei);
    var tdTotal = document.getElementById('td-total-' + fi + '-' + ei);
    if (tdCargo) tdCargo.rowSpan = total;
    if (tdArea) tdArea.rowSpan = total;
    if (tdTotal) tdTotal.rowSpan = total;
}

function asignarATodos(val) {
    if (!val) return;
    document.querySelectorAll('.contra-sel').forEach(function(sel) {
        if (!sel.value) sel.value = val;
    });
}

function validarFormulario() {
    var alguno = false;
    document.querySelectorAll('.contra-sel').forEach(function(sel) {
        if (sel.value) alguno = true;
    });
    if (!alguno) { alert('Asigna al menos un contratista antes de guardar.'); return false; }
    return true;
}
</script>
<?php endif; ?>

</main>

<?php
include __DIR__ . '/../partials/footer.php';
sqlsrv_close($conn);
?>
