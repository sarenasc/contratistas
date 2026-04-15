<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../config/app.php';

header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Cache-Control: post-check=0, pre-check=0", false);
header("Pragma: no-cache");
header("Expires: 0");

require_once __DIR__ . '/../app/Middlewares/auth_guard.php';
require_once __DIR__ . '/../config/conexion.php';
require_once __DIR__ . '/../app/lib/csrf.php';

// Generar token CSRF para la sesión actual
csrf_token();

// Verificar CSRF en toda petición POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) {
        $is_ajax = !empty($_SERVER['HTTP_X_REQUESTED_WITH'])
                    && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
        $is_fetch = !empty($_SERVER['HTTP_ACCEPT'])
                    && str_contains($_SERVER['HTTP_ACCEPT'], 'application/json');
        if ($is_ajax || $is_fetch || !empty($_SERVER['HTTP_X_CSRF_TOKEN'])) {
            http_response_code(403);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['ok' => false, 'error' => 'Token CSRF inválido. Recarga la página e intenta nuevamente.']);
            exit;
        }
        http_response_code(403);
        $ref = filter_var($_SERVER['HTTP_REFERER'] ?? '', FILTER_SANITIZE_URL);
        $redirect = $ref ?: (BASE_URL . '/Inicio.php');
        header('Location: ' . $redirect);
        exit;
    }
}
