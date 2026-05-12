<?php
include 'includes/session.php';
include 'includes/db.php';
require_once('includes/fpdf.php');

if ($_SESSION['rol'] !== 'vendedor' && $_SESSION['rol'] !== 'administrador') {
    die('No autorizado');
}

$id_vendedor = $_SESSION['usuario_id'];
$nombre_vendedor = $_SESSION['nombre'] ?? 'Vendedor';
$tipo = $_GET['exportar'] ?? 'excel';
$fecha_inicio = $_GET['fecha_inicio'] ?? date('Y-m-01');
$fecha_fin = $_GET['fecha_fin'] ?? date('Y-m-d');

// Formatear fechas para mostrar
$fecha_inicio_mostrar = date('d/m/Y', strtotime($fecha_inicio));
$fecha_fin_mostrar = date('d/m/Y', strtotime($fecha_fin));

// Obtener resumen
$resumen = $conn->query("
    SELECT 
        COUNT(DISTINCT v.id) AS total_ventas,
        IFNULL(SUM(v.cantidad_vendida), 0) AS total_unidades,
        IFNULL(SUM(v.cantidad_vendida * p.precio_venta), 0) AS total_ingresos,
        IFNULL(SUM((p.precio_venta - p.precio_compra) * v.cantidad_vendida), 0) AS utilidad_estimada,
        IFNULL(AVG(v.cantidad_vendida * p.precio_venta), 0) AS ticket_promedio
    FROM ventas v
    JOIN productos p ON v.id_producto = p.id
    WHERE v.id_vendedor = $id_vendedor
    AND DATE(v.fecha_venta) BETWEEN '$fecha_inicio' AND '$fecha_fin'
")->fetch_assoc();

// Obtener ventas detalladas
$ventas = $conn->query("
    SELECT 
        DATE(v.fecha_venta) AS fecha,
        p.nombre AS producto,
        v.cantidad_vendida,
        p.precio_venta,
        (v.cantidad_vendida * p.precio_venta) AS total,
        v.metodo_pago,
        v.correo_cliente,
        v.folio_ticket
    FROM ventas v
    JOIN productos p ON v.id_producto = p.id
    WHERE v.id_vendedor = $id_vendedor
    AND DATE(v.fecha_venta) BETWEEN '$fecha_inicio' AND '$fecha_fin'
    ORDER BY v.fecha_venta DESC
");

if ($tipo == 'excel') {
    exportarExcel($ventas, $resumen, $fecha_inicio_mostrar, $fecha_fin_mostrar, $nombre_vendedor);
} elseif ($tipo == 'pdf') {
    exportarPDF($conn, $ventas, $resumen, $fecha_inicio_mostrar, $fecha_fin_mostrar, $nombre_vendedor, $id_vendedor, $fecha_inicio, $fecha_fin);
}

function exportarExcel($ventas, $resumen, $fecha_inicio, $fecha_fin, $nombre_vendedor) {
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="reporte_ventas_' . date('Y-m-d') . '.csv"');
    
    // Agregar BOM para UTF-8
    echo "\xEF\xBB\xBF";
    
    $output = fopen('php://output', 'w');
    
    // Título del reporte
    fputcsv($output, ['REPORTE DE VENTAS']);
    fputcsv($output, ['Vendedor:', $nombre_vendedor]);
    fputcsv($output, ['Período:', $fecha_inicio . ' - ' . $fecha_fin]);
    fputcsv($output, ['Fecha de exportación:', date('d/m/Y H:i:s')]);
    fputcsv($output, []);
    
    // Resumen
    fputcsv($output, ['RESUMEN']);
    fputcsv($output, ['Total Ventas', '$' . number_format($resumen['total_ingresos'], 2)]);
    fputcsv($output, ['Unidades Vendidas', $resumen['total_unidades']]);
    fputcsv($output, ['Utilidad Estimada', '$' . number_format($resumen['utilidad_estimada'], 2)]);
    fputcsv($output, ['Ticket Promedio', '$' . number_format($resumen['ticket_promedio'], 2)]);
    fputcsv($output, ['Número de Transacciones', $resumen['total_ventas']]);
    fputcsv($output, []);
    
    // Detalle de ventas
    fputcsv($output, ['DETALLE DE VENTAS']);
    fputcsv($output, ['Fecha', 'Folio', 'Producto', 'Cantidad', 'Precio Unitario', 'Total', 'Método de Pago', 'Cliente']);
    
    while ($row = $ventas->fetch_assoc()) {
        fputcsv($output, [
            $row['fecha'],
            $row['folio_ticket'],
            $row['producto'],
            $row['cantidad_vendida'],
            '$' . number_format($row['precio_venta'], 2),
            '$' . number_format($row['total'], 2),
            traducirMetodoPago($row['metodo_pago']),
            $row['correo_cliente'] ?: 'No registrado'
        ]);
    }
    
    fclose($output);
}

function exportarPDF($conn, $ventas, $resumen, $fecha_inicio, $fecha_fin, $nombre_vendedor, $id_vendedor, $fecha_inicio_sql, $fecha_fin_sql) {
    // Crear PDF en orientación Landscape para mejor visualización
    $pdf = new FPDF('L', 'mm', 'A4');
    $pdf->AddPage();
    $pdf->SetMargins(10, 10, 10);
    
    // Colores de la tienda
    $color_principal = array(249, 115, 22); // Naranja
    $color_texto = array(30, 41, 59); // Gris oscuro
    
    // ================== ENCABEZADO ==================
    // Logo (si existe)
    $logo_path = 'uploads/logo.png';
    if (file_exists($logo_path)) {
        $pdf->Image($logo_path, 10, 8, 30);
        $pdf->SetY(8);
    }
    
    // Título
    $pdf->SetFont('Arial', 'B', 18);
    $pdf->SetTextColor($color_principal[0], $color_principal[1], $color_principal[2]);
    $pdf->Cell(0, 10, 'REPORTE DE VENTAS', 0, 1, 'C');
    
    // Línea decorativa
    $pdf->SetDrawColor($color_principal[0], $color_principal[1], $color_principal[2]);
    $pdf->Line(10, $pdf->GetY(), 287, $pdf->GetY());
    $pdf->Ln(5);
    
    // Información del reporte
    $pdf->SetFont('Arial', '', 10);
    $pdf->SetTextColor($color_texto[0], $color_texto[1], $color_texto[2]);
    $pdf->Cell(50, 7, 'Vendedor:', 0, 0);
    $pdf->SetFont('Arial', 'B', 10);
    $pdf->Cell(0, 7, utf8_decode($nombre_vendedor), 0, 1);
    
    $pdf->SetFont('Arial', '', 10);
    $pdf->Cell(50, 7, 'Período:', 0, 0);
    $pdf->SetFont('Arial', 'B', 10);
    $pdf->Cell(0, 7, $fecha_inicio . ' - ' . $fecha_fin, 0, 1);
    
    $pdf->SetFont('Arial', '', 10);
    $pdf->Cell(50, 7, 'Fecha de exportación:', 0, 0);
    $pdf->SetFont('Arial', 'B', 10);
    $pdf->Cell(0, 7, date('d/m/Y H:i:s'), 0, 1);
    $pdf->Ln(8);
    
    // ================== TARJETAS DE RESUMEN ==================
    // Fondo para resumen
    $pdf->SetFillColor(248, 250, 252);
    $pdf->Rect(10, $pdf->GetY(), 277, 50, 'F');
    
    $pdf->SetFont('Arial', 'B', 11);
    $pdf->SetTextColor($color_principal[0], $color_principal[1], $color_principal[2]);
    $pdf->Cell(0, 8, 'RESUMEN GENERAL', 0, 1, 'L');
    
    $pdf->SetFont('Arial', '', 9);
    $pdf->SetTextColor($color_texto[0], $color_texto[1], $color_texto[2]);
    
    // 4 columnas de resumen
    $pdf->SetY($pdf->GetY() + 2);
    
    // Columna 1
    $pdf->SetX(15);
    $pdf->SetFont('Arial', 'B', 8);
    $pdf->Cell(60, 6, 'Total Ventas', 0, 0);
    $pdf->SetFont('Arial', '', 10);
    $pdf->SetTextColor(34, 197, 94);
    $pdf->Cell(60, 6, '$' . number_format($resumen['total_ingresos'], 2), 0, 0);
    
    // Columna 2
    $pdf->SetX(135);
    $pdf->SetFont('Arial', 'B', 8);
    $pdf->SetTextColor($color_texto[0], $color_texto[1], $color_texto[2]);
    $pdf->Cell(60, 6, 'Unidades Vendidas', 0, 0);
    $pdf->SetFont('Arial', '', 10);
    $pdf->SetTextColor(59, 130, 246);
    $pdf->Cell(60, 6, number_format($resumen['total_unidades']), 0, 1);
    
    // Columna 3
    $pdf->SetX(15);
    $pdf->SetFont('Arial', 'B', 8);
    $pdf->SetTextColor($color_texto[0], $color_texto[1], $color_texto[2]);
    $pdf->Cell(60, 6, 'Utilidad Estimada', 0, 0);
    $pdf->SetFont('Arial', '', 10);
    $pdf->SetTextColor(34, 197, 94);
    $pdf->Cell(60, 6, '$' . number_format($resumen['utilidad_estimada'], 2), 0, 0);
    
    // Columna 4
    $pdf->SetX(135);
    $pdf->SetFont('Arial', 'B', 8);
    $pdf->SetTextColor($color_texto[0], $color_texto[1], $color_texto[2]);
    $pdf->Cell(60, 6, 'Ticket Promedio', 0, 0);
    $pdf->SetFont('Arial', '', 10);
    $pdf->SetTextColor(249, 115, 22);
    $pdf->Cell(60, 6, '$' . number_format($resumen['ticket_promedio'], 2), 0, 1);
    
    // Columna 5
    $pdf->SetX(15);
    $pdf->SetFont('Arial', 'B', 8);
    $pdf->SetTextColor($color_texto[0], $color_texto[1], $color_texto[2]);
    $pdf->Cell(60, 6, 'Transacciones', 0, 0);
    $pdf->SetFont('Arial', '', 10);
    $pdf->SetTextColor(139, 92, 246);
    $pdf->Cell(60, 6, $resumen['total_ventas'], 0, 0);
    
    $pdf->Ln(15);
    
    // ================== TOP PRODUCTOS ==================
    $topProductos = $conn->query("
        SELECT 
            p.nombre,
            SUM(v.cantidad_vendida) AS unidades,
            SUM(v.cantidad_vendida * p.precio_venta) AS ingresos
        FROM ventas v
        JOIN productos p ON v.id_producto = p.id
        WHERE v.id_vendedor = $id_vendedor
        AND DATE(v.fecha_venta) BETWEEN '$fecha_inicio_sql' AND '$fecha_fin_sql'
        GROUP BY p.id
        ORDER BY ingresos DESC
        LIMIT 5
    ");
    
    if ($topProductos && $topProductos->num_rows > 0) {
        $pdf->SetFont('Arial', 'B', 11);
        $pdf->SetTextColor($color_principal[0], $color_principal[1], $color_principal[2]);
        $pdf->Cell(0, 8, 'TOP 5 PRODUCTOS MÁS VENDIDOS', 0, 1, 'L');
        
        // Encabezados de tabla
        $pdf->SetFillColor($color_principal[0], $color_principal[1], $color_principal[2]);
        $pdf->SetTextColor(255, 255, 255);
        $pdf->SetFont('Arial', 'B', 9);
        $pdf->Cell(180, 8, 'Producto', 1, 0, 'L', true);
        $pdf->Cell(40, 8, 'Unidades', 1, 0, 'C', true);
        $pdf->Cell(50, 8, 'Ingresos', 1, 1, 'C', true);
        
        $pdf->SetTextColor($color_texto[0], $color_texto[1], $color_texto[2]);
        $pdf->SetFont('Arial', '', 9);
        
        while ($row = $topProductos->fetch_assoc()) {
            $pdf->Cell(180, 7, utf8_decode(substr($row['nombre'], 0, 50)), 1, 0, 'L');
            $pdf->Cell(40, 7, number_format($row['unidades']), 1, 0, 'C');
            $pdf->Cell(50, 7, '$' . number_format($row['ingresos'], 2), 1, 1, 'R');
        }
        $pdf->Ln(8);
    }
    
    // ================== DETALLE DE VENTAS ==================
    $pdf->SetFont('Arial', 'B', 11);
    $pdf->SetTextColor($color_principal[0], $color_principal[1], $color_principal[2]);
    $pdf->Cell(0, 8, 'DETALLE DE VENTAS', 0, 1, 'L');
    
    // Encabezados de la tabla principal
    $pdf->SetFillColor($color_principal[0], $color_principal[1], $color_principal[2]);
    $pdf->SetTextColor(255, 255, 255);
    $pdf->SetFont('Arial', 'B', 8);
    $pdf->Cell(30, 7, 'Fecha', 1, 0, 'C', true);
    $pdf->Cell(35, 7, 'Folio', 1, 0, 'C', true);
    $pdf->Cell(80, 7, 'Producto', 1, 0, 'C', true);
    $pdf->Cell(20, 7, 'Cant', 1, 0, 'C', true);
    $pdf->Cell(25, 7, 'Precio', 1, 0, 'C', true);
    $pdf->Cell(30, 7, 'Total', 1, 0, 'C', true);
    $pdf->Cell(30, 7, 'Método', 1, 0, 'C', true);
    $pdf->Cell(35, 7, 'Cliente', 1, 1, 'C', true);
    
    $pdf->SetTextColor($color_texto[0], $color_texto[1], $color_texto[2]);
    $pdf->SetFont('Arial', '', 7);
    
    // Reiniciar el puntero de la consulta
    $ventas->data_seek(0);
    $fila = 0;
    
    while ($row = $ventas->fetch_assoc()) {
        // Verificar si necesitamos nueva página
        if ($pdf->GetY() > 180) {
            $pdf->AddPage();
            
            // Reimprimir encabezados
            $pdf->SetFillColor($color_principal[0], $color_principal[1], $color_principal[2]);
            $pdf->SetTextColor(255, 255, 255);
            $pdf->SetFont('Arial', 'B', 8);
            $pdf->Cell(30, 7, 'Fecha', 1, 0, 'C', true);
            $pdf->Cell(35, 7, 'Folio', 1, 0, 'C', true);
            $pdf->Cell(80, 7, 'Producto', 1, 0, 'C', true);
            $pdf->Cell(20, 7, 'Cant', 1, 0, 'C', true);
            $pdf->Cell(25, 7, 'Precio', 1, 0, 'C', true);
            $pdf->Cell(30, 7, 'Total', 1, 0, 'C', true);
            $pdf->Cell(30, 7, 'Método', 1, 0, 'C', true);
            $pdf->Cell(35, 7, 'Cliente', 1, 1, 'C', true);
            $pdf->SetTextColor($color_texto[0], $color_texto[1], $color_texto[2]);
            $pdf->SetFont('Arial', '', 7);
        }
        
        // Alternar colores de fila
        $fila++;
        if ($fila % 2 == 0) {
            $pdf->SetFillColor(248, 250, 252);
            $fill = true;
        } else {
            $fill = false;
        }
        
        $pdf->Cell(30, 6, $row['fecha'], 1, 0, 'C', $fill);
        $pdf->Cell(35, 6, $row['folio_ticket'], 1, 0, 'C', $fill);
        $pdf->Cell(80, 6, utf8_decode(substr($row['producto'], 0, 35)), 1, 0, 'L', $fill);
        $pdf->Cell(20, 6, $row['cantidad_vendida'], 1, 0, 'C', $fill);
        $pdf->Cell(25, 6, '$' . number_format($row['precio_venta'], 2), 1, 0, 'R', $fill);
        $pdf->Cell(30, 6, '$' . number_format($row['total'], 2), 1, 0, 'R', $fill);
        $pdf->Cell(30, 6, traducirMetodoPago($row['metodo_pago']), 1, 0, 'C', $fill);
        $cliente = $row['correo_cliente'] ? substr($row['correo_cliente'], 0, 25) : 'No registrado';
        $pdf->Cell(35, 6, utf8_decode($cliente), 1, 1, 'L', $fill);
    }
    
    // ================== PIE DE PÁGINA ==================
    $pdf->SetY(-15);
    $pdf->SetFont('Arial', 'I', 8);
    $pdf->SetTextColor(128, 128, 128);
    $pdf->Cell(0, 10, 'Reporte generado por PescaVentas - Página ' . $pdf->PageNo(), 0, 0, 'C');
    
    // Salida del PDF
    $pdf->Output('D', 'reporte_ventas_' . date('Y-m-d') . '.pdf');
}

function traducirMetodoPago($metodo) {
    $metodos = [
        'efectivo' => 'Efectivo',
        'transferencia' => 'Transferencia',
        'tarjeta_debito' => 'Tarjeta Débito',
        'tarjeta_credito' => 'Tarjeta Crédito'
    ];
    return $metodos[$metodo] ?? $metodo;
}
?>