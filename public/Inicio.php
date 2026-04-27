<?php
require_once __DIR__ . '/_bootstrap.php';

// Notificaciones para aprobadores: lotes pendientes o rechazados asignados al usuario
$notif_pendientes = 0;
$notif_rechazados = 0;

if (puede_aprobar() || es_admin()) {
    $id_usuario = $_SESSION['id_usuario'];
    $areas      = $_SESSION['areas_aprobar'] ?? [];

    if (es_jefe_operaciones() || es_admin()) {
        // Jefe de operaciones: lotes aprobados_area pendientes de su revisión
        $stmtP = sqlsrv_query($conn,
            "SELECT COUNT(*) AS cnt FROM dbo.dota_asistencia_lote WHERE estado = 'aprobado_area' AND ISNULL(activo,1)=1"
        );
        if ($stmtP) {
            $r = sqlsrv_fetch_array($stmtP, SQLSRV_FETCH_ASSOC);
            $notif_pendientes = (int)($r['cnt'] ?? 0);
        }
        $stmtR = sqlsrv_query($conn,
            "SELECT COUNT(*) AS cnt FROM dbo.dota_asistencia_lote WHERE estado = 'rechazado_ops' AND ISNULL(activo,1)=1"
        );
        if ($stmtR) {
            $r = sqlsrv_fetch_array($stmtR, SQLSRV_FETCH_ASSOC);
            $notif_rechazados = (int)($r['cnt'] ?? 0);
        }
    } elseif (!empty($areas)) {
        // Jefe de área: lotes pendientes o rechazados_ops que incluyan sus áreas
        $placeholders = implode(',', array_fill(0, count($areas), '?'));
        $params       = array_merge([$id_usuario], $areas);

        // Pendientes: lotes en estado pendiente que aún no aprobó este usuario para alguna de sus áreas
        $stmtP = sqlsrv_query($conn,
            "SELECT COUNT(DISTINCT l.registro) AS cnt
             FROM dbo.dota_asistencia_lote l
             JOIN dbo.dota_asistencia_carga a ON a.registro = l.registro AND a.area IN ($placeholders)
             WHERE l.estado = 'pendiente' AND ISNULL(l.activo,1)=1
               AND NOT EXISTS (
                   SELECT 1 FROM dbo.dota_asistencia_aprobacion ap
                   WHERE ap.registro = l.registro AND ap.id_usuario = ? AND ap.accion = 'aprobado'
               )",
            array_merge($areas, [$id_usuario])
        );
        if ($stmtP) {
            $r = sqlsrv_fetch_array($stmtP, SQLSRV_FETCH_ASSOC);
            $notif_pendientes = (int)($r['cnt'] ?? 0);
        }

        // Rechazados por operaciones: vuelven al jefe de área
        $stmtR = sqlsrv_query($conn,
            "SELECT COUNT(DISTINCT l.registro) AS cnt
             FROM dbo.dota_asistencia_lote l
             JOIN dbo.dota_asistencia_carga a ON a.registro = l.registro AND a.area IN ($placeholders)
             WHERE l.estado = 'rechazado_ops' AND ISNULL(l.activo,1)=1",
            $areas
        );
        if ($stmtR) {
            $r = sqlsrv_fetch_array($stmtR, SQLSRV_FETCH_ASSOC);
            $notif_rechazados = (int)($r['cnt'] ?? 0);
        }
    }
}

// ── Dashboard Reloj — datos del día ──────────────────────────────
$reloj_hoy = date('Y-m-d');
$reloj_dt  = $reloj_hoy . ' 00:00:00';
$reloj_fin = $reloj_hoy . ' 23:59:59';
$reloj_resumen = $reloj_adentro = $reloj_ultimas = null;

