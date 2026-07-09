<?php
include '../includes/db.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

header('Content-Type: application/json; charset=utf-8');

error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');

function responder_json(bool $success, string $message, array $extra = []): void
{
    echo json_encode(array_merge([
        'success' => $success,
        'message' => $message
    ], $extra), JSON_UNESCAPED_UNICODE);
    exit;
}

function tabla_existe(mysqli $conn, string $tabla): bool
{
    $stmt = $conn->prepare("
        SELECT COUNT(*) AS total
        FROM INFORMATION_SCHEMA.TABLES
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = ?
    ");

    if (!$stmt) {
        return false;
    }

    $stmt->bind_param('s', $tabla);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return intval($row['total'] ?? 0) > 0;
}

function columna_existe(mysqli $conn, string $tabla, string $columna): bool
{
    $stmt = $conn->prepare("
        SELECT COUNT(*) AS total
        FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = ?
          AND COLUMN_NAME = ?
    ");

    if (!$stmt) {
        return false;
    }

    $stmt->bind_param('ss', $tabla, $columna);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return intval($row['total'] ?? 0) > 0;
}

function columna_auto_increment(mysqli $conn, string $tabla, string $columna): bool
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
        throw new Exception("No se encontró la columna $columna en $tabla.");
    }

    $row = $res->fetch_assoc();
    $stmt->close();

    return stripos($row['EXTRA'] ?? '', 'auto_increment') !== false;
}

function siguiente_id_tabla(mysqli $conn, string $tabla, string $columna = 'id'): int
{
    $tablaSegura = preg_replace('/[^a-zA-Z0-9_]/', '', $tabla);
    $columnaSegura = preg_replace('/[^a-zA-Z0-9_]/', '', $columna);

    if ($tablaSegura === '' || $columnaSegura === '') {
        throw new Exception('Nombre de tabla o columna inválido para calcular siguiente ID.');
    }

    $res = $conn->query("SELECT COALESCE(MAX(`$columnaSegura`), 0) + 1 AS siguiente FROM `$tablaSegura`");

    if (!$res) {
        throw new Exception("No se pudo calcular el siguiente ID de $tablaSegura: " . $conn->error);
    }

    $row = $res->fetch_assoc();

    return intval($row['siguiente'] ?? 1);
}

function existe_fk_canceladas_hacia_ventas(mysqli $conn): bool
{
    $sql = "
        SELECT COUNT(*) AS total
        FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'ventas_canceladas'
          AND COLUMN_NAME = 'id_venta'
          AND REFERENCED_TABLE_NAME = 'ventas'
          AND REFERENCED_COLUMN_NAME = 'id'
    ";

    $res = $conn->query($sql);

    if (!$res) {
        return false;
    }

    $row = $res->fetch_assoc();

    return intval($row['total'] ?? 0) > 0;
}

function generar_cancelacion_uid(string $folio): string
{
    try {
        $random = bin2hex(random_bytes(8));
    } catch (Throwable $e) {
        $random = str_replace('.', '', uniqid('', true));
    }

    $folioLimpio = preg_replace('/[^a-zA-Z0-9_-]/', '_', $folio);
    $folioLimpio = substr($folioLimpio, 0, 30);

    return 'CAN-' . date('YmdHis') . '-' . $folioLimpio . '-' . $random;
}

