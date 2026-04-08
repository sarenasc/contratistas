<?php
require_once __DIR__ . '/../_bootstrap.php';
require_once __DIR__ . '/../../app/lib/db.php';



$flash_error = null;
$flash_ok    = null;
$title = "Tarifas Especiales";
include __DIR__ . '/../partials/header.php';
include __DIR__ . '/../partials/navbar_wrapper.php';
?>

<main class="container-fluid py-4 px-3">

<?php if ($flash_error): ?>
  <div class="alert alert-danger"><?= htmlspecialchars($flash_error) ?></div>
<?php endif; ?>

<?php if ($flash_ok): ?>
  <div class="alert alert-success"><?= htmlspecialchars($flash_ok) ?></div>
<?php endif; ?>
?>



    <!-- Formulario para seleccionar fechas -->
    <h2 class="text-center mt-4">Filtrar Registros entre Fechas</h2>
    <div class="container mt-4">
        <form method="post" action="proceso_edicion_turnos.php">
            <div class="form-row">
                <div class="form-group col-md-5">
                    <label for="start_date">Fecha de Inicio:</label>
                    <input type="date" class="form-control" name="start_date" required value="<?php echo isset($start_date) ? htmlspecialchars($start_date) : ''; ?>">
                </div>
                <div class="form-group col-md-5">
                    <label for="end_date">Fecha de Fin:</label>
                    <input type="date" class="form-control" name="end_date" required value="<?php echo isset($end_date) ? htmlspecialchars($end_date) : ''; ?>">
                </div>
                <div class="form-group col-md-2 d-flex align-items-end">
                    <button type="submit" name="filtrar" class="btn btn-primary btn-block">Filtrar</button>
                </div>
            </div>
        </form>
    </div>
</div>


<?php
include __DIR__ . '/../partials/footer.php';

// Cerrar la conexión
sqlsrv_close($conn);
?>