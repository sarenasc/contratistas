<?php
require_once __DIR__ . '/../_bootstrap.php';
require_once __DIR__ . '/../../app/lib/db.php';

if (!puede_modulo('configuraciones') && !es_admin()) {
    header('Location: ' . BASE_URL . '/Inicio.php');
    exit;
}

$flash_error = null;
$flash_ok    = null;

const DIAS_SEMANA = [
    1 => 'Lunes',
    2 => 'Martes',
    3 => 'Miercoles',
    4 => 'Jueves',
    5 => 'Viernes',
    6 => 'Sabado',
    7 => 'Domingo',
];

function ensure_turno_detalle_table($conn): void {
    $sql = "
IF OBJECT_ID('dbo.dota_turno_detalle','U') IS NULL
BEGIN
    CREATE TABLE dbo.dota_turno_detalle (
        id           INT IDENTITY(1,1) NOT NULL PRIMARY KEY,
        id_turno     INT NOT NULL,
        dia_semana   TINYINT NOT NULL,
        hora_entrada TIME(0) NOT NULL,
        hora_salida  TIME(0) NOT NULL,
        activo       BIT NOT NULL CONSTRAINT DF_dota_turno_detalle_activo DEFAULT (1),
        CONSTRAINT FK_dota_turno_detalle_turno
            FOREIGN KEY (id_turno) REFERENCES dbo.dota_turno(id) ON DELETE CASCADE,
        CONSTRAINT UQ_dota_turno_detalle UNIQUE (id_turno, dia_semana),
        CONSTRAINT CK_dota_turno_detalle_dia CHECK (dia_semana BETWEEN 1 AND 7)
    );
END
";
    db_query($conn, $sql, [], 'crear tabla dota_turno_detalle');
}

function hora_valida(string $hora): bool {
    return preg_match('/^\d{2}:\d{2}$/', $hora) === 1;
}

function normalizar_detalles_turno(array $source): array {
    $detalles = [];

    foreach (DIAS_SEMANA as $diaNum => $diaNombre) {
        $row = $source[$diaNum] ?? [];
        $activo = isset($row['activo']);
        $entrada = trim((string)($row['entrada'] ?? ''));
        $salida  = trim((string)($row['salida'] ?? ''));

        if (!$activo && $entrada === '' && $salida === '') {
            continue;
        }

        if (!$activo) {
            throw new RuntimeException("Marca el dia {$diaNombre} o limpia sus horas.");
        }
        if ($entrada === '' || $salida === '') {
            throw new RuntimeException("Completa hora de entrada y salida para {$diaNombre}.");
        }
        if (!hora_valida($entrada) || !hora_valida($salida)) {
            throw new RuntimeException("Formato de hora invalido en {$diaNombre}. Usa HH:MM.");
        }
        if ($entrada === $salida) {
            throw new RuntimeException("La hora de entrada y salida no puede ser igual en {$diaNombre}.");
        }

        $detalles[$diaNum] = [
            'dia_semana'   => $diaNum,
            'hora_entrada' => $entrada,
            'hora_salida'  => $salida,
        ];
    }

    if (!$detalles) {
        throw new RuntimeException('Debes configurar al menos un dia para el turno.');
    }

    return $detalles;
}

function guardar_detalles_turno($conn, int $idTurno, array $detalles): void {
    db_query($conn, "DELETE FROM dbo.dota_turno_detalle WHERE id_turno = ?", [$idTurno], 'limpiar detalle turno');
    foreach ($detalles as $detalle) {
        db_query(
            $conn,
            "INSERT INTO dbo.dota_turno_detalle (id_turno, dia_semana, hora_entrada, hora_salida, activo)
             VALUES (?, ?, CONVERT(time, ?, 108), CONVERT(time, ?, 108), 1)",
            [$idTurno, $detalle['dia_semana'], $detalle['hora_entrada'], $detalle['hora_salida']],
            'guardar detalle turno'
        );
    }
}

