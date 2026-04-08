<?php
require_once __DIR__ . '/../_bootstrap.php';
$username = $_SESSION['nom_usu'];

// Consultas para llenar los combos
$queryCargo     = sqlsrv_query($conn, "SELECT id_cargo, cargo FROM [dbo].[Dota_Cargo]");
$queryEspecie   = sqlsrv_query($conn, "SELECT id_especie, especie FROM [dbo].[especie]");
$queryTemporada = sqlsrv_query($conn, "SELECT id_temporada, temporada FROM [dbo].[temporada]");
$queryTipoTarifa= sqlsrv_query($conn, "SELECT id_tipo, tipo_Tarifa FROM [dbo].[Dota_Tarifa_Especiales]");

// Agregar un nuevo registro
if (isset($_POST['guardar'])) {
    $sql = "INSERT INTO [dbo].[Dota_ValorEspecial_Dotacion]
            (cargo, valor, especie, temporada, tipo_tarifa, fecha, valor_HHEE)
            VALUES (?, ?, ?, ?, ?, ?, ?)";
    sqlsrv_query($conn, $sql, [
        $_POST['cargo'], $_POST['valor'], $_POST['especie'],
        $_POST['temporada'], $_POST['tipo_tarifa'], $_POST['fecha'], $_POST['valor_HHEE']
    ]);
}

// Editar un registro existente
if (isset($_POST['editar'])) {
    $sql = "UPDATE [dbo].[Dota_ValorEspecial_Dotacion]
            SET cargo = ?, valor = ?, especie = ?, temporada = ?, tipo_tarifa = ?, fecha = ?, valor_HHEE = ?
            WHERE id = ?";
    sqlsrv_query($conn, $sql, [
        $_POST['cargo'], $_POST['valor'], $_POST['especie'],
        $_POST['temporada'], $_POST['tipo_tarifa'], $_POST['fecha'], $_POST['valor_HHEE'],
        (int)$_POST['id']
    ]);
}

// Eliminar un registro
if (isset($_POST['eliminar'])) {
    sqlsrv_query($conn, "DELETE FROM [dbo].[Dota_ValorEspecial_Dotacion] WHERE id = ?", [(int)$_POST['id']]);
}

