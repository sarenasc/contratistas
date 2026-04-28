<?php
declare(strict_types=1);

require_once __DIR__ . '/../_bootstrap.php';
require_once __DIR__ . '/../../app/lib/db.php';
require_once __DIR__ . '/../../vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Reader\IReadFilter;

$title = "Asistencia";
$flash_error = null;
$flash_ok    = null;

set_time_limit(0);

/* =========================
   Helpers
========================= */
function norm_txt($s): string {
    $s = (string)$s;
    $s = trim($s);
    $s = preg_replace('/\s+/', ' ', $s);
    return mb_strtoupper($s, 'UTF-8');
}

function is_excel_error(string $s): bool {
    return $s !== '' && $s[0] === '#';
}

function indexToColumnLetter(int $index1based): string {
    $index = $index1based;
    $letters = '';
    while ($index > 0) {
        $mod = ($index - 1) % 26;
        $letters = chr(65 + $mod) . $letters;
        $index = intdiv(($index - 1), 26);
    }
    return $letters;
}

function suggest_id(string $valueFromExcel, array $catalog): ?int {
    $needle = norm_txt($valueFromExcel);
    if ($needle === '') return null;
    foreach ($catalog as $row) {
        if (norm_txt($row['name']) === $needle) return (int)$row['id'];
    }
    return null;
}

class ChunkReadFilter implements IReadFilter {
    private int $startRow = 1;
    private int $endRow = 1;
    private array $columnsAllowed = [];

    public function __construct(array $columnsAllowed = []) {
        foreach ($columnsAllowed as $c) $this->columnsAllowed[strtoupper($c)] = true;
    }

    public function setRows(int $startRow, int $chunkSize): void {
        $this->startRow = $startRow;
        $this->endRow   = $startRow + $chunkSize - 1;
    }

    public function readCell($columnAddress, $row, $worksheetName = ''): bool {
        if ($row < $this->startRow || $row > $this->endRow) return false;
        if (empty($this->columnsAllowed)) return true;
        return isset($this->columnsAllowed[strtoupper($columnAddress)]);
    }
}

/* =========================
   Reset por POST
========================= */
if (isset($_POST['reset_sesion'])) {
    unset($_SESSION['asistencia_upload']);
    $flash_ok = "Sesión reiniciada.";
}

/* =========================
   Catálogos desde BD
========================= */
$areas = [];
$cargos = [];
$mos = [];
$empleadores = [];
$turnos = [];

try {
    $stmt = db_query($conn, "SELECT id_area, Area FROM [dbo].[Area] ORDER BY Area");
    while ($r = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
        $areas[] = ['id' => (int)$r['id_area'], 'name' => (string)$r['Area']];
    }

    $stmt = db_query($conn, "SELECT id_cargo, cargo FROM dbo.Dota_Cargo ORDER BY cargo");
    while ($r = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
        $cargos[] = ['id' => (int)$r['id_cargo'], 'name' => (string)$r['cargo']];
    }

    $stmt = db_query($conn, "SELECT id_mo, nombre_mo, abrev FROM dbo.dota_tipo_mo ORDER BY nombre_mo");
    while ($r = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
        $mos[] = ['id' => (int)$r['id_mo'], 'name' => (string)$r['nombre_mo'], 'abrev' => (string)($r['abrev'] ?? '')];
    }

    $stmt = db_query($conn, "SELECT id, nombre FROM [dbo].[dota_contratista] ORDER BY nombre ASC");
    while ($r = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
        $empleadores[] = ['id' => (int)$r['id'], 'name' => (string)$r['nombre']];
    }

    // dota_turno puede no existir aún — se carga solo si la tabla está presente
    $chkTurno = sqlsrv_query($conn, "SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA='dbo' AND TABLE_NAME='dota_turno'");
    if ($chkTurno && sqlsrv_fetch($chkTurno)) {
        $stmt = db_query($conn, "SELECT id, nombre_turno FROM [dbo].[dota_turno] ORDER BY nombre_turno");
        while ($r = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            $turnos[] = ['id' => (int)$r['id'], 'name' => (string)$r['nombre_turno']];
        }
    }

    // Verificar que dota_jefe_area existe y cargar catálogo
    $jefes_ok = false;
    $jefes    = [];
    $chkJefe = sqlsrv_query($conn, "SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA='dbo' AND TABLE_NAME='dota_jefe_area'");
    if ($chkJefe && sqlsrv_fetch($chkJefe)) {
        $stmtJ = sqlsrv_query($conn,
            "SELECT j.id, j.nombre, j.id_turno FROM dbo.dota_jefe_area j WHERE j.activo = 1 ORDER BY j.nombre");
        if ($stmtJ) {
            while ($r = sqlsrv_fetch_array($stmtJ, SQLSRV_FETCH_ASSOC)) {
                $jefes[] = ['id' => (int)$r['id'], 'name' => (string)$r['nombre'], 'id_turno' => (int)($r['id_turno'] ?? 0)];
            }
        }
        $jefes_ok = !empty($jefes);
    }

} catch (Throwable $e) {
    $flash_error = $e->getMessage();
}

