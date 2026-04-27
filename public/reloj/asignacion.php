<?php
require_once __DIR__ . '/../_bootstrap.php';
if (!puede_modulo('reloj')) { header('Location: ' . BASE_URL . '/Inicio.php'); exit; }

$flash_ok = $flash_error = null;

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

// ── Guardar asignaciones ──────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['accion'] ?? '') === 'asignar') {
    $ids            = $_POST['ids']   ?? [];
    $cargos_post    = $_POST['cargos'] ?? [];
    $turnos_post    = $_POST['turnos'] ?? [];
    $id_contratista = (int)($_POST['id_contratista'] ?? 0);

    if (!$id_contratista || empty($ids)) {
        $flash_error = "Selecciona contratista y al menos un trabajador.";
    } else {
        $ok = 0;
        foreach ($ids as $wid) {
            $wid      = (int)$wid;
            $id_cargo = (int)($cargos_post[$wid] ?? 0) ?: null;
            $id_turno = (int)($turnos_post[$wid] ?? 0) ?: null;
            sqlsrv_query($conn,
                "UPDATE dbo.reloj_trabajador SET id_contratista=?, id_cargo=?, id_turno=? WHERE id=?",
                [$id_contratista, $id_cargo, $id_turno, $wid]);
            $ok++;
        }
        $flash_ok = "$ok trabajador(es) asignados.";
    }
}

// ── Remover asignación ────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['accion'] ?? '') === 'remover') {
    $wid = (int)$_POST['wid'];
    sqlsrv_query($conn,
        "UPDATE dbo.reloj_trabajador SET id_contratista=NULL, id_cargo=NULL, id_turno=NULL WHERE id=?",
        [$wid]);
    $flash_ok = "Asignación removida.";
}

// ── Datos de referencia ───────────────────────────────────────────
// Usar nombre (persona) en lugar de razon_social
$q_cont = sqlsrv_query($conn,
    "SELECT id, nombre, razon_social FROM dbo.dota_contratista ORDER BY nombre");
$contratistas = [];
while ($r = sqlsrv_fetch_array($q_cont, SQLSRV_FETCH_ASSOC))
    $contratistas[$r['id']] = [
        'nombre'       => $r['nombre'],
        'razon_social' => $r['razon_social'],
    ];

$q_cargo = sqlsrv_query($conn,
    "SELECT id_cargo, cargo FROM dbo.Dota_Cargo ORDER BY cargo");
$cargos_list = [];
while ($r = sqlsrv_fetch_array($q_cargo, SQLSRV_FETCH_ASSOC))
    $cargos_list[$r['id_cargo']] = $r['cargo'];

$q_turno = sqlsrv_query($conn,
    "SELECT id, nombre_turno FROM dbo.dota_turno ORDER BY nombre_turno");
$turnos_list = [];
while ($r = sqlsrv_fetch_array($q_turno, SQLSRV_FETCH_ASSOC))
    $turnos_list[$r['id']] = $r['nombre_turno'];

// Secciones activas
$sections = array_unique(array_filter(array_map('intval', $_GET['c'] ?? [])));

