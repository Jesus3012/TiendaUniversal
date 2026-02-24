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
    // Si no existe, usar una imagen por defecto de internet
    define('IMAGEN_POR_DEFECTO_URL', 'https://via.placeholder.com/300x200?text=Sin+Imagen');
} else {
    define('IMAGEN_POR_DEFECTO_URL', '/uploads/no-image.png');
}

/* ================== CONSULTA ================== */
$query = "
    SELECT 
        p.*,
        GROUP_CONCAT(cb.codigo SEPARATOR ', ') AS codigos_agrupados
    FROM productos p
    LEFT JOIN codigos_barras cb ON cb.producto_id = p.id
    GROUP BY p.id
    ORDER BY p.nombre ASC
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
?>

<style>
/* ================== FONDO GENERAL ================== */
.content-wrapper {
    background: linear-gradient(180deg, #FFF4E6, #FFFFFF);
    min-height: 100vh;
    padding: 25px;
    border-radius: 18px 0 0 18px;
}

/* ================== HEADER ================== */
.page-title {
    font-size: 1.9rem;
    font-weight: 700;
    color: #2c2c2c;
}

/* ================== BUSCADOR ================== */
.buscador-box {
    max-width: 360px;
}

#buscador {
    border-radius: 14px 0 0 14px !important;
    border-right: none;
}

.input-group-text {
    border-radius: 0 14px 14px 0 !important;
    background: #111;
    border: none;
}

/* ================== CARD PRODUCTO ================== */
.product-card-pro {
    background: #fff;
    border-radius: 18px;
    overflow: hidden;
    transition: all .35s ease;
    box-shadow: 0 10px 26px rgba(0,0,0,0.08);
    height: 100%;
}

.product-card-pro:hover {
    transform: translateY(-6px);
    box-shadow: 0 18px 45px rgba(0,0,0,0.16);
}

/* ================== IMAGEN ================== */
.product-image-pro {
    width: 100%;
    height: 190px;
    object-fit: cover;
    background: #f4f4f4;
}

/* ================== TEXTO ================== */
.product-title {
    font-size: 1.1rem;
    font-weight: 600;
    color: #222;
}

.product-meta {
    font-size: .9rem;
    color: #666;
}

/* ================== BADGES ================== */
.badge-stock {
    position: absolute;
    top: 14px;
    right: 14px;
    padding: 6px 14px;
    font-size: .75rem;
    border-radius: 20px;
    box-shadow: 0 4px 10px rgba(0,0,0,.15);
    z-index: 10;
}

/* ================== GRID ESTABLE ================== */
.product-card {
    display: block;
}

.codigos-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(100px, 1fr));
    gap: 8px;
    margin-top: 10px;
    max-height: 300px;
    overflow-y: auto;
    padding: 5px;
}

.codigo-chip {
    background: #f1f3f5;
    border-radius: 8px;
    padding: 8px 10px;
    font-size: 13px;
    text-align: center;
    font-weight: 500;
    border: 1px solid #e0e0e0;
    transition: 0.2s ease;
    cursor: pointer;
    user-select: none;
}

.codigo-chip:hover {
    background: #007bff;
    color: white;
    border-color: #0056b3;
    transform: scale(1.05);
}

.codigo-chip.copiado {
    background: #28a745;
    color: white;
    border-color: #1e7e34;
}

.codigo-toggle {
    cursor: pointer;
    color: #007bff;
    text-decoration: underline dotted;
}

.codigo-toggle:hover {
    color: #0056b3;
}

/* ================== MODAL PERSONALIZADO ================== */
.swal-codigos .swal2-icon {
    transform: scale(0.8);
}

.swal-codigos .swal2-close {
    color: #6c757d;
    font-size: 40px;
    transition: 0.2s ease;
    outline: none;
    box-shadow: none;
}

.swal-codigos .swal2-close:hover {
    color: #dc3545;
    transform: rotate(90deg);
}

