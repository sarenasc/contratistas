<?php
declare(strict_types=1);
require_once __DIR__ . '/../_bootstrap.php';
require_once __DIR__ . '/../../app/lib/db.php';
require_once __DIR__ . '/../../vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Font;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;

$id_factura = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($id_factura <= 0) { http_response_code(400); exit('ID inválido.'); }

/* ── Cabecera ── */
$fac_stmt = sqlsrv_query($conn,
    "SELECT id, semana, anio, version, obs, estado, fecha_creacion, total_neto,
            tot_base_jorn, tot_base_hhee, tot_pct_jorn, tot_pct_hhee,
            tot_bono, tot_factura, descuento
     FROM dbo.dota_factura WHERE id=?", [$id_factura]);
$fac = $fac_stmt ? sqlsrv_fetch_array($fac_stmt, SQLSRV_FETCH_ASSOC) : null;
if (!$fac) { http_response_code(404); exit('Proforma no encontrada.'); }

$semana  = (int)$fac['semana'];
$anio    = (int)$fac['anio'];
$version = (int)$fac['version'];

/* ── Detalle por contratista+cargo (de la proforma guardada) ── */
$det_stmt = db_query($conn,
    "SELECT fd.id_contratista, c.nombre AS contratista,
            fd.cargo_nombre, fd.tarifa_nombre, fd.especial, fd.esp_nom,
            fd.registros, fd.jornada, fd.hhee,
            fd.base_jorn, fd.base_hhee, fd.pct_jorn, fd.pct_hhee,
            fd.bono, fd.total
     FROM dbo.dota_factura_detalle fd
     JOIN dbo.dota_contratista c ON c.id = fd.id_contratista
     WHERE fd.id_factura = ?
     ORDER BY c.nombre, fd.cargo_nombre",
    [$id_factura], "detalle exportar");

$detalle = [];
while ($r = sqlsrv_fetch_array($det_stmt, SQLSRV_FETCH_ASSOC)) $detalle[] = $r;

/* Agrupar por contratista */
$por_cont = [];
foreach ($detalle as $d) {
    $cid = (int)$d['id_contratista'];
    if (!isset($por_cont[$cid])) {
        $por_cont[$cid] = [
            'nombre'      => $d['contratista'],
            'filas'       => [],
            'tot_jorn'    => 0.0,
            'tot_hhee'    => 0.0,
            'tot_vj'      => 0.0,  // valor jornadas (base_jorn + pct_jorn)
            'tot_vh'      => 0.0,  // valor HHEE     (base_hhee + pct_hhee)
            'tot_total'   => 0.0,
        ];
    }
    $por_cont[$cid]['filas'][]      = $d;
    $por_cont[$cid]['tot_jorn']    += (float)$d['jornada'];
    $por_cont[$cid]['tot_hhee']    += (float)$d['hhee'];
    $por_cont[$cid]['tot_vj']      += (float)$d['base_jorn'] + (float)$d['pct_jorn'];
    $por_cont[$cid]['tot_vh']      += (float)$d['base_hhee'] + (float)$d['pct_hhee'];
    $por_cont[$cid]['tot_total']   += (float)$d['total'];
}

/* ── DIARIO: consulta raw de asistencia para esta semana ── */
$sql_diario = "
    SELECT
        a.empleador,
        c.nombre           AS contratista,
        a.cargo            AS id_cargo,
        dc.cargo           AS cargo_nombre,
        a.fecha,
        a.jornada,
        a.hhee,
        a.especie,
        a.rut,
        a.nombre           AS trabajador,
        a.turno,
        tt.Tipo_tarifa     AS tar_nom,
        tt.ValorContratista AS tar_valor,
        tt.horasExtras      AS tar_hhee,
        tt.PorcContrastista AS tar_porc,
        tt.porc_hhee        AS tar_porc_hhee,
        tt.bono             AS tar_bono,
        te.esp_id,
        te.esp_nom,
        te.esp_valor,
        te.esp_hhee,
        te.esp_porc,
        te.esp_porc_hhee,
        te.esp_bono,
        ar.Area AS area_nombre
    FROM dbo.dota_asistencia_carga a
    LEFT JOIN dbo.dota_contratista c   ON c.id        = a.empleador
    LEFT JOIN dbo.Dota_Cargo dc        ON dc.id_cargo = a.cargo
    LEFT JOIN dbo.Area ar              ON ar.id_area  = a.area
    OUTER APPLY (
        SELECT TOP 1 vd2.id_tipo_tarifa
        FROM dbo.Dota_Valor_Dotacion vd2
        WHERE vd2.id_cargo = a.cargo
          AND (vd2.id_especie IS NULL OR vd2.id_especie IN (
                SELECT e.id_especie FROM dbo.especie e WHERE e.especie = a.especie))
        ORDER BY CASE WHEN vd2.id_especie IS NOT NULL THEN 0 ELSE 1 END
    ) vd
    LEFT JOIN dbo.Dota_tipo_tarifa tt
           ON tt.id_tipo_tarifa = vd.id_tipo_tarifa AND tt.tarifa_activa = 1
    OUTER APPLY (
        SELECT TOP 1 src.esp_id, src.esp_nom, src.esp_valor, src.esp_hhee,
                     src.esp_porc, src.esp_porc_hhee, src.esp_bono
        FROM (
            SELECT dte.id_tipo AS esp_id, dte.tipo_Tarifa AS esp_nom,
                   ved.valor AS esp_valor, ved.valor_HHEE AS esp_hhee,
                   CAST(NULL AS DECIMAL(18,6)) AS esp_porc,
                   CAST(NULL AS DECIMAL(18,6)) AS esp_porc_hhee,
                   CAST(NULL AS DECIMAL(18,2)) AS esp_bono, 0 AS prioridad
            FROM dbo.Dota_ValorEspecial_Dotacion ved
            JOIN dbo.Dota_Tarifa_Especiales dte ON dte.id_tipo = ved.tipo_tarifa
            WHERE CAST(ved.fecha AS DATE) = CAST(a.fecha AS DATE) AND ved.cargo = a.cargo
              AND (ved.especie IS NULL OR ved.especie IN (
                    SELECT id_especie FROM dbo.especie WHERE especie = a.especie))
            UNION ALL
            SELECT id_tipo, tipo_tarifa, valor_base, HH_EE_base,
                   porc_contratista, porc_hhee, CAST(NULL AS DECIMAL(18,2)), 1
            FROM dbo.Dota_Tarifa_Especiales
            WHERE fecha IS NOT NULL AND CAST(fecha AS DATE) = CAST(a.fecha AS DATE)
        ) src ORDER BY src.prioridad
    ) te
    WHERE a.semana = ? AND YEAR(a.fecha) = ?
      AND (a.jornada > 0 OR a.hhee > 0)
    ORDER BY c.nombre, a.fecha, dc.cargo, a.nombre
";

$diario_rows = [];
// pivot_dia[contratista][area|cargo_key][fecha_key]  = jornada
// pivot_hhee[contratista][area|cargo_key][fecha_key] = hhee
// pivot_dot[contratista][turno|cargo_key][fecha_key] = count trabajadores
$pivot_dia  = [];
$pivot_hhee = [];
$pivot_dot  = [];
$all_dates  = [];  // fecha_key => fecha_display (DD/MM)

try {
    $dst = db_query($conn, $sql_diario, [$semana, $anio], "diario exportar");
    while ($r = sqlsrv_fetch_array($dst, SQLSRV_FETCH_ASSOC)) {
        $esp        = $r['esp_id'] !== null;
        $v_dia      = $esp && $r['esp_valor']     !== null ? (float)$r['esp_valor']     : (float)$r['tar_valor'];
        $v_hhee_tar = $esp && $r['esp_hhee']      !== null ? (float)$r['esp_hhee']      : (float)$r['tar_hhee'];
        $porc_jorn  = $esp && $r['esp_porc']      !== null ? (float)$r['esp_porc']      : (float)$r['tar_porc'];
        $porc_hhee  = $esp && $r['esp_porc_hhee'] !== null ? (float)$r['esp_porc_hhee'] : (float)$r['tar_porc_hhee'];
        $bono       = $esp && $r['esp_bono']      !== null ? (float)$r['esp_bono']      : ($esp ? 0.0 : (float)$r['tar_bono']);

        $jornada   = (float)$r['jornada'];
        $hhee      = (float)$r['hhee'];
        $base_jorn = $jornada * $v_dia;
        $base_hhee = $hhee    * $v_hhee_tar;
        $pct_jorn  = $base_jorn * $porc_jorn;
        $pct_hhee  = $base_hhee * $porc_hhee;
        $emp_jorn  = $base_jorn + $pct_jorn;
        $emp_hhee  = $base_hhee + $pct_hhee;
        $total_fac = $emp_jorn + $emp_hhee + $bono;

        $fecha_obj  = $r['fecha'] instanceof DateTime ? $r['fecha'] : new DateTime(substr((string)$r['fecha'], 0, 10));
        $fecha_key  = $fecha_obj->format('Y-m-d');
        $fecha_disp = $fecha_obj->format('d/m');
        $fecha_str  = $fecha_obj->format('d/m/Y');

        if (!isset($all_dates[$fecha_key])) $all_dates[$fecha_key] = $fecha_disp;

        $diario_rows[] = [
            'semana'      => $semana,
            'fecha'       => $fecha_str,
            'contratista' => $r['contratista'] ?? '',
            'area'        => $r['area_nombre'] ?? '',
            'cargo'       => $r['cargo_nombre'] ?? '',
            'tarifa'      => $esp ? ($r['esp_nom'] ?? '') : ($r['tar_nom'] ?? ''),
            'turno'       => $r['turno'] ?? '',
            'jornada'     => $jornada,
            'val_jorn'    => $emp_jorn,
            'hhee'        => $hhee,
            'total'       => $total_fac,
        ];

        /* ── Acumular para pivots ── */
        $cont_key = $r['contratista'] ?? '';
        $area_nom = $r['area_nombre'] ?? '';
        $carg_nom = $r['cargo_nombre'] ?? '';
        $turn_nom = $r['turno'] ?? '';
        $rc_key   = $area_nom . '||' . $carg_nom;
        $tc_key   = $turn_nom . '||' . $carg_nom;

        if (!isset($pivot_dia[$cont_key][$rc_key])) {
            $pivot_dia[$cont_key][$rc_key]  = ['area' => $area_nom, 'cargo' => $carg_nom, 'fechas' => []];
        }
        if (!isset($pivot_hhee[$cont_key][$rc_key])) {
            $pivot_hhee[$cont_key][$rc_key] = ['area' => $area_nom, 'cargo' => $carg_nom, 'fechas' => []];
        }
        // pivot_dot: bloque = "Area — Turno", fila = cargo, valor = conteo trabajadores
        $dot_block = ($area_nom !== '' ? $area_nom : '(sin área)') . ' — ' . ($turn_nom !== '' ? $turn_nom : '(sin turno)');
        if (!isset($pivot_dot[$dot_block][$carg_nom])) {
            $pivot_dot[$dot_block][$carg_nom] = ['label' => $carg_nom, 'fechas' => []];
        }
        $pivot_dia[$cont_key][$rc_key]['fechas'][$fecha_key]   = ($pivot_dia[$cont_key][$rc_key]['fechas'][$fecha_key]   ?? 0) + $jornada;
        $pivot_hhee[$cont_key][$rc_key]['fechas'][$fecha_key]  = ($pivot_hhee[$cont_key][$rc_key]['fechas'][$fecha_key]  ?? 0) + $hhee;
        $pivot_dot[$dot_block][$carg_nom]['fechas'][$fecha_key] = ($pivot_dot[$dot_block][$carg_nom]['fechas'][$fecha_key] ?? 0) + 1;
    }
} catch (Throwable $ignore) {}

ksort($all_dates);  // ordenar fechas cronológicamente

/* ════════════════════════════════════════════
   HELPERS DE ESTILO
═══════════════════════════════════════════ */
function styleHeader(array $params): array {
    return [
        'font'      => ['bold' => true, 'color' => ['argb' => 'FFFFFFFF']],
        'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FF2C3E50']],
        'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER,
                        'vertical'   => Alignment::VERTICAL_CENTER, 'wrapText' => true],
        'borders'   => ['allBorders' => ['borderStyle' => Border::BORDER_THIN,
                                         'color' => ['argb' => 'FFAAAAAA']]],
    ];
}
function styleTotal(string $bg = 'FF1ABC9C'): array {
    return [
        'font'      => ['bold' => true],
        'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => $bg]],
        'alignment' => ['horizontal' => Alignment::HORIZONTAL_RIGHT],
        'borders'   => ['allBorders' => ['borderStyle' => Border::BORDER_THIN,
                                         'color' => ['argb' => 'FFAAAAAA']]],
    ];
}
function styleData(): array {
    return [
        'borders'   => ['allBorders' => ['borderStyle' => Border::BORDER_THIN,
                                         'color' => ['argb' => 'FFDDDDDD']]],
    ];
}
$fmt_num  = '#,##0';
$fmt_dec  = '#,##0.00';

