<?php
require_once __DIR__ . '/../_bootstrap.php';
$username = nombre_usuario();

$flash_error = null;
$flash_ok    = null;

if (isset($_POST['guardar'])) {
    $nombre = trim($_POST['nombre_turno']);
    if ($nombre === '') {
        $flash_error = "El nombre del turno no puede estar vacío.";
    } else {
        $r = sqlsrv_query($conn, "INSERT INTO [dbo].[dota_turno] (nombre_turno) VALUES (?)", [$nombre]);
        if ($r === false) $flash_error = "Error al guardar: " . print_r(sqlsrv_errors(), true);
        else $flash_ok = "Turno agregado correctamente.";
    }
}

if (isset($_POST['editar'])) {
    $id     = (int)$_POST['id'];
    $nombre = trim($_POST['nombre_turno']);
    $r = sqlsrv_query($conn, "UPDATE [dbo].[dota_turno] SET nombre_turno = ? WHERE id = ?", [$nombre, $id]);
    if ($r === false) $flash_error = "Error al editar: " . print_r(sqlsrv_errors(), true);
    else $flash_ok = "Turno actualizado.";
}

if (isset($_POST['eliminar'])) {
    $id = (int)$_POST['id'];
    $r = sqlsrv_query($conn, "DELETE FROM [dbo].[dota_turno] WHERE id = ?", [$id]);
    if ($r === false) $flash_error = "Error al eliminar: " . print_r(sqlsrv_errors(), true);
    else $flash_ok = "Turno eliminado.";
}

$query = sqlsrv_query($conn, "SELECT id, nombre_turno FROM [dbo].[dota_turno] ORDER BY nombre_turno");

$title = "Turnos";
include __DIR__ . '/../partials/header.php';
include __DIR__ . '/../partials/navbar_wrapper.php';
?>

<main class="container py-4">

    <div class="text-center my-4">
        <h5 class="text-muted">Usuario: <?= htmlspecialchars($username) ?></h5>
        <h1 class="display-4">Gestión de Turnos</h1>
    </div>

    <?php if ($flash_error): ?>
      <div class="alert alert-danger"><?= htmlspecialchars($flash_error) ?></div>
    <?php endif; ?>
    <?php if ($flash_ok): ?>
      <div class="alert alert-success"><?= htmlspecialchars($flash_ok) ?></div>
    <?php endif; ?>

    <!-- Formulario agregar -->
    <div class="card mb-4">
        <div class="card-header">Agregar Nuevo Turno</div>
        <div class="card-body">
            <form method="POST">
                <div class="row g-3 align-items-end">
                    <div class="col-md-6">
                        <label class="form-label">Nombre del Turno</label>
                        <input type="text" class="form-control" name="nombre_turno" required>
                    </div>
                    <div class="col-md-3">
                        <button type="submit" name="guardar" class="btn btn-primary w-100">Guardar</button>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <!-- Form oculto para editar/eliminar -->
    <form id="form-accion" method="POST">
        <input type="hidden" name="id"           id="f-id">
        <input type="hidden" name="nombre_turno" id="f-nombre">
        <button type="submit" name="editar"   id="f-btn-editar"   style="display:none"></button>
        <button type="submit" name="eliminar" id="f-btn-eliminar" style="display:none"></button>
    </form>

    <!-- Tabla -->
    <h2 class="text-center">Turnos Existentes</h2>
    <div class="table-responsive mt-3">
        <table class="table table-bordered table-hover">
            <thead class="table-dark">
                <tr>
                    <th>ID</th>
                    <th>Nombre Turno</th>
                    <th>Acciones</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($query): while ($row = sqlsrv_fetch_array($query, SQLSRV_FETCH_ASSOC)): ?>
                <tr>
                    <td><?= (int)$row['id'] ?></td>
                    <td><input type="text" class="form-control inp-nombre" value="<?= htmlspecialchars($row['nombre_turno']) ?>"></td>
                    <td>
                        <button type="button" class="btn btn-warning btn-sm"
                                onclick="accion('editar', <?= (int)$row['id'] ?>, this)">Editar</button>
                        <button type="button" class="btn btn-danger btn-sm"
                                onclick="accion('eliminar', <?= (int)$row['id'] ?>, this)">Eliminar</button>
                    </td>
                </tr>
                <?php endwhile; endif; ?>
            </tbody>
        </table>
    </div>

    <script>
    function accion(tipo, id, btn) {
        var tr = btn.closest('tr');
        document.getElementById('f-id').value     = id;
        document.getElementById('f-nombre').value = tr.querySelector('.inp-nombre').value;
        document.getElementById('f-btn-' + tipo).click();
    }
    </script>

</main>

<?php
include __DIR__ . '/../partials/footer.php';
sqlsrv_close($conn);
?>
