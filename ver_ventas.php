<?php
include 'includes/db.php';
include('includes/header.php');
include('includes/navbar.php');

// --- Filtros (proveedor y fechas) ---
$filtroProveedor = $_GET['proveedor'] ?? '';
$filtroInicio = $_GET['fecha_inicio'] ?? '';
$filtroFin = $_GET['fecha_fin'] ?? '';

// ============================================
// CONSULTA PRINCIPAL - AGRUPADA POR PROVEEDOR Y PRODUCTO
// EXCLUYE EL PRODUCTO ESPECIAL DE LA DEUDA
// ============================================

// Consulta para obtener ventas AGRUPADAS por proveedor y producto
// SOLO PRODUCTOS (excluir insumos)
$sqlVentasAgrupadas = "
SELECT 
    p.proveedor,
    p.nombre AS producto,
    p.cantidad AS stock_actual,
    p.precio_compra,
    p.precio_venta,
    p.tipo_adquisicion,
    SUM(v.cantidad_vendida) AS total_vendido,
    COUNT(v.id) AS numero_ventas,
    
    -- Deuda: es 0 si el producto es PAGADO, de lo contrario precio_compra * vendidos
    CASE 
        WHEN p.tipo_adquisicion = 'pagado' THEN 0
        ELSE (p.precio_compra * SUM(v.cantidad_vendida))
    END AS deuda_total,
    
    -- Venta total (siempre se calcula normal)
    (p.precio_venta * SUM(v.cantidad_vendida)) AS venta_total,
    
    -- Ganancia total (venta - compra, normal para todos)
    ((p.precio_venta - p.precio_compra) * SUM(v.cantidad_vendida)) AS ganancia_total,
    
    -- Indicador de producto pagado
    CASE 
        WHEN p.tipo_adquisicion = 'pagado' THEN 1
        ELSE 0
    END AS es_producto_pagado
    
FROM ventas v
INNER JOIN productos p ON v.id_producto = p.id
WHERE p.tipo_inventario = 'producto'  /* EXCLUIR INSUMOS */
";

if ($filtroProveedor !== '') {
    $sqlVentasAgrupadas .= " AND p.proveedor = '" . $conn->real_escape_string($filtroProveedor) . "'";
}

if ($filtroInicio !== '' && $filtroFin !== '') {
    $sqlVentasAgrupadas .= " AND DATE(v.fecha_venta) BETWEEN '" . $conn->real_escape_string($filtroInicio) . "' 
    AND '" . $conn->real_escape_string($filtroFin) . "'";
}

// AGRUPAR por proveedor y producto
$sqlVentasAgrupadas .= " GROUP BY p.proveedor, p.nombre, p.cantidad, p.precio_compra, p.precio_venta";
$sqlVentasAgrupadas .= " ORDER BY p.proveedor ASC, p.nombre ASC";

$resultadoVentasAgrupadas = $conn->query($sqlVentasAgrupadas);
$ventasAgrupadas = [];
$deudaPorProveedor = [];
$totalesGlobales = [
    'total_ventas' => 0,
    'total_deuda' => 0,
    'total_ganancia' => 0
];

if ($resultadoVentasAgrupadas) {
    while ($row = $resultadoVentasAgrupadas->fetch_assoc()) {
        $ventasAgrupadas[] = $row;
        $prov = $row['proveedor'];
        
        // Solo acumular deuda si NO es producto pagado
        if (!$row['es_producto_pagado']) {
            // Acumular deuda por proveedor
            if (!isset($deudaPorProveedor[$prov])) {
                $deudaPorProveedor[$prov] = 0;
            }
            $deudaPorProveedor[$prov] += $row['deuda_total'];
            
            // Acumular deuda total global
            $totalesGlobales['total_deuda'] += $row['deuda_total'];
        }

        // Las ventas y ganancias siempre se acumulan (para todos los productos)
        $totalesGlobales['total_ventas'] += $row['venta_total'];
        $totalesGlobales['total_ganancia'] += $row['ganancia_total'];
    }
}

// Obtener todos los productos (para stock, incluso sin ventas) - SOLO PRODUCTOS
$sqlProductosConStock = "
SELECT 
    proveedor,
    nombre,
    cantidad AS stock_actual,
    precio_compra,
    precio_venta,
    tipo_adquisicion,
    CASE 
        WHEN tipo_adquisicion = 'pagado' THEN 1
        ELSE 0
    END AS es_producto_pagado
FROM productos
WHERE activo = 1 
AND tipo_inventario = 'producto'  /* EXCLUIR INSUMOS */
";

if ($filtroProveedor !== '') {
    $sqlProductosConStock .= " AND proveedor = '" . $conn->real_escape_string($filtroProveedor) . "'";
}

$sqlProductosConStock .= " ORDER BY proveedor ASC, nombre ASC";
$resultadoProductos = $conn->query($sqlProductosConStock);
$todosProductos = [];
if ($resultadoProductos) {
    while ($row = $resultadoProductos->fetch_assoc()) {
        $todosProductos[] = $row;
    }
}

// Para compatibilidad con el código existente
$totalVentas = $totalesGlobales['total_ventas'];
$totalProveedor = $totalesGlobales['total_deuda']; // AHORA esto NO incluye el producto especial
$totalGanancia = $totalesGlobales['total_ganancia']; // Esto SÍ incluye ganancia del producto especial

// --- CONSULTA PARA PRODUCTOS CON VENTAS (para la vista) ---
$sql = "
SELECT 
    p.id,
    p.nombre,
    p.proveedor,
    p.precio_compra,
    p.precio_venta,
    p.cantidad,
    p.tipo_adquisicion,
    CASE 
        WHEN p.tipo_adquisicion = 'pagado' THEN 1
        ELSE 0
    END AS es_producto_pagado,
    IFNULL((
        SELECT SUM(v.cantidad_vendida)
        FROM ventas v
        WHERE v.id_producto = p.id
";

if ($filtroInicio !== '' && $filtroFin !== '') {
    $sql .= " AND DATE(v.fecha_venta) BETWEEN '" . $conn->real_escape_string($filtroInicio) . "' 
    AND '" . $conn->real_escape_string($filtroFin) . "'";
}

$sql .= "
    ), 0) AS total_vendida
FROM productos p
WHERE p.tipo_inventario = 'producto'
";

if ($filtroProveedor !== '') {
    $sql .= " AND p.proveedor = '" . $conn->real_escape_string($filtroProveedor) . "'";
}

$sql .= " HAVING total_vendida > 0 ORDER BY p.nombre ASC";

$resultado = $conn->query($sql);

// --- Variables para la vista ---
$totalGanancia = $totalProveedor = $totalVendidos = $totalStock = $totalVentas = 0;
$productos = [];

if ($resultado && $resultado->num_rows > 0) {
    while ($row = $resultado->fetch_assoc()) {
        $vendidos = (int)$row['total_vendida'];
        $stock = (int)$row['cantidad'];

        $ganancia = ($row['precio_venta'] - $row['precio_compra']) * $vendidos;
        
        // Para la deuda, si es producto pagado, la deuda es 0
        if ($row['es_producto_pagado']) {
            $costoProveedor = 0;
        } else {
            $costoProveedor = $row['precio_compra'] * $vendidos;
        }
        
        $ventaTotal = $row['precio_venta'] * $vendidos;

        $totalGanancia += $ganancia;
        $totalProveedor += $costoProveedor;
        $totalVendidos += $vendidos;
        $totalStock += $stock;
        $totalVentas += $ventaTotal;

        $productos[] = [
            'nombre' => $row['nombre'],
            'proveedor' => $row['proveedor'],
            'vendidos' => $vendidos,
            'stock' => $stock,
            'precio_compra' => $row['precio_compra'],
            'precio_venta' => $row['precio_venta'],
            'ganancia' => $ganancia,
            'es_pagado' => $row['es_producto_pagado']
        ];
    }
} else {
    // No hay productos con ventas, pero mantenemos arrays vacíos
    $productos = [];
}

// Proveedores para filtro
$listaProveedores = $conn->query("SELECT DISTINCT proveedor FROM productos WHERE proveedor != '' ORDER BY proveedor");

// Obtener todas las fechas con ventas para el calendario
$sqlFechasVentas = "
    SELECT DISTINCT DATE(v.fecha_venta) as fecha
    FROM ventas v
    ORDER BY fecha DESC
";
$resultadoFechas = $conn->query($sqlFechasVentas);
$fechasVentas = [];
if ($resultadoFechas) {
    while ($row = $resultadoFechas->fetch_assoc()) {
        $fechasVentas[] = $row['fecha'];
    }
}

// Calcular total de productos vendidos (solo de ventas agrupadas)
$totalVendidosCorrecto = array_sum(array_column($ventasAgrupadas, 'total_vendido'));

// Calcular stock total (de TODOS los productos activos)
$totalStockCorrecto = array_sum(array_column($todosProductos, 'stock_actual'));

// Calcular número total de productos diferentes (activos)
$totalProductosDiferentes = count($todosProductos);

// Calcular valor del inventario (precio_venta * stock_actual) de TODOS los productos
$valorInventarioTotal = 0;
foreach ($todosProductos as $producto) {
    $valorInventarioTotal += $producto['precio_venta'] * $producto['stock_actual'];
}

// Verificar si hay producto especial para mostrar mensajes
$hayProductoEspecial = false;
foreach ($ventasAgrupadas as $v) {
    if ($v['es_producto_pagado']) {
        $hayProductoEspecial = true;
        break;
    }
}

// Calcular si hay datos para mostrar
$hayDatosTablaProductos = count($productos) > 0;
$hayDatosTablaDeuda = count($ventasAgrupadas) > 0;
?>

<link rel="stylesheet" href="css/ver_ventas.css">

