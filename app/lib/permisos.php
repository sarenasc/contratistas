<?php
/**
 * Helpers de permisos — requieren sesión activa con datos cargados en login.php
 * Disponibles en toda la app vía _bootstrap.php
 */

function es_admin(): bool {
    return ((int)($_SESSION['id_perfil'] ?? 0)) === 1;
}

/** El perfil puede aprobar asistencia (Aprobador-Edicion=3 o Admin=1) */
function puede_aprobar(): bool {
    return es_admin() || in_array((int)($_SESSION['id_perfil'] ?? 0), [3]);
}

/** Acceso a un módulo: 'configuraciones'|'tarifas'|'procesos'|'contratista'|'aprobacion' */
function puede_modulo(string $modulo): bool {
    if (es_admin()) return true;
    return in_array($modulo, $_SESSION['modulos'] ?? []);
}

/** ¿Puede este usuario aprobar/rechazar asistencia de un área concreta? */
function puede_aprobar_area(int $id_area): bool {
    if (es_admin()) return true;
    return in_array($id_area, $_SESSION['areas_aprobar'] ?? []);
}

/** ¿Puede aprobar un cargo específico (excepción fuera de su área)? */
function puede_aprobar_cargo(int $id_cargo): bool {
    if (es_admin()) return true;
    return in_array($id_cargo, $_SESSION['cargos_aprobar'] ?? []);
}

/** Jefe de Operaciones: nivel_aprobacion = 2, ve y aprueba todo */
function es_jefe_operaciones(): bool {
    return ((int)($_SESSION['nivel_aprobacion'] ?? 0)) >= 2;
}

/** Nombre para mostrar en la UI */
function nombre_usuario(): string {
    $n = trim(($_SESSION['nombre'] ?? '') . ' ' . ($_SESSION['apellido'] ?? ''));
    return $n ?: ($_SESSION['usuario'] ?? '');
}
