<?php
include 'includes/session.php';
include 'includes/db.php';
include 'includes/header.php';
include 'includes/navbar.php';

/* ================== CONSTANTES Y CONFIGURACIÓN ================== */
define('STOCK_BAJO_LIMITE', 5);
define('IMAGEN_POR_DEFECTO', 'uploads/no-image.png');
define('PRODUCTOS_POR_PAGINA', 12);

// Verificar si la imagen por defecto existe
$imagen_default_path = $_SERVER['DOCUMENT_ROOT'] . '/uploads/no-image.png';
if (!file_exists($imagen_default_path)) {
    define('IMAGEN_POR_DEFECTO_URL', 'https://via.placeholder.com/300x200?text=Sin+Imagen');
} else {
    define('IMAGEN_POR_DEFECTO_URL', '/uploads/no-image.png');
}

// Función para determinar icono según categoría
function getProductIcon($nombre, $categoria = null) {
    $nombre_lower = strtolower($nombre);
    $categoria_lower = $categoria ? strtolower($categoria) : '';
    $texto_busqueda = $categoria_lower ?: $nombre_lower;
    
    if (preg_match('/(electronica|telefono|celular|smartphone|tablet|computadora|laptop|pc|monitor|teclado|mouse|audifonos|pantalla|impresora|cargador|cable|adaptador|bateria|pila|usb|memoria|disco|tarjeta)/', $texto_busqueda)) return 'fas fa-microchip';
    if (preg_match('/(ropa|camisa|pantalon|vestido|chaqueta|sueter|short|falda|jean|blusa|camiseta)/', $texto_busqueda)) return 'fas fa-tshirt';
    if (preg_match('/(calzado|zapato|tenis|sandalia|botas|zapatilla|chancla)/', $texto_busqueda)) return 'fas fa-shoe-prints';
    if (preg_match('/(alimento|comida|bebida|refresco|agua|snack|galleta|pan|leche|jugo|gaseosa|cerveza|vino)/', $texto_busqueda)) return 'fas fa-utensils';
    if (preg_match('/(hogar|mueble|silla|mesa|escritorio|estante|cocina|baño|sofa|cama|ropero|armario)/', $texto_busqueda)) return 'fas fa-couch';
    if (preg_match('/(papeleria|oficina|papel|lapiz|pluma|cuaderno|libreta|escritura|marcador|borrador|regla|folder|carpeta)/', $texto_busqueda)) return 'fas fa-pen';
    if (preg_match('/(herramienta|martillo|destornillador|pinza|taladro|sierra|llave|alicate|nivel|cincel)/', $texto_busqueda)) return 'fas fa-tools';
    if (preg_match('/(belleza|shampoo|jabon|crema|maquillaje|perfume|cosmetico|desodorante|pasta|cepillo|peine)/', $texto_busqueda)) return 'fas fa-spa';
    if (preg_match('/(deporte|pelota|bicicleta|pesa|gimnasio|balon|raqueta|casco|guante)/', $texto_busqueda)) return 'fas fa-futbol';
    if (preg_match('/(libro|revista|lectura|texto|manual|guia|diccionario|enciclopedia)/', $texto_busqueda)) return 'fas fa-book';
    if (preg_match('/(juguete|muñeca|carro|peluche|lego|rompecabezas|bloques|consola|videojuego)/', $texto_busqueda)) return 'fas fa-gamepad';
    if (preg_match('/(limpieza|limpia|detergente|cloro|escoba|trapeador|recogedor|bolsa)/', $texto_busqueda)) return 'fas fa-pump-soap';
    
    return 'fas fa-box';
}

function getIconColor($iconClass) {
    $colors = [
        'fa-microchip' => 'primary', 'fa-tshirt' => 'info', 'fa-shoe-prints' => 'warning',
        'fa-utensils' => 'success', 'fa-couch' => 'secondary', 'fa-pen' => 'indigo',
        'fa-tools' => 'danger', 'fa-spa' => 'pink', 'fa-futbol' => 'teal',
        'fa-book' => 'purple', 'fa-gamepad' => 'orange', 'fa-pump-soap' => 'cyan', 'fa-box' => 'gray'
    ];
    foreach ($colors as $icon => $color) {
        if (strpos($iconClass, $icon) !== false) return $color;
    }
    return 'gray';
}

