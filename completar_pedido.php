<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

include 'includes/db.php';
session_start();

header('Content-Type: application/json');

$data = json_decode(file_get_contents("php://input"), true);
$folio = intval($data['folio']);
$usuario = $_SESSION['nombre'] ?? 'Sistema';

// Depuración - registrar lo que llega
error_log("Completar pedido - Folio recibido: " . $folio);

$conn->begin_transaction();

try {
    // 1️⃣ Verificar si el pedido existe
    $check = $conn->query("SELECT id_orden, estado FROM pedidos WHERE id_orden = $folio");
    
    if($check->num_rows == 0){
        throw new Exception("No existe el pedido con folio: $folio");
    }
    
    $pedidoData = $check->fetch_assoc();
    error_log("Pedido encontrado - Estado actual: " . $pedidoData['estado']);
    
    // 2️⃣ Obtener productos pendientes
    $productos = $conn->query("
        SELECT id, id_producto, nombre_producto, cantidad_pedida, faltante
        FROM pedidos 
        WHERE id_orden = $folio AND estado = 'pendiente'
    ");

    if($productos->num_rows > 0){
        while($row = $productos->fetch_assoc()) {
            $id_pedido = $row['id'];
            $nombre = $row['nombre_producto'];
            $cantidad_original = (int)$row['cantidad_pedida'];
            $faltante = (int)$row['faltante'];
            $cantidad_solventada = $cantidad_original - $faltante;

            // Guardar en pedidos_solventados
            $conn->query("
                INSERT INTO pedidos_solventados
                (id_pedido, id_producto, cantidad_original, cantidad_solventada, cantidad_faltante, usuario, fecha)
                VALUES ($id_pedido, {$row['id_producto']}, $cantidad_original, $cantidad_solventada, $faltante, '$usuario', NOW())
            ");

            // LOG de producto completado
            $conn->query("
                INSERT INTO pedidos_log (id_pedido, accion, detalle, usuario, fecha) 
                VALUES ($folio, 'PRODUCTO COMPLETADO', 'Producto: $nombre | Original: $cantidad_original | Completado: $cantidad_solventada', '$usuario', NOW())
            ");
        }
    }

    // 3️⃣ Actualizar pedidos a completado
    $sqlUpdate = "UPDATE pedidos SET estado = 'completado', faltante = 0, fecha_completado = NOW() WHERE id_orden = $folio";
    $conn->query($sqlUpdate);
    error_log("Filas actualizadas en pedidos: " . $conn->affected_rows);

    // 4️⃣ Actualizar ventas
    $sqlVenta = "UPDATE ventas SET metodo_pago = 'completado' WHERE id_orden = $folio";
    $conn->query($sqlVenta);
    error_log("Filas actualizadas en ventas: " . $conn->affected_rows);

    // 5️⃣ LOG general del pedido
    $conn->query("
        INSERT INTO pedidos_log (id_pedido, accion, detalle, usuario, fecha) 
        VALUES ($folio, 'PEDIDO COMPLETADO', 'El pedido ha sido completado en su totalidad', '$usuario', NOW())
    ");

    $conn->commit();

    echo json_encode(['success' => true]);

} catch (Exception $e) {
    $conn->rollback();
    error_log("Error: " . $e->getMessage());
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
?>