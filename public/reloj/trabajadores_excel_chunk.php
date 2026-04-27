<?php
declare(strict_types=1);
ob_start();

require_once __DIR__ . '/../_bootstrap.php';
if (!puede_modulo('reloj')) {
    http_response_code(403);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'error' => 'Sin permisos.']);
    exit;
}

require_once __DIR__ . '/../../vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Reader\IReadFilter;

header('Content-Type: application/json; charset=utf-8');
set_time_limit(0);

const RELOJ_IMPORT_SESSION_KEY = 'reloj_worker_import';
const RELOJ_IMPORT_STATE_KEY = 'reloj_worker_import_state';
const RELOJ_IMPORT_PROGRESS_KEY = 'reloj_worker_import_progress';

final class WorkerChunkFilter implements IReadFilter
{
    private int $startRow = 1;
    private int $endRow = 1;
    private array $columns = [];

    public function __construct(array $columns)
    {
        foreach ($columns as $column) {
            $this->columns[strtoupper((string)$column)] = true;
        }
    }

    public function setRows(int $startRow, int $size): void
    {
        $this->startRow = $startRow;
        $this->endRow = $startRow + $size - 1;
    }

    public function readCell($columnAddress, $row, $worksheetName = ''): bool
    {
        if ((int)$row < $this->startRow || (int)$row > $this->endRow) {
            return false;
        }
        return isset($this->columns[strtoupper((string)$columnAddress)]);
    }
}

function worker_norm_txt(string $value): string
{
    $value = trim($value);
    $value = preg_replace('/\s+/', ' ', $value);
    return mb_strtoupper($value, 'UTF-8');
}

function worker_extract_rut_digits($rut): int
{
    return (int)preg_replace('/[^0-9]/', '', (string)$rut);
}

