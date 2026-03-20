<?php
include '../includes/session.php';
include '../includes/db.php';

header('Content-Type: application/json');

$usuario_id = $_SESSION['usuario_id'];
$rol = $_SESSION['rol'];
$folio = $_GET['folio'] ?? '';

if (empty($folio)) {
    echo json_encode(['success' => false, 'message' => 'Folio no proporcionado']);
    exit;
}

// Verificar permisos
if ($rol === 'vendedor') {
    $check_sql = "SELECT 1 FROM ventas WHERE folio_ticket = ? AND id_vendedor = ? LIMIT 1";
    $check_stmt = $conn->prepare($check_sql);
    if (!$check_stmt) {
        echo json_encode(['success' => false, 'message' => 'Error en la consulta de permisos']);
        exit;
    }
    $check_stmt->bind_param('si', $folio, $usuario_id);
    $check_stmt->execute();
    if ($check_stmt->get_result()->num_rows === 0) {
        echo json_encode(['success' => false, 'message' => 'No autorizado']);
        exit;
    }
}

try {
    // Verificar si hay cancelaciones en ventas_canceladas
    $query = "SELECT 
                v.id_producto,
                p.nombre AS producto,
                v.cantidad_vendida AS cantidad,
                p.precio_venta AS precio_unitario,
                (v.cantidad_vendida * p.precio_venta) AS subtotal,
                
                -- Verificar si el artículo está cancelado (buscamos en ventas_canceladas)
                CASE 
                    WHEN vc.id_venta IS NOT NULL THEN 1
                    ELSE 0
                END AS cancelado,
                
                -- Obtener cantidad devuelta de devoluciones_parciales
                COALESCE(dp.cantidad_devuelta, 0) AS cantidad_devuelta
                
              FROM ventas v
              JOIN productos p ON v.id_producto = p.id
              
              -- LEFT JOIN para ventas canceladas (cancelación completa del artículo)
              LEFT JOIN ventas_canceladas vc ON vc.id_venta = v.id AND vc.cantidad_devuelta = v.cantidad_vendida
              
              -- LEFT JOIN para devoluciones parciales
              LEFT JOIN (
                  SELECT 
                      id_venta,
                      SUM(cantidad_devuelta) AS cantidad_devuelta
                  FROM devoluciones_parciales
                  GROUP BY id_venta
              ) dp ON dp.id_venta = v.id
              
              WHERE v.folio_ticket = ?
              ORDER BY p.nombre ASC";

    $stmt = $conn->prepare($query);
    if (!$stmt) {
        echo json_encode([
            'success' => false, 
            'message' => 'Error al preparar la consulta: ' . $conn->error
        ]);
        exit;
    }
    
    $stmt->bind_param('s', $folio);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if (!$result) {
        echo json_encode([
            'success' => false, 
            'message' => 'Error al ejecutar la consulta: ' . $stmt->error
        ]);
        exit;
    }
    
    $items = [];
    $subtotal = 0;
    
    while ($row = $result->fetch_assoc()) {
        // Calcular cantidad disponible
        $row['disponible'] = $row['cancelado'] ? 0 : ($row['cantidad'] - $row['cantidad_devuelta']);
        $row['devueltos'] = $row['cantidad_devuelta'];
        
        // Formatear precios
        $row['precio_unitario_formateado'] = '$' . number_format($row['precio_unitario'], 2);
        $row['subtotal_formateado'] = '$' . number_format($row['subtotal'], 2);
        
        $items[] = $row;
        
        // Solo sumar al subtotal si no está cancelado
        if (!$row['cancelado']) {
            $subtotal += $row['subtotal'];
        }
    }
    
    // Si no hay items
    if (empty($items)) {
        echo json_encode([
            'success' => false,
            'message' => 'No se encontraron productos para este folio',
            'folio' => $folio
        ]);
        exit;
    }
    
    $total = $subtotal; // Sin IVA
    
    echo json_encode([
        'success' => true,
        'data' => $items,
        'subtotal' => $subtotal,
        'subtotal_formateado' => '$' . number_format($subtotal, 2),
        'total' => $total,
        'total_formateado' => '$' . number_format($total, 2),
        'folio' => $folio
    ]);
    
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => 'Error al obtener detalles: ' . $e->getMessage()
    ]);
}
?>