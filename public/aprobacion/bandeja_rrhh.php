<?php
require_once __DIR__ . '/../_bootstrap.php';

if (!puede_modulo('procesos') && !es_admin()) {
    header('Location: ' . BASE_URL . '/Inicio.php'); exit;
}

// ── LISTAR LOTES ──────────────────────────────────────────────────────────────
$lotes = [];
$stmtL = sqlsrv_query($conn,
    "SELECT l.registro, l.fecha_carga, l.semana, l.anio, l.estado,
            u.nombre + ' ' + ISNULL(u.apellido,'') AS usuario_carga,
            (SELECT COUNT(*)             FROM dbo.dota_asistencia_carga WHERE registro = l.registro) AS total_reg,
            (SELECT COUNT(DISTINCT area) FROM dbo.dota_asistencia_carga WHERE registro = l.registro) AS total_areas
     FROM dbo.dota_asistencia_lote l
     LEFT JOIN dbo.dota_usuarios u ON u.id_usuario = l.id_usuario_carga
     WHERE l.estado IN ('borrador','pendiente','rechazado_area','rechazado_ops')
       AND ISNULL(l.activo, 1) = 1
     ORDER BY
         CASE l.estado
             WHEN 'rechazado_ops'  THEN 1
             WHEN 'rechazado_area' THEN 2
             WHEN 'pendiente'      THEN 3
             ELSE 4
         END,
         l.fecha_carga DESC"
);
if ($stmtL) {
    while ($r = sqlsrv_fetch_array($stmtL, SQLSRV_FETCH_ASSOC)) {
        $reg = $r['registro'];

        // Historial de rechazos del lote
        $stmtH = sqlsrv_query($conn,
            "SELECT ap.accion, ap.observacion, ap.fecha,
                    ISNULL(u.nombre + ' ' + ISNULL(u.apellido,''), 'Desconocido') AS usuario_nombre,
                    ar.Area    AS area_nombre,
                    t.nombre_turno
             FROM dbo.dota_asistencia_aprobacion ap
             LEFT JOIN dbo.dota_usuarios u ON u.id_usuario = ap.id_usuario
             LEFT JOIN dbo.Area           ar ON ar.id_area  = ap.id_area
             LEFT JOIN dbo.dota_turno     t  ON t.id        = ap.id_turno
             WHERE ap.registro = ?
               AND ap.accion IN ('rechazado_area','rechazado_ops')
             ORDER BY ap.fecha DESC",
            [$reg]
        );
        $rechazos = [];
        while ($stmtH && ($h = sqlsrv_fetch_array($stmtH, SQLSRV_FETCH_ASSOC))) {
            if (isset($h['fecha']) && $h['fecha'] instanceof DateTime)
                $h['fecha'] = $h['fecha']->format('d/m/Y H:i');
            $rechazos[] = $h;
        }

        // Resumen por área+turno
        $stmtAT = sqlsrv_query($conn,
            "SELECT DISTINCT ac.area, ac.turno,
                    ar.Area                            AS area_nombre,
                    ISNULL(t.nombre_turno,'Sin turno') AS turno_nombre,
                    (SELECT COUNT(*) FROM dbo.dota_asistencia_carga x
                     WHERE x.registro = ac.registro AND x.area = ac.area AND x.turno = ac.turno
                    ) AS registros,
                    last_ap.accion        AS ultimo_accion,
                    last_ap.observacion   AS ultimo_obs,
                    last_ap.fecha         AS ultimo_fecha,
                    ISNULL(uap.nombre + ' ' + ISNULL(uap.apellido,''),'') AS ultimo_usuario
             FROM dbo.dota_asistencia_carga ac
             LEFT JOIN dbo.Area ar      ON ar.id_area = ac.area
             LEFT JOIN dbo.dota_turno t ON t.id       = ac.turno
             LEFT JOIN (
                 SELECT id_area, id_turno, accion, observacion, fecha, id_usuario,
                        ROW_NUMBER() OVER (PARTITION BY id_area, ISNULL(id_turno,-1) ORDER BY fecha DESC) AS rn
                 FROM dbo.dota_asistencia_aprobacion
                 WHERE registro = ?
             ) last_ap ON last_ap.id_area = ac.area
                       AND ISNULL(last_ap.id_turno,-1) = ISNULL(ac.turno,-1)
                       AND last_ap.rn = 1
             LEFT JOIN dbo.dota_usuarios uap ON uap.id_usuario = last_ap.id_usuario
             WHERE ac.registro = ?
             ORDER BY turno_nombre, area_nombre",
            [$reg, $reg]
        );
        $areas_turno = [];
        while ($stmtAT && ($a = sqlsrv_fetch_array($stmtAT, SQLSRV_FETCH_ASSOC))) {
            if (isset($a['ultimo_fecha']) && $a['ultimo_fecha'] instanceof DateTime)
                $a['ultimo_fecha'] = $a['ultimo_fecha']->format('d/m/Y H:i');
            $areas_turno[] = $a;
        }

        $r['rechazos']   = $rechazos;
        $r['areas_turno'] = $areas_turno;
        $r['fecha_carga'] = $r['fecha_carga'] instanceof DateTime
            ? $r['fecha_carga']->format('d/m/Y H:i')
            : (string)$r['fecha_carga'];
        $lotes[] = $r;
    }
}

