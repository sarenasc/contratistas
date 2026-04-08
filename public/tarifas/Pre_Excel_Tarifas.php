<?php
require_once "../../config/conexion.php"; // Conexión a la base de datos
require '../../vendor/autoload.php'; // Cargar PhpSpreadsheet

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
        $sheet->setCellValue('A1', 'Valores por cargos '); // Título
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(28); // Formato en negrita y tamaño de fuente
        $sheet->getStyle('A1')->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER); // Centrar el texto

        

        // Definir los encabezados de la tabla
        $headers = ['Cargo', 'Tarifa', 'Valor Persona', '% Contratista','Valor a Contratista','Valor Hora', '% HHEE',  'HHEE A Contratista','bono','Especie','Desde','Hasta','Estado Tarifa','Tipo de Pago','Tipo de Mano de Obra'];
        $col = 'B';
        foreach ($headers as $header) {
            $sheet->setCellValue($col . '10', $header);
            $col++;
        }
        $lastCol = $col; // Aquí $col ya quedó en la siguiente columna
            $lastCol--;      // Retrocedemos una columna

            $range = 'B10:' . $lastCol . '10';

            $sheet->getStyle($range)->applyFromArray([
                'alignment' => [
                    'horizontal' => \PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER,
                    'vertical'   => \PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER,
                    'wrapText'   => true,
                ],
            ]);

        // Consultar los datos del los valores
        $viewSql = "SELECT
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
                        case 
                        when tarifa_activa = 1 then 'Activa'
                        else 'Inactiva'
                        end as TarifaActiva,
                        case 
                        when caja = 1 then 'Cobro por Caja(Trato)'
                        when kilo = 1 then 'Cobro por Jornada'
                        end as TipoCobro,
                        E.especie,
                        M.abrev
                        FROM dbo.Dota_Valor_Dotacion D
                        JOIN dbo.Dota_Cargo C ON C.id_cargo = D.id_cargo
                        JOIN dbo.Dota_Tipo_Tarifa T ON T.id_tipo_tarifa = D.id_tipo_tarifa
                        JOIN dbo.dota_tipo_mo M ON M.id_mo = C.id_mo
                        LEFT JOIN dbo.especie E on D.id_especie = E.id_especie";
       

        $viewStmt = sqlsrv_query($conn, $viewSql);

        if ($viewStmt === false) {
            die(print_r(sqlsrv_errors(), true));
        }

        // Inicializar las variables de totales
        

        // Llenar los datos de la hoja
        $rowIndex = 11; // Empezar dela fila 11 fila (debajo de los encabezados)
        while ($viewRow = sqlsrv_fetch_array($viewStmt, SQLSRV_FETCH_ASSOC)) {
            $sheet->setCellValue('B' . $rowIndex, $viewRow['cargo_nombre']);
            $sheet->setCellValue('C' . $rowIndex, $viewRow['tarifa_nombre']);
            $sheet->setCellValue('D' . $rowIndex, $viewRow['ValorContratista']);
            $sheet->setCellValue('E' . $rowIndex, $viewRow['PorcContrastista']);
            $sheet->setCellValue('F' . $rowIndex, $viewRow['ValorAContratista']);
            $sheet->setCellValue('G' . $rowIndex, $viewRow['horasExtras']);
            $sheet->setCellValue('H' . $rowIndex, $viewRow['porc_hhee']);
            $sheet->setCellValue('I' . $rowIndex, $viewRow['hheeAContratista']);           
            $sheet->setCellValue('J' . $rowIndex, $viewRow['bono']);
            $sheet->setCellValue('K' . $rowIndex, $viewRow['especie']);                 
            $sheet->setCellValue('L' . $rowIndex, $viewRow['fecha_desde']->format('Y-m-d'));
            $sheet->setCellValue('M' . $rowIndex, $viewRow['fecha_hasta']->format('Y-m-d'));
            $sheet->setCellValue('N' . $rowIndex, $viewRow['TarifaActiva']);
            $sheet->setCellValue('O' . $rowIndex, $viewRow['TipoCobro']); 
            $sheet->setCellValue('P' . $rowIndex, $viewRow['abrev']);   
                   
            $rowIndex++;        
            $sheet->getStyle('B10:P'.$rowIndex-1)->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN)->setColor(new Color('FF000000')); // Bordes negros   

        }

        $sheet->getColumnDimension('B')->setWidth(30); // Columna A de ancho 20
        $sheet->getColumnDimension('C')->setWidth(19); 
        $sheet->getColumnDimension('D')->setWidth(13);
        $sheet->getColumnDimension('E')->setWidth(12);
        $sheet->getColumnDimension('F')->setWidth(16); 
        $sheet->getColumnDimension('G')->setWidth(11); 
        $sheet->getColumnDimension('H')->setWidth(7); 
        $sheet->getColumnDimension('I')->setWidth(17);
        $sheet->getColumnDimension('J')->setWidth(9); 
        $sheet->getColumnDimension('K')->setWidth(12); 
        $sheet->getColumnDimension('L')->setWidth(12); 
        $sheet->getColumnDimension('M')->setWidth(12); 
        $sheet->getColumnDimension('N')->setWidth(12); 
        $sheet->getColumnDimension('O')->setWidth(20); 
        $sheet->getColumnDimension('P')->setWidth(20);   

        foreach (['D', 'F','G','I','J'] as $col) {
            $sheet->getStyle($col . '3:' . $col . ($rowIndex - 1))
                ->getNumberFormat()
                ->setFormatCode('[$$-409] #,##0'); // Formato de moneda con separadores de miles 
        }
        foreach (['H', 'E',] as $col) {
            $sheet->getStyle($col . '3:' . $col . ($rowIndex - 1))
                ->getNumberFormat()
                ->setFormatCode('0.00%'); // Formato de moneda con separadores de miles 
        }

        // Aplicar color de fondo y formato al encabezado
    $sheet->getStyle('B10:P10')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('AED6F1'); // 
    //$sheet->getStyle('A1:B1')->getFont()->getColor()->setARGB(Color::COLOR_BLACK); // Texto nEGRO
    $sheet->getStyle('B10:P10')->getFont()->setBold(true); // Texto en negrita

    
        
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
