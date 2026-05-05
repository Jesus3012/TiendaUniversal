<?php
include '../includes/db.php';
session_start();

header('Content-Type: application/json');

// Habilitar errores para depuración
error_reporting(E_ALL);
ini_set('display_errors', 0); // No mostrar en pantalla
ini_set('log_errors', 1);

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

// Verificar si es un pedido (formato PEDIDO-XX)
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
    // 1️⃣ Obtener ventas del folio
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

    // 2️⃣ Evitar doble cancelación
    $ver = $conn->prepare("SELECT id FROM ventas_canceladas WHERE folio_ticket = ?");
    $ver->bind_param("s", $folio);
    $ver->execute();

    if ($ver->get_result()->num_rows > 0) {
        $ver->close();
        throw new Exception('Esta venta ya fue cancelada anteriormente.');
    }
    $ver->close();

    // 3️⃣ Procesar productos (restaurar stock)
    foreach ($ventas as $v) {
        $nuevoStock = $v['stock_actual'] + $v['cantidad_vendida'];

        $upStock = $conn->prepare("UPDATE productos SET cantidad = ? WHERE id = ?");
        $upStock->bind_param("ii", $nuevoStock, $v['id_producto']);
        $upStock->execute();
        $upStock->close();

        $rep = $conn->prepare("
            UPDATE reporte_proveedor
            SET ventas = GREATEST(ventas - ?, 0)
            WHERE producto_id = ? AND proveedor = ? AND DATE(fecha_conteo) = CURDATE()
        ");
        $rep->bind_param("iis", $v['cantidad_vendida'], $v['id_producto'], $v['proveedor']);
        $rep->execute();
        $rep->close();

        $motivo = "Cancelación total del ticket";
        $ins = $conn->prepare("
            INSERT INTO ventas_canceladas
            (folio_ticket, id_venta, cantidad_devuelta, motivo, fecha_cancelacion)
            VALUES (?, ?, ?, ?, NOW())
        ");
        $ins->bind_param("siis", $folio, $v['id'], $v['cantidad_vendida'], $motivo);
        $ins->execute();
        $ins->close();

        $del = $conn->prepare("DELETE FROM ventas WHERE id = ?");
        $del->bind_param("i", $v['id']);
        $del->execute();
        $del->close();
    }

    // 4️⃣ Marcar pedido como CANCELADO (TABLA pedidos)
    if ($esPedido && $idOrden) {
        $updatePedido = $conn->prepare("
            UPDATE pedidos 
            SET estado = 'cancelado' 
            WHERE id_orden = ?
        ");
        $updatePedido->bind_param("i", $idOrden);
        $updatePedido->execute();
        $updatePedido->close();
    }

    // 5️⃣ Actualizar ordenes_pedido con fecha de cancelación (CORREGIDO)
    if ($esPedido && $idOrden) {
        // Verificar si existe la tabla y la columna
        $checkTable = $conn->query("SHOW TABLES LIKE 'ordenes_pedido'");
        if ($checkTable && $checkTable->num_rows > 0) {
            
            // Verificar si existe la columna fecha_cancelacion
            $checkColumn = $conn->query("SHOW COLUMNS FROM ordenes_pedido LIKE 'fecha_cancelacion'");
            if ($checkColumn && $checkColumn->num_rows > 0) {
                
                // Verificar si existe la columna estado
                $checkEstado = $conn->query("SHOW COLUMNS FROM ordenes_pedido LIKE 'estado'");
                
                if ($checkEstado && $checkEstado->num_rows > 0) {
                    // Si tiene columna estado
                    $updateOrden = $conn->prepare("
                        UPDATE ordenes_pedido 
                        SET fecha_cancelacion = NOW(), estado = 'cancelado'
                        WHERE id_orden = ?
                    ");
                } else {
                    // Si no tiene columna estado
                    $updateOrden = $conn->prepare("
                        UPDATE ordenes_pedido 
                        SET fecha_cancelacion = NOW()
                        WHERE id_orden = ?
                    ");
                }
                
                $updateOrden->bind_param("i", $idOrden);
                $updateOrden->execute();
                
                if ($updateOrden->affected_rows > 0) {
                    error_log("ordenes_pedido actualizado para id_orden: $idOrden");
                } else {
                    error_log("No se actualizó ordenes_pedido para id_orden: $idOrden");
                }
                $updateOrden->close();
                
            } else {
                error_log("La columna 'fecha_cancelacion' no existe en ordenes_pedido");
            }
        } else {
            error_log("La tabla 'ordenes_pedido' no existe");
        }
    }

    // 6️⃣ Registrar log del pedido
    if ($esPedido && $idOrden) {
        $log = $conn->prepare("
            INSERT INTO pedidos_log
            (id_pedido, accion, detalle, usuario, fecha)
            VALUES (?, ?, ?, ?, NOW())
        ");

        if ($total > 0 && $total === $completados) {
            $accion = "Pedido completado cancelado";
            $detalle = "Se canceló un pedido que ya estaba completado (ticket $folio). Stock restaurado. Estado actual: cancelado";
        } else {
            $accion = "Pedido cancelado";
            $detalle = "Cancelación realizada desde ventas (ticket $folio). Estado actual: cancelado";
        }

        $log->bind_param("isss", $idOrden, $accion, $detalle, $usuario);
        $log->execute();
        $log->close();
    }

    $conn->commit();

    $mensajeFinal = "Venta cancelada y stock restaurado correctamente.";

    if ($esPedido && $total > 0 && $total === $completados) {
        $mensajeFinal = "Pedido completado cancelado y stock restaurado correctamente. El pedido ha sido marcado como cancelado.";
    } elseif ($esPedido) {
        $mensajeFinal = "Pedido cancelado, stock restaurado. El pedido ha sido marcado como cancelado.";
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

$conn->close();
?>