/* ════════════════════════════════════════════
   SPREADSHEET
═══════════════════════════════════════════ */
$ss = new Spreadsheet();
$ss->getProperties()
   ->setTitle("Proforma S{$semana}/{$anio} v{$version}")
   ->setCreator('Sistema Contratista');

/* ══════════════════════════════════════════
   HOJA 1 — RESUMEN
══════════════════════════════════════════ */
$ws = $ss->getActiveSheet();
$ws->setTitle('RESUMEN');

// Título
$ws->mergeCells('A1:F1');
$ws->setCellValue('A1', "FACTURACIÓN CONTRATISTAS — Semana {$semana}/{$anio}  (v{$version})");
$ws->getStyle('A1')->applyFromArray([
    'font'      => ['bold' => true, 'size' => 13],
    'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
]);
$ws->getRowDimension(1)->setRowHeight(24);

// Sub-info
$ws->mergeCells('A2:F2');
$obs_txt = $fac['obs'] ? 'Obs: ' . $fac['obs'] : '';
$ws->setCellValue('A2', $obs_txt);
$ws->getStyle('A2')->applyFromArray([
    'font' => ['italic' => true, 'color' => ['argb' => 'FF666666']],
]);

// Headers
$hdrs = ['Empleador', 'Jornadas', 'HH.EE.', 'Valor Jornadas', 'Valor HH.EE.', 'Total'];
$cols = ['A', 'B', 'C', 'D', 'E', 'F'];
foreach ($hdrs as $i => $h) {
    $ws->setCellValue($cols[$i] . '4', $h);
}
$ws->getStyle('A4:F4')->applyFromArray(styleHeader([]));
$ws->getRowDimension(4)->setRowHeight(20);

