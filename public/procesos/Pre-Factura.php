<?php
require_once __DIR__ . '/../_bootstrap.php';
require_once __DIR__ . '/../../app/lib/db.php';

$title       = "Pre-Factura";
$flash_error = null;

/* ─────────────────────────────────────────
   FILTROS
───────────────────────────────────────── */
$filtro_semana = isset($_GET['semana']) ? (int)$_GET['semana'] : (int)date('W');
$filtro_anio   = isset($_GET['anio'])   ? (int)$_GET['anio']   : (int)date('Y');
$filtro_cont   = isset($_GET['id_contratista']) ? (int)$_GET['id_contratista'] : 0;

/* ─────────────────────────────────────────
   CATÁLOGOS
───────────────────────────────────────── */
$contratistas = [];
try {
    $s = db_query($conn, "SELECT id, nombre FROM dbo.dota_contratista ORDER BY nombre");
    while ($r = sqlsrv_fetch_array($s, SQLSRV_FETCH_ASSOC)) $contratistas[] = $r;
} catch (Throwable $e) { $flash_error = $e->getMessage(); }

/* ─────────────────────────────────────────
   LÓGICA DE CÁLCULO
   ─────────────────────────────────────────
   Dos montos siempre separados:

   BASE (lo que recibe el trabajador)
     base_jornada = jornada × ValorContratista
     base_hhee    = hhee    × horasExtras

   TOTAL EMPLEADOR (lo que paga el empleador al contratista)
     emp_jornada  = base_jornada × PorcContrastista
     emp_hhee     = base_hhee    × porc_hhee
     (bono se suma directo al total empleador)

   PORCENTAJE CONTRATISTA (diferencia que se queda la empresa contratista)
     porc_jornada = emp_jornada - base_jornada
     porc_hhee    = emp_hhee    - base_hhee

   TOTAL FACTURA = emp_jornada + emp_hhee + bono
───────────────────────────────────────── */

$resultado       = [];
$resultado_cajas = [];
$total_global    = 0.0;
$bloqueado       = false;
$bloqueado_info  = [];

// Verificar lotes sin aprobación final para la semana/año seleccionados
$lotes_sin_aprobar = [];
if ($filtro_semana > 0 && $filtro_anio > 0) {
    $stmtLotes = sqlsrv_query($conn,
        "SELECT registro, estado, fecha_carga FROM dbo.dota_asistencia_lote
         WHERE semana = ? AND anio = ? AND estado != 'listo_factura' AND ISNULL(activo, 1) = 1
         ORDER BY fecha_carga DESC",
        [$filtro_semana, $filtro_anio]
    );
    if ($stmtLotes) {
        while ($r = sqlsrv_fetch_array($stmtLotes, SQLSRV_FETCH_ASSOC)) {
            if ($r['fecha_carga'] instanceof DateTime)
                $r['fecha_carga'] = $r['fecha_carga']->format('d/m/Y H:i');
            $lotes_sin_aprobar[] = $r;
        }
    }
}

