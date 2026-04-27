<?php
require_once __DIR__ . '/../_bootstrap.php';
if (!puede_modulo('reloj')) { header('Location: ' . BASE_URL . '/Inicio.php'); exit; }

$fecha = $_GET['fecha'] ?? date('Y-m-d');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha)) {
    $fecha = date('Y-m-d');
}
$fecha_dt     = $fecha . ' 00:00:00';
$fecha_dt_fin = $fecha . ' 23:59:59';

$sql_marcaciones_dia = "
    SELECT
        m.id_numero,
        m.id_dispositivo,
        m.fecha_hora,
        COUNT(*) OVER (
            PARTITION BY m.id_numero, CAST(m.fecha_hora AS date)
        ) AS marcas_dia,
        ROW_NUMBER() OVER (
            PARTITION BY m.id_numero, CAST(m.fecha_hora AS date)
            ORDER BY m.fecha_hora ASC, m.id_dispositivo ASC
        ) AS rn_entrada,
        ROW_NUMBER() OVER (
            PARTITION BY m.id_numero, CAST(m.fecha_hora AS date)
            ORDER BY m.fecha_hora DESC, m.id_dispositivo DESC
        ) AS rn_salida
    FROM dbo.reloj_marcacion m
    WHERE m.fecha_hora BETWEEN CONVERT(datetime,?,120) AND CONVERT(datetime,?,120)
      AND m.id_numero != 0
";

