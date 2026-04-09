<?php
require_once __DIR__ . '/../_bootstrap.php';

if (!puede_aprobar() && !puede_modulo('procesos') && !es_admin()) {
    header('Location: ' . BASE_URL . '/Inicio.php'); exit;
}

$registro = trim($_GET['registro'] ?? '');
if ($registro === '') {
    header('Location: ' . BASE_URL . '/Inicio.php'); exit;
}

// Cabecera del lote
$lote = null;
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

if (!$lote) {
    echo '<div class="container py-4"><div class="alert alert-danger">Lote no encontrado.</div></div>';
    exit;
}

// Registros del lote
$registros = [];
$stmtR = sqlsrv_query($conn,
    "SELECT ac.fecha, ac.rut, ac.nombre, ac.sexo,
            ar.Area AS area, dc.cargo AS labor, tr.nombre_turno AS turno,
            ac.jornada, ac.hhee, ac.especie, ac.obs
     FROM dbo.dota_asistencia_carga ac
     LEFT JOIN dbo.Area         ar ON ar.id_area    = ac.area
     LEFT JOIN dbo.Dota_Cargo   dc ON dc.id_cargo   = ac.cargo
     LEFT JOIN dbo.dota_turno   tr ON tr.id         = ac.turno
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

// Historial de aprobaciones
$historial = [];
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
if ($stmtH) {
    while ($h = sqlsrv_fetch_array($stmtH, SQLSRV_FETCH_ASSOC)) {
        if ($h['fecha'] instanceof DateTime) $h['fecha'] = $h['fecha']->format('d/m/Y H:i');
        $historial[] = $h;
    }
}

$badges = [
    'pendiente'      => 'warning text-dark',
    'aprobado_area'  => 'success',
    'rechazado_area' => 'danger',
    'aprobado_ops'   => 'success',
    'rechazado_ops'  => 'danger',
    'listo_factura'  => 'primary',
];

$title = "Detalle Asistencia";
include __DIR__ . '/../partials/header.php';
include __DIR__ . '/../partials/navbar_wrapper.php';
?>

<main class="container py-4">

    <!-- Cabecera -->
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h4 class="mb-1">Detalle del Lote</h4>
            <small class="text-muted"><?= htmlspecialchars($registro) ?></small>
        </div>
        <div class="text-end">
            <?php $cls = $badges[$lote['estado']] ?? 'secondary'; ?>
            <span class="badge bg-<?= $cls ?> fs-6"><?= htmlspecialchars($lote['estado']) ?></span><br>
            <small class="text-muted">
                Sem <strong><?= (int)$lote['semana'] ?></strong> / <?= (int)$lote['anio'] ?> &nbsp;|&nbsp;
                Cargado por <?= htmlspecialchars($lote['usuario_carga'] ?? '—') ?>
                el <?= htmlspecialchars($lote['fecha_carga']) ?>
            </small>
        </div>
    </div>

    <div class="d-flex gap-2 mb-4">
        <a href="javascript:history.back()" class="btn btn-outline-secondary btn-sm">← Volver</a>
        <?php if (puede_modulo('procesos') || es_admin()): ?>
        <a href="<?= BASE_URL ?>/procesos/editar_asistencia.php?registro=<?= urlencode($registro) ?>"
           class="btn btn-outline-warning btn-sm">Editar asistencia</a>
        <?php endif; ?>
    </div>

    <!-- Resumen numérico -->
    <?php
    $totalJ = array_sum(array_column($registros, 'jornada'));
    $totalH = array_sum(array_column($registros, 'hhee'));
    ?>
    <div class="row g-3 mb-4">
        <div class="col-sm-4">
            <div class="card text-center">
                <div class="card-body py-2">
                    <div class="fs-4 fw-bold"><?= count($registros) ?></div>
                    <small class="text-muted">Registros</small>
                </div>
            </div>
        </div>
        <div class="col-sm-4">
            <div class="card text-center">
                <div class="card-body py-2">
                    <div class="fs-4 fw-bold"><?= number_format($totalJ, 2) ?></div>
                    <small class="text-muted">Total Jornada</small>
                </div>
            </div>
        </div>
        <div class="col-sm-4">
            <div class="card text-center">
                <div class="card-body py-2">
                    <div class="fs-4 fw-bold"><?= number_format($totalH, 2) ?></div>
                    <small class="text-muted">Total HH.EE</small>
                </div>
            </div>
        </div>
    </div>

    <!-- Tabla de registros -->
    <div class="card mb-4">
        <div class="card-header fw-bold">Registros de asistencia</div>
        <div class="card-body p-0">
            <div class="table-responsive" style="max-height:450px; overflow-y:auto;">
                <table class="table table-sm table-bordered table-hover mb-0">
                    <thead class="table-dark sticky-top">
                        <tr>
                            <th>Fecha</th>
                            <th>RUT</th>
                            <th>Nombre</th>
                            <th>Área</th>
                            <th>Labor</th>
                            <th>Turno</th>
                            <th class="text-end">Jornada</th>
                            <th class="text-end">HH.EE</th>
                            <th>Especie</th>
                            <th>Obs</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($registros as $r): ?>
                        <tr>
                            <td><?= htmlspecialchars($r['fecha']) ?></td>
                            <td><?= htmlspecialchars($r['rut'] ?? '') ?></td>
                            <td><?= htmlspecialchars($r['nombre'] ?? '') ?></td>
                            <td><?= htmlspecialchars($r['area'] ?? '') ?></td>
                            <td><?= htmlspecialchars($r['labor'] ?? '') ?></td>
                            <td><?= htmlspecialchars($r['turno'] ?? '') ?></td>
                            <td class="text-end"><?= number_format((float)$r['jornada'], 2) ?></td>
                            <td class="text-end"><?= number_format((float)$r['hhee'], 2) ?></td>
                            <td><?= htmlspecialchars($r['especie'] ?? '') ?></td>
                            <td><?= htmlspecialchars($r['obs'] ?? '') ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Historial de aprobaciones -->
    <div class="card">
        <div class="card-header fw-bold">Historial de aprobaciones</div>
        <div class="card-body p-0">
            <?php if (empty($historial)): ?>
                <p class="text-muted p-3 mb-0">Sin acciones registradas aún.</p>
            <?php else: ?>
            <table class="table table-sm table-bordered mb-0">
                <thead class="table-light">
                    <tr><th>Acción</th><th>Aprobador</th><th>Área</th><th>Fecha</th><th>Observación</th></tr>
                </thead>
                <tbody>
                <?php foreach ($historial as $h): ?>
                <tr>
                    <td>
                        <span class="badge bg-<?= $h['accion'] === 'aprobado' ? 'success' : ($h['accion'] === 'rechazado' ? 'danger' : 'secondary') ?>">
                            <?= htmlspecialchars($h['accion']) ?>
                        </span>
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

</main>

<?php include __DIR__ . '/../partials/footer.php'; ?>