.codigo-barra-container {
    margin: 15px 0;
    text-align: center;
    padding: 15px;
    background: #f8f9fa;
    border-radius: 10px;
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

.sin-resultados p {
    color: #6c757d;
}

/* ================== LOADER ================== */
.loader-container {
    display: flex;
    justify-content: center;
    align-items: center;
    min-height: 300px;
}

.loader {
    border: 5px solid #f3f3f3;
    border-top: 5px solid #007bff;
    border-radius: 50%;
    width: 50px;
    height: 50px;
    animation: spin 1s linear infinite;
}

@keyframes spin {
    0% { transform: rotate(0deg); }
    100% { transform: rotate(360deg); }
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
    from {
        transform: translateX(100%);
        opacity: 0;
    }
    to {
        transform: translateX(0);
        opacity: 1;
    }
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
                        <input type="text" id="buscador" class="form-control" placeholder="Buscar producto..." autocomplete="off">
                        <div class="input-group-append">
                            <span class="input-group-text">
                                <i class="fas fa-search text-white"></i>
                            </span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <section class="content">
        <div class="container-fluid">
            
            <?php if ($error_db): ?>
                <div class="alert alert-danger text-center" role="alert">
                    <i class="fas fa-exclamation-triangle mr-2"></i>
                    Error al cargar los productos. Por favor, intenta de nuevo más tarde.
                </div>
            <?php else: ?>
                
                <div id="loaderInicial" class="loader-container">
                    <div class="loader"></div>
                </div>

                <div class="row" id="listaProductos" style="display: none;">
                    
                    <?php foreach ($productos as $row): ?>
                        <?php
                            $nombre = htmlspecialchars($row['nombre']);
                            $stock  = (int)$row['cantidad'];
                            $precio = number_format($row['precio_venta'], 2);
                            $codigo = $row['codigos_agrupados'] ?? '---';
                            $imagen = !empty($row['imagen']) ? htmlspecialchars($row['imagen']) : IMAGEN_POR_DEFECTO_URL;
                            
                            if ($stock == 0) {
                                $badge_class = 'badge-danger';
                                $badge_text = 'Sin stock';
                            } elseif ($stock <= STOCK_BAJO_LIMITE) {
                                $badge_class = 'badge-warning';
                                $badge_text = "Stock bajo ($stock)";
                            } else {
                                $badge_class = 'badge-success';
                                $badge_text = "Stock $stock";
                            }
                        ?>

                        <div class="col-xl-3 col-lg-4 col-md-6 col-sm-6 mb-4 product-card"
                             data-nombre="<?= strtolower($nombre); ?>"
                             data-producto-id="<?= $row['id']; ?>">

                            <div class="product-card-pro position-relative">
                                <span class="badge <?= $badge_class; ?> badge-stock">
                                    <?= $badge_text; ?>
                                </span>

                                <img src="<?= $imagen; ?>" 
                                    class="product-image-pro img-carga" 
                                    loading="lazy"
                                    onload="imagenCargada(this)"
                                    onerror="imagenError(this)">

                                <div class="p-3">
                                    <h5 class="product-title mb-2"><?= $nombre; ?></h5>

                                    <div class="product-meta mb-1">
                                        <i class="fas fa-tag mr-1"></i> Precio venta:
                                        <span class="text-success font-weight-bold">$<?= $precio; ?></span>
                                    </div>

                                    <div class="product-meta mb-1">
                                        <i class="fas fa-cubes mr-1"></i> Stock actual:
                                        <?= $stock > 0
                                            ? "<span class='font-weight-bold text-dark'>$stock</span>"
                                            : "<span class='font-weight-bold text-danger'>Agotado</span>"; ?>
                                    </div>

                                    <div class="product-meta">
                                        <i class="fas fa-barcode mr-1"></i> <strong>Código(s):</strong>

                                        <?php
                                        if (!empty($codigo) && $codigo !== '---') {
                                            $codigosArray = array_map('trim', explode(',', $codigo));
                                            $codigoPrincipal = $codigosArray[0];
                                            $tieneMultiples = count($codigosArray) > 1;

                                            echo "
                                                <span 
                                                    class='codigo-toggle'
                                                    data-codigos='".htmlspecialchars(json_encode($codigosArray))."'
                                                    data-producto='".htmlspecialchars($nombre)."'
                                                >
                                                    $codigoPrincipal
                                                    " . ($tieneMultiples ? " <i class='fas fa-expand ml-1'></i>" : "") . "
                                                </span>
                                            ";
                                        } else {
                                            echo "<span class='text-muted'>---</span>";
                                        }
                                        ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>

                </div>

                <div id="sinResultados" class="sin-resultados" style="display: none;">
                    <i class="fas fa-search"></i>
                    <h4>No se encontraron productos</h4>
                    <p>Intenta con otro término de búsqueda</p>
                </div>

            <?php endif; ?>

        </div>
    </section>
</div>

<!-- Cargar librerías necesarias -->
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script src="https://cdn.jsdelivr.net/npm/jsbarcode@3.11.5/dist/JsBarcode.all.min.js"></script>

<script>
// ================== VARIABLES GLOBALES ==================
let imagenesCargadas = 0;
let imagenesError = 0;
let totalImagenes = <?= count($productos); ?>;
let timeoutLoader;

// ================== FUNCIÓN PARA VERIFICAR CARGA COMPLETA ==================
function verificarCargaCompleta() {
    // Si todas las imágenes se procesaron (cargadas o con error)
    if (imagenesCargadas + imagenesError >= totalImagenes) {
        ocultarLoader();
    }
}

function ocultarLoader() {
    const loader = document.getElementById('loaderInicial');
    const lista = document.getElementById('listaProductos');
    
    if (loader) {
        loader.style.display = 'none';
    }
    if (lista) {
        lista.style.display = 'flex';
    }
    
    // Limpiar timeout si existe
    if (timeoutLoader) {
        clearTimeout(timeoutLoader);
    }
}

// Función para cuando una imagen se carga correctamente
function imagenCargada(img) {
    imagenesCargadas++;
    verificarCargaCompleta();
}

// Función para cuando hay error en una imagen
function imagenError(img) {
    imagenesError++;
    // Cambiar a imagen por defecto si no se cargó
    if (!img.src.includes('no-image') && !img.src.includes('placeholder')) {
        img.src = '<?= IMAGEN_POR_DEFECTO_URL; ?>';
    }
    verificarCargaCompleta();
}

// ================== INICIALIZACIÓN ==================
document.addEventListener('DOMContentLoaded', function() {
    // Si no hay productos, ocultar loader inmediatamente
    if (totalImagenes === 0) {
        ocultarLoader();
        return;
    }
    
    // Timeout de seguridad: si después de 5 segundos no se han cargado todas las imágenes,
    // forzar la ocultación del loader
    timeoutLoader = setTimeout(function() {
        console.log('Timeout de seguridad: forzando ocultación del loader');
        ocultarLoader();
    }, 5000);
    
    // Inicializar buscador
    const buscador = document.getElementById("buscador");
    if (buscador) {
        buscador.addEventListener("input", filtrarProductos);
    }
    
    // Verificar imágenes que ya podrían estar cacheadas
    const imagenes = document.querySelectorAll('.img-carga');
    if (imagenes.length === 0) {
        ocultarLoader();
    } else {
        imagenes.forEach(img => {
            // Si la imagen ya está completa (cargada de caché)
            if (img.complete) {
                if (img.naturalHeight !== 0) {
                    imagenCargada(img);
                } else {
                    imagenError(img);
                }
            }
        });
    }
});

// ================== BUSCADOR ==================
function filtrarProductos() {
    const buscador = document.getElementById("buscador");
    const texto = buscador.value.toLowerCase().trim();
    const productos = document.querySelectorAll(".product-card");
    let productosVisibles = 0;

    productos.forEach(card => {
        const nombre = card.dataset.nombre;
        const visible = nombre.includes(texto);
        card.style.display = visible ? "" : "none";
        if (visible) productosVisibles++;
    });

    const sinResultados = document.getElementById('sinResultados');
    if (sinResultados) {
        sinResultados.style.display = (productosVisibles === 0 && texto !== '') ? 'block' : 'none';
    }
}

// ================== TOAST NOTIFICATION ==================
function mostrarToast(mensaje, tipo = 'success') {
    const toast = document.createElement('div');
    toast.className = 'toast-custom';
    toast.style.background = tipo === 'success' ? '#28a745' : '#dc3545';
    toast.textContent = mensaje;
    document.body.appendChild(toast);

    setTimeout(() => {
        toast.style.animation = 'slideIn 0.3s reverse';
        setTimeout(() => {
            if (toast.parentNode) {
                document.body.removeChild(toast);
            }
        }, 300);
    }, 2000);
}

// ================== FUNCIÓN PARA COPIAR TEXTO ==================
function copiarAlPortapapeles(texto) {
    if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(texto).then(() => {
            mostrarToast('✓ Código copiado al portapapeles');
        }).catch(err => {
            console.error('Error al copiar: ', err);
            copiarConFallback(texto);
        });
    } else {
        copiarConFallback(texto);
    }
}

function copiarConFallback(texto) {
    try {
        const textarea = document.createElement('textarea');
        textarea.value = texto;
        textarea.style.position = 'fixed';
        textarea.style.opacity = '0';
        document.body.appendChild(textarea);
        textarea.select();
        document.execCommand('copy');
        document.body.removeChild(textarea);
        mostrarToast('✓ Código copiado al portapapeles');
    } catch (err) {
        console.error('Error en fallback de copia: ', err);
        mostrarToast('✗ No se pudo copiar el código', 'error');
    }
}

// ================== GENERAR CÓDIGO DE BARRAS ==================
function generarCodigoBarras(contenedorId, codigo) {
    const container = document.getElementById(contenedorId);
    if (!container) return;
    
    container.innerHTML = '';
    
    if (typeof JsBarcode === 'undefined') {
        container.innerHTML = '<p class="text-muted">Error al cargar generador de códigos</p>';
        return;
    }
    
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
        console.error('Error generando código de barras:', e);
        container.innerHTML = '<p class="text-muted">No se pudo generar código de barras</p>';
    }
}

