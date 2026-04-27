<?php
require_once __DIR__ . '/../_bootstrap.php';
if (!puede_modulo('reloj')) { header('Location: ' . BASE_URL . '/Inicio.php'); exit; }

$id_usuario_marc = (int)($_SESSION['id_usuario'] ?? 0);

if (!function_exists('ensure_marcacion_manual_table')) {
    function ensure_marcacion_manual_table($conn): void {
        sqlsrv_query($conn, "
            IF OBJECT_ID('dbo.reloj_marcacion_manual','U') IS NULL
            BEGIN
                CREATE TABLE dbo.reloj_marcacion_manual (
                    id           INT IDENTITY(1,1) NOT NULL PRIMARY KEY,
                    rut          NVARCHAR(20)  NOT NULL,
                    fecha        DATE          NOT NULL,
                    hora_entrada TIME          NULL,
                    hora_salida  TIME          NULL,
                    id_usuario   INT           NOT NULL,
                    fecha_reg    DATETIME      NOT NULL CONSTRAINT DF_rmm_fecha_reg DEFAULT GETDATE(),
                    CONSTRAINT UQ_reloj_marcacion_manual UNIQUE (rut, fecha)
                )
            END
        ");
    }
}

if (!function_exists('ensure_reloj_turno_column')) {
    function ensure_reloj_turno_column($conn): void {
        $sql = "
IF COL_LENGTH('dbo.reloj_trabajador', 'id_turno') IS NULL
BEGIN
    ALTER TABLE dbo.reloj_trabajador ADD id_turno INT NULL;
END
";
        sqlsrv_query($conn, $sql);
    }
}

if (!function_exists('ensure_turno_detalle_table')) {
    function ensure_turno_detalle_table($conn): void {
        $sql = "
IF OBJECT_ID('dbo.dota_turno_detalle','U') IS NULL
BEGIN
    CREATE TABLE dbo.dota_turno_detalle (
        id           INT IDENTITY(1,1) NOT NULL PRIMARY KEY,
        id_turno     INT NOT NULL,
        dia_semana   TINYINT NOT NULL,
        hora_entrada TIME(0) NOT NULL,
        hora_salida  TIME(0) NOT NULL,
        activo       BIT NOT NULL CONSTRAINT DF_dota_turno_detalle_activo DEFAULT (1),
        CONSTRAINT FK_dota_turno_detalle_turno
            FOREIGN KEY (id_turno) REFERENCES dbo.dota_turno(id) ON DELETE CASCADE,
        CONSTRAINT UQ_dota_turno_detalle UNIQUE (id_turno, dia_semana),
        CONSTRAINT CK_dota_turno_detalle_dia CHECK (dia_semana BETWEEN 1 AND 7)
    );
END
";
        sqlsrv_query($conn, $sql);
    }
}

function to_datetime_or_null($value): ?DateTime {
    if ($value instanceof DateTime) {
        return clone $value;
    }
    if ($value === null || $value === '') {
        return null;
    }
    try {
        return new DateTime((string)$value);
    } catch (Throwable $e) {
        return null;
    }
}

function abs_minutes_diff(DateTime $a, DateTime $b): int {
    return (int)floor(abs($a->getTimestamp() - $b->getTimestamp()) / 60);
}

function classify_mark(DateTime $markDt, ?DateTime $entryDt, ?DateTime $exitDt): string {
    if (!$entryDt && !$exitDt) {
        return 'entrada';
    }
    if ($entryDt && !$exitDt) {
        return 'entrada';
    }
    if (!$entryDt && $exitDt) {
        return 'salida';
    }

    $diffEntry = abs_minutes_diff($markDt, $entryDt);
    $diffExit  = abs_minutes_diff($markDt, $exitDt);
    return $diffEntry <= $diffExit ? 'entrada' : 'salida';
}

ensure_reloj_turno_column($conn);
ensure_turno_detalle_table($conn);
ensure_marcacion_manual_table($conn);

// ── Guardar override manual ──────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['guardar_override'])) {
    $ov_rut    = trim($_POST['ov_rut']    ?? '');
    $ov_fecha  = trim($_POST['ov_fecha']  ?? '');
    $ov_entr   = trim($_POST['ov_entrada'] ?? '');
    $ov_sal    = trim($_POST['ov_salida']  ?? '');

    if ($ov_rut !== '' && $ov_fecha !== '') {
        $h_entr = $ov_entr !== '' ? $ov_entr . ':00' : null;
        $h_sal  = $ov_sal  !== '' ? $ov_sal  . ':00' : null;
        sqlsrv_query($conn, "
            MERGE dbo.reloj_marcacion_manual AS tgt
            USING (SELECT ? AS rut, CONVERT(date,?,120) AS fecha) AS src
               ON tgt.rut = src.rut AND tgt.fecha = src.fecha
            WHEN MATCHED THEN
                UPDATE SET hora_entrada = ?, hora_salida = ?, id_usuario = ?, fecha_reg = GETDATE()
            WHEN NOT MATCHED THEN
                INSERT (rut, fecha, hora_entrada, hora_salida, id_usuario)
                VALUES (?, CONVERT(date,?,120), ?, ?, ?);
        ", [$ov_rut, $ov_fecha, $h_entr, $h_sal, $id_usuario_marc,
            $ov_rut, $ov_fecha, $h_entr, $h_sal, $id_usuario_marc]);
    }

    $qs = http_build_query([
        'desde'       => $_POST['desde']       ?? '',
        'hasta'       => $_POST['hasta']        ?? '',
        'rut'         => $_POST['filtro_rut']   ?? '',
        'dispositivo' => $_POST['dispositivo']  ?? 0,
    ]);
    header("Location: marcaciones.php?$qs"); exit;
}

