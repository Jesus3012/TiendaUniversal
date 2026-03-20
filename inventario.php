<?php
include 'includes/session.php';
include 'includes/db.php';
include 'includes/header.php';
include 'includes/navbar.php';

/* ================== CONSTANTES Y CONFIGURACIÓN ================== */
define('STOCK_BAJO_LIMITE', 5);
define('IMAGEN_POR_DEFECTO', 'uploads/no-image.png');

// Verificar si la imagen por defecto existe
$imagen_default_path = $_SERVER['DOCUMENT_ROOT'] . '/uploads/no-image.png';
if (!file_exists($imagen_default_path)) {
    define('IMAGEN_POR_DEFECTO_URL', 'https://via.placeholder.com/300x200?text=Sin+Imagen');
} else {
    define('IMAGEN_POR_DEFECTO_URL', '/uploads/no-image.png');
}

// Función para determinar icono según categoría (solo para productos sin imagen)
function getProductIcon($nombre, $categoria = null) {
    $nombre_lower = strtolower($nombre);
    $categoria_lower = $categoria ? strtolower($categoria) : '';
    
    $texto_busqueda = $categoria_lower ?: $nombre_lower;
    
    // Electrónica
    if (preg_match('/(electronica|telefono|celular|smartphone|tablet|computadora|laptop|pc|monitor|teclado|mouse|audifonos|pantalla|impresora|cargador|cable|adaptador|bateria|pila|usb|memoria|disco|tarjeta)/', $texto_busqueda)) {
        return 'fas fa-microchip';
    }
    // Ropa
    if (preg_match('/(ropa|camisa|pantalon|vestido|chaqueta|sueter|short|falda|jean|blusa|camiseta)/', $texto_busqueda)) {
        return 'fas fa-tshirt';
    }
    // Calzado
    if (preg_match('/(calzado|zapato|tenis|sandalia|botas|zapatilla|chancla)/', $texto_busqueda)) {
        return 'fas fa-shoe-prints';
    }
    // Alimentos
    if (preg_match('/(alimento|comida|bebida|refresco|agua|snack|galleta|pan|leche|jugo|gaseosa|cerveza|vino)/', $texto_busqueda)) {
        return 'fas fa-utensils';
    }
    // Hogar
    if (preg_match('/(hogar|mueble|silla|mesa|escritorio|estante|cocina|baño|sofa|cama|ropero|armario)/', $texto_busqueda)) {
        return 'fas fa-couch';
    }
    // Papelería
    if (preg_match('/(papeleria|oficina|papel|lapiz|pluma|cuaderno|libreta|escritura|marcador|borrador|regla|folder|carpeta)/', $texto_busqueda)) {
        return 'fas fa-pen';
    }
    // Herramientas
    if (preg_match('/(herramienta|martillo|destornillador|pinza|taladro|sierra|llave|alicate|nivel|cincel)/', $texto_busqueda)) {
        return 'fas fa-tools';
    }
    // Belleza
    if (preg_match('/(belleza|shampoo|jabon|crema|maquillaje|perfume|cosmetico|desodorante|pasta|cepillo|peine)/', $texto_busqueda)) {
        return 'fas fa-spa';
    }
    // Deportes
    if (preg_match('/(deporte|pelota|bicicleta|pesa|gimnasio|balon|raqueta|casco|guante)/', $texto_busqueda)) {
        return 'fas fa-futbol';
    }
    // Libros
    if (preg_match('/(libro|revista|lectura|texto|manual|guia|diccionario|enciclopedia)/', $texto_busqueda)) {
        return 'fas fa-book';
    }
    // Juguetes
    if (preg_match('/(juguete|muñeca|carro|peluche|lego|rompecabezas|bloques|consola|videojuego)/', $texto_busqueda)) {
        return 'fas fa-gamepad';
    }
    // Limpieza
    if (preg_match('/(limpieza|limpia|detergente|cloro|escoba|trapeador|recogedor|bolsa)/', $texto_busqueda)) {
        return 'fas fa-pump-soap';
    }
    
    return 'fas fa-box';
}