/* =========================
   Mapeos guardados
========================= */
$saved_maps = [];  // ['area'|'empleador'|'cargo'|'turno'][VALOR_EXCEL] = id_sistema
$mapa_ok    = false;
$chkMapa = sqlsrv_query($conn, "SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA='dbo' AND TABLE_NAME='dota_asistencia_mapa'");
if ($chkMapa && sqlsrv_fetch($chkMapa)) {
    $mapa_ok = true;
    $smStmt = sqlsrv_query($conn, "SELECT tipo, valor_excel, id_sistema FROM dbo.dota_asistencia_mapa");
    if ($smStmt) {
        while ($sm = sqlsrv_fetch_array($smStmt, SQLSRV_FETCH_ASSOC)) {
            $saved_maps[$sm['tipo']][$sm['valor_excel']] = (int)$sm['id_sistema'];
        }
    }
}

/* =========================
   Procesar carga Excel
========================= */
if (isset($_POST['cargar_excel']) && isset($_FILES['archivo']) && $_FILES['archivo']['error'] === UPLOAD_ERR_OK) {
    try {
        unset($_SESSION['asistencia_upload']); // evita sesión vieja

        // 1) Guardar archivo en storage/asistencia
        $uploadDir = __DIR__ . '/../../storage/asistencia/';
        if (!is_dir($uploadDir)) mkdir($uploadDir, 0777, true);

        $originalName = $_FILES['archivo']['name'];
        $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        if (!in_array($extension, ['xlsx','xls'], true)) {
            throw new RuntimeException("Formato no permitido. Sube un .xlsx o .xls");
        }

        $newFileName = 'asistencia_' . date('Ymd_His') . '_' . uniqid() . '.' . $extension;
        $destination = $uploadDir . $newFileName;

        if (!move_uploaded_file($_FILES['archivo']['tmp_name'], $destination)) {
            throw new RuntimeException("No se pudo guardar el archivo en storage/asistencia.");
        }

        // 2) ✅ totalRows REAL usando getHighestRow() en hoja Matriz
        $readerFull = IOFactory::createReaderForFile($destination);
        $readerFull->setReadDataOnly(true);
        $readerFull->setLoadSheetsOnly(['Matriz']);
        $spreadsheetFull = $readerFull->load($destination);
        $sheetFull = $spreadsheetFull->getActiveSheet();
        $totalRows = (int)$sheetFull->getHighestRow();
        $highestCol = $sheetFull->getHighestDataColumn();
        $spreadsheetFull->disconnectWorksheets();
        unset($spreadsheetFull);

        if ($totalRows < 2) throw new RuntimeException("El archivo no tiene filas de datos.");

        // 3) Reader liviano para chunks, forzando hoja Matriz
        $reader = IOFactory::createReaderForFile($destination);
        $reader->setReadDataOnly(true);
        $reader->setReadEmptyCells(false);
        $reader->setLoadSheetsOnly(['Matriz']);

        // 4) Leer encabezados fila 1
        $headerFilter = new ChunkReadFilter(); // sin limitar columnas
        $headerFilter->setRows(1, 1);
        $reader->setReadFilter($headerFilter);

        $spreadsheet = $reader->load($destination);
        $sheet = $spreadsheet->getActiveSheet();

        $headerRow = $sheet->rangeToArray("A1:{$highestCol}1", null, true, false)[0];

        $headersMap = [];
        foreach ($headerRow as $i => $h) $headersMap[$i] = norm_txt((string)$h);
        $idxByHeader = array_flip($headersMap);

        $neededHeaders = ['FECHA','SEMANA','RESPONSABLE','AREA','EMPLEADOR','CARGO','RUT','NOMBRE','SEXO','TURNO','%JORNADA','HE'];
        foreach ($neededHeaders as $need) {
            if (!isset($idxByHeader[$need])) throw new RuntimeException("Falta encabezado: {$need}");
        }

        // Columnas necesarias en letras
        $neededColLetters = [];
        foreach ($neededHeaders as $h) $neededColLetters[] = indexToColumnLetter($idxByHeader[$h] + 1);

        $spreadsheet->disconnectWorksheets();
        unset($spreadsheet);

        // 5) Recorrer chunks HASTA totalRows y construir uniques
        $uniques = [
            'Area'      => [],
            'Empleador' => [],
            'Cargo'     => [],
            'Turno'     => [],
        ];

        $preview = [];
        $rowsDetected = 0;

        $chunkSize = 2000;
        $dataFilter = new ChunkReadFilter($neededColLetters);
        $reader->setReadFilter($dataFilter);

        for ($start = 2; $start <= $totalRows; $start += $chunkSize) {
            $dataFilter->setRows($start, $chunkSize);

            $ss = $reader->load($destination);
            $sh = $ss->getActiveSheet();

            $end = min($start + $chunkSize - 1, $totalRows);
            $rows = $sh->rangeToArray("A{$start}:{$highestCol}{$end}", null, true, false);

            foreach ($rows as $rowArr) {
                // fila vacía?
                $any = false;
                foreach ($rowArr as $vv) {
                    if ($vv !== null && trim((string)$vv) !== '') { $any = true; break; }
                }
                if (!$any) continue;

                $get = function(string $header) use ($rowArr, $idxByHeader) {
                    $k = $idxByHeader[$header] ?? null;
                    if ($k === null) return '';
                    $v = $rowArr[$k] ?? '';
                    if ($v === null) return '';
                    if (is_string($v)) return trim($v);
                    return trim((string)$v);
                };

                $fila = [
                    'Fecha'       => $get('FECHA'),
                    'Semana'      => $get('SEMANA'),
                    'Responsable' => $get('RESPONSABLE'),
                    'Area'        => $get('AREA'),
                    'Empleador'   => $get('EMPLEADOR'),
                    'Cargo'       => $get('CARGO'),
                    'Rut'         => $get('RUT'),
                    'Nombre'      => $get('NOMBRE'),
                    'Sexo'        => $get('SEXO'),
                    'Turno'       => $get('TURNO'),
                    '%Jornada'    => $get('%JORNADA'),
                    'HE'          => $get('HE'),
                    'MOD'         => $get('MOD'),
                ];

                $rowsDetected++;

                if (count($preview) < 10) $preview[] = $fila;

                $vArea = $fila['Area'];      if (!is_excel_error($vArea) && $vArea !== '') $uniques['Area'][norm_txt($vArea)]      = $vArea;
                $vEmp  = $fila['Empleador']; if (!is_excel_error($vEmp)  && $vEmp !== '')  $uniques['Empleador'][norm_txt($vEmp)]  = $vEmp;
                $vCarg = $fila['Cargo'];     if (!is_excel_error($vCarg) && $vCarg !== '') $uniques['Cargo'][norm_txt($vCarg)]     = $vCarg;
                $vTurn = $fila['Turno'];     if (!is_excel_error($vTurn) && $vTurn !== '') $uniques['Turno'][norm_txt($vTurn)]     = $vTurn;
            }

            $ss->disconnectWorksheets();
            unset($ss);
        }

        foreach ($uniques as $k => $dict) {
            ksort($dict);
            $uniques[$k] = array_values($dict);
        }

        $_SESSION['asistencia_upload'] = [
            'archivo'   => $newFileName,
            'ruta'      => $destination,
            'totalRows' => $totalRows,
            'detected'  => $rowsDetected,
            'uniques'   => $uniques,
            'preview'   => $preview,
        ];

        $flash_ok = "Archivo guardado y leído correctamente. Filas detectadas: {$rowsDetected}";

    } catch (Throwable $e) {
        $flash_error = $e->getMessage();
    }
}

