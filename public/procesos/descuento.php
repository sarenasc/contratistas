<?php
require_once __DIR__ . '/../_bootstrap.php';
require_once __DIR__ . '/../../app/lib/db.php';

$title       = "Descuentos";
$flash_error = null;
$flash_ok    = null;

/* ── Recalcula descuento y total_neto en dota_factura ── */
function recalc_factura($conn, int $id_factura): void {
    sqlsrv_query($conn,
        "UPDATE dbo.dota_factura
         SET descuento  = (SELECT ISNULL(SUM(valor),0) FROM dbo.dota_factura_descuento WHERE id_factura = f.id),
             total_neto = tot_factura
                        - (SELECT ISNULL(SUM(valor),0) FROM dbo.dota_factura_descuento WHERE id_factura = f.id)
         FROM dbo.dota_factura f
         WHERE f.id = ?",
        [$id_factura]);
}

/* ── Guardar nuevo ── */
if (isset($_POST['guardar'])) {
    $id_factura = (int)($_POST['id_factura']     ?? 0);
    $id_cont    = (int)($_POST['id_contratista'] ?? 0);
    $valor      = (float)str_replace(['.', ','], ['', '.'], $_POST['valor'] ?? '');
    $obs        = mb_substr(trim($_POST['observacion'] ?? ''), 0, 500);

    if ($id_factura <= 0 || $id_cont <= 0 || $valor <= 0) {
        $flash_error = "Proforma, contratista y valor son obligatorios.";
    } else {
        $r = sqlsrv_query($conn,
            "INSERT INTO dbo.dota_factura_descuento (id_factura, id_contratista, valor, observacion)
             VALUES (?, ?, ?, ?)",
            [$id_factura, $id_cont, $valor, $obs ?: null]);
        if ($r) {
            recalc_factura($conn, $id_factura);
            $flash_ok = "Descuento registrado.";
        } else {
            $flash_error = "Error al guardar: " . (sqlsrv_errors()[0]['message'] ?? '');
        }
    }
}

/* ── Editar existente ── */
if (isset($_POST['editar'])) {
    $id         = (int)($_POST['id']             ?? 0);
    $id_factura = (int)($_POST['id_factura']     ?? 0);
    $id_cont    = (int)($_POST['id_contratista'] ?? 0);
    $valor      = (float)str_replace(['.', ','], ['', '.'], $_POST['valor'] ?? '');
    $obs        = mb_substr(trim($_POST['observacion'] ?? ''), 0, 500);

    if ($id <= 0 || $id_factura <= 0 || $id_cont <= 0 || $valor <= 0) {
        $flash_error = "Datos inválidos.";
    } else {
        $r = sqlsrv_query($conn,
            "UPDATE dbo.dota_factura_descuento
             SET id_contratista=?, valor=?, observacion=?
             WHERE id=? AND id_factura=?",
            [$id_cont, $valor, $obs ?: null, $id, $id_factura]);
        if ($r) {
            recalc_factura($conn, $id_factura);
            $flash_ok = "Descuento actualizado.";
        } else {
            $flash_error = "Error al actualizar.";
        }
    }
}

/* ── Eliminar ── */
if (isset($_POST['eliminar'])) {
    $id         = (int)($_POST['id']         ?? 0);
    $id_factura = (int)($_POST['id_factura'] ?? 0);
    if ($id > 0 && $id_factura > 0) {
        sqlsrv_query($conn,
            "DELETE FROM dbo.dota_factura_descuento WHERE id=? AND id_factura=?",
            [$id, $id_factura]);
        recalc_factura($conn, $id_factura);
        $flash_ok = "Descuento eliminado.";
    }
}

/* ── Catálogos ── */
$contratistas = [];
$qc = sqlsrv_query($conn, "SELECT id, nombre FROM dbo.dota_contratista ORDER BY nombre");
while ($r = sqlsrv_fetch_array($qc, SQLSRV_FETCH_ASSOC)) $contratistas[] = $r;

