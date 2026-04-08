<?php
// Bootstrap para sesión, auth y partials (BASE_URL, header, navbar)
require_once __DIR__ . '/../_bootstrap.php';
// Conexión a BD Reloj (sobreescribe $conn con la BD ATT2000)
require_once __DIR__ . '/../../conexion_Reloj.php';

$username = $_SESSION['nom_usu'];

if (isset($_POST['filtrar'])) {
    $start_date = $_POST['start_date'];
    $end_date   = $_POST['end_date'];

    $sql  = "SELECT [USERID],[CHECKTIME],[Fecha],[Hora],[Name],[CHECKTYPE]
             FROM RegistoHora
             WHERE Fecha BETWEEN CONVERT(date, ?, 102) AND CONVERT(date, ?, 102)";
    $stmt = sqlsrv_query($conn, $sql, [$start_date, $end_date]);

    if ($stmt === false) {
        error_log('Error proceso_edicion_turnos: ' . print_r(sqlsrv_errors(), true));
        $stmt = null;
    }
} else {
    $stmt = null;
}

$rows = $stmt ? sqlsrv_has_rows($stmt) : false;

$title = "Registros de Horarios";
include __DIR__ . '/../partials/header.php';
include __DIR__ . '/../partials/navbar_wrapper.php';
?>

<main class="container py-4">

    <h1 class="mb-4">Registros de Horarios</h1>

    <?php if ($rows === true): ?>
    <div class="table-responsive">
        <table class="table table-striped table-hover table-bordered">
            <thead class="table-dark">
                <tr>
                    <th>ID</th>
                    <th>Nombre</th>
                    <th>Fecha</th>
                    <th>Hora</th>
                    <th>Tipo</th>
                    <th>Editar</th>
                </tr>
            </thead>
            <tbody>
            <?php while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)):
                $formattedDateFull = $row['CHECKTIME'] instanceof DateTime ? $row['CHECKTIME']->format('Y-m-d H:i:s') : '';
                $formattedDate     = $row['Fecha']     instanceof DateTime ? $row['Fecha']->format('Y-m-d')           : '';
                $formattedHour     = $row['Hora']      instanceof DateTime ? $row['Hora']->format('H:i:s')            : '';
                $tipo = $row['CHECKTYPE'] === 'I' ? 'Entrada' : ($row['CHECKTYPE'] === 'O' ? 'Salida' : $row['CHECKTYPE']);
            ?>
                <tr>
                    <td><?= htmlspecialchars($row['USERID']) ?></td>
                    <td><?= htmlspecialchars($row['Name']) ?></td>
                    <td><?= htmlspecialchars($formattedDate) ?></td>
                    <td><?= htmlspecialchars($formattedHour) ?></td>
                    <td><?= htmlspecialchars($tipo) ?></td>
                    <td>
                        <form action="editar_turno.php" method="post" class="d-inline">
                            <input type="hidden" name="id"        value="<?= htmlspecialchars($row['USERID']) ?>">
                            <input type="hidden" name="checkTime" value="<?= htmlspecialchars($formattedDateFull) ?>">
                            <button type="submit" class="btn btn-sm btn-primary" name="btnid">Editar</button>
                        </form>
                    </td>
                </tr>
            <?php endwhile; ?>
            </tbody>
        </table>
    </div>
    <?php else: ?>
    <div class="alert alert-warning">No se encontraron registros en el rango de fechas seleccionado.</div>
    <?php endif; ?>

</main>

<?php
include __DIR__ . '/../partials/footer.php';
sqlsrv_close($conn);
?>
