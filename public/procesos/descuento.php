<?php
require_once __DIR__ . '/../_bootstrap.php';
require_once __DIR__ . '/../../app/lib/db.php';



$flash_error = null;
$flash_ok    = null;


// Consultas para llenar los combos
$queryContratista = sqlsrv_query($conn, "SELECT id, nombre FROM [dbo].[dota_contratista]");

// Agregar un nuevo registro
if (isset($_POST['guardar'])) {
    $id_contratista = $_POST['id_contratista'];
    $valor = $_POST['valor'];
    $fecha = $_POST['fecha'];
    $observacion = $_POST['observacion'];

    $sql_insert = "INSERT INTO [dbo].[dota_descuento] (id_contratista, valor, fecha, observacion) 
                   VALUES (?, ?, ?, ?)";
    $params = [$id_contratista, $valor, $fecha, $observacion];
    sqlsrv_query($conn, $sql_insert, $params);
}

// Editar un registro existente
if (isset($_POST['editar'])) {
    $id = $_POST['id'];
    $id_contratista = $_POST['id_contratista'];
    $valor = $_POST['valor'];
    $fecha = $_POST['fecha'];
    $observacion = $_POST['observacion'];

    $sql_update = "UPDATE [dbo].[dota_descuento]
                   SET id_contratista = ?, valor = ?, fecha = ?, observacion = ?
                   WHERE id = ?";
    $params = [$id_contratista, $valor, $fecha, $observacion, $id];
    sqlsrv_query($conn, $sql_update, $params);
}

// Eliminar un registro
if (isset($_POST['eliminar'])) {
    $id = $_POST['id'];

    $sql_delete = "DELETE FROM [dbo].[dota_descuento] WHERE id = ?";
    $params = [$id];
    sqlsrv_query($conn, $sql_delete, $params);
}

// Consultar los registros
$sql = "SELECT [id], [id_contratista], [valor], [fecha], [observacion] 
        FROM [dbo].[dota_descuento]";
$query = sqlsrv_query($conn, $sql);


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



<div class="container">
    <h1 class="text-center mb-4">Gestión de Descuentos</h1>

    <!-- Formulario para agregar un nuevo registro -->
    <div class="card mb-4">
        <div class="card-header">Agregar Nuevo Descuento</div>
        <div class="card-body">
            <form method="POST">
                <div class="form-group">
                    <label for="id_contratista">Contratista:</label>
                    <select class="form-control" id="id_contratista" name="id_contratista" required>
                        <option value="">Seleccionar Contratista</option>
                        <?php while ($rowContratista = sqlsrv_fetch_array($queryContratista, SQLSRV_FETCH_ASSOC)) {
                            echo "<option value='" . $rowContratista['id'] . "'>" . $rowContratista['nombre'] . "</option>";
                        } ?>
                    </select>
                </div>
                <div class="form-group">
                    <label for="valor">Valor:</label>
                    <input type="number" class="form-control" id="valor" name="valor" required>
                </div>
                <div class="form-group">
                    <label for="fecha">Fecha:</label>
                    <input type="date" class="form-control" id="fecha" name="fecha" required>
                </div>
                <div class="form-group">
                    <label for="observacion">Observación:</label>
                    <textarea class="form-control" id="observacion" name="observacion" rows="3"></textarea>
                </div>
                <button type="submit" name="guardar" class="btn btn-primary">Guardar</button>
            </form>
        </div>
    </div>

    <!-- Tabla de registros -->
    <h2 class="text-center">Registros Existentes</h2>
    <table class="table table-bordered table-hover mt-3">
        <thead class="thead-dark">
            <tr>
                <th>ID</th>
                <th>Contratista</th>
                <th>Valor</th>
                <th>Fecha</th>
                <th>Observación</th>
                <th>Acciones</th>
            </tr>
        </thead>
        <tbody>
            <?php while ($row = sqlsrv_fetch_array($query, SQLSRV_FETCH_ASSOC)) { ?>
                <tr>
                    <form method="POST">
                        <td><?php echo $row['id']; ?></td>
                        <td>
                            <select name="id_contratista" class="form-control">
                                <?php
                                $contratista_query = sqlsrv_query($conn, "SELECT id, nombre FROM [dbo].[dota_contratista]");
                                while ($contratista_row = sqlsrv_fetch_array($contratista_query, SQLSRV_FETCH_ASSOC)) {
                                    $selected = $contratista_row['id'] == $row['id_contratista'] ? 'selected' : '';
                                    echo "<option value='{$contratista_row['id']}' $selected>{$contratista_row['nombre']}</option>";
                                }
                                ?>
                            </select>
                        </td>
                        <td><input type="number" name="valor" value="<?php echo $row['valor']; ?>" class="form-control"></td>
                        <td><input type="date" name="fecha" value="<?php echo $row['fecha']->format('Y-m-d'); ?>" class="form-control"></td>
                        <td><textarea name="observacion" class="form-control"><?php echo $row['observacion']; ?></textarea></td>
                        <td>
                            <input type="hidden" name="id" value="<?php echo $row['id']; ?>">
                            <button type="submit" name="editar" class="btn btn-warning btn-sm">Editar</button>
                            <button type="submit" name="eliminar" class="btn btn-danger btn-sm">Eliminar</button>
                        </td>
                    </form>
                </tr>
            <?php } ?>
        </tbody>
    </table>
</div>

<?php
include __DIR__ . '/../partials/footer.php';

// Cerrar la conexión
sqlsrv_close($conn);
?>
