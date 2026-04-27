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

// Mapa id_cargo → nombre para usar en paso 2
$cargo_map = [];
foreach ($cargos as $c) $cargo_map[(int)$c['id_cargo']] = $c['cargo'];

/* ── Guardar múltiples cargos (nuevo flujo paso 2) ── */
if (isset($_POST['guardar_multiple'])) {
    $contratista  = (int)$_POST['contratista'];
    $area         = (int)$_POST['area'];
    $id_turno     = (int)($_POST['id_turno'] ?? 0) ?: null;
    $fecha        = trim($_POST['fecha']);
    $ids_cargo    = $_POST['ids_cargo']    ?? [];
    $cantidades   = $_POST['cantidades']   ?? [];

    if (!$contratista || !$area || !$fecha || empty($ids_cargo)) {
        $flash_error = "Faltan datos obligatorios.";
    } else {
        $dt       = DateTime::createFromFormat('Y-m-d', $fecha);
        $guardados = 0;
        $errores   = 0;
        foreach ($ids_cargo as $i => $id_cargo) {
            $id_cargo  = (int)$id_cargo;
            $cantidad  = (int)($cantidades[$i] ?? 0);
            if ($id_cargo <= 0 || $cantidad <= 0) continue;

            $stmtV = sqlsrv_query($conn,
                "SELECT ISNULL(MAX(version),0) AS max_v FROM dbo.dota_solicitud_contratista
                 WHERE contratista=? AND cargo=? AND area=?",
                [$contratista, $id_cargo, $area]);
            $rowV    = $stmtV ? sqlsrv_fetch_array($stmtV, SQLSRV_FETCH_ASSOC) : ['max_v' => 0];
            $version = (int)$rowV['max_v'] + 1;

            $res = sqlsrv_query($conn,
                "INSERT INTO dbo.dota_solicitud_contratista (contratista,cargo,area,cantidad,version,fecha,id_turno)
                 VALUES (?,?,?,?,?,?,?)",
                [$contratista, $id_cargo, $area, $cantidad, $version, $dt, $id_turno]);
            $res !== false ? $guardados++ : $errores++;
        }
        if ($errores === 0)
            $flash_ok = "Se guardaron {$guardados} registro(s) correctamente.";
        else
            $flash_error = "Se guardaron {$guardados} registro(s) con {$errores} error(es).";
    }
}

/* ── Guardar 1 cargo (flujo anterior — mantener compatibilidad) ── */
if (isset($_POST['guardar'])) {
    $contratista = (int)$_POST['contratista'];
    $cargo       = (int)$_POST['cargo'];
    $area        = (int)$_POST['area'];
    $cantidad    = (int)$_POST['cantidad'];
    $fecha       = trim($_POST['fecha']);
    $id_turno    = (int)($_POST['id_turno'] ?? 0) ?: null;

    if (!$contratista || !$cargo || !$area || !$cantidad || !$fecha) {
        $flash_error = "Todos los campos son obligatorios.";
    } else {
        $stmtV = sqlsrv_query($conn,
            "SELECT ISNULL(MAX(version),0) AS max_v FROM dbo.dota_solicitud_contratista
             WHERE contratista=? AND cargo=? AND area=?",
            [$contratista, $cargo, $area]);
        $rowV    = sqlsrv_fetch_array($stmtV, SQLSRV_FETCH_ASSOC);
        $version = (int)$rowV['max_v'] + 1;
        $dt      = DateTime::createFromFormat('Y-m-d', $fecha);
        $res     = sqlsrv_query($conn,
            "INSERT INTO dbo.dota_solicitud_contratista (contratista,cargo,area,cantidad,version,fecha,id_turno)
             VALUES (?,?,?,?,?,?,?)",
            [$contratista, $cargo, $area, $cantidad, $version, $dt, $id_turno]);
        $flash_ok = $res !== false ? "Solicitud guardada (versión {$version})." : "Error al guardar.";
    }
}

