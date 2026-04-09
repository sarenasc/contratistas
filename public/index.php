<?php
if (!file_exists(__DIR__ . '/../config/setup.lock')) {
    header('Location: setup.php');
    exit;
}

if (session_status() === PHP_SESSION_NONE) session_start();

if (isset($_SESSION['id_usuario']) && !empty($_SESSION['id_usuario'])) {
    header("Location: Inicio.php");
    exit;
}

$err     = $_GET['err']     ?? null;
$invalid = $_GET['invalid'] ?? null;
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Inicio de Sesión — Contratistas</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">

<nav class="navbar navbar-expand-lg navbar-dark bg-dark mb-4">
    <div class="container-fluid">
        <a class="navbar-brand" href="#">Sistema de Gestión de Personal Contratista</a>
    </div>
</nav>

<div class="container d-flex justify-content-center align-items-center" style="min-height: 80vh;">
    <div class="card p-4 shadow-lg" style="max-width: 400px; width: 100%;">
        <h3 class="text-center mb-4">Inicio de Sesión</h3>

        <?php if ($err): ?>
            <div class="alert alert-danger">Error al conectar con la base de datos.</div>
        <?php endif; ?>
        <?php if ($invalid): ?>
            <div class="alert alert-warning">Usuario o contraseña incorrectos.</div>
        <?php endif; ?>

        <form method="post" action="login.php">
            <div class="mb-3">
                <label class="form-label">Usuario</label>
                <input type="text" class="form-control" name="user" autofocus required>
            </div>
            <div class="mb-3">
                <label class="form-label">Contraseña</label>
                <input type="password" class="form-control" name="pass" required>
            </div>
            <button type="submit" class="btn btn-primary w-100" name="inicio">Iniciar Sesión</button>
        </form>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
