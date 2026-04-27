<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['id_usuario']) || empty($_SESSION['id_usuario'])) {
    header("Location: " . BASE_URL . "/index.php");
    exit;
}

// Verificar que el usuario sigue activo en BD (cada 5 minutos)
$_now = time();
if (!isset($_SESSION['last_verified']) || ($_now - $_SESSION['last_verified']) > 300) {
    $__stmt = sqlsrv_query($conn,
        "SELECT activo FROM dbo.dota_usuarios WHERE id_usuario = ?",
        [(int)$_SESSION['id_usuario']]
    );
    $__row = $__stmt ? sqlsrv_fetch_array($__stmt, SQLSRV_FETCH_ASSOC) : null;
    if (!$__row || !(int)$__row['activo']) {
        session_destroy();
        header("Location: " . BASE_URL . "/index.php?msg=sesion_expirada");
        exit;
    }
    $_SESSION['last_verified'] = $_now;
    unset($__stmt, $__row);
}
unset($_now);
