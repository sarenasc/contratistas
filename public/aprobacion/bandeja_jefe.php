<?php
require_once __DIR__ . '/../_bootstrap.php';

if (!puede_aprobar() && !es_admin()) {
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

$id_usuario       = (int)$_SESSION['id_usuario'];
$areas_prop       = [];
$area_turno_pairs = [];

// Siempre recargar áreas desde BD para reflejar cambios de permisos sin relogin
if (!es_admin()) {
    $stmtFB = sqlsrv_query($conn,
        "SELECT id_area, id_turno FROM dbo.dota_jefe_area
         WHERE id_usuario = ? AND activo = 1 AND nivel_aprobacion = 1",
        [$id_usuario]);
    while ($stmtFB && ($fb = sqlsrv_fetch_array($stmtFB, SQLSRV_FETCH_ASSOC))) {
        $id_a = (int)$fb['id_area'];
        $id_t = ($fb['id_turno'] !== null) ? (int)$fb['id_turno'] : null;
        $area_turno_pairs[] = ['area' => $id_a, 'turno' => $id_t];
        $areas_prop[] = $id_a;
    }
    $areas_prop = array_values(array_unique($areas_prop));
    // Actualizar sesión con datos frescos
    $_SESSION['area_turno_pairs'] = $area_turno_pairs;
    $_SESSION['areas_aprobar']    = $areas_prop;
}

// Helper: construye condición EXISTS (area+turno) para SQL parametrizado
// turno NULL = aprueba esa área en cualquier turno
function buildAreaTurnoExists(array $pairs, string $tabla = 'ac2'): array {
    $conds  = [];
    $params = [];
    foreach ($pairs as $p) {
        if ($p['turno'] !== null) {
            $conds[]  = "({$tabla}.area = ? AND {$tabla}.turno = ?)";
            $params[] = $p['area'];
            $params[] = $p['turno'];
        } else {
            $conds[]  = "({$tabla}.area = ?)";
            $params[] = $p['area'];
        }
    }
    return ['sql' => implode(' OR ', $conds), 'params' => $params];
}

// ── APROBAR ──────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['aprobar'])) {
    $registro  = trim($_POST['registro'] ?? '');
    $id_area   = (int)($_POST['id_area'] ?? 0) ?: null;
    $admin_all = !empty($_POST['admin_all']) && es_admin();

    if ($registro === '') {
        $flash_error = "Datos incompletos.";
    } else {
        if ($admin_all) {
            // Insertar una aprobación por cada área del lote que tenga jefe requerido
            $stmtAreas = sqlsrv_query($conn,
                "SELECT DISTINCT j.id_area
                 FROM dbo.dota_jefe_area j
                 WHERE j.activo = 1 AND j.nivel_aprobacion = 1 AND j.id_usuario IS NOT NULL
                   AND j.id_area IN (SELECT DISTINCT area FROM dbo.dota_asistencia_carga WHERE registro = ?)",
                [$registro]
            );
            $areas_lote = [];
            if ($stmtAreas) while ($ar = sqlsrv_fetch_array($stmtAreas, SQLSRV_FETCH_ASSOC))
                $areas_lote[] = (int)$ar['id_area'];

            foreach ($areas_lote as $aid) {
                sqlsrv_query($conn,
                    "INSERT INTO dbo.dota_asistencia_aprobacion (registro, id_usuario, accion, id_area)
                     VALUES (?, ?, 'aprobado', ?)",
                    [$registro, $id_usuario, $aid]
                );
            }

            sqlsrv_query($conn,
                "UPDATE dbo.dota_asistencia_lote SET estado = 'aprobado_area' WHERE registro = ?",
                [$registro]
            );
            $flash_ok = "Lote aprobado como administrador (" . count($areas_lote) . " área(s)). Pasa a Jefe de Operaciones.";
        } else {
            // Aprobación normal (jefe de área o soporte por un área específica)
            sqlsrv_query($conn,
                "INSERT INTO dbo.dota_asistencia_aprobacion (registro, id_usuario, accion, id_area)
                 VALUES (?, ?, 'aprobado', ?)",
                [$registro, $id_usuario, $id_area]
            );

            // Chequeo por ÁREA: cubierta si el jefe asignado aprobó (por id_usuario)
            // O si alguien aprobó con ese id_area (soporte admin)
            $stmtPend = sqlsrv_query($conn,
                "SELECT COUNT(*) AS pendientes
                 FROM (
                     SELECT DISTINCT j.id_area
                     FROM dbo.dota_jefe_area j
                     WHERE j.activo = 1 AND j.id_usuario IS NOT NULL AND j.nivel_aprobacion = 1
                       AND j.id_area IN (SELECT DISTINCT area FROM dbo.dota_asistencia_carga WHERE registro = ?)
                 ) req_areas
                 WHERE NOT EXISTS (
                     SELECT 1 FROM dbo.dota_asistencia_aprobacion ap
                     WHERE ap.registro = ? AND ap.accion = 'aprobado'
                       AND (
                           ap.id_area = req_areas.id_area
                           OR ap.id_usuario IN (
                               SELECT j2.id_usuario FROM dbo.dota_jefe_area j2
                               WHERE j2.id_area = req_areas.id_area
                                 AND j2.nivel_aprobacion = 1 AND j2.activo = 1 AND j2.id_usuario IS NOT NULL
                           )
                       )
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
                    "UPDATE dbo.dota_asistencia_lote SET estado = 'aprobado_area' WHERE registro = ?",
                    [$registro]
                );
                $flash_ok = "Lote aprobado. Pasa a revisión del Jefe de Operaciones.";
            } else {
                $flash_ok = "Aprobación registrada. Quedan {$pendientes} área(s) pendiente(s).";
            }
        }
    }
}

