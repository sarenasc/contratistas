<?php
require_once __DIR__ . '/../_bootstrap.php';
require_once __DIR__ . '/../../app/lib/db.php';

if (!puede_modulo('procesos') && !es_admin()) {
    header('Location: ' . BASE_URL . '/Inicio.php'); exit;
}

$flash_error = null;
$flash_ok    = null;
$id_usuario  = (int)$_SESSION['id_usuario'];

// Catálogos
$cargos_cat = [];
$stmtC = sqlsrv_query($conn, "SELECT id_cargo, cargo FROM dbo.Dota_Cargo ORDER BY cargo");
if ($stmtC) while ($r = sqlsrv_fetch_array($stmtC, SQLSRV_FETCH_ASSOC))
    $cargos_cat[(int)$r['id_cargo']] = $r['cargo'];

$areas_cat = [];
$stmtA = sqlsrv_query($conn, "SELECT id_area, Area FROM dbo.Area ORDER BY Area");
if ($stmtA) while ($r = sqlsrv_fetch_array($stmtA, SQLSRV_FETCH_ASSOC))
    $areas_cat[(int)$r['id_area']] = $r['Area'];

// ── GUARDAR EDICIÓN ────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['guardar'])) {
    $registro = trim($_POST['registro'] ?? '');
    $ids      = (array)($_POST['id']      ?? []);
    $jornadas = (array)($_POST['jornada'] ?? []);
    $hhees    = (array)($_POST['hhee']    ?? []);
    $cargos   = (array)($_POST['cargo']   ?? []);
    $obs_rows = (array)($_POST['obs']     ?? []);

    if ($registro === '' || empty($ids)) {
        $flash_error = "No se recibieron datos para guardar.";
    } else {
        $errores = 0;
        foreach ($ids as $i => $id) {
            $id      = (int)$id;
            $jornada = (float)str_replace(',', '.', $jornadas[$i] ?? 0);
            $hhee    = (float)str_replace(',', '.', $hhees[$i]    ?? 0);
            $cargo   = (int)$cargos[$i];
            $obs     = trim($obs_rows[$i] ?? '');

            $r = sqlsrv_query($conn,
                "UPDATE dbo.dota_asistencia_carga
                 SET jornada = ?, hhee = ?, cargo = ?, obs = ?
                 WHERE id = ? AND registro = ?",
                [$jornada, $hhee, $cargo ?: null, $obs ?: null, $id, $registro]
            );
            if ($r === false) $errores++;
        }

        if ($errores === 0) {
            // Registrar edición en el log
            sqlsrv_query($conn,
                "INSERT INTO dbo.dota_asistencia_aprobacion (registro, id_usuario, accion, observacion)
                 VALUES (?, ?, 'editado', ?)",
                [$registro, $id_usuario, 'Edición de registros por RRHH']
            );

            // Si el lote estaba rechazado, volver a pendiente
            sqlsrv_query($conn,
                "UPDATE dbo.dota_asistencia_lote
                 SET estado = 'pendiente'
                 WHERE registro = ? AND estado IN ('rechazado_area','rechazado_ops')",
                [$registro]
            );

            $flash_ok = "Registros actualizados correctamente. El lote volvió a estado pendiente.";
        } else {
            $flash_error = "Se produjeron $errores error(es) al guardar.";
        }
    }
}

// ── SELECCIONAR LOTE ────────────────────────────────────────────────────────
$registro = trim($_GET['registro'] ?? $_POST['registro'] ?? '');

// Lista de lotes disponibles para editar
$lotes_lista = [];
$stmtLL = sqlsrv_query($conn,
    "SELECT l.registro, l.semana, l.anio, l.estado, l.fecha_carga,
            u.nombre + ' ' + ISNULL(u.apellido,'') AS usuario_carga
     FROM dbo.dota_asistencia_lote l
     LEFT JOIN dbo.dota_usuarios u ON u.id_usuario = l.id_usuario_carga
     WHERE l.estado != 'listo_factura'
     ORDER BY l.fecha_carga DESC"
);
if ($stmtLL) while ($r = sqlsrv_fetch_array($stmtLL, SQLSRV_FETCH_ASSOC)) {
    if ($r['fecha_carga'] instanceof DateTime) $r['fecha_carga'] = $r['fecha_carga']->format('d/m/Y H:i');
    $lotes_lista[] = $r;
}

// Registros del lote seleccionado
$registros = [];
$lote_info = null;
if ($registro !== '') {
    $stmtLI = sqlsrv_query($conn,
        "SELECT l.registro, l.semana, l.anio, l.estado FROM dbo.dota_asistencia_lote WHERE registro = ?",
        [$registro]
    );
    if ($stmtLI) $lote_info = sqlsrv_fetch_array($stmtLI, SQLSRV_FETCH_ASSOC);

    $stmtR = sqlsrv_query($conn,
        "SELECT ac.id, ac.fecha, ac.rut, ac.nombre, ac.cargo, ac.area,
                ac.jornada, ac.hhee, ac.especie, ac.obs,
                ar.Area AS area_nombre, dc.cargo AS cargo_nombre,
                tr.nombre_turno AS turno
         FROM dbo.dota_asistencia_carga ac
         LEFT JOIN dbo.Area         ar ON ar.id_area  = ac.area
         LEFT JOIN dbo.Dota_Cargo   dc ON dc.id_cargo = ac.cargo
         LEFT JOIN dbo.dota_turno   tr ON tr.id       = ac.turno
         WHERE ac.registro = ?
         ORDER BY ac.fecha, ar.Area, ac.nombre",
        [$registro]
    );
    if ($stmtR) {
        while ($r = sqlsrv_fetch_array($stmtR, SQLSRV_FETCH_ASSOC)) {
            if ($r['fecha'] instanceof DateTime) $r['fecha'] = $r['fecha']->format('d/m/Y');
            $registros[] = $r;
        }
    }
}

