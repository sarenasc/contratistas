<?php
require_once __DIR__ . '/../_bootstrap.php';
require_once __DIR__ . '/../../app/lib/db.php';

if (!puede_modulo('procesos') && !es_admin()) {
    header('Location: ' . BASE_URL . '/Inicio.php'); exit;
}

$flash_error = null;
$flash_ok    = null;
$id_usuario  = (int)$_SESSION['id_usuario'];

// ── IMPORTAR DESDE RELOJES ─────────────────────────────────────────────────
function _reloj_classify(DateTime $mark, ?DateTime $entryRef, ?DateTime $exitRef): string {
    if (!$entryRef || !$exitRef) return 'entrada';
    $dE = abs($mark->getTimestamp() - $entryRef->getTimestamp());
    $dS = abs($mark->getTimestamp() - $exitRef->getTimestamp());
    return $dE <= $dS ? 'entrada' : 'salida';
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

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['importar_reloj'])) {
    $fecha_desde = trim($_POST['fecha_desde'] ?? '');
    $fecha_hasta = trim($_POST['fecha_hasta'] ?? '');
    $semana_imp  = (int)($_POST['semana'] ?? 0);
    $anio_imp    = (int)($_POST['anio']   ?? date('Y'));

    if ($fecha_desde === '' || $fecha_hasta === '' || $semana_imp <= 0) {
        $flash_error = "Completa todos los campos para importar.";
    } else {
        $stmt_imp = db_query($conn, "
            SELECT
                m.fecha_hora,
                m.id_numero,
                ISNULL(t.rut, CAST(m.id_numero AS NVARCHAR(20))) AS rut,
                t.nombre,
                t.id_cargo,
                t.id_area,
                t.id_turno,
                t.id_contratista,
                CONVERT(VARCHAR(5), td.hora_entrada, 108) AS hora_entrada,
                CONVERT(VARCHAR(5), td.hora_salida,  108) AS hora_salida
            FROM dbo.reloj_marcacion m
            JOIN dbo.reloj_trabajador t
                ON t.id_numero = m.id_numero AND t.activo = 1
            LEFT JOIN dbo.dota_turno_detalle td
                ON td.id_turno  = t.id_turno AND td.activo = 1
                AND td.dia_semana = ((DATEDIFF(day,'19000101',CAST(m.fecha_hora AS date)) % 7) + 1)
            WHERE m.fecha_hora BETWEEN CONVERT(datetime,?,120) AND CONVERT(datetime,?,120)
              AND m.id_numero != 0
              AND t.id_area  IS NOT NULL
              AND t.id_cargo IS NOT NULL
            ORDER BY t.nombre ASC, m.fecha_hora ASC
        ", [$fecha_desde . ' 00:00:00', $fecha_hasta . ' 23:59:59']);

        $grouped = [];
        while ($row = sqlsrv_fetch_array($stmt_imp, SQLSRV_FETCH_ASSOC)) {
            $markDt   = $row['fecha_hora'] instanceof DateTime ? clone $row['fecha_hora'] : new DateTime((string)$row['fecha_hora']);
            $fechaKey = $markDt->format('Y-m-d');
            $gkey     = $fechaKey . '|' . $row['id_numero'];

            if (!isset($grouped[$gkey])) {
                $grouped[$gkey] = [
                    'fecha'        => $fechaKey,
                    'rut'          => (string)$row['rut'],
                    'nombre'       => (string)$row['nombre'],
                    'id_cargo'        => (int)$row['id_cargo'],
                    'id_area'         => (int)$row['id_area'],
                    'id_turno'        => $row['id_turno'] !== null ? (int)$row['id_turno'] : null,
                    'id_contratista'  => $row['id_contratista'] !== null ? (int)$row['id_contratista'] : null,
                    'hora_entrada' => (string)($row['hora_entrada'] ?? ''),
                    'hora_salida'  => (string)($row['hora_salida']  ?? ''),
                    'entrada'      => null,
                    'salida'       => null,
                ];
            }

            $g      = &$grouped[$gkey];
            $eRef   = $g['hora_entrada'] !== '' ? new DateTime($g['fecha'] . ' ' . $g['hora_entrada'] . ':00') : null;
            $sRef   = $g['hora_salida']  !== '' ? new DateTime($g['fecha'] . ' ' . $g['hora_salida']  . ':00') : null;
            $kind   = _reloj_classify($markDt, $eRef, $sRef);

            if ($kind === 'entrada' && $g['entrada'] === null) $g['entrada'] = clone $markDt;
            if ($kind === 'salida'  && $g['salida']  === null) $g['salida']  = clone $markDt;
            unset($g);
        }

        foreach ($grouped as &$g) {
            $eRef = $g['hora_entrada'] !== '' ? new DateTime($g['fecha'] . ' ' . $g['hora_entrada'] . ':00') : null;
            $sRef = $g['hora_salida']  !== '' ? new DateTime($g['fecha'] . ' ' . $g['hora_salida']  . ':00') : null;

            // Sin salida real → usar hora de salida del turno como estimada
            if ($g['salida'] === null && $sRef !== null) {
                $g['salida'] = clone $sRef;
            }

            if ($g['entrada'] instanceof DateTime && $g['salida'] instanceof DateTime && $eRef && $sRef) {
                $worked   = ($g['salida']->getTimestamp() - $g['entrada']->getTimestamp()) / 3600;
                $expected = ($sRef->getTimestamp() - $eRef->getTimestamp()) / 3600;
                if ($expected > 0 && $worked > 0) {
                    $g['jornada'] = min(1.0, round($worked / $expected, 4));
                    $g['hhee']    = round(max(0.0, $worked - $expected), 4);
                } else {
                    $g['jornada'] = 0.0; $g['hhee'] = 0.0;
                }
            } else {
                $g['jornada'] = 0.0; $g['hhee'] = 0.0;
            }
        }
        unset($g);

        if (empty($grouped)) {
            $flash_error = "No se encontraron marcaciones en el período con trabajadores que tengan área y cargo configurados en los relojes.";
        } else {
            $registro_nuevo = 'reloj_SEM' . $semana_imp . '_' . $anio_imp . '_' . date('YmdHis');
            $n_ins = 0;
            foreach ($grouped as $g) {
                db_query($conn,
                    "INSERT INTO dbo.dota_asistencia_carga
                        (registro, fecha, semana, empleador, rut, nombre, cargo, area, jornada, hhee, turno, hora_entrada, hora_salida)
                     VALUES (?, CONVERT(date,?,120), ?, ?, ?, ?, ?, ?, ?, ?, ?, CONVERT(time,?,108), CONVERT(time,?,108))",
                    [
                        $registro_nuevo, $g['fecha'], $semana_imp, $g['id_contratista'], $g['rut'], $g['nombre'],
                        $g['id_cargo'], $g['id_area'], $g['jornada'], $g['hhee'],
                        $g['id_turno'],
                        $g['entrada'] instanceof DateTime ? $g['entrada']->format('H:i:s') : null,
                        $g['salida'] instanceof DateTime ? $g['salida']->format('H:i:s') : null,
                    ]
                );
                $n_ins++;
            }
            db_query($conn,
                "INSERT INTO dbo.dota_asistencia_lote
                    (registro, id_usuario_carga, estado, semana, anio, activo)
                 VALUES (?, ?, 'borrador', ?, ?, 1)",
                [$registro_nuevo, $id_usuario, $semana_imp, $anio_imp]
            );
            header("Location: editar_asistencia.php?registro=" . urlencode($registro_nuevo)
                 . "&importado=1&n=" . $n_ins);
            exit;
        }
    }
}

// Catálogos
$cargos_cat = [];
$stmtC = sqlsrv_query($conn, "SELECT id_cargo, cargo FROM dbo.Dota_Cargo ORDER BY cargo");
if ($stmtC) while ($r = sqlsrv_fetch_array($stmtC, SQLSRV_FETCH_ASSOC))
    $cargos_cat[(int)$r['id_cargo']] = $r['cargo'];

// ── ELIMINAR LOTE (baja lógica) ────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['eliminar_lote'])) {
    $reg = trim($_POST['registro'] ?? '');
    if ($reg !== '') {
        // Si ya existe en dota_asistencia_lote → actualizar activo = 0
        $stmtEx = sqlsrv_query($conn,
            "SELECT estado FROM dbo.dota_asistencia_lote WHERE registro = ?", [$reg]);
        $rowEx = $stmtEx ? sqlsrv_fetch_array($stmtEx, SQLSRV_FETCH_ASSOC) : null;

        // Verificar si columna activo existe
        $chkCol = sqlsrv_query($conn,
            "SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA='dbo' AND TABLE_NAME='dota_asistencia_lote' AND COLUMN_NAME='activo'");
        if (!$chkCol || !sqlsrv_fetch($chkCol)) {
            $flash_error = "Primero ejecute en SQL Server: ALTER TABLE dbo.dota_asistencia_lote ADD activo BIT NOT NULL DEFAULT 1";
        } elseif ($rowEx) {
            if ($rowEx['estado'] === 'listo_factura') {
                $flash_error = "No se puede eliminar un lote que ya esta listo para facturar.";
            } else {
                sqlsrv_query($conn,
                    "UPDATE dbo.dota_asistencia_lote SET activo = 0 WHERE registro = ?", [$reg]);
                header("Location: editar_asistencia.php?elim=1"); exit;
            }
        } else {
            // Lote sin entrada en dota_asistencia_lote → insertar con activo=0
            sqlsrv_query($conn,
                "INSERT INTO dbo.dota_asistencia_lote (registro, id_usuario_carga, estado, activo)
                 VALUES (?, ?, 'eliminado', 0)",
                [$reg, $id_usuario]);
            header("Location: editar_asistencia.php?elim=1"); exit;
        }
    }
}

