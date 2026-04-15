<?php
require_once __DIR__ . '/../_bootstrap.php';
$username = $_SESSION['nom_usu'];

// Agregar un nuevo registro
if (isset($_POST['guardar'])) {
    $area     = $_POST['area'];
    $cod_fact = $_POST['cod_fact'];
    $sql_insert = "INSERT INTO [dbo].[Area] (Area, cod_fact) VALUES (?, ?)";
    sqlsrv_query($conn, $sql_insert, [$area, $cod_fact]);
}

// Editar un registro existente
if (isset($_POST['editar'])) {
    $id_area  = (int)$_POST['id_area'];
    $area     = $_POST['area'];
    $cod_fact = $_POST['cod_fact'];
    $sql_update = "UPDATE [dbo].[Area] SET Area = ?, cod_fact = ? WHERE id_area = ?";
    sqlsrv_query($conn, $sql_update, [$area, $cod_fact, $id_area]);
}

// Eliminar un registro
if (isset($_POST['eliminar'])) {
    $id_area = (int)$_POST['id_area'];
    $sql_delete = "DELETE FROM [dbo].[Area] WHERE id_area = ?";
    sqlsrv_query($conn, $sql_delete, [$id_area]);
}

// Consultar los registros
$query = sqlsrv_query($conn, "SELECT [id_area], [Area], [cod_fact] FROM [dbo].[Area]");

$title = "Áreas";
include __DIR__ . '/../partials/header.php';
include __DIR__ . '/../partials/navbar_wrapper.php';
?>

<main class="container py-4">

    <div class="text-center my-4">
        <h5 class="text-muted">Usuario: <?= htmlspecialchars($username) ?></h5>
        <h1 class="display-4">Gestión de Áreas</h1>
    </div>

    <!-- Formulario agregar -->
    <div class="card mb-4">
        <div class="card-header">Agregar Nueva Área</div>
        <div class="card-body">
            <form method="POST">
                <?= csrf_field() ?>
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label">Nombre del Área</label>
                        <input type="text" class="form-control" name="area" required>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Código Facturador</label>
                        <input type="text" class="form-control" name="cod_fact" required>
                    </div>
                </div>
                <button type="submit" name="guardar" class="btn btn-primary mt-3">Guardar</button>
            </form>
        </div>
    </div>

    <!-- Form oculto único para editar/eliminar -->
    <form id="form-accion" method="POST">
        <?= csrf_field() ?>
        <input type="hidden" name="id_area"  id="f-id_area">
        <input type="hidden" name="area"     id="f-area">
        <input type="hidden" name="cod_fact" id="f-cod_fact">
        <button type="submit" name="editar"   id="f-btn-editar"   style="display:none"></button>
        <button type="submit" name="eliminar" id="f-btn-eliminar" style="display:none"></button>
    </form>

    <!-- Tabla de registros -->
    <h2 class="text-center">Áreas Existentes</h2>
    <div class="table-responsive mt-3">
        <table class="table table-bordered table-hover">
            <thead class="table-dark">
                <tr>
                    <th>ID</th>
                    <th>Área</th>
                    <th>Código Facturador</th>
                    <th>Acciones</th>
                </tr>
            </thead>
            <tbody>
                <?php while ($row = sqlsrv_fetch_array($query, SQLSRV_FETCH_ASSOC)): ?>
                <tr>
                    <td><?= (int)$row['id_area'] ?></td>
                    <td><input type="text" class="form-control inp-area"     value="<?= htmlspecialchars($row['Area']) ?>"></td>
                    <td><input type="text" class="form-control inp-cod_fact" value="<?= htmlspecialchars($row['cod_fact']) ?>"></td>
                    <td>
                        <button type="button" class="btn btn-warning btn-sm"
                                onclick="accion('editar', <?= (int)$row['id_area'] ?>, this)">Editar</button>
                        <button type="button" class="btn btn-danger btn-sm"
                                onclick="accion('eliminar', <?= (int)$row['id_area'] ?>, this)">Eliminar</button>
                    </td>
                </tr>
                <?php endwhile; ?>
            </tbody>
        </table>
    </div>

    <script>
    function accion(tipo, id, btn) {
        var tr = btn.closest('tr');
        document.getElementById('f-id_area').value  = id;
        document.getElementById('f-area').value     = tr.querySelector('.inp-area').value;
        document.getElementById('f-cod_fact').value = tr.querySelector('.inp-cod_fact').value;
        document.getElementById('f-btn-' + tipo).click();
    }
    </script>

</main>

<?php
include __DIR__ . '/../partials/footer.php';
sqlsrv_close($conn);
?>
