<?php
require_once __DIR__ . '/../_bootstrap.php';
require_once __DIR__ . '/../../app/lib/db.php';

if (!es_admin()) {
    header('Location: ' . BASE_URL . '/Inicio.php'); exit;
}

$title       = "Administración — Eliminar Documentos";
$flash_error = null;
$flash_ok    = null;

/* ══════════════════════════════════════════════════════════════
   ACCIONES POST
══════════════════════════════════════════════════════════════ */

// ── Eliminar proforma ────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['eliminar_proforma'])) {
    $id = (int)($_POST['id_factura'] ?? 0);
    if ($id <= 0) {
        $flash_error = "ID de proforma inválido.";
    } else {
        // Verificar que existe
        $chk = sqlsrv_query($conn, "SELECT semana, anio, version, estado FROM dbo.dota_factura WHERE id = ?", [$id]);
        $pf  = $chk ? sqlsrv_fetch_array($chk, SQLSRV_FETCH_ASSOC) : null;
        if (!$pf) {
            $flash_error = "Proforma #$id no encontrada.";
        } else {
            sqlsrv_query($conn, "DELETE FROM dbo.dota_factura_descuento WHERE id_factura = ?", [$id]);
            sqlsrv_query($conn, "DELETE FROM dbo.dota_factura_detalle   WHERE id_factura = ?", [$id]);
            $del = sqlsrv_query($conn, "DELETE FROM dbo.dota_factura WHERE id = ?", [$id]);
            if ($del === false) {
                $errs = sqlsrv_errors();
                $flash_error = "Error al eliminar proforma: " . ($errs[0]['message'] ?? 'desconocido');
            } else {
                $flash_ok = "Proforma #$id (Sem {$pf['semana']}/{$pf['anio']} v{$pf['version']}, {$pf['estado']}) eliminada correctamente.";
            }
        }
    }
}

// ── Eliminar lote de asistencia ──────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['eliminar_lote'])) {
    $reg = trim($_POST['registro'] ?? '');
    if ($reg === '') {
        $flash_error = "Registro de lote inválido.";
    } else {
        // Verificar que existe y no está en proforma cerrada
        $chk = sqlsrv_query($conn,
            "SELECT l.semana, l.anio, l.estado,
                    (SELECT COUNT(*) FROM dbo.dota_factura f
                     WHERE f.semana = l.semana AND f.anio = l.anio AND f.estado = 'cerrado') AS en_proforma_cerrada
             FROM dbo.dota_asistencia_lote l WHERE l.registro = ?", [$reg]);
        $lote = $chk ? sqlsrv_fetch_array($chk, SQLSRV_FETCH_ASSOC) : null;
        if (!$lote) {
            $flash_error = "Lote no encontrado.";
        } elseif ((int)$lote['en_proforma_cerrada'] > 0) {
            $flash_error = "No se puede eliminar: la semana {$lote['semana']}/{$lote['anio']} tiene proforma(s) cerrada(s).";
        } else {
            $n = sqlsrv_query($conn, "SELECT COUNT(*) FROM dbo.dota_asistencia_carga WHERE registro = ?", [$reg]);
            $n_rows = $n ? (int)sqlsrv_fetch_array($n, SQLSRV_FETCH_NUMERIC)[0] : 0;

            sqlsrv_query($conn, "DELETE FROM dbo.dota_asistencia_aprobacion WHERE registro = ?", [$reg]);
            sqlsrv_query($conn, "DELETE FROM dbo.dota_asistencia_carga      WHERE registro = ?", [$reg]);
            $del = sqlsrv_query($conn, "DELETE FROM dbo.dota_asistencia_lote WHERE registro = ?", [$reg]);
            if ($del === false) {
                $errs = sqlsrv_errors();
                $flash_error = "Error al eliminar lote: " . ($errs[0]['message'] ?? 'desconocido');
            } else {
                $flash_ok = "Lote '{$reg}' eliminado ({$n_rows} registros de asistencia).";
            }
        }
    }
}

