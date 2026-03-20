<?php
include '../includes/session.php';
include '../includes/db.php';

$usuario_id = $_SESSION['usuario_id'];
$rol = $_SESSION['rol'];

// === FILTROS ===
$producto = $_GET['producto'] ?? '';
$cliente  = $_GET['cliente'] ?? '';
$inicio   = $_GET['inicio'] ?? '';
$fin      = $_GET['fin'] ?? '';

$where = [];

// Si es vendedor solo ve sus ventas
if ($rol === 'vendedor') {
    $where[] = "v.id_vendedor = " . intval($usuario_id);
}

if ($producto) {
    $producto_escaped = $conn->real_escape_string($producto);
    $where[] = "EXISTS (
        SELECT 1 FROM ventas v2 
        JOIN productos p2 ON v2.id_producto = p2.id 
        WHERE v2.folio_ticket = v.folio_ticket 
        AND p2.nombre LIKE '%$producto_escaped%'
    )";
}

if ($cliente) {
    $cliente_escaped = $conn->real_escape_string($cliente);
    $where[] = "v.correo_cliente LIKE '%$cliente_escaped%'";
}

if ($inicio && $fin) {
    $inicio_escaped = $conn->real_escape_string($inicio);
    $fin_escaped = $conn->real_escape_string($fin);
    $where[] = "DATE(v.fecha_venta) BETWEEN '$inicio_escaped' AND '$fin_escaped'";
}

$condicion = $where ? "WHERE " . implode(" AND ", $where) : "";

$sql = "
    SELECT 
        v.folio_ticket,
        v.correo_cliente,
        v.fecha_venta,
        v.ticket_pdf,
        
        -- Calcular total general sumando todos los productos de la venta
        (
            SELECT SUM(v2.cantidad_vendida * p2.precio_venta)
            FROM ventas v2
            JOIN productos p2 ON v2.id_producto = p2.id
            WHERE v2.folio_ticket = v.folio_ticket
        ) AS total_general,

        -- Determinar si es pedido y su estado
        CASE 
            WHEN v.folio_ticket LIKE 'PEDIDO-%' THEN
                CASE
                    -- Extraer el número después de PEDIDO-
                    WHEN (
                        SELECT COUNT(*) FROM pedidos 
                        WHERE id_orden = CAST(SUBSTRING_INDEX(v.folio_ticket, 'PEDIDO-', -1) AS UNSIGNED)
                        AND estado = 'completado'
                    ) = (
                        SELECT COUNT(*) FROM pedidos 
                        WHERE id_orden = CAST(SUBSTRING_INDEX(v.folio_ticket, 'PEDIDO-', -1) AS UNSIGNED)
                    )
                    THEN 'completado'
                    ELSE 'pendiente'
                END
            ELSE NULL
        END AS estado_pedido

    FROM ventas v
    $condicion
    GROUP BY v.folio_ticket, v.correo_cliente, v.fecha_venta, v.ticket_pdf
    ORDER BY v.fecha_venta DESC
";

$q = $conn->query($sql);
$data = [];

if ($q) {
    while ($r = $q->fetch_assoc()) {
        $data[] = [
            'folio_ticket'   => $r['folio_ticket'],
            'correo_cliente' => $r['correo_cliente'],
            'fecha_raw'      => $r['fecha_venta'],
            'fecha_venta'    => date('d/m/Y H:i', strtotime($r['fecha_venta'])),
            'ticket_pdf'     => $r['ticket_pdf'],
            'total_general'  => floatval($r['total_general']),
            'estado_pedido'  => $r['estado_pedido'] ?? null
        ];
    }
}

echo json_encode(['data' => $data]);
?>