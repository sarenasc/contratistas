<?php
require_once __DIR__ . '/../_bootstrap.php';
require_once __DIR__ . '/../../scripts/reloj/_rutas.php';
if (!es_admin()) { http_response_code(403); exit; }

header('Content-Type: application/json');

$ip     = trim($_GET['ip']     ?? '');
$puerto = (int)($_GET['puerto'] ?? 4370);

if ($ip === '') {
    echo json_encode(['online' => false, 'error' => 'IP vacía']);
    exit;
}

$socket = @fsockopen($ip, $puerto, $errno, $errstr, 3);
if (!$socket) {
    echo json_encode(['online' => false, 'error' => $errstr]);
    exit;
}
fclose($socket);

$script = PY_INFO;

$cmd    = '"' . PYTHON_BIN . '" "' . $script . '" ' . escapeshellarg($ip) . " $puerto 2>&1";
$output = []; $rc = 0;
exec($cmd, $output, $rc);

$info = ['online' => true];
foreach ($output as $line) {
    if (str_starts_with($line, 'firmware:'))  $info['firmware']  = trim(substr($line, 9));
    if (str_starts_with($line, 'serial:'))    $info['serial']    = trim(substr($line, 7));
    if (str_starts_with($line, 'usuarios:'))  $info['usuarios']  = trim(substr($line, 9));
}

echo json_encode($info);
