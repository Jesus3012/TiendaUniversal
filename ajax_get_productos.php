<?php
session_start();
require_once 'includes/db.php';

if (!isset($_SESSION['usuario_id'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'No autorizado']);
    exit;
}

$tipo = isset($_GET['tipo']) ? $_GET['tipo'] : 'todos';

// Validar que el tipo sea válido
if (!in_array($tipo, ['todos', 'producto', 'insumo'])) {
    $tipo = 'todos';
}

// Construir consulta optimizada
$query = "SELECT id, nombre, categoria, proveedor, imagen, cantidad, 
                 precio_compra, precio_venta, tipo_codigo, tipo_inventario, atributos 
          FROM productos 
          WHERE activo = 1";

if ($tipo !== 'todos') {
    $query .= " AND tipo_inventario = '" . $conn->real_escape_string($tipo) . "'";
}

$query .= " ORDER BY id DESC LIMIT 1000";

$result = $conn->query($query);
$productos = [];

if ($result) {
    while ($row = $result->fetch_assoc()) {
        // Decodificar atributos JSON
        $row['atributos_array'] = $row['atributos'] ? json_decode($row['atributos'], true) : [];
        
        // Verificar si la imagen existe físicamente
        $row['imagen_exists'] = !empty($row['imagen']) && file_exists($row['imagen']);
        
        // Verificar si el PDF de códigos existe
        $row['pdf_exists'] = file_exists('uploads/codigos/producto_' . $row['id'] . '.pdf');
        
        $productos[] = $row;
    }
}

// Obtener total de registros para el filtro actual
$totalQuery = "SELECT COUNT(*) as total FROM productos WHERE activo = 1";
if ($tipo !== 'todos') {
    $totalQuery .= " AND tipo_inventario = '" . $conn->real_escape_string($tipo) . "'";
}
$totalResult = $conn->query($totalQuery);
$total = $totalResult->fetch_assoc()['total'];

// Devolver JSON
echo json_encode([
    'success' => true,
    'productos' => $productos,
    'total' => (int)$total,
    'filtro' => $tipo
]);
?>