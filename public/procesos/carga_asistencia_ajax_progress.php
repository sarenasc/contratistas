<?php
declare(strict_types=1);

require_once __DIR__ . '/../_bootstrap.php';
header('Content-Type: application/json; charset=utf-8');

$p = $_SESSION['asistencia_progress'] ?? ['pct'=>0,'msg'=>'','done'=>false];
echo json_encode([
  'pct' => (int)($p['pct'] ?? 0),
  'msg' => (string)($p['msg'] ?? ''),
  'done'=> (bool)($p['done'] ?? false),
]);