/* ══════════════════════════════════════════════════════════════
   FILTROS
══════════════════════════════════════════════════════════════ */
$tab      = in_array($_GET['tab'] ?? '', ['lotes'], true) ? 'lotes' : 'proformas';
$f_semana = (int)($_GET['semana'] ?? 0);
$f_anio   = (int)($_GET['anio']   ?? (int)date('Y'));

/* ══════════════════════════════════════════════════════════════
   DATOS — PROFORMAS
══════════════════════════════════════════════════════════════ */
$proformas = [];
if ($tab === 'proformas') {
    $where  = "WHERE f.anio = ?";
    $params = [$f_anio];
    if ($f_semana > 0) { $where .= " AND f.semana = ?"; $params[] = $f_semana; }

    $stmt = sqlsrv_query($conn,
        "SELECT f.id, f.semana, f.anio, f.version, f.obs, f.estado,
                f.fecha_creacion, f.tot_factura, f.total_neto,
                (SELECT STRING_AGG(nombre, ', ')
                 FROM (SELECT DISTINCT c.nombre
                       FROM dbo.dota_factura_detalle fd
                       JOIN dbo.dota_contratista c ON c.id = fd.id_contratista
                       WHERE fd.id_factura = f.id) sub) AS contratistas
         FROM dbo.dota_factura f
         $where
         ORDER BY f.anio DESC, f.semana DESC, f.version DESC",
        $params);
    if ($stmt) {
        while ($r = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            $r['fecha_str'] = $r['fecha_creacion'] instanceof DateTime
                ? $r['fecha_creacion']->format('d/m/Y H:i') : substr((string)$r['fecha_creacion'], 0, 16);
            $proformas[] = $r;
        }
    }
}

/* ══════════════════════════════════════════════════════════════
   DATOS — LOTES
══════════════════════════════════════════════════════════════ */
$lotes = [];
if ($tab === 'lotes') {
    $where  = "WHERE l.anio = ?";
    $params = [$f_anio];
    if ($f_semana > 0) { $where .= " AND l.semana = ?"; $params[] = $f_semana; }

    $stmt = sqlsrv_query($conn,
        "SELECT l.registro, l.semana, l.anio, l.estado, l.fecha_carga,
                u.nombre + ' ' + ISNULL(u.apellido,'') AS usuario_carga,
                (SELECT COUNT(*) FROM dbo.dota_asistencia_carga WHERE registro = l.registro) AS total_reg,
                (SELECT COUNT(*) FROM dbo.dota_factura f
                 WHERE f.semana = l.semana AND f.anio = l.anio AND f.estado = 'cerrado') AS en_proforma_cerrada
         FROM dbo.dota_asistencia_lote l
         LEFT JOIN dbo.dota_usuarios u ON u.id_usuario = l.id_usuario_carga
         $where
         ORDER BY l.fecha_carga DESC",
        $params);
    if ($stmt) {
        while ($r = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            $r['fecha_str'] = $r['fecha_carga'] instanceof DateTime
                ? $r['fecha_carga']->format('d/m/Y H:i') : substr((string)$r['fecha_carga'], 0, 16);
            $lotes[] = $r;
        }
    }
}

function fmt_adm($n) { return '$' . number_format((float)$n, 0, ',', '.'); }

include __DIR__ . '/../partials/header.php';
include __DIR__ . '/../partials/navbar_wrapper.php';
?>

<main class="container py-4">
<h1 class="display-5 text-center mb-1">Administración — Eliminar Documentos</h1>
<p class="text-center text-muted mb-4">Solo administradores. Las eliminaciones son <strong>permanentes</strong>.</p>

<?php if ($flash_error): ?>
<div class="alert alert-danger"><?= htmlspecialchars($flash_error) ?></div>
<?php endif; ?>
<?php if ($flash_ok): ?>
<div class="alert alert-success"><?= htmlspecialchars($flash_ok) ?></div>
<?php endif; ?>

