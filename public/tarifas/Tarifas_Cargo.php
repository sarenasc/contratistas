<?php
require_once __DIR__ . '/../_bootstrap.php';
require_once __DIR__ . '/../../app/lib/db.php';
require_once __DIR__ . '/../../vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\IOFactory;

$username = $_SESSION['nom_usu'];

if (!$conn) {
    $flash_error = "Error de conexión a la base de datos. Contacte al administrador.";
}

// Consultas para llenar los combobox
$queryCargo       = sqlsrv_query($conn, "SELECT id_cargo, cargo FROM [dbo].[Dota_cargo] ORDER BY cargo");
$queryTipoTarifa  = sqlsrv_query($conn, "SELECT id_tipo_tarifa, Tipo_tarifa FROM [dbo].[Dota_tipo_tarifa] WHERE tarifa_activa = 1 ORDER BY Tipo_tarifa");
$queryEspecie     = sqlsrv_query($conn, "SELECT id_especie, especie FROM dbo.especie ORDER BY especie");
$queryContratista = sqlsrv_query($conn, "SELECT id, nombre FROM dbo.dota_contratista ORDER BY nombre");


// Agregar un nuevo registro
if (isset($_POST['guardar'])) {


    $id_tipo_tarifa  = (int)($_POST['tipo_tarifa']    ?? 0);
    $cargos          = $_POST['cargo']                ?? [];
    $especie         = $_POST['especie']              ?? '';
    $id_contratista  = (int)($_POST['id_contratista'] ?? 0); // 0 = todos

    if ($id_tipo_tarifa <= 0) die("Debe seleccionar un tipo de tarifa.");
    if (!is_array($cargos) || count($cargos) === 0) die("Debe seleccionar al menos un cargo.");

    $especie_val      = $especie !== '' ? (int)$especie : null;
    $contratista_val  = $id_contratista > 0 ? $id_contratista : null;

    $sql = "INSERT INTO dbo.Dota_Valor_Dotacion (id_cargo, id_tipo_tarifa, id_especie, id_contratista)
            VALUES (?, ?, ?, ?)";

    foreach ($cargos as $id_cargo) {
        $id_cargo = (int)$id_cargo;

        // Evitar duplicados considerando contratista
        $sqlCheck = "SELECT 1 FROM dbo.Dota_Valor_Dotacion
                     WHERE id_tipo_tarifa = ? AND id_cargo = ?
                       AND ISNULL(id_especie,    -1) = ISNULL(?, -1)
                       AND ISNULL(id_contratista,-1) = ISNULL(?, -1)";
        $stmtCheck = db_query($conn, $sqlCheck, [$id_tipo_tarifa, $id_cargo, $especie_val, $contratista_val], "CHECK vínculo");

        if (!sqlsrv_fetch_array($stmtCheck, SQLSRV_FETCH_ASSOC)) {
            db_query($conn, $sql, [$id_cargo, $id_tipo_tarifa, $especie_val, $contratista_val], "INSERT vínculo");
        }
    }

    $msg_ok = "Tarifas Asignadas correctamente.";
}

    


// Editar un registro existente
if (isset($_POST['editar'])) {
    $id              = (int)$_POST['id_tipo'];
    $cargo           = (int)$_POST['cargo'];
    $tipo_tarifa     = (int)$_POST['tipo_tarifa'];
    $especie         = $_POST['especie'] !== '' ? (int)$_POST['especie'] : null;
    $id_contratista  = (int)($_POST['id_contratista'] ?? 0);
    $contratista_val = $id_contratista > 0 ? $id_contratista : null;

    $sql = "UPDATE dbo.Dota_Valor_Dotacion SET id_cargo = ?, id_especie = ?, id_tipo_tarifa = ?, id_contratista = ? WHERE id = ?";
    sqlsrv_query($conn, $sql, [$cargo, $especie, $tipo_tarifa, $contratista_val, $id]);
}

// Eliminar un registro
if (isset($_POST['eliminar'])) {
    $id_tipo = (int)$_POST['id_tipo'];
    $sql     = "DELETE FROM [dbo].[Dota_Valor_Dotacion] WHERE id = ?";
    sqlsrv_query($conn, $sql, [$id_tipo]);
}

// ── Carga masiva desde Excel ─────────────────────────────────────────────────
$excel_insertados = 0;
$excel_duplicados = 0;
$excel_sin_match  = [];
$excel_flash_ok   = null;
$excel_flash_err  = null;

