<?php
// Responder preflight CORS antes del bootstrap (evita que auth_guard redirija OPTIONS)
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

require_once __DIR__ . '/../_bootstrap.php';
require_once __DIR__ . '/../../app/lib/db.php';
require_once __DIR__ . '/../../vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;

header('Content-Type: application/json; charset=utf-8');

$action = $_REQUEST['action'] ?? 'leer';

// Helpers
function norml($s): string {
    $s = mb_strtolower(trim((string)$s));
    return strtr($s, [
        'á'=>'a','é'=>'e','í'=>'i','ó'=>'o','ú'=>'u','ñ'=>'n','ü'=>'u',
        'Á'=>'a','É'=>'e','Í'=>'i','Ó'=>'o','Ú'=>'u','Ñ'=>'n',
    ]);
}
function jerr(string $msg): void {
    echo json_encode(['ok'=>false,'msg'=>$msg], JSON_UNESCAPED_UNICODE);
    exit;
}
// Obtener valor de celda — API PhpSpreadsheet 2.x / 3.x
function xlVal($ws, int $col, int $row): string {
    $addr = Coordinate::stringFromColumnIndex($col) . $row;
    return trim((string)$ws->getCell($addr)->getValue());
}

/* ═══════════════════════════════════════════════
   ACTION: LEER — parsea Excel, devuelve JSON
   ═══════════════════════════════════════════════ */
