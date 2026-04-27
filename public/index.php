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
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="assets/css/theme-styles.css" rel="stylesheet">
    <link href="assets/css/theme-animations.css" rel="stylesheet">
    <link href="assets/css/theme-components.css" rel="stylesheet">
    <link href="assets/css/app.css" rel="stylesheet">
</head>
<body class="login-body">

<main class="login-shell">
    <section class="login-brand-panel">
        <div class="login-brand-copy anim-fade-down">
            <div class="login-brand-title">Sistema Contratista</div>
            <div class="login-brand-subtitle">Condor de Apalta</div>
        </div>
        <div class="login-clock anim-fade-up" aria-hidden="true">
            <div class="login-clock-face">
                <span class="clock-hand clock-hour"></span>
                <span class="clock-hand clock-minute"></span>
                <span class="clock-hand clock-second"></span>
                <span class="clock-center-dot"></span>
            </div>
            <div class="login-clock-label">Reloj control y facturación</div>
        </div>
        <div class="login-feature-list anim-fade-up">
            <span>Asistencia biométrica</span>
            <span>Aprobación por áreas</span>
            <span>Pre-facturación de contratistas</span>
        </div>
    </section>

    <section class="login-form-panel">
    <div class="login-card anim-scale-in">
        <h1 class="login-title">Inicio de sesión</h1>
        <p class="login-subtitle">Ingresa con tu usuario del sistema.</p>

        <?php if ($err): ?>
            <div class="alert alert-danger">Error al conectar con la base de datos.</div>
        <?php endif; ?>
        <?php if ($invalid): ?>
            <div class="alert alert-warning">Usuario o contraseña incorrectos.</div>
        <?php endif; ?>

        <form method="post" action="login.php">
            <div class="mb-3">
                <label class="form-label">Usuario</label>
                <input type="text" class="form-control form-input" name="user" autofocus required>
            </div>
            <div class="mb-3">
                <label class="form-label">Contraseña</label>
                <input type="password" class="form-control form-input" name="pass" required>
            </div>
            <button type="submit" class="btn btn-primary w-100" name="inicio">Iniciar Sesión</button>
        </form>
    </div>
    </section>
</main>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