if (isset($_POST['cargar_excel']) && isset($_FILES['archivo_excel']) && $_FILES['archivo_excel']['error'] === UPLOAD_ERR_OK) {
    try {
        $tmp = $_FILES['archivo_excel']['tmp_name'];
        $ext = strtolower(pathinfo($_FILES['archivo_excel']['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, ['xlsx','xls'], true))
            throw new RuntimeException("Formato no permitido. Use .xlsx o .xls");

        $reader   = IOFactory::createReaderForFile($tmp);
        $reader->setReadDataOnly(true);
        $wsNames  = $reader->listWorksheetNames($tmp);
        $sheetName = in_array('Tarifas', $wsNames, true) ? 'Tarifas' : null;
        if (!$sheetName)
            throw new RuntimeException("No se encontró la hoja 'Tarifas'. Hojas disponibles: " . implode(', ', $wsNames));
        $reader->setLoadSheetsOnly([$sheetName]);

        $ss   = $reader->load($tmp);
        $rows = $ss->getActiveSheet()->toArray(null, true, false, false);
        $ss->disconnectWorksheets(); unset($ss);

        if (empty($rows)) throw new RuntimeException("La hoja 'Tarifas' está vacía.");

        // Detectar encabezados (normalizado: sin espacios extra, minúsculas)
        $headers  = array_map(fn($h) => preg_replace('/\s+/', ' ', strtolower(trim((string)$h))), $rows[0]);
        $iLabor   = array_search('labor',      $headers);
        $iTarifa  = array_search('tipo tarifa', $headers);
        $iEspecie = array_search('especie',    $headers);

        if ($iLabor === false || $iTarifa === false)
            throw new RuntimeException("Faltan columnas. Necesita 'Labor' y 'Tipo Tarifa'. Detectadas: " . implode(', ', $headers));

        // Pre-cargar catálogos en mapas UPPER → id
        $mapCargo = [];
        $q = sqlsrv_query($conn, "SELECT id_cargo, cargo FROM dbo.Dota_Cargo");
        while ($r = sqlsrv_fetch_array($q, SQLSRV_FETCH_ASSOC))
            $mapCargo[mb_strtoupper(trim($r['cargo']), 'UTF-8')] = (int)$r['id_cargo'];

        $mapTarifa = [];
        $q = sqlsrv_query($conn, "SELECT id_tipo_Tarifa, Tipo_Tarifa FROM dbo.Dota_Tipo_Tarifa");
        while ($r = sqlsrv_fetch_array($q, SQLSRV_FETCH_ASSOC))
            $mapTarifa[mb_strtoupper(trim($r['Tipo_Tarifa']), 'UTF-8')] = (int)$r['id_tipo_Tarifa'];

        $mapEspecie = [];
        $q = sqlsrv_query($conn, "SELECT id_especie, especie FROM dbo.especie");
        if ($q) while ($r = sqlsrv_fetch_array($q, SQLSRV_FETCH_ASSOC))
            $mapEspecie[mb_strtoupper(trim($r['especie']), 'UTF-8')] = (int)$r['id_especie'];

        // Columna opcional Contratista
        $iContratista = array_search('contratista', $headers);

        $mapContratista = [];
        $q = sqlsrv_query($conn, "SELECT id, nombre FROM dbo.dota_contratista");
        if ($q) while ($r = sqlsrv_fetch_array($q, SQLSRV_FETCH_ASSOC))
            $mapContratista[mb_strtoupper(trim($r['nombre']), 'UTF-8')] = (int)$r['id'];

        $sql_ins = "INSERT INTO dbo.Dota_Valor_Dotacion (id_cargo, id_tipo_tarifa, id_especie, id_contratista) VALUES (?,?,?,?)";
        $sql_chk = "SELECT 1 FROM dbo.Dota_Valor_Dotacion
                    WHERE id_cargo=? AND id_tipo_tarifa=?
                      AND ISNULL(id_especie,-1)     = ISNULL(?,-1)
                      AND ISNULL(id_contratista,-1) = ISNULL(?,-1)";

        foreach (array_slice($rows, 1) as $row) {
            $labor  = mb_strtoupper(trim((string)($row[$iLabor]  ?? '')), 'UTF-8');
            $tarifa = mb_strtoupper(trim((string)($row[$iTarifa] ?? '')), 'UTF-8');
            $espRaw = $iEspecie      !== false ? trim((string)($row[$iEspecie]      ?? '')) : '';
            $conRaw = $iContratista  !== false ? trim((string)($row[$iContratista]  ?? '')) : '';

            if ($labor === '' && $tarifa === '') continue; // fila vacía

            $id_cargo  = $mapCargo[$labor]   ?? null;
            $id_tarifa = $mapTarifa[$tarifa]  ?? null;

            if ($id_cargo === null || $id_tarifa === null) {
                $excel_sin_match[] = [
                    'labor'      => $labor,
                    'tarifa'     => $tarifa,
                    'sin_cargo'  => $id_cargo  === null,
                    'sin_tarifa' => $id_tarifa === null,
                ];
                continue;
            }

            $id_especie     = $espRaw !== '' ? ($mapEspecie[mb_strtoupper($espRaw, 'UTF-8')] ?? null) : null;
            $id_contratista = $conRaw !== '' ? ($mapContratista[mb_strtoupper($conRaw, 'UTF-8')] ?? null) : null;

            // Evitar duplicados
            $chk = sqlsrv_query($conn, $sql_chk, [$id_cargo, $id_tarifa, $id_especie, $id_contratista]);
            if ($chk && sqlsrv_fetch($chk)) { $excel_duplicados++; continue; }

            db_query($conn, $sql_ins, [$id_cargo, $id_tarifa, $id_especie, $id_contratista], "INSERT Excel tarifa");
            $excel_insertados++;
        }

        $excel_flash_ok = "Carga completada: <b>{$excel_insertados}</b> insertados"
            . ($excel_duplicados ? ", <b>{$excel_duplicados}</b> duplicados omitidos" : "")
            . (count($excel_sin_match) ? ", <b>" . count($excel_sin_match) . "</b> sin coincidencia (ver tabla abajo)" : ".");

    } catch (Throwable $e) {
        $excel_flash_err = "Error al procesar Excel: " . htmlspecialchars($e->getMessage());
    }
}
// ─────────────────────────────────────────────────────────────────────────────

// Consultar los registros
$sql = "SELECT
  C.cargo AS cargo_nombre,
  T.Tipo_Tarifa AS tarifa_nombre,
  T.ValorContratista,
  T.horasExtras,
  T.PorcContrastista,
  T.porc_hhee,
  T.bono,
  (T.ValorContratista * (T.PorcContrastista+1)) as ValorAContratista,
  (T.horasExtras * (porc_hhee+1)) as hheeAContratista,
  T.fecha_desde,
  T.fecha_hasta,
  E.especie,
  case 
  when tarifa_activa = 1 then 'Activa'
  else 'Inactiva'
  end as TarifaActiva,
  case 
  when caja = 1 then 'Cobro por Caja(Trato)'
  when kilo = 1 then 'Cobro por Jornada'
  end as TipoCobro,
  M.abrev
FROM dbo.Dota_Valor_Dotacion D
left JOIN dbo.Dota_Cargo C ON C.id_cargo = D.id_cargo
left JOIN dbo.Dota_Tipo_Tarifa T ON T.id_tipo_tarifa = D.id_tipo_tarifa
left JOIN dbo.dota_tipo_mo M ON M.id_mo = C.id_mo
left JOIN dbo.especie E on D.id_especie = E.id_especie
;
";
$query = sqlsrv_query($conn, $sql);


$title = "Valor de tarifas por cargo";
include __DIR__ . '/../partials/header.php';
include __DIR__ . '/../partials/navbar_wrapper.php';
?>






<div class="container py-4">

<?php if ($excel_flash_ok): ?>
  <div class="alert alert-success"><?= $excel_flash_ok ?></div>
<?php endif; ?>
<?php if ($excel_flash_err): ?>
  <div class="alert alert-danger"><?= $excel_flash_err ?></div>
<?php endif; ?>

 <div class="row justify-content-center">
    <div class="col-12 col-lg-10 col-xl-8">

                <h1 class="text-center mb-4">Tarifas por cargos</h1>

                <!-- Panel carga Excel -->
                <div class="card mb-4">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <span>Carga Masiva desde Excel</span>
                        <button class="btn btn-sm btn-outline-secondary" type="button"
                                data-bs-toggle="collapse" data-bs-target="#panelExcel">Mostrar / Ocultar</button>
                    </div>
                    <div class="collapse<?= (!empty($excel_sin_match) || $excel_flash_err) ? ' show' : '' ?>" id="panelExcel">
                        <div class="card-body">
                            <p class="text-muted small mb-2">
                                Sube el archivo <strong>tarifas.xlsx</strong> — se leerá la hoja <code>Tarifas</code>.<br>
                                Columnas requeridas: <code>Labor</code>, <code>Tipo Tarifa</code>. Opcionales: <code>Especie</code> (vacía = todas), <code>Contratista</code> (vacío = todos).
                            </p>
                            <form method="POST" enctype="multipart/form-data" class="d-flex gap-2 align-items-end">
                                <?= csrf_field() ?>
                                <div>
                                    <label class="form-label mb-1">Archivo Excel</label>
                                    <input type="file" name="archivo_excel" class="form-control" accept=".xlsx,.xls" required>
                                </div>
                                <button type="submit" name="cargar_excel" class="btn btn-success">Cargar</button>
                            </form>

                            <?php if (!empty($excel_sin_match)): ?>
                            <hr>
                            <h6 class="text-danger mt-3">Filas sin coincidencia (<?= count($excel_sin_match) ?>):</h6>
                            <div class="table-responsive">
                            <table class="table table-sm table-bordered table-warning mt-2" style="font-size:.85rem">
                                <thead class="table-dark">
                                    <tr>
                                        <th>Labor (Excel)</th>
                                        <th>Tipo Tarifa (Excel)</th>
                                        <th>Problema</th>
                                    </tr>
                                </thead>
                                <tbody>
                                <?php foreach ($excel_sin_match as $sm): ?>
                                <tr>
                                    <td><?= htmlspecialchars($sm['labor']) ?></td>
                                    <td><?= htmlspecialchars($sm['tarifa']) ?></td>
                                    <td class="text-danger small">
                                        <?= $sm['sin_cargo']  ? '⚠ Labor no encontrada en Dota_Cargo ' : '' ?>
                                        <?= $sm['sin_tarifa'] ? '⚠ Tipo Tarifa no encontrado' : '' ?>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <div class="card mb-4">
                    <div class="card-header">Enlazar Cargo con tarifa
                </div>
                        <div class="card-body">
                            <form method="POST">
                                <?= csrf_field() ?>

                                        <div class="row g-3">
                                             <div class="form-group col-12 col-md-4">
                                                     <label for="tipo_tarifa">Tipo Tarifa:</label>
                                                <select name="tipo_tarifa" class="form-control" required>
                                                    <option value="">Seleccionar Tipo Tarifa</option>
                                                    <?php while ($row = sqlsrv_fetch_array($queryTipoTarifa, SQLSRV_FETCH_ASSOC)) { ?>
                                                        <option value="<?php echo $row['id_tipo_tarifa']; ?>"><?php echo htmlspecialchars($row['Tipo_tarifa']); ?></option>
                                                    <?php } ?>
                                                </select>      
                                            </div> 
                                            <div class="form-group col-12 col-md-4">
                                               <label for="cargo">Cargo:</label>
                                                        <select id="cargo" name="cargo[]" class="form-control" multiple required>
                                                        <?php while ($rowCargo = sqlsrv_fetch_array($queryCargo, SQLSRV_FETCH_ASSOC)) { ?>
                                                            <option value="<?= (int)$rowCargo['id_cargo'] ?>">
                                                            <?= htmlspecialchars($rowCargo['cargo']) ?>
                                                            </option>
                                                        <?php } ?>
                                                        </select>
                                                        <small class="text-muted">Escribe para buscar y selecciona varios.</small>

                                            </div>
                                            <div class="form-group col-12 col-md-4">
                                                     <label for="especie">Especie:</label>
                                                <select name="especie" class="form-control">
                                                    <option value="">— Todas —</option>
                                                    <?php while ($row = sqlsrv_fetch_array($queryEspecie, SQLSRV_FETCH_ASSOC)) { ?>
                                                        <option value="<?php echo $row['id_especie']; ?>"><?php echo htmlspecialchars($row['especie']); ?></option>
                                                    <?php } ?>
                                                </select>
                                            </div>
                                            <div class="form-group col-12 col-md-4">
                                                <label for="id_contratista">Contratista:</label>
                                                <select name="id_contratista" class="form-control">
                                                    <option value="0">— Todos —</option>
                                                    <?php
                                                    $qCon = sqlsrv_query($conn, "SELECT id, nombre FROM dbo.dota_contratista ORDER BY nombre");
                                                    while ($rCon = sqlsrv_fetch_array($qCon, SQLSRV_FETCH_ASSOC)) { ?>
                                                        <option value="<?= (int)$rCon['id'] ?>"><?= htmlspecialchars($rCon['nombre']) ?></option>
                                                    <?php } ?>
                                                </select>
                                            </div>
                                        </div>
                                         <button type="submit" name="guardar" class="btn btn-primary">Guardar</button>
                            </form>
                                        
                   
               
       
        </div>
    </div>
                        
    </div>
</div>




    <?php
// Establecer la cantidad de registros por página
$registros_por_pagina = 10;

// Obtener el número de la página actual desde la URL (por defecto es 1)
$pagina_actual = isset($_GET['pagina']) ? (int)$_GET['pagina'] : 1;
if ($pagina_actual < 1) $pagina_actual = 1;

// Manejar búsqueda
$busqueda = isset($_GET['busqueda']) ? trim($_GET['busqueda']) : '';
$filtro_busqueda = $busqueda ? "WHERE C.cargo LIKE '%" . str_replace("'", "''", $busqueda) . "%'" : '';

// Contar el total de registros para calcular el número de páginas
$query_total_registros = sqlsrv_query($conn, "SELECT
 count(*) as total
FROM dbo.Dota_Valor_Dotacion D
JOIN dbo.Dota_Cargo C ON C.id_cargo = D.id_cargo
JOIN dbo.Dota_Tipo_Tarifa T ON T.id_tipo_tarifa = D.id_tipo_tarifa
JOIN dbo.dota_tipo_mo M ON M.id_mo = C.id_mo
LEFT JOIN dbo.dota_contratista CON ON CON.id = D.id_contratista $filtro_busqueda");
if ($query_total_registros === false) {
    $flash_error = "Error al consultar registros. Intente nuevamente.";
}
$total_registros = sqlsrv_fetch_array($query_total_registros, SQLSRV_FETCH_ASSOC)['total'];
$total_paginas = ceil($total_registros / $registros_por_pagina);

// Calcular el índice de inicio para la consulta SQL
$offset = ($pagina_actual - 1) * $registros_por_pagina;

// Consulta para obtener los registros con límite y desplazamiento
$query = sqlsrv_query($conn,"SELECT
  D.id,
  D.id_cargo,
  D.id_tipo_tarifa,
  D.id_contratista,
  C.cargo AS Cargo,
  T.Tipo_Tarifa AS tarifa_nombre,
  C.id_mo,
  D.id_especie,
  M.abrev,
  CON.nombre AS contratista_nombre
FROM dbo.Dota_Valor_Dotacion D
JOIN dbo.Dota_Cargo C ON C.id_cargo = D.id_cargo
JOIN dbo.Dota_Tipo_Tarifa T ON T.id_tipo_tarifa = D.id_tipo_tarifa
JOIN dbo.dota_tipo_mo M ON M.id_mo = C.id_mo
LEFT JOIN dbo.especie E on D.id_especie = E.id_especie
LEFT JOIN dbo.dota_contratista CON ON CON.id = D.id_contratista
$filtro_busqueda ORDER BY D.id OFFSET $offset ROWS FETCH NEXT $registros_por_pagina ROWS ONLY");
if ($query === false) {
    $flash_error = "Error al obtener los registros. Intente nuevamente.";
}

?>

<!-- Formulario de búsqueda -->
<div class="mb-3">
    <form method="GET" action="">
        <div class="input-group">
            <input type="text" name="busqueda" class="form-control" placeholder="Buscar por cargo" value="<?php echo htmlspecialchars($busqueda); ?>">
            <button class="btn btn-primary" type="submit">Buscar</button>
        </div>
    </form>
</div>

   <!-- Tabla de registros -->
   <div class="container mt-4 table-container">
    <h2 class="text-center mt-4">Registros Existentes</h2>
    <div class="table-responsive">
        <table class="table table-bordered table-hover mt-3">
            <thead class="thead-dark">
                <tr>
                <th>ID</th>
                <th>Cargo</th>
                <th>Tipo Tarifa</th>
                <th>Especie</th>
                <th>Contratista</th>
                <th>Acciones</th>
            </tr>
        </thead>
        <tbody>
            <?php while ($row = sqlsrv_fetch_array($query, SQLSRV_FETCH_ASSOC)) { ?>
                <tr>
                    <form method="POST">
                        <?= csrf_field() ?>
                        <td><?php echo $row['id']; ?></td>
                        <td>
                            <select name="cargo" class="form-control" onmouseover="this.title=this.options[this.selectedIndex].text">
                                <?php
                                $cargo_query = sqlsrv_query($conn, "SELECT id_cargo, cargo FROM Dota_cargo");
                                while ($cargo_row = sqlsrv_fetch_array($cargo_query, SQLSRV_FETCH_ASSOC)) {
                                    $selected = $cargo_row['id_cargo'] == $row['id_cargo'] ? 'selected' : '';
                                    echo "<option value='{$cargo_row['id_cargo']}' $selected>{$cargo_row['cargo']}</option>";
                                }
                                ?>
                            </select>
                        </td>
                            <td>
                            <select name="tipo_tarifa" class="form-control" onmouseover="this.title=this.options[this.selectedIndex].text">
                                <?php
                                $tipo_tarifa_query = sqlsrv_query($conn, "SELECT id_tipo_tarifa, tipo_tarifa FROM Dota_Tipo_tarifa where tarifa_activa = 1");
                                while ($tipo_tarifa_row = sqlsrv_fetch_array($tipo_tarifa_query, SQLSRV_FETCH_ASSOC)) {
                                    $selected = $tipo_tarifa_row['id_tipo_tarifa'] == $row['id_tipo_tarifa'] ? 'selected' : '';
                                    echo "<option value='{$tipo_tarifa_row['id_tipo_tarifa']}' $selected>{$tipo_tarifa_row['tipo_tarifa']}</option>";
                                }
                                ?>
                            </select>
                        </td>
                        <td>
                            <select name="especie" class="form-control"
                                onmouseover="this.title=this.options[this.selectedIndex].text">
                                <option value="">— Todas —</option>
                                <?php
                                $especie_query = sqlsrv_query($conn, "SELECT id_especie, especie FROM especie");
                                while ($especie_row = sqlsrv_fetch_array($especie_query, SQLSRV_FETCH_ASSOC)) {
                                    $selected = $especie_row['id_especie'] == $row['id_especie'] ? 'selected' : '';
                                    echo "<option value='{$especie_row['id_especie']}' $selected>{$especie_row['especie']}</option>";
                                }
                                ?>
                            </select>
                        </td>
                        <td>
                            <select name="id_contratista" class="form-control"
                                onmouseover="this.title=this.options[this.selectedIndex].text">
                                <option value="0">— Todos —</option>
                                <?php
                                $con_query = sqlsrv_query($conn, "SELECT id, nombre FROM dbo.dota_contratista ORDER BY nombre");
                                while ($con_row = sqlsrv_fetch_array($con_query, SQLSRV_FETCH_ASSOC)) {
                                    $selected = $con_row['id'] == $row['id_contratista'] ? 'selected' : '';
                                    echo "<option value='{$con_row['id']}' $selected>" . htmlspecialchars($con_row['nombre']) . "</option>";
                                }
                                ?>
                            </select>
                        </td>
                        <td>
                            <input type="hidden" name="id_tipo" value="<?php echo $row['id']; ?>">
                            <button type="submit" name="editar" class="btn btn-warning btn-sm">Editar</button>
                            <button type="submit" name="eliminar" class="btn btn-danger btn-sm">Eliminar</button>
                        </td>
                    </form>
                </tr>
            <?php } ?>
        </tbody>
    </table>
</div>

<!-- Paginación -->
<nav>
    <ul class="pagination justify-content-center">
        <?php if ($pagina_actual > 1): ?>
            <li class="page-item"><a class="page-link" href="?pagina=<?php echo $pagina_actual - 1; ?>&busqueda=<?php echo urlencode($busqueda); ?>">Anterior</a></li>
        <?php endif; ?>

        <?php for ($i = 1; $i <= $total_paginas; $i++): ?>
            <li class="page-item <?php if ($i == $pagina_actual) echo 'active'; ?>">
                <a class="page-link" href="?pagina=<?php echo $i; ?>&busqueda=<?php echo urlencode($busqueda); ?>"><?php echo $i; ?></a>
            </li>
        <?php endfor; ?>

        <?php if ($pagina_actual < $total_paginas): ?>
            <li class="page-item"><a class="page-link" href="?pagina=<?php echo $pagina_actual + 1; ?>&busqueda=<?php echo urlencode($busqueda); ?>">Siguiente</a></li>
        <?php endif; ?>
    </ul>
</nav>




<!--Botón para exportar a Excel-->
<div class='buttons'>
<a href='Pre_Excel_Tarifas.php'>
    <button>Exportar a Excel</button>
</a>
</div>

</div>

</div><!-- /container -->

<?php
include __DIR__ . '/../partials/footer.php';
sqlsrv_close($conn);
?>