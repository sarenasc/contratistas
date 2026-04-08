<?php
// logout.php
session_start();

header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Pragma: no-cache");
header("Expires: 0");

$_SESSION = [];
session_unset();     // Eliminar todas las variables de sesión
session_destroy();   // Destruir la sesión

if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000,
        $params["path"], $params["domain"],
        $params["secure"], $params["httponly"]
    );
}

header("Location: Index.php"); // Redirigir a la página de inicio de sesión
exit();
?>
