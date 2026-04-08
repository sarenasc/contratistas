<?php
declare(strict_types=1);
ob_start(); // captura cualquier output inesperado (notices, warnings) para no corromper el JSON

require_once __DIR__ . '/../_bootstrap.php';
require_once __DIR__ . '/../../vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Reader\IReadFilter;

header('Content-Type: application/json; charset=utf-8');
set_time_limit(0);

class ChunkFilter implements IReadFilter {
    private int $start = 1;
    private int $end   = 1;
    private array $cols = [];

    public function __construct(array $cols = []) {
        foreach ($cols as $c) $this->cols[strtoupper($c)] = true;
    }

    public function setRows(int $start, int $size): void {
        $this->start = $start;
        $this->end   = $start + $size - 1;
    }

    public function readCell($col, $row, $ws = ''): bool {
        if ($row < $this->start || $row > $this->end) return false;
        if (empty($this->cols)) return true;
        return isset($this->cols[strtoupper($col)]);
    }
}

function norm_u(string $s): string {
    $s = trim($s);
    $s = preg_replace('/\s+/', ' ', $s);
    return mb_strtoupper($s, 'UTF-8');
}

function is_excel_error(string $s): bool {
    return $s !== '' && $s[0] === '#';
}

function excel_date_to_ymd($value): string {
    if ($value === null || trim((string)$value) === '') return '';
    if ($value instanceof DateTime) return $value->format('Y-m-d');
    if (is_numeric($value)) {
        $base = new DateTime('1899-12-30');
        $base->modify('+' . (int)floor((float)$value) . ' days');
        return $base->format('Y-m-d');
    }
    $s = str_replace(['.','\\'], '/', trim((string)$value));
    foreach (['d/m/Y','Y-m-d','d-m-Y','Y/m/d'] as $fmt) {
        $dt = DateTime::createFromFormat($fmt, $s);
        if ($dt) return $dt->format('Y-m-d');
    }
    return '';
}

