<?php
require_once __DIR__ . '/../_bootstrap.php';
$username = $_SESSION['nom_usu'];

$flash_error = null;
$flash_ok    = null;

// Cargar áreas
$areas = [];
$qAreas = sqlsrv_query($conn, "SELECT id_area, Area FROM dbo.Area ORDER BY Area");
if ($qAreas) {
    while ($r = sqlsrv_fetch_array($qAreas, SQLSRV_FETCH_ASSOC)) {
        $areas[(int)$r['id_area']] = (string)$r['Area'];
    }
}

// Cargar turnos (opcional — tabla puede no existir)
$turnos = [];
$chkT = sqlsrv_query($conn, "SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA='dbo' AND TABLE_NAME='dota_turno'");
if ($chkT && sqlsrv_fetch($chkT)) {
    $qT = sqlsrv_query($conn, "SELECT id, nombre_turno FROM dbo.dota_turno ORDER BY nombre_turno");
    if ($qT) {
        while ($r = sqlsrv_fetch_array($qT, SQLSRV_FETCH_ASSOC)) {
            $turnos[(int)$r['id']] = (string)$r['nombre_turno'];
        }
    }
}

if (isset($_POST['guardar'])) {
    $nombre   = trim($_POST['nombre']);
    $rut      = trim($_POST['rut']);
    $id_area  = (int)$_POST['id_area'];
    $id_turno = (int)$_POST['id_turno'] ?: null;
    $activo   = isset($_POST['activo']) ? 1 : 0;

    if ($nombre === '') {
        $flash_error = "El nombre no puede estar vacío.";
    } elseif ($id_area === 0) {
        $flash_error = "Debe seleccionar un área.";
    } else {
        $r = sqlsrv_query($conn,
            "INSERT INTO dbo.dota_jefe_area (nombre, rut, id_area, id_turno, activo) VALUES (?, ?, ?, ?, ?)",
            [$nombre, $rut ?: null, $id_area, $id_turno, $activo]
        );
        if ($r === false) $flash_error = "Error al guardar. Intente nuevamente.";
        else $flash_ok = "Jefe de área agregado correctamente.";
    }
}

if (isset($_POST['editar'])) {
    $id       = (int)$_POST['id'];
    $nombre   = trim($_POST['nombre']);
    $rut      = trim($_POST['rut']);
    $id_area  = (int)$_POST['id_area'];
    $id_turno = (int)$_POST['id_turno'] ?: null;
    $activo   = (int)$_POST['activo'];

    $r = sqlsrv_query($conn,
        "UPDATE dbo.dota_jefe_area SET nombre=?, rut=?, id_area=?, id_turno=?, activo=? WHERE id=?",
        [$nombre, $rut ?: null, $id_area, $id_turno, $activo, $id]
    );
    if ($r === false) $flash_error = "Error al editar. Intente nuevamente.";
    else $flash_ok = "Jefe de área actualizado.";
}

if (isset($_POST['eliminar'])) {
    $id = (int)$_POST['id'];
    $r = sqlsrv_query($conn, "DELETE FROM dbo.dota_jefe_area WHERE id=?", [$id]);
    if ($r === false) $flash_error = "Error al eliminar. Intente nuevamente.";
    else $flash_ok = "Jefe de área eliminado.";
}

$query = sqlsrv_query($conn,
    "SELECT j.id, j.nombre, j.rut, j.id_area, a.Area, j.id_turno, t.nombre_turno, j.activo
     FROM dbo.dota_jefe_area j
     INNER JOIN dbo.Area a ON a.id_area = j.id_area
     LEFT  JOIN dbo.dota_turno t ON t.id = j.id_turno
     ORDER BY a.Area, t.nombre_turno, j.nombre"
);

$title = "Jefes de Área";
include __DIR__ . '/../partials/header.php';
include __DIR__ . '/../partials/navbar_wrapper.php';
?>

