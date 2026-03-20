<?php
include 'includes/session.php';
include 'includes/db.php';
include 'includes/header.php';
include 'includes/navbar.php';

if ($rol !== 'administrador') {
    header("Location: inventario_vendedor.php");
    exit;
}

/* ================= FUNCIONES MEJORADAS ================= */
function obtenerCategorias($conn) {
    $result = $conn->query("
        SELECT DISTINCT categoria 
        FROM productos 
        WHERE tipo_inventario = 'producto' AND activo = 1
        ORDER BY categoria
    ");
    $categorias = [];
    while ($row = $result->fetch_assoc()) {
        $categorias[] = $row['categoria'];
    }
    return $categorias;
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
        ORDER BY p.categoria, p.nombre
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
        ORDER BY p.categoria, p.nombre
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
    
    $result = $conn->query("SELECT SUM(precio_venta * cantidad) as total FROM productos WHERE activo = 1");
    $stats['valor_total'] = $result->fetch_assoc()['total'] ?? 0;
    
    return $stats;
}

function obtenerTodosAtributos($conn) {
    $atributos = [
        'colores' => [],
        'tallas' => [],
        'materiales' => [],
        'marcas' => [],
        'unidades' => []
    ];
    
    $result = $conn->query("SELECT atributos FROM productos WHERE activo = 1 AND atributos IS NOT NULL");
    while ($row = $result->fetch_assoc()) {
        $attrs = json_decode($row['atributos'], true);
        if ($attrs) {
            if (isset($attrs['color'])) {
                $colores = is_array($attrs['color']) ? $attrs['color'] : [$attrs['color']];
                foreach ($colores as $color) {
                    if (!in_array($color, $atributos['colores'])) {
                        $atributos['colores'][] = $color;
                    }
                }
            }
            if (isset($attrs['talla'])) {
                $tallas = is_array($attrs['talla']) ? $attrs['talla'] : [$attrs['talla']];
                foreach ($tallas as $talla) {
                    if (!in_array($talla, $atributos['tallas'])) {
                        $atributos['tallas'][] = $talla;
                    }
                }
            }
            if (isset($attrs['material'])) {
                $materiales = is_array($attrs['material']) ? $attrs['material'] : [$attrs['material']];
                foreach ($materiales as $material) {
                    if (!in_array($material, $atributos['materiales'])) {
                        $atributos['materiales'][] = $material;
                    }
                }
            }
            if (isset($attrs['marca'])) {
                if (!in_array($attrs['marca'], $atributos['marcas'])) {
                    $atributos['marcas'][] = $attrs['marca'];
                }
            }
            if (isset($attrs['unidad'])) {
                if (!in_array($attrs['unidad'], $atributos['unidades'])) {
                    $atributos['unidades'][] = $attrs['unidad'];
                }
            }
        }
    }
    
    sort($atributos['colores']);
    sort($atributos['tallas']);
    sort($atributos['materiales']);
    sort($atributos['marcas']);
    sort($atributos['unidades']);
    
    return $atributos;
}
?>

<style>
/* ===== ESTILOS GENERALES ===== */
:root {
    --primary-gradient: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    --success-gradient: linear-gradient(135deg, #84fab0 0%, #8fd3f4 100%);
    --warning-gradient: linear-gradient(135deg, #fad961 0%, #f76b1c 100%);
    --danger-gradient: linear-gradient(135deg, #f093fb 0%, #f5576c 100%);
    --info-gradient: linear-gradient(135deg, #a1c4fd 0%, #c2e9fb 100%);
}

body {
    background: #f4f6f9;
}

.content-wrapper {
    background: #f4f6f9;
}

/* ===== BARRA DE HERRAMIENTAS RESPONSIVE ===== */
.toolbar {
    background: white;
    border-radius: 20px;
    padding: 15px 20px;
    margin-bottom: 30px;
    box-shadow: 0 5px 20px rgba(0,0,0,0.03);
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 15px;
}

@media (max-width: 768px) {
    .toolbar {
        flex-direction: column;
        align-items: stretch;
        padding: 15px;
    }
    
    .toolbar .d-flex {
        flex-wrap: wrap;
        justify-content: center;
    }
}

.search-wrapper {
    flex: 1;
    min-width: 300px;
    position: relative;
}

@media (max-width: 768px) {
    .search-wrapper {
        min-width: 100%;
    }
}

.search-wrapper i {
    position: absolute;
    left: 15px;
    top: 50%;
    transform: translateY(-50%);
    color: #95a5a6;
}

.search-wrapper input {
    width: 100%;
    height: 45px;
    padding: 0 45px;
    border: 2px solid #ecf0f1;
    border-radius: 12px;
    font-size: 0.95rem;
    transition: all 0.3s;
    background: #f8f9fa;
}

.search-wrapper input:focus {
    border-color: #667eea;
    outline: none;
    background: white;
    box-shadow: 0 5px 15px rgba(102, 126, 234, 0.1);
}

.search-wrapper .clear-search {
    position: absolute;
    right: 15px;
    top: 50%;
    transform: translateY(-50%);
    color: #95a5a6;
    cursor: pointer;
    transition: color 0.3s;
}

.search-wrapper .clear-search:hover {
    color: #e74c3c;
}

.filter-badge {
    background: #f8f9fa;
    border-radius: 30px;
    padding: 10px 20px;
    cursor: pointer;
    transition: all 0.3s;
    border: 2px solid transparent;
    white-space: nowrap;
}

@media (max-width: 480px) {
    .filter-badge {
        padding: 8px 12px;
        font-size: 0.85rem;
    }
}

.filter-badge:hover,
.filter-badge.active {
    background: #667eea;
    color: white;
    border-color: #5a67d8;
}

.filter-badge i {
    margin-right: 8px;
}

.view-toggle {
    display: flex;
    gap: 5px;
    background: #f8f9fa;
    padding: 5px;
    border-radius: 12px;
}

.view-toggle button {
    border: none;
    background: transparent;
    padding: 8px 15px;
    border-radius: 10px;
    color: #7f8c8d;
    transition: all 0.3s;
    cursor: pointer;
}

.view-toggle button.active {
    background: white;
    color: #667eea;
    box-shadow: 0 3px 10px rgba(0,0,0,0.05);
}

/* ===== FILTROS AVANZADOS RESPONSIVE ===== */
.filters-panel {
    background: white;
    border-radius: 20px;
    padding: 20px;
    margin-bottom: 30px;
    box-shadow: 0 5px 20px rgba(0,0,0,0.03);
    display: none;
}

.filters-panel.show {
    display: block;
    animation: slideDown 0.3s ease;
}

@keyframes slideDown {
    from {
        opacity: 0;
        transform: translateY(-10px);
    }
    to {
        opacity: 1;
        transform: translateY(0);
    }
}

@media (max-width: 768px) {
    .filters-panel {
        padding: 15px;
    }
    
    .filters-panel .row > div {
        margin-bottom: 15px;
    }
    
    .filters-panel .row > div:last-child {
        margin-bottom: 0;
    }
}

.filter-group {
    margin-bottom: 20px;
}

.filter-group-title {
    font-size: 0.85rem;
    font-weight: 600;
    color: #34495e;
    margin-bottom: 10px;
    display: flex;
    align-items: center;
    gap: 8px;
}

.filter-group-title i {
    color: #667eea;
}

.filter-chips {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
}

.filter-chip {
    padding: 6px 15px;
    background: #f8f9fa;
    border-radius: 30px;
    font-size: 0.85rem;
    color: #2c3e50;
    cursor: pointer;
    transition: all 0.2s;
    border: 1px solid transparent;
}

.filter-chip:hover {
    background: #e9ecef;
    border-color: #dee2e6;
}

.filter-chip.active {
    background: #667eea;
    color: white;
}

.filter-chip .count {
    margin-left: 5px;
    background: rgba(0,0,0,0.1);
    padding: 2px 6px;
    border-radius: 12px;
    font-size: 0.7rem;
}

.filter-chip.active .count {
    background: rgba(255,255,255,0.2);
}

.filter-divider {
    height: 1px;
    background: #ecf0f1;
    margin: 20px 0;
}

/* ===== FILTROS ACTIVOS RESPONSIVE ===== */
.active-filters {
    background: #f8f9fa;
    border-radius: 12px;
    padding: 12px 15px;
    margin-bottom: 20px;
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 10px;
}

@media (max-width: 768px) {
    .active-filters {
        flex-direction: column;
        align-items: flex-start;
    }
    
    .clear-all-filters {
        margin-left: 0 !important;
        align-self: flex-end;
    }
}

.active-filters-label {
    color: #7f8c8d;
    font-size: 0.85rem;
    font-weight: 500;
}

.active-filter-tag {
    background: white;
    border-radius: 30px;
    padding: 5px 12px;
    font-size: 0.85rem;
    display: inline-flex;
    align-items: center;
    gap: 8px;
    box-shadow: 0 2px 5px rgba(0,0,0,0.03);
    border: 1px solid #ecf0f1;
}

.active-filter-tag i {
    cursor: pointer;
    color: #95a5a6;
    transition: color 0.2s;
}

.active-filter-tag i:hover {
    color: #e74c3c;
}

.clear-all-filters {
    color: #e74c3c;
    cursor: pointer;
    font-size: 0.85rem;
    font-weight: 500;
    margin-left: auto;
    transition: color 0.2s;
}

.clear-all-filters:hover {
    color: #c0392b;
}

/* ===== TARJETAS COMPACTAS RESPONSIVE ===== */
.product-card-compact {
    border: 1px solid #e9ecef !important;
    border-radius: 12px !important;
    overflow: hidden;
    transition: all 0.2s ease;
    background: white !important;
    box-shadow: 0 2px 8px rgba(0,0,0,0.03);
}

.product-card-compact:hover {
    transform: translateY(-3px);
    box-shadow: 0 8px 20px rgba(0,0,0,0.06);
    border-color: #dee2e6 !important;
}

/* Badge de tipo de producto */
.product-type-badge-compact {
    position: absolute;
    top: 8px;
    left: 8px;
    z-index: 2;
    border-radius: 20px;
    padding: 4px 10px;
    font-size: 0.65rem;
    display: flex;
    align-items: center;
    gap: 5px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.05);
    border: 1px solid;
    font-weight: 600;
    backdrop-filter: blur(2px);
}

/* Contenedor de imagen */
.product-image-compact {
    position: relative;
    width: 100%;
    height: 130px;
    overflow: hidden;
    background: #f8f9fa;
    border-bottom: 1px solid #f1f1f1;
}

@media (max-width: 768px) {
    .product-image-compact {
        height: 110px;
    }
}

.product-image-compact img {
    width: 100%;
    height: 100%;
    object-fit: cover;
    transition: transform 0.3s ease;
}

.product-card-compact:hover .product-image-compact img {
    transform: scale(1.05);
}

/* Placeholder sin imagen */
.no-image-compact {
    width: 100%;
    height: 100%;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
}

.no-image-compact i {
    font-size: 2.5rem;
    margin-bottom: 5px;
    opacity: 0.7;
}

.no-image-compact span {
    font-size: 0.8rem;
    font-weight: 600;
    width: 30px;
    height: 30px;
    display: flex;
    align-items: center;
    justify-content: center;
    border-radius: 50%;
}

/* Badge de códigos de barras */
.barcode-compact {
    position: absolute;
    bottom: 8px;
    right: 8px;
    border-radius: 20px;
    padding: 4px 10px;
    font-size: 0.6rem;
    display: flex;
    align-items: center;
    gap: 5px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.05);
    border: 1px solid;
    font-weight: 600;
    backdrop-filter: blur(2px);
}

/* Título del producto */
.product-title-compact {
    font-size: 0.85rem;
    font-weight: 600;
    color: #2c3e50;
    margin-bottom: 4px;
    line-height: 1.3;
    display: -webkit-box;
    -webkit-line-clamp: 2;
    -webkit-box-orient: vertical;
    overflow: hidden;
    height: 2.2rem;
}

@media (max-width: 768px) {
    .product-title-compact {
        font-size: 0.8rem;
    }
}

/* Meta información compacta */
.product-meta-compact {
    display: flex;
    flex-wrap: wrap;
    gap: 6px;
    margin-bottom: 6px;
    font-size: 0.65rem;
    color: #6c757d;
}

.product-meta-compact span {
    padding: 2px 8px;
    border-radius: 20px;
    display: inline-flex;
    align-items: center;
    gap: 3px;
    border: 1px solid;
}

/* Atributos compactos */
.attributes-compact {
    display: flex;
    flex-wrap: wrap;
    gap: 4px;
    margin-bottom: 8px;
}

.attr-item {
    border-radius: 16px;
    padding: 3px 8px;
    font-size: 0.6rem;
    display: inline-flex;
    align-items: center;
    gap: 4px;
    border: 1px solid;
    transition: all 0.2s ease;
}

.attr-item:hover {
    transform: translateY(-1px);
    box-shadow: 0 2px 5px rgba(0,0,0,0.05);
}

/* Precios en grid compacto */
.prices-compact {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 4px;
    background: #f8f9fa;
    border-radius: 8px;
    padding: 6px;
    margin-bottom: 8px;
    border: 1px solid #e9ecef;
}

@media (max-width: 768px) {
    .prices-compact {
        grid-template-columns: repeat(2, 1fr);
    }
}

.price-item {
    border-radius: 8px;
    padding: 4px;
    text-align: center;
    border: 1px solid #dee2e6;
    transition: all 0.2s ease;
}

.price-item:hover {
    background: white !important;
    border-color: #adb5bd;
}

.price-item small {
    display: block;
    font-size: 0.55rem;
    color: #6c757d;
    text-transform: uppercase;
    letter-spacing: 0.2px;
}

.price-item span {
    display: block;
    font-size: 0.7rem;
    font-weight: 600;
}

/* Stock compacto */
.stock-compact {
    border-top: 1px dashed #e9ecef;
    padding-top: 6px;
}

.stock-compact small {
    font-size: 0.65rem;
}

.stock-compact .progress {
    background-color: #e9ecef;
    border-radius: 10px;
    overflow: hidden;
}

/* ===== FONDOS SUAVES PARA ICONOS ===== */
.bg-primary-soft {
    background: #e6f0ff !important;
    border-color: #b8d4ff !important;
}

.bg-success-soft {
    background: #e3f5e9 !important;
    border-color: #b8e0c5 !important;
}

.bg-info-soft {
    background: #e3f2fd !important;
    border-color: #b8d9ff !important;
}

.bg-warning-soft {
    background: #fff3e0 !important;
    border-color: #ffd7a5 !important;
}

.bg-danger-soft {
    background: #fee9e9 !important;
    border-color: #fccaca !important;
}

/* Badges de stock */
.badge-danger {
    background: #dc3545 !important;
    color: white !important;
    box-shadow: 0 2px 5px rgba(220, 53, 69, 0.2);
}

.badge-warning {
    background: #ffc107 !important;
    color: #212529 !important;
    box-shadow: 0 2px 5px rgba(255, 193, 7, 0.2);
}

.badge-success {
    background: #28a745 !important;
    color: white !important;
    box-shadow: 0 2px 5px rgba(40, 167, 69, 0.2);
}

/* Empty state */
.empty-state-card {
    text-align: center;
    padding: 40px 20px;
    background: white;
    border-radius: 12px;
    border: 2px dashed #dee2e6;
    margin: 20px 0;
    animation: fadeIn 0.3s ease;
}

.empty-state-card i {
    font-size: 3.5rem;
    color: #adb5bd;
    margin-bottom: 15px;
}

.empty-state-card h5 {
    color: #495057;
    font-weight: 600;
    margin-bottom: 10px;
}

.empty-state-card p {
    color: #6c757d;
    margin-bottom: 0;
    font-size: 0.95rem;
}

@keyframes fadeIn {
    from {
        opacity: 0;
        transform: translateY(10px);
    }
    to {
        opacity: 1;
        transform: translateY(0);
    }
}

/* Para insumos, ajustar grid de precios */
.product-card-compact[data-tipo="insumo"] .prices-compact {
    grid-template-columns: repeat(2, 1fr);
}

/* ===== VISTA DE LISTA RESPONSIVE ===== */
#productosGrid.list-view,
#insumosGrid.list-view {
    display: block;
}

#productosGrid.list-view .row,
#insumosGrid.list-view .row {
    display: block;
}

#productosGrid.list-view .producto-item,
#insumosGrid.list-view .insumo-item {
    width: 100%;
    max-width: 100%;
    flex: 0 0 100%;
    margin-bottom: 1rem;
}

