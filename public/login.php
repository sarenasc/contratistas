<?php
require_once __DIR__ . '/../config/conexion.php';
if (session_status() === PHP_SESSION_NONE) session_start();

if (isset($_POST['inicio'])) {
    $usuario = trim($_POST['user'] ?? '');
    $pass    = $_POST['pass'] ?? '';

    $sql  = "SELECT TOP 1 id_usuario, usuario, password_hash, nombre, apellido, id_perfil, activo
             FROM dbo.dota_usuarios WHERE usuario = ?";
    $stmt = sqlsrv_query($conn, $sql, [$usuario]);

    if ($stmt === false) {
        header("Location: index.php?err=1"); exit;
    }

    $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);

    if (!$row || !$row['activo'] || !password_verify($pass, $row['password_hash'])) {
        header("Location: index.php?invalid=1"); exit;
    }

    // Cargar módulos permitidos
    $stmtM  = sqlsrv_query($conn, "SELECT modulo FROM dbo.dota_usuario_modulos WHERE id_usuario = ?", [$row['id_usuario']]);
    $modulos = [];
    while ($stmtM && ($m = sqlsrv_fetch_array($stmtM, SQLSRV_FETCH_ASSOC))) {
        $modulos[] = $m['modulo'];
    }

    // Cargar áreas que puede aprobar: desde dota_usuario_areas Y desde dota_jefe_area
    $stmtA = sqlsrv_query($conn, "SELECT id_area FROM dbo.dota_usuario_areas WHERE id_usuario = ?", [$row['id_usuario']]);
    $areas = [];
    while ($stmtA && ($a = sqlsrv_fetch_array($stmtA, SQLSRV_FETCH_ASSOC))) {
        $areas[] = (int)$a['id_area'];
    }
    // Cargar pares (area, turno) de dota_jefe_area nivel 1
    // Si id_turno es NULL = aprueba esa área en todos los turnos
    $area_turno_pairs = [];
    $stmtJA = sqlsrv_query($conn,
        "SELECT id_area, id_turno FROM dbo.dota_jefe_area
         WHERE id_usuario = ? AND activo = 1 AND nivel_aprobacion = 1",
        [$row['id_usuario']]);
    while ($stmtJA && ($a = sqlsrv_fetch_array($stmtJA, SQLSRV_FETCH_ASSOC))) {
        $id_a = (int)$a['id_area'];
        $id_t = ($a['id_turno'] !== null) ? (int)$a['id_turno'] : null;
        $area_turno_pairs[] = ['area' => $id_a, 'turno' => $id_t];
        $areas[] = $id_a;
    }
    $areas = array_values(array_unique($areas));

    // Cargar cargos específicos que puede aprobar
    $stmtC = sqlsrv_query($conn, "SELECT id_cargo FROM dbo.dota_usuario_cargos WHERE id_usuario = ?", [$row['id_usuario']]);
    $cargos = [];
    while ($stmtC && ($c = sqlsrv_fetch_array($stmtC, SQLSRV_FETCH_ASSOC))) {
        $cargos[] = (int)$c['id_cargo'];
    }

    // Nivel de aprobación (1=jefe área, 2=jefe operaciones, 0=no es jefe)
    $stmtJ   = sqlsrv_query($conn, "SELECT MAX(nivel_aprobacion) AS nivel FROM dbo.dota_jefe_area WHERE id_usuario = ? AND activo = 1", [$row['id_usuario']]);
    $nivelRow = $stmtJ ? sqlsrv_fetch_array($stmtJ, SQLSRV_FETCH_ASSOC) : null;
    $nivel    = (int)($nivelRow['nivel'] ?? 0);

    session_regenerate_id(true);
    $_SESSION['id_usuario']       = (int)$row['id_usuario'];
    $_SESSION['usuario']          = $row['usuario'];
    $_SESSION['nombre']           = $row['nombre'];
    $_SESSION['apellido']         = $row['apellido'];
    $_SESSION['id_perfil']        = (int)$row['id_perfil'];
    $_SESSION['modulos']          = $modulos;
    $_SESSION['areas_aprobar']    = $areas;
    $_SESSION['area_turno_pairs'] = $area_turno_pairs;
    $_SESSION['cargos_aprobar']   = $cargos;
    $_SESSION['nivel_aprobacion'] = $nivel;

    header("Location: Inicio.php"); exit;
}