if (isset($_GET['elim']))      $flash_ok = "Lote eliminado de la vista correctamente.";
if (isset($_GET['importado'])) $flash_ok = "Se importaron " . (int)($_GET['n'] ?? 0) . " registros desde los relojes. Revisa y corrige jornada/HH.EE antes de enviar.";

// ── ENVIAR PARA APROBACIÓN ─────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['enviar_aprobacion'])) {
    $reg = trim($_POST['registro'] ?? '');
    if ($reg !== '') {
        $stmtEx2 = sqlsrv_query($conn,
            "SELECT estado FROM dbo.dota_asistencia_lote WHERE registro = ?", [$reg]);
        $rowEx2 = $stmtEx2 ? sqlsrv_fetch_array($stmtEx2, SQLSRV_FETCH_ASSOC) : null;

        if ($rowEx2) {
            $upd = sqlsrv_query($conn,
                "UPDATE dbo.dota_asistencia_lote SET estado = 'pendiente' WHERE registro = ?",
                [$reg]);
            if ($upd === false) {
                $errs = sqlsrv_errors();
                $flash_error = "Error al actualizar: " . ($errs[0]['message'] ?? 'desconocido');
            }
        } else {
            $ins = sqlsrv_query($conn,
                "INSERT INTO dbo.dota_asistencia_lote (registro, id_usuario_carga, estado)
                 VALUES (?, ?, 'pendiente')",
                [$reg, $id_usuario]);
            if ($ins === false) {
                $errs = sqlsrv_errors();
                $flash_error = "Error al insertar: " . ($errs[0]['message'] ?? 'desconocido');
            }
        }

        if (!$flash_error) {
            header("Location: editar_asistencia.php?registro=" . urlencode($reg) . "&enviado=1");
            exit;
        }
        // Si hubo error, caer al render normal con $flash_error
        $registro = $reg;
    }
}
if (isset($_GET['enviado'])) $flash_ok = "Lote enviado para aprobacion de jefes de area.";

