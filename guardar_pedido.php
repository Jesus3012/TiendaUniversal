<?php
include 'includes/db.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

header('Content-Type: application/json; charset=utf-8');

error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');

date_default_timezone_set('America/Mexico_City');

function responder_json(bool $success, string $message, array $extra = []): void
{
    echo json_encode(array_merge([
        'success' => $success,
        'message' => $message
    ], $extra), JSON_UNESCAPED_UNICODE);
    exit;
}

function limpiar_texto(?string $valor, int $max = 255): string
{
    $valor = trim((string)$valor);
    $valor = preg_replace('/\s+/', ' ', $valor);
    return substr($valor, 0, $max);
}

function columna_auto_increment(mysqli $conn, string $tabla, string $columna): bool
{
    $stmt = $conn->prepare("\n        SELECT EXTRA\n        FROM INFORMATION_SCHEMA.COLUMNS\n        WHERE TABLE_SCHEMA = DATABASE()\n          AND TABLE_NAME = ?\n          AND COLUMN_NAME = ?\n        LIMIT 1\n    ");

    if (!$stmt) {
        throw new Exception("No se pudo revisar la columna $tabla.$columna: " . $conn->error);
    }

    $stmt->bind_param('ss', $tabla, $columna);
    $stmt->execute();
    $res = $stmt->get_result();

    if (!$res || $res->num_rows === 0) {
        $stmt->close();
        throw new Exception("No existe la columna $tabla.$columna en la base actual.");
    }

    $row = $res->fetch_assoc();
    $stmt->close();

    return stripos($row['EXTRA'] ?? '', 'auto_increment') !== false;
}

function siguiente_id(mysqli $conn, string $tabla, string $columna): int
{
    $tablaLimpia = preg_replace('/[^a-zA-Z0-9_]/', '', $tabla);
    $columnaLimpia = preg_replace('/[^a-zA-Z0-9_]/', '', $columna);

    if ($tablaLimpia === '' || $columnaLimpia === '') {
        throw new Exception('Tabla o columna inválida para generar ID.');
    }

    $sql = "SELECT COALESCE(MAX(`$columnaLimpia`), 0) + 1 AS siguiente FROM `$tablaLimpia`";
    $res = $conn->query($sql);

    if (!$res) {
        throw new Exception("No se pudo generar el siguiente ID para $tablaLimpia: " . $conn->error);
    }

    $row = $res->fetch_assoc();
    return (int)($row['siguiente'] ?? 1);
}

function insertar_log_pedido(mysqli $conn, bool $logAutoIncrement, int $idOrden, string $accion, string $detalle, string $usuario): void
{
    if ($logAutoIncrement) {
        $stmt = $conn->prepare("\n            INSERT INTO pedidos_log\n                (id_pedido, accion, detalle, usuario, fecha)\n            VALUES\n                (?, ?, ?, ?, NOW())\n        ");

        if (!$stmt) {
            throw new Exception('No se pudo preparar log del pedido: ' . $conn->error);
        }

        $stmt->bind_param('isss', $idOrden, $accion, $detalle, $usuario);
    } else {
        $idLog = siguiente_id($conn, 'pedidos_log', 'id');

        $stmt = $conn->prepare("\n            INSERT INTO pedidos_log\n                (id, id_pedido, accion, detalle, usuario, fecha)\n            VALUES\n                (?, ?, ?, ?, ?, NOW())\n        ");

        if (!$stmt) {
            throw new Exception('No se pudo preparar log del pedido: ' . $conn->error);
        }

        $stmt->bind_param('iisss', $idLog, $idOrden, $accion, $detalle, $usuario);
    }

    if (!$stmt->execute()) {
        $error = $stmt->error;
        $stmt->close();
        throw new Exception('Error al guardar log del pedido: ' . $error);
    }

    $stmt->close();
}

function insertar_orden(mysqli $conn, bool $ordenAutoIncrement, string $solicitadoPor): int
{
    if ($ordenAutoIncrement) {
        $stmt = $conn->prepare("\n            INSERT INTO ordenes_pedido\n                (solicitado_por, fecha)\n            VALUES\n                (?, NOW())\n        ");

        if (!$stmt) {
            throw new Exception('No se pudo preparar la orden: ' . $conn->error);
        }

        $stmt->bind_param('s', $solicitadoPor);

        if (!$stmt->execute()) {
            $error = $stmt->error;
            $stmt->close();
            throw new Exception('Error al crear orden: ' . $error);
        }

        $stmt->close();
        $idOrden = (int)$conn->insert_id;

        if ($idOrden <= 0) {
            throw new Exception('No se pudo obtener el ID de la orden generada.');
        }

        return $idOrden;
    }

    $idOrden = siguiente_id($conn, 'ordenes_pedido', 'id_orden');

    $stmt = $conn->prepare("\n        INSERT INTO ordenes_pedido\n            (id_orden, solicitado_por, fecha)\n        VALUES\n            (?, ?, NOW())\n    ");

    if (!$stmt) {
        throw new Exception('No se pudo preparar la orden: ' . $conn->error);
    }

    $stmt->bind_param('is', $idOrden, $solicitadoPor);

    if (!$stmt->execute()) {
        $error = $stmt->error;
        $stmt->close();
        throw new Exception('Error al crear orden: ' . $error);
    }

    $stmt->close();
    return $idOrden;
}

