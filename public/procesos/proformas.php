<?php
require_once __DIR__ . '/../_bootstrap.php';
require_once __DIR__ . '/../../app/lib/db.php';

$title = "Proformas";
$flash_error = null;

/* ── Cambio de estado ── */
if (isset($_POST['cambiar_estado'])) {
    $id_fac   = (int)$_POST['id_factura'];
    $nuevo    = in_array($_POST['nuevo_estado'], ['proceso','cerrado'], true) ? $_POST['nuevo_estado'] : null;
    if ($id_fac > 0 && $nuevo) {
        $fc = $nuevo === 'cerrado' ? new DateTime() : null;
        sqlsrv_query($conn,
            "UPDATE dbo.dota_factura SET estado=?, fecha_cierre=? WHERE id=?",
            [$nuevo, $fc, $id_fac]);
    }
    header('Location: proformas.php'); exit;
}

/* ── Filtros ── */
$f_semana = isset($_GET['semana']) ? (int)$_GET['semana'] : 0;
$f_anio   = isset($_GET['anio'])   ? (int)$_GET['anio']   : (int)date('Y');
$f_estado = $_GET['estado'] ?? '';

/* ── Proformas ── */
$where = "WHERE f.anio = ?";
$params = [$f_anio];
if ($f_semana > 0) { $where .= " AND f.semana = ?"; $params[] = $f_semana; }
if (in_array($f_estado, ['proceso','cerrado'], true)) { $where .= " AND f.estado = ?"; $params[] = $f_estado; }

try {
    $stmt = db_query($conn,
        "SELECT f.id, f.semana, f.anio, f.version, f.obs, f.estado,
                f.fecha_creacion, f.fecha_cierre, f.usuario,
                f.tot_base_jorn, f.tot_base_hhee, f.tot_pct_jorn, f.tot_pct_hhee,
                f.tot_bono, f.tot_factura, f.descuento, f.total_neto
         FROM dbo.dota_factura f
         $where
         ORDER BY f.anio DESC, f.semana DESC, f.version DESC",
        $params, "Proformas");
    $proformas = [];
    while ($r = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) $proformas[] = $r;
} catch (Throwable $e) { $flash_error = $e->getMessage(); $proformas = []; }

