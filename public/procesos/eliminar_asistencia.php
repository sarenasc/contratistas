<?php
declare(strict_types=1);

require_once __DIR__ . '/../_bootstrap.php';
require_once __DIR__ . '/../../app/lib/db.php';

$title       = "Eliminar Asistencia";
$flash_error = null;
$flash_ok    = null;

/* ══════════════════════════════════════════════
   AJAX: ejecutar eliminación
══════════════════════════════════════════════ */
if (isset($_POST['ajax_eliminar'])) {
    header('Content-Type: application/json; charset=utf-8');
    try {
        $tipo = $_POST['tipo'] ?? '';

        if ($tipo === 'semana') {
            $semana = (int)($_POST['semana'] ?? 0);
            $anio   = (int)($_POST['anio']   ?? 0);
            if ($semana <= 0 || $anio <= 0) throw new RuntimeException("Semana y año son requeridos.");

            // Eliminar proformas asociadas (detalle y descuentos primero por FK)
            $pf_deleted = 0;
            $pf_warn    = null;
            try {
                $pf_ids_stmt = db_query($conn,
                    "SELECT id FROM dbo.dota_factura WHERE semana = ? AND anio = ?",
                    [$semana, $anio], "IDs proforma"
                );
                $pf_ids = [];
                while ($pf = sqlsrv_fetch_array($pf_ids_stmt, SQLSRV_FETCH_ASSOC)) {
                    $pf_ids[] = (int)$pf['id'];
                }
                if (!empty($pf_ids)) {
                    $ph = implode(',', array_fill(0, count($pf_ids), '?'));
                    sqlsrv_query($conn, "DELETE FROM dbo.dota_factura_descuento WHERE id_factura IN ($ph)", $pf_ids);
                    sqlsrv_query($conn, "DELETE FROM dbo.dota_factura_detalle   WHERE id_factura IN ($ph)", $pf_ids);
                    sqlsrv_query($conn, "DELETE FROM dbo.dota_factura           WHERE id          IN ($ph)", $pf_ids);
                    $pf_deleted = count($pf_ids);
                }
            } catch (Throwable $pf_e) {
                $pf_warn = $pf_e->getMessage();
            }

            // Eliminar asistencia
            $stmt = db_query($conn,
                "DELETE FROM dbo.dota_asistencia_carga
                  WHERE semana = ? AND YEAR(fecha) = ?",
                [$semana, $anio],
                "DELETE semana"
            );
            $deleted = sqlsrv_rows_affected($stmt);

            $extra = $pf_deleted > 0 ? " Se eliminaron {$pf_deleted} proforma(s) asociada(s)." : "";
            if ($pf_warn) $extra .= " (Aviso proformas: {$pf_warn})";
            echo json_encode(['ok' => true, 'deleted' => $deleted,
                'msg' => "Se eliminaron {$deleted} registros de la semana {$semana} / {$anio}.{$extra}"]);

        } elseif ($tipo === 'dia') {
            $fecha = $_POST['fecha'] ?? '';
            if (!$fecha || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha)) {
                throw new RuntimeException("Fecha inválida.");
            }

            // Obtener semana/año del día antes de borrar, para buscar proforma
            $sem_stmt = db_query($conn,
                "SELECT DISTINCT semana, YEAR(fecha) AS anio
                 FROM dbo.dota_asistencia_carga WHERE fecha = ?",
                [new DateTime($fecha)], "semana del dia"
            );
            $sem_rows = [];
            while ($sr = sqlsrv_fetch_array($sem_stmt, SQLSRV_FETCH_ASSOC)) {
                $sem_rows[] = [(int)$sr['semana'], (int)$sr['anio']];
            }

            // Buscar proformas correspondientes y eliminarlas
            $pf_deleted = 0;
            $pf_warn    = null;
            try {
                foreach ($sem_rows as [$sem_v, $anio_v]) {
                    $pf_ids_stmt = db_query($conn,
                        "SELECT id FROM dbo.dota_factura WHERE semana = ? AND anio = ?",
                        [$sem_v, $anio_v], "IDs proforma dia"
                    );
                    $pf_ids = [];
                    while ($pf = sqlsrv_fetch_array($pf_ids_stmt, SQLSRV_FETCH_ASSOC)) {
                        $pf_ids[] = (int)$pf['id'];
                    }
                    if (!empty($pf_ids)) {
                        $ph = implode(',', array_fill(0, count($pf_ids), '?'));
                        sqlsrv_query($conn, "DELETE FROM dbo.dota_factura_descuento WHERE id_factura IN ($ph)", $pf_ids);
                        sqlsrv_query($conn, "DELETE FROM dbo.dota_factura_detalle   WHERE id_factura IN ($ph)", $pf_ids);
                        sqlsrv_query($conn, "DELETE FROM dbo.dota_factura           WHERE id          IN ($ph)", $pf_ids);
                        $pf_deleted += count($pf_ids);
                    }
                }
            } catch (Throwable $pf_e) {
                $pf_warn = $pf_e->getMessage();
            }

            $stmt = db_query($conn,
                "DELETE FROM dbo.dota_asistencia_carga WHERE fecha = ?",
                [new DateTime($fecha)],
                "DELETE dia"
            );
            $deleted = sqlsrv_rows_affected($stmt);

            $extra = $pf_deleted > 0 ? " Se eliminaron {$pf_deleted} proforma(s) asociada(s)." : "";
            if ($pf_warn) $extra .= " (Aviso proformas: {$pf_warn})";
            echo json_encode(['ok' => true, 'deleted' => $deleted,
                'msg' => "Se eliminaron {$deleted} registros del día {$fecha}.{$extra}"]);

        } elseif ($tipo === 'proforma') {
            $id_fac = (int)($_POST['id_fac'] ?? 0);
            if ($id_fac <= 0) throw new RuntimeException("ID de proforma inválido.");

            try {
                sqlsrv_query($conn, "DELETE FROM dbo.dota_factura_descuento WHERE id_factura = ?", [$id_fac]);
                sqlsrv_query($conn, "DELETE FROM dbo.dota_factura_detalle   WHERE id_factura = ?", [$id_fac]);
                $st = sqlsrv_query($conn, "DELETE FROM dbo.dota_factura WHERE id = ?", [$id_fac]);
                $deleted = $st ? sqlsrv_rows_affected($st) : 0;
            } catch (Throwable $pf_e) {
                throw new RuntimeException("Error al eliminar proforma: " . $pf_e->getMessage());
            }

            echo json_encode(['ok' => true, 'deleted' => $deleted,
                'msg' => "Proforma ID {$id_fac} eliminada correctamente."]);

        } else {
            throw new RuntimeException("Tipo de eliminación no válido.");
        }
    } catch (Throwable $e) {
        echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

/* ══════════════════════════════════════════════
   Cargar resumen: semanas y días con registros
══════════════════════════════════════════════ */
$semanas  = [];
$dias     = [];
$proformas = [];

try {
    // Semanas disponibles
    $stmt = db_query($conn,
        "SELECT semana, YEAR(fecha) AS anio, COUNT(*) AS total,
                MIN(fecha) AS fecha_desde, MAX(fecha) AS fecha_hasta,
                MAX(fecha_carga) AS ultima_carga
         FROM dbo.dota_asistencia_carga
         WHERE semana IS NOT NULL
         GROUP BY semana, YEAR(fecha)
         ORDER BY YEAR(fecha) DESC, semana DESC",
        [], "semanas"
    );
    while ($r = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
        $sem = (int)$r['semana'];
        $anio_v = (int)$r['anio'];
        // Contar proformas asociadas
        $pf_stmt = db_query($conn,
            "SELECT COUNT(*) AS cnt FROM dbo.dota_factura WHERE semana = ? AND anio = ?",
            [$sem, $anio_v], "cnt proforma"
        );
        $pf_row = sqlsrv_fetch_array($pf_stmt, SQLSRV_FETCH_ASSOC);
        $semanas[] = [
            'semana'       => $sem,
            'anio'         => $anio_v,
            'total'        => (int)$r['total'],
            'fecha_desde'  => $r['fecha_desde'] instanceof DateTime
                                 ? $r['fecha_desde']->format('Y-m-d') : (string)$r['fecha_desde'],
            'fecha_hasta'  => $r['fecha_hasta'] instanceof DateTime
                                 ? $r['fecha_hasta']->format('Y-m-d') : (string)$r['fecha_hasta'],
            'ultima_carga' => $r['ultima_carga'] instanceof DateTime
                                 ? $r['ultima_carga']->format('Y-m-d H:i') : (string)$r['ultima_carga'],
            'proformas'    => (int)($pf_row['cnt'] ?? 0),
        ];
    }

    // Proformas disponibles
    try {
        $stmt_pf = db_query($conn,
            "SELECT f.id, f.semana, f.anio, f.version, f.estado,
                    f.tot_factura, f.total_neto, f.fecha_creacion, f.usuario,
                    (SELECT COUNT(*) FROM dbo.dota_factura_detalle fd WHERE fd.id_factura = f.id) AS n_det
             FROM dbo.dota_factura f
             ORDER BY f.anio DESC, f.semana DESC, f.version DESC",
            [], "proformas"
        );
        while ($r = sqlsrv_fetch_array($stmt_pf, SQLSRV_FETCH_ASSOC)) {
            $proformas[] = [
                'id'            => (int)$r['id'],
                'semana'        => (int)$r['semana'],
                'anio'          => (int)$r['anio'],
                'version'       => (int)$r['version'],
                'estado'        => (string)$r['estado'],
                'tot_factura'   => (float)$r['tot_factura'],
                'total_neto'    => (float)$r['total_neto'],
                'fecha_creacion'=> $r['fecha_creacion'] instanceof DateTime
                                      ? $r['fecha_creacion']->format('Y-m-d H:i') : substr((string)$r['fecha_creacion'], 0, 16),
                'usuario'       => (string)($r['usuario'] ?? ''),
                'n_det'         => (int)$r['n_det'],
            ];
        }
    } catch (Throwable $ignore) {
        // Si la tabla no existe aún, no mostrar pestaña
    }

    // Días disponibles
    $stmt = db_query($conn,
        "SELECT fecha, semana, COUNT(*) AS total,
                MAX(fecha_carga) AS ultima_carga
         FROM dbo.dota_asistencia_carga
         GROUP BY fecha, semana
         ORDER BY fecha DESC",
        [], "dias"
    );
    while ($r = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
        $dias[] = [
            'fecha'       => $r['fecha'] instanceof DateTime
                                ? $r['fecha']->format('Y-m-d') : (string)$r['fecha'],
            'semana'      => (int)($r['semana'] ?? 0),
            'total'       => (int)$r['total'],
            'ultima_carga'=> $r['ultima_carga'] instanceof DateTime
                                ? $r['ultima_carga']->format('Y-m-d H:i') : (string)$r['ultima_carga'],
        ];
    }
} catch (Throwable $e) {
    $flash_error = $e->getMessage();
}

include __DIR__ . '/../partials/header.php';
include __DIR__ . '/../partials/navbar_wrapper.php';
?>

<main class="container py-4">

<?php if ($flash_error): ?>
  <div class="alert alert-danger"><?= htmlspecialchars($flash_error) ?></div>
<?php endif; ?>

<div class="d-flex align-items-center gap-3 mb-4">
  <h4 class="mb-0">🗑️ Eliminar Registros de Asistencia</h4>
  <a href="<?= BASE_URL ?>/procesos/carga_asistencia.php" class="btn btn-outline-secondary btn-sm">← Volver a Carga</a>
</div>

<div class="alert alert-warning">
  <strong>Atención:</strong> Esta acción elimina registros permanentemente de <code>dota_asistencia_carga</code>.
  Úsala antes de volver a subir una semana o día que ya fue cargado.
</div>

<!-- Tabs -->
<ul class="nav nav-tabs mb-0" id="tabEliminar">
  <li class="nav-item">
    <button class="nav-link active" data-bs-toggle="tab" data-bs-target="#tabSemana">Por Semana</button>
  </li>
  <li class="nav-item">
    <button class="nav-link" data-bs-toggle="tab" data-bs-target="#tabDia">Por Día</button>
  </li>
  <li class="nav-item">
    <button class="nav-link" data-bs-toggle="tab" data-bs-target="#tabProforma">Proformas</button>
  </li>
</ul>

<div class="tab-content border border-top-0 rounded-bottom p-3 bg-white shadow-sm mb-4">

  <!-- ── TAB SEMANA ── -->
  <div class="tab-pane fade show active" id="tabSemana">
    <?php if (empty($semanas)): ?>
      <p class="text-muted mt-3">No hay registros de asistencia en la base de datos.</p>
    <?php else: ?>
    <p class="text-muted mt-2 mb-3">Selecciona la semana que deseas eliminar y haz clic en <strong>Eliminar</strong>.</p>
    <div class="table-responsive">
      <table class="table table-sm table-hover align-middle">
        <thead class="table-dark">
          <tr>
            <th>Semana</th>
            <th>Año</th>
            <th>Registros</th>
            <th>Desde</th>
            <th>Hasta</th>
            <th>Última carga</th>
            <th>Proformas</th>
            <th></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($semanas as $s): ?>
          <tr>
            <td><strong>Sem. <?= $s['semana'] ?></strong></td>
            <td><?= $s['anio'] ?></td>
            <td><span class="badge bg-secondary"><?= number_format($s['total']) ?></span></td>
            <td><?= htmlspecialchars($s['fecha_desde']) ?></td>
            <td><?= htmlspecialchars($s['fecha_hasta']) ?></td>
            <td class="text-muted small"><?= htmlspecialchars($s['ultima_carga']) ?></td>
            <td>
              <?php if ($s['proformas'] > 0): ?>
                <span class="badge bg-warning text-dark"><?= $s['proformas'] ?> proforma(s)</span>
              <?php else: ?>
                <span class="text-muted small">—</span>
              <?php endif; ?>
            </td>
            <td class="d-flex gap-1 flex-wrap">
              <button class="btn btn-danger btn-sm"
                      onclick="confirmarEliminar('semana', <?= $s['semana'] ?>, <?= $s['anio'] ?>, null,
                               'semana <?= $s['semana'] ?> / <?= $s['anio'] ?> (<?= number_format($s['total']) ?> registros<?= $s['proformas'] > 0 ? ' + ' . $s['proformas'] . ' proforma(s)' : '' ?>)')">
                🗑️ Todo
              </button>
              <?php if ($s['proformas'] > 0): ?>
              <button class="btn btn-outline-danger btn-sm"
                      onclick="confirmarEliminarProformasSemana(<?= $s['semana'] ?>, <?= $s['anio'] ?>, <?= $s['proformas'] ?>)">
                📄 Solo Proforma
              </button>
              <?php endif; ?>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php endif; ?>
  </div>

  <!-- ── TAB PROFORMAS ── -->
  <div class="tab-pane fade" id="tabProforma">
    <?php if (empty($proformas)): ?>
      <p class="text-muted mt-3">No hay proformas en la base de datos.</p>
    <?php else: ?>
    <p class="text-muted mt-2 mb-3">Selecciona la proforma que deseas eliminar. Esta acción <strong>no</strong> elimina la asistencia.</p>
    <div class="table-responsive">
      <table class="table table-sm table-hover align-middle">
        <thead class="table-dark">
          <tr>
            <th>Semana</th>
            <th>Año</th>
            <th>Versión</th>
            <th>Estado</th>
            <th>Líneas</th>
            <th>Total Neto</th>
            <th>Creada</th>
            <th>Usuario</th>
            <th></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($proformas as $pf): ?>
          <tr>
            <td><strong>Sem. <?= $pf['semana'] ?></strong></td>
            <td><?= $pf['anio'] ?></td>
            <td>v<?= $pf['version'] ?></td>
            <td>
              <?php if ($pf['estado'] === 'cerrado'): ?>
                <span class="badge bg-dark">Cerrada</span>
              <?php else: ?>
                <span class="badge bg-warning text-dark">En proceso</span>
              <?php endif; ?>
            </td>
            <td><span class="badge bg-secondary"><?= $pf['n_det'] ?></span></td>
            <td class="text-end">$<?= number_format($pf['total_neto'], 0, ',', '.') ?></td>
            <td class="text-muted small"><?= htmlspecialchars($pf['fecha_creacion']) ?></td>
            <td class="text-muted small"><?= htmlspecialchars($pf['usuario']) ?></td>
            <td>
              <button class="btn btn-danger btn-sm"
                      onclick="confirmarEliminar('proforma', null, null, null,
                               'proforma Sem.<?= $pf['semana'] ?>/<?= $pf['anio'] ?> v<?= $pf['version'] ?>',
                               <?= $pf['id'] ?>)">
                🗑️ Eliminar
              </button>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php endif; ?>
  </div>

  <!-- ── TAB DÍA ── -->
  <div class="tab-pane fade" id="tabDia">
    <?php if (empty($dias)): ?>
      <p class="text-muted mt-3">No hay registros de asistencia en la base de datos.</p>
    <?php else: ?>
    <p class="text-muted mt-2 mb-3">Selecciona el día exacto que deseas eliminar.</p>
    <div class="table-responsive">
      <table class="table table-sm table-hover align-middle">
        <thead class="table-dark">
          <tr>
            <th>Fecha</th>
            <th>Semana</th>
            <th>Registros</th>
            <th>Última carga</th>
            <th></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($dias as $d): ?>
          <tr>
            <td><strong><?= htmlspecialchars($d['fecha']) ?></strong></td>
            <td>Sem. <?= $d['semana'] ?></td>
            <td><span class="badge bg-secondary"><?= number_format($d['total']) ?></span></td>
            <td class="text-muted small"><?= htmlspecialchars($d['ultima_carga']) ?></td>
            <td>
              <button class="btn btn-danger btn-sm"
                      onclick="confirmarEliminar('dia', null, null, <?= json_encode($d['fecha']) ?>,
                               'día <?= htmlspecialchars($d['fecha']) ?> (<?= number_format($d['total']) ?> registros)')">
                🗑️ Eliminar
              </button>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php endif; ?>
  </div>

