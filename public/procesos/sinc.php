<?php
// Conexión a la base de datos
require_once "conexion.php";

if (!$conn) {
    die(print_r(sqlsrv_errors(), true));
}

// Verificar y eliminar los datos existentes en la tabla Registro_Marcacion
$sqlDelete = "DELETE FROM dota_Registro_Marcacion";
$queryDelete = sqlsrv_query($conn, $sqlDelete);

if ($queryDelete === false) {
    die(print_r(sqlsrv_errors(), true));
} else {
    echo "Datos antiguos eliminados correctamente.<br>";
    flush();
    ob_flush();
}

// Consulta para seleccionar los nuevos datos
$sqlSelect = "
    SELECT 
        '77645615-2' AS Rut_Empresa,
        '1' AS C_Sucursal,
        Tipo,
        n_departamento,
        APROBADOR,
        MONTH(Fecha) AS Mes,
        YEAR(Fecha) AS Año,
        convert(date,Fecha,102) as Fecha,
        Rut,
        codigo,
        nombre,
        CASE WHEN Contratista = '1' THEN 'SI' ELSE 'NO' END AS Contratista,
        DAY(Fecha) AS Dia,
        C_CentroCosto AS C_CC,
        N_CentroCosto AS N_CC,
        C_Grupo AS C_SU,
        N_Grupo AS N_SU,
        C_Grupo + '-' + C_CentroCosto AS SUC_CC,
        N_LaborFX AS Labor,
        C_LaborFX AS C_Labor,
        id_Turno AS id_Turno,
        CASE WHEN HoraExta = 0 THEN 0 ELSE cast(HHEE as float) END AS HoraExtra,
        cast(Jornada as float) as Jornada
    FROM [dbo].[V_DotacionTrabajadores2]
    ORDER BY Fecha, id_persona ASC
";

// Ejecutar la consulta de selección y contar las filas
$querySelect = sqlsrv_query($conn2, $sqlSelect);
$totalRows = 0;
$data = []; // Guardar las filas en un array temporal

if ($querySelect === false) {
    die(print_r(sqlsrv_errors(), true));
} else {
    while ($row = sqlsrv_fetch_array($querySelect, SQLSRV_FETCH_ASSOC)) {
        $data[] = $row; // Almacenar los datos para reiniciar la iteración
        $totalRows++;
    }
}

// Mostrar barra de progreso
// para que funcione debe estar output_buffering en Off en archivo php.ini
echo '<div id="progressBarContainer" style="width: 100%; background-color: #ccc;">
        <div id="progressBar" style="width: 0%; height: 30px; background-color: #4caf50;"></div>
      </div>';
echo '<div id="progressText">0%</div>';
flush();
ob_flush();

// Insertar los nuevos datos en la tabla Registro_Marcacion
$currentRow = 0;
foreach ($data as $row) {
    $sqlInsert = "
        INSERT INTO dota_Registro_Marcacion (
            Rut_Empresa,
            C_Sucursal,
            Tipo,
            n_departamento,
            APROBADOR,
            Mes,
            Año,
            Fecha,
            Rut,
            codigo,
            nombre,
            Contratista,
            Dia,
            C_CC,
            N_CC,
            C_SU,
            N_SU,
            SUC_CC,
            Labor,
            C_Labor,
            id_Turno,
            HoraExtra,
            Jornada
        ) VALUES (
            ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?
        )
    ";

    $params = [
        $row['Rut_Empresa'],
        $row['C_Sucursal'],
        $row['Tipo'],
        $row['n_departamento'],
        $row['APROBADOR'],
        $row['Mes'],
        $row['Año'],
        $row['Fecha'],
        $row['Rut'],
        $row['codigo'],
        $row['nombre'],
        $row['Contratista'],
        $row['Dia'],
        $row['C_CC'],
        $row['N_CC'],
        $row['C_SU'],
        $row['N_SU'],
        $row['SUC_CC'],
        $row['Labor'],
        $row['C_Labor'],
        $row['id_Turno'],
        $row['HoraExtra'],
        $row['Jornada']
    ];

    // Ejecutar la inserción
    $queryInsert = sqlsrv_query($conn, $sqlInsert, $params);
    if ($queryInsert === false) {
        die(print_r(sqlsrv_errors(), true));
    }

    // Actualizar barra de progreso
    $currentRow++;
    $progress = ($currentRow / $totalRows) * 100;
    echo "<script>
            document.getElementById('progressBar').style.width = '$progress%';
            document.getElementById('progressText').innerText = Math.round($progress) + '%';
          </script>";
    flush();
    ob_flush();
}

echo "<script>
    alert('Sincronización correcta.');
    window.location.href = 'inicio.php';
</script>";

// Cerrar la conexión
sqlsrv_close($conn);
?>
