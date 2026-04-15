<?php
if (session_status() === PHP_SESSION_NONE){
    session_start();
}

// Timeout de sesión: 30 minutos de inactividad
define('SESSION_TIMEOUT', 30 * 60);

if (isset($_SESSION['id']) && !empty($_SESSION['id'])) {
    if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity']) > SESSION_TIMEOUT) {
        session_unset();
        session_destroy();
        session_start();
        header("Location:" . BASE_URL . "/Index.php?timeout=1");
        exit;
    }
    $_SESSION['last_activity'] = time();
}

// Si no está logueado, redirigir al login
if (!isset($_SESSION['id']) || empty($_SESSION['id'])) {
    header("Location:" . BASE_URL ."/Index.php");
    exit;
}
?>