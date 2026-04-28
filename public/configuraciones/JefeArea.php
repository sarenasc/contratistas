<?php
require_once __DIR__ . '/../_bootstrap.php';

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

// Cargar turnos
$turnos = [];
$qT = sqlsrv_query($conn, "SELECT id, nombre_turno FROM dbo.dota_turno ORDER BY nombre_turno");
if ($qT) {
    while ($r = sqlsrv_fetch_array($qT, SQLSRV_FETCH_ASSOC)) {
        $turnos[(int)$r['id']] = (string)$r['nombre_turno'];
    }
}

// Cargar usuarios para vincular
$usuarios_cat = [];
$qU = sqlsrv_query($conn, "SELECT id_usuario, usuario, nombre, apellido FROM dbo.dota_usuarios WHERE activo = 1 ORDER BY nombre, apellido");
if ($qU) {
    while ($r = sqlsrv_fetch_array($qU, SQLSRV_FETCH_ASSOC)) {
        $label = trim($r['nombre'] . ' ' . $r['apellido']) ?: $r['usuario'];
        $usuarios_cat[(int)$r['id_usuario']] = $label;
    }
}

if (isset($_POST['guardar'])) {
    $nombre     = trim($_POST['nombre']);
    $rut        = trim($_POST['rut']);
    $id_area    = (int)$_POST['id_area'];
    $id_turno   = (int)$_POST['id_turno'] ?: null;
    $id_usuario = (int)$_POST['id_usuario'] ?: null;
    $activo     = isset($_POST['activo']) ? 1 : 0;

    if ($nombre === '') {
        $flash_error = "El nombre no puede estar vacío.";
    } elseif ($id_area === 0) {
        $flash_error = "Debe seleccionar un área.";
    } else {
        $r = sqlsrv_query($conn,
            "INSERT INTO dbo.dota_jefe_area (nombre, rut, id_area, id_turno, id_usuario, activo) VALUES (?, ?, ?, ?, ?, ?)",
            [$nombre, $rut ?: null, $id_area, $id_turno, $id_usuario, $activo]
        );
        if ($r === false) $flash_error = "Error al guardar: " . print_r(sqlsrv_errors(), true);
        else $flash_ok = "Jefe de área agregado correctamente.";
    }
}

if (isset($_POST['editar'])) {
    $id         = (int)$_POST['id'];
    $nombre     = trim($_POST['nombre']);
    $rut        = trim($_POST['rut']);
    $id_area    = (int)$_POST['id_area'];
    $id_turno   = (int)$_POST['id_turno'] ?: null;
    $id_usuario = (int)$_POST['id_usuario'] ?: null;
    $activo     = (int)$_POST['activo'];

    if ($nombre === '') {
        $flash_error = "El nombre no puede estar vacío.";
    } elseif ($id_area === 0) {
        $flash_error = "Debe seleccionar un área.";
    } else {
        $r = sqlsrv_query($conn,
            "UPDATE dbo.dota_jefe_area SET nombre=?, rut=?, id_area=?, id_turno=?, id_usuario=?, activo=? WHERE id=?",
            [$nombre, $rut ?: null, $id_area, $id_turno, $id_usuario, $activo, $id]
        );
        if ($r === false) $flash_error = "Error al editar: " . print_r(sqlsrv_errors(), true);
        else $flash_ok = "Jefe de área actualizado.";
    }
}

if (isset($_POST['eliminar'])) {
    $id = (int)$_POST['id'];
    $r = sqlsrv_query($conn, "DELETE FROM dbo.dota_jefe_area WHERE id=?", [$id]);
    if ($r === false) $flash_error = "Error al eliminar: " . print_r(sqlsrv_errors(), true);
    else $flash_ok = "Jefe de área eliminado.";
}

// Paginación
$per_page = 20;
$page     = max(1, (int)($_GET['page'] ?? 1));
$offset   = ($page - 1) * $per_page;

$total     = 0;
$stmtCount = sqlsrv_query($conn, "SELECT COUNT(*) FROM dbo.dota_jefe_area j INNER JOIN dbo.Area a ON a.id_area = j.id_area");
if ($stmtCount) { $total = (int)sqlsrv_fetch_array($stmtCount, SQLSRV_FETCH_NUMERIC)[0]; }
$total_pages = max(1, (int)ceil($total / $per_page));
$page        = min($page, $total_pages);

