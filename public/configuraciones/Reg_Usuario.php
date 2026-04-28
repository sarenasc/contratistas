<?php
require_once __DIR__ . '/../_bootstrap.php';

// Solo administradores
if (!es_admin()) {
    header('Location: ' . BASE_URL . '/Inicio.php'); exit;
}

$flash_error = null;
$flash_ok    = null;

// Catálogos
$perfiles = [];
$qP = sqlsrv_query($conn, "SELECT id_perfil, nombre FROM dbo.dota_perfiles ORDER BY id_perfil");
if ($qP) while ($r = sqlsrv_fetch_array($qP, SQLSRV_FETCH_ASSOC)) $perfiles[(int)$r['id_perfil']] = $r['nombre'];

$areas_cat = [];
$qA = sqlsrv_query($conn, "SELECT id_area, Area FROM dbo.Area ORDER BY Area");
if ($qA) while ($r = sqlsrv_fetch_array($qA, SQLSRV_FETCH_ASSOC)) $areas_cat[(int)$r['id_area']] = $r['Area'];

$cargos_cat = [];
$qC = sqlsrv_query($conn, "SELECT id_cargo, cargo FROM dbo.Dota_Cargo ORDER BY cargo");
if ($qC) while ($r = sqlsrv_fetch_array($qC, SQLSRV_FETCH_ASSOC)) $cargos_cat[(int)$r['id_cargo']] = $r['cargo'];

$turnos_cat = [];
$qT = sqlsrv_query($conn, "SELECT id, nombre_turno FROM dbo.dota_turno ORDER BY nombre_turno");
if ($qT) while ($r = sqlsrv_fetch_array($qT, SQLSRV_FETCH_ASSOC)) $turnos_cat[(int)$r['id']] = $r['nombre_turno'];

$modulos_lista  = ['configuraciones', 'tarifas', 'procesos', 'reloj', 'contratista', 'aprobacion', 'gestion_estados'];
$modulos_labels = [
    'configuraciones' => 'Configuraciones',
    'tarifas'         => 'Tarifas',
    'procesos'        => 'Procesos',
    'reloj'           => 'Reloj',
    'contratista'     => 'Contratistas',
    'aprobacion'      => 'Aprobación',
    'gestion_estados' => 'Gestión de Estados',
];