</div>

<!-- Modal confirmación -->
<div class="modal fade" id="modalConfirm" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header bg-danger text-white">
        <h5 class="modal-title">⚠️ Confirmar eliminación</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <p>¿Estás seguro que deseas eliminar los registros de la <strong id="modalDesc"></strong>?</p>
        <p class="text-muted small">Esta acción no se puede deshacer. Luego podrás volver a cargar el Excel sin duplicados.</p>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
        <button type="button" class="btn btn-danger" id="btnConfirmarSi">Sí, eliminar</button>
      </div>
    </div>
  </div>
</div>

<!-- Toast resultado -->
<div class="position-fixed bottom-0 end-0 p-3" style="z-index:9999">
  <div id="toastResult" class="toast align-items-center text-white border-0" role="alert">
    <div class="d-flex">
      <div class="toast-body" id="toastMsg"></div>
      <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button>
    </div>
  </div>
</div>

</main>

<script>
let _pendingPayload = null;

function confirmarEliminar(tipo, semana, anio, fecha, desc, idFac) {
  _pendingPayload = { tipo, semana, anio, fecha, idFac: idFac || null };
  document.getElementById('modalDesc').textContent = desc;
  new bootstrap.Modal(document.getElementById('modalConfirm')).show();
}

document.getElementById('btnConfirmarSi').addEventListener('click', async function () {
  bootstrap.Modal.getInstance(document.getElementById('modalConfirm')).hide();
  if (!_pendingPayload) return;

  const fd = new FormData();
  fd.append('ajax_eliminar', '1');
  fd.append('tipo', _pendingPayload.tipo);
  if (_pendingPayload.semana) fd.append('semana', _pendingPayload.semana);
  if (_pendingPayload.anio)   fd.append('anio',   _pendingPayload.anio);
  if (_pendingPayload.fecha)  fd.append('fecha',   _pendingPayload.fecha);
  if (_pendingPayload.idFac)  fd.append('id_fac',  _pendingPayload.idFac);

  try {
    const res  = await fetch('eliminar_asistencia.php', { method: 'POST', body: fd });
    const data = await res.json();
    const toast = new bootstrap.Toast(document.getElementById('toastResult'));
    const el    = document.getElementById('toastResult');
    const msg   = document.getElementById('toastMsg');

    if (data.ok) {
      el.classList.remove('bg-danger');
      el.classList.add('bg-success');
      msg.textContent = '✅ ' + data.msg;
      toast.show();
      setTimeout(() => location.reload(), 1800);
    } else {
      el.classList.remove('bg-success');
      el.classList.add('bg-danger');
      msg.textContent = '❌ ' + (data.error || 'Error al eliminar');
      toast.show();
    }
  } catch (e) {
    alert('Error de conexión: ' + e.message);
  }
});
</script>

<?php include __DIR__ . '/../partials/footer.php'; ?>
