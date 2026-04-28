<?php
// Conexión a la base de datos
require_once __DIR__ . '/../_bootstrap.php';
require_once __DIR__ . '/../../app/lib/db.php';
$flash_error = null;
$flash_ok    = null;

if (!$conn) {
    error_log("DB connection failed: " . db_errors_to_string());
    die("Error de conexión. Contacte al administrador.");
}

// Agregar un nuevo registro
if (isset($_POST['guardar'])) {
    $nombre_mo = strtoupper($_POST['mo']);
    $abrev     = strtoupper($_POST['abrev']);
    db_query($conn, "INSERT INTO [dbo].[dota_tipo_mo] ([nombre_mo],[abrev]) VALUES (?, ?)", [$nombre_mo, $abrev], 'INSERT tipo_mo');
}

// Editar un registro existente
if (isset($_POST['editar'])) {
    $id_mo     = (int)$_POST['id_mo'];
    $nombre_mo = strtoupper($_POST['nom_mo']);
    $abrev     = strtoupper($_POST['abrev']);
    db_query($conn, "UPDATE [dbo].[dota_tipo_mo] SET nombre_mo = ?, abrev = ? WHERE id_mo = ?", [$nombre_mo, $abrev, $id_mo], 'UPDATE tipo_mo');
}

// Eliminar un registro
if (isset($_POST['eliminar'])) {
    $id_mo = (int)$_POST['id_mo'];
    db_query($conn, "DELETE FROM [dbo].[dota_tipo_mo] WHERE id_mo = ?", [$id_mo], 'DELETE tipo_mo');
}

// Consultar los registros
$query = db_query($conn, "SELECT id_mo,nombre_mo,abrev FROM dota_tipo_mo", [], 'SELECT tipo_mo');



$title = "Tipo Mano de Obra";
include __DIR__ . '/../partials/header.php';
include __DIR__ . '/../partials/navbar_wrapper.php';

?>


<main class="container py-4">

<?php if ($flash_error): ?>
  <div class="alert alert-danger"><?= htmlspecialchars($flash_error) ?></div>
<?php endif; ?>

<?php if ($flash_ok): ?>
  <div class="alert alert-success"><?= htmlspecialchars($flash_ok) ?></div>
<?php endif; ?>


<!-- Contenido principal -->
<div class="container">
    <div class="text-center my-4">
        <h1 class="display-4"><?php echo $title ?></h1>
    </div>

    <!-- Formulario para agregar un nuevo registro -->
    <div class="card mb-4">
        <div class="card-header">
            Agregar Tipo de Mano de Obra
        </div>
        <div class="card-body">
            <form method="POST">
                <div class="row g-3">
                    <div class="form-group col-12 col-md-6">
                    <label>Nombre Mano de Obra</label>
                    <input type="text" class="form-control" name="mo" required>
                    </div>

                    <div class="form-group col-12 col-md-6">
                    <label>Abreviacion</label>
                    <input type="text" class="form-control" name="abrev" required>
                    </div>
                </div>
                <button type="submit" name="guardar" class="btn btn-primary">Guardar Nuevo Registro</button>
            </form>
        </div>
    </div>

    <?php
// Establecer la cantidad de registros por página
$registros_por_pagina = 10;

// Obtener el número de la página actual desde la URL (por defecto es 1)
$pagina_actual = isset($_GET['pagina']) ? (int)$_GET['pagina'] : 1;
if ($pagina_actual < 1) $pagina_actual = 1;

// Manejar búsqueda
$busqueda = isset($_GET['busqueda']) ? trim($_GET['busqueda']) : '';
$filtro_busqueda = $busqueda ? "WHERE nombre_mo LIKE ?" : '';
$search_params = $busqueda ? ["%$busqueda%"] : [];

// Contar el total de registros para calcular el número de páginas
$stmt_total = db_query($conn, "SELECT COUNT(*) AS total FROM [dota_tipo_mo] $filtro_busqueda", $search_params, 'COUNT tipo_mo');
$total_registros = sqlsrv_fetch_array($stmt_total, SQLSRV_FETCH_ASSOC)['total'];
$total_paginas = ceil($total_registros / $registros_por_pagina);

// Calcular el índice de inicio para la consulta SQL
$offset = ($pagina_actual - 1) * $registros_por_pagina;

// Consulta para obtener los registros con límite y desplazamiento
$query = db_query($conn, "SELECT * FROM dota_tipo_mo $filtro_busqueda ORDER BY id_mo OFFSET $offset ROWS FETCH NEXT $registros_por_pagina ROWS ONLY", $search_params, 'SELECT tipo_mo paginado');
?>

<!-- Formulario de búsqueda -->
<div class="mb-3">
    <form method="GET" action="">
        <div class="input-group">
            <input type="text" name="busqueda" class="form-control" placeholder="Buscar por cargo" value="<?php echo htmlspecialchars($busqueda); ?>">
            <button class="btn btn-primary" type="submit">Buscar</button>
        </div>
    </form>
</div>

<!-- Tabla de cargos -->
<h2 class="text-center">Lista de Cargos</h2>
<div class="table-responsive">
    <table class="table table-bordered table-hover mt-4">
        <thead class="thead-dark">
            <tr>
                <th>ID</th>
                <th>Nombre</th>
                <th>Abreviatura</th>
                <th>Acciones</th>
            </tr>
        </thead>
        <tbody>
            <?php while ($row = sqlsrv_fetch_array($query, SQLSRV_FETCH_ASSOC)) { ?>
                <tr>
                    <form method="POST">
                        <td><?php echo $row['id_mo']; ?></td>
                        <td><input type="text" name="nom_mo" class="form-control" value="<?php echo htmlspecialchars($row['nombre_mo']); ?>"></td> 
                        <td><input type="text" name="abrev" class="form-control" value="<?php echo htmlspecialchars($row['abrev']); ?>"></td>
                        <                          
                        <td>
                            <input type="hidden" name="id_mo" value="<?php echo $row['id_mo']; ?>">
                            <button type="submit" name="editar" class="btn btn-warning">Editar</button>
                            <button type="submit" name="eliminar" class="btn btn-danger">Eliminar</button>
                        </td>
                    </form>
                </tr>
            <?php } ?>
        </tbody>
    </table>
</div>

<!-- Paginación -->
<nav>
    <ul class="pagination justify-content-center">
        <?php if ($pagina_actual > 1): ?>
            <li class="page-item"><a class="page-link" href="?pagina=<?php echo $pagina_actual - 1; ?>&busqueda=<?php echo urlencode($busqueda); ?>">Anterior</a></li>
        <?php endif; ?>

        <?php for ($i = 1; $i <= $total_paginas; $i++): ?>
            <li class="page-item <?php if ($i == $pagina_actual) echo 'active'; ?>">
                <a class="page-link" href="?pagina=<?php echo $i; ?>&busqueda=<?php echo urlencode($busqueda); ?>"><?php echo $i; ?></a>
            </li>
        <?php endfor; ?>

        <?php if ($pagina_actual < $total_paginas): ?>
            <li class="page-item"><a class="page-link" href="?pagina=<?php echo $pagina_actual + 1; ?>&busqueda=<?php echo urlencode($busqueda); ?>">Siguiente</a></li>
        <?php endif; ?>
    </ul>
</nav>


<?php include __DIR__ . '/../partials/footer.php';




// Cierra la conexión
sqlsrv_close($conn);
?>
