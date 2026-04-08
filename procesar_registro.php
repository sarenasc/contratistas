<?php
require_once "conexion.php";

if ($conn === false) {
    die(print_r(sqlsrv_errors(), true));
}

// Obtener datos del formulario
$nombre_usuario = $_POST['nombre_usuario'];
$contraseña = password_hash($_POST['contraseña'], PASSWORD_BCRYPT); // Encriptar la contraseña
$area = $_POST['area'];
$nombre = $_POST['nombre'];
$apellido = $_POST['apellido'];

// Preparar la consulta SQL
$sql = "INSERT INTO usuarios (nom_usu, pass_usu, id_area, Nombre, Apellido) VALUES ('$nombre_usuario', $contraseña, $area, '$nombre', '$apellido')";
$params = array($nombre_usuario, $contraseña, $area, $nombre, $apellido);

// Ejecutar la consulta
$stmt = sqlsrv_query($conn, $sql, $params);

if ($stmt === false) {
    echo "Error en la ejecución de la consulta.<br>";
    die(print_r(sqlsrv_errors(), true));
} else {
    echo "Registro exitoso";
}

// Liberar los recursos y cerrar la conexión
sqlsrv_free_stmt($stmt);
sqlsrv_close($conn);
?>