$proformas = [];
$qp = sqlsrv_query($conn,
    "SELECT id, semana, anio, version, estado
     FROM dbo.dota_factura
     ORDER BY anio DESC, semana DESC, version DESC");
while ($r = sqlsrv_fetch_array($qp, SQLSRV_FETCH_ASSOC)) $proformas[] = $r;

/* ── Registros existentes ── */
$registros = [];
$qr = sqlsrv_query($conn,
    "SELECT fd.id, fd.id_factura, fd.id_contratista, fd.valor, fd.observacion,
            f.semana, f.anio, f.version, f.estado,
            c.nombre AS contratista
     FROM dbo.dota_factura_descuento fd
     JOIN dbo.dota_factura f     ON f.id  = fd.id_factura
     JOIN dbo.dota_contratista c ON c.id  = fd.id_contratista
     ORDER BY f.anio DESC, f.semana DESC, f.version DESC, c.nombre");
if ($qr) while ($r = sqlsrv_fetch_array($qr, SQLSRV_FETCH_ASSOC)) $registros[] = $r;

include __DIR__ . '/../partials/header.php';
include __DIR__ . '/../partials/navbar_wrapper.php';
?>

<main class="container-fluid py-4 px-3">
<h4 class="mb-4 fw-bold">Gestión de Descuentos</h4>

<?php if ($flash_error): ?>
<div class="alert alert-danger"><?= htmlspecialchars($flash_error) ?></div>
<?php endif; ?>
<?php if ($flash_ok): ?>
<div class="alert alert-success"><?= htmlspecialchars($flash_ok) ?></div>
<?php endif; ?>

<!-- ── Formulario agregar ── -->
<div class="card shadow-sm mb-4">
  <div class="card-header fw-semibold">Agregar Descuento</div>
  <div class="card-body">
    <form method="POST" class="row g-3">
      <?= csrf_field() ?>
      <div class="col-md-3">
        <label class="form-label small">Proforma</label>
        <select name="id_factura" class="form-select form-select-sm" required>
          <option value="">Seleccionar…</option>
          <?php foreach ($proformas as $pf): ?>
          <option value="<?= $pf['id'] ?>">
            Sem <?= $pf['semana'] ?>/<?= $pf['anio'] ?> — v<?= $pf['version'] ?>
            <?= $pf['estado'] === 'cerrado' ? '🔒' : '' ?>
          </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-3">
        <label class="form-label small">Contratista</label>
        <select name="id_contratista" class="form-select form-select-sm" required>
          <option value="">Seleccionar…</option>
          <?php foreach ($contratistas as $c): ?>
          <option value="<?= $c['id'] ?>"><?= htmlspecialchars($c['nombre']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-2">
        <label class="form-label small">Valor</label>
        <input type="number" name="valor" class="form-control form-control-sm"
               min="1" step="1" required>
      </div>
      <div class="col-md-3">
        <label class="form-label small">Observación</label>
        <input type="text" name="observacion" class="form-control form-control-sm"
               maxlength="500" placeholder="opcional">
      </div>
      <div class="col-md-1 d-flex align-items-end">
        <button type="submit" name="guardar" class="btn btn-primary btn-sm w-100">Guardar</button>
      </div>
    </form>
  </div>
</div>

<!-- ── Tabla de registros ── -->
<?php if (empty($registros)): ?>
<div class="alert alert-info">No hay descuentos registrados.</div>
<?php else: ?>
<div class="card shadow-sm">
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table table-sm table-bordered align-middle small mb-0">
        <thead class="table-dark">
          <tr>
            <th>Proforma</th>
            <th>Estado</th>
            <th>Contratista</th>
            <th class="text-end">Valor</th>
            <th>Observación</th>
            <th style="width:160px"></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($registros as $r):
            $fid = 'fdesc-' . $r['id'];
          ?>
          <form id="<?= $fid ?>" method="POST">
            <input type="hidden" form="<?= $fid ?>" name="id"         value="<?= $r['id'] ?>">
            <input type="hidden" form="<?= $fid ?>" name="id_factura" value="<?= $r['id_factura'] ?>">
          </form>
          <tr>
            <td>Sem <?= $r['semana'] ?>/<?= $r['anio'] ?> v<?= $r['version'] ?></td>
            <td>
              <?php if ($r['estado'] === 'cerrado'): ?>
                <span class="badge bg-dark">Cerrada</span>
              <?php else: ?>
                <span class="badge bg-warning text-dark">En proceso</span>
              <?php endif; ?>
            </td>
            <td>
              <select name="id_contratista" form="<?= $fid ?>" class="form-select form-select-sm">
                <?php foreach ($contratistas as $c): ?>
                <option value="<?= $c['id'] ?>"
                  <?= $c['id'] == $r['id_contratista'] ? 'selected' : '' ?>>
                  <?= htmlspecialchars($c['nombre']) ?>
                </option>
                <?php endforeach; ?>
              </select>
            </td>
            <td class="text-end">
              <input type="number" name="valor" form="<?= $fid ?>"
                     value="<?= (int)$r['valor'] ?>"
                     class="form-control form-control-sm text-end" min="1" step="1"
                     style="width:120px">
            </td>
            <td>
              <input type="text" name="observacion" form="<?= $fid ?>"
                     value="<?= htmlspecialchars($r['observacion'] ?? '') ?>"
                     class="form-control form-control-sm" maxlength="500">
            </td>
            <td>
              <button type="submit" name="editar" form="<?= $fid ?>"
                      class="btn btn-warning btn-sm">Actualizar</button>
              <button type="submit" name="eliminar" form="<?= $fid ?>"
                      class="btn btn-danger btn-sm"
                      onclick="return confirm('¿Eliminar este descuento?')">Eliminar</button>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>
<?php endif; ?>
</main>

<?php include __DIR__ . '/../partials/footer.php'; ?>