$title = "Bandeja RRHH";
include __DIR__ . '/../partials/header.php';
include __DIR__ . '/../partials/navbar_wrapper.php';
?>

<main class="container py-4">
    <h1 class="display-5 text-center mb-1">Bandeja RRHH</h1>
    <p class="text-center text-muted mb-4">Lotes pendientes de revisión o con rechazo de jefe/operaciones</p>

    <?php if (empty($lotes)): ?>
        <div class="alert alert-success text-center">
            No hay lotes pendientes ni rechazados. Todo al día.
        </div>
    <?php else: ?>

    <?php foreach ($lotes as $l):
        $estado_cfg = [
            'rechazado_ops'  => ['danger',  'Rechazado por Operaciones'],
            'rechazado_area' => ['warning', 'Rechazado por Jefe Área'],
            'pendiente'      => ['info',    'Pendiente aprobación'],
            'borrador'       => ['secondary','Borrador'],
        ];
        [$cls, $lbl_estado] = $estado_cfg[$l['estado']] ?? ['secondary', $l['estado']];
    ?>
    <div class="card mb-4 shadow-sm border-<?= $cls ?>">

        <!-- Cabecera -->
        <div class="card-header d-flex flex-wrap justify-content-between align-items-center gap-2 bg-<?= $cls ?> bg-opacity-10">
            <div>
                <span class="badge bg-<?= $cls ?>"><?= $lbl_estado ?></span>
                <strong class="ms-2">Semana <?= (int)$l['semana'] ?> / <?= (int)$l['anio'] ?></strong>
                <span class="text-muted small ms-2"><?= htmlspecialchars($l['registro']) ?></span>
            </div>
            <div class="d-flex align-items-center gap-2 flex-wrap">
                <span class="text-muted small">
                    Cargado: <?= htmlspecialchars($l['fecha_carga']) ?>
                    <?php if ($l['usuario_carga']): ?>
                        — <?= htmlspecialchars($l['usuario_carga']) ?>
                    <?php endif; ?>
                </span>
                <span class="text-muted small">
                    <?= (int)$l['total_reg'] ?> registros &nbsp;|&nbsp; <?= (int)$l['total_areas'] ?> áreas
                </span>
                <a href="../procesos/editar_asistencia.php?registro=<?= urlencode($l['registro']) ?>"
                   class="btn btn-sm btn-primary">
                    Editar asistencia
                </a>
                <a href="detalle_asistencia.php?registro=<?= urlencode($l['registro']) ?>"
                   class="btn btn-sm btn-outline-secondary">
                    Ver detalle
                </a>
            </div>
        </div>

        <div class="card-body p-3">

            <!-- Resumen por área + turno -->
            <h6 class="fw-semibold mb-2">Estado por turno / área</h6>
            <div class="table-responsive mb-3">
                <table class="table table-sm table-bordered align-middle mb-0" style="font-size:.88rem;">
                    <thead class="table-dark">
                        <tr>
                            <th>Turno</th>
                            <th>Área</th>
                            <th class="text-center">Registros</th>
                            <th class="text-center">Último estado</th>
                            <th>Motivo rechazo</th>
                            <th>Por</th>
                            <th>Fecha</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($l['areas_turno'] as $a):
                        $accion = $a['ultimo_accion'] ?? null;
                        [$row_cls, $badge] = match($accion) {
                            'aprobado_area'  => ['', '<span class="badge bg-success">Aprobado área</span>'],
                            'rechazado_area' => ['table-warning', '<span class="badge bg-warning text-dark">Rechazado área</span>'],
                            'aprobado_ops'   => ['', '<span class="badge bg-primary">Aprobado ops</span>'],
                            'rechazado_ops'  => ['table-danger', '<span class="badge bg-danger">Rechazado ops</span>'],
                            default          => ['', '<span class="badge bg-secondary">Sin revisión</span>'],
                        };
                    ?>
                    <tr class="<?= $row_cls ?>">
                        <td><?= htmlspecialchars($a['turno_nombre']) ?></td>
                        <td><?= htmlspecialchars($a['area_nombre'] ?? "Área {$a['area']}") ?></td>
                        <td class="text-center"><?= (int)$a['registros'] ?></td>
                        <td class="text-center"><?= $badge ?></td>
                        <td><?= $a['ultimo_obs'] ? htmlspecialchars($a['ultimo_obs']) : '<span class="text-muted">—</span>' ?></td>
                        <td class="small"><?= htmlspecialchars($a['ultimo_usuario']) ?></td>
                        <td class="small text-nowrap"><?= htmlspecialchars($a['ultimo_fecha'] ?? '') ?></td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <!-- Historial de rechazos -->
            <?php if (!empty($l['rechazos'])): ?>
            <div>
                <button class="btn btn-sm btn-outline-secondary mb-2"
                        data-bs-toggle="collapse"
                        data-bs-target="#hist-<?= md5($l['registro']) ?>"
                        aria-expanded="false">
                    Historial de rechazos (<?= count($l['rechazos']) ?>)
                </button>
                <div class="collapse" id="hist-<?= md5($l['registro']) ?>">
                    <table class="table table-sm table-striped align-middle small">
                        <thead class="table-secondary">
                            <tr>
                                <th>Fecha</th>
                                <th>Tipo</th>
                                <th>Área</th>
                                <th>Turno</th>
                                <th>Usuario</th>
                                <th>Observación</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($l['rechazos'] as $h): ?>
                        <tr>
                            <td class="text-nowrap"><?= htmlspecialchars($h['fecha'] ?? '') ?></td>
                            <td>
                                <?php if ($h['accion'] === 'rechazado_ops'): ?>
                                    <span class="badge bg-danger">Operaciones</span>
                                <?php else: ?>
                                    <span class="badge bg-warning text-dark">Jefe Área</span>
                                <?php endif; ?>
                            </td>
                            <td><?= htmlspecialchars($h['area_nombre'] ?? '') ?></td>
                            <td><?= htmlspecialchars($h['nombre_turno'] ?? '—') ?></td>
                            <td><?= htmlspecialchars($h['usuario_nombre']) ?></td>
                            <td><?= htmlspecialchars($h['observacion'] ?? '') ?></td>
                        </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <?php endif; ?>

        </div><!-- /card-body -->
    </div>
    <?php endforeach; ?>

    <?php endif; ?>
</main>

<?php
include __DIR__ . '/../partials/footer.php';
sqlsrv_close($conn);
?>
