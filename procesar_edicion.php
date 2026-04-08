<?php
require_once "conexion_Reloj.php";

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $userid = $_POST['USERID'];
    $CHECKTIME = $_POST['Fecha']." ".$_POST['Hora'];
    $name = $_POST['Name'];
    $checktype = $_POST['CHECKTYPE'];
    $dateFull = $_POST['datefull'];

    // Preparar y ejecutar la consulta de actualización
    $sql = "UPDATE [dbo].[CHECKINOUT]
    SET CHECKTYPE = '$checktype', CHECKTIME = convert(datetime,'$CHECKTIME',102)
     WHERE USERID = $userid and CHECKTIME = convert(datetime,'$dateFull',102)";
    $params = array($CHECKTIME, $name, $checktype, $userid);
    
    $stmt = sqlsrv_query($conn, $sql, $params);

    if ($stmt === false) {
        die(print_r(sqlsrv_errors(), true));
    }

    //Redirigir al usuario después de la edición
   header("Location: Edicion_turno.php?mensaje=actualizacion_exitosa");
   exit;
 }
?>
