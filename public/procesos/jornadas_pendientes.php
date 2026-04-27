<?php
require_once __DIR__ . '/../_bootstrap.php';
require_once __DIR__ . '/../../app/lib/db.php';

$title = "Jornadas Pendientes";

$filtro_semana = isset($_GET['semana']) ? (int)$_GET['semana'] : (int)date('W');
$filtro_anio   = isset($_GET['anio'])   ? (int)$_GET['anio']   : (int)date('Y');

$contratistas = [];
$cargos       = [];
$turnos_cat   = [];
$especies     = [];
$pendientes   = [];
$flash_error  = null;

try {
    $s = db_query($conn, "SELECT id, nombre FROM dbo.dota_contratista ORDER BY nombre");
    while ($r = sqlsrv_fetch_array($s, SQLSRV_FETCH_ASSOC)) $contratistas[] = $r;
} catch (Throwable $e) { $flash_error = $e->getMessage(); }

try {
    $s = db_query($conn, "SELECT id_cargo, cargo FROM dbo.Dota_Cargo ORDER BY cargo");
    while ($r = sqlsrv_fetch_array($s, SQLSRV_FETCH_ASSOC)) $cargos[] = $r;
} catch (Throwable $ignore) {}

try {
    $s = db_query($conn, "SELECT nombre_turno FROM dbo.dota_turno ORDER BY nombre_turno");
    while ($r = sqlsrv_fetch_array($s, SQLSRV_FETCH_ASSOC)) $turnos_cat[] = $r['nombre_turno'];
} catch (Throwable $ignore) {}

try {
    $s = db_query($conn, "SELECT especie FROM dbo.especie ORDER BY especie");
    while ($r = sqlsrv_fetch_array($s, SQLSRV_FETCH_ASSOC)) $especies[] = $r['especie'];
} catch (Throwable $ignore) {}

try {
    $s = db_query($conn,
        "SELECT jp.id, jp.rut, jp.nombre, jp.jornada, jp.hhee, jp.especie, jp.turno,
                jp.fecha, jp.semana_original, jp.anio_original, jp.obs,
                dc.cargo AS cargo_nombre, c.nombre AS contratista_nombre
         FROM dbo.dota_jornadas_pendientes jp
         JOIN dbo.Dota_Cargo dc       ON dc.id_cargo = jp.id_cargo
         JOIN dbo.dota_contratista c  ON c.id        = jp.id_contratista
         WHERE jp.semana_factura = ? AND jp.anio_factura = ?
         ORDER BY c.nombre, jp.fecha, jp.nombre",
        [$filtro_semana, $filtro_anio]);
    while ($r = sqlsrv_fetch_array($s, SQLSRV_FETCH_ASSOC)) {
        $r['fecha_str'] = ($r['fecha'] instanceof DateTime)
            ? $r['fecha']->format('Y-m-d')
            : substr((string)$r['fecha'], 0, 10);
        $pendientes[] = $r;
    }
} catch (Throwable $e) { $flash_error = $e->getMessage(); }

include __DIR__ . '/../partials/header.php';
include __DIR__ . '/../partials/navbar_wrapper.php';
?>

<main class="container-fluid py-4 px-3">

<h4 class="mb-3">Jornadas Pendientes</h4>
<p class="text-muted small mb-4">
  Registra manualmente jornadas de semanas anteriores que no estaban en el archivo de asistencia.
  Se incluirán en la Pre-Factura de la semana de facturación elegida.
</p>

<?php if ($flash_error): ?>
<div class="alert alert-danger"><?= htmlspecialchars($flash_error) ?></div>
<?php endif; ?>

<!-- FILTRO SEMANA -->
<div class="card shadow-sm mb-4">
  <div class="card-header fw-semibold">Semana de Facturación</div>
  <div class="card-body">
    <form method="GET" class="row g-2 align-items-end">
      <div class="col-auto">
        <label class="form-label">Semana</label>
        <input type="number" name="semana" class="form-control" min="1" max="53" value="<?= $filtro_semana ?>">
      </div>
      <div class="col-auto">
        <label class="form-label">Año</label>
        <input type="number" name="anio" class="form-control" min="2020" max="2100" value="<?= $filtro_anio ?>">
      </div>
      <div class="col-auto">
        <button class="btn btn-primary">Filtrar</button>
      </div>
    </form>
  </div>
</div>