<!-- Tabs -->
<ul class="nav nav-tabs mb-4">
    <li class="nav-item">
        <a class="nav-link <?= $tab === 'proformas' ? 'active' : '' ?>"
           href="?tab=proformas&semana=<?= $f_semana ?>&anio=<?= $f_anio ?>">
            Proformas
        </a>
    </li>
    <li class="nav-item">
        <a class="nav-link <?= $tab === 'lotes' ? 'active' : '' ?>"
           href="?tab=lotes&semana=<?= $f_semana ?>&anio=<?= $f_anio ?>">
            Lotes de Asistencia
        </a>
    </li>
</ul>

<!-- Filtros -->
<form method="GET" class="card card-body mb-4 p-3">
    <input type="hidden" name="tab" value="<?= $tab ?>">
    <div class="row g-2 align-items-end">
        <div class="col-md-2">
            <label class="form-label small">Semana</label>
            <input type="number" name="semana" class="form-control form-control-sm"
                   value="<?= $f_semana ?: '' ?>" min="1" max="53" placeholder="Todas">
        </div>
        <div class="col-md-2">
            <label class="form-label small">Año</label>
            <input type="number" name="anio" class="form-control form-control-sm"
                   value="<?= $f_anio ?>" min="2020" max="2100">
        </div>
        <div class="col-md-2">
            <button type="submit" class="btn btn-primary btn-sm w-100">Filtrar</button>
        </div>
        <div class="col-md-2">
            <a href="?tab=<?= $tab ?>&anio=<?= date('Y') ?>" class="btn btn-outline-secondary btn-sm w-100">Limpiar</a>
        </div>
    </div>
</form>

<?php if ($tab === 'proformas'): ?>
<!-- ══════════ TAB PROFORMAS ══════════ -->
<?php if (empty($proformas)): ?>
<div class="alert alert-info text-center">No hay proformas para los filtros seleccionados.</div>
<?php else: ?>
<div class="table-responsive">
<table class="table table-bordered table-hover align-middle small">
    <thead class="table-dark">
        <tr>
            <th>ID</th>
            <th class="text-center">Sem / Año</th>
            <th class="text-center">Versión</th>
            <th class="text-center">Estado</th>
            <th>Creada</th>
            <th>Contratistas</th>
            <th class="text-end">Total Factura</th>
            <th class="text-end">Neto</th>
            <th class="text-center">Obs</th>
            <th class="text-center" style="width:110px">Acción</th>
        </tr>
    </thead>
    <tbody>
    <?php foreach ($proformas as $pf):
        $cerrada = $pf['estado'] === 'cerrado';
    ?>
    <tr class="<?= $cerrada ? 'table-danger' : '' ?>">
        <td class="fw-semibold">#<?= (int)$pf['id'] ?></td>
        <td class="text-center"><?= (int)$pf['semana'] ?> / <?= (int)$pf['anio'] ?></td>
        <td class="text-center">v<?= (int)$pf['version'] ?></td>
        <td class="text-center">
            <?php if ($cerrada): ?>
            <span class="badge bg-dark">Cerrada</span>
            <?php else: ?>
            <span class="badge bg-warning text-dark">En proceso</span>
            <?php endif; ?>
        </td>
        <td class="text-nowrap"><?= htmlspecialchars($pf['fecha_str']) ?></td>
        <td><?= htmlspecialchars($pf['contratistas'] ?? '—') ?></td>
        <td class="text-end"><?= fmt_adm($pf['tot_factura']) ?></td>
        <td class="text-end"><?= fmt_adm($pf['total_neto']) ?></td>
        <td><?= htmlspecialchars(mb_strimwidth($pf['obs'] ?? '', 0, 40, '…')) ?></td>
        <td class="text-center">
            <form method="POST"
                  onsubmit="return confirmarEliminarProforma(<?= (int)$pf['id'] ?>, <?= (int)$pf['semana'] ?>, <?= (int)$pf['anio'] ?>, '<?= $cerrada ? 'cerrada' : 'en proceso' ?>')">
                <input type="hidden" name="eliminar_proforma" value="1">
                <input type="hidden" name="id_factura" value="<?= (int)$pf['id'] ?>">
                <button type="submit" class="btn btn-danger btn-sm">
                    Eliminar
                </button>
            </form>
        </td>
    </tr>
    <?php endforeach; ?>
    </tbody>