$row = 5;
$sum_start = $row;
foreach ($por_cont as $cont) {
    $ws->setCellValue('A' . $row, $cont['nombre']);
    $ws->setCellValue('B' . $row, $cont['tot_jorn']);
    $ws->setCellValue('C' . $row, $cont['tot_hhee']);
    $ws->setCellValue('D' . $row, $cont['tot_vj']);
    $ws->setCellValue('E' . $row, $cont['tot_vh']);
    $ws->setCellValue('F' . $row, $cont['tot_total']);
    $ws->getStyle("A{$row}:F{$row}")->applyFromArray(styleData());
    $ws->getStyle("B{$row}:F{$row}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
    foreach (['B','C'] as $c) {
        $ws->getStyle("{$c}{$row}")->getNumberFormat()->setFormatCode($fmt_dec);
    }
    foreach (['D','E','F'] as $c) {
        $ws->getStyle("{$c}{$row}")->getNumberFormat()->setFormatCode($fmt_num);
    }
    $row++;
}

// Total row
$sum_end = $row - 1;
$ws->setCellValue('A' . $row, 'TOTAL');
foreach (['B','C','D','E','F'] as $c) {
    $ws->setCellValue("{$c}{$row}", "=SUM({$c}{$sum_start}:{$c}{$sum_end})");
}
$ws->getStyle("A{$row}:F{$row}")->applyFromArray(styleTotal());
foreach (['B','C'] as $c) {
    $ws->getStyle("{$c}{$row}")->getNumberFormat()->setFormatCode($fmt_dec);
}
foreach (['D','E','F'] as $c) {
    $ws->getStyle("{$c}{$row}")->getNumberFormat()->setFormatCode($fmt_num);
}

// Column widths
$ws->getColumnDimension('A')->setWidth(30);
foreach (['B','C'] as $c) $ws->getColumnDimension($c)->setWidth(12);
foreach (['D','E','F'] as $c) $ws->getColumnDimension($c)->setWidth(16);

/* ══════════════════════════════════════════
   HOJAS POR CONTRATISTA
══════════════════════════════════════════ */
foreach ($por_cont as $cont) {
    $sheet_name = mb_substr(preg_replace('/[\/\\\?\*\[\]:]/', '_', $cont['nombre']), 0, 31);
    $ws2 = $ss->createSheet();
    $ws2->setTitle($sheet_name);

    // Título
    $ws2->mergeCells('A1:H1');
    $ws2->setCellValue('A1', $cont['nombre'] . " — Semana {$semana}/{$anio}");
    $ws2->getStyle('A1')->applyFromArray([
        'font'      => ['bold' => true, 'size' => 12],
        'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
    ]);
    $ws2->getRowDimension(1)->setRowHeight(22);

    // Subtotales en fila 3
    $ws2->setCellValue('A3', 'Subtotales:');
    $ws2->setCellValue('D3', $cont['tot_jorn']);
    $ws2->setCellValue('E3', $cont['tot_hhee']);
    $ws2->setCellValue('F3', $cont['tot_vj']);
    $ws2->setCellValue('G3', $cont['tot_vh']);
    $ws2->setCellValue('H3', $cont['tot_total']);
    $ws2->getStyle('A3:H3')->applyFromArray([
        'font' => ['bold' => true, 'italic' => true],
        'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FFF0F0F0']],
        'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['argb' => 'FFCCCCCC']]],
    ]);
    foreach (['D3','E3'] as $c) {
        $ws2->getStyle($c)->getNumberFormat()->setFormatCode($fmt_dec);
    }
    foreach (['F3','G3','H3'] as $c) {
        $ws2->getStyle($c)->getNumberFormat()->setFormatCode($fmt_num);
    }

    // Headers fila 4
    $hdrs2 = ['Semana', 'Labor', 'Tarifa', 'Jornadas', 'HH.EE.', 'Valor Jornadas', 'Valor HH.EE.', 'Total'];
    $cols2 = ['A','B','C','D','E','F','G','H'];
    foreach ($hdrs2 as $i => $h) {
        $ws2->setCellValue($cols2[$i] . '4', $h);
    }
    $ws2->getStyle('A4:H4')->applyFromArray(styleHeader([]));
    $ws2->getRowDimension(4)->setRowHeight(20);

    $row2 = 5;
    foreach ($cont['filas'] as $f) {
        $ws2->setCellValue('A' . $row2, $semana);
        $labor = $f['cargo_nombre'];
        if ($f['especial'] && $f['esp_nom']) $labor .= ' (' . $f['esp_nom'] . ')';
        $ws2->setCellValue('B' . $row2, $labor);
        $ws2->setCellValue('C' . $row2, $f['tarifa_nombre']);
        $ws2->setCellValue('D' . $row2, (float)$f['jornada']);
        $ws2->setCellValue('E' . $row2, (float)$f['hhee']);
        $vj = (float)$f['base_jorn'] + (float)$f['pct_jorn'];
        $vh = (float)$f['base_hhee'] + (float)$f['pct_hhee'];
        $ws2->setCellValue('F' . $row2, $vj);
        $ws2->setCellValue('G' . $row2, $vh);
        $ws2->setCellValue('H' . $row2, (float)$f['total']);
        $ws2->getStyle("A{$row2}:H{$row2}")->applyFromArray(styleData());
        if ($f['especial']) {
            $ws2->getStyle("A{$row2}:H{$row2}")->getFill()
                ->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FFFFF3CD');
        }
        foreach (['D','E'] as $c) {
            $ws2->getStyle("{$c}{$row2}")->getNumberFormat()->setFormatCode($fmt_dec);
        }
        foreach (['F','G','H'] as $c) {
            $ws2->getStyle("{$c}{$row2}")->getNumberFormat()->setFormatCode($fmt_num);
        }
        $ws2->getStyle("D{$row2}:H{$row2}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
        $row2++;
    }

    // Total row
    $ws2->setCellValue('A' . $row2, 'TOTAL');
    $ws2->setCellValue('D' . $row2, $cont['tot_jorn']);
    $ws2->setCellValue('E' . $row2, $cont['tot_hhee']);
    $ws2->setCellValue('F' . $row2, $cont['tot_vj']);
    $ws2->setCellValue('G' . $row2, $cont['tot_vh']);
    $ws2->setCellValue('H' . $row2, $cont['tot_total']);
    $ws2->getStyle("A{$row2}:H{$row2}")->applyFromArray(styleTotal('FFBDE3C3'));
    foreach (['D','E'] as $c) {
        $ws2->getStyle("{$c}{$row2}")->getNumberFormat()->setFormatCode($fmt_dec);
    }
    foreach (['F','G','H'] as $c) {
        $ws2->getStyle("{$c}{$row2}")->getNumberFormat()->setFormatCode($fmt_num);
    }

    // Column widths
    $ws2->getColumnDimension('A')->setWidth(8);
    $ws2->getColumnDimension('B')->setWidth(28);
    $ws2->getColumnDimension('C')->setWidth(20);
    foreach (['D','E'] as $c) $ws2->getColumnDimension($c)->setWidth(11);
    foreach (['F','G','H'] as $c) $ws2->getColumnDimension($c)->setWidth(16);
}

