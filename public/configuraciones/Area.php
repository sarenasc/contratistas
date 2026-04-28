<?php
require_once __DIR__ . '/../_bootstrap.php';

$flash_error = null;
$flash_ok    = null;

// Agregar un nuevo registro
if (isset($_POST['guardar'])) {
    $area     = trim($_POST['area'] ?? '');
    $cod_fact = trim($_POST['cod_fact'] ?? '');
    if ($area === '') {
        $flash_error = "El nombre del área no puede estar vacío.";
    } elseif ($cod_fact === '') {
        $flash_error = "El código facturador no puede estar vacío.";
    } else {
        $r = sqlsrv_query($conn, "INSERT INTO [dbo].[Area] (Area, cod_fact) VALUES (?, ?)", [$area, $cod_fact]);
        if ($r === false) $flash_error = "Error al guardar el área.";
        else $flash_ok = "Área agregada correctamente.";
    }
}

// Editar un registro existente
if (isset($_POST['editar'])) {
    $id_area  = (int)$_POST['id_area'];
    $area     = trim($_POST['area'] ?? '');
    $cod_fact = trim($_POST['cod_fact'] ?? '');
    if ($area === '') {
        $flash_error = "El nombre del área no puede estar vacío.";
    } elseif ($cod_fact === '') {
        $flash_error = "El código facturador no puede estar vacío.";
    } else {
        $r = sqlsrv_query($conn, "UPDATE [dbo].[Area] SET Area = ?, cod_fact = ? WHERE id_area = ?", [$area, $cod_fact, $id_area]);
        if ($r === false) $flash_error = "Error al actualizar el área.";
        else $flash_ok = "Área actualizada correctamente.";
    }
}

// Eliminar un registro
if (isset($_POST['eliminar'])) {
    $id_area = (int)$_POST['id_area'];
    $r = sqlsrv_query($conn, "DELETE FROM [dbo].[Area] WHERE id_area = ?", [$id_area]);
    if ($r === false) $flash_error = "Error al eliminar el área.";
    else $flash_ok = "Área eliminada.";
}

// Paginación
$per_page = 20;
$page     = max(1, (int)($_GET['page'] ?? 1));
$offset   = ($page - 1) * $per_page;

$total     = 0;
$stmtCount = sqlsrv_query($conn, "SELECT COUNT(*) FROM [dbo].[Area]");
if ($stmtCount) { $total = (int)sqlsrv_fetch_array($stmtCount, SQLSRV_FETCH_NUMERIC)[0]; }
$total_pages = max(1, (int)ceil($total / $per_page));
$page        = min($page, $total_pages);

$query = sqlsrv_query($conn,
    "SELECT [id_area], [Area], [cod_fact] FROM [dbo].[Area]
     ORDER BY [Area] OFFSET ? ROWS FETCH NEXT ? ROWS ONLY",
    [$offset, $per_page]
);

$title = "Áreas";
include __DIR__ . '/../partials/header.php';
include __DIR__ . '/../partials/navbar_wrapper.php';
?>

<main class="container py-4">

    <div class="text-center my-4">
        <h1 class="display-4">Gestión de Áreas</h1>
    </div>

    <?php if ($flash_error): ?>
      <div class="alert alert-danger"><?= htmlspecialchars($flash_error) ?></div>
    <?php endif; ?>
    <?php if ($flash_ok): ?>
      <div class="alert alert-success"><?= htmlspecialchars($flash_ok) ?></div>
    <?php endif; ?>

    <!-- Formulario agregar -->
    <div class="card mb-4">
        <div class="card-header">Agregar Nueva Área</div>
        <div class="card-body">
            <form method="POST">
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

    <!-- Paginación -->
    <?php if ($total_pages > 1): ?>
    <nav class="mt-3">
        <ul class="pagination justify-content-center">
            <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>">
                <a class="page-link" href="?page=<?= $page - 1 ?>">«</a>
            </li>
            <?php for ($p = 1; $p <= $total_pages; $p++): ?>
            <li class="page-item <?= $p === $page ? 'active' : '' ?>">
                <a class="page-link" href="?page=<?= $p ?>"><?= $p ?></a>
            </li>
            <?php endfor; ?>
            <li class="page-item <?= $page >= $total_pages ? 'disabled' : '' ?>">
                <a class="page-link" href="?page=<?= $page + 1 ?>">»</a>
            </li>
        </ul>
        <p class="text-center text-muted small">Mostrando <?= $offset + 1 ?>–<?= min($offset + $per_page, $total) ?> de <?= $total ?> registros</p>
    </nav>
    <?php endif; ?>

    <script>
    function accion(tipo, id, btn) {
        if (tipo === 'eliminar' && !confirm('¿Eliminar esta área?')) return;
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