#productosGrid.list-view .product-card-compact,
#insumosGrid.list-view .product-card-compact {
    display: flex;
    flex-direction: row;
    height: auto !important;
}

#productosGrid.list-view .product-image-compact,
#insumosGrid.list-view .product-image-compact {
    width: 150px;
    height: 150px;
    flex-shrink: 0;
}

#productosGrid.list-view .card-body,
#insumosGrid.list-view .card-body {
    flex: 1;
    display: flex;
    flex-direction: column;
}

@media (max-width: 768px) {
    #productosGrid.list-view .product-card-compact,
    #insumosGrid.list-view .product-card-compact {
        flex-direction: column;
    }
    
    #productosGrid.list-view .product-image-compact,
    #insumosGrid.list-view .product-image-compact {
        width: 100%;
        height: 150px;
    }
}

/* Ajustes para móviles pequeños */
@media (max-width: 576px) {
    .producto-item, .insumo-item {
        padding-left: 5px;
        padding-right: 5px;
    }
    
    .prices-compact {
        grid-template-columns: 1fr;
    }
    
    .product-meta-compact {
        justify-content: center;
    }
    
    .attributes-compact {
        justify-content: center;
    }
}
</style>

<div class="content-wrapper">
    <section class="content p-4">
        <!-- Cabecera -->
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h2 class="font-weight-bold mb-0" style="color: #2c3e50;">
                <i class="fas fa-boxes mr-3 text-primary"></i>
                Inventario General
            </h2>
        </div>

        <?php 
        $stats = obtenerEstadisticas($conn);
        $todosProductos = obtenerTodosProductos($conn);
        $todosInsumos = obtenerTodosInsumos($conn);
        $todosAtributos = obtenerTodosAtributos($conn);
        $categorias = obtenerCategorias($conn);
        ?>

        <!-- Estadísticas mejoradas -->
        <div class="row">
            <div class="col-lg-3 col-md-6 col-sm-6">
                <div class="info-box bg-gradient-primary">
                    <span class="info-box-icon"><i class="fas fa-box"></i></span>
                    <div class="info-box-content">
                        <span class="info-box-text">Productos Totales</span>
                        <span class="info-box-number"><?= intval($stats['total_productos']) ?></span>
                        <div class="progress">
                            <div class="progress-bar" style="width: 100%"></div>
                        </div>
                        <span class="progress-description">
                            <i class="fas fa-arrow-up text-sm"></i> En inventario
                        </span>
                    </div>
                </div>
            </div>
            
            <div class="col-lg-3 col-md-6 col-sm-6">
                <div class="info-box bg-gradient-success">
                    <span class="info-box-icon"><i class="fas fa-cubes"></i></span>
                    <div class="info-box-content">
                        <span class="info-box-text">Insumos</span>
                        <span class="info-box-number"><?= intval($stats['total_insumos']) ?></span>
                        <div class="progress">
                            <div class="progress-bar" style="width: 100%"></div>
                        </div>
                        <span class="progress-description">
                            <i class="fas fa-check-circle"></i> Materiales y suministros
                        </span>
                    </div>
                </div>
            </div>
            
            <div class="col-lg-3 col-md-6 col-sm-6">
                <div class="info-box bg-gradient-warning">
                    <span class="info-box-icon"><i class="fas fa-exclamation-triangle"></i></span>
                    <div class="info-box-content">
                        <span class="info-box-text">Stock Bajo</span>
                        <span class="info-box-number"><?= intval($stats['stock_bajo']) ?></span>
                        <div class="progress">
                            <div class="progress-bar" style="width: <?= $stats['total_productos'] > 0 ? min(100, ($stats['stock_bajo'] / $stats['total_productos']) * 100) : 0 ?>%"></div>
                        </div>
                        <span class="progress-description">
                            <i class="fas fa-clock"></i> Requieren atención
                        </span>
                    </div>
                </div>
            </div>
            
            <div class="col-lg-3 col-md-6 col-sm-6">
                <div class="info-box bg-gradient-info">
                    <span class="info-box-icon"><i class="fas fa-dollar-sign"></i></span>
                    <div class="info-box-content">
                        <span class="info-box-text">Valor Inventario</span>
                        <span class="info-box-number">$<?= number_format($stats['valor_total'], 0) ?></span>
                        <div class="progress">
                            <div class="progress-bar" style="width: 100%"></div>
                        </div>
                        <span class="progress-description">
                            <i class="fas fa-chart-line"></i> Costo total
                        </span>
                    </div>
                </div>
            </div>
        </div>

        <!-- Barra de herramientas principal -->
        <div class="toolbar">
            <div class="search-wrapper">
                <i class="fas fa-search"></i>
                <input type="text" id="buscadorInteligente" 
                       placeholder="Buscar por nombre, categoría, proveedor, color, talla, material, marca..."
                       autocomplete="off">
                <i class="fas fa-times clear-search" id="clearSearch" style="display: none;"></i>
            </div>
            
            <div class="d-flex gap-2">
                <div class="filter-badge active" data-filter="todos">
                    <i class="fas fa-th-large"></i>
                    Todos
                </div>
                <div class="filter-badge" data-filter="productos">
                    <i class="fas fa-box"></i>
                    Productos
                </div>
                <div class="filter-badge" data-filter="insumos">
                    <i class="fas fa-cubes"></i>
                    Insumos
                </div>
                <div class="filter-badge" data-filter="stockBajo">
                    <i class="fas fa-exclamation-triangle"></i>
                    Stock Bajo
                </div>
            </div>
            
            <button class="filter-badge" id="toggleFiltersBtn">
                <i class="fas fa-sliders-h"></i>
                Filtros
                <span class="badge badge-light ml-1" id="filtrosActivosCount">0</span>
            </button>

            <div class="view-toggle">
                <button class="active" data-view="grid">
                    <i class="fas fa-th"></i>
                </button>
                <button data-view="list">
                    <i class="fas fa-list"></i>
                </button>
            </div>
        </div>

        <!-- Panel de filtros avanzados -->
        <div class="filters-panel" id="filtersPanel">
            <div class="row">
                <div class="col-md-4">
                    <div class="filter-group">
                        <div class="filter-group-title">
                            <i class="fas fa-tags"></i>
                            Categorías
                        </div>
                        <div class="filter-chips" data-filter-category="categoria">
                            <span class="filter-chip active" data-value="">Todas</span>
                            <?php foreach($categorias as $categoria): ?>
                            <span class="filter-chip" data-value="<?= htmlspecialchars($categoria) ?>">
                                <?= htmlspecialchars($categoria) ?>
                            </span>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
                
                <div class="col-md-4">
                    <div class="filter-group">
                        <div class="filter-group-title">
                            <i class="fas fa-palette"></i>
                            Colores
                        </div>
                        <div class="filter-chips" data-filter-category="color">
                            <span class="filter-chip active" data-value="">Todos</span>
                            <?php foreach($todosAtributos['colores'] as $color): ?>
                            <span class="filter-chip" data-value="<?= htmlspecialchars($color) ?>">
                                <?= htmlspecialchars($color) ?>
                            </span>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
                
                <div class="col-md-4">
                    <div class="filter-group">
                        <div class="filter-group-title">
                            <i class="fas fa-ruler"></i>
                            Tallas
                        </div>
                        <div class="filter-chips" data-filter-category="talla">
                            <span class="filter-chip active" data-value="">Todas</span>
                            <?php foreach($todosAtributos['tallas'] as $talla): ?>
                            <span class="filter-chip" data-value="<?= htmlspecialchars($talla) ?>">
                                <?= htmlspecialchars($talla) ?>
                            </span>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="filter-divider"></div>
            
            <div class="row">
                <div class="col-md-4">
                    <div class="filter-group">
                        <div class="filter-group-title">
                            <i class="fas fa-tshirt"></i>
                            Materiales
                        </div>
                        <div class="filter-chips" data-filter-category="material">
                            <span class="filter-chip active" data-value="">Todos</span>
                            <?php foreach($todosAtributos['materiales'] as $material): ?>
                            <span class="filter-chip" data-value="<?= htmlspecialchars($material) ?>">
                                <?= htmlspecialchars($material) ?>
                            </span>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
                
                <div class="col-md-4">
                    <div class="filter-group">
                        <div class="filter-group-title">
                            <i class="fas fa-trademark"></i>
                            Marcas
                        </div>
                        <div class="filter-chips" data-filter-category="marca">
                            <span class="filter-chip active" data-value="">Todas</span>
                            <?php foreach($todosAtributos['marcas'] as $marca): ?>
                            <span class="filter-chip" data-value="<?= htmlspecialchars($marca) ?>">
                                <?= htmlspecialchars($marca) ?>
                            </span>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
                
                <div class="col-md-4">
                    <div class="filter-group">
                        <div class="filter-group-title">
                            <i class="fas fa-weight-hanging"></i>
                            Unidades
                        </div>
                        <div class="filter-chips" data-filter-category="unidad">
                            <span class="filter-chip active" data-value="">Todas</span>
                            <?php foreach($todosAtributos['unidades'] as $unidad): ?>
                            <span class="filter-chip" data-value="<?= htmlspecialchars($unidad) ?>">
                                <?= htmlspecialchars($unidad) ?>
                            </span>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Filtros activos -->
        <div class="active-filters" id="activeFilters" style="display: none;">
            <span class="active-filters-label">
                <i class="fas fa-filter mr-1"></i>
                Filtros activos:
            </span>
            <div id="activeFiltersList" class="d-flex flex-wrap gap-2"></div>
            <span class="clear-all-filters" id="clearAllFilters">
                <i class="fas fa-times-circle mr-1"></i>
                Limpiar todo
            </span>
        </div>

        <!-- ===== SECCIÓN DE PRODUCTOS ===== -->
        <div class="card card-primary card-outline mb-4" id="productosSection">
            <div class="card-header bg-gradient-primary text-white">
                <h3 class="card-title">
                    <i class="fas fa-box mr-2"></i>
                    Productos
                    <span class="badge badge-light ml-2" id="productosCount"><?= count($todosProductos) ?></span>
                </h3>
            </div>
            <div class="card-body">
                <div class="row" id="productosGrid">
                    <?php foreach($todosProductos as $row): 
                        $atributos = $row['atributos_array'] ?: [];
                        
                        if($row['cantidad'] <= 0) {
                            $stock_badge = 'danger';
                            $stock_icon = 'fa-times-circle';
                            $stock_text = 'Agotado';
                            $stock_color = '#dc3545';
                        } elseif($row['cantidad'] <= 5) {
                            $stock_badge = 'danger';
                            $stock_icon = 'fa-exclamation-circle';
                            $stock_text = 'Stock crítico';
                            $stock_color = '#dc3545';
                        } elseif($row['cantidad'] <= 15) {
                            $stock_badge = 'warning';
                            $stock_icon = 'fa-exclamation-triangle';
                            $stock_text = 'Stock bajo';
                            $stock_color = '#ffc107';
                        } else {
                            $stock_badge = 'success';
                            $stock_icon = 'fa-check-circle';
                            $stock_text = 'Stock normal';
                            $stock_color = '#28a745';
                        }
                    ?>
                    <div class="col-lg-3 col-md-4 col-sm-6 col-12 mb-3 producto-item" 
                         data-nombre="<?= strtolower(htmlspecialchars($row['nombre'])) ?>"
                         data-categoria="<?= strtolower(htmlspecialchars($row['categoria'] ?? '')) ?>"
                         data-proveedor="<?= strtolower(htmlspecialchars($row['proveedor'] ?? '')) ?>"
                         data-color="<?= isset($atributos['color']) ? strtolower(htmlspecialchars(is_array($atributos['color']) ? implode(' ', $atributos['color']) : $atributos['color'])) : '' ?>"
                         data-talla="<?= isset($atributos['talla']) ? strtolower(htmlspecialchars(is_array($atributos['talla']) ? implode(' ', $atributos['talla']) : $atributos['talla'])) : '' ?>"
                         data-material="<?= isset($atributos['material']) ? strtolower(htmlspecialchars(is_array($atributos['material']) ? implode(' ', $atributos['material']) : $atributos['material'])) : '' ?>"
                         data-marca="<?= strtolower(htmlspecialchars($atributos['marca'] ?? '')) ?>"
                         data-unidad="<?= strtolower(htmlspecialchars($atributos['unidad'] ?? '')) ?>"
                         data-tipo="producto"
                         data-stock="<?= $row['cantidad'] ?>">
                        
                        <div class="card product-card-compact h-100">
                            <div class="product-type-badge-compact bg-primary-soft">
                                <i class="fas fa-box text-primary"></i>
                                <span class="text-primary">Producto</span>
                            </div>
                            
                            <div class="product-image-compact">
                                <?php if(!empty($row['imagen']) && file_exists($row['imagen'])): ?>
                                    <img src="<?= htmlspecialchars($row['imagen']) ?>" 
                                         alt="<?= htmlspecialchars($row['nombre']) ?>"
                                         loading="lazy">
                                <?php else: ?>
                                    <div class="no-image-compact bg-primary-soft">
                                        <i class="fas fa-box-open text-primary"></i>
                                        <span class="bg-primary text-white"><?= strtoupper(substr($row['nombre'], 0, 2)) ?></span>
                                    </div>
                                <?php endif; ?>
                                
                                <?php if($row['codigos_disponibles'] > 0): ?>
                                <div class="barcode-compact bg-info-soft">
                                    <i class="fas fa-barcode text-info"></i>
                                    <span class="text-info"><?= $row['codigos_disponibles'] ?></span>
                                </div>
                                <?php endif; ?>
                            </div>
                            
                            <div class="card-body p-2">
                                <h6 class="product-title-compact"><?= htmlspecialchars($row['nombre']) ?></h6>
                                
                                <div class="product-meta-compact">
                                    <?php if($row['categoria']): ?>
                                    <span class="bg-warning-soft">
                                        <i class="fas fa-tag text-warning"></i>
                                        <?= htmlspecialchars($row['categoria']) ?>
                                    </span>
                                    <?php endif; ?>
                                    <?php if($row['proveedor']): ?>
                                    <span class="bg-info-soft">
                                        <i class="fas fa-truck text-info"></i>
                                        <?= htmlspecialchars($row['proveedor']) ?>
                                    </span>
                                    <?php endif; ?>
                                </div>
                                
                                <?php if(!empty($atributos)): ?>
                                <div class="attributes-compact">
                                    <?php if(isset($atributos['color']) && !empty($atributos['color'])): ?>
                                        <span class="attr-item" style="background: #f0f0ff; border-color: #c6c6ff;">
                                            <i class="fas fa-palette" style="color: #6f42c1;"></i>
                                            <span style="color: #495057;"><?= is_array($atributos['color']) ? implode(', ', array_slice($atributos['color'], 0, 1)) : $atributos['color'] ?></span>
                                        </span>
                                    <?php endif; ?>
                                    
                                    <?php if(isset($atributos['talla']) && !empty($atributos['talla'])): ?>
                                        <span class="attr-item" style="background: #e8f5e9; border-color: #a5d6a7;">
                                            <i class="fas fa-ruler" style="color: #2e7d32;"></i>
                                            <span style="color: #495057;"><?= is_array($atributos['talla']) ? implode(', ', array_slice($atributos['talla'], 0, 1)) : $atributos['talla'] ?></span>
                                        </span>
                                    <?php endif; ?>
                                    
                                    <?php if(isset($atributos['marca']) && !empty($atributos['marca'])): ?>
                                        <span class="attr-item" style="background: #fff3e0; border-color: #ffcc80;">
                                            <i class="fas fa-trademark" style="color: #ef6c00;"></i>
                                            <span style="color: #495057;"><?= $atributos['marca'] ?></span>
                                        </span>
                                    <?php endif; ?>
                                    
                                    <?php if(isset($atributos['material']) && !empty($atributos['material'])): ?>
                                        <span class="attr-item" style="background: #e0f2f1; border-color: #80cbc4;">
                                            <i class="fas fa-tshirt" style="color: #00796b;"></i>
                                            <span style="color: #495057;"><?= is_array($atributos['material']) ? implode(', ', array_slice($atributos['material'], 0, 1)) : $atributos['material'] ?></span>
                                        </span>
                                    <?php endif; ?>
                                </div>
                                <?php endif; ?>
                                
                                <div class="prices-compact">
                                    <div class="price-item" style="background: #f8f9fa;">
                                        <small style="color: #6c757d;">
                                            <i class="fas fa-arrow-down text-danger mr-1"></i>Compra
                                        </small>
                                        <span style="color: #dc3545; font-weight: 700;">$<?= number_format($row['precio_compra'], 0) ?></span>
                                    </div>
                                    <div class="price-item" style="background: #f8f9fa;">
                                        <small style="color: #6c757d;">
                                            <i class="fas fa-arrow-up text-success mr-1"></i>Venta
                                        </small>
                                        <span style="color: #28a745; font-weight: 700;">$<?= number_format($row['precio_venta'], 0) ?></span>
                                    </div>
                                    <div class="price-item" style="background: #f8f9fa;">
                                        <small style="color: #6c757d;">
                                            <i class="fas fa-chart-line text-info mr-1"></i>Utilidad
                                        </small>
                                        <span style="color: <?= $row['utilidad'] > 0 ? '#28a745' : '#dc3545' ?>; font-weight: 700;">
                                            $<?= number_format($row['utilidad'], 0) ?>
                                        </span>
                                    </div>
                                </div>
                                
                                <div class="stock-compact">
                                    <div class="d-flex justify-content-between align-items-center mb-1">
                                        <small class="text-muted">
                                            <i class="fas fa-boxes" style="color: #6c757d;"></i> Stock
                                        </small>
                                        <div>
                                            <span class="badge badge-<?= $stock_badge ?> mr-1" style="font-size: 0.6rem;">
                                                <i class="fas <?= $stock_icon ?> mr-1"></i><?= $stock_text ?>
                                            </span>
                                            <small class="font-weight-bold"><?= number_format($row['cantidad'], 0) ?> uni</small>
                                        </div>
                                    </div>
                                    <div class="progress" style="height: 6px; background: #e9ecef;">
                                        <div class="progress-bar bg-<?= $stock_badge ?>" 
                                             style="width: <?= min(100, ($row['cantidad'] / 50) * 100) ?>%; 
                                                    background: <?= $stock_color ?> !important;"></div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <div id="productosEmpty" class="empty-state-card" style="display: none;">
                    <i class="fas fa-box-open"></i>
                    <h5>No hay productos</h5>
                    <p>No se encontraron productos con los filtros seleccionados</p>
                </div>
            </div>
        </div>

        <!-- ===== SECCIÓN DE INSUMOS ===== -->
        <div class="card card-success card-outline mb-4" id="insumosSection">
            <div class="card-header bg-gradient-success text-white">
                <h3 class="card-title">
                    <i class="fas fa-cubes mr-2"></i>
                    Insumos y Materiales
                    <span class="badge badge-light ml-2" id="insumosCount"><?= count($todosInsumos) ?></span>
                </h3>
            </div>
            <div class="card-body">
                <div class="row" id="insumosGrid">
                    <?php foreach($todosInsumos as $row): 
                        $atributos = $row['atributos_array'] ?: [];
                        $unidad = $atributos['unidad'] ?? 'unidad';
                        
                        if($row['cantidad'] <= 0) {
                            $stock_badge = 'danger';
                            $stock_icon = 'fa-times-circle';
                            $stock_text = 'Agotado';
                            $stock_color = '#dc3545';
                        } elseif($row['cantidad'] <= 5) {
                            $stock_badge = 'danger';
                            $stock_icon = 'fa-exclamation-circle';
                            $stock_text = 'Stock crítico';
                            $stock_color = '#dc3545';
                        } elseif($row['cantidad'] <= 15) {
                            $stock_badge = 'warning';
                            $stock_icon = 'fa-exclamation-triangle';
                            $stock_text = 'Stock bajo';
                            $stock_color = '#ffc107';
                        } else {
                            $stock_badge = 'success';
                            $stock_icon = 'fa-check-circle';
                            $stock_text = 'Stock normal';
                            $stock_color = '#28a745';
                        }
                    ?>
                    <div class="col-lg-3 col-md-4 col-sm-6 col-12 mb-3 insumo-item"
                         data-nombre="<?= strtolower(htmlspecialchars($row['nombre'])) ?>"
                         data-categoria="<?= strtolower(htmlspecialchars($row['categoria'] ?? '')) ?>"
                         data-proveedor="<?= strtolower(htmlspecialchars($row['proveedor'] ?? '')) ?>"
                         data-material="<?= isset($atributos['material']) ? strtolower(htmlspecialchars(is_array($atributos['material']) ? implode(' ', $atributos['material']) : $atributos['material'])) : '' ?>"
                         data-marca="<?= strtolower(htmlspecialchars($atributos['marca'] ?? '')) ?>"
                         data-unidad="<?= strtolower(htmlspecialchars($atributos['unidad'] ?? '')) ?>"
                         data-tipo="insumo"
                         data-stock="<?= $row['cantidad'] ?>">
                        
                        <div class="card product-card-compact h-100">
                            <div class="product-type-badge-compact bg-success-soft">
                                <i class="fas fa-cubes text-success"></i>
                                <span class="text-success">Insumo</span>
                            </div>
                            
                            <div class="product-image-compact">
                                <?php if(!empty($row['imagen']) && file_exists($row['imagen'])): ?>
                                    <img src="<?= htmlspecialchars($row['imagen']) ?>" 
                                         alt="<?= htmlspecialchars($row['nombre']) ?>"
                                         loading="lazy">
                                <?php else: ?>
                                    <div class="no-image-compact bg-success-soft">
                                        <i class="fas fa-cubes text-success"></i>
                                        <span class="bg-success text-white"><?= strtoupper(substr($row['nombre'], 0, 2)) ?></span>
                                    </div>
                                <?php endif; ?>
                            </div>
                            
                            <div class="card-body p-2">
                                <h6 class="product-title-compact"><?= htmlspecialchars($row['nombre']) ?></h6>
                                
                                <div class="product-meta-compact">
                                    <?php if($row['categoria']): ?>
                                    <span class="bg-warning-soft">
                                        <i class="fas fa-layer-group text-warning"></i>
                                        <?= htmlspecialchars($row['categoria']) ?>
                                    </span>
                                    <?php endif; ?>
                                    <?php if($row['proveedor']): ?>
                                    <span class="bg-info-soft">
                                        <i class="fas fa-truck text-info"></i>
                                        <?= htmlspecialchars($row['proveedor']) ?>
                                    </span>
                                    <?php endif; ?>
                                </div>
                                
                                <?php if(!empty($atributos)): ?>
                                <div class="attributes-compact">
                                    <?php if(isset($atributos['material']) && !empty($atributos['material'])): ?>
                                        <span class="attr-item" style="background: #e0f2f1; border-color: #80cbc4;">
                                            <i class="fas fa-tshirt" style="color: #00796b;"></i>
                                            <span style="color: #495057;"><?= is_array($atributos['material']) ? implode(', ', array_slice($atributos['material'], 0, 1)) : $atributos['material'] ?></span>
                                        </span>
                                    <?php endif; ?>
                                    
                                    <?php if(isset($atributos['marca']) && !empty($atributos['marca'])): ?>
                                        <span class="attr-item" style="background: #fff3e0; border-color: #ffcc80;">
                                            <i class="fas fa-trademark" style="color: #ef6c00;"></i>
                                            <span style="color: #495057;"><?= $atributos['marca'] ?></span>
                                        </span>
                                    <?php endif; ?>
                                    
                                    <?php if(isset($atributos['unidad'])): ?>
                                        <span class="attr-item" style="background: #e8eaf6; border-color: #9fa8da;">
                                            <i class="fas fa-weight-hanging" style="color: #3f51b5;"></i>
                                            <span style="color: #495057;"><?= $atributos['unidad'] ?></span>
                                        </span>
                                    <?php endif; ?>
                                </div>
                                <?php endif; ?>
                                
                                <div class="prices-compact" style="grid-template-columns: repeat(2, 1fr);">
                                    <div class="price-item" style="background: #f8f9fa;">
                                        <small style="color: #6c757d;">
                                            <i class="fas fa-tag text-danger mr-1"></i>Costo
                                        </small>
                                        <span style="color: #dc3545; font-weight: 700;">$<?= number_format($row['precio_compra'], 2) ?></span>
                                    </div>
                                    <div class="price-item" style="background: #f8f9fa;">
                                        <small style="color: #6c757d;">
                                            <i class="fas fa-weight-hanging text-info mr-1"></i>Unidad
                                        </small>
                                        <span style="color: #17a2b8; font-weight: 700;"><?= $unidad ?></span>
                                    </div>
                                </div>
                                
                                <div class="stock-compact">
                                    <div class="d-flex justify-content-between align-items-center mb-1">
                                        <small class="text-muted">
                                            <i class="fas fa-boxes" style="color: #6c757d;"></i> Stock
                                        </small>
                                        <div>
                                            <span class="badge badge-<?= $stock_badge ?> mr-1" style="font-size: 0.6rem;">
                                                <i class="fas <?= $stock_icon ?> mr-1"></i><?= $stock_text ?>
                                            </span>
                                            <small class="font-weight-bold"><?= number_format($row['cantidad'], 2) ?> <?= $unidad ?></small>
                                        </div>
                                    </div>
                                    <div class="progress" style="height: 6px; background: #e9ecef;">
                                        <div class="progress-bar bg-<?= $stock_badge ?>" 
                                             style="width: <?= min(100, ($row['cantidad'] / 50) * 100) ?>%; 
                                                    background: <?= $stock_color ?> !important;"></div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <div id="insumosEmpty" class="empty-state-card" style="display: none;">
                    <i class="fas fa-cubes"></i>
                    <h5>No hay insumos</h5>
                    <p>No se encontraron insumos con los filtros seleccionados</p>
                </div>
            </div>
        </div>
    </section>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // Elementos del DOM
    const buscador = document.getElementById('buscadorInteligente');
    const clearSearch = document.getElementById('clearSearch');
    const filterBadges = document.querySelectorAll('.filter-badge[data-filter]');
    const filterChips = document.querySelectorAll('.filter-chip');
    const toggleFiltersBtn = document.getElementById('toggleFiltersBtn');
    const filtersPanel = document.getElementById('filtersPanel');
    const viewToggleBtns = document.querySelectorAll('.view-toggle button');
    const productosGrid = document.getElementById('productosGrid');
    const insumosGrid = document.getElementById('insumosGrid');
    const productosSection = document.getElementById('productosSection');
    const insumosSection = document.getElementById('insumosSection');
    const productosEmpty = document.getElementById('productosEmpty');
    const insumosEmpty = document.getElementById('insumosEmpty');
    const productosCount = document.getElementById('productosCount');
    const insumosCount = document.getElementById('insumosCount');
    const activeFilters = document.getElementById('activeFilters');
    const activeFiltersList = document.getElementById('activeFiltersList');
    const clearAllFilters = document.getElementById('clearAllFilters');
    const filtrosActivosCount = document.getElementById('filtrosActivosCount');
    
    const productos = document.querySelectorAll('.producto-item');
    const insumos = document.querySelectorAll('.insumo-item');
    
    let filtrosActivos = {
        busqueda: '',
        tipo: 'todos',
        categoria: '',
        color: '',
        talla: '',
        material: '',
        marca: '',
        unidad: ''
    };
    
    let timeoutBusqueda;
    
    // ===== FUNCIONES PRINCIPALES =====
    
    function actualizarContadorFiltrosActivos() {
        const filtrosAplicados = Object.entries(filtrosActivos)
            .filter(([key, value]) => value && key !== 'busqueda');
        
        const count = filtrosAplicados.length;
        filtrosActivosCount.textContent = count;
        
        filtrosActivosCount.style.display = count === 0 ? 'none' : 'inline-block';
    }
    
    function actualizarBadgesActivos() {
        const filtrosAplicados = Object.entries(filtrosActivos)
            .filter(([key, value]) => value && key !== 'busqueda');
        
        if (filtrosAplicados.length > 0) {
            activeFiltersList.innerHTML = '';
            
            filtrosAplicados.forEach(([categoria, valor]) => {
                const nombreCategoria = {
                    'tipo': 'Tipo',
                    'categoria': 'Categoría',
                    'color': 'Color',
                    'talla': 'Talla',
                    'material': 'Material',
                    'marca': 'Marca',
                    'unidad': 'Unidad'
                }[categoria] || categoria;
                
                let valorMostrar = valor;
                if (categoria === 'tipo') {
                    if (valor === 'productos') valorMostrar = 'Solo productos';
                    else if (valor === 'insumos') valorMostrar = 'Solo insumos';
                    else if (valor === 'stockBajo') valorMostrar = 'Stock bajo';
                }
                
                const tag = document.createElement('span');
                tag.className = 'active-filter-tag';
                tag.innerHTML = `
                    ${nombreCategoria}: ${valorMostrar}
                    <i class="fas fa-times" data-categoria="${categoria}" data-valor="${valor}"></i>
                `;
                
                tag.querySelector('i').addEventListener('click', function() {
                    filtrosActivos[this.dataset.categoria] = this.dataset.categoria === 'tipo' ? 'todos' : '';
                    actualizarUIFiltros();
                    aplicarFiltros();
                });
                
                activeFiltersList.appendChild(tag);
            });
            
            activeFilters.style.display = 'flex';
        } else {
            activeFilters.style.display = 'none';
        }
    }
    
    function actualizarUIFiltros() {
        // Actualizar badges de tipo
        filterBadges.forEach(badge => {
            const filter = badge.dataset.filter;
            if (filter === filtrosActivos.tipo) {
                badge.classList.add('active');
            } else {
                badge.classList.remove('active');
            }
        });
        
        // Actualizar chips de filtros
        filterChips.forEach(chip => {
            chip.classList.remove('active');
        });
        
        // Marcar chips activos
        Object.keys(filtrosActivos).forEach(categoria => {
            if (categoria !== 'busqueda' && categoria !== 'tipo' && filtrosActivos[categoria]) {
                const chipActivo = document.querySelector(`.filter-chip[data-filter-category="${categoria}"][data-value="${filtrosActivos[categoria]}"]`);
                if (chipActivo) chipActivo.classList.add('active');
            }
        });
        
        // Marcar "Todos" en categorías sin filtro
        document.querySelectorAll('.filter-chips').forEach(group => {
            const categoria = group.dataset.filterCategory;
            if (categoria && !filtrosActivos[categoria]) {
                const todosChip = group.querySelector('.filter-chip[data-value=""]');
                if (todosChip) todosChip.classList.add('active');
            }
        });
    }
    
    function limpiarTodosFiltros() {
        filtrosActivos = {
            busqueda: '',
            tipo: 'todos',
            categoria: '',
            color: '',
            talla: '',
            material: '',
            marca: '',
            unidad: ''
        };
        
        buscador.value = '';
        clearSearch.style.display = 'none';
        
        actualizarUIFiltros();
        aplicarFiltros();
    }
    
    // ===== EVENT LISTENERS =====
    
    // Búsqueda
    if (buscador) {
        buscador.addEventListener('input', function() {
            clearTimeout(timeoutBusqueda);
            
            if (this.value) {
                clearSearch.style.display = 'block';
            } else {
                clearSearch.style.display = 'none';
            }
            
            timeoutBusqueda = setTimeout(() => {
                filtrosActivos.busqueda = this.value;
                aplicarFiltros();
            }, 300);
        });
    }
    
    // Limpiar búsqueda
    if (clearSearch) {
        clearSearch.addEventListener('click', function() {
            buscador.value = '';
            filtrosActivos.busqueda = '';
            this.style.display = 'none';
            aplicarFiltros();
            buscador.focus();
        });
    }
    
    // Filtros rápidos
    filterBadges.forEach(badge => {
        badge.addEventListener('click', function() {
            filtrosActivos.tipo = this.dataset.filter;
            actualizarUIFiltros();
            aplicarFiltros();
        });
    });
    
    // Filtros avanzados
    filterChips.forEach(chip => {
        chip.addEventListener('click', function() {
            const group = this.closest('[data-filter-category]');
            if (!group) return;
            
            const categoria = group.dataset.filterCategory;
            const valor = this.dataset.value;
            
            filtrosActivos[categoria] = valor;
            actualizarUIFiltros();
            aplicarFiltros();
        });
    });
    
    // Toggle panel de filtros
    if (toggleFiltersBtn && filtersPanel) {
        toggleFiltersBtn.addEventListener('click', function() {
            filtersPanel.classList.toggle('show');
        });
    }
    
    // ===== CAMBIAR VISTA (GRID/LISTA) =====
    viewToggleBtns.forEach(btn => {
        btn.addEventListener('click', function() {
            viewToggleBtns.forEach(b => b.classList.remove('active'));
            this.classList.add('active');
            
            if (this.dataset.view === 'grid') {
                productosGrid.classList.remove('list-view');
                insumosGrid.classList.remove('list-view');
                // Remover clases de lista de todos los productos
                document.querySelectorAll('.producto-item, .insumo-item').forEach(item => {
                    item.classList.remove('col-12');
                    item.classList.add('col-lg-3', 'col-md-4', 'col-sm-6');
                });
            } else {
                productosGrid.classList.add('list-view');
                insumosGrid.classList.add('list-view');
                // Cambiar a vista de lista
                document.querySelectorAll('.producto-item, .insumo-item').forEach(item => {
                    item.classList.remove('col-lg-3', 'col-md-4', 'col-sm-6');
                    item.classList.add('col-12');
                });
            }
        });
    });

    // Modificar la función evaluarProducto para manejar mejor el stock bajo
    function evaluarProducto(producto) {
        let visible = true;
        const stock = parseFloat(producto.dataset.stock) || 0;
        
        // Filtro de búsqueda
        if (filtrosActivos.busqueda) {
            const terminos = filtrosActivos.busqueda.toLowerCase().split(' ');
            const textoCompleto = [
                producto.dataset.nombre || '',
                producto.dataset.categoria || '',
                producto.dataset.proveedor || '',
                producto.dataset.color || '',
                producto.dataset.talla || '',
                producto.dataset.material || '',
                producto.dataset.marca || '',
                producto.dataset.unidad || ''
            ].join(' ').toLowerCase();
            
            visible = terminos.every(termino => textoCompleto.includes(termino));
        }
        
        // Filtro por tipo
        if (visible && filtrosActivos.tipo !== 'todos') {
            const tipo = producto.dataset.tipo;
            
            switch(filtrosActivos.tipo) {
                case 'productos':
                    visible = tipo === 'producto';
                    break;
                case 'insumos':
                    visible = tipo === 'insumo';
                    break;
                case 'stockBajo':
                    visible = stock <= 5; // Stock bajo para ambos tipos
                    break;
            }
        }
        
        // Filtros de atributos
        if (visible && filtrosActivos.categoria) {
            visible = (producto.dataset.categoria || '').toLowerCase().includes(filtrosActivos.categoria.toLowerCase());
        }
        
        if (visible && filtrosActivos.color) {
            visible = (producto.dataset.color || '').toLowerCase().includes(filtrosActivos.color.toLowerCase());
        }
        
        if (visible && filtrosActivos.talla) {
            visible = (producto.dataset.talla || '').toLowerCase().includes(filtrosActivos.talla.toLowerCase());
        }
        
        if (visible && filtrosActivos.material) {
            visible = (producto.dataset.material || '').toLowerCase().includes(filtrosActivos.material.toLowerCase());
        }
        
        if (visible && filtrosActivos.marca) {
            visible = (producto.dataset.marca || '').toLowerCase().includes(filtrosActivos.marca.toLowerCase());
        }
        
        if (visible && filtrosActivos.unidad) {
            visible = (producto.dataset.unidad || '').toLowerCase().includes(filtrosActivos.unidad.toLowerCase());
        }
        
        return visible;
    }

    // Modificar la función aplicarFiltros para manejar mejor los mensajes de vacío
    function aplicarFiltros() {
        let productosVisibles = 0;
        let insumosVisibles = 0;
        let productosConStockBajo = 0;
        let insumosConStockBajo = 0;
        
        // Contar totales con stock bajo
        productos.forEach(p => {
            if (parseFloat(p.dataset.stock) <= 5) productosConStockBajo++;
        });
        
        insumos.forEach(i => {
            if (parseFloat(i.dataset.stock) <= 5) insumosConStockBajo++;
        });
        
        // Filtrar productos
        productos.forEach(producto => {
            const visible = evaluarProducto(producto);
            producto.style.display = visible ? 'block' : 'none';
            if (visible) productosVisibles++;
        });
        
        // Filtrar insumos
        insumos.forEach(insumo => {
            const visible = evaluarProducto(insumo);
            insumo.style.display = visible ? 'block' : 'none';
            if (visible) insumosVisibles++;
        });
        
        // Actualizar contadores
        productosCount.textContent = productosVisibles;
        insumosCount.textContent = insumosVisibles;
        
        // Mostrar/ocultar secciones según filtro de tipo y disponibilidad
        if (filtrosActivos.tipo === 'productos') {
            productosSection.style.display = productosVisibles > 0 ? 'block' : 'none';
            insumosSection.style.display = 'none';
            
            if (productosVisibles === 0) {
                if (productosConStockBajo === 0) {
                    productosEmpty.innerHTML = `
                        <i class="fas fa-box-open"></i>
                        <h5>No hay productos con stock bajo</h5>
                        <p>Todos los productos tienen stock suficiente</p>
                    `;
                }
                productosEmpty.style.display = 'block';
            } else {
                productosEmpty.style.display = 'none';
            }
            
        } else if (filtrosActivos.tipo === 'insumos') {
            productosSection.style.display = 'none';
            insumosSection.style.display = insumosVisibles > 0 ? 'block' : 'none';
            
            if (insumosVisibles === 0) {
                if (insumosConStockBajo === 0) {
                    insumosEmpty.innerHTML = `
                        <i class="fas fa-cubes"></i>
                        <h5>No hay insumos con stock bajo</h5>
                        <p>Todos los insumos tienen stock suficiente</p>
                    `;
                }
                insumosEmpty.style.display = 'block';
            } else {
                insumosEmpty.style.display = 'none';
            }
            
        } else if (filtrosActivos.tipo === 'stockBajo') {
            // Mostrar solo las secciones que tengan elementos con stock bajo
            productosSection.style.display = productosVisibles > 0 ? 'block' : 'none';
            insumosSection.style.display = insumosVisibles > 0 ? 'block' : 'none';
            
            // Productos: verificar si hay productos con stock bajo
            if (productosVisibles === 0) {
                if (productosConStockBajo === 0) {
                    productosEmpty.innerHTML = `
                        <i class="fas fa-box-open"></i>
                        <h5>No hay productos con stock bajo</h5>
                        <p>Todos los productos tienen stock suficiente</p>
                    `;
                } else {
                    productosEmpty.innerHTML = `
                        <i class="fas fa-box-open"></i>
                        <h5>No se encontraron productos</h5>
                        <p>Ningún producto coincide con los filtros adicionales</p>
                    `;
                }
                productosEmpty.style.display = 'block';
            } else {
                productosEmpty.style.display = 'none';
            }
            
            // Insumos: verificar si hay insumos con stock bajo
            if (insumosVisibles === 0) {
                if (insumosConStockBajo === 0) {
                    insumosEmpty.innerHTML = `
                        <i class="fas fa-cubes"></i>
                        <h5>No hay insumos con stock bajo</h5>
                        <p>Todos los insumos tienen stock suficiente</p>
                    `;
                } else {
                    insumosEmpty.innerHTML = `
                        <i class="fas fa-cubes"></i>
                        <h5>No se encontraron insumos</h5>
                        <p>Ningún insumo coincide con los filtros adicionales</p>
                    `;
                }
                insumosEmpty.style.display = 'block';
            } else {
                insumosEmpty.style.display = 'none';
            }
            
        } else {
            // Modo "todos" normal - mostrar secciones solo si tienen elementos visibles
            productosSection.style.display = productosVisibles > 0 ? 'block' : 'none';
            insumosSection.style.display = insumosVisibles > 0 ? 'block' : 'none';
            
            // Mensajes de vacío normales
            if (productosVisibles === 0) {
                productosEmpty.innerHTML = `
                    <i class="fas fa-box-open"></i>
                    <h5>No hay productos</h5>
                    <p>No se encontraron productos con los filtros seleccionados</p>
                `;
                productosEmpty.style.display = 'block';
            } else {
                productosEmpty.style.display = 'none';
            }
            
            if (insumosVisibles === 0) {
                insumosEmpty.innerHTML = `
                    <i class="fas fa-cubes"></i>
                    <h5>No hay insumos</h5>
                    <p>No se encontraron insumos con los filtros seleccionados</p>
                `;
                insumosEmpty.style.display = 'block';
            } else {
                insumosEmpty.style.display = 'none';
            }
        }
        
        // Si no hay ninguna sección visible, mostrar un mensaje general
        const noHayNada = productosVisibles === 0 && insumosVisibles === 0;
        if (noHayNada && filtrosActivos.tipo === 'todos') {
            // Podrías mostrar un mensaje general aquí si lo deseas
        }
        
        // Actualizar contadores
        actualizarContadorFiltrosActivos();
        actualizarBadgesActivos();
    }
        
    // Limpiar todos los filtros
    if (clearAllFilters) {
        clearAllFilters.addEventListener('click', limpiarTodosFiltros);
    }
    
    // Inicializar
    actualizarUIFiltros();
    aplicarFiltros();
});
</script>