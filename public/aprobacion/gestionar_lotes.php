<?php
require_once __DIR__ . '/../_bootstrap.php';

if (!puede_modulo('gestion_estados') && !es_admin()) {
    header('Location: ' . BASE_URL . '/Inicio.php'); exit;
}

$flash_error = null;
$flash_ok    = null;
$id_usuario  = (int)$_SESSION['id_usuario'];

// Mapa legible de estados
$estados_labels = [
    'borrador'       => 'Borrador',
    'pendiente'      => 'Pendiente',
    'aprobado_area'  => 'Aprobado Área',
    'rechazado_area' => 'Rechazado Área',
    'rechazado_ops'  => 'Rechazado Operaciones',
    'listo_factura'  => 'Aprobado',
];
$estados_colores = [
    'borrador'       => 'badge-borrador',
    'pendiente'      => 'badge-pendiente',
    'aprobado_area'  => 'badge-pendiente',
    'rechazado_area' => 'badge-vencido',
    'rechazado_ops'  => 'badge-vencido',
    'listo_factura'  => 'badge-pagado',
];
$estados_validos = array_keys($estados_labels);

// ── CAMBIAR ESTADO ────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['cambiar_estado'])) {
    $registro       = trim($_POST['registro'] ?? '');
    $nuevo_estado   = trim($_POST['nuevo_estado'] ?? '');
    $observacion    = trim($_POST['observacion'] ?? '');

    if ($registro === '' || !in_array($nuevo_estado, $estados_validos, true)) {
        $flash_error = "Datos inválidos.";
    } elseif ($observacion === '') {
        $flash_error = "Debe ingresar una observación para justificar el cambio de estado.";
    } else {
        // Leer estado actual
        $stmtAct = sqlsrv_query($conn,
            "SELECT estado FROM dbo.dota_asistencia_lote WHERE registro = ? AND ISNULL(activo,1) = 1",
            [$registro]
        );
        $estado_actual = null;
        if ($stmtAct) {
            $ra = sqlsrv_fetch_array($stmtAct, SQLSRV_FETCH_ASSOC);
            $estado_actual = $ra['estado'] ?? null;
        }

        if ($estado_actual === null) {
            $flash_error = "Lote no encontrado.";
        } elseif ($estado_actual === $nuevo_estado) {
            $flash_error = "El lote ya está en el estado seleccionado.";
        } else {
            // Actualizar estado
            $r = sqlsrv_query($conn,
                "UPDATE dbo.dota_asistencia_lote SET estado = ? WHERE registro = ?",
                [$nuevo_estado, $registro]
            );
            if ($r === false) {
                $flash_error = "Error al actualizar el estado.";
            } else {
                // Registrar en historial
                sqlsrv_query($conn,
                    "INSERT INTO dbo.dota_asistencia_aprobacion
                        (registro, id_usuario, accion, observacion)
                     VALUES (?, ?, 'cambio_manual', ?)",
                    [$registro, $id_usuario,
                     "Estado cambiado de [{$estado_actual}] a [{$nuevo_estado}]: {$observacion}"]
                );
                $lbl_ant = $estados_labels[$estado_actual]  ?? $estado_actual;
                $lbl_nvo = $estados_labels[$nuevo_estado]   ?? $nuevo_estado;
                $flash_ok = "Estado del lote actualizado: {$lbl_ant} → {$lbl_nvo}.";
            }
        }
    }
}

// ── FILTRO ────────────────────────────────────────────────────────────────────
$filtro_estado = $_GET['estado'] ?? '';
$filtro_sem    = (int)($_GET['semana'] ?? 0);
$filtro_anio   = (int)($_GET['anio']   ?? 0);

$where_parts = ["ISNULL(l.activo, 1) = 1"];
$params      = [];

if ($filtro_estado !== '' && in_array($filtro_estado, $estados_validos, true)) {
    $where_parts[] = "l.estado = ?";
    $params[]      = $filtro_estado;
}
if ($filtro_sem > 0) {
    $where_parts[] = "l.semana = ?";
    $params[]      = $filtro_sem;
}
if ($filtro_anio > 0) {
    $where_parts[] = "l.anio = ?";
    $params[]      = $filtro_anio;
}

$where_sql = implode(' AND ', $where_parts);

// Paginación
$per_page = 25;
$page     = max(1, (int)($_GET['page'] ?? 1));
$offset   = ($page - 1) * $per_page;

$stmtCnt = sqlsrv_query($conn,
    "SELECT COUNT(*) FROM dbo.dota_asistencia_lote l WHERE {$where_sql}",
    $params
);
$total       = $stmtCnt ? (int)sqlsrv_fetch_array($stmtCnt, SQLSRV_FETCH_NUMERIC)[0] : 0;
$total_pages = max(1, (int)ceil($total / $per_page));
$page        = min($page, $total_pages);
$offset      = ($page - 1) * $per_page;

