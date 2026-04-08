<?php
require_once __DIR__ . '/../_bootstrap.php';
$username = $_SESSION['nom_usu'];

// Agregar un nuevo registro
if (isset($_POST['guardar'])) {
    $nom_usu   = $_POST['nom_usu'];
    $pass_hash = password_hash($_POST['pass_usu'], PASSWORD_BCRYPT);
    $nombre    = $_POST['nombre'];
    $apellido  = $_POST['apellido'];
    $sql = "INSERT INTO TRA_usuario (nom_usu, pass_usu, Nombre, Apellido, sistema1) VALUES (?, ?, ?, ?, 11)";
    sqlsrv_query($conn, $sql, [$nom_usu, $pass_hash, $nombre, $apellido]);
}

// Editar un registro existente
if (isset($_POST['editar'])) {
    $id       = (int)$_POST['id'];
    $nom_usu  = $_POST['nom_usu'];
    $nombre   = $_POST['nombre'];
    $apellido = $_POST['apellido'];
    // Solo actualizar contraseña si se proporcionó una nueva
    if (!empty($_POST['pass_usu'])) {
        $pass_hash = password_hash($_POST['pass_usu'], PASSWORD_BCRYPT);
        $sql = "UPDATE TRA_usuario SET nom_usu = ?, pass_usu = ?, Nombre = ?, Apellido = ? WHERE id = ?";
        sqlsrv_query($conn, $sql, [$nom_usu, $pass_hash, $nombre, $apellido, $id]);
    } else {
        $sql = "UPDATE TRA_usuario SET nom_usu = ?, Nombre = ?, Apellido = ? WHERE id = ?";
        sqlsrv_query($conn, $sql, [$nom_usu, $nombre, $apellido, $id]);
    }
}

// Eliminar un registro
if (isset($_POST['eliminar'])) {
    $id  = (int)$_POST['id'];
    $sql = "DELETE FROM TRA_usuario WHERE id = ?";
    sqlsrv_query($conn, $sql, [$id]);
}

// Consultar los registros
$query = sqlsrv_query($conn, "SELECT id, nom_usu, pass_usu, Nombre, Apellido FROM TRA_usuario WHERE sistema1 = 11");

$title = "Usuarios";
include __DIR__ . '/../partials/header.php';
include __DIR__ . '/../partials/navbar_wrapper.php';
?>

<main class="container py-4">

    <div class="text-center my-4">
        <h5 class="text-muted">Usuario: <?= htmlspecialchars($username) ?></h5>
        <h1 class="display-4">Gestión de Usuarios</h1>
    </div>

    <!-- Formulario agregar -->
    <div class="card mb-4">
        <div class="card-header">Agregar Nuevo Usuario</div>
        <div class="card-body">
            <form method="POST">
                <div class="row g-3">
                    <div class="col-md-3">
                        <label class="form-label">Nombre de Usuario</label>
                        <input type="text" name="nom_usu" class="form-control" required>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Contraseña</label>
                        <input type="password" name="pass_usu" class="form-control" required>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Nombre</label>
                        <input type="text" name="nombre" class="form-control" required>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Apellido</label>
                        <input type="text" name="apellido" class="form-control" required>
                    </div>
                </div>
                <button type="submit" name="guardar" class="btn btn-primary mt-3">Guardar Nuevo Registro</button>
            </form>
        </div>
    </div>

    <!-- Tabla de registros -->
    <h2 class="text-center">Usuarios Registrados</h2>
    <div class="table-responsive mt-3">
        <table class="table table-bordered table-hover">
            <thead class="table-dark">
                <tr>
                    <th>ID</th>
                    <th>Usuario</th>
                    <th>Nueva Contraseña</th>
                    <th>Nombre</th>
                    <th>Apellido</th>
                    <th>Acciones</th>
                </tr>
            </thead>
            <tbody>
                <?php while ($row = sqlsrv_fetch_array($query, SQLSRV_FETCH_ASSOC)): ?>
                <tr>
                    <form method="POST">
                        <td><?= $row['id'] ?></td>
                        <td><input type="text" name="nom_usu" value="<?= htmlspecialchars($row['nom_usu']) ?>" class="form-control"></td>
                        <td><input type="password" name="pass_usu" class="form-control" placeholder="Nueva contraseña (dejar vacío para no cambiar)"></td>
                        <td><input type="text" name="nombre" value="<?= htmlspecialchars($row['Nombre']) ?>" class="form-control"></td>
                        <td><input type="text" name="apellido" value="<?= htmlspecialchars($row['Apellido']) ?>" class="form-control"></td>
                        <td>
                            <input type="hidden" name="id" value="<?= $row['id'] ?>">
                            <button type="submit" name="editar" class="btn btn-warning btn-sm">Editar</button>
                            <button type="submit" name="eliminar" class="btn btn-danger btn-sm">Eliminar</button>
                        </td>
                    </form>
                </tr>
                <?php endwhile; ?>
            </tbody>
        </table>
    </div>

</main>

<?php
include __DIR__ . '/../partials/footer.php';
sqlsrv_close($conn);
?>