<main class="container py-4">

    <div class="text-center my-4">
        <h5 class="text-muted">Usuario: <?= htmlspecialchars($username) ?></h5>
        <h1 class="display-4">Gestión de Jefes de Área</h1>
    </div>

    <?php if ($flash_error): ?>
      <div class="alert alert-danger"><?= htmlspecialchars($flash_error) ?></div>
    <?php endif; ?>
    <?php if ($flash_ok): ?>
      <div class="alert alert-success"><?= htmlspecialchars($flash_ok) ?></div>
    <?php endif; ?>

    <!-- Formulario agregar -->
    <div class="card mb-4">
        <div class="card-header">Agregar Nuevo Jefe de Área</div>
        <div class="card-body">
            <form method="POST">
                <?= csrf_field() ?>
                <div class="row g-3 align-items-end">
                    <div class="col-md-3">
                        <label class="form-label">Nombre</label>
                        <input type="text" class="form-control" name="nombre" required>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">RUT</label>
                        <input type="text" class="form-control" name="rut" placeholder="12345678-9">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Área</label>
                        <select class="form-control" name="id_area" required>
                            <option value="">-- Seleccionar --</option>
                            <?php foreach ($areas as $aid => $anom): ?>
                            <option value="<?= $aid ?>"><?= htmlspecialchars($anom) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">Turno <small class="text-muted">(opcional)</small></label>
                        <select class="form-control" name="id_turno">
                            <option value="">-- Sin turno --</option>
                            <?php foreach ($turnos as $tid => $tnom): ?>
                            <option value="<?= $tid ?>"><?= htmlspecialchars($tnom) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-1 d-flex align-items-end">
                        <div class="form-check mb-2">
                            <input class="form-check-input" type="checkbox" name="activo" id="chkActivo" checked>
                            <label class="form-check-label" for="chkActivo">Activo</label>
                        </div>
                    </div>
                    <div class="col-md-1">
                        <button type="submit" name="guardar" class="btn btn-primary w-100">Guardar</button>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <!-- Form oculto editar/eliminar -->
    <form id="form-accion" method="POST">
        <?= csrf_field() ?>
        <input type="hidden" name="id"       id="f-id">
        <input type="hidden" name="nombre"   id="f-nombre">
        <input type="hidden" name="rut"      id="f-rut">
        <input type="hidden" name="id_area"  id="f-id_area">
        <input type="hidden" name="id_turno" id="f-id_turno">
        <input type="hidden" name="activo"   id="f-activo">
        <button type="submit" name="editar"   id="f-btn-editar"   style="display:none"></button>
        <button type="submit" name="eliminar" id="f-btn-eliminar" style="display:none"></button>
    </form>

    <!-- Tabla -->
    <h2 class="text-center">Jefes de Área Registrados</h2>
    <div class="table-responsive mt-3">
        <table class="table table-bordered table-hover">
            <thead class="table-dark">
                <tr>
                    <th>ID</th>
                    <th>Nombre</th>
                    <th>RUT</th>
                    <th>Área</th>
                    <th>Turno</th>
                    <th>Activo</th>
                    <th>Acciones</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($query): while ($row = sqlsrv_fetch_array($query, SQLSRV_FETCH_ASSOC)): ?>
                <tr
                    data-id="<?= (int)$row['id'] ?>"
                    data-nombre="<?= htmlspecialchars($row['nombre']) ?>"
                    data-rut="<?= htmlspecialchars($row['rut'] ?? '') ?>"
                    data-id-area="<?= (int)$row['id_area'] ?>"
                    data-id-turno="<?= (int)($row['id_turno'] ?? 0) ?>"
                    data-activo="<?= (int)$row['activo'] ?>">
                    <td><?= (int)$row['id'] ?></td>
                    <td><input type="text" class="form-control inp-nombre" value="<?= htmlspecialchars($row['nombre']) ?>"></td>
                    <td><input type="text" class="form-control inp-rut" value="<?= htmlspecialchars($row['rut'] ?? '') ?>" placeholder="12345678-9"></td>
                    <td>
                        <select class="form-control inp-area">
                            <?php foreach ($areas as $aid => $anom): ?>
                            <option value="<?= $aid ?>" <?= $aid === (int)$row['id_area'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($anom) ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </td>
                    <td>
                        <select class="form-control inp-turno">
                            <option value="">-- Sin turno --</option>
                            <?php foreach ($turnos as $tid => $tnom): ?>
                            <option value="<?= $tid ?>" <?= $tid === (int)($row['id_turno'] ?? 0) ? 'selected' : '' ?>>
                                <?= htmlspecialchars($tnom) ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </td>
                    <td class="text-center">
                        <input type="checkbox" class="form-check-input inp-activo" <?= $row['activo'] ? 'checked' : '' ?>>
                    </td>
                    <td>
                        <button type="button" class="btn btn-warning btn-sm" onclick="accion('editar', this)">Editar</button>
                        <button type="button" class="btn btn-danger btn-sm"  onclick="accion('eliminar', this)">Eliminar</button>
                    </td>
                </tr>
                <?php endwhile; endif; ?>
            </tbody>
        </table>
    </div>

    <script>
    function accion(tipo, btn) {
        const tr = btn.closest('tr');
        document.getElementById('f-id').value       = tr.dataset.id;
        document.getElementById('f-nombre').value   = tr.querySelector('.inp-nombre').value;
        document.getElementById('f-rut').value      = tr.querySelector('.inp-rut').value;
        document.getElementById('f-id_area').value  = tr.querySelector('.inp-area').value;
        document.getElementById('f-id_turno').value = tr.querySelector('.inp-turno').value;
        document.getElementById('f-activo').value   = tr.querySelector('.inp-activo').checked ? 1 : 0;
        document.getElementById('f-btn-' + tipo).click();
    }
    </script>

</main>

<?php
include __DIR__ . '/../partials/footer.php';
sqlsrv_close($conn);
?>