$fecha_desde = $_GET['desde'] ?? date('Y-m-d');
$fecha_hasta = $_GET['hasta'] ?? date('Y-m-d');
$filtro_rut  = trim($_GET['rut'] ?? '');
$filtro_disp = (int)($_GET['dispositivo'] ?? 0);

$params = [$fecha_desde . ' 00:00:00', $fecha_hasta . ' 23:59:59'];
$where  = "WHERE m.fecha_hora BETWEEN CONVERT(datetime,?,120) AND CONVERT(datetime,?,120) AND m.id_numero != 0";
if ($filtro_rut)  { $where .= " AND (t.rut LIKE ? OR t.nombre LIKE ?)"; $params[] = "%$filtro_rut%"; $params[] = "%$filtro_rut%"; }
if ($filtro_disp) { $where .= " AND m.id_dispositivo = ?";               $params[] = $filtro_disp; }

$sql_rows = "
    SELECT TOP 5000
        CAST(m.fecha_hora AS date) AS fecha,
        m.fecha_hora,
        m.id_numero,
        ISNULL(t.rut, CAST(m.id_numero AS NVARCHAR(20))) AS rut,
        ISNULL(t.nombre, N'(sin registro)')              AS nombre,
        t.id_turno,
        tr.nombre_turno,
        CONVERT(VARCHAR(5), td.hora_entrada, 108) AS hora_entrada,
        CONVERT(VARCHAR(5), td.hora_salida, 108)  AS hora_salida,
        d.nombre AS dispositivo
    FROM dbo.reloj_marcacion m
    LEFT JOIN dbo.reloj_trabajador   t  ON t.id_numero = m.id_numero
    LEFT JOIN dbo.dota_turno         tr ON tr.id       = t.id_turno
    LEFT JOIN dbo.dota_turno_detalle td ON td.id_turno = t.id_turno
        AND td.activo = 1
        AND td.dia_semana = ((DATEDIFF(day, '19000101', CAST(m.fecha_hora AS date)) % 7) + 1)
    JOIN dbo.reloj_dispositivo d ON d.id = m.id_dispositivo
    $where
    ORDER BY CAST(m.fecha_hora AS date) DESC, nombre ASC, m.fecha_hora ASC
";

$rows = sqlsrv_query($conn, $sql_rows, $params);
$sql_error = null;
$groupedRows = [];

