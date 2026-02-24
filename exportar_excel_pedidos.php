<?php
require 'vendor/autoload.php';
include 'includes/db.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;

//  WHERE dinámico
$where = "WHERE 1=1";

if(isset($_GET['id_orden']) && $_GET['id_orden'] != ''){
    $id_orden = intval($_GET['id_orden']);
    $where .= " AND pe.id_orden = $id_orden";
}

if(isset($_GET['solicitado_por']) && $_GET['solicitado_por'] != ''){
    $sol = $conn->real_escape_string($_GET['solicitado_por']);
    $where .= " AND pe.solicitado_por = '$sol'";
}

// 🔷 CONSULTA CORRECTA
$res = $conn->query("
SELECT pe.*, pr.cantidad AS stock_real
FROM pedidos pe
JOIN productos pr ON pr.id = pe.id_producto
$where
AND pe.cantidad_pedida > 0
ORDER BY pe.fecha DESC
");

// Verificar si realmente hay algo que pedir
$hayPedidos = false;

while($check = $res->fetch_assoc()){
    if((int)$check['cantidad_pedida'] > 0){
        $hayPedidos = true;
        break;
    }
}

if(!$hayPedidos){
    header('Content-Type: application/json');
    echo json_encode([
        'sin_pedidos' => true,
        'mensaje' => 'No hay reportes pendientes por exportar.'
    ]);
    exit;
}

$res->data_seek(0);

$spreadsheet = new Spreadsheet();
$sheet = $spreadsheet->getActiveSheet();
$sheet->setTitle('Lista de Pedidos');


// 🔷 TÍTULO
$sheet->mergeCells('A1:F1');
$sheet->setCellValue('A1', 'REPORTE DE PEDIDOS / REABASTECIMIENTO');
$sheet->getStyle('A1')->getFont()->setSize(18)->setBold(true);
$sheet->getStyle('A1')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
$sheet->getRowDimension(1)->setRowHeight(35);


// 🔷 ENCABEZADOS
$headers = ['Producto','Stock Actual','Cantidad Pedida','Artículos por hacer','Solicitado por','Fecha'];
$sheet->fromArray($headers, NULL, 'A3');

$sheet->getStyle('A3:F3')->applyFromArray([
    'font' => [
        'bold' => true,
        'color' => ['rgb' => 'FFFFFF'],
        'size' => 12
    ],
    'alignment' => [
        'horizontal' => Alignment::HORIZONTAL_CENTER,
        'vertical' => Alignment::VERTICAL_CENTER
    ],
    'fill' => [
        'fillType' => Fill::FILL_SOLID,
        'startColor' => ['rgb' => '343A40']
    ]
]);

$fila = 4;

while($row = $res->fetch_assoc()){

    $sheet->setCellValue("A$fila", $row['nombre_producto']);
    $sheet->setCellValue("B$fila", $row['stock_real']);
    $sheet->setCellValue("C$fila", $row['cantidad_pedida']);
    $sheet->setCellValue("D$fila", $row['faltante']);
    $sheet->setCellValue("E$fila", $row['solicitado_por']);
    $sheet->setCellValue("F$fila", date('d/m/Y H:i', strtotime($row['fecha'])));

    $sheet->getStyle("A$fila:F$fila")->applyFromArray([
        'borders' => [
            'allBorders' => ['borderStyle' => Border::BORDER_THIN]
        ],
        'alignment' => [
            'vertical' => Alignment::VERTICAL_CENTER,
            'horizontal' => Alignment::HORIZONTAL_CENTER
        ]
    ]);

    $fila++;
}

// 🔷 AJUSTES
foreach(range('A','F') as $col){
    $sheet->getColumnDimension($col)->setAutoSize(true);
}

$sheet->setAutoFilter("A3:F" . ($fila-1));
$sheet->freezePane('A4');

$writer = new Xlsx($spreadsheet);

header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment;filename="Reporte_Pedidos.xlsx"');
header('Cache-Control: max-age=0');

$writer->save('php://output');
exit;