function insertar_detalle_pedido(
    mysqli $conn,
    bool $pedidoAutoIncrement,
    int $idOrden,
    int $idProducto,
    string $nombreProducto,
    int $stockActual,
    int $cantidadPedida,
    int $faltante,
    string $solicitadoPor
): void {
    $estado = 'pendiente';

    if ($pedidoAutoIncrement) {
        $stmt = $conn->prepare("\n            INSERT INTO pedidos\n                (id_orden, id_producto, nombre_producto, stock_actual, cantidad_pedida, faltante, solicitado_por, estado)\n            VALUES\n                (?, ?, ?, ?, ?, ?, ?, ?)\n        ");

        if (!$stmt) {
            throw new Exception('No se pudo preparar el detalle del pedido: ' . $conn->error);
        }

        $stmt->bind_param('iisiisss', $idOrden, $idProducto, $nombreProducto, $stockActual, $cantidadPedida, $faltante, $solicitadoPor, $estado);
    } else {
        $idPedido = siguiente_id($conn, 'pedidos', 'id');

        $stmt = $conn->prepare("\n            INSERT INTO pedidos\n                (id, id_orden, id_producto, nombre_producto, stock_actual, cantidad_pedida, faltante, solicitado_por, estado)\n            VALUES\n                (?, ?, ?, ?, ?, ?, ?, ?, ?)\n        ");

        if (!$stmt) {
            throw new Exception('No se pudo preparar el detalle del pedido: ' . $conn->error);
        }

        $stmt->bind_param('iiisiisss', $idPedido, $idOrden, $idProducto, $nombreProducto, $stockActual, $cantidadPedida, $faltante, $solicitadoPor, $estado);
    }

    if (!$stmt->execute()) {
        $error = $stmt->error;
        $stmt->close();
        throw new Exception('Error al insertar pedido: ' . $error);
    }

    $stmt->close();
}

function insertar_venta_pedido(mysqli $conn, string $folioTicket, int $idOrden, int $idProducto, int $cantidadPedida, string $solicitadoPor): void
{
    $metodoPago = 'pedido';
    $correoCliente = $solicitadoPor;

    $stmt = $conn->prepare("\n        INSERT INTO ventas\n            (folio_ticket, id_orden, id_producto, cantidad_vendida, metodo_pago, correo_cliente, fecha_venta)\n        VALUES\n            (?, ?, ?, ?, ?, ?, NOW())\n    ");

    if (!$stmt) {
        throw new Exception('No se pudo preparar venta del pedido: ' . $conn->error);
    }

    $stmt->bind_param('siiiss', $folioTicket, $idOrden, $idProducto, $cantidadPedida, $metodoPago, $correoCliente);

    if (!$stmt->execute()) {
        $error = $stmt->error;
        $stmt->close();
        throw new Exception('Error al insertar en ventas: ' . $error);
    }

    $stmt->close();
}

$rawInput = file_get_contents('php://input');
$data = json_decode($rawInput, true);

if (!is_array($data)) {
    responder_json(false, 'La información enviada no es válida.');
}

$solicitadoPor = limpiar_texto($data['solicitado_por'] ?? '', 100);
$pedidos = $data['pedidos'] ?? [];
$usuario = $_SESSION['nombre'] ?? 'Sistema';

if ($solicitadoPor === '') {
    responder_json(false, 'Escribe para quién es el pedido.');
}

if (!is_array($pedidos) || count($pedidos) === 0) {
    responder_json(false, 'No hay productos seleccionados para guardar.');
}

$pedidosAgrupados = [];

foreach ($pedidos as $pedido) {
    $idProducto = (int)($pedido['id'] ?? 0);
    $cantidad = (int)($pedido['pedido'] ?? 0);

    if ($idProducto <= 0 || $cantidad <= 0) {
        continue;
    }

    if (!isset($pedidosAgrupados[$idProducto])) {
        $pedidosAgrupados[$idProducto] = 0;
    }

    $pedidosAgrupados[$idProducto] += $cantidad;
}

