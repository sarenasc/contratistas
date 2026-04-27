<?php
require_once __DIR__ . '/../_bootstrap.php';
require_once __DIR__ . '/../../scripts/reloj/_rutas.php';
if (!puede_modulo('reloj')) { http_response_code(403); exit; }

header('Content-Type: application/json; charset=utf-8');

$id_numero = (int)($_POST['id_numero'] ?? 0);
$fecha     = trim($_POST['fecha'] ?? '');

if ($id_numero <= 0 || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha)) {
    echo json_encode(['ok' => false, 'error' => 'Parámetros inválidos.']);
    exit;
}

$cmd    = '"' . PYTHON_BIN . '" "' . PY_ELIMINAR_MARC . '" ' . $id_numero . ' ' . $fecha . ' 2>&1';
$output = shell_exec($cmd);

// El script imprime JSON en la última línea
$lines = array_filter(array_map('trim', explode("\n", trim($output ?? ''))));
$last  = end($lines) ?: '{}';
$data  = json_decode($last, true);

if (!is_array($data)) {
    echo json_encode(['ok' => false, 'error' => 'Error en script Python.', 'raw' => $output]);
    exit;
}

echo json_encode($data);