// ================== MANEJADOR DE CLICKS ==================
document.addEventListener('click', function(e) {
    const toggle = e.target.closest('.codigo-toggle');
    if (!toggle) return;
    
    e.preventDefault();
    
    try {
        const codigos = JSON.parse(toggle.dataset.codigos);
        const nombreProducto = toggle.dataset.producto || 'Producto';

        let html = `
            <div class="text-center mb-3">
                <strong style="font-size: 1.1rem;">${nombreProducto}</strong>
            </div>
        `;

        if (codigos.length === 1) {
            html += `
                <div class="codigo-barra-container">
                    <div id="barcode-container"></div>
                </div>
            `;
        }

        html += `<div class="codigos-grid">`;
        codigos.forEach(c => {
            html += `<div class="codigo-chip" data-codigo="${c}">${c}</div>`;
        });
        html += `</div>`;

        if (typeof Swal === 'undefined') {
            mostrarToast('✗ Error al cargar ventana modal', 'error');
            return;
        }
        
        Swal.fire({
            title: 'Códigos del producto',
            html: html,
            icon: 'info',
            customClass: {
                popup: 'swal-codigos'
            },
            showConfirmButton: false,
            showCloseButton: true,
            width: 500,
            didOpen: () => {
                if (codigos.length === 1) {
                    generarCodigoBarras('barcode-container', codigos[0]);
                }

                document.querySelectorAll('.codigo-chip').forEach(chip => {
                    chip.addEventListener('click', function(e) {
                        e.stopPropagation();
                        const codigo = this.dataset.codigo || this.textContent;
                        copiarAlPortapapeles(codigo);
                        
                        const originalText = this.textContent;
                        this.classList.add('copiado');
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
        console.error('Error al procesar códigos:', error);
        mostrarToast('✗ Error al mostrar los códigos', 'error');
    }
});
</script>

<?php include 'includes/footer.php'; ?>