/* ── Cargar contratistas por proforma ── */
$cont_by_pf = [];
$desc_by_pf = [];   // [id_factura][id_contratista] = [[id, valor, obs], ...]
if (!empty($proformas)) {
    $ids = array_column($proformas, 'id');
    $ph  = implode(',', array_fill(0, count($ids), '?'));
    try {
        $sc = db_query($conn,
            "SELECT fd.id_factura, c.nombre
             FROM dbo.dota_factura_detalle fd
             JOIN dbo.dota_contratista c ON c.id = fd.id_contratista
             WHERE fd.id_factura IN ($ph)
             GROUP BY fd.id_factura, c.nombre
             ORDER BY c.nombre", $ids, "Contratistas proforma");
        while ($rc = sqlsrv_fetch_array($sc, SQLSRV_FETCH_ASSOC))
            $cont_by_pf[(int)$rc['id_factura']][] = $rc['nombre'];
    } catch (Throwable $ignore) {}

    try {
        $sd = db_query($conn,
            "SELECT id, id_factura, id_contratista, valor, observacion
             FROM dbo.dota_factura_descuento
             WHERE id_factura IN ($ph)
             ORDER BY id_factura, id_contratista, id", $ids, "Descuentos proforma");
        while ($rd = sqlsrv_fetch_array($sd, SQLSRV_FETCH_ASSOC))
            $desc_by_pf[(int)$rd['id_factura']][(int)$rd['id_contratista']][] = [
                'id'    => (int)$rd['id'],
                'valor' => (float)$rd['valor'],
                'obs'   => $rd['observacion'],
            ];
    } catch (Throwable $ignore) {}
}

function fmt($n)  { return '$' . number_format((float)$n, 0, ',', '.'); }
function fdate($v){ return $v instanceof DateTime ? $v->format('d/m/Y H:i') : substr((string)$v,0,16); }

include __DIR__ . '/../partials/header.php';
include __DIR__ . '/../partials/navbar_wrapper.php';
?>

<main class="container-fluid py-4 px-3">
<h4 class="mb-4 fw-bold">Proformas de Facturación</h4>

<?php if ($flash_error): ?>
<div class="alert alert-danger"><?= htmlspecialchars($flash_error) ?></div>
<?php endif; ?>

<!-- Filtros -->
<div class="card shadow-sm mb-4">
  <div class="card-body py-2">
    <form method="GET" class="row g-2 align-items-end">
      <div class="col-auto">
        <label class="form-label mb-1 small">Semana</label>
        <input type="number" name="semana" class="form-control form-control-sm" min="1" max="53"
               value="<?= $f_semana ?: '' ?>" placeholder="Todas" style="width:90px">
      </div>
      <div class="col-auto">
        <label class="form-label mb-1 small">Año</label>
        <input type="number" name="anio" class="form-control form-control-sm"
               value="<?= $f_anio ?>" style="width:90px">
      </div>
      <div class="col-auto">
        <label class="form-label mb-1 small">Estado</label>
        <select name="estado" class="form-select form-select-sm">
          <option value="">Todos</option>
          <option value="proceso"  <?= $f_estado==='proceso'  ? 'selected':'' ?>>En Proceso</option>
          <option value="cerrado"  <?= $f_estado==='cerrado'  ? 'selected':'' ?>>Cerrado</option>
        </select>
      </div>
      <div class="col-auto">
        <button type="submit" class="btn btn-primary btn-sm">Filtrar</button>
      </div>
    </form>
  </div>
</div>

<?php if (empty($proformas)): ?>
<div class="alert alert-info">No hay proformas para los filtros seleccionados.</div>
<?php else: ?>

<div class="accordion" id="accPf">
<?php foreach ($proformas as $i => $pf):
  $id      = (int)$pf['id'];
  $emp_jorn = (float)$pf['tot_base_jorn'] + (float)$pf['tot_pct_jorn'];
  $emp_hhee = (float)$pf['tot_base_hhee'] + (float)$pf['tot_pct_hhee'];
  $conts    = $cont_by_pf[$id] ?? [];
?>
<div class="accordion-item mb-2 border rounded shadow-sm">
  <h2 class="accordion-header">
    <button class="accordion-button <?= $i > 0 ? 'collapsed':'' ?> py-2"
            type="button" data-bs-toggle="collapse"
            data-bs-target="#pfc<?= $id ?>">
      <div class="d-flex flex-wrap gap-3 w-100 align-items-center">
        <span class="fw-bold">Sem <?= $pf['semana'] ?>/<?= $pf['anio'] ?> — v<?= $pf['version'] ?></span>
        <?php if ($pf['estado'] === 'cerrado'): ?>
        <span class="badge bg-dark">Cerrada</span>
        <?php else: ?>
        <span class="badge bg-warning text-dark">En proceso</span>
        <?php endif; ?>
        <?php if ($conts): ?>
        <span class="text-muted small"><?= htmlspecialchars(implode(', ', $conts)) ?></span>
        <?php endif; ?>
        <span class="ms-auto fw-bold text-success"><?= fmt($pf['total_neto']) ?></span>
        <span class="text-muted small"><?= fdate($pf['fecha_creacion']) ?></span>
      </div>
    </button>
  </h2>

  <div id="pfc<?= $id ?>" class="accordion-collapse collapse <?= $i===0?'show':'' ?>">
    <div class="accordion-body p-3">

      <!-- Obs + acciones -->
      <div class="d-flex flex-wrap gap-3 align-items-start mb-3">
        <?php if ($pf['obs']): ?>
        <div class="text-muted small flex-grow-1">
          <strong>Obs:</strong> <?= htmlspecialchars($pf['obs']) ?>
        </div>
        <?php endif; ?>
        <div class="d-flex gap-2 ms-auto">
          <!-- Cambiar estado -->
          <?php if ($pf['estado'] !== 'cerrado'): ?>
          <form method="POST" class="d-inline">
            <?= csrf_field() ?>
            <input type="hidden" name="id_factura" value="<?= $id ?>">
            <input type="hidden" name="nuevo_estado" value="cerrado">
            <button type="submit" name="cambiar_estado"
                    class="btn btn-sm btn-outline-dark"
                    onclick="return confirm('¿Cerrar esta proforma? No podrá modificarse.')">
              Cerrar
            </button>
          </form>
          <?php else: ?>
          <form method="POST" class="d-inline">
            <?= csrf_field() ?>
            <input type="hidden" name="id_factura" value="<?= $id ?>">
            <input type="hidden" name="nuevo_estado" value="proceso">
            <button type="submit" name="cambiar_estado" class="btn btn-sm btn-outline-warning">
              Reabrir
            </button>
          </form>
          <?php endif; ?>
          <!-- Exportar Excel -->
          <a href="exportar_proforma.php?id=<?= $id ?>"
             class="btn btn-sm btn-success">
            ⬇ Excel
          </a>
        </div>
      </div>

      <!-- Resumen totales -->
      <div class="row g-2 mb-3 text-center">
        <div class="col"><div class="card border-0 bg-light py-2">
          <div class="small text-muted">Base trabajadores</div>
          <div class="fw-semibold"><?= fmt((float)$pf['tot_base_jorn']+(float)$pf['tot_base_hhee']) ?></div>
        </div></div>
        <div class="col"><div class="card border-0 bg-light py-2">
          <div class="small text-muted">% Contratistas</div>
          <div class="fw-semibold"><?= fmt((float)$pf['tot_pct_jorn']+(float)$pf['tot_pct_hhee']+(float)$pf['tot_bono']) ?></div>
        </div></div>
        <div class="col"><div class="card border-0 bg-light py-2">
          <div class="small text-muted">Total Factura</div>
          <div class="fw-bold text-success"><?= fmt($pf['tot_factura']) ?></div>
        </div></div>
        <?php if ((float)$pf['descuento'] > 0): ?>
        <div class="col"><div class="card border-0 bg-light py-2">
          <div class="small text-muted">Descuentos</div>
          <div class="fw-semibold text-danger">- <?= fmt($pf['descuento']) ?></div>
        </div></div>
        <?php endif; ?>
        <div class="col"><div class="card border-0 bg-success text-white py-2">
          <div class="small">Neto a Pagar</div>
          <div class="fw-bold fs-6"><?= fmt($pf['total_neto']) ?></div>
        </div></div>
      </div>

      <!-- Detalle por contratista -->
      <?php
      try {
          $sd = db_query($conn,
              "SELECT fd.id_contratista, c.nombre AS contratista,
                      fd.cargo_nombre, fd.tarifa_nombre, fd.especial, fd.esp_nom,
                      fd.registros, fd.jornada, fd.hhee,
                      fd.base_jorn, fd.base_hhee, fd.pct_jorn, fd.pct_hhee,
                      fd.bono, fd.total
               FROM dbo.dota_factura_detalle fd
               JOIN dbo.dota_contratista c ON c.id = fd.id_contratista
               WHERE fd.id_factura = ?
               ORDER BY c.nombre, fd.cargo_nombre",
              [$id], "Detalle proforma");
          $filas = [];
          while ($rd = sqlsrv_fetch_array($sd, SQLSRV_FETCH_ASSOC)) $filas[] = $rd;
      } catch (Throwable $e) { $filas = []; }

      /* Agrupar por contratista */
      $grupos = [];
      foreach ($filas as $f) {
          $cid = (int)$f['id_contratista'];
          if (!isset($grupos[$cid])) $grupos[$cid] = ['nombre'=>$f['contratista'],'filas'=>[],'tot'=>0];
          $grupos[$cid]['filas'][] = $f;
          $grupos[$cid]['tot']    += (float)$f['total'];
      }
      ?>
      <?php foreach ($grupos as $cid_g => $grupo):
        $desc_list = $desc_by_pf[$id][$cid_g] ?? [];
        $desc_tot  = array_sum(array_column($desc_list, 'valor'));
        $neto_ct   = $grupo['tot'] - $desc_tot;
        $pf_abierta = ($pf['estado'] !== 'cerrado');
      ?>
      <div class="mb-3">
        <div class="fw-semibold small mb-1"><?= htmlspecialchars($grupo['nombre']) ?></div>
        <div class="table-responsive">
          <table class="table table-sm table-bordered align-middle small mb-0">
            <thead class="table-secondary">
              <tr>
                <th>Labor</th>
                <th>Tarifa</th>
                <th class="text-end">Reg.</th>
                <th class="text-end">Jorn.</th>
                <th class="text-end">HHEE</th>
                <th class="text-end bg-secondary bg-opacity-25">Base Jorn.</th>
                <th class="text-end bg-secondary bg-opacity-25">Base HHEE</th>
                <th class="text-end bg-primary bg-opacity-25">% Jorn.</th>
                <th class="text-end bg-primary bg-opacity-25">% HHEE</th>
                <th class="text-end bg-success bg-opacity-25 fw-bold">Total</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($grupo['filas'] as $f): ?>
              <tr class="<?= $f['especial'] ? 'table-warning':'' ?>">
                <td><?= htmlspecialchars($f['cargo_nombre']) ?>
                  <?php if ($f['especial']): ?>
                  <span class="badge bg-warning text-dark">⚡ <?= htmlspecialchars($f['esp_nom']) ?></span>
                  <?php endif; ?>
                </td>
                <td class="text-muted"><?= htmlspecialchars($f['tarifa_nombre']) ?></td>
                <td class="text-end"><?= (int)$f['registros'] ?></td>
                <td class="text-end"><?= number_format((float)$f['jornada'],2,',','.') ?></td>
                <td class="text-end"><?= number_format((float)$f['hhee'],2,',','.') ?></td>
                <td class="text-end bg-secondary bg-opacity-10"><?= fmt($f['base_jorn']) ?></td>
                <td class="text-end bg-secondary bg-opacity-10"><?= fmt($f['base_hhee']) ?></td>
                <td class="text-end bg-primary bg-opacity-10"><?= fmt($f['pct_jorn']) ?></td>
                <td class="text-end bg-primary bg-opacity-10"><?= fmt($f['pct_hhee']) ?></td>
                <td class="text-end fw-bold bg-success bg-opacity-10"><?= fmt($f['total']) ?></td>
              </tr>
              <?php endforeach; ?>
            </tbody>
            <tfoot>
              <tr class="table-secondary fw-semibold">
                <td colspan="9" class="text-end">Subtotal <?= htmlspecialchars($grupo['nombre']) ?></td>
                <td class="text-end"><?= fmt($grupo['tot']) ?></td>
              </tr>

              <?php foreach ($desc_list as $dl): ?>
              <tr class="table-danger" id="desc-row-<?= $dl['id'] ?>">
                <td colspan="8" class="text-end text-danger small">
                  Descuento<?= $dl['obs'] ? ': ' . htmlspecialchars($dl['obs']) : '' ?>
                </td>
                <td class="text-end text-danger fw-semibold">- <?= fmt($dl['valor']) ?></td>
                <td class="text-end">
                  <?php if ($pf_abierta): ?>
                  <button type="button" class="btn btn-danger btn-sm py-0 px-1"
                    onclick="eliminarDescuento(<?= $dl['id'] ?>, <?= $id ?>, this)"
                    title="Eliminar descuento">×</button>
                  <?php endif; ?>
                </td>
              </tr>
              <?php endforeach; ?>

              <?php if ($desc_tot > 0): ?>
              <tr class="table-dark fw-bold" id="neto-row-<?= $id ?>-<?= $cid_g ?>">
                <td colspan="9" class="text-end">Neto <?= htmlspecialchars($grupo['nombre']) ?></td>
                <td class="text-end text-success" id="neto-val-<?= $id ?>-<?= $cid_g ?>"><?= fmt($neto_ct) ?></td>
              </tr>
              <?php else: ?>
              <tr class="table-dark fw-semibold" id="neto-row-<?= $id ?>-<?= $cid_g ?>">
                <td colspan="9" class="text-end">Total <?= htmlspecialchars($grupo['nombre']) ?></td>
                <td class="text-end" id="neto-val-<?= $id ?>-<?= $cid_g ?>"><?= fmt($neto_ct) ?></td>
              </tr>
              <?php endif; ?>

              <?php if ($pf_abierta): ?>
              <tr>
                <td colspan="10" class="bg-light py-2">
                  <div class="d-flex gap-2 align-items-center flex-wrap" id="form-desc-<?= $id ?>-<?= $cid_g ?>">
                    <span class="small text-muted">Agregar descuento:</span>
                    <input type="number" class="form-control form-control-sm" style="width:130px"
                           min="1" step="1" placeholder="Valor"
                           id="desc-val-<?= $id ?>-<?= $cid_g ?>">
                    <input type="text" class="form-control form-control-sm" style="width:220px"
                           maxlength="200" placeholder="Observación (opcional)"
                           id="desc-obs-<?= $id ?>-<?= $cid_g ?>">
                    <button type="button" class="btn btn-outline-danger btn-sm"
                      onclick="agregarDescuento(<?= $id ?>, <?= $cid_g ?>, this)">
                      + Descuento
                    </button>
                  </div>
                </td>
              </tr>
              <?php endif; ?>
            </tfoot>
          </table>
        </div>
      </div>
      <?php endforeach; ?>
      <?php if (empty($grupos)): ?>
      <p class="text-muted small">Sin líneas de detalle.</p>
      <?php endif; ?>

    </div><!-- /body -->
  </div><!-- /collapse -->
</div><!-- /item -->
<?php endforeach; ?>
</div><!-- /accordion -->
<?php endif; ?>
</main>

<script>
function fmt(n) {
    return '$' + Math.round(n).toLocaleString('es-CL');
}

function agregarDescuento(idFac, idCont, btn) {
    var val = parseFloat(document.getElementById('desc-val-' + idFac + '-' + idCont).value);
    var obs = document.getElementById('desc-obs-' + idFac + '-' + idCont).value.trim();
    if (!val || val <= 0) { alert('Ingresa un valor mayor a 0.'); return; }

    btn.disabled = true;
    fetch('proforma_descuento.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({ accion: 'agregar', id_factura: idFac, id_contratista: idCont, valor: val, observacion: obs })
    })
    .then(r => r.json())
    .then(function(d) {
        if (!d.ok) { alert('Error: ' + d.error); btn.disabled = false; return; }

        /* Insertar fila de descuento antes del neto */
        var netoRow = document.getElementById('neto-row-' + idFac + '-' + idCont);
        var tr = document.createElement('tr');
        tr.id = 'desc-row-' + d.id;
        tr.className = 'table-danger';
        tr.innerHTML =
            '<td colspan="8" class="text-end text-danger small">Descuento' +
            (obs ? ': ' + obs.replace(/</g,'&lt;') : '') + '</td>' +
            '<td class="text-end text-danger fw-semibold">- ' + fmt(d.valor) + '</td>' +
            '<td class="text-end"><button type="button" class="btn btn-danger btn-sm py-0 px-1"' +
            ' onclick="eliminarDescuento(' + d.id + ',' + idFac + ',this)" title="Eliminar">×</button></td>';
        netoRow.parentNode.insertBefore(tr, netoRow);

        /* Actualizar neto del contratista */
        actualizarTotalesProforma(idFac, d.totales);

        /* Limpiar inputs */
        document.getElementById('desc-val-' + idFac + '-' + idCont).value = '';
        document.getElementById('desc-obs-' + idFac + '-' + idCont).value = '';
        btn.disabled = false;
    })
    .catch(function() { alert('Error de red.'); btn.disabled = false; });
}

function eliminarDescuento(idDesc, idFac, btn) {
    if (!confirm('¿Eliminar este descuento?')) return;
    btn.disabled = true;
    fetch('proforma_descuento.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({ accion: 'eliminar', id: idDesc, id_factura: idFac })
    })
    .then(r => r.json())
    .then(function(d) {
        if (!d.ok) { alert('Error: ' + d.error); btn.disabled = false; return; }
        var row = document.getElementById('desc-row-' + idDesc);
        if (row) row.remove();
        actualizarTotalesProforma(idFac, d.totales);
    })
    .catch(function() { alert('Error de red.'); btn.disabled = false; });
}

function actualizarTotalesProforma(idFac, totales) {
    /* Actualiza el badge de total neto en el header del accordion */
    var badge = document.querySelector('#pfc' + idFac + ' ~ * .text-success.fw-bold, #pfc' + idFac + ' .text-success.fw-bold');
    /* Actualiza la tarjeta Neto a Pagar */
    var cards = document.querySelectorAll('#pfc' + idFac + ' .accordion-body .card');
    cards.forEach(function(c) {
        if (c.querySelector('.small')?.textContent.trim() === 'Neto a Pagar') {
            c.querySelector('.fw-bold').textContent = fmt(totales.total_neto);
        }
        if (c.querySelector('.small')?.textContent.trim() === 'Descuentos') {
            c.querySelector('.fw-semibold').textContent = '- ' + fmt(totales.descuento);
        }
    });
    /* Actualiza el header del accordion */
    var hdr = document.querySelector('[data-bs-target="#pfc' + idFac + '"] .ms-auto');
    if (hdr) hdr.textContent = fmt(totales.total_neto);
}
</script>

<?php include __DIR__ . '/../partials/footer.php'; ?>