$data = $_SESSION['asistencia_upload'] ?? null;

include __DIR__ . '/../partials/header.php';
include __DIR__ . '/../partials/navbar_wrapper.php';
?>

<main class="container py-4">

<?php if ($flash_error): ?>
  <div class="alert alert-danger"><?= htmlspecialchars($flash_error) ?></div>
<?php endif; ?>

<?php if ($flash_ok): ?>
  <div class="alert alert-success"><?= htmlspecialchars($flash_ok) ?></div>
<?php endif; ?>

<?php if (!$jefes_ok): ?>
<div class="alert alert-warning">
  <strong>Advertencia:</strong> No hay Jefes de Área registrados (o la tabla aún no existe).
  Configúralos en <a href="<?= BASE_URL ?>/configuraciones/JefeArea.php">Configuraciones → Jefes de Área</a> antes de cargar asistencia.
</div>
<?php endif; ?>

<div class="card shadow-sm mb-4">
  <div class="card-header">1) Subir Excel de Asistencia</div>
  <div class="card-body">
    <div class="row g-3 mb-3">
      <div class="col-12 col-md-8">
        <input type="file" id="archivoInput" class="form-control" accept=".xlsx,.xls">
        <small class="text-muted">Se lee por bloques. No inserta nada aún.</small>
      </div>
    </div>

    <!-- Filtro -->
    <div class="mb-3">
      <label class="form-label fw-semibold">Filtrar datos por:</label>
      <div class="d-flex flex-wrap gap-3 align-items-center">
        <label class="form-check form-check-radio" for="ftTodo">
          <input class="form-check-input" type="radio" name="filtro_tipo" id="ftTodo" value="todo" checked onchange="onFiltroChange()">
          <span class="form-check-box"></span>
          <span class="form-check-label">Todo el archivo</span>
        </label>
        <label class="form-check form-check-radio" for="ftSemana">
          <input class="form-check-input" type="radio" name="filtro_tipo" id="ftSemana" value="semana" onchange="onFiltroChange()">
          <span class="form-check-box"></span>
          <span class="form-check-label">Por Semana</span>
        </label>
        <label class="form-check form-check-radio" for="ftDia">
          <input class="form-check-input" type="radio" name="filtro_tipo" id="ftDia" value="dia" onchange="onFiltroChange()">
          <span class="form-check-box"></span>
          <span class="form-check-label">Por Día</span>
        </label>

        <div id="inputSemana" style="display:none;" class="d-flex gap-2">
          <input type="number" id="filtroSemana" class="form-control form-control-sm" placeholder="Nº semana" min="1" max="53" style="width:110px;">
          <input type="number" id="filtroAnio" class="form-control form-control-sm" placeholder="Año" min="2000" max="2100" style="width:100px;" value="<?= date('Y') ?>">
        </div>
        <div id="inputDia" style="display:none;">
          <input type="date" id="filtroDia" class="form-control form-control-sm" style="width:160px;">
        </div>
      </div>
    </div>

    <!-- Observación -->
    <div class="mb-3">
      <label class="form-label fw-semibold" for="obsInput">Observación <span class="text-muted fw-normal">(opcional)</span></label>
      <input type="text" id="obsInput" class="form-control" maxlength="255"
             placeholder="Ej: Carga semana 14 — corrección turno noche">
    </div>

    <button type="button" class="btn btn-primary" onclick="startRead()">Cargar y agrupar</button>
  </div>
