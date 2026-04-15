<?php
require_once __DIR__ . '/../_bootstrap.php';
require_once __DIR__ . '/../../app/lib/db.php';



$flash_error = null;
$flash_ok    = null;


// Agregar un nuevo registro
if (isset($_POST['guardar'])) {
    $razon_social  = $_POST['razon_social'];
    $rut           = $_POST['rut'];
    $nombre        = $_POST['nombre'];
    $cod_fact      = $_POST['cod_fact'];
    $valor_empresa = isset($_POST['valor_empresa']) ? 1 : 0;
    $sql = "INSERT INTO [dbo].[dota_contratista] (razon_social, rut, nombre, cod_fact, valor_empresa) VALUES (?, ?, ?, ?, ?)";
    sqlsrv_query($conn, $sql, [$razon_social, $rut, $nombre, $cod_fact, $valor_empresa]);
}

// Editar un registro existente
if (isset($_POST['editar'])) {
    $id            = (int)$_POST['id'];
    $razon_social  = $_POST['razon_social'];
    $rut           = $_POST['rut'];
    $nombre        = $_POST['nombre'];
    $cod_fact      = $_POST['cod_fact'];
    $valor_empresa = isset($_POST['valor_empresa']) ? 1 : 0;
    $sql = "UPDATE [dbo].[dota_contratista] SET razon_social = ?, rut = ?, nombre = ?, cod_fact = ?, valor_empresa = ? WHERE id = ?";
    sqlsrv_query($conn, $sql, [$razon_social, $rut, $nombre, $cod_fact, $valor_empresa, $id]);
}

// Eliminar un registro
if (isset($_POST['eliminar'])) {
    $id  = (int)$_POST['id'];
    $sql = "DELETE FROM [dbo].[dota_contratista] WHERE id = ?";
    sqlsrv_query($conn, $sql, [$id]);
}

// Consultar los registros
$sql = "SELECT [id], [razon_social], [rut], [nombre], [cod_fact], [valor_empresa] FROM [dbo].[dota_contratista]";
$query = sqlsrv_query($conn, $sql);
$title = "Tipo Tarifas";
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
 


    <!-- Contenido principal -->
    <div class="container" style="max-width: 80%;">
        <h1 class="text-center mb-4">Ingreso Contratistas</h1>

        <!-- Formulario para agregar un nuevo registro -->
        <form method="POST" class="mb-4">
            <?= csrf_field() ?>
            <div class="form-row">
                <div class="form-group col-md-4">
                    <label for="razon_social">Razón Social</label>
                    <input type="text" name="razon_social" class="form-control" required>
                </div>
                <div class="form-group col-md-4">
                    <label for="rut">RUT</label>
                    <input type="text" name="rut" class="form-control" required>
                </div>
                <div class="form-group col-md-4">
                    <label for="nombre">Nombre</label>
                    <input type="text" name="nombre" class="form-control" required>
                </div>
                <div class="form-group col-md-4">
                    <label for="nombre">Codigo Facturador</label>
                    <input type="text" name="cod_fact" class="form-control">
                </div>
                <div class="form-group col-md-4 d-flex align-items-end">
                    <div class="form-check">
                        <input type="checkbox" name="valor_empresa" id="valor_empresa_new" class="form-check-input" value="1">
                        <label class="form-check-label" for="valor_empresa_new">
                            Valor Empresa
                            <small class="text-muted d-block">Los valores se ingresan manualmente en Pre-Factura</small>
                        </label>
                    </div>
                </div>
            </div>
            <button type="submit" name="guardar" class="btn btn-primary">Guardar Nuevo Registro</button>
        </form>

        <!-- Tabla de registros -->
        <h2 class="text-center">Registros Existentes</h2>
        <table class="table table-bordered table-hover mt-3">
            <thead class="thead-dark">
                <tr>
                    <th>ID</th>
                    <th>Razón Social</th>
                    <th>RUT</th>
                    <th>Nombre</th>
                    <th>Cod. Fact.</th>
                    <th class="text-center">Valor Empresa</th>
                    <th>Acciones</th>
                </tr>
            </thead>
            <tbody>
                <?php while ($row = sqlsrv_fetch_array($query, SQLSRV_FETCH_ASSOC)) { ?>
                    <tr>
                        <form method="POST">
                            <?= csrf_field() ?>
                            <td><?php echo $row['id']; ?></td>
                            <td><input type="text" name="razon_social" value="<?php echo htmlspecialchars($row['razon_social']); ?>" class="form-control"></td>
                            <td><input type="text" name="rut" value="<?php echo htmlspecialchars($row['rut']); ?>" class="form-control"></td>
                            <td><input type="text" name="nombre" value="<?php echo htmlspecialchars($row['nombre']); ?>" class="form-control"></td>
                            <td><input type="text" name="cod_fact" value="<?php echo htmlspecialchars($row['cod_fact']); ?>" class="form-control"></td>
                            <td class="text-center align-middle">
                                <input type="checkbox" name="valor_empresa" class="form-check-input"
                                    value="1" <?= $row['valor_empresa'] ? 'checked' : '' ?>>
                            </td>
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



<?php include __DIR__ . '/../partials/footer.php';
// Cerrar la conexión
sqlsrv_close($conn);
?>
