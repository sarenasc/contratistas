<?php

require_once __DIR__ . '/../_bootstrap.php';
require_once __DIR__ . '/../../app/lib/db.php';
require_once __DIR__ . '/../../app/lib/validators.php';

$flash_error = null;
$flash_ok    = null;

// Agregar un nuevo registro
if (isset($_POST['guardar'])) {
    $nombre_tarifa = $_POST['nombre'];
    $valor_base    = normalize_decimal_or_null($_POST['valor_base']);
    $base_hhee     = normalize_decimal_or_null($_POST['base_hhee']);
    $fecha         = $_POST['fecha'];
    $porc_base     = normalize_decimal_or_null($_POST['porcentaje_base']);
    $porce_hhee    = normalize_decimal_or_null($_POST['por_hhee']);

    $check = sqlsrv_query($conn, "SELECT COUNT(*) as count FROM [dbo].[Dota_Tarifa_Especiales] WHERE fecha = ?", [$fecha]);
    $chk   = sqlsrv_fetch_array($check, SQLSRV_FETCH_ASSOC);
    if ($chk['count'] > 0) {
        $flash_error = 'La fecha ya está registrada.';
    } else {
        $sql = "INSERT INTO [dbo].[Dota_Tarifa_Especiales] (tipo_tarifa,fecha,valor_base,HH_EE_base,porc_contratista,porc_hhee) VALUES (?,?,?,?,?,?)";
        sqlsrv_query($conn, $sql, [$nombre_tarifa,$fecha,$valor_base,$base_hhee,$porc_base,$porce_hhee]);
        // Exclusiones del nuevo registro
        $nuevo_id = null;
        $qId = sqlsrv_query($conn, "SELECT TOP 1 id_tipo FROM dbo.Dota_Tarifa_Especiales WHERE fecha=? ORDER BY id_tipo DESC", [$fecha]);
        if ($qId && $rId = sqlsrv_fetch_array($qId, SQLSRV_FETCH_ASSOC)) $nuevo_id = (int)$rId['id_tipo'];
        if ($nuevo_id && isset($_POST['ids_cargo'])) {
            foreach ($_POST['ids_cargo'] as $ic) {
                sqlsrv_query($conn, "INSERT INTO dbo.Dota_Tarifa_Excluidos (id_tipo,id_cargo) VALUES (?,?)", [$nuevo_id, (int)$ic]);
            }
        }
        $flash_ok = "Registro Guardado Exitosamente";
    }
}

// Editar un registro existente
if (isset($_POST['editar'])) {
    $id_tipo       = $_POST['id_tipo'];
    $nombre_tarifa = $_POST['nombre'];
    $valor_base    = normalize_decimal_or_null($_POST['valor_base']);
    $base_hhee     = normalize_decimal_or_null($_POST['base_hhee']);
    $fecha         = $_POST['fecha'];
    $porc_base     = normalize_decimal_or_null($_POST['porcentaje_base']);
    $porce_hhee    = normalize_decimal_or_null($_POST['por_hhee']);

    $check = sqlsrv_query($conn, "SELECT COUNT(*) as count FROM [dbo].[Dota_Tarifa_Especiales] WHERE fecha = ? AND id_tipo != ?", [$fecha, (int)$id_tipo]);
    $chk   = sqlsrv_fetch_array($check, SQLSRV_FETCH_ASSOC);
    if ($chk['count'] > 0) {
        $flash_error = 'La fecha ya está registrada.';
    } else {
        $sql = "UPDATE [dbo].[Dota_Tarifa_Especiales] SET tipo_tarifa=?,fecha=?,valor_base=?,HH_EE_base=?,porc_contratista=?,porc_hhee=? WHERE id_tipo=?";
        sqlsrv_query($conn, $sql, [$nombre_tarifa,$fecha,$valor_base,$base_hhee,$porc_base,$porce_hhee,$id_tipo]);
        $flash_ok = "Registro actualizado exitosamente.";
    }
}

// Eliminar un registro
if (isset($_POST['eliminar'])) {
    $id_tipo = (int)$_POST['id_tipo'];
    sqlsrv_query($conn, "DELETE FROM [dbo].[Dota_Tarifa_Especiales] WHERE id_tipo=?", [$id_tipo]);
    $flash_ok = "Registro Eliminado Exitosamente";
}

