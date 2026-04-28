<?php
require_once __DIR__ . '/../_bootstrap.php';

if (!es_jefe_operaciones() && !es_admin()) {
    header('Location: ' . BASE_URL . '/Inicio.php'); exit;
}

if (!function_exists('ensure_asistencia_horas_columns')) {
    function ensure_asistencia_horas_columns($conn): void {
        sqlsrv_query($conn, "
IF COL_LENGTH('dbo.dota_asistencia_carga', 'hora_entrada') IS NULL
BEGIN
    ALTER TABLE dbo.dota_asistencia_carga ADD hora_entrada TIME NULL;
END
IF COL_LENGTH('dbo.dota_asistencia_carga', 'hora_salida') IS NULL
BEGIN
    ALTER TABLE dbo.dota_asistencia_carga ADD hora_salida TIME NULL;
END
");
    }
}
ensure_asistencia_horas_columns($conn);

$flash_error = null;
$flash_ok    = null;
$id_usuario  = (int)$_SESSION['id_usuario'];

// ── APROBAR ÁREA ──────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['aprobar_area'])) {
    $registro = trim($_POST['registro'] ?? '');
    $id_area  = (int)($_POST['id_area']  ?? 0);
    $id_turno = (int)($_POST['id_turno'] ?? 0) ?: null;

    if ($registro === '' || $id_area === 0) {
        $flash_error = "Datos incompletos.";
    } else {
        sqlsrv_query($conn,
            "INSERT INTO dbo.dota_asistencia_aprobacion (registro, id_usuario, accion, id_area, id_turno)
             VALUES (?, ?, 'aprobado_ops', ?, ?)",
            [$registro, $id_usuario, $id_area, $id_turno]
        );

        // Verificar si TODOS los combos area+turno del lote tienen aprobado_ops
        $stmtPend = sqlsrv_query($conn,
            "SELECT COUNT(*) AS pendientes
             FROM (SELECT DISTINCT area, turno FROM dbo.dota_asistencia_carga WHERE registro = ?) combos
             WHERE NOT EXISTS (
                 SELECT 1 FROM (
                     SELECT id_area, id_turno, accion,
                            ROW_NUMBER() OVER (PARTITION BY id_area, ISNULL(id_turno,-1) ORDER BY fecha DESC) AS rn
                     FROM dbo.dota_asistencia_aprobacion
                     WHERE registro = ? AND accion IN ('aprobado_ops','rechazado_ops')
                 ) t
                 WHERE t.rn = 1 AND t.accion = 'aprobado_ops'
                   AND t.id_area = combos.area
                   AND ISNULL(t.id_turno,-1) = ISNULL(combos.turno,-1)
             )",
            [$registro, $registro]
        );
        $pendientes = 0;
        if ($stmtPend) {
            $r = sqlsrv_fetch_array($stmtPend, SQLSRV_FETCH_ASSOC);
            $pendientes = (int)($r['pendientes'] ?? 0);
        }

        if ($pendientes === 0) {
            sqlsrv_query($conn,
                "UPDATE dbo.dota_asistencia_lote SET estado = 'listo_factura' WHERE registro = ?",
                [$registro]
            );
            $flash_ok = "Todos los turnos/áreas aprobados. Lote listo para Pre-Factura.";
        } else {
            $flash_ok = "Aprobado. Quedan {$pendientes} turno(s)/área(s) pendiente(s).";
        }
    }
}

// ── RECHAZAR ÁREA ─────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['rechazar_area'])) {
    $registro    = trim($_POST['registro']    ?? '');
    $id_area     = (int)($_POST['id_area']    ?? 0);
    $id_turno    = (int)($_POST['id_turno']   ?? 0) ?: null;
    $observacion = trim($_POST['observacion'] ?? '');

    if ($registro === '' || $id_area === 0 || $observacion === '') {
        $flash_error = "Debe ingresar una observación al rechazar.";
    } else {
        sqlsrv_query($conn,
            "INSERT INTO dbo.dota_asistencia_aprobacion (registro, id_usuario, accion, observacion, id_area, id_turno)
             VALUES (?, ?, 'rechazado_ops', ?, ?, ?)",
            [$registro, $id_usuario, $observacion, $id_area, $id_turno]
        );
        sqlsrv_query($conn,
            "UPDATE dbo.dota_asistencia_lote SET estado = 'rechazado_ops' WHERE registro = ?",
            [$registro]
        );
        $flash_ok = "Turno/área rechazado. El lote vuelve a RRHH para corrección.";
    }
}

