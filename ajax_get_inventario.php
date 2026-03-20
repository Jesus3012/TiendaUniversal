<?php
include 'includes/session.php';
include 'includes/db.php';

if ($rol !== 'administrador') {
    http_response_code(403);
    exit('Acceso denegado');
}

header('Content-Type: application/json');

// Parámetros de búsqueda y filtros
$busqueda = $_GET['busqueda'] ?? '';
$categoria = $_GET['categoria'] ?? 'todas';
$tipo = $_GET['tipo'] ?? 'todos'; // productos, insumos, todos
$stock = $_GET['stock'] ?? 'todos'; // todos, bajo, sin_stock
$pagina = intval($_GET['pagina'] ?? 1);
$por_pagina = intval($_GET['por_pagina'] ?? 50);

// Construir consulta base
$where_clauses = ["p.activo = 1"];
$params = [];
$types = "";

if (!empty($busqueda)) {
    $where_clauses[] = "(p.nombre LIKE ? OR p.categoria LIKE ? OR p.proveedor LIKE ? OR p.atributos LIKE ? OR p.descripcion LIKE ?)";
    $search_term = "%$busqueda%";
    $params = array_merge($params, [$search_term, $search_term, $search_term, $search_term, $search_term]);
    $types .= "sssss";
}

if ($categoria !== 'todas') {
    if ($categoria === 'insumos') {
        $where_clauses[] = "p.tipo_inventario = 'insumo'";
    } else {
        $where_clauses[] = "p.tipo_inventario = 'producto' AND p.categoria = ?";
        $params[] = $categoria;
        $types .= "s";
    }
}

if ($tipo !== 'todos') {
    $where_clauses[] = "p.tipo_inventario = ?";
    $params[] = $tipo;
    $types .= "s";
}

if ($stock === 'bajo') {
    $where_clauses[] = "p.cantidad <= 5 AND p.cantidad > 0";
} elseif ($stock === 'sin_stock') {
    $where_clauses[] = "p.cantidad <= 0";
}

// Consulta para contar total
$count_query = "
    SELECT COUNT(*) as total
    FROM productos p
    WHERE " . implode(" AND ", $where_clauses);

$stmt_count = $conn->prepare($count_query);
if (!empty($params)) {
    $stmt_count->bind_param($types, ...$params);
}
$stmt_count->execute();
$total_resultados = $stmt_count->get_result()->fetch_assoc()['total'];

// Consulta para obtener productos con paginación
$query = "
    SELECT
        p.*,
        (p.precio_venta - p.precio_compra) AS utilidad,
        (SELECT COUNT(*) FROM codigos_barras cb WHERE cb.producto_id = p.id AND cb.disponible = 1) AS codigos_disponibles
    FROM productos p
    WHERE " . implode(" AND ", $where_clauses) . "
    ORDER BY p.tipo_inventario, p.categoria, p.nombre
    LIMIT ? OFFSET ?";

$params[] = $por_pagina;
$params[] = ($pagina - 1) * $por_pagina;
$types .= "ii";

$stmt = $conn->prepare($query);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$result = $stmt->get_result();

$productos = [];
while ($row = $result->fetch_assoc()) {
    $row['atributos_array'] = json_decode($row['atributos'], true) ?? [];
    $productos[] = $row;
}

// Estadísticas rápidas
$stats_query = "
    SELECT
        COUNT(CASE WHEN tipo_inventario = 'producto' THEN 1 END) as total_productos,
        COUNT(CASE WHEN tipo_inventario = 'insumo' THEN 1 END) as total_insumos,
        COUNT(CASE WHEN cantidad <= 5 THEN 1 END) as stock_bajo,
        SUM(precio_compra * cantidad) as valor_total
    FROM productos WHERE activo = 1";

$stats_result = $conn->query($stats_query);
$stats = $stats_result->fetch_assoc();