<div class="content-wrapper">

    <!-- HEADER -->
    <section class="content-header">
        <div class="container-fluid">
            <div class="row align-items-center">

                <!-- TITULO -->
                <div class="col-12 col-md-6 mb-2 mb-md-0">
                    <h1 class="mb-0 font-weight-bold">
                        <i class="fas fa-chart-line mr-2" style="color: #f97316;"></i>
                        Reporte de Ventas
                    </h1>
                    <small class="text-muted">
                        Resumen financiero y desempeño comercial
                    </small>
                </div>

                <!-- BOTONES -->
                <div class="col-12 col-md-6 text-md-right text-left">
                    
                    <!-- CONTENEDOR DEL BOTÓN PDF CON DROPDOWN -->
                    <div class="pdf-dropdown-container" id="pdfDropdownContainer">
                        <button id="btnPDF" class="btn btn-danger btn-sm shadow-sm">
                            <i class="fas fa-file-pdf mr-1"></i> Exportar PDF
                            <i class="fas fa-chevron-down ml-1" style="font-size: 12px;"></i>
                        </button>
                        
                        <!-- DROPDOWN MENU -->
                        <div class="pdf-dropdown-menu" id="pdfDropdown">
                            <div class="pdf-dropdown-header">
                                <h3><i class="fas fa-file-export text-primary mr-2"></i>Exportar Reporte</h3>
                                <button class="close-btn" id="closeDropdownBtn">&times;</button>
                            </div>
                            <div class="pdf-dropdown-body">
                                <div class="pdf-dropdown-options">
                                    <button class="pdf-dropdown-btn btn-proveedor" id="pdfProveedor">
                                        <span class="btn-icon">
                                            <i class="fas fa-truck text-danger"></i>
                                        </span>
                                        <span class="btn-text">
                                            <strong>Reporte por Proveedor</strong>
                                            <small>Deuda detallada por proveedor</small>
                                        </span>
                                    </button>

                                    <button class="pdf-dropdown-btn btn-general" id="pdfGeneral">
                                        <span class="btn-icon">
                                            <i class="fas fa-chart-line text-success"></i>
                                        </span>
                                        <span class="btn-text">
                                            <strong>Reporte General</strong>
                                            <small>Ventas y ganancias completas</small>
                                        </span>
                                    </button>

                                    <button class="pdf-dropdown-btn btn-ambos" id="pdfAmbos">
                                        <span class="btn-icon">
                                            <i class="fas fa-layer-group text-primary"></i>
                                        </span>
                                        <span class="btn-text">
                                            <strong>Generar Ambos</strong>
                                            <small>Reporte general y de proveedores</small>
                                        </span>
                                    </button>
                                </div>
                            </div>
                            <div class="pdf-dropdown-footer">
                                <i class="fas fa-info-circle mr-1"></i> Los PDFs se descargarán automáticamente
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Overlay para cerrar dropdown al hacer clic fuera -->
                <div class="dropdown-overlay" id="dropdownOverlay"></div>

            </div>
        </div>
    </section>

    <!-- MAIN -->
    <section class="content">
        <div class="container-fluid">

            <!-- KPIs con información CORREGIDA -->
            <div class="row">
                <div class="col-12 col-md-6 col-lg-3 mb-3 d-flex">
                    <div class="small-box bg-info d-flex flex-column w-100">
                        <div class="inner flex-grow-1">
                            <h3><?= number_format($totalVendidosCorrecto) ?></h3>
                            <p class="mb-0">Productos vendidos</p>
                            <small><?= $totalProductosDiferentes ?> productos en inventario</small>
                            <div class="mini-progress mt-2">
                                <?php $porcentajeVendido = $totalStockCorrecto > 0 ? ($totalVendidosCorrecto / $totalStockCorrecto) * 100 : 0; ?>
                                <div class="mini-progress-bar" style="width: <?= min(100, $porcentajeVendido) ?>%"></div>
                            </div>
                        </div>
                        <div class="icon"><i class="fas fa-box"></i></div>
                        <div class="small-box-footer mt-auto">
                            <i class="fas fa-chart-bar mr-1"></i> <?= number_format($porcentajeVendido, 1) ?>% del inventario vendido
                        </div>
                    </div>
                </div>

                <div class="col-12 col-md-6 col-lg-3 mb-3 d-flex">
                    <div class="small-box bg-danger d-flex flex-column w-100">
                        <div class="inner flex-grow-1">
                            <h3>$<?= number_format($totalProveedor, 2) ?></h3>
                            <p class="mb-0">Deuda con proveedores</p>
                            <small>
                                Costo de productos vendidos
                                <?php if ($hayProductoEspecial): ?>
                                <br><span class="text-warning"><i class="fas fa-check-circle"></i> (Excluye libretas pagadas)</span>
                                <?php endif; ?>
                            </small>
                            <div class="mini-progress mt-2">
                                <?php $porcentajeCosto = ($totalGanancia + $totalProveedor) > 0 ? ($totalProveedor / ($totalGanancia + $totalProveedor)) * 100 : 0; ?>
                                <div class="mini-progress-bar" style="width: <?= $porcentajeCosto ?>%"></div>
                            </div>
                        </div>
                        <div class="icon"><i class="fas fa-hand-holding-usd"></i></div>
                        <div class="small-box-footer mt-auto">
                            <i class="fas fa-percentage mr-1"></i> <?= number_format($porcentajeCosto, 1) ?>% del costo total
                        </div>
                    </div>
                </div>

                <div class="col-12 col-md-6 col-lg-3 mb-3 d-flex">
                    <div class="small-box bg-success d-flex flex-column w-100">
                        <div class="inner flex-grow-1">
                            <h3>$<?= number_format($totalGanancia, 2) ?></h3>
                            <p class="mb-0">Ganancia neta</p>
                            <small>
                                Margen: <?= $totalProveedor > 0 ? number_format(($totalGanancia / $totalProveedor) * 100, 1) : 0 ?>%
                                <?php if ($hayProductoEspecial): ?>
                                <br><span class="text-light"><i class="fas fa-check-circle"></i> Incluye libretas pagadas</span>
                                <?php endif; ?>
                            </small>
                            <div class="mini-progress mt-2">
                                <?php $porcentajeGanancia = ($totalGanancia + $totalProveedor) > 0 ? ($totalGanancia / ($totalGanancia + $totalProveedor)) * 100 : 0; ?>
                                <div class="mini-progress-bar" style="width: <?= $porcentajeGanancia ?>%"></div>
                            </div>
                        </div>
                        <div class="icon"><i class="fas fa-chart-line"></i></div>
                        <div class="small-box-footer mt-auto">
                            <i class="fas fa-arrow-up mr-1"></i> Rentabilidad
                        </div>
                    </div>
                </div>

                <div class="col-12 col-md-6 col-lg-3 mb-3 d-flex">
                    <div class="small-box bg-warning d-flex flex-column w-100">
                        <div class="inner flex-grow-1">
                            <h3><?= number_format($totalStockCorrecto) ?></h3>
                            <p class="mb-0">Stock restante total</p>
                            <small>
                                Valor: $<?= number_format($valorInventarioTotal, 2) ?> | 
                                <?= $totalProductosDiferentes ?> productos
                            </small>
                            <div class="mini-progress mt-2">
                                <?php $porcentajeStock = $totalStockCorrecto > 0 ? (($totalStockCorrecto - $totalVendidosCorrecto) / $totalStockCorrecto) * 100 : 0; ?>
                                <div class="mini-progress-bar" style="width: <?= $porcentajeStock ?>%"></div>
                            </div>
                        </div>
                        <div class="icon"><i class="fas fa-warehouse"></i></div>
                        <div class="small-box-footer mt-auto">
                            <i class="fas fa-boxes mr-1"></i> Invetario actual
                        </div>
                    </div>
                </div>
            </div>
           
            <!-- FILTROS EN TIEMPO REAL -->
            <div class="card card-outline card-primary shadow-sm mb-4">
                <div class="card-header">
                    <h3 class="card-title font-weight-bold">
                        <i class="fas fa-filter mr-2"></i>Filtros de búsqueda
                    </h3>
                </div>

                <div class="card-body">
                    <div class="row">
                        <div class="col-12 col-md-4 mb-2">
                            <label class="text-muted">Proveedor</label>
                            <select name="proveedor" id="filtroProveedor" class="form-control">
                                <option value="">Todos los proveedores</option>
                                <?php while ($p = $listaProveedores->fetch_assoc()): ?>
                                    <option value="<?= htmlspecialchars($p['proveedor']) ?>" <?= $filtroProveedor == $p['proveedor'] ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($p['proveedor']) ?>
                                    </option>
                                <?php endwhile; ?>
                            </select>
                        </div>

                        <div class="col-6 col-md-3 mb-2">
                            <label class="text-muted">Fecha inicio</label>
                            <input type="date" name="fecha_inicio" id="filtroFechaInicio" value="<?= htmlspecialchars($filtroInicio) ?>" class="form-control">
                        </div>

                        <div class="col-6 col-md-3 mb-2">
                            <label class="text-muted">Fecha fin</label>
                            <input type="date" name="fecha_fin" id="filtroFechaFin" value="<?= htmlspecialchars($filtroFin) ?>" class="form-control">
                        </div>

                        <div class="col-12 col-md-2">
                            <div class="form-group">
                                <label class="text-muted">&nbsp;</label>
                                <div class="d-flex gap-2">
                                    <a href="?" id="limpiarFiltrosBtn" class="btn btn-outline-secondary btn-block">
                                        <i class="fas fa-eraser mr-1"></i> Limpiar
                                    </a>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- TABLA PRODUCTOS CON PAGINACIÓN Y ORDENAMIENTO -->
            <div class="card card-outline card-warning shadow-sm mt-4">
                <div class="card-header d-flex flex-column flex-md-row align-items-md-center">
                    <h3 class="card-title font-weight-bold mb-2 mb-md-0">
                        <i class="fas fa-boxes mr-2"></i>Productos y Ventas
                    </h3>
                    <div class="ml-md-auto">
                        <span class="badge badge-warning p-2 mr-2">
                            Total ganancias: $<?= number_format(array_sum(array_column($productos, 'ganancia')), 2) ?>
                        </span>
                        <?php if ($hayProductoEspecial): ?>
                        <span class="badge badge-success p-2">
                            <i class="fas fa-check-circle"></i> Incluye productos pagados
                        </span>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="card-body table-responsive p-0">
                    <table class="table table-hover table-sm mb-0" id="tablaProductos">
                        <thead class="thead-dark">
                            <tr>
                                <th>Producto</th>
                                <th>Proveedor</th>
                                <th>Vendidos</th>
                                <th>Stock Restante</th>
                                <th>Compra</th>
                                <th>Venta</th>
                                <th>Ganancia</th>
                                <th>Adquisición</th>
                            </tr>
                        </thead>
                        <tbody id="tablaProductosBody">
                            <?php if (empty($productos)): ?>
                            <tr>
                                <td colspan="8" class="text-center py-5">
                                    <div class="no-data-message" style="margin: 0;">
                                        <i class="fas fa-chart-line" style="font-size: 4rem; color: #dee2e6;"></i>
                                        <p class="mt-3 mb-0">No hay productos con ventas en el período seleccionado</p>
                                        <small class="text-muted">Prueba con otro proveedor o rango de fechas</small>
                                    </div>
                                </td>
                            </tr>
                            <?php else: ?>
                                <?php foreach ($productos as $p): ?>
                                <tr class="<?= $p['es_pagado'] ? 'table-success' : '' ?>">
                                    <td>
                                        <?= htmlspecialchars($p['nombre']) ?>
                                        <?php if ($p['es_pagado']): ?>
                                            <span class="badge badge-success ml-1"><i class="fas fa-check-circle"></i> Pagado</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?= htmlspecialchars($p['proveedor']) ?></td>
                                    <td><?= number_format($p['vendidos']) ?></td>
                                    <td>
                                        <span class="badge <?= $p['stock'] <= 0 ? 'badge-danger' : ($p['stock'] <= 5 ? 'badge-warning' : 'badge-success') ?>">
                                            <?= number_format($p['stock']) ?>
                                        </span>
                                    </td>
                                    <td>$<?= number_format($p['precio_compra'], 2) ?></td>
                                    <td>$<?= number_format($p['precio_venta'], 2) ?></td>
                                    <td class="font-weight-bold text-success">$<?= number_format($p['ganancia'], 2) ?></td>
                                    <td>
                                        <?php if ($p['es_pagado']): ?>
                                            <span class="badge-adquisicion badge-pagado">
                                                <i class="fas fa-check-circle"></i> Pagado
                                            </span>
                                        <?php else: ?>
                                            <span class="badge-adquisicion badge-concesion">
                                                <i class="fas fa-handshake"></i> Concesión
                                            </span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
                <?php if (!empty($productos)): ?>
                <div class="card-body">
                    <div class="pagination-controls">
                        <div class="records-per-page">
                            <label>Mostrar:</label>
                            <select id="productosPorPagina">
                                <option value="5">5</option>
                                <option value="10" selected>10</option>
                                <option value="25">25</option>
                                <option value="50">50</option>
                            </select>
                        </div>
                        <div id="paginacionProductos"></div>
                    </div>
                    <div class="pagination-info" id="productosInfo">
                        Mostrando <span id="productosDesde">0</span> a <span id="productosHasta">0</span> de <span id="productosTotal"><?= count($productos) ?></span> productos
                    </div>
                </div>
                <?php endif; ?>
            </div>

            <!-- DEUDA CON PROVEEDORES CON PAGINACIÓN Y ORDENAMIENTO -->
            <div class="card card-outline card-danger shadow-sm mt-4">
                <div class="card-header d-flex flex-column flex-md-row align-items-md-center">
                    <h3 class="card-title font-weight-bold mb-2 mb-md-0">
                        <i class="fas fa-hand-holding-usd mr-2"></i>
                        Deuda con Proveedores
                    </h3>
                    <span class="badge badge-danger p-2 ml-md-auto">
                        Total adeudo: $<?= number_format($totalProveedor, 2) ?>
                    </span>
                </div>
                <div class="card-body">
                    <!-- ALERTA PARA PRODUCTOS PAGADOS -->
                    <?php if ($hayProductoEspecial): ?>
                    <div class="alert alert-success alert-dismissible fade show" id="alertaProductoEspecial" style="display: none;">
                        <button type="button" class="close" onclick="cerrarAlertaProductoEspecial()" aria-label="Close">
                            <span aria-hidden="true">&times;</span>
                        </button>
                        <h5><i class="icon fas fa-check-circle"></i> Productos pagados por adelantado</h5>
                        <p>
                            Los productos con tipo de adquisición <strong>PAGADO</strong> están excluidos de esta deuda.
                            Sin embargo, su ganancia SÍ está incluida en el reporte de ventas.
                        </p>
                    </div>
                    <div class="text-center mb-3" id="btnMostrarAlertaEspecial">
                        <button type="button" class="btn btn-sm btn-outline-success" id="mostrarAlertaBtn">
                            <i class="fas fa-info-circle mr-1"></i> Ver información sobre productos pagados
                        </button>
                    </div>
                    <?php endif; ?>
                    
                    <div class="table-responsive p-0">
                        <table class="table table-hover table-sm mb-0" id="tablaDeuda">
                            <thead class="thead-dark">
                                <tr>
                                    <th>Producto</th>
                                    <th>Proveedor</th>
                                    <th>Vendidos</th>
                                    <th>Costo unitario</th>
                                    <th>Deuda total</th>
                                    <th>Adquisición</th>
                                </tr>
                            </thead>
                            <tbody id="tablaDeudaBody">
                                <?php if (empty($ventasAgrupadas)): ?>
                                <tr>
                                    <td colspan="6" class="text-center py-5">
                                        <div class="no-data-message" style="margin: 0;">
                                            <i class="fas fa-hand-holding-usd" style="font-size: 4rem; color: #dee2e6;"></i>
                                            <p class="mt-3 mb-0">No hay deudas registradas en el período seleccionado</p>
                                            <small class="text-muted">Prueba con otro proveedor o rango de fechas</small>
                                        </div>
                                    </td>
                                </tr>
                                <?php else: ?>
                                    <?php foreach ($ventasAgrupadas as $v): ?>
                                    <tr class="<?= $v['es_producto_pagado'] ? 'table-success' : '' ?>">
                                        <td><?= htmlspecialchars($v['producto']) ?></td>
                                        <td><?= htmlspecialchars($v['proveedor']) ?></td>
                                        <td><?= number_format($v['total_vendido']) ?></td>
                                        <td>$<?= number_format($v['precio_compra'], 2) ?></td>
                                        <td class="font-weight-bold <?= $v['es_producto_pagado'] ? 'text-success' : 'text-danger' ?>">
                                            <?php if ($v['es_producto_pagado']): ?>
                                                <span class="badge-adquisicion badge-pagado">
                                                    <i class="fas fa-check-circle"></i> PAGADO
                                                </span>
                                            <?php else: ?>
                                                $<?= number_format($v['deuda_total'], 2) ?>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if ($v['es_producto_pagado']): ?>
                                                <span class="badge-adquisicion badge-pagado">
                                                    <i class="fas fa-check-circle"></i> Pagado
                                                </span>
                                            <?php else: ?>
                                                <span class="badge-adquisicion badge-concesion">
                                                    <i class="fas fa-handshake"></i> Concesión
                                                </span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php if (!empty($ventasAgrupadas)): ?>
                    <div class="pagination-controls">
                        <div class="records-per-page">
                            <label>Mostrar:</label>
                            <select id="deudaPorPagina">
                                <option value="5">5</option>
                                <option value="10" selected>10</option>
                                <option value="25">25</option>
                                <option value="50">50</option>
                            </select>
                        </div>
                        <div id="paginacionDeuda"></div>
                    </div>
                    <div class="pagination-info" id="deudaInfo">
                        Mostrando <span id="deudaDesde">0</span> a <span id="deudaHasta">0</span> de <span id="deudaTotal"><?= count($ventasAgrupadas) ?></span> registros
                    </div>
                    <?php else: ?>
                    <div class="card-footer text-right">
                        <small class="text-muted">
                            <i class="fas fa-info-circle mr-1"></i>
                            Este monto representa el total pendiente de pago a proveedores.
                            Los productos con tipo de adquisición <strong>PAGADO</strong> están excluidos.
                        </small>
                    </div>
                    <?php endif; ?>
                </div>
                <?php if (!empty($ventasAgrupadas)): ?>
                <div class="card-footer text-right">
                    <small class="text-muted">
                        <i class="fas fa-info-circle mr-1"></i>
                        Este monto representa el total pendiente de pago a proveedores.
                        Los productos con tipo de adquisición <strong>PAGADO</strong> están excluidos.
                    </small>
                </div>
                <?php endif; ?>
            </div>

            <!-- CALENDARIO DE ACTIVIDAD MEJORADO -->
            <div class="card card-outline card-info shadow-sm mt-4">
                <div class="card-header">
                    <h3 class="card-title font-weight-bold">
                        <i class="fas fa-calendar-alt mr-2"></i>
                        Calendario de Actividad
                    </h3>
                    <div class="card-tools">
                        <button type="button" class="btn btn-tool" id="limpiarHistorialBtn" title="Limpiar historial de reportes">
                            <i class="fas fa-trash-alt"></i>
                        </button>
                    </div>
                </div>
                <div class="card-body">
                    <div class="row">
                        <!-- Calendario - 8 columnas para más espacio -->
                        <div class="col-lg-8 col-md-12">
                            <div class="calendar-container">
                                <div id="calendario"></div>
                            </div>
                        </div>
                        
                        <!-- Panel lateral - 4 columnas compacto SIN ESPACIO EN BLANCO -->
                        <div class="col-lg-4 col-md-12">
                            <div class="calendar-sidebar">
                                <!-- Leyenda -->
                                <div class="info-box">
                                    <div class="info-box-content w-100">
                                        <h6 class="mb-3"><i class="fas fa-chart-simple mr-2 text-info"></i>Leyenda</h6>
                                        <div class="legend-item">
                                            <div class="legend-color venta"></div>
                                            <span><strong>Ventas</strong> - Días con ventas</span>
                                        </div>
                                        <div class="legend-item">
                                            <div class="legend-color reporte"></div>
                                            <span><strong>Reportes</strong> - Días con reportes</span>
                                        </div>
                                        <div class="legend-item">
                                            <div class="legend-color ambos"></div>
                                            <span><strong>Ambos</strong> - Ventas y reportes</span>
                                        </div>
                                    </div>
                                </div>
                                
                                <!-- Estadísticas -->
                                <div class="info-box">
                                    <div class="info-box-content w-100">
                                        <h6 class="mb-3"><i class="fas fa-chart-bar mr-2 text-info"></i>Estadísticas</h6>
                                        <div class="row">
                                            <div class="col-4">
                                                <div class="stats-card">
                                                    <p class="stats-number text-success" id="diasVentas">0</p>
                                                    <p class="stats-label">Ventas</p>
                                                </div>
                                            </div>
                                            <div class="col-4">
                                                <div class="stats-card">
                                                    <p class="stats-number text-danger" id="diasReportes">0</p>
                                                    <p class="stats-label">Reportes</p>
                                                </div>
                                            </div>
                                            <div class="col-4">
                                                <div class="stats-card">
                                                    <p class="stats-number text-primary" id="diasActivos">0</p>
                                                    <p class="stats-label">Dias activos</p>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                
                                <!-- Info adicional - esta caja se estirará para ocupar el espacio restante -->
                                <div class="info-box" style="flex: 1;">
                                    <div class="info-box-content w-100">
                                        <small class="text-muted">
                                            <center>
                                            <i class="fas fa-info-circle mr-1"></i>
                                            Los días con reportes se guardan automáticamente al generar un PDF.
                                            </center>
                                        </small>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- GRAFICA CON MÁS INFORMACIÓN -->
            <?php if (!empty($productos) && ($totalGanancia > 0 || $totalProveedor > 0)): ?>
            <div class="card card-outline card-info shadow-sm mt-4">
                <div class="card-header">
                    <h3 class="card-title font-weight-bold">
                        <i class="fas fa-chart-pie mr-2"></i>Distribución financiera
                    </h3>
                </div>

                <div class="card-body">
                    <div class="row">
                        <div class="col-md-7">
                            <div style="position: relative; height: 300px;">
                                <canvas id="graficaVentas"></canvas>
                            </div>
                        </div>
                        <div class="col-md-5">
                            <div class="financial-summary">
                                <h5 class="mb-3"><i class="fas fa-calculator mr-2"></i>Resumen Financiero</h5>
                                <table class="table table-sm">
                                    <tr>
                                        <td class="label">Ingresos totales:</td>
                                        <td class="value text-primary">$<?= number_format($totalGanancia + $totalProveedor, 2) ?></td>
                                    </tr>
                                    <tr>
                                        <td class="label">Costo de ventas:</td>
                                        <td class="value text-danger">$<?= number_format($totalProveedor, 2) ?></td>
                                    </tr>
                                    <tr>
                                        <td class="label">Ganancia neta:</td>
                                        <td class="value text-success">$<?= number_format($totalGanancia, 2) ?></td>
                                    </tr>
                                    <tr>
                                        <td class="label">Margen de ganancia:</td>
                                        <td class="value text-success">
                                            <strong><?= $totalProveedor > 0 ? number_format(($totalGanancia / $totalProveedor) * 100, 1) : 0 ?>%</strong>
                                        </td>
                                    </tr>
                                    <tr>
                                        <td class="label">Relación costo/ingreso:</td>
                                        <td class="value text-info">
                                            <?= ($totalGanancia + $totalProveedor) > 0 ? number_format(($totalProveedor / ($totalGanancia + $totalProveedor)) * 100, 1) : 0 ?>%
                                        </td>
                                    </tr>
                                </table>
                                
                                <div class="mt-3 p-2 bg-light rounded">
                                    <small class="text-muted">
                                        <i class="fas fa-info-circle mr-1"></i>
                                        La ganancia representa el <?= ($totalGanancia + $totalProveedor) > 0 ? number_format(($totalGanancia / ($totalGanancia + $totalProveedor)) * 100, 1) : 0 ?>% 
                                        de los ingresos totales.
                                        <?php if ($hayProductoEspecial): ?>
                                        <br><span class="text-success"><i class="fas fa-check-circle"></i> Incluye libretas pagadas</span>
                                        <?php endif; ?>
                                    </small>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <?php else: ?>
            <!-- Mostrar mensaje cuando no hay datos -->
            <div class="card card-outline card-secondary shadow-sm mt-4">
                <div class="card-header">
                    <h3 class="card-title font-weight-bold">
                        <i class="fas fa-chart-pie mr-2"></i>Distribución financiera
                    </h3>
                </div>
                <div class="card-body text-center py-5">
                    <i class="fas fa-chart-simple" style="font-size: 4rem; color: #dee2e6;"></i>
                    <p class="mt-3 text-muted">No hay datos suficientes para mostrar la gráfica</p>
                    <small class="text-muted">Se necesitan ventas registradas para generar el análisis financiero</small>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </section>
