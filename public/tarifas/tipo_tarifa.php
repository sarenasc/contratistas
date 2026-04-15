<?php
require_once __DIR__ . '/../_bootstrap.php';
require_once __DIR__ . '/../../app/lib/db.php';

function normalize_date_or_null($v) {
    $v = trim((string)$v);
    if ($v === '') return null;           // permite null
    // Esperamos YYYY-MM-DD (input type=date)
    $dt = DateTime::createFromFormat('Y-m-d', $v);
    if (!$dt) return null;                // si viene malo, lo dejamos null
    return $dt->format('Y-m-d');
}

function normalize_decimal_or_null($v) {
    $v = trim((string)$v);
    if ($v === '') return null;

    $v = str_replace(' ', '', $v);

    // Si tiene coma, asumimos formato chileno 1.234,56
    if (strpos($v, ',') !== false) {
        $v = str_replace('.', '', $v);   // quitar miles
        $v = str_replace(',', '.', $v);  // coma decimal → punto
    }

    return is_numeric($v) ? (float)$v : null;
}



$flash_error = null;
$flash_ok    = null;

//si se activa kilos, cajas = 0
//echo '<pre>'; print_r($_POST); echo '</pre>'; exit;
if (isset($_POST['toggle_kilo'])) {

    $id = (int)$_POST['id_tipo'];
    $nuevo = (int)$_POST['nuevo_estado'];

    $sql = "UPDATE dbo.Dota_Tipo_Tarifa
            SET kilo = ?, caja = ?
            WHERE id_tipo_Tarifa = ?";

    $kilo = $nuevo;
    $caja = ($nuevo === 1) ? 0 : 0; // si activa kilo, caja se apaga

    db_query($conn, $sql, [$kilo, $caja, $id], 'Toggle Kilo');
}

//si se activa caja, kilo = 0
if (isset($_POST['toggle_caja'])) {

    $id = (int)$_POST['id_tipo'];
    $nuevo = (int)$_POST['nuevo_estado'];

    $sql = "UPDATE dbo.Dota_Tipo_Tarifa
            SET caja = ?, kilo = ?
            WHERE id_tipo_Tarifa = ?";

    $caja = $nuevo;
    $kilo = ($nuevo === 1) ? 0 : 0;

    db_query($conn, $sql, [$caja, $kilo, $id], 'Toggle Caja');
}