// ── LISTAR LOTES ──────────────────────────────────────────────────────────────
$lotes = [];
$stmtL = sqlsrv_query($conn,
    "SELECT l.registro, l.fecha_carga, l.semana, l.anio, l.estado,
            u.nombre + ' ' + ISNULL(u.apellido,'') AS usuario_carga,
            (SELECT COUNT(*)           FROM dbo.dota_asistencia_carga WHERE registro = l.registro) AS total_reg,
            (SELECT COUNT(DISTINCT area) FROM dbo.dota_asistencia_carga WHERE registro = l.registro) AS total_areas
     FROM dbo.dota_asistencia_lote l
     LEFT JOIN dbo.dota_usuarios u ON u.id_usuario = l.id_usuario_carga
     WHERE l.estado IN ('aprobado_area','rechazado_ops')
       AND ISNULL(l.activo, 1) = 1
     ORDER BY l.fecha_carga DESC"
);
if ($stmtL) {
    while ($r = sqlsrv_fetch_array($stmtL, SQLSRV_FETCH_ASSOC)) {
        $reg = $r['registro'];

        // Áreas+turno del lote con estado ops — PARTITION BY area+turno para no cruzar turnos
        $stmtAT = sqlsrv_query($conn,
            "SELECT DISTINCT ac.area, ac.turno,
                    ar.Area                            AS area_nombre,
                    ISNULL(t.nombre_turno,'Sin turno') AS turno_nombre,
                    (SELECT COUNT(*) FROM dbo.dota_asistencia_carga x
                     WHERE x.registro = ac.registro AND x.area = ac.area AND x.turno = ac.turno
                    ) AS registros,
                    (SELECT MIN(x.fecha) FROM dbo.dota_asistencia_carga x
                     WHERE x.registro = ac.registro AND x.area = ac.area AND x.turno = ac.turno
                    ) AS fecha_min,
                    (SELECT MAX(x.fecha) FROM dbo.dota_asistencia_carga x
                     WHERE x.registro = ac.registro AND x.area = ac.area AND x.turno = ac.turno
                    ) AS fecha_max,
                    ops.accion        AS ops_accion,
                    ops.observacion   AS ops_obs,
                    ops.ops_fecha,
                    ops_u.nombre + ' ' + ISNULL(ops_u.apellido,'') AS ops_usuario
             FROM dbo.dota_asistencia_carga ac
             LEFT JOIN dbo.Area ar         ON ar.id_area = ac.area
             LEFT JOIN dbo.dota_turno t    ON t.id       = ac.turno
             LEFT JOIN (
                 SELECT id_area, id_turno, accion, observacion,
                        fecha  AS ops_fecha,
                        id_usuario,
                        ROW_NUMBER() OVER (
                            PARTITION BY id_area, ISNULL(id_turno,-1)
                            ORDER BY fecha DESC
                        ) AS rn
                 FROM dbo.dota_asistencia_aprobacion
                 WHERE registro = ? AND accion IN ('aprobado_ops','rechazado_ops')
             ) ops ON ops.id_area = ac.area
                   AND ISNULL(ops.id_turno,-1) = ISNULL(ac.turno,-1)
                   AND ops.rn = 1
             LEFT JOIN dbo.dota_usuarios ops_u ON ops_u.id_usuario = ops.id_usuario
             WHERE ac.registro = ?
             ORDER BY turno_nombre, area_nombre",
            [$reg, $reg]
        );

        $areas_por_turno  = [];
        $combos_vistos    = [];   // clave "area-turno" para contar combos únicos
        $aprobadas_ops    = 0;
        while ($stmtAT && ($a = sqlsrv_fetch_array($stmtAT, SQLSRV_FETCH_ASSOC))) {
            if (isset($a['ops_fecha'])  && $a['ops_fecha']  instanceof DateTime)
                $a['ops_fecha']  = $a['ops_fecha']->format('d/m/Y H:i');
            if (isset($a['fecha_min']) && $a['fecha_min'] instanceof DateTime)
                $a['fecha_min'] = $a['fecha_min']->format('d/m/Y');
            if (isset($a['fecha_max']) && $a['fecha_max'] instanceof DateTime)
                $a['fecha_max'] = $a['fecha_max']->format('d/m/Y');

            // Cargar registros de esta área+turno agrupados por labor
            $stmtRec = sqlsrv_query($conn,
                "SELECT ac.fecha, ac.rut, ac.nombre, ac.sexo,
                        ISNULL(dc.cargo,'Sin labor') AS labor,
                        ac.jornada, ac.hhee, ac.especie, ac.obs,
                        CONVERT(VARCHAR(5), ac.hora_entrada, 108) AS hora_entrada,
                        CONVERT(VARCHAR(5), ac.hora_salida, 108) AS hora_salida
                 FROM dbo.dota_asistencia_carga ac
                 LEFT JOIN dbo.Dota_Cargo dc ON dc.id_cargo = ac.cargo
                 WHERE ac.registro = ? AND ac.area = ? AND ac.turno = ?
                 ORDER BY ac.fecha, dc.cargo, ac.nombre",
                [$reg, $a['area'], $a['turno']]
            );
            $by_fecha = [];
            while ($stmtRec && ($rec = sqlsrv_fetch_array($stmtRec, SQLSRV_FETCH_ASSOC))) {
                if ($rec['fecha'] instanceof DateTime) $rec['fecha'] = $rec['fecha']->format('d/m/Y');
                $by_fecha[$rec['fecha']][$rec['labor']][] = $rec;
            }
            $a['by_fecha'] = $by_fecha;

            $turno_lbl  = $a['turno_nombre'];
            $combo_key  = $a['area'] . '-' . ($a['turno'] ?? 'null');
            $areas_por_turno[$turno_lbl][] = $a;
            if (!isset($combos_vistos[$combo_key])) {
                $combos_vistos[$combo_key] = true;
                if ($a['ops_accion'] === 'aprobado_ops') $aprobadas_ops++;
            }
        }

        $total_areas_unicas  = count($combos_vistos);   // combos área+turno únicos
        $r['areas_por_turno']     = $areas_por_turno;
        $r['total_areas_unicas']  = $total_areas_unicas;
        $r['aprobadas_ops']       = $aprobadas_ops;
        $r['fecha_carga'] = $r['fecha_carga'] instanceof DateTime
            ? $r['fecha_carga']->format('d/m/Y H:i')
            : (string)$r['fecha_carga'];
        $lotes[] = $r;
    }
}

