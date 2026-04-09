<?php
require_once __DIR__ . '/../_bootstrap.php';

if (!es_jefe_operaciones() && !es_admin()) {
    header('Location: ' . BASE_URL . '/Inicio.php'); exit;
}

$flash_error = null;
$flash_ok    = null;
$id_usuario  = (int)$_SESSION['id_usuario'];

// ── APROBAR (listo_factura) ───────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['aprobar'])) {
    $registro = trim($_POST['registro'] ?? '');
    if ($registro === '') {
        $flash_error = "Datos incompletos.";
    } else {
        sqlsrv_query($conn,
            "INSERT INTO dbo.dota_asistencia_aprobacion (registro, id_usuario, accion)
             VALUES (?, ?, 'aprobado')",
            [$registro, $id_usuario]
        );
        sqlsrv_query($conn,
            "UPDATE dbo.dota_asistencia_lote SET estado = 'listo_factura' WHERE registro = ?",
            [$registro]
        );
        $flash_ok = "Lote aprobado. Ya está disponible para Pre-Factura.";
    }
}

// ── RECHAZAR (rechazado_ops → vuelve a jefes área) ───────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['rechazar'])) {
    $registro    = trim($_POST['registro']    ?? '');
    $observacion = trim($_POST['observacion'] ?? '');
    $id_area     = (int)($_POST['id_area']    ?? 0) ?: null;

    if ($registro === '' || $observacion === '') {
        $flash_error = "Debe ingresar una observación al rechazar.";
    } else {
        sqlsrv_query($conn,
            "INSERT INTO dbo.dota_asistencia_aprobacion (registro, id_usuario, accion, observacion, id_area)
             VALUES (?, ?, 'rechazado', ?, ?)",
            [$registro, $id_usuario, $observacion, $id_area]
        );
        sqlsrv_query($conn,
            "UPDATE dbo.dota_asistencia_lote SET estado = 'rechazado_ops' WHERE registro = ?",
            [$registro]
        );
        $flash_ok = "Lote devuelto a los jefes de área.";
    }
}

// ── LISTAR: lotes aprobados_area + rechazados_ops (para ver historial) ────────
$lotes = [];
$stmtL = sqlsrv_query($conn,
    "SELECT l.registro, l.fecha_carga, l.semana, l.anio, l.estado,
            u.nombre + ' ' + ISNULL(u.apellido,'') AS usuario_carga,
            (SELECT COUNT(*) FROM dbo.dota_asistencia_carga WHERE registro = l.registro) AS total_reg,
            (SELECT COUNT(DISTINCT area) FROM dbo.dota_asistencia_carga WHERE registro = l.registro) AS total_areas
     FROM dbo.dota_asistencia_lote l
     LEFT JOIN dbo.dota_usuarios u ON u.id_usuario = l.id_usuario_carga
     WHERE l.estado IN ('aprobado_area','rechazado_ops')
     ORDER BY l.fecha_carga DESC"
);
if ($stmtL) {
    while ($r = sqlsrv_fetch_array($stmtL, SQLSRV_FETCH_ASSOC)) {
        // Historial de aprobaciones de jefes
        $stmtH = sqlsrv_query($conn,
            "SELECT ap.accion, ap.fecha, ap.observacion,
                    u.nombre + ' ' + ISNULL(u.apellido,'') AS aprobador,
                    a.Area
             FROM dbo.dota_asistencia_aprobacion ap
             LEFT JOIN dbo.dota_usuarios u ON u.id_usuario = ap.id_usuario
             LEFT JOIN dbo.Area          a ON a.id_area    = ap.id_area
             WHERE ap.registro = ?
             ORDER BY ap.fecha ASC",
            [$r['registro']]
        );
        $historial = [];
        while ($stmtH && ($h = sqlsrv_fetch_array($stmtH, SQLSRV_FETCH_ASSOC))) {
            $h['fecha'] = $h['fecha'] instanceof DateTime ? $h['fecha']->format('d/m/Y H:i') : (string)$h['fecha'];
            $historial[] = $h;
        }
        $r['historial']  = $historial;
        $r['fecha_carga'] = $r['fecha_carga'] instanceof DateTime
            ? $r['fecha_carga']->format('d/m/Y H:i')
            : (string)$r['fecha_carga'];
        $lotes[] = $r;
    }
}