// ── GUARDAR (nuevo usuario) ──────────────────────────────────────────────────
if (isset($_POST['guardar'])) {
    $usuario   = trim($_POST['usuario'] ?? '');
    $pass      = $_POST['pass_usu'] ?? '';
    $nombre    = trim($_POST['nombre'] ?? '');
    $apellido  = trim($_POST['apellido'] ?? '');
    $email     = trim($_POST['email'] ?? '');
    $id_perfil = (int)($_POST['id_perfil'] ?? 0);
    $id_area   = (int)($_POST['id_area'] ?? 0) ?: null;
    $activo    = isset($_POST['activo']) ? 1 : 0;

    if ($usuario === '' || $pass === '' || $id_perfil === 0) {
        $flash_error = "Usuario, contraseña y perfil son obligatorios.";
    } else {
        $hash = password_hash($pass, PASSWORD_DEFAULT);
        try {
            $stmtI = sqlsrv_query($conn,
                "INSERT INTO dbo.dota_usuarios (usuario, password_hash, nombre, apellido, email, id_area, id_perfil, activo)
                 OUTPUT INSERTED.id_usuario
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)",
                [$usuario, $hash, $nombre ?: null, $apellido ?: null, $email ?: null, $id_area, $id_perfil, $activo]
            );
            if ($stmtI === false) throw new RuntimeException(print_r(sqlsrv_errors(), true));

            $newRow   = sqlsrv_fetch_array($stmtI, SQLSRV_FETCH_ASSOC);
            $nuevo_id = (int)$newRow['id_usuario'];

            // Módulos
            foreach ($modulos_lista as $mod) {
                if (!empty($_POST['mod_' . $mod])) {
                    sqlsrv_query($conn,
                        "INSERT INTO dbo.dota_usuario_modulos (id_usuario, modulo) VALUES (?, ?)",
                        [$nuevo_id, $mod]
                    );
                }
            }

            // Áreas de aprobación
            foreach ((array)($_POST['areas_aprobar'] ?? []) as $aid) {
                $aid = (int)$aid;
                if ($aid > 0) sqlsrv_query($conn,
                    "INSERT INTO dbo.dota_usuario_areas (id_usuario, id_area) VALUES (?, ?)",
                    [$nuevo_id, $aid]
                );
            }

            // Cargos específicos
            foreach ((array)($_POST['cargos_aprobar'] ?? []) as $cid) {
                $cid = (int)$cid;
                if ($cid > 0) sqlsrv_query($conn,
                    "INSERT INTO dbo.dota_usuario_cargos (id_usuario, id_cargo) VALUES (?, ?)",
                    [$nuevo_id, $cid]
                );
            }

            // Nivel de aprobación → dota_jefe_area
            $nivel_aprobacion = (int)($_POST['nivel_aprobacion'] ?? 0);
            if ($nivel_aprobacion > 0) {
                $ja_area  = ($nivel_aprobacion === 1) ? ((int)($_POST['ja_id_area'] ?? 0) ?: null) : null;
                $ja_turno = ($nivel_aprobacion === 1) ? ((int)($_POST['ja_id_turno'] ?? 0) ?: null) : null;
                $ja_nombre = trim($nombre . ' ' . $apellido) ?: $usuario;
                $resJA = sqlsrv_query($conn,
                    "INSERT INTO dbo.dota_jefe_area (nombre, id_area, id_turno, id_usuario, nivel_aprobacion, activo)
                     VALUES (?, ?, ?, ?, ?, 1)",
                    [$ja_nombre, $ja_area, $ja_turno, $nuevo_id, $nivel_aprobacion]
                );
                if ($resJA === false) {
                    $errs = sqlsrv_errors();
                    $flash_error = "Usuario creado pero error en nivel de aprobacion: " . ($errs[0]['message'] ?? 'desconocido');
                }
            }

            if (!$flash_error) $flash_ok = "Usuario '$usuario' creado correctamente.";
        } catch (Exception $e) {
            $flash_error = "Error al guardar: " . $e->getMessage();
        }
    }
}