// ── RECHAZAR ─────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['rechazar'])) {
    $registro    = trim($_POST['registro'] ?? '');
    $observacion = trim($_POST['observacion'] ?? '');
    $id_area     = (int)($_POST['id_area'] ?? 0) ?: null;

    if ($registro === '' || $observacion === '') {
        $flash_error = "Debe ingresar una observación al rechazar.";
    } else {
        sqlsrv_query($conn,
            "INSERT INTO dbo.dota_asistencia_aprobacion (registro, id_usuario, accion, observacion, id_area)
             VALUES (?, ?, 'rechazado', ?, ?)",
            [$registro, $id_usuario, $observacion, $id_area]
        );
        sqlsrv_query($conn,
            "UPDATE dbo.dota_asistencia_lote SET estado = 'rechazado_area' WHERE registro = ?",
            [$registro]
        );
        $flash_ok = "Lote rechazado. RRHH recibirá la observación.";
    }
}

// ── LISTAR LOTES ─────────────────────────────────────────────────────────────
// Lotes en estado pendiente o rechazado_ops que contengan áreas del usuario
$lotes = [];

if (!empty($areas_prop) || es_admin()) {
    $whereAreas = '';
    $paramsQ    = ["'pendiente'", "'rechazado_ops'"];

    $existsCond = '';
    $paramsLotes = [];
    if (!es_admin() && !empty($area_turno_pairs)) {
        $ex = buildAreaTurnoExists($area_turno_pairs, 'ac2');
        $existsCond  = "AND EXISTS (
            SELECT 1 FROM dbo.dota_asistencia_carga ac2
            WHERE ac2.registro = l.registro AND ({$ex['sql']})
        )";
        $paramsLotes = $ex['params'];
    }

    $sqlLotes = "
        SELECT l.registro, l.fecha_carga, l.semana, l.anio, l.estado,
               u.nombre + ' ' + ISNULL(u.apellido,'') AS usuario_carga,
               (SELECT COUNT(*) FROM dbo.dota_asistencia_carga WHERE registro = l.registro) AS total_reg
        FROM dbo.dota_asistencia_lote l
        LEFT JOIN dbo.dota_usuarios u ON u.id_usuario = l.id_usuario_carga
        WHERE l.estado IN ('pendiente','rechazado_ops')
          AND ISNULL(l.activo, 1) = 1
        $existsCond
        ORDER BY l.fecha_carga DESC
    ";

    $stmtL = sqlsrv_query($conn, $sqlLotes, $paramsLotes ?: []);
    if ($stmtL) {
        while ($r = sqlsrv_fetch_array($stmtL, SQLSRV_FETCH_ASSOC)) {
            // Verificar si este usuario ya aprobó este lote
            $stmtYa = sqlsrv_query($conn,
                "SELECT TOP 1 accion FROM dbo.dota_asistencia_aprobacion
                 WHERE registro = ? AND id_usuario = ? ORDER BY fecha DESC",
                [$r['registro'], $id_usuario]
            );
            $ya_accion = null;
            if ($stmtYa) {
                $ya = sqlsrv_fetch_array($stmtYa, SQLSRV_FETCH_ASSOC);
                $ya_accion = $ya['accion'] ?? null;
            }
            $r['ya_accion']  = $ya_accion;
            $r['fecha_carga'] = $r['fecha_carga'] instanceof DateTime
                ? $r['fecha_carga']->format('d/m/Y H:i')
                : (string)$r['fecha_carga'];
            $lotes[] = $r;
        }
    }
}