// ── GUARDAR EDICIÓN ────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['guardar'])) {
    $registro = trim($_POST['registro'] ?? '');
    $ids      = (array)($_POST['id']      ?? []);
    $jornadas = (array)($_POST['jornada'] ?? []);
    $hhees    = (array)($_POST['hhee']    ?? []);
    $cargos   = (array)($_POST['cargo']   ?? []);
    $obs_rows = (array)($_POST['obs']     ?? []);
    $entradas = (array)($_POST['hora_entrada'] ?? []);
    $salidas  = (array)($_POST['hora_salida']  ?? []);

    if ($registro === '' || empty($ids)) {
        $flash_error = "No se recibieron datos para guardar.";
    } else {
        // Verificar que el lote sigue siendo editable
        $stmtChk = sqlsrv_query($conn,
            "SELECT estado FROM dbo.dota_asistencia_lote WHERE registro = ?", [$registro]);
        $chkRow = $stmtChk ? sqlsrv_fetch_array($stmtChk, SQLSRV_FETCH_ASSOC) : null;
        $estadoChk = $chkRow['estado'] ?? '';

        if (!in_array($estadoChk, ['borrador', 'rechazado_area', 'rechazado_ops', 'sin_registro'], true)) {
            $flash_error = "Este lote no puede editarse en su estado actual ($estadoChk).";
        } else {
            $errores = 0;
            foreach ($ids as $i => $id) {
                $id      = (int)$id;
                $jornada = (float)str_replace(',', '.', $jornadas[$i] ?? 0);
                $hhee    = (float)str_replace(',', '.', $hhees[$i]    ?? 0);
                $cargo   = (int)$cargos[$i];
                $obs     = trim($obs_rows[$i] ?? '');
                $entrada = trim($entradas[$i] ?? '');
                $salida  = trim($salidas[$i]  ?? '');
                $entrada = preg_match('/^\d{2}:\d{2}$/', $entrada) ? $entrada . ':00' : null;
                $salida  = preg_match('/^\d{2}:\d{2}$/', $salida)  ? $salida . ':00'  : null;

                $r = sqlsrv_query($conn,
                    "UPDATE dbo.dota_asistencia_carga
                     SET jornada = ?, hhee = ?, cargo = ?, obs = ?,
                         hora_entrada = CASE WHEN ? IS NULL THEN NULL ELSE CONVERT(time, ?, 108) END,
                         hora_salida  = CASE WHEN ? IS NULL THEN NULL ELSE CONVERT(time, ?, 108) END
                     WHERE id = ? AND registro = ?",
                    [$jornada, $hhee, $cargo ?: null, $obs ?: null, $entrada, $entrada, $salida, $salida, $id, $registro]
                );
                if ($r === false) $errores++;
            }

            if ($errores === 0) {
                sqlsrv_query($conn,
                    "INSERT INTO dbo.dota_asistencia_aprobacion (registro, id_usuario, accion, observacion)
                     VALUES (?, ?, 'editado', ?)",
                    [$registro, $id_usuario, 'Edicion de registros por RRHH']
                );

                sqlsrv_query($conn,
                    "UPDATE dbo.dota_asistencia_lote
                     SET estado = 'pendiente'
                     WHERE registro = ? AND estado IN ('rechazado_area','rechazado_ops')",
                    [$registro]
                );

                $flash_ok = "Registros actualizados. El lote volvio a estado pendiente.";
            } else {
                $flash_error = "Se produjeron $errores error(es) al guardar.";
            }
        }
    }

    // Redirigir para evitar doble POST
    if (!$flash_error) {
        header("Location: editar_asistencia.php?registro=" . urlencode($registro) . "&ok=1");
        exit;
    }
}