if (isset($_POST['toggle_activa']) || isset($_POST['toggle_caja']) || isset($_POST['toggle_kilo'])) {

    $id = (int)($_POST['id_tipo'] ?? 0);
    $activar = (int)($_POST['nuevo_estado'] ?? 0);

    // Solo validar solapamiento al ACTIVAR una tarifa (no al toggle de kilo/caja)
    if (isset($_POST['toggle_activa']) && $activar === 1) {

        $sqlGet = "SELECT Tipo_Tarifa, fecha_desde, fecha_hasta,kilo,caja
                   FROM dbo.Dota_Tipo_Tarifa
                   WHERE id_tipo_Tarifa = ?";

        $stmtGet = db_query($conn, $sqlGet, [$id], 'GET tarifa para validar');

        
        $rowTarifa = sqlsrv_fetch_array($stmtGet, SQLSRV_FETCH_ASSOC);

        if (!$rowTarifa) {
                // No existe el id, o el SELECT no devolvió filas
                die("No se encontró la tarifa para validar (id_tipo_tarifa=$id).");
         }

        $tipo  = $rowTarifa['Tipo_Tarifa'];
        $desde = $rowTarifa['fecha_desde']->format('Y-m-d');
        $hasta = $rowTarifa['fecha_hasta']->format('Y-m-d');
        $kilos = (int)$rowTarifa['kilo'] ?? 0;
        $cajas = (int)$rowTarifa['caja'] ?? 0;


        $sqlCheck = "
            SELECT 1
            FROM dbo.Dota_Tipo_Tarifa
            WHERE Tipo_Tarifa = ?
              AND tarifa_activa = 1
              AND id_tipo_Tarifa <> ?
              AND fecha_desde <= CAST(? AS DATE)
              AND fecha_hasta >= CAST(? AS DATE)
        ";

        $stmtCheck = db_query($conn, $sqlCheck, [
            $tipo,
            $id,
            $hasta,
            $desde,
        ], 'CHECK overlap toggle');

        if (sqlsrv_fetch_array($stmtCheck)) {
            throw new RuntimeException("No se puede activar: existe otra tarifa activa que se cruza en fechas.");
        }
    }

    // Si pasa validación o es desactivación
    $sqlUpdate = "UPDATE dbo.Dota_Tipo_Tarifa
              SET tarifa_activa = ?
              WHERE id_tipo_Tarifa = ?";

    db_query($conn, $sqlUpdate, [$activar, $id], 'UPDATE toggle activa');
    header("Location: " . $_SERVER['REQUEST_URI']);
    exit;


}


    
    //guardar desde aqui
                    
                                try {

                                        if (isset($_POST['toggle_activa'])) {

                                            $id = (int)$_POST['id_tipo'];
                                            $activar = isset($_POST['tarifa_activa']) ? 1 : 0;

                                            // Si se está activando, validar solapamiento
                                            if ($activar === 1) {

                                                $sqlGet = "SELECT Tipo_Tarifa, fecha_desde, fecha_hasta
                                                        FROM dbo.Dota_Tipo_Tarifa
                                                        WHERE id_tipo_Tarifa = ?";

                                                $stmtGet = db_query($conn, $sqlGet, [$id], 'GET tarifa para validar');

                                                $rowTarifa = sqlsrv_fetch_array($stmtGet, SQLSRV_FETCH_ASSOC);

                                                $tipo  = $rowTarifa['Tipo_Tarifa'];
                                                $desde = $rowTarifa['fecha_desde']->format('Y-m-d');
                                                $hasta = $rowTarifa['fecha_hasta']->format('Y-m-d');

                                                $sqlCheck = "
                                                    SELECT 1
                                                    FROM dbo.Dota_Tipo_Tarifa
                                                    WHERE Tipo_Tarifa = ?
                                                    AND tarifa_activa = 1
                                                    AND id_tipo_Tarifa <> ?
                                                    AND fecha_desde <= CAST(? AS DATE)
                                                    AND fecha_hasta >= CAST(? AS DATE)
                                                ";

                                                $stmtCheck = db_query($conn, $sqlCheck, [
                                                    $tipo,
                                                    $id,
                                                    $hasta,
                                                    $desde,
                                                ], 'CHECK overlap toggle');

                                                if (sqlsrv_fetch_array($stmtCheck)) {
                                                    throw new RuntimeException("No se puede activar: existe otra tarifa activa que se cruza en fechas.");
                                                }
                                            }

                                            // Si pasa validación o es desactivación
                                            $sqlUpdate = "UPDATE dbo.Dota_Tipo_Tarifa
                                                        SET tarifa_activa = ?
                                                        WHERE id_tipo_Tarifa = ?";

                                            db_query($conn, $sqlUpdate, [$activar, $id], 'UPDATE toggle activa');
                                        }


                                            if (isset($_POST['guardar'])) {

                                                $id = (int)($_POST['id_tipo'] ?? 0);   
                                                $desde = normalize_date_or_null($_POST['desde'] ?? '');
                                                $hasta = normalize_date_or_null($_POST['hasta'] ?? '');
                                                $porcentaje = normalize_decimal_or_null($_POST['porcentaje'] ?? '');
                                                $por_hhee   = normalize_decimal_or_null($_POST['por_hhee'] ?? '');
                                                $kilo = $_POST['kilo'];
                                                $caja = $_POST['caja'];


                                                    if (!$desde || !$hasta) {
                                                    throw new RuntimeException("Debes ingresar Fecha desde y Fecha hasta.");
                                                    }

                                                    $tipo  = mb_strtoupper(trim($_POST['tipo_tarifa']), 'UTF-8');


                                                    $tarifa_activa = (int)($_POST['tarifa_activa']) ?? 0;

                                                    if ($tarifa_activa === 1) {
                                                        $sqlCheck = "
                                                            SELECT TOP 1 id_tipo_Tarifa
                                                            FROM dbo.Dota_Tipo_Tarifa
                                                            WHERE Tipo_Tarifa = ?
                                                            AND tarifa_activa = 1
                                                            AND fecha_desde <= ?
                                                            AND fecha_hasta >=  ?
                                                        ";

                                            // ojo: aquí la condición usa fecha_hasta y fecha_desde, por eso pasamos ($hasta, $desde)
                                                        $desdeObj = new DateTime($desde);
                                                        $hastaObj = new DateTime($hasta);

                                                        $paramsCheck = [
                                                            $tipo,
                                                            [$hastaObj, SQLSRV_PARAM_IN],
                                                            [$desdeObj, SQLSRV_PARAM_IN],
                                                        ];

                                                        $stmtCheck = db_query($conn, $sqlCheck, $paramsCheck, 'CHECK overlap toggle');

                                        

                                            if (sqlsrv_fetch_array($stmtCheck, SQLSRV_FETCH_ASSOC)) {
                                                throw new RuntimeException("Ya existe una tarifa ACTIVA para '$tipo' que se cruza con el rango de fechas ingresado.");
                                            }
                                        }


                                                $sql = "INSERT INTO [dbo].[Dota_Tipo_Tarifa]
                                                        ([Tipo_Tarifa],[ValorContratista],[PorcContrastista],[horasExtras],[fecha_desde],[fecha_hasta],[bono],[porc_hhee],[tarifa_activa],[kilo],[caja])
                                                        VALUES (?,?,?,?,convert(date,? ,23),convert(date,?,23),?,?,?,?,?)";

                                                $desde = normalize_date_or_null($_POST['desde'] ?? '');
                                                $hasta = normalize_date_or_null($_POST['hasta'] ?? '');
                                                $porcentaje = normalize_decimal_or_null($_POST['porcentaje'] ?? '');
                                                $por_hhee   = normalize_decimal_or_null($_POST['por_hhee'] ?? '');


                                                $params = [
                                                    $_POST['tipo_tarifa'],
                                                    $_POST['valor_base'],
                                                    $porcentaje,
                                                    $_POST['hhee'],
                                                    $desde,
                                                    $hasta,
                                                    $_POST['bono'],
                                                    $por_hhee,
                                                    $tarifa_activa,
                                                    $kilo,
                                                    $caja
                                                ];

                                                db_query($conn, $sql, $params, 'INSERT Dota_Tipo_Tarifa');
                                                $flash_ok = "Registro guardado correctamente.";
                                            }

                                            // tu SELECT también con helper
                                            $query = db_query($conn,
                                                "SELECT [id_tipo_Tarifa],[Tipo_Tarifa],[ValorContratista],[PorcContrastista],[horasExtras],[fecha_desde],[fecha_hasta],[bono],[porc_hhee],[kilo],[caja]
                                                FROM [dbo].[Dota_Tipo_Tarifa]",
                                                [],
                                                'SELECT Dota_Tipo_Tarifa'
                                            );

                                         } catch (Throwable $e) {
                                            /*echo "<pre>";
                                            print_r($_POST);
                                            echo "</pre>";
                                            exit;*/

                                            $flash_error = $e->getMessage();
                                        }



                                         //aqui termina el guardar





