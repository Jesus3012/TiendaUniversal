<?php
declare(strict_types=1);

date_default_timezone_set('America/Mexico_City');
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/ventas_reembolsos_helper.php';

function ca_estado_folio(mysqli $conn, string $folio): string
{
    $stmt = $conn->prepare("SELECT id, cantidad_vendida, estado FROM ventas WHERE folio_ticket = ?");
    $stmt->bind_param('s', $folio);
    $stmt->execute();
    $filas = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    $disponible = 0;
    $ajustada = false;
    $pendiente = false;

    foreach ($filas as $fila) {
        $disponible += vrh_cantidad_disponible($conn, $fila);
        $estado = mb_strtolower((string) ($fila['estado'] ?? 'completada'), 'UTF-8');
        $ajustada = $ajustada || in_array($estado, ['parcial', 'cancelada'], true);
        $pendiente = $pendiente || $estado === 'pendiente';
    }

    if ($filas === [] || $disponible <= 0) {
        return 'cancelada';
    }

    if ($ajustada) {
        return 'parcial';
    }

    return $pendiente ? 'pendiente' : 'completada';
}

$usuarioId = (int) ($_SESSION['usuario_id'] ?? 0);
$rol = mb_strtolower(trim((string) ($_SESSION['rol'] ?? '')), 'UTF-8');

if ($usuarioId <= 0) {
    vrh_responder(['success' => false, 'message' => 'Sesión no válida.'], 401);
}

if (!in_array($rol, ['administrador', 'super_administrador', 'vendedor'], true)) {
    vrh_responder(['success' => false, 'message' => 'No tienes permiso para cancelar artículos.'], 403);
}

$data = vrh_json_entrada();
$folio = trim((string) ($data['folio'] ?? ''));
$productoId = (int) ($data['id_producto'] ?? 0);
$productoNombre = trim((string) ($data['producto'] ?? ''));
$motivo = mb_substr(trim((string) ($data['motivo'] ?? '')), 0, 255);
$motivoFinal = $motivo !== '' ? $motivo : 'Cancelación de artículo';

if ($folio === '' || ($productoId <= 0 && $productoNombre === '')) {
    vrh_responder([
        'success' => false,
        'message' => 'El folio y el producto son obligatorios.',
    ], 422);
}

$lockName = 'cancelar_articulo_' . hash('sha256', $folio . '|' . $productoId . '|' . $productoNombre);
$lockObtenido = false;