$title = "Reloj — Asignación a Contratistas";
include __DIR__ . '/../partials/header.php';
include __DIR__ . '/../partials/navbar_wrapper.php';
?>
<main class="container-fluid py-4">

  <div class="d-flex justify-content-between align-items-center mb-3">
    <h4 class="mb-0">Asignación de Trabajadores a Contratistas</h4>
    <a href="trabajadores.php" class="btn btn-sm btn-outline-secondary">&#8592; Volver</a>
  </div>

  <?php if ($flash_ok):    ?><div class="alert alert-success"><?= htmlspecialchars($flash_ok) ?></div><?php endif; ?>
  <?php if ($flash_error): ?><div class="alert alert-danger"><?= htmlspecialchars($flash_error) ?></div><?php endif; ?>

  <!-- Selector para agregar sección -->
  <div class="card shadow-sm mb-4">
    <div class="card-body d-flex gap-3 align-items-end flex-wrap">
      <div class="flex-grow-1">
        <label class="form-label mb-1 fw-semibold">Agregar contratista</label>
        <select id="selectContratista" class="form-select">
          <option value="">— Seleccionar —</option>
          <?php foreach ($contratistas as $cid => $cdata): ?>
            <?php if (!in_array($cid, $sections)): ?>
              <option value="<?= $cid ?>"><?= htmlspecialchars($cdata['nombre']) ?></option>
            <?php endif; ?>
          <?php endforeach; ?>
        </select>
      </div>
      <button class="btn btn-primary" onclick="agregarSeccion()">+ Agregar sección</button>
    </div>
  </div>

  <?php if (empty($sections)): ?>
    <div class="alert alert-info">Selecciona un contratista arriba para comenzar.</div>
  <?php endif; ?>

  <?php foreach ($sections as $sec_id):
    if (!isset($contratistas[$sec_id])) continue;
    $sec_nombre = $contratistas[$sec_id]['nombre'];
    $sec_razon  = $contratistas[$sec_id]['razon_social'];

    // Trabajadores ya asignados a este contratista
    $asignados = sqlsrv_query($conn, "
        SELECT t.id, t.rut, t.nombre, t.id_cargo, t.id_turno,
               c.cargo AS nombre_cargo, tr.nombre_turno
        FROM dbo.reloj_trabajador t
        LEFT JOIN dbo.Dota_Cargo c ON c.id_cargo = t.id_cargo
        LEFT JOIN dbo.dota_turno tr ON tr.id = t.id_turno
        WHERE t.id_contratista = ? AND t.activo = 1
        ORDER BY t.nombre
    ", [$sec_id]);

    $otras_secs = array_values(array_filter($sections, fn($s) => $s !== $sec_id));
    $url_sin    = 'asignacion.php?' . http_build_query(['c' => $otras_secs]);
  ?>
  <div class="card shadow-sm mb-4 border-primary">
    <div class="card-header bg-primary text-white d-flex justify-content-between align-items-center py-2">
      <div>
        <span class="fw-bold fs-5">&#128203; <?= htmlspecialchars($sec_nombre) ?></span>
        <small class="ms-2 opacity-75"><?= htmlspecialchars($sec_razon) ?></small>
      </div>
      <a href="<?= htmlspecialchars($url_sin) ?>" class="btn btn-sm btn-outline-light">&#10005; Cerrar</a>
    </div>
    <div class="card-body">

      <!-- Búsqueda en vivo -->
      <div class="mb-3">
        <label class="form-label fw-semibold mb-1">Buscar trabajador</label>
        <input type="text"
               id="search_<?= $sec_id ?>"
               class="form-control"
               placeholder="Escriba nombre o RUT..."
               autocomplete="off"
               oninput="buscarTrabajador(<?= $sec_id ?>)">
        <div id="resultados_<?= $sec_id ?>" class="mt-2"></div>
      </div>

      <!-- Trabajadores ya asignados -->
      <h6 class="text-muted mt-4 mb-2">
        Trabajadores asignados
        <span class="badge bg-primary" id="cnt_asig_<?= $sec_id ?>"></span>
      </h6>
      <div class="table-responsive" id="tabla_asig_<?= $sec_id ?>">
        <table class="table table-sm align-middle border">
          <thead class="table-secondary">
            <tr><th>RUT</th><th>Nombre</th><th style="min-width:220px">Cargo</th><th style="min-width:220px">Turno</th><th></th></tr>
          </thead>
          <tbody>
          <?php
          $cnt_asig = 0;
          while ($a = sqlsrv_fetch_array($asignados, SQLSRV_FETCH_ASSOC)):
              $cnt_asig++;
          ?>
            <tr>
              <td><?= htmlspecialchars($a['rut']) ?></td>
              <td><?= htmlspecialchars($a['nombre']) ?></td>
              <td>
                <form method="post" class="d-flex gap-1 align-items-center">
                  <input type="hidden" name="accion" value="asignar">
                  <input type="hidden" name="id_contratista" value="<?= $sec_id ?>">
                  <input type="hidden" name="ids[]" value="<?= $a['id'] ?>">
                  <?php foreach ($sections as $s): ?>
                    <input type="hidden" name="_c[]" value="<?= $s ?>">
                  <?php endforeach; ?>
                  <select name="cargos[<?= $a['id'] ?>]" class="form-select form-select-sm">
                    <option value="">— Sin cargo —</option>
                    <?php foreach ($cargos_list as $cgid => $cgnombre): ?>
                      <option value="<?= $cgid ?>" <?= $a['id_cargo'] == $cgid ? 'selected' : '' ?>>
                        <?= htmlspecialchars($cgnombre) ?>
                      </option>
                    <?php endforeach; ?>
                  </select>
              </td>
              <td>
                  <select name="turnos[<?= $a['id'] ?>]" class="form-select form-select-sm">
                    <option value="">— Sin turno —</option>
                    <?php foreach ($turnos_list as $tid => $tnombre): ?>
                      <option value="<?= $tid ?>" <?= (int)($a['id_turno'] ?? 0) === (int)$tid ? 'selected' : '' ?>>
                        <?= htmlspecialchars($tnombre) ?>
                      </option>
                    <?php endforeach; ?>
                  </select>
                  <button class="btn btn-outline-primary btn-sm" style="white-space:nowrap">Guardar</button>
                </form>
              </td>
              <td>
                <form method="post" class="d-inline"
                      onsubmit="return confirm('¿Remover a <?= htmlspecialchars($a['nombre'], ENT_QUOTES) ?>?')">
                  <input type="hidden" name="accion" value="remover">
                  <input type="hidden" name="wid" value="<?= $a['id'] ?>">
                  <?php foreach ($sections as $s): ?>
                    <input type="hidden" name="_c[]" value="<?= $s ?>">
                  <?php endforeach; ?>
                  <button class="btn btn-outline-danger btn-sm">&#10005;</button>
                </form>
              </td>
            </tr>
          <?php endwhile; ?>
          <?php if ($cnt_asig === 0): ?>
            <tr id="row_empty_<?= $sec_id ?>">
              <td colspan="5" class="text-center text-muted py-2">
                Busca trabajadores arriba para agregar.
              </td>
            </tr>
          <?php endif; ?>
          </tbody>
        </table>
      </div>
      <small class="text-muted" id="lbl_cnt_<?= $sec_id ?>"><?= $cnt_asig ?> asignado(s).</small>
    </div>
  </div>
  <?php endforeach; ?>

</main>

<!-- Template fila de resultado de búsqueda (oculto) -->
<template id="tpl_resultado">
  <div class="d-flex align-items-center gap-2 p-2 border rounded mb-1 bg-white resultado-item">
    <div class="flex-grow-1">
      <span class="fw-semibold nombre-w"></span>
      <small class="text-muted ms-2 rut-w"></small>
      <small class="badge bg-warning text-dark ms-1 cont-w" style="display:none"></small>
    </div>
    <select class="form-select form-select-sm cargo-sel" style="max-width:200px">
      <option value="">— Sin cargo —</option>
      <?php foreach ($cargos_list as $cgid => $cgnombre): ?>
        <option value="<?= $cgid ?>"><?= htmlspecialchars($cgnombre) ?></option>
      <?php endforeach; ?>
    </select>
    <select class="form-select form-select-sm turno-sel" style="max-width:200px">
      <option value="">— Sin turno —</option>
      <?php foreach ($turnos_list as $tid => $tnombre): ?>
        <option value="<?= $tid ?>"><?= htmlspecialchars($tnombre) ?></option>
      <?php endforeach; ?>
    </select>
    <button class="btn btn-success btn-sm btn-asignar" style="white-space:nowrap">+ Asignar</button>
  </div>
</template>

<?php include __DIR__ . '/../partials/footer.php'; ?>
<script>
const SECTIONS = <?= json_encode(array_values($sections)) ?>;

// ── Agregar sección ───────────────────────────────────────────────
function agregarSeccion() {
    const cid = document.getElementById('selectContratista').value;
    if (!cid) return;
    const params = new URLSearchParams();
    [...SECTIONS, cid].forEach(s => params.append('c[]', s));
    window.location.href = 'asignacion.php?' + params.toString();
}

// ── Búsqueda en vivo ──────────────────────────────────────────────
const debounceTimers = {};

function buscarTrabajador(secId) {
    clearTimeout(debounceTimers[secId]);
    const q = document.getElementById('search_' + secId).value.trim();
    const box = document.getElementById('resultados_' + secId);

    if (q.length < 2) { box.innerHTML = ''; return; }

    debounceTimers[secId] = setTimeout(() => {
        box.innerHTML = '<div class="text-muted small p-1">Buscando...</div>';
        fetch('buscar_trabajadores.php?q=' + encodeURIComponent(q) + '&excluir=' + secId)
            .then(r => r.json())
            .then(data => renderResultados(secId, data, q))
            .catch(() => { box.innerHTML = '<div class="text-danger small">Error al buscar.</div>'; });
    }, 280);
}

function renderResultados(secId, data, q) {
    const box = document.getElementById('resultados_' + secId);
    if (data.length === 0) {
        box.innerHTML = '<div class="text-muted small p-2">Sin resultados para "' + htmlEsc(q) + '".</div>';
        return;
    }
    box.innerHTML = '<div class="mb-1 text-muted small">' + data.length + ' resultado(s):</div>';
    const tpl = document.getElementById('tpl_resultado');

    data.forEach(w => {
        const el = tpl.content.cloneNode(true);
        el.querySelector('.nombre-w').textContent = w.nombre;
        el.querySelector('.rut-w').textContent    = w.rut;
        if (w.nombre_contratista) {
            const b = el.querySelector('.cont-w');
            b.textContent = 'En: ' + w.nombre_contratista;
            b.style.display = '';
        }
        if (w.id_cargo) {
            const sel = el.querySelector('.cargo-sel');
            sel.value = w.id_cargo;
        }
        if (w.id_turno) {
            const selTurno = el.querySelector('.turno-sel');
            selTurno.value = w.id_turno;
        }
        const btn = el.querySelector('.btn-asignar');
        btn.dataset.wid   = w.id;
        btn.dataset.secId = secId;
        btn.addEventListener('click', function() {
            const cargo = this.closest('.resultado-item').querySelector('.cargo-sel').value;
            const turno = this.closest('.resultado-item').querySelector('.turno-sel').value;
            asignarTrabajador(this.dataset.wid, this.dataset.secId, cargo, turno, w.nombre);
        });
        box.appendChild(el);
    });
}

function asignarTrabajador(wid, secId, cargoId, turnoId, nombre) {
    const fd = new FormData();
    fd.append('accion', 'asignar');
    fd.append('id_contratista', secId);
    fd.append('ids[]', wid);
    fd.append('cargos[' + wid + ']', cargoId);
    fd.append('turnos[' + wid + ']', turnoId);
    SECTIONS.forEach(s => fd.append('_c[]', s));

    fetch('asignacion.php?' + new URLSearchParams(SECTIONS.map(s => ['c[]', s])).toString(), {
        method: 'POST', body: fd
    }).then(() => {
        // Quitar el item de resultados
        document.getElementById('resultados_' + secId)
            .querySelectorAll('.resultado-item')
            .forEach(el => {
                if (el.querySelector('.btn-asignar').dataset.wid == wid) el.remove();
            });
        // Agregar fila a la tabla de asignados
        agregarFilaAsignado(secId, wid, nombre, cargoId);
    });
}

function agregarFilaAsignado(secId, wid, nombre, cargoId) {
    // Recarga la sección para mostrar la fila actualizada
    const url = 'asignacion.php?' + new URLSearchParams(SECTIONS.map(s => ['c[]', s])).toString();
    window.location.href = url;
}

function htmlEsc(s) {
    return s.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
}

// ── Redirigir post manteniendo secciones ──────────────────────────
<?php
$secs_post = array_filter(array_map('intval', $_POST['_c'] ?? []));
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $secs_post):
    $qs = implode('&', array_map(fn($s) => 'c[]='.$s, $secs_post));
?>
if (window.location.search !== '?<?= $qs ?>') {
    window.history.replaceState(null,'','asignacion.php?<?= $qs ?>');
}
<?php endif; ?>
</script>