function cargar_detalles_turno($conn, array $idsTurno): array {
    if (!$idsTurno) {
        return [];
    }

    $placeholders = implode(',', array_fill(0, count($idsTurno), '?'));
    $sql = "
        SELECT id_turno, dia_semana,
               CONVERT(VARCHAR(5), hora_entrada, 108) AS hora_entrada,
               CONVERT(VARCHAR(5), hora_salida, 108)  AS hora_salida
        FROM dbo.dota_turno_detalle
        WHERE id_turno IN ($placeholders) AND activo = 1
        ORDER BY id_turno, dia_semana
    ";

    $stmt = db_query($conn, $sql, $idsTurno, 'listar detalle turnos');
    $detalles = [];
    while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
        $detalles[(int)$row['id_turno']][] = [
            'dia_semana'   => (int)$row['dia_semana'],
            'hora_entrada' => (string)$row['hora_entrada'],
            'hora_salida'  => (string)$row['hora_salida'],
        ];
    }
    return $detalles;
}

function resumen_detalles_turno(array $detalles): string {
    if (!$detalles) {
        return 'Sin horario definido';
    }

    $partes = [];
    foreach ($detalles as $detalle) {
        $nombreDia = DIAS_SEMANA[$detalle['dia_semana']] ?? ('Dia ' . $detalle['dia_semana']);
        $partes[] = "{$nombreDia}: {$detalle['hora_entrada']} - {$detalle['hora_salida']}";
    }
    return implode(' | ', $partes);
}

function detalles_para_formulario(array $detalles): array {
    $salida = [];
    foreach ($detalles as $detalle) {
        $salida[$detalle['dia_semana']] = [
            'activo'  => true,
            'entrada' => $detalle['hora_entrada'],
            'salida'  => $detalle['hora_salida'],
        ];
    }
    return $salida;
}

ensure_turno_detalle_table($conn);

$txOpen = false;

try {
    if (isset($_POST['guardar'])) {
        $nombre = trim((string)($_POST['nombre_turno'] ?? ''));
        $detalles = normalizar_detalles_turno($_POST['dias'] ?? []);

        if ($nombre === '') {
            throw new RuntimeException('El nombre del turno no puede estar vacio.');
        }

        sqlsrv_begin_transaction($conn);
        $txOpen = true;
        $stmt = db_query(
            $conn,
            "INSERT INTO dbo.dota_turno (nombre_turno) OUTPUT INSERTED.id VALUES (?)",
            [$nombre],
            'crear turno'
        );
        $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
        $idTurno = (int)($row['id'] ?? 0);
        if ($idTurno <= 0) {
            throw new RuntimeException('No se pudo obtener el nuevo turno.');
        }

        guardar_detalles_turno($conn, $idTurno, $detalles);
        sqlsrv_commit($conn);
        $txOpen = false;
        $flash_ok = 'Turno agregado correctamente.';
    }

    if (isset($_POST['editar'])) {
        $id = (int)($_POST['id'] ?? 0);
        $nombre = trim((string)($_POST['nombre_turno'] ?? ''));
        $detalles = normalizar_detalles_turno($_POST['dias'] ?? []);

        if ($id <= 0) {
            throw new RuntimeException('Turno invalido.');
        }
        if ($nombre === '') {
            throw new RuntimeException('El nombre del turno no puede estar vacio.');
        }

        sqlsrv_begin_transaction($conn);
        $txOpen = true;
        db_query($conn, "UPDATE dbo.dota_turno SET nombre_turno = ? WHERE id = ?", [$nombre, $id], 'editar turno');
        guardar_detalles_turno($conn, $id, $detalles);
        sqlsrv_commit($conn);
        $txOpen = false;
        $flash_ok = 'Turno actualizado.';
    }

    if (isset($_POST['eliminar'])) {
        $id = (int)($_POST['id'] ?? 0);
        if ($id <= 0) {
            throw new RuntimeException('Turno invalido.');
        }

        sqlsrv_begin_transaction($conn);
        $txOpen = true;
        db_query($conn, "DELETE FROM dbo.dota_turno WHERE id = ?", [$id], 'eliminar turno');
        sqlsrv_commit($conn);
        $txOpen = false;
        $flash_ok = 'Turno eliminado.';
    }
} catch (Throwable $e) {
    if ($txOpen) {
        sqlsrv_rollback($conn);
    }
    $flash_error = $e->getMessage();
}

$per_page = 20;
$page     = max(1, (int)($_GET['page'] ?? 1));
$offset   = ($page - 1) * $per_page;