// Áreas para modales: admin ve todas, jefe solo las suyas
$areas_usu = [];
if (es_admin()) {
    $stmtA = sqlsrv_query($conn, "SELECT id_area, Area FROM dbo.Area ORDER BY Area");
    if ($stmtA) while ($r = sqlsrv_fetch_array($stmtA, SQLSRV_FETCH_ASSOC))
        $areas_usu[(int)$r['id_area']] = $r['Area'];
} elseif (!empty($areas_prop)) {
    $ph = implode(',', array_fill(0, count($areas_prop), '?'));
    $stmtA = sqlsrv_query($conn, "SELECT id_area, Area FROM dbo.Area WHERE id_area IN ($ph) ORDER BY Area", $areas_prop);
    if ($stmtA) while ($r = sqlsrv_fetch_array($stmtA, SQLSRV_FETCH_ASSOC))
        $areas_usu[(int)$r['id_area']] = $r['Area'];
}

$title = "Bandeja Jefe de Área";
include __DIR__ . '/../partials/header.php';
include __DIR__ . '/../partials/navbar_wrapper.php';
?>

<main class="container py-4">
    <h1 class="display-5 text-center mb-4">Bandeja — Jefe de Área</h1>

    <?php if ($flash_error): ?>
        <div class="alert alert-danger"><?= htmlspecialchars($flash_error) ?></div>
    <?php endif; ?>
    <?php if ($flash_ok): ?>
        <div class="alert alert-success"><?= htmlspecialchars($flash_ok) ?></div>
    <?php endif; ?>

    <?php if (empty($lotes)): ?>
        <div class="alert alert-info text-center">No hay lotes pendientes de aprobación.</div>
    <?php else: ?>

    <!-- Formularios ocultos para aprobar/rechazar -->
    <form id="form-aprobar" method="POST">
        <input type="hidden" name="registro"  id="ap-registro">
        <input type="hidden" name="id_area"   id="ap-area">
        <input type="hidden" name="admin_all" id="ap-admin-all" value="0">
        <button type="submit" name="aprobar" id="ap-btn" style="display:none"></button>
    </form>
    <form id="form-rechazar" method="POST">
        <input type="hidden" name="registro"    id="re-registro">
        <input type="hidden" name="id_area"     id="re-area">
        <input type="hidden" name="observacion" id="re-obs">
        <button type="submit" name="rechazar" id="re-btn" style="display:none"></button>
    </form>

    <?php
    // Contar registros del jefe en cada lote (filtrado por área+turno)
    $regs_mi_area = [];
    if (!empty($area_turno_pairs)) {
        // Filtro completo con área y turno
        $ex = buildAreaTurnoExists($area_turno_pairs, 'ac');
        foreach ($lotes as $l) {
            $stmtMR = sqlsrv_query($conn,
                "SELECT COUNT(*) AS cnt FROM dbo.dota_asistencia_carga ac
                 WHERE ac.registro = ? AND ({$ex['sql']})",
                array_merge([$l['registro']], $ex['params'])
            );
            $mr = $stmtMR ? sqlsrv_fetch_array($stmtMR, SQLSRV_FETCH_ASSOC) : null;
            $regs_mi_area[$l['registro']] = (int)($mr['cnt'] ?? 0);
        }
    } elseif (!empty($areas_prop)) {
        // Fallback: filtrar solo por área si no hay pares cargados
        $ph = implode(',', array_fill(0, count($areas_prop), '?'));
        foreach ($lotes as $l) {
            $stmtMR = sqlsrv_query($conn,
                "SELECT COUNT(*) AS cnt FROM dbo.dota_asistencia_carga
                 WHERE registro = ? AND area IN ($ph)",
                array_merge([$l['registro']], $areas_prop)
            );
            $mr = $stmtMR ? sqlsrv_fetch_array($stmtMR, SQLSRV_FETCH_ASSOC) : null;
            $regs_mi_area[$l['registro']] = (int)($mr['cnt'] ?? 0);
        }
    }

    // Área principal para formularios
    $area_principal = $area_turno_pairs[0]['area'] ?? ($areas_prop[0] ?? null);

    // URL de filtro para detalle: pares area:turno (ej: "2:1,3:")
    if (!empty($area_turno_pairs)) {
        $pares_url = implode(',', array_map(
            fn($p) => $p['area'] . ':' . ($p['turno'] ?? ''),
            $area_turno_pairs
        ));
    } else {
        // Fallback: solo áreas sin turno
        $pares_url = implode(',', array_map(fn($a) => $a . ':', $areas_prop));
    }

    // Pre-cargar registros agrupados por lote: turno → área → labor → fecha
    $records_por_lote = [];
    foreach ($lotes as $l) {
        $reg = $l['registro'];
        $by_turno = [];
        if (es_admin()) {
            $stmtGR = sqlsrv_query($conn,
                "SELECT ac.fecha, ac.rut, ac.nombre, ac.sexo,
                        ISNULL(dc.cargo,'Sin labor') AS labor,
                        ISNULL(ar.Area,'Sin área') AS area_nombre,
                        ISNULL(tr.nombre_turno,'Sin turno') AS turno_nombre,
                        ac.jornada, ac.hhee, ac.especie, ac.obs,
                        CONVERT(VARCHAR(5), ac.hora_entrada, 108) AS hora_entrada,
                        CONVERT(VARCHAR(5), ac.hora_salida, 108) AS hora_salida
                 FROM dbo.dota_asistencia_carga ac
                 LEFT JOIN dbo.Area ar ON ar.id_area = ac.area
                 LEFT JOIN dbo.Dota_Cargo dc ON dc.id_cargo = ac.cargo
                 LEFT JOIN dbo.dota_turno tr ON tr.id = ac.turno
                 WHERE ac.registro = ?
                 ORDER BY tr.nombre_turno, ar.Area, dc.cargo, ac.fecha, ac.nombre",
                [$reg]
            );
        } elseif (!empty($area_turno_pairs)) {
            $ex = buildAreaTurnoExists($area_turno_pairs, 'ac');
            $stmtGR = sqlsrv_query($conn,
                "SELECT ac.fecha, ac.rut, ac.nombre, ac.sexo,
                        ISNULL(dc.cargo,'Sin labor') AS labor,
                        ISNULL(ar.Area,'Sin área') AS area_nombre,
                        ISNULL(tr.nombre_turno,'Sin turno') AS turno_nombre,
                        ac.jornada, ac.hhee, ac.especie, ac.obs,
                        CONVERT(VARCHAR(5), ac.hora_entrada, 108) AS hora_entrada,
                        CONVERT(VARCHAR(5), ac.hora_salida, 108) AS hora_salida
                 FROM dbo.dota_asistencia_carga ac
                 LEFT JOIN dbo.Area ar ON ar.id_area = ac.area
                 LEFT JOIN dbo.Dota_Cargo dc ON dc.id_cargo = ac.cargo
                 LEFT JOIN dbo.dota_turno tr ON tr.id = ac.turno
                 WHERE ac.registro = ? AND ({$ex['sql']})
                 ORDER BY tr.nombre_turno, ar.Area, dc.cargo, ac.fecha, ac.nombre",
                array_merge([$reg], $ex['params'])
            );
        } else {
            $records_por_lote[$reg] = [];
            continue;
        }
        while (isset($stmtGR) && $stmtGR && ($grec = sqlsrv_fetch_array($stmtGR, SQLSRV_FETCH_ASSOC))) {
            if ($grec['fecha'] instanceof DateTime) $grec['fecha'] = $grec['fecha']->format('d/m/Y');
            $t   = $grec['turno_nombre'];
            $a   = $grec['area_nombre'];
            $lab = $grec['labor'];
            $f   = $grec['fecha'];
            $by_turno[$t][$a][$f][$lab][] = $grec;
        }
        $records_por_lote[$reg] = $by_turno;
    }
    ?>

    <div class="table-responsive">
        <table class="table table-bordered table-hover align-middle">
            <thead class="table-dark">
                <tr>
                    <th>Semana / Año</th>
                    <th>Fecha carga</th>
                    <th>Mis registros</th>
                    <th>Total lote</th>
                    <th>Estado lote</th>
                    <th>Mi aprobacion</th>
                    <th>Acciones</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($lotes as $l): ?>
                <?php
                $badges_e = ['pendiente' => 'warning text-dark', 'rechazado_ops' => 'danger'];
                $cls = $badges_e[$l['estado']] ?? 'secondary';
                $mis_regs = $regs_mi_area[$l['registro']] ?? 0;
                ?>
                <?php $det_id = 'bj-' . md5($l['registro']); ?>
                <tr>
                    <td class="text-center">
                        Sem <strong><?= (int)$l['semana'] ?></strong> / <?= (int)$l['anio'] ?>
                    </td>
                    <td>
                        <?= htmlspecialchars($l['fecha_carga']) ?><br>
                        <small class="text-muted"><?= htmlspecialchars($l['usuario_carga'] ?? '') ?></small>
                    </td>
                    <td class="text-center">
                        <span class="fw-bold text-primary"><?= $mis_regs ?></span>
                        <small class="text-muted d-block">
                            <?= implode(', ', array_values(array_intersect_key($areas_usu, array_flip($areas_prop)))) ?>
                        </small>
                    </td>
                    <td class="text-center text-muted"><?= (int)$l['total_reg'] ?></td>
                    <td class="text-center">
                        <span class="badge bg-<?= $cls ?>"><?= htmlspecialchars($l['estado']) ?></span>
                    </td>
                    <td class="text-center">
                        <?php if ($l['ya_accion'] === 'aprobado'): ?>
                            <span class="badge bg-success">Aprobado</span>
                        <?php elseif ($l['ya_accion'] === 'rechazado'): ?>
                            <span class="badge bg-danger">Rechazado</span>
                        <?php else: ?>
                            <span class="badge bg-secondary">Pendiente</span>
                        <?php endif; ?>
                    </td>
                    <td class="text-nowrap">
                        <button class="btn btn-outline-secondary btn-sm btn-ver"
                                data-bs-toggle="collapse"
                                data-bs-target="#<?= $det_id ?>"
                                aria-expanded="false">Ver</button>
                        <a href="detalle_asistencia.php?registro=<?= urlencode($l['registro']) ?>&pares=<?= urlencode($pares_url) ?>"
                           class="btn btn-outline-secondary btn-sm">Detalle</a>

                        <?php if (es_admin()): ?>
                        <button class="btn btn-success btn-sm"
                            onclick="aprobarTodo(<?= htmlspecialchars(json_encode($l['registro']), ENT_QUOTES) ?>)"
                            title="Aprueba el lote completo saltando verificación de jefes">
                            Aprobar todo
                        </button>
                        <button class="btn btn-outline-success btn-sm"
                            onclick="abrirSoporte(<?= htmlspecialchars(json_encode($l['registro']), ENT_QUOTES) ?>)"
                            title="Aprueba una área específica en nombre de un jefe">
                            Aprobar area (soporte)
                        </button>
                        <button class="btn btn-danger btn-sm"
                            onclick="rechazar(<?= htmlspecialchars(json_encode($l['registro']), ENT_QUOTES) ?>, 0)">
                            Rechazar
                        </button>
                        <?php elseif ($l['ya_accion'] !== 'aprobado'): ?>
                        <button class="btn btn-success btn-sm"
                            onclick="aprobar(<?= htmlspecialchars(json_encode($l['registro']), ENT_QUOTES) ?>, <?= (int)$area_principal ?>)">
                            Aprobar
                        </button>
                        <button class="btn btn-danger btn-sm"
                            onclick="rechazar(<?= htmlspecialchars(json_encode($l['registro']), ENT_QUOTES) ?>, <?= (int)$area_principal ?>)">
                            Rechazar
                        </button>
                        <?php endif; ?>
                    </td>
                </tr>
                <!-- Fila colapso: jerarquía Turno → Área → Labor + Fecha -->
                <tr class="collapse" id="<?= $det_id ?>">
                    <td colspan="7" class="p-0 border-0 bg-light">
                    <?php $by_turno = $records_por_lote[$l['registro']] ?? []; ?>
                    <?php if (empty($by_turno)): ?>
                        <p class="text-muted p-3 mb-0">Sin registros para mostrar.</p>
                    <?php else: ?>
                        <div class="p-3">
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
                            <?php foreach ($by_turno as $turno_nom => $areas): ?>
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
                                    $col_id = 'bja-' . md5($l['registro'] . $turno_nom . $area_nom);
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
                                                    $f_cnt = 0; $f_j = 0.0; $f_h = 0.0;
                                                    foreach ($labors as $recs) foreach ($recs as $r) {
                                                        $f_cnt++; $f_j += (float)$r['jornada']; $f_h += (float)$r['hhee'];
                                                    }
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
                                                                    <td><?= htmlspecialchars($rec['rut']     ?? '') ?></td>
                                                                    <td><?= htmlspecialchars($rec['nombre']  ?? '') ?></td>
                                                                    <td><?= htmlspecialchars($rec['sexo']    ?? '') ?></td>
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
                        </table>
                        </div>
                    <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</main>

