<?php
/**
 * CSRF helpers
 *
 * csrf_token()  — genera (si no existe) y retorna el token de la sesión
 * csrf_field()  — retorna el <input hidden> listo para insertar en formularios HTML
 * csrf_verify() — valida el token recibido (POST body o header HTTP)
 */

function csrf_token(): string {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrf_field(): string {
    return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') . '">';
}

function csrf_verify(): bool {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    if (empty($_SESSION['csrf_token'])) {
        return false;
    }
    // Aceptar token desde POST body o desde header HTTP (para peticiones AJAX/fetch)
    $token = $_POST['csrf_token']
          ?? $_SERVER['HTTP_X_CSRF_TOKEN']
          ?? '';
    return hash_equals($_SESSION['csrf_token'], (string)$token);
}
