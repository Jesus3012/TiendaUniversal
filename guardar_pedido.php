<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

include 'includes/db.php';
session_start();

$usuario = $_SESSION['nombre'] ?? 'Sistema';
$data = json_decode(file_get_contents("php://input"), true);

$solicitado_por = $data['solicitado_por'];
$pedidos = $data['pedidos'];

$conn->begin_transaction();

try {
    // 1️⃣ Crear ORDEN
    $sqlOrden = "INSERT INTO ordenes_pedido (solicitado_por, fecha) VALUES ('$solicitado_por', NOW())";
    if (!$conn->query($sqlOrden)) {
        throw new Exception("Error al crear orden: " . $conn->error);
    }
    
    $id_orden = $conn->insert_id;
    
    if ($id_orden == 0) {
        throw new Exception("No se pudo generar el ID de la orden");
    }

    // 📝 LOG: Pedido creado
    $sqlLog = "INSERT INTO pedidos_log (id_pedido, accion, detalle, usuario) 
               VALUES ($id_orden, 'PEDIDO CREADO', 'El usuario creó el pedido para: $solicitado_por', '$usuario')";
    if (!$conn->query($sqlLog)) {
        throw new Exception("Error al guardar log: " . $conn->error);
    }

    $folio_ticket = 'PEDIDO-' . $id_orden;

    foreach ($pedidos as $p) {
        // Obtener stock actual
        $stockResult = $conn->query("SELECT cantidad FROM productos WHERE id = {$p['id']}");
        if (!$stockResult) {
            throw new Exception("Error al obtener stock: " . $conn->error);
        }
        $stockActual = $stockResult->fetch_assoc()['cantidad'];
        
        $pedidoCantidad = (int)$p['pedido'];
        $faltante = max(0, $pedidoCantidad - $stockActual);
        
        // Insertar en pedidos
        $sqlPedido = "INSERT INTO pedidos 
            (id_orden, id_producto, nombre_producto, stock_actual, cantidad_pedida, faltante, solicitado_por, estado) 
            VALUES ($id_orden, {$p['id']}, '{$p['nombre']}', $stockActual, $pedidoCantidad, $faltante, '$solicitado_por', 'pendiente')";
        if (!$conn->query($sqlPedido)) {
            throw new Exception("Error al insertar pedido: " . $conn->error);
        }

        // 📝 LOG: Producto agregado
        $detalle = "Producto: {$p['nombre']} | Stock actual: $stockActual | Pedido: $pedidoCantidad | Faltante: $faltante";
        $sqlLog = "INSERT INTO pedidos_log (id_pedido, accion, detalle, usuario) 
                   VALUES ($id_orden, 'PRODUCTO AGREGADO', '$detalle', '$usuario')";
        if (!$conn->query($sqlLog)) {
            throw new Exception("Error al guardar log de producto: " . $conn->error);
        }

        // Insertar en ventas
        $sqlVenta = "INSERT INTO ventas 
            (folio_ticket, id_orden, id_producto, cantidad_vendida, metodo_pago, correo_cliente, fecha_venta) 
            VALUES ('$folio_ticket', $id_orden, {$p['id']}, $pedidoCantidad, 'pedido', '$solicitado_por', NOW())";
        if (!$conn->query($sqlVenta)) {
            throw new Exception("Error al insertar en ventas: " . $conn->error);
        }

        // Descontar stock (PERMITIR NEGATIVOS - sin validación)
        $nuevoStock = $stockActual - $pedidoCantidad;
        
        // Actualizar stock (puede quedar negativo)
        $sqlUpdate = "UPDATE productos SET cantidad = $nuevoStock WHERE id = {$p['id']}";
        if (!$conn->query($sqlUpdate)) {
            throw new Exception("Error al actualizar stock: " . $conn->error);
        }
        
        // 📝 LOG: Stock descontado (siempre se descuenta, aunque quede negativo)
        if ($nuevoStock >= 0) {
            $detalle = "Se descontaron $pedidoCantidad unidades de {$p['nombre']}. Stock anterior: $stockActual | Stock actual: $nuevoStock";
            $sqlLog = "INSERT INTO pedidos_log (id_pedido, accion, detalle, usuario) 
                       VALUES ($id_orden, 'STOCK DESCONTADO', '$detalle', '$usuario')";
            $conn->query($sqlLog);
        } else {
            $detalle = "Stock INSUFICIENTE para {$p['nombre']}. Se descontaron $pedidoCantidad unidades. Stock anterior: $stockActual | Stock actual: $nuevoStock (NEGATIVO) | Faltante: " . abs($nuevoStock);
            $sqlLog = "INSERT INTO pedidos_log (id_pedido, accion, detalle, usuario) 
                       VALUES ($id_orden, 'STOCK NEGATIVO', '$detalle', '$usuario')";
            $conn->query($sqlLog);
        }
    }

    $conn->commit();

    echo json_encode([
        "success" => true,
        "id_orden" => $id_orden,
        "folio_ticket" => $folio_ticket
    ]);

} catch (Exception $e) {
    $conn->rollback();
    echo json_encode([
        "success" => false,
        "error" => $e->getMessage()
    ]);
}
?>