// Áreas para select de rechazo
$areas_cat = [];
$stmtA = sqlsrv_query($conn, "SELECT id_area, Area FROM dbo.Area ORDER BY Area");
if ($stmtA) while ($r = sqlsrv_fetch_array($stmtA, SQLSRV_FETCH_ASSOC))
    $areas_cat[(int)$r['id_area']] = $r['Area'];

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
        <button type="submit" name="aprobar" id="ap-btn" style="display:none"></button>
    </form>
    <form id="form-rechazar" method="POST">
        <input type="hidden" name="registro"    id="re-registro">
        <input type="hidden" name="id_area"     id="re-area">
        <input type="hidden" name="observacion" id="re-obs">
        <button type="submit" name="rechazar" id="re-btn" style="display:none"></button>
    </form>

    <?php foreach ($lotes as $l): ?>
    <div class="card mb-4 shadow-sm">
        <div class="card-header d-flex justify-content-between align-items-center">
            <div>
                <strong>Sem <?= (int)$l['semana'] ?> / <?= (int)$l['anio'] ?></strong>
                <span class="text-muted ms-2" style="font-size:.85rem;"><?= htmlspecialchars($l['registro']) ?></span>
            </div>
            <div class="d-flex align-items-center gap-2">
                <?php
                $badges = ['aprobado_area' => 'success', 'rechazado_ops' => 'danger'];
                $cls    = $badges[$l['estado']] ?? 'secondary';
                ?>
                <span class="badge bg-<?= $cls ?>"><?= htmlspecialchars($l['estado']) ?></span>
                <small class="text-muted"><?= $l['fecha_carga'] ?> — <?= htmlspecialchars($l['usuario_carga'] ?? '') ?></small>
            </div>
        </div>
        <div class="card-body">
            <div class="row mb-3">
                <div class="col-md-4">
                    <strong><?= (int)$l['total_reg'] ?></strong> registros &nbsp;|&nbsp;
                    <strong><?= (int)$l['total_areas'] ?></strong> áreas
                </div>
                <div class="col-md-8 text-end">
                    <a href="detalle_asistencia.php?registro=<?= urlencode($l['registro']) ?>"
                       class="btn btn-outline-secondary btn-sm">Ver detalle</a>
                    <?php if ($l['estado'] === 'aprobado_area'): ?>
                    <button class="btn btn-success btn-sm"
                        onclick="aprobar(<?= htmlspecialchars(json_encode($l['registro']), ENT_QUOTES) ?>)">
                        Aprobar → Listo para Factura
                    </button>
                    <button class="btn btn-danger btn-sm"
                        onclick="rechazar(<?= htmlspecialchars(json_encode($l['registro']), ENT_QUOTES) ?>)">
                        Rechazar
                    </button>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Historial de aprobaciones -->
            <?php if (!empty($l['historial'])): ?>
            <table class="table table-sm table-bordered mb-0">
                <thead class="table-light">
                    <tr><th>Acción</th><th>Aprobador</th><th>Área</th><th>Fecha</th><th>Observación</th></tr>
                </thead>
                <tbody>
                <?php foreach ($l['historial'] as $h): ?>
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
    <?php endforeach; ?>

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
                    <label class="form-label">Área con el problema <small class="text-muted">(opcional)</small></label>
                    <select id="modal-area" class="form-control">
                        <option value="">-- General --</option>
                        <?php foreach ($areas_cat as $aid => $anom): ?>
                            <option value="<?= $aid ?>"><?= htmlspecialchars($anom) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="mb-3">
                    <label class="form-label">Observación <span class="text-danger">*</span></label>
                    <textarea id="modal-obs" class="form-control" rows="4"
                              placeholder="Describe el problema..."></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                <button type="button" class="btn btn-danger" onclick="confirmarRechazo()">Confirmar Rechazo</button>
            </div>
        </div>
    </div>
</div>

<?php include __DIR__ . '/../partials/footer.php'; ?>

<script>
let registroActual = '';

function aprobar(registro) {
    if (!confirm('¿Confirmar aprobación final? El lote quedará listo para Pre-Factura.')) return;
    document.getElementById('ap-registro').value = registro;
    document.getElementById('ap-btn').click();
}

function rechazar(registro) {
    registroActual = registro;
    document.getElementById('modal-obs').value = '';
    new bootstrap.Modal(document.getElementById('modalRechazo')).show();
}

function confirmarRechazo() {
    const obs = document.getElementById('modal-obs').value.trim();
    if (!obs) { alert('La observación es obligatoria.'); return; }
    document.getElementById('re-registro').value = registroActual;
    document.getElementById('re-area').value     = document.getElementById('modal-area').value;
    document.getElementById('re-obs').value      = obs;
    document.getElementById('re-btn').click();
}
</script>