if ($filtro_semana > 0 && $filtro_anio > 0 && !$flash_error && empty($lotes_sin_aprobar)) {

    $params         = [$filtro_semana, $filtro_anio];
    $whereContr     = '';
    $whereContrPend = '';
    if ($filtro_cont > 0) {
        $whereContr     = ' AND ac.empleador = ?';
        $whereContrPend = ' AND id_contratista = ?';
        $params[]       = $filtro_cont;
    }
    // Segunda mitad del UNION (jornadas_pendientes)
    $params[] = $filtro_semana;
    $params[] = $filtro_anio;
    if ($filtro_cont > 0) {
        $params[] = $filtro_cont;
    }

    $sql = "
        WITH base AS (
            SELECT
                ac.id AS id_row,
                ac.empleador, ac.cargo, ac.fecha, ac.jornada, ac.hhee, ac.especie,
                ac.rut, ac.nombre,
                ISNULL(tr.nombre_turno, '') AS turno,
                CAST(0 AS BIT) AS es_pendiente,
                ac.semana      AS semana_orig,
                YEAR(ac.fecha) AS anio_orig
            FROM dbo.dota_asistencia_carga ac
            LEFT JOIN dbo.dota_turno tr ON tr.id = ac.turno
            WHERE ac.semana = ? AND YEAR(ac.fecha) = ?
              AND (ac.jornada > 0 OR ac.hhee > 0)
              AND NOT EXISTS (
                  SELECT 1 FROM dbo.dota_asistencia_lote l
                  WHERE l.registro = ac.registro AND l.activo = 0
              )
              {$whereContr}

            UNION ALL

            SELECT
                id AS id_row,
                id_contratista AS empleador,
                id_cargo       AS cargo,
                fecha, jornada, hhee, especie, rut, nombre, turno,
                CAST(1 AS BIT)  AS es_pendiente,
                semana_original AS semana_orig,
                anio_original   AS anio_orig
            FROM dbo.dota_jornadas_pendientes
            WHERE semana_factura = ? AND anio_factura = ?
              AND (jornada > 0 OR hhee > 0)
              {$whereContrPend}
        )
        SELECT
            a.id_row,
            a.empleador,
            c.nombre           AS contratista_nombre,
            c.valor_empresa,
            a.cargo         AS id_cargo,
            dc.cargo        AS cargo_nombre,
            a.fecha,
            a.jornada,
            a.hhee,
            a.especie,
            a.rut,
            a.nombre        AS trabajador,
            a.turno,
            a.es_pendiente,
            a.semana_orig,
            a.anio_orig,

            /* — Tarifa regular — */
            tt.Tipo_tarifa       AS tar_nom,
            tt.ValorContratista  AS tar_valor,
            tt.horasExtras       AS tar_hhee,
            tt.PorcContrastista  AS tar_porc,
            tt.porc_hhee         AS tar_porc_hhee,
            tt.bono              AS tar_bono,

            /* — Tarifa especial del día (override) — */
            te.esp_id,
            te.esp_nom,
            te.esp_valor,
            te.esp_hhee,
            te.esp_porc,
            te.esp_porc_hhee,
            te.esp_bono

        FROM base a
        LEFT JOIN dbo.dota_contratista c ON c.id        = a.empleador
        LEFT JOIN dbo.Dota_Cargo dc       ON dc.id_cargo = a.cargo

        /* Tarifa regular: TOP 1 por cargo, prefiere especie específica */
        OUTER APPLY (
            SELECT TOP 1 vd2.id_tipo_tarifa
            FROM dbo.Dota_Valor_Dotacion vd2
            WHERE vd2.id_cargo = a.cargo
              AND (
                    vd2.id_especie IS NULL
                    OR vd2.id_especie IN (
                        SELECT e.id_especie FROM dbo.especie e WHERE e.especie = a.especie
                    )
              )
            ORDER BY
                CASE WHEN vd2.id_especie IS NOT NULL THEN 0 ELSE 1 END
        ) vd
        LEFT JOIN dbo.Dota_tipo_tarifa tt
               ON tt.id_tipo_tarifa = vd.id_tipo_tarifa
              AND tt.tarifa_activa  = 1

        /* Tarifa especial: busca en dos fuentes por orden de prioridad.
           1. Dota_ValorEspecial_Dotacion (por cargo+fecha, más específica)
           2. Dota_Tarifa_Especiales con campo fecha (global por fecha)
           Si hay match → fila amarilla, acumulada en línea separada del resumen. */
        OUTER APPLY (
            SELECT TOP 1
                src.esp_id, src.esp_nom,
                src.esp_valor, src.esp_hhee,
                src.esp_porc, src.esp_porc_hhee, src.esp_bono
            FROM (
                -- Fuente 1: tarifa especial por cargo (TarifasEspNormal)
                -- No tiene porc propio → se usa NULL y el cálculo cae al % de la tarifa regular
                SELECT
                    dte.id_tipo          AS esp_id,
                    dte.tipo_Tarifa      AS esp_nom,
                    ved.valor            AS esp_valor,
                    ved.valor_HHEE       AS esp_hhee,
                    CAST(NULL AS DECIMAL(18,6)) AS esp_porc,
                    CAST(NULL AS DECIMAL(18,6)) AS esp_porc_hhee,
                    CAST(NULL AS DECIMAL(18,2)) AS esp_bono,
                    0                    AS prioridad
                FROM dbo.Dota_ValorEspecial_Dotacion ved
                JOIN dbo.Dota_Tarifa_Especiales dte ON dte.id_tipo = ved.tipo_tarifa
                WHERE CAST(ved.fecha AS DATE) = CAST(a.fecha AS DATE)
                  AND ved.cargo = a.cargo
                  AND (
                        ved.especie IS NULL
                        OR ved.especie IN (
                            SELECT id_especie FROM dbo.especie WHERE especie = a.especie
                        )
                      )

                UNION ALL

                -- Fuente 2: tarifa especial global por fecha (tarifasEspecial)
                -- Tiene todos los campos, incluyendo porc y bono
                SELECT
                    id_tipo              AS esp_id,
                    tipo_tarifa          AS esp_nom,
                    valor_base           AS esp_valor,
                    HH_EE_base           AS esp_hhee,
                    porc_contratista     AS esp_porc,
                    porc_hhee            AS esp_porc_hhee,
                    CAST(NULL AS DECIMAL(18,2)) AS esp_bono,
                    1                    AS prioridad
                FROM dbo.Dota_Tarifa_Especiales
                WHERE fecha IS NOT NULL
                  AND CAST(fecha AS DATE) = CAST(a.fecha AS DATE)
            ) src
            ORDER BY src.prioridad
        ) te
        ORDER BY c.nombre, dc.cargo, a.fecha, a.nombre
    ";

    try {
        $stmt = db_query($conn, $sql, $params, "Pre-Factura");

        while ($r = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            $cid = (int)$r['empleador'];
            $esp = $r['esp_id'] !== null;

            /* Valores efectivos según tarifa especial (si existe) o regular.
               Cada campo de la especial puede ser NULL → cae al valor de la tarifa regular. */
            $v_dia      = $esp && $r['esp_valor']      !== null ? (float)$r['esp_valor']      : (float)$r['tar_valor'];
            $v_hhee_tar = $esp && $r['esp_hhee']       !== null ? (float)$r['esp_hhee']       : (float)$r['tar_hhee'];
            $porc_jorn  = $esp && $r['esp_porc']       !== null ? (float)$r['esp_porc']       : (float)$r['tar_porc'];
            $porc_hhee  = $esp && $r['esp_porc_hhee']  !== null ? (float)$r['esp_porc_hhee']  : (float)$r['tar_porc_hhee'];
            $bono       = $esp && $r['esp_bono']       !== null ? (float)$r['esp_bono']       : ($esp ? 0.0 : (float)$r['tar_bono']);

            $jornada = (float)$r['jornada'];
            $hhee    = (float)$r['hhee'];

            /* ── Montos separados ── */
            $base_jorn = $jornada * $v_dia;          // base trabajador (jornada)
            $base_hhee = $hhee    * $v_hhee_tar;     // base trabajador (HHEE)

            /* PorcContrastista es un % simple (ej: 0.2367 = 23.67%).
               pct = base × porc  → siempre positivo
               emp = base + pct   → total que paga el empleador */
            $pct_jorn  = $base_jorn * $porc_jorn;    // % contratista sobre jornada (positivo)
            $pct_hhee  = $base_hhee * $porc_hhee;    // % contratista sobre HHEE (positivo)

            $emp_jorn  = $base_jorn + $pct_jorn;     // total empleador jornada
            $emp_hhee  = $base_hhee + $pct_hhee;     // total empleador HHEE

            $total_fac = $emp_jorn + $emp_hhee + $bono;  // factura total

            $fecha_str = ($r['fecha'] instanceof DateTime)
                       ? $r['fecha']->format('Y-m-d')
                       : substr((string)$r['fecha'], 0, 10);

            if (!isset($resultado[$cid])) {
                $resultado[$cid] = [
                    'nombre'         => $r['contratista_nombre'] ?? '(sin nombre)',
                    'valor_empresa'  => (bool)($r['valor_empresa'] ?? false),
                    'workers'        => [],
                    'dias_esp'       => [],
                    'cargos'         => [],
                    'filas'          => [],
                    /* totales acumulados */
                    'tot_base_jorn' => 0.0,
                    'tot_base_hhee' => 0.0,
                    'tot_emp_jorn'  => 0.0,
                    'tot_emp_hhee'  => 0.0,
                    'tot_pct_jorn'  => 0.0,
                    'tot_pct_hhee'  => 0.0,
                    'tot_bono'      => 0.0,
                    'tot_factura'   => 0.0,
                    'sin_tarifa'    => 0,
                    'sin_tarifa_detalle' => [],
                ];
            }

            $resultado[$cid]['workers'][$r['rut']] = true;
            $resultado[$cid]['tot_base_jorn'] += $base_jorn;
            $resultado[$cid]['tot_base_hhee'] += $base_hhee;
            $resultado[$cid]['tot_emp_jorn']  += $emp_jorn;
            $resultado[$cid]['tot_emp_hhee']  += $emp_hhee;
            $resultado[$cid]['tot_pct_jorn']  += $pct_jorn;
            $resultado[$cid]['tot_pct_hhee']  += $pct_hhee;
            $resultado[$cid]['tot_bono']      += $bono;
            $resultado[$cid]['tot_factura']   += $total_fac;

            if ($v_dia == 0 && $v_hhee_tar == 0 && $porc_jorn == 0) {
                $resultado[$cid]['sin_tarifa']++;
                $resultado[$cid]['sin_tarifa_detalle'][] = [
                    'fecha'      => $fecha_str,
                    'rut'        => $r['rut'],
                    'trabajador' => $r['trabajador'],
                    'cargo'      => $r['cargo_nombre'] ?? '—',
                    'especie'    => $r['especie'] ?? '',
                    'jornada'    => $jornada,
                    'hhee'       => $hhee,
                ];
            }

            if ($esp) $resultado[$cid]['dias_esp'][$fecha_str] = $r['esp_nom'];

            /* Resumen por cargo — clave separada para días normales vs especiales.
               Mismo cargo puede tener DOS filas: una regular + una por tarifa especial. */
            $cargo_id  = (int)($r['id_cargo'] ?? 0);
            $ck        = $esp ? ($cargo_id . '_e' . (int)$r['esp_id']) : ($cargo_id . '_r');

            if (!isset($resultado[$cid]['cargos'][$ck])) {
                $resultado[$cid]['cargos'][$ck] = [
                    'nombre'       => $r['cargo_nombre'] ?? '—',
                    'especial'     => $esp,
                    'esp_nom'      => $esp ? ($r['esp_nom'] ?? '') : '',
                    'tarifa'       => $esp ? ($r['esp_nom'] ?? '—') : ($r['tar_nom'] ?? '—'),
                    /* valores efectivos del primer registro — para pre-llenar inputs valor_empresa */
                    'v_dia'        => $v_dia,
                    'v_hhee'       => $v_hhee_tar,
                    'v_factor'     => $porc_jorn,
                    'v_factor_hhee'=> $porc_hhee,
                    'registros'    => 0,
                    'jornada'      => 0.0,
                    'hhee'         => 0.0,
                    'base_jorn'    => 0.0,
                    'base_hhee'    => 0.0,
                    'emp_jorn'     => 0.0,
                    'emp_hhee'     => 0.0,
                    'pct_jorn'     => 0.0,
                    'pct_hhee'     => 0.0,
                    'bono'         => 0.0,
                    'total'        => 0.0,
                ];
            }
            $resultado[$cid]['cargos'][$ck]['registros']++;
            $resultado[$cid]['cargos'][$ck]['jornada']   += $jornada;
            $resultado[$cid]['cargos'][$ck]['hhee']      += $hhee;
            $resultado[$cid]['cargos'][$ck]['base_jorn'] += $base_jorn;
            $resultado[$cid]['cargos'][$ck]['base_hhee'] += $base_hhee;
            $resultado[$cid]['cargos'][$ck]['emp_jorn']  += $emp_jorn;
            $resultado[$cid]['cargos'][$ck]['emp_hhee']  += $emp_hhee;
            $resultado[$cid]['cargos'][$ck]['pct_jorn']  += $pct_jorn;
            $resultado[$cid]['cargos'][$ck]['pct_hhee']  += $pct_hhee;
            $resultado[$cid]['cargos'][$ck]['bono']      += $bono;
            $resultado[$cid]['cargos'][$ck]['total']     += $total_fac;

            /* Detalle fila */
            $resultado[$cid]['filas'][] = [
                'id_row'       => (int)$r['id_row'],
                'fecha'        => $fecha_str,
                'rut'          => $r['rut'],
                'trabajador'   => $r['trabajador'],
                'cargo'        => $r['cargo_nombre'] ?? '—',
                'turno'        => $r['turno'],
                'jornada'      => $jornada,
                'hhee'         => $hhee,
                'v_dia'        => $v_dia,
                'v_hhee'       => $v_hhee_tar,
                'porc_jorn'    => $porc_jorn,
                'porc_hhee'    => $porc_hhee,
                'base_jorn'    => $base_jorn,
                'base_hhee'    => $base_hhee,
                'emp_jorn'     => $emp_jorn,
                'emp_hhee'     => $emp_hhee,
                'pct_jorn'     => $pct_jorn,
                'pct_hhee'     => $pct_hhee,
                'bono'         => $bono,
                'total_fac'    => $total_fac,
                'tar_nom'      => $r['tar_nom'] ?? ($esp ? $r['esp_nom'] : '—'),
                'especial'     => $esp,
                'esp_nom'      => $r['esp_nom'] ?? '',
                'es_pendiente' => (bool)$r['es_pendiente'],
                'semana_orig'  => (int)($r['semana_orig']  ?? 0),
                'anio_orig'    => (int)($r['anio_orig']    ?? 0),
            ];

            $total_global += $total_fac;
        }

    } catch (Throwable $e) {
        $flash_error = $e->getMessage();
    }

    /* ── Proformas ya guardadas para esta semana ── */
    $proformasSemana = [];
    try {
        $sp = sqlsrv_query($conn,
            "SELECT f.id, f.version, f.obs, f.estado, f.fecha_creacion, f.total_neto,
                    f.tot_factura, f.tot_base_jorn, f.tot_base_hhee
             FROM dbo.dota_factura f
             WHERE f.semana=? AND f.anio=?
             ORDER BY f.version",
            [$filtro_semana, $filtro_anio]);
        if ($sp) while ($rp = sqlsrv_fetch_array($sp, SQLSRV_FETCH_ASSOC)) {
            /* Contratistas incluidos en cada proforma */
            $sc = sqlsrv_query($conn,
                "SELECT DISTINCT c.nombre
                 FROM dbo.dota_factura_detalle fd
                 JOIN dbo.dota_contratista c ON c.id = fd.id_contratista
                 WHERE fd.id_factura=?",
                [(int)$rp['id']]);
            $names = [];
            if ($sc) while ($rc = sqlsrv_fetch_array($sc, SQLSRV_FETCH_ASSOC))
                $names[] = $rc['nombre'];
            $rp['contratistas'] = implode(', ', $names);
            $rp['fecha_str'] = $rp['fecha_creacion'] instanceof DateTime
                ? $rp['fecha_creacion']->format('d/m/Y H:i')
                : substr((string)$rp['fecha_creacion'], 0, 16);
            $proformasSemana[] = $rp;
        }
    } catch (Throwable $ignore) {}

    /* ── Verificar si la semana/contratista está cerrada ── */
    $bloqueado      = false;
    $bloqueado_info = [];   // proformas cerradas que bloquean
    foreach ($proformasSemana as $pf) {
        if ($pf['estado'] !== 'cerrado') continue;
        if ($filtro_cont > 0) {
            // Solo bloquea si el contratista filtrado está incluido en esa proforma
            $chk = sqlsrv_query($conn,
                "SELECT 1 FROM dbo.dota_factura_detalle WHERE id_factura=? AND id_contratista=?",
                [(int)$pf['id'], $filtro_cont]);
            if (!$chk || !sqlsrv_fetch($chk)) continue;
        }
        $bloqueado        = true;
        $bloqueado_info[] = $pf;
    }
    if ($bloqueado) {
        $resultado    = [];
        $total_global = 0.0;
    }

    /* ── Producción por cajas del período ── */
    $resultado_cajas = [];
    if (!$bloqueado) {
        $paramsCaja = [$filtro_semana, $filtro_anio];
        $whereCaja  = '';
        if ($filtro_cont > 0) { $whereCaja = ' AND pc.id_contratista = ?'; $paramsCaja[] = $filtro_cont; }
        $stC = sqlsrv_query($conn,
            "SELECT pc.id_contratista, c.nombre AS contratista_nombre,
                    t.Tipo_Tarifa, t.ValorContratista, t.PorcContrastista, t.bono,
                    SUM(pc.cajas) AS total_cajas
             FROM dbo.dota_produccion_cajas pc
             JOIN dbo.dota_contratista   c ON c.id             = pc.id_contratista
             JOIN dbo.Dota_tipo_tarifa   t ON t.id_tipo_tarifa = pc.id_tipo_tarifa
             WHERE pc.semana = ? AND pc.anio = ? $whereCaja
             GROUP BY pc.id_contratista, c.nombre, t.Tipo_Tarifa,
                      t.ValorContratista, t.PorcContrastista, t.bono",
            $paramsCaja
        );
        if ($stC) while ($rc = sqlsrv_fetch_array($stC, SQLSRV_FETCH_ASSOC)) {
            $cid   = (int)$rc['id_contratista'];
            $cajas = (float)$rc['total_cajas'];
            $vd    = (float)$rc['ValorContratista'];
            $porc  = (float)$rc['PorcContrastista'];
            $bono  = (float)$rc['bono'];
            $base  = $cajas * $vd;
            $pct   = $base * $porc;
            $total = $base + $pct + $bono;
            $resultado_cajas[$cid] = [
                'nombre'     => $rc['contratista_nombre'],
                'tipo_tar'   => $rc['Tipo_Tarifa'],
                'cajas'      => $cajas,
                'val_caja'   => $vd,
                'porc'       => $porc,
                'bono'       => $bono,
                'base'       => $base,
                'pct'        => $pct,
                'total'      => $total,
            ];
            $total_global += $total;
        }
    }

    /* ── Descuentos del período (desde dota_factura_descuento) ── */
    $descuentos = [];
    if (!empty($resultado)) {
        $ids   = array_keys($resultado);
        $ph    = implode(',', array_fill(0, count($ids), '?'));
        $pDesc = array_merge($ids, [$filtro_semana, $filtro_anio]);
        try {
            $sd = db_query($conn,
                "SELECT fd.id_contratista, SUM(fd.valor) AS total
                 FROM dbo.dota_factura_descuento fd
                 JOIN dbo.dota_factura f ON f.id = fd.id_factura
                 WHERE fd.id_contratista IN ($ph)
                   AND f.semana = ?
                   AND f.anio   = ?
                 GROUP BY fd.id_contratista",
                $pDesc, "Descuentos");
            while ($rd = sqlsrv_fetch_array($sd, SQLSRV_FETCH_ASSOC))
                $descuentos[(int)$rd['id_contratista']] = (float)$rd['total'];
        } catch (Throwable $ignore) {}
    }
}

