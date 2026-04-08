<?php
declare(strict_types=1);

require_once __DIR__ . '/../_bootstrap.php';
require_once __DIR__ . '/../../app/lib/db.php';
require_once __DIR__ . '/../../vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Reader\IReadFilter;

$title = "Asistencia";
include __DIR__ . '/../partials/header.php';
include __DIR__ . '/../partials/navbar_wrapper.php';

set_time_limit(0);

$flash_error = null;
$flash_ok    = null;

function norm_key(string $s): string {
    $s = trim($s);
    $s = preg_replace('/\s+/', ' ', $s);
    return mb_strtoupper($s, 'UTF-8');
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

class ChunkReadFilter implements IReadFilter {
    private int $startRow = 1;
    private int $endRow = 1;
    private array $columnsAllowed = [];

    public function __construct(array $columnsAllowed = []) {
        foreach ($columnsAllowed as $c) {
            $this->columnsAllowed[strtoupper($c)] = true;
        }
    }

    public function setRows(int $startRow, int $chunkSize): void {
        $this->startRow = $startRow;
        $this->endRow = $startRow + $chunkSize - 1;
    }

    public function readCell($columnAddress, $row, $worksheetName = ''): bool {
        if ($row < $this->startRow || $row > $this->endRow) return false;
        if (empty($this->columnsAllowed)) return true;
        return isset($this->columnsAllowed[strtoupper($columnAddress)]);
    }
}

function parse_excel_date($value): ?DateTime {
    if ($value === null) return null;
    if ($value instanceof DateTime) return $value;

    if (is_numeric($value)) {
        $base = new DateTime('1899-12-30 00:00:00');
        $days = (float)$value;
        $seconds = (int)round(($days - floor($days)) * 86400);
        $base->modify('+' . (int)floor($days) . ' days');
        $base->modify('+' . $seconds . ' seconds');
        return $base;
    }

    $s = trim((string)$value);
    if ($s === '') return null;

    $s = str_replace(['.', '\\'], ['/', '/'], $s);

    $formats = [
        'd-m-Y H:i:s','d-m-Y H:i','d-m-Y',
        'd/m/Y H:i:s','d/m/Y H:i','d/m/Y',
        'Y-m-d H:i:s','Y-m-d H:i','Y-m-d',
        'Y/m/d H:i:s','Y/m/d H:i','Y/m/d',
    ];

    foreach ($formats as $fmt) {
        $dt = DateTime::createFromFormat($fmt, $s);
        if ($dt instanceof DateTime) {
            $errors = DateTime::getLastErrors();
            if (($errors['warning_count'] ?? 0) === 0 && ($errors['error_count'] ?? 0) === 0) {
                return $dt;
            }
        }
    }

    $ts = strtotime($s);
    if ($ts !== false) return (new DateTime())->setTimestamp($ts);

    return null;
}

$data = $_SESSION['asistencia_upload'] ?? null;
if (!$data) {
    $flash_error = "No hay datos cargados en sesión. Vuelve al Paso 1 y carga el Excel.";
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $data) {
    try {
        $archivo = $data['archivo'] ?? '';
        $ruta    = $data['ruta'] ?? '';
        $uniques = $data['uniques'] ?? null;

        if ($archivo === '' || $ruta === '' || !file_exists($ruta)) {
            throw new RuntimeException("No se encontró el archivo guardado. Vuelve al Paso 1 y carga nuevamente.");
        }
        if (!$uniques) {
            throw new RuntimeException("No están los uniques en sesión. Vuelve al Paso 1.");
        }

        $map_responsable_turno = $_POST['map_responsable_turno'] ?? [];
        $map_area              = $_POST['map_area']              ?? [];
        $map_empleador         = $_POST['map_empleador']         ?? [];
        $map_cargo             = $_POST['map_cargo']             ?? [];
        $map_turno             = $_POST['map_turno']             ?? [];

        $filtroTipo  = in_array($_POST['filtro_tipo'] ?? '', ['semana','dia','todo'], true)
                       ? $_POST['filtro_tipo'] : 'todo';
        $filtroValor = trim((string)($_POST['filtro_valor'] ?? ''));
        $filtroAnio  = (int)($_POST['filtro_anio'] ?? 0);

        foreach ($uniques['Area'] as $val)      if (empty($map_area[norm_key($val)]))      throw new RuntimeException("Falta asignar Area: $val");
        foreach ($uniques['Empleador'] as $val) if (empty($map_empleador[norm_key($val)])) throw new RuntimeException("Falta asignar Empleador: $val");
        foreach ($uniques['Cargo'] as $val)     if (empty($map_cargo[norm_key($val)]))     throw new RuntimeException("Falta asignar Cargo: $val");
        foreach ($uniques['Turno'] as $val)     if (empty($map_turno[norm_key($val)]))     throw new RuntimeException("Falta asignar Turno: $val");

        // Aplicar overrides de turno para jefes que rotaron — clave = jefe_id
        foreach ($map_responsable_turno as $jefeId => $turnoId) {
            $jefeId  = (int)$jefeId;
            $turnoId = (int)$turnoId;
            if ($turnoId > 0 && $jefeId > 0) {
                sqlsrv_query($conn, "UPDATE dbo.dota_jefe_area SET id_turno = ? WHERE id = ?", [$turnoId, $jefeId]);
            }
        }

        // Construir mapa area+turno → jefe_id para auto-detectar el jefe de cada fila
        // Clave: "{area_id}_{turno_id}" o "{area_id}_0" para jefes sin turno específico
        $jefeByAreaTurno = [];
        $chkJ = sqlsrv_query($conn, "SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA='dbo' AND TABLE_NAME='dota_jefe_area'");
        if ($chkJ && sqlsrv_fetch($chkJ)) {
            $stmtJ = sqlsrv_query($conn, "SELECT id, id_area, id_turno FROM dbo.dota_jefe_area WHERE activo = 1");
            if ($stmtJ) {
                while ($rj = sqlsrv_fetch_array($stmtJ, SQLSRV_FETCH_ASSOC)) {
                    $k = (int)$rj['id_area'] . '_' . (int)($rj['id_turno'] ?? 0);
                    $jefeByAreaTurno[$k] = (int)$rj['id'];
                }
                sqlsrv_free_stmt($stmtJ);
            }
        }
        if ($chkJ) sqlsrv_free_stmt($chkJ);

        // totalRows real usando getHighestRow() — detectar hoja correcta
        $wsNames  = IOFactory::createReaderForFile($ruta)->listWorksheetNames($ruta);
        $sheetName = in_array('Matriz', $wsNames, true) ? 'Matriz' : ($wsNames[0] ?? null);
        if (!$sheetName) throw new RuntimeException("El archivo no contiene hojas.");

        $readerInfo = IOFactory::createReaderForFile($ruta);
        $readerInfo->setReadDataOnly(true);
        $readerInfo->setLoadSheetsOnly([$sheetName]);
        $ssFull = $readerInfo->load($ruta);
        $totalRows = (int)$ssFull->getActiveSheet()->getHighestRow();
        $ssFull->disconnectWorksheets();
        unset($ssFull);
        if ($totalRows < 2) throw new RuntimeException("El archivo no tiene filas de datos.");

        // Reader para lectura liviana
        $reader = IOFactory::createReaderForFile($ruta);
        $reader->setReadDataOnly(true);
        $reader->setReadEmptyCells(false);
        $reader->setLoadSheetsOnly([$sheetName]);

        // Leer encabezado (fila 1)
        $headerFilter = new ChunkReadFilter(); // sin limitar columnas
        $headerFilter->setRows(1, 1);
        $reader->setReadFilter($headerFilter);

        $spreadsheet = $reader->load($ruta);
        $sheet = $spreadsheet->getActiveSheet();
        $highestCol = $sheet->getHighestDataColumn();
        $headerRow = $sheet->rangeToArray("A1:{$highestCol}1", null, true, false)[0];

        $headersMap = [];
        foreach ($headerRow as $i => $h) $headersMap[$i] = norm_key((string)$h);
        $idxByHeader = array_flip($headersMap);

        $expected = ['FECHA','SEMANA','RESPONSABLE','AREA','EMPLEADOR','CARGO','RUT','NOMBRE','SEXO','TURNO','%JORNADA','HE'];
        foreach ($expected as $need) {
            if (!isset($idxByHeader[$need])) throw new RuntimeException("El Excel no trae el encabezado esperado: {$need}");
        }

        // Columnas necesarias (letras)
        $neededColLetters = [];
        foreach ($expected as $h) {
            $neededColLetters[] = indexToColumnLetter($idxByHeader[$h] + 1);
        }

        // ESPECIE es opcional — si existe en el Excel se lee por fila
        $especieIdx = $idxByHeader['ESPECIE'] ?? null;
        if ($especieIdx !== null) {
            $neededColLetters[] = indexToColumnLetter($especieIdx + 1);
        }

        $spreadsheet->disconnectWorksheets();
        unset($spreadsheet);

        // Transacción
        if (!sqlsrv_begin_transaction($conn)) {
            throw new RuntimeException("No se pudo iniciar transacción: " . print_r(sqlsrv_errors(), true));
        }

        $sql = "
            INSERT INTO dbo.dota_asistencia_carga (
                fecha, semana, responsable, area, empleador, cargo,
                rut, nombre, sexo, turno, jornada, hhee, especie, obs, registro, id_jefe
            )
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ";

        $chunkSize = 800;
        $inserted = 0;

        $dataFilter = new ChunkReadFilter($neededColLetters);
        $reader->setReadFilter($dataFilter);

        for ($start = 2; $start <= $totalRows; $start += $chunkSize) {
            $dataFilter->setRows($start, $chunkSize);

            $spreadsheetChunk = $reader->load($ruta);
            $sheetChunk = $spreadsheetChunk->getActiveSheet();

            $end = min($start + $chunkSize - 1, $totalRows);
            $rows = $sheetChunk->rangeToArray("A{$start}:{$highestCol}{$end}", null, true, false);

            foreach ($rows as $rowIndexInChunk => $rowArr) {

                $get = function(string $header) use ($rowArr, $idxByHeader) {
                    $k = $idxByHeader[$header] ?? null;
                    if ($k === null) return '';
                    $v = $rowArr[$k] ?? '';
                    if ($v === null) return '';
                    if (is_string($v)) return trim($v);
                    if ($v instanceof DateTime) return $v->format('Y-m-d H:i:s');
                    return trim((string)$v);
                };

                $any = false;
                foreach ($rowArr as $vv) { if (trim((string)$vv) !== '') { $any = true; break; } }
                if (!$any) continue;

                // Aplicar filtro
                if ($filtroTipo === 'semana') {
                    $semanaRaw = $rowArr[$idxByHeader['SEMANA']] ?? '';
                    $semanaVal = (int)round((float)$semanaRaw);
                    if ($semanaVal !== (int)$filtroValor) continue;
                    if ($filtroAnio > 0) {
                        $dtAnio = parse_excel_date($rowArr[$idxByHeader['FECHA']] ?? null);
                        if (!$dtAnio || (int)$dtAnio->format('Y') !== $filtroAnio) continue;
                    }
                } elseif ($filtroTipo === 'dia') {
                    $fechaRawFlt = $rowArr[$idxByHeader['FECHA']] ?? null;
                    $dtFlt = parse_excel_date($fechaRawFlt);
                    if (!$dtFlt || $dtFlt->format('Y-m-d') !== $filtroValor) continue;
                }

                $fechaRaw = $get('FECHA');
                $dt = parse_excel_date($fechaRaw);
                if (!$dt) {
                    $filaReal = $start + $rowIndexInChunk;
                    throw new RuntimeException("Fecha inválida en fila Excel #{$filaReal} (valor: '{$fechaRaw}').");
                }

                $area_id      = (int)$map_area[norm_key($get('AREA'))];
                $empleador_id = (int)$map_empleador[norm_key($get('EMPLEADOR'))];
                $cargo_id     = (int)$map_cargo[norm_key($get('CARGO'))];
                $turno_id     = (int)$map_turno[norm_key($get('TURNO'))];

                // Auto-detectar jefe por area+turno; si no hay match exacto, buscar jefe sin turno del área
                $id_jefe = $jefeByAreaTurno[$area_id . '_' . $turno_id]
                        ?? $jefeByAreaTurno[$area_id . '_0']
                        ?? null;

                // ESPECIE: opcional — solo si la columna existe y el valor no está vacío ni es error
                $especie_val = null;
                if ($especieIdx !== null) {
                    $eRaw = trim((string)($rowArr[$especieIdx] ?? ''));
                    if ($eRaw !== '' && ($eRaw[0] ?? '') !== '#') $especie_val = $eRaw;
                }

                $jornada = (float)str_replace(',', '.', (string)$get('%JORNADA'));
                $hhee    = (float)str_replace(',', '.', (string)$get('HE'));

                $params = [
                    $dt,
                    (int)$get('SEMANA'),
                    (string)$get('RESPONSABLE'),
                    $area_id,
                    $empleador_id,
                    $cargo_id,
                    (string)$get('RUT'),
                    (string)$get('NOMBRE'),
                    (string)$get('SEXO'),
                    $turno_id,
                    $jornada,
                    $hhee,
                    $especie_val,
                    null,
                    $archivo,
                    $id_jefe,
                ];

                $stmt = sqlsrv_query($conn, $sql, $params);
                if ($stmt === false) {
                    $filaReal = $start + $rowIndexInChunk;
                    throw new RuntimeException("Error insert en fila Excel #{$filaReal}: " . print_r(sqlsrv_errors(), true));
                }

                $inserted++;
            }

            $spreadsheetChunk->disconnectWorksheets();
            unset($spreadsheetChunk);
        }

        sqlsrv_commit($conn);
        unset($_SESSION['asistencia_upload']);

        $flash_ok = "Carga completada ✅ Registros insertados: {$inserted} de " . ($totalRows - 1);

    } catch (Throwable $e) {
        sqlsrv_rollback($conn);
        $flash_error = $e->getMessage();
    }
}
?>

<main class="container py-4">
<?php if ($flash_error): ?>
  <div class="alert alert-danger"><?= htmlspecialchars($flash_error) ?></div>
<?php endif; ?>
<?php if ($flash_ok): ?>
  <div class="alert alert-success"><?= htmlspecialchars($flash_ok) ?></div>
<?php endif; ?>
<div class="mt-3">
  <a href="carga_asistencia.php" class="btn btn-primary">Volver</a>
</div>
</main>

<?php include __DIR__ . '/../partials/footer.php'; ?>