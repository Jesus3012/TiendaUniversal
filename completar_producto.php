<?php
include 'includes/db.php';

$data = json_decode(file_get_contents("php://input"), true);
$id = intval($data['id']); // ID del renglón en pedidos
$usuario = 'admin'; // luego puedes usar $_SESSION['usuario']

$conn->begin_transaction();

try {

    // 1️⃣ Obtener datos del producto del pedido
    $pedido = $conn->query("
        SELECT id_orden, id_producto, cantidad_pedida, faltante
        FROM pedidos
        WHERE id = $id
    ")->fetch_assoc();

    if(!$pedido){
        throw new Exception("No se encontró el producto del pedido");
    }

    $id_orden = $pedido['id_orden'];
    $id_producto = $pedido['id_producto'];
    $cantidad_original = $pedido['cantidad_pedida'];
    $faltante = $pedido['faltante'];

    // 🔹 Cantidad que realmente llegó
    $cantidad_solventada = $faltante;

    // 2️⃣ Sumar al inventario lo que llegó
    $conn->query("
        UPDATE productos
        SET cantidad = cantidad + $faltante
        WHERE id = $id_producto
    ");

    // 3️⃣ Guardar en pedidos_solventados
    $conn->query("
        INSERT INTO pedidos_solventados
        (id_pedido, cantidad_original, cantidad_solventada, cantidad_faltante, usuario)
        VALUES
        ($id, $cantidad_original, $cantidad_solventada, 0, '$usuario')
    ");

    // 4️⃣ Guardar log
    $detalle = "Producto ID $id_producto completado. Se recibieron $faltante unidades.";

    $conn->query("
        INSERT INTO pedidos_log
        (id_pedido, accion, detalle, usuario)
        VALUES
        ($id, 'Producto completado', '$detalle', '$usuario')
    ");

    // 5️⃣ Marcar SOLO ese producto como completado (tu lógica original)
    $conn->query("
        UPDATE pedidos
        SET estado = 'completado',
            cantidad_pedida = 0,
            faltante = 0
        WHERE id = $id
    ");

    $conn->commit();

    echo json_encode(['ok'=>true]);

} catch (Exception $e) {

    $conn->rollback();
    echo json_encode([
        'ok'=>false,
        'error'=>$e->getMessage()
    ]);
}