// Generar HTML
$html = '';
$current_tipo = '';
foreach ($productos as $producto) {
    if ($producto['tipo_inventario'] !== $current_tipo) {
        if (!empty($html)) {
            $html .= '</div>'; // Cerrar sección anterior
        }
        $current_tipo = $producto['tipo_inventario'];
        $titulo = $current_tipo === 'producto' ? 'Productos' : 'Insumos';
        $html .= '<h3 class="section-title"><i class="fas fa-' . ($current_tipo === 'producto' ? 'box' : 'cubes') . '"></i> ' . $titulo . '</h3>';
        $html .= '<div class="row">';
    }

    $imagen = !empty($producto['imagen']) ? 'uploads/productos/' . $producto['imagen'] : '';
    $stock_class = $producto['cantidad'] <= 0 ? 'critical' : ($producto['cantidad'] <= 5 ? 'low' : 'normal');
    $stock_percentage = $producto['cantidad_maxima'] > 0 ? min(100, ($producto['cantidad'] / $producto['cantidad_maxima']) * 100) : 100;

    $html .= '<div class="col-xl-3 col-lg-4 col-md-6 col-sm-12 producto-card-wrapper mb-4">';
    $html .= '<div class="product-card">';

    // Tipo badge
    $html .= '<div class="type-badge ' . $producto['tipo_inventario'] . '">' . ucfirst($producto['tipo_inventario']) . '</div>';

    // Imagen
    if (!empty($imagen)) {
        $html .= '<img src="' . htmlspecialchars($imagen) . '" alt="' . htmlspecialchars($producto['nombre']) . '" class="product-img">';
    } else {
        $html .= '<div class="no-image-placeholder">';
        $html .= '<i class="fas fa-image"></i>';
        $html .= '<span>' . substr(htmlspecialchars($producto['nombre']), 0, 2) . '</span>';
        $html .= '</div>';
    }

    // Stock badge
    $stock_text = $producto['cantidad'] <= 0 ? 'Sin Stock' : ($producto['cantidad'] <= 5 ? 'Stock Bajo' : 'En Stock');
    $badge_class = $producto['cantidad'] <= 0 ? 'badge-danger' : ($producto['cantidad'] <= 5 ? 'badge-warning' : 'badge-success');
    $html .= '<div class="product-badge badge ' . $badge_class . '">' . $stock_text . '</div>';

    $html .= '<div class="card-body">';
    $html .= '<h5 class="card-title font-weight-bold">' . htmlspecialchars($producto['nombre']) . '</h5>';
    $html .= '<p class="text-muted small mb-2">' . htmlspecialchars($producto['categoria']) . ' • ' . htmlspecialchars($producto['proveedor']) . '</p>';

    // Atributos
    if (!empty($producto['atributos_array'])) {
        $html .= '<div class="mb-2">';
        foreach ($producto['atributos_array'] as $attr) {
            $html .= '<span class="attribute-tag">' . htmlspecialchars($attr) . '</span>';
        }
        $html .= '</div>';
    }

    // Precios
    $html .= '<div class="d-flex justify-content-between align-items-center mb-2">';
    $html .= '<span class="text-success font-weight-bold">$ ' . number_format($producto['precio_venta'], 2) . '</span>';
    $html .= '<small class="text-muted">Compra: $ ' . number_format($producto['precio_compra'], 2) . '</small>';
    $html .= '</div>';

    // Utilidad
    $utilidad_color = $producto['utilidad'] >= 0 ? 'text-success' : 'text-danger';
    $html .= '<p class="small ' . $utilidad_color . ' mb-2">Utilidad: $ ' . number_format($producto['utilidad'], 2) . '</p>';

    // Stock
    $html .= '<div class="d-flex justify-content-between align-items-center mb-2">';
    $html .= '<span class="small">Stock: ' . intval($producto['cantidad']) . '</span>';
    if ($producto['cantidad_maxima'] > 0) {
        $html .= '<small class="text-muted">Máx: ' . intval($producto['cantidad_maxima']) . '</small>';
    }
    $html .= '</div>';

    // Barra de stock
    $html .= '<div class="stock-bar">';
    $html .= '<div class="stock-fill ' . $stock_class . '" style="width: ' . $stock_percentage . '%"></div>';
    $html .= '</div>';

    // Códigos disponibles
    if ($producto['codigos_disponibles'] > 0) {
        $html .= '<p class="small text-info mt-2"><i class="fas fa-barcode"></i> Códigos: ' . intval($producto['codigos_disponibles']) . '</p>';
    }

    // Botones
    $html .= '<div class="btn-group btn-group-sm mt-3" role="group">';
    $html .= '<a href="editar_producto.php?id=' . $producto['id'] . '" class="btn btn-outline-primary"><i class="fas fa-edit"></i></a>';
    $html .= '<a href="ver_codigos.php?id=' . $producto['id'] . '" class="btn btn-outline-info"><i class="fas fa-barcode"></i></a>';
    $html .= '<button class="btn btn-outline-danger" onclick="eliminarProducto(' . $producto['id'] . ')"><i class="fas fa-trash"></i></button>';
    $html .= '</div>';

    $html .= '</div></div></div>';
}

if (!empty($html)) {
    $html .= '</div>'; // Cerrar última sección
}

if (empty($productos)) {
    $html = '<div class="empty-state"><i class="fas fa-search"></i><h5>No se encontraron productos</h5><p>Intenta con otros filtros de búsqueda</p></div>';
}

// Generar paginación
$paginacion = '';
$total_paginas = ceil($total_resultados / $por_pagina);
if ($total_paginas > 1) {
    $paginacion .= '<nav aria-label="Paginación de productos">';
    $paginacion .= '<ul class="pagination">';

    // Anterior
    if ($pagina > 1) {
        $paginacion .= '<li class="page-item"><a class="page-link" href="#" data-pagina="' . ($pagina - 1) . '">Anterior</a></li>';
    }

    // Páginas
    $inicio = max(1, $pagina - 2);
    $fin = min($total_paginas, $pagina + 2);

    if ($inicio > 1) {
        $paginacion .= '<li class="page-item"><a class="page-link" href="#" data-pagina="1">1</a></li>';
        if ($inicio > 2) {
            $paginacion .= '<li class="page-item disabled"><span class="page-link">...</span></li>';
        }
    }

    for ($i = $inicio; $i <= $fin; $i++) {
        $active = $i == $pagina ? ' active' : '';
        $paginacion .= '<li class="page-item' . $active . '"><a class="page-link" href="#" data-pagina="' . $i . '">' . $i . '</a></li>';
    }

    if ($fin < $total_paginas) {
        if ($fin < $total_paginas - 1) {
            $paginacion .= '<li class="page-item disabled"><span class="page-link">...</span></li>';
        }
        $paginacion .= '<li class="page-item"><a class="page-link" href="#" data-pagina="' . $total_paginas . '">' . $total_paginas . '</a></li>';
    }

    // Siguiente
    if ($pagina < $total_paginas) {
        $paginacion .= '<li class="page-item"><a class="page-link" href="#" data-pagina="' . ($pagina + 1) . '">Siguiente</a></li>';
    }

    $paginacion .= '</ul></nav>';
}

echo json_encode([
    'productos' => $productos,
    'total' => $total_resultados,
    'pagina' => $pagina,
    'por_pagina' => $por_pagina,
    'total_paginas' => $total_paginas,
    'html' => $html,
    'paginacion' => $paginacion,
    'stats' => $stats
]);
?>