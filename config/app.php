<?php
// Detecta /contratista/public desde SCRIPT_NAME; fallback al valor hardcodeado.
$_script = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '');
$_pos    = strpos($_script, '/public/');
define('BASE_URL', $_pos !== false ? substr($_script, 0, $_pos + 7) : '/contratista/public');
unset($_script, $_pos);