</div>

<!-- FullCalendar CSS y JS -->
<link href='https://cdn.jsdelivr.net/npm/fullcalendar@5.11.3/main.min.css' rel='stylesheet' />
<script src='https://cdn.jsdelivr.net/npm/fullcalendar@5.11.3/main.min.js'></script>
<script src='https://cdn.jsdelivr.net/npm/fullcalendar@5.11.3/locales/es.js'></script>

<!-- SCRIPTS -->
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf-autotable/3.5.28/jspdf.plugin.autotable.min.js"></script>

<script>
// Elementos del DOM
const btnPDF = document.getElementById('btnPDF');
const pdfDropdown = document.getElementById('pdfDropdown');
const dropdownOverlay = document.getElementById('dropdownOverlay');
const closeDropdownBtn = document.getElementById('closeDropdownBtn');

// Variables para el calendario
let calendar = null;
let fechasVentasPHP = <?= json_encode($fechasVentas) ?>;

// ===== FUNCIONES PARA LA ALERTA DEL PRODUCTO ESPECIAL =====
function cerrarAlertaProductoEspecial() {
    var alerta = document.getElementById('alertaProductoEspecial');
    var btnContainer = document.getElementById('btnMostrarAlertaEspecial');
    
    if (alerta) {
        alerta.style.display = 'none';
    }
    if (btnContainer) {
        btnContainer.style.display = 'block';
    }
    localStorage.setItem('alertaEspecialOculta', 'true');
}

function mostrarAlertaProductoEspecial() {
    var alerta = document.getElementById('alertaProductoEspecial');
    var btnContainer = document.getElementById('btnMostrarAlertaEspecial');
    
    if (alerta) {
        alerta.style.display = 'block';
    }
    if (btnContainer) {
        btnContainer.style.display = 'none';
    }
    localStorage.removeItem('alertaEspecialOculta');
}

// Inicializar alerta de producto especial
document.addEventListener('DOMContentLoaded', function() {
    var alerta = document.getElementById('alertaProductoEspecial');
    var btnMostrar = document.getElementById('mostrarAlertaBtn');
    var btnContainer = document.getElementById('btnMostrarAlertaEspecial');
    
    if (alerta && btnMostrar && btnContainer) {
        var alertaOculta = localStorage.getItem('alertaEspecialOculta');
        if (alertaOculta === 'true') {
            alerta.style.display = 'none';
            btnContainer.style.display = 'block';
        } else {
            alerta.style.display = 'block';
            btnContainer.style.display = 'none';
        }
        
        btnMostrar.addEventListener('click', mostrarAlertaProductoEspecial);
    }
});

// Función para abrir el dropdown
function openDropdown() {
    pdfDropdown.classList.add('active');
    dropdownOverlay.classList.add('active');
}

// Función para cerrar el dropdown
function closeDropdown() {
    pdfDropdown.classList.remove('active');
    dropdownOverlay.classList.remove('active');
}

// Evento para abrir al hacer clic en el botón
if (btnPDF) {
    btnPDF.addEventListener('click', (e) => {
        e.preventDefault();
        e.stopPropagation();
        if (pdfDropdown.classList.contains('active')) {
            closeDropdown();
        } else {
            openDropdown();
        }
    });
}

// Cerrar al hacer clic en la X
if (closeDropdownBtn) {
    closeDropdownBtn.addEventListener('click', (e) => {
        e.preventDefault();
        e.stopPropagation();
        closeDropdown();
    });
}

// Cerrar al hacer clic en el overlay
if (dropdownOverlay) {
    dropdownOverlay.addEventListener('click', closeDropdown);
}

// Prevenir que el dropdown se cierre al hacer clic dentro de él
if (pdfDropdown) {
    pdfDropdown.addEventListener('click', (e) => {
        e.stopPropagation();
    });
}

// Cerrar con tecla Escape
document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape' && pdfDropdown && pdfDropdown.classList.contains('active')) {
        closeDropdown();
    }
});

// Función para cargar eventos en el calendario
function cargarEventosCalendario() {
    if (!calendar) return;
    
    // Limpiar eventos existentes
    calendar.removeAllEvents();
    
    // Obtener datos
    const fechasVentas = fechasVentasPHP.map(f => f.split('T')[0]);
    const fechasReportes = JSON.parse(localStorage.getItem('diasReportes') || '[]');
    const reportesPorFecha = JSON.parse(localStorage.getItem('reportesPorFecha') || '{}');
    
    // Crear mapa de eventos
    const eventosMap = new Map();
    
    fechasVentas.forEach(fecha => {
        eventosMap.set(fecha, { venta: true, reporte: eventosMap.get(fecha)?.reporte || false });
    });
    
    fechasReportes.forEach(fecha => {
        eventosMap.set(fecha, { venta: eventosMap.get(fecha)?.venta || false, reporte: true });
    });
    
    let contVentas = 0;
    let contReportes = 0;
    
    // Crear eventos de fondo
    eventosMap.forEach((valor, fechaStr) => {
        const [year, month, day] = fechaStr.split('-').map(Number);
        const fechaObj = new Date(year, month - 1, day, 12, 0, 0);
        
        if (valor.venta) contVentas++;
        if (valor.reporte) contReportes++;
        
        let claseEvento = '';
        if (valor.venta && valor.reporte) claseEvento = 'evento-ambos';
        else if (valor.venta) claseEvento = 'evento-venta';
        else if (valor.reporte) claseEvento = 'evento-reporte';
        
        if (claseEvento) {
            calendar.addEvent({
                id: fechaStr,
                start: fechaObj,
                allDay: true,
                display: 'background',
                classNames: [claseEvento]
            });
        }
    });
    
    // Actualizar contadores
    document.getElementById('diasVentas').textContent = contVentas;
    document.getElementById('diasReportes').textContent = contReportes;
    document.getElementById('diasActivos').textContent = eventosMap.size;
    
    // Aplicar clases a las celdas después de renderizar
    setTimeout(() => {
        eventosMap.forEach((valor, fechaStr) => {
            const [year, month, day] = fechaStr.split('-').map(Number);
            const fechaBuscar = `${year}-${String(month).padStart(2, '0')}-${String(day).padStart(2, '0')}`;
            
            let celdaClass = '';
            let tooltip = '';
            
            if (valor.venta && valor.reporte) {
                celdaClass = 'fc-day-ambos';
                tooltip = 'Ventas y reportes';
            } else if (valor.venta) {
                celdaClass = 'fc-day-venta';
                tooltip = 'Día con ventas';
            } else if (valor.reporte) {
                celdaClass = 'fc-day-reporte';
                tooltip = 'Día con reportes';
            }
            
            if (celdaClass) {
                const dayCells = document.querySelectorAll('.fc-daygrid-day');
                dayCells.forEach(cell => {
                    const cellDate = cell.getAttribute('data-date');
                    if (cellDate === fechaBuscar) {
                        cell.classList.add(celdaClass);
                        cell.setAttribute('data-title', tooltip);
                    }
                });
            }
        });
    }, 150);
}

// Inicializar calendario
document.addEventListener('DOMContentLoaded', function() {
    const calendarEl = document.getElementById('calendario');
    
    if (calendarEl) {
        calendar = new FullCalendar.Calendar(calendarEl, {
            initialView: 'dayGridMonth',
            locale: 'es',
            headerToolbar: {
                left: 'prev,next',
                center: 'title',
                right: 'today'
            },
            buttonText: {
                today: 'Hoy'
            },
            height: 'auto',
            firstDay: 1,
            contentHeight: 'auto',
            aspectRatio: 1.5,
            dayCellDidMount: function(info) {
                const fechaStr = info.date.toISOString().split('T')[0];
                const fechasVentasSet = new Set(fechasVentasPHP.map(f => f.split('T')[0]));
                const reportes = JSON.parse(localStorage.getItem('diasReportes') || '[]');
                
                const tieneVenta = fechasVentasSet.has(fechaStr);
                const tieneReporte = reportes.includes(fechaStr);
                
                if (tieneVenta && tieneReporte) {
                    info.el.classList.add('fc-day-ambos');
                    info.el.setAttribute('data-title', 'Ventas y reportes');
                } else if (tieneVenta) {
                    info.el.classList.add('fc-day-venta');
                    info.el.setAttribute('data-title', 'Día con ventas');
                } else if (tieneReporte) {
                    info.el.classList.add('fc-day-reporte');
                    info.el.setAttribute('data-title', 'Día con reportes');
                }
            },
            dateClick: function(info) {
                const fecha = info.date;
                const fechaStr = fecha.toISOString().split('T')[0];
                const fechaLocal = fecha.toLocaleDateString('es-ES', {
                    weekday: 'long',
                    year: 'numeric',
                    month: 'long',
                    day: 'numeric'
                });
                
                const reportesPorFecha = JSON.parse(localStorage.getItem('reportesPorFecha') || '{}');
                const reportesFecha = reportesPorFecha[fechaStr] || [];
                const fechasVentasSet = new Set(fechasVentasPHP.map(f => f.split('T')[0]));
                const tieneVenta = fechasVentasSet.has(fechaStr);
                const tieneReporte = reportesFecha.length > 0;
                
                let icono = 'info';
                let titulo = '';
                let colorHeader = '';
                
                if (tieneVenta && tieneReporte) {
                    icono = 'warning';
                    titulo = 'Día con ventas y reportes';
                    colorHeader = '#f39c12';
                } else if (tieneVenta) {
                    icono = 'success';
                    titulo = 'Día con ventas';
                    colorHeader = '#28a745';
                } else if (tieneReporte) {
                    icono = 'info';
                    titulo = 'Día con reportes';
                    colorHeader = '#dc3545';
                } else {
                    icono = 'info';
                    titulo = 'Sin actividad registrada';
                    colorHeader = '#6c757d';
                }
                
                let html = `<div style="text-align: left;">`;
                html += `<p><i class="fas fa-calendar-alt" style="color: #17a2b8; margin-right: 8px;"></i> <strong>Fecha:</strong> ${fechaLocal.charAt(0).toUpperCase() + fechaLocal.slice(1)}</p>`;
                html += `<hr style="margin: 10px 0;">`;
                
                if (tieneVenta) {
                    html += `<p><i class="fas fa-shopping-cart" style="color: #28a745; margin-right: 8px;"></i> <strong>Ventas:</strong> Hay ventas registradas en este día</p>`;
                }
                
                if (tieneReporte && reportesFecha.length > 0) {
                    html += `<p><i class="fas fa-file-pdf" style="color: #dc3545; margin-right: 8px;"></i> <strong>Reportes generados (${reportesFecha.length}):</strong></p>`;
                    html += `<div style="margin-left: 25px; margin-top: 5px; margin-bottom: 10px;">`;
                    reportesFecha.forEach((reporte, idx) => {
                        const tipoIcon = reporte.tipo === 'general' ? '' : '';
                        const tipoTexto = reporte.tipo === 'general' ? 'Reporte General' : 'Reporte por Proveedor';
                        html += `<div class="mb-2 p-2" style="background: #f8f9fa; border-left: 4px solid ${reporte.tipo === 'general' ? '#28a745' : '#dc3545'}; border-radius: 4px;">
                                    <div class="d-flex align-items-center">
                                        <span style="margin-right: 10px; font-size: 1.1rem;">${tipoIcon}</span>
                                        <div>
                                            <strong>${tipoTexto}</strong><br>
                                            <small class="text-muted">Proveedor: ${reporte.proveedor || 'Todos'}</small>
                                        </div>
                                    </div>
                                </div>`;
                    });
                    html += `</div>`;
                }
                
                if (!tieneVenta && !tieneReporte) {
                    html += `<div class="text-center py-3">
                                <i class="fas fa-calendar-day" style="font-size: 2rem; color: #dee2e6; margin-bottom: 10px; display: block;"></i>
                                <p class="text-muted mb-0">No hay actividad registrada en este día</p>
                                <small class="text-muted">Genera un reporte desde el botón PDF para registrar actividad</small>
                            </div>`;
                }
                
                html += `</div>`;
                
                Swal.fire({
                    title: titulo,
                    html: html,
                    icon: icono,
                    confirmButtonText: '<i class="fas fa-check-circle mr-1"></i> Cerrar',
                    confirmButtonColor: colorHeader || '#3085d6',
                    width: '480px',
                    background: '#fff',
                    showCloseButton: true,
                    customClass: {
                        popup: 'rounded-3 shadow-lg',
                        title: 'fs-5 fw-bold',
                        confirmButton: 'px-4 py-2 rounded-pill'
                    },
                    didOpen: () => {
                        document.body.style.overflow = 'hidden';
                    },
                    willClose: () => {
                        document.body.style.overflow = '';
                    }
                });
            }
        });
        
        calendar.render();
        
        setTimeout(() => {
            cargarEventosCalendario();
        }, 200);
    }
});

