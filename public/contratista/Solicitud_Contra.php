<?php
require_once __DIR__ . '/../_bootstrap.php';
require_once __DIR__ . '/../../app/lib/db.php';

$flash_error = null;
$flash_ok    = null;

// Catálogos para selects
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

// Guardar nuevo registro
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
        // Calcular próxima versión
        $stmtV = sqlsrv_query($conn,
            "SELECT ISNULL(MAX(version), 0) AS max_v FROM dbo.dota_solicitud_contratista
             WHERE contratista = ? AND cargo = ? AND area = ?",
            [$contratista, $cargo, $area]);
        $rowV    = sqlsrv_fetch_array($stmtV, SQLSRV_FETCH_ASSOC);
        $version = (int)$rowV['max_v'] + 1;

        $dt  = DateTime::createFromFormat('Y-m-d', $fecha);
        $res = sqlsrv_query($conn,
            "INSERT INTO dbo.dota_solicitud_contratista (contratista, cargo, area, cantidad, version, fecha, id_turno)
             VALUES (?, ?, ?, ?, ?, ?, ?)",
            [$contratista, $cargo, $area, $cantidad, $version, $dt, $id_turno]);
        $flash_ok = $res !== false ? "Solicitud guardada (versión {$version})." : "Error al guardar.";
    }
}

// Editar registro
if (isset($_POST['editar'])) {
    $id          = (int)$_POST['id'];
    $contratista = (int)$_POST['contratista'];
    $cargo       = (int)$_POST['cargo'];
    $area        = (int)$_POST['area'];
    $cantidad    = (int)$_POST['cantidad'];
    $fecha       = trim($_POST['fecha']);
    $id_turno    = (int)($_POST['id_turno'] ?? 0) ?: null;

    $dt  = DateTime::createFromFormat('Y-m-d', $fecha);
    $res = sqlsrv_query($conn,
        "UPDATE dbo.dota_solicitud_contratista
         SET contratista=?, cargo=?, area=?, cantidad=?, fecha=?, id_turno=?
         WHERE id=?",
        [$contratista, $cargo, $area, $cantidad, $dt, $id_turno, $id]);
    $flash_ok = $res !== false ? "Registro actualizado." : "Error al actualizar.";
}