$title = "Bandeja Operaciones";
include __DIR__ . '/../partials/header.php';
include __DIR__ . '/../partials/navbar_wrapper.php';
?>

<main class="container py-4">
    <h1 class="display-5 text-center mb-4">Bandeja — Jefe de Operaciones</h1>

    <?php if ($flash_error): ?>
        <div class="alert alert-danger"><?= htmlspecialchars($flash_error) ?></div>
    <?php endif; ?>
    <?php if ($flash_ok): ?>
        <div class="alert alert-success"><?= htmlspecialchars($flash_ok) ?></div>
    <?php endif; ?>

    <?php if (empty($lotes)): ?>
        <div class="alert alert-info text-center">No hay lotes pendientes de revisión.</div>
    <?php else: ?>

    <!-- Formularios ocultos -->
    <form id="form-aprobar" method="POST">
        <input type="hidden" name="registro" id="ap-registro">
        <input type="hidden" name="id_area"  id="ap-area">
        <input type="hidden" name="id_turno" id="ap-turno">
        <button type="submit" name="aprobar_area" id="ap-btn" style="display:none"></button>
    </form>
    <form id="form-rechazar" method="POST">
        <input type="hidden" name="registro"    id="re-registro">
        <input type="hidden" name="id_area"     id="re-area">
        <input type="hidden" name="id_turno"    id="re-turno">
        <input type="hidden" name="observacion" id="re-obs">
        <button type="submit" name="rechazar_area" id="re-btn" style="display:none"></button>
    </form>

    <?php foreach ($lotes as $l):
        $estado_lbl = [
            'borrador'       => ['badge-borrador',  'Borrador'],
            'pendiente'      => ['badge-pendiente', 'Pendiente'],
            'aprobado_area'  => ['badge-pendiente', 'Aprobado Área'],
            'rechazado_area' => ['badge-vencido',   'Rechazado Área'],
            'rechazado_ops'  => ['badge-vencido',   'Rechazado'],
            'listo_factura'  => ['badge-pagado',    'Aprobado'],
        ];
        [$cls, $lbl] = $estado_lbl[$l['estado']] ?? ['secondary', $l['estado']];
        $prog   = $l['total_areas_unicas'] > 0
                  ? round($l['aprobadas_ops'] / $l['total_areas_unicas'] * 100)
                  : 0;
    ?>
    <div class="card mb-4 shadow-sm">

        <!-- Cabecera lote -->
        <div class="card-header d-flex flex-wrap justify-content-between align-items-center gap-2">
            <div>
                <strong>Sem <?= (int)$l['semana'] ?> / <?= (int)$l['anio'] ?></strong>
                <span class="text-muted ms-2 small"><?= htmlspecialchars($l['registro']) ?></span>
            </div>
            <div class="d-flex align-items-center gap-2 flex-wrap">
                <span class="badge <?= $cls ?>"><span class="badge-dot"></span><?= $lbl ?></span>
                <span class="text-muted small">
                    <?= $l['fecha_carga'] ?> — <?= htmlspecialchars($l['usuario_carga'] ?? '') ?>
                </span>
                <span class="text-muted small">
                    <?= (int)$l['total_reg'] ?> regs &nbsp;|&nbsp; <?= (int)$l['total_areas'] ?> áreas
                </span>
                <a href="detalle_asistencia.php?registro=<?= urlencode($l['registro']) ?>"
                   class="btn btn-outline-secondary btn-sm">Ver lote completo</a>
            </div>
        </div>

        <!-- Aviso lote bloqueado -->
        <?php if ($l['estado'] === 'rechazado_ops'): ?>
        <div class="alert alert-warning mb-0 py-2 px-3 rounded-0 border-0 border-bottom small">
            <strong>Lote rechazado.</strong>
            RRHH debe corregir la asistencia y el jefe de área debe volver a aprobar antes de que puedas actuar.
        </div>
        <?php endif; ?>

        <!-- Barra de progreso -->
        <div class="px-3 pt-3 pb-1">
            <div class="d-flex justify-content-between small text-muted mb-1">
                <span>Progreso aprobación operaciones</span>
                <span><?= $l['aprobadas_ops'] ?> / <?= $l['total_areas_unicas'] ?> áreas aprobadas</span>
            </div>
            <div class="progress mb-2" style="height:8px;">
                <div class="progress-bar bg-success" style="width:<?= $prog ?>%"></div>
            </div>
        </div>

        <!-- Tabla turno → área -->
        <div class="card-body p-0">
            <table class="table table-bordered table-sm mb-0 align-middle">
                <thead>
                    <tr class="table-dark">
                        <th class="ps-3">Turno / Área</th>
                        <th class="text-center" style="width:110px;">Fecha(s)</th>
                        <th class="text-center" style="width:80px;">Registros</th>
                        <th class="text-center" style="width:130px;">Estado</th>
                        <th class="text-center" style="width:230px;">Acciones</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($l['areas_por_turno'] as $turno_nom => $areas): ?>

                    <!-- Fila cabecera turno -->
                    <tr class="table-secondary">
                        <td colspan="5" class="fw-semibold ps-3 py-1">
                            <?= htmlspecialchars($turno_nom) ?>
                        </td>
                    </tr>

                    <?php
                    // Botones bloqueados si el lote fue rechazado: espera re-aprobación del jefe de área
                    $lote_bloqueado = ($l['estado'] === 'rechazado_ops');
                    ?>
                    <?php foreach ($areas as $aidx => $a):
                        $ops = $a['ops_accion'] ?? null;
                        [$ops_badge, $row_cls] = match($ops) {
                            'aprobado_ops'  => ['<span class="badge bg-success">Aprobado</span>',  ''],
                            'rechazado_ops' => ['<span class="badge bg-danger">Rechazado</span>',  'table-danger'],
                            default         => ['<span class="badge bg-warning text-dark">Pendiente</span>', 'table-warning'],
                        };
                        $fecha_min  = $a['fecha_min'] ?? '';
                        $fecha_max  = $a['fecha_max'] ?? '';
                        $fecha_lbl  = ($fecha_min === $fecha_max || $fecha_max === '') ? $fecha_min : $fecha_min . ' — ' . $fecha_max;
                        $collapse_id = 'det-' . md5($l['registro']) . '-' . $a['area'] . '-' . $a['turno'];
                    ?>
                    <tr class="<?= $row_cls ?>">
                        <td class="ps-4">
                            <strong><?= htmlspecialchars($a['area_nombre'] ?? "Área {$a['area']}") ?></strong>
                            <?php if ($a['ops_obs']): ?>
                            <div class="text-danger small mt-1">
                                <i class="bi bi-exclamation-circle-fill me-1"></i>
                                <?= htmlspecialchars($a['ops_obs']) ?>
                            </div>
                            <?php endif; ?>
                            <?php if ($a['ops_fecha'] && $ops): ?>
                            <div class="text-muted small">
                                <?= htmlspecialchars($a['ops_usuario'] ?? '') ?> — <?= htmlspecialchars($a['ops_fecha']) ?>
                            </div>
                            <?php endif; ?>
                        </td>
                        <td class="text-center small text-nowrap"><?= htmlspecialchars($fecha_lbl) ?></td>
                        <td class="text-center"><?= (int)$a['registros'] ?></td>
                        <td class="text-center"><?= $ops_badge ?></td>
                        <td class="text-center text-nowrap">
                            <!-- Botón toggle colapso inline -->
                            <button class="btn btn-outline-secondary btn-sm btn-ver"
                                    data-bs-toggle="collapse"
                                    data-bs-target="#<?= $collapse_id ?>"
                                    aria-expanded="false">
                                Ver
                            </button>
                            <?php if ($lote_bloqueado): ?>
                            <button class="btn btn-success btn-sm" disabled
                                    title="Esperando re-aprobación del jefe de área">Aprobar</button>
                            <button class="btn btn-danger btn-sm" disabled
                                    title="Esperando re-aprobación del jefe de área">Rechazar</button>
                            <?php elseif ($ops !== 'aprobado_ops'): ?>
                            <button class="btn btn-success btn-sm"
                                onclick="aprobarArea(<?= htmlspecialchars(json_encode($l['registro']), ENT_QUOTES) ?>, <?= (int)$a['area'] ?>, <?= (int)($a['turno'] ?? 0) ?>)">
                                Aprobar
                            </button>
                            <button class="btn btn-danger btn-sm"
                                onclick="rechazarArea(<?= htmlspecialchars(json_encode($l['registro']), ENT_QUOTES) ?>, <?= (int)$a['area'] ?>, <?= (int)($a['turno'] ?? 0) ?>, <?= htmlspecialchars(json_encode($a['area_nombre'] ?? "Área {$a['area']}"), ENT_QUOTES) ?>)">
                                Rechazar
                            </button>
                            <?php else: ?>
                            <button class="btn btn-outline-secondary btn-sm" disabled>Aprobado</button>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <!-- Fila colapso: agrupado por labor -->
                    <tr class="collapse" id="<?= $collapse_id ?>">
                        <td colspan="5" class="p-0 border-0">
                            <div class="px-4 py-2 bg-white border-bottom">
                                <?php
                                // Totales generales del área+turno
                                $tj_total = 0; $th_total = 0; $cnt_total = 0;
                                foreach ($a['by_fecha'] as $labors)
                                    foreach ($labors as $recs)
                                        foreach ($recs as $rec) {
                                            $tj_total  += (float)$rec['jornada'];
                                            $th_total  += (float)$rec['hhee'];
                                            $cnt_total++;
                                        }
                                ?>
                                <table class="table table-sm table-bordered mb-0 align-middle small">
                                    <thead class="table-secondary">
                                        <tr>
                                            <th class="text-center">Fecha</th>
                                            <th>Labor</th>
                                            <th class="text-center">Registros</th>
                                            <th class="text-end">Jornada</th>
                                            <th class="text-end">HH.EE</th>
                                            <th class="text-center" style="width:60px;">Detalle</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                    <?php foreach ($a['by_fecha'] as $fecha_nom => $labors):
                                        $f_cnt = 0; $f_j = 0.0; $f_h = 0.0;
                                        foreach ($labors as $recs) foreach ($recs as $r) {
                                            $f_cnt++; $f_j += (float)$r['jornada']; $f_h += (float)$r['hhee'];
                                        }
                                        foreach ($labors as $labor_nom => $recs):
                                            $tj_lf = array_sum(array_column($recs, 'jornada'));
                                            $th_lf = array_sum(array_column($recs, 'hhee'));
                                            $lf_id = 'lf-' . md5($collapse_id . $fecha_nom . $labor_nom);
                                    ?>
                                    <!-- Fila fecha + labor -->
                                    <tr>
                                        <td class="text-center text-nowrap"><?= htmlspecialchars($fecha_nom) ?></td>
                                        <td class="fw-semibold"><?= htmlspecialchars($labor_nom) ?></td>
                                        <td class="text-center"><?= count($recs) ?></td>
                                        <td class="text-end"><?= number_format($tj_lf, 2) ?></td>
                                        <td class="text-end"><?= number_format($th_lf, 2) ?></td>
                                        <td class="text-center">
                                            <button class="btn btn-sm btn-outline-secondary py-0 px-2 btn-labor-toggle"
                                                    data-bs-toggle="collapse"
                                                    data-bs-target="#<?= $lf_id ?>"
                                                    aria-expanded="false"
                                                    title="Ver registros">+</button>
                                        </td>
                                    </tr>
                                    <!-- Sub-colapso con registros de labor+fecha -->
                                    <tr class="collapse" id="<?= $lf_id ?>">
                                        <td colspan="6" class="p-0 bg-light">
                                            <div class="table-responsive" style="max-height:260px;overflow-y:auto;">
                                                <table class="table table-sm table-hover mb-0 align-middle" style="font-size:.82rem;">
                                                    <thead class="table-dark">
                                                        <tr>
                                                            <th>RUT</th>
                                                            <th>Nombre</th>
                                                            <th>Sexo</th>
                                                            <th class="text-center">Entrada</th>
                                                            <th class="text-center">Salida</th>
                                                            <th class="text-end">Jornada</th>
                                                            <th class="text-end">HH.EE</th>
                                                            <th>Especie</th>
                                                            <th>Obs</th>
                                                        </tr>
                                                    </thead>
                                                    <tbody>
                                                    <?php foreach ($recs as $rec): ?>
                                                    <tr>
                                                        <td><?= htmlspecialchars($rec['rut'] ?? '') ?></td>
                                                        <td><?= htmlspecialchars($rec['nombre'] ?? '') ?></td>
                                                        <td><?= htmlspecialchars($rec['sexo'] ?? '') ?></td>
                                                        <td class="text-center"><?= htmlspecialchars((string)($rec['hora_entrada'] ?? '')) ?></td>
                                                        <td class="text-center"><?= htmlspecialchars((string)($rec['hora_salida'] ?? '')) ?></td>
                                                        <td class="text-end"><?= number_format((float)$rec['jornada'], 2) ?></td>
                                                        <td class="text-end"><?= number_format((float)$rec['hhee'], 2) ?></td>
                                                        <td><?= htmlspecialchars($rec['especie'] ?? '') ?></td>
                                                        <td><?= htmlspecialchars($rec['obs'] ?? '') ?></td>
                                                    </tr>
                                                    <?php endforeach; ?>
                                                    </tbody>
                                                </table>
                                            </div>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                    <tr class="table-info small">
                                        <td class="text-center fw-semibold text-nowrap">Total <?= htmlspecialchars($fecha_nom) ?></td>
                                        <td></td>
                                        <td class="text-center fw-semibold"><?= $f_cnt ?></td>
                                        <td class="text-end fw-semibold"><?= number_format($f_j, 2) ?></td>
                                        <td class="text-end fw-semibold"><?= number_format($f_h, 2) ?></td>
                                        <td></td>
                                    </tr>
                                    <?php endforeach; ?>
                                    </tbody>
                                    <tfoot class="table-light fw-bold">
                                        <tr>
                                            <td colspan="2">Total área</td>
                                            <td class="text-center"><?= $cnt_total ?></td>
                                            <td class="text-end"><?= number_format($tj_total, 2) ?></td>
                                            <td class="text-end"><?= number_format($th_total, 2) ?></td>
                                            <td></td>
                                        </tr>
                                    </tfoot>
                                </table>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>

                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endforeach; ?>

    <?php endif; ?>