<!-- Modal rechazo -->
<div class="modal fade" id="modalRechazo" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Rechazar lote</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="mb-3">
                    <label class="form-label">Área que presenta el problema</label>
                    <select id="modal-area" class="form-control">
                        <option value="">-- General (todo el lote) --</option>
                        <?php foreach ($areas_usu as $aid => $anom): ?>
                            <option value="<?= $aid ?>"><?= htmlspecialchars($anom) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="mb-3">
                    <label class="form-label">Observación <span class="text-danger">*</span></label>
                    <textarea id="modal-obs" class="form-control" rows="4"
                              placeholder="Describe el problema encontrado..."></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                <button type="button" class="btn btn-danger" onclick="confirmarRechazo()">Confirmar Rechazo</button>
            </div>
        </div>
    </div>
</div>

<!-- Modal soporte (admin) -->
<div class="modal fade" id="modalSoporte" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-success text-white">
                <h5 class="modal-title">Aprobar area como soporte</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" id="soporte-registro">
                <p class="text-muted small">Selecciona el area que apruebas en nombre del jefe ausente. El lote avanzara si todas las areas quedan cubiertas.</p>
                <div class="mb-3">
                    <label class="form-label fw-bold">Area <span class="text-danger">*</span></label>
                    <select id="soporte-area" class="form-select">
                        <option value="">-- Seleccionar area --</option>
                        <?php foreach ($areas_usu as $aid => $anom): ?>
                            <option value="<?= $aid ?>"><?= htmlspecialchars($anom) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                <button type="button" class="btn btn-success" onclick="confirmarSoporte()">Confirmar aprobacion</button>
            </div>
        </div>
    </div>
