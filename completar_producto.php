<?php
error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');

include 'includes/db.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

header('Content-Type: application/json; charset=utf-8');

function responder_json(bool $success, string $message, array $extra = []): void
{
    echo json_encode(array_merge([
        'success' => $success,
        'message' => $message,
        'error' => $success ? null : $message
    ], $extra), JSON_UNESCAPED_UNICODE);
    exit;
}

function limpiar_identificador(string $nombre): string
{
    return preg_replace('/[^a-zA-Z0-9_]/', '', $nombre);
}

function columna_auto_increment(mysqli $conn, string $tabla, string $columna = 'id'): bool
{
    $stmt = $conn->prepare("
        SELECT EXTRA
        FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = ?
          AND COLUMN_NAME = ?
        LIMIT 1
    ");

    if (!$stmt) {
        throw new Exception("No se pudo revisar AUTO_INCREMENT de $tabla.$columna: " . $conn->error);
    }

    $stmt->bind_param('ss', $tabla, $columna);
    $stmt->execute();
    $res = $stmt->get_result();

    if (!$res || $res->num_rows === 0) {
        $stmt->close();
        throw new Exception("No se encontró la columna $columna en la tabla $tabla.");
    }

    $row = $res->fetch_assoc();
    $stmt->close();

    return stripos($row['EXTRA'] ?? '', 'auto_increment') !== false;
}

function siguiente_id(mysqli $conn, string $tabla, string $columna = 'id'): int
{
    $tabla = limpiar_identificador($tabla);
    $columna = limpiar_identificador($columna);

    if ($tabla === '' || $columna === '') {
        throw new Exception('Nombre de tabla o columna inválido para generar ID.');
    }

    $sql = "SELECT COALESCE(MAX(`$columna`), 0) + 1 AS siguiente FROM `$tabla`";
    $res = $conn->query($sql);

    if (!$res) {
        throw new Exception("No se pudo generar el siguiente ID para $tabla: " . $conn->error);
    }

    $row = $res->fetch_assoc();
    return intval($row['siguiente'] ?? 1);
}

function insertar_pedidos_log(
    mysqli $conn,
    bool $logAutoIncrement,
    ?int &$idLogManual,
    int $idOrden,
    string $accion,
    string $detalle,
    string $usuario
): void {
    if ($logAutoIncrement) {
        $stmt = $conn->prepare("
            INSERT INTO pedidos_log
                (id_pedido, accion, detalle, usuario, fecha)
            VALUES
                (?, ?, ?, ?, NOW())
        ");

        if (!$stmt) {
            throw new Exception('No se pudo preparar el log del pedido: ' . $conn->error);
        }

        $stmt->bind_param('isss', $idOrden, $accion, $detalle, $usuario);
    } else {
        if ($idLogManual === null) {
            $idLogManual = siguiente_id($conn, 'pedidos_log', 'id');
        }

        $stmt = $conn->prepare("
            INSERT INTO pedidos_log
                (id, id_pedido, accion, detalle, usuario, fecha)
            VALUES
                (?, ?, ?, ?, ?, NOW())
        ");

        if (!$stmt) {
            throw new Exception('No se pudo preparar el log del pedido: ' . $conn->error);
        }

        $stmt->bind_param('iisss', $idLogManual, $idOrden, $accion, $detalle, $usuario);
    }

    if (!$stmt->execute()) {
        $error = $stmt->error;
        $stmt->close();
        throw new Exception('No se pudo guardar el log del pedido: ' . $error);
    }

    if ($stmt->affected_rows <= 0) {
        $stmt->close();
        throw new Exception('El log del pedido no fue insertado.');
    }

    $stmt->close();

    if (!$logAutoIncrement && $idLogManual !== null) {
        $idLogManual++;
    }
}

function insertar_pedido_solventado(
    mysqli $conn,
    bool $solventadosAutoIncrement,
    ?int &$idSolventadoManual,
    int $idPedido,
    ?int $idProducto,
    int $cantidadOriginal,
    int $cantidadSolventada,
    int $cantidadFaltante,
    string $usuario
): void {
    if ($solventadosAutoIncrement) {
        $stmt = $conn->prepare("
            INSERT INTO pedidos_solventados
                (id_pedido, id_producto, cantidad_original, cantidad_solventada, cantidad_faltante, usuario, fecha)
            VALUES
                (?, ?, ?, ?, ?, ?, NOW())
        ");

        if (!$stmt) {
            throw new Exception('No se pudo preparar pedidos_solventados: ' . $conn->error);
        }

        $stmt->bind_param(
            'iiiiis',
            $idPedido,
            $idProducto,
            $cantidadOriginal,
            $cantidadSolventada,
            $cantidadFaltante,
            $usuario
        );
    } else {
        if ($idSolventadoManual === null) {
            $idSolventadoManual = siguiente_id($conn, 'pedidos_solventados', 'id');
        }

        $stmt = $conn->prepare("
            INSERT INTO pedidos_solventados
                (id, id_pedido, id_producto, cantidad_original, cantidad_solventada, cantidad_faltante, usuario, fecha)
            VALUES
                (?, ?, ?, ?, ?, ?, ?, NOW())
        ");

        if (!$stmt) {
            throw new Exception('No se pudo preparar pedidos_solventados: ' . $conn->error);
        }

        $stmt->bind_param(
            'iiiiiis',
            $idSolventadoManual,
            $idPedido,
            $idProducto,
            $cantidadOriginal,
            $cantidadSolventada,
            $cantidadFaltante,
            $usuario
        );
    }

    if (!$stmt->execute()) {
        $error = $stmt->error;
        $stmt->close();
        throw new Exception('No se pudo guardar en pedidos_solventados: ' . $error);
    }

    if ($stmt->affected_rows <= 0) {
        $stmt->close();
        throw new Exception('No se insertó el registro en pedidos_solventados.');
    }

    $stmt->close();

    if (!$solventadosAutoIncrement && $idSolventadoManual !== null) {
        $idSolventadoManual++;
    }
}

function existe_log_pedido_completado(mysqli $conn, int $idOrden): bool
{
    $stmt = $conn->prepare("
        SELECT id
        FROM pedidos_log
        WHERE id_pedido = ?
          AND UPPER(accion) IN ('PEDIDO COMPLETADO', 'PEDIDO COMPLETADO AUTOMÁTICO', 'PEDIDO COMPLETADO AUTOMATICO')
        LIMIT 1
    ");

    if (!$stmt) {
        return false;
    }

    $stmt->bind_param('i', $idOrden);
    $stmt->execute();
    $res = $stmt->get_result();
    $existe = $res && $res->num_rows > 0;
    $stmt->close();

    return $existe;
}

$input = json_decode(file_get_contents('php://input'), true);

if (!is_array($input)) {
    $input = $_POST ?? [];
}

$id = intval($input['id'] ?? 0);
$usuario = $_SESSION['nombre'] ?? $_SESSION['usuario'] ?? 'Sistema';

if ($id <= 0) {
    responder_json(false, 'ID de producto del pedido inválido.');
}

try {
    $conn->begin_transaction();

    $logAutoIncrement = columna_auto_increment($conn, 'pedidos_log', 'id');
    $solventadosAutoIncrement = columna_auto_increment($conn, 'pedidos_solventados', 'id');

    $idLogManual = $logAutoIncrement ? null : siguiente_id($conn, 'pedidos_log', 'id');
    $idSolventadoManual = $solventadosAutoIncrement ? null : siguiente_id($conn, 'pedidos_solventados', 'id');

    $stmtPedido = $conn->prepare("
        SELECT id, id_orden, id_producto, nombre_producto, cantidad_pedida, faltante, estado
        FROM pedidos
        WHERE id = ?
        FOR UPDATE
    ");

    if (!$stmtPedido) {
        throw new Exception('No se pudo preparar la consulta del producto: ' . $conn->error);
    }

    $stmtPedido->bind_param('i', $id);
    $stmtPedido->execute();
    $pedido = $stmtPedido->get_result()->fetch_assoc();
    $stmtPedido->close();

    if (!$pedido) {
        throw new Exception('Producto del pedido no encontrado.');
    }

    if (($pedido['estado'] ?? '') === 'completado') {
        $idOrdenCompletado = intval($pedido['id_orden']);

        if (!existe_log_pedido_completado($conn, $idOrdenCompletado)) {
            insertar_pedidos_log(
                $conn,
                $logAutoIncrement,
                $idLogManual,
                $idOrdenCompletado,
                'PEDIDO COMPLETADO',
                'El producto ya estaba completado. Se verificó el historial automáticamente.',
                $usuario
            );
        }

        $conn->commit();

        responder_json(true, 'El producto ya estaba completado.', [
            'id' => $id,
            'id_orden' => $idOrdenCompletado,
            'ya_estaba_completado' => true
        ]);
    }

    if (($pedido['estado'] ?? '') !== 'pendiente') {
        throw new Exception('Este producto no está pendiente y no puede completarse.');
    }

    $idPedido = intval($pedido['id']);
    $idOrden = intval($pedido['id_orden']);
    $idProducto = $pedido['id_producto'] !== null ? intval($pedido['id_producto']) : null;
    $nombre = trim((string)($pedido['nombre_producto'] ?? 'Producto sin nombre'));
    $cantidadOriginal = max(0, intval($pedido['cantidad_pedida'] ?? 0));
    $faltanteOriginal = max(0, intval($pedido['faltante'] ?? 0));

    // Al completar el artículo, queda cubierto al 100%.
    $cantidadSolventada = $cantidadOriginal;

    insertar_pedido_solventado(
        $conn,
        $solventadosAutoIncrement,
        $idSolventadoManual,
        $idPedido,
        $idProducto,
        $cantidadOriginal,
        $cantidadSolventada,
        $faltanteOriginal,
        $usuario
    );

    insertar_pedidos_log(
        $conn,
        $logAutoIncrement,
        $idLogManual,
        $idOrden,
        'PRODUCTO COMPLETADO',
        "Producto: $nombre | Cantidad pedida: $cantidadOriginal | Faltante anterior: $faltanteOriginal | Cantidad completada: $cantidadSolventada",
        $usuario
    );

    $stmtUpdate = $conn->prepare("
        UPDATE pedidos
        SET estado = 'completado',
            faltante = 0,
            fecha_completado = NOW()
        WHERE id = ?
          AND estado = 'pendiente'
    ");

    if (!$stmtUpdate) {
        throw new Exception('No se pudo preparar actualización del producto: ' . $conn->error);
    }

    $stmtUpdate->bind_param('i', $idPedido);

    if (!$stmtUpdate->execute()) {
        $error = $stmtUpdate->error;
        $stmtUpdate->close();
        throw new Exception('No se pudo marcar el producto como completado: ' . $error);
    }

    if ($stmtUpdate->affected_rows <= 0) {
        $stmtUpdate->close();
        throw new Exception('No se actualizó el producto. Puede que ya no esté pendiente.');
    }

    $stmtUpdate->close();

    $stmtPendientes = $conn->prepare("
        SELECT COUNT(*) AS pendientes
        FROM pedidos
        WHERE id_orden = ?
          AND estado = 'pendiente'
    ");

    if (!$stmtPendientes) {
        throw new Exception('No se pudo verificar pendientes del pedido: ' . $conn->error);
    }

    $stmtPendientes->bind_param('i', $idOrden);
    $stmtPendientes->execute();
    $pendientes = intval($stmtPendientes->get_result()->fetch_assoc()['pendientes'] ?? 0);
    $stmtPendientes->close();

    $pedidoCompletado = false;

    if ($pendientes === 0) {
        $stmtVenta = $conn->prepare("
            UPDATE ventas
            SET metodo_pago = 'completado'
            WHERE id_orden = ?
        ");

        if (!$stmtVenta) {
            throw new Exception('No se pudo preparar actualización de ventas: ' . $conn->error);
        }

        $stmtVenta->bind_param('i', $idOrden);

        if (!$stmtVenta->execute()) {
            $error = $stmtVenta->error;
            $stmtVenta->close();
            throw new Exception('No se pudo actualizar ventas: ' . $error);
        }

        $stmtVenta->close();

        if (!existe_log_pedido_completado($conn, $idOrden)) {
            insertar_pedidos_log(
                $conn,
                $logAutoIncrement,
                $idLogManual,
                $idOrden,
                'PEDIDO COMPLETADO',
                'Todos los productos del pedido han sido completados.',
                $usuario
            );
        }

        $pedidoCompletado = true;
    }

    $conn->commit();

    responder_json(true, $pedidoCompletado
        ? 'Producto completado. El pedido completo también quedó finalizado.'
        : 'Producto completado y log guardado correctamente.', [
        'id' => $idPedido,
        'id_orden' => $idOrden,
        'pedido_completado' => $pedidoCompletado,
        'producto' => $nombre
    ]);
} catch (Throwable $e) {
    try {
        $conn->rollback();
    } catch (Throwable $rollbackError) {
        error_log('Rollback completar_producto.php: ' . $rollbackError->getMessage());
    }

    error_log('Error completar_producto.php: ' . $e->getMessage());

    responder_json(false, $e->getMessage());
}
?>
