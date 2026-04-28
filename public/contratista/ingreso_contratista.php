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
$title = "Contratistas";
include __DIR__ . '/../partials/header.php';
include __DIR__ . '/../partials/navbar_wrapper.php';

?>

<main class="container py-4">

    <div class="text-center my-4">
        <h1 class="display-4">Ingreso Contratistas</h1>
    </div>

    <?php if ($flash_error): ?>
      <div class="alert alert-danger"><?= htmlspecialchars($flash_error) ?></div>
    <?php endif; ?>

    <?php if ($flash_ok): ?>
      <div class="alert alert-success"><?= htmlspecialchars($flash_ok) ?></div>
    <?php endif; ?>

    <div class="card mb-4">
        <div class="card-header">Agregar Nuevo Contratista</div>
        <div class="card-body">
            <form method="POST">
                <div class="row g-3">
                    <div class="col-12 col-md-6 col-xl-3">
                        <label class="form-label" for="razon_social">Razón Social</label>
                        <input type="text" name="razon_social" id="razon_social" class="form-control form-input" required>
                    </div>
                    <div class="col-12 col-md-6 col-xl-3">
                        <label class="form-label" for="rut">RUT</label>
                        <input type="text" name="rut" id="rut" class="form-control form-input" required>
                    </div>
                    <div class="col-12 col-md-6 col-xl-3">
                        <label class="form-label" for="nombre">Nombre</label>
                        <input type="text" name="nombre" id="nombre" class="form-control form-input" required>
                    </div>
                    <div class="col-12 col-md-6 col-xl-3">
                        <label class="form-label" for="cod_fact">Código Facturador</label>
                        <input type="text" name="cod_fact" id="cod_fact" class="form-control form-input">
                    </div>
                    <div class="col-12">
                        <label class="form-check" for="valor_empresa_new">
                            <input type="checkbox" name="valor_empresa" id="valor_empresa_new" value="1">
                            <span class="form-check-box"></span>
                            <span class="form-check-label">
                                Valor Empresa
                                <span>Los valores se ingresan manualmente en Pre-Factura</span>
                            </span>
                        </label>
                    </div>
                </div>
                <button type="submit" name="guardar" class="btn btn-primary mt-3">Guardar Nuevo Registro</button>
            </form>
        </div>
    </div>

    <div class="card">
        <div class="card-header">Registros Existentes</div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-bordered table-hover mb-0">
                    <thead class="table-dark">
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
                                    <td class="align-middle"><?php echo (int)$row['id']; ?></td>
                                    <td><input type="text" name="razon_social" value="<?php echo htmlspecialchars($row['razon_social']); ?>" class="form-control form-input form-input-sm"></td>
                                    <td><input type="text" name="rut" value="<?php echo htmlspecialchars($row['rut']); ?>" class="form-control form-input form-input-sm"></td>
                                    <td><input type="text" name="nombre" value="<?php echo htmlspecialchars($row['nombre']); ?>" class="form-control form-input form-input-sm"></td>
                                    <td><input type="text" name="cod_fact" value="<?php echo htmlspecialchars($row['cod_fact']); ?>" class="form-control form-input form-input-sm"></td>
                                    <td class="text-center align-middle">
                                        <input type="checkbox" name="valor_empresa" class="form-check-input"
                                            value="1" <?= $row['valor_empresa'] ? 'checked' : '' ?>>
                                    </td>
                                    <td class="align-middle">
                                        <input type="hidden" name="id" value="<?php echo (int)$row['id']; ?>">
                                        <button type="submit" name="editar" class="btn btn-warning btn-sm">Editar</button>
                                        <button type="submit" name="eliminar" class="btn btn-danger btn-sm"
                                                onclick="return confirm('¿Eliminar este contratista?')">Eliminar</button>
                                    </td>
                                </form>
                            </tr>
                        <?php } ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

</main>

<?php include __DIR__ . '/../partials/footer.php';
// Cerrar la conexión
sqlsrv_close($conn);
?>
