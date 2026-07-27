<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/session.php';
require_once __DIR__ . '/includes/db.php';

/*
|--------------------------------------------------------------------------
| Validar acceso ANTES de imprimir HTML
|--------------------------------------------------------------------------
*/

$usuario_id_sesion = (int) ($_SESSION['usuario_id'] ?? 0);
$rol_actual = strtolower(trim((string) ($_SESSION['rol'] ?? '')));

$roles_administrativos = [
    'administrador',
    'super_administrador',
];

if ($usuario_id_sesion <= 0) {
    header('Location: login.php');
    exit;
}

if (!in_array($rol_actual, $roles_administrativos, true)) {
    header('Location: inventario_vendedor.php');
    exit;
}

/*
|--------------------------------------------------------------------------
| Cargar la interfaz después de validar
|--------------------------------------------------------------------------
*/

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/navbar.php';

/* ================= FUNCIONES ================= */

/**
 * Convierte cualquier valor de base de datos a número seguro.
 * Evita errores de PHP 8 cuando el valor es NULL, vacío o no numérico.
 *
 * @param mixed $valor
 */
function numeroSeguro($valor): float
{
    if ($valor === null || $valor === '' || !is_numeric($valor)) {
        return 0.0;
    }

    return (float) $valor;
}

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
            (COALESCE(p.precio_venta, 0) - COALESCE(p.precio_compra, 0)) AS utilidad,
            (SELECT COUNT(*) FROM codigos_barras cb WHERE cb.producto_id = p.id AND cb.disponible = 1) AS codigos_disponibles
        FROM productos p
        WHERE p.tipo_inventario = 'producto' 
        AND p.activo = 1
        ORDER BY p.nombre ASC
    ");
    $productos = [];
    while ($row = $result->fetch_assoc()) {
        $atributosRaw = isset($row['atributos'])
            ? trim((string) $row['atributos'])
            : '';

        $atributosDecodificados = $atributosRaw !== ''
            ? json_decode($atributosRaw, true)
            : [];

        $row['atributos_array'] = is_array($atributosDecodificados)
            ? $atributosDecodificados
            : [];

        $row['precio_compra'] = numeroSeguro($row['precio_compra'] ?? 0);
        $row['precio_venta'] = numeroSeguro($row['precio_venta'] ?? 0);
        $row['utilidad'] = numeroSeguro($row['utilidad'] ?? 0);
        $row['cantidad'] = numeroSeguro($row['cantidad'] ?? 0);
        $row['stock_especial'] = ((int)($row['stock_especial'] ?? 0) === 1) ? 1 : 0;

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
        $atributosRaw = isset($row['atributos'])
            ? trim((string) $row['atributos'])
            : '';

        $atributosDecodificados = $atributosRaw !== ''
            ? json_decode($atributosRaw, true)
            : [];

        $row['atributos_array'] = is_array($atributosDecodificados)
            ? $atributosDecodificados
            : [];

        $row['precio_compra'] = numeroSeguro($row['precio_compra'] ?? 0);
        $row['precio_venta'] = numeroSeguro($row['precio_venta'] ?? 0);
        $row['cantidad'] = numeroSeguro($row['cantidad'] ?? 0);

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

    $result = $conn->query("SELECT COUNT(*) AS total FROM productos WHERE activo = 1 AND tipo_inventario = 'producto'");
    $stats['total_productos'] = (int) numeroSeguro($result && ($row = $result->fetch_assoc()) ? ($row['total'] ?? 0) : 0);

    $result = $conn->query("SELECT COUNT(*) AS total FROM productos WHERE activo = 1 AND tipo_inventario = 'producto' AND COALESCE(stock_especial, 0) = 1");
    $stats['productos_especiales'] = (int) numeroSeguro($result && ($row = $result->fetch_assoc()) ? ($row['total'] ?? 0) : 0);

    $result = $conn->query("SELECT COUNT(*) AS total FROM productos WHERE activo = 1 AND tipo_inventario = 'insumo'");
    $stats['total_insumos'] = (int) numeroSeguro($result && ($row = $result->fetch_assoc()) ? ($row['total'] ?? 0) : 0);

    /*
     * Los productos especiales no participan en stock bajo ni agotados,
     * porque su disponibilidad depende únicamente de stock_especial = 1.
     * Los insumos continúan usando su cantidad normalmente.
     */
    $result = $conn->query("
        SELECT COUNT(*) AS total
        FROM productos
        WHERE activo = 1
          AND cantidad > 0
          AND cantidad <= 5
          AND (
              tipo_inventario = 'insumo'
              OR COALESCE(stock_especial, 0) = 0
          )
    ");
    $stats['stock_bajo'] = (int) numeroSeguro($result && ($row = $result->fetch_assoc()) ? ($row['total'] ?? 0) : 0);

    $result = $conn->query("
        SELECT COUNT(*) AS total
        FROM productos
        WHERE activo = 1
          AND cantidad <= 0
          AND (
              tipo_inventario = 'insumo'
              OR COALESCE(stock_especial, 0) = 0
          )
    ");
    $stats['sin_stock'] = (int) numeroSeguro($result && ($row = $result->fetch_assoc()) ? ($row['total'] ?? 0) : 0);

    $result = $conn->query("
        SELECT SUM(
            CASE
                WHEN tipo_inventario = 'producto' AND COALESCE(stock_especial, 0) = 1 THEN 0
                ELSE COALESCE(precio_venta, 0) * GREATEST(COALESCE(cantidad, 0), 0)
            END
        ) AS total
        FROM productos
        WHERE activo = 1
    ");
    $stats['valor_total'] = numeroSeguro($result && ($row = $result->fetch_assoc()) ? ($row['total'] ?? 0) : 0);

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

        <!-- ENCABEZADO CLÁSICO DEL INVENTARIO -->
        <div class="inventory-title-classic">
            <h1>
                <span class="inventory-title-classic-icon">
                    <i class="fas fa-boxes"></i>
                </span>
                Inventario
            </h1>
        </div>

        <!-- BREADCRUMB CLÁSICO -->
        <div class="custom-breadcrumb inventory-breadcrumb-classic">
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb">
                    <li class="breadcrumb-item">
                        <a href="<?= in_array($rol_actual, ['administrador', 'super_administrador'], true) ? 'dashboard_admin.php' : 'dashboard_vendedor.php' ?>">
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

        <!-- TARJETAS DE ESTADÍSTICAS CLÁSICAS -->
        <div class="row inventory-stats-classic">
            <div class="col-xl-3 col-md-6">
                <article class="inventory-stat-card stat-products">
                    <div class="inventory-stat-copy">
                        <strong><?= number_format(numeroSeguro($stats['total_productos'] ?? 0), 0) ?></strong>
                        <span>Productos</span>
                        <small>
                            <?= number_format(numeroSeguro($stats['productos_especiales'] ?? 0), 0) ?>
                            con disponibilidad ilimitada
                        </small>
                    </div>
                    <div class="inventory-stat-icon">
                        <i class="fas fa-box"></i>
                    </div>
                </article>
            </div>

            <div class="col-xl-3 col-md-6">
                <article class="inventory-stat-card stat-supplies">
                    <div class="inventory-stat-copy">
                        <strong><?= number_format(numeroSeguro($stats['total_insumos'] ?? 0), 0) ?></strong>
                        <span>Insumos</span>
                        <small>Materiales con cantidad controlada</small>
                    </div>
                    <div class="inventory-stat-icon">
                        <i class="fas fa-cubes"></i>
                    </div>
                </article>
            </div>

            <div class="col-xl-3 col-md-6">
                <article class="inventory-stat-card stat-warning">
                    <div class="inventory-stat-copy">
                        <strong><?= number_format(numeroSeguro($stats['stock_bajo'] ?? 0), 0) ?></strong>
                        <span>Stock bajo (≤5)</span>
                        <small>
                            <?= number_format(numeroSeguro($stats['sin_stock'] ?? 0), 0) ?>
                            artículos agotados
                        </small>
                    </div>
                    <div class="inventory-stat-icon">
                        <i class="fas fa-exclamation-triangle"></i>
                    </div>
                </article>
            </div>

            <div class="col-xl-3 col-md-6">
                <article class="inventory-stat-card stat-value">
                    <div class="inventory-stat-copy">
                        <strong>$<?= number_format(numeroSeguro($stats['valor_total'] ?? 0), 0) ?></strong>
                        <span>Valor inventario</span>
                        <small>Solo existencias controladas</small>
                    </div>
                    <div class="inventory-stat-icon">
                        <i class="fas fa-dollar-sign"></i>
                    </div>
                </article>
            </div>
        </div>

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
                <button class="filter-btn filter-btn-special" data-filter="especiales">
                    <i class="fas fa-infinity"></i> Especiales
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
        <div class="card inventory-section-card" id="productosCard">
            <div class="card-header">
                <div class="inventory-section-heading">
                    <div>
                        <h3 class="card-title">
                            <span class="section-heading-icon product"><i class="fas fa-box"></i></span>
                            Productos
                            <span class="section-count" id="productosCount"><?= count($todosProductos) ?></span>
                        </h3>
                    </div>
                </div>
            </div>
            <div class="card-body">
                <div class="row" id="productosGrid">
                    <?php foreach($todosProductos as $producto): 
                        $stock = numeroSeguro($producto['cantidad'] ?? 0);
                        $esEspecial = ((int)($producto['stock_especial'] ?? 0) === 1);

                        if ($esEspecial) {
                            $stockClass = 'special';
                            $stockStatus = 'Disponible siempre';
                            $stockPercent = 100;
                        } elseif($stock <= 0) {
                            $stockClass = 'critical';
                            $stockStatus = 'Agotado';
                        } elseif($stock <= 5) {
                            $stockClass = 'critical';
                            $stockStatus = 'Stock crítico';
                        } elseif($stock <= 15) {
                            $stockClass = 'low';
                            $stockStatus = 'Stock bajo';
                        } else {
                            $stockClass = 'normal';
                            $stockStatus = 'Disponible';
                        }

                        $stockPercent = $esEspecial ? 100 : min(100, max(0, ($stock / 50) * 100));
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
                        data-stock-especial="<?= $esEspecial ? 1 : 0 ?>"
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
                        // Insignia visual: la bandera stock_especial es la única fuente de verdad.
                        if ($esEspecial) {
                            $insigniaIcono = 'fa-infinity';
                            $insigniaTexto = 'ESPECIAL';
                        } elseif($stock <= 0) {
                            $insigniaIcono = 'fa-times-circle';
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
                        
                        <div class="product-card producto <?= $colorClase ?> <?= $esEspecial ? 'product-card-special' : '' ?>" style="--accent-color: <?= $esEspecial ? '#7c3aed' : $colorHex ?>;">
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
                                <div class="product-badge <?= $esEspecial ? 'especial' : 'producto' ?>">
                                    <i class="fas <?= $esEspecial ? 'fa-infinity' : $iconoCard ?>"></i>
                                    <span><?= $esEspecial ? 'Especial' : 'Producto' ?></span>
                                </div>
                                
                                <!-- Badge de estado de stock -->
                                <?php if($esEspecial || $stock <= 5): ?>
                                <div class="stock-warning-badge <?= $esEspecial ? 'special' : '' ?>">
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
                                    <span class="meta-badge categoria">
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
                                        <span class="price-value" style="color: #dc2626;">$<?= number_format(numeroSeguro($producto['precio_compra'] ?? 0), 0) ?></span>
                                    </div>
                                    <div class="price-item">
                                        <small><i class="fas fa-arrow-up"></i> Venta</small>
                                        <span class="price-value" style="color: #16a34a;">$<?= number_format(numeroSeguro($producto['precio_venta'] ?? 0), 0) ?></span>
                                    </div>
                                    <div class="price-item">
                                        <small><i class="fas fa-chart-line"></i> Utilidad</small>
                                        <span class="price-value utility-value">$<?= number_format(numeroSeguro($producto['utilidad'] ?? 0), 0) ?></span>
                                    </div>
                                </div>
                                
                                <div class="stock-info <?= $esEspecial ? 'stock-info-special' : '' ?>">
                                    <div class="stock-row">
                                        <span class="stock-status <?= $stockClass ?>">
                                            <i class="fas <?= $esEspecial ? 'fa-infinity' : ($stock <= 0 ? 'fa-times-circle' : ($stock <= 5 ? 'fa-exclamation-triangle' : 'fa-check-circle')) ?>"></i>
                                            <?= $stockStatus ?>
                                        </span>
                                        <span class="stock-number">
                                            <?= $esEspecial ? '<i class="fas fa-infinity"></i> Sin límite' : number_format(numeroSeguro($stock), 0) . ' unidades' ?>
                                        </span>
                                    </div>
                                    <div class="progress-bar-custom" aria-hidden="true">
                                        <div class="progress-fill <?= $stockClass ?>" style="width: <?= $stockPercent ?>%"></div>
                                    </div>
                                </div>

                                <div class="barcode-open-hint <?= empty($producto['codigos_array']) ? 'empty' : '' ?>">
                                    <i class="fas fa-barcode"></i>
                                    <span><?= empty($producto['codigos_array']) ? 'Sin código registrado' : 'Ver código de barras' ?></span>
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

                <div class="inventory-pagination" id="productosPagination" hidden>
                    <div class="inventory-pagination-info" id="productosPaginationInfo"></div>
                    <div class="inventory-pagination-controls" id="productosPaginationControls" aria-label="Paginación de productos"></div>
                </div>
            </div>
        </div>

        <!-- Insumos Section -->
        <div class="card inventory-section-card" id="insumosCard">
            <div class="card-header">
                <div class="inventory-section-heading">
                    <div>
                        <h3 class="card-title">
                            <span class="section-heading-icon supply"><i class="fas fa-cubes"></i></span>
                            Insumos y materiales
                            <span class="section-count supply" id="insumosCount"><?= count($todosInsumos) ?></span>
                        </h3>
                        <p>Materiales con existencias controladas y unidad de consumo.</p>
                    </div>
                </div>
            </div>
            <div class="card-body">
                <div class="row" id="insumosGrid">
                    <?php foreach($todosInsumos as $insumo): 
                        $stock = numeroSeguro($insumo['cantidad'] ?? 0);
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
                                        <span class="text-danger">$<?= number_format(numeroSeguro($insumo['precio_compra'] ?? 0), 2) ?></span>
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
                                        <span class="stock-number"><?= number_format(numeroSeguro($stock), 2) ?> <?= $unidad ?></span>
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

                <div class="inventory-pagination" id="insumosPagination" hidden>
                    <div class="inventory-pagination-info" id="insumosPaginationInfo"></div>
                    <div class="inventory-pagination-controls" id="insumosPaginationControls" aria-label="Paginación de insumos"></div>
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
    const productosPagination = document.getElementById('productosPagination');
    const productosPaginationInfo = document.getElementById('productosPaginationInfo');
    const productosPaginationControls = document.getElementById('productosPaginationControls');
    const insumosPagination = document.getElementById('insumosPagination');
    const insumosPaginationInfo = document.getElementById('insumosPaginationInfo');
    const insumosPaginationControls = document.getElementById('insumosPaginationControls');

    const estadoPaginacion = {
        productos: { pagina: 1 },
        insumos: { pagina: 1 }
    };

    let vistaActual = 'grid';
    
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
        const esEspecial = Number(item.dataset.stockEspecial || '0') === 1;
        const tipo = item.dataset.tipo === 'insumo'
            ? 'Insumo'
            : (esEspecial ? 'Producto especial' : 'Producto');
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
                    else if (valor === 'especiales') valorMostrar = 'Productos especiales';
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
                    aplicarFiltros(true);
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
        aplicarFiltros(true);
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
        const esEspecial = Number(item.dataset.stockEspecial || '0') === 1;
        
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
                case 'especiales':
                    visible = tipo === 'producto' && esEspecial;
                    break;
                case 'insumos':
                    visible = tipo === 'insumo';
                    break;
                case 'stockBajo':
                    visible = !esEspecial && stock > 0 && stock <= 5;
                    break;
                case 'sinStock':
                    visible = !esEspecial && stock <= 0;
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
    
    // Paginación real para productos e insumos.
    function obtenerElementosPorPagina() {
        if (window.innerWidth <= 575) return vistaActual === 'list' ? 5 : 6;
        if (window.innerWidth <= 991) return vistaActual === 'list' ? 6 : 8;
        return vistaActual === 'list' ? 8 : 12;
    }

    function reiniciarPaginacion() {
        estadoPaginacion.productos.pagina = 1;
        estadoPaginacion.insumos.pagina = 1;
    }

    function crearSecuenciaPaginas(paginaActual, totalPaginas) {
        if (totalPaginas <= 7) {
            return Array.from({ length: totalPaginas }, (_, index) => index + 1);
        }

        const paginas = [1];
        const inicio = Math.max(2, paginaActual - 1);
        const fin = Math.min(totalPaginas - 1, paginaActual + 1);

        if (inicio > 2) paginas.push('ellipsis-start');
        for (let pagina = inicio; pagina <= fin; pagina++) paginas.push(pagina);
        if (fin < totalPaginas - 1) paginas.push('ellipsis-end');

        paginas.push(totalPaginas);
        return paginas;
    }

    function renderizarPaginacion(tipo, itemsFiltrados) {
        const esProductos = tipo === 'productos';
        const todosItems = esProductos ? productos : insumos;
        const contenedor = esProductos ? productosPagination : insumosPagination;
        const info = esProductos ? productosPaginationInfo : insumosPaginationInfo;
        const controles = esProductos ? productosPaginationControls : insumosPaginationControls;
        const elementosPorPagina = obtenerElementosPorPagina();
        const totalItems = itemsFiltrados.length;
        const totalPaginas = Math.max(1, Math.ceil(totalItems / elementosPorPagina));
        const estado = estadoPaginacion[tipo];

        estado.pagina = Math.min(Math.max(1, estado.pagina), totalPaginas);

        todosItems.forEach(item => {
            item.style.display = 'none';
        });

        const inicio = (estado.pagina - 1) * elementosPorPagina;
        const fin = Math.min(inicio + elementosPorPagina, totalItems);

        itemsFiltrados.slice(inicio, fin).forEach(item => {
            item.style.display = '';
        });

        if (!contenedor || !info || !controles) return;

        if (totalItems === 0) {
            contenedor.hidden = true;
            info.textContent = '';
            controles.innerHTML = '';
            return;
        }

        contenedor.hidden = false;
        const etiqueta = esProductos ? 'productos' : 'insumos';
        info.innerHTML = `Mostrando <strong>${inicio + 1}–${fin}</strong> de <strong>${totalItems}</strong> ${etiqueta}`;

        const boton = (contenido, pagina, deshabilitado = false, activo = false, etiquetaAria = '') => `
            <button type="button"
                    class="inventory-page-btn${activo ? ' active' : ''}"
                    data-page="${pagina}"
                    ${deshabilitado ? 'disabled' : ''}
                    ${etiquetaAria ? `aria-label="${etiquetaAria}"` : ''}>
                ${contenido}
            </button>`;

        let html = boton(
            '<i class="fas fa-chevron-left"></i>',
            estado.pagina - 1,
            estado.pagina === 1,
            false,
            'Página anterior'
        );

        crearSecuenciaPaginas(estado.pagina, totalPaginas).forEach(valor => {
            if (typeof valor === 'string') {
                html += '<span class="inventory-page-ellipsis">…</span>';
                return;
            }

            html += boton(
                String(valor),
                valor,
                false,
                valor === estado.pagina,
                `Página ${valor}`
            );
        });

        html += boton(
            '<i class="fas fa-chevron-right"></i>',
            estado.pagina + 1,
            estado.pagina === totalPaginas,
            false,
            'Página siguiente'
        );

        controles.innerHTML = html;
        controles.querySelectorAll('.inventory-page-btn[data-page]').forEach(control => {
            control.addEventListener('click', function() {
                if (this.disabled) return;

                const nuevaPagina = Number(this.dataset.page || 1);
                if (!Number.isInteger(nuevaPagina) || nuevaPagina < 1 || nuevaPagina > totalPaginas) return;

                estado.pagina = nuevaPagina;
                renderizarPaginacion(tipo, itemsFiltrados);

                const card = esProductos ? productosCard : insumosCard;
                if (card) {
                    const top = card.getBoundingClientRect().top + window.pageYOffset - 85;
                    window.scrollTo({ top, behavior: 'smooth' });
                }
            });
        });
    }

    // Aplicar filtros y después mostrar únicamente la página correspondiente.
    function aplicarFiltros(reiniciar = false) {
        if (reiniciar) reiniciarPaginacion();

        const productosFiltrados = Array.from(productos).filter(evaluarItem);
        const insumosFiltrados = Array.from(insumos).filter(evaluarItem);

        if (productosCount) productosCount.textContent = productosFiltrados.length;
        if (insumosCount) insumosCount.textContent = insumosFiltrados.length;

        if (filtros.tipo === 'productos' || filtros.tipo === 'especiales') {
            if (productosCard) productosCard.style.display = 'block';
            if (insumosCard) insumosCard.style.display = 'none';
        } else if (filtros.tipo === 'insumos') {
            if (productosCard) productosCard.style.display = 'none';
            if (insumosCard) insumosCard.style.display = 'block';
        } else {
            if (productosCard) productosCard.style.display = 'block';
            if (insumosCard) insumosCard.style.display = 'block';
        }

        if (productosEmpty) {
            productosEmpty.style.display = productosFiltrados.length === 0 && filtros.tipo !== 'insumos'
                ? 'block'
                : 'none';
        }

        if (insumosEmpty) {
            insumosEmpty.style.display = insumosFiltrados.length === 0 && !['productos', 'especiales'].includes(filtros.tipo)
                ? 'block'
                : 'none';
        }

        renderizarPaginacion('productos', productosFiltrados);
        renderizarPaginacion('insumos', insumosFiltrados);

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
                aplicarFiltros(true);
            }, 300);
        });
    }
    
    // Limpiar búsqueda
    if (clearSearch) {
        clearSearch.addEventListener('click', function() {
            if (searchInput) searchInput.value = '';
            filtros.busqueda = '';
            this.style.display = 'none';
            aplicarFiltros(true);
            if (searchInput) searchInput.focus();
        });
    }
    
    // Botones de filtro por tipo
    filterBtns.forEach(btn => {
        btn.addEventListener('click', function() {
            filtros.tipo = this.dataset.filter;
            actualizarUIFiltros();
            aplicarFiltros(true);
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
            aplicarFiltros(true);
        });
    });
    
    // Toggle panel de filtros
    if (toggleFiltersBtn && filtersPanel) {
        toggleFiltersBtn.addEventListener('click', () => {
            filtersPanel.classList.toggle('show');
        });
    }
    
    // Cambiar vista y volver a paginar con el tamaño adecuado.
    viewBtns.forEach(btn => {
        btn.addEventListener('click', function() {
            viewBtns.forEach(b => b.classList.remove('active'));
            this.classList.add('active');

            vistaActual = this.dataset.view === 'list' ? 'list' : 'grid';
            const usarLista = vistaActual === 'list';

            if (productosGrid) productosGrid.classList.toggle('list-view', usarLista);
            if (insumosGrid) insumosGrid.classList.toggle('list-view', usarLista);

            aplicarFiltros(true);
        });
    });
    
    // Limpiar todos los filtros
    if (clearAllFilters) {
        clearAllFilters.addEventListener('click', limpiarTodosFiltros);
    }
    
    let resizeInventarioTimer = null;
    window.addEventListener('resize', function() {
        clearTimeout(resizeInventarioTimer);
        resizeInventarioTimer = setTimeout(() => aplicarFiltros(false), 180);
    });

    // Inicializar
    actualizarUIFiltros();
    aplicarFiltros(true);
});
</script>

<?php include('includes/footer.php'); ?>