$total = 0;
$stmtCount = db_query($conn, "SELECT COUNT(*) AS total FROM dbo.dota_turno");
if ($stmtCount) {
    $rowTotal = sqlsrv_fetch_array($stmtCount, SQLSRV_FETCH_ASSOC);
    $total = (int)($rowTotal['total'] ?? 0);
}
$total_pages = max(1, (int)ceil($total / $per_page));
$page = min($page, $total_pages);
$offset = ($page - 1) * $per_page;

$query = db_query(
    $conn,
    "SELECT id, nombre_turno
     FROM dbo.dota_turno
     ORDER BY nombre_turno
     OFFSET ? ROWS FETCH NEXT ? ROWS ONLY",
    [$offset, $per_page],
    'listar turnos'
);

$turnos = [];
while ($row = sqlsrv_fetch_array($query, SQLSRV_FETCH_ASSOC)) {
    $turnos[] = [
        'id'           => (int)$row['id'],
        'nombre_turno' => (string)$row['nombre_turno'],
    ];
}

$detallesByTurno = cargar_detalles_turno($conn, array_column($turnos, 'id'));

$title = "Turnos";
include __DIR__ . '/../partials/header.php';
include __DIR__ . '/../partials/navbar_wrapper.php';
?>

<main class="container py-4">
    <div class="text-center my-4">
        <h1 class="display-4">Gestion de Turnos</h1>
        <p class="text-muted mb-0">Define los dias y horarios que usara el sistema para interpretar las marcas del reloj.</p>
    </div>

    <?php if ($flash_error): ?>
      <div class="alert alert-danger"><?= htmlspecialchars($flash_error) ?></div>
    <?php endif; ?>
    <?php if ($flash_ok): ?>
      <div class="alert alert-success"><?= htmlspecialchars($flash_ok) ?></div>
    <?php endif; ?>

    <div class="card mb-4">
        <div class="card-header fw-bold">Agregar Nuevo Turno</div>
        <div class="card-body">
            <form method="POST">
                <div class="row g-3">
                    <div class="col-lg-4">
                        <label class="form-label">Nombre del turno</label>
                        <input type="text" class="form-control" name="nombre_turno" required>
                    </div>
                    <div class="col-12">
                        <div class="table-responsive">
                            <table class="table table-bordered align-middle">
                                <thead class="table-light">
                                    <tr>
                                        <th>Aplica</th>
                                        <th>Dia</th>
                                        <th>Hora entrada</th>
                                        <th>Hora salida</th>
                                    </tr>
                                </thead>
                                <tbody>
                                <?php foreach (DIAS_SEMANA as $diaNum => $diaNombre): ?>
                                    <tr>
                                        <td class="text-center">
                                            <input type="checkbox" class="form-check-input" name="dias[<?= $diaNum ?>][activo]" value="1">
                                        </td>
                                        <td><?= htmlspecialchars($diaNombre) ?></td>
                                        <td><input type="time" class="form-control" name="dias[<?= $diaNum ?>][entrada]"></td>
                                        <td><input type="time" class="form-control" name="dias[<?= $diaNum ?>][salida]"></td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                    <div class="col-lg-3">
                        <button type="submit" name="guardar" class="btn btn-primary w-100">Guardar turno</button>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <h2 class="text-center">Turnos Existentes</h2>
    <div class="table-responsive mt-3">
        <table class="table table-bordered table-hover align-middle">
            <thead class="table-dark">
                <tr>
                    <th>ID</th>
                    <th>Nombre</th>
                    <th>Horario</th>
                    <th class="text-center">Acciones</th>
                </tr>
            </thead>
            <tbody>
            <?php if ($turnos): ?>
                <?php foreach ($turnos as $turno): ?>
                    <?php $detalles = $detallesByTurno[$turno['id']] ?? []; ?>
                    <tr>
                        <td><?= $turno['id'] ?></td>
                        <td><?= htmlspecialchars($turno['nombre_turno']) ?></td>
                        <td>
                            <small><?= htmlspecialchars(resumen_detalles_turno($detalles)) ?></small>
                        </td>
                        <td class="text-center">
                            <button
                                type="button"
                                class="btn btn-warning btn-sm btn-editar-turno"
                                data-id="<?= $turno['id'] ?>"
                                data-nombre="<?= htmlspecialchars($turno['nombre_turno'], ENT_QUOTES) ?>"
                                data-detalles="<?= htmlspecialchars(json_encode(detalles_para_formulario($detalles), JSON_UNESCAPED_UNICODE), ENT_QUOTES) ?>"
                                data-bs-toggle="modal"
                                data-bs-target="#modalEditarTurno"
                            >
                                Editar
                            </button>
                            <form method="POST" class="d-inline" onsubmit="return confirm('¿Eliminar este turno?');">
                                <input type="hidden" name="id" value="<?= $turno['id'] ?>">
                                <button type="submit" name="eliminar" class="btn btn-danger btn-sm">Eliminar</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php else: ?>
                <tr>
                    <td colspan="4" class="text-center text-muted py-4">No hay turnos registrados.</td>
                </tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>

    <?php if ($total_pages > 1): ?>
    <nav class="mt-3">
        <ul class="pagination justify-content-center">
            <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>">
                <a class="page-link" href="?page=<?= $page - 1 ?>">«</a>
            </li>
            <?php for ($p = 1; $p <= $total_pages; $p++): ?>
            <li class="page-item <?= $p === $page ? 'active' : '' ?>">
                <a class="page-link" href="?page=<?= $p ?>"><?= $p ?></a>
            </li>
            <?php endfor; ?>
            <li class="page-item <?= $page >= $total_pages ? 'disabled' : '' ?>">
                <a class="page-link" href="?page=<?= $page + 1 ?>">»</a>
            </li>
        </ul>
        <p class="text-center text-muted small">Mostrando <?= $total ? ($offset + 1) : 0 ?>–<?= min($offset + $per_page, $total) ?> de <?= $total ?> registros</p>
    </nav>
    <?php endif; ?>