try {
    $state = $_SESSION[RELOJ_IMPORT_STATE_KEY] ?? null;
    if (!$state) {
        throw new RuntimeException('Sin estado de importacion. Vuelve a cargar el Excel.');
    }

    $ruta = (string)$state['ruta'];
    $sheetName = (string)$state['sheetName'];
    $totalRows = (int)$state['totalRows'];
    $highestCol = (string)$state['highestCol'];
    $nextRow = (int)$state['nextRow'];
    $chunkSize = (int)$state['chunkSize'];
    $idxByHeader = $state['idxByHeader'];
    $neededColLetters = $state['neededColLetters'];
    $groupedPeople = $state['groupedPeople'];
    $uniques = $state['uniques'];

    $reader = IOFactory::createReaderForFile($ruta);
    $reader->setReadDataOnly(true);
    $reader->setReadEmptyCells(false);
    $reader->setLoadSheetsOnly([$sheetName]);

    $filter = new WorkerChunkFilter($neededColLetters);
    $filter->setRows($nextRow, $chunkSize);
    $reader->setReadFilter($filter);

    $spreadsheet = $reader->load($ruta);
    $sheet = $spreadsheet->getActiveSheet();
    $endRow = min($nextRow + $chunkSize - 1, $totalRows);
    $rows = $sheet->rangeToArray("A{$nextRow}:{$highestCol}{$endRow}", null, true, false);
    $spreadsheet->disconnectWorksheets();
    unset($spreadsheet);

    foreach ($rows as $row) {
        $get = static function (string $header) use ($row, $idxByHeader): string {
            $index = $idxByHeader[$header] ?? null;
            if ($index === null) {
                return '';
            }
            return trim((string)($row[$index] ?? ''));
        };

        $nombre = preg_replace('/\s+/', ' ', $get('NOMBRE'));
        if ($nombre === '') {
            continue;
        }

        $nameKey = worker_norm_txt($nombre);
        $areaVal = $get('AREA');
        $emplVal = $get('EMPLEADOR');
        $cargoVal = $get('CARGO');
        $turnoVal = $get('TURNO');
        $rutVal = $get('RUT');

        if (!isset($groupedPeople[$nameKey])) {
            $groupedPeople[$nameKey] = [
                'nombre' => $nombre,
                'rut' => $rutVal,
                'area_excel' => $areaVal,
                'empl_excel' => $emplVal,
                'cargo_excel' => $cargoVal,
                'turno_excel' => $turnoVal,
            ];
        } else {
            foreach ([
                'rut' => $rutVal,
                'area_excel' => $areaVal,
                'empl_excel' => $emplVal,
                'cargo_excel' => $cargoVal,
                'turno_excel' => $turnoVal,
            ] as $field => $value) {
                if ($groupedPeople[$nameKey][$field] === '' && $value !== '') {
                    $groupedPeople[$nameKey][$field] = $value;
                }
            }
        }

        if ($areaVal !== '') {
            $uniques['area'][worker_norm_txt($areaVal)] = $areaVal;
        }
        if ($emplVal !== '') {
            $uniques['empleador'][worker_norm_txt($emplVal)] = $emplVal;
        }
        if ($cargoVal !== '') {
            $uniques['cargo'][worker_norm_txt($cargoVal)] = $cargoVal;
        }
        if ($turnoVal !== '') {
            $uniques['turno'][worker_norm_txt($turnoVal)] = $turnoVal;
        }
    }

    $nextRow = $endRow + 1;
    $done = $nextRow > $totalRows;
    $pct = (int)min(100, round(($endRow - 1) / max(1, $totalRows - 1) * 100));
    $msg = "Leyendo fila {$endRow} de {$totalRows}...";

    $state['nextRow'] = $nextRow;
    $state['groupedPeople'] = $groupedPeople;
    $state['uniques'] = $uniques;
    $_SESSION[RELOJ_IMPORT_STATE_KEY] = $state;

    if ($done) {
        foreach ($uniques as $type => $dict) {
            ksort($dict);
            $uniques[$type] = array_values($dict);
        }

        $existingByRut = [];
        $existingByName = [];
        $qExisting = sqlsrv_query(
            $conn,
            "SELECT id, id_numero, rut, nombre, id_area, id_contratista, id_cargo, id_turno
             FROM dbo.reloj_trabajador"
        );
        while ($qExisting && ($ex = sqlsrv_fetch_array($qExisting, SQLSRV_FETCH_ASSOC))) {
            $existing = [
                'id' => (int)$ex['id'],
                'id_numero' => (int)($ex['id_numero'] ?? 0),
                'rut' => (string)($ex['rut'] ?? ''),
                'nombre' => (string)($ex['nombre'] ?? ''),
                'id_area' => (int)($ex['id_area'] ?? 0),
                'id_contratista' => (int)($ex['id_contratista'] ?? 0),
                'id_cargo' => (int)($ex['id_cargo'] ?? 0),
                'id_turno' => (int)($ex['id_turno'] ?? 0),
            ];
            if ($existing['id_numero'] > 0) {
                $existingByRut[$existing['id_numero']] = $existing;
            }
            $existingByName[worker_norm_txt($existing['nombre'])] = $existing;
        }

        foreach ($groupedPeople as $key => &$person) {
            $rutDigits = worker_extract_rut_digits($person['rut'] ?? '');
            $existing = null;
            if ($rutDigits > 0 && isset($existingByRut[$rutDigits])) {
                $existing = $existingByRut[$rutDigits];
            } elseif (isset($existingByName[$key])) {
                $existing = $existingByName[$key];
            }

            $person['rut_digits'] = $rutDigits;
            $person['existing'] = $existing;
            $person['is_incomplete_existing'] = $existing
                ? (!$existing['id_area'] || !$existing['id_contratista'] || !$existing['id_cargo'] || !$existing['id_turno'])
                : false;
        }
        unset($person);

        $_SESSION[RELOJ_IMPORT_SESSION_KEY] = [
            'file' => $ruta,
            'people' => array_values($groupedPeople),
            'uniques' => $uniques,
            'preview_count' => count($groupedPeople),
        ];
        unset($_SESSION[RELOJ_IMPORT_STATE_KEY]);

        $pct = 100;
        $msg = 'Excel leido correctamente. Preparando vista previa...';
        $_SESSION[RELOJ_IMPORT_PROGRESS_KEY] = ['pct' => 100, 'msg' => $msg, 'done' => true];
    } else {
        $_SESSION[RELOJ_IMPORT_PROGRESS_KEY] = ['pct' => $pct, 'msg' => $msg, 'done' => false];
    }

    session_write_close();
    ob_end_clean();
    echo json_encode([
        'ok' => true,
        'pct' => $pct,
        'done' => $done,
        'msg' => $msg,
        'preview_count' => $done ? count($groupedPeople) : null,
    ]);
} catch (Throwable $e) {
    session_write_close();
    ob_end_clean();
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