<!-- FORM AGREGAR -->
<div class="card shadow-sm mb-4">
  <div class="card-header fw-semibold">Agregar Jornada Pendiente — se facturará en semana <?= $filtro_semana ?>/<?= $filtro_anio ?></div>
  <div class="card-body">
    <div id="form-msg" class="mb-2" style="display:none"></div>
    <div class="row g-3">

      <div class="col-12 col-md-4">
        <label class="form-label">Contratista <span class="text-danger">*</span></label>
        <select id="f-contratista" class="form-select form-select-sm">
          <option value="">— Seleccionar —</option>
          <?php foreach ($contratistas as $ct): ?>
          <option value="<?= (int)$ct['id'] ?>"><?= htmlspecialchars($ct['nombre']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="col-12 col-md-4">
        <label class="form-label">Labor / Cargo <span class="text-danger">*</span></label>
        <select id="f-cargo" class="form-select form-select-sm">
          <option value="">— Seleccionar —</option>
          <?php foreach ($cargos as $cg): ?>
          <option value="<?= (int)$cg['id_cargo'] ?>"><?= htmlspecialchars($cg['cargo']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="col-6 col-md-2">
        <label class="form-label">RUT</label>
        <input type="text" id="f-rut" class="form-control form-control-sm" placeholder="12345678-9">
      </div>

      <div class="col-12 col-md-5">
        <label class="form-label">Nombre trabajador</label>
        <input type="text" id="f-nombre" class="form-control form-control-sm" placeholder="Nombre completo">
      </div>

      <div class="col-6 col-md-2">
        <label class="form-label">Fecha original <span class="text-danger">*</span></label>
        <input type="date" id="f-fecha" class="form-control form-control-sm">
      </div>
      <div class="col-12 col-md-3">
        <label class="form-label text-muted small">Semana original (calculada)</label>
        <input type="text" id="f-sem-orig" class="form-control form-control-sm bg-light" readonly
               placeholder="Se calcula al elegir fecha">
      </div>

      <div class="col-6 col-md-2">
        <label class="form-label">Turno</label>
        <?php if (!empty($turnos_cat)): ?>
        <select id="f-turno" class="form-select form-select-sm">
          <option value="">—</option>
          <?php foreach ($turnos_cat as $t): ?>
          <option value="<?= htmlspecialchars($t) ?>"><?= htmlspecialchars($t) ?></option>
          <?php endforeach; ?>
        </select>
        <?php else: ?>
        <input type="text" id="f-turno" class="form-control form-control-sm" placeholder="Ej: Día">
        <?php endif; ?>
      </div>

      <div class="col-6 col-md-2">
        <label class="form-label">Especie</label>
        <?php if (!empty($especies)): ?>
        <select id="f-especie" class="form-select form-select-sm">
          <option value="">—</option>
          <?php foreach ($especies as $e): ?>
          <option value="<?= htmlspecialchars($e) ?>"><?= htmlspecialchars($e) ?></option>
          <?php endforeach; ?>
        </select>
        <?php else: ?>
        <input type="text" id="f-especie" class="form-control form-control-sm" placeholder="Especie">
        <?php endif; ?>
      </div>

      <div class="col-4 col-md-1">
        <label class="form-label">Jornada</label>
        <input type="number" id="f-jornada" class="form-control form-control-sm"
               min="0" max="1" step="0.01" value="1">
      </div>

      <div class="col-4 col-md-1">
        <label class="form-label">HHEE</label>
        <input type="number" id="f-hhee" class="form-control form-control-sm"
               min="0" step="0.5" value="0">
      </div>

      <div class="col-12 col-md-4">
        <label class="form-label">Observación</label>
        <input type="text" id="f-obs" class="form-control form-control-sm" placeholder="Opcional">
      </div>

      <div class="col-12">
        <button id="btn-guardar" class="btn btn-success">Guardar registro</button>
      </div>
    </div>
  </div>
</div>

<!-- TABLA REGISTROS -->
<div class="card shadow-sm">
  <div class="card-header fw-semibold">
    Pendientes semana <?= $filtro_semana ?> / <?= $filtro_anio ?>
    <span class="badge bg-secondary ms-2"><?= count($pendientes) ?></span>
  </div>
  <div class="card-body p-0">
    <?php if (empty($pendientes)): ?>
    <div class="p-3 text-muted small">No hay registros pendientes para esta semana de facturación.</div>
    <?php else: ?>
    <div class="table-responsive">
      <table class="table table-sm table-bordered table-hover align-middle mb-0 small">
        <thead class="table-dark">
          <tr>
            <th>Contratista</th>
            <th>Labor</th>
            <th>RUT</th>
            <th>Trabajador</th>
            <th>Fecha orig.</th>
            <th>Sem. orig.</th>
            <th>Turno</th>
            <th>Especie</th>
            <th class="text-end">Jorn.</th>
            <th class="text-end">HHEE</th>
            <th>Obs.</th>
            <th></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($pendientes as $p): ?>
          <tr>
            <td><?= htmlspecialchars($p['contratista_nombre']) ?></td>
            <td><?= htmlspecialchars($p['cargo_nombre']) ?></td>
            <td><?= htmlspecialchars($p['rut']) ?></td>
            <td><?= htmlspecialchars($p['nombre']) ?></td>
            <td><?= $p['fecha_str'] ?></td>
            <td>S<?= $p['semana_original'] ?>/<?= $p['anio_original'] ?></td>
            <td><?= htmlspecialchars($p['turno'] ?? '') ?></td>
            <td><?= htmlspecialchars($p['especie'] ?? '') ?></td>
            <td class="text-end"><?= number_format((float)$p['jornada'], 2) ?></td>
            <td class="text-end"><?= number_format((float)$p['hhee'], 2) ?></td>
            <td class="text-muted"><?= htmlspecialchars($p['obs'] ?? '') ?></td>
            <td>
              <button class="btn btn-outline-danger btn-sm py-0 px-1 btn-eliminar"
                      data-id="<?= (int)$p['id'] ?>">✕</button>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php endif; ?>
  </div>
</div>

</main>

<script>
(function () {
  // Calcular semana ISO desde la fecha seleccionada
  function isoWeek(dateStr) {
    var d = new Date(dateStr);
    d.setHours(0, 0, 0, 0);
    d.setDate(d.getDate() + 3 - ((d.getDay() + 6) % 7));
    var week1 = new Date(d.getFullYear(), 0, 4);
    var week = 1 + Math.round(((d - week1) / 86400000 - 3 + (week1.getDay() + 6) % 7) / 7);
    var year = d.getFullYear();
    // Ajustar año ISO
    var jan4 = new Date(year, 0, 4);
    var firstMon = new Date(jan4 - ((jan4.getDay() + 6) % 7) * 86400000);
    if (new Date(dateStr) < firstMon) year--;
    return 'S' + week + '/' + year;
  }

  document.getElementById('f-fecha').addEventListener('change', function () {
    if (this.value) {
      document.getElementById('f-sem-orig').value = isoWeek(this.value);
    } else {
      document.getElementById('f-sem-orig').value = '';
    }
  });

  document.getElementById('btn-guardar').addEventListener('click', function () {
    var cont    = document.getElementById('f-contratista').value;
    var cargo   = document.getElementById('f-cargo').value;
    var rut     = document.getElementById('f-rut').value.trim();
    var nombre  = document.getElementById('f-nombre').value.trim();
    var fecha   = document.getElementById('f-fecha').value;
    var turno   = document.getElementById('f-turno').value.trim();
    var especie = document.getElementById('f-especie').value.trim();
    var jorn    = parseFloat(document.getElementById('f-jornada').value) || 0;
    var hhee    = parseFloat(document.getElementById('f-hhee').value)    || 0;
    var obs     = document.getElementById('f-obs').value.trim();

    if (!cont || !cargo || !fecha) {
      alert('Contratista, cargo y fecha son obligatorios.');
      return;
    }

    var btn = this;
    btn.disabled = true;
    btn.textContent = 'Guardando…';

    fetch('jornadas_pendientes_ajax.php', {
      method:  'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        accion:         'guardar',
        id_contratista: parseInt(cont),
        id_cargo:       parseInt(cargo),
        rut, nombre, fecha, turno, especie,
        jornada:        jorn,
        hhee:           hhee,
        obs,
        semana_factura: <?= $filtro_semana ?>,
        anio_factura:   <?= $filtro_anio ?>,
      })
    })
    .then(function (r) { return r.json(); })
    .then(function (res) {
      btn.disabled = false;
      btn.textContent = 'Guardar registro';
      var msg = document.getElementById('form-msg');
      msg.style.display = '';
      if (res.ok) {
        msg.innerHTML = '<div class="alert alert-success py-1 mb-0">✓ Registro guardado.</div>';
        setTimeout(function () { location.reload(); }, 700);
      } else {
        msg.innerHTML = '<div class="alert alert-danger py-1 mb-0">' + (res.error || 'Error') + '</div>';
      }
    })
    .catch(function () {
      btn.disabled = false;
      btn.textContent = 'Guardar registro';
      var msg = document.getElementById('form-msg');
      msg.style.display = '';
      msg.innerHTML = '<div class="alert alert-danger py-1 mb-0">Error de red.</div>';
    });
  });

  document.querySelectorAll('.btn-eliminar').forEach(function (btn) {
    btn.addEventListener('click', function () {
      if (!confirm('¿Eliminar este registro?')) return;
      fetch('jornadas_pendientes_ajax.php', {
        method:  'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ accion: 'eliminar', id: parseInt(btn.dataset.id) })
      })
      .then(function (r) { return r.json(); })
      .then(function (res) {
        if (res.ok) location.reload();
        else alert(res.error || 'Error al eliminar.');
      });
    });
  });
})();
</script>

<?php include __DIR__ . '/../partials/footer.php'; ?>
