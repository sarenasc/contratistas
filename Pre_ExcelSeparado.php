<?php
require_once "conexion.php"; // Conexión a la base de datos
require 'vendor/autoload.php'; // Cargar PhpSpreadsheet

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

// Verificar si se ha solicitado la exportación a Excel
if (isset($_GET['exportar_excel'])) {
    // Obtener los parámetros
    $idContratista = $_GET['id'];
    $semana = isset($_GET['semana']) ? $_GET['semana'] : null;
    $ano = isset($_GET['ano']) ? $_GET['ano'] : null;

    // Crear un nuevo objeto Spreadsheet
    $spreadsheet = new Spreadsheet();
    $sheet = $spreadsheet->getActiveSheet();
    $sheet->setTitle('Datos Contratista');
    
    // Consultar los datos del contratista
    $sql = "SELECT [Fecha], [Semana], [Mes], [Año], [Contratista], [id],[Area], [cargo], [Valor], 
                   [Total_CLP_Jornada], [Total_CLP_Horas Extra], [Hora Extra], [Jornada]
            FROM [dbo].[view_PreFactura]
            WHERE [id] = $idContratista";

    $params = array($idContratista);
    if ($semana) {
        $sql .= " AND [Semana] = $semana";
        $params[] = $semana;
    }
    if ($ano) {
        $sql .= " AND [Año] = $ano";
        $params[] = $ano;
    }

    $stmt = sqlsrv_query($conn, $sql, $params);
    if ($stmt === false) {
        die(print_r(sqlsrv_errors(), true));
    }

    // Definir los encabezados de la tabla
    $headers = ['Fecha', 'Semana', 'Mes', 'Año', 'Contratista', 'Área', 'Cargo', 'Valor', 'Total $ Jornada', 'Total $ Horas Extra', 'Hora Extra', 'Jornada'];
    $col = 'A';
    foreach ($headers as $header) {
        $sheet->setCellValue($col.'1', $header);
        $col++;
    }

    // Llenar los datos
    $row = 2;
    while ($viewRow = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
        $sheet->setCellValue('A'.$row, $viewRow['Fecha']->format('Y-m-d'));
        $sheet->setCellValue('B'.$row, $viewRow['Semana']);
        $sheet->setCellValue('C'.$row, $viewRow['Mes']);
        $sheet->setCellValue('D'.$row, $viewRow['Año']);
        $sheet->setCellValue('E'.$row, $viewRow['Contratista']);
        $sheet->setCellValue('F'.$row, $viewRow['Area']);
        $sheet->setCellValue('G'.$row, $viewRow['cargo']);
        $sheet->setCellValue('H'.$row, $viewRow['Valor']);
        $sheet->setCellValue('I'.$row, $viewRow['Total_CLP_Jornada']);
        $sheet->setCellValue('J'.$row, $viewRow['Total_CLP_Horas Extra']);
        $sheet->setCellValue('K'.$row, $viewRow['Hora Extra']);
        $sheet->setCellValue('L'.$row, $viewRow['Jornada']);
        $row++;
    }

    // Crear el archivo Excel y forzar la descarga
    $writer = new Xlsx($spreadsheet);
    $filename = "Contratista_{$idContratista}_Semana{$semana}_Año{$ano}.xlsx";
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment;filename="'.$filename.'"');
    header('Cache-Control: max-age=0');
    $writer->save('php://output');

    sqlsrv_free_stmt($stmt);
    sqlsrv_close($conn);
    exit;
}
?>