try {
    $state = $_SESSION['asistencia_state'] ?? null;
    if (!$state) throw new RuntimeException("Sin estado de sesión. Reinicia el proceso.");

    $ruta             = $state['ruta'];
    $totalRows        = $state['totalRows'];
    $highestCol       = $state['highestCol'];
    $nextRow          = $state['nextRow'];
    $chunkSize        = $state['chunkSize'];
    $idxByHeader      = $state['idxByHeader'];
    $neededColLetters = $state['neededColLetters'];
    $uniques          = $state['uniques'];
    $preview          = $state['preview'];
    $rowsDetected     = $state['rowsDetected'];
    $filtroTipo       = $state['filtro_tipo']  ?? 'todo';
    $filtroValor      = $state['filtro_valor'] ?? '';
    $filtroAnio       = (int)($state['filtro_anio'] ?? 0);
    $sheetName        = $state['sheetName']    ?? null;
    $filteredFile     = $state['filtered_file'] ?? null;
    $obs              = $state['obs']           ?? '';
    $especieIdx       = $idxByHeader['ESPECIE'] ?? null;

    // Procesar chunk
    $reader = IOFactory::createReaderForFile($ruta);
    $reader->setReadDataOnly(true);
    $reader->setReadEmptyCells(false);
    if ($sheetName) $reader->setLoadSheetsOnly([$sheetName]);

    $filter = new ChunkFilter($neededColLetters);
    $filter->setRows($nextRow, $chunkSize);
    $reader->setReadFilter($filter);

    $ss = $reader->load($ruta);
    $sh = $ss->getActiveSheet();
    $end = min($nextRow + $chunkSize - 1, $totalRows);
    $rows = $sh->rangeToArray("A{$nextRow}:{$highestCol}{$end}", null, true, false);
    $ss->disconnectWorksheets();
    unset($ss);

    $get = function(string $header) use ($idxByHeader, &$rowArr): string {
        $k = $idxByHeader[$header] ?? null;
        if ($k === null) return '';
        $v = $rowArr[$k] ?? '';
        if ($v === null) return '';
        return is_string($v) ? trim($v) : trim((string)$v);
    };

    foreach ($rows as $rowArr) {
        $any = false;
        foreach ($rowArr as $vv) {
            if ($vv !== null && trim((string)$vv) !== '') { $any = true; break; }
        }
        if (!$any) continue;

        // Aplicar filtro
        if ($filtroTipo === 'semana') {
            $semanaRaw = $rowArr[$idxByHeader['SEMANA']] ?? '';
            $semanaVal = (int)round((float)$semanaRaw);  // normaliza 14.0 → 14
            if ($semanaVal !== (int)$filtroValor) continue;
            // Validar año usando la columna FECHA
            if ($filtroAnio > 0) {
                $fechaAnio = excel_date_to_ymd($rowArr[$idxByHeader['FECHA']] ?? '');
                $anioFila  = $fechaAnio !== '' ? (int)substr($fechaAnio, 0, 4) : 0;
                if ($anioFila !== $filtroAnio) continue;
            }
        } elseif ($filtroTipo === 'dia') {
            $fechaRaw = $rowArr[$idxByHeader['FECHA']] ?? '';
            $fechaStr = excel_date_to_ymd($fechaRaw);
            if ($fechaStr !== $filtroValor) continue;
        }

        $rowsDetected++;

        if (count($preview) < 10) {
            $preview[] = [
                'Fecha'     => $get('FECHA'),
                'Semana'    => $get('SEMANA'),
                'Area'      => $get('AREA'),
                'Empleador' => $get('EMPLEADOR'),
                'Cargo'     => $get('CARGO'),
            ];
        }

        $vArea = $get('AREA');      if (!is_excel_error($vArea) && $vArea !== '') $uniques['Area'][norm_u($vArea)]      = $vArea;
        $vEmp  = $get('EMPLEADOR');  if (!is_excel_error($vEmp)  && $vEmp !== '')  $uniques['Empleador'][norm_u($vEmp)]    = $vEmp;
        $vCarg = $get('CARGO');      if (!is_excel_error($vCarg) && $vCarg !== '') $uniques['Cargo'][norm_u($vCarg)]       = $vCarg;
        $vTurn = $get('TURNO');      if (!is_excel_error($vTurn) && $vTurn !== '') $uniques['Turno'][norm_u($vTurn)]       = $vTurn;

        // Guardar fila filtrada en NDJSON para que paso2 no reescanee el Excel
        if ($filteredFile !== null) {
            $eRaw = $especieIdx !== null ? trim((string)($rowArr[$especieIdx] ?? '')) : '';
            $rowData = [
                'FECHA'       => excel_date_to_ymd($rowArr[$idxByHeader['FECHA']] ?? ''),
                'SEMANA'      => (int)round((float)($rowArr[$idxByHeader['SEMANA']] ?? 0)),
                'RESPONSABLE' => $get('RESPONSABLE'),
                'AREA'        => $vArea,
                'EMPLEADOR'   => $vEmp,
                'CARGO'       => $vCarg,
                'RUT'         => $get('RUT'),
                'NOMBRE'      => $get('NOMBRE'),
                'SEXO'        => $get('SEXO'),
                'TURNO'       => $vTurn,
                'JORNADA'     => (float)str_replace(',', '.', $get('%JORNADA')),
                'HHEE'        => (float)str_replace(',', '.', $get('HE')),
                'ESPECIE'     => ($eRaw !== '' && ($eRaw[0] ?? '') !== '#') ? $eRaw : '',
            ];
            file_put_contents($filteredFile, json_encode($rowData, JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND | LOCK_EX);
        }
    }

    $nextRow += $chunkSize;
    $done = ($nextRow > $totalRows);
    $pct  = (int)min(100, round(($end - 1) / max(1, $totalRows - 1) * 100));
    $msg  = "Procesando fila {$end} de {$totalRows}...";

    // Actualizar estado
    $state['nextRow']      = $nextRow;
    $state['uniques']      = $uniques;
    $state['preview']      = $preview;
    $state['rowsDetected'] = $rowsDetected;
    $_SESSION['asistencia_state'] = $state;

    if ($done) {
        foreach ($uniques as $k => $dict) {
            ksort($dict);
            $uniques[$k] = array_values($dict);
        }

        $_SESSION['asistencia_upload'] = [
            'archivo'       => $state['archivo'],
            'ruta'          => $state['ruta'],
            'totalRows'     => $totalRows,
            'detected'      => $rowsDetected,
            'uniques'       => $uniques,
            'preview'       => $preview,
            'filtro_tipo'   => $filtroTipo,
            'filtro_valor'  => $filtroValor,
            'filtro_anio'   => $filtroAnio,
            'filtered_file' => $filteredFile,
            'obs'           => $obs,
        ];

        unset($_SESSION['asistencia_state']);
        $pct = 100;
        $msg = "Completado: {$rowsDetected} filas detectadas.";
        $_SESSION['asistencia_progress'] = ['pct'=>100, 'msg'=>$msg, 'done'=>true];
    } else {
        $_SESSION['asistencia_progress'] = ['pct'=>$pct, 'msg'=>$msg, 'done'=>false];
    }

    session_write_close(); // escribe y libera la sesión antes de enviar la respuesta
    ob_end_clean();
    echo json_encode(['ok'=>true, 'pct'=>$pct, 'done'=>$done, 'msg'=>$msg,
                      'detected'=>$rowsDetected, 'archivo'=>$state['archivo']]);

} catch (Throwable $e) {
    session_write_close();
    ob_end_clean();
    echo json_encode(['ok'=>false, 'error'=>$e->getMessage()]);
}