/* ══════════════════════════════════════════
   HOJA DIARIO
══════════════════════════════════════════ */
$wsd = $ss->createSheet();
$wsd->setTitle('DIARIO');

// Título
$wsd->mergeCells('A1:K1');
$wsd->setCellValue('A1', "DETALLE DIARIO — Semana {$semana}/{$anio}");
$wsd->getStyle('A1')->applyFromArray([
    'font'      => ['bold' => true, 'size' => 12],
    'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
]);
$wsd->getRowDimension(1)->setRowHeight(22);

// Headers
$hdrs_d = ['Semana', 'Fecha', 'Contratista', 'Area', 'Cargo', 'Tarifa Base', 'T', 'Jornadas', 'Valor Jornadas', 'HH.EE.', 'Total'];
$cols_d = ['A','B','C','D','E','F','G','H','I','J','K'];
foreach ($hdrs_d as $i => $h) {
    $wsd->setCellValue($cols_d[$i] . '3', $h);
}
$wsd->getStyle('A3:K3')->applyFromArray(styleHeader([]));
$wsd->getRowDimension(3)->setRowHeight(20);

$rowd = 4;
foreach ($diario_rows as $dr) {
    $wsd->setCellValue('A' . $rowd, $dr['semana']);
    $wsd->setCellValue('B' . $rowd, $dr['fecha']);
    $wsd->setCellValue('C' . $rowd, $dr['contratista']);
    $wsd->setCellValue('D' . $rowd, $dr['area']);
    $wsd->setCellValue('E' . $rowd, $dr['cargo']);
    $wsd->setCellValue('F' . $rowd, $dr['tarifa']);
    $wsd->setCellValue('G' . $rowd, $dr['turno']);
    $wsd->setCellValue('H' . $rowd, $dr['jornada']);
    $wsd->setCellValue('I' . $rowd, $dr['val_jorn']);
    $wsd->setCellValue('J' . $rowd, $dr['hhee']);
    $wsd->setCellValue('K' . $rowd, $dr['total']);
    $wsd->getStyle("A{$rowd}:K{$rowd}")->applyFromArray(styleData());
    $wsd->getStyle("H{$rowd}:K{$rowd}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
    $wsd->getStyle("H{$rowd}")->getNumberFormat()->setFormatCode($fmt_dec);
    $wsd->getStyle("I{$rowd}")->getNumberFormat()->setFormatCode($fmt_num);
    $wsd->getStyle("J{$rowd}")->getNumberFormat()->setFormatCode($fmt_dec);
    $wsd->getStyle("K{$rowd}")->getNumberFormat()->setFormatCode($fmt_num);
    $rowd++;
}

// Column widths
$wsd->getColumnDimension('A')->setWidth(8);
$wsd->getColumnDimension('B')->setWidth(12);
$wsd->getColumnDimension('C')->setWidth(25);
$wsd->getColumnDimension('D')->setWidth(14);
$wsd->getColumnDimension('E')->setWidth(25);
$wsd->getColumnDimension('F')->setWidth(20);
$wsd->getColumnDimension('G')->setWidth(6);
$wsd->getColumnDimension('H')->setWidth(11);
$wsd->getColumnDimension('I')->setWidth(16);
$wsd->getColumnDimension('J')->setWidth(11);
$wsd->getColumnDimension('K')->setWidth(14);

/* ══════════════════════════════════════════
   FUNCIÓN PIVOT COMPARTIDA (DETALLE DIA / DETALLE HHEE)
   Usa Coordinate::stringFromColumnIndex() — compatible con PhpSpreadsheet 3.x
══════════════════════════════════════════ */
/**
 * build_pivot_sheet — crea una hoja pivot con bloques por clave exterior.
 *
 * Modos:
 *   $single_col = false (default): col A = col1_label, col B = col2_label, fechas desde C
 *   $single_col = true:            col A = col1_label,                      fechas desde B
 *
 * Estructura de $pivot_data:
 *   [bloque_titulo][fila_key] = ['area' => val_colA, 'cargo' => val_colB, 'fechas' => [...]]
 *   Si $single_col, solo se usa 'area' (o 'label') como etiqueta de fila.
 */
function build_pivot_sheet(
    Spreadsheet $ss,
    string $title,
    array $pivot_data,
    array $all_dates,
    string $num_format,
    int $semana,
    string $col1_label = 'Area',
    string $col2_label = 'Cargo',
    bool $single_col   = false
): void {
    $ws = $ss->createSheet();
    $ws->setTitle($title);

    $n_dates    = count($all_dates);
    $DS         = $single_col ? 2 : 3;      // primera columna de fechas (Data Start)
    $TOTAL_COL  = $DS + $n_dates;
    $date_keys  = array_keys($all_dates);

    $col = fn(int $n): string => Coordinate::stringFromColumnIndex($n);

    $hdr_fill = ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FF1E3A5F']];
    $sem_fill = ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FF2B5BA8']];
    $tot_fill = ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FFFFC000']];
    $alt_fill = ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FFF2F2F2']];
    $wht_fill = ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FFFFFFFF']];
    $thin     = ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['argb' => 'FFCCCCCC']]];

    // Fila 1 oculta: semana por columna de fecha
    for ($ci = 0; $ci < $n_dates; $ci++) {
        $ws->setCellValue($col($DS + $ci) . '1', $semana);
        $ws->getStyle($col($DS + $ci) . '1')->getNumberFormat()->setFormatCode('0');
    }
    $ws->setCellValue($col($TOTAL_COL) . '1', 'T');
    $ws->getRowDimension(1)->setVisible(false);

    $tot_ltr      = $col($TOTAL_COL);
    $last_dat_ltr = $col($DS - 1 + $n_dates);   // última col de fechas
    $cur_row      = 2;

    foreach ($pivot_data as $block_name => $rows) {
        $has_data = false;
        foreach ($rows as $rc) {
            foreach ($rc['fechas'] as $v) { if ($v > 0) { $has_data = true; break 2; } }
        }
        if (!$has_data) continue;

        $title_row  = $cur_row;
        $sem_row    = $cur_row + 1;
        $date_row   = $cur_row + 2;
        $data_start = $cur_row + 3;

        // ── Título del bloque ──
        $ws->mergeCells("A{$title_row}:{$tot_ltr}{$title_row}");
        $ws->setCellValue("A{$title_row}", $block_name);
        $ws->getStyle("A{$title_row}:{$tot_ltr}{$title_row}")->applyFromArray([
            'font'      => ['bold' => true, 'color' => ['argb' => 'FFFFFFFF'], 'size' => 12],
            'fill'      => $hdr_fill,
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
        ]);

        // ── Cabecera de columnas: etiqueta(s) fusionadas 2 filas ──
        $ws->mergeCells("A{$sem_row}:A{$date_row}");
        $ws->setCellValue("A{$sem_row}", $col1_label);
        $ws->getStyle("A{$sem_row}")->applyFromArray([
            'font'      => ['bold' => true, 'color' => ['argb' => 'FFFFFFFF']],
            'fill'      => $sem_fill,
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER,
                            'vertical'   => Alignment::VERTICAL_CENTER],
            'borders'   => $thin,
        ]);

        if (!$single_col) {
            $ws->mergeCells("B{$sem_row}:B{$date_row}");
            $ws->setCellValue("B{$sem_row}", $col2_label);
            $ws->getStyle("B{$sem_row}")->applyFromArray([
                'font'      => ['bold' => true, 'color' => ['argb' => 'FFFFFFFF']],
                'fill'      => $sem_fill,
                'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER,
                                'vertical'   => Alignment::VERTICAL_CENTER],
                'borders'   => $thin,
            ]);
        }

        // Semana span sobre columnas de fechas
        $date_start_ltr = $col($DS);
        $ws->mergeCells("{$date_start_ltr}{$sem_row}:{$last_dat_ltr}{$sem_row}");
        $ws->setCellValue("{$date_start_ltr}{$sem_row}", "Semana {$semana}");
        $ws->getStyle("{$date_start_ltr}{$sem_row}:{$last_dat_ltr}{$sem_row}")->applyFromArray([
            'font'      => ['bold' => true, 'color' => ['argb' => 'FFFFFFFF']],
            'fill'      => $sem_fill,
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
            'borders'   => $thin,
        ]);

        // TOTAL header fusionado 2 filas
        $ws->mergeCells("{$tot_ltr}{$sem_row}:{$tot_ltr}{$date_row}");
        $ws->setCellValue("{$tot_ltr}{$sem_row}", 'TOTAL');
        $ws->getStyle("{$tot_ltr}{$sem_row}")->applyFromArray([
            'font'      => ['bold' => true],
            'fill'      => $tot_fill,
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER,
                            'vertical'   => Alignment::VERTICAL_CENTER],
            'borders'   => $thin,
        ]);

        // Fechas DD/MM
        foreach ($date_keys as $ci => $fk) {
            $cl = $col($DS + $ci);
            $ws->setCellValue("{$cl}{$date_row}", $all_dates[$fk]);
            $ws->getStyle("{$cl}{$date_row}")->applyFromArray([
                'font'      => ['bold' => true, 'color' => ['argb' => 'FFFFFFFF']],
                'fill'      => $sem_fill,
                'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
                'borders'   => $thin,
            ]);
        }

        // ── Filas de datos ──
        $cur_row = $data_start;
        $row_idx = 0;
        foreach ($rows as $rc) {
            if (array_sum($rc['fechas']) == 0) continue;

            $fill    = ($row_idx % 2 === 1) ? $alt_fill : $wht_fill;
            $lbl_val = $rc['label'] ?? $rc['area'] ?? '';

            $ws->setCellValue("A{$cur_row}", $lbl_val);
            $ws->getStyle("A{$cur_row}")->applyFromArray(['fill' => $fill, 'borders' => $thin]);

            if (!$single_col) {
                $ws->setCellValue("B{$cur_row}", $rc['cargo'] ?? '');
                $ws->getStyle("B{$cur_row}")->applyFromArray(['fill' => $fill, 'borders' => $thin]);
            }

            $row_total = 0.0;
            foreach ($date_keys as $ci => $fk) {
                $val = (float)($rc['fechas'][$fk] ?? 0);
                $cl  = $col($DS + $ci);
                $ws->setCellValue("{$cl}{$cur_row}", $val != 0 ? round($val, 2) : null);
                $ws->getStyle("{$cl}{$cur_row}")->applyFromArray([
                    'fill'      => $fill,
                    'borders'   => $thin,
                    'alignment' => ['horizontal' => Alignment::HORIZONTAL_RIGHT],
                ]);
                $ws->getStyle("{$cl}{$cur_row}")->getNumberFormat()->setFormatCode($num_format);
                $row_total += $val;
            }

            $ws->setCellValue("{$tot_ltr}{$cur_row}", round($row_total, 2));
            $ws->getStyle("{$tot_ltr}{$cur_row}")->applyFromArray([
                'font'      => ['bold' => true],
                'fill'      => $tot_fill,
                'borders'   => $thin,
                'alignment' => ['horizontal' => Alignment::HORIZONTAL_RIGHT],
            ]);
            $ws->getStyle("{$tot_ltr}{$cur_row}")->getNumberFormat()->setFormatCode($num_format);

            $cur_row++;
            $row_idx++;
        }

        $cur_row += 2;
    }

    // Anchos de columna
    $ws->getColumnDimension('A')->setWidth(26);
    if (!$single_col) $ws->getColumnDimension('B')->setWidth(26);
    for ($ci = 0; $ci < $n_dates; $ci++) {
        $ws->getColumnDimension($col($DS + $ci))->setWidth(7);
    }
    $ws->getColumnDimension($col($TOTAL_COL))->setWidth(10);
    $ws->freezePane($col($DS) . '2');
}

/* ── DETALLE DIA ── */
build_pivot_sheet($ss, 'DETALLE DIA',  $pivot_dia,  $all_dates, '0',    $semana);

/* ── DETALLE HHEE ── */
build_pivot_sheet($ss, 'DETALLE HHEE', $pivot_hhee, $all_dates, '0.00', $semana);

/* ── DOTACION DIARIA ── */
build_pivot_sheet($ss, 'DOTACION', $pivot_dot, $all_dates, '0', $semana, 'Labor', '', true);

/* ── Activar primera hoja ── */
$ss->setActiveSheetIndex(0);

/* ── Enviar ── */
$filename = "Proforma_S{$semana}_{$anio}_v{$version}.xlsx";
header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: max-age=0');

$writer = new Xlsx($ss);
$writer->save('php://output');
exit;
