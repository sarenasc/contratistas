<?php
// Primer arranque: si no existe el lock de configuración, redirigir al setup
if (!file_exists(__DIR__ . '/../config/setup.lock')) {
    header('Location: setup.php');
    exit;
}

session_start();
if (isset($_SESSION['id']) && !empty($_SESSION['id'])){
    header("Location: Inicio.php");
    exit;
}
require_once __DIR__ . '/../app/lib/csrf.php';
csrf_token();


?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Inicio de Sesión</title>
    <!-- Incluir Bootstrap CSS -->
    <link href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css" rel="stylesheet">
  
</head>
<body>

<!-- Menú de navegación superior -->
<nav class="navbar navbar-expand-lg navbar-dark bg-dark mb-4">
    <div class="container-fluid">
        <a class="navbar-brand" href="#">Sistema de Gestión de Personal Contratista</a>
        <button class="navbar-toggler" type="button" data-toggle="collapse" data-target="#navbarNav" aria-controls="navbarNav" aria-expanded="false" aria-label="Toggle navigation">
            <span class="navbar-toggler-icon"></span>
        </button>
        <div class="collapse navbar-collapse" id="navbarNav">
            <ul class="navbar-nav ml-auto">
                <li class="nav-item">
                    <a class="nav-link" href="index.php">Inicio</a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="contacto.php">Contacto</a>
                </li>
            </ul>
        </div>
    </div>
</nav>

<!-- Contenedor de inicio de sesión -->
<div class="container d-flex justify-content-center align-items-center" style="min-height: 80vh;">
    <div class="card p-4 shadow-lg" style="max-width: 400px; width: 100%;">
        <h3 class="text-center text-success mb-4">Inicio de Sesión</h3>
        <?php if (isset($_GET['timeout'])): ?>
        <div class="alert alert-warning">Sesión expirada por inactividad. Por favor inicie sesión nuevamente.</div>
        <?php endif; ?>
        <?php if (isset($_GET['err']) && $_GET['err'] === '1'): ?>
        <div class="alert alert-danger">Error al iniciar sesión. Intente nuevamente.</div>
        <?php endif; ?>
        <form method="post" action="login.php">
            <?= csrf_field() ?>
            <div class="form-group">
                <label for="username">Usuario</label>
                <input type="text" class="form-control" id="username" name="user" placeholder="Ingrese su usuario" required>
            </div>
            <div class="form-group">
                <label for="password">Contraseña</label>
                <input type="password" class="form-control" id="password" name="pass" placeholder="Ingrese su contraseña" required>
            </div>
            <button type="submit" class="btn btn-primary btn-block" name="inicio">Iniciar Sesión</button>
        </form>
    </div>
</div>

<!-- Incluir Bootstrap JS y jQuery -->
<script src="https://code.jquery.com/jquery-3.5.1.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/@popperjs/core@2.4.4/dist/umd/popper.min.js"></script>
<script src="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/js/bootstrap.min.js"></script>
</body>
</html>

