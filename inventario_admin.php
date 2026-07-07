<?php
include 'includes/session.php';
include 'includes/db.php';
include 'includes/header.php';
include 'includes/navbar.php';

if ($rol !== 'administrador') {
    header("Location: inventario_vendedor.php");
    exit;
}

/* ================= FUNCIONES ================= */
function obtenerCategorias($conn) {
    $result = $conn->query("
        SELECT DISTINCT categoria 
        FROM productos 
        WHERE tipo_inventario = 'producto' AND activo = 1 AND categoria IS NOT NULL AND categoria != ''
        ORDER BY categoria
    ");
    $categorias = [];
    while ($row = $result->fetch_assoc()) {
        $categorias[] = $row['categoria'];
    }
    return $categorias;
}

function obtenerProveedores($conn) {
    $result = $conn->query("
        SELECT DISTINCT proveedor 
        FROM productos 
        WHERE proveedor IS NOT NULL AND proveedor != '' AND activo = 1
        ORDER BY proveedor
    ");
    $proveedores = [];
    while ($row = $result->fetch_assoc()) {
        $proveedores[] = $row['proveedor'];
    }
    return $proveedores;
}

function obtenerTodosProductos($conn) {
    $result = $conn->query("
        SELECT 
            p.*,
            (p.precio_venta - p.precio_compra) AS utilidad,
            (SELECT COUNT(*) FROM codigos_barras cb WHERE cb.producto_id = p.id AND cb.disponible = 1) AS codigos_disponibles
        FROM productos p
        WHERE p.tipo_inventario = 'producto' 
        AND p.activo = 1
        ORDER BY p.nombre ASC
    ");
    $productos = [];
    while ($row = $result->fetch_assoc()) {
        $row['atributos_array'] = json_decode($row['atributos'], true);
        $row['tipo'] = 'producto';
        $productos[] = $row;
    }
    return $productos;
}

function obtenerTodosInsumos($conn) {
    $result = $conn->query("
        SELECT 
            p.*,
            (SELECT COUNT(*) FROM codigos_barras cb WHERE cb.producto_id = p.id AND cb.disponible = 1) AS codigos_disponibles
        FROM productos p
        WHERE p.tipo_inventario = 'insumo' 
        AND p.activo = 1
        ORDER BY p.nombre ASC
    ");
    $insumos = [];
    while ($row = $result->fetch_assoc()) {
        $row['atributos_array'] = json_decode($row['atributos'], true);
        $row['tipo'] = 'insumo';
        $insumos[] = $row;
    }
    return $insumos;
}


function obtenerCodigosProductos($conn, $productoIds) {
    $codigosPorProducto = [];

    $ids = array_values(array_unique(array_filter(array_map('intval', $productoIds), function($id) {
        return $id > 0;
    })));

    if (empty($ids)) {
        return $codigosPorProducto;
    }

    $idsSql = implode(',', $ids);

    /*
        Se usa SELECT * para que funcione aunque tu columna se llame:
        codigo, codigo_barra, codigo_barras, barcode, codigo_producto o clave.
        El sistema toma automáticamente la primera que encuentre con valor.
    */
    $sql = "
        SELECT *
        FROM codigos_barras
        WHERE producto_id IN ($idsSql)
        AND disponible = 1
        ORDER BY producto_id ASC, id ASC
    ";

    $result = $conn->query($sql);

    if (!$result) {
        return $codigosPorProducto;
    }

    while ($row = $result->fetch_assoc()) {
        $productoId = (int)($row['producto_id'] ?? 0);
        if ($productoId <= 0) {
            continue;
        }

        $codigo = '';
        foreach (['codigo', 'codigo_barra', 'codigo_barras', 'barcode', 'codigo_producto', 'clave'] as $campo) {
            if (isset($row[$campo]) && trim((string)$row[$campo]) !== '') {
                $codigo = trim((string)$row[$campo]);
                break;
            }
        }

        if ($codigo === '') {
            continue;
        }

        if (!isset($codigosPorProducto[$productoId])) {
            $codigosPorProducto[$productoId] = [];
        }

        $codigosPorProducto[$productoId][] = [
            'id' => (int)($row['id'] ?? 0),
            'codigo' => $codigo,
            'disponible' => isset($row['disponible']) ? (int)$row['disponible'] : 1,
            'fecha' => $row['created_at'] ?? $row['fecha_creacion'] ?? $row['fecha_registro'] ?? ''
        ];
    }

    return $codigosPorProducto;
}

function jsonSeguro($data) {
    return htmlspecialchars(
        json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ENT_QUOTES,
        'UTF-8'
    );
}

function obtenerEstadisticas($conn) {
    $stats = [];
    
    $result = $conn->query("SELECT COUNT(*) as total FROM productos WHERE activo = 1 AND tipo_inventario = 'producto'");
    $stats['total_productos'] = $result->fetch_assoc()['total'];
    
    $result = $conn->query("SELECT COUNT(*) as total FROM productos WHERE activo = 1 AND tipo_inventario = 'insumo'");
    $stats['total_insumos'] = $result->fetch_assoc()['total'];
    
    $result = $conn->query("SELECT COUNT(*) as total FROM productos WHERE cantidad <= 5 AND activo = 1");
    $stats['stock_bajo'] = $result->fetch_assoc()['total'];
    
    $result = $conn->query("SELECT COUNT(*) as total FROM productos WHERE cantidad = 0 AND activo = 1");
    $stats['sin_stock'] = $result->fetch_assoc()['total'];
    
    $result = $conn->query("SELECT SUM(precio_venta * cantidad) as total FROM productos WHERE activo = 1");
    $stats['valor_total'] = $result->fetch_assoc()['total'] ?? 0;
    
    return $stats;
}

$stats = obtenerEstadisticas($conn);
$todosProductos = obtenerTodosProductos($conn);
$todosInsumos = obtenerTodosInsumos($conn);
$categorias = obtenerCategorias($conn);
$proveedores = obtenerProveedores($conn);

$idsInventario = array_merge(
    array_column($todosProductos, 'id'),
    array_column($todosInsumos, 'id')
);
$codigosPorProducto = obtenerCodigosProductos($conn, $idsInventario);

foreach ($todosProductos as &$productoTmp) {
    $productoTmp['codigos_array'] = $codigosPorProducto[(int)$productoTmp['id']] ?? [];
    $productoTmp['codigos_disponibles'] = count($productoTmp['codigos_array']);
}
unset($productoTmp);

foreach ($todosInsumos as &$insumoTmp) {
    $insumoTmp['codigos_array'] = $codigosPorProducto[(int)$insumoTmp['id']] ?? [];
    $insumoTmp['codigos_disponibles'] = count($insumoTmp['codigos_array']);
}
unset($insumoTmp);
?>

<link rel="stylesheet" href="css/inventario.css?v=<?= time() ?>">

<style>
    .producto-item .product-card,
    .insumo-item .product-card {
        cursor: pointer;
        transition: transform .18s ease, box-shadow .18s ease, border-color .18s ease;
    }

    .producto-item .product-card:hover,
    .insumo-item .product-card:hover {
        transform: translateY(-2px);
        box-shadow: 0 12px 28px rgba(15, 23, 42, .12);
    }

    .barcode-open-hint {
        margin-top: 10px;
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 7px;
        border: 1px dashed rgba(249, 115, 22, .35);
        background: linear-gradient(135deg, rgba(255, 247, 237, .9), rgba(255, 255, 255, .92));
        color: #c2410c;
        font-size: .76rem;
        font-weight: 800;
        border-radius: 12px;
        padding: 7px 9px;
    }

    .barcode-open-hint.empty {
        border-color: rgba(148, 163, 184, .45);
        background: #f8fafc;
        color: #64748b;
    }

    .barcode-modal .modal-content {
        border: 0;
        border-radius: 22px;
        overflow: hidden;
        box-shadow: 0 22px 60px rgba(15, 23, 42, .22);
    }

    .barcode-modal .modal-header {
        border: 0;
        color: white;
        background:
            radial-gradient(circle at top right, rgba(255,255,255,.22), transparent 34%),
            linear-gradient(135deg, #f97316, #ea580c);
        padding: 16px 18px;
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 14px;
    }

    .barcode-modal .modal-title {
        font-weight: 900;
        letter-spacing: -.02em;
        display: flex;
        align-items: center;
        gap: 9px;
        line-height: 1.2;
    }

    .barcode-close-btn {
        width: 42px;
        height: 42px;
        border: 0;
        border-radius: 999px;
        background: #ffffff;
        color: #c2410c;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        font-size: 1.08rem;
        box-shadow: 0 12px 24px rgba(124, 45, 18, .22);
        transition: transform .16s ease, background .16s ease, color .16s ease, box-shadow .16s ease;
        flex: 0 0 auto;
    }

    .barcode-close-btn:hover {
        transform: translateY(-1px);
        background: #fff7ed;
        color: #9a3412;
        box-shadow: 0 16px 28px rgba(124, 45, 18, .28);
    }

    .barcode-close-btn:focus {
        outline: 3px solid rgba(255, 237, 213, .95);
        outline-offset: 2px;
    }

    .barcode-summary {
        display: grid;
        grid-template-columns: 48px 1fr;
        gap: 12px;
        align-items: center;
        padding: 14px;
        border: 1px solid #fed7aa;
        border-radius: 16px;
        background: #fff7ed;
        margin-bottom: 14px;
    }

    .barcode-summary-icon {
        width: 48px;
        height: 48px;
        display: grid;
        place-items: center;
        border-radius: 15px;
        background: white;
        color: #f97316;
        font-size: 1.35rem;
        box-shadow: 0 8px 18px rgba(249, 115, 22, .16);
    }

    .barcode-summary h6 {
        margin: 0;
        font-size: .95rem;
        font-weight: 900;
        color: #111827;
    }

    .barcode-summary p {
        margin: 2px 0 0;
        color: #64748b;
        font-size: .82rem;
        font-weight: 600;
    }

    .barcode-list {
        display: grid;
        gap: 12px;
        max-height: 58vh;
        overflow: auto;
        padding-right: 3px;
    }

    .barcode-item-card {
        border: 1px solid #fed7aa;
        border-radius: 18px;
        background:
            linear-gradient(180deg, #ffffff 0%, #fffaf5 100%);
        padding: 14px;
        box-shadow: 0 10px 26px rgba(15, 23, 42, .08);
    }

    .barcode-item-head {
        display: flex;
        justify-content: space-between;
        align-items: center;
        gap: 10px;
        margin-bottom: 8px;
    }

    .barcode-item-head span {
        color: #475569;
        font-size: .78rem;
        font-weight: 900;
        text-transform: uppercase;
        letter-spacing: .04em;
    }

    .barcode-copy-btn {
        border: 0;
        border-radius: 999px;
        background: #fff7ed;
        color: #c2410c;
        font-size: .78rem;
        font-weight: 900;
        padding: 7px 12px;
        transition: transform .16s ease, background .16s ease;
        white-space: nowrap;
    }

    .barcode-copy-btn:hover {
        background: #ffedd5;
        transform: translateY(-1px);
    }

    .barcode-code-hero {
        display: grid;
        grid-template-columns: 38px 1fr;
        gap: 10px;
        align-items: center;
        margin-bottom: 10px;
        padding: 10px 12px;
        border-radius: 15px;
        border: 1px solid #fdba74;
        background: linear-gradient(135deg, #fff7ed, #ffffff);
    }

    .barcode-code-icon {
        width: 38px;
        height: 38px;
        border-radius: 13px;
        display: grid;
        place-items: center;
        background: #ffedd5;
        color: #c2410c;
        font-size: 1rem;
    }

    .barcode-code-label {
        margin: 0 0 3px;
        color: #9a3412;
        font-size: .68rem;
        font-weight: 900;
        text-transform: uppercase;
        letter-spacing: .08em;
    }

    .barcode-code-value {
        margin: 0;
        color: #111827;
        font-family: Consolas, Monaco, 'Courier New', monospace;
        font-size: clamp(1.08rem, 2.8vw, 1.65rem);
        font-weight: 950;
        letter-spacing: .08em;
        line-height: 1.1;
        word-break: break-word;
    }

    .barcode-svg-wrap {
        border-radius: 14px;
        border: 1px solid #e5e7eb;
        background: #ffffff;
        padding: 12px 10px;
        text-align: center;
        overflow-x: auto;
    }

    .barcode-svg {
        width: 100%;
        max-width: 430px;
        min-height: 98px;
    }
    .barcode-help-text {
        margin: 8px 0 0;
        color: #64748b;
        font-size: .78rem;
        font-weight: 700;
        text-align: center;
    }

    @media (max-width: 575.98px) {
        .barcode-modal .modal-dialog {
            margin: .75rem;
        }

        .barcode-modal .modal-header {
            padding: 14px;
        }

        .barcode-close-btn {
            width: 38px;
            height: 38px;
        }

        .barcode-code-hero {
            grid-template-columns: 1fr;
            text-align: center;
        }

        .barcode-code-icon {
            margin: 0 auto;
        }
    }

    .barcode-empty-state {
        text-align: center;
        padding: 22px 12px;
        border-radius: 16px;
        background: #f8fafc;
        border: 1px dashed #cbd5e1;
        color: #64748b;
    }

    .barcode-empty-state i {
        font-size: 2.2rem;
        color: #94a3b8;
        margin-bottom: 10px;
    }

    .barcode-empty-state h6 {
        font-weight: 900;
        color: #334155;
        margin-bottom: 5px;
    }
</style>


<div class="content-wrapper">
    <div class="container-fluid">
        
        <!-- Breadcrumb -->
        <div class="content-header">
            <div class="container-fluid">
                <div class="row mb-2">
                    <div class="col-sm-6">
                        <h1 class="m-0" style="font-size: 1.5rem; font-weight: 700;">
                            <i class="fas fa-boxes" style="color: #f97316;"></i> Inventario
                        </h1>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- BREADCRUMB BLANCO - INVENTARIO -->
        <div class="custom-breadcrumb">
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb">
                    <li class="breadcrumb-item">
                        <a href="<?= $_SESSION['rol'] === 'administrador' ? 'dashboard_admin.php' : 'dashboard_vendedor.php' ?>">
                            <i class="fas fa-home"></i> Inicio
                        </a>
                    </li>
                    <li class="breadcrumb-item">
                        <a href="dashboard_inventario.php">
                            <i class="fas fa-boxes"></i> Inventario
                        </a>
                    </li>
                    <li class="breadcrumb-item active">
                        <i class="fas fa-chart-line"></i> General
                    </li>
                </ol>
            </nav>
        </div>

        <!-- Stats Boxes -->
        <div class="row">
            <div class="col-lg-3 col-6">
                <div class="small-box bg-primary-custom">
                    <div class="inner">
                        <h3><?= number_format($stats['total_productos']) ?></h3>
                        <p>Productos</p>
                    </div>
                    <div class="icon">
                        <i class="fas fa-box"></i>
                    </div>
                </div>
            </div>
            <div class="col-lg-3 col-6">
                <div class="small-box bg-success-custom">
                    <div class="inner">
                        <h3><?= number_format($stats['total_insumos']) ?></h3>
                        <p>Insumos</p>
                    </div>
                    <div class="icon">
                        <i class="fas fa-cubes"></i>
                    </div>
                </div>
            </div>
            <div class="col-lg-3 col-6">
                <div class="small-box bg-warning-custom">
                    <div class="inner">
                        <h3><?= number_format($stats['stock_bajo']) ?></h3>
                        <p>Stock Bajo (≤5)</p>
                    </div>
                    <div class="icon">
                        <i class="fas fa-exclamation-triangle"></i>
                    </div>
                </div>
            </div>
            <div class="col-lg-3 col-6">
                <div class="small-box bg-info-custom">
                    <div class="inner">
                        <h3>$<?= number_format($stats['valor_total'], 0) ?></h3>
                        <p>Valor Inventario</p>
                    </div>
                    <div class="icon">
                        <i class="fas fa-dollar-sign"></i>
                    </div>
                </div>
            </div>
        </div>
        <br>
        
        <!-- Toolbar -->
        <div class="toolbar">
            <div class="search-box">
                <i class="fas fa-search"></i>
                <input type="text" id="searchInput" placeholder="Buscar por nombre, categoría o proveedor..." autocomplete="off">
                <i class="fas fa-times" id="clearSearch" style="display: none;"></i>
            </div>
            
            <div class="filter-buttons">
                <button class="filter-btn active" data-filter="todos">
                    <i class="fas fa-th-large"></i> Todos
                </button>
                <button class="filter-btn" data-filter="productos">
                    <i class="fas fa-box"></i> Productos
                </button>
                <button class="filter-btn" data-filter="insumos">
                    <i class="fas fa-cubes"></i> Insumos
                </button>
                <button class="filter-btn" data-filter="stockBajo">
                    <i class="fas fa-exclamation-triangle"></i> Stock Bajo
                </button>
                <button class="filter-btn" data-filter="sinStock">
                    <i class="fas fa-times-circle"></i> Sin Stock
                </button>
            </div>
            
            <div>
                <button class="filter-btn" id="toggleFiltersBtn">
                    <i class="fas fa-sliders-h"></i> Filtros
                    <span class="badge bg-danger text-white rounded-pill ms-1" id="filtersCount" style="display: none; font-size: 0.6rem;">0</span>
                </button>
            </div>

            <div class="view-toggle">
                <button class="view-btn active" data-view="grid">
                    <i class="fas fa-th"></i>
                </button>
                <button class="view-btn" data-view="list">
                    <i class="fas fa-list"></i>
                </button>
            </div>
        </div>

        <!-- Filters Panel -->
        <div class="filters-panel" id="filtersPanel">
            <div class="row">
                <div class="col-md-6">
                    <div class="filter-section">
                        <div class="filter-section-title"><i class="fas fa-tags"></i> Categorías</div>
                        <div class="filter-tags" data-filter-type="categoria">
                            <span class="filter-tag active" data-value="">Todas</span>
                            <?php foreach($categorias as $cat): ?>
                            <span class="filter-tag" data-value="<?= htmlspecialchars($cat) ?>"><?= htmlspecialchars($cat) ?></span>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="filter-section">
                        <div class="filter-section-title"><i class="fas fa-truck"></i> Proveedores</div>
                        <div class="filter-tags" data-filter-type="proveedor">
                            <span class="filter-tag active" data-value="">Todos</span>
                            <?php foreach($proveedores as $prov): ?>
                            <span class="filter-tag" data-value="<?= htmlspecialchars($prov) ?>"><?= htmlspecialchars($prov) ?></span>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Active Filters Bar -->
        <div class="active-filters-bar" id="activeFiltersBar" style="display: none;">
            <span class="small text-muted"><i class="fas fa-filter"></i> Filtros activos:</span>
            <div id="activeFiltersList" class="d-flex flex-wrap gap-2"></div>
            <span class="clear-filters" id="clearAllFilters">
                <i class="fas fa-times-circle"></i> Limpiar todo
            </span>
        </div>

        <!-- Productos Section -->
        <div class="card" id="productosCard">
            <div class="card-header">
                <h3 class="card-title">
                    <i class="fas fa-box" style="color: #3b82f6;"></i> Productos
                    <span class="badge bg-primary ms-2" id="productosCount"><?= count($todosProductos) ?></span>
                </h3>
            </div>
            <div class="card-body">
                <div class="row" id="productosGrid">
                    <?php foreach($todosProductos as $producto): 
                        $stock = $producto['cantidad'];
                        if($stock <= 0) { $stockClass = 'critical'; $stockStatus = 'Agotado'; }
                        elseif($stock <= 5) { $stockClass = 'critical'; $stockStatus = 'Stock Crítico'; }
                        elseif($stock <= 15) { $stockClass = 'low'; $stockStatus = 'Stock Bajo'; }
                        else { $stockClass = 'normal'; $stockStatus = 'Stock Normal'; }
                        $stockPercent = min(100, ($stock / 50) * 100);
                        $inicial = strtoupper(substr($producto['nombre'], 0, 2));
                    ?>
                    <div class="col-lg-3 col-md-4 col-sm-6 col-12 mb-3 producto-item"
                        data-id="<?= $producto['id'] ?>"
                        data-articulo="<?= htmlspecialchars($producto['nombre'], ENT_QUOTES, 'UTF-8') ?>"
                        data-codigos='<?= jsonSeguro($producto['codigos_array'] ?? []) ?>'
                        data-nombre="<?= strtolower(htmlspecialchars($producto['nombre'])) ?>"
                        data-categoria="<?= strtolower(htmlspecialchars($producto['categoria'] ?? '')) ?>"
                        data-proveedor="<?= strtolower(htmlspecialchars($producto['proveedor'] ?? '')) ?>"
                        data-stock="<?= $stock ?>"
                        data-tipo="producto">
                        
                        <?php 
                        // Determinar color e ícono según categoría o proveedor
                        $categoriaSlug = strtolower(str_replace(' ', '-', $producto['categoria'] ?? ''));
                        $proveedorSlug = strtolower(str_replace(' ', '-', $producto['proveedor'] ?? ''));
                        
                        // Asignar color e ícono dinámico
                        $colorClase = '';
                        $colorHex = '#f97316'; // Color por defecto naranja
                        $iconoProducto = 'fa-box'; // Icono por defecto
                        $iconoCard = 'fa-box';
                        
                        if($categoriaSlug == 'figuras') {
                            $colorClase = 'categoria-figuras';
                            $colorHex = '#3b82f6'; // Azul
                            $iconoProducto = 'fa-dragon';
                            $iconoCard = 'fa-dragon';
                        } elseif($categoriaSlug == 'impresion-3d' || $categoriaSlug == 'impresión-3d') {
                            $colorClase = 'categoria-impresion-3d';
                            $colorHex = '#8b5cf6'; // Morado
                            $iconoProducto = 'fa-cube';
                            $iconoCard = 'fa-cube';
                        } elseif($categoriaSlug == 'dinosaurios') {
                            $colorClase = 'categoria-dinosaurios';
                            $colorHex = '#10b981'; // Verde esmeralda
                            $iconoProducto = 'fa-dragon';
                            $iconoCard = 'fa-dragon';
                        } elseif($categoriaSlug == 'marinos') {
                            $colorClase = 'categoria-marinos';
                            $colorHex = '#06b6d4'; // Celeste
                            $iconoProducto = 'fa-fish';
                            $iconoCard = 'fa-fish';
                        } elseif($categoriaSlug == 'reptiles') {
                            $colorClase = 'categoria-reptiles';
                            $colorHex = '#84cc16'; // Verde lima
                            $iconoProducto = 'fa-lizard';
                            $iconoCard = 'fa-lizard';
                        } elseif($categoriaSlug == 'aves') {
                            $colorClase = 'categoria-aves';
                            $colorHex = '#f59e0b'; // Ámbar
                            $iconoProducto = 'fa-dove';
                            $iconoCard = 'fa-dove';
                        } elseif($proveedorSlug == 'nevaris-3d') {
                            $colorClase = 'proveedor-nevaris';
                            $colorHex = '#ec489a'; // Rosa
                            $iconoProducto = 'fa-star';
                            $iconoCard = 'fa-star';
                        } elseif($proveedorSlug == 'artesanias-gloria') {
                            $colorClase = 'proveedor-artesanias';
                            $colorHex = '#14b8a6'; // Turquesa
                            $iconoProducto = 'fa-hands';
                            $iconoCard = 'fa-hands';
                        } elseif($categoriaSlug == 'herramientas') {
                            $colorClase = 'categoria-herramientas';
                            $colorHex = '#ef4444'; // Rojo
                            $iconoProducto = 'fa-tools';
                            $iconoCard = 'fa-tools';
                        } elseif($categoriaSlug == 'accesorios') {
                            $colorClase = 'categoria-accesorios';
                            $colorHex = '#a855f7'; // Morado claro
                            $iconoProducto = 'fa-ring';
                            $iconoCard = 'fa-ring';
                        }
                        
                        // Determinar insignia según el stock
                        $insigniaIcono = '';
                        $insigniaTexto = '';
                        if($stock <= 0) {
                            $insigniaIcono = 'fa-skull';
                            $insigniaTexto = 'AGOTADO';
                        } elseif($stock <= 5) {
                            $insigniaIcono = 'fa-exclamation-triangle';
                            $insigniaTexto = 'STOCK CRÍTICO';
                        } elseif($stock <= 15) {
                            $insigniaIcono = 'fa-chart-line';
                            $insigniaTexto = 'STOCK BAJO';
                        } else {
                            $insigniaIcono = 'fa-check-circle';
                            $insigniaTexto = 'DISPONIBLE';
                        }
                        ?>
                        
                        <div class="product-card producto <?= $colorClase ?>" style="border-top: 3px solid <?= $colorHex ?>;">
                            <div class="product-image">
                                <?php if(!empty($producto['imagen']) && file_exists($producto['imagen'])): ?>
                                    <img src="<?= htmlspecialchars($producto['imagen']) ?>" alt="<?= htmlspecialchars($producto['nombre']) ?>">
                                <?php else: ?>
                                    <div class="no-image" style="color: <?= $colorHex ?>; border-color: <?= $colorHex ?>;">
                                        <i class="fas <?= $iconoProducto ?>" style="color: <?= $colorHex ?>; font-size: 3rem;"></i>
                                        <span style="color: <?= $colorHex ?>;"><?= $inicial ?></span>
                                    </div>
                                <?php endif; ?>
                                
                                <!-- Badge de tipo de producto -->
                                <div class="product-badge producto" style="background: <?= $colorHex ?>;">
                                    <i class="fas <?= $iconoCard ?>"></i>
                                    <span>Producto</span>
                                </div>
                                
                                <!-- Badge de estado de stock -->
                                <?php if($stock <= 5): ?>
                                <div class="stock-warning-badge" style="background: <?= $colorHex ?>;">
                                    <i class="fas <?= $insigniaIcono ?>"></i>
                                    <span><?= $insigniaTexto ?></span>
                                </div>
                                <?php endif; ?>
                                
                                <?php if($producto['codigos_disponibles'] > 0): ?>
                                <div class="barcode-badge">
                                    <i class="fas fa-barcode"></i> <?= $producto['codigos_disponibles'] ?>
                                </div>
                                <?php endif; ?>
                            </div>
                            
                            <div class="product-body">
                                <h6 class="product-title"><?= htmlspecialchars($producto['nombre']) ?></h6>
                                
                                <div class="product-meta">
                                    <?php if($producto['categoria']): ?>
                                    <span class="meta-badge categoria" style="background: <?= $colorHex ?>20; color: <?= $colorHex ?>;">
                                        <i class="fas <?= $iconoCard ?>"></i> <?= htmlspecialchars($producto['categoria']) ?>
                                    </span>
                                    <?php endif; ?>
                                    <?php if($producto['proveedor']): ?>
                                    <span class="meta-badge proveedor">
                                        <i class="fas fa-truck"></i> <?= htmlspecialchars($producto['proveedor']) ?>
                                    </span>
                                    <?php endif; ?>
                                </div>
                                
                                <?php if(!empty($producto['atributos_array'])): ?>
                                <div class="attributes">
                                    <?php if(isset($producto['atributos_array']['color'])): ?>
                                    <span class="attr-badge"><i class="fas fa-palette"></i> <?= htmlspecialchars($producto['atributos_array']['color']) ?></span>
                                    <?php endif; ?>
                                    <?php if(isset($producto['atributos_array']['talla'])): ?>
                                    <span class="attr-badge"><i class="fas fa-ruler"></i> <?= htmlspecialchars($producto['atributos_array']['talla']) ?></span>
                                    <?php endif; ?>
                                    <?php if(isset($producto['atributos_array']['material'])): ?>
                                    <span class="attr-badge"><i class="fas fa-cube"></i> <?= htmlspecialchars($producto['atributos_array']['material']) ?></span>
                                    <?php endif; ?>
                                </div>
                                <?php endif; ?>
                                
                                <div class="prices prices-producto">
                                    <div class="price-item">
                                        <small><i class="fas fa-arrow-down"></i> Compra</small>
                                        <span class="price-value" style="color: #dc2626;">$<?= number_format($producto['precio_compra'], 0) ?></span>
                                    </div>
                                    <div class="price-item">
                                        <small><i class="fas fa-arrow-up"></i> Venta</small>
                                        <span class="price-value" style="color: #16a34a;">$<?= number_format($producto['precio_venta'], 0) ?></span>
                                    </div>
                                    <div class="price-item">
                                        <small><i class="fas fa-chart-line"></i> Utilidad</small>
                                        <span class="price-value" style="color: <?= $colorHex ?>;">$<?= number_format($producto['utilidad'], 0) ?></span>
                                    </div>
                                </div>
                                
                                <div class="stock-info">
                                    <div class="d-flex justify-content-between align-items-center">
                                        <span class="stock-status <?= $stockClass ?>">
                                            <i class="fas <?= $stock <= 0 ? 'fa-skull' : ($stock <= 5 ? 'fa-exclamation-triangle' : 'fa-check-circle') ?>"></i>
                                            <?= $stockStatus ?>
                                        </span>
                                        <span class="stock-number"><?= number_format($stock) ?> unidades</span>
                                    </div>
                                    <div class="progress-bar-custom">
                                        <div class="progress-fill <?= $stockClass ?>" style="width: <?= $stockPercent ?>%"></div>
                                    </div>
                                </div>

                                <div class="barcode-open-hint <?= empty($producto['codigos_array']) ? 'empty' : '' ?>">
                                    <i class="fas fa-barcode"></i>
                                    <span><?= empty($producto['codigos_array']) ? 'Sin códigos registrados' : 'Clic para ver códigos' ?></span>
                                </div>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <div id="productosEmpty" class="empty-state" style="display: none;">
                    <i class="fas fa-box-open"></i>
                    <h5>No hay productos</h5>
                    <p>No se encontraron productos con los filtros seleccionados</p>
                </div>
            </div>
        </div>

        <!-- Insumos Section -->
        <div class="card" id="insumosCard">
            <div class="card-header">
                <h3 class="card-title">
                    <i class="fas fa-cubes" style="color: #22c55e;"></i> Insumos y Materiales
                    <span class="badge bg-success ms-2" id="insumosCount"><?= count($todosInsumos) ?></span>
                </h3>
            </div>
            <div class="card-body">
                <div class="row" id="insumosGrid">
                    <?php foreach($todosInsumos as $insumo): 
                        $stock = $insumo['cantidad'];
                        if($stock <= 0) { $stockClass = 'critical'; $stockStatus = 'Agotado'; }
                        elseif($stock <= 5) { $stockClass = 'critical'; $stockStatus = 'Stock Crítico'; }
                        elseif($stock <= 15) { $stockClass = 'low'; $stockStatus = 'Stock Bajo'; }
                        else { $stockClass = 'normal'; $stockStatus = 'Stock Normal'; }
                        $stockPercent = min(100, ($stock / 50) * 100);
                        $inicial = strtoupper(substr($insumo['nombre'], 0, 2));
                        $unidad = $insumo['atributos_array']['unidad'] ?? 'unidad';
                    ?>
                    <div class="col-lg-3 col-md-4 col-sm-6 col-12 mb-3 insumo-item"
                         data-id="<?= $insumo['id'] ?>"
                         data-articulo="<?= htmlspecialchars($insumo['nombre'], ENT_QUOTES, 'UTF-8') ?>"
                         data-codigos='<?= jsonSeguro($insumo['codigos_array'] ?? []) ?>'
                         data-nombre="<?= strtolower(htmlspecialchars($insumo['nombre'])) ?>"
                         data-categoria="<?= strtolower(htmlspecialchars($insumo['categoria'] ?? '')) ?>"
                         data-proveedor="<?= strtolower(htmlspecialchars($insumo['proveedor'] ?? '')) ?>"
                         data-stock="<?= $stock ?>"
                         data-tipo="insumo">
                        
                        <div class="product-card insumo">
                            <div class="product-image">
                                <?php if(!empty($insumo['imagen']) && file_exists($insumo['imagen'])): ?>
                                    <img src="<?= htmlspecialchars($insumo['imagen']) ?>" alt="<?= htmlspecialchars($insumo['nombre']) ?>">
                                <?php else: ?>
                                    <div class="no-image">
                                        <i class="fas fa-cubes"></i>
                                        <span><?= $inicial ?></span>
                                    </div>
                                <?php endif; ?>
                                <div class="product-badge insumo">
                                    <i class="fas fa-cubes"></i> Insumo
                                </div>
                            </div>
                            
                            <div class="product-body">
                                <h6 class="product-title"><?= htmlspecialchars($insumo['nombre']) ?></h6>
                                
                                <div class="product-meta">
                                    <?php if($insumo['categoria']): ?>
                                    <span class="meta-badge categoria"><i class="fas fa-tag"></i> <?= htmlspecialchars($insumo['categoria']) ?></span>
                                    <?php endif; ?>
                                    <?php if($insumo['proveedor']): ?>
                                    <span class="meta-badge proveedor"><i class="fas fa-truck"></i> <?= htmlspecialchars($insumo['proveedor']) ?></span>
                                    <?php endif; ?>
                                </div>
                                
                                <?php if(!empty($insumo['atributos_array'])): ?>
                                <div class="attributes">
                                    <?php if(isset($insumo['atributos_array']['material'])): ?>
                                    <span class="attr-badge"><i class="fas fa-tshirt"></i> <?= htmlspecialchars($insumo['atributos_array']['material']) ?></span>
                                    <?php endif; ?>
                                    <?php if(isset($insumo['atributos_array']['color'])): ?>
                                    <span class="attr-badge"><i class="fas fa-palette"></i> <?= htmlspecialchars($insumo['atributos_array']['color']) ?></span>
                                    <?php endif; ?>
                                </div>
                                <?php endif; ?>
                                
                                <div class="prices prices-insumo">
                                    <div class="price-item">
                                        <small><i class="fas fa-tag"></i> Costo</small>
                                        <span class="text-danger">$<?= number_format($insumo['precio_compra'], 2) ?></span>
                                    </div>
                                    <div class="price-item">
                                        <small><i class="fas fa-weight-hanging"></i> Unidad</small>
                                        <span class="text-primary"><?= htmlspecialchars($unidad) ?></span>
                                    </div>
                                </div>
                                
                                <div class="stock-info">
                                    <div class="d-flex justify-content-between align-items-center">
                                        <span class="stock-status <?= $stockClass ?>">
                                            <i class="fas <?= $stock <= 0 ? 'fa-times-circle' : ($stock <= 5 ? 'fa-exclamation-triangle' : 'fa-check-circle') ?>"></i>
                                            <?= $stockStatus ?>
                                        </span>
                                        <span class="stock-number"><?= number_format($stock, 2) ?> <?= $unidad ?></span>
                                    </div>
                                    <div class="progress-bar-custom">
                                        <div class="progress-fill <?= $stockClass ?>" style="width: <?= $stockPercent ?>%"></div>
                                    </div>
                                </div>

                                <div class="barcode-open-hint <?= empty($insumo['codigos_array']) ? 'empty' : '' ?>">
                                    <i class="fas fa-barcode"></i>
                                    <span><?= empty($insumo['codigos_array']) ? 'Sin códigos registrados' : 'Clic para ver códigos' ?></span>
                                </div>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <div id="insumosEmpty" class="empty-state" style="display: none;">
                    <i class="fas fa-cubes"></i>
                    <h5>No hay insumos</h5>
                    <p>No se encontraron insumos con los filtros seleccionados</p>
                </div>
            </div>
        </div>

    </div>
</div>


<!-- Modal códigos de barras -->
<div class="modal fade barcode-modal" id="barcodeModal" tabindex="-1" aria-labelledby="barcodeModalTitle" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="barcodeModalTitle">
                    <i class="fas fa-barcode me-2"></i> Códigos de barras
                </h5>
                <button type="button" class="barcode-close-btn" data-bs-dismiss="modal" aria-label="Cerrar" title="Cerrar">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <div class="modal-body">
                <div class="barcode-summary">
                    <div class="barcode-summary-icon">
                        <i class="fas fa-box-open"></i>
                    </div>
                    <div>
                        <h6 id="barcodeArticuloNombre">Artículo</h6>
                        <p id="barcodeArticuloInfo">Consulta los códigos disponibles de este artículo.</p>
                    </div>
                </div>
                <div class="barcode-list" id="barcodeList"></div>
            </div>
            <div class="modal-footer border-0 pt-0">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                    <i class="fas fa-times"></i> Cerrar
                </button>
            </div>
        </div>
    </div>
</div>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/jsbarcode@3.11.6/dist/JsBarcode.all.min.js"></script>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // Elementos DOM
    const searchInput = document.getElementById('searchInput');
    const clearSearch = document.getElementById('clearSearch');
    const filterBtns = document.querySelectorAll('.filter-btn[data-filter]');
    const filterTags = document.querySelectorAll('.filter-tag');
    const toggleFiltersBtn = document.getElementById('toggleFiltersBtn');
    const filtersPanel = document.getElementById('filtersPanel');
    const viewBtns = document.querySelectorAll('.view-btn');
    const productosGrid = document.getElementById('productosGrid');
    const insumosGrid = document.getElementById('insumosGrid');
    const productosCard = document.getElementById('productosCard');
    const insumosCard = document.getElementById('insumosCard');
    const productosEmpty = document.getElementById('productosEmpty');
    const insumosEmpty = document.getElementById('insumosEmpty');
    const productosCount = document.getElementById('productosCount');
    const insumosCount = document.getElementById('insumosCount');
    const activeFiltersBar = document.getElementById('activeFiltersBar');
    const activeFiltersList = document.getElementById('activeFiltersList');
    const clearAllFilters = document.getElementById('clearAllFilters');
    const filtersCount = document.getElementById('filtersCount');
    
    // Obtener todos los items
    const productos = document.querySelectorAll('.producto-item');
    const insumos = document.querySelectorAll('.insumo-item');
    const inventarioItems = document.querySelectorAll('.producto-item, .insumo-item');

    // Modal de códigos de barras
    const barcodeModalEl = document.getElementById('barcodeModal');
    const barcodeModal = barcodeModalEl && window.bootstrap ? new bootstrap.Modal(barcodeModalEl) : null;
    const barcodeModalTitle = document.getElementById('barcodeModalTitle');
    const barcodeArticuloNombre = document.getElementById('barcodeArticuloNombre');
    const barcodeArticuloInfo = document.getElementById('barcodeArticuloInfo');
    const barcodeList = document.getElementById('barcodeList');

    function escapeHtml(texto) {
        return String(texto ?? '')
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    function normalizarCodigos(raw) {
        if (!raw) return [];

        try {
            const codigos = JSON.parse(raw);
            if (!Array.isArray(codigos)) return [];

            return codigos
                .map((item) => {
                    if (typeof item === 'string') {
                        return { codigo: item };
                    }
                    return item || null;
                })
                .filter((item) => item && String(item.codigo || '').trim() !== '')
                .map((item) => ({
                    ...item,
                    codigo: String(item.codigo).trim()
                }));
        } catch (error) {
            console.error('No se pudieron leer los códigos de barras:', error);
            return [];
        }
    }

    function copiarCodigo(codigo, boton) {
        const textoOriginal = boton.innerHTML;

        const marcarCopiado = () => {
            boton.innerHTML = '<i class="fas fa-check"></i> Copiado';
            setTimeout(() => {
                boton.innerHTML = textoOriginal;
            }, 1400);
        };

        if (navigator.clipboard && window.isSecureContext) {
            navigator.clipboard.writeText(codigo).then(marcarCopiado).catch(() => {
                fallbackCopiarCodigo(codigo);
                marcarCopiado();
            });
        } else {
            fallbackCopiarCodigo(codigo);
            marcarCopiado();
        }
    }

    function fallbackCopiarCodigo(codigo) {
        const inputTemporal = document.createElement('textarea');
        inputTemporal.value = codigo;
        inputTemporal.style.position = 'fixed';
        inputTemporal.style.left = '-9999px';
        document.body.appendChild(inputTemporal);
        inputTemporal.focus();
        inputTemporal.select();
        try {
            document.execCommand('copy');
        } catch (error) {
            console.error('No se pudo copiar el código:', error);
        }
        document.body.removeChild(inputTemporal);
    }

    function renderizarBarras() {
        if (!window.JsBarcode) return;

        document.querySelectorAll('#barcodeList .barcode-svg').forEach((svg) => {
            const codigo = svg.dataset.code || '';
            if (!codigo) return;

            try {
                JsBarcode(svg, codigo, {
                    format: 'CODE128',
                    width: 2,
                    height: 72,
                    margin: 10,
                    displayValue: true,
                    text: codigo,
                    font: 'monospace',
                    fontSize: 18,
                    fontOptions: 'bold',
                    textMargin: 8,
                    background: '#ffffff',
                    lineColor: '#111827'
                });
            } catch (error) {
                const contenedor = svg.closest('.barcode-svg-wrap');
                if (contenedor) {
                    contenedor.innerHTML = '<div class="text-muted small py-3">No se pudo dibujar este código, pero el valor está disponible abajo.</div>';
                }
            }
        });
    }

    function abrirModalCodigos(item) {
        if (!barcodeModal || !barcodeList) return;

        const articulo = item.dataset.articulo || item.dataset.nombre || 'Artículo';
        const tipo = item.dataset.tipo === 'insumo' ? 'Insumo' : 'Producto';
        const codigos = normalizarCodigos(item.dataset.codigos || '[]');

        if (barcodeModalTitle) {
            barcodeModalTitle.innerHTML = '<i class="fas fa-barcode me-2"></i> Códigos de barras';
        }
        if (barcodeArticuloNombre) {
            barcodeArticuloNombre.textContent = articulo;
        }
        if (barcodeArticuloInfo) {
            barcodeArticuloInfo.textContent = `${tipo} seleccionado · ${codigos.length} código${codigos.length === 1 ? '' : 's'} disponible${codigos.length === 1 ? '' : 's'}`;
        }

        if (codigos.length === 0) {
            barcodeList.innerHTML = `
                <div class="barcode-empty-state">
                    <i class="fas fa-barcode"></i>
                    <h6>Este artículo no tiene códigos registrados</h6>
                    <p class="mb-0">Cuando registres códigos de barras para este artículo, aparecerán aquí.</p>
                </div>
            `;
        } else {
            barcodeList.innerHTML = codigos.map((itemCodigo, index) => {
                const codigo = String(itemCodigo.codigo || '').trim();
                const codigoSeguro = escapeHtml(codigo);

                return `
                    <div class="barcode-item-card">
                        <div class="barcode-item-head">
                            <span><i class="fas fa-barcode"></i> Código ${index + 1}</span>
                            <button type="button" class="barcode-copy-btn" data-code="${codigoSeguro}">
                                <i class="fas fa-copy"></i> Copiar código
                            </button>
                        </div>
                        <div class="barcode-code-hero">
                            <div class="barcode-code-icon"><i class="fas fa-hashtag"></i></div>
                            <div>
                                <p class="barcode-code-label">Código del artículo</p>
                                <p class="barcode-code-value">${codigoSeguro}</p>
                            </div>
                        </div>
                        <div class="barcode-svg-wrap">
                            <svg class="barcode-svg" data-code="${codigoSeguro}"></svg>
                            <p class="barcode-help-text">Escanea este código o cópialo para usarlo manualmente.</p>
                        </div>                    </div>
                `;
            }).join('');
        }

        barcodeList.querySelectorAll('.barcode-copy-btn').forEach((boton) => {
            boton.addEventListener('click', function(e) {
                e.preventDefault();
                e.stopPropagation();
                copiarCodigo(this.dataset.code || '', this);
            });
        });

        barcodeModal.show();
        setTimeout(renderizarBarras, 160);
    }

    inventarioItems.forEach((item) => {
        const card = item.querySelector('.product-card');
        if (!card) return;

        card.setAttribute('title', 'Clic para ver códigos de barras');
        card.addEventListener('click', function(event) {
            if (event.target.closest('a, button, input, select, textarea')) {
                return;
            }
            abrirModalCodigos(item);
        });
    });
    
    // Estado de filtros
    let filtros = {
        busqueda: '',
        tipo: 'todos',
        categoria: '',
        proveedor: ''
    };
    
    let searchTimeout;
    
    // Función para actualizar contador de filtros
    function actualizarContadorFiltros() {
        let count = 0;
        if (filtros.tipo !== 'todos') count++;
        if (filtros.categoria) count++;
        if (filtros.proveedor) count++;
        
        if (filtersCount) {
            filtersCount.textContent = count;
            filtersCount.style.display = count === 0 ? 'none' : 'inline-block';
        }
    }
    
    // Función para actualizar badges activos
    function actualizarBadgesActivos() {
        if (!activeFiltersList) return;
        
        const activos = [];
        if (filtros.tipo !== 'todos') activos.push({ categoria: 'tipo', valor: filtros.tipo });
        if (filtros.categoria) activos.push({ categoria: 'categoria', valor: filtros.categoria });
        if (filtros.proveedor) activos.push({ categoria: 'proveedor', valor: filtros.proveedor });
        
        if (activos.length > 0) {
            activeFiltersList.innerHTML = '';
            activos.forEach(({ categoria, valor }) => {
                const nombres = { tipo: 'Tipo', categoria: 'Categoría', proveedor: 'Proveedor' };
                let valorMostrar = valor;
                if (categoria === 'tipo') {
                    if (valor === 'productos') valorMostrar = 'Solo productos';
                    else if (valor === 'insumos') valorMostrar = 'Solo insumos';
                    else if (valor === 'stockBajo') valorMostrar = 'Stock bajo (≤5)';
                    else if (valor === 'sinStock') valorMostrar = 'Sin stock';
                }
                const tag = document.createElement('span');
                tag.className = 'active-filter';
                tag.innerHTML = `${nombres[categoria]}: ${valorMostrar} <i class="fas fa-times" data-categoria="${categoria}"></i>`;
                tag.querySelector('i').addEventListener('click', (e) => {
                    e.stopPropagation();
                    if (categoria === 'tipo') filtros.tipo = 'todos';
                    else filtros[categoria] = '';
                    actualizarUIFiltros();
                    aplicarFiltros();
                });
                activeFiltersList.appendChild(tag);
            });
            activeFiltersBar.style.display = 'flex';
        } else {
            activeFiltersBar.style.display = 'none';
        }
    }
    
    // Actualizar UI según filtros
    function actualizarUIFiltros() {
        // Actualizar botones de tipo
        filterBtns.forEach(btn => {
            if (btn.dataset.filter === filtros.tipo) {
                btn.classList.add('active');
            } else {
                btn.classList.remove('active');
            }
        });
        
        // Actualizar tags de categoría
        const categoriaTags = document.querySelectorAll('.filter-tags[data-filter-type="categoria"] .filter-tag');
        categoriaTags.forEach(tag => {
            if (tag.dataset.value === filtros.categoria || (filtros.categoria === '' && tag.dataset.value === '')) {
                tag.classList.add('active');
            } else {
                tag.classList.remove('active');
            }
        });
        
        // Actualizar tags de proveedor
        const proveedorTags = document.querySelectorAll('.filter-tags[data-filter-type="proveedor"] .filter-tag');
        proveedorTags.forEach(tag => {
            if (tag.dataset.value === filtros.proveedor || (filtros.proveedor === '' && tag.dataset.value === '')) {
                tag.classList.add('active');
            } else {
                tag.classList.remove('active');
            }
        });
    }
    
    // Limpiar todos los filtros
    function limpiarTodosFiltros() {
        filtros = { busqueda: '', tipo: 'todos', categoria: '', proveedor: '' };
        if (searchInput) searchInput.value = '';
        if (clearSearch) clearSearch.style.display = 'none';
        actualizarUIFiltros();
        aplicarFiltros();
    }
    
    // Evaluar si un item debe ser visible
    function evaluarItem(item) {
        let visible = true;
        
        // Obtener datos del item
        const nombre = (item.dataset.nombre || '').toLowerCase();
        const categoria = (item.dataset.categoria || '').toLowerCase();
        const proveedor = (item.dataset.proveedor || '').toLowerCase();
        const tipo = item.dataset.tipo || '';
        const stock = parseFloat(item.dataset.stock) || 0;
        
        // Filtro de búsqueda
        if (filtros.busqueda) {
            const termino = filtros.busqueda.toLowerCase();
            const textoBusqueda = `${nombre} ${categoria} ${proveedor}`;
            visible = textoBusqueda.includes(termino);
            if (!visible) return false;
        }
        
        // Filtro de tipo
        if (filtros.tipo !== 'todos') {
            switch(filtros.tipo) {
                case 'productos':
                    visible = tipo === 'producto';
                    break;
                case 'insumos':
                    visible = tipo === 'insumo';
                    break;
                case 'stockBajo':
                    visible = stock > 0 && stock <= 5;
                    break;
                case 'sinStock':
                    visible = stock === 0;
                    break;
            }
            if (!visible) return false;
        }
        
        // Filtro de categoría
        if (filtros.categoria) {
            visible = categoria === filtros.categoria.toLowerCase();
            if (!visible) return false;
        }
        
        // Filtro de proveedor
        if (filtros.proveedor) {
            visible = proveedor === filtros.proveedor.toLowerCase();
            if (!visible) return false;
        }
        
        return visible;
    }
    
    // Aplicar todos los filtros
    function aplicarFiltros() {
        let prodVisibles = 0;
        let insVisibles = 0;
        
        // Filtrar productos
        productos.forEach(producto => {
            const visible = evaluarItem(producto);
            producto.style.display = visible ? '' : 'none';
            if (visible) prodVisibles++;
        });
        
        // Filtrar insumos
        insumos.forEach(insumo => {
            const visible = evaluarItem(insumo);
            insumo.style.display = visible ? '' : 'none';
            if (visible) insVisibles++;
        });
        
        // Actualizar contadores
        if (productosCount) productosCount.textContent = prodVisibles;
        if (insumosCount) insumosCount.textContent = insVisibles;
        
        // Mostrar/ocultar secciones según filtro de tipo
        if (filtros.tipo === 'productos') {
            if (productosCard) productosCard.style.display = 'block';
            if (insumosCard) insumosCard.style.display = 'none';
        } else if (filtros.tipo === 'insumos') {
            if (productosCard) productosCard.style.display = 'none';
            if (insumosCard) insumosCard.style.display = 'block';
        } else {
            if (productosCard) productosCard.style.display = 'block';
            if (insumosCard) insumosCard.style.display = 'block';
        }
        
        // Mostrar mensajes de vacío
        if (productosEmpty) {
            productosEmpty.style.display = (prodVisibles === 0 && filtros.tipo !== 'insumos') ? 'block' : 'none';
        }
        if (insumosEmpty) {
            insumosEmpty.style.display = (insVisibles === 0 && filtros.tipo !== 'productos') ? 'block' : 'none';
        }
        
        actualizarContadorFiltros();
        actualizarBadgesActivos();
    }
    
    // ========== EVENTOS ==========
    
    // Búsqueda con debounce
    if (searchInput) {
        searchInput.addEventListener('input', function() {
            clearTimeout(searchTimeout);
            if (clearSearch) clearSearch.style.display = this.value ? 'block' : 'none';
            searchTimeout = setTimeout(() => {
                filtros.busqueda = this.value;
                aplicarFiltros();
            }, 300);
        });
    }
    
    // Limpiar búsqueda
    if (clearSearch) {
        clearSearch.addEventListener('click', function() {
            if (searchInput) searchInput.value = '';
            filtros.busqueda = '';
            this.style.display = 'none';
            aplicarFiltros();
            if (searchInput) searchInput.focus();
        });
    }
    
    // Botones de filtro por tipo
    filterBtns.forEach(btn => {
        btn.addEventListener('click', function() {
            filtros.tipo = this.dataset.filter;
            actualizarUIFiltros();
            aplicarFiltros();
        });
    });
    
    // Tags de filtro (categoría y proveedor)
    filterTags.forEach(tag => {
        tag.addEventListener('click', function() {
            const group = this.closest('[data-filter-type]');
            if (!group) return;
            const tipoFiltro = group.dataset.filterType;
            const valor = this.dataset.value || '';
            
            if (tipoFiltro === 'categoria') {
                filtros.categoria = valor;
            } else if (tipoFiltro === 'proveedor') {
                filtros.proveedor = valor;
            }
            
            actualizarUIFiltros();
            aplicarFiltros();
        });
    });
    
    // Toggle panel de filtros
    if (toggleFiltersBtn && filtersPanel) {
        toggleFiltersBtn.addEventListener('click', () => {
            filtersPanel.classList.toggle('show');
        });
    }
    
    // Cambiar vista (grid / lista)
    viewBtns.forEach(btn => {
        btn.addEventListener('click', function() {
            viewBtns.forEach(b => b.classList.remove('active'));
            this.classList.add('active');
            if (this.dataset.view === 'grid') {
                if (productosGrid) productosGrid.classList.remove('list-view');
                if (insumosGrid) insumosGrid.classList.remove('list-view');
            } else {
                if (productosGrid) productosGrid.classList.add('list-view');
                if (insumosGrid) insumosGrid.classList.add('list-view');
            }
        });
    });
    
    // Limpiar todos los filtros
    if (clearAllFilters) {
        clearAllFilters.addEventListener('click', limpiarTodosFiltros);
    }
    
    // Inicializar
    actualizarUIFiltros();
    aplicarFiltros();
});
</script>

<?php include('includes/footer.php'); ?>