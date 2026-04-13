<?php
include '../includes/db.php';
session_start();

header('Content-Type: application/json');

$input = json_decode(file_get_contents("php://input"), true);

$folio   = $input['folio']   ?? null;
$forzar  = $input['forzar']  ?? false;
$usuario = $_SESSION['nombre'] ?? 'Sistema';

if (!$folio) {
    echo json_encode([
        'success' => false,
        'message' => 'Se requiere folio'
    ]);
    exit;
}

$esPedido = false;
$idOrden = null;
$total = 0;
$completados = 0;


if (preg_match('/^PEDIDO-(\d+)$/', $folio, $matches)) {

    $esPedido = true;
    $idOrden = intval($matches[1]);

    $check = $conn->prepare("
        SELECT 
            COUNT(*) as total,
            SUM(CASE WHEN estado = 'completado' THEN 1 ELSE 0 END) as completados
        FROM pedidos
        WHERE id_orden = ?
    ");
    $check->bind_param("i", $idOrden);
    $check->execute();
    $estadoPedido = $check->get_result()->fetch_assoc();
    $check->close();

    $total = intval($estadoPedido['total'] ?? 0);
    $completados = intval($estadoPedido['completados'] ?? 0);

    if ($total > 0 && $total === $completados && !$forzar) {

        echo json_encode([
            'success' => false,
            'pedido_completado' => true,
            'message' => 'Este pedido ya está completado. ¿Seguro que deseas cancelarlo?'
        ]);
        exit;
    }
}

$conn->begin_transaction();

try {

    /*
    |--------------------------------------------------------------------------
    | 1️⃣ Obtener ventas del folio
    |--------------------------------------------------------------------------
    */

    $q = $conn->prepare("
        SELECT v.id, v.id_producto, v.cantidad_vendida,
               p.cantidad AS stock_actual,
               p.proveedor
        FROM ventas v
        JOIN productos p ON v.id_producto = p.id
        WHERE v.folio_ticket = ?
    ");
    $q->bind_param("s", $folio);
    $q->execute();
    $res = $q->get_result();

    if ($res->num_rows === 0) {
        throw new Exception('No se encontraron ventas para este folio.');
    }

    $ventas = $res->fetch_all(MYSQLI_ASSOC);
    $q->close();

    /*
    |--------------------------------------------------------------------------
    | 2️⃣ Evitar doble cancelación
    |--------------------------------------------------------------------------
    */

    $ver = $conn->prepare("
        SELECT id FROM ventas_canceladas WHERE folio_ticket = ?
    ");
    $ver->bind_param("s", $folio);
    $ver->execute();

    if ($ver->get_result()->num_rows > 0) {
        $ver->close();
        throw new Exception('Esta venta ya fue cancelada anteriormente.');
    }
    $ver->close();

    /*
    |--------------------------------------------------------------------------
    | 3️⃣ Procesar productos
    |--------------------------------------------------------------------------
    */

    foreach ($ventas as $v) {

        $nuevoStock = $v['stock_actual'] + $v['cantidad_vendida'];

        // Restaurar stock
        $upStock = $conn->prepare("
            UPDATE productos SET cantidad = ? WHERE id = ?
        ");
        $upStock->bind_param("ii", $nuevoStock, $v['id_producto']);
        $upStock->execute();
        $upStock->close();

        // Ajustar reporte proveedor
        $rep = $conn->prepare("
            UPDATE reporte_proveedor
            SET ventas = GREATEST(ventas - ?, 0)
            WHERE producto_id = ?
              AND proveedor = ?
              AND DATE(fecha_conteo) = CURDATE()
        ");
        $rep->bind_param(
            "iis",
            $v['cantidad_vendida'],
            $v['id_producto'],
            $v['proveedor']
        );
        $rep->execute();
        $rep->close();

        // Registrar cancelación
        $motivo = "Cancelación total del ticket";

        $ins = $conn->prepare("
            INSERT INTO ventas_canceladas
            (folio_ticket, id_venta, cantidad_devuelta, motivo, fecha_cancelacion)
            VALUES (?, ?, ?, ?, NOW())
        ");
        $ins->bind_param(
            "siis",
            $folio,
            $v['id'],
            $v['cantidad_vendida'],
            $motivo
        );
        $ins->execute();
        $ins->close();

        // Eliminar venta
        $del = $conn->prepare("DELETE FROM ventas WHERE id = ?");
        $del->bind_param("i", $v['id']);
        $del->execute();
        $del->close();
    }

    /*
    |--------------------------------------------------------------------------
    | 4️⃣ Registrar log del pedido (CORREGIDO)
    |--------------------------------------------------------------------------
    */

    if ($esPedido && $idOrden) {

        $log = $conn->prepare("
            INSERT INTO pedidos_log
            (id_pedido, accion, detalle, usuario)
            VALUES (?, ?, ?, ?)
        ");

        if ($total > 0 && $total === $completados) {
            $accion = "Pedido completado cancelado";
            $detalle = "Se canceló un pedido que ya estaba completado (ticket $folio). Stock restaurado.";
        } else {
            $accion = "Pedido cancelado";
            $detalle = "Cancelación realizada desde ventas (ticket $folio).";
        }

        // 🔥 AQUÍ ESTÁ LA CORRECCIÓN
        // Usamos idOrden, NO el id del detalle
        $log->bind_param("isss", $idOrden, $accion, $detalle, $usuario);
        $log->execute();
        $log->close();
    }

    /*
    |--------------------------------------------------------------------------
    | ✅ Confirmar
    |--------------------------------------------------------------------------
    */

    $conn->commit();

    $mensajeFinal = "Venta cancelada y stock restaurado correctamente.";

    if ($esPedido && $total > 0 && $total === $completados) {
        $mensajeFinal = "Pedido completado cancelado y stock restaurado correctamente.";
    }

    echo json_encode([
        'success' => true,
        'message' => $mensajeFinal
    ]);

} catch (Exception $e) {

    $conn->rollback();

    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
