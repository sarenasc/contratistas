<?php
require_once __DIR__ . '/../_bootstrap.php';
if (!puede_modulo('reloj')) { http_response_code(403); exit; }

header('Content-Type: application/json; charset=utf-8');

if (!function_exists('ensure_reloj_turno_column')) {
    function ensure_reloj_turno_column($conn): void {
        $sql = "
IF COL_LENGTH('dbo.reloj_trabajador', 'id_turno') IS NULL
BEGIN
    ALTER TABLE dbo.reloj_trabajador ADD id_turno INT NULL;
END
";
        sqlsrv_query($conn, $sql);
    }
}

ensure_reloj_turno_column($conn);

$q              = trim($_GET['q']  ?? '');
$excluir_cont   = (int)($_GET['excluir'] ?? 0);

if (strlen($q) < 2) { echo json_encode([]); exit; }

$params = ["%$q%", "%$q%"];
$extra  = '';
if ($excluir_cont) {
    $extra    = ' AND (t.id_contratista != ? OR t.id_contratista IS NULL)';
    $params[] = $excluir_cont;
}

$rows = sqlsrv_query($conn,
    "SELECT TOP 20 t.id, t.rut, t.nombre, t.id_cargo, t.id_turno,
            c.cargo AS nombre_cargo,
            dc.nombre AS nombre_contratista,
            tr.nombre_turno
     FROM dbo.reloj_trabajador t
     LEFT JOIN dbo.Dota_Cargo        c  ON c.id_cargo = t.id_cargo
     LEFT JOIN dbo.dota_contratista  dc ON dc.id      = t.id_contratista
     LEFT JOIN dbo.dota_turno        tr ON tr.id      = t.id_turno
     WHERE t.activo = 1
       AND (t.nombre LIKE ? OR t.rut LIKE ?)
       $extra
     ORDER BY t.nombre",
    $params);

$result = [];
while ($r = sqlsrv_fetch_array($rows, SQLSRV_FETCH_ASSOC)) {
    $result[] = [
        'id'                  => $r['id'],
        'rut'                 => $r['rut'],
        'nombre'              => $r['nombre'],
        'id_cargo'            => $r['id_cargo'],
        'id_turno'            => $r['id_turno'],
        'nombre_cargo'        => $r['nombre_cargo'] ?? '',
        'nombre_contratista'  => $r['nombre_contratista'] ?? '',
        'nombre_turno'        => $r['nombre_turno'] ?? '',
    ];
}
echo json_encode($result);