if (isset($_GET['ok'])) $flash_ok = "Registros actualizados. El lote volvio a estado pendiente.";

// ── SELECCIONAR LOTE ────────────────────────────────────────────────────────
$registro = trim($_GET['registro'] ?? '');

// Verificar si la columna activo existe (puede no existir si no se ejecutó el ALTER aún)
$chkActivo = sqlsrv_query($conn,
    "SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
     WHERE TABLE_SCHEMA='dbo' AND TABLE_NAME='dota_asistencia_lote' AND COLUMN_NAME='activo'");
$tiene_activo = ($chkActivo && sqlsrv_fetch($chkActivo));
$filtro_activo = $tiene_activo ? "AND ISNULL(l.activo,1) = 1" : "";

// Lista de lotes: JOIN entre dota_asistencia_lote y registros huerfanos de dota_asistencia_carga
// Asi funciona aunque dota_asistencia_lote este vacia (lotes subidos antes del nuevo flujo)
$lotes_lista = [];
$filtro_usuario = (es_admin() || es_jefe_operaciones()) ? '' : " AND (l.id_usuario_carga = $id_usuario OR l.id_usuario_carga IS NULL)";

$sqlLotes = "
    -- Lotes con registro en dota_asistencia_lote (solo activos)
    SELECT l.registro,
           ISNULL(l.semana, 0)          AS semana,
           ISNULL(l.anio,  YEAR(GETDATE())) AS anio,
           l.estado,
           l.fecha_carga,
           u.nombre + ' ' + ISNULL(u.apellido,'') AS usuario_carga
    FROM dbo.dota_asistencia_lote l
    LEFT JOIN dbo.dota_usuarios u ON u.id_usuario = l.id_usuario_carga
    WHERE 1=1 $filtro_activo
    $filtro_usuario

    UNION

    -- Registros en asistencia_carga sin entrada en dota_asistencia_lote
    SELECT DISTINCT ac.registro,
           0 AS semana,
           YEAR(GETDATE()) AS anio,
           'sin_registro' AS estado,
           NULL            AS fecha_carga,
           NULL            AS usuario_carga
    FROM dbo.dota_asistencia_carga ac
    WHERE NOT EXISTS (
        SELECT 1 FROM dbo.dota_asistencia_lote l2 WHERE l2.registro = ac.registro
    )

    ORDER BY fecha_carga DESC
";
$stmtLL = sqlsrv_query($conn, $sqlLotes);
if ($stmtLL) while ($r = sqlsrv_fetch_array($stmtLL, SQLSRV_FETCH_ASSOC)) {
    if ($r['fecha_carga'] instanceof DateTime) $r['fecha_carga'] = $r['fecha_carga']->format('d/m/Y H:i');
    elseif ($r['fecha_carga'] === null)        $r['fecha_carga'] = '(sin fecha)';
    $lotes_lista[] = $r;
}

// Registros del lote seleccionado
$registros  = [];
$lote_info  = null;
if ($registro !== '') {
    $stmtLI = sqlsrv_query($conn,
        "SELECT l.registro, l.semana, l.anio, l.estado FROM dbo.dota_asistencia_lote WHERE registro = ?",
        [$registro]
    );
    if ($stmtLI) $lote_info = sqlsrv_fetch_array($stmtLI, SQLSRV_FETCH_ASSOC);

    // Fallback: si no hay entrada en dota_asistencia_lote pero si hay registros en asistencia_carga
    if (!$lote_info) {
        $chkAC = sqlsrv_query($conn,
            "SELECT TOP 1 semana FROM dbo.dota_asistencia_carga WHERE registro = ?", [$registro]);
        if ($chkAC && sqlsrv_fetch($chkAC)) {
            $lote_info = ['registro' => $registro, 'semana' => 0, 'anio' => 0, 'estado' => 'sin_registro'];
        }
    }

    $stmtR = sqlsrv_query($conn,
        "SELECT ac.id, ac.fecha, ac.rut, ac.nombre, ac.cargo, ac.area,
                ac.jornada, ac.hhee, ac.especie, ac.obs,
                CONVERT(VARCHAR(5), ac.hora_entrada, 108) AS hora_entrada,
                CONVERT(VARCHAR(5), ac.hora_salida, 108) AS hora_salida,
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

    // Agrupar para vista lectura: turno → área → fecha → labor
    $reg_grouped = [];
    foreach ($registros as $r) {
        $t = $r['turno']        ?? 'Sin turno';
        $a = $r['area_nombre']  ?? 'Sin área';
        $f = $r['fecha']        ?? '';
        $l = $r['cargo_nombre'] ?? 'Sin labor';
        $reg_grouped[$t][$a][$f][$l][] = $r;
    }
}

// Estados
$estado_actual = $lote_info['estado'] ?? '';
$es_editable   = in_array($estado_actual, ['borrador', 'rechazado_area', 'rechazado_ops', 'sin_registro'], true);

$badges = [
    'borrador'       => ['cls' => 'secondary',          'label' => 'Borrador (en revision RRHH)'],
    'pendiente'      => ['cls' => 'warning text-dark',  'label' => 'Pendiente aprobacion jefes'],
    'aprobado_area'  => ['cls' => 'info text-dark',     'label' => 'Aprobado por areas'],
    'rechazado_area' => ['cls' => 'danger',              'label' => 'Rechazado por area'],
    'rechazado_ops'  => ['cls' => 'danger',              'label' => 'Rechazado por operaciones'],
    'listo_factura'  => ['cls' => 'success',             'label' => 'Listo para facturar'],
    'sin_registro'   => ['cls' => 'secondary',           'label' => 'Cargado (flujo anterior)'],
];

$motivo_readonly = match($estado_actual) {
    'pendiente'     => 'El lote fue enviado y está en revisión por los jefes de área. Solo podrá editarse si un jefe lo rechaza.',
    'aprobado_area' => 'El lote fue aprobado por las áreas y está en revisión por Operaciones. Solo podrá editarse si Operaciones lo rechaza.',
    'listo_factura' => 'El lote está listo para facturar. No puede editarse.',
    default         => '',
};

$title = "Revisar / Editar Asistencia";
include __DIR__ . '/../partials/header.php';
include __DIR__ . '/../partials/navbar_wrapper.php';
?>

<main class="container-fluid py-4" style="max-width:1400px;">
    <h1 class="display-6 text-center mb-4">Revisar / Editar Asistencia</h1>

    <?php if ($flash_error): ?>
        <div class="alert alert-danger"><?= htmlspecialchars($flash_error) ?></div>
    <?php endif; ?>
    <?php if ($flash_ok): ?>
        <div class="alert alert-success"><?= htmlspecialchars($flash_ok) ?></div>
    <?php endif; ?>

    <!-- Selector de lote -->
    <div class="card mb-4 shadow-sm">
        <div class="card-header fw-bold d-flex justify-content-between align-items-center">
            <span>Seleccionar lote</span>
            <button type="button" class="btn btn-sm btn-outline-primary"
                    data-bs-toggle="modal" data-bs-target="#modalImportarReloj">
                &#8635; Importar desde relojes
            </button>
        </div>
        <div class="card-body">
            <form method="GET">
                <div class="row g-2 align-items-end">
                    <div class="col-md-10">
                        <?php
                        $estados_editables = ['borrador','sin_registro','rechazado_area','rechazado_ops'];
                        $lotes_edit = array_filter($lotes_lista, fn($l) => in_array($l['estado'], $estados_editables));
                        $lotes_lock = array_filter($lotes_lista, fn($l) => !in_array($l['estado'], $estados_editables));
                        ?>
                        <select name="registro" class="form-select" required>
                            <option value="">-- Seleccionar lote --</option>
                            <?php if (!empty($lotes_edit)): ?>
                            <optgroup label="Editables">
                            <?php foreach ($lotes_edit as $l):
                                $b = $badges[$l['estado']] ?? ['label' => $l['estado']];
                            ?>
                            <option value="<?= htmlspecialchars($l['registro']) ?>"
                                <?= $l['registro'] === $registro ? 'selected' : '' ?>>
                                Sem <?= (int)$l['semana'] ?>/<?= (int)$l['anio'] ?> —
                                <?= htmlspecialchars($b['label']) ?> —
                                <?= htmlspecialchars($l['fecha_carga']) ?> —
                                <?= htmlspecialchars($l['usuario_carga'] ?? '') ?>
                            </option>
                            <?php endforeach; ?>
                            </optgroup>
                            <?php endif; ?>
                            <?php if (!empty($lotes_lock)): ?>
                            <optgroup label="Solo lectura (en proceso de aprobacion)">
                            <?php foreach ($lotes_lock as $l):
                                $b = $badges[$l['estado']] ?? ['label' => $l['estado']];
                            ?>
                            <option value="<?= htmlspecialchars($l['registro']) ?>"
                                <?= $l['registro'] === $registro ? 'selected' : '' ?>>
                                🔒 Sem <?= (int)$l['semana'] ?>/<?= (int)$l['anio'] ?> —
                                <?= htmlspecialchars($b['label']) ?> —
                                <?= htmlspecialchars($l['fecha_carga']) ?> —
                                <?= htmlspecialchars($l['usuario_carga'] ?? '') ?>
                            </option>
                            <?php endforeach; ?>
                            </optgroup>
                            <?php endif; ?>
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

    <!-- Cabecera del lote -->
    <?php
        $b   = $badges[$estado_actual] ?? ['cls' => 'secondary', 'label' => $estado_actual];
        $totalJ = array_sum(array_column($registros, 'jornada'));
        $totalH = array_sum(array_column($registros, 'hhee'));
    ?>
    <div class="card mb-3 shadow-sm border-0 bg-light">
        <div class="card-body py-2">
            <div class="row align-items-center">
                <div class="col-auto">
                    <span class="badge bg-<?= $b['cls'] ?> fs-6"><?= $b['label'] ?></span>
                </div>
                <div class="col">
                    <strong>Sem <?= (int)$lote_info['semana'] ?>/<?= (int)$lote_info['anio'] ?></strong>
                    <span class="text-muted ms-2 small"><?= htmlspecialchars($registro) ?></span>
                </div>
                <div class="col-auto text-end">
                    <span class="me-3"><strong><?= count($registros) ?></strong> <small class="text-muted">registros</small></span>
                    <span class="me-3"><strong><?= number_format($totalJ, 2) ?></strong> <small class="text-muted">jornada</small></span>
                    <span><strong><?= number_format($totalH, 2) ?></strong> <small class="text-muted">HH.EE</small></span>
                </div>
            </div>
        </div>
    </div>

    <?php if (!$es_editable): ?>
    <div class="alert alert-warning d-flex align-items-center gap-3 mb-3">
        <span class="fs-4">🔒</span>
        <div class="flex-grow-1">
            <strong>Lote bloqueado para edición.</strong><br>
            <small><?= htmlspecialchars($motivo_readonly ?: 'Este lote no puede editarse en su estado actual.') ?></small>
        </div>
        <a href="<?= BASE_URL ?>/aprobacion/detalle_asistencia.php?registro=<?= urlencode($registro) ?>"
           class="btn btn-sm btn-outline-secondary flex-shrink-0">Ver historial</a>
    </div>
    <?php endif; ?>

    <?php if (!empty($registros)): ?>

    <?php if ($es_editable): ?>

    <?php if (in_array($estado_actual, ['borrador', 'sin_registro'], true)): ?>
    <div class="alert alert-warning d-flex align-items-center justify-content-between mb-3">
        <div>
            <strong>Lote en revision.</strong> Revisa y corrige los datos. Cuando este listo,
            envialo para que los jefes de area lo aprueben.
            <br><small class="text-muted">Estado actual: <strong><?= htmlspecialchars($estado_actual) ?></strong></small>
        </div>
        <form method="POST" class="ms-3 flex-shrink-0"
              onsubmit="return confirm('¿Enviar este lote para aprobacion de los jefes de area?')">
            <input type="hidden" name="registro" value="<?= htmlspecialchars($registro) ?>">
            <button type="submit" name="enviar_aprobacion" class="btn btn-success fw-bold px-4">
                Enviar para aprobacion &rarr;
            </button>
        </form>
    </div>
    <?php endif; ?>

    <!-- MODO EDICION — jerarquía Turno → Área → Fecha/Labor → registros editables -->
    <form method="POST">
        <input type="hidden" name="registro" value="<?= htmlspecialchars($registro) ?>">

        <?php $grand_cnt = 0; $grand_j = 0.0; $grand_h = 0.0; ?>
        <table class="table table-bordered table-sm mb-0 align-middle">
            <thead>
                <tr class="table-dark">
                    <th class="ps-3">Turno / Área</th>
                    <th class="text-center" style="width:80px;">Registros</th>
                    <th class="text-end"    style="width:90px;">Jornada</th>
                    <th class="text-end"    style="width:90px;">HH.EE</th>
                    <th class="text-center" style="width:80px;">Detalle</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($reg_grouped as $turno_nom => $areas): ?>
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
                    $grand_cnt += $area_cnt; $grand_j += $area_j; $grand_h += $area_h;
                    $col_id = 'edit-' . md5($turno_nom . $area_nom . $registro);
                ?>
                <tr>
                    <td class="ps-4"><strong><?= htmlspecialchars($area_nom) ?></strong></td>
                    <td class="text-center"><?= $area_cnt ?></td>
                    <td class="text-end"><?= number_format($area_j, 2) ?></td>
                    <td class="text-end"><?= number_format($area_h, 2) ?></td>
                    <td class="text-center">
                        <button type="button" class="btn btn-outline-secondary btn-sm btn-ver py-0"
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
                                        $lf_id = 'lf-edit-' . md5($col_id . $fecha_nom . $labor_nom);
                                ?>
                                <tr>
                                    <td class="text-center text-nowrap"><?= htmlspecialchars($fecha_nom) ?></td>
                                    <td class="fw-semibold"><?= htmlspecialchars($labor_nom) ?></td>
                                    <td class="text-center"><?= count($recs) ?></td>
                                    <td class="text-end"><?= number_format($tj_lf, 2) ?></td>
                                    <td class="text-end"><?= number_format($th_lf, 2) ?></td>
                                    <td class="text-center">
                                        <button type="button" class="btn btn-sm btn-outline-secondary py-0 px-2 btn-labor-toggle"
                                                data-bs-toggle="collapse"
                                                data-bs-target="#<?= $lf_id ?>"
                                                aria-expanded="false">+</button>
                                    </td>
                                </tr>
                                <tr class="collapse" id="<?= $lf_id ?>">
                                    <td colspan="6" class="p-0 bg-light">
                                        <div class="table-responsive" style="max-height:300px;overflow-y:auto;">
                                            <table class="table table-sm table-hover mb-0 align-middle" style="font-size:.82rem;">
                                                <thead class="table-dark">
                                                    <tr>
                                                        <th>RUT</th>
                                                        <th>Nombre</th>
                                                        <th>Labor</th>
                                                        <th style="width:105px;">Entrada</th>
                                                        <th style="width:105px;">Salida</th>
                                                        <th style="width:110px;">Jornada</th>
                                                        <th style="width:110px;">HH.EE</th>
                                                        <th>Especie</th>
                                                        <th style="width:150px;">Obs</th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                <?php foreach ($recs as $rec): ?>
                                                <tr>
                                                    <input type="hidden" name="id[]" value="<?= (int)$rec['id'] ?>">
                                                    <td><?= htmlspecialchars($rec['rut']    ?? '') ?></td>
                                                    <td><?= htmlspecialchars($rec['nombre'] ?? '') ?></td>
                                                    <td>
                                                        <select name="cargo[]" class="form-select form-select-sm">
                                                            <?php foreach ($cargos_cat as $cid => $cnom): ?>
                                                            <option value="<?= $cid ?>" <?= $cid === (int)$rec['cargo'] ? 'selected' : '' ?>>
                                                                <?= htmlspecialchars($cnom) ?>
                                                            </option>
                                                            <?php endforeach; ?>
                                                        </select>
                                                    </td>
                                                    <td>
                                                        <input type="time" name="hora_entrada[]" class="form-control form-control-sm"
                                                               value="<?= htmlspecialchars((string)($rec['hora_entrada'] ?? '')) ?>">
                                                    </td>
                                                    <td>
                                                        <input type="time" name="hora_salida[]" class="form-control form-control-sm"
                                                               value="<?= htmlspecialchars((string)($rec['hora_salida'] ?? '')) ?>">
                                                    </td>
                                                    <td>
                                                        <input type="number" name="jornada[]" class="form-control form-control-sm"
                                                               value="<?= number_format((float)$rec['jornada'], 2, '.', '') ?>"
                                                               step="0.01" min="0">
                                                    </td>
                                                    <td>
                                                        <input type="number" name="hhee[]" class="form-control form-control-sm"
                                                               value="<?= number_format((float)$rec['hhee'], 2, '.', '') ?>"
                                                               step="0.01" min="0">
                                                    </td>
                                                    <td><?= htmlspecialchars($rec['especie'] ?? '') ?></td>
                                                    <td>
                                                        <input type="text" name="obs[]" class="form-control form-control-sm"
                                                               value="<?= htmlspecialchars($rec['obs'] ?? '') ?>">
                                                    </td>
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
            <tfoot class="table-light fw-bold">
                <tr>
                    <td class="ps-3">Total general</td>
                    <td class="text-center"><?= $grand_cnt ?></td>
                    <td class="text-end"><?= number_format($grand_j, 2) ?></td>
                    <td class="text-end"><?= number_format($grand_h, 2) ?></td>
                    <td></td>
                </tr>
            </tfoot>
        </table>

        <div class="mt-3 d-flex gap-2">
            <button type="submit" name="guardar" class="btn btn-primary">Guardar cambios</button>
            <a href="<?= BASE_URL ?>/aprobacion/detalle_asistencia.php?registro=<?= urlencode($registro) ?>"
               class="btn btn-outline-secondary">Ver historial</a>
        </div>
    </form>
    <form method="POST" class="mt-2"
          onsubmit="return confirm('¿Eliminar este lote de la vista?\nLos datos quedan en la base de datos.')">
        <input type="hidden" name="registro" value="<?= htmlspecialchars($registro) ?>">
        <button type="submit" name="eliminar_lote" class="btn btn-outline-danger btn-sm">
            Eliminar lote
        </button>
    </form>

    <?php else: ?>
    <!-- MODO SOLO LECTURA — jerarquía Turno → Área → Labor + Fecha -->
    <?php $grand_cnt = 0; $grand_j = 0.0; $grand_h = 0.0; ?>
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
        <?php foreach ($reg_grouped as $turno_nom => $areas): ?>
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
                $grand_cnt += $area_cnt; $grand_j += $area_j; $grand_h += $area_h;
                $col_id = 'ea-' . md5($turno_nom . $area_nom . $registro);
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
                                                <td><?= htmlspecialchars($rec['rut']         ?? '') ?></td>
                                                <td><?= htmlspecialchars($rec['nombre']      ?? '') ?></td>
                                                <td><?= htmlspecialchars($rec['sexo']        ?? '') ?></td>
                                                <td class="text-center"><?= htmlspecialchars((string)($rec['hora_entrada'] ?? '')) ?></td>
                                                <td class="text-center"><?= htmlspecialchars((string)($rec['hora_salida'] ?? '')) ?></td>
                                                <td class="text-end"><?= number_format((float)$rec['jornada'], 2) ?></td>
                                                <td class="text-end"><?= number_format((float)$rec['hhee'],    2) ?></td>
                                                <td><?= htmlspecialchars($rec['especie']     ?? '') ?></td>
                                                <td><?= htmlspecialchars($rec['obs']         ?? '') ?></td>
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
        <tfoot class="table-light fw-bold">
            <tr>
                <td class="ps-3">Total general</td>
                <td class="text-center"><?= $grand_cnt ?></td>
                <td class="text-end"><?= number_format($grand_j, 2) ?></td>
                <td class="text-end"><?= number_format($grand_h, 2) ?></td>
                <td></td>
            </tr>
        </tfoot>
    </table>
    <div class="mt-3 d-flex gap-2">
        <a href="<?= BASE_URL ?>/aprobacion/detalle_asistencia.php?registro=<?= urlencode($registro) ?>"
           class="btn btn-outline-secondary">Ver historial de aprobacion</a>
        <?php if ($estado_actual !== 'listo_factura'): ?>
        <form method="POST" onsubmit="return confirm('¿Eliminar este lote de la vista?\nLos datos quedan en la base de datos.')">
            <input type="hidden" name="registro" value="<?= htmlspecialchars($registro) ?>">
            <button type="submit" name="eliminar_lote" class="btn btn-outline-danger">
                Eliminar lote
            </button>
        </form>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <?php else: ?>
        <div class="alert alert-info">No se encontraron registros para este lote.</div>
    <?php endif; ?>

    <?php endif; ?>

</main>

<script>
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
</script>

<!-- Modal: Importar desde relojes -->
<div class="modal fade" id="modalImportarReloj" tabindex="-1">
  <div class="modal-dialog"><div class="modal-content">
    <form method="POST">
      <div class="modal-header">
        <h5 class="modal-title">Importar asistencia desde relojes</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <p class="text-muted small mb-3">
          Lee las marcaciones del período indicado y genera un lote <strong>borrador</strong>
          con entrada/salida clasificadas según el turno de cada trabajador.
          Solo incluye trabajadores con área y cargo configurados en los relojes.
        </p>
        <div class="row g-3">
          <div class="col-6">
            <label class="form-label fw-semibold">Fecha desde</label>
            <input type="date" name="fecha_desde" class="form-control" required
                   value="<?= date('Y-m-d', strtotime('monday this week')) ?>">
          </div>
          <div class="col-6">
            <label class="form-label fw-semibold">Fecha hasta</label>
            <input type="date" name="fecha_hasta" class="form-control" required
                   value="<?= date('Y-m-d', strtotime('sunday this week')) ?>">
          </div>
          <div class="col-6">
            <label class="form-label fw-semibold">Semana</label>
            <input type="number" name="semana" class="form-control" required
                   min="1" max="53" value="<?= date('W') ?>">
          </div>
          <div class="col-6">
            <label class="form-label fw-semibold">Año</label>
            <input type="number" name="anio" class="form-control" required
                   min="2020" max="2099" value="<?= date('Y') ?>">
          </div>
        </div>
        <div class="alert alert-info small mt-3 mb-0">
          <strong>Cálculo de jornada:</strong> horas reales trabajadas ÷ horas del turno.
          Si un trabajador no tiene horario para el día o le falta entrada/salida, jornada quedará en 0 para corrección manual.
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
        <button type="submit" name="importar_reloj" class="btn btn-primary">Importar</button>
      </div>
    </form>
  </div></div>
</div>

<?php include __DIR__ . '/../partials/footer.php'; ?>
