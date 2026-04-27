<?php
/**
 * Rutas centralizadas para los scripts Python del módulo Reloj.
 * Usar: require_once __DIR__ . '/../../scripts/reloj/_rutas.php';
 */

// Lee PYTHON_BIN del .env — si no está definido usa 'python'
$_env_reloj = parse_ini_file(__DIR__ . '/../../config/.env');
define('PYTHON_BIN',     $_env_reloj['PYTHON_BIN']     ?? 'python');
define('RELOJ_SCRIPTS',  __DIR__);

define('PY_SYNC',       RELOJ_SCRIPTS . DIRECTORY_SEPARATOR . 'sync_relojes.py');
define('PY_REGISTRAR',  RELOJ_SCRIPTS . DIRECTORY_SEPARATOR . 'registrar_usuario.py');
define('PY_LIMPIAR',    RELOJ_SCRIPTS . DIRECTORY_SEPARATOR . 'limpiar_reloj.py');
define('PY_IMPORTAR',   RELOJ_SCRIPTS . DIRECTORY_SEPARATOR . 'importar_usuarios.py');
define('PY_INFO',       RELOJ_SCRIPTS . DIRECTORY_SEPARATOR . 'info_reloj.py');
define('PY_SINC_USERS',      RELOJ_SCRIPTS . DIRECTORY_SEPARATOR . 'sincronizar_usuarios.py');
define('PY_PUSH_AREAS',      RELOJ_SCRIPTS . DIRECTORY_SEPARATOR . 'push_areas_reloj.py');
define('PY_SYNC_HUELLAS',    RELOJ_SCRIPTS . DIRECTORY_SEPARATOR . 'sync_huellas_relojes.py');
define('PY_ELIMINAR',        RELOJ_SCRIPTS . DIRECTORY_SEPARATOR . 'eliminar_usuario.py');
define('PY_ELIMINAR_MARC',   RELOJ_SCRIPTS . DIRECTORY_SEPARATOR . 'eliminar_marcacion.py');
