<?php
session_start();
include 'includes/db.php';

if (!isset($_SESSION['usuario_id'])) {
    die('No autorizado');
}

$inicio = $_GET['inicio'] ?? '';
$fin = $_GET['fin'] ?? '';
$busqueda = $_GET['busqueda'] ?? '';

// Configurar headers para Excel
header('Content-Type: application/vnd.ms-excel');
header('Content-Disposition: attachment; filename="ventas_' . date('Y-m-d') . '.xls"');
header('Cache-Control: max-age=0');

// Crear el contenido del Excel
echo "Folio\tTotal\tCliente\tFecha\tEstado\n";

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
        
        $cliente = !empty($row['correo_cliente']) ? $row['correo_cliente'] : 'Venta en general';
        
        echo $row['folio_ticket'] . "\t" . 
             number_format($row['total_general'], 2) . "\t" . 
             $cliente . "\t" . 
             date('d/m/Y H:i', strtotime($row['fecha_venta'])) . "\t" . 
             $estado . "\n";
    }
} else {
    echo "No hay ventas en el período seleccionado.\t\t\t\t";
}

$conn->close();
exit;
?>