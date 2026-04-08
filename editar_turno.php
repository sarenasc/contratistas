<?php
require_once "conexion.php";
session_start();
$username = $_SESSION['nom_usu'];


// Verificar si se pasó el ID del registro a editar
$userid=$_POST['id'];
$dateFull=$_POST['checkTime'];

if (isset($_POST['btnid'])) {
    require_once "conexion_Reloj.php";   

    // Consultar el registro actual de la base de datos
    $sql = "SELECT USERID,CHECKTIME,Fecha,Hora,Name,CHECKTYPE FROM RegistoHora WHERE USERID = $userid and CHECKTIME = convert(datetime,'$dateFull',102)" ;
    $params = array($userid);
    $stmt = sqlsrv_query($conn, $sql, $params);

    if ($stmt === false) {
        die(print_r(sqlsrv_errors(), true));
    }

    $record = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
    if (!$record) {
        echo "<p class='text-danger'>Registro no encontrado</p>";
        exit;
    }
} else {
    echo "<p class='text-danger'>No se proporcionó ID</p>";
    exit;
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edición de Registro de Entrada/Salida</title>
    <!-- Incluir Bootstrap CSS -->
    <link href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css" rel="stylesheet">
    <!-- Incluir CSS personalizado -->
    <link href="styles.css" rel="stylesheet">
</head>
<body>

<!-- Menú de navegación superior -->
<nav class="navbar navbar-expand-lg navbar-dark bg-dark mb-4">
    <div class="container-fluid">
        <a class="navbar-brand" href="#">Sistema de Gestión</a>
        <button class="navbar-toggler" type="button" data-toggle="collapse" data-target="#navbarNav" aria-controls="navbarNav" aria-expanded="false" aria-label="Toggle navigation">
            <span class="navbar-toggler-icon"></span>
        </button>
        <div class="collapse navbar-collapse" id="navbarNav">
            <ul class="navbar-nav ml-auto">
                <li class="nav-item">
                    <a class="nav-link" href="Edicion_turno.php">Atrás</a>
                </li>
                <li class="nav-item">
                    <a class="nav-link text-danger" href="logout.php">Cerrar sesión</a>
                </li>
            </ul>
        </div>
    </div>
</nav>

<!-- Contenido principal -->
<div class="container">
    <h2 class="text-center mb-4">Editar Registro</h2>
    <form method="post" action="procesar_edicion.php">
        <input type="hidden" name="USERID" value="<?php echo htmlspecialchars($record['USERID']); ?>">

        <div class="form-group">
            <label for="fecha">Fecha</label>
            <input type="date" class="form-control" id="fecha" name="Fecha" value="<?php echo htmlspecialchars($record['Fecha']->format('Y-m-d')); ?>" required>
        </div>
        <div class="form-group">
            <label for="hora">Hora</label>
            <input type="time" class="form-control" id="hora" name="Hora" value="<?php echo htmlspecialchars($record['Hora']->format('H:i')); ?>" required>
        </div>
        <div class="form-group">
            <label for="name">Nombre</label>
            <input type="text" class="form-control" id="name" name="Name" value="<?php echo htmlspecialchars($record['Name']); ?>" required>
        </div>
        <div class="form-group">
            <label for="checktype">Tipo</label>
            <input type="text" class="form-control" id="checktype" name="CHECKTYPE" value="<?php echo htmlspecialchars($record['CHECKTYPE']); ?>" required>
        </div>
        <input type="hidden" name="datefull" value="<?php  echo htmlspecialchars($dateFull); ?>">
        <button type="submit" class="btn btn-primary btn-block">Guardar Cambios</button>
    </form>
</div>

<!-- Incluir Bootstrap JS y jQuery -->
<script src="https://code.jquery.com/jquery-3.5.1.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/@popperjs/core@2.4.4/dist/umd/popper.min.js"></script>
<script src="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/js/bootstrap.min.js"></script>
</body>
</html>