function insertar_log_cancelacion_unificado(
    mysqli $conn,
    bool $idAutoIncrement,
    ?int $idManual,
    string $folio,
    int $cantidadDevuelta,
    ?string $motivo,
    string $cancelacionUid
): void {
    $tieneCancelacionUid = columna_existe($conn, 'ventas_canceladas', 'cancelacion_uid');

    if ($tieneCancelacionUid) {
        if ($idAutoIncrement) {
            $stmt = $conn->prepare("
                INSERT INTO `ventas_canceladas`
                    (`cancelacion_uid`, `id_venta`, `cantidad_devuelta`, `motivo`, `fecha_cancelacion`, `folio_ticket`)
                VALUES
                    (?, NULL, ?, ?, NOW(), ?)
            ");

            if (!$stmt) {
                throw new Exception('No se pudo preparar el log de venta cancelada: ' . $conn->error);
            }

            $stmt->bind_param('siss', $cancelacionUid, $cantidadDevuelta, $motivo, $folio);
        } else {
            if ($idManual === null) {
                throw new Exception('No se pudo generar el ID manual para ventas_canceladas.');
            }

            $stmt = $conn->prepare("
                INSERT INTO `ventas_canceladas`
                    (`id`, `cancelacion_uid`, `id_venta`, `cantidad_devuelta`, `motivo`, `fecha_cancelacion`, `folio_ticket`)
                VALUES
                    (?, ?, NULL, ?, ?, NOW(), ?)
            ");

            if (!$stmt) {
                throw new Exception('No se pudo preparar el log de venta cancelada: ' . $conn->error);
            }

            $stmt->bind_param('isiss', $idManual, $cancelacionUid, $cantidadDevuelta, $motivo, $folio);
        }
    } else {
        // Respaldo por si en otra BD todavía no existe cancelacion_uid.
        if ($idAutoIncrement) {
            $stmt = $conn->prepare("
                INSERT INTO `ventas_canceladas`
                    (`id_venta`, `cantidad_devuelta`, `motivo`, `fecha_cancelacion`, `folio_ticket`)
                VALUES
                    (NULL, ?, ?, NOW(), ?)
            ");

            if (!$stmt) {
                throw new Exception('No se pudo preparar el log de venta cancelada: ' . $conn->error);
            }

            $stmt->bind_param('iss', $cantidadDevuelta, $motivo, $folio);
        } else {
            if ($idManual === null) {
                throw new Exception('No se pudo generar el ID manual para ventas_canceladas.');
            }

            $stmt = $conn->prepare("
                INSERT INTO `ventas_canceladas`
                    (`id`, `id_venta`, `cantidad_devuelta`, `motivo`, `fecha_cancelacion`, `folio_ticket`)
                VALUES
                    (?, NULL, ?, ?, NOW(), ?)
            ");

            if (!$stmt) {
                throw new Exception('No se pudo preparar el log de venta cancelada: ' . $conn->error);
            }

            $stmt->bind_param('iiss', $idManual, $cantidadDevuelta, $motivo, $folio);
        }
    }

    if (!$stmt->execute()) {
        $error = $stmt->error;
        $stmt->close();
        throw new Exception('No se pudo guardar el registro en ventas_canceladas: ' . $error);
    }

    if ($stmt->affected_rows <= 0) {
        $stmt->close();
        throw new Exception('El registro de ventas_canceladas no fue insertado.');
    }

    $stmt->close();
}

function contar_logs_cancelacion(mysqli $conn, string $folio): int
{
    $stmt = $conn->prepare("
        SELECT COUNT(*) AS total
        FROM `ventas_canceladas`
        WHERE `folio_ticket` = ?
    ");

    if (!$stmt) {
        throw new Exception('No se pudo contar el log de venta cancelada: ' . $conn->error);
    }

    $stmt->bind_param('s', $folio);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return intval($row['total'] ?? 0);
}

function contar_logs_cancelacion_uid(mysqli $conn, string $cancelacionUid): int
{
    if (!columna_existe($conn, 'ventas_canceladas', 'cancelacion_uid')) {
        return 0;
    }

    $stmt = $conn->prepare("
        SELECT COUNT(*) AS total
        FROM `ventas_canceladas`
        WHERE `cancelacion_uid` = ?
    ");

    if (!$stmt) {
        throw new Exception('No se pudo contar el log por cancelacion_uid: ' . $conn->error);
    }

    $stmt->bind_param('s', $cancelacionUid);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return intval($row['total'] ?? 0);
}

function insertar_log_pedido_cancelado(
    mysqli $conn,
    int $idOrden,
    string $folio,
    string $usuario,
    ?string $motivo,
    int $cantidadTotalCancelada,
    int $productosProcesados,
    bool $pedidoEstabaCompletado
): void {
    if (!tabla_existe($conn, 'pedidos_log')) {
        throw new Exception('No existe la tabla pedidos_log; no se puede guardar el historial de cancelación del pedido.');
    }

    $accion = $pedidoEstabaCompletado ? 'PEDIDO COMPLETADO CANCELADO' : 'PEDIDO CANCELADO';

    $detalle = $pedidoEstabaCompletado
        ? "Se canceló un pedido que ya estaba completado. Ticket: $folio. Stock restaurado. Productos procesados: $productosProcesados. Cantidad total cancelada: $cantidadTotalCancelada."
        : "Se canceló el pedido desde historial de ventas. Ticket: $folio. Stock restaurado. Productos procesados: $productosProcesados. Cantidad total cancelada: $cantidadTotalCancelada.";

    if ($motivo !== null && trim($motivo) !== '') {
        $detalle .= " Motivo: $motivo";
    } else {
        $detalle .= ' Motivo: sin motivo capturado.';
    }

    $idAutoIncrement = columna_auto_increment($conn, 'pedidos_log', 'id');

    if ($idAutoIncrement) {
        $stmt = $conn->prepare("
            INSERT INTO `pedidos_log`
                (`id_pedido`, `accion`, `detalle`, `usuario`, `fecha`)
            VALUES
                (?, ?, ?, ?, NOW())
        ");

        if (!$stmt) {
            throw new Exception('No se pudo preparar el log de cancelación en pedidos_log: ' . $conn->error);
        }

        $stmt->bind_param('isss', $idOrden, $accion, $detalle, $usuario);
    } else {
        $idLog = siguiente_id_tabla($conn, 'pedidos_log', 'id');

        $stmt = $conn->prepare("
            INSERT INTO `pedidos_log`
                (`id`, `id_pedido`, `accion`, `detalle`, `usuario`, `fecha`)
            VALUES
                (?, ?, ?, ?, ?, NOW())
        ");

        if (!$stmt) {
            throw new Exception('No se pudo preparar el log de cancelación en pedidos_log: ' . $conn->error);
        }

        $stmt->bind_param('iisss', $idLog, $idOrden, $accion, $detalle, $usuario);
    }

    if (!$stmt->execute()) {
        $error = $stmt->error;
        $stmt->close();
        throw new Exception('No se pudo guardar el log de cancelación del pedido: ' . $error);
    }

    if ($stmt->affected_rows <= 0) {
        $stmt->close();
        throw new Exception('El log de cancelación del pedido no fue insertado.');
    }

    $stmt->close();
}

function contar_logs_pedido_cancelado(mysqli $conn, int $idOrden): int
{
    $acciones = [
        'PEDIDO CANCELADO',
        'PEDIDO COMPLETADO CANCELADO',
        'Pedido cancelado',
        'Pedido completado cancelado'
    ];

    $stmt = $conn->prepare("
        SELECT COUNT(*) AS total
        FROM `pedidos_log`
        WHERE `id_pedido` = ?
          AND `accion` IN (?, ?, ?, ?)
    ");

    if (!$stmt) {
        throw new Exception('No se pudo verificar el log de cancelación del pedido: ' . $conn->error);
    }

    $stmt->bind_param('issss', $idOrden, $acciones[0], $acciones[1], $acciones[2], $acciones[3]);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return intval($row['total'] ?? 0);
}

$input = json_decode(file_get_contents('php://input'), true);

if (!is_array($input)) {
    $input = $_POST ?? [];
}

$folio = trim((string)($input['folio'] ?? ''));
$forzar = filter_var($input['forzar'] ?? false, FILTER_VALIDATE_BOOLEAN);
$motivoRecibido = trim((string)($input['motivo'] ?? ''));
$motivo = $motivoRecibido !== '' ? $motivoRecibido : null;
$usuario = $_SESSION['nombre'] ?? 'Sistema';

if ($folio === '') {
    responder_json(false, 'Se requiere folio');
}

$esPedido = false;
$idOrden = null;
$total = 0;
$completados = 0;
$pedidoEstabaCompletado = false;
$cancelacionUid = null;
$logsFinales = 0;

try {
    if (!tabla_existe($conn, 'ventas_canceladas')) {
        $base = defined('DB_NAME') ? DB_NAME : 'base actual';
        throw new Exception('No se encontró la tabla ventas_canceladas en la base conectada: ' . $base);
    }

    if (preg_match('/^PEDIDO-(\d+)$/', $folio, $matches)) {
        $esPedido = true;
        $idOrden = intval($matches[1]);

        $check = $conn->prepare("
            SELECT
                COUNT(*) AS total,
                SUM(CASE WHEN estado = 'completado' THEN 1 ELSE 0 END) AS completados
            FROM pedidos
            WHERE id_orden = ?
        ");

        if (!$check) {
            throw new Exception('No se pudo verificar el estado del pedido: ' . $conn->error);
        }

        $check->bind_param('i', $idOrden);
        $check->execute();
        $estadoPedido = $check->get_result()->fetch_assoc();
        $check->close();

        $total = intval($estadoPedido['total'] ?? 0);
        $completados = intval($estadoPedido['completados'] ?? 0);
        $pedidoEstabaCompletado = ($total > 0 && $total === $completados);

        if ($pedidoEstabaCompletado && !$forzar) {
            responder_json(false, 'Este pedido ya está completado. ¿Seguro que deseas cancelarlo?', [
                'pedido_completado' => true
            ]);
        }
    }

    $conn->begin_transaction();

    $idAutoIncrementVentasCanceladas = columna_auto_increment($conn, 'ventas_canceladas', 'id');
    $idManualVentasCanceladas = $idAutoIncrementVentasCanceladas ? null : siguiente_id_tabla($conn, 'ventas_canceladas', 'id');

    $q = $conn->prepare("
        SELECT
            v.id,
            v.id_producto,
            v.cantidad_vendida,
            p.cantidad AS stock_actual,
            p.proveedor
        FROM ventas v
        INNER JOIN productos p ON v.id_producto = p.id
        WHERE v.folio_ticket = ?
        FOR UPDATE
    ");

    if (!$q) {
        throw new Exception('No se pudo preparar la consulta de ventas: ' . $conn->error);
    }

    $q->bind_param('s', $folio);
    $q->execute();
    $res = $q->get_result();

    if (!$res || $res->num_rows === 0) {
        $q->close();
        throw new Exception('No se encontraron ventas activas para este folio. Es posible que esta venta ya haya sido cancelada o eliminada.');
    }

    $ventas = $res->fetch_all(MYSQLI_ASSOC);
    $q->close();

    /*
        IMPORTANTE:
        Ya NO se valida si existe el folio en ventas_canceladas.
        Antes se hacía algo como:
        SELECT id FROM ventas_canceladas WHERE folio_ticket = ? LIMIT 1

        Eso causaba el conflicto cuando una venta nueva usaba un folio que ya había sido cancelado antes.
        Ahora cada cancelación se identifica por cancelacion_uid.
    */

    $cantidadTotalCancelada = 0;

    foreach ($ventas as $v) {
        $cantidadTotalCancelada += intval($v['cantidad_vendida']);
    }

    if ($cantidadTotalCancelada <= 0) {
        throw new Exception('La cantidad total a cancelar no es válida.');
    }

    $logsFolioAntes = contar_logs_cancelacion($conn, $folio);
    $cancelacionUid = generar_cancelacion_uid($folio);

    insertar_log_cancelacion_unificado(
        $conn,
        $idAutoIncrementVentasCanceladas,
        $idManualVentasCanceladas,
        $folio,
        $cantidadTotalCancelada,
        $motivo,
        $cancelacionUid
    );

    $productosProcesados = 0;

    foreach ($ventas as $v) {
        $idVenta = intval($v['id']);
        $idProducto = intval($v['id_producto']);
        $cantidadVendida = intval($v['cantidad_vendida']);
        $proveedor = trim((string)($v['proveedor'] ?? ''));

        if ($idVenta <= 0 || $idProducto <= 0 || $cantidadVendida <= 0) {
            throw new Exception('Se detectó un registro de venta inválido. No se puede continuar con la cancelación.');
        }

        $upStock = $conn->prepare("
            UPDATE `productos`
            SET `cantidad` = `cantidad` + ?
            WHERE `id` = ?
        ");

        if (!$upStock) {
            throw new Exception('No se pudo preparar la restauración de stock: ' . $conn->error);
        }

        $upStock->bind_param('ii', $cantidadVendida, $idProducto);

        if (!$upStock->execute()) {
            $error = $upStock->error;
            $upStock->close();
            throw new Exception('No se pudo restaurar el stock: ' . $error);
        }

        $upStock->close();

        if ($proveedor !== '') {
            $rep = $conn->prepare("
                UPDATE reporte_proveedor
                SET ventas = GREATEST(ventas - ?, 0)
                WHERE producto_id = ?
                  AND proveedor = ?
                  AND DATE(fecha_conteo) = CURDATE()
            ");

            if ($rep) {
                $rep->bind_param('iis', $cantidadVendida, $idProducto, $proveedor);
                $rep->execute();
                $rep->close();
            }
        }

        $del = $conn->prepare("
            DELETE FROM `ventas`
            WHERE `id` = ?
        ");

        if (!$del) {
            throw new Exception('No se pudo preparar la eliminación de ventas: ' . $conn->error);
        }

        $del->bind_param('i', $idVenta);

        if (!$del->execute()) {
            $error = $del->error;
            $del->close();
            throw new Exception('No se pudo eliminar la venta original: ' . $error);
        }

        if ($del->affected_rows <= 0) {
            $del->close();
            throw new Exception('No se eliminó la venta original. Se revierte la operación para evitar descuadre de stock.');
        }

        $del->close();
        $productosProcesados++;
    }

    $logsFinales = contar_logs_cancelacion($conn, $folio);

    if (columna_existe($conn, 'ventas_canceladas', 'cancelacion_uid')) {
        $logsUidFinales = contar_logs_cancelacion_uid($conn, $cancelacionUid);

        if ($logsUidFinales !== 1) {
            $mensajeFk = existe_fk_canceladas_hacia_ventas($conn)
                ? ' Detecté una relación de ventas_canceladas.id_venta hacia ventas.id. El log se guarda con id_venta NULL, pero revisa que la columna permita NULL.'
                : '';

            throw new Exception('La venta no se canceló porque el log único de cancelación no quedó guardado correctamente en ventas_canceladas.' . $mensajeFk);
        }
    } else {
        if ($logsFinales <= $logsFolioAntes) {
            $mensajeFk = existe_fk_canceladas_hacia_ventas($conn)
                ? ' Detecté una relación de ventas_canceladas.id_venta hacia ventas.id. El log se guarda con id_venta NULL, pero revisa que la columna permita NULL.'
                : '';

            throw new Exception('La venta no se canceló porque no se insertó un nuevo log en ventas_canceladas.' . $mensajeFk);
        }
    }

    if ($productosProcesados <= 0) {
        throw new Exception('No se procesó ningún producto de la venta.');
    }

    if ($esPedido && $idOrden) {
        $updatePedido = $conn->prepare("
            UPDATE `pedidos`
            SET `estado` = 'cancelado'
            WHERE `id_orden` = ?
        ");

        if (!$updatePedido) {
            throw new Exception('No se pudo preparar actualización del pedido: ' . $conn->error);
        }

        $updatePedido->bind_param('i', $idOrden);

        if (!$updatePedido->execute()) {
            $error = $updatePedido->error;
            $updatePedido->close();
            throw new Exception('No se pudo marcar el pedido como cancelado: ' . $error);
        }

        $updatePedido->close();
    }

    if ($esPedido && $idOrden && tabla_existe($conn, 'ordenes_pedido')) {
        $tieneFechaCancelacion = columna_existe($conn, 'ordenes_pedido', 'fecha_cancelacion');
        $tieneEstado = columna_existe($conn, 'ordenes_pedido', 'estado');

        if ($tieneFechaCancelacion && $tieneEstado) {
            $updateOrden = $conn->prepare("
                UPDATE `ordenes_pedido`
                SET `fecha_cancelacion` = NOW(),
                    `estado` = 'cancelado'
                WHERE `id_orden` = ?
            ");
        } elseif ($tieneFechaCancelacion) {
            $updateOrden = $conn->prepare("
                UPDATE `ordenes_pedido`
                SET `fecha_cancelacion` = NOW()
                WHERE `id_orden` = ?
            ");
        } elseif ($tieneEstado) {
            $updateOrden = $conn->prepare("
                UPDATE `ordenes_pedido`
                SET `estado` = 'cancelado'
                WHERE `id_orden` = ?
            ");
        } else {
            $updateOrden = null;
        }

        if ($updateOrden) {
            $updateOrden->bind_param('i', $idOrden);

            if (!$updateOrden->execute()) {
                $error = $updateOrden->error;
                $updateOrden->close();
                throw new Exception('No se pudo actualizar ordenes_pedido: ' . $error);
            }

            $updateOrden->close();
        }
    }

    $logsPedidoAntes = 0;

    if ($esPedido && $idOrden) {
        $logsPedidoAntes = contar_logs_pedido_cancelado($conn, $idOrden);

        insertar_log_pedido_cancelado(
            $conn,
            $idOrden,
            $folio,
            $usuario,
            $motivo,
            $cantidadTotalCancelada,
            $productosProcesados,
            $pedidoEstabaCompletado
        );

        $logsPedidoDespues = contar_logs_pedido_cancelado($conn, $idOrden);

        if ($logsPedidoDespues <= $logsPedidoAntes) {
            throw new Exception('La cancelación sí procesó la venta, pero no se guardó el log en pedidos_log. Se revierte la operación.');
        }
    }

    $conn->commit();

    if ($esPedido && $pedidoEstabaCompletado) {
        $mensajeFinal = 'Pedido completado cancelado y stock restaurado correctamente.';
    } elseif ($esPedido) {
        $mensajeFinal = 'Pedido cancelado y stock restaurado correctamente.';
    } else {
        $mensajeFinal = 'Venta cancelada y stock restaurado correctamente.';
    }

    responder_json(true, $mensajeFinal, [
        'folio' => $folio,
        'cancelacion_uid' => $cancelacionUid,
        'id_orden' => $idOrden,
        'logs_guardados' => $logsFinales,
        'log_pedido_guardado' => $esPedido,
        'cantidad_total_cancelada' => $cantidadTotalCancelada,
        'productos_procesados' => $productosProcesados,
        'motivo_guardado' => $motivo
    ]);
} catch (Throwable $e) {
    if ($conn instanceof mysqli) {
        try {
            $conn->rollback();
        } catch (Throwable $rollbackError) {
            error_log('Error rollback cancelar_venta.php: ' . $rollbackError->getMessage());
        }
    }

    error_log('Error cancelar_venta.php: ' . $e->getMessage());

    responder_json(false, $e->getMessage());
}

$conn->close();