// Función para registrar un día de reporte
function registrarDiaReporte(tipoGenerado) {
    const hoy = new Date();
    const fechaStr = hoy.toISOString().split('T')[0];
    
    let reportesGuardados = JSON.parse(localStorage.getItem('diasReportes') || '[]');
    if (!reportesGuardados.includes(fechaStr)) {
        reportesGuardados.push(fechaStr);
        localStorage.setItem('diasReportes', JSON.stringify(reportesGuardados));
    }
    
    if (calendar) {
        cargarEventosCalendario();
    }
}

// Función para registrar rango de reportes
function registrarRangoReporte(tipoGenerado) {
    const filtroInicio = '<?= $filtroInicio ?>';
    const filtroFin = '<?= $filtroFin ?>';
    const filtroProveedor = '<?= $filtroProveedor ?>';
    
    <?php
    // Consulta para obtener proveedores únicos con ventas en el período
    $sqlProveedoresActivos = "
    SELECT DISTINCT p.proveedor
    FROM ventas v
    INNER JOIN productos p ON v.id_producto = p.id
    WHERE 1
    ";
    
    if ($filtroProveedor !== '') {
        $sqlProveedoresActivos .= " AND p.proveedor = '" . $conn->real_escape_string($filtroProveedor) . "'";
    }
    
    if ($filtroInicio !== '' && $filtroFin !== '') {
        $sqlProveedoresActivos .= " AND DATE(v.fecha_venta) BETWEEN '" . $conn->real_escape_string($filtroInicio) . "' 
        AND '" . $conn->real_escape_string($filtroFin) . "'";
    }
    
    $sqlProveedoresActivos .= " ORDER BY p.proveedor";
    
    $resultadoProveedoresActivos = $conn->query($sqlProveedoresActivos);
    $proveedoresActivos = [];
    if ($resultadoProveedoresActivos) {
        while ($row = $resultadoProveedoresActivos->fetch_assoc()) {
            $proveedoresActivos[] = $row['proveedor'];
        }
    }
    ?>
    
    const proveedoresEnReporte = <?= json_encode($proveedoresActivos) ?>;
    
    function getFechaLocal(date) {
        const year = date.getFullYear();
        const month = String(date.getMonth() + 1).padStart(2, '0');
        const day = String(date.getDate()).padStart(2, '0');
        return `${year}-${month}-${day}`;
    }
    
    const hoy = new Date();
    const hoyStr = getFechaLocal(hoy);
    
    let reportesPorFecha = JSON.parse(localStorage.getItem('reportesPorFecha') || '{}');
    
    if (filtroInicio && filtroFin) {
        const [inicioYear, inicioMonth, inicioDay] = filtroInicio.split('-').map(Number);
        const [finYear, finMonth, finDay] = filtroFin.split('-').map(Number);
        
        let fechaActual = new Date(inicioYear, inicioMonth - 1, inicioDay);
        const fechaFin = new Date(finYear, finMonth - 1, finDay);
        
        let fechasProcesadas = [];
        
        while (fechaActual <= fechaFin) {
            const fechaStr = getFechaLocal(fechaActual);
            
            if (fechaActual <= new Date()) {
                fechasProcesadas.push(fechaStr);
                
                if (!reportesPorFecha[fechaStr]) {
                    reportesPorFecha[fechaStr] = [];
                }
                
                if (tipoGenerado === 'proveedor') {
                    if (filtroProveedor) {
                        const reporteInfo = {
                            fecha: fechaStr,
                            proveedor: filtroProveedor,
                            tipo: 'proveedor',
                            descripcion: 'Reporte por proveedor',
                            timestamp: new Date().toISOString()
                        };
                        
                        const existe = reportesPorFecha[fechaStr].some(r => 
                            r.proveedor === filtroProveedor && r.tipo === 'proveedor'
                        );
                        
                        if (!existe) {
                            reportesPorFecha[fechaStr].push(reporteInfo);
                        }
                    } else {
                        const reporteInfo = {
                            fecha: fechaStr,
                            proveedor: 'TODOS_PROVEEDORES',
                            tipo: 'proveedor',
                            descripcion: 'Reporte por proveedor - Todos los proveedores',
                            proveedoresIncluidos: proveedoresEnReporte,
                            timestamp: new Date().toISOString()
                        };
                        
                        const existe = reportesPorFecha[fechaStr].some(r => 
                            r.proveedor === 'TODOS_PROVEEDORES' && r.tipo === 'proveedor'
                        );
                        
                        if (!existe) {
                            reportesPorFecha[fechaStr].push(reporteInfo);
                        }
                    }
                } 
                else if (tipoGenerado === 'general') {
                    if (filtroProveedor) {
                        const reporteGeneral = {
                            fecha: fechaStr,
                            proveedor: filtroProveedor,
                            tipo: 'general',
                            descripcion: 'Reporte General',
                            timestamp: new Date().toISOString()
                        };
                        
                        const existeGeneral = reportesPorFecha[fechaStr].some(r => 
                            r.proveedor === filtroProveedor && r.tipo === 'general'
                        );
                        
                        if (!existeGeneral) {
                            reportesPorFecha[fechaStr].push(reporteGeneral);
                        }
                    } else {
                        const reporteGeneral = {
                            fecha: fechaStr,
                            proveedor: 'TODOS',
                            tipo: 'general',
                            descripcion: 'Reporte General (Todos los proveedores)',
                            proveedoresIncluidos: proveedoresEnReporte,
                            timestamp: new Date().toISOString()
                        };
                        
                        const existeGeneral = reportesPorFecha[fechaStr].some(r => r.tipo === 'general' && r.proveedor === 'TODOS');
                        
                        if (!existeGeneral) {
                            reportesPorFecha[fechaStr].push(reporteGeneral);
                        }
                    }
                }
                else if (tipoGenerado === 'ambos') {
                    // Agregar reporte de proveedor
                    if (filtroProveedor) {
                        const existeProv = reportesPorFecha[fechaStr].some(r => 
                            r.proveedor === filtroProveedor && r.tipo === 'proveedor'
                        );
                        
                        if (!existeProv) {
                            reportesPorFecha[fechaStr].push({
                                fecha: fechaStr,
                                proveedor: filtroProveedor,
                                tipo: 'proveedor',
                                descripcion: 'Reporte por proveedor',
                                timestamp: new Date().toISOString()
                            });
                        }
                    } else {
                        const existeProvTodos = reportesPorFecha[fechaStr].some(r => 
                            r.proveedor === 'TODOS_PROVEEDORES' && r.tipo === 'proveedor'
                        );
                        
                        if (!existeProvTodos) {
                            reportesPorFecha[fechaStr].push({
                                fecha: fechaStr,
                                proveedor: 'TODOS_PROVEEDORES',
                                tipo: 'proveedor',
                                descripcion: 'Reporte por proveedor - Todos los proveedores',
                                proveedoresIncluidos: proveedoresEnReporte,
                                timestamp: new Date().toISOString()
                            });
                        }
                    }
                    
                    // Agregar reporte general
                    if (filtroProveedor) {
                        const existeGeneral = reportesPorFecha[fechaStr].some(r => 
                            r.proveedor === filtroProveedor && r.tipo === 'general'
                        );
                        
                        if (!existeGeneral) {
                            reportesPorFecha[fechaStr].push({
                                fecha: fechaStr,
                                proveedor: filtroProveedor,
                                tipo: 'general',
                                descripcion: 'Reporte General',
                                timestamp: new Date().toISOString()
                            });
                        }
                    } else {
                        const existeGeneral = reportesPorFecha[fechaStr].some(r => r.tipo === 'general' && r.proveedor === 'TODOS');
                        
                        if (!existeGeneral) {
                            reportesPorFecha[fechaStr].push({
                                fecha: fechaStr,
                                proveedor: 'TODOS',
                                tipo: 'general',
                                descripcion: 'Reporte General (Todos los proveedores)',
                                proveedoresIncluidos: proveedoresEnReporte,
                                timestamp: new Date().toISOString()
                            });
                        }
                    }
                }
            }
            
            fechaActual.setDate(fechaActual.getDate() + 1);
        }
        
        localStorage.setItem('reportesPorFecha', JSON.stringify(reportesPorFecha));
        
        let diasReporte = JSON.parse(localStorage.getItem('diasReportes') || '[]');
        fechasProcesadas.forEach(fecha => {
            if (!diasReporte.includes(fecha)) {
                diasReporte.push(fecha);
            }
        });
        localStorage.setItem('diasReportes', JSON.stringify(diasReporte));
        
        if (calendar) {
            cargarEventosCalendario();
        }
        
    } else {
        if (!reportesPorFecha[hoyStr]) {
            reportesPorFecha[hoyStr] = [];
        }
        
        if (tipoGenerado === 'proveedor') {
            if (filtroProveedor) {
                const reporteInfo = {
                    fecha: hoyStr,
                    proveedor: filtroProveedor,
                    tipo: 'proveedor',
                    descripcion: 'Reporte por proveedor',
                    timestamp: new Date().toISOString()
                };
                
                const existe = reportesPorFecha[hoyStr].some(r => 
                    r.proveedor === filtroProveedor && r.tipo === 'proveedor'
                );
                
                if (!existe) {
                    reportesPorFecha[hoyStr].push(reporteInfo);
                }
            } else {
                const reporteInfo = {
                    fecha: hoyStr,
                    proveedor: 'TODOS_PROVEEDORES',
                    tipo: 'proveedor',
                    descripcion: 'Reporte por proveedor - Todos los proveedores',
                    proveedoresIncluidos: proveedoresEnReporte,
                    timestamp: new Date().toISOString()
                };
                
                const existe = reportesPorFecha[hoyStr].some(r => 
                    r.proveedor === 'TODOS_PROVEEDORES' && r.tipo === 'proveedor'
                );
                
                if (!existe) {
                    reportesPorFecha[hoyStr].push(reporteInfo);
                }
            }
        }
        else if (tipoGenerado === 'general') {
            if (filtroProveedor) {
                const reporteGeneral = {
                    fecha: hoyStr,
                    proveedor: filtroProveedor,
                    tipo: 'general',
                    descripcion: 'Reporte General',
                    timestamp: new Date().toISOString()
                };
                
                const existeGeneral = reportesPorFecha[hoyStr].some(r => 
                    r.proveedor === filtroProveedor && r.tipo === 'general'
                );
                
                if (!existeGeneral) {
                    reportesPorFecha[hoyStr].push(reporteGeneral);
                }
            } else {
                const reporteGeneral = {
                    fecha: hoyStr,
                    proveedor: 'TODOS',
                    tipo: 'general',
                    descripcion: 'Reporte General (Todos los proveedores)',
                    proveedoresIncluidos: proveedoresEnReporte,
                    timestamp: new Date().toISOString()
                };
                
                const existeGeneral = reportesPorFecha[hoyStr].some(r => r.tipo === 'general' && r.proveedor === 'TODOS');
                
                if (!existeGeneral) {
                    reportesPorFecha[hoyStr].push(reporteGeneral);
                }
            }
        }
        else if (tipoGenerado === 'ambos') {
            if (filtroProveedor) {
                const existeProv = reportesPorFecha[hoyStr].some(r => 
                    r.proveedor === filtroProveedor && r.tipo === 'proveedor'
                );
                
                if (!existeProv) {
                    reportesPorFecha[hoyStr].push({
                        fecha: hoyStr,
                        proveedor: filtroProveedor,
                        tipo: 'proveedor',
                        descripcion: 'Reporte por proveedor',
                        timestamp: new Date().toISOString()
                    });
                }
            } else {
                const existeProvTodos = reportesPorFecha[hoyStr].some(r => 
                    r.proveedor === 'TODOS_PROVEEDORES' && r.tipo === 'proveedor'
                );
                
                if (!existeProvTodos) {
                    reportesPorFecha[hoyStr].push({
                        fecha: hoyStr,
                        proveedor: 'TODOS_PROVEEDORES',
                        tipo: 'proveedor',
                        descripcion: 'Reporte por proveedor - Todos los proveedores',
                        proveedoresIncluidos: proveedoresEnReporte,
                        timestamp: new Date().toISOString()
                    });
                }
            }
            
            if (filtroProveedor) {
                const existeGeneral = reportesPorFecha[hoyStr].some(r => 
                    r.proveedor === filtroProveedor && r.tipo === 'general'
                );
                
                if (!existeGeneral) {
                    reportesPorFecha[hoyStr].push({
                        fecha: hoyStr,
                        proveedor: filtroProveedor,
                        tipo: 'general',
                        descripcion: 'Reporte General',
                        timestamp: new Date().toISOString()
                    });
                }
            } else {
                const existeGeneral = reportesPorFecha[hoyStr].some(r => r.tipo === 'general' && r.proveedor === 'TODOS');
                
                if (!existeGeneral) {
                    reportesPorFecha[hoyStr].push({
                        fecha: hoyStr,
                        proveedor: 'TODOS',
                        tipo: 'general',
                        descripcion: 'Reporte General (Todos los proveedores)',
                        proveedoresIncluidos: proveedoresEnReporte,
                        timestamp: new Date().toISOString()
                    });
                }
            }
        }
        
        localStorage.setItem('reportesPorFecha', JSON.stringify(reportesPorFecha));
        
        let diasReporte = JSON.parse(localStorage.getItem('diasReportes') || '[]');
        if (!diasReporte.includes(hoyStr)) {
            diasReporte.push(hoyStr);
            localStorage.setItem('diasReportes', JSON.stringify(diasReporte));
        }
        
        if (calendar) {
            cargarEventosCalendario();
        }
    }
}

// Limpiar historial de reportes
document.addEventListener('DOMContentLoaded', function() {
    const limpiarHistorialBtn = document.getElementById('limpiarHistorialBtn');
    
    if (limpiarHistorialBtn) {
        limpiarHistorialBtn.addEventListener('click', function(e) {
            e.preventDefault();
            
            Swal.fire({
                title: '¿Limpiar historial?',
                text: 'Se eliminarán todos los días con reportes del calendario.',
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#d33',
                cancelButtonColor: '#3085d6',
                confirmButtonText: 'Sí, limpiar',
                cancelButtonText: 'Cancelar'
            }).then((result) => {
                if (result.isConfirmed) {
                    localStorage.removeItem('diasReportes');
                    localStorage.removeItem('reportesPorFecha');
                    
                    if (typeof cargarEventosCalendario === 'function') {
                        cargarEventosCalendario();
                    }
                    
                    Swal.fire({
                        icon: 'success',
                        title: 'Historial limpiado',
                        text: 'Los días con reportes han sido eliminados.',
                        timer: 2000,
                        showConfirmButton: false
                    });
                }
            });
        });
    }
});

