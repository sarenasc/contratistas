<?php
require_once __DIR__ . '/base_url.php';
?>

<?php if (puede_modulo('aprobacion') || puede_aprobar()): ?>
<!-- Sección Aprobación -->
<li class="nav-item dropdown">
    <a class="nav-link dropdown-toggle" href="#" id="navbarAprobacion" role="button" data-bs-toggle="dropdown">
        Aprobación
    </a>
    <div class="dropdown-menu" aria-labelledby="navbarAprobacion">
        <?php if (puede_aprobar() && !es_jefe_operaciones()): ?>
            <a class="dropdown-item" href="<?= BASE_URL ?>/aprobacion/bandeja_jefe.php">Bandeja Jefe Área</a>
        <?php endif; ?>
        <?php if (es_jefe_operaciones() || es_admin()): ?>
            <a class="dropdown-item" href="<?= BASE_URL ?>/aprobacion/bandeja_operaciones.php">Bandeja Operaciones</a>
        <?php endif; ?>
        <a class="dropdown-item" href="<?= BASE_URL ?>/aprobacion/detalle_asistencia.php">Detalle Asistencia</a>
    </div>
</li>
<?php endif; ?>

<?php if (puede_modulo('procesos')): ?>
<!-- Sección Procesos -->
<li class="nav-item dropdown">
    <a class="nav-link dropdown-toggle" href="#" id="navbarProcesos" role="button" data-bs-toggle="dropdown">
        Procesos
    </a>
    <div class="dropdown-menu" aria-labelledby="navbarProcesos">
        <a class="dropdown-item" href="<?= BASE_URL ?>/procesos/carga_asistencia.php">Carga Asistencia</a>
        <a class="dropdown-item" href="<?= BASE_URL ?>/procesos/editar_asistencia.php">Editar Asistencia</a>
        <a class="dropdown-item" href="<?= BASE_URL ?>/procesos/descuento.php">Descuento</a>
        <a class="dropdown-item" href="<?= BASE_URL ?>/procesos/Pre-Factura.php">Pre Factura</a>
        <a class="dropdown-item" href="<?= BASE_URL ?>/procesos/proformas.php">Proformas</a>
    </div>
</li>
<?php endif; ?>

<?php if (puede_modulo('contratista')): ?>
<!-- Sección Contratistas -->
<li class="nav-item dropdown">
    <a class="nav-link dropdown-toggle" href="#" id="navbarContratistas" role="button" data-bs-toggle="dropdown">
        Contratistas
    </a>
    <div class="dropdown-menu" aria-labelledby="navbarContratistas">
        <a class="dropdown-item" href="<?= BASE_URL ?>/contratista/ingreso_contratista.php">Contratista</a>
        <a class="dropdown-item" href="<?= BASE_URL ?>/contratista/Solicitud_Contra.php">Solicitud Contratista</a>
    </div>
</li>
<?php endif; ?>

<?php if (puede_modulo('tarifas')): ?>
<!-- Sección Tarifas -->
<li class="nav-item dropdown">
    <a class="nav-link dropdown-toggle" href="#" id="navbarTarifas" role="button" data-bs-toggle="dropdown">
        Tarifas
    </a>
    <div class="dropdown-menu" aria-labelledby="navbarTarifas">
        <a class="dropdown-item" href="<?= BASE_URL ?>/tarifas/Tarifas_Cargo.php">Cargos y Tarifas</a>
        <a class="dropdown-item" href="<?= BASE_URL ?>/tarifas/tipo_tarifa.php">Tipo Tarifas</a>
        <a class="dropdown-item" href="<?= BASE_URL ?>/tarifas/tarifasEspecial.php">Tarifas Especiales</a>
    </div>
</li>
<?php endif; ?>

<?php if (puede_modulo('configuraciones')): ?>
<!-- Sección Configuracion -->
<li class="nav-item dropdown">
    <a class="nav-link dropdown-toggle" href="#" id="navbarConfiguracion" role="button" data-bs-toggle="dropdown">
        Configuraciones
    </a>
    <div class="dropdown-menu" aria-labelledby="navbarConfiguracion">
        <a class="dropdown-item" href="<?= BASE_URL ?>/configuraciones/tipo_mo.php">Tipo de mano de obra</a>
        <a class="dropdown-item" href="<?= BASE_URL ?>/configuraciones/Cargos.php">Labores</a>
        <a class="dropdown-item" href="<?= BASE_URL ?>/configuraciones/Area.php">Area</a>
        <a class="dropdown-item" href="<?= BASE_URL ?>/configuraciones/Turnos.php">Turnos</a>
        <a class="dropdown-item" href="<?= BASE_URL ?>/configuraciones/JefeArea.php">Jefes de Área</a>
        <?php if (es_admin()): ?>
            <a class="dropdown-item" href="<?= BASE_URL ?>/configuraciones/Reg_Usuario.php">Usuarios</a>
        <?php endif; ?>
    </div>
</li>
<?php endif; ?>
