<?php
/**
 * Limpia archivos temporales de storage/asistencia/ con más de $max_age_days días.
 * Se llama al inicio del proceso de carga para evitar acumulación indefinida.
 */
function cleanup_asistencia_storage(int $max_age_days = 7): void {
    $dir = __DIR__ . '/../../storage/asistencia/';
    if (!is_dir($dir)) return;

    $cutoff = time() - ($max_age_days * 86400);

    foreach (new DirectoryIterator($dir) as $file) {
        if ($file->isDot() || !$file->isFile()) continue;
        // Solo eliminar archivos Excel temporales de carga
        $ext = strtolower($file->getExtension());
        if (!in_array($ext, ['xlsx', 'xls'], true)) continue;
        if ($file->getMTime() < $cutoff) {
            @unlink($file->getRealPath());
        }
    }
}