// Gráfica
<?php if (!empty($productos) && ($totalGanancia > 0 || $totalProveedor > 0)): ?>
const ctx = document.getElementById('graficaVentas');
if (ctx) {
    new Chart(ctx, {
        type: 'pie',
        data: {
            labels: [
                'Ganancia neta ($<?= number_format($totalGanancia, 2) ?>)',
                'Deuda a proveedores ($<?= number_format($totalProveedor, 2) ?>)'
            ],
            datasets: [{
                data: [
                    <?= $totalGanancia ?>,
                    <?= $totalProveedor ?>
                ],
                backgroundColor: [
                    '#28a745',
                    '#dc3545'
                ],
                borderColor: '#ffffff',
                borderWidth: 2,
                hoverOffset: 8
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: {
                    position: 'bottom',
                    labels: {
                        padding: 15,
                        boxWidth: 12,
                        font: { size: 12 }
                    }
                },
                tooltip: {
                    callbacks: {
                        label: function(context) {
                            const total = context.dataset.data.reduce((a, b) => a + b, 0);
                            const value = context.raw;
                            const percent = ((value / total) * 100).toFixed(1);
                            return `${context.label}: $${value.toLocaleString()} (${percent}%)`;
                        }
                    }
                }
            }
        }
    });
}
<?php endif; ?>

function generarPDF(tipo) {
    // Pasar el tipo a registrarRangoReporte
    registrarRangoReporte(tipo);
    
    const { jsPDF } = window.jspdf;
    
    // ============================================
    // CONFIGURACIÓN PROFESIONAL
    // ============================================
    const colors = {
        primary: [26, 115, 232],
        secondary: [66, 133, 244],
        success: [52, 168, 83],
        warning: [251, 188, 5],
        danger: [234, 67, 53],
        dark: [32, 33, 36],
        medium: [95, 99, 104],
        light: [248, 249, 250],
        white: [255, 255, 255]
    };

    <?php
    // ============================================
    // CONSULTA PRINCIPAL - AGRUPADA POR PROVEEDOR Y PRODUCTO
    // ============================================
    
    // Reutilizar las consultas que ya tienes al inicio del archivo
    // No es necesario repetirlas aquí, ya están definidas arriba

    // Generar nombres de archivo - SOLO DEFINIR VARIABLES, NO FUNCIONES
    $fechaActual = date('Y-m-d'); // Solo fecha, sin hora
    $horaActual = date('H-i-s');
    $usuario_nombre = $_SESSION['nombre'] ?? 'Sistema';
    $usuario_id = $_SESSION['usuario_id'] ?? 0;
    $totalRegistros = count($ventasAgrupadas);

    // Determinar nombres de archivo según los filtros
    $nombreArchivoGeneral = "reporte_admin_{$fechaActual}.pdf";
    $nombreArchivoProveedor = $filtroProveedor ? 
        "reporte_proveedor_" . preg_replace('/[^a-zA-Z0-9]/', '_', $filtroProveedor) . "_{$fechaActual}.pdf" : 
        "reporte_proveedores_todos_{$fechaActual}.pdf";
    ?>

    function guardarPDFenServidor(pdfBlob, nombreArchivo, tipoPDF) {
        return new Promise((resolve, reject) => {
            // Determinar la carpeta según el tipo de reporte
            let carpetaDestino = '';
            let modulo = '';
            
            if (tipoPDF === 'general') {
                carpetaDestino = 'Ventas_Generales';
                modulo = 'reporte de ventas - general';
            } else if (tipoPDF === 'proveedor') {
                carpetaDestino = 'Ventas_Proveedor';
                modulo = 'reporte de ventas - proveedor';
            } else {
                carpetaDestino = 'reportes_ventas'; // Fallback para ambos
                modulo = 'reporte de ventas';
            }
            
            const formData = new FormData();
            formData.append('pdf_file', pdfBlob, nombreArchivo);
            formData.append('carpeta', carpetaDestino);
            formData.append('tipo', tipoPDF);
            formData.append('modulo', modulo);
            formData.append('proveedor', '<?= $filtroProveedor ?>');
            formData.append('total_registros', '<?= $totalRegistros ?>');
            
            fetch('guardar_pdf.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    resolve(data);
                } else {
                    reject(data.error || 'Error al guardar el PDF');
                }
            })
            .catch(error => reject(error));
        });
    }

// Función para generar PDF General (Administrador) - DISEÑO MINIMALISTA
function generarPDFGeneral() {
    const docAdmin = new jsPDF({
        orientation: "p",
        unit: "mm",
        format: "a4",
        putOnlyUsedFonts: true
    });

    const pageWidth = docAdmin.internal.pageSize.getWidth();
    const pageHeight = docAdmin.internal.pageSize.getHeight();
    let y = 25;
    let pageNum = 1;

    // ============================================
    // CARGAR LOGOS (TIENDA + PROVEEDOR SI HAY FILTRO)
    // ============================================
    <?php
    // Obtener datos de la tienda
    $sqlConfig = "SELECT nombre, logo FROM configuracion_galeria LIMIT 1";
    $resultConfig = $conn->query($sqlConfig);
    $configTienda = $resultConfig->fetch_assoc();
    $nombreTienda = $configTienda['nombre'] ?? 'PESCADORES DE LA PREHISTORIA';
    $logoTiendaPath = $configTienda['logo'] ?? '';
    
    // Buscar logo tienda
    $logoTiendaBase64 = '';
    $logoTiendaExt = 'png';
    if (empty($logoTiendaPath) || !file_exists($logoTiendaPath)) {
        $rutasPosibles = [
            '../img/logo.png', '../img/logo.jpg', '../img/panel_principal.jpg',
            '../img/panel_principal.png', '../dist/img/logo.png', '../dist/img/logo.jpg'
        ];
        foreach ($rutasPosibles as $ruta) {
            if (file_exists($ruta)) { $logoTiendaPath = $ruta; break; }
        }
    }
    if (!empty($logoTiendaPath) && file_exists($logoTiendaPath)) {
        $tipo = mime_content_type($logoTiendaPath);
        $data = file_get_contents($logoTiendaPath);
        $logoTiendaBase64 = "data:" . $tipo . ";base64," . base64_encode($data);
        $ext = pathinfo($logoTiendaPath, PATHINFO_EXTENSION);
        $logoTiendaExt = $ext === 'jpg' ? 'jpeg' : $ext;
    }
    
    // Logo del proveedor con redimensionado automático (si hay filtro)
    $logoProveedorBase64 = '';
    $logoProveedorExt = 'png';
    $logoProveedorPath = '';
    $logoProveedorWidth = 25;
    $logoProveedorHeight = 25;
    $nombreProveedor = '';
    $inicialesProveedor = '';
    
    if ($filtroProveedor !== '') {
        $nombreProveedor = $filtroProveedor;
        $sqlLogoProv = "SELECT logo, nombre FROM proveedores WHERE nombre = '" . $conn->real_escape_string($filtroProveedor) . "' LIMIT 1";
        $resultLogoProv = $conn->query($sqlLogoProv);
        if ($resultLogoProv && $row = $resultLogoProv->fetch_assoc()) {
            $logoProveedorPath = $row['logo'];
            if (!empty($logoProveedorPath) && file_exists($logoProveedorPath)) {
                // Obtener dimensiones de la imagen
                $size = getimagesize($logoProveedorPath);
                $anchoOriginal = $size[0];
                $altoOriginal = $size[1];
                $ratio = $anchoOriginal / $altoOriginal;
                
                $tipo = mime_content_type($logoProveedorPath);
                $data = file_get_contents($logoProveedorPath);
                $logoProveedorBase64 = "data:" . $tipo . ";base64," . base64_encode($data);
                $ext = pathinfo($logoProveedorPath, PATHINFO_EXTENSION);
                $logoProveedorExt = $ext === 'jpg' ? 'jpeg' : $ext;
                
                // USAR EL LADO MÁS PEQUEÑO para decidir el tamaño
                $ladoMenor = min($anchoOriginal, $altoOriginal);
                
                if ($ladoMenor < 310) {
                    $maxSize = 45;  // Logo muy pequeño (menos de 300px en su lado menor)
                } elseif ($ladoMenor < 500) {
                    $maxSize = 35;  // Logo pequeño (300-500px)
                } elseif ($ladoMenor > 800) {
                    $maxSize = 20;  // Logo muy grande (más de 800px)
                } else {
                    $maxSize = 25;  // Tamaño normal (500-800px)
                }
                
                // Calcular tamaño proporcional
                if ($anchoOriginal > $altoOriginal) {
                    // Logo horizontal (más ancho que alto)
                    $logoProveedorWidth = $maxSize;
                    $logoProveedorHeight = $maxSize / $ratio;
                } else {
                    // Logo vertical o cuadrado
                    $logoProveedorHeight = $maxSize;
                    $logoProveedorWidth = $maxSize * $ratio;
                }
            }
        }
        // Obtener iniciales del proveedor (primeras dos letras en mayúscula)
        $palabras = explode(' ', $nombreProveedor);
        if (count($palabras) >= 2) {
            $inicialesProveedor = strtoupper(substr($palabras[0], 0, 1) . substr($palabras[1], 0, 1));
        } else {
            $inicialesProveedor = strtoupper(substr($nombreProveedor, 0, 2));
        }
    }
    ?>

    // ============================================
    // ENCABEZADO - LOGO IZQUIERDA, NOMBRE CENTRADO, LOGO DERECHA PROPORCIONAL
    // ============================================
    
    const logoY = 12;
    const logoTiendaSize = 25;
    
    <?php if ($filtroProveedor !== ''): ?>
    // Logo izquierdo (Tienda)
    <?php if (!empty($logoTiendaBase64)): ?>
    try {
        docAdmin.addImage('<?= $logoTiendaBase64 ?>', '<?= $logoTiendaExt ?>', 15, logoY, logoTiendaSize, logoTiendaSize);
    } catch(e) {}
    <?php endif; ?>
    
    // Nombre de la tienda CENTRADO
    docAdmin.setFontSize(12);
    docAdmin.setTextColor(60, 60, 60);
    docAdmin.setFont("helvetica", "bold");
    docAdmin.text("<?= strtoupper($nombreTienda) ?>", pageWidth / 2, logoY + logoTiendaSize/2 + 4, { align: "center" });
    
    <?php if (!empty($logoProveedorBase64)): ?>
    // Logo derecho del proveedor CON TAMAÑO PROPORCIONAL AUTOMÁTICO
    const logoX = pageWidth - <?= $logoProveedorWidth ?> - 15;
    try {
        docAdmin.addImage('<?= $logoProveedorBase64 ?>', '<?= $logoProveedorExt ?>', logoX, logoY, <?= $logoProveedorWidth ?>, <?= $logoProveedorHeight ?>);
    } catch(e) {
        // Si falla la imagen, mostrar iniciales
        docAdmin.setFontSize(26);
        docAdmin.setTextColor(52, 152, 219);
        docAdmin.setFont("helvetica", "bold");
        docAdmin.text("<?= $inicialesProveedor ?>", pageWidth - 50, logoY + logoTiendaSize/2 + 6);
    }
    <?php else: ?>
    // INICIALES GRANDES del proveedor
    docAdmin.setFontSize(26);
    docAdmin.setTextColor(52, 152, 219);
    docAdmin.setFont("helvetica", "bold");
    docAdmin.text("<?= $inicialesProveedor ?>", pageWidth - 50, logoY + logoTiendaSize/2 + 6);
    <?php endif; ?>
    
    <?php else: ?>
    // UN LOGO (sin filtro de proveedor)
    <?php if (!empty($logoTiendaBase64)): ?>
    try {
        docAdmin.addImage('<?= $logoTiendaBase64 ?>', '<?= $logoTiendaExt ?>', 15, logoY, logoTiendaSize, logoTiendaSize);
    } catch(e) {}
    <?php endif; ?>
    
    // Nombre de la tienda CENTRADO
    docAdmin.setFontSize(14);
    docAdmin.setTextColor(60, 60, 60);
    docAdmin.setFont("helvetica", "bold");
    docAdmin.text("<?= strtoupper($nombreTienda) ?>", pageWidth / 2, logoY + logoTiendaSize/2 + 4, { align: "center" });
    <?php endif; ?>
    
    // Línea decorativa sutil
    docAdmin.setDrawColor(230, 230, 230);
    docAdmin.line(15, logoY + logoTiendaSize + 8, pageWidth - 15, logoY + logoTiendaSize + 8);

    function addFooter(doc, pageNum, totalPages) {
        doc.setPage(pageNum);
        doc.setFontSize(8);
        doc.setTextColor(colors.medium[0], colors.medium[1], colors.medium[2]);
        doc.setFont("helvetica", "normal");
        
        doc.setDrawColor(220, 220, 220);
        doc.line(20, pageHeight - 12, pageWidth - 20, pageHeight - 12);
        
        doc.text(
            `Documento generado el ${new Date().toLocaleDateString()}`,
            20,
            pageHeight - 6
        );
        doc.text(
            `Página ${pageNum} de ${totalPages} | Reporte Administrativo Confidencial`,
            pageWidth - 20,
            pageHeight - 6,
            { align: "right" }
        );
    }

    <?php if (empty($ventasAgrupadas)): ?>
    docAdmin.setFillColor(colors.light[0], colors.light[1], colors.light[2]);
    docAdmin.rect(0, 0, pageWidth, pageHeight, "F");

    docAdmin.setFontSize(24);
    docAdmin.setTextColor(colors.dark[0], colors.dark[1], colors.dark[2]);
    docAdmin.setFont("helvetica", "bold");
    docAdmin.text("REPORTE DE VENTAS", pageWidth / 2, 80, { align: "center" });

    docAdmin.setFontSize(14);
    docAdmin.setTextColor(colors.medium[0], colors.medium[1], colors.medium[2]);
    docAdmin.text("No hay ventas registradas en el período seleccionado", pageWidth / 2, 120, { align: "center" });

    docAdmin.setFontSize(10);
    docAdmin.text("Por favor, seleccione un rango de fechas con ventas para visualizar el reporte", pageWidth / 2, 140, { align: "center" });

    addFooter(docAdmin, 1, 1);
    
    const pdfBlob = docAdmin.output('blob');
    const pdfUrl = URL.createObjectURL(pdfBlob);
    window.open(pdfUrl, '_blank');
    
    guardarPDFenServidor(pdfBlob, '<?= $nombreArchivoGeneral ?>', 'general')
        .then(() => {
            console.log('PDF guardado en servidor');
            mostrarNotificacion('Reporte guardado exitosamente', 'success');
        })
        .catch(error => {
            console.error('Error al guardar PDF:', error);
            mostrarNotificacion('Reporte descargado pero no se pudo guardar en el servidor', 'warning');
        });
    return;
    <?php endif; ?>

    // --- PORTADA PROFESIONAL ---
    docAdmin.setFontSize(28);
    docAdmin.setTextColor(colors.dark[0], colors.dark[1], colors.dark[2]);
    docAdmin.setFont("helvetica", "bold");
    docAdmin.text("REPORTE DE VENTAS", pageWidth / 2, 70, { align: "center" });

    docAdmin.setFontSize(12);
    docAdmin.setTextColor(colors.medium[0], colors.medium[1], colors.medium[2]);
    docAdmin.setFont("helvetica", "normal");

    let periodoTexto = "";
    <?php if ($filtroInicio !== '' && $filtroFin !== ''): ?>
    periodoTexto = "Período: <?= htmlspecialchars($filtroInicio) ?> al <?= htmlspecialchars($filtroFin) ?>";
    <?php else: ?>
    periodoTexto = "Período: Histórico completo";
    <?php endif; ?>

    <?php if ($filtroProveedor !== ''): ?>
    periodoTexto += " | Proveedor: <?= htmlspecialchars($filtroProveedor) ?>";
    <?php endif; ?>

    let infoY = 90;
    docAdmin.text(periodoTexto, pageWidth / 2, infoY, { align: "center" });

    let cardY = infoY + 15;
    docAdmin.setFillColor(colors.light[0], colors.light[1], colors.light[2]);
    docAdmin.roundedRect(20, cardY, pageWidth - 40, 45, 3, 3, "F");

    docAdmin.setFontSize(10);
    docAdmin.setTextColor(colors.medium[0], colors.medium[1], colors.medium[2]);
    docAdmin.text("TOTAL INGRESOS", 35, cardY + 15);
    docAdmin.text("DEUDA TOTAL", pageWidth / 2 - 25, cardY + 15);
    docAdmin.text("GANANCIA NETA", pageWidth - 55, cardY + 15);

    docAdmin.setFontSize(18);
    docAdmin.setTextColor(colors.dark[0], colors.dark[1], colors.dark[2]);
    docAdmin.setFont("helvetica", "bold");
    docAdmin.text("$<?= number_format($totalVentas, 2) ?>", 35, cardY + 30);
    docAdmin.text("$<?= number_format($totalProveedor, 2) ?>", pageWidth / 2 - 25, cardY + 30);
    docAdmin.text("$<?= number_format($totalGanancia, 2) ?>", pageWidth - 55, cardY + 30);

    y = cardY + 60;

    // TABLA PRINCIPAL - VENTAS AGRUPADAS POR PROVEEDOR Y PRODUCTO
    if (y > 200) { docAdmin.addPage(); y = 25; pageNum++; }
    
    docAdmin.setFontSize(16);
    docAdmin.setTextColor(colors.success[0], colors.success[1], colors.success[2]);
    docAdmin.setFont("helvetica", "bold");
    docAdmin.text("Resumen de Ventas por Producto", 20, y);
    y += 8;

    const ventasAgrupadasData = [
        <?php foreach ($ventasAgrupadas as $venta): ?>
        [
            '<?= addslashes($venta['proveedor']) ?>',
            '<?= addslashes($venta['producto']) ?>',
            <?= $venta['total_vendido'] ?>,
            <?= $venta['stock_actual'] ?>,
            '$<?= number_format($venta['precio_compra'], 2) ?>',
            '$<?= number_format($venta['precio_venta'], 2) ?>',
            '<?= $venta['es_producto_pagado'] ? "PAGADO" : "$".number_format($venta['deuda_total'], 2) ?>',
            '$<?= number_format($venta['ganancia_total'], 2) ?>'
        ],
        <?php endforeach; ?>
    ];

    docAdmin.autoTable({
        head: [['Proveedor', 'Producto', 'Vendidos', 'Stock Restante', 'P.Compra', 'P.Venta', 'Deuda', 'Ganancia']],
        body: ventasAgrupadasData,
        startY: y,
        theme: "striped",
        headStyles: { fillColor: colors.success, textColor: colors.white, fontSize: 8 },
        styles: { fontSize: 7, cellPadding: 2 },
        columnStyles: {
            0: { cellWidth: 30, fontStyle: 'bold' },
            1: { cellWidth: 40 },
            2: { cellWidth: 15, halign: 'center' },
            3: { cellWidth: 20, halign: 'center' },
            4: { cellWidth: 18, halign: 'right' },
            5: { cellWidth: 18, halign: 'right' },
            6: { cellWidth: 22, halign: 'right', fontStyle: 'bold' },
            7: { cellWidth: 22, halign: 'right', fontStyle: 'bold' }
        },
        margin: { left: 10, right: 10 }
    });

    y = docAdmin.lastAutoTable.finalY + 10;

    // ============================================
    // TABLA DE STOCK COMPLETO (CENTRADA DINÁMICAMENTE)
    // ============================================
    if (y > 240) { docAdmin.addPage(); y = 25; pageNum++; }

    docAdmin.setFontSize(16);
    docAdmin.setTextColor(colors.secondary[0], colors.secondary[1], colors.secondary[2]);
    docAdmin.setFont("helvetica", "bold");
    docAdmin.text("Inventario Completo por Proveedor", 20, y);
    y += 8;

    const stockCompletoData = [
        <?php foreach ($todosProductos as $producto): 
            $vendido = 0;
            foreach ($ventasAgrupadas as $venta) {
                if ($venta['proveedor'] == $producto['proveedor'] && $venta['producto'] == $producto['nombre']) {
                    $vendido = $venta['total_vendido'];
                    break;
                }
            }
            $stockRestante = $producto['stock_actual'];
        ?>
        [
            '<?= addslashes($producto['proveedor']) ?>',
            '<?= addslashes($producto['nombre']) ?>',
            <?= $producto['stock_actual'] ?>,
            <?= $vendido ?>,
            <?= $stockRestante - $vendido ?>,
            '$<?= number_format($producto['precio_venta'], 2) ?>',
            '<?= $producto['es_producto_pagado'] ? "PAGADO" : "" ?>'
        ],
        <?php endforeach; ?>
    ];

    // Calcular el margen para centrar la tabla dinámicamente
    const anchoInventario = 190;
    const margenIzquierdo = (pageWidth - anchoInventario) / 2;
    const margenDerecho = (pageWidth - anchoInventario) / 2;

    docAdmin.autoTable({
        head: [['Proveedor', 'Producto', 'Stock Inicial', 'Vendidos', 'Stock Restante', 'P.Venta', 'Estado']],
        body: stockCompletoData,
        startY: y,
        theme: "grid",
        headStyles: { fillColor: colors.secondary, textColor: colors.white, fontSize: 8, halign: 'center' },
        styles: { fontSize: 7, cellPadding: 2, halign: 'center' },
        columnStyles: {
            0: { cellWidth: 30, fontStyle: 'bold', halign: 'left' },
            1: { cellWidth: 45, halign: 'left' },
            2: { cellWidth: 20, halign: 'center' },
            3: { cellWidth: 18, halign: 'center' },
            4: { cellWidth: 22, halign: 'center', fontStyle: 'bold' },
            5: { cellWidth: 22, halign: 'right' },
            6: { cellWidth: 18, halign: 'center' }
        },
        margin: { left: margenIzquierdo, right: margenDerecho }
    });

    y = docAdmin.lastAutoTable.finalY + 15;

    // RESUMEN POR PROVEEDOR
    docAdmin.setFontSize(14);
    docAdmin.setTextColor(colors.dark[0], colors.dark[1], colors.dark[2]);
    docAdmin.setFont("helvetica", "bold");
    docAdmin.text("Resumen de Deuda por Proveedor", 20, y);
    y += 8;

    const resumenProvData = [
        <?php 
        ksort($deudaPorProveedor);
        foreach ($deudaPorProveedor as $prov => $total): 
        ?>
        ['<?= addslashes($prov) ?>', '$<?= number_format($total, 2) ?>'],
        <?php endforeach; ?>
        ['', ''],
        ['TOTAL GENERAL', '$<?= number_format($totalProveedor, 2) ?>']
    ];

    docAdmin.autoTable({
        startY: y,
        body: resumenProvData,
        theme: "plain",
        styles: { fontSize: 10, cellPadding: 4 },
        columnStyles: {
            0: { cellWidth: 120, fontStyle: 'bold', halign: 'left' },
            1: { cellWidth: 50, halign: 'right', fontStyle: 'bold' }
        },
        margin: { left: 30, right: 30 }
    });

    const totalPagesAdmin = docAdmin.internal.getNumberOfPages();
    for (let i = 1; i <= totalPagesAdmin; i++) {
        addFooter(docAdmin, i, totalPagesAdmin);
    }

    const pdfBlob = docAdmin.output('blob');
    const pdfUrl = URL.createObjectURL(pdfBlob);
    // Primero descargar automáticamente
    const link = document.createElement('a');
    link.href = pdfUrl;
    link.download = '<?= $nombreArchivoGeneral ?>';
    link.click();
    // Luego abrir en nueva ventana
    setTimeout(() => {
        window.open(pdfUrl, '_blank');
    }, 500);
    
    guardarPDFenServidor(pdfBlob, '<?= $nombreArchivoGeneral ?>', 'general')
        .then(() => {
            console.log('PDF guardado en servidor');
            mostrarNotificacion('Reporte guardado exitosamente', 'success');
        })
        .catch(error => {
            console.error('Error al guardar PDF:', error);
            mostrarNotificacion('Reporte descargado pero no se pudo guardar en el servidor', 'warning');
        });
}

