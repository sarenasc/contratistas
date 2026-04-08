<?php
declare(strict_types=1);
ob_start();

require_once __DIR__ . '/../_bootstrap.php';
require_once __DIR__ . '/../../app/lib/db.php';

header('Content-Type: application/json; charset=utf-8');
set_time_limit(0);

function norm_key_p2s(string $s): string {
    $s = trim($s);
    $s = preg_replace('/\s+/', ' ', $s);
    return mb_strtoupper($s, 'UTF-8');
}

try {
    $data = $_SESSION['asistencia_upload'] ?? null;
    if (!$data) throw new RuntimeException("No hay datos en sesión. Vuelve al Paso 1.");

    $archivo      = $data['archivo']       ?? '';
    $uniques      = $data['uniques']       ?? null;
    $totalRows    = (int)($data['detected'] ?? 0); // filas ya filtradas
    $filteredFile = $data['filtered_file'] ?? '';
    $obs          = $data['obs']           ?? '';

    if ($archivo === '') throw new RuntimeException("Datos de sesión incompletos. Vuelve al Paso 1.");
    if (!$uniques)       throw new RuntimeException("No están los datos de sesión. Vuelve al Paso 1.");
    if ($filteredFile === '' || !file_exists($filteredFile)) {
        throw new RuntimeException("No se encontró el archivo de datos filtrados. Vuelve al Paso 1 y carga nuevamente.");
    }
    if ($totalRows === 0) throw new RuntimeException("No hay filas detectadas para guardar.");

    $map_responsable_turno = $_POST['map_responsable_turno'] ?? [];
    $map_area              = $_POST['map_area']              ?? [];
    $map_empleador         = $_POST['map_empleador']         ?? [];
    $map_cargo             = $_POST['map_cargo']             ?? [];
    $map_turno             = $_POST['map_turno']             ?? [];
    $filtroTipo  = in_array($_POST['filtro_tipo']  ?? '', ['semana', 'dia', 'todo'], true)
                   ? $_POST['filtro_tipo'] : 'todo';
    $filtroValor = trim((string)($_POST['filtro_valor'] ?? ''));
    $filtroAnio  = (int)($_POST['filtro_anio'] ?? 0);

    // Validar que todos los valores estén asignados
    foreach ($uniques['Area']      as $val) if (empty($map_area[norm_key_p2s($val)]))      throw new RuntimeException("Falta asignar Área: $val");
    foreach ($uniques['Empleador'] as $val) if (empty($map_empleador[norm_key_p2s($val)])) throw new RuntimeException("Falta asignar Empleador: $val");
    foreach ($uniques['Cargo']     as $val) if (empty($map_cargo[norm_key_p2s($val)]))     throw new RuntimeException("Falta asignar Cargo: $val");
    foreach ($uniques['Turno']     as $val) if (empty($map_turno[norm_key_p2s($val)]))     throw new RuntimeException("Falta asignar Turno: $val");

    // Actualizar turno de jefes que rotaron
    foreach ($map_responsable_turno as $jefeId => $turnoId) {
        $jefeId  = (int)$jefeId;
        $turnoId = (int)$turnoId;
        if ($turnoId > 0 && $jefeId > 0) {
            sqlsrv_query($conn, "UPDATE dbo.dota_jefe_area SET id_turno = ? WHERE id = ?", [$turnoId, $jefeId]);
        }
    }

    // Construir mapa area+turno → jefe_id
    $jefeByAreaTurno = [];
    $chkJ = sqlsrv_query($conn, "SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA='dbo' AND TABLE_NAME='dota_jefe_area'");
    if ($chkJ && sqlsrv_fetch($chkJ)) {
        $stmtJ = sqlsrv_query($conn, "SELECT id, id_area, id_turno FROM dbo.dota_jefe_area WHERE activo = 1");
        if ($stmtJ) {
            while ($rj = sqlsrv_fetch_array($stmtJ, SQLSRV_FETCH_ASSOC)) {
                $k = (int)$rj['id_area'] . '_' . (int)($rj['id_turno'] ?? 0);
                $jefeByAreaTurno[$k] = (int)$rj['id'];
            }
            sqlsrv_free_stmt($stmtJ);
        }
    }
    if ($chkJ) sqlsrv_free_stmt($chkJ);

    // Persistir mapeos en dota_asistencia_mapa (si la tabla existe)
    $chkMapaP2 = sqlsrv_query($conn, "SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA='dbo' AND TABLE_NAME='dota_asistencia_mapa'");
    if ($chkMapaP2 && sqlsrv_fetch($chkMapaP2)) {
        $upsert = "MERGE dbo.dota_asistencia_mapa AS tgt
                   USING (SELECT ? AS tipo, ? AS valor_excel, ? AS id_sistema) AS src
                      ON tgt.tipo = src.tipo AND tgt.valor_excel = src.valor_excel
                   WHEN MATCHED THEN UPDATE SET tgt.id_sistema = src.id_sistema
                   WHEN NOT MATCHED THEN INSERT (tipo, valor_excel, id_sistema) VALUES (src.tipo, src.valor_excel, src.id_sistema);";
        $pairs = [
            ['area',      $map_area],
            ['empleador', $map_empleador],
            ['cargo',     $map_cargo],
            ['turno',     $map_turno],
        ];
        foreach ($pairs as [$tipo, $map]) {
            foreach ($map as $valor_excel => $id_sis) {
                $id_sis = (int)$id_sis;
                if ($id_sis > 0) {
                    sqlsrv_query($conn, $upsert, [$tipo, (string)$valor_excel, $id_sis]);
                }
            }
        }
    }

    // Guardar estado para los chunks — sin re-leer el Excel
    $_SESSION['asistencia_paso2_state'] = [
        'archivo'         => $archivo,
        'filtered_file'   => $filteredFile,
        'total_rows'      => $totalRows,
        'file_offset'     => 0,
        'next_line'       => 0,
        'chunkSize'       => 800,
        'inserted'        => 0,
        'map_area'        => $map_area,
        'map_empleador'   => $map_empleador,
        'map_cargo'       => $map_cargo,
        'map_turno'       => $map_turno,
        'jefeByAreaTurno' => $jefeByAreaTurno,
        'obs'             => $obs,
    ];

    session_write_close();
    ob_end_clean();
    echo json_encode(['ok' => true, 'totalRows' => $totalRows]);

} catch (Throwable $e) {
    session_write_close();
    ob_end_clean();
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