</table>
</div>
<?php endif; ?>

<?php else: /* tab = lotes */ ?>
<!-- ══════════ TAB LOTES ══════════ -->
<?php if (empty($lotes)): ?>
<div class="alert alert-info text-center">No hay lotes para los filtros seleccionados.</div>
<?php else: ?>
<div class="table-responsive">
<table class="table table-bordered table-hover align-middle small">
    <thead class="table-dark">
        <tr>
            <th>Registro</th>
            <th class="text-center">Sem / Año</th>
            <th class="text-center">Estado</th>
            <th>Cargado</th>
            <th>Por</th>
            <th class="text-center">Registros</th>
            <th class="text-center" style="width:110px">Acción</th>
        </tr>
    </thead>
    <tbody>
    <?php
    $badges = [
        'borrador'       => 'secondary',
        'pendiente'      => 'info',
        'aprobado_area'  => 'primary',
        'rechazado_area' => 'warning',
        'rechazado_ops'  => 'danger',
        'listo_factura'  => 'success',
        'eliminado'      => 'dark',
    ];
    foreach ($lotes as $l):
        $bloqueado = (int)$l['en_proforma_cerrada'] > 0;
        $cls_badge = $badges[$l['estado']] ?? 'secondary';
    ?>
    <tr class="<?= $bloqueado ? 'table-warning' : '' ?>">
        <td style="font-size:.78rem;word-break:break-all"><?= htmlspecialchars($l['registro']) ?></td>
        <td class="text-center"><?= (int)$l['semana'] ?> / <?= (int)$l['anio'] ?></td>
        <td class="text-center">
            <span class="badge bg-<?= $cls_badge ?>"><?= htmlspecialchars($l['estado']) ?></span>
            <?php if ($bloqueado): ?>
            <br><span class="badge bg-warning text-dark mt-1">Proforma cerrada</span>
            <?php endif; ?>
        </td>
        <td class="text-nowrap"><?= htmlspecialchars($l['fecha_str']) ?></td>
        <td><?= htmlspecialchars(trim($l['usuario_carga'] ?? '')) ?></td>
        <td class="text-center"><?= (int)$l['total_reg'] ?></td>
        <td class="text-center">
            <?php if ($bloqueado): ?>
            <span class="text-muted small">Bloqueado</span>
            <?php else: ?>
            <form method="POST"
                  onsubmit="return confirmarEliminarLote(<?= htmlspecialchars(json_encode($l['registro']), ENT_QUOTES) ?>, <?= (int)$l['total_reg'] ?>)">
                <input type="hidden" name="eliminar_lote" value="1">
                <input type="hidden" name="registro" value="<?= htmlspecialchars($l['registro'], ENT_QUOTES) ?>">
                <button type="submit" class="btn btn-danger btn-sm">
                    Eliminar
                </button>
            </form>
            <?php endif; ?>
        </td>
    </tr>
    <?php endforeach; ?>
    </tbody>
</table>
</div>
<?php endif; ?>
<?php endif; ?>

</main>

<script>
function confirmarEliminarProforma(id, semana, anio, estado) {
    var msg = '⚠ ELIMINAR PROFORMA #' + id + '\n'
            + 'Semana ' + semana + ' / ' + anio + ' — ' + estado.toUpperCase() + '\n\n'
            + 'Esta acción es PERMANENTE e irreversible.\n'
            + '¿Confirmas la eliminación?';
    return confirm(msg);
}

function confirmarEliminarLote(registro, nReg) {
    var msg = '⚠ ELIMINAR LOTE DE ASISTENCIA\n'
            + registro + '\n'
            + nReg + ' registros de asistencia serán eliminados permanentemente.\n\n'
            + '¿Confirmas la eliminación?';
    return confirm(msg);
}
</script>

<?php include __DIR__ . '/../partials/footer.php'; ?>
