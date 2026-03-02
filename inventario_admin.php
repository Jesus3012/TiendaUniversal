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
        WHERE tipo_inventario = 'producto' AND activo = 1
        ORDER BY categoria
    ");
    $categorias = [];
    while ($row = $result->fetch_assoc()) {
        $categorias[] = $row['categoria'];
    }
    return $categorias;
}

function obtenerProductosPorCategoria($conn, $categoria) {
    $stmt = $conn->prepare("
        SELECT 
            p.*,
            (p.precio_venta - p.precio_compra) AS utilidad,
            (SELECT COUNT(*) FROM codigos_barras cb WHERE cb.producto_id = p.id AND cb.disponible = 1) AS codigos_disponibles
        FROM productos p
        WHERE p.tipo_inventario = 'producto' 
        AND p.categoria = ?
        AND p.activo = 1
        ORDER BY p.nombre
    ");
    $stmt->bind_param("s", $categoria);
    $stmt->execute();
    return $stmt->get_result();
}

function obtenerInsumos($conn) {
    return $conn->query("
        SELECT 
            p.*,
            (SELECT COUNT(*) FROM codigos_barras cb WHERE cb.producto_id = p.id AND cb.disponible = 1) AS codigos_disponibles
        FROM productos p
        WHERE p.tipo_inventario = 'insumo' 
        AND p.activo = 1
        ORDER BY p.categoria, p.nombre
    ");
}

function obtenerEstadisticas($conn) {
    $stats = [];
    
    $result = $conn->query("SELECT COUNT(*) as total FROM productos WHERE activo = 1 AND tipo_inventario = 'producto'");
    $stats['total_productos'] = $result->fetch_assoc()['total'];
    
    $result = $conn->query("SELECT COUNT(*) as total FROM productos WHERE activo = 1 AND tipo_inventario = 'insumo'");
    $stats['total_insumos'] = $result->fetch_assoc()['total'];
    
    $result = $conn->query("SELECT COUNT(*) as total FROM productos WHERE cantidad <= 5 AND activo = 1");
    $stats['stock_bajo'] = $result->fetch_assoc()['total'];
    
    $result = $conn->query("SELECT SUM(precio_compra * cantidad) as total FROM productos WHERE activo = 1");
    $stats['valor_total'] = $result->fetch_assoc()['total'] ?? 0;
    
    return $stats;
}
?>

<style>
/* Estilos personalizados */
.product-card {
    background: #fff;
    border-radius: 16px;
    overflow: hidden;
    transition: all 0.3s ease;
    box-shadow: 0 4px 15px rgba(0,0,0,0.05);
    border: 1px solid #f1f1f1;
}

.product-card:hover {
    transform: translateY(-6px);
    box-shadow: 0 15px 35px rgba(0,0,0,0.12);
}

.product-img {
    width: 100%;
    height: 200px;
    object-fit: cover;
    transition: transform 0.4s ease;
}

.product-card:hover .product-img {
    transform: scale(1.05);
}

.product-badge {
    position: absolute;
    top: 12px;
    right: 12px;
    z-index: 2;
    border-radius: 30px;
    padding: 5px 12px;
    font-size: 11px;
    font-weight: 600;
}

.card-body {
    padding: 18px;
}

.attribute-tag {
    display: inline-block;
    background: #f4f6f9;
    padding: 4px 10px;
    border-radius: 20px;
    font-size: 11px;
    margin-right: 5px;
    margin-bottom: 4px;
}

.stock-bar {
    height: 8px;
    background: #f1f1f1;
    border-radius: 10px;
    overflow: hidden;
    margin-top: 5px;
}

.stock-fill {
    height: 100%;
    border-radius: 10px;
    transition: width 0.4s ease;
}

.stock-fill.normal {
    background: linear-gradient(90deg, #28a745, #5cd65c);
}

.stock-fill.low {
    background: linear-gradient(90deg, #ffc107, #ffdb4d);
}

.stock-fill.critical {
    background: linear-gradient(90deg, #dc3545, #ff6b6b);
}

.btn-group .btn {
    border-radius: 8px !important;
    transition: all 0.2s ease;
}

.btn-group .btn:hover {
    transform: scale(1.05);
}

.category-pill {
    display: inline-block;
    padding: 8px 20px;
    margin: 0 3px;
    border-radius: 30px;
    background: #f8f9fa;
    color: #495057;
    text-decoration: none;
    font-weight: 500;
    font-size: 0.9rem;
    transition: all 0.2s ease;
    cursor: pointer;
    border: 1px solid #dee2e6;
}

.category-pill:hover {
    background: #e9ecef;
    border-color: #adb5bd;
}

.category-pill.active {
    background: #007bff;
    color: white;
    border-color: #007bff;
}

.attribute-tag {
    display: inline-block;
    padding: 3px 8px;
    margin: 2px;
    background: #f8f9fa;
    border-radius: 15px;
    font-size: 0.75rem;
    border: 1px solid #dee2e6;
    color: #495057;
}

.stock-bar {
    height: 6px;
    background: #e9ecef;
    border-radius: 3px;
    overflow: hidden;
    margin: 5px 0;
}

.stock-fill {
    height: 100%;
    border-radius: 3px;
    transition: width 0.3s ease;
}

.stock-fill.critical { background: #dc3545; }
.stock-fill.low { background: #ffc107; }
.stock-fill.normal { background: #28a745; }
.stock-fill.high { background: #17a2b8; }

.stat-card {
    background: white;
    border-radius: 10px;
    padding: 20px;
    box-shadow: 0 2px 10px rgba(0,0,0,0.05);
    border-left: 4px solid;
    transition: transform 0.2s ease;
    height: 100%;
}

.stat-card:hover {
    transform: translateY(-3px);
    box-shadow: 0 5px 15px rgba(0,0,0,0.1);
}

.stat-card.primary { border-left-color: #007bff; }
.stat-card.success { border-left-color: #28a745; }
.stat-card.warning { border-left-color: #ffc107; }
.stat-card.info { border-left-color: #17a2b8; }

.stat-icon {
    font-size: 2rem;
    color: #adb5bd;
    opacity: 0.5;
}

.empty-state {
    text-align: center;
    padding: 40px;
    background: white;
    border-radius: 10px;
    box-shadow: 0 2px 10px rgba(0,0,0,0.05);
}

.empty-state i {
    font-size: 3rem;
    color: #adb5bd;
    margin-bottom: 15px;
}

.categories-nav {
    background: white;
    border-radius: 10px;
    padding: 15px;
    margin-bottom: 20px;
    box-shadow: 0 2px 10px rgba(0,0,0,0.05);
    overflow-x: auto;
    white-space: nowrap;
}

.section-title {
    font-size: 1.25rem;
    font-weight: 600;
    color: #495057;
    margin: 20px 0 15px;
    padding-bottom: 10px;
    border-bottom: 2px solid #f1f1f1;
    padding-bottom: 10px;
    margin-bottom: 25px;
}

.section-title i {
    color: #007bff;
    margin-right: 10px;
}

.small-box {
    transition: all 0.3s ease-in-out;
    border-radius: 12px;
}

.small-box:hover {
    transform: translateY(-5px);
    box-shadow: 0 10px 25px rgba(0,0,0,0.15);
}

.search-container {
    position: relative;
    width: 100%;
}

.search-icon {
    position: absolute;
    left: 20px;
    top: 50%;
    transform: translateY(-50%);
    color: #007bff;
    font-size: 1.2rem;
    transition: all 0.3s ease;
    z-index: 10;
}

.search-input {
    width: 90%;
    height: 60px;
    padding: 15px 25px 15px 55px;
    border: 2px solid #e9ecef;
    border-radius: 40px;
    font-size: 1rem;
    transition: all 0.3s ease;
    background: white;
    box-shadow: 0 5px 15px rgba(0,0,0,0.02);
}

.search-input:focus {
    outline: none;
    border-color: #007bff;
    box-shadow: 0 10px 25px rgba(0,123,255,0.1);
    padding-left: 60px;
}

.search-input:focus + .search-icon {
    transform: translateY(-50%) scale(1.1);
    color: #0056b3;
}

.search-input::placeholder {
    color: #adb5bd;
    font-weight: 300;
}

/* Animación para el encabezado de categoría */
.categoria-header {
    animation: fadeInDown 0.5s ease;
    padding: 10px 0;
    border-bottom: 2px solid #e9ecef;
}

.categoria-header h4 i {
    transition: transform 0.3s ease;
}

.categoria-header:hover h4 i {
    transform: rotate(10deg);
}

/* Animación para las tarjetas */
.producto-card-animado {
    transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
    animation: fadeInUp 0.5s ease;
    height: 100%;
    background: #fff;
    border-radius: 8px;
    overflow: hidden;
    box-shadow: 0 2px 4px rgba(0,0,0,0.1);
}

.producto-card-animado:hover {
    transform: translateY(-5px);
    box-shadow: 0 8px 16px rgba(0,0,0,0.15);
}

/* Animaciones de entrada */
@keyframes fadeInDown {
    from {
        opacity: 0;
        transform: translateY(-20px);
    }
    to {
        opacity: 1;
        transform: translateY(0);
    }
}

@keyframes fadeInUp {
    from {
        opacity: 0;
        transform: translateY(20px);
    }
    to {
        opacity: 1;
        transform: translateY(0);
    }
}

/* Contenedor de imagen con altura fija */
.product-image-container {
    width: 100%;
    height: 180px;
    background: linear-gradient(135deg, #f8f9fa, #e9ecef);
    display: flex;
    align-items: center;
    justify-content: center;
    overflow: hidden;
    border-bottom: 1px solid #dee2e6;
}

.product-img {
    width: 100%;
    height: 100%;
    object-fit: cover;
    transition: transform 0.3s ease;
}

.producto-card-animado:hover .product-img {
    transform: scale(1.05);
}

/* Placeholder para imágenes */
.no-image-placeholder {
    width: 100%;
    height: 100%;
    background: linear-gradient(135deg, #007bff, #0056b3);
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    color: white;
    position: relative;
    overflow: hidden;
}

.no-image-placeholder::before {
    content: '';
    position: absolute;
    top: -50%;
    left: -50%;
    width: 200%;
    height: 200%;
    background: linear-gradient(45deg, 
        transparent 30%, 
        rgba(255,255,255,0.1) 50%, 
        transparent 70%);
    animation: shine 3s infinite;
}

@keyframes shine {
    0% {
        transform: translateX(-100%) translateY(-100%) rotate(45deg);
    }
    100% {
        transform: translateX(100%) translateY(100%) rotate(45deg);
    }
}

.no-image-placeholder i {
    font-size: 48px;
    margin-bottom: 10px;
    filter: drop-shadow(0 4px 6px rgba(0,0,0,0.2));
    animation: floatIcon 3s ease-in-out infinite;
}

.no-image-placeholder span {
    font-size: 24px;
    font-weight: bold;
    text-transform: uppercase;
    background: rgba(255,255,255,0.2);
    width: 50px;
    height: 50px;
    display: flex;
    align-items: center;
    justify-content: center;
    border-radius: 50%;
    box-shadow: 0 4px 10px rgba(0,0,0,0.2);
}

@keyframes floatIcon {
    0%, 100% {
        transform: translateY(0);
    }
    50% {
        transform: translateY(-5px);
    }
}

/* Badge de stock bajo */
.product-badge {
    position: absolute;
    top: 10px;
    right: 10px;
    z-index: 10;
    padding: 5px 10px;
    border-radius: 20px;
    font-size: 0.75rem;
    animation: pulse 2s infinite;
}

@keyframes pulse {
    0%, 100% {
        transform: scale(1);
    }
    50% {
        transform: scale(1.05);
    }
}

/* Barra de stock */
.stock-bar {
    height: 6px;
    background: #e9ecef;
    border-radius: 10px;
    overflow: hidden;
    margin-top: 5px;
}

.stock-fill {
    height: 100%;
    border-radius: 10px;
    transition: width 0.5s ease;
}

.stock-fill.critical {
    background: linear-gradient(90deg, #dc3545, #bd2130);
}

.stock-fill.low {
    background: linear-gradient(90deg, #ffc107, #e0a800);
}

.stock-fill.normal {
    background: linear-gradient(90deg, #28a745, #218838);
}

/* Atributos */
.attribute-tag {
    display: inline-block;
    background: #f8f9fa;
    border-radius: 20px;
    padding: 3px 10px;
    font-size: 0.75rem;
    color: #495057;
    border: 1px solid #dee2e6;
    margin-right: 5px;
    margin-bottom: 5px;
    transition: all 0.2s;
}

.attribute-tag:hover {
    background: #e9ecef;
    transform: translateY(-1px);
}

/* Estado vacío */
.empty-state {
    text-align: center;
    padding: 40px 20px;
    background: #f8f9fa;
    border-radius: 8px;
    margin: 20px 0;
    animation: fadeIn 0.5s ease;
}

.empty-state i {
    font-size: 48px;
    color: #adb5bd;
    margin-bottom: 15px;
    animation: floatIcon 3s ease-in-out infinite;
}

@keyframes fadeIn {
    from {
        opacity: 0;
    }
    to {
        opacity: 1;
    }
}

.product-img {
    width: 100%;
    height: 200px;
    object-fit: cover;
    border-radius: 8px 8px 0 0;
}

.product-img.no-image {
    height: 200px;
    border-radius: 8px 8px 0 0;
    transition: all 0.3s ease;
}

.product-card:hover .product-img.no-image {
    transform: scale(1.02);
}

.product-img.no-image i {
    transition: all 0.3s ease;
}

.product-card:hover .product-img.no-image i {
    transform: scale(1.1);
}

/* Estilos adicionales para los badges */
.badge-pill {
    padding: 8px 15px;
    font-size: 0.85rem;
}
</style>

<div class="content-wrapper">
    <section class="content pt-4">
        <div class="container-fluid">
            
            <!-- Cabecera -->
            <div class="row mb-4">
                <div class="col-12">
                    <div class="d-flex flex-column flex-md-row justify-content-between align-items-start align-items-md-center">

                        <div>
                            <h2 class="font-weight-bold mb-1">Inventario General</h2>
                            <p class="text-muted">Gestiona todos tus productos y materiales</p>
                        </div>                    
                    </div>
                </div>
            </div>

            <?php $stats = obtenerEstadisticas($conn); ?>

            <!-- Estadísticas -->
            <div class="row mb-4">
                <!-- Productos Totales -->
                <div class="col-lg-3 col-md-6 col-sm-6">
                    <div class="small-box bg-primary shadow" style="cursor:pointer">
                        <div class="inner">
                            <h3><?= intval($stats['total_productos']) ?></h3>
                            <p>Productos Totales</p>
                        </div>
                        <div class="icon">
                            <i class="fas fa-box"></i>
                        </div>
                    </div>
                </div>

                <!-- Insumos -->
                <div class="col-lg-3 col-md-6 col-sm-6">
                    <div class="small-box bg-success shadow" style="cursor:pointer">
                        <div class="inner">
                            <h3><?= intval($stats['total_insumos']) ?></h3>
                            <p>Insumos</p>
                        </div>
                        <div class="icon">
                            <i class="fas fa-cubes"></i>
                        </div>
                    </div>
                </div>

                <!-- Stock Bajo -->
                <div class="col-lg-3 col-md-6 col-sm-6">
                    <div class="small-box bg-warning shadow" style="cursor:pointer">
                        <div class="inner">
                            <h3><?= intval($stats['stock_bajo']) ?></h3>
                            <p>Stock Bajo</p>
                        </div>
                        <div class="icon">
                            <i class="fas fa-exclamation-triangle"></i>
                        </div>
                    </div>
                </div>

                <!-- Valor Inventario -->
                <div class="col-lg-3 col-md-6 col-sm-6">
                    <div class="small-box bg-info shadow" style="cursor:pointer">
                        <div class="inner">
                            <h3>$<?= number_format($stats['valor_total'], 2) ?></h3>
                            <p>Valor Inventario</p>
                        </div>
                        <div class="icon">
                            <i class="fas fa-dollar-sign"></i>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Buscador Animado -->
            <div class="row mb-4">
                <div class="col-md-8 col-lg-6 mx-auto">
                    <div class="search-container">
                        <i class="fas fa-search search-icon"></i>
                        <input type="text" id="buscador" class="search-input" 
                            placeholder="Encuentra lo que necesitas...">
                    </div>
                </div>
            </div>

            <?php $categorias = obtenerCategorias($conn); ?>

            <!-- Navegación de categorías -->
            <div class="categories-nav">
                <span class="category-pill active" data-categoria="todos">Todos los productos</span>
                <?php foreach($categorias as $categoria): ?>
                <span class="category-pill" data-categoria="<?= htmlspecialchars($categoria) ?>">
                    <?= htmlspecialchars($categoria) ?>
                </span>
                <?php endforeach; ?>
            </div>

            <!-- Productos por categoría -->
            <div id="productos-container">
                <?php foreach($categorias as $categoria): 
                    $productos = obtenerProductosPorCategoria($conn, $categoria);
                    $total_productos = $productos->num_rows;
                ?>
                <div class="categoria-section" data-categoria="<?= htmlspecialchars($categoria) ?>" style="display: none;">
                    <!-- Título de categoría estilo original pero con animación -->
                    <div class="section-title d-flex justify-content-between align-items-center mb-3 categoria-header">
                        <h4 class="mb-0">
                            <i class="fas fa-tag text-primary mr-2"></i>
                            <?= htmlspecialchars($categoria) ?>
                        </h4>
                        <span class="badge badge-pill badge-dark">
                            <?= $total_productos ?> productos
                        </span>
                    </div>
                    
                    <?php if($total_productos > 0): ?>
                    <div class="row" id="row-<?= preg_replace('/[^a-zA-Z0-9]/', '', $categoria) ?>">
                        <?php while($row = $productos->fetch_assoc()): 
                            $atributos = json_decode($row['atributos'], true);
                            $porcentaje_stock = min(100, ($row['cantidad'] / 50) * 100);
                            
                            if($row['cantidad'] <= 5) {
                                $stock_class = 'critical';
                                $stock_badge = 'danger';
                                $stock_icon = 'fa-exclamation-circle';
                            } elseif($row['cantidad'] <= 15) {
                                $stock_class = 'low';
                                $stock_badge = 'warning';
                                $stock_icon = 'fa-exclamation-triangle';
                            } else {
                                $stock_class = 'normal';
                                $stock_badge = 'success';
                                $stock_icon = 'fa-check-circle';
                            }
                            
                            // Determinar icono según categoría para cuando no hay imagen
                            $icono_producto = 'fa-box';
                            $categoria_lower = strtolower($row['categoria']);
                            
                            $iconos_categoria = [
                            // Souvenirs clásicos
                            'llaveros' => 'fa-key',
                            'llavero' => 'fa-key',
                            'imanes' => 'fa-magnet',
                            'postales' => 'fa-envelope',
                            'fotografias' => 'fa-camera',
                            'fotos' => 'fa-camera-retro',
                            'albumes' => 'fa-images',
                            'marcos' => 'fa-frame',
                            'cuadros' => 'fa-painting',
                            
                            // Artesanías y decoración
                            'artesanias' => 'fa-hands',
                            'ceramica' => 'fa-mug-saucer',
                            'barro' => 'fa-jug',
                            'textiles' => 'fa-scroll',
                            'decoracion' => 'fa-vase',
                            'figuras' => 'fa-chess-queen',
                            'estatuillas' => 'fa-crown',
                            'adornos' => 'fa-star',
                            
                            // Recuerdos típicos
                            'recuerdos' => 'fa-gift',
                            'recuerdo' => 'fa-gift',
                            'tradicional' => 'fa-flag',
                            'tipico' => 'fa-earth-americas',
                            'region' => 'fa-map-location',
                            'local' => 'fa-store',
                            
                            // Regalos personalizados
                            'personalizado' => 'fa-pen-fancy',
                            'grabados' => 'fa-engraving',
                            'iniciales' => 'fa-font',
                            'nombres' => 'fa-signature',
                            'fechas' => 'fa-calendar',
                            
                            // Bolsas y empaques
                            'bolsas' => 'fa-shopping-bag',
                            'empaques' => 'fa-box-open',
                            'cajas' => 'fa-cube',
                            'papel_regalo' => 'fa-wrapping-paper',
                            
                            // Souvenirs para ocasiones
                            'bodas' => 'fa-ring',
                            'cumpleanos' => 'fa-birthday-cake',
                            'bautizos' => 'fa-dove',
                            'comunion' => 'fa-church',
                            'graduacion' => 'fa-graduation-cap',
                            'aniversario' => 'fa-heart',
                            
                            // Souvenirs por material
                            'madera' => 'fa-tree',
                            'metal' => 'fa-gear',
                            'vidrio' => 'fa-wine-glass',
                            'tela' => 'fa-tshirt',
                            'cuero' => 'fa-hand',
                            'piedra' => 'fa-gem',
                            
                            // Souvenirs comestibles
                            'dulces' => 'fa-candy-cane',
                            'chocolates' => 'fa-cookie-bite',
                            'miel' => 'fa-jar',
                            'licores' => 'fa-wine-bottle',
                            'vinos' => 'fa-wine-glass-alt',
                            
                            // Souvenirs para eventos
                            'eventos' => 'fa-calendar-check',
                            'ferias' => 'fa-tents',
                            'exposiciones' => 'fa-image',
                            'congresos' => 'fa-users',
                            
                            // Souvenirs para turistas
                            'turismo' => 'fa-map',
                            'viajes' => 'fa-plane',
                            'destinos' => 'fa-location-dot',
                            'paises' => 'fa-globe',
                            'ciudades' => 'fa-city',
                            
                            // Souvenirs para mascotas
                            'mascotas' => 'fa-paw',
                            'perros' => 'fa-dog',
                            'gatos' => 'fa-cat',
                            
                            // Souvenirs para niños
                            'infantil' => 'fa-child',
                            'bebes' => 'fa-baby',
                            'juguetes' => 'fa-puzzle-piece',
                            
                            // Souvenirs religiosos
                            'religioso' => 'fa-cross',
                            'virgen' => 'fa-church',
                            'santos' => 'fa-pray',
                            
                            // Souvenirs de naturaleza
                            'naturaleza' => 'fa-leaf',
                            'flores' => 'fa-seedling',
                            'animales' => 'fa-dragon',
                            'mar' => 'fa-fish',
                            'playa' => 'fa-umbrella-beach',
                            'montana' => 'fa-mountain'
                        ];
                            
                            foreach($iconos_categoria as $cat => $icono) {
                                if(strpos($categoria_lower, $cat) !== false) {
                                    $icono_producto = $icono;
                                    break;
                                }
                            }
                        ?>
                        <div class="col-xl-3 col-lg-4 col-md-6 mb-4 producto-card-wrapper" 
                            data-nombre="<?= strtolower(htmlspecialchars($row['nombre'])) ?>"
                            data-categoria="<?= strtolower(htmlspecialchars($categoria)) ?>"
                            data-proveedor="<?= strtolower(htmlspecialchars($row['proveedor'] ?? '')) ?>">
                            
                            <div class="product-card position-relative producto-card-animado">
                                <?php if($row['cantidad'] <= 5): ?>
                                <span class="product-badge badge badge-danger">
                                    <i class="fas <?= $stock_icon ?> mr-1"></i>Stock Bajo
                                </span>
                                <?php endif; ?>
                                
                                <!-- Contenedor de imagen con altura fija -->
                                <div class="product-image-container">
                                    <?php if(!empty($row['imagen']) && file_exists($row['imagen'])): ?>
                                        <img src="<?= htmlspecialchars($row['imagen']) ?>" 
                                            class="product-img" 
                                            alt="<?= htmlspecialchars($row['nombre']) ?>"
                                            loading="lazy"
                                            onerror="this.parentElement.innerHTML = '<div class=\'no-image-placeholder\'><i class=\'fas <?= $icono_producto ?> fa-3x\'></i><span><?= htmlspecialchars(substr($row['nombre'], 0, 2)) ?></span></div>'">
                                    <?php else: ?>
                                        <div class="no-image-placeholder">
                                            <i class="fas <?= $icono_producto ?> fa-3x"></i>
                                            <span><?= htmlspecialchars(substr($row['nombre'], 0, 2)) ?></span>
                                        </div>
                                    <?php endif; ?>
                                </div>
                                
                                <div class="card-body">
                                    <h5 class="card-title font-weight-bold d-inline-block mr-2"><?= htmlspecialchars($row['nombre']) ?></h5>
                                    <span class="badge badge-pill badge-light border">
                                        <i class="fas fa-tag text-primary mr-1"></i><?= htmlspecialchars($row['categoria']) ?>
                                    </span>
                                    
                                    <!-- Atributos -->
                                    <?php if($atributos): ?>
                                    <div class="mb-2">
                                        <?php if(isset($atributos['color'])): ?>
                                            <span class="attribute-tag">
                                                <i class="fas fa-palette mr-1"></i>
                                                <?= is_array($atributos['color']) ? implode(', ', array_slice($atributos['color'], 0, 2)) . (count($atributos['color']) > 2 ? ' +' . (count($atributos['color'])-2) : '') : $atributos['color'] ?>
                                            </span>
                                        <?php endif; ?>
                                        
                                        <?php if(isset($atributos['talla'])): ?>
                                            <span class="attribute-tag">
                                                <i class="fas fa-ruler mr-1"></i>
                                                <?= is_array($atributos['talla']) ? implode(', ', array_slice($atributos['talla'], 0, 2)) . (count($atributos['talla']) > 2 ? ' +' . (count($atributos['talla'])-2) : '') : $atributos['talla'] ?>
                                            </span>
                                        <?php endif; ?>
                                    </div>
                                    <?php endif; ?>
                                    
                                    <!-- Detalles -->
                                    <table class="table table-sm table-borderless mt-2">
                                        <tr>
                                            <td class="text-muted p-0"><small>Proveedor:</small></td>
                                            <td class="p-0 text-right"><small><?= htmlspecialchars($row['proveedor'] ?: 'N/A') ?></small></td>
                                        </tr>
                                        <tr>
                                            <td class="text-muted p-0"><small>Compra:</small></td>
                                            <td class="p-0 text-right"><small>$<?= number_format($row['precio_compra'], 2) ?></small></td>
                                        </tr>
                                        <tr>
                                            <td class="text-muted p-0"><small>Venta:</small></td>
                                            <td class="p-0 text-right"><small class="text-success">$<?= number_format($row['precio_venta'], 2) ?></small></td>
                                        </tr>
                                        <tr>
                                            <td class="text-muted p-0"><small>Utilidad:</small></td>
                                            <td class="p-0 text-right">
                                                <small class="<?= $row['utilidad'] > 0 ? 'text-success font-weight-bold' : 'text-danger font-weight-bold' ?>">
                                                    $<?= number_format($row['utilidad'], 2) ?>
                                                    (<?= $row['precio_compra'] > 0 ? round(($row['utilidad'] / $row['precio_compra']) * 100, 1) : 0 ?>%)
                                                </small>
                                            </td>
                                        </tr>
                                        <?php if($row['tipo_codigo'] == 'multiple'): ?>
                                        <tr>
                                            <td class="text-muted p-0"><small>Códigos:</small></td>
                                            <td class="p-0 text-right"><small><?= $row['codigos_disponibles'] ?? 0 ?> disponibles</small></td>
                                        </tr>
                                        <?php endif; ?>
                                    </table>
                                    
                                    <!-- Stock con barra de progreso animada -->
                                    <div class="mt-2">
                                        <div class="d-flex justify-content-between align-items-center">
                                            <small class="text-muted">Stock:</small>
                                            <span class="badge badge-<?= $stock_badge ?> shadow-sm px-3 py-1">
                                                <i class="fas <?= $stock_icon ?> mr-1"></i>
                                                <?= $row['cantidad'] ?> unidades
                                            </span>
                                        </div>
                                        <div class="stock-bar">
                                            <div class="stock-fill <?= $stock_class ?>" style="width: <?= $porcentaje_stock ?>%"></div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <?php endwhile; ?>
                    </div>
                    <?php else: ?>
                    <div class="empty-state">
                        <i class="fas fa-box-open"></i>
                        <h5>No hay productos en esta categoría</h5>
                        <p class="text-muted">Comienza agregando un nuevo producto</p>
                    </div>
                    <?php endif; ?>
                </div>
                <?php endforeach; ?>
            </div>

            <!-- Sección de Insumos -->
            <div class="section-title mt-5">
                <i class="fas fa-tools"></i>
                Insumos y Materiales
            </div>

            <?php
            // Función para obtener el icono según el tipo de insumo
            function getIconoInsumo($nombre, $categoria, $atributos) {
                $nombre_lower = strtolower($nombre);
                $categoria_lower = strtolower($categoria ?? '');
                $unidad = strtolower($atributos['unidad'] ?? '');
                
                // Mapeo de palabras clave a iconos
                $iconos = [
                    // DTF y impresión
                    'dtf' => 'fa-print',
                    'transfer' => 'fa-print',
                    'polvo' => 'fa-cube',
                    'pelicula' => 'fa-film',
                    'film' => 'fa-film',
                    'tinta' => 'fa-fill-drip',
                    
                    // Telas y textil
                    'tela' => 'fa-tshirt',
                    'poliéster' => 'fa-tshirt',
                    'algodon' => 'fa-tshirt',
                    'lienzo' => 'fa-paint-roller',
                    'franela' => 'fa-tshirt',
                    'dril' => 'fa-tshirt',
                    
                    // Vinilos
                    'vinilo' => 'fa-sticky-note',
                    'calendario' => 'fa-sticky-note',
                    'imprimible' => 'fa-sticky-note',
                    'corte' => 'fa-cut',
                    
                    // Sublimación
                    'sublimacion' => 'fa-hot-tub',
                    'sublimación' => 'fa-hot-tub',
                    'papel' => 'fa-copy',
                    'termico' => 'fa-temperature-high',
                    
                    // Hilos y costura
                    'hilo' => 'fa-thread',
                    'cordon' => 'fa-grip-lines',
                    
                    // Pegamentos y químicos
                    'pegamento' => 'fa-flask',
                    'adhesivo' => 'fa-flask',
                    'silicona' => 'fa-flask',
                    'quimico' => 'fa-flask',
                    'químico' => 'fa-flask',
                    
                    // Herramientas
                    'herramienta' => 'fa-tools',
                    'cutter' => 'fa-cut',
                    'tijera' => 'fa-cut',
                    'regla' => 'fa-ruler',
                    
                    // Empaque
                    'empaque' => 'fa-box',
                    'bolsa' => 'fa-shopping-bag',
                    'caja' => 'fa-box-open',
                    'etiqueta' => 'fa-tag',
                    
                    // Metales y plásticos
                    'metal' => 'fa-cog',
                    'acero' => 'fa-cog',
                    'aluminio' => 'fa-cog',
                    'plastico' => 'fa-cube',
                    'plástico' => 'fa-cube',
                    
                    // Madera
                    'madera' => 'fa-tree',
                    'mdf' => 'fa-tree',
                    'triplex' => 'fa-tree',
                    
                    // Electrónica
                    'electronico' => 'fa-microchip',
                    'electrónico' => 'fa-microchip',
                    'cable' => 'fa-plug',
                    'led' => 'fa-lightbulb',
                    
                    // Acrílicos
                    'acrilico' => 'fa-gem',
                    'acrílico' => 'fa-gem',
                    
                    // Llaveros y accesorios
                    'llavero' => 'fa-key',
                    'accesorio' => 'fa-crown',
                    'broche' => 'fa-circle',
                    
                    // Por metros (DTF, telas, vinilos)
                    'metro' => 'fa-ruler',
                    'metros' => 'fa-ruler',
                    'rollo' => 'fa-scroll',
                ];
                
                // Buscar coincidencias en nombre
                foreach ($iconos as $key => $icono) {
                    if (strpos($nombre_lower, $key) !== false) {
                        return $icono;
                    }
                }
                
                // Buscar coincidencias en categoría
                foreach ($iconos as $key => $icono) {
                    if (strpos($categoria_lower, $key) !== false) {
                        return $icono;
                    }
                }
                
                // Si tiene unidad en metros, sugerir regla
                if (strpos($unidad, 'm') !== false || strpos($unidad, 'metro') !== false) {
                    return 'fa-ruler';
                }
                
                // Icono por defecto
                return 'fa-cubes';
            }

            // Función para obtener el color de fondo del icono
            function getColorInsumo($icono) {
                $colores = [
                    'fa-print' => '#4361ee', // Azul para DTF/impresión
                    'fa-tshirt' => '#f72585', // Rosa para telas
                    'fa-sticky-note' => '#ff9e00', // Naranja para vinilos
                    'fa-hot-tub' => '#b5179e', // Morado para sublimación
                    'fa-thread' => '#4cc9f0', // Celeste para hilos
                    'fa-flask' => '#f8961e', // Naranja para químicos
                    'fa-tools' => '#6c757d', // Gris para herramientas
                    'fa-box' => '#2b9348', // Verde para empaques
                    'fa-cog' => '#3d5a80', // Azul oscuro para metales
                    'fa-tree' => '#588157', // Verde bosque para madera
                    'fa-microchip' => '#7209b7', // Púrpura para electrónica
                    'fa-gem' => '#4895ef', // Azul claro para acrílicos
                    'fa-key' => '#f9c74f', // Amarillo para llaveros
                    'fa-ruler' => '#577590', // Azul grisáceo para metros
                    'fa-scroll' => '#ad9b84', // Beige para rollos
                ];
                
                return $colores[$icono] ?? '#6c757d';
            }

            $insumos = obtenerInsumos($conn);
            $total_insumos = $insumos->num_rows;
            ?>

            <?php if($total_insumos > 0): ?>
            <div class="row">
                <?php while($row = $insumos->fetch_assoc()): 
                    $atributos = json_decode($row['atributos'], true);
                    $porcentaje_stock = min(100, ($row['cantidad'] / 50) * 100);
                    
                    if($row['cantidad'] <= 5) {
                        $stock_class = 'critical';
                        $stock_badge = 'danger';
                    } elseif($row['cantidad'] <= 15) {
                        $stock_class = 'low';
                        $stock_badge = 'warning';
                    } else {
                        $stock_class = 'normal';
                        $stock_badge = 'success';
                    }
                    
                    $icono = getIconoInsumo($row['nombre'], $row['categoria'], $atributos);
                    $color_icono = getColorInsumo($icono);
                ?>
                <div class="col-xl-3 col-lg-4 col-md-6 mb-4">
                    <div class="product-card">
                        <?php if($row['imagen'] && file_exists($row['imagen'])): ?>
                            <img src="<?= $row['imagen'] ?>" class="product-img" alt="<?= htmlspecialchars($row['nombre']) ?>">
                        <?php else: ?>
                            <div class="product-img no-image d-flex align-items-center justify-content-center" style="background: linear-gradient(135deg, <?= $color_icono ?>20, <?= $color_icono ?>40);">
                                <div class="text-center">
                                    <i class="fas <?= $icono ?> fa-4x" style="color: <?= $color_icono ?>"></i>
                                    <?php if(strpos($row['nombre'], 'DTF') !== false || strpos($row['nombre'], 'dtf') !== false): ?>
                                        <small class="d-block mt-2 font-weight-bold" style="color: <?= $color_icono ?>">DTF</small>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endif; ?>
                        
                        <div class="card-body">
                            <h5 class="card-title font-weight-bold mb-1"><?= htmlspecialchars($row['nombre']) ?></h5>
                            
                            <!-- Badge especial para DTF -->
                            <?php if(strpos($row['nombre'], 'DTF') !== false || strpos($row['nombre'], 'dtf') !== false): ?>
                                <span class="badge badge-primary mb-2" style="background: <?= $color_icono ?>">
                                    <i class="fas fa-print mr-1"></i> DTF
                                </span>
                            <?php endif; ?>
                            
                            <?php if($row['categoria']): ?>
                            <small class="text-secondary d-block mb-2">
                                <i class="fas fa-layer-group mr-1"></i><?= htmlspecialchars($row['categoria']) ?>
                            </small>
                            <?php endif; ?>
                            
                            <table class="table table-sm table-borderless mt-2">
                                <tr>
                                    <td class="text-muted p-0"><small>Proveedor:</small></td>
                                    <td class="p-0 text-right"><small><?= $row['proveedor'] ?: 'N/A' ?></small></td>
                                </tr>
                                <tr>
                                    <td class="text-muted p-0"><small>Costo:</small></td>
                                    <td class="p-0 text-right"><small>$<?= number_format($row['precio_compra'], 2) ?></small></td>
                                </tr>
                                <?php if($atributos && isset($atributos['unidad'])): ?>
                                <tr>
                                    <td class="text-muted p-0"><small>Unidad:</small></td>
                                    <td class="p-0 text-right">
                                        <small>
                                            <?php if(strpos($atributos['unidad'], 'm') !== false): ?>
                                                <i class="fas fa-ruler mr-1"></i>
                                            <?php endif; ?>
                                            <?= $atributos['unidad'] ?>
                                        </small>
                                    </td>
                                </tr>
                                <?php endif; ?>
                                
                                <!-- Mostrar ancho si existe (útil para DTF, telas) -->
                                <?php if($atributos && isset($atributos['ancho'])): ?>
                                <tr>
                                    <td class="text-muted p-0"><small>Ancho:</small></td>
                                    <td class="p-0 text-right"><small><?= $atributos['ancho'] ?> m</small></td>
                                </tr>
                                <?php endif; ?>
                            </table>
                            
                            <div class="mt-2">
                                <div class="d-flex justify-content-between align-items-center">
                                    <small class="text-muted">Stock:</small>
                                    <span class="badge badge-<?= $stock_badge ?>">
                                        <?= number_format($row['cantidad'], ($row['cantidad'] == intval($row['cantidad']) ? 0 : 2)) ?> 
                                        <?= $atributos['unidad'] ?? 'unidades' ?>
                                    </span>
                                </div>
                                <div class="stock-bar">
                                    <div class="stock-fill <?= $stock_class ?>" style="width: <?= $porcentaje_stock ?>%"></div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endwhile; ?>
            </div>

            <?php else: ?>
            <div class="empty-state">
                <i class="fas fa-tools fa-4x text-muted mb-3"></i>
                <h5>No hay insumos registrados</h5>
                <p class="text-muted">Agrega materiales para personalización como:</p>
                <div class="row justify-content-center mt-3">
                    <div class="col-auto">
                        <span class="badge badge-pill badge-primary p-2 m-1">
                            <i class="fas fa-print mr-1"></i> DTF
                        </span>
                        <span class="badge badge-pill badge-danger p-2 m-1">
                            <i class="fas fa-tshirt mr-1"></i> Telas
                        </span>
                        <span class="badge badge-pill badge-warning p-2 m-1">
                            <i class="fas fa-sticky-note mr-1"></i> Vinilos
                        </span>
                        <span class="badge badge-pill badge-info p-2 m-1">
                            <i class="fas fa-hot-tub mr-1"></i> Sublimación
                        </span>
                        <span class="badge badge-pill badge-success p-2 m-1">
                            <i class="fas fa-thread mr-1"></i> Hilos
                        </span>
                    </div>
                </div>
            </div>
            <?php endif; ?>

        </div>
    </section>
</div>

<script>
let categoriaActual = 'todos';

document.addEventListener('DOMContentLoaded', function() {
    mostrarCategoria('todos');
    
    document.querySelectorAll('.category-pill').forEach(pill => {
        pill.addEventListener('click', function() {
            document.querySelectorAll('.category-pill').forEach(p => p.classList.remove('active'));
            this.classList.add('active');
            mostrarCategoria(this.dataset.categoria);
        });
    });
    
    document.getElementById('buscador').addEventListener('keyup', function() {
        buscarProductos(this.value.toLowerCase());
    });
});

function mostrarCategoria(categoria) {
    categoriaActual = categoria;

    document.getElementById('buscador').value = '';

    document.querySelectorAll('.categoria-section').forEach(section => {

        const esCategoria = categoria === 'todos' || 
                            section.dataset.categoria === categoria;

        section.style.display = esCategoria ? 'block' : 'none';

        // Mostrar todos los productos dentro de la categoría visible
        if (esCategoria) {
            section.querySelectorAll('.producto-card-wrapper').forEach(card => {
                card.style.display = 'block';
            });
        }
    });

    // Eliminar mensaje de no resultados si existe
    const mensaje = document.getElementById('no-resultados');
    if (mensaje) mensaje.remove();
}

function buscarProductos(filtro) {
    filtro = filtro.toLowerCase();
    let totalVisibles = 0;

    document.querySelectorAll('.categoria-section').forEach(section => {

        let productosVisiblesEnSeccion = 0;

        section.querySelectorAll('.producto-card-wrapper').forEach(card => {

            const nombre = card.dataset.nombre;
            const categoria = card.dataset.categoria;
            const proveedor = card.dataset.proveedor;

            const coincide = nombre.includes(filtro) ||
                             categoria.includes(filtro) ||
                             proveedor.includes(filtro);

            const categoriaCoincide = categoriaActual === 'todos' ||
                                      section.dataset.categoria === categoriaActual;

            if (coincide && categoriaCoincide) {
                card.style.display = 'block';
                productosVisiblesEnSeccion++;
                totalVisibles++;
            } else {
                card.style.display = 'none';
            }

        });

        // 🔥 OCULTAR SECCIÓN SI NO TIENE PRODUCTOS VISIBLES
        if (productosVisiblesEnSeccion > 0 && filtro !== '') {
            section.style.display = 'block';
        } else if (filtro !== '') {
            section.style.display = 'none';
        }

    });

    // ---- MENSAJE GLOBAL DE NO RESULTADOS ----
    let mensajeExistente = document.getElementById('no-resultados');

    if (totalVisibles === 0 && filtro !== '') {

        if (!mensajeExistente) {
            const mensaje = document.createElement('div');
            mensaje.id = 'no-resultados';
            mensaje.className = 'col-12';
            mensaje.innerHTML = `
                <div class="empty-state">
                    <i class="fas fa-search"></i>
                    <h5>No se encontraron productos</h5>
                    <p class="text-muted">Intenta con otros términos de búsqueda</p>
                </div>
            `;

            document.querySelector('#productos-container').appendChild(mensaje);
        }

    } else if (mensajeExistente) {
        mensajeExistente.remove();
    }
}

document.getElementById('buscador').addEventListener('input', function() {
    if (this.value.trim() === '') {
        mostrarCategoria(categoriaActual);
    } else {
        buscarProductos(this.value.trim());
    }
});


function editarProducto(id) {
    window.location.href = 'editar_producto.php?id=' + id;
}

function editarInsumo(id) {
    window.location.href = 'editar_insumo.php?id=' + id;
}

function ajustarStock(id) {
    $('#modalAjustarStock').modal('show');
    document.getElementById('producto_id_ajuste').value = id;
}

function eliminarProducto(id) {
    if (confirm('¿Estás seguro de eliminar este producto? Esta acción no se puede deshacer.')) {
        window.location.href = 'eliminar_producto.php?id=' + id;
    }
}
</script>