$stmtL = sqlsrv_query($conn,
    "SELECT l.registro, l.fecha_carga, l.semana, l.anio, l.estado,
            u.nombre + ' ' + ISNULL(u.apellido,'') AS usuario_carga,
            (SELECT COUNT(*) FROM dbo.dota_asistencia_carga WHERE registro = l.registro) AS total_reg,
            (SELECT COUNT(*) FROM dbo.dota_factura f
             WHERE f.semana = l.semana AND f.anio = l.anio) AS tiene_proforma
     FROM dbo.dota_asistencia_lote l
     LEFT JOIN dbo.dota_usuarios u ON u.id_usuario = l.id_usuario_carga
     WHERE {$where_sql}
     ORDER BY l.fecha_carga DESC
     OFFSET ? ROWS FETCH NEXT ? ROWS ONLY",
    array_merge($params, [$offset, $per_page])
);

$lotes = [];
if ($stmtL) {
    while ($r = sqlsrv_fetch_array($stmtL, SQLSRV_FETCH_ASSOC)) {
        $r['fecha_carga'] = $r['fecha_carga'] instanceof DateTime
            ? $r['fecha_carga']->format('d/m/Y H:i')
            : (string)$r['fecha_carga'];
        $lotes[] = $r;
    }
}

// URL base para paginación con filtros
$qs_base = http_build_query(array_filter([
    'estado' => $filtro_estado,
    'semana' => $filtro_sem ?: null,
    'anio'   => $filtro_anio ?: null,
]));

$title = "Gestionar Lotes";
include __DIR__ . '/../partials/header.php';
include __DIR__ . '/../partials/navbar_wrapper.php';
?>

<main class="container py-4">
    <h1 class="display-5 text-center mb-1">Gestión de Estados de Lotes</h1>
    <p class="text-center text-muted mb-4">Permite cambiar manualmente el estado de cualquier lote de asistencia.</p>

    <?php if ($flash_error): ?>
        <div class="alert alert-danger"><?= htmlspecialchars($flash_error) ?></div>
    <?php endif; ?>
    <?php if ($flash_ok): ?>
        <div class="alert alert-success"><?= htmlspecialchars($flash_ok) ?></div>
    <?php endif; ?>

    <!-- Filtros -->
    <form method="GET" class="card card-body mb-4 p-3">
        <div class="row g-2 align-items-end">
            <div class="col-md-3">
                <label class="form-label small">Estado</label>
                <select name="estado" class="form-select form-select-sm">
                    <option value="">— Todos —</option>
                    <?php foreach ($estados_labels as $key => $lbl): ?>
                    <option value="<?= $key ?>" <?= $filtro_estado === $key ? 'selected' : '' ?>>
                        <?= $lbl ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label small">Semana</label>
                <input type="number" name="semana" class="form-control form-control-sm"
                       value="<?= $filtro_sem ?: '' ?>" min="1" max="53" placeholder="ej. 15">
            </div>
            <div class="col-md-2">
                <label class="form-label small">Año</label>
                <input type="number" name="anio" class="form-control form-control-sm"
                       value="<?= $filtro_anio ?: '' ?>" min="2020" max="2100" placeholder="ej. 2026">
            </div>
            <div class="col-md-2">
                <button type="submit" class="btn btn-primary btn-sm w-100">Filtrar</button>
            </div>
            <div class="col-md-2">
                <a href="gestionar_lotes.php" class="btn btn-outline-secondary btn-sm w-100">Limpiar</a>
            </div>
        </div>
    </form>

    <!-- Formulario oculto cambio estado -->
    <form id="form-cambio" method="POST">
        <input type="hidden" name="cambiar_estado" value="1">
        <input type="hidden" name="registro"     id="cs-registro">
        <input type="hidden" name="nuevo_estado" id="cs-nuevo_estado">
        <input type="hidden" name="observacion"  id="cs-observacion">
    </form>

    <!-- Tabla lotes -->
    <?php if (empty($lotes)): ?>
        <div class="alert alert-info text-center">No se encontraron lotes con los filtros seleccionados.</div>
    <?php else: ?>
    <div class="table-responsive">
        <table class="table table-bordered table-hover align-middle small">
            <thead class="table-dark">
                <tr>
                    <th>Semana</th>
                    <th>Año</th>
                    <th>Cargado</th>
                    <th>Por</th>
                    <th class="text-center">Registros</th>
                    <th class="text-center">Estado actual</th>
                    <th class="text-center" style="width:220px;">Cambiar a</th>
                    <th class="text-center">Detalle</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($lotes as $l):
                $cls_est      = $estados_colores[$l['estado']] ?? 'secondary';
                $lbl_est      = $estados_labels[$l['estado']]  ?? $l['estado'];
                $tiene_pf     = (int)($l['tiene_proforma'] ?? 0) > 0;
            ?>
            <tr class="<?= $tiene_pf ? 'table-secondary' : '' ?>">
                <td class="text-center fw-semibold"><?= (int)$l['semana'] ?></td>
                <td class="text-center"><?= (int)$l['anio'] ?></td>
                <td class="text-nowrap"><?= htmlspecialchars($l['fecha_carga']) ?></td>
                <td><?= htmlspecialchars($l['usuario_carga'] ?? '') ?></td>
                <td class="text-center"><?= (int)$l['total_reg'] ?></td>
                <td class="text-center">
                    <span class="badge <?= $cls_est ?>"><span class="badge-dot"></span><?= $lbl_est ?></span>
                    <?php if ($tiene_pf): ?>
                    <br><span class="badge bg-dark mt-1" title="Semana ya tiene proforma generada">🔒 Proforma</span>
                    <?php endif; ?>
                    <div class="text-muted" style="font-size:.72rem;"><?= htmlspecialchars($l['registro']) ?></div>
                </td>
                <td class="text-center">
                    <?php if ($tiene_pf): ?>
                    <span class="text-muted small fst-italic">Bloqueado — hay proforma</span>
                    <?php else: ?>
                    <div class="d-flex gap-1 justify-content-center flex-wrap">
                        <?php foreach ($estados_labels as $key => $lbl):
                            if ($key === $l['estado']) continue; ?>
                        <button class="btn btn-sm btn-outline-<?= $estados_colores[$key] ?? 'secondary' ?> py-0 px-1"
                                style="font-size:.75rem;"
                                onclick="cambiarEstado(<?= htmlspecialchars(json_encode($l['registro']), ENT_QUOTES) ?>, '<?= $key ?>', '<?= addslashes($lbl) ?>', '<?= addslashes($lbl_est) ?>')">
                            <?= $lbl ?>
                        </button>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>
                </td>
                <td class="text-center">
                    <a href="detalle_asistencia.php?registro=<?= urlencode($l['registro']) ?>"
                       class="btn btn-sm btn-outline-secondary py-0">Ver</a>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <!-- Paginación -->
    <?php if ($total_pages > 1): ?>
    <nav class="mt-3">
        <ul class="pagination justify-content-center">
            <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>">
                <a class="page-link" href="?<?= $qs_base ?>&page=<?= $page - 1 ?>">«</a>
            </li>
            <?php for ($p = 1; $p <= $total_pages; $p++): ?>
            <li class="page-item <?= $p === $page ? 'active' : '' ?>">
                <a class="page-link" href="?<?= $qs_base ?>&page=<?= $p ?>"><?= $p ?></a>
            </li>
            <?php endfor; ?>
            <li class="page-item <?= $page >= $total_pages ? 'disabled' : '' ?>">
                <a class="page-link" href="?<?= $qs_base ?>&page=<?= $page + 1 ?>">»</a>
            </li>
        </ul>
        <p class="text-center text-muted small">Mostrando <?= $offset + 1 ?>–<?= min($offset + $per_page, $total) ?> de <?= $total ?> lotes</p>
    </nav>
    <?php endif; ?>

    <?php endif; ?>