function fmt($n)  { return '$' . number_format((float)$n, 0, ',', '.'); }
function fmtd($n) { return number_format((float)$n, 2, ',', '.'); }
function fmtp($n) { return number_format((float)$n, 4, ',', '.'); }  // factor porcentaje

include __DIR__ . '/../partials/header.php';
include __DIR__ . '/../partials/navbar_wrapper.php';
?>

<main class="container-fluid py-4 px-3">

<?php if ($flash_error): ?>
<div class="alert alert-danger"><?= htmlspecialchars($flash_error) ?></div>
<?php endif; ?>

<!-- ══════════ FILTROS ══════════ -->
<div class="card shadow-sm mb-4">
  <div class="card-header fw-semibold">Filtros</div>
  <div class="card-body">
    <form method="GET" class="row g-3 align-items-end">
      <div class="col-6 col-md-2">
        <label class="form-label">Semana</label>
        <input type="number" name="semana" class="form-control" min="1" max="53"
               value="<?= $filtro_semana ?>">
      </div>
      <div class="col-6 col-md-2">
        <label class="form-label">Año</label>
        <input type="number" name="anio" class="form-control" min="2020" max="2100"
               value="<?= $filtro_anio ?>">
      </div>
      <div class="col-12 col-md-4">
        <label class="form-label">Contratista</label>
        <select name="id_contratista" class="form-select">
          <option value="0">— Todos —</option>
          <?php foreach ($contratistas as $ct): ?>
          <option value="<?= (int)$ct['id'] ?>"
            <?= $filtro_cont === (int)$ct['id'] ? 'selected' : '' ?>>
            <?= htmlspecialchars($ct['nombre']) ?>
          </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-12 col-md-2">
        <button type="submit" class="btn btn-primary w-100">Calcular</button>
      </div>
    </form>
  </div>
</div>

<!-- leyenda de cálculo -->
<div class="alert alert-light border small mb-4 py-2">
  <strong>Fórmula:</strong>
  &nbsp;Base jornada = jornada × ValorDía
  &nbsp;|&nbsp; Base HHEE = HHEE × ValorHHEE
  &nbsp;|&nbsp; Total empleador = Base × Factor%
  &nbsp;|&nbsp; <span class="text-warning fw-semibold">⚡ fila amarilla = tarifa especial del día</span>
</div>

<?php if (!empty($lotes_sin_aprobar)): ?>
<div class="alert alert-danger">
    <strong>Atención:</strong> Hay <?= count($lotes_sin_aprobar) ?> lote(s) de la semana <?= $filtro_semana ?>/<?= $filtro_anio ?>
    que aún no tienen aprobación final. No se puede generar la Pre-Factura hasta que todos estén en estado <strong>listo_factura</strong>.
    <table class="table table-sm table-bordered mt-2 mb-0 bg-white">
        <thead><tr><th>Lote</th><th>Estado</th><th>Fecha carga</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($lotes_sin_aprobar as $ls): ?>
        <tr>
            <td><small><?= htmlspecialchars($ls['registro']) ?></small></td>
            <td><span class="badge bg-<?= $ls['estado'] === 'pendiente' ? 'warning text-dark' : 'danger' ?>"><?= htmlspecialchars($ls['estado']) ?></span></td>
            <td><?= htmlspecialchars($ls['fecha_carga']) ?></td>
            <td>
                <a href="<?= BASE_URL ?>/aprobacion/detalle_asistencia.php?registro=<?= urlencode($ls['registro']) ?>"
                   class="btn btn-outline-secondary btn-sm">Ver</a>
            </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php elseif ($bloqueado && !empty($bloqueado_info)): ?>
<div class="alert alert-danger d-flex gap-3 align-items-start">
  <span style="font-size:2rem">🔒</span>
  <div>
    <strong>Semana <?= $filtro_semana ?>/<?= $filtro_anio ?> cerrada — no se pueden ver ni modificar los datos.</strong><br>
    <?php if ($filtro_cont > 0): ?>
      El contratista seleccionado está incluido en una proforma cerrada.
    <?php else: ?>
      Existen proformas cerradas para esta semana.
    <?php endif; ?>
    <ul class="mb-0 mt-2">
      <?php foreach ($bloqueado_info as $bi): ?>
        <li>
          Proforma v<?= $bi['version'] ?>
          — creada <?= htmlspecialchars($bi['fecha_str']) ?>
          — neto <?= '$' . number_format((float)$bi['total_neto'], 0, ',', '.') ?>
          <?php if ($bi['contratistas']): ?>
            (<?= htmlspecialchars($bi['contratistas']) ?>)
          <?php endif; ?>
        </li>
      <?php endforeach; ?>
    </ul>
    <a href="proformas.php?semana=<?= $filtro_semana ?>&anio=<?= $filtro_anio ?>"
       class="btn btn-outline-danger btn-sm mt-2">Ver proformas</a>
  </div>