// ── Resumen por dispositivo ───────────────────────────────────────────────
$resumen = sqlsrv_query($conn, "
    SELECT
        d.nombre AS dispositivo,
        COUNT(DISTINCT CASE WHEN tc.tipo_calc = 'entrada' THEN tc.id_numero END) AS entradas,
        COUNT(DISTINCT CASE WHEN tc.tipo_calc = 'salida'  THEN tc.id_numero END) AS salidas
    FROM dbo.reloj_dispositivo d
    LEFT JOIN (
        SELECT
            md.id_numero,
            md.id_dispositivo,
            CASE
                WHEN md.rn_entrada = 1 THEN 'entrada'
                WHEN md.marcas_dia > 1 AND md.rn_salida = 1 THEN 'salida'
                ELSE 'intermedia'
            END AS tipo_calc
        FROM ($sql_marcaciones_dia) md
    ) tc ON tc.id_dispositivo = d.id
    WHERE d.activo = 1
    GROUP BY d.id, d.nombre
    ORDER BY d.nombre
", [$fecha_dt, $fecha_dt_fin]);

// ── Personas que entraron y aún no salieron ───────────────────────────────
$adentro = sqlsrv_query($conn, "
    WITH marcas AS (
        SELECT
            md.id_numero,
            md.id_dispositivo,
            md.fecha_hora,
            CASE
                WHEN md.rn_entrada = 1 THEN 'entrada'
                WHEN md.marcas_dia > 1 AND md.rn_salida = 1 THEN 'salida'
                ELSE 'intermedia'
            END AS tipo_calc
        FROM ($sql_marcaciones_dia) md
    )
    SELECT
        mc.id_numero,
        ISNULL(t.rut,    CAST(mc.id_numero AS NVARCHAR(20))) AS rut,
        ISNULL(t.nombre, N'(sin registro)')                  AS nombre,
        MAX(CASE WHEN mc.tipo_calc = 'entrada' THEN mc.fecha_hora END) AS ultima_entrada,
        MAX(d.nombre) AS dispositivo
    FROM marcas mc
    LEFT JOIN dbo.reloj_trabajador  t ON t.id_numero = mc.id_numero
    JOIN  dbo.reloj_dispositivo     d ON d.id        = mc.id_dispositivo
    GROUP BY mc.id_numero, t.rut, t.nombre
    HAVING MAX(CASE WHEN mc.tipo_calc = 'entrada' THEN mc.fecha_hora END) >
           ISNULL(MAX(CASE WHEN mc.tipo_calc = 'salida' THEN mc.fecha_hora END), '1900-01-01')
    ORDER BY nombre
", [$fecha_dt, $fecha_dt_fin]);

// ── Últimas 30 marcaciones con tipo calculado ─────────────────────────────
$ultimas = sqlsrv_query($conn, "
    SELECT TOP 30
        m.fecha_hora,
        CASE
            WHEN md.rn_entrada = 1 THEN 'entrada'
            WHEN md.marcas_dia > 1 AND md.rn_salida = 1 THEN 'salida'
            ELSE 'intermedia'
        END AS tipo_calc,
        ISNULL(t.rut,    CAST(m.id_numero AS NVARCHAR(20))) AS rut,
        ISNULL(t.nombre, N'(sin registro)')                  AS nombre,
        d.nombre AS dispositivo
    FROM dbo.reloj_marcacion m
    JOIN ($sql_marcaciones_dia) md
      ON md.id_numero = m.id_numero
     AND md.id_dispositivo = m.id_dispositivo
     AND md.fecha_hora = m.fecha_hora
    LEFT JOIN dbo.reloj_trabajador t
           ON t.id_numero = m.id_numero
    JOIN  dbo.reloj_dispositivo    d ON d.id = m.id_dispositivo
    WHERE m.fecha_hora BETWEEN CONVERT(datetime,?,120) AND CONVERT(datetime,?,120)
      AND m.id_numero != 0
    ORDER BY m.fecha_hora DESC
", [$fecha_dt, $fecha_dt_fin, $fecha_dt, $fecha_dt_fin]);

$title = "Reloj — Dashboard";
include __DIR__ . '/../partials/header.php';
include __DIR__ . '/../partials/navbar_wrapper.php';
?>
<main class="container-fluid py-4">

  <div class="d-flex flex-wrap align-items-center gap-3 mb-4">
    <h4 class="mb-0">Dashboard Reloj Biométrico</h4>
    <form method="get" class="d-flex align-items-center gap-2">
      <input type="date" name="fecha" class="form-control form-control-sm"
             value="<?= htmlspecialchars($fecha) ?>">
      <button type="submit" class="btn btn-sm btn-outline-secondary">Ir</button>
    </form>
    <button class="btn btn-success btn-sm" onclick="abrirSync('sync')">&#8635; Sincronizar relojes</button>
    <button class="btn btn-outline-danger btn-sm" onclick="confirmarLimpiar()">&#128465; Limpiar historial en relojes</button>
  </div>

  <!-- Resumen por reloj -->
  <div class="row g-3 mb-4">
  <?php
  $hay_resumen = false;
  while ($r = sqlsrv_fetch_array($resumen, SQLSRV_FETCH_ASSOC)):
      $hay_resumen = true;
  ?>
    <div class="col-md-4">
      <div class="card border-0 shadow-sm">
        <div class="card-body">
          <h6 class="card-title text-muted"><?= htmlspecialchars($r['dispositivo']) ?></h6>
          <div class="d-flex gap-4 mt-2">
            <div class="text-center">
              <div class="fs-2 fw-bold text-success"><?= (int)$r['entradas'] ?></div>
              <small class="text-muted">Entradas</small>
            </div>
            <div class="text-center">
              <div class="fs-2 fw-bold text-danger"><?= (int)$r['salidas'] ?></div>
              <small class="text-muted">Salidas</small>
            </div>
          </div>
        </div>
      </div>
    </div>
  <?php endwhile; ?>
  <?php if (!$hay_resumen): ?>
    <div class="col-12">
      <div class="alert alert-info mb-0">Sin datos para el <?= htmlspecialchars($fecha) ?>. Prueba sincronizar o cambiar la fecha.</div>
    </div>
  <?php endif; ?>
  </div>

  <div class="row g-3">
    <!-- Personas dentro -->
    <div class="col-md-6">
      <div class="card shadow-sm">
        <div class="card-header bg-success text-white fw-bold">Personas dentro ahora</div>
        <div class="card-body p-0">
          <div class="table-responsive">
            <table class="table table-sm mb-0">
              <thead><tr><th>Nombre</th><th>RUT</th><th>Entrada</th><th>Reloj</th></tr></thead>
              <tbody>
              <?php
              $cnt = 0;
              while ($a = sqlsrv_fetch_array($adentro, SQLSRV_FETCH_ASSOC)):
                  $cnt++;
                  $ts = $a['ultima_entrada'] instanceof DateTime
                        ? $a['ultima_entrada']->format('H:i') : '';
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
        </div>
        <div class="card-footer text-muted small"><?= $cnt ?> persona(s)</div>
      </div>
    </div>

    <!-- Últimas marcaciones -->
    <div class="col-md-6">
      <div class="card shadow-sm">
        <div class="card-header bg-dark text-white fw-bold">Últimas marcaciones</div>
        <div class="card-body p-0">
          <div class="table-responsive">
            <table class="table table-sm mb-0">
              <thead><tr><th>Hora</th><th>Tipo</th><th>Nombre</th><th>Reloj</th></tr></thead>
              <tbody>
              <?php while ($u = sqlsrv_fetch_array($ultimas, SQLSRV_FETCH_ASSOC)):
                  $ts      = $u['fecha_hora'] instanceof DateTime
                             ? $u['fecha_hora']->format('H:i:s') : '';
                  $tipo_calc = (string)$u['tipo_calc'];
              ?>
                <tr>
                  <td><?= $ts ?></td>
                  <td><?php if ($tipo_calc === 'entrada'): ?>
                    <span class="badge bg-success">Entrada</span>
                  <?php elseif ($tipo_calc === 'salida'): ?>
                    <span class="badge bg-danger">Salida</span>
                  <?php else: ?>
                    <span class="badge bg-secondary">Marca</span>
                  <?php endif; ?></td>
                  <td><?= htmlspecialchars($u['nombre']) ?></td>
                  <td><small><?= htmlspecialchars($u['dispositivo']) ?></small></td>
                </tr>
              <?php endwhile; ?>
              </tbody>
            </table>
          </div>
        </div>
        <div class="card-footer text-end">
          <a href="marcaciones.php?desde=<?= $fecha ?>&hasta=<?= $fecha ?>"
             class="btn btn-sm btn-outline-secondary">Ver historial del día</a>
        </div>
      </div>
    </div>
  </div>
</main>

<!-- Modal progreso -->
<div class="modal fade" id="modalSync" tabindex="-1" data-bs-backdrop="static">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="modalSyncTitulo">Sincronizando...</h5>
      </div>
      <div class="modal-body">
        <div class="progress mb-3" style="height:24px;">
          <div id="syncBar" class="progress-bar progress-bar-striped progress-bar-animated
               bg-success" style="width:0%">0%</div>
        </div>
        <pre id="syncLog" class="bg-dark text-light p-3 rounded"
             style="max-height:300px;overflow-y:auto;font-size:.8rem;"></pre>
      </div>
      <div class="modal-footer">
        <button class="btn btn-secondary d-none" id="btnCerrarSync"
                data-bs-dismiss="modal" onclick="location.reload()">Cerrar</button>
      </div>
    </div>
  </div>
</div>

<?php include __DIR__ . '/../partials/footer.php'; ?>
<script>
function confirmarLimpiar() {
    if (!confirm(
        'ATENCIÓN: Esta acción borrará el historial de marcaciones almacenado en los relojes físicos.\n\n' +
        'Los registros en la base de datos SQL Server NO se eliminarán.\n\n' +
        '¿Desea continuar?'
    )) return;
    abrirSync('limpiar');
}

function abrirSync(accion) {
    const titulos = {
        sync:    'Sincronizando relojes...',
        limpiar: 'Limpiando historial en relojes...'
    };
    document.getElementById('modalSyncTitulo').textContent = titulos[accion] || accion;
    document.getElementById('syncLog').textContent = '';
    const bar = document.getElementById('syncBar');
    bar.style.width = '5%';
    bar.textContent = '0%';
    bar.classList.remove('progress-bar-animated');
    bar.classList.add('progress-bar-animated');
    document.getElementById('btnCerrarSync').classList.add('d-none');

    const modal = new bootstrap.Modal(document.getElementById('modalSync'));
    modal.show();

    let pct = 5;
    const source = new EventSource('sync_stream.php?accion=' + accion);

    source.onmessage = function(e) {
        const msg = JSON.parse(e.data);
        if (msg === '__DONE__') {
            source.close();
            bar.style.width = '100%';
            bar.textContent = '100%';
            bar.classList.remove('progress-bar-animated');
            document.getElementById('btnCerrarSync').classList.remove('d-none');
        } else {
            const log = document.getElementById('syncLog');
            log.textContent += msg + '\n';
            log.scrollTop = log.scrollHeight;
            pct = Math.min(pct + 30, 90);
            bar.style.width = pct + '%';
            bar.textContent = pct + '%';
        }
    };

    source.onerror = function() {
        source.close();
        document.getElementById('syncLog').textContent += '\nError de conexion.';
        document.getElementById('btnCerrarSync').classList.remove('d-none');
    };
}
</script>
