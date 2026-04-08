<?php
require_once __DIR__ . '/../_bootstrap.php';

    $nombre = $_SESSION['Nombre'];
    $apellido = $_SESSION['Apellido'];
    $username = $_SESSION['nom_usu'];
$fecha = '';
$n_cc = '';
$año = '';

// Comprobamos si se enviaron filtros
if (isset($_POST['fecha']) && isset($_POST['n_cc'])) {
    $fecha = $_POST['fecha'];
    $n_cc = $_POST['n_cc'];
    $año = $_POST['Año'];
    
}

?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Consulta de Registro de Marcación</title>
    <!-- Bootstrap CSS -->
    <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css">
</head>
<body>
<nav class="navbar navbar-expand-lg navbar-dark bg-dark mb-4">
    <div class="container-fluid">
        <a class="navbar-brand" href="#">Gestión de Dotación<br>Usuario: <?php echo $username ?></a>
        <button class="navbar-toggler" type="button" data-toggle="collapse" data-target="#navbarNav" aria-controls="navbarNav" aria-expanded="false" aria-label="Toggle navigation">
            <span class="navbar-toggler-icon"></span>
        </button>
        <div class="collapse navbar-collapse" id="navbarNav">
            <ul class="navbar-nav ml-auto">
                <!-- aqui deberia ir el menu -->
     <?php require_once __DIR__ . '..\..\partials\navbar.php'; ?>
                <li class="nav-item">
                    <a class="nav-link" href="../Inicio.php">Atrás</a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="../logout.php">Cerrar sesión</a>
                </li>
            </ul>
        </div>
    </div>
</nav>
<div class="container mt-4">
    <h2 class="text-center">Consulta de Registro de Jornadas Y Horas Extras</h2>

    <!-- Formulario de filtros -->
    <form method="POST" class="mt-4">
        <div class="form-row">
        <div class="col-md-4">
                <label for="fecha">Año</label>
                <input type="text" name="Año" class="form-control" id="Año" value="<?php echo $año; ?>" required placeholder="Año">
            </div>
            <div class="col-md-4">
                <label for="fecha">Semana</label>
                <input type="text" name="fecha" class="form-control" id="fecha" value="<?php echo $fecha; ?>" required placeholder="Semana">
            </div>
            <div class="col-md-4">
                <label for="n_cc">Seleccionar Contratista</label>
                <select name="n_cc" class="form-control" id="n_cc" required>
                    <option value="">Seleccionar Contratista</option>
                    <?php
                    // Consulta para obtener los valores de N_CC desde la base de datos
                    $ncc_query = sqlsrv_query($conn, "SELECT DISTINCT [Contratista] FROM [dbo].[view_agr_semana]");                    
                    while ($ncc_row = sqlsrv_fetch_array($ncc_query, SQLSRV_FETCH_ASSOC)) {
                        $selected = $ncc_row['Contratista'] == $n_cc ? 'selected' : '';
                        echo "<option value='{$ncc_row['Contratista']}' $selected>{$ncc_row['Contratista']}</option>";
                    }
                    ?>
                </select>
            </div>
            <div class="col-md-4">
                <button type="submit" class="btn btn-primary mt-4">Filtrar</button>
            </div>
        </div>
    </form>

    <hr>

    <!-- Tabla de resultados agrupados -->
    <h3 class="text-center mt-4"></h3>
    
    <table class="table table-bordered table-hover mt-3">
        <thead class="thead-dark">
       
            <tr>
                <th>Fecha</th>
                <th>Area</th>
                <th>Cargo</th>
                <th>Total Horas Extra</th>
                <th>Total Jornada</th>
            </tr>
        </thead>
        <tbody>
            <?php
            // Consulta SQL para obtener los datos filtrados y agrupados
            $query = "
                SELECT [Fecha]
                ,[Semana]
      ,[Mes]
      ,[Año]
      ,[Contratista]
      ,[Area]
      ,[cargo]
      ,[Hora Extra]
      ,[Jornada]
  FROM [dbo].[view_agr_semana]
                WHERE [Semana] = '$fecha' AND [Contratista] = '$n_cc' AND [Año]= '$año'
                order by cargo ASC
            ";

            $result = sqlsrv_query($conn, $query);
            // Mostrar los resultados en la tabla
            while ($row = sqlsrv_fetch_array($result, SQLSRV_FETCH_ASSOC)) {
            ?>
                <tr>
                    <td><?php echo $row['Fecha']->format('Y/m/d'); ?></td>
                    <td><?php echo $row['Area']; ?></td>
                    <td><?php echo $row['cargo']; ?></td>
                    <td><?php echo number_format($row['Hora Extra'], 2); ?></td>
                    <td><?php echo number_format($row['Jornada'], 2); ?></td>
                </tr>
            <?php } ?>
        </tbody>
    </table>
</div>

<!-- Bootstrap JS and dependencies -->
<script src="https://code.jquery.com/jquery-3.5.1.slim.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/@popperjs/core@2.9.2/dist/umd/popper.min.js"></script>
<script src="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/js/bootstrap.min.js"></script>
</body>
</html>
