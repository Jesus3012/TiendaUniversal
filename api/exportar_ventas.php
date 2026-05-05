<?php
session_start();
include '../includes/db.php';
require_once '../includes/fpdf.php';

if (!isset($_SESSION['usuario_id'])) {
    die('No autorizado');
}

$inicio = $_GET['inicio'] ?? '';
$fin = $_GET['fin'] ?? '';
$busqueda = $_GET['busqueda'] ?? '';

$sql = "SELECT v.folio_ticket, v.correo_cliente, v.fecha_venta,
        (SELECT SUM(v2.cantidad_vendida * p2.precio_venta) 
         FROM ventas v2 
         JOIN productos p2 ON v2.id_producto = p2.id 
         WHERE v2.folio_ticket = v.folio_ticket) as total_general
        FROM ventas v
        WHERE DATE(v.fecha_venta) BETWEEN '$inicio' AND '$fin'";

if (!empty($busqueda)) {
    $busqueda = $conn->real_escape_string($busqueda);
    $sql .= " AND (v.folio_ticket LIKE '%$busqueda%' OR v.correo_cliente LIKE '%$busqueda%')";
}

$sql .= " ORDER BY v.fecha_venta DESC";

$result = $conn->query($sql);

// Crear PDF
$pdf = new FPDF();
$pdf->AddPage();
$pdf->SetFont('Arial', 'B', 16);

// Título
$pdf->Cell(0, 10, 'Reporte de Ventas', 0, 1, 'C');
$pdf->SetFont('Arial', '', 10);
$pdf->Cell(0, 8, 'Periodo: ' . date('d/m/Y', strtotime($inicio)) . ' - ' . date('d/m/Y', strtotime($fin)), 0, 1, 'C');
$pdf->Ln(10);

// Encabezados de columna
$pdf->SetFont('Arial', 'B', 9);
$pdf->Cell(45, 8, 'Folio', 1);
$pdf->Cell(30, 8, 'Total', 1);
$pdf->Cell(40, 8, 'Cliente', 1);
$pdf->Cell(40, 8, 'Fecha', 1);
$pdf->Cell(30, 8, 'Estado', 1);
$pdf->Ln();

// Datos
$pdf->SetFont('Arial', '', 8);
$totalGeneral = 0;

if ($result && $result->num_rows > 0) {
    while ($row = $result->fetch_assoc()) {
        $estado = 'Venta directa';
        if (strpos($row['folio_ticket'], 'PEDIDO-') === 0) {
            $estado = 'Pedido';
        } elseif (strpos($row['folio_ticket'], 'Venta_conteo') === 0) {
            $estado = 'Conteo';
        } elseif (strpos($row['folio_ticket'], 'Venta_codigo') === 0) {
            $estado = 'Venta código';
        }
        
        $cliente = !empty($row['correo_cliente']) ? substr($row['correo_cliente'], 0, 25) : 'Venta en general';
        $total = $row['total_general'];
        $totalGeneral += $total;
        
        $pdf->Cell(45, 7, substr($row['folio_ticket'], 0, 20), 1);
        $pdf->Cell(30, 7, '$' . number_format($total, 2), 1);
        $pdf->Cell(40, 7, $cliente, 1);
        $pdf->Cell(40, 7, date('d/m/Y H:i', strtotime($row['fecha_venta'])), 1);
        $pdf->Cell(30, 7, $estado, 1);
        $pdf->Ln();
    }
    
    // Total general
    $pdf->SetFont('Arial', 'B', 9);
    $pdf->Cell(75, 8, 'TOTAL GENERAL:', 1);
    $pdf->Cell(30, 8, '$' . number_format($totalGeneral, 2), 1);
    $pdf->Cell(80, 8, '', 1);
    $pdf->Ln();
} else {
    $pdf->Cell(0, 10, 'No hay ventas en el período seleccionado', 1, 1, 'C');
}

// Salida del PDF
$pdf->Output('ventas_' . date('Y-m-d') . '.pdf', 'D');
exit;
?>