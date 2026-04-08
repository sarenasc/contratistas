<?php
require_once __DIR__ . '/../_bootstrap.php';
require_once __DIR__ . '/../../app/lib/db.php';

$title       = "Descuentos";
$flash_error = null;
$flash_ok    = null;

/* ── Guardar nuevo ── */
if (isset($_POST['guardar'])) {
    $id_cont = (int)$_POST['id_contratista'];
    $valor   = (float)str_replace(['.', ','], ['', '.'], $_POST['valor'] ?? '');
    $fecha   = $_POST['fecha']       ?? '';
    $obs     = mb_substr(trim($_POST['observacion'] ?? ''), 0, 500);

    if ($id_cont <= 0 || $valor <= 0 || !$fecha) {
        $flash_error = "Contratista, valor y fecha son obligatorios.";
    } else {
        $r = sqlsrv_query($conn,
            "INSERT INTO dbo.dota_descuento (id_contratista, valor, fecha, observacion)
             VALUES (?, ?, ?, ?)",
            [$id_cont, $valor, $fecha, $obs ?: null]);
        if ($r) { $flash_ok = "Descuento registrado."; }
        else    { $flash_error = "Error al guardar."; }
    }
}

/* ── Editar existente ── */
if (isset($_POST['editar'])) {
    $id      = (int)$_POST['id'];
    $id_cont = (int)$_POST['id_contratista'];
    $valor   = (float)str_replace(['.', ','], ['', '.'], $_POST['valor'] ?? '');
    $fecha   = $_POST['fecha']       ?? '';
    $obs     = mb_substr(trim($_POST['observacion'] ?? ''), 0, 500);

    if ($id <= 0 || $id_cont <= 0 || $valor <= 0 || !$fecha) {
        $flash_error = "Datos inválidos.";
    } else {
        $r = sqlsrv_query($conn,
            "UPDATE dbo.dota_descuento
             SET id_contratista=?, valor=?, fecha=?, observacion=?
             WHERE id=?",
            [$id_cont, $valor, $fecha, $obs ?: null, $id]);
        if ($r) { $flash_ok = "Descuento actualizado."; }
        else    { $flash_error = "Error al actualizar."; }
    }
}

/* ── Eliminar ── */
if (isset($_POST['eliminar'])) {
    $id = (int)$_POST['id'];
    if ($id > 0) {
        sqlsrv_query($conn, "DELETE FROM dbo.dota_descuento WHERE id=?", [$id]);
        $flash_ok = "Descuento eliminado.";
    }
}

/* ── Cargar datos ── */
$contratistas = [];
$qc = sqlsrv_query($conn, "SELECT id, nombre FROM dbo.dota_contratista ORDER BY nombre");
while ($r = sqlsrv_fetch_array($qc, SQLSRV_FETCH_ASSOC)) $contratistas[] = $r;

$registros = [];
$qr = sqlsrv_query($conn,
    "SELECT d.id, d.id_contratista, c.nombre AS contratista,
            d.valor, d.fecha, d.observacion
     FROM dbo.dota_descuento d
     JOIN dbo.dota_contratista c ON c.id = d.id_contratista
     ORDER BY d.fecha DESC, c.nombre");
while ($r = sqlsrv_fetch_array($qr, SQLSRV_FETCH_ASSOC)) $registros[] = $r;

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

<!-- Formulario agregar -->
<div class="card shadow-sm mb-4">
  <div class="card-header fw-semibold">Agregar Descuento</div>
  <div class="card-body">
    <form method="POST" class="row g-3">
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
      <div class="col-md-2">
        <label class="form-label small">Fecha</label>
        <input type="date" name="fecha" class="form-control form-control-sm" required>
      </div>
      <div class="col-md-4">
        <label class="form-label small">Observación</label>
        <input type="text" name="observacion" class="form-control form-control-sm"
               maxlength="500">
      </div>
      <div class="col-md-1 d-flex align-items-end">
        <button type="submit" name="guardar" class="btn btn-primary btn-sm w-100">Guardar</button>
      </div>
    </form>
  </div>
</div>

<!-- Tabla de registros -->
<?php if (empty($registros)): ?>
<div class="alert alert-info">No hay descuentos registrados.</div>
<?php else: ?>
<div class="card shadow-sm">
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table table-sm table-bordered align-middle small mb-0">
        <thead class="table-dark">
          <tr>
            <th>Contratista</th>
            <th class="text-end">Valor</th>
            <th>Fecha</th>
            <th>Observación</th>
            <th style="width:180px"></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($registros as $r): ?>
          <tr>
            <form method="POST">
              <input type="hidden" name="id" value="<?= $r['id'] ?>">
              <td>
                <select name="id_contratista" class="form-select form-select-sm">
                  <?php foreach ($contratistas as $c): ?>
                  <option value="<?= $c['id'] ?>"
                    <?= $c['id'] == $r['id_contratista'] ? 'selected' : '' ?>>
                    <?= htmlspecialchars($c['nombre']) ?>
                  </option>
                  <?php endforeach; ?>
                </select>
              </td>
              <td class="text-end">
                <input type="number" name="valor"
                       value="<?= (float)$r['valor'] ?>"
                       class="form-control form-control-sm text-end" min="1" step="1"
                       style="width:110px">
              </td>
              <td>
                <input type="date" name="fecha"
                       value="<?= $r['fecha'] instanceof DateTime ? $r['fecha']->format('Y-m-d') : substr((string)$r['fecha'],0,10) ?>"
                       class="form-control form-control-sm">
              </td>
              <td>
                <input type="text" name="observacion"
                       value="<?= htmlspecialchars($r['observacion'] ?? '') ?>"
                       class="form-control form-control-sm" maxlength="500">
              </td>
              <td>
                <button type="submit" name="editar"
                        class="btn btn-warning btn-sm">Actualizar</button>
                <button type="submit" name="eliminar"
                        class="btn btn-danger btn-sm"
                        onclick="return confirm('¿Eliminar este descuento?')">
                  Eliminar
                </button>
              </td>
            </form>
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