// Obtener página actual
$pagina_actual = isset($_GET['pagina']) ? max(1, intval($_GET['pagina'])) : 1;
$offset = ($pagina_actual - 1) * PRODUCTOS_POR_PAGINA;

// Contar total de productos
$count_query = "SELECT COUNT(*) as total FROM productos WHERE tipo_inventario = 'producto' OR tipo_inventario IS NULL OR tipo_inventario = ''";
$count_result = $conn->query($count_query);
$total_productos = $count_result ? $count_result->fetch_assoc()['total'] : 0;
$total_paginas = ceil($total_productos / PRODUCTOS_POR_PAGINA);

// Consulta con paginación
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
    LIMIT " . PRODUCTOS_POR_PAGINA . " OFFSET " . $offset;

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
?>

<style>
.content-wrapper {
    background: linear-gradient(180deg, #FFF4E6, #FFFFFF);
    min-height: 100vh;
    padding: 25px;
    border-radius: 18px 0 0 18px;
}

.page-title {
    font-size: 1.9rem;
    font-weight: 700;
    color: #2c2c2c;
}

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

.pagination-wrapper {
    display: flex;
    justify-content: center;
    align-items: center;
    gap: 15px;
    margin-top: 40px;
    margin-bottom: 20px;
    flex-wrap: wrap;
}

.pagination-btn {
    padding: 10px 20px;
    background: white;
    border: 2px solid #007bff;
    border-radius: 40px;
    color: #007bff;
    font-weight: 600;
    transition: all 0.3s;
    cursor: pointer;
    font-size: 0.9rem;
}

.pagination-btn:hover:not(:disabled) {
    background: #007bff;
    color: white;
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(0,123,255,0.3);
}

.pagination-btn:disabled {
    opacity: 0.5;
    cursor: not-allowed;
}

.page-number {
    min-width: 40px;
    height: 40px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    background: white;
    border: 1px solid #dee2e6;
    border-radius: 10px;
    color: #495057;
    font-weight: 500;
    cursor: pointer;
    transition: all 0.2s;
    margin: 0 3px;
}

.page-number:hover {
    background: #e9ecef;
    border-color: #adb5bd;
}

.page-number.active {
    background: #007bff;
    border-color: #007bff;
    color: white;
}

.page-number.disabled {
    cursor: default;
    background: transparent;
    border: none;
}

.skeleton-card {
    background: #fff;
    border-radius: 18px;
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
    transform: scale(1.02);
}

.codigo-chip.copiado {
    background: #28a745;
    color: white;
}
</style>

<div class="content-wrapper">
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
            
            <div class="row mt-3">
                <div class="col-12">
                    <small class="text-muted">
                        <i class="fas fa-cube mr-1"></i> 
                        Mostrando <span id="productos-mostrados"><?= count($productos) ?></span> de <span id="productos-totales"><?= $total_productos ?></span> productos
                        (Página <span id="pagina-actual"><?= $pagina_actual ?></span> de <span id="total-paginas"><?= $total_paginas ?></span>)
                    </small>
                </div>
            </div>
        </div>
    </section>

    <section class="content">
        <div class="container-fluid">
            
            <?php if ($error_db): ?>
                <div class="alert alert-danger text-center">Error al cargar los productos.</div>
            <?php endif; ?>
            
            <div id="skeletonLoader" class="row">
                <?php for ($i = 0; $i < PRODUCTOS_POR_PAGINA; $i++): ?>
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

            <div class="row" id="listaProductos" style="display: none;">
                <?php foreach ($productos as $row): ?>
                    <?php
                        $nombre = htmlspecialchars($row['nombre']);
                        $categoria = htmlspecialchars($row['categoria'] ?? 'Sin categoría');
                        $stock = (int)$row['cantidad'];
                        $precio = number_format($row['precio_venta'], 2);
                        $precio_compra = number_format($row['precio_compra'], 2);
                        $codigo = $row['codigos_agrupados'] ?? '';
                        $tiene_imagen = !empty($row['imagen']);
                        $imagen = $tiene_imagen ? htmlspecialchars($row['imagen']) : IMAGEN_POR_DEFECTO_URL;
                        
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
                        
                        $margen = (($row['precio_venta'] - $row['precio_compra']) / max(0.01, $row['precio_compra'])) * 100;
                        $margen_class = $margen > 30 ? 'text-success' : ($margen > 15 ? 'text-warning' : 'text-danger');
                        $iconClass = getProductIcon($nombre, $categoria);
                        $iconColor = getIconColor($iconClass);
                    ?>
                    <div class="col-xl-3 col-lg-4 col-md-6 col-sm-6 mb-4 product-card"
                         data-nombre="<?= strtolower($nombre); ?>"
                         data-categoria="<?= strtolower($categoria); ?>">
                        <div class="product-card-pro position-relative">
                            <span class="badge <?= $badge_class; ?> badge-stock">
                                <i class="fas <?= $badge_icon ?> mr-1"></i> <?= $badge_text; ?>
                            </span>
                            <?php if ($tiene_imagen): ?>
                                <div class="product-image-wrapper">
                                    <img src="<?= $imagen ?>" class="product-image" loading="lazy" alt="<?= $nombre ?>"
                                         onerror="this.src='<?= IMAGEN_POR_DEFECTO_URL ?>'">
                                </div>
                            <?php else: ?>
                                <div class="product-icon-wrapper">
                                    <i class="<?= $iconClass ?> product-icon icon-<?= $iconColor ?>"></i>
                                </div>
                            <?php endif; ?>
                            <div class="card-content">
                                <h5 class="product-title" title="<?= $nombre ?>"><?= $nombre ?></h5>
                                <div class="product-categoria"><i class="fas fa-tag"></i> <?= $categoria ?></div>
                                <div class="product-metrics">
                                    <div class="metric-item"><div class="metric-icon"><i class="fas fa-tag"></i></div><div class="metric-content"><div class="metric-label">Venta</div><div class="metric-value text-primary">$<?= $precio ?></div></div></div>
                                    <div class="metric-item"><div class="metric-icon"><i class="fas fa-cubes"></i></div><div class="metric-content"><div class="metric-label">Stock</div><div class="metric-value <?= $stock > 0 ? 'text-success' : 'text-danger' ?>"><?= $stock ?></div></div></div>
                                    <div class="metric-item"><div class="metric-icon"><i class="fas fa-shopping-cart"></i></div><div class="metric-content"><div class="metric-label">Compra</div><div class="metric-value">$<?= $precio_compra ?></div></div></div>
                                    <div class="metric-item"><div class="metric-icon"><i class="fas fa-chart-line"></i></div><div class="metric-content"><div class="metric-label">Margen</div><div class="metric-value <?= $margen_class ?>"><?= number_format($margen, 1) ?>%</div></div></div>
                                </div>
                                <?php if (!empty($codigo)): ?>
                                    <?php $codigosArray = array_map('trim', explode(',', $codigo)); $codigoPrincipal = $codigosArray[0]; $tieneMultiples = count($codigosArray) > 1; ?>
                                    <div class="codigo-container">
                                        <span class="codigo-label"><i class="fas fa-barcode"></i> Código:</span>
                                        <span class="codigo-toggle" data-codigos='<?= htmlspecialchars(json_encode($codigosArray)) ?>' data-producto='<?= htmlspecialchars($nombre) ?>'>
                                            <?= htmlspecialchars($codigoPrincipal) ?>
                                            <?php if ($tieneMultiples): ?><span class="badge badge-info badge-pill ml-1">+<?= count($codigosArray)-1 ?></span><?php endif; ?>
                                        </span>
                                    </div>
                                <?php else: ?>
                                    <div class="codigo-container"><span class="codigo-label"><i class="fas fa-barcode"></i> Código:</span><span class="text-muted">---</span></div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>

            <?php if ($total_paginas > 1): ?>
            <div class="pagination-wrapper">
                <button class="pagination-btn" id="btnPrimera" data-page="1" <?= $pagina_actual == 1 ? 'disabled' : '' ?>>
                    <i class="fas fa-angle-double-left"></i> Primera
                </button>
                <button class="pagination-btn" id="btnAnterior" data-page="<?= $pagina_actual - 1 ?>" <?= $pagina_actual == 1 ? 'disabled' : '' ?>>
                    <i class="fas fa-chevron-left"></i> Anterior
                </button>
                
                <div class="pagination-pages" id="paginationPages">
                    <?php
                    $rango = 2;
                    $inicio = max(1, $pagina_actual - $rango);
                    $fin = min($total_paginas, $pagina_actual + $rango);
                    if ($inicio > 1) echo '<span class="page-number" data-page="1">1</span>';
                    if ($inicio > 2) echo '<span class="page-number disabled">...</span>';
                    for ($i = $inicio; $i <= $fin; $i++):
                    ?>
                        <span class="page-number <?= $i == $pagina_actual ? 'active' : '' ?>" data-page="<?= $i ?>"><?= $i ?></span>
                    <?php endfor;
                    if ($fin < $total_paginas - 1) echo '<span class="page-number disabled">...</span>';
                    if ($fin < $total_paginas) echo '<span class="page-number" data-page="' . $total_paginas . '">' . $total_paginas . '</span>';
                    ?>
                </div>
                
                <button class="pagination-btn" id="btnSiguiente" data-page="<?= $pagina_actual + 1 ?>" <?= $pagina_actual == $total_paginas ? 'disabled' : '' ?>>
                    Siguiente <i class="fas fa-chevron-right"></i>
                </button>
                <button class="pagination-btn" id="btnUltima" data-page="<?= $total_paginas ?>" <?= $pagina_actual == $total_paginas ? 'disabled' : '' ?>>
                    Última <i class="fas fa-angle-double-right"></i>
                </button>
            </div>
            <?php endif; ?>

            <div id="sinResultados" class="sin-resultados" style="display: none;">
                <i class="fas fa-search"></i>
                <h4>No se encontraron productos</h4>
                <p class="text-muted mb-0">Intenta con otro término de búsqueda</p>
            </div>
        </div>
    </section>
</div>

<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script src="https://cdn.jsdelivr.net/npm/jsbarcode@3.11.5/dist/JsBarcode.all.min.js"></script>

<script>
let currentPage = <?= $pagina_actual ?>;
let totalPages = <?= $total_paginas ?>;
let searchTerm = '';
let isLoading = false;

document.addEventListener('DOMContentLoaded', function() {
    setTimeout(function() {
        document.getElementById('skeletonLoader').style.display = 'none';
        document.getElementById('listaProductos').style.display = 'flex';
    }, 300);
    
    initBuscador();
    initPagination();
    initCodigosEventos();
});

function initCodigosEventos() {
    document.querySelectorAll('.codigo-toggle').forEach(el => {
        el.removeEventListener('click', manejarClickCodigo);
        el.addEventListener('click', manejarClickCodigo);
    });
}

function initBuscador() {
    const buscador = document.getElementById("buscador");
    if (!buscador) return;
    let timeoutId;
    buscador.addEventListener("input", function() {
        clearTimeout(timeoutId);
        timeoutId = setTimeout(() => {
            searchTerm = this.value;
            cargarPagina(1);
        }, 500);
    });
}

function initPagination() {
    const paginationButtons = document.querySelectorAll('.page-number, #btnPrimera, #btnAnterior, #btnSiguiente, #btnUltima');
    paginationButtons.forEach(btn => {
        btn.addEventListener('click', function(e) {
            if (isLoading) return;
            const page = parseInt(this.dataset.page);
            if (page && page !== currentPage && page >= 1 && page <= totalPages) {
                cargarPagina(page);
            }
        });
    });
}

function cargarPagina(page) {
    if (isLoading) return;
    isLoading = true;
    currentPage = page;
    
    const url = new URL(window.location.href);
    url.searchParams.set('pagina', page);
    window.history.pushState({}, '', url);
    
    document.getElementById('skeletonLoader').style.display = 'flex';
    document.getElementById('listaProductos').style.display = 'none';
    
    fetch(`ajax/cargar_productos.php?pagina=${page}&buscar=${encodeURIComponent(searchTerm)}`)
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                actualizarProductos(data.productos);
                actualizarPaginacion(data.pagina_actual, data.total_paginas);
                document.getElementById('productos-mostrados').textContent = data.total_mostrados;
                document.getElementById('pagina-actual').textContent = data.pagina_actual;
                document.getElementById('total-paginas').textContent = data.total_paginas;
                document.getElementById('productos-totales').textContent = data.total_productos;
            }
        })
        .catch(error => console.error('Error:', error))
        .finally(() => {
            setTimeout(() => {
                document.getElementById('skeletonLoader').style.display = 'none';
                document.getElementById('listaProductos').style.display = 'flex';
                isLoading = false;
            }, 300);
        });
}