if (puede_modulo('reloj')) {
    $reloj_resumen = sqlsrv_query($conn, "
        SELECT d.nombre AS dispositivo,
               COUNT(DISTINCT CASE WHEN m.tipo=1 THEN m.id_numero END) AS entradas,
               COUNT(DISTINCT CASE WHEN m.tipo=0 THEN m.id_numero END) AS salidas
        FROM dbo.reloj_dispositivo d
        LEFT JOIN dbo.reloj_marcacion m
            ON m.id_dispositivo = d.id
            AND m.fecha_hora BETWEEN CONVERT(datetime,?,120) AND CONVERT(datetime,?,120)
        WHERE d.activo = 1
        GROUP BY d.id, d.nombre ORDER BY d.nombre
    ", [$reloj_dt, $reloj_fin]);

    $reloj_adentro = sqlsrv_query($conn, "
        SELECT m.id_numero,
               ISNULL(t.rut, CAST(m.id_numero AS NVARCHAR(20))) AS rut,
               ISNULL(t.nombre,'(sin registro)')                 AS nombre,
               MAX(m.fecha_hora)                                 AS ultima_marca,
               d.nombre AS dispositivo
        FROM dbo.reloj_marcacion m
        LEFT JOIN dbo.reloj_trabajador t ON t.id_numero = m.id_numero
        JOIN  dbo.reloj_dispositivo    d ON d.id = m.id_dispositivo
        WHERE m.fecha_hora BETWEEN CONVERT(datetime,?,120) AND CONVERT(datetime,?,120)
        GROUP BY m.id_numero, t.rut, t.nombre, d.nombre, d.id
        HAVING MAX(CASE WHEN m.tipo=1 THEN m.fecha_hora END) >
               ISNULL(MAX(CASE WHEN m.tipo=0 THEN m.fecha_hora END),'1900-01-01')
        ORDER BY nombre
    ", [$reloj_dt, $reloj_fin]);

    $reloj_ultimas = sqlsrv_query($conn, "
        SELECT TOP 10
            m.fecha_hora, m.tipo,
            ISNULL(t.nombre,'(sin registro)') AS nombre,
            d.nombre AS dispositivo
        FROM dbo.reloj_marcacion m
        LEFT JOIN dbo.reloj_trabajador t ON t.id_numero = m.id_numero
        JOIN  dbo.reloj_dispositivo    d ON d.id = m.id_dispositivo
        WHERE m.fecha_hora BETWEEN CONVERT(datetime,?,120) AND CONVERT(datetime,?,120)
        ORDER BY m.fecha_hora DESC
    ", [$reloj_dt, $reloj_fin]);
}

$title = "Inicio";
include __DIR__ . '/partials/header.php';
include __DIR__ . '/partials/navbar_wrapper.php';
?>

<main class="container-fluid py-4">

    <div class="text-center mb-3">
        <h1 class="display-6">Bienvenido, <?= htmlspecialchars(nombre_usuario()) ?></h1>
        <p class="text-muted mb-0">Sistema de Gestión de Personal Contratista — Condor de Apalta</p>
    </div>

    <?php if ($notif_pendientes > 0): ?>
    <div class="alert alert-warning d-flex align-items-center mx-3" role="alert">
        <strong class="me-2">Atención:</strong>
        Tienes <strong class="mx-1"><?= $notif_pendientes ?></strong>
        lote(s) de asistencia pendiente(s) de aprobación.
        <a href="<?= BASE_URL ?>/aprobacion/<?= es_jefe_operaciones() ? 'bandeja_operaciones' : 'bandeja_jefe' ?>.php"
           class="btn btn-warning btn-sm ms-auto">Ver bandeja</a>
    </div>
    <?php endif; ?>

    <?php if ($notif_rechazados > 0): ?>
    <div class="alert alert-danger d-flex align-items-center mx-3" role="alert">
        <strong class="me-2">Atención:</strong>
        Tienes <strong class="mx-1"><?= $notif_rechazados ?></strong>
        lote(s) de asistencia rechazado(s) para revisar.
        <a href="<?= BASE_URL ?>/aprobacion/<?= es_jefe_operaciones() ? 'bandeja_operaciones' : 'bandeja_jefe' ?>.php"
           class="btn btn-danger btn-sm ms-auto">Ver bandeja</a>
    </div>
    <?php endif; ?>

    <?php if (puede_modulo('reloj') && $reloj_resumen): ?>
    <hr class="mx-3">
    <div class="d-flex justify-content-between align-items-center px-3 mb-3">
        <h5 class="mb-0">
            Reloj Biométrico —
            <span class="text-muted fw-normal"><?= date('d/m/Y') ?></span>
        </h5>
        <a href="<?= BASE_URL ?>/reloj/dashboard.php" class="btn btn-sm btn-outline-secondary">
            Ver dashboard completo
        </a>
    </div>

    <!-- Resumen por reloj -->
    <div class="row g-3 px-3 mb-3">
    <?php while ($r = sqlsrv_fetch_array($reloj_resumen, SQLSRV_FETCH_ASSOC)): ?>
        <div class="col-md-4">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body py-2">
                    <div class="text-muted small"><?= htmlspecialchars($r['dispositivo']) ?></div>
                    <div class="d-flex gap-4 mt-1">
                        <div class="text-center">
                            <div class="fs-3 fw-bold text-success"><?= (int)$r['entradas'] ?></div>
                            <small class="text-muted">Entradas</small>
                        </div>
                        <div class="text-center">
                            <div class="fs-3 fw-bold text-danger"><?= (int)$r['salidas'] ?></div>
                            <small class="text-muted">Salidas</small>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    <?php endwhile; ?>
    </div>

    <!-- Personas dentro + Últimas marcaciones -->
    <div class="row g-3 px-3">
        <div class="col-md-7">
            <div class="card shadow-sm">
                <div class="card-header bg-success text-white py-2 fw-bold">
                    Personas dentro ahora
                </div>
                <div class="table-responsive">
                    <table class="table table-sm mb-0">
                        <thead><tr><th>Nombre</th><th>RUT</th><th>Entrada</th><th>Reloj</th></tr></thead>
                        <tbody>
                        <?php
                        $cnt = 0;
                        while ($a = sqlsrv_fetch_array($reloj_adentro, SQLSRV_FETCH_ASSOC)):
                            $cnt++;
                            $ts = $a['ultima_marca'] instanceof DateTime
                                  ? $a['ultima_marca']->format('H:i') : '';
                        ?>
                            <tr>
                                <td><?= htmlspecialchars($a['nombre']) ?></td>
                                <td><?= htmlspecialchars($a['rut']) ?></td>
                                <td><?= $ts ?></td>
                                <td><small><?= htmlspecialchars($a['dispositivo']) ?></small></td>
                            </tr>
                        <?php endwhile; ?>
                        <?php if ($cnt === 0): ?>
                            <tr><td colspan="4" class="text-center text-muted py-2">Sin personas dentro.</td></tr>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>
                <div class="card-footer text-muted small py-1"><?= $cnt ?> persona(s)</div>
            </div>
        </div>

        <div class="col-md-5">
            <div class="card shadow-sm">
                <div class="card-header bg-dark text-white py-2 fw-bold">
                    Últimas marcaciones
                </div>
                <div class="table-responsive">
                    <table class="table table-sm mb-0">
                        <thead><tr><th>Hora</th><th>Tipo</th><th>Nombre</th></tr></thead>
                        <tbody>
                        <?php while ($u = sqlsrv_fetch_array($reloj_ultimas, SQLSRV_FETCH_ASSOC)):
                            $ts = $u['fecha_hora'] instanceof DateTime
                                  ? $u['fecha_hora']->format('H:i:s') : '';
                        ?>
                            <tr>
                                <td><?= $ts ?></td>
                                <td><?= $u['tipo']==1
                                    ? '<span class="badge bg-success">Entrada</span>'
                                    : '<span class="badge bg-danger">Salida</span>' ?></td>
                                <td><?= htmlspecialchars($u['nombre']) ?></td>
                            </tr>
                        <?php endwhile; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>

</main>

<?php include __DIR__ . '/partials/footer.php'; ?>