// ── EDITAR ───────────────────────────────────────────────────────────────────
if (isset($_POST['editar'])) {
    $id        = (int)$_POST['id'];
    $usuario   = trim($_POST['usuario'] ?? '');
    $nombre    = trim($_POST['nombre'] ?? '');
    $apellido  = trim($_POST['apellido'] ?? '');
    $email     = trim($_POST['email'] ?? '');
    $id_perfil = (int)($_POST['id_perfil'] ?? 0);
    $id_area   = (int)($_POST['id_area'] ?? 0) ?: null;
    $activo    = (int)($_POST['activo'] ?? 0);

    if ($usuario === '' || $id_perfil === 0) {
        $flash_error = "Usuario y perfil son obligatorios.";
    } else {
        // Actualizar datos básicos
        if (!empty($_POST['pass_usu'])) {
            $hash = password_hash($_POST['pass_usu'], PASSWORD_DEFAULT);
            sqlsrv_query($conn,
                "UPDATE dbo.dota_usuarios SET usuario=?, password_hash=?, nombre=?, apellido=?, email=?, id_area=?, id_perfil=?, activo=? WHERE id_usuario=?",
                [$usuario, $hash, $nombre ?: null, $apellido ?: null, $email ?: null, $id_area, $id_perfil, $activo, $id]
            );
        } else {
            sqlsrv_query($conn,
                "UPDATE dbo.dota_usuarios SET usuario=?, nombre=?, apellido=?, email=?, id_area=?, id_perfil=?, activo=? WHERE id_usuario=?",
                [$usuario, $nombre ?: null, $apellido ?: null, $email ?: null, $id_area, $id_perfil, $activo, $id]
            );
        }

        // Reemplazar módulos
        sqlsrv_query($conn, "DELETE FROM dbo.dota_usuario_modulos WHERE id_usuario = ?", [$id]);
        foreach ($modulos_lista as $mod) {
            if (!empty($_POST['mod_' . $mod])) {
                sqlsrv_query($conn,
                    "INSERT INTO dbo.dota_usuario_modulos (id_usuario, modulo) VALUES (?, ?)",
                    [$id, $mod]
                );
            }
        }

        // Reemplazar áreas
        sqlsrv_query($conn, "DELETE FROM dbo.dota_usuario_areas WHERE id_usuario = ?", [$id]);
        foreach ((array)($_POST['areas_aprobar'] ?? []) as $aid) {
            $aid = (int)$aid;
            if ($aid > 0) sqlsrv_query($conn,
                "INSERT INTO dbo.dota_usuario_areas (id_usuario, id_area) VALUES (?, ?)",
                [$id, $aid]
            );
        }

        // Reemplazar cargos
        sqlsrv_query($conn, "DELETE FROM dbo.dota_usuario_cargos WHERE id_usuario = ?", [$id]);
        foreach ((array)($_POST['cargos_aprobar'] ?? []) as $cid) {
            $cid = (int)$cid;
            if ($cid > 0) sqlsrv_query($conn,
                "INSERT INTO dbo.dota_usuario_cargos (id_usuario, id_cargo) VALUES (?, ?)",
                [$id, $cid]
            );
        }

        // Nivel de aprobación → dota_jefe_area
        $nivel_aprobacion = (int)($_POST['nivel_aprobacion'] ?? 0);
        sqlsrv_query($conn, "DELETE FROM dbo.dota_jefe_area WHERE id_usuario = ?", [$id]);
        if ($nivel_aprobacion > 0) {
            // Para nivel 1 se usa el área seleccionada; para nivel 2/3 no se requiere área
            $ja_area  = ($nivel_aprobacion === 1) ? ((int)($_POST['ja_id_area'] ?? 0) ?: null) : null;
            $ja_turno = ($nivel_aprobacion === 1) ? ((int)($_POST['ja_id_turno'] ?? 0) ?: null) : null;
            $ja_nombre = trim($nombre . ' ' . $apellido) ?: $usuario;
            $resJA = sqlsrv_query($conn,
                "INSERT INTO dbo.dota_jefe_area (nombre, id_area, id_turno, id_usuario, nivel_aprobacion, activo)
                 VALUES (?, ?, ?, ?, ?, 1)",
                [$ja_nombre, $ja_area, $ja_turno, $id, $nivel_aprobacion]
            );
            if ($resJA === false) {
                $errs = sqlsrv_errors();
                $flash_error = "Error al guardar nivel de aprobacion: " . ($errs[0]['message'] ?? 'desconocido');
            }
        }

        if (!$flash_error) $flash_ok = "Usuario actualizado correctamente.";
    }
}

// ── TOGGLE ACTIVO ────────────────────────────────────────────────────────────
if (isset($_POST['toggle_activo'])) {
    $id     = (int)$_POST['id'];
    $activo = (int)$_POST['activo'];
    sqlsrv_query($conn, "UPDATE dbo.dota_usuarios SET activo = ? WHERE id_usuario = ?", [$activo, $id]);
    header("Location: Reg_Usuario.php"); exit;
}

