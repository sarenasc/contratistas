<?php

require_once __DIR__ . '/../_bootstrap.php';
require_once __DIR__ . '/../../app/lib/db.php';



$flash_error = null;
$flash_ok    = null;

function normalize_decimal_or_null($v) {
    $v = trim((string)$v);
    if ($v === '') return null;

    $v = str_replace(' ', '', $v);

    // Si tiene coma, asumimos formato chileno 1.234,56
    if (strpos($v, ',') !== false) {
        $v = str_replace('.', '', $v);   // quitar miles
        $v = str_replace(',', '.', $v);  // coma decimal → punto
    }

    return is_numeric($v) ? (float)$v : null;
}


// Agregar un nuevo registro
if (isset($_POST['guardar'])) {

    $nombre_tarifa = $_POST['nombre'];
    $valor_base    = normalize_decimal_or_null($_POST['valor_base']);
    $base_hhee     = normalize_decimal_or_null($_POST['base_hhee']);
    $fecha         = $_POST['fecha'];
    $porc_base     = normalize_decimal_or_null($_POST['porcentaje_base']);
    $porce_hhee    = normalize_decimal_or_null($_POST['por_hhee']);
    

    // Validar si la fecha ya existe
    $check_date_query = sqlsrv_query($conn, "SELECT COUNT(*) as count FROM [dbo].[Dota_Tarifa_Especiales] WHERE fecha = '$fecha'");
    $check_date_result = sqlsrv_fetch_array($check_date_query, SQLSRV_FETCH_ASSOC);
    
    if ($check_date_result['count'] > 0) {
        $flash_error='La fecha ya está registrada.';
    } else {
        // Insertar el nuevo registro si la fecha no existe
        $sql = "INSERT INTO [dbo].[Dota_Tarifa_Especiales] (tipo_tarifa,fecha,valor_base,HH_EE_base,porc_contratista,porc_hhee) VALUES (?,?,?,?,?,?)";
        $params = array($nombre_tarifa,$fecha,$valor_base,$base_hhee,$porc_base,$porce_hhee);
        sqlsrv_query($conn, $sql, $params);
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
    

    // Validar si la fecha ya existe en otro registro (que no sea el que estamos editando)
    $check_date_query = sqlsrv_query($conn, "SELECT COUNT(*) as count FROM [dbo].[Dota_Tarifa_Especiales] WHERE fecha = '$fecha' AND id_tipo != $id_tipo");
    $check_date_result = sqlsrv_fetch_array($check_date_query, SQLSRV_FETCH_ASSOC);

    if ($check_date_result['count'] > 0) {
        $flash_error='La fecha ya está registrada.';
    } else {
        // Actualizar el registro si la fecha no existe en otro registro
        $sql = "UPDATE [dbo].[Dota_Tarifa_Especiales] SET tipo_tarifa = ?, fecha = ?, valor_base = ?, HH_EE_base = ?, porc_contratista = ?, porc_hhee = ? WHERE id_tipo = ?";
        $params = array($nombre_tarifa,$fecha, $valor_base,$base_hhee,$porc_base,$porce_hhee, $id_tipo);
        sqlsrv_query($conn, $sql, $params);
        $flash_ok = "Registro actualizado exitosamente.";
    }
}

// Eliminar un registro
if (isset($_POST['eliminar'])) {
    $id_tipo = $_POST['id_tipo'];
    $sql = "DELETE FROM [dbo].[Dota_Tarifa_Especiales] WHERE id_tipo = $id_tipo";
    $params = array($id_tipo);  
    sqlsrv_query($conn, $sql, $params);
    $flash_ok = "Registro Eliminado Exitosamente";
}

// Consultar los registros
$sql = "SELECT [id_tipo], [tipo_tarifa], [fecha], [valor_base],[HH_EE_base],[porc_contratista],[porc_hhee]
FROM [dbo].[Dota_Tarifa_Especiales]";
$query = sqlsrv_query($conn, $sql);
if ($query === false) {
    $flash_error = "Error al consultar tarifas: " . htmlspecialchars(sqlsrv_errors()[0]['message'] ?? 'desconocido');
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
                    <div class="card-header">Agregar Nuevo Tipo Tarifa                        
                    </div>
                <div class="card-body">
                            <form method="POST">
                                <?= csrf_field() ?>

                                        <div class="row g-3">
                                                <div class="form-group col-12 col-md-6">
                                                <label>Nombre Tarifa</label>
                                                <input type="text" class="form-control" name="nombre" required>
                                                </div>

                                                <div class="form-group col-12 col-md-6">
                                                <label>Valor Base</label>
                                                <input type="number" step="0.0001" class="form-control" name="valor_base" required>
                                                </div>
                                        </div>

                                        <div class="row g-3">
                                                <div class="form-group col-12 col-md-6">
                                                <label>Valor Base HHEE</label>
                                                <input type="number" step="0.0001" class="form-control" name="base_hhee" required>
                                                </div>

                                                <div class="form-group col-12 col-md-6">
                                                <label>Fecha</label>
                                                <input type="date" class="form-control" name="fecha" required>
                                                </div>
                                        </div>

                                        <div class="row g-3">
                                                <div class="form-group col-12 col-md-6">
                                                <label>Porcentaje contratista del valor base</label>
                                                <input type="number" step="0.0001" class="form-control" name="porcentaje_base" placeholder="ej: 0.125" required>
                                                </div>
                                                <div class="form-group col-12 col-md-6">
                                                <label>Porcentaje contratista de HHEE</label>
                                                <input type="number" step="0.0001" class="form-control" name="por_hhee" placeholder="ej: 0.125" required>
                                                </div>
                                        </div>
                                           
                                        </div>
                                         </div>
                                         <button type="submit" name="guardar" class="btn btn-primary">Guardar</button>
                            </form>
                                        
                   
               
       
                       

    </div>
</div>


   <!-- Tabla de registros -->
   <div class="container mt-4">
    <h2 class="text-center mt-4">Registros Existentes</h2>
    <div class="table-responsive">
        <table class="table table-bordered table-hover mt-3">
            <thead class="thead-dark">
                <tr>
                    <th>ID</th>
                    <th>Nombre Tarifa</th>
                    <th>Fecha</th>
                    <th>Valor Base</th>
                    <th>Valor HHee</th>
                    <th>% Contratista</th>
                    <th>% Hora Extra</th>
                    <th>Acciones</th>
                </tr>
            </thead>
            <tbody>
                <?php while ($query && $row = sqlsrv_fetch_array($query, SQLSRV_FETCH_ASSOC)):
                    $fid = 'frow-' . $row['id_tipo'];
                ?>
                    <form id="<?= $fid ?>" method="POST"></form>
                    <tr>
                        <td><?= $row['id_tipo'] ?></td>
                        <td><input type="text"   form="<?= $fid ?>" name="nombre"          value="<?= htmlspecialchars($row['tipo_tarifa']) ?>" class="form-control form-control-sm"></td>
                        <td><input type="date"   form="<?= $fid ?>" name="fecha"           value="<?= $row['fecha']->format('Y-m-d') ?>" class="form-control form-control-sm"></td>
                        <td><input type="number" form="<?= $fid ?>" name="valor_base"      value="<?= number_format((float)($row['valor_base']       ?? 0), 4, '.', '') ?>" step="0.0001" class="form-control form-control-sm"></td>
                        <td><input type="number" form="<?= $fid ?>" name="base_hhee"       value="<?= number_format((float)($row['HH_EE_base']       ?? 0), 4, '.', '') ?>" step="0.0001" class="form-control form-control-sm"></td>
                        <td><input type="number" form="<?= $fid ?>" name="porcentaje_base" value="<?= number_format((float)($row['porc_contratista'] ?? 0), 4, '.', '') ?>" step="0.0001" class="form-control form-control-sm"></td>
                        <td><input type="number" form="<?= $fid ?>" name="por_hhee"        value="<?= number_format((float)($row['porc_hhee']        ?? 0), 4, '.', '') ?>" step="0.0001" class="form-control form-control-sm"></td>
                        <td>
                            <input type="hidden" form="<?= $fid ?>" name="id_tipo" value="<?= $row['id_tipo'] ?>">
                            <button type="submit" form="<?= $fid ?>" name="editar"    class="btn btn-warning btn-sm">Editar</button>
                            <button type="submit" form="<?= $fid ?>" name="eliminar"  class="btn btn-danger btn-sm"
                                    onclick="return confirm('¿Eliminar este registro?')">Eliminar</button>
                        </td>
                    </tr>
                <?php endwhile; ?>
            </tbody>
        </table>
    </div>
</div>



<?php
include __DIR__ . '/../partials/footer.php';

// Cerrar la conexión
sqlsrv_close($conn);
?>
