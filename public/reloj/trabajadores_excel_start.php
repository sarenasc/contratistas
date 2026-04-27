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

final class WorkerHeaderFilter implements IReadFilter
{
    public function readCell($columnAddress, $row, $worksheetName = ''): bool
    {
        return (int)$row === 1;
    }
}

function worker_import_dir(): string
{
    $dir = __DIR__ . '/../../storage/reloj_import/';
    if (!is_dir($dir)) {
        mkdir($dir, 0777, true);
    }
    return $dir;
}

function worker_norm_txt(string $value): string
{
    $value = trim($value);
    $value = preg_replace('/\s+/', ' ', $value);
    return mb_strtoupper($value, 'UTF-8');
}

function worker_col_letter(int $idx1): string
{
    $letters = '';
    while ($idx1 > 0) {
        $mod = ($idx1 - 1) % 26;
        $letters = chr(65 + $mod) . $letters;
        $idx1 = intdiv(($idx1 - 1), 26);
    }
    return $letters;
}

try {
    if (!isset($_FILES['archivo_excel']) || $_FILES['archivo_excel']['error'] !== UPLOAD_ERR_OK) {
        throw new RuntimeException('Selecciona un archivo Excel valido.');
    }

    $oldData = $_SESSION[RELOJ_IMPORT_SESSION_KEY] ?? null;
    $oldState = $_SESSION[RELOJ_IMPORT_STATE_KEY] ?? null;
    foreach ([$oldData['file'] ?? null, $oldState['ruta'] ?? null] as $oldFile) {
        if (!empty($oldFile) && is_string($oldFile) && file_exists($oldFile)) {
            @unlink($oldFile);
        }
    }
    unset($_SESSION[RELOJ_IMPORT_SESSION_KEY], $_SESSION[RELOJ_IMPORT_STATE_KEY], $_SESSION[RELOJ_IMPORT_PROGRESS_KEY]);

    $ext = strtolower(pathinfo($_FILES['archivo_excel']['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, ['xlsx', 'xls'], true)) {
        throw new RuntimeException('Formato no permitido. Sube .xlsx o .xls');
    }

    $dest = worker_import_dir() . 'workers_' . date('Ymd_His') . '_' . uniqid('', true) . '.' . $ext;
    if (!move_uploaded_file($_FILES['archivo_excel']['tmp_name'], $dest)) {
        throw new RuntimeException('No se pudo guardar el archivo subido.');
    }

    $readerInfo = IOFactory::createReaderForFile($dest);
    $sheetNames = $readerInfo->listWorksheetNames($dest);
    $sheetName = in_array('Matriz', $sheetNames, true) ? 'Matriz' : ($sheetNames[0] ?? null);
    if ($sheetName === null) {
        throw new RuntimeException('El archivo no contiene hojas.');
    }

    $sheetInfoList = $readerInfo->listWorksheetInfo($dest);
    $sheetInfo = null;
    foreach ($sheetInfoList as $info) {
        if (($info['worksheetName'] ?? '') === $sheetName) {
            $sheetInfo = $info;
            break;
        }
    }
    if ($sheetInfo === null) {
        throw new RuntimeException('No se pudo leer la hoja seleccionada.');
    }

    $totalRows = (int)($sheetInfo['totalRows'] ?? 0);
    $highestCol = (string)($sheetInfo['lastColumnLetter'] ?? 'A');
    if ($totalRows < 2) {
        throw new RuntimeException('El archivo no tiene filas de datos.');
    }

    $reader = IOFactory::createReaderForFile($dest);
    $reader->setReadDataOnly(true);
    $reader->setLoadSheetsOnly([$sheetName]);
    $reader->setReadFilter(new WorkerHeaderFilter());

    $spreadsheet = $reader->load($dest);
    $sheet = $spreadsheet->getActiveSheet();
    $headerRow = $sheet->rangeToArray("A1:{$highestCol}1", null, true, false)[0];
    $spreadsheet->disconnectWorksheets();
    unset($spreadsheet);

    $headersMap = [];
    foreach ($headerRow as $i => $header) {
        $headersMap[$i] = worker_norm_txt((string)$header);
    }
    $idxByHeader = array_flip($headersMap);

    $required = ['AREA', 'EMPLEADOR', 'CARGO', 'RUT', 'NOMBRE', 'TURNO'];
    foreach ($required as $need) {
        if (!isset($idxByHeader[$need])) {
            throw new RuntimeException("Falta encabezado: {$need}");
        }
    }

    $neededColLetters = [];
    foreach ($required as $header) {
        $neededColLetters[] = worker_col_letter($idxByHeader[$header] + 1);
    }

    $_SESSION[RELOJ_IMPORT_STATE_KEY] = [
        'file' => $dest,
        'ruta' => $dest,
        'sheetName' => $sheetName,
        'totalRows' => $totalRows,
        'highestCol' => $highestCol,
        'nextRow' => 2,
        'chunkSize' => 750,
        'idxByHeader' => $idxByHeader,
        'neededColLetters' => $neededColLetters,
        'groupedPeople' => [],
        'uniques' => ['area' => [], 'empleador' => [], 'cargo' => [], 'turno' => []],
    ];
    $_SESSION[RELOJ_IMPORT_PROGRESS_KEY] = [
        'pct' => 2,
        'msg' => 'Archivo subido. Iniciando lectura...',
        'done' => false,
    ];

    session_write_close();
    ob_end_clean();
    echo json_encode([
        'ok' => true,
        'totalRows' => $totalRows,
        'msg' => "Archivo subido. Leyendo hoja {$sheetName}...",
    ]);
} catch (Throwable $e) {
    session_write_close();
    ob_end_clean();
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
