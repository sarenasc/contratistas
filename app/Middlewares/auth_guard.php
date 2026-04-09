<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['id_usuario']) || empty($_SESSION['id_usuario'])) {
    header("Location: " . BASE_URL . "/index.php");
    exit;
}
