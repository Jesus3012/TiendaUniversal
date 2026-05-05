<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

include 'includes/db.php';
session_start();

$data = json_decode(file_get_contents("php://input"), true);
$id = intval($data['id']);
$usuario = $_SESSION['nombre'] ?? 'Sistema';

$conn->begin_transaction();

try {
    // 1️⃣ Obtener datos del producto (solo pendientes)
    $pedido = $conn->query("
        SELECT id, id_orden, id_producto, nombre_producto, cantidad_pedida, faltante
        FROM pedidos
        WHERE id = $id AND estado = 'pendiente'
    ")->fetch_assoc();

    if(!$pedido){
        throw new Exception("Producto no encontrado o ya completado");
    }

    $id_orden = $pedido['id_orden'];
    $id_producto = $pedido['id_producto'];
    $nombre = $pedido['nombre_producto'];
    $cantidad_original = $pedido['cantidad_pedida'];
    $faltante = $pedido['faltante'];
    $cantidad_solventada = $cantidad_original - $faltante;

    // 2️⃣ Guardar en pedidos_solventados
    $conn->query("
        INSERT INTO pedidos_solventados
        (id_pedido, id_producto, cantidad_original, cantidad_solventada, cantidad_faltante, usuario, fecha)
        VALUES ($id, " . ($id_producto ?: 'NULL') . ", $cantidad_original, $cantidad_solventada, $faltante, '$usuario', NOW())
    ");

    // 3️⃣ LOG de producto completado
    $conn->query("
        INSERT INTO pedidos_log (id_pedido, accion, detalle, usuario, fecha) 
        VALUES ($id_orden, 'PRODUCTO COMPLETADO', 'Producto: $nombre | Original: $cantidad_original | Completado: $cantidad_solventada', '$usuario', NOW())
    ");

    // 4️⃣ Marcar producto como completado
    $conn->query("
        UPDATE pedidos
        SET estado = 'completado',
            faltante = 0,
            fecha_completado = NOW()
        WHERE id = $id
    ");

    // 5️⃣ Verificar si TODOS los productos del pedido están completados
    $pendientes = $conn->query("
        SELECT COUNT(*) as pendientes 
        FROM pedidos 
        WHERE id_orden = $id_orden AND estado != 'completado'
    ")->fetch_assoc()['pendientes'];
    
    if ($pendientes == 0) {
        // Actualizar ventas a completado
        $conn->query("
            UPDATE ventas
            SET metodo_pago = 'completado'
            WHERE id_orden = $id_orden
        ");
        
        // LOG de pedido completado
        $conn->query("
            INSERT INTO pedidos_log (id_pedido, accion, detalle, usuario, fecha) 
            VALUES ($id_orden, 'PEDIDO COMPLETADO', 'Todos los productos del pedido han sido completados', '$usuario', NOW())
        ");
    }

    $conn->commit();

    echo json_encode(['success' => true]);

} catch (Exception $e) {
    $conn->rollback();
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
?>