</main>

<!-- Modal confirmación -->
<div class="modal fade" id="modalCambio" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Confirmar cambio de estado</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <p id="modal-texto"></p>
                <div class="mb-3">
                    <label class="form-label fw-semibold">Motivo / observación <span class="text-danger">*</span></label>
                    <textarea id="modal-obs" class="form-control" rows="3"
                              placeholder="Indique el motivo del cambio manual de estado..."></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                <button type="button" class="btn btn-primary" id="modal-confirmar">Confirmar cambio</button>
            </div>
        </div>
    </div>
</div>

<script>
let _reg = '', _nuevo = '';

function cambiarEstado(registro, nuevoEstado, lblNuevo, lblActual) {
    _reg   = registro;
    _nuevo = nuevoEstado;
    document.getElementById('modal-texto').textContent =
        'Vas a cambiar el estado de "' + lblActual + '" a "' + lblNuevo + '". Esta acción queda registrada en el historial.';
    document.getElementById('modal-obs').value = '';
    new bootstrap.Modal(document.getElementById('modalCambio')).show();
}

document.getElementById('modal-confirmar').addEventListener('click', function () {
    const obs = document.getElementById('modal-obs').value.trim();
    if (!obs) {
        document.getElementById('modal-obs').classList.add('is-invalid');
        return;
    }
    document.getElementById('cs-registro').value     = _reg;
    document.getElementById('cs-nuevo_estado').value = _nuevo;
    document.getElementById('cs-observacion').value  = obs;
    document.getElementById('form-cambio').submit();
});

document.getElementById('modal-obs').addEventListener('input', function () {
    this.classList.remove('is-invalid');
});
</script>

<?php
include __DIR__ . '/../partials/footer.php';
sqlsrv_close($conn);
?>
