<?php
declare(strict_types=1);
ob_start();

require_once __DIR__ . '/../_bootstrap.php';
require_once __DIR__ . '/../../app/lib/db.php';

header('Content-Type: application/json; charset=utf-8');
set_time_limit(0);

function norm_key_p2c(string $s): string {
    $s = trim($s);
    $s = preg_replace('/\s+/', ' ', $s);
    return mb_strtoupper($s, 'UTF-8');
}

try {
    $state = $_SESSION['asistencia_paso2_state'] ?? null;
    if (!$state) throw new RuntimeException("Sin estado de sesión. Reinicia el proceso desde el Paso 1.");

    $archivo         = $state['archivo'];
    $filteredFile    = $state['filtered_file'];
    $totalRows       = $state['total_rows'];
    $fileOffset      = $state['file_offset'];
    $nextLine        = $state['next_line'];
    $chunkSize       = $state['chunkSize'];
    $inserted        = $state['inserted'];
    $map_area        = $state['map_area'];
    $map_empleador   = $state['map_empleador'];
    $map_cargo       = $state['map_cargo'];
    $map_turno       = $state['map_turno'];
    $jefeByAreaTurno = $state['jefeByAreaTurno'];
    $obs             = $state['obs'] ?? null;

    if (!file_exists($filteredFile)) {
        throw new RuntimeException("No se encontró el archivo de datos filtrados. Reinicia desde Paso 1.");
    }

    // Leer chunkSize líneas desde el offset guardado
    $fp = fopen($filteredFile, 'r');
    if (!$fp) throw new RuntimeException("No se pudo abrir el archivo de datos filtrados.");
    fseek($fp, $fileOffset);

    $rows = [];
    for ($i = 0; $i < $chunkSize; $i++) {
        $line = fgets($fp);
        if ($line === false) break;
        $decoded = json_decode(trim($line), true);
        if ($decoded !== null) $rows[] = $decoded;
    }
    $newOffset = ftell($fp);
    fclose($fp);

    $sqlInsert = "
        INSERT INTO dbo.dota_asistencia_carga (
            fecha, semana, responsable, area, empleador, cargo,
            rut, nombre, sexo, turno, jornada, hhee, especie, obs, registro, id_jefe
        )
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ";

    foreach ($rows as $rowNum => $row) {
        $fechaStr = trim($row['FECHA'] ?? '');
        if ($fechaStr === '') {
            $lineNum = $nextLine + $rowNum + 1;
            sqlsrv_query($conn, "DELETE FROM dbo.dota_asistencia_carga WHERE registro = ?", [$archivo]);
            unset($_SESSION['asistencia_paso2_state']);
            throw new RuntimeException("Fecha vacía en línea #{$lineNum}. Se revirtieron {$inserted} registros.");
        }
        try {
            $dt = new DateTime($fechaStr);
        } catch (Exception $ex) {
            $lineNum = $nextLine + $rowNum + 1;
            sqlsrv_query($conn, "DELETE FROM dbo.dota_asistencia_carga WHERE registro = ?", [$archivo]);
            unset($_SESSION['asistencia_paso2_state']);
            throw new RuntimeException("Fecha inválida '{$fechaStr}' en línea #{$lineNum}. Se revirtieron {$inserted} registros.");
        }

        $area_id      = (int)$map_area[norm_key_p2c($row['AREA']      ?? '')];
        $empleador_id = (int)$map_empleador[norm_key_p2c($row['EMPLEADOR'] ?? '')];
        $cargo_id     = (int)$map_cargo[norm_key_p2c($row['CARGO']    ?? '')];
        $turno_id     = (int)$map_turno[norm_key_p2c($row['TURNO']    ?? '')];

        $id_jefe = $jefeByAreaTurno[$area_id . '_' . $turno_id]
                ?? $jefeByAreaTurno[$area_id . '_0']
                ?? null;

        $eRaw = trim((string)($row['ESPECIE'] ?? ''));
        $especie_val = ($eRaw !== '' && ($eRaw[0] ?? '') !== '#') ? $eRaw : null;

        try {
            db_query($conn, $sqlInsert, [
                $dt,
                (int)$row['SEMANA'],
                (string)$row['RESPONSABLE'],
                $area_id,
                $empleador_id,
                $cargo_id,
                (string)$row['RUT'],
                (string)$row['NOMBRE'],
                (string)$row['SEXO'],
                $turno_id,
                (float)$row['JORNADA'],
                (float)$row['HHEE'],
                $especie_val,
                ($obs !== '' ? $obs : null),
                $archivo,
                $id_jefe,
            ], "INSERT línea " . ($nextLine + $rowNum + 1));
        } catch (RuntimeException $ex) {
            $lineNum = $nextLine + $rowNum + 1;
            sqlsrv_query($conn, "DELETE FROM dbo.dota_asistencia_carga WHERE registro = ?", [$archivo]);
            unset($_SESSION['asistencia_paso2_state']);
            throw new RuntimeException("Error SQL en línea #{$lineNum}: " . $ex->getMessage() . " — Se revirtieron {$inserted} registros.");
        }

        $inserted++;
    }

    $nextLine  += count($rows);
    $done       = ($nextLine >= $totalRows || empty($rows));
    $pct        = (int)min(100, round($nextLine / max(1, $totalRows) * 100));

    if ($done) {
        // Registrar el lote en dota_asistencia_lote (estado inicial: pendiente)
        $semana_lote = $state['semana_lote'] ?? null;
        $anio_lote   = $state['anio_lote']   ?? (int)date('Y');
        $id_usuario_carga = $_SESSION['id_usuario'] ?? null;

        sqlsrv_query($conn,
            "IF NOT EXISTS (SELECT 1 FROM dbo.dota_asistencia_lote WHERE registro = ?)
             INSERT INTO dbo.dota_asistencia_lote (registro, id_usuario_carga, semana, anio, estado)
             VALUES (?, ?, ?, ?, 'pendiente')",
            [$archivo, $archivo, $id_usuario_carga, $semana_lote, $anio_lote]
        );

        unset($_SESSION['asistencia_paso2_state'], $_SESSION['asistencia_upload']);
        session_write_close();
        ob_end_clean();
        echo json_encode([
            'ok'       => true,
            'done'     => true,
            'pct'      => 100,
            'inserted' => $inserted,
            'total'    => $totalRows,
            'registro' => $archivo,
            'msg'      => "Carga completada. Registros insertados: {$inserted}",
        ]);
    } else {
        $state['file_offset'] = $newOffset;
        $state['next_line']   = $nextLine;
        $state['inserted']    = $inserted;
        $_SESSION['asistencia_paso2_state'] = $state;
        session_write_close();
        ob_end_clean();
        echo json_encode([
            'ok'       => true,
            'done'     => false,
            'pct'      => $pct,
            'inserted' => $inserted,
            'total'    => $totalRows,
            'msg'      => "Insertando... {$nextLine} de {$totalRows}",
        ]);
    }

} catch (Throwable $e) {
    session_write_close();
    ob_end_clean();
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