// Consultar los registros
$query = sqlsrv_query($conn, "SELECT [id],[cargo],[valor],[especie],[temporada],[tipo_tarifa],[fecha],[valor_HHEE]
                               FROM [dbo].[Dota_ValorEspecial_Dotacion]");

$title = "Valores Especiales Dotación";
include __DIR__ . '/../partials/header.php';
include __DIR__ . '/../partials/navbar_wrapper.php';
?>

<main class="container py-4">

    <div class="text-center my-4">
        <h5 class="text-muted">Usuario: <?= htmlspecialchars($username) ?></h5>
        <h1 class="display-4">Gestión de Valores Especiales Dotación</h1>
    </div>

    <!-- Formulario agregar -->
    <div class="card mb-4">
        <div class="card-header">Agregar Nuevo Valor Especial</div>
        <div class="card-body">
            <form method="POST">
                <div class="row g-3">
                    <div class="col-md-4">
                        <label class="form-label">Cargo</label>
                        <select class="form-control" name="cargo" required>
                            <option value="">Seleccionar Cargo</option>
                            <?php while ($r = sqlsrv_fetch_array($queryCargo, SQLSRV_FETCH_ASSOC)): ?>
                                <option value="<?= $r['id_cargo'] ?>"><?= htmlspecialchars($r['cargo']) ?></option>
                            <?php endwhile; ?>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Especie</label>
                        <select class="form-control" name="especie" required>
                            <option value="">Seleccionar Especie</option>
                            <?php while ($r = sqlsrv_fetch_array($queryEspecie, SQLSRV_FETCH_ASSOC)): ?>
                                <option value="<?= $r['id_especie'] ?>"><?= htmlspecialchars($r['especie']) ?></option>
                            <?php endwhile; ?>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Temporada</label>
                        <select class="form-control" name="temporada" required>
                            <option value="">Seleccionar Temporada</option>
                            <?php while ($r = sqlsrv_fetch_array($queryTemporada, SQLSRV_FETCH_ASSOC)): ?>
                                <option value="<?= $r['id_temporada'] ?>"><?= htmlspecialchars($r['temporada']) ?></option>
                            <?php endwhile; ?>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Tipo Tarifa</label>
                        <select class="form-control" name="tipo_tarifa" required>
                            <option value="">Seleccionar Tipo Tarifa</option>
                            <?php while ($r = sqlsrv_fetch_array($queryTipoTarifa, SQLSRV_FETCH_ASSOC)): ?>
                                <option value="<?= $r['id_tipo'] ?>"><?= htmlspecialchars($r['tipo_Tarifa']) ?></option>
                            <?php endwhile; ?>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">Valor</label>
                        <input type="number" class="form-control" name="valor" required>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">Valor HHEE</label>
                        <input type="number" class="form-control" name="valor_HHEE" required>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Fecha</label>
                        <input type="date" class="form-control" name="fecha" required>
                    </div>
                </div>
                <button type="submit" name="guardar" class="btn btn-primary mt-3">Guardar</button>
            </form>
        </div>
    </div>

    <!-- Tabla de registros -->
    <h2 class="text-center">Registros Existentes</h2>
    <div class="table-responsive mt-3">
        <table class="table table-bordered table-hover">
            <thead class="table-dark">
                <tr>
                    <th>ID</th><th>Cargo</th><th>Valor</th><th>Especie</th>
                    <th>Temporada</th><th>Tipo Tarifa</th><th>Fecha</th><th>Valor HHEE</th><th>Acciones</th>
                </tr>
            </thead>
            <tbody>
                <?php while ($row = sqlsrv_fetch_array($query, SQLSRV_FETCH_ASSOC)): ?>
                <tr>
                    <form method="POST">
                        <td><?= $row['id'] ?></td>
                        <td>
                            <select name="cargo" class="form-control">
                                <?php $q = sqlsrv_query($conn, "SELECT id_cargo, cargo FROM Dota_Cargo");
                                while ($r = sqlsrv_fetch_array($q, SQLSRV_FETCH_ASSOC)): ?>
                                    <option value="<?= $r['id_cargo'] ?>" <?= $r['id_cargo'] == $row['cargo'] ? 'selected' : '' ?>><?= htmlspecialchars($r['cargo']) ?></option>
                                <?php endwhile; ?>
                            </select>
                        </td>
                        <td><input type="number" name="valor" value="<?= $row['valor'] ?>" class="form-control"></td>
                        <td>
                            <select name="especie" class="form-control">
                                <?php $q = sqlsrv_query($conn, "SELECT id_especie, especie FROM especie");
                                while ($r = sqlsrv_fetch_array($q, SQLSRV_FETCH_ASSOC)): ?>
                                    <option value="<?= $r['id_especie'] ?>" <?= $r['id_especie'] == $row['especie'] ? 'selected' : '' ?>><?= htmlspecialchars($r['especie']) ?></option>
                                <?php endwhile; ?>
                            </select>
                        </td>
                        <td>
                            <select name="temporada" class="form-control">
                                <?php $q = sqlsrv_query($conn, "SELECT id_temporada, temporada FROM temporada");
                                while ($r = sqlsrv_fetch_array($q, SQLSRV_FETCH_ASSOC)): ?>
                                    <option value="<?= $r['id_temporada'] ?>" <?= $r['id_temporada'] == $row['temporada'] ? 'selected' : '' ?>><?= htmlspecialchars($r['temporada']) ?></option>
                                <?php endwhile; ?>
                            </select>
                        </td>
                        <td>
                            <select name="tipo_tarifa" class="form-control">
                                <?php $q = sqlsrv_query($conn, "SELECT id_tipo, tipo_Tarifa FROM Dota_Tarifa_Especiales");
                                while ($r = sqlsrv_fetch_array($q, SQLSRV_FETCH_ASSOC)): ?>
                                    <option value="<?= $r['id_tipo'] ?>" <?= $r['id_tipo'] == $row['tipo_tarifa'] ? 'selected' : '' ?>><?= htmlspecialchars($r['tipo_Tarifa']) ?></option>
                                <?php endwhile; ?>
                            </select>
                        </td>
                        <td><input type="date" name="fecha" value="<?= $row['fecha']->format('Y-m-d') ?>" class="form-control"></td>
                        <td><input type="number" name="valor_HHEE" value="<?= $row['valor_HHEE'] ?>" class="form-control"></td>
                        <td>
                            <input type="hidden" name="id" value="<?= $row['id'] ?>">
                            <button type="submit" name="editar" class="btn btn-warning btn-sm">Editar</button>
                            <button type="submit" name="eliminar" class="btn btn-danger btn-sm">Eliminar</button>
                        </td>
                    </form>
                </tr>
                <?php endwhile; ?>
            </tbody>
        </table>
    </div>

</main>

<?php
include __DIR__ . '/../partials/footer.php';
sqlsrv_close($conn);
?>