if ($rows === false) {
    $sql_error = print_r(sqlsrv_errors(), true);
} else {
    while ($m = sqlsrv_fetch_array($rows, SQLSRV_FETCH_ASSOC)) {
        $fechaObj = to_datetime_or_null($m['fecha']);
        $fechaKey = $fechaObj ? $fechaObj->format('Y-m-d') : (string)$m['fecha'];
        $groupKey = $fechaKey . '|' . (string)$m['id_numero'];

        if (!isset($groupedRows[$groupKey])) {
            $groupedRows[$groupKey] = [
                'fecha'        => $fechaObj,
                'fecha_key'    => $fechaKey,
                'id_numero'    => (string)$m['id_numero'],
                'rut'          => (string)$m['rut'],
                'nombre'       => (string)$m['nombre'],
                'id_turno'     => isset($m['id_turno']) ? (int)$m['id_turno'] : 0,
                'nombre_turno' => (string)($m['nombre_turno'] ?? ''),
                'hora_entrada' => (string)($m['hora_entrada'] ?? ''),
                'hora_salida'  => (string)($m['hora_salida'] ?? ''),
                'dispositivo'  => (string)$m['dispositivo'],
                'total_marcas' => 0,
                'entrada'      => null,
                'salida'       => null,
            ];
        }

        $markDt = to_datetime_or_null($m['fecha_hora']);
        if (!$markDt) {
            continue;
        }

        $groupedRows[$groupKey]['total_marcas']++;

        $entryDt = null;
        $exitDt  = null;
        if ($groupedRows[$groupKey]['hora_entrada'] !== '') {
            $entryDt = new DateTime($groupedRows[$groupKey]['fecha_key'] . ' ' . $groupedRows[$groupKey]['hora_entrada'] . ':00');
        }
        if ($groupedRows[$groupKey]['hora_salida'] !== '') {
            $exitDt = new DateTime($groupedRows[$groupKey]['fecha_key'] . ' ' . $groupedRows[$groupKey]['hora_salida'] . ':00');
        }

        $kind = classify_mark($markDt, $entryDt, $exitDt);
        if ($kind === 'entrada' && $groupedRows[$groupKey]['entrada'] === null) {
            $groupedRows[$groupKey]['entrada'] = clone $markDt;
        }
        if ($kind === 'salida' && $groupedRows[$groupKey]['salida'] === null) {
            $groupedRows[$groupKey]['salida'] = clone $markDt;
        }
    }
}

$mergedRows = [];
foreach ($groupedRows as $row) {
    $mergeKey = $row['fecha_key'] . '|' . mb_strtoupper(trim((string)$row['rut']), 'UTF-8') . '|' . mb_strtoupper(trim((string)$row['nombre']), 'UTF-8');
    if (!isset($mergedRows[$mergeKey])) {
        $row['dispositivos'] = [$row['dispositivo'] => true];
        $mergedRows[$mergeKey] = $row;
        continue;
    }

    $current = &$mergedRows[$mergeKey];
    $current['total_marcas'] += (int)$row['total_marcas'];

    if ($current['entrada'] === null || ($row['entrada'] instanceof DateTime && $row['entrada'] < $current['entrada'])) {
        $current['entrada'] = $row['entrada'];
    }
    if ($current['salida'] === null || ($row['salida'] instanceof DateTime && $row['salida'] < $current['salida'])) {
        $current['salida'] = $row['salida'];
    }

    if ($current['nombre_turno'] === '' && $row['nombre_turno'] !== '') {
        $current['nombre_turno'] = $row['nombre_turno'];
        $current['id_turno'] = $row['id_turno'];
    }
    if ($current['hora_entrada'] === '' && $row['hora_entrada'] !== '') {
        $current['hora_entrada'] = $row['hora_entrada'];
    }
    if ($current['hora_salida'] === '' && $row['hora_salida'] !== '') {
        $current['hora_salida'] = $row['hora_salida'];
    }

    $current['dispositivos'][$row['dispositivo']] = true;
    unset($current);
}

foreach ($mergedRows as &$row) {
    $row['dispositivo'] = implode(', ', array_keys($row['dispositivos']));
    unset($row['dispositivos']);
}
unset($row);

