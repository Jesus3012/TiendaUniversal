<?php
header('Content-Type: application/json');
require_once '../includes/db.php';
require_once '../includes/session.php';

if (!isset($_SESSION['usuario_id'])) {
    echo json_encode(['success' => false, 'message' => 'No autorizado']);
    exit;
}

$pagina = isset($_GET['pagina']) ? max(1, intval($_GET['pagina'])) : 1;
$buscar = isset($_GET['buscar']) ? trim($_GET['buscar']) : '';
$por_pagina = 12;
$offset = ($pagina - 1) * $por_pagina;

// Construir consulta base
$sql = "
    SELECT 
        p.*,
        GROUP_CONCAT(cb.codigo SEPARATOR ', ') AS codigos_agrupados
    FROM productos p
    LEFT JOIN codigos_barras cb ON cb.producto_id = p.id
    WHERE (p.tipo_inventario = 'producto' OR p.tipo_inventario IS NULL OR p.tipo_inventario = '')
";

// Agregar condición de búsqueda si existe
if (!empty($buscar)) {
    $buscar = $conn->real_escape_string($buscar);
    $sql .= " AND (p.nombre LIKE '%$buscar%' OR p.categoria LIKE '%$buscar%')";
}

$sql .= " GROUP BY p.id
    ORDER BY 
        CASE 
            WHEN p.imagen IS NOT NULL AND p.imagen != '' THEN 0 
            ELSE 1 
        END,
        p.nombre ASC
    LIMIT $por_pagina OFFSET $offset";

$result = $conn->query($sql);
$productos = [];
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $productos[] = $row;
    }
}

// Contar total para paginación
$count_sql = "
    SELECT COUNT(DISTINCT p.id) as total
    FROM productos p
    WHERE (p.tipo_inventario = 'producto' OR p.tipo_inventario IS NULL OR p.tipo_inventario = '')
";

if (!empty($buscar)) {
    $buscar = $conn->real_escape_string($buscar);
    $count_sql .= " AND (p.nombre LIKE '%$buscar%' OR p.categoria LIKE '%$buscar%')";
}

$count_result = $conn->query($count_sql);
$total_productos = 0;
if ($count_result) {
    $row = $count_result->fetch_assoc();
    $total_productos = $row['total'];
}
$total_paginas = ceil($total_productos / $por_pagina);

echo json_encode([
    'success' => true,
    'productos' => $productos,
    'pagina_actual' => $pagina,
    'total_paginas' => $total_paginas,
    'total_productos' => $total_productos,
    'total_mostrados' => count($productos)
]);
?>