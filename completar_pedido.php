<?php
include 'includes/db.php';

header('Content-Type: application/json');
session_start();

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$data = json_decode(file_get_contents("php://input"), true);

$folio = intval($data['folio']);
$usuario = $_SESSION['nombre'] ?? 'Sistema';

$conn->begin_transaction();

try {

    // 1️⃣ Obtener TODOS los productos del pedido
    $stmt = $conn->prepare("
        SELECT id, nombre_producto, cantidad_pedida, faltante 
        FROM pedidos 
        WHERE id_orden = ?
    ");
    $stmt->bind_param("i", $folio);
    $stmt->execute();
    $res = $stmt->get_result();

    if($res->num_rows == 0){
        throw new Exception("Pedido no encontrado");
    }

    while($row = $res->fetch_assoc()) {

        $id_producto = $row['id'];
        $nombre = $row['nombre_producto'];
        $cantidad_original = (int)$row['cantidad_pedida'];
        $faltante = (int)$row['faltante'];
        $cantidad_solventada = max(0, $cantidad_original - $faltante);

        // 2️⃣ Guardar en pedidos_solventados (UNO POR PRODUCTO)
        $stmt2 = $conn->prepare("
            INSERT INTO pedidos_solventados
            (id_pedido, id_producto, cantidad_original, cantidad_solventada, cantidad_faltante, usuario)
            VALUES (?, ?, ?, ?, ?, ?)
        ");
        $stmt2->bind_param(
            "iiiiis",
            $folio,
            $id_producto,
            $cantidad_original,
            $cantidad_solventada,
            $faltante,
            $usuario
        );
        $stmt2->execute();

        // 3️⃣ Guardar log por producto
        $accion = "Pedido completado";
        $detalle = "Producto: $nombre | Original: $cantidad_original | Solventadas: $cantidad_solventada | Faltante: $faltante";

        $stmt3 = $conn->prepare("
            INSERT INTO pedidos_log
            (id_pedido, accion, detalle, usuario)
            VALUES (?, ?, ?, ?)
        ");
        $stmt3->bind_param("isss", $folio, $accion, $detalle, $usuario);
        $stmt3->execute();
    }

    // 4️⃣ Actualizar TODOS los productos del pedido
    $stmt4 = $conn->prepare("
        UPDATE pedidos
        SET estado = 'completado',
            cantidad_pedida = 0,
            faltante = 0
        WHERE id_orden = ?
    ");
    $stmt4->bind_param("i", $folio);
    $stmt4->execute();

    $conn->commit();

    echo json_encode(['ok'=>true]);

} catch (Throwable $e) {

    $conn->rollback();

    echo json_encode([
        'error' => true,
        'mensaje' => $e->getMessage()
    ]);
}
