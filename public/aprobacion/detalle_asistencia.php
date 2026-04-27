<?php
require_once __DIR__ . '/../_bootstrap.php';

if (!puede_aprobar() && !puede_modulo('procesos') && !es_admin()) {
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

$registro    = trim($_GET['registro'] ?? '');
// Filtro área+turno (viene desde bandeja_jefe como "2:1,3:")
$pares_param  = trim($_GET['pares'] ?? '');
$pares_filtro = [];  // [['area'=>2,'turno'=>1], ['area'=>3,'turno'=>null], ...]
if ($pares_param !== '') {
    foreach (explode(',', $pares_param) as $par) {
        $parts = explode(':', $par);
        $id_a  = (int)trim($parts[0]);
        $id_t  = isset($parts[1]) && trim($parts[1]) !== '' ? (int)trim($parts[1]) : null;
        if ($id_a > 0) $pares_filtro[] = ['area' => $id_a, 'turno' => $id_t];
    }
}

// ── LISTA DE LOTES (cuando no viene registro) ──────────────────────────────
$lotes_lista = [];
$stmtLL = sqlsrv_query($conn,
    "SELECT l.registro, l.semana, l.anio, l.estado, l.fecha_carga,
            u.nombre + ' ' + ISNULL(u.apellido,'') AS usuario_carga
     FROM dbo.dota_asistencia_lote l
     LEFT JOIN dbo.dota_usuarios u ON u.id_usuario = l.id_usuario_carga
     WHERE ISNULL(l.activo, 1) = 1
     ORDER BY l.fecha_carga DESC"
);
if ($stmtLL) while ($r = sqlsrv_fetch_array($stmtLL, SQLSRV_FETCH_ASSOC)) {
    if ($r['fecha_carga'] instanceof DateTime) $r['fecha_carga'] = $r['fecha_carga']->format('d/m/Y H:i');
    $lotes_lista[] = $r;
}

$badges = [
    'borrador'       => 'secondary',
    'pendiente'      => 'warning text-dark',
    'aprobado_area'  => 'info text-dark',
    'rechazado_area' => 'danger',
    'rechazado_ops'  => 'danger',
    'listo_factura'  => 'success',
];

$labels = [
    'borrador'       => 'Borrador',
    'pendiente'      => 'Pendiente aprobacion',
    'aprobado_area'  => 'Aprobado por areas',
    'rechazado_area' => 'Rechazado por area',
    'rechazado_ops'  => 'Rechazado por operaciones',
    'listo_factura'  => 'Listo para facturar',
];

// ── DETALLE DEL LOTE SELECCIONADO ─────────────────────────────────────────
$lote      = null;
$registros = [];
$historial = [];

if ($registro !== '') {
    $stmtL = sqlsrv_query($conn,
        "SELECT l.*, u.nombre + ' ' + ISNULL(u.apellido,'') AS usuario_carga
         FROM dbo.dota_asistencia_lote l
         LEFT JOIN dbo.dota_usuarios u ON u.id_usuario = l.id_usuario_carga
         WHERE l.registro = ?",
        [$registro]
    );
    if ($stmtL) {
        $lote = sqlsrv_fetch_array($stmtL, SQLSRV_FETCH_ASSOC);
        if ($lote && $lote['fecha_carga'] instanceof DateTime)
            $lote['fecha_carga'] = $lote['fecha_carga']->format('d/m/Y H:i');
    }

    if ($lote) {
        $where_area  = '';
        $params_area = [$registro];
        if (!empty($pares_filtro)) {
            $conds = [];
            foreach ($pares_filtro as $p) {
                if ($p['turno'] !== null) {
                    $conds[]     = "(ac.area = ? AND ac.turno = ?)";
                    $params_area[] = $p['area'];
                    $params_area[] = $p['turno'];
                } else {
                    $conds[]     = "(ac.area = ?)";
                    $params_area[] = $p['area'];
                }
            }
            $where_area = "AND (" . implode(' OR ', $conds) . ")";
        }
        $stmtR = sqlsrv_query($conn,
            "SELECT ac.fecha, ac.rut, ac.nombre, ac.sexo,
                    ar.Area AS area, dc.cargo AS labor, tr.nombre_turno AS turno,
                    ac.jornada, ac.hhee, ac.especie, ac.obs,
                    CONVERT(VARCHAR(5), ac.hora_entrada, 108) AS hora_entrada,
                    CONVERT(VARCHAR(5), ac.hora_salida, 108) AS hora_salida
             FROM dbo.dota_asistencia_carga ac
             LEFT JOIN dbo.Area         ar ON ar.id_area  = ac.area
             LEFT JOIN dbo.Dota_Cargo   dc ON dc.id_cargo = ac.cargo
             LEFT JOIN dbo.dota_turno   tr ON tr.id       = ac.turno
             WHERE ac.registro = ? $where_area
             ORDER BY ac.fecha, ar.Area, ac.nombre",
            $params_area
        );
        if ($stmtR) while ($r = sqlsrv_fetch_array($stmtR, SQLSRV_FETCH_ASSOC)) {
            if ($r['fecha'] instanceof DateTime) $r['fecha'] = $r['fecha']->format('d/m/Y');
            $registros[] = $r;
        }

        // Agrupar: turno → área → fecha → labor
        $reg_grouped = [];
        foreach ($registros as $r) {
            $t = $r['turno']  ?? 'Sin turno';
            $a = $r['area']   ?? 'Sin área';
            $f = $r['fecha']  ?? '';
            $l = $r['labor']  ?? 'Sin labor';
            $reg_grouped[$t][$a][$f][$l][] = $r;
        }

        $stmtH = sqlsrv_query($conn,
            "SELECT ap.accion, ap.fecha, ap.observacion,
                    u.nombre + ' ' + ISNULL(u.apellido,'') AS aprobador,
                    a.Area
             FROM dbo.dota_asistencia_aprobacion ap
             LEFT JOIN dbo.dota_usuarios u ON u.id_usuario = ap.id_usuario
             LEFT JOIN dbo.Area          a ON a.id_area    = ap.id_area
             WHERE ap.registro = ?
             ORDER BY ap.fecha ASC",
            [$registro]
        );
        if ($stmtH) while ($h = sqlsrv_fetch_array($stmtH, SQLSRV_FETCH_ASSOC)) {
            if ($h['fecha'] instanceof DateTime) $h['fecha'] = $h['fecha']->format('d/m/Y H:i');
            $historial[] = $h;
        }

        // Estado de aprobaciones por jefe requerido
        // Chequeo por id_usuario del jefe O por id_area (admin soporte)
        $stmtAP = sqlsrv_query($conn,
            "SELECT u.nombre + ' ' + ISNULL(u.apellido,'') AS jefe,
                    a.Area,
                    t.nombre_turno AS turno,
                    (SELECT MAX(CASE WHEN accion = 'aprobado'  THEN 1 ELSE 0 END)
                     FROM dbo.dota_asistencia_aprobacion ap
                     WHERE ap.registro = ?
                       AND (ap.id_usuario = j.id_usuario OR ap.id_area = j.id_area)
                    ) AS es_aprobado,
                    (SELECT MAX(CASE WHEN accion = 'rechazado' THEN 1 ELSE 0 END)
                     FROM dbo.dota_asistencia_aprobacion ap
                     WHERE ap.registro = ?
                       AND (ap.id_usuario = j.id_usuario OR ap.id_area = j.id_area)
                    ) AS es_rechazado,
                    (SELECT MAX(fecha)
                     FROM dbo.dota_asistencia_aprobacion ap
                     WHERE ap.registro = ?
                       AND (ap.id_usuario = j.id_usuario OR ap.id_area = j.id_area)
                    ) AS fecha
             FROM dbo.dota_jefe_area j
             JOIN dbo.dota_usuarios u  ON u.id_usuario = j.id_usuario
             JOIN dbo.Area a           ON a.id_area    = j.id_area
             LEFT JOIN dbo.dota_turno t ON t.id        = j.id_turno
             WHERE j.activo = 1
               AND j.nivel_aprobacion = 1
               AND j.id_usuario IS NOT NULL
               AND j.id_area IN (
                   SELECT DISTINCT area FROM dbo.dota_asistencia_carga WHERE registro = ?
               )
             ORDER BY a.Area, t.nombre_turno, u.nombre",
            [$registro, $registro, $registro, $registro]
        );
        $estado_jefes = [];
        if ($stmtAP) while ($ap = sqlsrv_fetch_array($stmtAP, SQLSRV_FETCH_ASSOC)) {
            if ($ap['fecha'] instanceof DateTime) $ap['fecha'] = $ap['fecha']->format('d/m/Y H:i');
            if ((int)($ap['es_aprobado'] ?? 0))      $ap['estado_jefe'] = 'aprobado';
            elseif ((int)($ap['es_rechazado'] ?? 0)) $ap['estado_jefe'] = 'rechazado';
            else                                      $ap['estado_jefe'] = 'pendiente';
            $estado_jefes[] = $ap;
        }
    }
}

$title = "Detalle Asistencia";
include __DIR__ . '/../partials/header.php';
include __DIR__ . '/../partials/navbar_wrapper.php';
?>

<main class="container-fluid py-4" style="max-width:1400px;">
    <h1 class="display-6 text-center mb-4">Detalle de Asistencia</h1>

    <!-- Selector de lote -->
    <div class="card mb-4 shadow-sm">
        <div class="card-header fw-bold">Seleccionar lote</div>
        <div class="card-body">
            <form method="GET">
                <div class="row g-2 align-items-end">
                    <div class="col-md-10">
                        <select name="registro" class="form-select" required>
                            <option value="">-- Seleccionar lote --</option>
                            <?php foreach ($lotes_lista as $l):
                                $cls = $badges[$l['estado']] ?? 'secondary';
                                $lbl = $labels[$l['estado']] ?? $l['estado'];
                            ?>
                            <option value="<?= htmlspecialchars($l['registro']) ?>"
                                <?= $l['registro'] === $registro ? 'selected' : '' ?>>
                                Sem <?= (int)$l['semana'] ?>/<?= (int)$l['anio'] ?> —
                                <?= htmlspecialchars($lbl) ?> —
                                <?= htmlspecialchars($l['fecha_carga']) ?> —
                                <?= htmlspecialchars($l['usuario_carga'] ?? '') ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <button type="submit" class="btn btn-primary w-100">Ver detalle</button>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <?php if ($registro !== '' && !$lote): ?>
        <div class="alert alert-danger">Lote no encontrado.</div>
    <?php endif; ?>

    <?php if ($lote): ?>

    <!-- Cabecera del lote -->
    <?php
        $cls = $badges[$lote['estado']] ?? 'secondary';
        $lbl = $labels[$lote['estado']] ?? $lote['estado'];
        $totalJ = array_sum(array_column($registros, 'jornada'));
        $totalH = array_sum(array_column($registros, 'hhee'));
    ?>
    <div class="card mb-3 shadow-sm border-0 bg-light">
        <div class="card-body py-2">
            <div class="row align-items-center">
                <div class="col-auto">
                    <span class="badge bg-<?= $cls ?> fs-6"><?= $lbl ?></span>
                </div>
                <div class="col">
                    <strong>Sem <?= (int)$lote['semana'] ?>/<?= (int)$lote['anio'] ?></strong>
                    <span class="text-muted ms-2 small"><?= htmlspecialchars($registro) ?></span>
                </div>
                <div class="col-auto text-end small text-muted">
                    Cargado por <?= htmlspecialchars($lote['usuario_carga'] ?? '—') ?>
                    el <?= htmlspecialchars($lote['fecha_carga']) ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Resumen -->
    <div class="row g-3 mb-4">
        <div class="col-sm-4">
            <div class="card text-center shadow-sm">
                <div class="card-body py-2">
                    <div class="fs-4 fw-bold"><?= count($registros) ?></div>
                    <small class="text-muted">Registros</small>
                </div>
            </div>
        </div>
        <div class="col-sm-4">
            <div class="card text-center shadow-sm">
                <div class="card-body py-2">
                    <div class="fs-4 fw-bold"><?= number_format($totalJ, 2) ?></div>
                    <small class="text-muted">Total Jornada</small>
                </div>
            </div>
        </div>
        <div class="col-sm-4">
            <div class="card text-center shadow-sm">
                <div class="card-body py-2">
                    <div class="fs-4 fw-bold"><?= number_format($totalH, 2) ?></div>
                    <small class="text-muted">Total HH.EE</small>
                </div>
            </div>
        </div>
    </div>

    <?php if (!empty($pares_filtro)): ?>
    <?php
    // Armar etiqueta legible del filtro activo
    $filtro_labels = [];
    foreach ($pares_filtro as $pf) {
        $area_lbl  = '';
        $turno_lbl = '';
        foreach ($lotes_lista as $ll) { /* no aplica aquí */ }
        // Buscar nombre de área y turno directamente en los registros cargados
        foreach ($registros as $rec) {
            if (isset($rec['area'])) { $area_lbl = $rec['area']; break; }
        }
        $stmtLbl = sqlsrv_query($conn,
            "SELECT ar.Area, ISNULL(t.nombre_turno,'Sin turno') AS turno
             FROM dbo.Area ar
             LEFT JOIN dbo.dota_turno t ON t.id = ?
             WHERE ar.id_area = ?",
            [$pf['turno'], $pf['area']]
        );
        if ($stmtLbl && ($lbl = sqlsrv_fetch_array($stmtLbl, SQLSRV_FETCH_ASSOC)))
            $filtro_labels[] = htmlspecialchars($lbl['Area']) . ' / ' . htmlspecialchars($lbl['turno']);
    }
    ?>
    <div class="alert alert-info py-2 mb-3 d-flex align-items-center justify-content-between">
        <span>
            Mostrando: <strong><?= implode(', ', $filtro_labels) ?: 'filtro activo' ?></strong>
            — <?= count($registros) ?> registro(s)
        </span>
        <a href="detalle_asistencia.php?registro=<?= urlencode($registro) ?>" class="btn btn-sm btn-outline-secondary">Ver lote completo</a>
    </div>
    <?php elseif (!empty($_SESSION['area_turno_pairs'])): ?>
    <!-- Jefe accede desde menu directo: ofrecer filtrar por su área -->
    <div class="alert alert-info py-2 mb-3">
        Viendo lote completo.
        <?php
        $pares_ses = array_map(
            fn($p) => $p['area'] . ':' . ($p['turno'] ?? ''),
            $_SESSION['area_turno_pairs']
        );
        ?>
        <a href="detalle_asistencia.php?registro=<?= urlencode($registro) ?>&pares=<?= urlencode(implode(',', $pares_ses)) ?>"
           class="ms-2 small">Ver solo mi area</a>
    </div>
    <?php endif; ?>

    <!-- Botones de acción -->
    <div class="d-flex gap-2 mb-3">
        <?php if (puede_modulo('procesos') || es_admin()): ?>
        <a href="<?= BASE_URL ?>/procesos/editar_asistencia.php?registro=<?= urlencode($registro) ?>"
           class="btn btn-outline-warning btn-sm">Ir a Revisar/Editar</a>
        <?php endif; ?>
    </div>

    <!-- Registros: Turno → Área → Labor + Fecha -->
    <div class="card mb-4 shadow-sm">
        <div class="card-header fw-bold">Registros de asistencia</div>
        <div class="card-body p-0">
        <?php if (empty($reg_grouped)): ?>
            <p class="text-muted p-3 mb-0">Sin registros.</p>
        <?php else: ?>
            <?php $grand_cnt = 0; $grand_j = 0.0; $grand_h = 0.0; ?>
            <table class="table table-bordered table-sm mb-0 align-middle">
                <thead>
                    <tr class="table-dark">
                        <th class="ps-3">Turno / Área</th>
                        <th class="text-center" style="width:80px;">Registros</th>
                        <th class="text-end"   style="width:90px;">Jornada</th>
                        <th class="text-end"   style="width:90px;">HH.EE</th>
                        <th class="text-center" style="width:80px;">Detalle</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($reg_grouped as $turno_nom => $areas): ?>
                    <tr class="table-secondary">
                        <td colspan="5" class="fw-semibold ps-3 py-1"><?= htmlspecialchars($turno_nom) ?></td>
                    </tr>
                    <?php foreach ($areas as $area_nom => $fechas):
                        $area_cnt = 0; $area_j = 0.0; $area_h = 0.0;
                        foreach ($fechas as $labors)
                            foreach ($labors as $recs)
                                foreach ($recs as $rec) {
                                    $area_cnt++;
                                    $area_j += (float)$rec['jornada'];
                                    $area_h += (float)$rec['hhee'];
                                }
                        $grand_cnt += $area_cnt; $grand_j += $area_j; $grand_h += $area_h;
                        $col_id = 'da-' . md5($turno_nom . $area_nom);
                    ?>
                    <tr>
                        <td class="ps-4"><strong><?= htmlspecialchars($area_nom) ?></strong></td>
                        <td class="text-center"><?= $area_cnt ?></td>
                        <td class="text-end"><?= number_format($area_j, 2) ?></td>
                        <td class="text-end"><?= number_format($area_h, 2) ?></td>
                        <td class="text-center">
                            <button class="btn btn-outline-secondary btn-sm btn-ver py-0"
                                    data-bs-toggle="collapse"
                                    data-bs-target="#<?= $col_id ?>"
                                    aria-expanded="false">Ver</button>
                        </td>
                    </tr>
                    <tr class="collapse" id="<?= $col_id ?>">
                        <td colspan="5" class="p-0 border-0">
                            <div class="px-4 py-2 bg-white border-bottom">
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
                                    <?php foreach ($fechas as $fecha_nom => $labors):
                                        foreach ($labors as $labor_nom => $recs):
                                            $tj_lf = array_sum(array_column($recs, 'jornada'));
                                            $th_lf = array_sum(array_column($recs, 'hhee'));
                                            $lf_id = 'lf-' . md5($col_id . $fecha_nom . $labor_nom);
                                    ?>
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
                                                    aria-expanded="false">+</button>
                                        </td>
                                    </tr>
                                    <tr class="collapse" id="<?= $lf_id ?>">
                                        <td colspan="6" class="p-0 bg-light">
                                            <div class="table-responsive" style="max-height:260px;overflow-y:auto;">
                                                <table class="table table-sm table-hover mb-0 align-middle" style="font-size:.82rem;">
                                                    <thead class="table-dark">
                                                        <tr>
                                                            <th>RUT</th><th>Nombre</th><th>Sexo</th>
                                                            <th class="text-center">Entrada</th>
                                                            <th class="text-center">Salida</th>
                                                            <th class="text-end">Jornada</th>
                                                            <th class="text-end">HH.EE</th>
                                                            <th>Especie</th><th>Obs</th>
                                                        </tr>
                                                    </thead>
                                                    <tbody>
                                                    <?php foreach ($recs as $rec): ?>
                                                    <tr>
                                                        <td><?= htmlspecialchars($rec['rut']    ?? '') ?></td>
                                                        <td><?= htmlspecialchars($rec['nombre'] ?? '') ?></td>
                                                        <td><?= htmlspecialchars($rec['sexo']   ?? '') ?></td>
                                                        <td class="text-center"><?= htmlspecialchars((string)($rec['hora_entrada'] ?? '')) ?></td>
                                                        <td class="text-center"><?= htmlspecialchars((string)($rec['hora_salida'] ?? '')) ?></td>
                                                        <td class="text-end"><?= number_format((float)$rec['jornada'], 2) ?></td>
                                                        <td class="text-end"><?= number_format((float)$rec['hhee'],    2) ?></td>
                                                        <td><?= htmlspecialchars($rec['especie'] ?? '') ?></td>
                                                        <td><?= htmlspecialchars($rec['obs']     ?? '') ?></td>
                                                    </tr>
                                                    <?php endforeach; ?>
                                                    </tbody>
                                                </table>
                                            </div>
                                        </td>
                                    </tr>
                                    <?php endforeach; endforeach; ?>
                                    </tbody>
                                    <tfoot class="table-light fw-bold">
                                        <tr>
                                            <td colspan="2">Total área</td>
                                            <td class="text-center"><?= $area_cnt ?></td>
                                            <td class="text-end"><?= number_format($area_j, 2) ?></td>
                                            <td class="text-end"><?= number_format($area_h, 2) ?></td>
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
                <tfoot class="table-light fw-bold">
                    <tr>
                        <td class="ps-3">Total general</td>
                        <td class="text-center"><?= $grand_cnt ?></td>
                        <td class="text-end"><?= number_format($grand_j, 2) ?></td>
                        <td class="text-end"><?= number_format($grand_h, 2) ?></td>
                        <td></td>
                    </tr>
                </tfoot>
            </table>
        <?php endif; ?>
        </div>
    </div>

    <!-- Estado de aprobacion por jefe -->
    <?php if (!empty($estado_jefes)): ?>
    <div class="card mb-4 shadow-sm">
        <div class="card-header fw-bold d-flex align-items-center gap-2">
            Estado de aprobacion por jefe de area
            <?php
            $pendientes = count(array_filter($estado_jefes, fn($j) => $j['estado_jefe'] === 'pendiente'));
            $aprobados  = count(array_filter($estado_jefes, fn($j) => $j['estado_jefe'] === 'aprobado'));
            $rechazados = count(array_filter($estado_jefes, fn($j) => $j['estado_jefe'] === 'rechazado'));
            ?>
            <?php if ($pendientes > 0): ?>
            <span class="badge bg-warning text-dark"><?= $pendientes ?> pendiente<?= $pendientes > 1 ? 's' : '' ?></span>
            <?php endif; ?>
            <?php if ($aprobados > 0): ?>
            <span class="badge bg-success"><?= $aprobados ?> aprobado<?= $aprobados > 1 ? 's' : '' ?></span>
            <?php endif; ?>
            <?php if ($rechazados > 0): ?>
            <span class="badge bg-danger"><?= $rechazados ?> rechazado<?= $rechazados > 1 ? 's' : '' ?></span>
            <?php endif; ?>
        </div>
        <div class="card-body p-0">
            <table class="table table-sm table-bordered mb-0 align-middle">
                <thead class="table-light">
                    <tr>
                        <th>Estado</th>
                        <th>Jefe</th>
                        <th>Area</th>
                        <th>Turno</th>
                        <th>Fecha accion</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($estado_jefes as $jf):
                    $badgeCls = match($jf['estado_jefe']) {
                        'aprobado'  => 'success',
                        'rechazado' => 'danger',
                        default     => 'warning text-dark',
                    };
                    $badgeLbl = match($jf['estado_jefe']) {
                        'aprobado'  => 'Aprobado',
                        'rechazado' => 'Rechazado',
                        default     => 'Pendiente',
                    };
                ?>
                <tr class="<?= $jf['estado_jefe'] === 'pendiente' ? 'table-warning' : '' ?>">
                    <td><span class="badge bg-<?= $badgeCls ?>"><?= $badgeLbl ?></span></td>
                    <td><?= htmlspecialchars($jf['jefe'] ?? '') ?></td>
                    <td><?= htmlspecialchars($jf['Area'] ?? '') ?></td>
                    <td><?= htmlspecialchars($jf['turno'] ?? '—') ?></td>
                    <td class="text-muted small"><?= htmlspecialchars($jf['fecha'] ?? '—') ?></td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endif; ?>

    <!-- Historial -->
    <div class="card shadow-sm">
        <div class="card-header fw-bold">Historial de aprobaciones</div>
        <div class="card-body p-0">
            <?php if (empty($historial)): ?>
                <p class="text-muted p-3 mb-0">Sin acciones registradas aun.</p>
            <?php else: ?>
            <table class="table table-sm table-bordered mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Accion</th><th>Aprobador</th><th>Area</th>
                        <th>Fecha</th><th>Observacion</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($historial as $h):
                    [$hColor, $hLabel] = match($h['accion']) {
                        'aprobado'      => ['success', 'Aprobado (Área)'],
                        'rechazado'     => ['danger',  'Rechazado (Área)'],
                        'aprobado_ops'  => ['success', 'Aprobado (Ops)'],
                        'rechazado_ops' => ['danger',  'Rechazado (Ops)'],
                        default         => ['secondary', $h['accion']],
                    };
                ?>
                <tr>
                    <td>
                        <span class="badge bg-<?= $hColor ?>"><?= $hLabel ?></span>
                    </td>
                    <td><?= htmlspecialchars($h['aprobador'] ?? '') ?></td>
                    <td><?= htmlspecialchars($h['Area'] ?? '—') ?></td>
                    <td><?= htmlspecialchars($h['fecha']) ?></td>
                    <td><?= htmlspecialchars($h['observacion'] ?? '') ?></td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <?php endif; ?>
        </div>
    </div>

    <?php endif; ?>

</main>

<script>
document.addEventListener('show.bs.collapse', e => {
    const btn = document.querySelector(`[data-bs-target="#${e.target.id}"]`);
    if (!btn) return;
    btn.textContent = btn.classList.contains('btn-labor-toggle') ? '−' : 'Ocultar';
});
document.addEventListener('hide.bs.collapse', e => {
    const btn = document.querySelector(`[data-bs-target="#${e.target.id}"]`);
    if (!btn) return;
    btn.textContent = btn.classList.contains('btn-labor-toggle') ? '+' : 'Ver';
});
</script>
<?php include __DIR__ . '/../partials/footer.php'; ?>