</main>

<div class="modal fade" id="modalEditarTurno" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-scrollable">
        <div class="modal-content">
            <form method="POST" id="formEditarTurno">
                <div class="modal-header">
                    <h5 class="modal-title">Editar turno</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="id" id="edit-id">
                    <div class="mb-3">
                        <label class="form-label">Nombre del turno</label>
                        <input type="text" class="form-control" name="nombre_turno" id="edit-nombre" required>
                    </div>

                    <div class="table-responsive">
                        <table class="table table-bordered align-middle">
                            <thead class="table-light">
                                <tr>
                                    <th>Aplica</th>
                                    <th>Dia</th>
                                    <th>Hora entrada</th>
                                    <th>Hora salida</th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php foreach (DIAS_SEMANA as $diaNum => $diaNombre): ?>
                                <tr>
                                    <td class="text-center">
                                        <input type="checkbox" class="form-check-input edit-activo" data-dia="<?= $diaNum ?>" name="dias[<?= $diaNum ?>][activo]" value="1">
                                    </td>
                                    <td><?= htmlspecialchars($diaNombre) ?></td>
                                    <td><input type="time" class="form-control edit-entrada" data-dia="<?= $diaNum ?>" name="dias[<?= $diaNum ?>][entrada]"></td>
                                    <td><input type="time" class="form-control edit-salida" data-dia="<?= $diaNum ?>" name="dias[<?= $diaNum ?>][salida]"></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" name="editar" class="btn btn-primary">Guardar cambios</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
document.querySelectorAll('.btn-editar-turno').forEach(function (btn) {
    btn.addEventListener('click', function () {
        document.getElementById('edit-id').value = btn.dataset.id || '';
        document.getElementById('edit-nombre').value = btn.dataset.nombre || '';

        document.querySelectorAll('#formEditarTurno .edit-activo').forEach(function (input) {
            input.checked = false;
        });
        document.querySelectorAll('#formEditarTurno .edit-entrada, #formEditarTurno .edit-salida').forEach(function (input) {
            input.value = '';
        });

        let detalles = {};
        try {
            detalles = JSON.parse(btn.dataset.detalles || '{}');
        } catch (e) {
            detalles = {};
        }

        Object.keys(detalles).forEach(function (dia) {
            const row = detalles[dia] || {};
            const chk = document.querySelector('#formEditarTurno .edit-activo[data-dia="' + dia + '"]');
            const ent = document.querySelector('#formEditarTurno .edit-entrada[data-dia="' + dia + '"]');
            const sal = document.querySelector('#formEditarTurno .edit-salida[data-dia="' + dia + '"]');

            if (chk) chk.checked = !!row.activo;
            if (ent) ent.value = row.entrada || '';
            if (sal) sal.value = row.salida || '';
        });
    });
});
</script>

<?php
include __DIR__ . '/../partials/footer.php';
sqlsrv_close($conn);
?>
