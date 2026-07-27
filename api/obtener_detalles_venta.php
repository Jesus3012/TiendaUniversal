<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/ventas_reembolsos_helper.php';

$usuarioId = (int) ($_SESSION['usuario_id'] ?? 0);
$rol = mb_strtolower(trim((string) ($_SESSION['rol'] ?? '')), 'UTF-8');

if ($usuarioId <= 0) {
    vrh_responder(['success' => false, 'message' => 'Sesión no válida.'], 401);
}

if (!in_array($rol, ['vendedor', 'administrador', 'super_administrador'], true)) {
    vrh_responder(['success' => false, 'message' => 'No tienes permiso para consultar la venta.'], 403);
}

$folio = trim((string) ($_GET['folio'] ?? ''));
if ($folio === '') {
    vrh_responder(['success' => false, 'message' => 'El folio es obligatorio.'], 422);
}

if ($rol === 'vendedor') {
    $stmt = $conn->prepare("
        SELECT 1
        FROM ventas
        WHERE folio_ticket = ?
          AND id_vendedor = ?
        LIMIT 1
    ");
    $stmt->bind_param('si', $folio, $usuarioId);
    $stmt->execute();
    $permitido = (bool) $stmt->get_result()->fetch_row();
    $stmt->close();

    if (!$permitido) {
        vrh_responder(['success' => false, 'message' => 'No autorizado.'], 403);
    }
}

$precio = "COALESCE(NULLIF(v.precio_unitario, 0), p.precio_venta)";
$cancelado = "CASE WHEN COALESCE(vc.cancelacion_total, 0) = 1 THEN v.cantidad_vendida ELSE COALESCE(vc.cantidad_cancelada, 0) END";
$neta = "GREATEST(v.cantidad_vendida - COALESCE(dp.cantidad_devuelta, 0) - ({$cancelado}), 0)";

$sql = "
    SELECT
        v.id AS id_venta,
        v.id_producto,
        p.nombre AS producto,
        v.cantidad_vendida AS cantidad_original,
        COALESCE(dp.cantidad_devuelta, 0) AS cantidad_devuelta,
        ({$cancelado}) AS cantidad_cancelada,
        {$neta} AS cantidad,
        {$neta} AS disponible,
        {$precio} AS precio_unitario,
        ROUND(v.cantidad_vendida * {$precio}, 2) AS subtotal_original,
        ROUND(({$neta}) * {$precio}, 2) AS subtotal,
        LOWER(COALESCE(v.estado, 'completada')) AS estado,
        CASE WHEN {$neta} <= 0 THEN 1 ELSE 0 END AS cancelado,
        v.motivo_cancelacion,
        v.fecha_cancelacion,
        v.correo_cliente,
        v.fecha_venta,
        v.ticket_pdf,
        v.metodo_pago,
        v.referencia_pago,
        u.nombre AS vendedor_nombre
    FROM ventas v
    INNER JOIN productos p ON p.id = v.id_producto
    LEFT JOIN usuarios u ON u.id = v.id_vendedor
    LEFT JOIN (
        SELECT id_venta, SUM(cantidad_devuelta) AS cantidad_devuelta
        FROM devoluciones_parciales
        GROUP BY id_venta
    ) dp ON dp.id_venta = v.id
    LEFT JOIN (
        SELECT
            id_venta,
            SUM(CASE WHEN COALESCE(cantidad_devuelta, 0) > 0 THEN cantidad_devuelta ELSE 0 END) AS cantidad_cancelada,
            MAX(CASE WHEN COALESCE(cantidad_devuelta, 0) <= 0 THEN 1 ELSE 0 END) AS cancelacion_total
        FROM ventas_canceladas
        WHERE id_venta IS NOT NULL
        GROUP BY id_venta
    ) vc ON vc.id_venta = v.id
    WHERE v.folio_ticket = ?
    ORDER BY v.id ASC
";

$stmt = $conn->prepare($sql);
if (!$stmt) {
    vrh_responder([
        'success' => false,
        'message' => 'No se pudo preparar la consulta: ' . $conn->error,
    ], 500);
}

$stmt->bind_param('s', $folio);
$stmt->execute();
$result = $stmt->get_result();

$items = [];
$total = 0.00;
$totalOriginal = 0.00;
$correo = '';
$fecha = null;
$vendedor = '';
$metodo = '';
$referencia = '';
$ticketPdf = '';
$motivoCancelacion = '';
$fechaCancelacion = null;
$piezasVigentes = 0;
$piezasOriginales = 0;
$tieneAjuste = false;
$todosPendientes = true;

while ($row = $result->fetch_assoc()) {
    $row['id_venta'] = (int) $row['id_venta'];
    $row['id_producto'] = (int) $row['id_producto'];
    $row['cantidad_original'] = (int) $row['cantidad_original'];
    $row['cantidad_devuelta'] = (int) $row['cantidad_devuelta'];
    $row['cantidad_cancelada'] = (int) $row['cantidad_cancelada'];
    $row['cantidad'] = (int) $row['cantidad'];
    $row['disponible'] = (int) $row['disponible'];
    $row['cancelado'] = (int) $row['cancelado'];
    $row['precio_unitario'] = (float) $row['precio_unitario'];
    $row['subtotal_original'] = (float) $row['subtotal_original'];
    $row['subtotal'] = (float) $row['subtotal'];
    $row['devueltos'] = $row['cantidad_devuelta'];
    $row['precio_unitario_formateado'] = '$' . number_format($row['precio_unitario'], 2);
    $row['subtotal_formateado'] = '$' . number_format($row['subtotal'], 2);

    $total += $row['subtotal'];
    $totalOriginal += $row['subtotal_original'];
    $piezasVigentes += $row['cantidad'];
    $piezasOriginales += $row['cantidad_original'];

    $estado = mb_strtolower((string) ($row['estado'] ?? 'completada'), 'UTF-8');
    $tieneAjuste = $tieneAjuste
        || $row['cantidad'] < $row['cantidad_original']
        || in_array($estado, ['parcial', 'cancelada'], true);
    $todosPendientes = $todosPendientes && $estado === 'pendiente';

    $correo = $correo ?: trim((string) ($row['correo_cliente'] ?? ''));
    $fecha = $fecha ?: ($row['fecha_venta'] ?? null);
    $vendedor = $vendedor ?: trim((string) ($row['vendedor_nombre'] ?? ''));
    $metodo = $metodo ?: trim((string) ($row['metodo_pago'] ?? ''));
    $referencia = $referencia ?: trim((string) ($row['referencia_pago'] ?? ''));
    $ticketPdf = $ticketPdf ?: trim((string) ($row['ticket_pdf'] ?? ''));
    $motivoCancelacion = $motivoCancelacion ?: trim((string) ($row['motivo_cancelacion'] ?? ''));
    $fechaCancelacion = $fechaCancelacion ?: ($row['fecha_cancelacion'] ?? null);

    $items[] = $row;
}

$stmt->close();

if ($items === []) {
    vrh_responder(['success' => false, 'message' => 'No se encontró la venta.'], 404);
}

$estadoVenta = 'completada';
if ($piezasVigentes <= 0) {
    $estadoVenta = 'cancelada';
} elseif ($tieneAjuste) {
    $estadoVenta = 'parcial';
} elseif ($todosPendientes) {
    $estadoVenta = 'pendiente';
}

$reembolsos = [];
$totalReembolsadoMp = 0.00;
$ultimoEstadoReembolso = null;

if (vrh_tabla_existe($conn, 'mercadopago_reembolsos')) {
    $stmt = $conn->prepare("
        SELECT
            accion,
            monto,
            status,
            refund_id,
            reference_id,
            motivo,
            created_at
        FROM mercadopago_reembolsos
        WHERE folio_ticket = ?
        ORDER BY id DESC
    ");
    $stmt->bind_param('s', $folio);
    $stmt->execute();
    $resMp = $stmt->get_result();

    while ($mp = $resMp->fetch_assoc()) {
        $mp['monto'] = (float) ($mp['monto'] ?? 0);
        $reembolsos[] = $mp;

        if (
            in_array(
                mb_strtolower((string) ($mp['status'] ?? ''), 'UTF-8'),
                ['processed','approved','refunded','processing','pending','accepted'],
                true
            )
            && in_array($mp['accion'], ['reembolso_total', 'reembolso_parcial'], true)
        ) {
            $totalReembolsadoMp += $mp['monto'];
        }

        if ($ultimoEstadoReembolso === null) {
            $ultimoEstadoReembolso = $mp['status'] ?? null;
        }
    }
    $stmt->close();
}

vrh_responder([
    'success' => true,
    'data' => $items,
    'subtotal' => round($total, 2),
    'subtotal_formateado' => '$' . number_format($total, 2),
    'total' => round($total, 2),
    'total_formateado' => '$' . number_format($total, 2),
    'total_original' => round($totalOriginal, 2),
    'total_original_formateado' => '$' . number_format($totalOriginal, 2),
    'piezas_vigentes' => $piezasVigentes,
    'piezas_originales' => $piezasOriginales,
    'folio' => $folio,
    'fecha_venta' => $fecha,
    'correo_cliente' => $correo !== '' ? $correo : 'Venta en general',
    'vendedor_nombre' => $vendedor !== '' ? $vendedor : 'Sistema',
    'metodo_pago' => $metodo,
    'referencia_pago' => $referencia,
    'ticket_pdf' => $ticketPdf,
    'estado_venta' => $estadoVenta,
    'motivo_cancelacion' => $motivoCancelacion,
    'fecha_cancelacion' => $fechaCancelacion,
    'estado_reembolso' => $ultimoEstadoReembolso,
    'total_reembolsado_mp' => round($totalReembolsadoMp, 2),
    'reembolsos_mp' => $reembolsos,
]);
