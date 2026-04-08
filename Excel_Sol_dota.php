<?php
require 'vendor/autoload.php';  // Si usas Composer, carga la librería PhpSpreadsheet

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

// Conexión a la base de datos SQL Server
require_once "conexion.php";

// Consultar los registros
$sql = "WITH CTE AS (
    SELECT 
       [nombre]
      ,[cargo]
      ,[Area]
      ,[cantidad]
      ,[version]
      ,[fecha],
        ROW_NUMBER() OVER (PARTITION BY [nombre], [cargo], [Area] ORDER BY [version] DESC) AS rn
    FROM [dbo].[view_Solicitud_Contratista]
)
SELECT 
    [nombre]
      ,[cargo]
      ,[Area]
      ,[cantidad]
      ,[version]
      ,[fecha]
FROM CTE
WHERE rn = 1
ORDER BY [nombre], [cargo], [area];";
$query = sqlsrv_query($conn, $sql);

// Crear un nuevo archivo Excel
$spreadsheet = new Spreadsheet();
$sheet = $spreadsheet->getActiveSheet();

// Escribir los encabezados de la tabla
$sheet->setCellValue('A1', 'Contratista')
      ->setCellValue('B1', 'Cargo')
      ->setCellValue('C1', 'Área')
      ->setCellValue('D1', 'Cantidad')
      ->setCellValue('E1', 'Versión')
      ->setCellValue('F1', 'Fecha');

// Llenar los datos de la tabla
$rowNum = 2;  // Comenzamos en la fila 2 (la fila 1 tiene los encabezados)
while ($row = sqlsrv_fetch_array($query, SQLSRV_FETCH_ASSOC)) {
    $sheet->setCellValue('A' . $rowNum, $row['nombre'])
          ->setCellValue('B' . $rowNum, $row['cargo'])
          ->setCellValue('C' . $rowNum, $row['Area'])
          ->setCellValue('D' . $rowNum, $row['cantidad'])
          ->setCellValue('E' . $rowNum, $row['version'])
          ->setCellValue('F' . $rowNum, $row['fecha']->format('Y-m-d'));
    $rowNum++;
}

// Aplicar formato de tabla
$sheet->getStyle('A1:F' . ($rowNum - 1))->getBorders()->getAllBorders()->setBorderStyle(\PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN);
$sheet->getStyle('A1:F1')->getFont()->setBold(true); // Poner los encabezados en negrita
$sheet->getStyle('A1:F' . ($rowNum - 1))->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER); // Alinear al centro

// Hacer que los encabezados tengan un fondo gris
$sheet->getStyle('A1:F1')->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setRGB('D3D3D3');

// Definir el rango de la tabla
$tableRange = 'A1:F' . ($rowNum - 1);
$sheet->setAutoFilter($tableRange);  // Activar filtro en las columnas

// Convertir el rango de celdas en una tabla
$sheet->getStyle($tableRange)->getBorders()->getAllBorders()->setBorderStyle(\PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN);

// Crear el escritor Excel
$writer = new Xlsx($spreadsheet);

// Enviar el archivo al navegador
header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment;filename="solicitudes_contratistas.xlsx"');
header('Cache-Control: max-age=0');

// Guardar el archivo
$writer->save('php://output');
exit;
?>