</div>

<div class="mt-3">
  <div class="progress" style="height: 22px; display:none;" id="progressWrap">
    <div class="progress-bar progress-bar-striped progress-bar-animated"
         role="progressbar" style="width:0%" id="progressBar">0%</div>
  </div>
  <div class="small text-muted mt-1" id="progressText"></div>
</div>

<script>
const catalogCounts = {
  areas:       <?= count($areas) ?>,
  empleadores: <?= count($empleadores) ?>,
  cargos:      <?= count($cargos) ?>,
  turnos:      <?= count($turnos) ?>,
};

function onFiltroChange() {
  const tipo = document.querySelector('input[name="filtro_tipo"]:checked').value;
  document.getElementById('inputSemana').style.display = tipo === 'semana' ? '' : 'none';
  document.getElementById('inputDia').style.display    = tipo === 'dia'    ? '' : 'none';
}

async function startRead() {
  const fileInput = document.getElementById('archivoInput');
  if (!fileInput.files.length) return alert("Selecciona un archivo");

  // Validar catálogos
  const faltantes = [];
  if (!catalogCounts.areas)       faltantes.push("Áreas");
  if (!catalogCounts.empleadores) faltantes.push("Contratistas (Empleadores)");
  if (!catalogCounts.cargos)      faltantes.push("Cargos");
  if (!catalogCounts.turnos)      faltantes.push("Turnos");
  if (faltantes.length) {
    alert("Faltan datos en las siguientes tablas de configuración:\n\n• " + faltantes.join("\n• ") + "\n\nCarga los datos antes de continuar.");
    return;
  }

  const tipo = document.querySelector('input[name="filtro_tipo"]:checked').value;
  let valor = '';
  let anio  = '';

  if (tipo === 'semana') {
    valor = document.getElementById('filtroSemana').value.trim();
    if (!valor) return alert("Ingresa el número de semana");
    anio = document.getElementById('filtroAnio').value.trim();
    if (!anio) return alert("Ingresa el año");
  } else if (tipo === 'dia') {
    valor = document.getElementById('filtroDia').value;
    if (!valor) return alert("Selecciona un día");
  }

  const bar  = document.getElementById('progressBar');
  const wrap = document.getElementById('progressWrap');
  const txt  = document.getElementById('progressText');

  const setProgress = (pct, msg, extra = {}) => {
    wrap.style.display = 'block';
    bar.style.width    = pct + '%';
    bar.innerText      = pct + '%';
    let display = msg;
    if (extra.archivo)  display += ' | ' + extra.archivo;
    if (extra.detected !== undefined) display += ' | Detectados: ' + extra.detected;
    txt.innerText = display;
  };

  setProgress(0, 'Preparando...');

  const obs = document.getElementById('obsInput').value.trim();

  const fd = new FormData();
  fd.append('archivo',      fileInput.files[0]);
  fd.append('filtro_tipo',  tipo);
  fd.append('filtro_valor', valor);
  fd.append('obs',          obs);
  if (anio) fd.append('filtro_anio', anio);

  // Animación de puntos para fases de espera
  let dotsTimer = null;
  function startDots(baseMsg) {
    let n = 0;
    txt.innerText = baseMsg;
    dotsTimer = setInterval(() => {
      n = (n + 1) % 4;
      txt.innerText = baseMsg + '.'.repeat(n);
    }, 400);
  }
  function stopDots() {
    if (dotsTimer) { clearInterval(dotsTimer); dotsTimer = null; }
  }

  // 1) Subir archivo con progreso real (XHR)
  const upJson = await new Promise((resolve, reject) => {
    const xhr = new XMLHttpRequest();
    xhr.open('POST', 'carga_asistencia_ajax_start.php');

    xhr.upload.addEventListener('progress', e => {
      if (e.lengthComputable) {
        stopDots();
        const pct = Math.round(e.loaded / e.total * 25);
        const kb  = Math.round(e.loaded / 1024);
        const tot = Math.round(e.total  / 1024);
        setProgress(pct, `Subiendo archivo... ${kb} KB / ${tot} KB`);
      }
    });

    xhr.upload.addEventListener('load', () => {
      setProgress(25, '');
      startDots('Analizando estructura del archivo');
    });

    xhr.addEventListener('load', () => {
      stopDots();
      try { resolve(JSON.parse(xhr.responseText)); }
      catch(e) { reject(new Error('Respuesta inválida del servidor')); }
    });

    xhr.addEventListener('error', () => { stopDots(); reject(new Error('Error de red al subir')); });
    xhr.send(fd);
  }).catch(err => { alert(err.message); return null; });

  if (!upJson) return;
  if (!upJson.ok) return alert(upJson.error || 'Error al subir');

  const labelFiltro = tipo === 'todo'   ? 'todo el archivo'
                    : tipo === 'semana' ? `semana ${valor} / ${anio}`
                    : `día ${valor}`;

  // 2) Leer chunks con progreso
  let done = false;
  while (!done) {
    startDots('Leyendo datos');
    let json;
    try {
      const res = await fetch('carga_asistencia_ajax_chunk.php', { method:'POST' });
      stopDots();
      const txt = await res.text();
      json = JSON.parse(txt);
    } catch(e) {
      stopDots();
      return alert('Error al leer respuesta del servidor: ' + e.message);
    }
    if (!json.ok) return alert(json.error || 'Error al procesar chunk');

    setProgress(json.pct || 0, json.msg || '', { archivo: json.archivo, detected: json.detected });
    done = !!json.done;
  }

  // Forzar GET para que no reenvíe un POST previo
  window.location.href = window.location.pathname;
}
</script>



