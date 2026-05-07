<?php
require 'vendor/autoload.php';
include 'includes/db.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Color;

// Recibir parámetros
$id_orden = isset($_GET['id_orden']) ? $_GET['id_orden'] : '';
$solicitado_por = isset($_GET['solicitado_por']) ? $_GET['solicitado_por'] : '';
$estado = isset($_GET['estado']) ? $_GET['estado'] : 'todos';

// Construir WHERE dinámico
$where = "WHERE 1=1";

if($estado !== 'todos' && !empty($estado)){
    $where .= " AND pe.estado = '" . $conn->real_escape_string($estado) . "'";
}

if(!empty($id_orden)){
    $where .= " AND pe.id_orden = " . intval($id_orden);
}

if(!empty($solicitado_por)){
    $sol = $conn->real_escape_string($solicitado_por);
    $where .= " AND pe.solicitado_por = '$sol'";
}

// CONSULTA PRINCIPAL - Incluye todos los pedidos
$res = $conn->query("
    SELECT 
        pe.id_orden,
        pe.nombre_producto,
        pe.stock_actual,
        pe.cantidad_pedida,
        pe.faltante,
        pe.solicitado_por,
        pe.fecha,
        pe.estado,
        pe.fecha_completado,
        pr.cantidad AS stock_real
    FROM pedidos pe
    JOIN productos pr ON pr.id = pe.id_producto
    $where
    AND pe.cantidad_pedida > 0
    ORDER BY pe.id_orden DESC, pe.fecha DESC
");

// Verificar si hay pedidos
$hayPedidos = false;
$pedidosData = [];

while($check = $res->fetch_assoc()){
    if((int)$check['cantidad_pedida'] > 0){
        $hayPedidos = true;
        $pedidosData[] = $check;
    }
}

if(!$hayPedidos){
    header('Content-Type: application/json');
    $mensaje = '';
    if($estado === 'pendiente') $mensaje = 'No hay pedidos pendientes';
    elseif($estado === 'completado') $mensaje = 'No hay pedidos completados';
    elseif($estado === 'cancelado') $mensaje = 'No hay pedidos cancelados';
    else $mensaje = 'No hay pedidos registrados';
    
    echo json_encode(['sin_pedidos' => true, 'mensaje' => $mensaje]);
    exit;
}

$spreadsheet = new Spreadsheet();
$sheet = $spreadsheet->getActiveSheet();
$sheet->setTitle('Lista de Pedidos');

// ============================================
// TÍTULO PRINCIPAL
// ============================================
$sheet->mergeCells('A1:H1');
$titulo = '';
if($estado === 'pendiente') $titulo = 'REPORTE DE PEDIDOS PENDIENTES';
elseif($estado === 'completado') $titulo = 'REPORTE DE PEDIDOS COMPLETADOS';
elseif($estado === 'cancelado') $titulo = 'REPORTE DE PEDIDOS CANCELADOS';
else $titulo = 'REPORTE DE TODOS LOS PEDIDOS';

$sheet->setCellValue('A1', $titulo);
$sheet->getStyle('A1')->getFont()->setSize(18)->setBold(true);
$sheet->getStyle('A1')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
$sheet->getRowDimension(1)->setRowHeight(35);

// Subtítulo con filtros
$sheet->mergeCells('A2:H2');
$subtitulo = "Generado: " . date('d/m/Y H:i:s');
if($estado !== 'todos') $subtitulo .= " | Estado: " . ucfirst($estado);
if(!empty($solicitado_por)) $subtitulo .= " | Solicitante: $solicitado_por";
if(!empty($id_orden)) $subtitulo .= " | Folio: #$id_orden";

$sheet->setCellValue('A2', $subtitulo);
$sheet->getStyle('A2')->getFont()->setSize(10);
$sheet->getStyle('A2')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
$sheet->getStyle('A2')->getFont()->setItalic(true);

// ============================================
// ENCABEZADOS DE TABLA
// ============================================
$headers = ['Folio', 'Producto', 'Stock Actual', 'Cantidad Pedida', 'Faltante', 'Solicitado por', 'Fecha', 'Estado'];
$sheet->fromArray($headers, NULL, 'A4');

$sheet->getStyle('A4:H4')->applyFromArray([
    'font' => [
        'bold' => true,
        'color' => ['rgb' => 'FFFFFF'],
        'size' => 11
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

// ============================================
// DATOS
// ============================================
$fila = 5;
$totalPedidos = 0;
$totalCantidadPedida = 0;
$totalFaltante = 0;

foreach($pedidosData as $row){
    // Calcular estado para mostrar
    $estadoTexto = '';
    $estadoColor = '';
    
    if($row['estado'] === 'pendiente') {
        $estadoTexto = 'Pendiente';
        $estadoColor = 'FFC107';
    } elseif($row['estado'] === 'completado') {
        $estadoTexto = 'Completado';
        $estadoColor = '28A745';
        $totalCantidadPedida += $row['cantidad_pedida'];
    } elseif($row['estado'] === 'cancelado') {
        $estadoTexto = 'Cancelado';
        $estadoColor = 'DC3545';
    }
    
    $sheet->setCellValue("A$fila", $row['id_orden']);
    $sheet->setCellValue("B$fila", $row['nombre_producto']);
    $sheet->setCellValue("C$fila", $row['stock_real']);
    $sheet->setCellValue("D$fila", $row['cantidad_pedida']);
    $sheet->setCellValue("E$fila", $row['faltante'] ?? 0);
    $sheet->setCellValue("F$fila", $row['solicitado_por']);
    $sheet->setCellValue("G$fila", date('d/m/Y H:i', strtotime($row['fecha'])));
    $sheet->setCellValue("H$fila", $estadoTexto);
    
    // Color según estado
    $sheet->getStyle("H$fila")->applyFromArray([
        'font' => ['color' => ['rgb' => $estadoColor], 'bold' => true]
    ]);
    
    // Bordes y alineación
    $sheet->getStyle("A$fila:H$fila")->applyFromArray([
        'borders' => [
            'allBorders' => ['borderStyle' => Border::BORDER_THIN]
        ],
        'alignment' => [
            'vertical' => Alignment::VERTICAL_CENTER,
            'horizontal' => Alignment::HORIZONTAL_CENTER
        ]
    ]);
    
    $totalPedidos++;
    $totalCantidadPedida += $row['cantidad_pedida'];
    $totalFaltante += ($row['faltante'] ?? 0);
    
    $fila++;
}

// ============================================
// FILA DE TOTALES
// ============================================
$sheet->setCellValue("A$fila", "TOTALES");
$sheet->mergeCells("A$fila:B$fila");
$sheet->setCellValue("C$fila", "");
$sheet->setCellValue("D$fila", $totalCantidadPedida);
$sheet->setCellValue("E$fila", $totalFaltante);
$sheet->setCellValue("F$fila", "");
$sheet->setCellValue("G$fila", "");
$sheet->setCellValue("H$fila", $totalPedidos . " pedidos");

$sheet->getStyle("A$fila:H$fila")->applyFromArray([
    'font' => ['bold' => true, 'size' => 11],
    'fill' => [
        'fillType' => Fill::FILL_SOLID,
        'startColor' => ['rgb' => 'E9ECEF']
    ],
    'borders' => [
        'allBorders' => ['borderStyle' => Border::BORDER_THIN]
    ]
]);

// ============================================
// AJUSTES DE COLUMNAS
// ============================================
$columnas = ['A', 'B', 'C', 'D', 'E', 'F', 'G', 'H'];
$anchos = [10, 35, 12, 18, 12, 25, 18, 15];

foreach($columnas as $idx => $col){
    $sheet->getColumnDimension($col)->setWidth($anchos[$idx]);
}

$sheet->setAutoFilter("A4:H" . ($fila-1));
$sheet->freezePane('A5');

// ============================================
// ESTADÍSTICAS ADICIONALES (Hoja separada)
// ============================================
$spreadsheet->createSheet();
$spreadsheet->setActiveSheetIndex(1);
$statsSheet = $spreadsheet->getActiveSheet();
$statsSheet->setTitle('Estadísticas');

// Título de estadísticas
$statsSheet->mergeCells('A1:B1');
$statsSheet->setCellValue('A1', 'ESTADÍSTICAS DEL REPORTE');
$statsSheet->getStyle('A1')->getFont()->setSize(14)->setBold(true);
$statsSheet->getStyle('A1')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

// Datos de estadísticas
$statsSheet->setCellValue('A3', 'Total de pedidos:');
$statsSheet->setCellValue('B3', $totalPedidos);
$statsSheet->setCellValue('A4', 'Total de productos pedidos:');
$statsSheet->setCellValue('B4', $totalCantidadPedida);
$statsSheet->setCellValue('A5', 'Total de productos faltantes:');
$statsSheet->setCellValue('B5', $totalFaltante);
$statsSheet->setCellValue('A6', 'Fecha de generación:');
$statsSheet->setCellValue('B6', date('d/m/Y H:i:s'));
$statsSheet->setCellValue('A7', 'Usuario:');
$statsSheet->setCellValue('B7', $_SESSION['nombre'] ?? 'Sistema');
$statsSheet->setCellValue('A8', 'Filtro aplicado:');
$statsSheet->setCellValue('B8', $estado === 'todos' ? 'Todos los pedidos' : ucfirst($estado));

// Estilo para estadísticas
$statsSheet->getStyle('A3:A8')->getFont()->setBold(true);
$statsSheet->getColumnDimension('A')->setWidth(25);
$statsSheet->getColumnDimension('B')->setWidth(30);
$statsSheet->getStyle('A3:B8')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_LEFT);

// Volver a la primera hoja
$spreadsheet->setActiveSheetIndex(0);

// ============================================
// ENVIAR ARCHIVO
// ============================================
$writer = new Xlsx($spreadsheet);

// Nombre del archivo según el estado
$nombreArchivo = '';
if($estado === 'pendiente') $nombreArchivo = 'Reporte_Pedidos_Pendientes';
elseif($estado === 'completado') $nombreArchivo = 'Reporte_Pedidos_Completados';
elseif($estado === 'cancelado') $nombreArchivo = 'Reporte_Pedidos_Cancelados';
else $nombreArchivo = 'Reporte_Pedidos_Todos';

header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment;filename="' . $nombreArchivo . '_' . date('Y-m-d') . '.xlsx"');
header('Cache-Control: max-age=0');

$writer->save('php://output');
exit;
?>