// Función para obtener color del icono
function getIconColor($iconClass) {
    $colors = [
        'fa-microchip' => 'primary',
        'fa-tshirt' => 'info',
        'fa-shoe-prints' => 'warning',
        'fa-utensils' => 'success',
        'fa-couch' => 'secondary',
        'fa-pen' => 'indigo',
        'fa-tools' => 'danger',
        'fa-spa' => 'pink',
        'fa-futbol' => 'teal',
        'fa-book' => 'purple',
        'fa-gamepad' => 'orange',
        'fa-pump-soap' => 'cyan',
        'fa-box' => 'gray'
    ];
    
    foreach ($colors as $icon => $color) {
        if (strpos($iconClass, $icon) !== false) {
            return $color;
        }
    }
    return 'gray';
}

/* ================== CONSULTA ================== */
$query = "
    SELECT 
        p.*,
        GROUP_CONCAT(cb.codigo SEPARATOR ', ') AS codigos_agrupados
    FROM productos p
    LEFT JOIN codigos_barras cb ON cb.producto_id = p.id
    WHERE p.tipo_inventario = 'producto' OR p.tipo_inventario IS NULL OR p.tipo_inventario = ''
    GROUP BY p.id
    ORDER BY 
        CASE 
            WHEN p.imagen IS NOT NULL AND p.imagen != '' THEN 0 
            ELSE 1 
        END,
        p.nombre ASC
    LIMIT 50
";

$result = $conn->query($query);
if (!$result) {
    error_log("Error en consulta de inventario: " . $conn->error);
    $productos = [];
    $error_db = true;
} else {
    $productos = [];
    while ($row = $result->fetch_assoc()) {
        $productos[] = $row;
    }
    $error_db = false;
}

// Contar total de productos
$count_query = "SELECT COUNT(*) as total FROM productos WHERE tipo_inventario = 'producto' OR tipo_inventario IS NULL OR tipo_inventario = ''";
$count_result = $conn->query($count_query);
$total_productos = $count_result ? $count_result->fetch_assoc()['total'] : count($productos);
?>

<style>
/* ================== FONDO GENERAL (IGUAL AL ORIGINAL) ================== */
.content-wrapper {
    background: linear-gradient(180deg, #FFF4E6, #FFFFFF);
    min-height: 100vh;
    padding: 25px;
    border-radius: 18px 0 0 18px;
}

/* ================== HEADER (IGUAL AL ORIGINAL) ================== */
.page-title {
    font-size: 1.9rem;
    font-weight: 700;
    color: #2c2c2c;
}

/* ================== BUSCADOR (IGUAL AL ORIGINAL) ================== */
.buscador-box {
    max-width: 360px;
}

#buscador {
    border-radius: 14px 0 0 14px !important;
    border-right: none;
    border: 1px solid #ced4da;
    padding: 10px 15px;
}

.input-group-text {
    border-radius: 0 14px 14px 0 !important;
    background: #111;
    border: none;
    color: white;
}

/* ================== CARD PRODUCTO MEJORADA ================== */
.product-card-pro {
    background: #fff;
    border-radius: 18px;
    overflow: hidden;
    transition: all .35s ease;
    box-shadow: 0 10px 26px rgba(0,0,0,0.08);
    height: 100%;
    border: 1px solid rgba(0,0,0,0.03);
    display: flex;
    flex-direction: column;
}

.product-card-pro:hover {
    transform: translateY(-6px);
    box-shadow: 0 18px 45px rgba(0,0,0,0.12);
}

/* ================== IMAGEN ================== */
.product-image-wrapper {
    height: 190px;
    overflow: hidden;
    position: relative;
    background: #f8f9fa;
    border-bottom: 1px solid #f0f0f0;
}

.product-image {
    width: 100%;
    height: 100%;
    object-fit: cover;
    transition: transform 0.5s ease;
}

.product-card-pro:hover .product-image {
    transform: scale(1.08);
}

/* ================== ICONO (PARA PRODUCTOS SIN IMAGEN) ================== */
.product-icon-wrapper {
    height: 190px;
    display: flex;
    align-items: center;
    justify-content: center;
    background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
    border-bottom: 1px solid #f0f0f0;
}

.product-icon {
    font-size: 4.5rem;
    filter: drop-shadow(2px 4px 6px rgba(0,0,0,0.1));
}