// ── LISTAR ───────────────────────────────────────────────────────────────────
$usuarios = [];
$qU = sqlsrv_query($conn,
    "SELECT u.id_usuario, u.usuario, u.nombre, u.apellido, u.email, u.id_perfil, u.id_area, u.activo,
            p.nombre AS perfil_nombre, a.Area AS area_nombre
     FROM dbo.dota_usuarios u
     LEFT JOIN dbo.dota_perfiles p ON p.id_perfil = u.id_perfil
     LEFT JOIN dbo.Area          a ON a.id_area   = u.id_area
     ORDER BY u.nombre, u.apellido"
);
if ($qU) while ($r = sqlsrv_fetch_array($qU, SQLSRV_FETCH_ASSOC)) {
    $uid = (int)$r['id_usuario'];

    // Módulos del usuario
    $mods = [];
    $qM = sqlsrv_query($conn, "SELECT modulo FROM dbo.dota_usuario_modulos WHERE id_usuario = ?", [$uid]);
    if ($qM) while ($m = sqlsrv_fetch_array($qM, SQLSRV_FETCH_ASSOC)) $mods[] = $m['modulo'];

    // Áreas de aprobación
    $ars = [];
    $qAr = sqlsrv_query($conn, "SELECT id_area FROM dbo.dota_usuario_areas WHERE id_usuario = ?", [$uid]);
    if ($qAr) while ($ar = sqlsrv_fetch_array($qAr, SQLSRV_FETCH_ASSOC)) $ars[] = (int)$ar['id_area'];

    // Cargos específicos
    $cgs = [];
    $qCg = sqlsrv_query($conn, "SELECT id_cargo FROM dbo.dota_usuario_cargos WHERE id_usuario = ?", [$uid]);
    if ($qCg) while ($cg = sqlsrv_fetch_array($qCg, SQLSRV_FETCH_ASSOC)) $cgs[] = (int)$cg['id_cargo'];

    // Nivel de aprobación
    $qNiv = sqlsrv_query($conn,
        "SELECT TOP 1 nivel_aprobacion, ISNULL(id_area,0) AS id_area, ISNULL(id_turno,0) AS id_turno
         FROM dbo.dota_jefe_area WHERE id_usuario = ? AND activo = 1
         ORDER BY nivel_aprobacion DESC", [$uid]);
    $nivRow = $qNiv ? sqlsrv_fetch_array($qNiv, SQLSRV_FETCH_ASSOC) : null;
    $r['nivel_aprobacion'] = (int)($nivRow['nivel_aprobacion'] ?? 0);
    $r['ja_id_area']       = (int)($nivRow['id_area']          ?? 0);
    $r['ja_id_turno']      = (int)($nivRow['id_turno']         ?? 0);

    $r['modulos']       = $mods;
    $r['areas_aprobar'] = $ars;
    $r['cargos_aprobar']= $cgs;
    $usuarios[]         = $r;
}

$title = "Usuarios";
include __DIR__ . '/../partials/header.php';
include __DIR__ . '/../partials/navbar_wrapper.php';
?>

<main class="container py-4">

    <div class="text-center my-4">
        <h1 class="display-5">Gestión de Usuarios</h1>
    </div>

    <?php if ($flash_error): ?>
        <div class="alert alert-danger"><?= htmlspecialchars($flash_error) ?></div>
    <?php endif; ?>
    <?php if ($flash_ok): ?>
        <div class="alert alert-success"><?= htmlspecialchars($flash_ok) ?></div>
    <?php endif; ?>

    <!-- ── Formulario agregar ─────────────────────────────────────────────── -->
    <div class="card mb-4">
        <div class="card-header fw-bold">Agregar Nuevo Usuario</div>
        <div class="card-body">
            <form method="POST">
                <!-- Datos básicos -->
                <div class="row g-3 mb-3">
                    <div class="col-md-2">
                        <label class="form-label">Usuario <span class="text-danger">*</span></label>
                        <input type="text" name="usuario" class="form-control" required>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">Contraseña <span class="text-danger">*</span></label>
                        <input type="password" name="pass_usu" class="form-control" required>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">Nombre</label>
                        <input type="text" name="nombre" class="form-control">
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">Apellido</label>
                        <input type="text" name="apellido" class="form-control">
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">Email</label>
                        <input type="email" name="email" class="form-control">
                    </div>
                    <div class="col-md-1">
                        <label class="form-label">Perfil <span class="text-danger">*</span></label>
                        <select name="id_perfil" class="form-control" required>
                            <option value="">--</option>
                            <?php foreach ($perfiles as $pid => $pnom): ?>
                                <option value="<?= $pid ?>"><?= htmlspecialchars($pnom) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-1">
                        <label class="form-label">Área propia</label>
                        <select name="id_area" class="form-control">
                            <option value="">--</option>
                            <?php foreach ($areas_cat as $aid => $anom): ?>
                                <option value="<?= $aid ?>"><?= htmlspecialchars($anom) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <!-- Módulos -->
                <div class="mb-3">
                    <label class="form-label fw-bold">Módulos con acceso</label>
                    <div class="d-flex flex-wrap gap-3">
                        <?php foreach ($modulos_lista as $mod): ?>
                        <label class="form-check" for="new_mod_<?= $mod ?>">
                            <input class="form-check-input" type="checkbox" name="mod_<?= $mod ?>" id="new_mod_<?= $mod ?>">
                            <span class="form-check-box"></span>
                            <span class="form-check-label"><?= $modulos_labels[$mod] ?? ucfirst($mod) ?></span>
                        </label>
                        <?php endforeach; ?>
                    </div>
                </div>

                <!-- Áreas de aprobación -->
                <div class="row g-3 mb-3">
                    <div class="col-md-6">
                        <label class="form-label fw-bold">Áreas que puede aprobar</label>
                        <select name="areas_aprobar[]" data-multiselect data-placeholder="Seleccionar áreas..." data-search="Buscar área..." multiple>
                            <?php foreach ($areas_cat as $aid => $anom): ?>
                                <option value="<?= $aid ?>"><?= htmlspecialchars($anom) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label fw-bold">Cargos específicos adicionales</label>
                        <select name="cargos_aprobar[]" data-multiselect data-placeholder="Seleccionar cargos..." data-search="Buscar cargo..." multiple>
                            <?php foreach ($cargos_cat as $cid => $cnom): ?>
                                <option value="<?= $cid ?>"><?= htmlspecialchars($cnom) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <!-- Nivel de aprobación -->
                <div class="row g-3 mb-3">
                    <div class="col-md-8">
                        <label class="form-label fw-bold">Nivel de aprobacion</label>
                        <div class="row g-2 align-items-end">
                            <div class="col-md-3">
                                <label class="form-label small text-muted">Nivel</label>
                                <select name="nivel_aprobacion" id="new-nivel" class="form-select" onchange="toggleAreaNivel(this,'new')">
                                    <option value="0">0 — Usuario normal</option>
                                    <option value="1">1 — Jefe de area</option>
                                    <option value="2">2 — Jefe de operaciones</option>
                                    <option value="3">3 — Gerencia</option>
                                </select>
                            </div>
                            <div class="col-md-4 new-area-turno" style="display:none;">
                                <label class="form-label small text-muted">Area que aprueba</label>
                                <select name="ja_id_area" class="form-select">
                                    <option value="">-- Sin area especifica --</option>
                                    <?php foreach ($areas_cat as $aid => $anom): ?>
                                        <option value="<?= $aid ?>"><?= htmlspecialchars($anom) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-3 new-area-turno" style="display:none;">
                                <label class="form-label small text-muted">Turno <span class="text-muted">(opcional)</span></label>
                                <select name="ja_id_turno" class="form-select">
                                    <option value="">-- Todos los turnos --</option>
                                    <?php foreach ($turnos_cat as $tid => $tnom): ?>
                                        <option value="<?= $tid ?>"><?= htmlspecialchars($tnom) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="card border-info h-100">
                            <div class="card-body py-2 px-3 small">
                                <div class="fw-bold text-info mb-1">Referencia de niveles</div>
                                <div><span class="badge bg-secondary me-1">0</span> Usuario normal — solo accede a modulos asignados</div>
                                <div><span class="badge bg-primary me-1">1</span> Jefe de area — aprueba asistencia de su area/turno</div>
                                <div><span class="badge bg-warning text-dark me-1">2</span> Jefe de operaciones — aprueba lotes completos</div>
                                <div><span class="badge bg-success me-1">3</span> Gerencia — acceso de revision (se habilitara)</div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="d-flex align-items-center gap-3">
                    <label class="form-check" for="new_activo">
                        <input class="form-check-input" type="checkbox" name="activo" id="new_activo" checked>
                        <span class="form-check-box"></span>
                        <span class="form-check-label">Activo</span>
                    </label>
                    <button type="submit" name="guardar" class="btn btn-primary">Guardar usuario</button>
                </div>
            </form>
        </div>
    </div>

    <!-- ── Tabla de usuarios ──────────────────────────────────────────────── -->
    <h2 class="text-center mb-3">Usuarios Registrados</h2>
    <div class="table-responsive">
        <table class="table table-bordered table-hover align-middle">
            <thead class="table-dark">
                <tr>
                    <th>ID</th>
                    <th>Usuario</th>
                    <th>Nombre</th>
                    <th>Email</th>
                    <th>Perfil</th>
                    <th>Módulos</th>
                    <th>Áreas aprobación</th>
                    <th>Nivel aprobacion</th>
                    <th>Activo</th>
                    <th>Acciones</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($usuarios as $u): ?>
                <tr>
                    <td><?= (int)$u['id_usuario'] ?></td>
                    <td><?= htmlspecialchars($u['usuario']) ?></td>
                    <td><?= htmlspecialchars(trim($u['nombre'] . ' ' . $u['apellido'])) ?></td>
                    <td><?= htmlspecialchars($u['email'] ?? '') ?></td>
                    <td><?= htmlspecialchars($u['perfil_nombre'] ?? '') ?></td>
                    <td>
                        <?php if ($u['modulos']): ?>
                            <small><?= implode(', ', array_map('htmlspecialchars', $u['modulos'])) ?></small>
                        <?php else: ?>
                            <small class="text-muted">ninguno</small>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php
                        $areasNombres = array_map(fn($id) => $areas_cat[$id] ?? "[$id]", $u['areas_aprobar']);
                        echo $areasNombres ? '<small>' . implode(', ', array_map('htmlspecialchars', $areasNombres)) . '</small>'
                                           : '<small class="text-muted">ninguna</small>';
                        ?>
                    </td>
                    <td class="text-center">
                        <?php
                        $niv = (int)$u['nivel_aprobacion'];
                        $nivLabels = [0=>'Normal',1=>'Jefe Area',2=>'Jefe Ops',3=>'Gerencia'];
                        $nivColors = [0=>'secondary',1=>'primary',2=>'warning',3=>'success'];
                        $nivTextClass = [0=>'',1=>'',2=>'text-dark',3=>''];
                        ?>
                        <span class="badge bg-<?= $nivColors[$niv] ?> <?= $nivTextClass[$niv] ?>">
                            <?= $niv ?> — <?= $nivLabels[$niv] ?? $niv ?>
                        </span>
                    </td>
                    <td class="text-center">
                        <form method="POST" style="display:inline">
                            <input type="hidden" name="id" value="<?= (int)$u['id_usuario'] ?>">
                            <input type="hidden" name="activo" value="<?= $u['activo'] ? 0 : 1 ?>">
                            <button type="submit" name="toggle_activo"
                                class="btn btn-sm <?= $u['activo'] ? 'btn-success' : 'btn-secondary' ?>">
                                <?= $u['activo'] ? 'Sí' : 'No' ?>
                            </button>
                        </form>
                    </td>
                    <td>
                        <?php
                        $u_json = [
                            'id_usuario'       => $u['id_usuario'],
                            'usuario'          => $u['usuario'],
                            'nombre'           => $u['nombre'],
                            'apellido'         => $u['apellido'],
                            'email'            => $u['email'],
                            'id_perfil'        => $u['id_perfil'],
                            'id_area'          => $u['id_area'],
                            'activo'           => $u['activo'],
                            'modulos'          => $u['modulos'],
                            'areas_aprobar'    => $u['areas_aprobar'],
                            'cargos_aprobar'   => $u['cargos_aprobar'],
                            'nivel_aprobacion' => $u['nivel_aprobacion'],
                            'ja_id_area'       => $u['ja_id_area'],
                            'ja_id_turno'      => $u['ja_id_turno'],
                        ];
                        ?>
                        <button class="btn btn-warning btn-sm"
                            onclick="abrirModal(<?= htmlspecialchars(json_encode($u_json), ENT_QUOTES) ?>)">
                            Editar
                        </button>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</main>

<!-- ── Modal Editar ───────────────────────────────────────────────────────── -->
<div class="modal fade" id="modalEditar" tabindex="-1">
    <div class="modal-dialog modal-xl">
        <div class="modal-content">
            <form method="POST">
                <input type="hidden" name="id" id="edit-id">
                <div class="modal-header">
                    <h5 class="modal-title">Editar Usuario</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <!-- Datos básicos -->
                    <div class="row g-3 mb-3">
                        <div class="col-md-2">
                            <label class="form-label">Usuario <span class="text-danger">*</span></label>
                            <input type="text" name="usuario" id="edit-usuario" class="form-control" required>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label">Nueva contraseña</label>
                            <input type="password" name="pass_usu" class="form-control" placeholder="Dejar vacío para no cambiar">
                        </div>
                        <div class="col-md-2">
                            <label class="form-label">Nombre</label>
                            <input type="text" name="nombre" id="edit-nombre" class="form-control">
                        </div>
                        <div class="col-md-2">
                            <label class="form-label">Apellido</label>
                            <input type="text" name="apellido" id="edit-apellido" class="form-control">
                        </div>
                        <div class="col-md-2">
                            <label class="form-label">Email</label>
                            <input type="email" name="email" id="edit-email" class="form-control">
                        </div>
                        <div class="col-md-1">
                            <label class="form-label">Perfil <span class="text-danger">*</span></label>
                            <select name="id_perfil" id="edit-perfil" class="form-control" required>
                                <?php foreach ($perfiles as $pid => $pnom): ?>
                                    <option value="<?= $pid ?>"><?= htmlspecialchars($pnom) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-1">
                            <label class="form-label">Área propia</label>
                            <select name="id_area" id="edit-area" class="form-control">
                                <option value="">--</option>
                                <?php foreach ($areas_cat as $aid => $anom): ?>
                                    <option value="<?= $aid ?>"><?= htmlspecialchars($anom) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <!-- Módulos -->
                    <div class="mb-3">
                        <label class="form-label fw-bold">Módulos con acceso</label>
                        <div class="d-flex flex-wrap gap-3" id="edit-modulos">
                            <?php foreach ($modulos_lista as $mod): ?>
                            <label class="form-check" for="edit_mod_<?= $mod ?>">
                                <input class="form-check-input" type="checkbox" name="mod_<?= $mod ?>" id="edit_mod_<?= $mod ?>">
                                <span class="form-check-box"></span>
                                <span class="form-check-label"><?= $modulos_labels[$mod] ?? ucfirst($mod) ?></span>
                            </label>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <!-- Áreas y Cargos -->
                    <div class="row g-3 mb-3">
                        <div class="col-md-6">
                            <label class="form-label fw-bold">Áreas que puede aprobar</label>
                            <select name="areas_aprobar[]" id="edit-areas-aprobar" data-multiselect data-placeholder="Seleccionar áreas..." data-search="Buscar área..." multiple>
                                <?php foreach ($areas_cat as $aid => $anom): ?>
                                    <option value="<?= $aid ?>"><?= htmlspecialchars($anom) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-bold">Cargos específicos adicionales</label>
                            <select name="cargos_aprobar[]" id="edit-cargos-aprobar" data-multiselect data-placeholder="Seleccionar cargos..." data-search="Buscar cargo..." multiple>
                                <?php foreach ($cargos_cat as $cid => $cnom): ?>
                                    <option value="<?= $cid ?>"><?= htmlspecialchars($cnom) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <!-- Nivel de aprobación -->
                    <div class="row g-3 mb-3">
                        <div class="col-md-8">
                            <label class="form-label fw-bold">Nivel de aprobacion</label>
                            <div class="row g-2 align-items-end">
                                <div class="col-md-3">
                                    <label class="form-label small text-muted">Nivel</label>
                                    <select name="nivel_aprobacion" id="edit-nivel" class="form-select" onchange="toggleAreaNivel(this,'edit')">
                                        <option value="0">0 — Usuario normal</option>
                                        <option value="1">1 — Jefe de area</option>
                                        <option value="2">2 — Jefe de operaciones</option>
                                        <option value="3">3 — Gerencia</option>
                                    </select>
                                </div>
                                <div class="col-md-4 edit-area-turno" style="display:none;">
                                    <label class="form-label small text-muted">Area que aprueba</label>
                                    <select name="ja_id_area" id="edit-ja-area" class="form-select">
                                        <option value="">-- Sin area especifica --</option>
                                        <?php foreach ($areas_cat as $aid => $anom): ?>
                                            <option value="<?= $aid ?>"><?= htmlspecialchars($anom) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-3 edit-area-turno" style="display:none;">
                                    <label class="form-label small text-muted">Turno <span class="text-muted">(opcional)</span></label>
                                    <select name="ja_id_turno" id="edit-ja-turno" class="form-select">
                                        <option value="">-- Todos los turnos --</option>
                                        <?php foreach ($turnos_cat as $tid => $tnom): ?>
                                            <option value="<?= $tid ?>"><?= htmlspecialchars($tnom) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="card border-info h-100">
                                <div class="card-body py-2 px-3 small">
                                    <div class="fw-bold text-info mb-1">Referencia de niveles</div>
                                    <div><span class="badge bg-secondary me-1">0</span> Usuario normal</div>
                                    <div><span class="badge bg-primary me-1">1</span> Jefe de area</div>
                                    <div><span class="badge bg-warning text-dark me-1">2</span> Jefe de operaciones</div>
                                    <div><span class="badge bg-success me-1">3</span> Gerencia</div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <label class="form-check" for="edit-activo">
                        <input class="form-check-input" type="checkbox" name="activo" id="edit-activo" value="1">
                        <span class="form-check-box"></span>
                        <span class="form-check-label">Activo</span>
                    </label>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" name="editar" class="btn btn-primary">Guardar cambios</button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php include __DIR__ . '/../partials/footer.php'; ?>

<script>
function toggleAreaNivel(sel, prefix) {
    const nivel = parseInt(sel.value);
    document.querySelectorAll('.' + prefix + '-area-turno').forEach(el => {
        el.style.display = nivel === 1 ? '' : 'none';
    });
}

function abrirModal(u) {
    document.getElementById('edit-id').value       = u.id_usuario;
    document.getElementById('edit-usuario').value  = u.usuario;
    document.getElementById('edit-nombre').value   = u.nombre   || '';
    document.getElementById('edit-apellido').value = u.apellido || '';
    document.getElementById('edit-email').value    = u.email    || '';
    document.getElementById('edit-perfil').value   = u.id_perfil;
    document.getElementById('edit-area').value     = u.id_area  || '';
    document.getElementById('edit-activo').checked = u.activo == 1;

    // Nivel de aprobación
    const nivelSel = document.getElementById('edit-nivel');
    nivelSel.value = u.nivel_aprobacion || 0;
    toggleAreaNivel(nivelSel, 'edit');
    document.getElementById('edit-ja-area').value  = u.ja_id_area  || '';
    document.getElementById('edit-ja-turno').value = u.ja_id_turno || '';

    // Módulos
    <?php foreach ($modulos_lista as $mod): ?>
    document.getElementById('edit_mod_<?= $mod ?>').checked = u.modulos.includes('<?= $mod ?>');
    <?php endforeach; ?>

    // Áreas de aprobación
    document.getElementById('edit-areas-aprobar').msSetValues(u.areas_aprobar.map(String));

    // Cargos específicos
    document.getElementById('edit-cargos-aprobar').msSetValues(u.cargos_aprobar.map(String));

    new bootstrap.Modal(document.getElementById('modalEditar')).show();
}
</script>
