<?php
declare(strict_types=1);

date_default_timezone_set('America/Mexico_City');
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/ventas_reembolsos_helper.php';

$usuarioId = (int) ($_SESSION['usuario_id'] ?? 0);
$usuarioNombre = trim((string) ($_SESSION['nombre'] ?? $_SESSION['usuario_nombre'] ?? 'Usuario'));
$rol = mb_strtolower(trim((string) ($_SESSION['rol'] ?? '')), 'UTF-8');

if ($usuarioId <= 0) {
    vrh_responder(['success' => false, 'message' => 'Sesión no válida.'], 401);
}

if (!in_array($rol, ['administrador', 'super_administrador', 'vendedor'], true)) {
    vrh_responder(['success' => false, 'message' => 'No tienes permiso para cancelar ventas.'], 403);
}

$data = vrh_json_entrada();
$folio = trim((string) ($data['folio'] ?? ''));
$motivo = mb_substr(trim((string) ($data['motivo'] ?? '')), 0, 255);
$forzar = filter_var($data['forzar'] ?? false, FILTER_VALIDATE_BOOLEAN);

if ($folio === '') {
    vrh_responder(['success' => false, 'message' => 'El folio es obligatorio.'], 422);
}

$motivoFinal = $motivo !== '' ? $motivo : 'Cancelación total de la venta';
$lockName = 'cancelar_venta_' . hash('sha256', $folio);
$lockObtenido = false;