</div>
<?php elseif (empty($resultado) && $filtro_semana > 0 && !$flash_error): ?>
<div class="alert alert-info">No hay registros de asistencia para semana <?= $filtro_semana ?> / <?= $filtro_anio ?>.</div>
<?php endif; ?>

<?php if (!empty($resultado)): ?>

<!-- ══════════ RESUMEN GLOBAL ══════════ -->
<?php
$g_base_jorn  = array_sum(array_column($resultado, 'tot_base_jorn'));
$g_base_hhee  = array_sum(array_column($resultado, 'tot_base_hhee'));
$g_emp_jorn   = array_sum(array_column($resultado, 'tot_emp_jorn'));
$g_emp_hhee   = array_sum(array_column($resultado, 'tot_emp_hhee'));
$g_bono       = array_sum(array_column($resultado, 'tot_bono'));
$g_factura    = array_sum(array_column($resultado, 'tot_factura'));
$g_desc       = array_sum($descuentos);
$g_neto       = $g_factura - $g_desc;
$g_workers    = array_sum(array_map(fn($d) => count($d['workers']), $resultado));

/* Totales fijos (contratistas normales, sin valor_empresa).
   Los contratistas valor_empresa tienen tot_factura=0 porque no tienen tarifa;
   JS suma sus valores manuales encima de estos fijos. */
$g_fix_base = 0.0; $g_fix_pct = 0.0; $g_fix_fac = 0.0; $g_fix_desc = 0.0;
foreach ($resultado as $cid2 => $d2) {
    if ($d2['valor_empresa']) continue;
    $g_fix_base += $d2['tot_base_jorn'] + $d2['tot_base_hhee'];
    $g_fix_pct  += $d2['tot_pct_jorn']  + $d2['tot_pct_hhee']  + $d2['tot_bono'];
    $g_fix_fac  += $d2['tot_factura'];
    $g_fix_desc += $descuentos[$cid2] ?? 0.0;
}
?>
<div class="row g-2 mb-4">
  <div class="col-6 col-md col-lg">
    <div class="card text-center border-0 bg-light h-100">
      <div class="card-body py-2">
        <div class="fs-5 fw-bold text-primary"><?= count($resultado) ?></div>
        <div class="small text-muted">Contratistas</div>
      </div>
    </div>
  </div>
  <div class="col-6 col-md col-lg">
    <div class="card text-center border-0 bg-light h-100">
      <div class="card-body py-2">
        <div class="fs-5 fw-bold text-primary"><?= $g_workers ?></div>
        <div class="small text-muted">Trabajadores</div>
      </div>
    </div>
  </div>
  <div class="col-6 col-md col-lg">
    <div class="card text-center border-0 bg-light h-100">
      <div class="card-body py-2">
        <div class="fs-5 fw-bold" id="g-base-disp"
             data-fixed="<?= round($g_fix_base, 2) ?>"><?= fmt($g_base_jorn + $g_base_hhee) ?></div>
        <div class="small text-muted">Base trabajadores</div>
      </div>
    </div>
  </div>
  <div class="col-6 col-md col-lg">
    <div class="card text-center border-0 bg-light h-100">
      <div class="card-body py-2">
        <div class="fs-5 fw-bold text-secondary" id="g-pct-disp"
             data-fixed="<?= round($g_fix_pct, 2) ?>"><?= fmt($g_emp_jorn + $g_emp_hhee - $g_base_jorn - $g_base_hhee + $g_bono) ?></div>
        <div class="small text-muted">% Contratistas + bonos</div>
      </div>
    </div>
  </div>
  <div class="col-6 col-md col-lg">
    <div class="card text-center border-0 bg-success text-white h-100">
      <div class="card-body py-2">
        <div class="fs-5 fw-bold" id="g-fac-disp"
             data-fixed="<?= round($g_fix_fac, 2) ?>"
             data-desc="<?= round($g_fix_desc, 2) ?>"><?= fmt($g_factura) ?></div>
        <div class="small">Total factura</div>
      </div>
    </div>
  </div>
  <?php if ($g_desc > 0): ?>
  <div class="col-6 col-md col-lg">
    <div class="card text-center border-0 bg-dark text-white h-100">
      <div class="card-body py-2">
        <div class="fs-5 fw-bold"><?= fmt($g_neto) ?></div>
        <div class="small">Neto (- desc.)</div>
      </div>
    </div>
  </div>
  <?php endif; ?>
</div>

<!-- ══════════ ACORDEÓN POR CONTRATISTA ══════════ -->
<div class="accordion" id="accordionFactura">

<?php $idx = 0; foreach ($resultado as $cid => $datos):
  $desc     = $descuentos[$cid] ?? 0.0;
  $neto     = $datos['tot_factura'] - $desc;
  $n_work   = count($datos['workers']);
  $n_esp    = count($datos['dias_esp']);
  $sin_tar  = $datos['sin_tarifa'];
  $base_tot = $datos['tot_base_jorn'] + $datos['tot_base_hhee'];
  $pct_tot  = $datos['tot_pct_jorn']  + $datos['tot_pct_hhee'];
  $idx++;
