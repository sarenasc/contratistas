<?php
// Setup inicial — solo accesible si no existe config/setup.lock
session_start();

$lock_file = __DIR__ . '/../config/setup.lock';
$env_file  = __DIR__ . '/../config/.env';

if (file_exists($lock_file)) {
    header('Location: index.php');
    exit;
}

$errors  = [];
$success = false;

$env_actual = file_exists($env_file) ? parse_ini_file($env_file) : [];
function env_val($key, $default = '') {
    global $env_actual;
    return htmlspecialchars($env_actual[$key] ?? $default);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['configurar'])) {

    // 1. Recoger campos
    $db_server   = trim($_POST['db_server']   ?? '');
    $db_user     = trim($_POST['db_user']     ?? '');
    $db_password = trim($_POST['db_password'] ?? '');
    $db_name     = trim($_POST['db_name']     ?? 'Fact_contratista');
    $db_name2    = trim($_POST['db_name2']    ?? '');
    $db_reloj    = trim($_POST['db_reloj']    ?? '');

    $usuario   = trim($_POST['usuario']   ?? '');
    $pass_usu  = trim($_POST['pass_usu']  ?? '');
    $pass_conf = trim($_POST['pass_conf'] ?? '');
    $nombre    = trim($_POST['nombre']    ?? '');
    $apellido  = trim($_POST['apellido']  ?? '');
    $email     = trim($_POST['email']     ?? '');

    // 2. Validar
    if (!$db_server) $errors[] = 'El servidor de base de datos es obligatorio.';
    if (!$db_user)   $errors[] = 'El usuario de base de datos es obligatorio.';
    if (!$db_name)   $errors[] = 'El nombre de la BD principal es obligatorio.';
    if (!$usuario)   $errors[] = 'El nombre de usuario es obligatorio.';
    if (!$pass_usu)  $errors[] = 'La contraseña es obligatoria.';
    if (strlen($pass_usu) < 6) $errors[] = 'La contraseña debe tener al menos 6 caracteres.';
    if ($pass_usu !== $pass_conf) $errors[] = 'Las contraseñas no coinciden.';
    if (!$nombre)    $errors[] = 'El nombre del administrador es obligatorio.';

    if (empty($errors)) {

        // 3. Probar conexión
        $conn_test = @sqlsrv_connect($db_server, [
            'Database'     => $db_name,
            'UID'          => $db_user,
            'PWD'          => $db_password,
            'CharacterSet' => 'UTF-8',
            'LoginTimeout' => 5,
        ]);

        if (!$conn_test) {
            $msg = sqlsrv_errors()[0]['message'] ?? 'No se pudo conectar.';
            $errors[] = 'Error de conexión: ' . htmlspecialchars($msg);
        } else {

            // 4. Insertar perfiles base si no existen
            $perfilesBase = [
                [1, 'Administrador',     'Acceso total al sistema'],
                [2, 'Edicion',           'Puede crear y editar, sin aprobar'],
                [3, 'Aprobador-Edicion', 'Puede aprobar asistencia y editar'],
                [4, 'Visualizacion',     'Solo lectura, sin edición'],
            ];
            foreach ($perfilesBase as [$pid, $pnom, $pdesc]) {
                sqlsrv_query($conn_test,
                    "IF NOT EXISTS (SELECT 1 FROM dbo.dota_perfiles WHERE id_perfil = ?)
                     INSERT INTO dbo.dota_perfiles (id_perfil, nombre, descripcion) VALUES (?, ?, ?)",
                    [$pid, $pid, $pnom, $pdesc]
                );
            }

            // 5. Verificar que el usuario no exista ya
            $chk = sqlsrv_query($conn_test,
                "SELECT 1 FROM dbo.dota_usuarios WHERE usuario = ?", [$usuario]
            );
            if ($chk && sqlsrv_fetch($chk)) {
                $errors[] = "El usuario '$usuario' ya existe en la base de datos.";
            } else {
                // 6. Crear usuario administrador (id_perfil = 1)
                $hash = password_hash($pass_usu, PASSWORD_DEFAULT);
                $stmt = sqlsrv_query($conn_test,
                    "INSERT INTO dbo.dota_usuarios (usuario, password_hash, nombre, apellido, email, id_perfil, activo)
                     VALUES (?, ?, ?, ?, ?, 1, 1)",
                    [$usuario, $hash, $nombre, $apellido ?: null, $email ?: null]
                );

                if ($stmt === false) {
                    $msg = sqlsrv_errors()[0]['message'] ?? 'Error desconocido.';
                    $errors[] = 'Error al crear el usuario: ' . htmlspecialchars($msg);
                } else {
                    sqlsrv_close($conn_test);

                    // 7. Escribir .env
                    $env_content = "DB_SERVER={$db_server}\n"
                                 . "DB_USER={$db_user}\n"
                                 . "DB_PASSWORD={$db_password}\n"
                                 . "DB_NAME={$db_name}\n"
                                 . "DB_NAME2={$db_name2}\n"
                                 . "DB_NAME_RELOJ={$db_reloj}\n";

                    if (file_put_contents($env_file, $env_content) === false) {
                        $errors[] = 'No se pudo escribir config/.env — verifique permisos.';
                    } else {
                        // 8. Crear lock
                        file_put_contents($lock_file, date('Y-m-d H:i:s'));
                        $success = true;
                    }
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
        .setup-card { max-width: 720px; margin: 50px auto 80px; }
        .step-badge { width: 30px; height: 30px; border-radius: 50%; display: inline-flex;
                      align-items: center; justify-content: center; font-weight: bold; font-size: .85rem; }
    </style>
</head>
<body>

<div class="setup-card px-3">
    <div class="text-center mb-4">
        <h2 class="fw-bold">Sistema de Gestión de Contratistas</h2>
        <p class="text-muted mb-0">Condor de Apalta (Ex Almahue)</p>
        <small class="text-muted">Configuración inicial — solo visible la primera vez</small>
    </div>

    <?php if ($success): ?>

    <div class="card shadow border-0">
        <div class="card-body text-center py-5">
            <div class="text-success mb-3" style="font-size:3rem;">&#10003;</div>
            <h4 class="text-success">Sistema configurado correctamente</h4>
            <p class="text-muted mt-2">
                Base de datos conectada y usuario administrador <strong><?= htmlspecialchars($usuario) ?></strong> creado.
            </p>
            <a href="index.php" class="btn btn-primary mt-3 px-5">Ir al inicio de sesión</a>
        </div>
    </div>

    <?php else: ?>

    <?php if (!empty($errors)): ?>
    <div class="alert alert-danger">
        <ul class="mb-0">
            <?php foreach ($errors as $e): ?><li><?= $e ?></li><?php endforeach; ?>
        </ul>
    </div>
    <?php endif; ?>

    <form method="POST" novalidate>

        <!-- Paso 1: Base de datos -->
        <div class="card shadow border-0 mb-4">
            <div class="card-header bg-dark text-white d-flex align-items-center gap-2">
                <span class="step-badge bg-primary text-white">1</span>
                Conexión a Base de Datos
            </div>
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-md-5">
                        <label class="form-label">Servidor (IP o nombre) <span class="text-danger">*</span></label>
                        <input type="text" name="db_server" class="form-control"
                               value="<?= env_val('DB_SERVER', '172.20.20.5') ?>" required>
                        <div class="form-text">Ej: 192.168.1.10 o SERVIDOR\SQLEXPRESS</div>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Usuario BD <span class="text-danger">*</span></label>
                        <input type="text" name="db_user" class="form-control"
                               value="<?= env_val('DB_USER', 'sa') ?>" required>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Contraseña BD</label>
                        <input type="password" name="db_password" class="form-control"
                               value="<?= env_val('DB_PASSWORD') ?>">
                    </div>
                    <div class="col-12">
                        <label class="form-label">BD Principal <span class="text-danger">*</span></label>
                        <input type="text" name="db_name" class="form-control"
                               value="<?= env_val('DB_NAME', 'Fact_contratista') ?>"
                               placeholder="Fact_contratista" required>
                        <div class="form-text">Debe existir y tener las tablas creadas con setup.sql.</div>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">BD Facturador <small class="text-muted">(opcional)</small></label>
                        <input type="text" name="db_name2" class="form-control"
                               value="<?= env_val('DB_NAME2') ?>" placeholder="Facturador_ASanta_Almahue">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">BD Reloj Biométrico <small class="text-muted">(opcional)</small></label>
                        <input type="text" name="db_reloj" class="form-control"
                               value="<?= env_val('DB_NAME_RELOJ') ?>" placeholder="ATT2000">
                    </div>
                </div>
            </div>
        </div>

        <!-- Paso 2: Usuario administrador -->
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
                    <div class="col-md-6">
                        <label class="form-label">Email <small class="text-muted">(para notificaciones)</small></label>
                        <input type="email" name="email" class="form-control"
                               value="<?= htmlspecialchars($_POST['email'] ?? '') ?>">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Usuario (login) <span class="text-danger">*</span></label>
                        <input type="text" name="usuario" class="form-control" autocomplete="off"
                               value="<?= htmlspecialchars($_POST['usuario'] ?? '') ?>" required>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Contraseña <span class="text-danger">*</span></label>
                        <input type="password" name="pass_usu" class="form-control"
                               autocomplete="new-password" required>
                        <div class="form-text">Mínimo 6 caracteres.</div>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Confirmar contraseña <span class="text-danger">*</span></label>
                        <input type="password" name="pass_conf" class="form-control"
                               autocomplete="new-password" required>
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