if ($action === 'leer') {
    if (empty($_FILES['archivo']) || $_FILES['archivo']['error'] !== UPLOAD_ERR_OK)
        jerr('Sin archivo o error al subir (código: '.($_FILES['archivo']['error'] ?? '?').').');

    try {
        $wb = IOFactory::load($_FILES['archivo']['tmp_name']);
    } catch (Exception $e) {
        jerr('No se pudo leer el Excel: '.$e->getMessage());
    }

    $sheetName = 'Dotaciones 25-26';
    if (!$wb->sheetNameExists($sheetName))
        jerr('No se encontró la hoja "'.$sheetName.'". Disponibles: '.implode(', ', $wb->getSheetNames()));

    $ws   = $wb->getSheetByName($sheetName);
    $maxR = $ws->getHighestRow();
    $maxC = Coordinate::columnIndexFromString($ws->getHighestColumn());

    // Construir mapa columna → [especie, modalidad] a partir de filas 1 y 2
    $DATA_COL_START = 7;
    $ultima_esp  = '';
    $col_headers = [];
    for ($c = $DATA_COL_START; $c <= $maxC; $c++) {
        $e1 = xlVal($ws, $c, 1);
        if ($e1 !== '') $ultima_esp = $e1;
        $e2 = xlVal($ws, $c, 2);
        $col_headers[$c - $DATA_COL_START] = [$ultima_esp, $e2];
    }
    $n_cols = $maxC - $DATA_COL_START + 1;

    // Cargar especies BD: nombre normalizado → id
    $esp_map = [];
    $q = sqlsrv_query($conn, "SELECT id_especie, especie FROM dbo.especie");
    if ($q) while ($r = sqlsrv_fetch_array($q, SQLSRV_FETCH_ASSOC))
        $esp_map[norml($r['especie'])] = (int)$r['id_especie'];

    // Mapear cada columna Excel → id_especie de la BD
    $col_esp_id  = [];
    $col_esp_nom = [];
    for ($i = 0; $i < $n_cols; $i++) {
        [$e1, $e2] = $col_headers[$i];
        $e1k = preg_replace('/\bkiwis\b/i', 'kiwi', $e1);
        $candidatos = [];
        if ($e1 && $e2) $candidatos[] = norml("$e1k $e2");
        if ($e1)        $candidatos[] = norml($e1k);

        $found = null;
        foreach ($candidatos as $cand) {
            if (isset($esp_map[$cand])) { $found = $esp_map[$cand]; break; }
            foreach ($esp_map as $k => $v) {
                if (strpos($k, $cand) !== false || strpos($cand, $k) !== false) {
                    $found = $v; break 2;
                }
            }
        }
        $col_esp_id[$i]  = $found;
        $col_esp_nom[$i] = trim("$e1 $e2");
    }

    // Cargar cargos y áreas de la BD (normalizados para comparar)
    $cargo_map = [];
    $q = sqlsrv_query($conn, "SELECT id_cargo, cargo FROM dbo.Dota_Cargo");
    if ($q) while ($r = sqlsrv_fetch_array($q, SQLSRV_FETCH_ASSOC))
        $cargo_map[norml($r['cargo'])] = (int)$r['id_cargo'];

    $area_map = [];
    $q = sqlsrv_query($conn, "SELECT id_area, Area FROM dbo.Area");
    if ($q) while ($r = sqlsrv_fetch_array($q, SQLSRV_FETCH_ASSOC))
        $area_map[norml($r['Area'])] = (int)$r['id_area'];

    // Palabras clave que indican filas de totales (no son cargos)
    $skip_keys = ['total contratista','total planta','requerimiento','total turno',
                  'total mod','total moi','kg/hra','kg/hh'];

    $filas     = [];
    $sin_match = [];

    for ($row = 3; $row <= $maxR; $row++) {
        $tipo = norml(xlVal($ws, 5, $row));
        if ($tipo !== 'contratista') continue;

        $cargo_nombre = xlVal($ws, 3, $row);
        if ($cargo_nombre === '') continue;
        if (in_array(norml($cargo_nombre), $skip_keys)) continue;

        // Leer cantidades por especie
        $cantidades  = [];
        $tiene_valor = false;
        for ($i = 0; $i < $n_cols; $i++) {
            $v        = xlVal($ws, $DATA_COL_START + $i, $row);
            $cantidad = is_numeric($v) ? max(0, (int)$v) : 0;
            $cantidades[$i] = $cantidad;
            if ($cantidad > 0) $tiene_valor = true;
        }
        if (!$tiene_valor) continue;  // fila toda en cero → omitir

        $area_nombre = xlVal($ws, 2, $row);
        $id_cargo    = $cargo_map[norml($cargo_nombre)] ?? null;
        $id_area     = $area_map[norml($area_nombre)]   ?? null;

        if (!$id_cargo)               $sin_match[] = "Cargo: \"$cargo_nombre\"";
        if (!$id_area && $area_nombre) $sin_match[] = "Área: \"$area_nombre\"";

        $filas[] = [
            'cargo'      => $cargo_nombre,
            'area'       => $area_nombre,
            'id_cargo'   => $id_cargo,
            'id_area'    => $id_area,
            'cantidades' => $cantidades,
        ];
    }

    // Conservar solo columnas que tengan especie mapeada y al menos un valor > 0
    $cols_activas = [];
    for ($i = 0; $i < $n_cols; $i++) {
        if ($col_esp_id[$i] === null) continue;
        foreach ($filas as $f) {
            if (($f['cantidades'][$i] ?? 0) > 0) { $cols_activas[] = $i; break; }
        }
    }

    // Reconstruir arrays solo con columnas activas
    $especies_out = array_values(array_map(
        fn($i) => ['id' => $col_esp_id[$i], 'nombre' => $col_esp_nom[$i]],
        $cols_activas
    ));
    $filas_out = array_values(array_map(function ($f) use ($cols_activas) {
        $f['cantidades'] = array_values(array_map(fn($i) => $f['cantidades'][$i] ?? 0, $cols_activas));
        return $f;
    }, $filas));

    echo json_encode([
        'ok'       => true,
        'especies' => $especies_out,
        'filas'    => $filas_out,
        'sin_match'=> array_values(array_unique($sin_match)),
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

/* ═══════════════════════════════════════════════
   ACTION: GUARDAR — recibe JSON, inserta en BD
   ═══════════════════════════════════════════════ */
if ($action === 'guardar') {
    $body = json_decode(file_get_contents('php://input'), true);
    if (!$body) jerr('Payload JSON inválido.');

    $fecha_str = trim($body['fecha'] ?? '');
    $id_turno  = (int)($body['id_turno'] ?? 0) ?: null;
    $filas     = $body['filas'] ?? [];

    $dt = DateTime::createFromFormat('Y-m-d', $fecha_str);
    if (!$dt) jerr('Fecha inválida: '.$fecha_str);

    $guardados = 0;
    $errores   = 0;

    foreach ($filas as $fila) {
        $id_cargo       = (int)($fila['id_cargo']       ?? 0);
        $id_area        = (int)($fila['id_area']        ?? 0);
        $id_contratista = (int)($fila['id_contratista'] ?? 0);
        if (!$id_cargo || !$id_area || !$id_contratista) continue;

        foreach ($fila['cantidades'] as $item) {
            $id_especie = (int)($item['id_especie'] ?? 0) ?: null;
            $cantidad   = (int)($item['cantidad']   ?? 0);
            if ($cantidad <= 0) continue;

            $stmtV = sqlsrv_query($conn,
                "SELECT ISNULL(MAX(version),0) AS max_v FROM dbo.dota_solicitud_contratista
                 WHERE contratista=? AND cargo=? AND area=? AND ISNULL(id_especie,-1)=ISNULL(?,-1)",
                [$id_contratista, $id_cargo, $id_area, $id_especie]);
            $rowV    = $stmtV ? sqlsrv_fetch_array($stmtV, SQLSRV_FETCH_ASSOC) : ['max_v' => 0];
            $version = (int)$rowV['max_v'] + 1;

            $res = sqlsrv_query($conn,
                "INSERT INTO dbo.dota_solicitud_contratista
                   (contratista,cargo,area,cantidad,version,fecha,id_turno,id_especie)
                 VALUES (?,?,?,?,?,?,?,?)",
                [$id_contratista, $id_cargo, $id_area, $cantidad, $version, $dt, $id_turno, $id_especie]);
            $res !== false ? $guardados++ : $errores++;
        }
    }

    echo json_encode([
        'ok'       => true,
        'msg'      => "{$guardados} registro(s) guardado(s)".($errores ? " ({$errores} error(es))" : '').'. Recargando...',
        'guardados'=> $guardados,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

jerr('Acción no reconocida.');
