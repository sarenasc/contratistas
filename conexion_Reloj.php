<?php
$_env = parse_ini_file(__DIR__ . '/config/.env');

$serverName     = $_env['DB_SERVER'];
$connectionInfo = ['Database' => $_env['DB_NAME_RELOJ'], 'UID' => $_env['DB_USER'], 'PWD' => $_env['DB_PASSWORD'], 'CharacterSet' => 'UTF-8'];
$conn = sqlsrv_connect($serverName, $connectionInfo);
if (!$conn) {
    error_log('BD Reloj: ' . print_r(sqlsrv_errors(), true));
    die('Error al conectar con la base de datos.');
}
