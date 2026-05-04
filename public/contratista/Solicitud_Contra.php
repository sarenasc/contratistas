<?php
require_once __DIR__ . '/../_bootstrap.php';
require_once __DIR__ . '/../../app/lib/db.php';

$flash_error = null;
$flash_ok    = null;

// Catálogos
$contratistas = [];
$q = sqlsrv_query($conn, "SELECT id, nombre FROM dbo.dota_contratista ORDER BY nombre");
if ($q) while ($r = sqlsrv_fetch_array($q, SQLSRV_FETCH_ASSOC)) $contratistas[] = $r;

$cargos = [];
$q = sqlsrv_query($conn, "SELECT id_cargo, cargo FROM dbo.Dota_Cargo ORDER BY cargo");
if ($q) while ($r = sqlsrv_fetch_array($q, SQLSRV_FETCH_ASSOC)) $cargos[] = $r;

$areas = [];
$q = sqlsrv_query($conn, "SELECT id_area, Area FROM dbo.Area ORDER BY Area");
if ($q) while ($r = sqlsrv_fetch_array($q, SQLSRV_FETCH_ASSOC)) $areas[] = $r;

$turnos = [];
$q = sqlsrv_query($conn, "SELECT id, nombre_turno FROM dbo.dota_turno ORDER BY nombre_turno");
if ($q) while ($r = sqlsrv_fetch_array($q, SQLSRV_FETCH_ASSOC)) $turnos[] = $r;

$especies = [];
$q = sqlsrv_query($conn, "SELECT id_especie, especie FROM dbo.especie ORDER BY especie");
if ($q) while ($r = sqlsrv_fetch_array($q, SQLSRV_FETCH_ASSOC)) $especies[] = $r;

function solicitud_fechas_periodo(string $fecha, string $periodo): array {
    $base = DateTime::createFromFormat('Y-m-d', $fecha);
    if (!$base) return [];
    if ($periodo !== 'semana') return [$base];

    $inicio = clone $base;
    $inicio->modify('monday this week');
    $fechas = [];
    for ($i = 0; $i < 7; $i++) {
        $dia = clone $inicio;
        $dia->modify("+{$i} days");
        $fechas[] = $dia;
    }
    return $fechas;
}

/* ── Guardar múltiples cargos × especies ── */
if (isset($_POST['guardar_multiple'])) {
    $contratista = (int)$_POST['contratista'];
    $area        = (int)$_POST['area'];
    $id_turno    = (int)($_POST['id_turno'] ?? 0) ?: null;
    $fecha       = trim($_POST['fecha']);
    $periodo     = ($_POST['periodo'] ?? 'dia') === 'semana' ? 'semana' : 'dia';
    $ids_cargo   = $_POST['ids_cargo']   ?? [];
    $ids_especie = $_POST['ids_especie'] ?? [0];
    $cantidades  = $_POST['cantidades']  ?? [];

    if (!$contratista || !$area || !$fecha || empty($ids_cargo)) {
        $flash_error = "Faltan datos obligatorios.";
    } else {
        $fechas = solicitud_fechas_periodo($fecha, $periodo);
        $guardados = $errores = 0;

        foreach ($ids_cargo as $ci => $id_cargo) {
            $id_cargo = (int)$id_cargo;
            if ($id_cargo <= 0) continue;

            foreach ($ids_especie as $ei => $raw_esp) {
                $id_especie = (int)$raw_esp ?: null;

                $cantidad = is_array($cantidades[$ci] ?? null)
                    ? (int)($cantidades[$ci][$ei] ?? 0)
                    : (int)($cantidades[$ci] ?? 0);
                if ($cantidad <= 0) continue;

                foreach ($fechas as $dt) {
                    $stmtV = sqlsrv_query($conn,
                        "SELECT ISNULL(MAX(version),0) AS max_v FROM dbo.dota_solicitud_contratista
                         WHERE contratista=? AND cargo=? AND area=? AND ISNULL(id_especie,-1)=ISNULL(?,-1)",
                        [$contratista,$id_cargo,$area,$id_especie]);
                    $rowV    = $stmtV ? sqlsrv_fetch_array($stmtV, SQLSRV_FETCH_ASSOC) : ['max_v'=>0];
                    $version = (int)$rowV['max_v'] + 1;

                    $res = sqlsrv_query($conn,
                        "INSERT INTO dbo.dota_solicitud_contratista
                           (contratista,cargo,area,cantidad,version,fecha,id_turno,id_especie)
                         VALUES (?,?,?,?,?,?,?,?)",
                        [$contratista,$id_cargo,$area,$cantidad,$version,$dt,$id_turno,$id_especie]);
                    $res !== false ? $guardados++ : $errores++;
                }
            }
        }
        $flash_ok = $errores === 0
            ? "Se guardaron {$guardados} registro(s)."
            : "Se guardaron {$guardados} con {$errores} error(es).";
    }
}

