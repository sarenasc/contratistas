<?php
// Setup inicial — solo accesible si no existe config/setup.lock
session_start();

$lock_file = __DIR__ . '/../config/setup.lock';
$env_file  = __DIR__ . '/../config/.env';

// Si ya está configurado, redirigir
if (file_exists($lock_file)) {
    header('Location: index.php');
    exit;
}

$errors  = [];
$success = false;

// Leer valores actuales del .env para precargar el formulario
$env_actual = [];
if (file_exists($env_file)) {
    $env_actual = parse_ini_file($env_file);
}

function env_val($key, $default = '') {
    global $env_actual;
    return htmlspecialchars($env_actual[$key] ?? $default);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['configurar'])) {

    // --- 1. Recoger y validar campos ---
    $db_server   = trim($_POST['db_server']   ?? '');
    $db_user     = trim($_POST['db_user']     ?? '');
    $db_password = trim($_POST['db_password'] ?? '');
    $db_name     = trim($_POST['db_name']     ?? '');
    $db_name2    = trim($_POST['db_name2']    ?? '');
    $db_reloj    = trim($_POST['db_reloj']    ?? '');

    $nom_usu     = trim($_POST['nom_usu']     ?? '');
    $pass_usu    = trim($_POST['pass_usu']    ?? '');
    $pass_conf   = trim($_POST['pass_conf']   ?? '');
    $nombre      = trim($_POST['nombre']      ?? '');
    $apellido    = trim($_POST['apellido']    ?? '');

    if (!$db_server)  $errors[] = 'El servidor de base de datos es obligatorio.';
    if (!$db_user)    $errors[] = 'El usuario de base de datos es obligatorio.';
    if (!$db_name)    $errors[] = 'El nombre de la BD principal es obligatorio.';
    if (!$nom_usu)    $errors[] = 'El nombre de usuario del administrador es obligatorio.';
    if (!$pass_usu)   $errors[] = 'La contraseña es obligatoria.';
    if (strlen($pass_usu) < 6) $errors[] = 'La contraseña debe tener al menos 6 caracteres.';
    if ($pass_usu !== $pass_conf) $errors[] = 'Las contraseñas no coinciden.';
    if (!$nombre)     $errors[] = 'El nombre del administrador es obligatorio.';

    if (empty($errors)) {
        // --- 2. Probar conexión ---
        $conn_info = [
            'Database'      => $db_name,
            'UID'           => $db_user,
            'PWD'           => $db_password,
            'CharacterSet'  => 'UTF-8',
            'LoginTimeout'  => 5,
        ];
        $conn_test = @sqlsrv_connect($db_server, $conn_info);

        if (!$conn_test) {
            $sql_errors = sqlsrv_errors();
            $msg = $sql_errors[0]['message'] ?? 'No se pudo conectar.';
            $errors[] = 'Error de conexión a la base de datos: ' . htmlspecialchars($msg);
        } else {
            // --- 3. Crear primer usuario ---
            $pass_hash = password_hash($pass_usu, PASSWORD_BCRYPT);
            $sql_ins   = "INSERT INTO [dbo].[TRA_usuario] (nom_usu, pass_usu, Nombre, Apellido, sistema1)
                          VALUES (?, ?, ?, ?, 11)";
            $stmt = sqlsrv_query($conn_test, $sql_ins, [$nom_usu, $pass_hash, $nombre, $apellido]);

            if ($stmt === false) {
                $sql_err = sqlsrv_errors();
                $msg = $sql_err[0]['message'] ?? 'Error al crear el usuario.';
                $errors[] = 'Error al crear el usuario administrador: ' . htmlspecialchars($msg);
            } else {
                sqlsrv_close($conn_test);

                // --- 4. Escribir .env ---
                $env_content = "DB_SERVER={$db_server}\n"
                             . "DB_USER={$db_user}\n"
                             . "DB_PASSWORD={$db_password}\n"
                             . "DB_NAME={$db_name}\n"
                             . "DB_NAME2={$db_name2}\n"
                             . "DB_NAME_RELOJ={$db_reloj}\n";

                if (file_put_contents($env_file, $env_content) === false) {
                    $errors[] = 'No se pudo escribir el archivo de configuración. Verifique permisos en config/.env';
                } else {
                    // --- 5. Crear lock ---
                    file_put_contents($lock_file, date('Y-m-d H:i:s'));
                    $success = true;
                }
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Configuración Inicial — Sistema Contratistas</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body { background-color: #f0f2f5; }
        .setup-card { max-width: 680px; margin: 60px auto; }
        .step-badge { width: 32px; height: 32px; border-radius: 50%; display: inline-flex; align-items: center; justify-content: center; font-weight: bold; }
    </style>
</head>
<body>

<div class="setup-card">
    <div class="text-center mb-4">
        <h2 class="fw-bold">Sistema de Gestión de Contratistas</h2>
        <p class="text-muted">Configuración inicial del sistema</p>
    </div>

    <?php if ($success): ?>
    <div class="card shadow border-0">
        <div class="card-body text-center py-5">
            <div class="text-success mb-3" style="font-size:3rem;">✓</div>
            <h4 class="text-success">Sistema configurado correctamente</h4>
            <p class="text-muted mt-2">La base de datos fue conectada y el usuario administrador fue creado.</p>
            <a href="index.php" class="btn btn-primary mt-3 px-5">Ir al inicio de sesión</a>
        </div>
    </div>
    <?php else: ?>

    <?php if (!empty($errors)): ?>
    <div class="alert alert-danger">
        <ul class="mb-0">
            <?php foreach ($errors as $e): ?>
            <li><?= $e ?></li>
            <?php endforeach; ?>
        </ul>
    </div>
    <?php endif; ?>

    <form method="POST" novalidate>

        <!-- Sección 1: Base de datos -->
        <div class="card shadow border-0 mb-4">
            <div class="card-header bg-dark text-white d-flex align-items-center gap-2">
                <span class="step-badge bg-primary text-white">1</span>
                Configuración de Base de Datos
            </div>
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label">Servidor (IP o nombre) <span class="text-danger">*</span></label>
                        <input type="text" name="db_server" class="form-control"
                               value="<?= env_val('DB_SERVER', '192.168.1.1') ?>" required>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Usuario BD <span class="text-danger">*</span></label>
                        <input type="text" name="db_user" class="form-control"
                               value="<?= env_val('DB_USER', 'sa') ?>" required>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Contraseña BD</label>
                        <input type="password" name="db_password" class="form-control"
                               value="<?= env_val('DB_PASSWORD') ?>">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">BD Principal <span class="text-danger">*</span></label>
                        <input type="text" name="db_name" class="form-control"
                               value="<?= env_val('DB_NAME', 'SistGestion') ?>"
                               placeholder="SistGestion" required>
                        <div class="form-text">Donde se guardan usuarios, cargos, marcaciones, etc.</div>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">BD Facturador</label>
                        <input type="text" name="db_name2" class="form-control"
                               value="<?= env_val('DB_NAME2', 'Facturador_ASanta_Almahue') ?>"
                               placeholder="Facturador_ASanta_Almahue">
                        <div class="form-text">Para importar marcas del facturador externo.</div>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">BD Reloj Biométrico</label>
                        <input type="text" name="db_reloj" class="form-control"
                               value="<?= env_val('DB_NAME_RELOJ', 'ATT2000') ?>"
                               placeholder="ATT2000">
                        <div class="form-text">Base de datos del reloj de asistencia.</div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Sección 2: Usuario administrador -->
        <div class="card shadow border-0 mb-4">
            <div class="card-header bg-dark text-white d-flex align-items-center gap-2">
                <span class="step-badge bg-success text-white">2</span>
                Crear Usuario Administrador
            </div>
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label">Nombre <span class="text-danger">*</span></label>
                        <input type="text" name="nombre" class="form-control"
                               value="<?= htmlspecialchars($_POST['nombre'] ?? '') ?>" required>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Apellido</label>
                        <input type="text" name="apellido" class="form-control"
                               value="<?= htmlspecialchars($_POST['apellido'] ?? '') ?>">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Usuario <span class="text-danger">*</span></label>
                        <input type="text" name="nom_usu" class="form-control" autocomplete="off"
                               value="<?= htmlspecialchars($_POST['nom_usu'] ?? '') ?>" required>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Contraseña <span class="text-danger">*</span></label>
                        <input type="password" name="pass_usu" class="form-control" autocomplete="new-password" required>
                        <div class="form-text">Mínimo 6 caracteres.</div>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Confirmar contraseña <span class="text-danger">*</span></label>
                        <input type="password" name="pass_conf" class="form-control" autocomplete="new-password" required>
                    </div>
                </div>
            </div>
        </div>

        <div class="d-grid">
            <button type="submit" name="configurar" class="btn btn-primary btn-lg">
                Configurar Sistema
            </button>
        </div>

    </form>
    <?php endif; ?>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