// ── Aplicar overrides manuales ───────────────────────────────────────────────
$overrides = [];
$stmt_ov = sqlsrv_query($conn, "
    SELECT rut,
           CONVERT(varchar(10), fecha, 120)          AS fecha,
           CONVERT(varchar(5),  hora_entrada, 108)   AS hora_entrada,
           CONVERT(varchar(5),  hora_salida,  108)   AS hora_salida
    FROM dbo.reloj_marcacion_manual
    WHERE fecha BETWEEN CONVERT(date,?,120) AND CONVERT(date,?,120)
", [$fecha_desde, $fecha_hasta]);
if ($stmt_ov) {
    while ($ov = sqlsrv_fetch_array($stmt_ov, SQLSRV_FETCH_ASSOC)) {
        $overrides[$ov['fecha'] . '|' . $ov['rut']] = $ov;
    }
}

foreach ($mergedRows as &$row) {
    $ovKey = $row['fecha_key'] . '|' . $row['rut'];
    if (isset($overrides[$ovKey])) {
        $ov = $overrides[$ovKey];
        $row['entrada']       = $ov['hora_entrada'] ? new DateTime($row['fecha_key'] . ' ' . $ov['hora_entrada']) : null;
        $row['salida']        = $ov['hora_salida']  ? new DateTime($row['fecha_key'] . ' ' . $ov['hora_salida'])  : null;
        $row['tiene_override'] = true;
    } else {
        $row['tiene_override'] = false;
    }
}
unset($row);

$title = "Reloj — Marcaciones";
include __DIR__ . '/../partials/header.php';
include __DIR__ . '/../partials/navbar_wrapper.php';
?>
<main class="container-fluid py-4">
  <div class="d-flex justify-content-between align-items-center mb-3">
    <div>
      <h4 class="mb-0">Marcaciones Validadas por Turno</h4>
      <small class="text-muted">Se toma siempre la primera marca que corresponda a entrada y la primera que corresponda a salida.</small>
    </div>
    <button class="btn btn-success btn-sm" onclick="abrirSync()">&#8635; Sincronizar relojes</button>
  </div>

  <?php if ($rows === false): ?>
    <div class="alert alert-danger">
      <strong>Error SQL:</strong><pre><?= htmlspecialchars($sql_error) ?></pre>
    </div>
  <?php else: ?>

  <form method="get" class="row g-2 mb-3 align-items-end">
    <div class="col-auto">
      <label class="form-label mb-1">Desde</label>
      <input type="date" name="desde" class="form-control form-control-sm"
             value="<?= htmlspecialchars($fecha_desde) ?>">
    </div>
    <div class="col-auto">
      <label class="form-label mb-1">Hasta</label>
      <input type="date" name="hasta" class="form-control form-control-sm"
             value="<?= htmlspecialchars($fecha_hasta) ?>">
    </div>
    <div class="col-auto">
      <label class="form-label mb-1">RUT / Nombre</label>
      <input type="text" name="rut" class="form-control form-control-sm"
             placeholder="Filtrar..." value="<?= htmlspecialchars($filtro_rut) ?>">
    </div>
    <div class="col-auto">
      <label class="form-label mb-1">Reloj</label>
      <select name="dispositivo" class="form-select form-select-sm">
        <option value="0">Todos</option>
        <?php
        $q_devs = sqlsrv_query($conn,
            "SELECT id, nombre FROM dbo.reloj_dispositivo WHERE activo=1 ORDER BY nombre");
        while ($dv = sqlsrv_fetch_array($q_devs, SQLSRV_FETCH_ASSOC)):
        ?>
          <option value="<?= $dv['id'] ?>" <?= $filtro_disp == $dv['id'] ? 'selected' : '' ?>>
            <?= htmlspecialchars($dv['nombre']) ?>
          </option>
        <?php endwhile; ?>
      </select>
    </div>
    <div class="col-auto">
      <button class="btn btn-sm btn-primary">Filtrar</button>
      <a href="marcaciones.php" class="btn btn-sm btn-outline-secondary">Limpiar</a>
    </div>
  </form>

  <div class="alert alert-info py-2">
    La pantalla ignora el `tipo` del reloj. Si hay varias marcas, se muestra siempre la primera marca clasificada como entrada y la primera clasificada como salida.
  </div>

  <div class="table-responsive">
    <table class="table table-bordered table-hover table-sm align-middle">
      <thead class="table-dark">
        <tr>
          <th>Fecha</th>
          <th>RUT</th>
          <th>Nombre</th>
          <th>Turno</th>
          <th>Horario</th>
          <th>Entrada</th>
          <th>Salida</th>
          <th>Marcas</th>
          <th>Reloj</th>
          <th style="width:70px;"></th>
        </tr>
      </thead>
      <tbody>
      <?php
      $count = 0;
      foreach ($mergedRows as $row):
          $count++;
          $fechaTxt = $row['fecha'] instanceof DateTime ? $row['fecha']->format('d/m/Y') : htmlspecialchars($row['fecha_key']);
          $horario = '';
          if ($row['hora_entrada'] !== '' && $row['hora_salida'] !== '') {
              $horario = $row['hora_entrada'] . ' - ' . $row['hora_salida'];
          }
      ?>
        <tr>
          <td><?= $fechaTxt ?></td>
          <td><?= htmlspecialchars($row['rut']) ?></td>
          <td><?= htmlspecialchars($row['nombre']) ?></td>
          <td>
            <?php if ($row['nombre_turno'] !== ''): ?>
              <?= htmlspecialchars($row['nombre_turno']) ?>
            <?php else: ?>
              <span class="badge bg-secondary">Sin turno</span>
            <?php endif; ?>
          </td>
          <td>
            <?php if ($horario !== ''): ?>
              <span class="small"><?= htmlspecialchars($horario) ?></span>
            <?php else: ?>
              <span class="text-muted small">Sin horario del dia</span>
            <?php endif; ?>
          </td>
          <td>
            <?php if ($row['entrada'] instanceof DateTime): ?>
              <span class="badge <?= $row['tiene_override'] ? 'bg-warning text-dark' : 'bg-success' ?>">
                Entrada <?= $row['entrada']->format('H:i') ?>
              </span>
            <?php else: ?>
              <span class="badge bg-danger">Sin entrada</span>
            <?php endif; ?>
          </td>
          <td>
            <?php if ($row['salida'] instanceof DateTime): ?>
              <span class="badge <?= $row['tiene_override'] ? 'bg-warning text-dark' : 'bg-success' ?>">
                Salida <?= $row['salida']->format('H:i') ?>
              </span>
            <?php else: ?>
              <span class="badge bg-danger">Salida pendiente</span>
            <?php endif; ?>
          </td>
          <td><span class="badge bg-dark"><?= (int)$row['total_marcas'] ?></span></td>
          <td><?= htmlspecialchars($row['dispositivo']) ?></td>
          <td class="text-center">
            <button type="button" class="btn btn-sm btn-outline-secondary py-0 px-1 btn-editar-marca me-1"
                    data-rut="<?= htmlspecialchars($row['rut'], ENT_QUOTES) ?>"
                    data-nombre="<?= htmlspecialchars($row['nombre'], ENT_QUOTES) ?>"
                    data-fecha="<?= htmlspecialchars($row['fecha_key']) ?>"
                    data-entrada="<?= $row['entrada'] instanceof DateTime ? $row['entrada']->format('H:i') : '' ?>"
                    data-salida="<?= $row['salida']  instanceof DateTime ? $row['salida']->format('H:i')  : '' ?>"
                    title="Editar marcación">&#9998;</button>
            <button type="button" class="btn btn-sm btn-outline-danger py-0 px-1 btn-eliminar-marca"
                    data-id-numero="<?= htmlspecialchars($row['id_numero'], ENT_QUOTES) ?>"
                    data-nombre="<?= htmlspecialchars($row['nombre'], ENT_QUOTES) ?>"
                    data-fecha="<?= htmlspecialchars($row['fecha_key']) ?>"
                    title="Eliminar marcaciones">&#128465;</button>
          </td>
        </tr>
      <?php endforeach; ?>
      <?php if ($count === 0): ?>
        <tr><td colspan="10" class="text-center text-muted py-3">Sin registros para el periodo seleccionado.</td></tr>
      <?php endif; ?>
      </tbody>
    </table>
  </div>
  <small class="text-muted"><?= $count ?> fila(s) consolidadas encontradas.</small>

  <?php endif; ?>
</main>

<!-- Modal eliminar marcación -->
<div class="modal fade" id="modalEliminarMarca" tabindex="-1" data-bs-backdrop="static">
  <div class="modal-dialog modal-sm"><div class="modal-content">
    <div class="modal-header py-2 bg-danger text-white">
      <h6 class="modal-title mb-0">Eliminar marcaciones</h6>
      <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" id="btnCerrarElim"></button>
    </div>
    <div class="modal-body">
      <p class="small mb-2" id="elim_label"></p>
      <p class="small text-danger mb-0">Se eliminarán <strong>todas las marcaciones</strong> de ese trabajador en ese día, tanto de la base de datos como del reloj físico.</p>
    </div>
    <div class="modal-footer py-2" id="elim_footer_btns">
      <button type="button" class="btn btn-sm btn-secondary" data-bs-dismiss="modal">Cancelar</button>
      <button type="button" class="btn btn-sm btn-danger" id="btn_confirmar_elim">Eliminar</button>
    </div>
    <div class="modal-footer py-2 d-none" id="elim_footer_prog">
      <div class="spinner-border spinner-border-sm text-danger me-2" role="status"></div>
      <span class="small">Eliminando en BD y relojes...</span>
    </div>
    <div id="elim_resultado" class="px-3 pb-2 d-none small"></div>
  </div></div>
</div>

<!-- Modal editar marcación -->
<div class="modal fade" id="modalEditarMarca" tabindex="-1">
  <div class="modal-dialog modal-sm"><div class="modal-content">
    <form method="POST">
      <input type="hidden" name="guardar_override" value="1">
      <input type="hidden" name="ov_rut"   id="ov_rut">
      <input type="hidden" name="ov_fecha" id="ov_fecha">
      <input type="hidden" name="desde"       value="<?= htmlspecialchars($fecha_desde) ?>">
      <input type="hidden" name="hasta"        value="<?= htmlspecialchars($fecha_hasta) ?>">
      <input type="hidden" name="filtro_rut"   value="<?= htmlspecialchars($filtro_rut) ?>">
      <input type="hidden" name="dispositivo"  value="<?= $filtro_disp ?>">
      <div class="modal-header py-2">
        <h6 class="modal-title mb-0">Editar marcación</h6>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <p class="small text-muted mb-2" id="ov_label"></p>
        <div class="mb-2">
          <label class="form-label small fw-semibold mb-1">Entrada</label>
          <input type="time" name="ov_entrada" id="ov_entrada" class="form-control form-control-sm">
        </div>
        <div class="mb-0">
          <label class="form-label small fw-semibold mb-1">Salida</label>
          <input type="time" name="ov_salida" id="ov_salida" class="form-control form-control-sm">
        </div>
        <div class="form-text mt-2">Deja vacío para quitar el override de ese campo.</div>
      </div>
      <div class="modal-footer py-2">
        <button type="button" class="btn btn-sm btn-secondary" data-bs-dismiss="modal">Cancelar</button>
        <button type="submit" class="btn btn-sm btn-primary">Guardar</button>
      </div>
    </form>
  </div></div>
</div>

<div class="modal fade" id="modalSync" tabindex="-1" data-bs-backdrop="static">
  <div class="modal-dialog modal-lg"><div class="modal-content">
    <div class="modal-header"><h5 class="modal-title">Sincronizando relojes...</h5></div>
    <div class="modal-body">
      <div class="progress mb-3" style="height:22px;">
        <div id="syncBar" class="progress-bar progress-bar-striped progress-bar-animated bg-success" style="width:5%">5%</div>
      </div>
      <pre id="syncLog" class="bg-dark text-light p-3 rounded"
           style="max-height:300px;overflow-y:auto;font-size:.8rem;"></pre>
    </div>
    <div class="modal-footer">
      <button class="btn btn-secondary d-none" id="btnCerrarSync"
              data-bs-dismiss="modal" onclick="location.reload()">Cerrar</button>
    </div>
  </div></div>
</div>

<?php include __DIR__ . '/../partials/footer.php'; ?>
<script>
// ── Eliminar marcación ────────────────────────────────────────────────────
let _elimIdNumero = null, _elimFecha = null;

document.querySelectorAll('.btn-eliminar-marca').forEach(btn => {
    btn.addEventListener('click', () => {
        _elimIdNumero = btn.dataset.idNumero;
        _elimFecha    = btn.dataset.fecha;
        document.getElementById('elim_label').textContent =
            btn.dataset.nombre + ' — ' + btn.dataset.fecha;
        document.getElementById('elim_resultado').classList.add('d-none');
        document.getElementById('elim_footer_btns').classList.remove('d-none');
        document.getElementById('elim_footer_prog').classList.add('d-none');
        document.getElementById('btnCerrarElim').disabled = false;
        new bootstrap.Modal(document.getElementById('modalEliminarMarca')).show();
    });
});

document.getElementById('btn_confirmar_elim').addEventListener('click', () => {
    if (!_elimIdNumero || !_elimFecha) return;

    document.getElementById('elim_footer_btns').classList.add('d-none');
    document.getElementById('elim_footer_prog').classList.remove('d-none');
    document.getElementById('btnCerrarElim').disabled = true;

    const body = new FormData();
    body.append('id_numero', _elimIdNumero);
    body.append('fecha',     _elimFecha);

    fetch('eliminar_marcacion_ajax.php', { method: 'POST', body })
        .then(r => r.json())
        .then(res => {
            document.getElementById('elim_footer_prog').classList.add('d-none');
            document.getElementById('btnCerrarElim').disabled = false;

            const div = document.getElementById('elim_resultado');
            div.classList.remove('d-none');

            if (res.ok) {
                const rel = (res.relojes || []).map(r => '<li>' + r + '</li>').join('');
                div.innerHTML =
                    '<div class="text-success fw-semibold mb-1">✓ BD: ' + res.db + ' registro(s) eliminado(s)</div>' +
                    (rel ? '<ul class="mb-0 ps-3">' + rel + '</ul>' : '');
                setTimeout(() => location.reload(), 2500);
            } else {
                div.innerHTML = '<div class="text-danger">Error: ' + (res.error || 'desconocido') + '</div>';
                document.getElementById('elim_footer_btns').classList.remove('d-none');
            }
        })
        .catch(() => {
            document.getElementById('elim_footer_prog').classList.add('d-none');
            document.getElementById('elim_footer_btns').classList.remove('d-none');
            document.getElementById('btnCerrarElim').disabled = false;
            alert('Error de red al eliminar.');
        });
});

// ── Editar marcación ──────────────────────────────────────────────────────
document.querySelectorAll('.btn-editar-marca').forEach(btn => {
    btn.addEventListener('click', () => {
        document.getElementById('ov_rut').value      = btn.dataset.rut;
        document.getElementById('ov_fecha').value    = btn.dataset.fecha;
        document.getElementById('ov_entrada').value  = btn.dataset.entrada;
        document.getElementById('ov_salida').value   = btn.dataset.salida;
        document.getElementById('ov_label').textContent =
            btn.dataset.nombre + ' — ' + btn.dataset.fecha;
        new bootstrap.Modal(document.getElementById('modalEditarMarca')).show();
    });
});

function abrirSync() {
    document.getElementById('syncLog').textContent = '';
    const bar = document.getElementById('syncBar');
    bar.style.width = '5%'; bar.textContent = '5%';
    bar.classList.add('progress-bar-animated');
    document.getElementById('btnCerrarSync').classList.add('d-none');

    const modal = new bootstrap.Modal(document.getElementById('modalSync'));
    modal.show();

    let pct = 5;
    const source = new EventSource('sync_stream.php?accion=sync');

    source.onmessage = function(e) {
        const msg = JSON.parse(e.data);
        if (msg === '__DONE__') {
            source.close();
            bar.style.width = '100%'; bar.textContent = '100%';
            bar.classList.remove('progress-bar-animated');
            document.getElementById('btnCerrarSync').classList.remove('d-none');
        } else {
            document.getElementById('syncLog').textContent += msg + '\n';
            document.getElementById('syncLog').scrollTop = 9999;
            pct = Math.min(pct + 30, 90);
            bar.style.width = pct + '%'; bar.textContent = pct + '%';
        }
    };

    source.onerror = function() {
        source.close();
        document.getElementById('syncLog').textContent += '\nError de conexion.';
        document.getElementById('btnCerrarSync').classList.remove('d-none');
    };
}
</script>
