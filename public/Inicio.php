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
            "SELECT COUNT(*) AS cnt FROM dbo.dota_asistencia_lote WHERE estado = 'aprobado_area'"
        );
        if ($stmtP) {
            $r = sqlsrv_fetch_array($stmtP, SQLSRV_FETCH_ASSOC);
            $notif_pendientes = (int)($r['cnt'] ?? 0);
        }
        $stmtR = sqlsrv_query($conn,
            "SELECT COUNT(*) AS cnt FROM dbo.dota_asistencia_lote WHERE estado = 'rechazado_ops'"
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
             WHERE l.estado = 'pendiente'
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
             WHERE l.estado = 'rechazado_ops'",
            $areas
        );
        if ($stmtR) {
            $r = sqlsrv_fetch_array($stmtR, SQLSRV_FETCH_ASSOC);
            $notif_rechazados = (int)($r['cnt'] ?? 0);
        }
    }
}

$title = "Inicio";
include __DIR__ . '/partials/header.php';
include __DIR__ . '/partials/navbar_wrapper.php';
?>

<main class="container py-4">

    <div class="text-center my-4">
        <h1 class="display-5">Bienvenido, <?= htmlspecialchars(nombre_usuario()) ?></h1>
        <p class="text-muted">Sistema de Gestión de Personal Contratista — Condor de Apalta</p>
    </div>

    <?php if ($notif_pendientes > 0): ?>
    <div class="alert alert-warning d-flex align-items-center" role="alert">
        <strong class="me-2">Atención:</strong>
        Tienes <strong class="mx-1"><?= $notif_pendientes ?></strong>
        lote(s) de asistencia pendiente(s) de aprobación.
        <a href="<?= BASE_URL ?>/aprobacion/<?= es_jefe_operaciones() ? 'bandeja_operaciones' : 'bandeja_jefe' ?>.php"
           class="btn btn-warning btn-sm ms-auto">Ver bandeja</a>
    </div>
    <?php endif; ?>

    <?php if ($notif_rechazados > 0): ?>
    <div class="alert alert-danger d-flex align-items-center" role="alert">
        <strong class="me-2">Atención:</strong>
        Tienes <strong class="mx-1"><?= $notif_rechazados ?></strong>
        lote(s) de asistencia rechazado(s) para revisar.
        <a href="<?= BASE_URL ?>/aprobacion/<?= es_jefe_operaciones() ? 'bandeja_operaciones' : 'bandeja_jefe' ?>.php"
           class="btn btn-danger btn-sm ms-auto">Ver bandeja</a>
    </div>
    <?php endif; ?>

</main>

<?php include __DIR__ . '/partials/footer.php'; ?>