// ============================================
// PDF PARA PROVEEDORES CON TABLAS CENTRADAS
// ============================================
function generarPDFProveedores() {
    const docProv = new jsPDF({
        orientation: "p",
        unit: "mm",
        format: "a4"
    });
    
    const provPageWidth = docProv.internal.pageSize.getWidth();
    const provPageHeight = docProv.internal.pageSize.getHeight();
    let provY = 25;
    let provPageNum = 1;

    // ============================================
    // CARGAR LOGOS (TIENDA + PROVEEDOR)
    // ============================================
    <?php
    // Obtener datos de la tienda
    $sqlConfig = "SELECT nombre, logo FROM configuracion_galeria LIMIT 1";
    $resultConfig = $conn->query($sqlConfig);
    $configTienda = $resultConfig->fetch_assoc();
    $nombreTienda = $configTienda['nombre'] ?? 'PESCADORES DE LA PREHISTORIA';
    $logoTiendaPath = $configTienda['logo'] ?? '';
    
    if (empty($logoTiendaPath) || !file_exists($logoTiendaPath)) {
        $rutasPosibles = [
            '../img/logo.png', '../img/logo.jpg', '../img/panel_principal.jpg',
            '../img/panel_principal.png', '../dist/img/logo.png', '../dist/img/logo.jpg'
        ];
        foreach ($rutasPosibles as $ruta) {
            if (file_exists($ruta)) { $logoTiendaPath = $ruta; break; }
        }
    }
    
    $logoTiendaBase64 = '';
    $logoTiendaExt = 'png';
    if (!empty($logoTiendaPath) && file_exists($logoTiendaPath)) {
        $tipo = mime_content_type($logoTiendaPath);
        $data = file_get_contents($logoTiendaPath);
        $logoTiendaBase64 = "data:" . $tipo . ";base64," . base64_encode($data);
        $ext = pathinfo($logoTiendaPath, PATHINFO_EXTENSION);
        $logoTiendaExt = $ext === 'jpg' ? 'jpeg' : $ext;
    }
    
// Logo del proveedor
$logoProveedorBase64 = '';
$logoProveedorExt = 'png';
$logoProveedorPath = '';
$logoProveedorWidth = 25;
$logoProveedorHeight = 25;
$nombreProveedor = '';
$inicialesProveedor = '';

if ($filtroProveedor !== '') {
    $nombreProveedor = $filtroProveedor;
    $sqlLogoProveedor = "SELECT logo, nombre FROM proveedores WHERE nombre = '" . $conn->real_escape_string($filtroProveedor) . "' LIMIT 1";
    $resultLogoProveedor = $conn->query($sqlLogoProveedor);
    if ($resultLogoProveedor && $row = $resultLogoProveedor->fetch_assoc()) {
        $logoProveedorPath = $row['logo'];
        if (!empty($logoProveedorPath) && file_exists($logoProveedorPath)) {
            // Obtener dimensiones de la imagen
            $size = getimagesize($logoProveedorPath);
            $anchoOriginal = $size[0];
            $altoOriginal = $size[1];
            $ratio = $anchoOriginal / $altoOriginal;
            
            $tipo = mime_content_type($logoProveedorPath);
            $data = file_get_contents($logoProveedorPath);
            $logoProveedorBase64 = "data:" . $tipo . ";base64," . base64_encode($data);
            $ext = pathinfo($logoProveedorPath, PATHINFO_EXTENSION);
            $logoProveedorExt = $ext === 'jpg' ? 'jpeg' : $ext;
            
            // 🔥 USAR EL LADO MÁS PEQUEÑO para decidir el tamaño 🔥
            $ladoMenor = min($anchoOriginal, $altoOriginal);
            
            if ($ladoMenor < 310) {
                $maxSize = 45;  // Logo muy pequeño (menos de 300px en su lado menor)
            } elseif ($ladoMenor < 500) {
                $maxSize = 35;  // Logo pequeño (300-500px)
            } elseif ($ladoMenor > 800) {
                $maxSize = 20;  // Logo muy grande (más de 800px)
            } else {
                $maxSize = 25;  // Tamaño normal (500-800px)
            }
            
            // Calcular tamaño proporcional
            if ($anchoOriginal > $altoOriginal) {
                // Logo horizontal (más ancho que alto)
                $logoProveedorWidth = $maxSize;
                $logoProveedorHeight = $maxSize / $ratio;
            } else {
                // Logo vertical o cuadrado
                $logoProveedorHeight = $maxSize;
                $logoProveedorWidth = $maxSize * $ratio;
            }
        }
    }
    
    // Obtener iniciales del proveedor
    $palabras = explode(' ', $nombreProveedor);
    if (count($palabras) >= 2) {
        $inicialesProveedor = strtoupper(substr($palabras[0], 0, 1) . substr($palabras[1], 0, 1));
    } else {
        $inicialesProveedor = strtoupper(substr($nombreProveedor, 0, 2));
    }
}
    ?>

    function addProvFooter(doc, pageNum, totalPages) {
        doc.setPage(pageNum);
        doc.setFontSize(8);
        doc.setTextColor(colors.medium[0], colors.medium[1], colors.medium[2]);
        doc.setFont("helvetica", "normal");
        doc.setDrawColor(220, 220, 220);
        doc.line(20, provPageHeight - 12, provPageWidth - 20, provPageHeight - 12);
        doc.text(
            `Documento confidencial para proveedores | ${new Date().toLocaleDateString()}`,
            20,
            provPageHeight - 6
        );
        doc.text(
            `Página ${pageNum} de ${totalPages} | Estado de Cuenta`,
            provPageWidth - 20,
            provPageHeight - 6,
            { align: "right" }
        );
    }

    // ============================================
    // ENCABEZADO - LOGO IZQUIERDA, NOMBRE CENTRADO, INICIALES DERECHA
    // ============================================
    
    const logoY = 12;
    const logoSize = 25;
    
    <?php if ($filtroProveedor !== ''): ?>
    // DOS LOGOS (o logo + iniciales)
    // Logo izquierdo (Tienda)
    <?php if (!empty($logoTiendaBase64)): ?>
    try {
        docProv.addImage('<?= $logoTiendaBase64 ?>', '<?= $logoTiendaExt ?>', 15, logoY, logoSize, logoSize);
    } catch(e) {}
    <?php endif; ?>
    
    // Nombre de la tienda CENTRADO
    docProv.setFontSize(12);
    docProv.setTextColor(60, 60, 60);
    docProv.setFont("helvetica", "bold");
    docProv.text("<?= strtoupper($nombreTienda) ?>", provPageWidth / 2, logoY + logoSize/2 + 4, { align: "center" });
    
    <?php if (!empty($logoProveedorBase64)): ?>
    // Logo con tamaño proporcional
    const logoX = provPageWidth - <?= $logoProveedorWidth ?> - 15;
    try {
        docProv.addImage('<?= $logoProveedorBase64 ?>', '<?= $logoProveedorExt ?>', logoX, logoY, <?= $logoProveedorWidth ?>, <?= $logoProveedorHeight ?>);
    } catch(e) {
        // Si falla la imagen, mostrar iniciales
        docProv.setFontSize(26);
        docProv.setTextColor(245, 64, 29);
        docProv.setFont("helvetica", "bold");
        docProv.text("<?= $inicialesProveedor ?>", provPageWidth - 50, logoY + logoSize/2 + 6);
    }
    <?php else: ?>
    docProv.setFontSize(26);
    docProv.setTextColor(245, 64, 29);
    docProv.setFont("helvetica", "bold");
    docProv.text("<?= $inicialesProveedor ?>", provPageWidth - 50, logoY + logoSize/2 + 6);
    <?php endif; ?>
    
    <?php else: ?>
    // UN LOGO (sin filtro de proveedor)
    <?php if (!empty($logoTiendaBase64)): ?>
    try {
        docProv.addImage('<?= $logoTiendaBase64 ?>', '<?= $logoTiendaExt ?>', 15, logoY, logoSize, logoSize);
    } catch(e) {}
    <?php endif; ?>
    
    // Nombre de la tienda CENTRADO
    docProv.setFontSize(14);
    docProv.setTextColor(60, 60, 60);
    docProv.setFont("helvetica", "bold");
    docProv.text("<?= strtoupper($nombreTienda) ?>", provPageWidth / 2, logoY + logoSize/2 + 4, { align: "center" });
    <?php endif; ?>
    
    // Línea decorativa sutil
    docProv.setDrawColor(230, 230, 230);
    docProv.line(15, logoY + logoSize + 8, provPageWidth - 15, logoY + logoSize + 8);
    
    // TITULOS
    docProv.setFontSize(24);
    docProv.setTextColor(colors.danger[0], colors.danger[1], colors.danger[2]);
    docProv.setFont("helvetica", "bold");
    docProv.text("ESTADO DE CUENTA", provPageWidth / 2, 65, { align: "center" });
    docProv.text("PAGO A PROVEEDORES", provPageWidth / 2, 77, { align: "center" });
    
    docProv.setFontSize(10);
    docProv.setTextColor(colors.medium[0], colors.medium[1], colors.medium[2]);
    docProv.text(`Generado: ${new Date().toLocaleString()}`, provPageWidth / 2, 92, { align: "center" });
    
    <?php if ($filtroProveedor !== ''): ?>
    docProv.setFontSize(12);
    docProv.setTextColor(colors.danger[0], colors.danger[1], colors.danger[2]);
    docProv.setFont("helvetica", "bold");
    docProv.text("Proveedor: <?= htmlspecialchars($filtroProveedor) ?>", provPageWidth / 2, 104, { align: "center" });
    <?php endif; ?>
    
    <?php if ($filtroInicio !== '' && $filtroFin !== ''): ?>
    docProv.setFontSize(10);
    docProv.setTextColor(colors.medium[0], colors.medium[1], colors.medium[2]);
    docProv.text(`Período: <?= htmlspecialchars($filtroInicio) ?> al <?= htmlspecialchars($filtroFin) ?>`, provPageWidth / 2, 114, { align: "center" });
    <?php endif; ?>
    
    // --- RESUMEN DE DEUDA (CENTRADO) ---
    provY = 135;
    
    docProv.setFillColor(colors.light[0], colors.light[1], colors.light[2]);
    docProv.roundedRect(40, provY - 10, provPageWidth - 80, 35, 3, 3, "F");
    docProv.setDrawColor(colors.danger[0], colors.danger[1], colors.danger[2]);
    docProv.setLineWidth(0.5);
    docProv.roundedRect(40, provY - 10, provPageWidth - 80, 35, 3, 3, "S");
    
    docProv.setFontSize(10);
    docProv.setTextColor(colors.medium[0], colors.medium[1], colors.medium[2]);
    docProv.text("TOTAL A PAGAR A PROVEEDORES", provPageWidth / 2, provY, { align: "center" });
    
    docProv.setFontSize(26);
    docProv.setTextColor(colors.danger[0], colors.danger[1], colors.danger[2]);
    docProv.setFont("helvetica", "bold");
    docProv.text("$<?= number_format($totalProveedor, 2) ?>", provPageWidth / 2, provY + 15, { align: "center" });
    
    provY += 45;

    // ============================================
    // TABLA 1: DETALLE DE PRODUCTOS VENDIDOS (CENTRADA)
    // ============================================
    docProv.setFontSize(14);
    docProv.setTextColor(colors.dark[0], colors.dark[1], colors.dark[2]);
    docProv.setFont("helvetica", "bold");
    docProv.text("1. Detalle de Productos Vendidos", 20, provY);
    provY += 8;

    const proveedoresDataAgrupados = [
        <?php foreach ($ventasAgrupadas as $venta): ?>
        [
            '<?= addslashes($venta['proveedor']) ?>',
            '<?= addslashes($venta['producto']) ?>',
            <?= $venta['total_vendido'] ?>,
            <?= $venta['stock_actual'] ?>,
            '$<?= number_format($venta['precio_compra'], 2) ?>',
            '<?= $venta['es_producto_pagado'] ? "PAGADO" : "$".number_format($venta['deuda_total'], 2) ?>'
        ],
        <?php endforeach; ?>
    ];

    if (proveedoresDataAgrupados.length > 0) {
        docProv.autoTable({
            head: [['Proveedor', 'Producto', 'Vendidos', 'Stock Restante', 'P.Compra', 'Deuda Total']],
            body: proveedoresDataAgrupados,
            startY: provY,
            theme: "grid",
            headStyles: { 
                fillColor: colors.danger, 
                textColor: colors.white, 
                fontSize: 8, 
                halign: 'center',
                fontStyle: 'bold'
            },
            styles: { 
                fontSize: 7, 
                cellPadding: 3,
                overflow: 'linebreak',
                halign: 'center'
            },
            columnStyles: {
                0: { cellWidth: 30, fontStyle: 'bold', halign: 'left' },
                1: { cellWidth: 35, halign: 'left' },
                2: { cellWidth: 20, halign: 'center' },
                3: { cellWidth: 20, halign: 'center' },
                4: { cellWidth: 23, halign: 'right' },
                5: { cellWidth: 25, halign: 'right', fontStyle: 'bold' }
            },
            margin: { left: 20, right: 20 },
            tableWidth: provPageWidth - 40
        });
        
        provY = docProv.lastAutoTable.finalY + 15;

        // ============================================
        // TABLA 2: STOCK RESTANTE POR PRODUCTO (CENTRADA)
        // ============================================
        if (provY > 220) { docProv.addPage(); provY = 25; provPageNum++; }

        docProv.setFontSize(14);
        docProv.setTextColor(colors.danger[0], colors.danger[1], colors.danger[2]);
        docProv.setFont("helvetica", "bold");
        docProv.text("2. Stock Restante por Producto", 20, provY);
        provY += 8;

        const stockRestanteData = [
            <?php foreach ($todosProductos as $producto): 
                if ($filtroProveedor !== '' && $producto['proveedor'] != $filtroProveedor) continue;
                
                $vendido = 0;
                foreach ($ventasAgrupadas as $venta) {
                    if ($venta['proveedor'] == $producto['proveedor'] && $venta['producto'] == $producto['nombre']) {
                        $vendido = $venta['total_vendido'];
                        break;
                    }
                }
                $stockActual = $producto['stock_actual'];
                $stockInicial = $stockActual + $vendido;
            ?>
            [
                '<?= addslashes($producto['proveedor']) ?>',
                '<?= addslashes($producto['nombre']) ?>',
                <?= $stockInicial ?>,
                <?= $vendido ?>,
                <?= $stockActual ?>,
                '<?= $producto['es_producto_pagado'] ? "PAGADO" : "" ?>'
            ],
            <?php endforeach; ?>
        ];

        docProv.autoTable({
            head: [['Proveedor', 'Producto', 'Stock Inicial', 'Vendidos', 'Stock Restante', 'Estado']],
            body: stockRestanteData,
            startY: provY,
            theme: "striped",
            headStyles: { fillColor: colors.danger, textColor: colors.white, fontSize: 8, halign: 'center' },
            styles: { fontSize: 7, cellPadding: 3, halign: 'center' },
            columnStyles: {
                0: { cellWidth: 35, fontStyle: 'bold', halign: 'left' },
                1: { cellWidth: 45, halign: 'left' },
                2: { cellWidth: 22, halign: 'center' },
                3: { cellWidth: 20, halign: 'center' },
                4: { cellWidth: 22, halign: 'center', fontStyle: 'bold' },
                5: { cellWidth: 20, halign: 'center' }
            },
            margin: { left: 20, right: 20 },
            tableWidth: provPageWidth - 40
        });
        
        provY = docProv.lastAutoTable.finalY + 15;

        // ============================================
        // TABLA 3: RESUMEN POR PROVEEDOR (CENTRADA)
        // ============================================
        if (provY > 240) { docProv.addPage(); provY = 25; provPageNum++; }
        
        docProv.setFontSize(14);
        docProv.setTextColor(colors.danger[0], colors.danger[1], colors.danger[2]);
        docProv.setFont("helvetica", "bold");
        docProv.text("3. Resumen por Proveedor", 20, provY);
        provY += 8;

        const resumenProvData = [
            <?php 
            ksort($deudaPorProveedor);
            foreach ($deudaPorProveedor as $prov => $total): 
            ?>
            ['<?= addslashes($prov) ?>', '$<?= number_format($total, 2) ?>'],
            <?php endforeach; ?>
            ['', ''],
            ['TOTAL GENERAL', '$<?= number_format($totalProveedor, 2) ?>']
        ];

        // Calcular ancho de la tabla para centrarla
        const resumenAncho = 140;
        const resumenMargen = (provPageWidth - resumenAncho) / 2;
        
        docProv.autoTable({
            startY: provY,
            body: resumenProvData,
            theme: "plain",
            styles: { fontSize: 10, cellPadding: 6, halign: 'center' },
            columnStyles: {
                0: { cellWidth: 80, fontStyle: 'bold', halign: 'right' },
                1: { cellWidth: 60, halign: 'right', fontStyle: 'bold' }
            },
            margin: { left: resumenMargen, right: resumenMargen }
        });
    } else {
        docProv.setFontSize(14);
        docProv.setTextColor(colors.medium[0], colors.medium[1], colors.medium[2]);
        docProv.text("No hay ventas registradas en el período seleccionado.", provPageWidth / 2, provY + 20, { align: "center" });
    }

    const totalPagesProv = docProv.internal.getNumberOfPages();
    for (let i = 1; i <= totalPagesProv; i++) {
        addProvFooter(docProv, i, totalPagesProv);
    }

        const pdfBlob = docProv.output('blob');
        const pdfUrl = URL.createObjectURL(pdfBlob);

        // Primero descargar
        const link = document.createElement('a');
        link.href = pdfUrl;
        link.download = '<?= $nombreArchivoProveedor ?>';
        document.body.appendChild(link);
        link.click();
        document.body.removeChild(link);

        // Luego abrir en nueva ventana
        const nuevaVentana = window.open(pdfUrl, '_blank');

        // Revocar la URL después de un tiempo suficiente (3 segundos)
        setTimeout(() => {
            URL.revokeObjectURL(pdfUrl);
        }, 3000);

        guardarPDFenServidor(pdfBlob, '<?= $nombreArchivoProveedor ?>', 'proveedor')
            .then(() => {
                console.log('PDF guardado en servidor');
                mostrarNotificacion('Reporte guardado exitosamente', 'success');
            })
            .catch(error => {
                console.error('Error al guardar PDF:', error);
                mostrarNotificacion('Reporte descargado pero no se pudo guardar en el servidor', 'warning');
            });
        }

    // Función para mostrar notificaciones
    function mostrarNotificacion(mensaje, tipo) {
        // Crear elemento de notificación si no existe
        let notificacion = document.getElementById('notificacion-pdf');
        if (!notificacion) {
            notificacion = document.createElement('div');
            notificacion.id = 'notificacion-pdf';
            notificacion.style.position = 'fixed';
            notificacion.style.top = '20px';
            notificacion.style.right = '20px';
            notificacion.style.padding = '15px 20px';
            notificacion.style.borderRadius = '5px';
            notificacion.style.color = 'white';
            notificacion.style.fontWeight = 'bold';
            notificacion.style.zIndex = '9999';
            notificacion.style.boxShadow = '0 4px 6px rgba(0,0,0,0.1)';
            document.body.appendChild(notificacion);
        }
        
        // Configurar estilo según tipo
        if (tipo === 'success') {
            notificacion.style.backgroundColor = '#4CAF50';
        } else if (tipo === 'warning') {
            notificacion.style.backgroundColor = '#ff9800';
        } else {
            notificacion.style.backgroundColor = '#f44336';
        }
        
        notificacion.textContent = mensaje;
        notificacion.style.display = 'block';
        
        // Ocultar después de 3 segundos
        setTimeout(() => {
            notificacion.style.display = 'none';
        }, 3000);
    }

    // Ejecutar según el tipo seleccionado
    if (tipo === 'proveedor') {
        generarPDFProveedores();
    } else if (tipo === 'general') {
        generarPDFGeneral();
    } else if (tipo === 'ambos') {
        generarPDFGeneral();
        setTimeout(() => {
            generarPDFProveedores();
        }, 500);
    }
    
    closeDropdown();
}