?>
<div class="accordion-item mb-2 border rounded shadow-sm">

  <!-- CABECERA -->
  <h2 class="accordion-header" id="hd<?= $idx ?>">
    <button class="accordion-button <?= $idx > 1 ? 'collapsed' : '' ?> py-2"
            type="button" data-bs-toggle="collapse"
            data-bs-target="#cl<?= $idx ?>"
            aria-expanded="<?= $idx === 1 ? 'true' : 'false' ?>">
      <div class="d-flex flex-wrap gap-3 w-100 align-items-center">
        <span class="fw-bold"><?= htmlspecialchars($datos['nombre']) ?></span>
        <?php if ($datos['valor_empresa']): ?>
        <span class="badge bg-info text-dark">Valor Empresa</span>
        <?php endif; ?>
        <span class="badge bg-secondary"><?= $n_work ?> trab.</span>
        <?php if ($n_esp > 0): ?>
        <span class="badge bg-warning text-dark">⚡ <?= $n_esp ?> día<?= $n_esp > 1 ? 's' : '' ?> especial</span>
        <?php endif; ?>
        <?php if (!$datos['valor_empresa'] && $sin_tar > 0): ?>
        <span class="badge bg-danger"><?= $sin_tar ?> sin tarifa</span>
        <?php endif; ?>
        <?php if (!$datos['valor_empresa']): ?>
        <!-- mini resumen en cabecera -->
        <span class="ms-auto text-muted small">Base: <?= fmt($base_tot) ?></span>
        <span class="text-muted small">+ <?= fmt($pct_tot) ?></span>
        <?php if ($datos['tot_bono'] > 0): ?>
        <span class="text-muted small">+ bono <?= fmt($datos['tot_bono']) ?></span>
        <?php endif; ?>
        <span class="fw-bold text-success"><?= fmt($datos['tot_factura']) ?></span>
        <?php if ($desc > 0): ?>
        <span class="text-danger small">- <?= fmt($desc) ?></span>
        <span class="fw-bold">= <?= fmt($neto) ?></span>
        <?php endif; ?>
        <?php else: ?>
        <span class="ms-auto text-muted small">Ingresa valores manualmente</span>
        <span class="fw-bold text-success ve-total-header-<?= $cid ?>">$0</span>
        <?php endif; ?>
      </div>
    </button>
  </h2>

  <!-- CUERPO -->
  <div id="cl<?= $idx ?>" class="accordion-collapse collapse <?= $idx === 1 ? 'show' : '' ?>">
    <div class="accordion-body p-3">

      <?php if (!empty($datos['dias_esp'])): ?>
      <div class="alert alert-warning py-2 small mb-3">
        <strong>⚡ Días con tarifa especial:</strong>
        <?php foreach ($datos['dias_esp'] as $fec => $nom): ?>
          <span class="badge bg-warning text-dark ms-1"><?= htmlspecialchars($fec) ?></span>
          <span class="text-muted">(<?= htmlspecialchars($nom) ?>)</span>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>

      <?php if ($sin_tar > 0): ?>
      <div class="alert alert-danger py-2 small mb-3">
        <div class="d-flex justify-content-between align-items-center">
          <strong>⚠ <?= $sin_tar ?> fila(s) sin tarifa asignada.</strong>
          <button class="btn btn-danger btn-sm py-0" type="button"
                  data-bs-toggle="collapse"
                  data-bs-target="#sintar-<?= $idx ?>">
            Ver detalle
          </button>
        </div>
        <div class="collapse mt-2" id="sintar-<?= $idx ?>">
          <table class="table table-sm table-bordered table-white mb-0" style="font-size:.82rem">
            <thead class="table-dark">
              <tr>
                <th>Fecha</th>
                <th>RUT</th>
                <th>Trabajador</th>
                <th>Cargo</th>
                <th>Especie</th>
                <th class="text-end">Jorn.</th>
                <th class="text-end">HHEE</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($datos['sin_tarifa_detalle'] as $st): ?>
              <tr>
                <td><?= htmlspecialchars($st['fecha']) ?></td>
                <td><?= htmlspecialchars($st['rut']) ?></td>
                <td><?= htmlspecialchars($st['trabajador']) ?></td>
                <td><?= htmlspecialchars($st['cargo']) ?></td>
                <td><?= htmlspecialchars($st['especie']) ?></td>
                <td class="text-end"><?= fmtd($st['jornada']) ?></td>
                <td class="text-end"><?= fmtd($st['hhee']) ?></td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>
      <?php endif; ?>

      <!-- ── RESUMEN POR LABOR ── -->
      <h6 class="fw-semibold mb-2">Resumen por Labor</h6>
      <div class="table-responsive mb-4">

      <?php if ($datos['valor_empresa']): ?>
      <!-- ══ VALOR EMPRESA: inputs manuales por labor ══ -->
      <table class="table table-sm table-bordered align-middle mb-0" id="ve-table-<?= $cid ?>">
        <thead class="table-dark">
          <tr>
            <th>Labor</th>
            <th class="text-end">Reg.</th>
            <th class="text-end">Jorn.</th>
            <th class="text-end">HHEE</th>
            <th class="text-end bg-warning bg-opacity-50" style="min-width:110px">Val/Día</th>
            <th class="text-end bg-warning bg-opacity-50" style="min-width:110px">Val/HHEE</th>
            <th class="text-end bg-info bg-opacity-25" style="min-width:100px">Factor % Jorn.</th>
            <th class="text-end bg-info bg-opacity-25" style="min-width:100px">Factor % HHEE</th>
            <th class="text-end bg-secondary bg-opacity-25">Base Jorn.</th>
            <th class="text-end bg-secondary bg-opacity-25">Base HHEE</th>
            <th class="text-end bg-primary bg-opacity-25">% Jorn.</th>
            <th class="text-end bg-primary bg-opacity-25">% HHEE</th>
            <th class="text-end fw-bold bg-success bg-opacity-25">Total</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($datos['cargos'] as $ck => $cargo):
                $pre_dia        = $cargo['v_dia']        > 0 ? $cargo['v_dia']        : 0;
                $pre_hhee       = $cargo['v_hhee']       > 0 ? $cargo['v_hhee']       : 0;
                $pre_factor     = $cargo['v_factor']     > 0 ? $cargo['v_factor']     : 0;
                $pre_factor_hh  = $cargo['v_factor_hhee']> 0 ? $cargo['v_factor_hhee']: 0;
          ?>
          <tr data-cid="<?= $cid ?>"
              data-jorn="<?= $cargo['jornada'] ?>"
              data-hhee="<?= $cargo['hhee'] ?>"
              class="<?= $cargo['especial'] ? 'table-warning' : '' ?>">
            <td>
              <?= htmlspecialchars($cargo['nombre']) ?>
              <?php if ($cargo['especial']): ?>
              <div class="small"><span class="badge bg-warning text-dark">⚡ <?= htmlspecialchars($cargo['esp_nom']) ?></span></div>
              <?php elseif ($cargo['tarifa'] && $cargo['tarifa'] !== '—'): ?>
              <div class="text-muted small"><?= htmlspecialchars($cargo['tarifa']) ?></div>
              <?php endif; ?>
            </td>
            <td class="text-end"><?= $cargo['registros'] ?></td>
            <td class="text-end"><?= fmtd($cargo['jornada']) ?></td>
            <td class="text-end"><?= fmtd($cargo['hhee']) ?></td>
            <td class="bg-warning bg-opacity-25">
              <input type="number" class="form-control form-control-sm text-end ve-val-dia"
                     min="0" step="1"
                     value="<?= $pre_dia ?>"
                     placeholder="0">
            </td>
            <td class="bg-warning bg-opacity-25">
              <input type="number" class="form-control form-control-sm text-end ve-val-hhee"
                     min="0" step="1"
                     value="<?= $pre_hhee ?>"
                     placeholder="0">
            </td>
            <td class="bg-info bg-opacity-10">
              <input type="number" class="form-control form-control-sm text-end ve-factor"
                     min="0" step="0.0001"
                     value="<?= $pre_factor ?>"
                     placeholder="ej: 0.2367">
            </td>
            <td class="bg-info bg-opacity-10">
              <input type="number" class="form-control form-control-sm text-end ve-factor-hhee"
                     min="0" step="0.0001"
                     value="<?= $pre_factor_hh ?>"
                     placeholder="ej: 0.2367">
            </td>
            <td class="text-end bg-secondary bg-opacity-10 ve-base-jorn">$0</td>
            <td class="text-end bg-secondary bg-opacity-10 ve-base-hhee">$0</td>
            <td class="text-end bg-primary bg-opacity-10 ve-pct-jorn">$0</td>
            <td class="text-end bg-primary bg-opacity-10 ve-pct-hhee">$0</td>
            <td class="text-end fw-bold bg-success bg-opacity-10 ve-total-row">$0</td>
          </tr>
          <?php endforeach; ?>
        </tbody>
        <tfoot>
          <tr class="table-secondary fw-semibold">
            <td colspan="8" class="text-end">Subtotal</td>
            <td class="text-end ve-sub-base-jorn">$0</td>
            <td class="text-end ve-sub-base-hhee">$0</td>
            <td class="text-end ve-sub-pct-jorn">$0</td>
            <td class="text-end ve-sub-pct-hhee">$0</td>
            <td class="text-end fw-bold ve-sub-total" id="ve-sub-<?= $cid ?>">$0</td>
          </tr>
          <tr class="table-dark text-white">
            <td colspan="12" class="text-end fw-bold">TOTAL A FACTURAR</td>
            <td class="text-end fw-bold fs-6 ve-grand-total" id="ve-grand-<?= $cid ?>">$0</td>
          </tr>
        </tfoot>
      </table>

      <?php else: ?>
      <!-- ══ TARIFA NORMAL ══ -->
      <table class="table table-sm table-bordered align-middle mb-0">
        <thead class="table-dark">
          <tr>
            <th rowspan="2">Labor</th>
            <th rowspan="2" class="text-end">Reg.</th>
            <th rowspan="2" class="text-end">Jorn.</th>
            <th rowspan="2" class="text-end">HHEE</th>
            <th colspan="2" class="text-center border-start bg-secondary bg-opacity-75">Base trabajador</th>
            <th colspan="2" class="text-center border-start bg-primary bg-opacity-75">% Contratista</th>
            <th class="text-end border-start">Bono</th>
            <th class="text-end border-start fw-bold bg-success bg-opacity-25">Total Factura</th>
          </tr>
          <tr>
            <th class="text-end small bg-secondary bg-opacity-25">Jornada</th>
            <th class="text-end small bg-secondary bg-opacity-25">HHEE</th>
            <th class="text-end small bg-primary bg-opacity-25">Jornada</th>
            <th class="text-end small bg-primary bg-opacity-25">HHEE</th>
            <th class="text-end small"></th>
            <th class="text-end small bg-success bg-opacity-10"></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($datos['cargos'] as $cargo): ?>
          <tr class="<?= $cargo['especial'] ? 'table-warning' : '' ?>">
            <td>
              <?= htmlspecialchars($cargo['nombre']) ?>
              <?php if ($cargo['especial']): ?>
              <div class="small"><span class="badge bg-warning text-dark">⚡ <?= htmlspecialchars($cargo['esp_nom']) ?></span></div>
              <?php else: ?>
              <div class="text-muted small"><?= htmlspecialchars($cargo['tarifa']) ?></div>
              <?php endif; ?>
            </td>
            <td class="text-end"><?= $cargo['registros'] ?></td>
            <td class="text-end"><?= fmtd($cargo['jornada']) ?></td>
            <td class="text-end"><?= fmtd($cargo['hhee']) ?></td>
            <td class="text-end bg-secondary bg-opacity-10"><?= fmt($cargo['base_jorn']) ?></td>
            <td class="text-end bg-secondary bg-opacity-10"><?= fmt($cargo['base_hhee']) ?></td>
            <td class="text-end bg-primary bg-opacity-10"><?= fmt($cargo['pct_jorn']) ?></td>
            <td class="text-end bg-primary bg-opacity-10"><?= fmt($cargo['pct_hhee']) ?></td>
            <td class="text-end"><?= $cargo['bono'] > 0 ? fmt($cargo['bono']) : '—' ?></td>
            <td class="text-end fw-bold bg-success bg-opacity-10"><?= fmt($cargo['total']) ?></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
        <tfoot>
          <tr class="table-secondary fw-semibold">
            <td colspan="4" class="text-end">Subtotal</td>
            <td class="text-end"><?= fmt($datos['tot_base_jorn']) ?></td>
            <td class="text-end"><?= fmt($datos['tot_base_hhee']) ?></td>
            <td class="text-end"><?= fmt($datos['tot_pct_jorn']) ?></td>
            <td class="text-end"><?= fmt($datos['tot_pct_hhee']) ?></td>
            <td class="text-end"><?= $datos['tot_bono'] > 0 ? fmt($datos['tot_bono']) : '—' ?></td>
            <td class="text-end fw-bold"><?= fmt($datos['tot_factura']) ?></td>
          </tr>
          <?php if ($desc > 0): ?>
          <tr class="table-danger">
            <td colspan="9" class="text-end">Descuentos del período</td>
            <td class="text-end">- <?= fmt($desc) ?></td>
          </tr>
          <?php endif; ?>
          <tr class="table-dark text-white">
            <td colspan="9" class="text-end fw-bold">TOTAL A FACTURAR</td>
            <td class="text-end fw-bold fs-6"><?= fmt($neto) ?></td>
          </tr>
        </tfoot>
      </table>
      <?php endif; ?>

      </div><!-- /table-responsive -->

      <!-- ── DETALLE TRABAJADORES (colapsable) ── -->
      <div class="d-flex justify-content-between align-items-center mb-2">
        <h6 class="fw-semibold mb-0">Detalle por trabajador</h6>
        <button class="btn btn-outline-secondary btn-sm" type="button"
                data-bs-toggle="collapse" data-bs-target="#det<?= $idx ?>">
          Ver / ocultar
        </button>
      </div>
      <div id="det<?= $idx ?>" class="collapse show">
        <div class="table-responsive">
          <?php if ($datos['valor_empresa']): ?>
          <!-- Detalle simplificado: solo cantidades (los montos vienen de los inputs arriba) -->
          <table class="table table-sm table-bordered table-hover align-middle small mb-0">
            <thead class="table-secondary">
              <tr>
                <th>Fecha</th>
                <th>RUT</th>
                <th>Trabajador</th>
                <th>Labor</th>
                <th>Turno</th>
                <th class="text-end">Jorn.</th>
                <th class="text-end">HHEE</th>
                <th></th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($datos['filas'] as $f): ?>
              <tr class="<?= $f['especial'] ? 'table-warning' : ($f['es_pendiente'] ? 'table-info' : '') ?>">
                <td>
                  <?= htmlspecialchars($f['fecha']) ?>
                  <?php if ($f['es_pendiente']): ?>
                  <br><span class="badge bg-info text-dark" style="font-size:.7rem">S<?= $f['semana_orig'] ?>/<?= $f['anio_orig'] ?></span>
                  <?php endif; ?>
                </td>
                <td><?= htmlspecialchars($f['rut']) ?></td>
                <td><?= htmlspecialchars($f['trabajador']) ?></td>
                <td><?= htmlspecialchars($f['cargo']) ?></td>
                <td><?= htmlspecialchars($f['turno']) ?></td>
                <td class="text-end"><?= fmtd($f['jornada']) ?></td>
                <td class="text-end"><?= fmtd($f['hhee']) ?></td>
                <td>
                  <button class="btn btn-outline-danger btn-sm py-0 px-1 btn-elim-fila"
                          data-id="<?= $f['id_row'] ?>"
                          data-pendiente="<?= $f['es_pendiente'] ? 1 : 0 ?>"
                          title="Eliminar fila">✕</button>
                </td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
          <?php else: ?>
          <!-- Detalle completo con montos tarifados -->
          <table class="table table-sm table-bordered table-hover align-middle small mb-0">
            <thead class="table-secondary">
              <tr>
                <th>Fecha</th>
                <th>RUT</th>
                <th>Trabajador</th>
                <th>Labor</th>
                <th>Turno</th>
                <th class="text-end">Jorn.</th>
                <th class="text-end">HHEE</th>
                <th class="text-end">Val/día</th>
                <th class="text-end">Factor%</th>
                <th class="text-end bg-secondary bg-opacity-25">B. Jorn.</th>
                <th class="text-end bg-secondary bg-opacity-25">B. HHEE</th>
                <th class="text-end bg-primary bg-opacity-25">% Jorn.</th>
                <th class="text-end bg-primary bg-opacity-25">% HHEE</th>
                <th class="text-end bg-success bg-opacity-25 fw-bold">Total</th>
                <th>Tarifa</th>
                <th></th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($datos['filas'] as $f): ?>
              <tr class="<?= $f['especial'] ? 'table-warning' : ($f['es_pendiente'] ? 'table-info' : '') ?>">
                <td>
                  <?= htmlspecialchars($f['fecha']) ?>
                  <?php if ($f['es_pendiente']): ?>
                  <br><span class="badge bg-info text-dark" style="font-size:.7rem">S<?= $f['semana_orig'] ?>/<?= $f['anio_orig'] ?></span>
                  <?php endif; ?>
                </td>
                <td><?= htmlspecialchars($f['rut']) ?></td>
                <td><?= htmlspecialchars($f['trabajador']) ?></td>
                <td><?= htmlspecialchars($f['cargo']) ?></td>
                <td><?= htmlspecialchars($f['turno']) ?></td>
                <td class="text-end"><?= fmtd($f['jornada']) ?></td>
                <td class="text-end"><?= fmtd($f['hhee']) ?></td>
                <td class="text-end"><?= fmt($f['v_dia']) ?></td>
                <td class="text-end text-muted"><?= fmtp($f['porc_jorn']) ?>×</td>
                <td class="text-end bg-secondary bg-opacity-10"><?= fmt($f['base_jorn']) ?></td>
                <td class="text-end bg-secondary bg-opacity-10"><?= fmt($f['base_hhee']) ?></td>
                <td class="text-end bg-primary bg-opacity-10"><?= fmt($f['pct_jorn']) ?></td>
                <td class="text-end bg-primary bg-opacity-10"><?= fmt($f['pct_hhee']) ?></td>
                <td class="text-end fw-bold bg-success bg-opacity-10"><?= fmt($f['total_fac']) ?></td>
                <td class="small">
                  <?php if ($f['especial']): ?>
                  <span class="badge bg-warning text-dark">⚡ <?= htmlspecialchars($f['esp_nom']) ?></span>
                  <?php else: ?>
                  <?= htmlspecialchars($f['tar_nom']) ?>
                  <?php endif; ?>
                </td>
                <td>
                  <button class="btn btn-outline-danger btn-sm py-0 px-1 btn-elim-fila"
                          data-id="<?= $f['id_row'] ?>"
                          data-pendiente="<?= $f['es_pendiente'] ? 1 : 0 ?>"
                          title="Eliminar fila">✕</button>
                </td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
          <?php endif; ?>
        </div>
      </div><!-- /detalle -->

    </div><!-- /accordion-body -->
  </div><!-- /accordion-collapse -->
