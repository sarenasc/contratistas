<?php
if (session_status() === PHP_SESSION_NONE){
    session_start();
   
}

//si no esta logueado  lo mandamos al login
if ( !isset($_SESSION['id']) || empty($_SESSION['id'])){
    header("Location:" . BASE_URL ."/Index.php");
    exit;
}
?>