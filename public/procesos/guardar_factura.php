<?php
declare(strict_types=1);
ob_start();

require_once __DIR__ . '/../_bootstrap.php';
require_once __DIR__ . '/../../app/lib/db.php';

header('Content-Type: application/json; charset=utf-8');

try {
    $d = json_decode(file_get_contents('php://input'), true);
    if (!$d) throw new RuntimeException("Payload inválido.");

    $accion      = $d['accion']      ?? 'nuevo';   // 'nuevo' | 'anexar'
    $id_factura  = (int)($d['id_factura'] ?? 0);
    $semana      = (int)($d['semana']     ?? 0);
    $anio        = (int)($d['anio']       ?? 0);
    $obs         = mb_substr(trim($d['obs'] ?? ''), 0, 500);
    $estado      = in_array($d['estado'] ?? '', ['proceso','cerrado'], true) ? $d['estado'] : 'proceso';
    $contratistas = $d['contratistas'] ?? [];
    $usuario     = $_SESSION['nom_usu'] ?? null;

    if ($semana <= 0 || $anio <= 0) throw new RuntimeException("Semana/año inválidos.");
    if (empty($contratistas))       throw new RuntimeException("Sin contratistas seleccionados.");

    /* ── Calcular totales globales desde los datos recibidos ── */
    $tot_bj=0; $tot_bh=0; $tot_pj=0; $tot_ph=0;
    $tot_bono=0; $tot_fac=0; $tot_desc=0; $tot_neto=0;
    foreach ($contratistas as $ct) {
        $tot_bj   += (float)($ct['tot_base_jorn'] ?? 0);
        $tot_bh   += (float)($ct['tot_base_hhee'] ?? 0);
        $tot_pj   += (float)($ct['tot_pct_jorn']  ?? 0);
        $tot_ph   += (float)($ct['tot_pct_hhee']  ?? 0);
        $tot_bono += (float)($ct['tot_bono']       ?? 0);
        $tot_fac  += (float)($ct['tot_factura']    ?? 0);
        $tot_desc += (float)($ct['descuento']      ?? 0);
    }
    $tot_neto = $tot_fac - $tot_desc;

    /* ── NUEVO o ANEXAR ── */
    if ($accion === 'anexar' && $id_factura > 0) {

        /* Verificar que la factura existe y no está cerrada */
        $chk = sqlsrv_query($conn,
            "SELECT id, estado FROM dbo.dota_factura WHERE id=?", [$id_factura]);
        $fila = $chk ? sqlsrv_fetch_array($chk, SQLSRV_FETCH_ASSOC) : null;
        if (!$fila) throw new RuntimeException("Proforma #{$id_factura} no encontrada.");
        if ($fila['estado'] === 'cerrado') throw new RuntimeException("La proforma está cerrada y no se puede modificar.");

        /* Borrar líneas de esos contratistas (para re-insertar actualizadas) */
        $ids_ct = array_map(fn($c) => (int)$c['id_contratista'], $contratistas);
        $ph_ct  = implode(',', array_fill(0, count($ids_ct), '?'));
        sqlsrv_query($conn,
            "DELETE FROM dbo.dota_factura_detalle WHERE id_factura=? AND id_contratista IN ($ph_ct)",
            array_merge([$id_factura], $ids_ct));

        /* Recalcular totales globales sumando los existentes más los nuevos */
        $exist = sqlsrv_query($conn,
            "SELECT ISNULL(SUM(base_jorn),0) bj, ISNULL(SUM(base_hhee),0) bh,
                    ISNULL(SUM(pct_jorn),0) pj,  ISNULL(SUM(pct_hhee),0) ph,
                    ISNULL(SUM(bono),0) bono,     ISNULL(SUM(total),0) tot
             FROM dbo.dota_factura_detalle WHERE id_factura=?", [$id_factura]);
        $ex = $exist ? sqlsrv_fetch_array($exist, SQLSRV_FETCH_ASSOC) : [];
        $tot_bj   += (float)($ex['bj']   ?? 0);
        $tot_bh   += (float)($ex['bh']   ?? 0);
        $tot_pj   += (float)($ex['pj']   ?? 0);
        $tot_ph   += (float)($ex['ph']   ?? 0);
        $tot_bono += (float)($ex['bono'] ?? 0);
        $tot_fac  += (float)($ex['tot']  ?? 0);
        $tot_neto  = $tot_fac - $tot_desc;

        $fecha_cierre = $estado === 'cerrado' ? new DateTime() : null;
        db_query($conn,
            "UPDATE dbo.dota_factura
             SET obs=?, estado=?, usuario=?, fecha_cierre=?,
                 tot_base_jorn=?, tot_base_hhee=?, tot_pct_jorn=?, tot_pct_hhee=?,
                 tot_bono=?, tot_factura=?, descuento=?, total_neto=?
             WHERE id=?",
            [$obs, $estado, $usuario, $fecha_cierre,
             $tot_bj, $tot_bh, $tot_pj, $tot_ph,
             $tot_bono, $tot_fac, $tot_desc, $tot_neto, $id_factura],
            "UPDATE factura");
        $accion_resp = 'anexada';

    } else {
        /* NUEVO: siguiente versión para esa semana/año */
        $vq = sqlsrv_query($conn,
            "SELECT ISNULL(MAX(version),0)+1 AS v FROM dbo.dota_factura WHERE semana=? AND anio=?",
            [$semana, $anio]);
        $version = $vq ? (int)sqlsrv_fetch_array($vq, SQLSRV_FETCH_ASSOC)['v'] : 1;

        $fecha_cierre = $estado === 'cerrado' ? new DateTime() : null;
        $ins = db_query($conn,
            "INSERT INTO dbo.dota_factura
             (semana, anio, version, obs, estado, usuario, fecha_cierre,
              tot_base_jorn, tot_base_hhee, tot_pct_jorn, tot_pct_hhee,
              tot_bono, tot_factura, descuento, total_neto)
             OUTPUT INSERTED.id
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)",
            [$semana, $anio, $version, $obs, $estado, $usuario, $fecha_cierre,
             $tot_bj, $tot_bh, $tot_pj, $tot_ph,
             $tot_bono, $tot_fac, $tot_desc, $tot_neto],
            "INSERT factura");

        $ins_row    = sqlsrv_fetch_array($ins, SQLSRV_FETCH_ASSOC);
        $id_factura = $ins_row ? (int)$ins_row['id'] : 0;
        if ($id_factura <= 0) throw new RuntimeException("No se pudo obtener el ID de la proforma creada.");
        $accion_resp = "v{$version} creada";
    }

    /* ── Insertar detalle por contratista+cargo ── */
    $sql_det = "INSERT INTO dbo.dota_factura_detalle
        (id_factura, id_contratista, cargo_nombre, tarifa_nombre, especial, esp_nom,
         registros, jornada, hhee, v_dia, v_hhee, porc_jorn, porc_hhee,
         base_jorn, base_hhee, pct_jorn, pct_hhee, bono, total)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)";

    foreach ($contratistas as $ct) {
        $id_ct = (int)$ct['id_contratista'];
        foreach ($ct['cargos'] ?? [] as $cargo) {
            db_query($conn, $sql_det, [
                $id_factura,
                $id_ct,
                $cargo['cargo_nombre']  ?? '',
                $cargo['tarifa_nombre'] ?? '',
                (int)(bool)($cargo['especial'] ?? false),
                $cargo['esp_nom']       ?? null,
                (int)($cargo['registros']  ?? 0),
                (float)($cargo['jornada']  ?? 0),
                (float)($cargo['hhee']     ?? 0),
                (float)($cargo['v_dia']    ?? 0),
                (float)($cargo['v_hhee']   ?? 0),
                (float)($cargo['porc_jorn']?? 0),
                (float)($cargo['porc_hhee']?? 0),
                (float)($cargo['base_jorn']?? 0),
                (float)($cargo['base_hhee']?? 0),
                (float)($cargo['pct_jorn'] ?? 0),
                (float)($cargo['pct_hhee'] ?? 0),
                (float)($cargo['bono']     ?? 0),
                (float)($cargo['total']    ?? 0),
            ], "INSERT detalle");
        }
    }

    ob_end_clean();
    echo json_encode([
        'ok'       => true,
        'accion'   => $accion_resp,
        'id'       => $id_factura,
        'estado'   => $estado,
        'total'    => $tot_fac,
    ]);

} catch (Throwable $e) {
    ob_end_clean();
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