</div><!-- /accordion-item -->
<?php endforeach; ?>
</div><!-- /accordion -->

<!-- ══════════ SECCIÓN CAJAS ══════════ -->
<?php if (!empty($resultado_cajas)): ?>
<div class="card shadow-sm mb-4">
  <div class="card-header fw-semibold d-flex justify-content-between align-items-center">
    <span>Producción por Cajas — Semana <?= $filtro_semana ?>/<?= $filtro_anio ?></span>
    <a href="carga_cajas.php?semana=<?= $filtro_semana ?>&anio=<?= $filtro_anio ?>"
       class="btn btn-sm btn-outline-secondary">Editar cajas</a>
  </div>
  <div class="card-body p-0">
    <table class="table table-sm table-bordered mb-0">
      <thead class="table-dark">
        <tr>
          <th>Contratista</th>
          <th>Tarifa</th>
          <th class="text-end">Cajas</th>
          <th class="text-end">Val/Caja</th>
          <th class="text-end bg-secondary bg-opacity-25">Base</th>
          <th class="text-end bg-primary bg-opacity-25">% Contratista</th>
          <th class="text-end">Bono</th>
          <th class="text-end fw-bold bg-success bg-opacity-25">Total</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($resultado_cajas as $rc): ?>
        <tr>
          <td><?= htmlspecialchars($rc['nombre']) ?></td>
          <td class="small text-muted"><?= htmlspecialchars($rc['tipo_tar']) ?></td>
          <td class="text-end"><?= number_format($rc['cajas'], 2, ',', '.') ?></td>
          <td class="text-end"><?= fmt($rc['val_caja']) ?></td>
          <td class="text-end bg-secondary bg-opacity-10"><?= fmt($rc['base']) ?></td>
          <td class="text-end bg-primary bg-opacity-10"><?= fmt($rc['pct']) ?></td>
          <td class="text-end"><?= $rc['bono'] > 0 ? fmt($rc['bono']) : '—' ?></td>
          <td class="text-end fw-bold bg-success bg-opacity-10"><?= fmt($rc['total']) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
      <tfoot class="table-secondary fw-semibold">
        <tr>
          <td colspan="7" class="text-end">Total cajas</td>
          <td class="text-end"><?= fmt(array_sum(array_column($resultado_cajas,'total'))) ?></td>
        </tr>
      </tfoot>
    </table>
  </div>
</div>
<?php endif; ?>

<!-- ══════════ PANEL GUARDAR PROFORMA ══════════ -->
<?php
/* Construir datos JS: un objeto por contratista con sus cargos */
$js_prefactura = [];
foreach ($resultado as $cid2 => $d2) {
    $cargos_js = [];
    foreach ($d2['cargos'] as $cargo) {
        $cargos_js[] = [
            'cargo_nombre'  => $cargo['nombre'],
            'tarifa_nombre' => $cargo['tarifa'],
            'especial'      => $cargo['especial'],
            'esp_nom'       => $cargo['esp_nom'],
            'registros'     => $cargo['registros'],
            'jornada'       => $cargo['jornada'],
            'hhee'          => $cargo['hhee'],
            'v_dia'         => $cargo['v_dia'],
            'v_hhee'        => $cargo['v_hhee'],
            'porc_jorn'     => $cargo['v_factor'],
            'porc_hhee'     => $cargo['v_factor_hhee'],
            'base_jorn'     => $cargo['base_jorn'],
            'base_hhee'     => $cargo['base_hhee'],
            'pct_jorn'      => $cargo['pct_jorn'],
            'pct_hhee'      => $cargo['pct_hhee'],
            'bono'          => $cargo['bono'],
            'total'         => $cargo['total'],
        ];
    }
    $js_prefactura[$cid2] = [
        'nombre'        => $d2['nombre'],
        'valor_empresa' => $d2['valor_empresa'],
        'tot_base_jorn' => $d2['tot_base_jorn'],
        'tot_base_hhee' => $d2['tot_base_hhee'],
        'tot_pct_jorn'  => $d2['tot_pct_jorn'],
        'tot_pct_hhee'  => $d2['tot_pct_hhee'],
        'tot_bono'      => $d2['tot_bono'],
        'tot_factura'   => $d2['tot_factura'],
        'descuento'     => $descuentos[$cid2] ?? 0,
        'cargos'        => $cargos_js,
    ];
}
?>
<script>const PREFACTURA = <?= json_encode($js_prefactura, JSON_UNESCAPED_UNICODE) ?>;</script>