/* ── Editar ── */
if (isset($_POST['editar'])) {
    $id          = (int)$_POST['id'];
    $contratista = (int)$_POST['contratista'];
    $cargo       = (int)$_POST['cargo'];
    $area        = (int)$_POST['area'];
    $cantidad    = (int)$_POST['cantidad'];
    $fecha       = trim($_POST['fecha']);
    $id_turno    = (int)($_POST['id_turno'] ?? 0) ?: null;
    $dt          = DateTime::createFromFormat('Y-m-d', $fecha);
    $res         = sqlsrv_query($conn,
        "UPDATE dbo.dota_solicitud_contratista
         SET contratista=?,cargo=?,area=?,cantidad=?,fecha=?,id_turno=? WHERE id=?",
        [$contratista, $cargo, $area, $cantidad, $dt, $id_turno, $id]);
    $flash_ok = $res !== false ? "Registro actualizado." : "Error al actualizar.";
}

/* ── Eliminar ── */
if (isset($_POST['eliminar'])) {
    $id  = (int)$_POST['id'];
    $res = sqlsrv_query($conn, "DELETE FROM dbo.dota_solicitud_contratista WHERE id=?", [$id]);
    $flash_ok = $res !== false ? "Registro eliminado." : "Error al eliminar.";
}

// Consultar registros
$query = sqlsrv_query($conn,
    "SELECT S.id, S.cantidad, S.version, S.fecha,
            S.contratista, S.cargo AS id_cargo, S.area AS id_area, S.id_turno,
            C.nombre AS contratista_nombre, CA.cargo, A.Area, T.nombre_turno
     FROM dbo.dota_solicitud_contratista S
     LEFT JOIN dbo.dota_contratista C  ON C.id       = S.contratista
     LEFT JOIN dbo.Dota_Cargo       CA ON CA.id_cargo = S.cargo
     LEFT JOIN dbo.Area             A  ON A.id_area   = S.area
     LEFT JOIN dbo.dota_turno       T  ON T.id        = S.id_turno
     ORDER BY S.id DESC");
if ($query === false) $flash_error = "Error al consultar.";

$title = "Solicitudes de Contratistas";
include __DIR__ . '/../partials/header.php';
include __DIR__ . '/../partials/navbar_wrapper.php';
?>

<main class="container py-4">

<?php if ($flash_error): ?>
  <div class="alert alert-danger"><?= htmlspecialchars($flash_error) ?></div>
<?php endif; ?>
<?php if ($flash_ok): ?>
  <div class="alert alert-success"><?= htmlspecialchars($flash_ok) ?></div>
<?php endif; ?>

<h1 class="text-center mb-4">Gestión de Solicitudes de Contratistas</h1>

<!-- ══════════ FORMULARIO NUEVO (2 pasos) ══════════ -->
<div class="card mb-4 shadow-sm">
  <div class="card-header fw-semibold">Nueva Solicitud</div>
  <div class="card-body">

    <!-- PASO 1 -->
    <div id="paso1">
      <div class="row g-3">
        <div class="col-md-4">
          <label class="form-label fw-semibold">Contratista <span class="text-danger">*</span></label>
          <select id="p1-contratista" class="form-select" required>
            <option value="">-- Seleccione --</option>
            <?php foreach ($contratistas as $c): ?>
            <option value="<?= (int)$c['id'] ?>"><?= htmlspecialchars($c['nombre']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-md-4">
          <label class="form-label fw-semibold">Área <span class="text-danger">*</span></label>
          <select id="p1-area" class="form-select" required>
            <option value="">-- Seleccione --</option>
            <?php foreach ($areas as $a): ?>
            <option value="<?= (int)$a['id_area'] ?>"><?= htmlspecialchars($a['Area']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-md-4">
          <label class="form-label fw-semibold">Turno</label>
          <select id="p1-turno" class="form-select">
            <option value="">-- Sin turno --</option>
            <?php foreach ($turnos as $t): ?>
            <option value="<?= (int)$t['id'] ?>"><?= htmlspecialchars($t['nombre_turno']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-md-4">
          <label class="form-label fw-semibold">Fecha <span class="text-danger">*</span></label>
          <input type="date" id="p1-fecha" class="form-control" value="<?= date('Y-m-d') ?>" required>
        </div>
        <div class="col-12">
          <label class="form-label fw-semibold">Cargos <span class="text-danger">*</span></label>
          <!-- Buscador de cargos -->
          <div class="position-relative" style="max-width:480px">
            <input type="text" id="cargo-search" class="form-control" placeholder="Buscar cargo..." autocomplete="off">
            <ul id="cargo-dropdown" class="list-group position-absolute w-100 shadow-sm"
                style="display:none; z-index:1000; max-height:220px; overflow-y:auto; top:100%"></ul>
          </div>
          <!-- Tags de cargos seleccionados -->
          <div id="cargo-tags" class="d-flex flex-wrap gap-2 mt-2"></div>
          <div class="text-muted small mt-1" id="p1-sel-count"></div>
        </div>
      </div>
      <div class="mt-3">
        <button type="button" class="btn btn-primary" onclick="continuar()">Continuar →</button>
      </div>
    </div>

    <!-- PASO 2 -->
    <div id="paso2" style="display:none">
      <div class="alert alert-info py-2 mb-3" id="paso2-resumen"></div>
      <form method="POST" id="form-multiple">
        <input type="hidden" name="guardar_multiple" value="1">
        <input type="hidden" name="contratista" id="p2-contratista">
        <input type="hidden" name="area"         id="p2-area">
        <input type="hidden" name="id_turno"     id="p2-turno">
        <input type="hidden" name="fecha"        id="p2-fecha">

        <table class="table table-bordered table-sm align-middle">
          <thead class="table-secondary">
            <tr>
              <th>Cargo</th>
              <th style="width:160px">Cantidad <span class="text-danger">*</span></th>
            </tr>
          </thead>
          <tbody id="paso2-body"></tbody>
        </table>

        <div class="d-flex gap-2 mt-2">
          <button type="button" class="btn btn-outline-secondary" onclick="volver()">← Volver</button>
          <button type="submit" class="btn btn-success">Guardar Todo</button>
        </div>
      </form>
    </div>

  </div>
</div>

<!-- Tabla registros existentes -->
<h2 class="h5 mb-3">Registros Existentes</h2>
<div class="table-responsive">
  <table class="table table-bordered table-hover table-sm">
    <thead class="table-dark">
      <tr>
        <th>ID</th><th>Contratista</th><th>Cargo</th><th>Área</th>
        <th>Turno</th><th>Cantidad</th><th>Versión</th><th>Fecha</th><th>Acciones</th>
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
            <option value="<?= (int)$c['id'] ?>" <?= (int)$c['id'] === (int)$row['contratista'] ? 'selected' : '' ?>>
              <?= htmlspecialchars($c['nombre']) ?>
            </option>
            <?php endforeach; ?>
          </select>
        </td>
        <td>
          <select class="form-select form-select-sm inp-cargo">
            <?php foreach ($cargos as $c): ?>
            <option value="<?= (int)$c['id_cargo'] ?>" <?= (int)$c['id_cargo'] === (int)$row['id_cargo'] ? 'selected' : '' ?>>
              <?= htmlspecialchars($c['cargo']) ?>
            </option>
            <?php endforeach; ?>
          </select>
        </td>
        <td>
          <select class="form-select form-select-sm inp-area">
            <?php foreach ($areas as $a): ?>
            <option value="<?= (int)$a['id_area'] ?>" <?= (int)$a['id_area'] === (int)$row['id_area'] ? 'selected' : '' ?>>
              <?= htmlspecialchars($a['Area']) ?>
            </option>
            <?php endforeach; ?>
          </select>
        </td>
        <td>
          <select class="form-select form-select-sm inp-turno">
            <option value="">-- Sin turno --</option>
            <?php foreach ($turnos as $t): ?>
            <option value="<?= (int)$t['id'] ?>" <?= (int)$t['id'] === (int)($row['id_turno'] ?? 0) ? 'selected' : '' ?>>
              <?= htmlspecialchars($t['nombre_turno']) ?>
            </option>
            <?php endforeach; ?>
          </select>
        </td>
        <td><input type="number" class="form-control form-control-sm inp-cantidad" value="<?= (int)$row['cantidad'] ?>" min="1" style="width:80px"></td>
        <td class="text-center"><?= (int)$row['version'] ?></td>
        <td><input type="date" class="form-control form-control-sm inp-fecha" value="<?= htmlspecialchars($fecha_val) ?>"></td>
        <td class="text-nowrap">
          <button class="btn btn-warning btn-sm" onclick="accion('editar',<?= (int)$row['id'] ?>,this)">Editar</button>
          <button class="btn btn-danger btn-sm"  onclick="accion('eliminar',<?= (int)$row['id'] ?>,this)">Eliminar</button>
        </td>
      </tr>
    <?php endwhile; ?>
    </tbody>
  </table>
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
  <input type="hidden" name="cantidad"    id="f-cantidad">
  <input type="hidden" name="fecha"       id="f-fecha">
  <button type="submit" name="editar"   id="f-btn-editar"   style="display:none"></button>
  <button type="submit" name="eliminar" id="f-btn-eliminar" style="display:none"></button>
</form>

</main>

<script>
/* ── Nombre de contratistas/áreas/turnos para el resumen ── */
var nomContratista = {};
var nomArea        = {};
var nomTurno       = {};
<?php foreach ($contratistas as $c): ?>
nomContratista[<?= (int)$c['id'] ?>] = <?= json_encode($c['nombre']) ?>;
<?php endforeach; ?>
<?php foreach ($areas as $a): ?>
nomArea[<?= (int)$a['id_area'] ?>] = <?= json_encode($a['Area']) ?>;
<?php endforeach; ?>
<?php foreach ($turnos as $t): ?>
nomTurno[<?= (int)$t['id'] ?>] = <?= json_encode($t['nombre_turno']) ?>;
<?php endforeach; ?>

/* ── Datos de cargos para el buscador ── */
var todosLosCargos = <?= json_encode(array_values(array_map(fn($c) => ['id' => (int)$c['id_cargo'], 'nombre' => $c['cargo']], $cargos)), JSON_UNESCAPED_UNICODE) ?>;
var cargosSeleccionados = {}; // id → nombre

var searchInput  = document.getElementById('cargo-search');
var dropdown     = document.getElementById('cargo-dropdown');
var tagsDiv      = document.getElementById('cargo-tags');

searchInput.addEventListener('input', function() {
    var q = this.value.trim().toLowerCase();
    dropdown.innerHTML = '';
    if (q.length < 1) { dropdown.style.display = 'none'; return; }

    var resultados = todosLosCargos.filter(function(c) {
        return c.nombre.toLowerCase().includes(q) && !cargosSeleccionados[c.id];
    }).slice(0, 15);

    if (resultados.length === 0) { dropdown.style.display = 'none'; return; }

    resultados.forEach(function(c) {
        var li = document.createElement('li');
        li.className = 'list-group-item list-group-item-action py-1 px-2';
        li.style.cursor = 'pointer';
        li.textContent = c.nombre;
        li.addEventListener('mousedown', function(e) {
            e.preventDefault();
            agregarCargo(c.id, c.nombre);
        });
        dropdown.appendChild(li);
    });
    dropdown.style.display = '';
});

searchInput.addEventListener('blur', function() {
    setTimeout(function() { dropdown.style.display = 'none'; }, 150);
});

searchInput.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') { dropdown.style.display = 'none'; this.value = ''; }
});

function agregarCargo(id, nombre) {
    if (cargosSeleccionados[id]) return;
    cargosSeleccionados[id] = nombre;

    var tag = document.createElement('span');
    tag.className = 'badge bg-primary d-flex align-items-center gap-1 fs-6 fw-normal px-2 py-1';
    tag.style.fontSize = '0.85rem';
    tag.innerHTML = nombre + ' <button type="button" class="btn-close btn-close-white" style="font-size:0.6rem" aria-label="Quitar"></button>';
    tag.querySelector('button').addEventListener('click', function() {
        delete cargosSeleccionados[id];
        tag.remove();
        actualizarContador();
    });
    tagsDiv.appendChild(tag);
    searchInput.value = '';
    dropdown.style.display = 'none';
    actualizarContador();
    searchInput.focus();
}

function actualizarContador() {
    var n = Object.keys(cargosSeleccionados).length;
    document.getElementById('p1-sel-count').textContent = n > 0 ? n + ' cargo(s) seleccionado(s)' : '';
}

function continuar() {
    var cont  = document.getElementById('p1-contratista');
    var area  = document.getElementById('p1-area');
    var fecha = document.getElementById('p1-fecha');
    var ids   = Object.keys(cargosSeleccionados);

    if (!cont.value)    { cont.classList.add('is-invalid');  alert('Seleccione un contratista.'); return; }
    if (!area.value)    { area.classList.add('is-invalid');  alert('Seleccione un área.'); return; }
    if (!fecha.value)   { fecha.classList.add('is-invalid'); alert('Ingrese una fecha.'); return; }
    if (ids.length === 0) { alert('Agregue al menos un cargo.'); searchInput.focus(); return; }

    [cont, area, fecha].forEach(function(el) { el.classList.remove('is-invalid'); });

    document.getElementById('p2-contratista').value = cont.value;
    document.getElementById('p2-area').value        = area.value;
    document.getElementById('p2-turno').value       = document.getElementById('p1-turno').value;
    document.getElementById('p2-fecha').value       = fecha.value;

    var turnoVal = document.getElementById('p1-turno').value;
    var resumen  = '<strong>Contratista:</strong> ' + (nomContratista[cont.value] || cont.value)
                 + ' &nbsp;|&nbsp; <strong>Área:</strong> ' + (nomArea[area.value] || area.value)
                 + ' &nbsp;|&nbsp; <strong>Turno:</strong> ' + (turnoVal ? nomTurno[turnoVal] || turnoVal : 'Sin turno')
                 + ' &nbsp;|&nbsp; <strong>Fecha:</strong> ' + fecha.value;
    document.getElementById('paso2-resumen').innerHTML = resumen;

    var tbody = document.getElementById('paso2-body');
    tbody.innerHTML = '';
    ids.forEach(function(id) {
        var tr = document.createElement('tr');
        tr.innerHTML =
            '<td>' + cargosSeleccionados[id] + '</td>' +
            '<td>' +
              '<input type="hidden" name="ids_cargo[]" value="' + id + '">' +
              '<input type="number" name="cantidades[]" class="form-control form-control-sm" ' +
                     'min="1" placeholder="Cantidad" required style="width:130px">' +
            '</td>';
        tbody.appendChild(tr);
    });

    var primero = tbody.querySelector('input[type=number]');
    if (primero) primero.focus();

    document.getElementById('paso1').style.display = 'none';
    document.getElementById('paso2').style.display = '';
}

function volver() {
    document.getElementById('paso1').style.display = '';
    document.getElementById('paso2').style.display = 'none';
}

/* ── Editar / Eliminar filas existentes ── */
function accion(tipo, id, btn) {
    if (tipo === 'eliminar' && !confirm('¿Eliminar este registro?')) return;
    var tr = btn.closest('tr');
    document.getElementById('f-id').value          = id;
    document.getElementById('f-contratista').value = tr.querySelector('.inp-contratista').value;
    document.getElementById('f-cargo').value       = tr.querySelector('.inp-cargo').value;
    document.getElementById('f-area').value        = tr.querySelector('.inp-area').value;
    document.getElementById('f-turno').value       = tr.querySelector('.inp-turno').value;
    document.getElementById('f-cantidad').value    = tr.querySelector('.inp-cantidad').value;
    document.getElementById('f-fecha').value       = tr.querySelector('.inp-fecha').value;
    document.getElementById('f-btn-' + tipo).click();
}
</script>

<?php
include __DIR__ . '/../partials/footer.php';
sqlsrv_close($conn);
?>