function actualizarProductos(productos) {
    const container = document.getElementById('listaProductos');
    if (!container) return;
    container.innerHTML = '';
    
    if (productos.length === 0 && searchTerm !== '') {
        document.getElementById('sinResultados').style.display = 'block';
        return;
    }
    document.getElementById('sinResultados').style.display = 'none';
    
    productos.forEach(p => {
        const stock = parseInt(p.cantidad);
        let badgeClass = stock === 0 ? 'badge-danger' : (stock <= 5 ? 'badge-warning' : 'badge-success');
        let badgeText = stock === 0 ? 'Sin stock' : (stock <= 5 ? `Stock bajo (${stock})` : `${stock} disponibles`);
        let badgeIcon = stock === 0 ? 'fa-times-circle' : (stock <= 5 ? 'fa-exclamation-circle' : 'fa-check-circle');
        const margen = ((p.precio_venta - p.precio_compra) / Math.max(0.01, p.precio_compra)) * 100;
        let margenClass = margen > 30 ? 'text-success' : (margen > 15 ? 'text-warning' : 'text-danger');
        const tieneImagen = p.imagen && p.imagen !== '';
        const imagenUrl = tieneImagen ? p.imagen : '<?= IMAGEN_POR_DEFECTO_URL ?>';
        
        const categoriaProducto = p.categoria || 'Sin categoría';
        const nombreProducto = p.nombre;
        let iconoClases = getIconoPorCategoriaJS(nombreProducto, categoriaProducto);
        
        let codigosHtml = '';
        if (p.codigos_agrupados) {
            const codigosArray = p.codigos_agrupados.split(',').map(c => c.trim());
            const codigoPrincipal = codigosArray[0];
            const tieneMultiples = codigosArray.length > 1;
            const codigosJson = JSON.stringify(codigosArray).replace(/"/g, '&quot;');
            codigosHtml = `<div class="codigo-container">
                <span class="codigo-label"><i class="fas fa-barcode"></i> Código:</span>
                <span class="codigo-toggle" data-codigos='${codigosJson}' data-producto='${escapeHtml(p.nombre)}' style="cursor: pointer;">
                    ${escapeHtml(codigoPrincipal)}${tieneMultiples ? `<span class="badge badge-info badge-pill ml-1">+${codigosArray.length-1}</span>` : ''}
                </span>
            </div>`;
        } else {
            codigosHtml = `<div class="codigo-container"><span class="codigo-label"><i class="fas fa-barcode"></i> Código:</span><span class="text-muted">---</span></div>`;
        }
        
        const productHtml = `
            <div class="col-xl-3 col-lg-4 col-md-6 col-sm-6 mb-4 product-card" data-nombre="${escapeHtml(p.nombre.toLowerCase())}" data-categoria="${escapeHtml((p.categoria || 'Sin categoría').toLowerCase())}">
                <div class="product-card-pro position-relative">
                    <span class="badge ${badgeClass} badge-stock"><i class="fas ${badgeIcon} mr-1"></i> ${badgeText}</span>
                    ${tieneImagen ? 
                        `<div class="product-image-wrapper"><img src="${imagenUrl}" class="product-image" loading="lazy" alt="${escapeHtml(p.nombre)}" onerror="this.src='<?= IMAGEN_POR_DEFECTO_URL ?>'"></div>` : 
                        `<div class="product-icon-wrapper"><i class="${iconoClases} product-icon" style="font-size: 4.5rem;"></i></div>`
                    }
                    <div class="card-content">
                        <h5 class="product-title" title="${escapeHtml(p.nombre)}">${escapeHtml(p.nombre)}</h5>
                        <div class="product-categoria"><i class="fas fa-tag"></i> ${escapeHtml(p.categoria || 'Sin categoría')}</div>
                        <div class="product-metrics">
                            <div class="metric-item"><div class="metric-icon"><i class="fas fa-tag"></i></div><div class="metric-content"><div class="metric-label">Venta</div><div class="metric-value text-primary">$${parseFloat(p.precio_venta).toFixed(2)}</div></div></div>
                            <div class="metric-item"><div class="metric-icon"><i class="fas fa-cubes"></i></div><div class="metric-content"><div class="metric-label">Stock</div><div class="metric-value ${stock > 0 ? 'text-success' : 'text-danger'}">${stock}</div></div></div>
                            <div class="metric-item"><div class="metric-icon"><i class="fas fa-shopping-cart"></i></div><div class="metric-content"><div class="metric-label">Compra</div><div class="metric-value">$${parseFloat(p.precio_compra).toFixed(2)}</div></div></div>
                            <div class="metric-item"><div class="metric-icon"><i class="fas fa-chart-line"></i></div><div class="metric-content"><div class="metric-label">Margen</div><div class="metric-value ${margenClass}">${margen.toFixed(1)}%</div></div></div>
                        </div>
                        ${codigosHtml}
                    </div>
                </div>
            </div>
        `;
        container.insertAdjacentHTML('beforeend', productHtml);
    });
    
    initCodigosEventos();
}

function getIconoPorCategoriaJS(nombre, categoria) {
    const textoBusqueda = (categoria || nombre || '').toLowerCase();
    
    if (/(electronica|telefono|celular|smartphone|tablet|computadora|laptop|pc|monitor|teclado|mouse|audifonos|pantalla|impresora|cargador|cable|adaptador|bateria|pila|usb|memoria|disco|tarjeta)/.test(textoBusqueda)) {
        return 'fas fa-microchip icon-primary';
    }
    if (/(ropa|camisa|pantalon|vestido|chaqueta|sueter|short|falda|jean|blusa|camiseta)/.test(textoBusqueda)) {
        return 'fas fa-tshirt icon-info';
    }
    if (/(calzado|zapato|tenis|sandalia|botas|zapatilla|chancla)/.test(textoBusqueda)) {
        return 'fas fa-shoe-prints icon-warning';
    }
    if (/(alimento|comida|bebida|refresco|agua|snack|galleta|pan|leche|jugo|gaseosa|cerveza|vino)/.test(textoBusqueda)) {
        return 'fas fa-utensils icon-success';
    }
    if (/(hogar|mueble|silla|mesa|escritorio|estante|cocina|baño|sofa|cama|ropero|armario)/.test(textoBusqueda)) {
        return 'fas fa-couch icon-secondary';
    }
    if (/(papeleria|oficina|papel|lapiz|pluma|cuaderno|libreta|escritura|marcador|borrador|regla|folder|carpeta)/.test(textoBusqueda)) {
        return 'fas fa-pen icon-indigo';
    }
    if (/(herramienta|martillo|destornillador|pinza|taladro|sierra|llave|alicate|nivel|cincel)/.test(textoBusqueda)) {
        return 'fas fa-tools icon-danger';
    }
    if (/(belleza|shampoo|jabon|crema|maquillaje|perfume|cosmetico|desodorante|pasta|cepillo|peine)/.test(textoBusqueda)) {
        return 'fas fa-spa icon-pink';
    }
    if (/(deporte|pelota|bicicleta|pesa|gimnasio|balon|raqueta|casco|guante)/.test(textoBusqueda)) {
        return 'fas fa-futbol icon-teal';
    }
    if (/(libro|revista|lectura|texto|manual|guia|diccionario|enciclopedia)/.test(textoBusqueda)) {
        return 'fas fa-book icon-purple';
    }
    if (/(juguete|muñeca|carro|peluche|lego|rompecabezas|bloques|consola|videojuego)/.test(textoBusqueda)) {
        return 'fas fa-gamepad icon-orange';
    }
    if (/(limpieza|limpia|detergente|cloro|escoba|trapeador|recogedor|bolsa)/.test(textoBusqueda)) {
        return 'fas fa-pump-soap icon-cyan';
    }
    
    return 'fas fa-box icon-gray';
}

function generarCodigoBarras(containerId, codigo) {
    const container = document.getElementById(containerId);
    if (!container) return;
    
    container.innerHTML = '';
    
    if (typeof JsBarcode === 'undefined') {
        container.innerHTML = `<div style="font-family: monospace; font-size: 20px;">${escapeHtml(codigo)}</div>`;
        return;
    }
    
    try {
        // Crear una tarjeta bonita para el código
        const card = document.createElement('div');
        card.style.cssText = `
            background: linear-gradient(135deg, #fff 0%, #f8f9fa 100%);
            border-radius: 20px;
            padding: 20px;
            box-shadow: 0 10px 25px rgba(0,0,0,0.1);
            text-align: center;
            border: 1px solid #e0e0e0;
        `;
        
        // Título
        const title = document.createElement('div');
        title.style.cssText = `
            font-size: 12px;
            color: #6c757d;
            text-transform: uppercase;
            letter-spacing: 2px;
            margin-bottom: 15px;
        `;
        title.innerHTML = '<i class="fas fa-barcode"></i> CÓDIGO DE BARRAS';
        card.appendChild(title);
        
        // Canvas para el código
        const canvas = document.createElement('canvas');
        canvas.style.width = '100%';
        canvas.style.maxWidth = '300px';
        canvas.style.margin = '10px auto';
        canvas.style.display = 'block';
        card.appendChild(canvas);
        
        // Footer
        const footer = document.createElement('div');
        footer.style.cssText = `
            font-size: 11px;
            color: #adb5bd;
            margin-top: 15px;
            padding-top: 10px;
            border-top: 1px dashed #dee2e6;
        `;
        footer.innerHTML = '✓ Escanea con tu dispositivo';
        card.appendChild(footer);
        
        container.appendChild(card);
        
        JsBarcode(canvas, codigo, {
            format: "CODE128",
            width: 2.2,
            height: 60,
            displayValue: true,
            fontSize: 15,
            margin: 5,
            textAlign: "center",
            textPosition: "bottom",
            font: "monospace",
            background: "transparent",
            lineColor: "#000000"
        });
        
    } catch(e) {
        container.innerHTML = `<div style="font-family: monospace; font-size: 18px;">${escapeHtml(codigo)}</div>`;
    }
}

function manejarClickCodigo(e) {
    e.preventDefault();
    e.stopPropagation();
    const toggle = e.currentTarget;
    
    if (!toggle.dataset.codigos) {
        console.error('No hay códigos en el elemento');
        return;
    }
    
    try {
        let codigos;
        try {
            codigos = JSON.parse(toggle.dataset.codigos);
        } catch {
            codigos = [toggle.dataset.codigos];
        }
        
        const nombreProducto = toggle.dataset.producto || 'Producto';
        
        let html = `<div class="text-center mb-4"><strong style="font-size: 1.3rem; color: #007bff;">${escapeHtml(nombreProducto)}</strong></div>`;
        
        if (codigos.length === 1) {
            html += `<div class="text-center mb-4 p-3" style="background: #f8f9fa; border-radius: 12px;">
                        <div id="barcode-container" style="display: flex; justify-content: center; min-height: 100px;"></div>
                     </div>`;
        }
        
        html += `<div class="codigos-grid" style="display: grid; grid-template-columns: repeat(auto-fill, minmax(130px, 1fr)); gap: 12px; margin-top: 10px;">`;
        codigos.forEach(c => {
            html += `<div class="codigo-chip" data-codigo="${escapeHtml(c)}" style="cursor: pointer; padding: 12px; border-radius: 10px; background: #e9ecef; text-align: center; transition: all 0.2s; font-weight: 600;"> ${escapeHtml(c)}</div>`;
        });
        html += `</div>`;
        
        Swal.fire({
            title: 'Códigos de barras',
            html: html,
            showConfirmButton: false,
            showCloseButton: true,
            width: 600,
            didOpen: () => {
                if (codigos.length === 1) {
                    // Esperar a que el DOM esté listo
                    setTimeout(() => {
                        const container = document.getElementById('barcode-container');
                        if (container) {
                            generarCodigoBarras('barcode-container', codigos[0]);
                        }
                    }, 300);
                }
            }
        });
        
        // Eventos para copiar códigos
        setTimeout(() => {
            document.querySelectorAll('.codigo-chip').forEach(chip => {
                chip.removeEventListener('click', chip._clickHandler);
                const handler = async function(e) {
                    e.stopPropagation();
                    const codigo = this.dataset.codigo || this.textContent.replace('', '').trim();
                    await copiarAlPortapapeles(codigo);
                    
                    // Efecto visual de copiado
                    this.style.background = '#28a745';
                    this.style.color = 'white';
                    this.innerHTML = '✓ Copiado!';
                    
                    setTimeout(() => {
                        this.style.background = '#e9ecef';
                        this.style.color = '#333';
                        this.innerHTML = ` ${codigo}`;
                    }, 1500);
                };
                chip._clickHandler = handler;
                chip.addEventListener('click', handler);
            });
        }, 400);
        
    } catch (error) { 
        console.error('Error al mostrar códigos:', error);
        Swal.fire('Error', 'No se pudieron cargar los códigos del producto', 'error');
    }
}

function actualizarPaginacion(paginaActual, totalPaginas) {
    currentPage = paginaActual;
    totalPages = totalPaginas;
    
    const btnPrimera = document.getElementById('btnPrimera');
    const btnAnterior = document.getElementById('btnAnterior');
    const btnSiguiente = document.getElementById('btnSiguiente');
    const btnUltima = document.getElementById('btnUltima');
    
    if (btnPrimera) btnPrimera.disabled = paginaActual === 1;
    if (btnAnterior) btnAnterior.disabled = paginaActual === 1;
    if (btnSiguiente) btnSiguiente.disabled = paginaActual === totalPaginas;
    if (btnUltima) btnUltima.disabled = paginaActual === totalPaginas;
    
    if (btnPrimera) btnPrimera.dataset.page = 1;
    if (btnAnterior) btnAnterior.dataset.page = paginaActual - 1;
    if (btnSiguiente) btnSiguiente.dataset.page = paginaActual + 1;
    if (btnUltima) btnUltima.dataset.page = totalPaginas;
    
    const pagesContainer = document.getElementById('paginationPages');
    if (pagesContainer) {
        let inicio = Math.max(1, paginaActual - 2);
        let fin = Math.min(totalPaginas, paginaActual + 2);
        let html = '';
        if (inicio > 1) html += `<span class="page-number" data-page="1">1</span>`;
        if (inicio > 2) html += `<span class="page-number disabled">...</span>`;
        for (let i = inicio; i <= fin; i++) html += `<span class="page-number ${i === paginaActual ? 'active' : ''}" data-page="${i}">${i}</span>`;
        if (fin < totalPaginas - 1) html += `<span class="page-number disabled">...</span>`;
        if (fin < totalPaginas) html += `<span class="page-number" data-page="${totalPaginas}">${totalPaginas}</span>`;
        pagesContainer.innerHTML = html;
        
        document.querySelectorAll('.page-number:not(.disabled)').forEach(btn => {
            btn.addEventListener('click', function() { if (!isLoading) cargarPagina(parseInt(this.dataset.page)); });
        });
    }
}

async function copiarAlPortapapeles(texto) {
    try { 
        await navigator.clipboard.writeText(texto); 
        mostrarToast('✓ Código copiado'); 
    } catch { 
        const ta = Object.assign(document.createElement('textarea'), { value: texto, style: 'position:fixed;opacity:0' }); 
        document.body.appendChild(ta); 
        ta.select(); 
        document.execCommand('copy'); 
        document.body.removeChild(ta); 
        mostrarToast('✓ Código copiado'); 
    }
}

function mostrarToast(mensaje) {
    const toast = Object.assign(document.createElement('div'), { className: 'toast-custom', textContent: mensaje });
    document.body.appendChild(toast);
    setTimeout(() => { toast.style.animation = 'slideIn 0.3s reverse'; setTimeout(() => toast.remove(), 300); }, 2000);
}

function escapeHtml(text) {
    if (!text) return '';
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}
</script>

<?php include 'includes/footer.php'; ?>