</div>

<?php include __DIR__ . '/../partials/footer.php'; ?>

<script>
// Toggle Ver ↔ Ocultar / + ↔ −
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

let registroActual = '';
let areaActual     = '';

function aprobar(registro, id_area) {
    if (!confirm('¿Confirmar aprobación del lote para tu área?')) return;
    document.getElementById('ap-registro').value   = registro;
    document.getElementById('ap-area').value       = id_area || '';
    document.getElementById('ap-admin-all').value  = '0';
    document.getElementById('ap-btn').click();
}

function aprobarTodo(registro) {
    if (!confirm('¿Aprobar el lote completo como administrador?\nSe omitirán las aprobaciones pendientes de jefes de área.')) return;
    document.getElementById('ap-registro').value   = registro;
    document.getElementById('ap-area').value       = '';
    document.getElementById('ap-admin-all').value  = '1';
    document.getElementById('ap-btn').click();
}

function abrirSoporte(registro) {
    document.getElementById('soporte-registro').value = registro;
    document.getElementById('soporte-area').value     = '';
    new bootstrap.Modal(document.getElementById('modalSoporte')).show();
}

function confirmarSoporte() {
    const area = document.getElementById('soporte-area').value;
    if (!area) { alert('Debes seleccionar un área.'); return; }
    document.getElementById('ap-registro').value  = document.getElementById('soporte-registro').value;
    document.getElementById('ap-area').value      = area;
    document.getElementById('ap-admin-all').value = '0';
    bootstrap.Modal.getInstance(document.getElementById('modalSoporte')).hide();
    document.getElementById('ap-btn').click();
}

function rechazar(registro, id_area) {
    registroActual = registro;
    areaActual     = id_area || '';
    document.getElementById('modal-obs').value = '';
    new bootstrap.Modal(document.getElementById('modalRechazo')).show();
}

function confirmarRechazo() {
    const obs = document.getElementById('modal-obs').value.trim();
    if (!obs) { alert('La observación es obligatoria.'); return; }
    document.getElementById('re-registro').value = registroActual;
    document.getElementById('re-area').value     = document.getElementById('modal-area').value || areaActual;
    document.getElementById('re-obs').value      = obs;
    document.getElementById('re-btn').click();
}
</script>
