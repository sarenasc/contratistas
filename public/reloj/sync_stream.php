<?php
require_once __DIR__ . '/../_bootstrap.php';
require_once __DIR__ . '/../../scripts/reloj/_rutas.php';
if (!puede_modulo('reloj')) { http_response_code(403); exit; }

while (ob_get_level()) ob_end_clean();
header('Content-Type: text/event-stream');
header('Cache-Control: no-cache');
header('X-Accel-Buffering: no');
set_time_limit(180);

$accion  = $_GET['accion'] ?? 'sync';
$id_disp = (int)($_GET['id'] ?? 0);

$scripts = [
    'sync'       => PY_SYNC,
    'limpiar'    => PY_LIMPIAR,
    'importar'   => PY_IMPORTAR,
    'sinc_users' => PY_SINC_USERS,
    'push_areas' => PY_PUSH_AREAS,
    'sync_huellas' => PY_SYNC_HUELLAS,
];

if (!isset($scripts[$accion])) {
    echo "data: " . json_encode("ERROR: accion invalida") . "\n\n";
    echo "data: \"__DONE__\"\n\n";
    flush(); exit;
}

$script = $scripts[$accion];
$cmd    = '"' . PYTHON_BIN . '" "' . $script . '"';
if ($accion === 'limpiar' && $id_disp > 0) $cmd .= " $id_disp";
$cmd   .= " 2>&1";

$proc = popen($cmd, 'r');
if ($proc === false) {
    echo "data: " . json_encode("ERROR: No se pudo iniciar el proceso.") . "\n\n";
} else {
    while (!feof($proc)) {
        $line = fgets($proc, 4096);
        if ($line !== false && trim($line) !== '') {
            echo "data: " . json_encode(trim($line)) . "\n\n";
            flush();
        }
    }
    pclose($proc);
}
echo "data: \"__DONE__\"\n\n";
flush();