// Eliminar un registro
if (isset($_POST['eliminar'])) {
    $id_tipo = $_POST['id_tipo'];
    $sql = "DELETE FROM [dbo].[Dota_Tipo_Tarifa] WHERE id_tipo_Tarifa = $id_tipo";
    sqlsrv_query($conn, $sql);
}


            //// aqui empieza el update
               
               
                            if (isset($_POST['editar'])) {
                                 

                                $id = (int)($_POST['id_tipo'] ?? 0);
                                if ($id <= 0) {
                                    throw new RuntimeException("ID inválido para editar.");
                                }

                                // === 1) Leer valores enviados desde la fila ===
                                $tipo       = mb_strtoupper(trim($_POST["tipo_tarifa_$id"] ?? ''), 'UTF-8');
                                $valor_base = $_POST["valor_base_$id"] ?? '';
                                $bono       = $_POST["bono_$id"] ?? '';
                                $hhee       = $_POST["hhee_$id"] ?? '';

                                $desde_raw = $_POST["fecha_desde_$id"] ?? '';
                                $hasta_raw = $_POST["fecha_hasta_$id"] ?? '';

                                $desde = normalize_date_or_null($desde_raw);
                                $hasta = normalize_date_or_null($hasta_raw);

                                $porcentaje = normalize_decimal_or_null($_POST["porcentaje_$id"] ?? '');
                                $por_hhee   = normalize_decimal_or_null($_POST["porc_hhee_$id"] ?? '');



                                if (!$desde || !$hasta) {
                                    throw new RuntimeException("Fechas inválidas en la fila ID $id.");
                                }

                                // === 2) Verificar si esta tarifa está activa ===
                                $stmtEstado = db_query(
                                    $conn,
                                    "SELECT tarifa_activa FROM dbo.Dota_Tipo_Tarifa WHERE id_tipo_Tarifa = ?",
                                    [$id],
                                    "GET tarifa_activa (update)"
                                );

                                $rowEstado = sqlsrv_fetch_array($stmtEstado, SQLSRV_FETCH_ASSOC);
                                $activa = (int)($rowEstado['tarifa_activa'] ?? 0);

                                // === 3) Si está activa, validar que no se cruce con otra activa ===
                                if ($activa === 1) {

                                    $sqlCheck = "
                                        SELECT TOP 1 id_tipo_Tarifa
                                        FROM dbo.Dota_Tipo_Tarifa
                                        WHERE Tipo_Tarifa = ?
                                        AND tarifa_activa = 1
                                        AND id_tipo_Tarifa <> ?
                                        AND fecha_desde <= ?
                                        AND fecha_hasta >= ?
                                    ";

                                    $stmtCheck = db_query(
                                        $conn,
                                        $sqlCheck,
                                        [
                                            $tipo,
                                            $id,
                                            new DateTime($hasta),
                                            new DateTime($desde)
                                        ],
                                        "CHECK overlap update"
                                    );

                                    if (sqlsrv_fetch_array($stmtCheck, SQLSRV_FETCH_ASSOC)) {
                                        throw new RuntimeException(
                                            "No se puede editar: al estar ACTIVA, se cruza con otra tarifa activa del mismo tipo."
                                        );
                                    }
                                }

                                // === 4) UPDATE ===
                                $sqlUpdate = "
                                    UPDATE dbo.Dota_Tipo_Tarifa
                                    SET Tipo_Tarifa       = ?,
                                        ValorContratista  = ?,
                                        PorcContrastista  = ?,
                                        porc_hhee         = ?,
                                        bono              = ?,
                                        horasExtras       = ?,
                                        fecha_desde       = ?,
                                        fecha_hasta       = ?
                                    WHERE id_tipo_Tarifa = ?
                                ";

                                $paramsUpdate = [
                                    $tipo,
                                    $valor_base,
                                    $porcentaje,
                                    $por_hhee,
                                    $bono,
                                    $hhee,
                                    new DateTime($desde),
                                    new DateTime($hasta),
                                    $id
                                ];

                                db_query($conn, $sqlUpdate, $paramsUpdate, "UPDATE Dota_Tipo_Tarifa");

                                $flash_ok = "Registro ID $id actualizado correctamente.";

                                // Evitar reenvío de formulario
                                header("Location: " . $_SERVER['REQUEST_URI']);
                                exit;
                            }





            //// aca termina


