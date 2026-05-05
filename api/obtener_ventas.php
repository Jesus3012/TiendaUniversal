<?php
include '../includes/session.php';
include '../includes/db.php';

header('Content-Type: application/json');

$usuario_id = $_SESSION['usuario_id'];
$rol = $_SESSION['rol'];

// === FILTROS ===
$global = $_GET['global'] ?? '';
$inicio = $_GET['inicio'] ?? '';
$fin    = $_GET['fin'] ?? '';

$where = [];

// Si es vendedor solo ve sus ventas
if ($rol === 'vendedor') {
    $where[] = "v.id_vendedor = " . intval($usuario_id);
}

// BÚSQUEDA GLOBAL
if (!empty($global)) {
    $global_escaped = $conn->real_escape_string($global);
    $global_lower = strtolower(trim($global_escaped));
    
    $condiciones = [];
    
    // 1. Búsqueda por folio
    $condiciones[] = "v.folio_ticket LIKE '%$global_escaped%'";
    
    // 2. Búsqueda por cliente (email)
    $condiciones[] = "v.correo_cliente LIKE '%$global_escaped%'";
    
    // 3. Búsqueda por producto
    $condiciones[] = "EXISTS (
        SELECT 1 FROM ventas v2 
        JOIN productos p2 ON v2.id_producto = p2.id 
        WHERE v2.folio_ticket = v.folio_ticket 
        AND p2.nombre LIKE '%$global_escaped%'
    )";
    
    // 4. Búsqueda por "VENTA EN GENERAL" - buscar clientes vacíos o NULL
    if ($global_lower === 'venta en general' || $global_lower === 'venta general' || $global_lower === 'general' || $global_lower === 'venta') {
        $condiciones[] = "(v.correo_cliente IS NULL OR v.correo_cliente = '' OR v.correo_cliente = 'null' OR v.correo_cliente = 'Cliente no registrado')";
    }
    
    // 5. Búsqueda por monto total (número)
    if (is_numeric($global_escaped)) {
        $total_num = floatval($global_escaped);
        $condiciones[] = "ABS((
            SELECT COALESCE(SUM(v2.cantidad_vendida * p2.precio_venta), 0)
            FROM ventas v2
            JOIN productos p2 ON v2.id_producto = p2.id
            WHERE v2.folio_ticket = v.folio_ticket
        ) - $total_num) < 0.01"; // Margen de error de 1 centavo
    }
    
    // 6. Búsqueda por estado del pedido
    if ($global_lower === 'completado' || $global_lower === 'completados') {
        $condiciones[] = "v.folio_ticket LIKE 'PEDIDO-%' AND (
            SELECT COUNT(*) FROM pedidos 
            WHERE id_orden = CAST(SUBSTRING_INDEX(v.folio_ticket, 'PEDIDO-', -1) AS UNSIGNED)
            AND estado = 'completado'
        ) = (
            SELECT COUNT(*) FROM pedidos 
            WHERE id_orden = CAST(SUBSTRING_INDEX(v.folio_ticket, 'PEDIDO-', -1) AS UNSIGNED)
        )";
    } 
    elseif ($global_lower === 'pendiente' || $global_lower === 'pendientes') {
        $condiciones[] = "v.folio_ticket LIKE 'PEDIDO-%' AND (
            SELECT COUNT(*) FROM pedidos 
            WHERE id_orden = CAST(SUBSTRING_INDEX(v.folio_ticket, 'PEDIDO-', -1) AS UNSIGNED)
            AND estado = 'completado'
        ) < (
            SELECT COUNT(*) FROM pedidos 
            WHERE id_orden = CAST(SUBSTRING_INDEX(v.folio_ticket, 'PEDIDO-', -1) AS UNSIGNED)
        )";
    } 
    elseif ($global_lower === 'venta directa' || $global_lower === 'directa') {
        $condiciones[] = "v.folio_ticket NOT LIKE 'PEDIDO-%'";
    }
    
    $where[] = "(" . implode(" OR ", $condiciones) . ")";
}

// Filtro de fechas
if (!empty($inicio) && !empty($fin)) {
    $inicio_escaped = $conn->real_escape_string($inicio);
    $fin_escaped = $conn->real_escape_string($fin);
    $where[] = "DATE(v.fecha_venta) BETWEEN '$inicio_escaped' AND '$fin_escaped'";
}

$condicion = !empty($where) ? "WHERE " . implode(" AND ", $where) : "";

$sql = "
    SELECT 
        v.folio_ticket,
        v.correo_cliente,
        v.fecha_venta,
        v.ticket_pdf,
        (
            SELECT COALESCE(SUM(v2.cantidad_vendida * p2.precio_venta), 0)
            FROM ventas v2
            JOIN productos p2 ON v2.id_producto = p2.id
            WHERE v2.folio_ticket = v.folio_ticket
        ) AS total_general,
        CASE 
            WHEN v.folio_ticket LIKE 'PEDIDO-%' THEN
                CASE
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
        END AS estado_pedido,
        u.nombre as vendedor_nombre
    FROM ventas v
    LEFT JOIN usuarios u ON v.id_vendedor = u.id
    $condicion
    GROUP BY v.folio_ticket, v.correo_cliente, v.fecha_venta, v.ticket_pdf, u.nombre
    ORDER BY v.fecha_venta DESC
";

$result = $conn->query($sql);
$data = [];

if ($result && $result->num_rows > 0) {
    while ($row = $result->fetch_assoc()) {
        // Determinar si es "Venta en general"
        $cliente = $row['correo_cliente'];
        if (empty($cliente) || $cliente === 'null' || $cliente === 'Cliente no registrado') {
            $cliente = 'Venta en general';
        }
        
        $data[] = [
            'folio_ticket' => $row['folio_ticket'],
            'correo_cliente' => $cliente,
            'fecha_raw' => $row['fecha_venta'],  // ← Esta es la clave para ordenar
            'fecha_venta' => date('d/m/Y H:i', strtotime($row['fecha_venta'])),
            'ticket_pdf' => $row['ticket_pdf'],
            'total_general' => floatval($row['total_general']),
            'estado_pedido' => $row['estado_pedido'],
            'vendedor_nombre' => $row['vendedor_nombre']
        ];
    }
}

echo json_encode(['data' => $data]);
?>