// Guardar exclusiones de cargos para una tarifa (reemplaza las existentes)
if (isset($_POST['guardar_exclusiones_tarifa'])) {
    $id_tipo   = (int)$_POST['id_tipo'];
    $ids_cargo = isset($_POST['ids_cargo']) ? $_POST['ids_cargo'] : [];
    sqlsrv_query($conn, "DELETE FROM dbo.Dota_Tarifa_Excluidos WHERE id_tipo=?", [$id_tipo]);
    foreach ($ids_cargo as $ic) {
        sqlsrv_query($conn,
            "INSERT INTO dbo.Dota_Tarifa_Excluidos (id_tipo,id_cargo) VALUES (?,?)",
            [$id_tipo, (int)$ic]);
    }
    $flash_ok = "Exclusiones guardadas.";
}

// Consultar registros de tarifas
$query = sqlsrv_query($conn,
    "SELECT [id_tipo],[tipo_tarifa],[fecha],[valor_base],[HH_EE_base],[porc_contratista],[porc_hhee]
     FROM [dbo].[Dota_Tarifa_Especiales]");
if ($query === false) {
    $flash_error = "Error al consultar tarifas: " . htmlspecialchars(sqlsrv_errors()[0]['message'] ?? 'desconocido');
}

// Cargos disponibles para el select de exclusión
$cargos_list = [];
$qCar = sqlsrv_query($conn, "SELECT id_cargo, cargo FROM dbo.Dota_Cargo ORDER BY cargo");
while ($qCar && $r = sqlsrv_fetch_array($qCar, SQLSRV_FETCH_ASSOC)) $cargos_list[] = $r;

// Exclusiones agrupadas por id_tipo
$excl_por_tipo = [];
$qExc = sqlsrv_query($conn, "SELECT id_tipo, id_cargo FROM dbo.Dota_Tarifa_Excluidos");
while ($qExc && $r = sqlsrv_fetch_array($qExc, SQLSRV_FETCH_ASSOC)) {
    $excl_por_tipo[(int)$r['id_tipo']][] = (int)$r['id_cargo'];
}

$title = "Tarifas Especiales";
include __DIR__ . '/../partials/header.php';
include __DIR__ . '/../partials/navbar_wrapper.php';
?>

<main class="container-fluid py-4 px-3">

<?php if ($flash_error): ?>
  <div class="alert alert-danger"><?= htmlspecialchars($flash_error) ?></div>
<?php endif; ?>
<?php if ($flash_ok): ?>
  <div class="alert alert-success"><?= htmlspecialchars($flash_ok) ?></div>
<?php endif; ?>

<div class="row justify-content-center">
  <div class="col-12 col-lg-10 col-xl-8">

    <h1 class="text-center mb-4">Gestión de <?= $title ?></h1>

    <div class="card mb-4">
      <div class="card-header">Agregar Nuevo Tipo Tarifa</div>
      <div class="card-body">
        <form method="POST">
          <div class="row g-3">
            <div class="col-12 col-md-6">
              <label>Nombre Tarifa</label>
              <input type="text" class="form-control" name="nombre" required>
            </div>
            <div class="col-12 col-md-6">
              <label>Valor Base</label>
              <input type="number" step="any" class="form-control" name="valor_base" required>
            </div>
            <div class="col-12 col-md-6">
              <label>Valor Base HHEE</label>
              <input type="number" step="any" class="form-control" name="base_hhee" required>
            </div>
            <div class="col-12 col-md-6">
              <label>Fecha</label>
              <input type="date" class="form-control" name="fecha" required>
            </div>
            <div class="col-12 col-md-6">
              <label>Porcentaje contratista del valor base</label>
              <input type="number" step="any" class="form-control" name="porcentaje_base" placeholder="ej: 0.125" required>
            </div>
            <div class="col-12 col-md-6">
              <label>Porcentaje contratista de HHEE</label>
              <input type="number" step="any" class="form-control" name="por_hhee" placeholder="ej: 0.125" required>
            </div>
            <div class="col-12">
              <label>Excluir Cargos <span class="text-muted small">(opcional)</span></label>
              <select name="ids_cargo[]" multiple class="select2-cargo form-control">
                <?php foreach ($cargos_list as $c): ?>
                  <option value="<?= (int)$c['id_cargo'] ?>"><?= htmlspecialchars($c['cargo']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
          </div>
          <div class="mt-3">
            <button type="submit" name="guardar" class="btn btn-primary">Guardar</button>
          </div>
        </form>
      </div>
    </div>

  </div>
</div>

<!-- Tabla de registros -->
<div class="container mt-4">
  <h2 class="text-center mt-4">Registros Existentes</h2>
  <div class="table-responsive">
    <table class="table table-bordered table-hover mt-3 align-middle">
      <thead class="table-dark">
        <tr>
          <th>ID</th>
          <th>Nombre Tarifa</th>
          <th>Fecha</th>
          <th>Valor Base</th>
          <th>Valor HHEE</th>
          <th>% Contratista</th>
          <th>% Hora Extra</th>
          <th>Acciones</th>
        </tr>
      </thead>
      <tbody>
        <?php while ($query && $row = sqlsrv_fetch_array($query, SQLSRV_FETCH_ASSOC)):
            $tid      = (int)$row['id_tipo'];
            $excluidos = $excl_por_tipo[$tid] ?? [];
            $n_excl   = count($excluidos);
        ?>
        <!-- Fila edición -->
        <tr>
          <form method="POST">
            <td><?= $tid ?></td>
            <td><input type="text"   name="nombre"          value="<?= htmlspecialchars($row['tipo_tarifa']) ?>"                                                                               class="form-control form-control-sm"></td>
            <td><input type="date"   name="fecha"           value="<?= $row['fecha']->format('Y-m-d') ?>"                                                                                     class="form-control form-control-sm"></td>
            <td><input type="number" name="valor_base"      value="<?= rtrim(rtrim(number_format((float)($row['valor_base']       ?? 0), 6, '.', ''), '0'), '.') ?>" step="any" class="form-control form-control-sm"></td>
            <td><input type="number" name="base_hhee"       value="<?= rtrim(rtrim(number_format((float)($row['HH_EE_base']       ?? 0), 6, '.', ''), '0'), '.') ?>" step="any" class="form-control form-control-sm"></td>
            <td><input type="number" name="porcentaje_base" value="<?= rtrim(rtrim(number_format((float)($row['porc_contratista'] ?? 0), 6, '.', ''), '0'), '.') ?>" step="any" class="form-control form-control-sm"></td>
            <td><input type="number" name="por_hhee"        value="<?= rtrim(rtrim(number_format((float)($row['porc_hhee']        ?? 0), 6, '.', ''), '0'), '.') ?>" step="any" class="form-control form-control-sm"></td>
            <td>
              <input type="hidden" name="id_tipo" value="<?= $tid ?>">
              <button type="submit" name="editar"   class="btn btn-warning btn-sm">Editar</button>
              <button type="submit" name="eliminar" class="btn btn-danger  btn-sm">Eliminar</button>
              <button type="button" class="btn btn-secondary btn-sm"
                      data-bs-toggle="collapse" data-bs-target="#excl-<?= $tid ?>">
                Excluir Cargos<?= $n_excl > 0 ? " ($n_excl)" : '' ?>
              </button>
            </td>
          </form>
        </tr>
        <!-- Fila exclusiones (colapsable) -->
        <tr class="collapse" id="excl-<?= $tid ?>">
          <td colspan="8" class="bg-light">
            <form method="POST" class="d-flex align-items-end gap-2 p-2">
              <input type="hidden" name="id_tipo" value="<?= $tid ?>">
              <div class="flex-grow-1">
                <label class="form-label mb-1 small">Cargos excluidos de esta tarifa especial</label>
                <select name="ids_cargo[]" multiple class="select2-cargo form-control">
                  <?php foreach ($cargos_list as $c): ?>
                    <option value="<?= (int)$c['id_cargo'] ?>"
                      <?= in_array((int)$c['id_cargo'], $excluidos) ? 'selected' : '' ?>>
                      <?= htmlspecialchars($c['cargo']) ?>
                    </option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div>
                <button type="submit" name="guardar_exclusiones_tarifa" class="btn btn-primary btn-sm">Guardar</button>
              </div>
            </form>
          </td>
        </tr>
        <?php endwhile; ?>
      </tbody>
    </table>
  </div>
</div>

<?php
include __DIR__ . '/../partials/footer.php';
sqlsrv_close($conn);
?>