// Event listeners para los botones del dropdown
const pdfProveedor = document.getElementById('pdfProveedor');
const pdfGeneral = document.getElementById('pdfGeneral');
const pdfAmbos = document.getElementById('pdfAmbos');

if (pdfProveedor) pdfProveedor.addEventListener('click', () => generarPDF('proveedor'));
if (pdfGeneral) pdfGeneral.addEventListener('click', () => generarPDF('general'));
if (pdfAmbos) pdfAmbos.addEventListener('click', () => generarPDF('ambos'));

// Prevenir que los botones del dropdown cierren el dropdown sin generar PDF
document.querySelectorAll('.pdf-dropdown-btn').forEach(btn => {
    btn.addEventListener('click', (e) => {
        e.stopPropagation();
    });
});

// ==================== PAGINACIÓN Y ORDENAMIENTO PARA TABLAS ====================
class TablaDinamica {
    constructor(tablaId, tbodyId, itemsPorPaginaId, paginacionId, infoIds) {
        this.tabla = document.getElementById(tablaId);
        this.tbody = document.getElementById(tbodyId);
        this.itemsPorPaginaSelect = document.getElementById(itemsPorPaginaId);
        this.paginacionDiv = document.getElementById(paginacionId);
        this.infoDesde = document.getElementById(infoIds.desde);
        this.infoHasta = document.getElementById(infoIds.hasta);
        this.infoTotal = document.getElementById(infoIds.total);
        
        this.datos = [];
        this.paginaActual = 1;
        this.itemsPorPagina = 10;
        this.columnaOrden = 0;
        this.direccionOrden = 'asc';
        
        this.init();
    }
    
    init() {
        this.cargarDatos();
        
        // ✅ Si no hay datos, no inicializar paginación ni sorting
        if (this.datos.length === 0) {
            this.hidePaginationControls();
            return;
        }
        
        if (this.itemsPorPaginaSelect) {
            this.itemsPorPaginaSelect.addEventListener('change', (e) => {
                this.itemsPorPagina = parseInt(e.target.value);
                this.paginaActual = 1;
                this.renderizar();
            });
        }
        
        if (this.tabla) {
            this.tabla.querySelectorAll('.sortable').forEach((th, index) => {
                th.addEventListener('click', () => this.ordenarPor(index));
            });
        }
        
        this.renderizar();
    }
    
    hidePaginationControls() {
        if (this.itemsPorPaginaSelect && this.itemsPorPaginaSelect.closest('.pagination-controls')) {
            this.itemsPorPaginaSelect.closest('.pagination-controls').style.display = 'none';
        }
        if (this.paginacionDiv) this.paginacionDiv.style.display = 'none';
        if (this.infoDesde && this.infoDesde.closest('.pagination-info')) {
            this.infoDesde.closest('.pagination-info').style.display = 'none';
        }
    }
    