// Eliminar registro
if (isset($_POST['eliminar'])) {
    $id  = (int)$_POST['id'];
    $res = sqlsrv_query($conn,
        "DELETE FROM dbo.dota_solicitud_contratista WHERE id = ?", [$id]);
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
if ($query === false) $flash_error = "Error al consultar: " . htmlspecialchars(sqlsrv_errors()[0]['message'] ?? '');

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

<!-- Formulario agregar -->
<div class="card mb-4">
    <div class="card-header">Nueva Solicitud</div>
    <div class="card-body">
        <form method="POST">
            <div class="row g-3">
                <div class="col-md-4">
                    <label class="form-label">Contratista</label>
                    <select name="contratista" class="form-select" required>
                        <option value="">-- Seleccione --</option>
                        <?php foreach ($contratistas as $c): ?>
                        <option value="<?= (int)$c['id'] ?>"><?= htmlspecialchars($c['nombre']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label">Cargo</label>
                    <select name="cargo" class="form-select" required>
                        <option value="">-- Seleccione --</option>
                        <?php foreach ($cargos as $c): ?>
                        <option value="<?= (int)$c['id_cargo'] ?>"><?= htmlspecialchars($c['cargo']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label">Área</label>
                    <select name="area" class="form-select" required>
                        <option value="">-- Seleccione --</option>
                        <?php foreach ($areas as $a): ?>
                        <option value="<?= (int)$a['id_area'] ?>"><?= htmlspecialchars($a['Area']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label">Turno</label>
                    <select name="id_turno" class="form-select">
                        <option value="">-- Sin turno --</option>
                        <?php foreach ($turnos as $t): ?>
                        <option value="<?= (int)$t['id'] ?>"><?= htmlspecialchars($t['nombre_turno']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label">Cantidad</label>
                    <input type="number" name="cantidad" class="form-control" min="1" required>
                </div>
                <div class="col-md-4">
                    <label class="form-label">Fecha</label>
                    <input type="date" name="fecha" class="form-control" value="<?= date('Y-m-d') ?>" required>
                </div>
            </div>
            <button type="submit" name="guardar" class="btn btn-primary mt-3">Guardar</button>
        </form>
    </div>
</div>

<!-- Form oculto editar / eliminar -->
<form id="form-accion" method="POST">
    <input type="hidden" name="id"           id="f-id">
    <input type="hidden" name="contratista"  id="f-contratista">
    <input type="hidden" name="cargo"        id="f-cargo">
    <input type="hidden" name="area"         id="f-area">
    <input type="hidden" name="id_turno"     id="f-turno">
    <input type="hidden" name="cantidad"     id="f-cantidad">
    <input type="hidden" name="fecha"        id="f-fecha">
    <button type="submit" name="editar"   id="f-btn-editar"   style="display:none"></button>
    <button type="submit" name="eliminar" id="f-btn-eliminar" style="display:none"></button>
</form>

<!-- Tabla -->
<h2 class="text-center mb-3">Registros Existentes</h2>
<div class="table-responsive">
    <table class="table table-bordered table-hover">
        <thead class="table-dark">
            <tr>
                <th>ID</th>
                <th>Contratista</th>
                <th>Cargo</th>
                <th>Área</th>
                <th>Turno</th>
                <th>Cantidad</th>
                <th>Versión</th>
                <th>Fecha</th>
                <th>Acciones</th>
            </tr>
        </thead>
        <tbody>
        <?php while ($query && $row = sqlsrv_fetch_array($query, SQLSRV_FETCH_ASSOC)):
            $fecha = ($row['fecha'] instanceof DateTime) ? $row['fecha']->format('Y-m-d') : (string)$row['fecha'];
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
                <td><input type="number" class="form-control form-control-sm inp-cantidad" value="<?= (int)$row['cantidad'] ?>" min="1"></td>
                <td class="text-center"><?= (int)$row['version'] ?></td>
                <td><input type="date" class="form-control form-control-sm inp-fecha" value="<?= htmlspecialchars($fecha) ?>"></td>
                <td class="text-nowrap">
                    <button class="btn btn-warning btn-sm"
                            onclick="accion('editar', <?= (int)$row['id'] ?>, this)">Editar</button>
                    <button class="btn btn-danger btn-sm"
                            onclick="accion('eliminar', <?= (int)$row['id'] ?>, this)">Eliminar</button>
                </td>
            </tr>
        <?php endwhile; ?>
        </tbody>
    </table>
</div>

<div class="mt-3">
    <a href="Excel_Sol_dota.php" class="btn btn-success">Exportar a Excel</a>
</div>

</main>

<script>
function accion(tipo, id, btn) {
    if (tipo === 'eliminar' && !confirm('¿Eliminar este registro?')) return;
    var tr = btn.closest('tr');
    document.getElementById('f-id').value          = id;
    document.getElementById('f-contratista').value = tr.querySelector('.inp-contratista').value;
    document.getElementById('f-cargo').value        = tr.querySelector('.inp-cargo').value;
    document.getElementById('f-area').value         = tr.querySelector('.inp-area').value;
    document.getElementById('f-turno').value        = tr.querySelector('.inp-turno').value;
    document.getElementById('f-cantidad').value     = tr.querySelector('.inp-cantidad').value;
    document.getElementById('f-fecha').value        = tr.querySelector('.inp-fecha').value;
    document.getElementById('f-btn-' + tipo).click();
}
</script>

<?php
include __DIR__ . '/../partials/footer.php';
sqlsrv_close($conn);
?>