/* ── Editar ── */
if (isset($_POST['editar'])) {
    $id         = (int)$_POST['id'];
    $contratista= (int)$_POST['contratista'];
    $cargo      = (int)$_POST['cargo'];
    $area       = (int)$_POST['area'];
    $cantidad   = (int)$_POST['cantidad'];
    $fecha      = trim($_POST['fecha']);
    $id_turno   = (int)($_POST['id_turno'] ?? 0) ?: null;
    $id_especie = (int)($_POST['id_especie'] ?? 0) ?: null;
    $dt         = DateTime::createFromFormat('Y-m-d', $fecha);
    $res        = sqlsrv_query($conn,
        "UPDATE dbo.dota_solicitud_contratista
         SET contratista=?,cargo=?,area=?,cantidad=?,fecha=?,id_turno=?,id_especie=? WHERE id=?",
        [$contratista,$cargo,$area,$cantidad,$dt,$id_turno,$id_especie,$id]);
    $flash_ok = $res !== false ? "Registro actualizado." : "Error al actualizar.";
}

/* ── Eliminar ── */
if (isset($_POST['eliminar'])) {
    $id  = (int)$_POST['id'];
    $res = sqlsrv_query($conn,"DELETE FROM dbo.dota_solicitud_contratista WHERE id=?",[$id]);
    $flash_ok = $res !== false ? "Registro eliminado." : "Error al eliminar.";
}

