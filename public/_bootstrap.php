<?php
require_once __DIR__ . '/../app/lib/security.php';

// ── Session hardening (antes de session_start) ────────────────────────────
if (session_status() === PHP_SESSION_NONE) {
    $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
    session_set_cookie_params([
        'lifetime' => 0,           // Hasta cerrar el navegador
        'path'     => '/',
        'secure'   => $secure,     // Solo HTTPS si está disponible
        'httponly' => true,        // JS no puede leer la cookie de sesión
        'samesite' => 'Lax',       // Protección CSRF; Strict rompe navegación externa
    ]);
    ini_set('session.use_strict_mode',   '1');   // Rechaza IDs de sesión no iniciados por servidor
    ini_set('session.gc_maxlifetime',    '7200'); // 2 horas máximo de inactividad
    ini_set('session.use_only_cookies',  '1');   // No permitir session ID en URL
    session_start();
}

require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../app/lib/permisos.php';

// ── CORS: rechazar peticiones de orígenes distintos al propio ────────────
enforce_same_origin_cors();

// ── Security headers (aplican a todas las páginas) ────────────────────────
apply_security_headers();

// ── Cache: no guardar páginas autenticadas ────────────────────────────────
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Pragma: no-cache");
header("Expires: 0");

require_once __DIR__ . '/../config/conexion.php';
require_once __DIR__ . '/../app/Middlewares/auth_guard.php';
