<?php
require_once __DIR__ . '/base_url.php';


?>


<!-- Sección Procesos -->
    <li class="nav-item dropdown">
            <a class="nav-link dropdown-toggle" href="#" id="navbarProcesos" role="button" data-bs-toggle="dropdown" aria-haspopup="true" aria-expanded="false">
                Procesos
            </a>
            <div class="dropdown-menu" aria-labelledby="navbarProcesos">
                <!--<a class="dropdown-item" href="<?= BASE_URL ?>/procesos/Rev_datos.php">Revision de Datos</a>-->
                <!--<a class="dropdown-item" href="<?= BASE_URL ?>/procesos/edicion_turno.php">Editar Turnos</a>-->
                <a class="dropdown-item" href="<?= BASE_URL ?>/procesos/carga_asistencia.php">Carga Asistencia</a>
                <a class="dropdown-item" href="<?= BASE_URL ?>/procesos/descuento.php">Descuento</a>
                <a class="dropdown-item" href="<?= BASE_URL ?>/procesos/Pre-Factura.php">Pre Factura</a>
                <a class="dropdown-item" href="<?= BASE_URL ?>/procesos/proformas.php">Proformas</a>
                <!--<a class="dropdown-item" href="<?= BASE_URL ?>/procesos/sinc.php">Sincronizar Bases</a>-->
                
                
            </div>
        </li>

        <!-- Sección Contratistas -->
        <li class="nav-item dropdown">
            <a class="nav-link dropdown-toggle" href="#" id="navbarContratistas" role="button" data-bs-toggle="dropdown" aria-haspopup="true" aria-expanded="false">
                Contratistas
            </a>
            <div class="dropdown-menu" aria-labelledby="navbarContratistas">
                <a class="dropdown-item" href="<?= BASE_URL ?>/contratista/ingreso_contratista.php">Contratista</a>
                <a class="dropdown-item" href="<?= BASE_URL ?>/contratista/Solicitud_Contra.php">Solicitud Contratista</a>
                
            </div>
        </li>
        
        

        <!-- Sección Tarifas -->
        <li class="nav-item dropdown">
            <a class="nav-link dropdown-toggle" href="#" id="navbarTarifas" role="button" data-bs-toggle="dropdown" aria-haspopup="true" aria-expanded="false">
                Tarifas
            </a>
            <div class="dropdown-menu" aria-labelledby="navbarTarifas">
                <a class="dropdown-item" href="<?= BASE_URL ?>/tarifas/Tarifas_Cargo.php">Cargos y Tarifas</a>
                <!--<a class="dropdown-item" href="TarifasEspNormal.php">Tarifas Especiales Contratistas</a>-->
                <a class="dropdown-item" href="<?= BASE_URL ?>/tarifas/tipo_tarifa.php">Tipo Tarifas</a>
                <a class="dropdown-item" href="<?= BASE_URL ?>/tarifas/tarifasEspecial.php">Tarifas Especiales</a>
            </div>
        </li>

        <!-- Sección Configuracion -->
        <li class="nav-item dropdown">
            <a class="nav-link dropdown-toggle" href="#" id="navbarConfiguracion" role="button" data-bs-toggle="dropdown" aria-haspopup="true" aria-expanded="false">
                Configuraciones
            </a>
            <div class="dropdown-menu" aria-labelledby="navbarConfiguracion">
                <a class="dropdown-item" href="<?= BASE_URL ?>/configuraciones/tipo_mo.php">Tipo de mano de obra</a>
                <a class="dropdown-item" href="<?= BASE_URL ?>/configuraciones/Cargos.php">Labores</a>
                <a class="dropdown-item" href="<?= BASE_URL ?>/configuraciones/Area.php">Area</a>
                <a class="dropdown-item" href="<?= BASE_URL ?>/configuraciones/Turnos.php">Turnos</a>
                <a class="dropdown-item" href="<?= BASE_URL ?>/configuraciones/JefeArea.php">Jefes de Área</a>
                <a class="dropdown-item" href="<?= BASE_URL ?>/configuraciones/Reg_Usuario.php">Registro Usuario</a>
            </div>
        </li>