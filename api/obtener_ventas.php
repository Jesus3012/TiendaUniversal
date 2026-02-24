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

if ($producto)
    $where[] = "p.nombre LIKE '%" . $conn->real_escape_string($producto) . "%'";

if ($cliente)
    $where[] = "v.correo_cliente LIKE '%" . $conn->real_escape_string($cliente) . "%'";

if ($inicio && $fin)
    $where[] = "DATE(v.fecha_venta) BETWEEN '" . 
                $conn->real_escape_string($inicio) . "' 
                AND '" . 
                $conn->real_escape_string($fin) . "'";

$condicion = $where ? "WHERE " . implode(" AND ", $where) : "";

/*
|--------------------------------------------------------------------------
| CONSULTA PRINCIPAL
|--------------------------------------------------------------------------
| Agregamos detección de pedido usando SUBSTRING_INDEX
| Extrae el número después de PEDIDO-
|--------------------------------------------------------------------------
*/

$sql = "
    SELECT 
        v.folio_ticket,
        v.correo_cliente,
        v.fecha_venta,
        v.ticket_pdf,

        GROUP_CONCAT(p.nombre SEPARATOR '||') AS productos,
        GROUP_CONCAT(v.cantidad_vendida SEPARATOR '||') AS cantidades,
        GROUP_CONCAT((v.cantidad_vendida * p.precio_venta) SEPARATOR '||') AS totales,
        GROUP_CONCAT(p.id SEPARATOR '||') AS ids_productos,

        SUM(v.cantidad_vendida * p.precio_venta) AS total_general,

        CASE 
            WHEN pe.total_pedidos IS NOT NULL 
                 AND pe.total_pedidos = pe.completados
            THEN 'completado'
            WHEN pe.total_pedidos IS NOT NULL
            THEN 'pendiente'
            ELSE NULL
        END AS estado_pedido

    FROM ventas v
    JOIN productos p ON v.id_producto = p.id

    LEFT JOIN (
        SELECT 
            id_orden,
            COUNT(*) as total_pedidos,
            SUM(CASE WHEN estado = 'completado' THEN 1 ELSE 0 END) as completados
        FROM pedidos
        GROUP BY id_orden
    ) pe ON pe.id_orden = 
        CASE 
            WHEN v.folio_ticket LIKE 'PEDIDO-%'
            THEN CAST(SUBSTRING_INDEX(v.folio_ticket, 'PEDIDO-', -1) AS UNSIGNED)
            ELSE NULL
        END

    $condicion

    GROUP BY v.folio_ticket, v.correo_cliente, v.fecha_venta, v.ticket_pdf
    ORDER BY v.fecha_venta DESC
";

$q = $conn->query($sql);
$data = [];

if ($q) {
    while ($r = $q->fetch_assoc()) {

        $productos  = explode("||", $r['productos']);
        $cantidades = explode("||", $r['cantidades']);
        $totales    = explode("||", $r['totales']);
        $ids        = explode("||", $r['ids_productos']);

        $items = [];

        for ($i = 0; $i < count($productos); $i++) {
            $items[] = [
                'producto'     => $productos[$i],
                'id_producto'  => intval($ids[$i]),
                'cantidad'     => intval($cantidades[$i]),
                'total'        => '$' . number_format($totales[$i], 2)
            ];
        }

        $data[] = [
            'folio_ticket'   => $r['folio_ticket'],
            'correo_cliente' => $r['correo_cliente'],

            'fecha_raw'      => $r['fecha_venta'],
            'fecha_venta'    => date('d/m/Y H:i', strtotime($r['fecha_venta'])),

            'ticket_pdf'     => $r['ticket_pdf'],
            'total_general'  => '$' . number_format($r['total_general'], 2),

            // 🔥 ESTE ES EL CAMPO IMPORTANTE
            'estado_pedido'  => $r['estado_pedido'] ?? null,

            'items'          => $items
        ];
    }
}

echo json_encode(['data' => $data]);
