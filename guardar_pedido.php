<?php
include 'includes/db.php';
include 'includes/utils.php';
session_start();

$usuario = $_SESSION['nombre'] ?? 'Sistema';

$data = json_decode(file_get_contents("php://input"), true);

$solicitado_por = $data['solicitado_por'];
$pedidos = $data['pedidos'];

$conn->begin_transaction();

try {

    // 1️⃣ Crear ORDEN
    $stmtOrden = $conn->prepare("
        INSERT INTO ordenes_pedido (solicitado_por)
        VALUES (?)
    ");
    $stmtOrden->bind_param("s", $solicitado_por);
    $stmtOrden->execute();

    $id_orden = $stmtOrden->insert_id;

    logPedido(
        $conn,
        $id_orden,
        'PEDIDO CREADO',
        "El usuario creo el pedido para: $solicitado_por",
        $usuario
    );

    // ✅ FOLIO CORRECTO
    $folio_ticket = 'PEDIDO-' . $id_orden;

    $stmtPedido = $conn->prepare("
        INSERT INTO pedidos 
        (id_orden, id_producto, nombre_producto, stock_actual, cantidad_pedida, faltante, solicitado_por)
        VALUES (?, ?, ?, ?, ?, ?, ?)
    ");

    $stmtVenta = $conn->prepare("
        INSERT INTO ventas
        (folio_ticket, id_orden, id_producto, cantidad_vendida, metodo_pago, correo_cliente)
        VALUES (?, ?, ?, ?, 'pedido', ?)
    ");

    $stmtStock = $conn->prepare("
        UPDATE productos
        SET cantidad = cantidad - ?
        WHERE id = ? AND cantidad >= ?
    ");

    foreach ($pedidos as $p) {

        $stmtPedido->bind_param(
            "iisiiis",
            $id_orden,
            $p['id'],
            $p['nombre'],
            $p['stock'],
            $p['pedido'],
            $p['faltante'],
            $solicitado_por
        );
        $stmtPedido->execute();

        logPedido(
            $conn,
            $id_orden,
            'PRODUCTO AGREGADO AL PEDIDO',
            "Producto {$p['nombre']} | Stock: {$p['stock']} | Pedido: {$p['pedido']} | Faltante: {$p['faltante']}",
            $usuario,
            $p['id']
        );

        $stmtVenta->bind_param(
            "siiis",
            $folio_ticket,
            $id_orden,
            $p['id'],
            $p['pedido'],
            $solicitado_por
        );
        $stmtVenta->execute();

        $stmtStock->bind_param(
            "iii",
            $p['pedido'],
            $p['id'],
            $p['pedido']
        );
        $stmtStock->execute();

        if ($stmtStock->affected_rows === 0) {
            throw new Exception("Stock insuficiente para {$p['nombre']}");
        }

        logPedido(
            $conn,
            $id_orden,
            'STOCK DESCONTADO',
            "Se descontaron {$p['pedido']} unidades de {$p['nombre']} del inventario",
            $usuario,
            $p['id']
        );
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
