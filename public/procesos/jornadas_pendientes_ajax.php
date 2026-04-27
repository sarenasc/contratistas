<?php
declare(strict_types=1);
ob_start();

require_once __DIR__ . '/../_bootstrap.php';
require_once __DIR__ . '/../../app/lib/db.php';

header('Content-Type: application/json; charset=utf-8');

$body = json_decode(file_get_contents('php://input'), true);
if (!$body) { echo json_encode(['ok' => false, 'error' => 'Sin datos']); exit; }

$accion = $body['accion'] ?? '';

/* ── GUARDAR ── */
if ($accion === 'guardar') {
    $id_cont   = (int)($body['id_contratista'] ?? 0);
    $id_cargo  = (int)($body['id_cargo']       ?? 0);
    $rut       = trim($body['rut']     ?? '');
    $nombre    = trim($body['nombre']  ?? '');
    $fecha     = trim($body['fecha']   ?? '');
    $turno     = trim($body['turno']   ?? '');
    $especie   = trim($body['especie'] ?? '');
    $jornada   = (float)($body['jornada'] ?? 0);
    $hhee      = (float)($body['hhee']    ?? 0);
    $obs       = mb_substr(trim($body['obs'] ?? ''), 0, 255);
    $sem_fact  = (int)($body['semana_factura'] ?? 0);
    $anio_fact = (int)($body['anio_factura']   ?? 0);

    if (!$id_cont || !$id_cargo || $fecha === '') {
        echo json_encode(['ok' => false, 'error' => 'Datos incompletos']); exit;
    }
    if ($jornada <= 0 && $hhee <= 0) {
        echo json_encode(['ok' => false, 'error' => 'Debe ingresar jornada o HHEE mayor a 0']); exit;
    }

    try {
        $dt       = new DateTime($fecha);
        $sem_orig  = (int)$dt->format('W');
        $anio_orig = (int)$dt->format('o');  // año ISO
    } catch (Exception $e) {
        echo json_encode(['ok' => false, 'error' => 'Fecha inválida']); exit;
    }

    try {
        db_query($conn,
            "INSERT INTO dbo.dota_jornadas_pendientes
                (rut, nombre, id_cargo, id_contratista, turno, especie, fecha,
                 semana_original, anio_original, semana_factura, anio_factura,
                 jornada, hhee, obs)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)",
            [
                $rut, $nombre, $id_cargo, $id_cont,
                $turno   !== '' ? $turno   : null,
                $especie !== '' ? $especie : null,
                $fecha,
                $sem_orig, $anio_orig,
                $sem_fact, $anio_fact,
                $jornada, $hhee,
                $obs !== '' ? $obs : null,
            ]
        );
        echo json_encode(['ok' => true]);
    } catch (Throwable $e) {
        echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

/* ── ELIMINAR ── */
if ($accion === 'eliminar') {
    $id = (int)($body['id'] ?? 0);
    if (!$id) { echo json_encode(['ok' => false, 'error' => 'ID inválido']); exit; }
    try {
        db_query($conn, "DELETE FROM dbo.dota_jornadas_pendientes WHERE id = ?", [$id]);
        echo json_encode(['ok' => true]);
    } catch (Throwable $e) {
        echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

echo json_encode(['ok' => false, 'error' => 'Acción desconocida']);