$query = sqlsrv_query($conn,
    "SELECT j.id, j.nombre, j.rut, j.id_area, a.Area, j.id_turno, t.nombre_turno, j.id_usuario, j.activo
     FROM dbo.dota_jefe_area j
     INNER JOIN dbo.Area a ON a.id_area = j.id_area
     LEFT  JOIN dbo.dota_turno t ON t.id = j.id_turno
     ORDER BY a.Area, t.nombre_turno, j.nombre
     OFFSET ? ROWS FETCH NEXT ? ROWS ONLY",
    [$offset, $per_page]
);

$title = "Jefes de Área";
include __DIR__ . '/../partials/header.php';
include __DIR__ . '/../partials/navbar_wrapper.php';
?>

<main class="container py-4">

    <div class="text-center my-4">
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
                    <div class="col-md-2">
                        <label class="form-label">Usuario sistema <small class="text-muted">(opcional)</small></label>
                        <select class="form-control" name="id_usuario">
                            <option value="">-- Sin vincular --</option>
                            <?php foreach ($usuarios_cat as $uid => $unom): ?>
                            <option value="<?= $uid ?>"><?= htmlspecialchars($unom) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-1 d-flex align-items-end">
                        <label class="form-check mb-2" for="chkActivo">
                            <input class="form-check-input" type="checkbox" name="activo" id="chkActivo" checked>
                            <span class="form-check-box"></span>
                            <span class="form-check-label">Activo</span>
                        </label>
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
        <input type="hidden" name="id"          id="f-id">
        <input type="hidden" name="nombre"      id="f-nombre">
        <input type="hidden" name="rut"         id="f-rut">
        <input type="hidden" name="id_area"     id="f-id_area">
        <input type="hidden" name="id_turno"    id="f-id_turno">
        <input type="hidden" name="id_usuario"  id="f-id_usuario">
        <input type="hidden" name="activo"      id="f-activo">
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
                    <th>Usuario sistema</th>
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
                    data-id-usuario="<?= (int)($row['id_usuario'] ?? 0) ?>"
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
                    <td>
                        <select class="form-control inp-usuario">
                            <option value="">-- Sin vincular --</option>
                            <?php foreach ($usuarios_cat as $uid => $unom): ?>
                            <option value="<?= $uid ?>" <?= $uid === (int)($row['id_usuario'] ?? 0) ? 'selected' : '' ?>>
                                <?= htmlspecialchars($unom) ?>
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

    <!-- Paginación -->
    <?php if ($total_pages > 1): ?>
    <nav class="mt-3">
        <ul class="pagination justify-content-center">
            <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>">
                <a class="page-link" href="?page=<?= $page - 1 ?>">«</a>
            </li>
            <?php for ($p = 1; $p <= $total_pages; $p++): ?>
            <li class="page-item <?= $p === $page ? 'active' : '' ?>">
                <a class="page-link" href="?page=<?= $p ?>"><?= $p ?></a>
            </li>
            <?php endfor; ?>
            <li class="page-item <?= $page >= $total_pages ? 'disabled' : '' ?>">
                <a class="page-link" href="?page=<?= $page + 1 ?>">»</a>
            </li>
        </ul>
        <p class="text-center text-muted small">Mostrando <?= $offset + 1 ?>–<?= min($offset + $per_page, $total) ?> de <?= $total ?> registros</p>
    </nav>
    <?php endif; ?>

    <script>
    function accion(tipo, btn) {
        if (tipo === 'eliminar' && !confirm('¿Eliminar este jefe de área?')) return;
        const tr = btn.closest('tr');
        document.getElementById('f-id').value          = tr.dataset.id;
        document.getElementById('f-nombre').value      = tr.querySelector('.inp-nombre').value;
        document.getElementById('f-rut').value         = tr.querySelector('.inp-rut').value;
        document.getElementById('f-id_area').value     = tr.querySelector('.inp-area').value;
        document.getElementById('f-id_turno').value    = tr.querySelector('.inp-turno').value;
        document.getElementById('f-id_usuario').value  = tr.querySelector('.inp-usuario').value;
        document.getElementById('f-activo').value      = tr.querySelector('.inp-activo').checked ? 1 : 0;
        document.getElementById('f-btn-' + tipo).click();
    }
    </script>

</main>

<?php
include __DIR__ . '/../partials/footer.php';
sqlsrv_close($conn);
?>