if (count($pedidosAgrupados) === 0) {
    responder_json(false, 'No hay cantidades válidas para guardar.');
}

$conn->begin_transaction();

try {
    $ordenAutoIncrement = columna_auto_increment($conn, 'ordenes_pedido', 'id_orden');
    $pedidoAutoIncrement = columna_auto_increment($conn, 'pedidos', 'id');
    $logAutoIncrement = columna_auto_increment($conn, 'pedidos_log', 'id');

    $idOrden = insertar_orden($conn, $ordenAutoIncrement, $solicitadoPor);
    $folioTicket = 'PEDIDO-' . $idOrden;

    insertar_log_pedido(
        $conn,
        $logAutoIncrement,
        $idOrden,
        'PEDIDO CREADO',
        "El usuario creó el pedido para: $solicitadoPor",
        $usuario
    );

    $productosGuardados = 0;

    foreach ($pedidosAgrupados as $idProducto => $cantidadPedida) {
        $stmtProducto = $conn->prepare("\n            SELECT id, nombre, cantidad\n            FROM productos\n            WHERE id = ?\n              AND tipo_inventario = 'producto'\n            FOR UPDATE\n        ");

        if (!$stmtProducto) {
            throw new Exception('No se pudo preparar consulta de producto: ' . $conn->error);
        }

        $stmtProducto->bind_param('i', $idProducto);
        $stmtProducto->execute();
        $resProducto = $stmtProducto->get_result();

        if (!$resProducto || $resProducto->num_rows === 0) {
            $stmtProducto->close();
            throw new Exception("No se encontró el producto con ID $idProducto o no es producto vendible.");
        }

        $producto = $resProducto->fetch_assoc();
        $stmtProducto->close();

        $nombreProducto = (string)$producto['nombre'];
        $stockActual = (int)$producto['cantidad'];
        $faltante = max(0, $cantidadPedida - $stockActual);
        $nuevoStock = $stockActual - $cantidadPedida;

        insertar_detalle_pedido(
            $conn,
            $pedidoAutoIncrement,
            $idOrden,
            $idProducto,
            $nombreProducto,
            $stockActual,
            $cantidadPedida,
            $faltante,
            $solicitadoPor
        );

        $detalleProducto = "Producto: $nombreProducto | Stock actual: $stockActual | Pedido: $cantidadPedida | Faltante: $faltante";
        insertar_log_pedido($conn, $logAutoIncrement, $idOrden, 'PRODUCTO AGREGADO', $detalleProducto, $usuario);

        insertar_venta_pedido($conn, $folioTicket, $idOrden, $idProducto, $cantidadPedida, $solicitadoPor);

        $stmtStock = $conn->prepare("UPDATE productos SET cantidad = ? WHERE id = ?");

        if (!$stmtStock) {
            throw new Exception('No se pudo preparar actualización de stock: ' . $conn->error);
        }

        $stmtStock->bind_param('ii', $nuevoStock, $idProducto);

        if (!$stmtStock->execute()) {
            $error = $stmtStock->error;
            $stmtStock->close();
            throw new Exception('Error al actualizar stock: ' . $error);
        }

        $stmtStock->close();

        if ($nuevoStock >= 0) {
            $detalleStock = "Se descontaron $cantidadPedida unidades de $nombreProducto. Stock anterior: $stockActual | Stock actual: $nuevoStock";
            insertar_log_pedido($conn, $logAutoIncrement, $idOrden, 'STOCK DESCONTADO', $detalleStock, $usuario);
        } else {
            $detalleStock = "Stock INSUFICIENTE para $nombreProducto. Se descontaron $cantidadPedida unidades. Stock anterior: $stockActual | Stock actual: $nuevoStock (NEGATIVO) | Faltante: " . abs($nuevoStock);
            insertar_log_pedido($conn, $logAutoIncrement, $idOrden, 'STOCK NEGATIVO', $detalleStock, $usuario);
        }

        $productosGuardados++;
    }

    if ($productosGuardados <= 0) {
        throw new Exception('No se guardó ningún producto en el pedido.');
    }

    $conn->commit();

    responder_json(true, 'Pedido guardado correctamente.', [
        'id_orden' => $idOrden,
        'folio_ticket' => $folioTicket,
        'productos_guardados' => $productosGuardados
    ]);
} catch (Throwable $e) {
    try {
        $conn->rollback();
    } catch (Throwable $rollbackError) {
        error_log('Error rollback guardar_pedido.php: ' . $rollbackError->getMessage());
    }

    error_log('Error guardar_pedido.php: ' . $e->getMessage());

    responder_json(false, $e->getMessage());
}
