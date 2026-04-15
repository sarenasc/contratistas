<?php
require_once __DIR__ . '/../config/conexion.php';
require_once __DIR__ . '/../app/lib/csrf.php';
session_start();

if(isset($_POST['inicio'])){
    // Verificar CSRF antes de procesar el login
    if (!csrf_verify()) {
        header("Location: index.php?err=csrf");
        exit;
    }
    $usuario = isset($_POST['user']) ? trim($_POST['user']) : '';
    $pass    = isset($_POST['pass']) ? $_POST['pass']       : '';

    // Buscar solo por nombre de usuario; verificar contraseña en PHP
    $sql = "SELECT TOP 1
                [id],[nom_usu],[pass_usu],[id_area],[Nombre],[Apellido]
            FROM [dbo].[TRA_usuario]
            WHERE nom_usu = ?";
    $stmt = sqlsrv_query($conn, $sql, [$usuario]);

    if ($stmt === false) {
        header("Location: index.php?err=1");
        exit;
    }

    $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);

    if (!$row || !password_verify($pass, $row['pass_usu'])) {
        header("Location: index.php?invalid=1");
        exit;
    }

    session_regenerate_id(true);

    $_SESSION['nom_usu']  = $row['nom_usu'];
    $_SESSION['Nombre']   = $row['Nombre'];
    $_SESSION['Apellido'] = $row['Apellido'];
    $_SESSION['id']       = $row['id'];

    header("Location: Inicio.php");
    exit;
}



?>