try {
    $stmt = $conn->prepare('SELECT GET_LOCK(?, 15) AS obtenido');
    $stmt->bind_param('s', $lockName);
    $stmt->execute();
    $lockObtenido = (int) ($stmt->get_result()->fetch_assoc()['obtenido'] ?? 0) === 1;
    $stmt->close();

    if (!$lockObtenido) {
        throw new RuntimeException('El artículo está siendo procesado. Intenta nuevamente.');
    }

    if ($productoId > 0) {
        $stmt = $conn->prepare("
            SELECT
                v.id,
                v.folio_ticket,
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
              AND v.id_producto = ?
            ORDER BY v.id ASC
        ");
        $stmt->bind_param('si', $folio, $productoId);
    } else {
        $stmt = $conn->prepare("
            SELECT
                v.id,
                v.folio_ticket,
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
              AND p.nombre = ?
            ORDER BY v.id ASC
        ");
        $stmt->bind_param('ss', $folio, $productoNombre);
    }

    $stmt->execute();
    $filas = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    if ($filas === []) {
        throw new RuntimeException('No se encontró el producto dentro de esta venta.');
    }

    $productoId = (int) $filas[0]['id_producto'];
    $productoNombre = (string) $filas[0]['producto_nombre'];

    if ($rol === 'vendedor') {
        foreach ($filas as $fila) {
            if ((int) ($fila['id_vendedor'] ?? 0) !== $usuarioId) {
                throw new RuntimeException('No puedes cancelar artículos de una venta registrada por otro usuario.');
            }
        }
    }

    $metodo = vrh_normalizar_metodo((string) ($filas[0]['metodo_pago'] ?? ''));
    $plazo = vrh_validar_plazo(
        $conn,
        $metodo,
        (string) $filas[0]['fecha_venta'],
        'parcial'
    );

    $aplicaciones = [];
    $cantidadTotal = 0;
    $montoReembolso = 0.00;
    $semillaPartes = [];

    foreach ($filas as $fila) {
        $disponible = vrh_cantidad_disponible($conn, $fila);
        $devuelta = vrh_total_devuelto($conn, (int) $fila['id']);
        $cancelada = vrh_total_cancelado(
            $conn,
            (int) $fila['id'],
            (int) $fila['cantidad_vendida']
        );

        $semillaPartes[] = implode(':', [(int) $fila['id'], $devuelta, $cancelada, $disponible]);

        if ($disponible <= 0) {
            continue;
        }

        $precio = vrh_precio_unitario($fila);
        $importe = round($disponible * $precio, 2);
        $aplicaciones[] = [
            'venta' => $fila,
            'cantidad' => $disponible,
            'importe' => $importe,
        ];
        $cantidadTotal += $disponible;
        $montoReembolso += $importe;
    }

    if ($aplicaciones === []) {
        throw new RuntimeException('Este artículo ya fue cancelado o devuelto completamente.');
    }

    $montoReembolso = round($montoReembolso, 2);

    // Determina si este artículo representa todo lo que queda del folio.
    $stmt = $conn->prepare("
        SELECT
            v.id,
            v.cantidad_vendida,
            v.precio_unitario,
            v.subtotal,
            p.precio_venta
        FROM ventas v
        INNER JOIN productos p ON p.id = v.id_producto
        WHERE v.folio_ticket = ?
    ");
    $stmt->bind_param('s', $folio);
    $stmt->execute();
    $todas = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    $montoDisponibleFolio = 0.00;
    foreach ($todas as $fila) {
        $montoDisponibleFolio += vrh_cantidad_disponible($conn, $fila) * vrh_precio_unitario($fila);
    }
    $montoDisponibleFolio = round($montoDisponibleFolio, 2);
    $tipoMp = abs($montoDisponibleFolio - $montoReembolso) <= 0.01 ? 'total' : 'parcial';

    $resultadoMp = vrh_procesar_reembolso_mp(
        $conn,
        $filas[0],
        $folio,
        $montoReembolso,
        $tipoMp,
        'producto:' . $productoId . '|' . implode('|', $semillaPartes),
        $motivoFinal,
        $usuarioId
    );

    $estadoReembolso = vrh_es_tarjeta($metodo)
        ? (string) ($resultadoMp['status'] ?? 'accepted')
        : 'manual_pendiente';
    $reembolsoUid = (string) ($resultadoMp['idempotency_key'] ?? 'MAN-' . date('YmdHis') . '-' . bin2hex(random_bytes(4)));
    $cancelacionUid = 'ART-' . date('YmdHis') . '-' . bin2hex(random_bytes(5));

    $conn->begin_transaction();

    foreach ($aplicaciones as $aplicacion) {
        $venta = $aplicacion['venta'];
        $ventaId = (int) $venta['id'];
        $cantidad = (int) $aplicacion['cantidad'];
        $importe = (float) $aplicacion['importe'];

        $stmt = $conn->prepare("
            SELECT id, id_producto, cantidad_vendida
            FROM ventas
            WHERE id = ?
            FOR UPDATE
        ");
        $stmt->bind_param('i', $ventaId);
        $stmt->execute();
        $actual = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$actual || vrh_cantidad_disponible($conn, $actual) !== $cantidad) {
            throw new RuntimeException(
                'La venta cambió mientras Mercado Pago procesaba el reembolso. Revisa la bitácora antes de reintentar.'
            );
        }

        $stmt = $conn->prepare("UPDATE productos SET cantidad = cantidad + ? WHERE id = ?");
        $stmt->bind_param('ii', $cantidad, $productoId);
        $stmt->execute();
        if ($stmt->affected_rows !== 1) {
            throw new RuntimeException('No se pudo restaurar el stock del producto.');
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

        $proveedor = trim((string) ($venta['proveedor'] ?? ''));
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
    }

    if (vrh_tabla_existe($conn, 'pedidos')) {
        $stmt = $conn->prepare("
            SELECT id, cantidad_pedida, faltante
            FROM pedidos
            WHERE id_producto = ?
            ORDER BY fecha DESC
            LIMIT 1
            FOR UPDATE
        ");
        $stmt->bind_param('i', $productoId);
        $stmt->execute();
        $pedido = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if ($pedido) {
            $nuevaPedida = max((int) $pedido['cantidad_pedida'] - $cantidadTotal, 0);
            $nuevoFaltante = max((int) $pedido['faltante'] - $cantidadTotal, 0);
            $pedidoId = (int) $pedido['id'];
            $stmt = $conn->prepare("UPDATE pedidos SET cantidad_pedida = ?, faltante = ? WHERE id = ?");
            $stmt->bind_param('iii', $nuevaPedida, $nuevoFaltante, $pedidoId);
            $stmt->execute();
            $stmt->close();
        }
    }

    $estadoFolio = ca_estado_folio($conn, $folio);

    vrh_insertar_auditoria(
        $conn,
        $usuarioId,
        'CANCELAR_ARTICULO',
        "Canceló {$cantidadTotal} pieza(s) de {$productoNombre} en {$folio}. " .
        'Importe: $' . number_format($montoReembolso, 2) .
        ". Método: {$metodo}. Reembolso: {$estadoReembolso}. Motivo: {$motivoFinal}."
    );

    $conn->commit();

    $requiereManual = !vrh_es_tarjeta($metodo);
    $mensaje = vrh_es_tarjeta($metodo)
        ? 'Artículo cancelado, stock restaurado y reembolso enviado a Mercado Pago.'
        : 'Artículo cancelado y stock restaurado. Devuelve manualmente el importe al cliente.';

    vrh_responder([
        'success' => true,
        'message' => $mensaje,
        'folio' => $folio,
        'id_producto' => $productoId,
        'producto' => $productoNombre,
        'cantidad_cancelada' => $cantidadTotal,
        'monto_reembolso' => $montoReembolso,
        'monto_reembolso_formateado' => '$' . number_format($montoReembolso, 2),
        'estado_venta' => $estadoFolio,
        'estado_reembolso' => $estadoReembolso,
        'requiere_reembolso_manual' => $requiereManual,
        'mercadopago' => $resultadoMp,
        'plazo' => $plazo,
        'cancelacion_uid' => $cancelacionUid,
    ]);
} catch (Throwable $e) {
    try {
        $conn->rollback();
    } catch (Throwable $rollbackError) {
        error_log('Rollback cancelar_articulo.php: ' . $rollbackError->getMessage());
    }

    error_log('cancelar_articulo.php: ' . $e->getMessage());

    vrh_responder([
        'success' => false,
        'message' => $e->getMessage(),
        'requiere_revision_pago' => str_contains($e->getMessage(), 'Mercado Pago procesaba'),
    ], 409);
} finally {
    if ($lockObtenido) {
        try {
            $stmt = $conn->prepare('SELECT RELEASE_LOCK(?)');
            $stmt->bind_param('s', $lockName);
            $stmt->execute();
            $stmt->close();
        } catch (Throwable $unlockError) {
            error_log('RELEASE_LOCK cancelar_articulo.php: ' . $unlockError->getMessage());
        }
    }
}