// Consultar los registros
$sql = "SELECT [id_tipo_Tarifa], [Tipo_Tarifa],[ValorContratista],[PorcContrastista],[horasExtras],[fecha_desde],[fecha_hasta],[bono],[porc_hhee],[tarifa_activa],[caja],[kilo] 
FROM [dbo].[Dota_Tipo_Tarifa] ORDER BY [id_tipo_Tarifa] ASC" ;
$query = sqlsrv_query($conn, $sql);


$title = "Tipo Tarifas";
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



  <div class="row justify-content-center">
    <div class="col-12 col-lg-10 col-xl-8">

                <h1 class="text-center mb-4">Gestión de <?= $title ?></title></h1>


                <div class="card mb-4">
                    <div class="card-header">Agregar Nuevo Tipo Tarifa                        
                    </div>
                        <div class="card-body">
                            <form method="POST">
                                <?= csrf_field() ?>
                                                               <!--  2 campos -->
                                            <div class="row g-3">
                                                <div class="form-group col-12 col-md-6">
                                                <label>Tipo Tarifa</label>
                                                <input type="text" class="form-control" name="tipo_tarifa" required>
                                                </div>

                                                <div class="form-group col-12 col-md-6">
                                                <label>Valor Base</label>
                                                <input type="text" class="form-control" name="valor_base" required>
                                                </div>
                                            </div>
                                            <div class="row g-3">
                                                <div class="form-group col-12 col-md-6">
                                                <label>Porcentaje contratista del valor base</label>
                                                <input type="text" class="form-control" name="porcentaje" required>
                                                </div>
                                                <div class="form-group col-12 col-md-6">
                                                <label>Porcentaje contratista de HHEE</label>
                                                <input type="text" class="form-control" name="por_hhee" required>
                                                </div>
                                            </div>

                                            <div class = "row g-3">
                                                <div class="form-group col-12 col-md-6">
                                                <label>Bono</label>
                                                <input type="text" class="form-control" name="bono" required>
                                                </div>

                                                <div class="form-group col-12 col-md-6">
                                                <label>Valor Hora Extra</label>
                                                <input type="text" class="form-control" name="hhee" required>
                                                </div>
                                            </div>
                                            
                                            <div class="row g-3">
                                                <div class="form-group col-12 col-md-6">
                                                <label>Fecha desde</label>
                                                <input type="date" class="form-control" name="desde" required>
                                                </div>

                                                <div class="form-group col-12 col-md-6">
                                                <label>Fecha hasta</label>
                                                <input type="date" class="form-control" name="hasta" required>
                                                </div>
                                            </div>
                                            <div class="row g-3">
                                                <div class="form-group col-12 col-md-4">
                                                <div class="form-check mt-2">
                                                <input type="hidden" name="tarifa_activa" value="0">
                                                <input class="form-check-input" type="checkbox" value="1" id="tarifa_activa" name="tarifa_activa" checked>
                                                <label class="form-check-label" for="tarifa_activa">
                                                    Tarifa activa
                                                </label>
                                                </div>
                                                </div>
                                                <!-- Calculo de tarifas por kilos, para sacar unitario -->
                                                <div class="form-group col-12 col-md-4">
                                                <div class="form-check mt-2">
                                                <input type="hidden" name="kilo" value="0">
                                                <input class="form-check-input" type="checkbox" value="1" id="kilo" name="kilo" checked onchange="toggleCalculo('kilos')" checked>
                                                <label class="form-check-label" for="kilos">
                                                    Calculo Kilos
                                                </label>
                                                </div>
                                                </div>
                                                <div class="form-group col-12 col-md-4">
                                                <div class="form-check mt-2">
                                                <input type="hidden" name="caja" value="0">
                                                <input class="form-check-input" type="checkbox" value="1" id="cajas" name="caja" onchange="toggleCalculo('cajas')">
                                                <label class="form-check-label" for="cajas">
                                                    Calculo Cajas
                                                </label>
                                                </div>
                                                </div>
                                            </div>

                                            <button type="submit" name="guardar" class="btn btn-primary">Guardar</button>
                            </form>

                           
                        </div>
                    
                </div>

            

                                <!-- Tabla de registros -->
                                <div class="card shadow-sm">
                                    <div class="card-body">
                                        <h2 class="text-center mb-3">Registros Existentes</h2>

                                        <div class="table-responsive">
                                        <table class="table table-sm table-bordered table-hover mb-0" style="min-width: 1200px;">
                                            <thead class="thead-dark">
                                            <tr>
                                                <th style="width:70px;">ID</th>
                                                <th>Tipo Tarifa</th>
                                                <th>Valor Base</th>
                                                <th>% Contratista</th>
                                                <th>% HHEE</th>
                                                <th>Bono</th>
                                                <th>Valor Hora Extra</th>
                                                <th>Fecha Desde</th>
                                                <th>Fecha Hasta</th>
                                                <th>Estado Tarifa</th>
                                                <th>Calculo Kilo</th>
                                                <th>Calculo Caja</th>
                                                <th style="width:180px;">Acciones</th>
                                            </tr>
                                            </thead>

                                            <tbody>
                                            <?php
                                            // Formatea decimales con cero inicial y sin ceros finales
                                            $fmtDec = fn($v) => rtrim(rtrim(number_format((float)($v ?? 0), 4, '.', ''), '0'), '.');
                                            while ($row = sqlsrv_fetch_array($query, SQLSRV_FETCH_ASSOC)) { ?>
                                             <tr>
                                                <?php $id = (int)$row['id_tipo_Tarifa']; ?>
                                                <!-- ID -->
                                                <td><?= $id ?></td>

                                                <!-- Campos editables (sin form aquí) -->
                                                <td><input class="form-control form-control-sm" name="tipo_tarifa_<?= $id ?>"
                                                    value="<?= htmlspecialchars((string)$row['Tipo_Tarifa']) ?>" form="form_edit_<?= $id ?>"></td>

                                                <td><input class="form-control form-control-sm" name="valor_base_<?= $id ?>"
                                                    value="<?= $fmtDec($row['ValorContratista']) ?>" form="form_edit_<?= $id ?>"></td>

                                                <td><input class="form-control form-control-sm" name="porcentaje_<?= $id ?>"
                                                    value="<?= $fmtDec($row['PorcContrastista']) ?>" form="form_edit_<?= $id ?>"></td>

                                                <td><input class="form-control form-control-sm" name="porc_hhee_<?= $id ?>"
                                                    value="<?= $fmtDec($row['porc_hhee']) ?>" form="form_edit_<?= $id ?>"></td>

                                                <td><input class="form-control form-control-sm" name="bono_<?= $id ?>"
                                                    value="<?= $fmtDec($row['bono']) ?>" form="form_edit_<?= $id ?>"></td>

                                                <td><input class="form-control form-control-sm" name="hhee_<?= $id ?>"
                                                    value="<?= $fmtDec($row['horasExtras']) ?>" form="form_edit_<?= $id ?>"></td>

                                                <td>
                                                        <input type="date"
                                                                class="form-control form-control-sm"
                                                                name="fecha_desde_<?= $id ?>"
                                                                value="<?= htmlspecialchars(is_object($row['fecha_desde']) ? $row['fecha_desde']->format('Y-m-d') : (string)$row['fecha_desde']); ?>"
                                                                form="form_edit_<?= $id ?>">
                                                        </td>

                                                        <td>
                                                        <input type="date"
                                                                class="form-control form-control-sm"
                                                                name="fecha_hasta_<?= $id ?>"
                                                                value="<?= htmlspecialchars(is_object($row['fecha_hasta']) ? $row['fecha_hasta']->format('Y-m-d') : (string)$row['fecha_hasta']); ?>"
                                                                form="form_edit_<?= $id ?>">
                                                        </td>

                                                <!-- TARIFA ACTIVA -->
                                                <td class="text-center">
                                                        <form method="POST" action="tipo_tarifa.php">
                                                            <?= csrf_field() ?>
                                                            <input type="hidden" name="id_tipo" value="<?= (int)$row['id_tipo_Tarifa']; ?>">
                                                            <input type="hidden" name="toggle_activa" value="1">
                                                            <input type="hidden" name="nuevo_estado" value="<?= ((int)($row['tarifa_activa'] ?? 0) === 1) ? 1 : 0 ?>">

                                                            <input type="checkbox"
                                                                <?= ((int)($row['tarifa_activa'] ?? 0) === 1) ? 'checked' : '' ?>
                                                                onchange="this.form.nuevo_estado.value = this.checked ? 1 : 0; this.form.submit();">
                                                        </form>
                                                </td>
                                                <!-- CALCULO POR KILO -->
                                                <td class="text-center">
                                                        <form method="POST" action="tipo_tarifa.php">
                                                            <?= csrf_field() ?>
                                                            <input type="hidden" name="id_tipo" value="<?= (int)$row['id_tipo_Tarifa'] ?>">
                                                            <input type="hidden" name="toggle_kilo" value="1">
                                                            <input type="hidden" name="nuevo_estado" value="0">

                                                            <input type="checkbox"
                                                                <?= ((int)($row['kilo'] ?? 0) === 1) ? 'checked' : '' ?>
                                                                onchange="this.form.nuevo_estado.value = this.checked ? 1 : 0; this.form.submit();">
                                                        </form>
                                                </td>
                                                <!-- CALCULO POR CAJA -->
                                                <td class="text-center">
                                                        <form method="POST">
                                                            <?= csrf_field() ?>
                                                            <input type="hidden" name="id_tipo" value="<?= (int)$row['id_tipo_Tarifa'] ?>">
                                                            <input type="hidden" name="toggle_caja" value="1">
                                                            <input type="hidden" name="nuevo_estado" value="0">

                                                            <input type="checkbox"
                                                                <?= ((int)($row['caja'] ?? 0) === 1) ? 'checked' : '' ?>
                                                                onchange="this.form.nuevo_estado.value = this.checked ? 1 : 0; this.form.submit();">
                                                        </form>

                                                </td>


                                                <!-- Acciones (form separado) -->
                                                <td class="text-nowrap">
                                                <form method="POST" id="form_edit_<?= $id ?>">
                                                    <?= csrf_field() ?>
                                                    <input type="hidden" name="id_tipo" value="<?= $id ?>">
                                                    <input type="hidden" name="editar" value="1">
                                                    <button class="btn btn-warning btn-sm" type="submit">Editar</button>
                                                </form>

                                                <form method="POST" style="display:inline;">
                                                    <?= csrf_field() ?>
                                                    <input type="hidden" name="id_tipo" value="<?= (int)$row['id_tipo_Tarifa']; ?>">
                                                    <button class="btn btn-danger btn-sm" type="submit" name="eliminar"
                                                            onclick="return confirm('¿Eliminar este registro?')">Eliminar</button>
                                                </form>
                                                </td>

                                            </tr>
                                            <?php } ?>

                                            </tbody>

                                        </table>
                                        </div>
                                        </div>
                                    </div>


        </div>
    </div>
</main>

<script>
  function toggleCalculo(origen) {
    const chkKilos = document.getElementById('kilo');
    const chkCajas = document.getElementById('cajas');

    if (origen === 'kilos' && chkKilos && chkKilos.checked) {
      if (chkCajas) chkCajas.checked = false;
    }

    if (origen === 'cajas' && chkCajas && chkCajas.checked) {
      if (chkKilos) chkKilos.checked = false;
    }

    if (chkKilos && chkCajas && !chkKilos.checked && !chkCajas.checked) {
      if (origen === 'kilos') chkKilos.checked = true;
      if (origen === 'cajas') chkCajas.checked = true;
    }
  }

  document.addEventListener('DOMContentLoaded', function () {
    const chkKilos = document.getElementById('kilo');
    const chkCajas = document.getElementById('cajas');

    if (!chkKilos || !chkCajas) return; // elementos opcionales en la página
    if (chkKilos.checked && chkCajas.checked) {
      chkCajas.checked = false;
    }
    if (!chkKilos.checked && !chkCajas.checked) {
      chkKilos.checked = true;
    }
  });
</script>


<?php include __DIR__ . '/../partials/footer.php';


// Cerrar la conexión
sqlsrv_close($conn);
?>
