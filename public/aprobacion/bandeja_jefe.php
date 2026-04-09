<?php
require_once __DIR__ . '/../_bootstrap.php';

if (!puede_aprobar() && !es_admin()) {
    header('Location: ' . BASE_URL . '/Inicio.php'); exit;
}

$flash_error = null;
$flash_ok    = null;

$id_usuario  = (int)$_SESSION['id_usuario'];
$areas_prop  = $_SESSION['areas_aprobar'] ?? [];   // áreas asignadas al usuario

// ── APROBAR ──────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['aprobar'])) {
    $registro = trim($_POST['registro'] ?? '');
    $id_area  = (int)($_POST['id_area'] ?? 0) ?: null;

    if ($registro === '') {
        $flash_error = "Datos incompletos.";
    } else {
        // Registrar aprobación
        sqlsrv_query($conn,
            "INSERT INTO dbo.dota_asistencia_aprobacion (registro, id_usuario, accion, id_area)
             VALUES (?, ?, 'aprobado', ?)",
            [$registro, $id_usuario, $id_area]
        );

        // Verificar si todos los jefes requeridos ya aprobaron
        // Jefes requeridos: usuarios activos nivel 1 con área dentro del lote
        $stmtTodos = sqlsrv_query($conn,
            "SELECT COUNT(*) AS total
             FROM (
                 SELECT DISTINCT j.id_usuario
                 FROM dbo.dota_jefe_area j
                 WHERE j.activo = 1 AND j.id_usuario IS NOT NULL AND j.nivel_aprobacion = 1
                   AND j.id_area IN (SELECT DISTINCT area FROM dbo.dota_asistencia_carga WHERE registro = ?)
             ) req
             WHERE NOT EXISTS (
                 SELECT 1 FROM dbo.dota_asistencia_aprobacion ap
                 WHERE ap.registro = ? AND ap.id_usuario = req.id_usuario AND ap.accion = 'aprobado'
             )",
            [$registro, $registro]
        );
        $pendientes = 0;
        if ($stmtTodos) {
            $r = sqlsrv_fetch_array($stmtTodos, SQLSRV_FETCH_ASSOC);
            $pendientes = (int)($r['total'] ?? 0);
        }

        if ($pendientes === 0) {
            sqlsrv_query($conn,
                "UPDATE dbo.dota_asistencia_lote SET estado = 'aprobado_area' WHERE registro = ?",
                [$registro]
            );
            $flash_ok = "Lote aprobado. Pasa a revisión del Jefe de Operaciones.";
        } else {
            $flash_ok = "Aprobación registrada. Esperando {$pendientes} aprobador(es) más.";
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

    if (!es_admin() && !empty($areas_prop)) {
        $ph = implode(',', array_fill(0, count($areas_prop), '?'));
        $whereAreas = "AND EXISTS (
            SELECT 1 FROM dbo.dota_asistencia_carga ac
            WHERE ac.registro = l.registro AND ac.area IN ($ph)
        )";
    }

    $sqlLotes = "
        SELECT l.registro, l.fecha_carga, l.semana, l.anio, l.estado,
               u.nombre + ' ' + ISNULL(u.apellido,'') AS usuario_carga,
               (SELECT COUNT(*) FROM dbo.dota_asistencia_carga WHERE registro = l.registro) AS total_reg,
               (SELECT COUNT(DISTINCT area) FROM dbo.dota_asistencia_carga WHERE registro = l.registro) AS total_areas
        FROM dbo.dota_asistencia_lote l
        LEFT JOIN dbo.dota_usuarios u ON u.id_usuario = l.id_usuario_carga
        WHERE l.estado IN ('pendiente','rechazado_ops')
        $whereAreas
        ORDER BY l.fecha_carga DESC
    ";

    $params = es_admin() ? [] : $areas_prop;
    $stmtL  = sqlsrv_query($conn, $sqlLotes, $params);
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

// Áreas del usuario para el select del formulario
$areas_usu = [];
if (!empty($areas_prop)) {
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
        <input type="hidden" name="registro" id="ap-registro">
        <input type="hidden" name="id_area"  id="ap-area">
        <button type="submit" name="aprobar" id="ap-btn" style="display:none"></button>
    </form>
    <form id="form-rechazar" method="POST">
        <input type="hidden" name="registro"    id="re-registro">
        <input type="hidden" name="id_area"     id="re-area">
        <input type="hidden" name="observacion" id="re-obs">
        <button type="submit" name="rechazar" id="re-btn" style="display:none"></button>
    </form>

    <div class="table-responsive">
        <table class="table table-bordered table-hover align-middle">
            <thead class="table-dark">
                <tr>
                    <th>Lote (registro)</th>
                    <th>Fecha carga</th>
                    <th>Semana / Año</th>
                    <th>Registros</th>
                    <th>Áreas</th>
                    <th>Estado</th>
                    <th>Tu acción</th>
                    <th>Acciones</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($lotes as $l): ?>
                <tr>
                    <td>
                        <small class="text-muted"><?= htmlspecialchars($l['registro']) ?></small><br>
                        <span class="text-muted"><?= htmlspecialchars($l['usuario_carga'] ?? '') ?></span>
                    </td>
                    <td><?= htmlspecialchars($l['fecha_carga']) ?></td>
                    <td class="text-center">Sem <strong><?= (int)$l['semana'] ?></strong> / <?= (int)$l['anio'] ?></td>
                    <td class="text-center"><?= (int)$l['total_reg'] ?></td>
                    <td class="text-center"><?= (int)$l['total_areas'] ?></td>
                    <td class="text-center">
                        <?php
                        $badges = [
                            'pendiente'     => 'warning text-dark',
                            'rechazado_ops' => 'danger',
                        ];
                        $cls = $badges[$l['estado']] ?? 'secondary';
                        ?>
                        <span class="badge bg-<?= $cls ?>"><?= htmlspecialchars($l['estado']) ?></span>
                    </td>
                    <td class="text-center">
                        <?php if ($l['ya_accion']): ?>
                            <span class="badge bg-<?= $l['ya_accion'] === 'aprobado' ? 'success' : 'danger' ?>">
                                <?= $l['ya_accion'] ?>
                            </span>
                        <?php else: ?>
                            <span class="text-muted">—</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <a href="detalle_asistencia.php?registro=<?= urlencode($l['registro']) ?>"
                           class="btn btn-outline-secondary btn-sm">Ver</a>

                        <?php if ($l['ya_accion'] !== 'aprobado'): ?>
                        <button class="btn btn-success btn-sm"
                            onclick="aprobar(<?= htmlspecialchars(json_encode($l['registro']), ENT_QUOTES) ?>)">
                            Aprobar
                        </button>
                        <button class="btn btn-danger btn-sm"
                            onclick="rechazar(<?= htmlspecialchars(json_encode($l['registro']), ENT_QUOTES) ?>)">
                            Rechazar
                        </button>
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

<?php include __DIR__ . '/../partials/footer.php'; ?>

<script>
let registroActual = '';

function aprobar(registro) {
    if (!confirm('¿Confirmar aprobación del lote?')) return;
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
