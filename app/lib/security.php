<?php
/**
 * Configuración centralizada de seguridad HTTP.
 * Llamado desde _bootstrap.php antes de cualquier salida.
 */

/* ══════════════════════════════════════════════════════════════
   CORS — solo acepta el mismo origen que sirve la app.
   Los endpoints AJAX que vengan de otro origen reciben 403.
══════════════════════════════════════════════════════════════ */
function enforce_same_origin_cors(): void
{
    $origin = $_SERVER['HTTP_ORIGIN'] ?? null;
    if ($origin === null) {
        // Petición sin Origin → mismo origen o herramienta directa (curl, etc.)
        // No se envía CORS header → el navegador aplica same-origin policy.
        return;
    }

    // Construir el origen propio desde la petición actual
    $scheme   = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host     = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $own_origin = $scheme . '://' . $host;

    if (rtrim($origin, '/') !== rtrim($own_origin, '/')) {
        // Origen desconocido — rechazar
        http_response_code(403);
        // Respuesta mínima sin revelar detalles internos
        header('Content-Type: text/plain; charset=utf-8');
        echo 'Forbidden';
        exit;
    }

    // Origen válido — reflejar solo ese origen (no wildcard *)
    header('Access-Control-Allow-Origin: ' . $origin);
    header('Access-Control-Allow-Credentials: true');
    header('Access-Control-Allow-Methods: GET, POST');
    header('Access-Control-Allow-Headers: Content-Type, X-Requested-With');
    header('Vary: Origin');
}

/* ══════════════════════════════════════════════════════════════
   SECURITY HEADERS — aplica a todas las respuestas HTML.
   Para endpoints AJAX llama también enforce_same_origin_cors().
══════════════════════════════════════════════════════════════ */
function apply_security_headers(): void
{
    // Evitar que la página sea embebida en iframe (clickjacking)
    header('X-Frame-Options: DENY');

    // Evitar que el navegador adivine el content-type
    header('X-Content-Type-Options: nosniff');

    // No enviar Referer completo a terceros
    header('Referrer-Policy: strict-origin-when-cross-origin');

    // Deshabilitar features del navegador que la app no usa
    header('Permissions-Policy: geolocation=(), microphone=(), camera=(), payment=()');

    // Content-Security-Policy
    // - Bootstrap y Select2 se cargan desde cdn.jsdelivr.net (CDN de confianza)
    // - La app usa <script> y <style> inline → se requiere 'unsafe-inline'
    // - frame-ancestors 'none' duplica X-Frame-Options para navegadores modernos
    $csp = implode('; ', [
        "default-src 'self'",
        "script-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net",
        "style-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net https://fonts.googleapis.com https://fonts.gstatic.com",
        "img-src 'self' data: blob:",
        "font-src 'self' https://cdn.jsdelivr.net https://fonts.googleapis.com https://fonts.gstatic.com",
        "connect-src 'self' https://cdn.jsdelivr.net",
        "object-src 'none'",
        "base-uri 'self'",
        "form-action 'self'",
        "frame-ancestors 'none'",
    ]);
    header('Content-Security-Policy: ' . $csp);
}
