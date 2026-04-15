<?php
declare(strict_types=1);
ob_start();

require_once __DIR__ . '/../_bootstrap.php';
require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../app/lib/storage_cleanup.php';
cleanup_asistencia_storage(7);

use PhpOffice\PhpSpreadsheet\IOFactory;

header('Content-Type: application/json; charset=utf-8');
set_time_limit(0);

function norm_hdr(string $s): string {
    $s = trim($s);
    $s = preg_replace('/\s+/', ' ', $s);
    return mb_strtoupper($s, 'UTF-8');
}

function col_letter(int $idx1): string {
    $letters = '';
    while ($idx1 > 0) {
        $mod = ($idx1 - 1) % 26;
        $letters = chr(65 + $mod) . $letters;
        $idx1 = intdiv(($idx1 - 1), 26);
    }
    return $letters;
}

try {
    if (!isset($_FILES['archivo']) || $_FILES['archivo']['error'] !== UPLOAD_ERR_OK) {
        throw new RuntimeException("No se recibió archivo.");
    }

    unset($_SESSION['asistencia_upload'], $_SESSION['asistencia_state'], $_SESSION['asistencia_progress']);

    $uploadDir = __DIR__ . '/../../storage/asistencia/';
    if (!is_dir($uploadDir)) mkdir($uploadDir, 0777, true);

    $ext = strtolower(pathinfo($_FILES['archivo']['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, ['xlsx', 'xls'], true)) {
        throw new RuntimeException("Formato no permitido. Sube .xlsx o .xls");
    }

    $newFileName = 'asistencia_' . date('Ymd_His') . '_' . uniqid() . '.' . $ext;
    $destination = $uploadDir . $newFileName;
    if (!move_uploaded_file($_FILES['archivo']['tmp_name'], $destination)) {
        throw new RuntimeException("No se pudo guardar el archivo.");
    }

    // Detectar hoja: preferir "Matriz", si no existe usar la primera
    $readerInfo = IOFactory::createReaderForFile($destination);
    $wsInfo = $readerInfo->listWorksheetNames($destination);
    $sheetName = in_array('Matriz', $wsInfo, true) ? 'Matriz' : ($wsInfo[0] ?? null);
    if ($sheetName === null) throw new RuntimeException("El archivo no contiene hojas.");

    $reader = IOFactory::createReaderForFile($destination);
    $reader->setReadDataOnly(true);
    $reader->setLoadSheetsOnly([$sheetName]);

    $ss = $reader->load($destination);
    $sh = $ss->getActiveSheet();
    $totalRows  = (int)$sh->getHighestRow();
    $highestCol = (string)$sh->getHighestDataColumn();
    $headerRow  = $sh->rangeToArray("A1:{$highestCol}1", null, true, false)[0];
    $ss->disconnectWorksheets();
    unset($ss);

    if ($totalRows < 2) throw new RuntimeException("El archivo no tiene filas de datos.");

    $headersMap = [];
    foreach ($headerRow as $i => $h) $headersMap[$i] = norm_hdr((string)$h);
    $idxByHeader = array_flip($headersMap);

    $needed = ['FECHA','SEMANA','RESPONSABLE','AREA','EMPLEADOR','CARGO','RUT','NOMBRE','SEXO','TURNO','%JORNADA','HE'];
    foreach ($needed as $n) {
        if (!isset($idxByHeader[$n])) throw new RuntimeException("Falta encabezado: {$n}");
    }

    $neededColLetters = [];
    foreach ($needed as $h) $neededColLetters[] = col_letter($idxByHeader[$h] + 1);

    // ESPECIE es opcional — si existe en el Excel se incluye en la lectura
    if (isset($idxByHeader['ESPECIE'])) {
        $neededColLetters[] = col_letter($idxByHeader['ESPECIE'] + 1);
    }

    $filtroTipo  = in_array($_POST['filtro_tipo'] ?? '', ['semana','dia','todo'], true)
                   ? $_POST['filtro_tipo']
                   : 'todo';
    $filtroValor = trim((string)($_POST['filtro_valor'] ?? ''));
    $filtroAnio  = (int)($_POST['filtro_anio'] ?? 0);
    $obs         = mb_substr(trim((string)($_POST['obs'] ?? '')), 0, 255);

    // Archivo NDJSON para guardar solo las filas que pasan el filtro
    $filteredFile = $uploadDir . 'filtered_' . pathinfo($newFileName, PATHINFO_FILENAME) . '.ndjson';
    file_put_contents($filteredFile, ''); // crear/limpiar

    $_SESSION['asistencia_state'] = [
        'archivo'          => $newFileName,
        'ruta'             => $destination,
        'totalRows'        => $totalRows,
        'highestCol'       => $highestCol,
        'nextRow'          => 2,
        'chunkSize'        => 2000,
        'rowsDetected'     => 0,
        'preview'          => [],
        'uniques'          => ['Area'=>[], 'Empleador'=>[], 'Cargo'=>[], 'Turno'=>[]],
        'idxByHeader'      => $idxByHeader,
        'neededColLetters' => $neededColLetters,
        'sheetName'        => $sheetName,
        'filtro_tipo'      => $filtroTipo,
        'filtro_valor'     => $filtroValor,
        'filtro_anio'      => $filtroAnio,
        'filtered_file'    => $filteredFile,
        'obs'              => $obs,
    ];

    $_SESSION['asistencia_progress'] = ['pct'=>0, 'msg'=>'Archivo subido, iniciando lectura...', 'done'=>false];

    session_write_close();
    ob_end_clean();
    echo json_encode(['ok'=>true, 'totalRows'=>$totalRows]);

} catch (Throwable $e) {
    session_write_close();
    ob_end_clean();
    echo json_encode(['ok'=>false, 'error'=>$e->getMessage()]);
}
