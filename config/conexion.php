<?php

$_env = parse_ini_file(__DIR__ . '/.env');

$serverName     = $_env['DB_SERVER'];
$connectionInfo = ['Database' => $_env['DB_NAME'],  'UID' => $_env['DB_USER'], 'PWD' => $_env['DB_PASSWORD'], 'CharacterSet' => 'UTF-8'];
$conn = sqlsrv_connect($serverName, $connectionInfo);
if (!$conn) {
    error_log('BD SistGestion: ' . print_r(sqlsrv_errors(), true));
    die('Error al conectar con la base de datos.');
}

// Conexión para rescatar marcas
$connectionInfo2 = ['Database' => $_env['DB_NAME2'], 'UID' => $_env['DB_USER'], 'PWD' => $_env['DB_PASSWORD'], 'CharacterSet' => 'UTF-8'];
$conn2 = sqlsrv_connect($serverName, $connectionInfo2);
if (!$conn2) {
    error_log('BD Facturador: ' . print_r(sqlsrv_errors(), true));
    die('Error al conectar con la base de datos.');
}