<?php if ($data && isset($data['uniques'])): ?>
  <div class="card shadow-sm mb-4">
    <div class="card-header">2) Valores únicos detectados (para asignar códigos)</div>
    <div class="card-body">
      <p class="text-muted mb-2">
        Archivo guardado: <b><?= htmlspecialchars($data['archivo'] ?? '') ?></b><br>
        Filas detectadas: <b><?= (int)($data['detected'] ?? 0) ?></b>
        (totalRows Excel: <?= (int)($data['totalRows'] ?? 0) ?>)<br>
        <?php
          $fTipo  = $data['filtro_tipo']  ?? 'todo';
          $fValor = $data['filtro_valor'] ?? '';
          $fAnio  = (int)($data['filtro_anio'] ?? 0);
          if ($fTipo === 'semana')      echo "Filtro: <b>Semana " . htmlspecialchars($fValor) . ($fAnio ? " / Año {$fAnio}" : "") . "</b>";
          elseif ($fTipo === 'dia')     echo "Filtro: <b>Día " . htmlspecialchars($fValor) . "</b>";
          else                          echo "Filtro: <b>Todo el archivo</b>";
        ?>
      </p>

      <!-- Reset -->
      <form method="POST" class="mb-3">
        <button class="btn btn-outline-secondary" type="submit" name="reset_sesion">Reset</button>
      </form>

      <?php
      // Jefes con turno asignado — confirmar o cambiar turno para esta carga
      function render_responsable_section(array $jefes, array $turnos): void {
          $turnosById      = array_column($turnos, 'name', 'id');
          $jefes_con_turno = array_filter($jefes, fn($j) => $j['id_turno'] > 0);
          if (empty($jefes_con_turno)) return;

          echo '<h5 class="mt-3">Jefes de Área con turno — confirmar o cambiar si rotaron</h5>';
          echo '<div class="table-responsive mb-4">';
          echo '<table class="table table-sm table-bordered"><thead class="thead-dark"><tr>';
          echo '<th>Jefe de Área</th><th>Turno actual</th><th>Turno para esta carga</th></tr></thead><tbody>';
          foreach ($jefes_con_turno as $j) {
              echo '<tr><td>' . htmlspecialchars($j['name']) . '</td>';
              echo '<td>' . htmlspecialchars($turnosById[$j['id_turno']] ?? '—') . '</td>';
              echo '<td><select class="form-control" name="map_responsable_turno[' . (int)$j['id'] . ']">';
              foreach ($turnos as $t) {
                  $sel = ((int)$t['id'] === (int)$j['id_turno']) ? ' selected' : '';
                  echo '<option value="' . (int)$t['id'] . '"' . $sel . '>' . htmlspecialchars($t['name']) . '</option>';
              }
              echo '</select></td></tr>';
          }
          echo '</tbody></table></div>';
      }

      // Helper: muestra todos los valores del período filtrado con dropdown (match pre-seleccionado)
      function render_mapping_section(
          string $titulo,
          array $uniques,
          string $field_name,
          array $catalog,
          array $saved,        // $saved_maps['area'|...] – mapeos persistidos
          string $tipo_key,    // 'area'|'empleador'|'cargo'|'turno' – clave en $saved
          string $extra_class = ''
      ): void {
          echo '<h5 class="mt-3">' . htmlspecialchars($titulo) . '</h5>';

          if (empty($uniques)) {
              echo '<div class="alert alert-secondary py-2 mb-4">Sin valores para este período.</div>';
              return;
          }

          echo '<div class="table-responsive mb-4">';
          echo '<table class="table table-sm table-bordered"><thead class="thead-dark"><tr>'
             . '<th>Valor en Excel</th><th>ID sistema</th></tr></thead><tbody>';
          foreach ($uniques as $val) {
              $key  = norm_txt($val);
              // Prioridad: 1) mapeo guardado, 2) detección por nombre exacto
              $saved_id = $saved[$tipo_key][$key] ?? null;
              $suggest  = $saved_id !== null ? $saved_id : suggest_id($val, $catalog);
              $from_mem = $saved_id !== null;

              $selectClass = $extra_class ? ' ' . $extra_class : '';
              echo '<tr>';
              echo '<td>';
              echo htmlspecialchars($val);
              if ($from_mem) {
                  echo ' <span class="badge bg-info text-dark ms-1" title="Enlace guardado">💾</span>';
              }
              echo '</td>';
              echo '<td><select class="form-control' . $selectClass . '" name="' . htmlspecialchars($field_name) . '[' . htmlspecialchars($key) . ']">';
              echo '<option value="">-- sin asignar --</option>';
              foreach ($catalog as $item) {
                  $selected = ($suggest === (int)$item['id']) ? ' selected' : '';
                  $label    = (int)$item['id'] . ' - ' . htmlspecialchars($item['name']);
                  if (!empty($item['abrev'])) $label .= ' (' . htmlspecialchars($item['abrev']) . ')';
                  echo '<option value="' . (int)$item['id'] . '"' . $selected . '>' . $label . '</option>';
              }
              echo '</select></td></tr>';
          }
          echo '</tbody></table></div>';
      }
      ?>

      <form id="formPaso2" method="POST" action="carga_asistencia_paso2.php">
        <input type="hidden" name="filtro_tipo"  value="<?= htmlspecialchars($data['filtro_tipo']  ?? 'todo') ?>">
        <input type="hidden" name="filtro_valor" value="<?= htmlspecialchars($data['filtro_valor'] ?? '') ?>">
        <input type="hidden" name="filtro_anio"  value="<?= (int)($data['filtro_anio'] ?? 0) ?>">

        <?php if ($jefes_ok): render_responsable_section($jefes, $turnos); endif; ?>
        <?php render_mapping_section('Área',                    $data['uniques']['Area'],      'map_area',      $areas,       $saved_maps, 'area'); ?>
        <?php render_mapping_section('Empleador (Contratista)', $data['uniques']['Empleador'], 'map_empleador', $empleadores, $saved_maps, 'empleador'); ?>
        <?php render_mapping_section('Cargo',                   $data['uniques']['Cargo'],     'map_cargo',     $cargos,      $saved_maps, 'cargo', 'select2-cargo'); ?>
        <?php render_mapping_section('Turno',                   $data['uniques']['Turno'],     'map_turno',     $turnos,      $saved_maps, 'turno'); ?>

        <button id="btnPaso2" class="btn btn-success" type="button" onclick="startPaso2()">
          Continuar a Paso 2 (guardar)
        </button>
      </form>

      <!-- Progreso Paso 2 -->
      <div id="paso2ProgressWrap" style="display:none;" class="mt-3">
        <div class="progress" style="height:22px;">
          <div id="paso2Bar" class="progress-bar progress-bar-striped progress-bar-animated bg-success"
               role="progressbar" style="width:0%">0%</div>
        </div>
        <div id="paso2Txt" class="small text-muted mt-1"></div>
      </div>

    </div>
  </div>