    cargarDatos() {
        const filas = this.tbody.querySelectorAll('tr');
        this.datos = Array.from(filas)
            .filter(fila => {
                const celdas = fila.querySelectorAll('td');
                if (celdas.length <= 1) return false;
                // Verificar si es el mensaje de "no datos"
                if (celdas.length === 1 && celdas[0].innerText.includes('No hay')) return false;
                const texto = Array.from(celdas).map(c => c.innerText.trim()).join('');
                if (texto === '') return false;
                return true;
            })
            .map(fila => {
                return Array.from(fila.querySelectorAll('td')).map(celda => celda.innerText.trim());
            });
    }
    
    ordenarPor(columna) {
        if (this.columnaOrden === columna) {
            this.direccionOrden = this.direccionOrden === 'asc' ? 'desc' : 'asc';
        } else {
            this.columnaOrden = columna;
            this.direccionOrden = 'asc';
        }
        
        if (this.tabla) {
            this.tabla.querySelectorAll('.sortable').forEach(th => {
                th.classList.remove('asc', 'desc');
            });
            const thActual = this.tabla.querySelector(`.sortable[data-column="${columna}"]`);
            if (thActual) thActual.classList.add(this.direccionOrden);
        }
        
        this.ordenarDatos();
        this.paginaActual = 1;
        this.renderizar();
    }
    
    ordenarDatos() {
        const columna = this.columnaOrden;
        const direccion = this.direccionOrden;
        const esNumero = (valor) => {
            const limpio = valor.replace(/[$,]/g, '');
            return !isNaN(parseFloat(limpio)) && isFinite(limpio);
        };
        
        this.datos.sort((a, b) => {
            let valA = a[columna];
            let valB = b[columna];
            
            if (esNumero(valA) && esNumero(valB)) {
                valA = parseFloat(valA.replace(/[$,]/g, ''));
                valB = parseFloat(valB.replace(/[$,]/g, ''));
            }
            
            if (valA < valB) return direccion === 'asc' ? -1 : 1;
            if (valA > valB) return direccion === 'asc' ? 1 : -1;
            return 0;
        });
    }
    
renderizar() {
    if (this.datos.length === 0) {
        this.tbody.innerHTML = '<tr><td colspan="7" class="text-center py-5"><div class="no-data-message"><i class="fas fa-box-open" style="font-size: 4rem; color: #dee2e6;"></i><p class="mt-3 mb-0">No hay datos para mostrar</p></div></td></tr>';
        if (this.itemsPorPaginaSelect && this.itemsPorPaginaSelect.closest('.pagination-controls')) {
            this.itemsPorPaginaSelect.closest('.pagination-controls').style.display = 'none';
        }
        if (this.paginacionDiv) this.paginacionDiv.style.display = 'none';
        if (this.infoDesde && this.infoDesde.closest('.pagination-info')) {
            this.infoDesde.closest('.pagination-info').style.display = 'none';
        }
        return;
    }
    
    const inicio = (this.paginaActual - 1) * this.itemsPorPagina;
    const fin = inicio + this.itemsPorPagina;
    const paginaDatos = this.datos.slice(inicio, fin);
    const totalPaginas = Math.ceil(this.datos.length / this.itemsPorPagina);
    
    this.tbody.innerHTML = '';
    paginaDatos.forEach(filaData => {
        const fila = document.createElement('tr');
        
        filaData.forEach((celdaData, index) => {
            const celda = document.createElement('td');
            
            // CENTRAR TODAS LAS CELDAS
            celda.style.textAlign = 'center';
            celda.style.verticalAlign = 'middle';
            
            // Detectar si es la columna de Adquisición (última columna)
            const esUltimaColumna = index === filaData.length - 1;
            
            if (esUltimaColumna && (celdaData.includes('Pagado') || celdaData.includes('PAGADO'))) {
                // Solo el badge con color, sin animaciones
                celda.innerHTML = `<span style="background: #10b981; color: white; padding: 4px 12px; border-radius: 20px; font-size: 0.75rem; font-weight: 600; display: inline-flex; align-items: center; gap: 5px;">
                                    <i class="fas fa-check-circle"></i> Pagado
                                </span>`;
            } 
            else if (esUltimaColumna && (celdaData.includes('Concesión') || celdaData.includes('concesion'))) {
                celda.innerHTML = `<span style="background: #f59e0b; color: white; padding: 4px 12px; border-radius: 20px; font-size: 0.75rem; font-weight: 600; display: inline-flex; align-items: center; gap: 5px;">
                                    <i class="fas fa-handshake"></i> Concesión
                                </span>`;
            }
            else {
                celda.innerHTML = celdaData;
            }
            
            fila.appendChild(celda);
        });
        this.tbody.appendChild(fila);
    });
    
    const desde = this.datos.length > 0 ? inicio + 1 : 0;
    const hasta = Math.min(fin, this.datos.length);
    if (this.infoDesde) this.infoDesde.textContent = desde;
    if (this.infoHasta) this.infoHasta.textContent = hasta;
    if (this.infoTotal) this.infoTotal.textContent = this.datos.length;
    
    this.renderizarPaginacion(totalPaginas);
    
    if (this.itemsPorPaginaSelect && this.itemsPorPaginaSelect.closest('.pagination-controls')) {
        this.itemsPorPaginaSelect.closest('.pagination-controls').style.display = 'flex';
    }
    if (this.paginacionDiv) this.paginacionDiv.style.display = 'block';
    if (this.infoDesde && this.infoDesde.closest('.pagination-info')) {
        this.infoDesde.closest('.pagination-info').style.display = 'block';
    }
}
    
    renderizarPaginacion(totalPaginas) {
        if (!this.paginacionDiv) return;
        
        if (totalPaginas <= 1) {
            this.paginacionDiv.innerHTML = '';
            return;
        }
        
        let html = '<ul class="pagination mb-0">';
        
        if (this.paginaActual > 1) {
            html += `<li class="page-item"><a class="page-link" href="#" data-pagina="${this.paginaActual - 1}">«</a></li>`;
        } else {
            html += `<li class="page-item disabled"><span class="page-link">«</span></li>`;
        }
        
        const inicio = Math.max(1, this.paginaActual - 2);
        const fin = Math.min(totalPaginas, this.paginaActual + 2);
        
        if (inicio > 1) {
            html += `<li class="page-item"><a class="page-link" href="#" data-pagina="1">1</a></li>`;
            if (inicio > 2) html += `<li class="page-item disabled"><span class="page-link">...</span></li>`;
        }
        
        for (let i = inicio; i <= fin; i++) {
            const active = i === this.paginaActual ? 'active' : '';
            html += `<li class="page-item ${active}"><a class="page-link" href="#" data-pagina="${i}">${i}</a></li>`;
        }
        
        if (fin < totalPaginas) {
            if (fin < totalPaginas - 1) html += `<li class="page-item disabled"><span class="page-link">...</span></li>`;
            html += `<li class="page-item"><a class="page-link" href="#" data-pagina="${totalPaginas}">${totalPaginas}</a></li>`;
        }
        
        if (this.paginaActual < totalPaginas) {
            html += `<li class="page-item"><a class="page-link" href="#" data-pagina="${this.paginaActual + 1}">»</a></li>`;
        } else {
            html += `<li class="page-item disabled"><span class="page-link">»</span></li>`;
        }
        
        html += '</ul>';
        this.paginacionDiv.innerHTML = html;
        
        this.paginacionDiv.querySelectorAll('.page-link').forEach(link => {
            link.addEventListener('click', (e) => {
                e.preventDefault();
                const pagina = parseInt(link.getAttribute('data-pagina'));
                if (!isNaN(pagina) && pagina !== this.paginaActual) {
                    this.paginaActual = pagina;
                    this.renderizar();
                }
            });
        });
    }
}

// Inicializar tablas solo si hay datos
document.addEventListener('DOMContentLoaded', function() {
    // Verificar si hay datos reales en la tabla de productos
    const tablaProductosBody = document.getElementById('tablaProductosBody');
    const hayDatosProductos = tablaProductosBody && 
        tablaProductosBody.querySelectorAll('tr').length > 0 &&
        !tablaProductosBody.querySelector('tr td[colspan]');
    
    if (hayDatosProductos) {
        new TablaDinamica(
            'tablaProductos', 'tablaProductosBody', 'productosPorPagina', 'paginacionProductos',
            { desde: 'productosDesde', hasta: 'productosHasta', total: 'productosTotal' }
        );
    }
    
    // Verificar si hay datos reales en la tabla de deuda
    const tablaDeudaBody = document.getElementById('tablaDeudaBody');
    const hayDatosDeuda = tablaDeudaBody && 
        tablaDeudaBody.querySelectorAll('tr').length > 0 &&
        !tablaDeudaBody.querySelector('tr td[colspan]');
    
    if (hayDatosDeuda) {
        new TablaDinamica(
            'tablaDeuda', 'tablaDeudaBody', 'deudaPorPagina', 'paginacionDeuda',
            { desde: 'deudaDesde', hasta: 'deudaHasta', total: 'deudaTotal' }
        );
    }
});

// ==================== FILTROS EN TIEMPO REAL CORREGIDOS ====================
// Función para aplicar filtros con prevención de múltiples envíos
let filtroTimeout = null;
let isApplyingFilter = false;

function aplicarFiltrosEnTiempoReal() {
    // Prevenir múltiples aplicaciones simultáneas
    if (isApplyingFilter) return;
    
    const proveedor = document.getElementById('filtroProveedor')?.value || '';
    const fechaInicio = document.getElementById('filtroFechaInicio')?.value || '';
    const fechaFin = document.getElementById('filtroFechaFin')?.value || '';
    
    let url = new URL(window.location.href);
    let hasChanges = false;
    
    if (proveedor && proveedor !== '') {
        if (url.searchParams.get('proveedor') !== proveedor) {
            url.searchParams.set('proveedor', proveedor);
            hasChanges = true;
        }
    } else {
        if (url.searchParams.has('proveedor')) {
            url.searchParams.delete('proveedor');
            hasChanges = true;
        }
    }
    
    if (fechaInicio && fechaInicio !== '') {
        if (url.searchParams.get('fecha_inicio') !== fechaInicio) {
            url.searchParams.set('fecha_inicio', fechaInicio);
            hasChanges = true;
        }
    } else {
        if (url.searchParams.has('fecha_inicio')) {
            url.searchParams.delete('fecha_inicio');
            hasChanges = true;
        }
    }
    
    if (fechaFin && fechaFin !== '') {
        if (url.searchParams.get('fecha_fin') !== fechaFin) {
            url.searchParams.set('fecha_fin', fechaFin);
            hasChanges = true;
        }
    } else {
        if (url.searchParams.has('fecha_fin')) {
            url.searchParams.delete('fecha_fin');
            hasChanges = true;
        }
    }
    
    if (hasChanges) {
        // Mostrar indicador de carga
        const loadingDiv = document.querySelector('.filtro-loading');
        if (loadingDiv) loadingDiv.classList.add('active');
        isApplyingFilter = true;
        
        // Redirigir después de un pequeño delay para evitar múltiples redirecciones
        setTimeout(() => {
            window.location.href = url.toString();
        }, 100);
    }
}

// Configurar eventos de los filtros en tiempo real
document.addEventListener('DOMContentLoaded', function() {
    const filtroProveedor = document.getElementById('filtroProveedor');
    const filtroFechaInicio = document.getElementById('filtroFechaInicio');
    const filtroFechaFin = document.getElementById('filtroFechaFin');
    const limpiarFiltrosBtn = document.getElementById('limpiarFiltrosBtn');
    
    function triggerFiltros() {
        if (filtroTimeout) clearTimeout(filtroTimeout);
        filtroTimeout = setTimeout(function() {
            aplicarFiltrosEnTiempoReal();
        }, 500);
    }
    
    if (filtroProveedor) {
        filtroProveedor.addEventListener('change', triggerFiltros);
    }
    
    if (filtroFechaInicio) {
        filtroFechaInicio.addEventListener('change', triggerFiltros);
    }
    
    if (filtroFechaFin) {
        filtroFechaFin.addEventListener('change', triggerFiltros);
    }
    
    if (limpiarFiltrosBtn) {
        limpiarFiltrosBtn.addEventListener('click', function(e) {
            e.preventDefault();
            // Limpiar los selects manualmente antes de redirigir
            if (filtroProveedor) filtroProveedor.value = '';
            if (filtroFechaInicio) filtroFechaInicio.value = '';
            if (filtroFechaFin) filtroFechaFin.value = '';
            // Redirigir a la página sin parámetros
            window.location.href = window.location.pathname;
        });
    }
});

// Forzar redimensionamiento del calendario al colapsar sidebar
function redimensionarCalendario() {
    if (calendar) {
        setTimeout(() => {
            calendar.updateSize();
            if (typeof cargarEventosCalendario === 'function') {
                cargarEventosCalendario();
            }
        }, 300);
    }
}

// Detectar cuando se colapsa/expande el sidebar de AdminLTE
document.addEventListener('DOMContentLoaded', function() {
    const sidebarToggle = document.querySelector('[data-widget="pushmenu"], .sidebar-toggle, [data-widget="collapse"]');
    
    if (sidebarToggle) {
        sidebarToggle.addEventListener('click', function() {
            redimensionarCalendario();
        });
    }
    
    let resizeTimeout;
    window.addEventListener('resize', function() {
        clearTimeout(resizeTimeout);
        resizeTimeout = setTimeout(function() {
            if (calendar) {
                calendar.updateSize();
            }
        }, 250);
    });
    
    const sidebar = document.querySelector('.main-sidebar');
    if (sidebar && window.MutationObserver) {
        const observer = new MutationObserver(function(mutations) {
            mutations.forEach(function(mutation) {
                if (mutation.attributeName === 'class') {
                    redimensionarCalendario();
                }
            });
        });
        observer.observe(sidebar, { attributes: true });
    }
});

window.addEventListener('load', function() {
    setTimeout(() => {
        if (calendar) {
            calendar.updateSize();
        }
    }, 500);
});

// Forzar centrado de TODOS los encabezados y celdas
function centrarTablas() {
    // Para tabla de productos
    var thsProductos = document.querySelectorAll('#tablaProductos th');
    var tdsProductos = document.querySelectorAll('#tablaProductos td');
    
    // Para tabla de deuda
    var thsDeuda = document.querySelectorAll('#tablaDeuda th');
    var tdsDeuda = document.querySelectorAll('#tablaDeuda td');
    
    // Centrar encabezados productos
    thsProductos.forEach(function(th) {
        th.style.textAlign = 'center';
        th.style.verticalAlign = 'middle';
    });
    
    // Centrar celdas productos
    tdsProductos.forEach(function(td) {
        td.style.textAlign = 'center';
        td.style.verticalAlign = 'middle';
    });
    
    // Centrar encabezados deuda
    thsDeuda.forEach(function(th) {
        th.style.textAlign = 'center';
        th.style.verticalAlign = 'middle';
    });
    
    // Centrar celdas deuda
    tdsDeuda.forEach(function(td) {
        td.style.textAlign = 'center';
        td.style.verticalAlign = 'middle';
    });
}

// Ejecutar al cargar
centrarTablas();

// También ejecutar después de cada cambio en las tablas (por si la paginación las recarga)
var observer = new MutationObserver(function() {
    centrarTablas();
});

// Observar cambios en las tablas
var tablaProductos = document.getElementById('tablaProductos');
var tablaDeuda = document.getElementById('tablaDeuda');
if (tablaProductos) observer.observe(tablaProductos, { childList: true, subtree: true });
if (tablaDeuda) observer.observe(tablaDeuda, { childList: true, subtree: true });
</script>