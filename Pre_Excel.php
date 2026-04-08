<?php
require_once "conexion.php"; // Conexión a la base de datos
require 'vendor/autoload.php'; // Cargar PhpSpreadsheet

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Color;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;

// Verificar si se ha solicitado la exportación a Excel
if (isset($_GET['exportar_excel'])) {
    // Obtener los parámetros
    $semana = isset($_GET['semana']) ? $_GET['semana'] : null;
    $ano = isset($_GET['ano']) ? $_GET['ano'] : null;

    // Crear un nuevo objeto Spreadsheet
    $spreadsheet = new Spreadsheet();






    // Consultar los contratistas
    $sql = "SELECT [id], [nombre] FROM [dbo].[dota_contratista]";
    $stmt = sqlsrv_query($conn, $sql);

    if ($stmt === false) {
        die(print_r(sqlsrv_errors(), true));
    }
   
    
    // Recorrer los contratistas y generar una pestaña para cada uno
    while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
        $idContratista = $row['id'];
        $nombreContratista = $row['nombre'];

        // Crear una nueva hoja para cada contratista
        $sheet = $spreadsheet->createSheet();
        $sheet->setTitle($nombreContratista); // Título de la pestaña con el nombre del contratista

        //Agregar título en la parte superior de la hoja
        $sheet->mergeCells('A1:L4'); // Unir las celdas de la primera fila
        $sheet->setCellValue('A1', 'Reporte de Pre-factura - ' . $nombreContratista); // Título
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(28); // Formato en negrita y tamaño de fuente
        $sheet->getStyle('A1')->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER); // Centrar el texto

        

        // Definir los encabezados de la tabla
        $headers = ['Fecha', 'Semana', 'Mes', 'Año', 'Contratista', 'Área', 'Cargo', 'Jornada', 'Hora Extra','Valor CLP', 'Total $ Jornada', 'Total $ Horas Extra',  'Total'];
        $col = 'B';
        foreach ($headers as $header) {
            $sheet->setCellValue($col . '10', $header);
            $col++;
        }

        // Consultar los datos del contratista
        $viewSql = "SELECT [Fecha], [Semana], [Mes], [Año], [Contratista], [id],[Area], [cargo], [Valor], 
                           [Total_CLP_Jornada], [Total_CLP_Horas Extra], [Hora Extra], [Jornada],[Total]
                    FROM [dbo].[view_PreFactura]
                    WHERE [Contratista] = ?";
        $params = array($nombreContratista);
        if ($semana) {
            $viewSql .= " AND [Semana] = ?";
            $params[] = $semana;
        }
        if ($ano) {
            $viewSql .= " AND [Año] = ?";
            $params[] = $ano;
        }

        $viewStmt = sqlsrv_query($conn, $viewSql, $params);

        if ($viewStmt === false) {
            die(print_r(sqlsrv_errors(), true));
        }

        // Inicializar las variables de totales
        $totalCLPJornada = 0;
        $totalCLPHorasExtra = 0;
        $totalHoraExtra = 0;
        $totalJornadas = 0;
        $Total = 0;

        // Llenar los datos de la hoja
        $rowIndex = 11; // Empezar dela fila 11 fila (debajo de los encabezados)
        while ($viewRow = sqlsrv_fetch_array($viewStmt, SQLSRV_FETCH_ASSOC)) {
            $sheet->setCellValue('B' . $rowIndex, $viewRow['Fecha']->format('Y-m-d'));
            $sheet->setCellValue('C' . $rowIndex, $viewRow['Semana']);
            $sheet->setCellValue('D' . $rowIndex, $viewRow['Mes']);
            $sheet->setCellValue('E' . $rowIndex, $viewRow['Año']);
            $sheet->setCellValue('F' . $rowIndex, $viewRow['Contratista']);
            $sheet->setCellValue('G' . $rowIndex, $viewRow['Area']);
            $sheet->setCellValue('H' . $rowIndex, $viewRow['cargo']);
            $sheet->setCellValue('I' . $rowIndex, $viewRow['Jornada']);
            $sheet->setCellValue('J' . $rowIndex, $viewRow['Hora Extra']);           
            $sheet->setCellValue('K' . $rowIndex, $viewRow['Valor']);
            $sheet->setCellValue('L' . $rowIndex, $viewRow['Total_CLP_Jornada']);
            $sheet->setCellValue('M' . $rowIndex, $viewRow['Total_CLP_Horas Extra']);
            $sheet->setCellValue('N' . $rowIndex, $viewRow['Total']);

            

            // Acumulando los totales
            $totalCLPJornada += $viewRow['Total_CLP_Jornada'];
            $totalCLPHorasExtra += $viewRow['Total_CLP_Horas Extra'];
            $totalHoraExtra += $viewRow['Hora Extra'];
            $totalJornadas += $viewRow['Jornada'];
            $Total += $viewRow['Total'];

            $rowIndex++;
            $sheet->getStyle('B10:N'.$rowIndex)->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN)->setColor(new Color('FF000000')); // Bordes negros


            

        }

        // Aplicar color de fondo y formato al PIE DE TABLA
        $sheet->getStyle('B'.$rowIndex . ':' . 'N' . $rowIndex)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('AED6F1'); // 
        //$sheet->getStyle('A1:B1')->getFont()->getColor()->setARGB(Color::COLOR_BLACK); // Texto nEGRO
        $sheet->getStyle('B'.$rowIndex . ':' . 'N' . $rowIndex)->getFont()->setBold(true); // Texto en negrita
        
        $sheet->getColumnDimension('B')->setWidth(12); // Columna A de ancho 20
        $sheet->getColumnDimension('D')->setWidth(5); 
        $sheet->getColumnDimension('E')->setWidth(6);
        $sheet->getColumnDimension('F')->setWidth(21);
        $sheet->getColumnDimension('G')->setWidth(11); 
        $sheet->getColumnDimension('H')->setWidth(30); 
        $sheet->getColumnDimension('K')->setWidth(10); 
        $sheet->getColumnDimension('L')->setWidth(17); 
        $sheet->getColumnDimension('M')->setWidth(17); 
        $sheet->getColumnDimension('N')->setWidth(17); 

        // Formatear las celdas con valores monetarios (en pesos)
        foreach (['L', 'M', 'N'] as $col) {
            $sheet->getStyle($col . '4:' . $col . ($rowIndex - 1))
                ->getNumberFormat()
                ->setFormatCode('[$$-409] #,##0'); // Formato de moneda con separadores de miles 
        }

        // Agregar la fila de totales al final
        $sheet->setCellValue('B' . $rowIndex, 'Totales');
        $sheet->setCellValue('I' . $rowIndex, $totalJornadas);
        $sheet->setCellValue('J' . $rowIndex, $totalHoraExtra);
        $sheet->setCellValue('L' . $rowIndex, $totalCLPJornada);
        $sheet->setCellValue('M' . $rowIndex, $totalCLPHorasExtra);
        $sheet->setCellValue('N' . $rowIndex, $Total);

        // Aplicar color de fondo y formato al encabezado
    $sheet->getStyle('B10:N10')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('AED6F1'); // 
    //$sheet->getStyle('A1:B1')->getFont()->getColor()->setARGB(Color::COLOR_BLACK); // Texto nEGRO
    $sheet->getStyle('B10:N10')->getFont()->setBold(true); // Texto en negrita

    
        // Formatear las celdas de los totales con formato de moneda
        foreach ([ 'L','M','N'] as $col) {
            $sheet->getStyle($col . $rowIndex)->getFont()->setBold(true); // Hacer los totales en negrita
            $sheet->getStyle($col . $rowIndex)
                ->getNumberFormat()
                ->setFormatCode('[$$-409] #,##0'); // Formato de moneda con separadores de miles 
        }

        sqlsrv_free_stmt($viewStmt);
    }
    



    //pestaña total general
    if ($conn === false) {
        die(print_r(sqlsrv_errors(), true));
    }
    $semana1 = isset($_GET['semana']) ? $_GET['semana'] : null;
    // Consulta SQL
    $sql = "SELECT [Contratista], 
                   CASE WHEN SUM([Total]) IS NULL THEN 0 ELSE SUM([Total]) END AS Total
            FROM [dbo].[view_PreFactura]
            WHERE semana = $semana1
            GROUP BY Contratista";
    $query = sqlsrv_query($conn, $sql);
    
    if ($query === false) {
        die(print_r(sqlsrv_errors(), true));
    }
    // Primera pestaña: Resultados de la primera consulta
        $sheet = $spreadsheet->createSheet();
        //$sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Total General');

        //Agregar título en la parte superior de la hoja
        $sheet->mergeCells('A1:L4'); // Unir las celdas de la primera fila
        $sheet->setCellValue('A1', 'Total Contratistas ' ); // Título
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(28); // Formato en negrita y tamaño de fuente
        $sheet->getStyle('A1')->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER); // Centrar el texto
        
    // Agregar encabezados a la hoja
    $sheet->setCellValue('H8', 'Contratista');
    $sheet->setCellValue('I8', 'Total');
    
    $TG=0;
    // Rellenar datos desde la consulta
    $rowNumber = 9; // Empezar desde la segunda fila
    while ($row2 = sqlsrv_fetch_array($query, SQLSRV_FETCH_ASSOC)) {
        $sheet->setCellValue('H' . $rowNumber, $row2['Contratista']);
        $sheet->setCellValue('I' . $rowNumber, $row2['Total']);
       

       $TG += $row2['Total'];
        $rowNumber++;
    }
    // Formatear las celdas con valores monetarios (en pesos)
    foreach (['I'] as $col) {
        $sheet->getStyle($col . '2:' . $col . ($rowIndex - 1))
            ->getNumberFormat()
            ->setFormatCode('[$$-409] #,##0'); // Formato de moneda con separadores de miles 
    }
    // Aplicar color de fondo y formato al encabezado
    $sheet->getStyle('H8:I8')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('AED6F1'); // 
    //$sheet->getStyle('A1:B1')->getFont()->getColor()->setARGB(Color::COLOR_BLACK); // Texto nEGRO
    $sheet->getStyle('H8:I8')->getFont()->setBold(true); // Texto en negrita

    $sheet->getStyle('H8:I'. $rowNumber)->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN)->setColor(new Color('FF000000')); // Bordes negros

    $sheet->getColumnDimension('H')->setWidth(20); // Columna A de ancho 20
    $sheet->getColumnDimension('I')->setWidth(20); // Columna B de ancho 30

    // Aplicar color de fondo y formato al TOTAL
    $sheet->getStyle('H' . $rowNumber . ':I'. $rowNumber)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('AED6F1'); // 
    $sheet->getStyle('A1:B1')->getFont()->getColor()->setARGB(Color::COLOR_BLACK); // Texto nEGRO
    $sheet->getStyle('H' . $rowNumber . ':I'. $rowNumber)->getFont()->setBold(true); // Texto en negrita


    

               // Agregar la fila de totales al final
        $sheet->setCellValue('H' . $rowNumber, 'Totales');
        $sheet->setCellValue('I' . $rowNumber, $TG);

        // Formatear las celdas de los totales con formato de moneda
        foreach ([ 'I'] as $col) {
            $sheet->getStyle($col . $rowIndex)->getFont()->setBold(true); // Hacer los totales en negrita
            $sheet->getStyle($col . $rowIndex)
                ->getNumberFormat()
                ->setFormatCode('[$$-409] #,##0'); // Formato de moneda con separadores de miles 
        }
        


// fin total general
    
   
            



    // Eliminar la hoja predeterminada que se crea al inicializar el Spreadsheet
    $spreadsheet->removeSheetByIndex(0);
    
    // Eliminar las líneas de la cuadrícula (mostrar cuadrícula desactivado)
    foreach ($spreadsheet->getAllSheets() as $sheet) {
        $sheet->setShowGridlines(false);
    }

    

    // Crear el archivo Excel y forzar la descarga
    $writer = new Xlsx($spreadsheet);
    $filename = "Reporte_Contratistas_" . ($semana ? "Semana$semana" : "") . "_$ano.xlsx";
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment;filename="' . $filename . '"');
    header('Cache-Control: max-age=0');
    $writer->save('php://output');

    sqlsrv_close($conn);
    exit;
}
?>
