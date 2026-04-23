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
?>

<link rel="stylesheet" href="css/inventario.css">

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
                    <a href="#" class="small-box-footer" data-filter-type="productos">Ver productos <i class="fas fa-arrow-circle-right"></i></a>
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
                    <a href="#" class="small-box-footer" data-filter-type="insumos">Ver insumos <i class="fas fa-arrow-circle-right"></i></a>
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
                    <a href="#" class="small-box-footer" data-filter-type="stockBajo">Ver stock bajo <i class="fas fa-arrow-circle-right"></i></a>
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
                         data-nombre="<?= strtolower(htmlspecialchars($producto['nombre'])) ?>"
                         data-categoria="<?= strtolower(htmlspecialchars($producto['categoria'] ?? '')) ?>"
                         data-proveedor="<?= strtolower(htmlspecialchars($producto['proveedor'] ?? '')) ?>"
                         data-stock="<?= $stock ?>"
                         data-tipo="producto">
                        
                        <div class="product-card producto">
                            <div class="product-image">
                                <?php if(!empty($producto['imagen']) && file_exists($producto['imagen'])): ?>
                                    <img src="<?= htmlspecialchars($producto['imagen']) ?>" alt="<?= htmlspecialchars($producto['nombre']) ?>">
                                <?php else: ?>
                                    <div class="no-image">
                                        <i class="fas fa-box"></i>
                                        <span><?= $inicial ?></span>
                                    </div>
                                <?php endif; ?>
                                <div class="product-badge producto">
                                    <i class="fas fa-box"></i> Producto
                                </div>
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
                                    <span class="meta-badge categoria"><i class="fas fa-tag"></i> <?= htmlspecialchars($producto['categoria']) ?></span>
                                    <?php endif; ?>
                                    <?php if($producto['proveedor']): ?>
                                    <span class="meta-badge proveedor"><i class="fas fa-truck"></i> <?= htmlspecialchars($producto['proveedor']) ?></span>
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
                                </div>
                                <?php endif; ?>
                                
                                <div class="prices prices-producto">
                                    <div class="price-item">
                                        <small><i class="fas fa-arrow-down"></i> Compra</small>
                                        <span class="text-danger">$<?= number_format($producto['precio_compra'], 0) ?></span>
                                    </div>
                                    <div class="price-item">
                                        <small><i class="fas fa-arrow-up"></i> Venta</small>
                                        <span class="text-success">$<?= number_format($producto['precio_venta'], 0) ?></span>
                                    </div>
                                    <div class="price-item">
                                        <small><i class="fas fa-chart-line"></i> Utilidad</small>
                                        <span class="text-primary">$<?= number_format($producto['utilidad'], 0) ?></span>
                                    </div>
                                </div>
                                
                                <div class="stock-info">
                                    <div class="d-flex justify-content-between align-items-center">
                                        <span class="stock-status <?= $stockClass ?>">
                                            <i class="fas <?= $stock <= 0 ? 'fa-times-circle' : ($stock <= 5 ? 'fa-exclamation-triangle' : 'fa-check-circle') ?>"></i>
                                            <?= $stockStatus ?>
                                        </span>
                                        <span class="stock-number"><?= number_format($stock) ?> unidades</span>
                                    </div>
                                    <div class="progress-bar-custom">
                                        <div class="progress-fill <?= $stockClass ?>" style="width: <?= $stockPercent ?>%"></div>
                                    </div>
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

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>

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
    const smallBoxLinks = document.querySelectorAll('.small-box-footer');
    
    const productos = document.querySelectorAll('.producto-item');
    const insumos = document.querySelectorAll('.insumo-item');
    
    let filtros = {
        busqueda: '',
        tipo: 'todos',
        categoria: '',
        proveedor: ''
    };
    
    let searchTimeout;
    
    function actualizarContadorFiltros() {
        const count = Object.entries(filtros).filter(([k, v]) => v && k !== 'busqueda').length;
        filtersCount.textContent = count;
        filtersCount.style.display = count === 0 ? 'none' : 'inline-block';
    }
    
    function actualizarBadgesActivos() {
        const activos = Object.entries(filtros).filter(([k, v]) => v && k !== 'busqueda');
        
        if (activos.length > 0) {
            activeFiltersList.innerHTML = '';
            activos.forEach(([categoria, valor]) => {
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
                tag.innerHTML = `${nombres[categoria] || categoria}: ${valorMostrar} <i class="fas fa-times" data-categoria="${categoria}"></i>`;
                tag.querySelector('i').addEventListener('click', () => {
                    filtros[categoria] = categoria === 'tipo' ? 'todos' : '';
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
    
    function actualizarUIFiltros() {
        filterBtns.forEach(btn => {
            if (btn.dataset.filter === filtros.tipo) btn.classList.add('active');
            else btn.classList.remove('active');
        });
        
        filterTags.forEach(tag => tag.classList.remove('active'));
        
        if (filtros.categoria) {
            const tag = document.querySelector(`.filter-tag[data-value="${filtros.categoria}"]`);
            if (tag) tag.classList.add('active');
        } else {
            const todosTag = document.querySelector('.filter-tags[data-filter-type="categoria"] .filter-tag[data-value=""]');
            if (todosTag) todosTag.classList.add('active');
        }
        
        if (filtros.proveedor) {
            const tag = document.querySelector(`.filter-tag[data-value="${filtros.proveedor}"]`);
            if (tag) tag.classList.add('active');
        } else {
            const todosTag = document.querySelector('.filter-tags[data-filter-type="proveedor"] .filter-tag[data-value=""]');
            if (todosTag) todosTag.classList.add('active');
        }
    }
    
    function limpiarTodosFiltros() {
        filtros = { busqueda: '', tipo: 'todos', categoria: '', proveedor: '' };
        searchInput.value = '';
        clearSearch.style.display = 'none';
        actualizarUIFiltros();
        aplicarFiltros();
    }
    
    // Eventos
    searchInput.addEventListener('input', function() {
        clearTimeout(searchTimeout);
        clearSearch.style.display = this.value ? 'block' : 'none';
        searchTimeout = setTimeout(() => {
            filtros.busqueda = this.value;
            aplicarFiltros();
        }, 300);
    });
    
    clearSearch.addEventListener('click', function() {
        searchInput.value = '';
        filtros.busqueda = '';
        this.style.display = 'none';
        aplicarFiltros();
        searchInput.focus();
    });
    
    filterBtns.forEach(btn => {
        btn.addEventListener('click', function() {
            filtros.tipo = this.dataset.filter;
            actualizarUIFiltros();
            aplicarFiltros();
        });
    });
    
    filterTags.forEach(tag => {
        tag.addEventListener('click', function() {
            const group = this.closest('[data-filter-type]');
            if (!group) return;
            const categoria = group.dataset.filterType;
            const valor = this.dataset.value;
            filtros[categoria] = valor;
            actualizarUIFiltros();
            aplicarFiltros();
        });
    });
    
    toggleFiltersBtn?.addEventListener('click', () => filtersPanel.classList.toggle('show'));
    
    viewBtns.forEach(btn => {
        btn.addEventListener('click', function() {
            viewBtns.forEach(b => b.classList.remove('active'));
            this.classList.add('active');
            if (this.dataset.view === 'grid') {
                productosGrid.classList.remove('list-view');
                insumosGrid.classList.remove('list-view');
            } else {
                productosGrid.classList.add('list-view');
                insumosGrid.classList.add('list-view');
            }
        });
    });
    
    clearAllFilters?.addEventListener('click', limpiarTodosFiltros);
    
    smallBoxLinks.forEach(link => {
        link.addEventListener('click', function(e) {
            e.preventDefault();
            const filterType = this.dataset.filterType;
            if (filterType) {
                filtros.tipo = filterType;
                actualizarUIFiltros();
                aplicarFiltros();
                window.scrollTo({ top: 0, behavior: 'smooth' });
            }
        });
    });
    
    function evaluarItem(item) {
        let visible = true;
        const stock = parseFloat(item.dataset.stock) || 0;
        
        if (filtros.busqueda) {
            const terminos = filtros.busqueda.toLowerCase().split(' ');
            const texto = [item.dataset.nombre, item.dataset.categoria, item.dataset.proveedor].filter(Boolean).join(' ').toLowerCase();
            visible = terminos.every(t => texto.includes(t));
        }
        
        if (visible && filtros.tipo !== 'todos') {
            const tipo = item.dataset.tipo;
            switch(filtros.tipo) {
                case 'productos': visible = tipo === 'producto'; break;
                case 'insumos': visible = tipo === 'insumo'; break;
                case 'stockBajo': visible = stock > 0 && stock <= 5; break;
                case 'sinStock': visible = stock === 0; break;
            }
        }
        
        if (visible && filtros.categoria) {
            visible = (item.dataset.categoria || '').toLowerCase() === filtros.categoria.toLowerCase();
        }
        
        if (visible && filtros.proveedor) {
            visible = (item.dataset.proveedor || '').toLowerCase() === filtros.proveedor.toLowerCase();
        }
        
        return visible;
    }
    
    function aplicarFiltros() {
        let prodVisibles = 0, insVisibles = 0;
        
        productos.forEach(p => {
            const visible = evaluarItem(p);
            p.style.display = visible ? '' : 'none';
            if (visible) prodVisibles++;
        });
        
        insumos.forEach(i => {
            const visible = evaluarItem(i);
            i.style.display = visible ? '' : 'none';
            if (visible) insVisibles++;
        });
        
        productosCount.textContent = prodVisibles;
        insumosCount.textContent = insVisibles;
        
        // Mostrar/ocultar secciones y mensajes de vacío
        if (filtros.tipo === 'productos') {
            productosCard.style.display = 'block';
            insumosCard.style.display = 'none';
            productosEmpty.style.display = prodVisibles === 0 ? 'block' : 'none';
        } else if (filtros.tipo === 'insumos') {
            productosCard.style.display = 'none';
            insumosCard.style.display = 'block';
            insumosEmpty.style.display = insVisibles === 0 ? 'block' : 'none';
        } else {
            productosCard.style.display = 'block';
            insumosCard.style.display = 'block';
            productosEmpty.style.display = prodVisibles === 0 ? 'block' : 'none';
            insumosEmpty.style.display = insVisibles === 0 ? 'block' : 'none';
        }
        
        actualizarContadorFiltros();
        actualizarBadgesActivos();
    }
    
    actualizarUIFiltros();
    aplicarFiltros();
});
</script>

<?php include('includes/footer.php'); ?>