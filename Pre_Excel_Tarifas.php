<?php
require_once "conexion.php"; // Conexión a la base de datos
require 'vendor/autoload.php'; // Cargar PhpSpreadsheet

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Color;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;

// Verificar si se ha solicitado la exportación a Excel

   

    // Crear un nuevo objeto Spreadsheet
    $spreadsheet = new Spreadsheet();






       // Crear una nueva hoja 
        $sheet = $spreadsheet->createSheet();
        $sheet->setTitle("Valores"); // Título de la pestaña con el nombre del contratista

        //Agregar título en la parte superior de la hoja
        $sheet->mergeCells('A1:L4'); // Unir las celdas de la primera fila
        $sheet->setCellValue('A1', 'Valores Cargos '); // Título
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(28); // Formato en negrita y tamaño de fuente
        $sheet->getStyle('A1')->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER); // Centrar el texto

        

        // Definir los encabezados de la tabla
        $headers = ['Cargo', 'Especie', 'Temporada', 'Tipo de Tarifa', 'Desde', 'Hasta', 'Valores', 'Val.HH EE'];
        $col = 'B';
        foreach ($headers as $header) {
            $sheet->setCellValue($col . '10', $header);
            $col++;
        }

        // Consultar los datos del los valores
        $viewSql = "SELECT [Cargo]
      ,[Especie]
      ,[Temporada]
      ,[Tipo de Tarifa]
      ,[Desde]
      ,[Hasta]
      ,[Valor]
      ,[Valor HHEE]
  FROM [dbo].[view_Valores_Contratista]";
       

        $viewStmt = sqlsrv_query($conn, $viewSql);

        if ($viewStmt === false) {
            die(print_r(sqlsrv_errors(), true));
        }

        // Inicializar las variables de totales
        

        // Llenar los datos de la hoja
        $rowIndex = 11; // Empezar dela fila 11 fila (debajo de los encabezados)
        while ($viewRow = sqlsrv_fetch_array($viewStmt, SQLSRV_FETCH_ASSOC)) {
            $sheet->setCellValue('B' . $rowIndex, $viewRow['Cargo']);
            $sheet->setCellValue('C' . $rowIndex, $viewRow['Especie']);
            $sheet->setCellValue('D' . $rowIndex, $viewRow['Temporada']);
            $sheet->setCellValue('E' . $rowIndex, $viewRow['Tipo de Tarifa']);
            $sheet->setCellValue('F' . $rowIndex, $viewRow['Desde']->format('Y-m-d'));
            $sheet->setCellValue('G' . $rowIndex, $viewRow['Hasta']->format('Y-m-d'));
            $sheet->setCellValue('H' . $rowIndex, $viewRow['Valor']);
            $sheet->setCellValue('I' . $rowIndex, $viewRow['Valor HHEE']);           
            $rowIndex++;        
            $sheet->getStyle('B10:I'.$rowIndex-1)->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN)->setColor(new Color('FF000000')); // Bordes negros   

        }

        $sheet->getColumnDimension('B')->setWidth(23); // Columna A de ancho 20
        $sheet->getColumnDimension('C')->setWidth(10); 
        $sheet->getColumnDimension('D')->setWidth(11);
        $sheet->getColumnDimension('E')->setWidth(21);
        $sheet->getColumnDimension('F')->setWidth(11); 
        $sheet->getColumnDimension('G')->setWidth(11); 
        $sheet->getColumnDimension('H')->setWidth(10); 
        $sheet->getColumnDimension('I')->setWidth(10); 

        foreach (['H', 'I'] as $col) {
            $sheet->getStyle($col . '3:' . $col . ($rowIndex - 1))
                ->getNumberFormat()
                ->setFormatCode('[$$-409] #,##0'); // Formato de moneda con separadores de miles 
        }

        // Aplicar color de fondo y formato al encabezado
    $sheet->getStyle('B10:I10')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('AED6F1'); // 
    //$sheet->getStyle('A1:B1')->getFont()->getColor()->setARGB(Color::COLOR_BLACK); // Texto nEGRO
    $sheet->getStyle('B10:I10')->getFont()->setBold(true); // Texto en negrita

    
        
        sqlsrv_free_stmt($viewStmt);
    
    



    // Eliminar la hoja predeterminada que se crea al inicializar el Spreadsheet
    $spreadsheet->removeSheetByIndex(0);
    
    // Eliminar las líneas de la cuadrícula (mostrar cuadrícula desactivado)
    foreach ($spreadsheet->getAllSheets() as $sheet) {
        $sheet->setShowGridlines(false);
    }

    

    // Crear el archivo Excel y forzar la descarga
    $writer = new Xlsx($spreadsheet);
    $filename = "Reporte_Valores.xlsx";
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment;filename="' . $filename . '"');
    header('Cache-Control: max-age=0');
    $writer->save('php://output');

    sqlsrv_close($conn);
    exit;

?>