$badges = [
    'pendiente'      => 'warning text-dark',
    'aprobado_area'  => 'success',
    'rechazado_area' => 'danger',
    'rechazado_ops'  => 'danger',
    'listo_factura'  => 'primary',
];

$title = "Editar Asistencia";
include __DIR__ . '/../partials/header.php';
include __DIR__ . '/../partials/navbar_wrapper.php';
?>

<main class="container py-4">
    <h1 class="display-5 text-center mb-4">Editar Asistencia</h1>

    <?php if ($flash_error): ?>
        <div class="alert alert-danger"><?= htmlspecialchars($flash_error) ?></div>
    <?php endif; ?>
    <?php if ($flash_ok): ?>
        <div class="alert alert-success"><?= htmlspecialchars($flash_ok) ?></div>
    <?php endif; ?>

    <!-- Selector de lote -->
    <div class="card mb-4">
        <div class="card-header fw-bold">Seleccionar lote a editar</div>
        <div class="card-body">
            <form method="GET">
                <div class="row g-2 align-items-end">
                    <div class="col-md-10">
                        <select name="registro" class="form-control" required>
                            <option value="">-- Seleccionar lote --</option>
                            <?php foreach ($lotes_lista as $l): ?>
                            <option value="<?= htmlspecialchars($l['registro']) ?>"
                                <?= $l['registro'] === $registro ? 'selected' : '' ?>>
                                Sem <?= (int)$l['semana'] ?>/<?= (int)$l['anio'] ?> —
                                <?= htmlspecialchars($l['estado']) ?> —
                                <?= htmlspecialchars($l['fecha_carga']) ?> —
                                <?= htmlspecialchars($l['usuario_carga'] ?? '') ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <button type="submit" class="btn btn-primary w-100">Cargar</button>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <?php if ($lote_info): ?>
    <!-- Info del lote -->
    <div class="d-flex justify-content-between align-items-center mb-3">
        <div>
            <strong>Lote:</strong> <span class="text-muted"><?= htmlspecialchars($registro) ?></span>
            &nbsp;|&nbsp; Sem <strong><?= (int)$lote_info['semana'] ?></strong>/<?= (int)$lote_info['anio'] ?>
        </div>
        <span class="badge bg-<?= $badges[$lote_info['estado']] ?? 'secondary' ?>">
            <?= htmlspecialchars($lote_info['estado']) ?>
        </span>
    </div>

    <?php if (!empty($registros)): ?>
    <form method="POST">
        <input type="hidden" name="registro" value="<?= htmlspecialchars($registro) ?>">

        <div class="table-responsive" style="max-height:500px; overflow-y:auto;">
            <table class="table table-sm table-bordered table-hover mb-0">
                <thead class="table-dark sticky-top">
                    <tr>
                        <th>Fecha</th>
                        <th>RUT</th>
                        <th>Nombre</th>
                        <th>Área</th>
                        <th>Turno</th>
                        <th>Labor</th>
                        <th style="width:90px">Jornada</th>
                        <th style="width:90px">HH.EE</th>
                        <th>Especie</th>
                        <th>Obs</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($registros as $i => $r): ?>
                <tr>
                    <input type="hidden" name="id[]" value="<?= (int)$r['id'] ?>">
                    <td><?= htmlspecialchars($r['fecha']) ?></td>
                    <td><?= htmlspecialchars($r['rut'] ?? '') ?></td>
                    <td><?= htmlspecialchars($r['nombre'] ?? '') ?></td>
                    <td><?= htmlspecialchars($r['area_nombre'] ?? '') ?></td>
                    <td><?= htmlspecialchars($r['turno'] ?? '') ?></td>
                    <td>
                        <select name="cargo[]" class="form-control form-control-sm">
                            <?php foreach ($cargos_cat as $cid => $cnom): ?>
                            <option value="<?= $cid ?>" <?= $cid === (int)$r['cargo'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($cnom) ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </td>
                    <td>
                        <input type="number" name="jornada[]" class="form-control form-control-sm"
                               value="<?= number_format((float)$r['jornada'], 2, '.', '') ?>"
                               step="0.01" min="0">
                    </td>
                    <td>
                        <input type="number" name="hhee[]" class="form-control form-control-sm"
                               value="<?= number_format((float)$r['hhee'], 2, '.', '') ?>"
                               step="0.01" min="0">
                    </td>
                    <td><?= htmlspecialchars($r['especie'] ?? '') ?></td>
                    <td>
                        <input type="text" name="obs[]" class="form-control form-control-sm"
                               value="<?= htmlspecialchars($r['obs'] ?? '') ?>">
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <div class="mt-3 d-flex gap-2">
            <button type="submit" name="guardar" class="btn btn-primary">Guardar cambios</button>
            <a href="<?= BASE_URL ?>/aprobacion/detalle_asistencia.php?registro=<?= urlencode($registro) ?>"
               class="btn btn-outline-secondary">Ver detalle</a>
        </div>
    </form>
    <?php else: ?>
        <div class="alert alert-info">No se encontraron registros para este lote.</div>
    <?php endif; ?>
    <?php endif; ?>

</main>

<?php include __DIR__ . '/../partials/footer.php'; ?>
