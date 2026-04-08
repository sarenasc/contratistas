<?php
declare(strict_types=1);
ob_start();

require_once __DIR__ . '/../_bootstrap.php';
require_once __DIR__ . '/../../app/lib/db.php';

header('Content-Type: application/json; charset=utf-8');

try {
    $d      = json_decode(file_get_contents('php://input'), true);
    $accion = $d['accion'] ?? '';

    /* ── AGREGAR ─────────────────────────────────────────────── */
    if ($accion === 'agregar') {
        $id_factura     = (int)($d['id_factura']     ?? 0);
        $id_contratista = (int)($d['id_contratista'] ?? 0);
        $valor          = (float)($d['valor']        ?? 0);
        $observacion    = mb_substr(trim($d['observacion'] ?? ''), 0, 500);

        if ($id_factura <= 0 || $id_contratista <= 0 || $valor <= 0)
            throw new RuntimeException("Datos inválidos.");

        /* Verificar que la proforma exista y esté en proceso */
        $chk = sqlsrv_query($conn,
            "SELECT estado FROM dbo.dota_factura WHERE id=?", [$id_factura]);
        $fila = $chk ? sqlsrv_fetch_array($chk, SQLSRV_FETCH_ASSOC) : null;
        if (!$fila) throw new RuntimeException("Proforma no encontrada.");
        if ($fila['estado'] === 'cerrado') throw new RuntimeException("La proforma está cerrada.");

        /* Insertar */
        $ins = db_query($conn,
            "INSERT INTO dbo.dota_factura_descuento (id_factura, id_contratista, valor, observacion)
             OUTPUT INSERTED.id
             VALUES (?, ?, ?, ?)",
            [$id_factura, $id_contratista, $valor, $observacion ?: null],
            "INSERT descuento proforma");
        $new_id = (int)(sqlsrv_fetch_array($ins, SQLSRV_FETCH_ASSOC)['id'] ?? 0);

        /* Recalcular totales proforma */
        _recalc($conn, $id_factura);

        ob_end_clean();
        echo json_encode([
            'ok'    => true,
            'id'    => $new_id,
            'valor' => $valor,
            'obs'   => $observacion,
            'totales' => _totales($conn, $id_factura),
        ]);

    /* ── ELIMINAR ────────────────────────────────────────────── */
    } elseif ($accion === 'eliminar') {
        $id         = (int)($d['id']         ?? 0);
        $id_factura = (int)($d['id_factura'] ?? 0);

        if ($id <= 0 || $id_factura <= 0) throw new RuntimeException("ID inválido.");

        /* Verificar proforma en proceso */
        $chk = sqlsrv_query($conn,
            "SELECT estado FROM dbo.dota_factura WHERE id=?", [$id_factura]);
        $fila = $chk ? sqlsrv_fetch_array($chk, SQLSRV_FETCH_ASSOC) : null;
        if (!$fila) throw new RuntimeException("Proforma no encontrada.");
        if ($fila['estado'] === 'cerrado') throw new RuntimeException("La proforma está cerrada.");

        sqlsrv_query($conn,
            "DELETE FROM dbo.dota_factura_descuento WHERE id=? AND id_factura=?",
            [$id, $id_factura]);

        _recalc($conn, $id_factura);

        ob_end_clean();
        echo json_encode(['ok' => true, 'totales' => _totales($conn, $id_factura)]);

    } else {
        throw new RuntimeException("Acción desconocida.");
    }

} catch (Throwable $e) {
    ob_end_clean();
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}

/* ── Helpers ─────────────────────────────────────────────────── */

/** Recalcula descuento y total_neto en dota_factura desde dota_factura_descuento */
function _recalc($conn, int $id_factura): void {
    sqlsrv_query($conn,
        "UPDATE dbo.dota_factura
         SET descuento  = (SELECT ISNULL(SUM(valor),0) FROM dbo.dota_factura_descuento WHERE id_factura = f.id),
             total_neto = tot_factura
                        - (SELECT ISNULL(SUM(valor),0) FROM dbo.dota_factura_descuento WHERE id_factura = f.id)
         FROM dbo.dota_factura f
         WHERE f.id = ?",
        [$id_factura]);
}

/** Devuelve los totales actualizados de la proforma */
function _totales($conn, int $id_factura): array {
    $q = sqlsrv_query($conn,
        "SELECT descuento, total_neto FROM dbo.dota_factura WHERE id=?",
        [$id_factura]);
    $r = $q ? sqlsrv_fetch_array($q, SQLSRV_FETCH_ASSOC) : [];
    return [
        'descuento'  => (float)($r['descuento']  ?? 0),
        'total_neto' => (float)($r['total_neto'] ?? 0),
    ];
}