/* Colores de iconos */
.icon-primary { color: #007bff; }
.icon-info { color: #17a2b8; }
.icon-warning { color: #ffc107; }
.icon-success { color: #28a745; }
.icon-secondary { color: #6c757d; }
.icon-indigo { color: #6610f2; }
.icon-danger { color: #dc3545; }
.icon-pink { color: #e83e8c; }
.icon-teal { color: #20c997; }
.icon-purple { color: #6f42c1; }
.icon-orange { color: #fd7e14; }
.icon-cyan { color: #17a2b8; }
.icon-gray { color: #6c757d; }

/* ================== BADGE STOCK ================== */
.badge-stock {
    position: absolute;
    top: 14px;
    right: 14px;
    padding: 6px 14px;
    font-size: .75rem;
    border-radius: 20px;
    box-shadow: 0 4px 10px rgba(0,0,0,.15);
    z-index: 10;
    font-weight: 500;
}

.badge-success { background: #28a745; color: white; }
.badge-warning { background: #ffc107; color: #212529; }
.badge-danger { background: #dc3545; color: white; }

/* ================== CONTENIDO DE LA CARD ================== */
.card-content {
    padding: 18px;
    flex: 1;
    display: flex;
    flex-direction: column;
}

.product-title {
    font-size: 1.15rem;
    font-weight: 600;
    color: #222;
    margin-bottom: 8px;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

.product-categoria {
    font-size: 0.85rem;
    color: #6c757d;
    margin-bottom: 15px;
    display: flex;
    align-items: center;
}

.product-categoria i {
    margin-right: 6px;
    font-size: 0.8rem;
    color: #adb5bd;
}

/* ================== MÉTRICAS MEJORADAS ================== */
.product-metrics {
    display: flex;
    flex-wrap: wrap;
    gap: 12px;
    margin: 12px 0;
    background: #f8f9fa;
    border-radius: 14px;
    padding: 12px;
}

.metric-item {
    flex: 1 0 calc(50% - 6px);
    display: flex;
    align-items: center;
    gap: 8px;
}

.metric-icon {
    width: 32px;
    height: 32px;
    background: white;
    border-radius: 10px;
    display: flex;
    align-items: center;
    justify-content: center;
    color: #495057;
    font-size: 0.9rem;
    box-shadow: 0 2px 4px rgba(0,0,0,0.02);
}

.metric-content {
    flex: 1;
}

.metric-label {
    font-size: 0.7rem;
    color: #6c757d;
    text-transform: uppercase;
    letter-spacing: 0.3px;
}

.metric-value {
    font-size: 0.95rem;
    font-weight: 600;
    color: #212529;
}

.metric-value.text-success { color: #28a745; }
.metric-value.text-danger { color: #dc3545; }
.metric-value.text-primary { color: #007bff; }
.metric-value.text-warning { color: #ffc107; }

/* ================== CÓDIGOS ================== */
.codigo-container {
    margin-top: auto;
    padding-top: 15px;
    border-top: 1px dashed #dee2e6;
    display: flex;
    align-items: center;
    justify-content: space-between;
}

.codigo-label {
    font-size: 0.8rem;
    color: #6c757d;
}

.codigo-label i {
    margin-right: 4px;
    color: #adb5bd;
}

.codigo-toggle {
    cursor: pointer;
    color: #007bff;
    font-weight: 500;
    font-size: 0.9rem;
    padding: 4px 10px;
    border-radius: 20px;
    background: #f8f9fa;
    transition: all 0.2s;
}

.codigo-toggle:hover {
    background: #007bff;
    color: white;
}

/* ================== SKELETON LOADING ================== */
.skeleton-card {
    background: #fff;
    border-radius: 18px;
    padding: 0;
    overflow: hidden;
    box-shadow: 0 10px 26px rgba(0,0,0,0.08);
}

.skeleton-image {
    height: 190px;
    background: linear-gradient(90deg, #f0f0f0 25%, #e0e0e0 50%, #f0f0f0 75%);
    background-size: 200% 100%;
    animation: loading 1.5s infinite;
}

.skeleton-line {
    height: 16px;
    margin: 15px 18px;
    background: linear-gradient(90deg, #f0f0f0 25%, #e0e0e0 50%, #f0f0f0 75%);
    background-size: 200% 100%;
    animation: loading 1.5s infinite;
    border-radius: 8px;
}

.skeleton-line.short { width: 60%; }
.skeleton-line.medium { width: 80%; }

@keyframes loading {
    0% { background-position: 200% 0; }
    100% { background-position: -200% 0; }
}

/* ================== BOTÓN CARGAR MÁS ================== */
.btn-load-more {
    background: #fff;
    border: 2px solid #007bff;
    color: #007bff;
    padding: 12px 35px;
    border-radius: 30px;
    font-weight: 600;
    transition: all 0.3s;
    margin: 20px 0;
    cursor: pointer;
    box-shadow: 0 4px 12px rgba(0,123,255,0.1);
}

.btn-load-more:hover {
    background: #007bff;
    color: white;
    transform: translateY(-3px);
    box-shadow: 0 8px 20px rgba(0,123,255,0.3);
}

/* ================== MENSAJE SIN RESULTADOS ================== */
.sin-resultados {
    text-align: center;
    padding: 60px 20px;
    background: white;
    border-radius: 18px;
    box-shadow: 0 10px 26px rgba(0,0,0,0.08);
    margin: 20px 0;
}

.sin-resultados i {
    font-size: 4rem;
    color: #adb5bd;
    margin-bottom: 20px;
}

.sin-resultados h4 {
    color: #495057;
    margin-bottom: 10px;
}

/* ================== TOAST PERSONALIZADO ================== */
.toast-custom {
    position: fixed;
    bottom: 20px;
    right: 20px;
    background: #28a745;
    color: white;
    padding: 12px 24px;
    border-radius: 50px;
    box-shadow: 0 5px 15px rgba(0,0,0,0.2);
    z-index: 9999;
    animation: slideIn 0.3s ease;
    font-weight: 500;
}

@keyframes slideIn {
    from { transform: translateX(100%); opacity: 0; }
    to { transform: translateX(0); opacity: 1; }
}

/* ================== MODAL DE CÓDIGOS ================== */
.codigo-chip {
    background: #f1f3f5;
    border-radius: 8px;
    padding: 10px;
    text-align: center;
    cursor: pointer;
    border: 1px solid #e0e0e0;
    transition: 0.2s ease;
    font-weight: 500;
}

.codigo-chip:hover {
    background: #007bff;
    color: white;
    border-color: #0056b3;
    transform: scale(1.02);
}

.codigo-chip.copiado {
    background: #28a745;
    color: white;
    border-color: #1e7e34;
}
</style>

<div class="content-wrapper">
    <!-- Content Header -->
    <section class="content-header mb-4">
        <div class="container-fluid">
            <div class="row align-items-center">
                <div class="col-md-6">
                    <h1 class="page-title">
                        <i class="fas fa-boxes mr-2"></i> Inventario de Productos
                    </h1>
                </div>
                <div class="col-md-6 d-flex justify-content-md-end mt-3 mt-md-0">
                    <div class="input-group buscador-box">
                        <input type="text" id="buscador" class="form-control" 
                               placeholder="Buscar producto..." 
                               autocomplete="off">
                        <div class="input-group-append">
                            <span class="input-group-text">
                                <i class="fas fa-search text-white"></i>
                            </span>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Contador de productos -->
            <div class="row mt-3">
                <div class="col-12">
                    <small class="text-muted">
                        <i class="fas fa-cube mr-1"></i> 
                        Mostrando <span id="productos-mostrados">0</span> de <span id="productos-totales"><?= $total_productos ?></span> productos
                    </small>
                </div>
            </div>
        </div>
    </section>

    <!-- Main content -->
    <section class="content">
        <div class="container-fluid">
            
            <?php if ($error_db): ?>
                <div class="alert alert-danger text-center" role="alert">
                    <i class="fas fa-exclamation-triangle mr-2"></i>
                    Error al cargar los productos. Por favor, intenta de nuevo.
                </div>
            <?php endif; ?>
            
            <!-- Skeleton loading -->
            <div id="skeletonLoader" class="row">
                <?php for ($i = 0; $i < 8; $i++): ?>
                <div class="col-xl-3 col-lg-4 col-md-6 col-sm-6 mb-4">
                    <div class="skeleton-card">
                        <div class="skeleton-image"></div>
                        <div class="skeleton-line"></div>
                        <div class="skeleton-line short"></div>
                        <div class="skeleton-line medium"></div>
                        <div class="skeleton-line short" style="margin-bottom: 20px;"></div>
                    </div>
                </div>
                <?php endfor; ?>
            </div>

            <!-- Productos grid -->
            <div class="row" id="listaProductos" style="display: none;">
                
                <?php if (!empty($productos)): ?>
                    <?php foreach ($productos as $row): ?>
                        <?php
                            $nombre = htmlspecialchars($row['nombre']);
                            $categoria = htmlspecialchars($row['categoria'] ?? 'Sin categoría');
                            $stock  = (int)$row['cantidad'];
                            $precio = number_format($row['precio_venta'], 2);
                            $precio_compra = number_format($row['precio_compra'], 2);
                            $codigo = $row['codigos_agrupados'] ?? '';
                            $tiene_imagen = !empty($row['imagen']);
                            $imagen = $tiene_imagen ? htmlspecialchars($row['imagen']) : IMAGEN_POR_DEFECTO_URL;
                            
                            // Badge de stock
                            if ($stock == 0) {
                                $badge_class = 'badge-danger';
                                $badge_text = 'Sin stock';
                                $badge_icon = 'fa-times-circle';
                            } elseif ($stock <= STOCK_BAJO_LIMITE) {
                                $badge_class = 'badge-warning';
                                $badge_text = "Stock bajo ($stock)";
                                $badge_icon = 'fa-exclamation-circle';
                            } else {
                                $badge_class = 'badge-success';
                                $badge_text = "$stock disponibles";
                                $badge_icon = 'fa-check-circle';
                            }
                            
                            // Calcular margen
                            $margen = (($row['precio_venta'] - $row['precio_compra']) / $row['precio_compra']) * 100;
                            $margen_class = $margen > 30 ? 'text-success' : ($margen > 15 ? 'text-warning' : 'text-danger');
                            
                            // Icono para productos sin imagen
                            $iconClass = getProductIcon($nombre, $categoria);
                            $iconColor = getIconColor($iconClass);
                        ?>

                        <div class="col-xl-3 col-lg-4 col-md-6 col-sm-6 mb-4 product-card"
                             data-nombre="<?= strtolower($nombre); ?>"
                             data-categoria="<?= strtolower($categoria); ?>"
                             data-producto-id="<?= $row['id']; ?>">

                            <div class="product-card-pro position-relative">
                                <span class="badge <?= $badge_class; ?> badge-stock">
                                    <i class="fas <?= $badge_icon ?> mr-1"></i>
                                    <?= $badge_text; ?>
                                </span>

                                <?php if ($tiene_imagen): ?>
                                    <!-- Mostrar imagen si existe -->
                                    <div class="product-image-wrapper">
                                        <img src="<?= $imagen ?>" 
                                             class="product-image" 
                                             loading="lazy"
                                             alt="<?= $nombre ?>"
                                             onerror="this.src='<?= IMAGEN_POR_DEFECTO_URL ?>'">
                                    </div>
                                <?php else: ?>
                                    <!-- Mostrar icono si no hay imagen -->
                                    <div class="product-icon-wrapper">
                                        <i class="<?= $iconClass ?> product-icon icon-<?= $iconColor ?>"></i>
                                    </div>
                                <?php endif; ?>

                                <div class="card-content">
                                    <h5 class="product-title" title="<?= $nombre ?>">
                                        <?= $nombre ?>
                                    </h5>
                                    
                                    <div class="product-categoria">
                                        <i class="fas fa-tag"></i> <?= $categoria ?>
                                    </div>

                                    <!-- Métricas en grid -->
                                    <div class="product-metrics">
                                        <div class="metric-item">
                                            <div class="metric-icon">
                                                <i class="fas fa-tag"></i>
                                            </div>
                                            <div class="metric-content">
                                                <div class="metric-label">Venta</div>
                                                <div class="metric-value text-primary">$<?= $precio ?></div>
                                            </div>
                                        </div>
                                        
                                        <div class="metric-item">
                                            <div class="metric-icon">
                                                <i class="fas fa-cubes"></i>
                                            </div>
                                            <div class="metric-content">
                                                <div class="metric-label">Stock</div>
                                                <div class="metric-value <?= $stock > 0 ? 'text-success' : 'text-danger' ?>">
                                                    <?= $stock ?>
                                                </div>
                                            </div>
                                        </div>
                                        
                                        <div class="metric-item">
                                            <div class="metric-icon">
                                                <i class="fas fa-shopping-cart"></i>
                                            </div>
                                            <div class="metric-content">
                                                <div class="metric-label">Compra</div>
                                                <div class="metric-value">$<?= $precio_compra ?></div>
                                            </div>
                                        </div>
                                        
                                        <div class="metric-item">
                                            <div class="metric-icon">
                                                <i class="fas fa-chart-line"></i>
                                            </div>
                                            <div class="metric-content">
                                                <div class="metric-label">Margen</div>
                                                <div class="metric-value <?= $margen_class ?>">
                                                    <?= number_format($margen, 1) ?>%
                                                </div>
                                            </div>
                                        </div>
                                    </div>

                                    <!-- Códigos de barras -->
                                    <?php if (!empty($codigo)): ?>
                                        <?php
                                        $codigosArray = array_map('trim', explode(',', $codigo));
                                        $codigoPrincipal = $codigosArray[0];
                                        $tieneMultiples = count($codigosArray) > 1;
                                        ?>
                                        <div class="codigo-container">
                                            <span class="codigo-label">
                                                <i class="fas fa-barcode"></i> Código:
                                            </span>
                                            <span class="codigo-toggle"
                                                  data-codigos='<?= htmlspecialchars(json_encode($codigosArray)) ?>'
                                                  data-producto='<?= htmlspecialchars($nombre) ?>'
                                                  title="Haz clic para ver detalles">
                                                <?= htmlspecialchars($codigoPrincipal) ?>
                                                <?php if ($tieneMultiples): ?>
                                                    <span class="badge badge-info badge-pill ml-1">+<?= count($codigosArray)-1 ?></span>
                                                <?php endif; ?>
                                            </span>
                                        </div>
                                    <?php else: ?>
                                        <div class="codigo-container">
                                            <span class="codigo-label">
                                                <i class="fas fa-barcode"></i> Código:
                                            </span>
                                            <span class="text-muted">---</span>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div class="col-12">
                        <div class="sin-resultados">
                            <i class="fas fa-box-open"></i>
                            <h4>No hay productos disponibles</h4>
                            <p class="text-muted mb-0">Agrega productos para comenzar a ver el inventario</p>
                        </div>
                    </div>
                <?php endif; ?>

            </div>

            <!-- Load more button -->
            <?php if ($total_productos > count($productos) && count($productos) > 0): ?>
            <div class="text-center mt-4">
                <button id="btnCargarMas" class="btn-load-more">
                    <i class="fas fa-chevron-down mr-2"></i>
                    Cargar más productos
                </button>
            </div>
            <?php endif; ?>

            <!-- No results message -->
            <div id="sinResultados" class="sin-resultados" style="display: none;">
                <i class="fas fa-search"></i>
                <h4>No se encontraron productos</h4>
                <p class="text-muted mb-0">Intenta con otro término de búsqueda</p>
            </div>

        </div>
    </section>
</div>

<!-- Cargar librerías -->
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script src="https://cdn.jsdelivr.net/npm/jsbarcode@3.11.5/dist/JsBarcode.all.min.js"></script>

<script>
// ================== VARIABLES ==================
let productosCargados = <?= count($productos) ?>;
let totalProductos = <?= $total_productos ?>;
let paginaActual = 1;
let cargando = false;

// ================== INICIALIZACIÓN ==================
document.addEventListener('DOMContentLoaded', function() {
    actualizarContador();
    
    // Timeout para ocultar skeleton
    setTimeout(function() {
        const skeleton = document.getElementById('skeletonLoader');
        const lista = document.getElementById('listaProductos');
        
        if (skeleton) skeleton.style.display = 'none';
        if (lista) lista.style.display = 'flex';
        
        initBuscador();
    }, 400);
});

function actualizarContador() {
    const mostradosSpan = document.getElementById('productos-mostrados');
    const totalSpan = document.getElementById('productos-totales');
    
    if (mostradosSpan) mostradosSpan.textContent = productosCargados;
    if (totalSpan) totalSpan.textContent = totalProductos;
}

// ================== BUSCADOR ==================
function initBuscador() {
    const buscador = document.getElementById("buscador");
    if (!buscador) return;
    
    let timeoutId;
    
    buscador.addEventListener("input", function() {
        clearTimeout(timeoutId);
        timeoutId = setTimeout(() => filtrarProductos(this.value), 200);
    });
}

function filtrarProductos(texto) {
    texto = texto.toLowerCase().trim();
    const productos = document.querySelectorAll(".product-card");
    let productosVisibles = 0;

    productos.forEach(card => {
        const nombre = card.dataset.nombre || '';
        const categoria = card.dataset.categoria || '';
        const visible = nombre.includes(texto) || categoria.includes(texto);
        card.style.display = visible ? "block" : "none";
        if (visible) productosVisibles++;
    });

    const sinResultados = document.getElementById('sinResultados');
    if (sinResultados) {
        sinResultados.style.display = (productosVisibles === 0 && texto !== '') ? 'block' : 'none';
    }
    
    const mostradosSpan = document.getElementById('productos-mostrados');
    if (mostradosSpan) {
        mostradosSpan.textContent = texto ? productosVisibles : productosCargados;
    }
}

// ================== TOAST ==================
function mostrarToast(mensaje, tipo = 'success') {
    const toast = document.createElement('div');
    toast.className = 'toast-custom';
    toast.style.background = tipo === 'success' ? '#28a745' : '#dc3545';
    toast.textContent = mensaje;
    document.body.appendChild(toast);

    setTimeout(() => {
        toast.style.animation = 'slideIn 0.3s reverse';
        setTimeout(() => {
            if (toast.parentNode) document.body.removeChild(toast);
        }, 300);
    }, 2000);
}

// ================== COPIAR ==================
async function copiarAlPortapapeles(texto) {
    try {
        await navigator.clipboard.writeText(texto);
        mostrarToast('✓ Código copiado al portapapeles');
    } catch (err) {
        const textarea = document.createElement('textarea');
        textarea.value = texto;
        textarea.style.position = 'fixed';
        textarea.style.opacity = '0';
        document.body.appendChild(textarea);
        textarea.select();
        document.execCommand('copy');
        document.body.removeChild(textarea);
        mostrarToast('✓ Código copiado al portapapeles');
    }
}

// ================== GENERAR CÓDIGO DE BARRAS ==================
function generarCodigoBarras(contenedorId, codigo) {
    const container = document.getElementById(contenedorId);
    if (!container) return;
    
    container.innerHTML = '';
    
    try {
        const svg = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
        svg.id = 'barcode-' + Date.now();
        svg.style.maxWidth = '100%';
        container.appendChild(svg);
        
        JsBarcode(svg, codigo, {
            format: "CODE128",
            width: 2,
            height: 50,
            displayValue: true,
            fontSize: 14,
            margin: 10
        });
    } catch (e) {
        console.error(e);
        container.innerHTML = '<p class="text-muted">Error al generar código</p>';
    }
}

// ================== MANEJADOR DE CÓDIGOS ==================
document.addEventListener('click', async function(e) {
    const toggle = e.target.closest('.codigo-toggle');
    if (!toggle) return;
    
    e.preventDefault();
    
    try {
        const codigos = JSON.parse(toggle.dataset.codigos);
        const nombreProducto = toggle.dataset.producto || 'Producto';

        let html = `
            <div class="text-center mb-4">
                <strong style="font-size: 1.2rem; color: #007bff;">${nombreProducto}</strong>
            </div>
        `;

        if (codigos.length === 1) {
            html += `
                <div class="text-center mb-4 p-3" style="background: #f8f9fa; border-radius: 10px;">
                    <div id="barcode-container"></div>
                </div>
            `;
        }

        html += `<div class="codigos-grid" style="display: grid; grid-template-columns: repeat(auto-fill, minmax(120px, 1fr)); gap: 10px;">`;
        codigos.forEach(c => {
            html += `<div class="codigo-chip" data-codigo="${c}">${c}</div>`;
        });
        html += `</div>`;

        Swal.fire({
            title: 'Códigos de barras',
            html: html,
            showConfirmButton: false,
            showCloseButton: true,
            width: 550,
            didOpen: () => {
                if (codigos.length === 1) {
                    generarCodigoBarras('barcode-container', codigos[0]);
                }

                document.querySelectorAll('.codigo-chip').forEach(chip => {
                    chip.addEventListener('click', async function(e) {
                        e.stopPropagation();
                        const codigo = this.dataset.codigo || this.textContent;
                        await copiarAlPortapapeles(codigo);
                        
                        this.classList.add('copiado');
                        const originalText = this.textContent;
                        this.textContent = '✓ Copiado!';
                        
                        setTimeout(() => {
                            this.classList.remove('copiado');
                            this.textContent = originalText;
                        }, 1000);
                    });
                });
            }
        });
    } catch (error) {
        console.error('Error:', error);
        mostrarToast('Error al mostrar códigos', 'error');
    }
});
</script>

<?php include 'includes/footer.php'; ?>