// Consultar registros existentes
$query = sqlsrv_query($conn,
    "SELECT S.id,S.cantidad,S.version,S.fecha,
            S.contratista,S.cargo AS id_cargo,S.area AS id_area,S.id_turno,S.id_especie,
            C.nombre AS contratista_nombre,CA.cargo,A.Area,T.nombre_turno,E.especie
     FROM dbo.dota_solicitud_contratista S
     LEFT JOIN dbo.dota_contratista C ON C.id        = S.contratista
     LEFT JOIN dbo.Dota_Cargo      CA ON CA.id_cargo  = S.cargo
     LEFT JOIN dbo.Area             A ON A.id_area    = S.area
     LEFT JOIN dbo.dota_turno       T ON T.id         = S.id_turno
     LEFT JOIN dbo.especie          E ON E.id_especie = S.id_especie
     ORDER BY S.id DESC");
if ($query === false) $flash_error = "Error al consultar.";

$title = "Solicitudes de Contratistas";
include __DIR__ . '/../partials/header.php';
include __DIR__ . '/../partials/navbar_wrapper.php';
?>

<style>
/* Toggle de especie: estado seleccionado más visible */
.btn-check:checked + .btn-esp { background:#0d6efd; color:#fff; border-color:#0d6efd; }
.btn-check:checked + .btn-esp-mixta { background:#6c757d; color:#fff; border-color:#6c757d; }
/* Tabla preview: compacta */
#xl-preview .qty-inp { width:60px; padding:2px 4px; font-size:.8rem; }
#xl-preview th,#xl-preview td { font-size:.8rem; padding:4px 6px; white-space:nowrap; }
.row-sin-match td { background:#fff3cd !important; }
</style>

<main class="container-fluid py-4" style="max-width:1400px">

<?php if ($flash_error): ?>
<div class="alert alert-danger"><?= htmlspecialchars($flash_error) ?></div>
<?php endif; ?>
<?php if ($flash_ok): ?>
<div class="alert alert-success"><?= htmlspecialchars($flash_ok) ?></div>
<?php endif; ?>

<h1 class="text-center mb-4 h4">Gestión de Solicitudes de Contratistas</h1>

<!-- ══════════════════════════════════════════
     CARGA DESDE EXCEL (panel principal)
══════════════════════════════════════════ -->
<div class="card mb-4 shadow-sm">
  <div class="card-header fw-semibold bg-success text-white">
    Carga desde Excel — Dotaciones Packing
  </div>
  <div class="card-body">

    <form method="POST" action="carga_solicitud.php" enctype="multipart/form-data">
      <div class="row g-3 align-items-end">
        <div class="col-md-3">
          <label class="form-label fw-semibold">Fecha de la solicitud <span class="text-danger">*</span></label>
          <input type="date" name="fecha" class="form-control" value="<?= date('Y-m-d') ?>" required>
        </div>
        <div class="col-md-2">
          <label class="form-label fw-semibold">Periodo</label>
          <select name="periodo" class="form-select">
            <option value="dia">Por día</option>
            <option value="semana">Semana completa</option>
          </select>
        </div>
        <div class="col-md-3">
          <label class="form-label fw-semibold">Turno <span class="text-muted fw-normal">(opcional)</span></label>
          <select name="id_turno" class="form-select">
            <option value="">-- Sin turno --</option>
            <?php foreach ($turnos as $t): ?>
            <option value="<?= (int)$t['id'] ?>"><?= htmlspecialchars($t['nombre_turno']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-md-4">
          <label class="form-label fw-semibold">Archivo Excel <span class="text-danger">*</span>
            <span class="text-muted fw-normal small">(hoja «Dotaciones 25-26»)</span>
          </label>
          <input type="file" name="archivo" class="form-control" accept=".xlsx,.xls" required>
        </div>
        <div class="col-md-12 col-lg-2">
          <button type="submit" name="cargar_excel" class="btn btn-success w-100">
            Cargar y mapear
          </button>
        </div>
      </div>
      <div class="form-text mt-2">
        La carga continúa en 3 pasos: mapeo de cargos, áreas y especies; asignación de contratistas; guardado.
      </div>
    </form>

  </div>
</div>

<!-- ══════════════════════════════════════════
     FORMULARIO MANUAL (nueva solicitud)
══════════════════════════════════════════ -->
<div class="card mb-4 shadow-sm">
  <div class="card-header fw-semibold bg-dark text-white d-flex justify-content-between align-items-center">
    <span>Nueva Solicitud Manual</span>
    <button class="btn btn-sm btn-outline-light" type="button" data-bs-toggle="collapse" data-bs-target="#manual-body">
      Mostrar / Ocultar
    </button>
  </div>
  <div class="collapse" id="manual-body">
    <div class="card-body">

      <!-- PASO 1 -->
      <div id="paso1">
        <div class="row g-3">
          <div class="col-md-3">
            <label class="form-label fw-semibold">Contratista <span class="text-danger">*</span></label>
            <select id="p1-contratista" class="form-select">
              <option value="">-- Seleccione --</option>
              <?php foreach ($contratistas as $c): ?>
              <option value="<?= (int)$c['id'] ?>"><?= htmlspecialchars($c['nombre']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-md-3">
            <label class="form-label fw-semibold">Área <span class="text-danger">*</span></label>
            <select id="p1-area" class="form-select">
              <option value="">-- Seleccione --</option>
              <?php foreach ($areas as $a): ?>
              <option value="<?= (int)$a['id_area'] ?>"><?= htmlspecialchars($a['Area']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-md-3">
            <label class="form-label fw-semibold">Turno</label>
            <select id="p1-turno" class="form-select">
              <option value="">-- Sin turno --</option>
              <?php foreach ($turnos as $t): ?>
              <option value="<?= (int)$t['id'] ?>"><?= htmlspecialchars($t['nombre_turno']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-md-3">
            <label class="form-label fw-semibold">Fecha <span class="text-danger">*</span></label>
            <input type="date" id="p1-fecha" class="form-control" value="<?= date('Y-m-d') ?>">
          </div>
          <div class="col-md-3">
            <label class="form-label fw-semibold">Periodo</label>
            <select id="p1-periodo" class="form-select">
              <option value="dia">Por día</option>
              <option value="semana">Semana completa</option>
            </select>
          </div>

          <!-- ESPECIE — toggle buttons -->
          <div class="col-12">
            <label class="form-label fw-semibold">Especie(s) <span class="text-danger">*</span></label>
            <div class="d-flex flex-wrap gap-1 align-items-center p-2 border rounded bg-light">
              <!-- Mixta -->
              <input type="checkbox" class="btn-check" id="esp-mixta" value="0" autocomplete="off">
              <label class="btn btn-outline-secondary btn-sm btn-esp-mixta" for="esp-mixta">
                ⊕ Mixta
              </label>
              <div class="vr mx-1"></div>
              <?php foreach ($especies as $e): ?>
              <input type="checkbox" class="btn-check esp-chk"
                     id="esp-<?= (int)$e['id_especie'] ?>"
                     value="<?= (int)$e['id_especie'] ?>" autocomplete="off">
              <label class="btn btn-outline-primary btn-sm btn-esp"
                     for="esp-<?= (int)$e['id_especie'] ?>">
                <?= htmlspecialchars($e['especie']) ?>
              </label>
              <?php endforeach; ?>
            </div>
            <div id="esp-count" class="text-muted small mt-1"></div>
          </div>

          <!-- CARGOS — buscador con tags -->
          <div class="col-12">
            <label class="form-label fw-semibold">Cargos <span class="text-danger">*</span></label>
            <div class="position-relative" style="max-width:480px">
              <input type="text" id="cargo-search" class="form-control"
                     placeholder="Buscar cargo..." autocomplete="off">
              <ul id="cargo-dropdown" class="list-group position-absolute w-100 shadow-sm"
                  style="display:none;z-index:1000;max-height:220px;overflow-y:auto;top:100%"></ul>
            </div>
            <div id="cargo-tags" class="d-flex flex-wrap gap-2 mt-2"></div>
            <div class="text-muted small mt-1" id="p1-sel-count"></div>
          </div>
        </div>

        <div class="mt-3">
          <button type="button" class="btn btn-primary" onclick="continuar()">Continuar →</button>
        </div>
      </div><!-- /paso1 -->

      <!-- PASO 2 -->
      <div id="paso2" style="display:none">
        <div class="alert alert-info py-2 mb-3" id="paso2-resumen"></div>
        <form method="POST" id="form-multiple">
          <input type="hidden" name="guardar_multiple" value="1">
          <input type="hidden" name="contratista" id="p2-contratista">
          <input type="hidden" name="area"         id="p2-area">
          <input type="hidden" name="id_turno"     id="p2-turno">
          <input type="hidden" name="fecha"        id="p2-fecha">
          <input type="hidden" name="periodo"      id="p2-periodo">

          <div class="table-responsive">
            <table class="table table-bordered table-sm align-middle">
              <thead class="table-secondary" id="paso2-thead"></thead>
              <tbody id="paso2-body"></tbody>
            </table>
          </div>

          <div class="d-flex gap-2 mt-2">
            <button type="button" class="btn btn-outline-secondary" onclick="volver()">← Volver</button>
            <button type="submit" class="btn btn-success">Guardar Todo</button>
          </div>
        </form>
      </div><!-- /paso2 -->

    </div>
  </div>
</div>

<!-- ══════════════════════════════════════════
     TABLA DE REGISTROS EXISTENTES
══════════════════════════════════════════ -->
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
  <h2 class="h5 mb-0">Registros Existentes</h2>
  <div class="d-flex align-items-center gap-2">
    <label for="sol-page-size" class="form-label mb-0 small text-muted">Registros por vista</label>
    <select id="sol-page-size" class="form-select form-select-sm" style="width:auto">
      <option value="25">25</option>
      <option value="50">50</option>
      <option value="75">75</option>
      <option value="100">100</option>
    </select>
  </div>
</div>
<div class="table-responsive">
  <table class="table table-bordered table-hover table-sm" id="tabla-solicitudes">
    <thead class="table-dark">
      <tr>
        <th>ID</th><th>Contratista</th><th>Cargo</th><th>Área</th>
        <th>Turno</th><th>Especie</th><th>Cantidad</th><th>Ver.</th><th>Fecha</th><th>Acciones</th>
      </tr>
    </thead>
    <tbody>
    <?php while ($query && $row = sqlsrv_fetch_array($query, SQLSRV_FETCH_ASSOC)):
        $fecha_val = ($row['fecha'] instanceof DateTime) ? $row['fecha']->format('Y-m-d') : (string)$row['fecha'];
    ?>
      <tr>
        <td><?= (int)$row['id'] ?></td>
        <td>
          <select class="form-select form-select-sm inp-contratista">
            <?php foreach ($contratistas as $c): ?>
            <option value="<?= (int)$c['id'] ?>" <?= (int)$c['id']===(int)$row['contratista']?'selected':'' ?>>
              <?= htmlspecialchars($c['nombre']) ?>
            </option>
            <?php endforeach; ?>
          </select>
        </td>
        <td>
          <select class="form-select form-select-sm inp-cargo">
            <?php foreach ($cargos as $c): ?>
            <option value="<?= (int)$c['id_cargo'] ?>" <?= (int)$c['id_cargo']===(int)$row['id_cargo']?'selected':'' ?>>
              <?= htmlspecialchars($c['cargo']) ?>
            </option>
            <?php endforeach; ?>
          </select>
        </td>
        <td>
          <select class="form-select form-select-sm inp-area">
            <?php foreach ($areas as $a): ?>
            <option value="<?= (int)$a['id_area'] ?>" <?= (int)$a['id_area']===(int)$row['id_area']?'selected':'' ?>>
              <?= htmlspecialchars($a['Area']) ?>
            </option>
            <?php endforeach; ?>
          </select>
        </td>
        <td>
          <select class="form-select form-select-sm inp-turno">
            <option value="">--</option>
            <?php foreach ($turnos as $t): ?>
            <option value="<?= (int)$t['id'] ?>" <?= (int)$t['id']===(int)($row['id_turno']??0)?'selected':'' ?>>
              <?= htmlspecialchars($t['nombre_turno']) ?>
            </option>
            <?php endforeach; ?>
          </select>
        </td>
        <td>
          <select class="form-select form-select-sm inp-especie">
            <option value="0" <?= empty($row['id_especie'])?'selected':'' ?>>Mixta</option>
            <?php foreach ($especies as $e): ?>
            <option value="<?= (int)$e['id_especie'] ?>" <?= (int)$e['id_especie']===(int)($row['id_especie']??0)?'selected':'' ?>>
              <?= htmlspecialchars($e['especie']) ?>
            </option>
            <?php endforeach; ?>
          </select>
        </td>
        <td><input type="number" class="form-control form-control-sm inp-cantidad"
                   value="<?= (int)$row['cantidad'] ?>" min="1" style="width:75px"></td>
        <td class="text-center"><?= (int)$row['version'] ?></td>
        <td><input type="date" class="form-control form-control-sm inp-fecha"
                   value="<?= htmlspecialchars($fecha_val) ?>"></td>
        <td class="text-nowrap">
          <button class="btn btn-warning btn-sm" onclick="accion('editar',<?= (int)$row['id'] ?>,this)">Editar</button>
          <button class="btn btn-danger btn-sm"  onclick="accion('eliminar',<?= (int)$row['id'] ?>,this)">Eliminar</button>
        </td>
      </tr>
    <?php endwhile; ?>
    </tbody>
  </table>
</div>

<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mt-2">
  <div id="sol-page-info" class="text-muted small"></div>
  <nav aria-label="Paginación de solicitudes">
    <ul class="pagination pagination-sm mb-0" id="sol-pagination"></ul>
  </nav>
</div>

<div class="mt-3">
  <a href="Excel_Sol_dota.php" class="btn btn-success btn-sm">Exportar a Excel</a>
</div>

<!-- Form oculto editar/eliminar -->
<form id="form-accion" method="POST">
  <input type="hidden" name="id"          id="f-id">
  <input type="hidden" name="contratista" id="f-contratista">
  <input type="hidden" name="cargo"       id="f-cargo">
  <input type="hidden" name="area"        id="f-area">
  <input type="hidden" name="id_turno"    id="f-turno">
  <input type="hidden" name="id_especie"  id="f-especie">
  <input type="hidden" name="cantidad"    id="f-cantidad">
  <input type="hidden" name="fecha"       id="f-fecha">
  <button type="submit" name="editar"   id="f-btn-editar"   style="display:none"></button>
  <button type="submit" name="eliminar" id="f-btn-eliminar" style="display:none"></button>
</form>

</main>

<script>
/* ── Diccionarios ── */
var nomContratista = {};
var nomArea = {}, nomTurno = {}, nomEspecie = {0:'Mixta'};
<?php foreach ($contratistas as $c): ?>
nomContratista[<?= (int)$c['id'] ?>] = <?= json_encode($c['nombre']) ?>;
<?php endforeach; ?>
<?php foreach ($areas as $a): ?>
nomArea[<?= (int)$a['id_area'] ?>] = <?= json_encode($a['Area']) ?>;
<?php endforeach; ?>
<?php foreach ($turnos as $t): ?>
nomTurno[<?= (int)$t['id'] ?>] = <?= json_encode($t['nombre_turno']) ?>;
<?php endforeach; ?>
<?php foreach ($especies as $e): ?>
nomEspecie[<?= (int)$e['id_especie'] ?>] = <?= json_encode($e['especie']) ?>;
<?php endforeach; ?>

/* Lista contratistas para la tabla de preview Excel */
var listaContratistas = <?= json_encode(array_values(array_map(fn($c)=>['id'=>(int)$c['id'],'nombre'=>$c['nombre']], $contratistas)),JSON_UNESCAPED_UNICODE) ?>;

/* ── Cargos para el buscador manual ── */
var todosLosCargos  = <?= json_encode(array_values(array_map(fn($c)=>['id'=>(int)$c['id_cargo'],'nombre'=>$c['cargo']],$cargos)),JSON_UNESCAPED_UNICODE) ?>;
var cargosSeleccionados = {};

var searchInput = document.getElementById('cargo-search');
var dropdown    = document.getElementById('cargo-dropdown');
var tagsDiv     = document.getElementById('cargo-tags');

searchInput.addEventListener('input', function(){
    var q = this.value.trim().toLowerCase();
    dropdown.innerHTML = '';
    if (q.length < 1){ dropdown.style.display='none'; return; }
    var res = todosLosCargos.filter(c=>c.nombre.toLowerCase().includes(q)&&!cargosSeleccionados[c.id]).slice(0,15);
    if (!res.length){ dropdown.style.display='none'; return; }
    res.forEach(c=>{
        var li = document.createElement('li');
        li.className='list-group-item list-group-item-action py-1 px-2';
        li.style.cursor='pointer';
        li.textContent=c.nombre;
        li.addEventListener('mousedown',e=>{ e.preventDefault(); agregarCargo(c.id,c.nombre); });
        dropdown.appendChild(li);
    });
    dropdown.style.display='';
});
searchInput.addEventListener('blur',()=>setTimeout(()=>{dropdown.style.display='none';},150));
searchInput.addEventListener('keydown',e=>{ if(e.key==='Escape'){dropdown.style.display='none';this.value='';} });

function agregarCargo(id,nombre){
    if(cargosSeleccionados[id]) return;
    cargosSeleccionados[id]=nombre;
    var tag=document.createElement('span');
    tag.className='badge bg-primary d-flex align-items-center gap-1 fw-normal px-2 py-1';
    tag.style.fontSize='.85rem';
    tag.innerHTML=nombre+' <button type="button" class="btn-close btn-close-white" style="font-size:.6rem"></button>';
    tag.querySelector('button').addEventListener('click',()=>{ delete cargosSeleccionados[id]; tag.remove(); actualizarContador(); });
    tagsDiv.appendChild(tag);
    searchInput.value=''; dropdown.style.display='none';
    actualizarContador(); searchInput.focus();
}
function actualizarContador(){
    var n=Object.keys(cargosSeleccionados).length;
    document.getElementById('p1-sel-count').textContent=n?n+' cargo(s)':'';
}

/* ── Lógica de toggle de especies ── */
var chkMixta = document.getElementById('esp-mixta');
var chksEsp  = Array.from(document.querySelectorAll('.esp-chk'));

chkMixta.addEventListener('change',function(){
    chksEsp.forEach(c=>{ c.checked=false; c.disabled=this.checked; });
    actualizarEspCount();
});
chksEsp.forEach(c=>c.addEventListener('change',()=>{ if(c.checked) chkMixta.checked=false; actualizarEspCount(); }));

function actualizarEspCount(){
    var d=document.getElementById('esp-count');
    if(chkMixta.checked){ d.textContent='Mixta seleccionada'; return; }
    var sel=chksEsp.filter(c=>c.checked);
    d.textContent=sel.length?sel.length+' especie(s) seleccionada(s)':'';
}
function getEspeciesSeleccionadas(){
    if(chkMixta.checked) return [{id:0,nombre:'Mixta'}];
    return chksEsp.filter(c=>c.checked).map(c=>({id:parseInt(c.value),nombre:nomEspecie[parseInt(c.value)]||c.value}));
}

/* ── Continuar Paso 2 (manual) ── */
function continuar(){
    var cont=document.getElementById('p1-contratista');
    var area=document.getElementById('p1-area');
    var fecha=document.getElementById('p1-fecha');
    var ids=Object.keys(cargosSeleccionados);
    var esps=getEspeciesSeleccionadas();

    if(!cont.value){ alert('Seleccione un contratista.'); return; }
    if(!area.value){ alert('Seleccione un área.'); return; }
    if(!fecha.value){ alert('Ingrese una fecha.'); return; }
    if(!ids.length){ alert('Agregue al menos un cargo.'); searchInput.focus(); return; }
    if(!esps.length){ alert('Seleccione una especie o Mixta.'); return; }

    document.getElementById('p2-contratista').value=cont.value;
    document.getElementById('p2-area').value=area.value;
    document.getElementById('p2-turno').value=document.getElementById('p1-turno').value;
    document.getElementById('p2-fecha').value=fecha.value;
    document.getElementById('p2-periodo').value=document.getElementById('p1-periodo').value;

    // Limpiar hidden anteriores
    document.querySelectorAll('input[name="ids_especie[]"]').forEach(e=>e.remove());
    var form=document.getElementById('form-multiple');
    esps.forEach(e=>{
        var inp=document.createElement('input');
        inp.type='hidden'; inp.name='ids_especie[]'; inp.value=e.id;
        form.appendChild(inp);
    });

    var turnoVal=document.getElementById('p1-turno').value;
    var periodoVal=document.getElementById('p1-periodo').value;
    var espNombres=esps.map(e=>e.nombre).join(', ');
    document.getElementById('paso2-resumen').innerHTML=
        '<strong>Contratista:</strong> '+(nomContratista[cont.value]||cont.value)
       +' | <strong>Área:</strong> '+(nomArea[area.value]||area.value)
       +' | <strong>Turno:</strong> '+(turnoVal?nomTurno[turnoVal]||turnoVal:'Sin turno')
       +' | <strong>Fecha:</strong> '+fecha.value
       +' | <strong>Periodo:</strong> '+(periodoVal==='semana'?'Semana completa':'Por día')
       +' | <strong>Especie(s):</strong> '+espNombres;

    // Cabecera
    var thRow='<tr><th>Cargo</th>';
    esps.forEach(e=>{ thRow+='<th style="min-width:110px">'+e.nombre+' <span class="text-danger">*</span></th>'; });
    document.getElementById('paso2-thead').innerHTML=thRow+'</tr>';

    // Filas
    var tbody=document.getElementById('paso2-body');
    tbody.innerHTML='';
    ids.forEach((id,ci)=>{
        var tr=document.createElement('tr');
        var html='<td>'+cargosSeleccionados[id]+'<input type="hidden" name="ids_cargo[]" value="'+id+'"></td>';
        if(esps.length===1){
            html+='<td><input type="number" name="cantidades[]" class="form-control form-control-sm" min="0" placeholder="0" style="width:110px"></td>';
        } else {
            esps.forEach((_,ei)=>{
                html+='<td><input type="number" name="cantidades['+ci+']['+ei+']" class="form-control form-control-sm" min="0" placeholder="0" style="width:110px"></td>';
            });
        }
        tr.innerHTML=html;
        tbody.appendChild(tr);
    });
    var primero=tbody.querySelector('input[type=number]');
    if(primero) primero.focus();

    document.getElementById('paso1').style.display='none';
    document.getElementById('paso2').style.display='';
}
function volver(){
    document.getElementById('paso1').style.display='';
    document.getElementById('paso2').style.display='none';
}

/* ══════════════════════════════════════════════
   IMPORTACIÓN EXCEL — 2 pasos
══════════════════════════════════════════════ */
var _xlData = null; // datos cargados del Excel

function cargarExcel(){
    var fecha=document.getElementById('xl-fecha').value;
    var file=document.getElementById('xl-file').files[0];
    if(!fecha){ alert('Ingrese la fecha.'); return; }
    if(!file){  alert('Seleccione el archivo Excel.'); return; }

    document.getElementById('xl-btn-txt').textContent='Cargando...';
    document.getElementById('xl-spinner').style.display='';
    document.getElementById('xl-btn-cargar').disabled=true;
    document.getElementById('xl-load-error').innerHTML='';

    var fd=new FormData();
    fd.append('archivo',file);
    fd.append('action','leer');

    fetch('importar_solicitud.php?action=leer',{method:'POST',body:fd})
    .then(function(r){
        if(!r.ok) return r.text().then(t=>{ throw new Error('HTTP '+r.status+': '+t.substring(0,300)); });
        return r.json();
    })
    .then(function(d){
        document.getElementById('xl-btn-txt').textContent='Cargar y previsualizar';
        document.getElementById('xl-spinner').style.display='none';
        document.getElementById('xl-btn-cargar').disabled=false;

        if(!d.ok){
            document.getElementById('xl-load-error').innerHTML=
                '<div class="alert alert-danger mt-2">'+d.msg+'</div>';
            return;
        }
        _xlData=d;
        renderPreview(d);
        document.getElementById('xl-paso2').style.display='';
        document.getElementById('xl-preview').scrollIntoView({behavior:'smooth',block:'nearest'});
    })
    .catch(function(err){
        document.getElementById('xl-btn-txt').textContent='Cargar y previsualizar';
        document.getElementById('xl-spinner').style.display='none';
        document.getElementById('xl-btn-cargar').disabled=false;
        document.getElementById('xl-load-error').innerHTML=
            '<div class="alert alert-danger mt-2"><strong>Error:</strong> '+err.message+'</div>';
    });
}

function renderPreview(d){
    // Sin coincidencias
    var warnDiv=document.getElementById('xl-sinmatch-warn');
    if(d.sin_match&&d.sin_match.length){
        var ul=d.sin_match.map(m=>'<li>'+m+'</li>').join('');
        warnDiv.innerHTML='<div class="alert alert-warning py-2"><strong>Sin coincidencia en BD:</strong><ul class="mb-0 mt-1">'+ul+'</ul></div>';
    } else { warnDiv.innerHTML=''; }

    // Botón asignar mismo contratista a todas las filas
    var optCont='<option value="">-- Asignar --</option>';
    listaContratistas.forEach(c=>{ optCont+='<option value="'+c.id+'">'+c.nombre+'</option>'; });

    // Header
    var html='<div class="mb-2 d-flex align-items-center gap-2">'
        +'<label class="form-label mb-0 fw-semibold">Asignar a todos:</label>'
        +'<select id="xl-contra-todos" class="form-select form-select-sm" style="max-width:220px" onchange="asignarATodos(this.value)">'+optCont+'</select>'
        +'</div>';

    html+='<table class="table table-bordered table-sm" id="xl-preview-tbl"><thead class="table-dark sticky-top"><tr>';
    html+='<th>Cargo</th><th>Área</th>';
    d.especies.forEach(e=>{ html+='<th>'+e.nombre+'</th>'; });
    html+='<th style="min-width:180px">Contratista <span class="text-danger">*</span></th>';
    html+='</tr></thead><tbody>';

    d.filas.forEach(function(fila,fi){
        var rowClass=fila.id_cargo?'':'row-sin-match';
        html+='<tr class="'+rowClass+'">';
        html+='<td>'+(fila.id_cargo?'':'<span class="badge bg-warning text-dark me-1" title="No encontrado en BD">!</span>')+fila.cargo+'</td>';
        html+='<td>'+fila.area+'</td>';
        d.especies.forEach(function(e,ei){
            var qty=fila.cantidades[ei]||0;
            var disabled=fila.id_cargo?'':' disabled';
            html+='<td><input type="number" class="form-control qty-inp"'
                +' data-fi="'+fi+'" data-ei="'+ei+'"'
                +' min="0" value="'+qty+'"'+disabled+'></td>';
        });
        html+='<td>';
        if(fila.id_cargo){
            html+='<select class="form-select form-select-sm contra-sel" data-fi="'+fi+'">'+optCont+'</select>';
        } else {
            html+='<span class="text-muted small">No en BD</span>';
        }
        html+='</td></tr>';
    });
    html+='</tbody></table>';
    document.getElementById('xl-preview').innerHTML=html;
}

function asignarATodos(val){
    document.querySelectorAll('.contra-sel').forEach(s=>{ if(val) s.value=val; });
}

function volverPaso1(){
    document.getElementById('xl-paso2').style.display='none';
    document.getElementById('xl-save-result').innerHTML='';
    _xlData=null;
}

function guardarAsignaciones(){
    if(!_xlData){ alert('No hay datos cargados.'); return; }
    var fecha=document.getElementById('xl-fecha').value;
    if(!fecha){ alert('Ingrese la fecha.'); return; }

    // Verificar al menos un contratista asignado
    var selects=Array.from(document.querySelectorAll('.contra-sel'));
    var hayAsignacion=selects.some(s=>s.value!='');
    if(!hayAsignacion){ alert('Asigna al menos un contratista antes de guardar.'); return; }

    // Construir payload
    var filas=[];
    selects.forEach(function(sel){
        if(!sel.value) return;
        var fi=parseInt(sel.dataset.fi);
        var fila=_xlData.filas[fi];
        if(!fila.id_cargo||!fila.id_area) return;

        var cantidades=[];
        _xlData.especies.forEach(function(e,ei){
            var inp=document.querySelector('.qty-inp[data-fi="'+fi+'"][data-ei="'+ei+'"]');
            var qty=inp?parseInt(inp.value)||0:0;
            if(qty>0) cantidades.push({id_especie:e.id,cantidad:qty});
        });
        if(!cantidades.length) return;

        filas.push({
            id_cargo:       fila.id_cargo,
            id_area:        fila.id_area,
            id_contratista: parseInt(sel.value),
            cantidades:     cantidades,
        });
    });

    if(!filas.length){ alert('No hay registros con cantidad > 0 y contratista asignado.'); return; }

    document.getElementById('xl-btn-guardar').disabled=true;
    document.getElementById('xl-save-spinner').style.display='';
    document.getElementById('xl-save-result').innerHTML='';

    var idTurno=document.getElementById('xl-turno').value||null;
    fetch('importar_solicitud.php?action=guardar',{
        method:'POST',
        headers:{'Content-Type':'application/json'},
        body:JSON.stringify({fecha:fecha,id_turno:idTurno,filas:filas})
    })
    .then(r=>r.json())
    .then(function(d){
        document.getElementById('xl-btn-guardar').disabled=false;
        document.getElementById('xl-save-spinner').style.display='none';
        var cls=d.ok?'alert-success':'alert-danger';
        document.getElementById('xl-save-result').innerHTML='<div class="alert '+cls+' py-2 mb-0">'+d.msg+'</div>';
        if(d.ok&&d.guardados>0) setTimeout(()=>location.reload(),2000);
    })
    .catch(()=>{
        document.getElementById('xl-btn-guardar').disabled=false;
        document.getElementById('xl-save-spinner').style.display='none';
        document.getElementById('xl-save-result').innerHTML='<div class="alert alert-danger py-2 mb-0">Error de conexión.</div>';
    });
}

/* ── Paginación de registros existentes ── */
var solTable = document.getElementById('tabla-solicitudes');
var solPageSize = document.getElementById('sol-page-size');
var solPageInfo = document.getElementById('sol-page-info');
var solPagination = document.getElementById('sol-pagination');
var solCurrentPage = 1;

function solRows(){
    return solTable ? Array.from(solTable.querySelectorAll('tbody tr')) : [];
}

function renderSolicitudesPage(){
    var rows = solRows();
    var pageSize = parseInt(solPageSize.value, 10) || 25;
    var totalPages = Math.max(1, Math.ceil(rows.length / pageSize));
    if (solCurrentPage > totalPages) solCurrentPage = totalPages;
    if (solCurrentPage < 1) solCurrentPage = 1;

    var start = (solCurrentPage - 1) * pageSize;
    var end = start + pageSize;
    rows.forEach(function(row, idx){
        row.style.display = (idx >= start && idx < end) ? '' : 'none';
    });

    var shownStart = rows.length ? start + 1 : 0;
    var shownEnd = Math.min(end, rows.length);
    solPageInfo.textContent = 'Mostrando ' + shownStart + ' a ' + shownEnd + ' de ' + rows.length + ' registros';
    renderSolicitudesPagination(totalPages);
}

function pageButton(label, page, disabled, active){
    var li = document.createElement('li');
    li.className = 'page-item' + (disabled ? ' disabled' : '') + (active ? ' active' : '');
    var btn = document.createElement('button');
    btn.type = 'button';
    btn.className = 'page-link';
    btn.textContent = label;
    btn.addEventListener('click', function(){
        if (disabled) return;
        solCurrentPage = page;
        renderSolicitudesPage();
    });
    li.appendChild(btn);
    return li;
}

function renderSolicitudesPagination(totalPages){
    solPagination.innerHTML = '';
    solPagination.appendChild(pageButton('Anterior', solCurrentPage - 1, solCurrentPage === 1, false));

    var first = Math.max(1, solCurrentPage - 2);
    var last = Math.min(totalPages, solCurrentPage + 2);
    if (first > 1) {
        solPagination.appendChild(pageButton('1', 1, false, solCurrentPage === 1));
        if (first > 2) {
            var dots = document.createElement('li');
            dots.className = 'page-item disabled';
            dots.innerHTML = '<span class="page-link">...</span>';
            solPagination.appendChild(dots);
        }
    }
    for (var p = first; p <= last; p++) {
        solPagination.appendChild(pageButton(String(p), p, false, p === solCurrentPage));
    }
    if (last < totalPages) {
        if (last < totalPages - 1) {
            var dotsEnd = document.createElement('li');
            dotsEnd.className = 'page-item disabled';
            dotsEnd.innerHTML = '<span class="page-link">...</span>';
            solPagination.appendChild(dotsEnd);
        }
        solPagination.appendChild(pageButton(String(totalPages), totalPages, false, solCurrentPage === totalPages));
    }

    solPagination.appendChild(pageButton('Siguiente', solCurrentPage + 1, solCurrentPage === totalPages, false));
}

if (solTable && solPageSize && solPageInfo && solPagination) {
    solPageSize.addEventListener('change', function(){
        solCurrentPage = 1;
        renderSolicitudesPage();
    });
    renderSolicitudesPage();
}

/* ── Editar / Eliminar registros existentes ── */
function accion(tipo,id,btn){
    if(tipo==='eliminar'&&!confirm('¿Eliminar este registro?')) return;
    var tr=btn.closest('tr');
    document.getElementById('f-id').value         =id;
    document.getElementById('f-contratista').value=tr.querySelector('.inp-contratista').value;
    document.getElementById('f-cargo').value      =tr.querySelector('.inp-cargo').value;
    document.getElementById('f-area').value       =tr.querySelector('.inp-area').value;
    document.getElementById('f-turno').value      =tr.querySelector('.inp-turno').value;
    document.getElementById('f-especie').value    =tr.querySelector('.inp-especie').value;
    document.getElementById('f-cantidad').value   =tr.querySelector('.inp-cantidad').value;
    document.getElementById('f-fecha').value      =tr.querySelector('.inp-fecha').value;
    document.getElementById('f-btn-'+tipo).click();
}
</script>

<?php
include __DIR__ . '/../partials/footer.php';
sqlsrv_close($conn);
?>