try {
    $stmtLock = $conn->prepare('SELECT GET_LOCK(?, 15) AS obtenido');
    $stmtLock->bind_param('s', $lockName);
    $stmtLock->execute();
    $lockObtenido = (int) ($stmtLock->get_result()->fetch_assoc()['obtenido'] ?? 0) === 1;
    $stmtLock->close();

    if (!$lockObtenido) {
        throw new RuntimeException('La venta está siendo procesada por otra operación. Intenta nuevamente.');
    }

    $stmt = $conn->prepare("
        SELECT
            v.id,
            v.folio_ticket,
            v.id_orden,
            v.id_producto,
            v.id_vendedor,
            v.cantidad_vendida,
            v.precio_unitario,
            v.subtotal,
            v.metodo_pago,
            v.referencia_pago,
            v.fecha_venta,
            v.estado,
            p.nombre AS producto_nombre,
            p.precio_venta,
            p.proveedor
        FROM ventas v
        INNER JOIN productos p ON p.id = v.id_producto
        WHERE v.folio_ticket = ?
        ORDER BY v.id ASC
    ");
    $stmt->bind_param('s', $folio);
    $stmt->execute();
    $result = $stmt->get_result();
    $ventas = $result->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    if ($ventas === []) {
        throw new RuntimeException('No se encontró la venta.');
    }

    if ($rol === 'vendedor') {
        foreach ($ventas as $venta) {
            if ((int) ($venta['id_vendedor'] ?? 0) !== $usuarioId) {
                throw new RuntimeException('No puedes cancelar una venta registrada por otro usuario.');
            }
        }
    }

    $metodo = vrh_normalizar_metodo((string) ($ventas[0]['metodo_pago'] ?? ''));
    $plazo = vrh_validar_plazo(
        $conn,
        $metodo,
        (string) $ventas[0]['fecha_venta'],
        'total'
    );

    $idOrden = 0;
    foreach ($ventas as $venta) {
        $idOrden = max($idOrden, (int) ($venta['id_orden'] ?? 0));
    }

    if ($idOrden > 0 && vrh_tabla_existe($conn, 'pedidos')) {
        $stmt = $conn->prepare("
            SELECT
                COUNT(*) AS total,
                SUM(CASE WHEN estado = 'completado' THEN 1 ELSE 0 END) AS completados
            FROM pedidos
            WHERE id_orden = ?
        ");
        $stmt->bind_param('i', $idOrden);
        $stmt->execute();
        $pedido = $stmt->get_result()->fetch_assoc() ?: [];
        $stmt->close();

        $pedidoCompletado = (int) ($pedido['total'] ?? 0) > 0
            && (int) ($pedido['total'] ?? 0) === (int) ($pedido['completados'] ?? 0);

        if ($pedidoCompletado && !$forzar) {
            vrh_responder([
                'success' => false,
                'pedido_completado' => true,
                'message' => 'Este pedido ya está completado. Confirma nuevamente para cancelarlo.',
            ], 409);
        }
    }

    $aplicaciones = [];
    $montoTotal = 0.00;
    $cantidadTotal = 0;
    $semillaPartes = [];

    foreach ($ventas as $venta) {
        $disponible = vrh_cantidad_disponible($conn, $venta);
        $precio = vrh_precio_unitario($venta);
        $importe = round($disponible * $precio, 2);

        $semillaPartes[] = implode(':', [
            (int) $venta['id'],
            $disponible,
            vrh_total_devuelto($conn, (int) $venta['id']),
            vrh_total_cancelado($conn, (int) $venta['id'], (int) $venta['cantidad_vendida']),
        ]);

        if ($disponible <= 0) {
            continue;
        }

        $aplicaciones[] = [
            'venta' => $venta,
            'cantidad' => $disponible,
            'precio' => $precio,
            'importe' => $importe,
        ];
        $cantidadTotal += $disponible;
        $montoTotal += $importe;
    }

    $montoTotal = round($montoTotal, 2);

    if ($aplicaciones === []) {
        $conn->begin_transaction();
        $stmt = $conn->prepare("
            UPDATE ventas
            SET estado = 'cancelada',
                motivo_cancelacion = COALESCE(NULLIF(motivo_cancelacion, ''), ?),
                fecha_cancelacion = COALESCE(fecha_cancelacion, NOW()),
                cancelada_por = COALESCE(cancelada_por, ?)
            WHERE folio_ticket = ?
        ");
        $stmt->bind_param('sis', $motivoFinal, $usuarioId, $folio);
        $stmt->execute();
        $stmt->close();
        $conn->commit();

        vrh_responder([
            'success' => false,
            'message' => 'La venta ya no tiene artículos disponibles para cancelar.',
            'estado_venta' => 'cancelada',
        ], 409);
    }

    /*
     * El reembolso se solicita ANTES de restaurar stock.
     * Si Mercado Pago lo rechaza, no se modifica la venta local.
     */
    $resultadoMp = vrh_procesar_reembolso_mp(
        $conn,
        $ventas[0],
        $folio,
        $montoTotal,
        'total',
        implode('|', $semillaPartes),
        $motivoFinal,
        $usuarioId
    );

    $estadoReembolso = vrh_es_tarjeta($metodo)
        ? (string) ($resultadoMp['status'] ?? 'accepted')
        : 'manual_pendiente';
    $reembolsoUid = (string) ($resultadoMp['idempotency_key'] ?? 'MAN-' . date('YmdHis') . '-' . bin2hex(random_bytes(4)));
    $cancelacionUid = 'CAN-' . date('YmdHis') . '-' . bin2hex(random_bytes(5));

    $conn->begin_transaction();

    // Revalida dentro de la transacción y bloquea las filas.
    $stmt = $conn->prepare("
        SELECT
            v.id,
            v.id_producto,
            v.cantidad_vendida,
            v.estado,
            p.nombre AS producto_nombre,
            p.proveedor
        FROM ventas v
        INNER JOIN productos p ON p.id = v.id_producto
        WHERE v.folio_ticket = ?
        ORDER BY v.id ASC
        FOR UPDATE
    ");
    $stmt->bind_param('s', $folio);
    $stmt->execute();
    $bloqueadas = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    $mapa = [];
    foreach ($aplicaciones as $aplicacion) {
        $mapa[(int) $aplicacion['venta']['id']] = $aplicacion;
    }

    $procesadas = 0;
    foreach ($bloqueadas as $ventaBloqueada) {
        $ventaId = (int) $ventaBloqueada['id'];
        if (!isset($mapa[$ventaId])) {
            continue;
        }

        $aplicacion = $mapa[$ventaId];
        $cantidad = (int) $aplicacion['cantidad'];
        $importe = (float) $aplicacion['importe'];
        $productoId = (int) $ventaBloqueada['id_producto'];

        $disponibleActual = vrh_cantidad_disponible($conn, array_merge($ventaBloqueada, [
            'id' => $ventaId,
            'cantidad_vendida' => (int) $ventaBloqueada['cantidad_vendida'],
        ]));

        if ($disponibleActual !== $cantidad) {
            throw new RuntimeException(
                'La venta cambió mientras se procesaba el reembolso. Mercado Pago ya recibió la solicitud; revisa la bitácora antes de reintentar.'
            );
        }

        $stmt = $conn->prepare("
            UPDATE productos
            SET cantidad = cantidad + ?
            WHERE id = ?
        ");
        $stmt->bind_param('ii', $cantidad, $productoId);
        $stmt->execute();
        if ($stmt->affected_rows !== 1) {
            throw new RuntimeException('No se pudo restaurar el stock de ' . $ventaBloqueada['producto_nombre'] . '.');
        }
        $stmt->close();

        $stmt = $conn->prepare("
            INSERT INTO ventas_canceladas (
                cancelacion_uid,
                id_venta,
                id_venta_origen,
                cantidad_devuelta,
                monto_reembolso,
                motivo,
                metodo_pago,
                estado_reembolso,
                reembolso_uid,
                procesada_por,
                fecha_cancelacion,
                folio_ticket,
                folio_ocurrencia
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), ?, 1)
        ");
        $stmt->bind_param(
            'siiidssssis',
            $cancelacionUid,
            $ventaId,
            $ventaId,
            $cantidad,
            $importe,
            $motivoFinal,
            $metodo,
            $estadoReembolso,
            $reembolsoUid,
            $usuarioId,
            $folio
        );
        $stmt->execute();
        $stmt->close();

        $stmt = $conn->prepare("
            UPDATE ventas
            SET estado = 'cancelada',
                motivo_cancelacion = ?,
                fecha_cancelacion = NOW(),
                cancelada_por = ?
            WHERE id = ?
        ");
        $stmt->bind_param('sii', $motivoFinal, $usuarioId, $ventaId);
        $stmt->execute();
        $stmt->close();

        $proveedor = trim((string) ($ventaBloqueada['proveedor'] ?? ''));
        if ($proveedor !== '' && vrh_tabla_existe($conn, 'reporte_proveedor')) {
            $stmt = $conn->prepare("
                UPDATE reporte_proveedor
                SET ventas = GREATEST(ventas - ?, 0)
                WHERE producto_id = ?
                  AND proveedor = ?
                  AND DATE(fecha_conteo) = CURDATE()
            ");
            if ($stmt) {
                $stmt->bind_param('iis', $cantidad, $productoId, $proveedor);
                $stmt->execute();
                $stmt->close();
            }
        }

        $procesadas++;
    }

    if ($procesadas !== count($aplicaciones)) {
        throw new RuntimeException('No se procesaron todos los artículos de la venta.');
    }

    if ($idOrden > 0 && vrh_tabla_existe($conn, 'pedidos')) {
        $stmt = $conn->prepare("UPDATE pedidos SET estado = 'cancelado' WHERE id_orden = ?");
        $stmt->bind_param('i', $idOrden);
        $stmt->execute();
        $stmt->close();
    }

    if ($idOrden > 0 && vrh_tabla_existe($conn, 'pedidos_log')) {
        $accionPedido = 'PEDIDO CANCELADO';
        $detallePedido = "Se canceló el pedido {$folio}. Stock restaurado: {$cantidadTotal} pieza(s). " .
            'Importe devuelto: $' . number_format($montoTotal, 2) .
            ". Estado de reembolso: {$estadoReembolso}. Motivo: {$motivoFinal}.";
        $stmt = $conn->prepare("
            INSERT INTO pedidos_log (id_pedido, accion, detalle, usuario, fecha)
            VALUES (?, ?, ?, ?, NOW())
        ");
        if ($stmt) {
            $stmt->bind_param('isss', $idOrden, $accionPedido, $detallePedido, $usuarioNombre);
            $stmt->execute();
            $stmt->close();
        }
    }

    if ($idOrden > 0 && vrh_tabla_existe($conn, 'ordenes_pedido')) {
        $partes = [];
        if (vrh_columna_existe($conn, 'ordenes_pedido', 'fecha_cancelacion')) {
            $partes[] = 'fecha_cancelacion = NOW()';
        }
        if (vrh_columna_existe($conn, 'ordenes_pedido', 'estado')) {
            $partes[] = "estado = 'cancelado'";
        }
        if ($partes !== []) {
            $sql = 'UPDATE ordenes_pedido SET ' . implode(', ', $partes) . ' WHERE id_orden = ?';
            $stmt = $conn->prepare($sql);
            $stmt->bind_param('i', $idOrden);
            $stmt->execute();
            $stmt->close();
        }
    }

    vrh_insertar_auditoria(
        $conn,
        $usuarioId,
        'CANCELAR_VENTA',
        "Canceló la venta {$folio}. Piezas: {$cantidadTotal}. Importe: $" . number_format($montoTotal, 2) .
        ". Método: {$metodo}. Motivo: {$motivoFinal}. Reembolso: {$estadoReembolso}."
    );

    $conn->commit();

    $requiereManual = !vrh_es_tarjeta($metodo);
    $mensaje = vrh_es_tarjeta($metodo)
        ? 'Venta cancelada, stock restaurado y reembolso enviado a Mercado Pago.'
        : 'Venta cancelada y stock restaurado. Registra físicamente la devolución del dinero al cliente.';

    vrh_responder([
        'success' => true,
        'message' => $mensaje,
        'folio' => $folio,
        'estado_venta' => 'cancelada',
        'metodo_pago' => $metodo,
        'cantidad_cancelada' => $cantidadTotal,
        'monto_reembolso' => $montoTotal,
        'monto_reembolso_formateado' => '$' . number_format($montoTotal, 2),
        'estado_reembolso' => $estadoReembolso,
        'requiere_reembolso_manual' => $requiereManual,
        'mercadopago' => $resultadoMp,
        'plazo' => $plazo,
        'cancelacion_uid' => $cancelacionUid,
    ]);
} catch (Throwable $e) {
    if ($conn->errno === 0) {
        // No-op: evita advertencias en conexiones sin transacción activa.
    }

    try {
        $conn->rollback();
    } catch (Throwable $rollbackError) {
        error_log('Rollback cancelar_venta.php: ' . $rollbackError->getMessage());
    }

    error_log('cancelar_venta.php: ' . $e->getMessage());

    vrh_responder([
        'success' => false,
        'message' => $e->getMessage(),
        'requiere_revision_pago' => vrh_contiene($e->getMessage(), 'Mercado Pago ya recibió'),
    ], 409);
} finally {
    if ($lockObtenido) {
        try {
            $stmtUnlock = $conn->prepare('SELECT RELEASE_LOCK(?)');
            $stmtUnlock->bind_param('s', $lockName);
            $stmtUnlock->execute();
            $stmtUnlock->close();
        } catch (Throwable $unlockError) {
            error_log('RELEASE_LOCK cancelar_venta.php: ' . $unlockError->getMessage());
        }
    }
}