<?php endif; ?>

</main>

<script>
document.addEventListener("DOMContentLoaded", function() {
  if (window.jQuery && jQuery().select2) {
    $('.select2-cargo').select2({ width:'100%', placeholder:'Buscar cargo...', allowClear:true });
  }
});

async function startPaso2() {
  const btn      = document.getElementById('btnPaso2');
  const wrap     = document.getElementById('paso2ProgressWrap');
  const bar      = document.getElementById('paso2Bar');
  const txt      = document.getElementById('paso2Txt');

  btn.disabled = true;
  wrap.style.display = 'block';
  bar.style.width = '0%';
  bar.innerText   = '0%';
  bar.className   = 'progress-bar progress-bar-striped progress-bar-animated bg-success';
  txt.innerText   = 'Preparando...';

  // 1) Enviar mapeos a paso2_start
  const fd = new FormData(document.getElementById('formPaso2'));
  let startJson;
  try {
    const res = await fetch('carga_asistencia_paso2_start.php', { method: 'POST', body: fd });
    startJson = await res.json();
  } catch (e) {
    btn.disabled = false;
    return alert('Error al iniciar Paso 2: ' + e.message);
  }
  if (!startJson.ok) {
    btn.disabled = false;
    bar.className = 'progress-bar bg-danger';
    bar.style.width = '100%';
    txt.innerText = startJson.error || 'Error al iniciar Paso 2';
    return;
  }

  txt.innerText = 'Iniciando carga de registros...';

  // 2) Procesar chunks hasta done
  let done = false;
  let json;
  while (!done) {
    try {
      const res = await fetch('carga_asistencia_paso2_chunk.php', { method: 'POST' });
      json = await res.json();
    } catch (e) {
      btn.disabled = false;
      return alert('Error de red en Paso 2: ' + e.message);
    }

    if (!json.ok) {
      btn.disabled = false;
      bar.className   = 'progress-bar bg-danger';
      bar.style.width = '100%';
      bar.innerText   = 'Error';
      txt.innerText   = json.error || 'Error desconocido';
      return;
    }

    bar.style.width = json.pct + '%';
    bar.innerText   = json.pct + '%';
    txt.innerText   = json.msg
      + (json.inserted !== undefined ? ' | Insertados: ' + json.inserted : '');
    done = !!json.done;
  }

  // Éxito — redirigir a revisión del lote recién cargado
  bar.className = 'progress-bar bg-success';
  bar.style.width = '100%';
  bar.innerText   = '100%';
  txt.innerHTML   = '<span class="text-success fw-bold">Carga completada. Redirigiendo a revisión...</span>';
  const registroFinal = json.registro || '';
  const destino = registroFinal
    ? 'editar_asistencia.php?registro=' + encodeURIComponent(registroFinal)
    : 'carga_asistencia.php';
  setTimeout(() => { window.location.href = destino; }, 2000);
}
</script>

<?php include __DIR__ . '/../partials/footer.php'; ?>