<div class="card mt-4 shadow-sm mb-4">
  <div class="card-header fw-semibold">Guardar como Proforma</div>
  <div class="card-body">

    <?php if (!empty($proformasSemana)): ?>
    <!-- Proformas existentes para esta semana -->
    <div class="mb-3">
      <label class="form-label fw-semibold small">Proformas semana <?= $filtro_semana ?>/<?= $filtro_anio ?>:</label>
      <div class="d-flex flex-wrap gap-2">
        <?php foreach ($proformasSemana as $pf): ?>
        <div class="border rounded p-2 small" style="min-width:220px">
          <div class="d-flex justify-content-between align-items-center mb-1">
            <span class="fw-bold">v<?= (int)$pf['version'] ?></span>
            <?php if ($pf['estado'] === 'cerrado'): ?>
            <span class="badge bg-dark">Cerrada</span>
            <?php else: ?>
            <span class="badge bg-warning text-dark">En proceso</span>
            <?php endif; ?>
          </div>
          <div class="text-muted" style="font-size:.78rem"><?= $pf['fecha_str'] ?></div>
          <?php if ($pf['contratistas']): ?>
          <div class="text-muted" style="font-size:.78rem"><?= htmlspecialchars($pf['contratistas']) ?></div>
          <?php endif; ?>
          <div class="fw-semibold mt-1">$<?= number_format((float)$pf['tot_factura'], 0, ',', '.') ?></div>
          <?php if ($pf['estado'] !== 'cerrado'): ?>
          <button type="button" class="btn btn-outline-primary btn-sm mt-1 w-100 btn-anexar"
                  data-id="<?= (int)$pf['id'] ?>"
                  data-ver="<?= (int)$pf['version'] ?>">
            Anexar a esta proforma
          </button>
          <?php endif; ?>
        </div>
        <?php endforeach; ?>
      </div>
    </div>
    <hr>
    <?php endif; ?>

    <!-- Selección de contratistas -->
    <div class="mb-3">
      <label class="form-label fw-semibold small">Contratistas a incluir:</label>
      <div class="d-flex flex-wrap gap-3">
        <?php foreach ($resultado as $cid2 => $d2): ?>
        <label class="form-check" for="chk-<?= $cid2 ?>">
          <input type="checkbox" class="form-check-input chk-cont"
                 id="chk-<?= $cid2 ?>" value="<?= $cid2 ?>" checked>
          <span class="form-check-box"></span>
          <span class="form-check-label small">
            <?= htmlspecialchars($d2['nombre']) ?>
            <?php if ($d2['valor_empresa']): ?>
            <span class="badge bg-info text-dark" style="font-size:.7rem">VE</span>
            <?php endif; ?>
          </span>
        </label>
        <?php endforeach; ?>
      </div>
    </div>

    <!-- Obs + Estado + Botones -->
    <div class="row g-2 align-items-end">
      <div class="col-12 col-md">
        <label class="form-label mb-1 small">Observación</label>
        <textarea id="pf-obs" class="form-control form-control-sm" rows="2"
                  placeholder="Ej: Semana <?= $filtro_semana ?> — archivo del día lunes…"></textarea>
      </div>
      <div class="col-auto">
        <label class="form-label mb-1 small">Estado</label>
        <select id="pf-estado" class="form-select form-select-sm">
          <option value="proceso">En Proceso</option>
          <option value="cerrado">Cerrado</option>
        </select>
      </div>
      <div class="col-auto d-flex gap-2 align-items-end">
        <button type="button" id="btn-nueva-pf" class="btn btn-success btn-sm">
          Nueva proforma
        </button>
      </div>
      <div class="col-12 mt-1" id="pf-msg" style="display:none"></div>
    </div>
    <!-- Target de anexar: se llena al hacer click en "Anexar a esta proforma" -->
    <div id="pf-anexar-target" class="alert alert-info py-2 small mt-2" style="display:none">
      Anexando a proforma <strong id="pf-anexar-ver"></strong> —
      <a href="#" id="pf-anexar-cancel" class="text-danger">Cancelar</a>
    </div>
  </div>
</div>

<!-- ══════════ BARRA TOTAL GLOBAL ══════════ -->
<div class="card mt-4 border-dark shadow-sm">
  <div class="card-body py-3">
    <div class="row g-3 text-center">
      <div class="col">
        <div class="small text-muted">Base trabajadores</div>
        <div class="fw-semibold" id="g-base-disp2"><?= fmt($g_base_jorn + $g_base_hhee) ?></div>
      </div>
      <div class="col">
        <div class="small text-muted">+ % Contratistas</div>
        <div class="fw-semibold" id="g-pct-disp2"><?= fmt($g_emp_jorn + $g_emp_hhee - $g_base_jorn - $g_base_hhee) ?></div>
      </div>
      <?php if ($g_bono > 0): ?>
      <div class="col">
        <div class="small text-muted">+ Bonos</div>
        <div class="fw-semibold"><?= fmt($g_bono) ?></div>
      </div>
      <?php endif; ?>
      <div class="col border-start">
        <div class="small text-muted">Total factura</div>
        <div class="fw-bold fs-5 text-success" id="g-fac-disp2"><?= fmt($g_factura) ?></div>
      </div>
      <?php if ($g_desc > 0): ?>
      <div class="col">
        <div class="small text-muted">- Descuentos</div>
        <div class="fw-semibold text-danger"><?= fmt($g_desc) ?></div>
      </div>
      <div class="col border-start">
        <div class="small text-muted">Neto a pagar</div>
        <div class="fw-bold fs-5 text-dark"><?= fmt($g_neto) ?></div>
      </div>
      <?php endif; ?>
      <div class="col-auto d-flex align-items-center">
        <button class="btn btn-outline-success btn-sm" disabled title="Próximamente">⬇ Exportar Excel</button>
      </div>
    </div>
  </div>
</div>

<?php endif; ?>
</main>

<script>
/* ──────────────────────────────────────────────────────
   Cálculo en tiempo real para contratistas Valor Empresa
   ────────────────────────────────────────────────────── */
