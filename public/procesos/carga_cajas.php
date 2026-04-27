<?php
require_once __DIR__ . '/../_bootstrap.php';
require_once __DIR__ . '/../../app/lib/db.php';
if (!puede_modulo('procesos') && !es_admin()) {
    header('Location: ' . BASE_URL . '/Inicio.php'); exit;
}

$title       = "Carga de Cajas";
$flash_error = null;
$flash_ok    = null;

// Crear tabla si no existe
sqlsrv_query($conn, "
    IF OBJECT_ID('dbo.dota_produccion_cajas','U') IS NULL
    CREATE TABLE dbo.dota_produccion_cajas (
        id              INT IDENTITY(1,1) NOT NULL PRIMARY KEY,
        id_contratista  INT NOT NULL,
        id_tipo_tarifa  INT NOT NULL,
        fecha           DATE NOT NULL,
        semana          INT NOT NULL,
        anio            INT NOT NULL,
        cajas           DECIMAL(10,2) NOT NULL DEFAULT 0,
        id_usuario      INT NULL,
        fecha_reg       DATETIME NOT NULL DEFAULT GETDATE(),
        CONSTRAINT UQ_prod_cajas UNIQUE (id_contratista, fecha)
    )
");

/* ── Filtros ── */
$filtro_semana = isset($_GET['semana']) ? (int)$_GET['semana'] : (int)date('W');
$filtro_anio   = isset($_GET['anio'])   ? (int)$_GET['anio']   : (int)date('Y');

/* ── Calcular fechas Mon–Dom de la semana ── */
$dto  = new DateTime();
$dto->setISODate($filtro_anio, $filtro_semana, 1); // lunes
$dias = [];
for ($d = 0; $d < 7; $d++) {
    $dias[] = (clone $dto)->modify("+{$d} days");
}
$fecha_ini = $dias[0]->format('Y-m-d');
$fecha_fin = $dias[6]->format('Y-m-d');

/* ── Guardar ── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['guardar'])) {
    $id_usuario = (int)$_SESSION['id_usuario'];
    $errores = 0;
    foreach ($_POST['cajas'] ?? [] as $id_cont_str => $por_fecha) {
        $id_cont = (int)$id_cont_str;
        $id_tar  = (int)($_POST['id_tarifa'][$id_cont_str] ?? 0);
        if ($id_cont <= 0 || $id_tar <= 0) continue;
        foreach ($por_fecha as $fecha_str => $val) {
            $cajas = max(0.0, (float)str_replace(',', '.', $val));
            $res = sqlsrv_query($conn,
                "MERGE dbo.dota_produccion_cajas AS tgt
                 USING (SELECT ? AS id_contratista, ? AS fecha) AS src
                    ON tgt.id_contratista = src.id_contratista AND tgt.fecha = CONVERT(date,src.fecha,120)
                 WHEN MATCHED THEN
                     UPDATE SET cajas=?, id_tipo_tarifa=?, semana=?, anio=?, id_usuario=?, fecha_reg=GETDATE()
                 WHEN NOT MATCHED THEN
                     INSERT (id_contratista,id_tipo_tarifa,fecha,semana,anio,cajas,id_usuario)
                     VALUES (?,?,CONVERT(date,?,120),?,?,?,?);",
                [$id_cont, $fecha_str,
                 $cajas, $id_tar, $filtro_semana, $filtro_anio, $id_usuario,
                 $id_cont, $id_tar, $fecha_str, $filtro_semana, $filtro_anio, $cajas, $id_usuario]
            );
            if ($res === false) $errores++;
        }
    }
    $flash_ok = $errores === 0
        ? "Cajas guardadas correctamente para semana {$filtro_semana}/{$filtro_anio}."
        : "Guardado con {$errores} error(es). Revise los datos.";
    if (!$errores) {
        header("Location: carga_cajas.php?semana={$filtro_semana}&anio={$filtro_anio}"); exit;
    }
}

/* ── Tarifas activas de tipo "caja" para este período ── */
$tarifas_caja = [];
$stT = sqlsrv_query($conn,
    "SELECT t.id_tipo_tarifa, t.Tipo_Tarifa, t.ValorContratista, t.PorcContrastista,
            t.porc_hhee, t.bono, t.id_contratista, c.nombre AS nombre_contratista
     FROM dbo.Dota_tipo_tarifa t
     JOIN dbo.dota_contratista c ON c.id = t.id_contratista
     WHERE t.caja = 1
       AND t.tarifa_activa = 1
       AND t.id_contratista IS NOT NULL
       AND t.fecha_desde <= ? AND t.fecha_hasta >= ?
     ORDER BY c.nombre",
    [$fecha_fin, $fecha_ini]
);
if ($stT) while ($r = sqlsrv_fetch_array($stT, SQLSRV_FETCH_ASSOC))
    $tarifas_caja[(int)$r['id_contratista']] = $r;

/* ── Cajas ya registradas para la semana ── */
$registros = [];
if (!empty($tarifas_caja)) {
    $stR = sqlsrv_query($conn,
        "SELECT id_contratista, CONVERT(varchar(10),fecha,120) AS fecha, cajas
         FROM dbo.dota_produccion_cajas
         WHERE semana=? AND anio=?",
        [$filtro_semana, $filtro_anio]
    );
    if ($stR) while ($r = sqlsrv_fetch_array($stR, SQLSRV_FETCH_ASSOC))
        $registros[(int)$r['id_contratista']][$r['fecha']] = (float)$r['cajas'];
}

function fmt_c($n) { return '$'.number_format((float)$n, 0, ',', '.'); }

include __DIR__ . '/../partials/header.php';
include __DIR__ . '/../partials/navbar_wrapper.php';
?>
<main class="container-fluid py-4 px-3">
<h4 class="mb-3">Carga de Cajas por Semana</h4>

<?php if ($flash_error): ?><div class="alert alert-danger"><?= htmlspecialchars($flash_error) ?></div><?php endif; ?>
<?php if ($flash_ok):    ?><div class="alert alert-success"><?= htmlspecialchars($flash_ok) ?></div><?php endif; ?>

<!-- Filtros -->
<form method="GET" class="row g-2 mb-4 align-items-end">
  <div class="col-auto">
    <label class="form-label">Semana</label>
    <input type="number" name="semana" class="form-control form-control-sm" min="1" max="53"
           value="<?= $filtro_semana ?>" style="width:80px">
  </div>
  <div class="col-auto">
    <label class="form-label">Año</label>
    <input type="number" name="anio" class="form-control form-control-sm" min="2020" max="2100"
           value="<?= $filtro_anio ?>" style="width:90px">
  </div>
  <div class="col-auto">
    <button class="btn btn-primary btn-sm">Ver</button>
  </div>
  <div class="col-auto">
    <span class="text-muted small">
      <?= $dias[0]->format('d/m/Y') ?> — <?= $dias[6]->format('d/m/Y') ?>
    </span>
  </div>
</form>

<?php if (empty($tarifas_caja)): ?>
<div class="alert alert-info">
  No hay tarifas activas de tipo <strong>Calculo Cajas</strong> para el período
  <?= $dias[0]->format('d/m/Y') ?> – <?= $dias[6]->format('d/m/Y') ?>.<br>
  <small>Configure una tarifa con "Calculo Cajas" y asigne un contratista en
  <a href="<?= BASE_URL ?>/tarifas/tipo_tarifa.php">Tipo Tarifas</a>.</small>
</div>
<?php else: ?>

<form method="POST">
  <input type="hidden" name="guardar" value="1">

  <?php
  $dias_nombres = ['Lun','Mar','Mié','Jue','Vie','Sáb','Dom'];
  foreach ($tarifas_caja as $id_cont => $tar):
    $vd   = (float)$tar['ValorContratista'];
    $porc = (float)$tar['PorcContrastista'];
    $bono = (float)$tar['bono'];
    $id_tar = (int)$tar['id_tipo_tarifa'];
    $cajas_sem = $registros[$id_cont] ?? [];

    /* Totales de la semana */
    $total_cajas  = array_sum($cajas_sem);
    $base_tot     = $total_cajas * $vd;
    $pct_tot      = $base_tot * $porc;
    $emp_tot      = $base_tot + $pct_tot;
    $gran_tot     = $emp_tot + $bono;
  ?>
  <div class="card shadow-sm mb-4">
    <div class="card-header d-flex justify-content-between align-items-center">
      <span class="fw-bold"><?= htmlspecialchars($tar['nombre_contratista']) ?></span>
      <span class="badge bg-secondary"><?= htmlspecialchars($tar['Tipo_Tarifa']) ?> — $<?= number_format($vd,0,',','.') ?>/caja</span>
    </div>
    <div class="card-body">
      <input type="hidden" name="id_tarifa[<?= $id_cont ?>]" value="<?= $id_tar ?>">

      <!-- Inputs por día -->
      <div class="table-responsive mb-3">
        <table class="table table-sm table-bordered align-middle text-center mb-0">
          <thead class="table-dark">
            <tr>
              <?php foreach ($dias as $i => $d): ?>
              <th><?= $dias_nombres[$i] ?><br><small><?= $d->format('d/m') ?></small></th>
              <?php endforeach; ?>
              <th class="table-secondary">Total cajas</th>
            </tr>
          </thead>
          <tbody>
            <tr>
              <?php foreach ($dias as $d):
                $fkey = $d->format('Y-m-d');
                $val  = $cajas_sem[$fkey] ?? 0;
              ?>
              <td>
                <input type="number" class="form-control form-control-sm text-end caja-input"
                       name="cajas[<?= $id_cont ?>][<?= $fkey ?>]"
                       value="<?= $val > 0 ? $val : '' ?>"
                       min="0" step="0.01" placeholder="0"
                       data-cont="<?= $id_cont ?>"
                       style="min-width:70px">
              </td>
              <?php endforeach; ?>
              <td class="fw-bold total-cajas-<?= $id_cont ?>">
                <?= number_format($total_cajas, 2, ',', '.') ?>
              </td>
            </tr>
          </tbody>
        </table>
      </div>

      <!-- Resumen calculado -->
      <div class="row g-2 small">
        <div class="col-auto">
          <div class="border rounded px-3 py-2 text-center bg-light">
            <div class="text-muted">Total cajas</div>
            <div class="fw-semibold total-cajas-txt-<?= $id_cont ?>"><?= number_format($total_cajas,2,',','.') ?></div>
          </div>
        </div>
        <div class="col-auto">
          <div class="border rounded px-3 py-2 text-center bg-light">
            <div class="text-muted">Base (cajas × $<?= number_format($vd,0,',','.') ?>)</div>
            <div class="fw-semibold base-caja-<?= $id_cont ?>"><?= fmt_c($base_tot) ?></div>
          </div>
        </div>
        <div class="col-auto">
          <div class="border rounded px-3 py-2 text-center bg-light">
            <div class="text-muted">% Contratista (<?= number_format($porc*100,2,',','.') ?>%)</div>
            <div class="fw-semibold pct-caja-<?= $id_cont ?>"><?= fmt_c($pct_tot) ?></div>
          </div>
        </div>
        <?php if ($bono > 0): ?>
        <div class="col-auto">
          <div class="border rounded px-3 py-2 text-center bg-light">
            <div class="text-muted">Bono semanal</div>
            <div class="fw-semibold"><?= fmt_c($bono) ?></div>
          </div>
        </div>
        <?php endif; ?>
        <div class="col-auto">
          <div class="border rounded px-3 py-2 text-center bg-success text-white">
            <div>Total a facturar</div>
            <div class="fw-bold fs-6 gran-total-<?= $id_cont ?>"><?= fmt_c($gran_tot) ?></div>
          </div>
        </div>
      </div>

    </div>
  </div>

  <script>
  (function() {
    var vd   = <?= $vd ?>;
    var porc = <?= $porc ?>;
    var bono = <?= $bono ?>;
    var cid  = <?= $id_cont ?>;

    function recalc() {
      var total = 0;
      document.querySelectorAll('.caja-input[data-cont="'+cid+'"]').forEach(function(inp){
        total += parseFloat(inp.value.replace(',','.')) || 0;
      });
      var base = total * vd;
      var pct  = base * porc;
      var gran = base + pct + bono;
      var fmt = function(n){ return '$'+Math.round(n).toLocaleString('es-CL'); };
      var fmtN = function(n){ return n.toLocaleString('es-CL',{minimumFractionDigits:2,maximumFractionDigits:2}); };
      document.querySelector('.total-cajas-'+cid).textContent     = fmtN(total);
      document.querySelector('.total-cajas-txt-'+cid).textContent = fmtN(total);
      document.querySelector('.base-caja-'+cid).textContent       = fmt(base);
      document.querySelector('.pct-caja-'+cid).textContent        = fmt(pct);
      document.querySelector('.gran-total-'+cid).textContent      = fmt(gran);
    }

    document.querySelectorAll('.caja-input[data-cont="'+cid+'"]').forEach(function(inp){
      inp.addEventListener('input', recalc);
    });
  })();
  </script>

  <?php endforeach; ?>

  <div class="d-flex gap-2 mt-2">
    <button type="submit" class="btn btn-success">Guardar semana <?= $filtro_semana ?>/<?= $filtro_anio ?></button>
  </div>
</form>

<?php endif; ?>
</main>
<?php include __DIR__ . '/../partials/footer.php'; ?>