</main>

<!-- Modal rechazo por área -->
<div class="modal fade" id="modalRechazo" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-danger text-white">
                <h5 class="modal-title">
                    Rechazar área: <span id="modal-area-nombre" class="fw-bold"></span>
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <p class="text-muted small mb-3">
                    El lote volverá a los jefes de área para corrección.
                    Solo se rechaza el área indicada; las demás quedan con su estado actual.
                </p>
                <div class="mb-3">
                    <label class="form-label fw-bold">
                        Observación <span class="text-danger">*</span>
                    </label>
                    <textarea id="modal-obs" class="form-control" rows="4"
                              placeholder="Describe el problema encontrado en esta área..."></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                <button type="button" class="btn btn-danger" onclick="confirmarRechazo()">
                    Confirmar Rechazo
                </button>
            </div>
        </div>
    </div>
</div>

<?php include __DIR__ . '/../partials/footer.php'; ?>

<script>
// Cambiar texto del botón Ver ↔ Ocultar / + ↔ − al colapsar
document.addEventListener('show.bs.collapse', e => {
    const btn = document.querySelector(`[data-bs-target="#${e.target.id}"]`);
    if (!btn) return;
    if (btn.classList.contains('btn-labor-toggle')) {
        btn.textContent = '−';
    } else {
        btn.textContent = 'Ocultar';
    }
});
document.addEventListener('hide.bs.collapse', e => {
    const btn = document.querySelector(`[data-bs-target="#${e.target.id}"]`);
    if (!btn) return;
    if (btn.classList.contains('btn-labor-toggle')) {
        btn.textContent = '+';
    } else {
        btn.textContent = 'Ver';
    }
});