(function () {
  function fmtPeso(n) {
    return '$' + Math.round(n).toLocaleString('es-CL');
  }

  function recalcTable(table) {
    let subBJ = 0, subBH = 0, subPJ = 0, subPH = 0, subTot = 0;

    table.querySelectorAll('tbody tr').forEach(function (tr) {
      const jorn      = parseFloat(tr.dataset.jorn) || 0;
      const hhee      = parseFloat(tr.dataset.hhee) || 0;
      const valDia    = parseFloat(tr.querySelector('.ve-val-dia').value)     || 0;
      const valHH     = parseFloat(tr.querySelector('.ve-val-hhee').value)    || 0;
      const factor    = parseFloat(tr.querySelector('.ve-factor').value)      || 0;
      const factorHH  = parseFloat(tr.querySelector('.ve-factor-hhee').value) || 0;

      const bj  = jorn * valDia;
      const bh  = hhee * valHH;
      const pj  = bj * factor;
      const ph  = bh * factorHH;   // factor HHEE separado
      const tot = bj + bh + pj + ph;

      tr.querySelector('.ve-base-jorn').textContent = fmtPeso(bj);
      tr.querySelector('.ve-base-hhee').textContent = fmtPeso(bh);
      tr.querySelector('.ve-pct-jorn').textContent  = fmtPeso(pj);
      tr.querySelector('.ve-pct-hhee').textContent  = fmtPeso(ph);
      tr.querySelector('.ve-total-row').textContent = fmtPeso(tot);

      subBJ  += bj;  subBH  += bh;
      subPJ  += pj;  subPH  += ph;
      subTot += tot;
    });

    // tfoot subtotales
    const tf = table.tFoot;
    if (tf) {
      tf.querySelector('.ve-sub-base-jorn').textContent = fmtPeso(subBJ);
      tf.querySelector('.ve-sub-base-hhee').textContent = fmtPeso(subBH);
      tf.querySelector('.ve-sub-pct-jorn').textContent  = fmtPeso(subPJ);
      tf.querySelector('.ve-sub-pct-hhee').textContent  = fmtPeso(subPH);
      tf.querySelector('.ve-sub-total').textContent     = fmtPeso(subTot);
      tf.querySelector('.ve-grand-total').textContent   = fmtPeso(subTot);
    }

    // badge en cabecera del accordion
    const cid = table.id.replace('ve-table-', '');
    const hdr = document.querySelector('.ve-total-header-' + cid);
    if (hdr) hdr.textContent = fmtPeso(subTot);

    // totales globales
    updateGlobalTotals();
  }

  /* Actualiza los totales globales sumando todos los ve-table activos */
  function updateGlobalTotals() {
    var veBj=0, veBh=0, vePj=0, vePh=0, veBono=0, veTot=0;

    document.querySelectorAll('[id^="ve-table-"]').forEach(function(table){
      table.querySelectorAll('tbody tr').forEach(function(tr){
        var jorn  = parseFloat(tr.dataset.jorn)||0;
        var hhee  = parseFloat(tr.dataset.hhee)||0;
        var vd    = parseFloat(tr.querySelector('.ve-val-dia').value)||0;
        var vh    = parseFloat(tr.querySelector('.ve-val-hhee').value)||0;
        var fj    = parseFloat(tr.querySelector('.ve-factor').value)||0;
        var fh    = parseFloat(tr.querySelector('.ve-factor-hhee').value)||0;
        veBj  += jorn*vd;
        veBh  += hhee*vh;
        vePj  += jorn*vd*fj;
        vePh  += hhee*vh*fh;
        veTot += jorn*vd*(1+fj) + hhee*vh*(1+fh);
      });
    });

    var elBase = document.getElementById('g-base-disp');
    var elPct  = document.getElementById('g-pct-disp');
    var elFac  = document.getElementById('g-fac-disp');
    var elBase2= document.getElementById('g-base-disp2');
    var elPct2 = document.getElementById('g-pct-disp2');
    var elFac2 = document.getElementById('g-fac-disp2');

    if (!elBase) return;

    var fixBase = parseFloat(elBase.dataset.fixed)||0;
    var fixPct  = parseFloat(elPct.dataset.fixed)||0;
    var fixFac  = parseFloat(elFac.dataset.fixed)||0;
    var fixDesc = parseFloat(elFac.dataset.desc)||0;

    var totalBase = fixBase + veBj + veBh;
    var totalPct  = fixPct  + vePj + vePh + veBono;
    var totalFac  = fixFac  + veTot;

    elBase.textContent = fmtPeso(totalBase);
    elPct.textContent  = fmtPeso(totalPct);
    elFac.textContent  = fmtPeso(totalFac);
    if (elBase2) elBase2.textContent = fmtPeso(totalBase);
    if (elPct2)  elPct2.textContent  = fmtPeso(totalPct);
    if (elFac2)  elFac2.textContent  = fmtPeso(totalFac);
  }

  document.querySelectorAll('[id^="ve-table-"]').forEach(function (table) {
    recalcTable(table);
    table.addEventListener('input', function () { recalcTable(table); });
  });
  updateGlobalTotals();

  /* ══════════ PROFORMA ══════════ */

  var _pfAnexarId  = null;  // id de proforma a anexar (null = nueva)
  var _pfAnexarVer = null;

  /* Botones "Anexar a esta proforma" */
  document.querySelectorAll('.btn-anexar').forEach(function(btn){
    btn.addEventListener('click', function(){
      _pfAnexarId  = parseInt(btn.dataset.id);
      _pfAnexarVer = btn.dataset.ver;
      var target = document.getElementById('pf-anexar-target');
      document.getElementById('pf-anexar-ver').textContent = 'v' + _pfAnexarVer;
      target.style.display = '';
      document.getElementById('btn-nueva-pf').textContent = 'Guardar (anexar v' + _pfAnexarVer + ')';
    });
  });
  var cancelAnexar = document.getElementById('pf-anexar-cancel');
  if (cancelAnexar) cancelAnexar.addEventListener('click', function(e){
    e.preventDefault();
    _pfAnexarId = null; _pfAnexarVer = null;
    document.getElementById('pf-anexar-target').style.display = 'none';
    document.getElementById('btn-nueva-pf').textContent = 'Nueva proforma';
  });

  /* Recopilar datos de un contratista desde PREFACTURA + tablas ve */
  function getContratistaDatos(cid) {
    var base = PREFACTURA[cid];
    if (!base) return null;
    var ct = {
      id_contratista : parseInt(cid),
      descuento      : base.descuento || 0,
      cargos         : [],
    };

    if (base.valor_empresa) {
      /* Leer desde inputs de la tabla ve */
      var table = document.getElementById('ve-table-' + cid);
      var bj=0, bh=0, pj=0, ph=0, tot=0;
      if (table) {
        table.querySelectorAll('tbody tr').forEach(function(tr, i){
          var cargoBase  = base.cargos[i] || {};
          var jorn  = parseFloat(tr.dataset.jorn)||0;
          var hhee  = parseFloat(tr.dataset.hhee)||0;
          var vd    = parseFloat(tr.querySelector('.ve-val-dia').value)||0;
          var vh    = parseFloat(tr.querySelector('.ve-val-hhee').value)||0;
          var fj    = parseFloat(tr.querySelector('.ve-factor').value)||0;
          var fh    = parseFloat(tr.querySelector('.ve-factor-hhee').value)||0;
          var _bj   = jorn*vd, _bh = hhee*vh;
          var _pj   = _bj*fj, _ph = _bh*fh;
          var _tot  = _bj+_bh+_pj+_ph;
          bj+=_bj; bh+=_bh; pj+=_pj; ph+=_ph; tot+=_tot;
          ct.cargos.push({
            cargo_nombre  : cargoBase.cargo_nombre  || '',
            tarifa_nombre : cargoBase.tarifa_nombre || 'Valor Empresa',
            especial      : cargoBase.especial      || false,
            esp_nom       : cargoBase.esp_nom       || '',
            registros     : cargoBase.registros     || 0,
            jornada: jorn, hhee: hhee,
            v_dia: vd, v_hhee: vh,
            porc_jorn: fj, porc_hhee: fh,
            base_jorn: _bj, base_hhee: _bh,
            pct_jorn: _pj, pct_hhee: _ph,
            bono: 0, total: _tot,
          });
        });
      }
      ct.tot_base_jorn = bj; ct.tot_base_hhee = bh;
      ct.tot_pct_jorn  = pj; ct.tot_pct_hhee  = ph;
      ct.tot_bono = 0; ct.tot_factura = tot;
    } else {
      ct.tot_base_jorn = base.tot_base_jorn;
      ct.tot_base_hhee = base.tot_base_hhee;
      ct.tot_pct_jorn  = base.tot_pct_jorn;
      ct.tot_pct_hhee  = base.tot_pct_hhee;
      ct.tot_bono      = base.tot_bono;
      ct.tot_factura   = base.tot_factura;
      ct.cargos        = base.cargos.slice();
    }
    return ct;
  }

  /* Botón guardar */
  var btnNueva = document.getElementById('btn-nueva-pf');
  if (btnNueva) btnNueva.addEventListener('click', function(){
    var checks = document.querySelectorAll('.chk-cont:checked');
    if (!checks.length) {
      alert('Selecciona al menos un contratista.');
      return;
    }
    var contratistas = [];
    checks.forEach(function(chk){ contratistas.push(getContratistaDatos(chk.value)); });
    contratistas = contratistas.filter(Boolean);

    var payload = {
      accion      : _pfAnexarId ? 'anexar' : 'nuevo',
      id_factura  : _pfAnexarId || 0,
      semana      : <?= $filtro_semana ?>,
      anio        : <?= $filtro_anio ?>,
      obs         : document.getElementById('pf-obs').value,
      estado      : document.getElementById('pf-estado').value,
      contratistas: contratistas,
    };

    var msg = document.getElementById('pf-msg');
    btnNueva.disabled = true;
    btnNueva.textContent = 'Guardando…';

    fetch('guardar_factura.php', {
      method : 'POST',
      headers: {'Content-Type':'application/json'},
      body   : JSON.stringify(payload),
    })
    .then(r => r.json())
    .then(function(res){
      btnNueva.disabled = false;
      btnNueva.textContent = _pfAnexarId ? 'Guardar (anexar v' + _pfAnexarVer + ')' : 'Nueva proforma';
      msg.style.display = '';
      if (res.ok) {
        msg.innerHTML = '<div class="alert alert-success py-1 mb-0">✓ ' +
          res.accion + ' — ID #' + res.id +
          ' — Total: $' + Math.round(res.total).toLocaleString('es-CL') +
          (res.estado==='cerrado' ? ' <span class="badge bg-dark ms-1">Cerrada</span>' :
           ' <span class="badge bg-warning text-dark ms-1">En proceso</span>') +
          '</div>';
        /* Recargar para actualizar listado de proformas */
        setTimeout(function(){ location.reload(); }, 2000);
      } else {
        msg.innerHTML = '<div class="alert alert-danger py-1 mb-0">' + (res.error||'Error') + '</div>';
      }
    })
    .catch(function(){
      btnNueva.disabled = false;
      msg.style.display = '';
      msg.innerHTML = '<div class="alert alert-danger py-1 mb-0">Error de red</div>';
    });
  });
  /* ══════════ ELIMINAR FILA ══════════ */
  document.querySelectorAll('.btn-elim-fila').forEach(function(btn) {
    btn.addEventListener('click', function() {
      var origen = parseInt(btn.dataset.pendiente) === 1 ? 'jornada pendiente' : 'archivo asistencia';
      if (!confirm('¿Eliminar esta fila del ' + origen + '? Esta acción no se puede deshacer.')) return;
      btn.disabled = true;
      fetch('prefactura_eliminar.php', {
        method:  'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
          id:           parseInt(btn.dataset.id),
          es_pendiente: parseInt(btn.dataset.pendiente) === 1,
        })
      })
      .then(function(r) { return r.json(); })
      .then(function(res) {
        if (res.ok) {
          location.reload();
        } else {
          alert(res.error || 'Error al eliminar.');
          btn.disabled = false;
        }
      })
      .catch(function() {
        alert('Error de red.');
        btn.disabled = false;
      });
    });
  });

})();
</script>

<?php include __DIR__ . '/../partials/footer.php'; ?>
