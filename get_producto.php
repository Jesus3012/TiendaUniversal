<?php
// get_producto.php
session_start();
require_once 'includes/db.php';

if (!isset($_SESSION['usuario_id']) || $_SESSION['rol'] !== 'administrador') {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'No autorizado']);
    exit;
}

$id = isset($_GET['id']) ? intval($_GET['id']) : 0;

if ($id <= 0) {
    echo json_encode(['success' => false, 'error' => 'ID inválido']);
    exit;
}

$stmt = $conn->prepare("SELECT * FROM productos WHERE id = ? AND activo = 1");
$stmt->bind_param("i", $id);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    echo json_encode(['success' => false, 'error' => 'Producto no encontrado']);
    exit;
}

$producto = $result->fetch_assoc();

// ===== CORRECCIÓN: Asegurar que tipo_codigo tenga valor =====
if ($producto['tipo_inventario'] === 'producto') {
    // Para productos, si está vacío o null, asignar 'multiple'
    if (empty($producto['tipo_codigo']) || $producto['tipo_codigo'] === '') {
        $producto['tipo_codigo'] = 'multiple';
    }
} else {
    // Para insumos, no aplica
    $producto['tipo_codigo'] = null;
}

// Decodificar atributos JSON
$producto['atributos_array'] = $producto['atributos'] ? json_decode($producto['atributos'], true) : [];

// Verificar si la imagen existe
$producto['imagen_exists'] = !empty($producto['imagen']) && file_exists($producto['imagen']);

// Verificar si el PDF existe
$pdf_file = __DIR__ . '/uploads/codigos/producto_' . $producto['id'] . '.pdf';
$producto['pdf_exists'] = file_exists($pdf_file);

echo json_encode([
    'success' => true,
    'producto' => $producto
]);
?>