let registroActual = '';
let areaActual     = 0;

let turnoActual = 0;

function aprobarArea(registro, id_area, id_turno) {
    if (!confirm('¿Confirmar aprobación del turno/área?')) return;
    document.getElementById('ap-registro').value = registro;
    document.getElementById('ap-area').value     = id_area;
    document.getElementById('ap-turno').value    = id_turno;
    document.getElementById('ap-btn').click();
}

function rechazarArea(registro, id_area, id_turno, area_nombre) {
    registroActual = registro;
    areaActual     = id_area;
    turnoActual    = id_turno;
    document.getElementById('modal-area-nombre').textContent = area_nombre;
    document.getElementById('modal-obs').value = '';
    new bootstrap.Modal(document.getElementById('modalRechazo')).show();
}

function confirmarRechazo() {
    const obs = document.getElementById('modal-obs').value.trim();
    if (!obs) { alert('La observación es obligatoria.'); return; }
    document.getElementById('re-registro').value = registroActual;
    document.getElementById('re-area').value     = areaActual;
    document.getElementById('re-turno').value    = turnoActual;
    document.getElementById('re-obs').value      = obs;
    bootstrap.Modal.getInstance(document.getElementById('modalRechazo')).hide();
    document.getElementById('re-btn').click();
}
</script>
