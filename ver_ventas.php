<?php
include 'includes/db.php';
include('includes/header.php');
include('includes/navbar.php');

// ======================= PRODUCTO ESPECIAL (PAGADO) =======================
// Este producto NO debe aparecer en la deuda con proveedores
define('PRODUCTO_ESPECIAL_NOMBRE', 'libretas');
define('PROVEEDOR_ESPECIAL', 'Nevaris 3D');

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
    SUM(v.cantidad_vendida) AS total_vendido,
    COUNT(v.id) AS numero_ventas,
    
    -- Deuda: es 0 para el producto especial, precio_compra * vendidos para los demás
    CASE 
        WHEN LOWER(p.nombre) LIKE LOWER('%".PRODUCTO_ESPECIAL_NOMBRE."%') 
             AND LOWER(p.proveedor) LIKE LOWER('%".PROVEEDOR_ESPECIAL."%') 
        THEN 0
        ELSE (p.precio_compra * SUM(v.cantidad_vendida))
    END AS deuda_total,
    
    -- Venta total (siempre se calcula normal, independientemente del producto)
    (p.precio_venta * SUM(v.cantidad_vendida)) AS venta_total,
    
    -- Ganancia total (venta - compra, normal para todos)
    ((p.precio_venta - p.precio_compra) * SUM(v.cantidad_vendida)) AS ganancia_total,
    
    -- Indicador de producto especial (para mostrarlo en la tabla)
    CASE 
        WHEN LOWER(p.nombre) LIKE LOWER('%".PRODUCTO_ESPECIAL_NOMBRE."%') 
             AND LOWER(p.proveedor) LIKE LOWER('%".PROVEEDOR_ESPECIAL."%') 
        THEN 1
        ELSE 0
    END AS es_producto_especial
    
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
        
        // Solo acumular deuda si NO es el producto especial
        if (!$row['es_producto_especial']) {
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
    CASE 
        WHEN LOWER(nombre) LIKE LOWER('%".PRODUCTO_ESPECIAL_NOMBRE."%') 
             AND LOWER(proveedor) LIKE LOWER('%".PROVEEDOR_ESPECIAL."%') 
        THEN 1
        ELSE 0
    END AS es_producto_especial
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
    CASE 
        WHEN LOWER(p.nombre) LIKE LOWER('%".PRODUCTO_ESPECIAL_NOMBRE."%') 
             AND LOWER(p.proveedor) LIKE LOWER('%".PROVEEDOR_ESPECIAL."%') 
        THEN 1
        ELSE 0
    END AS es_producto_especial,
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
WHERE 1
";

if ($filtroProveedor !== '') {
    $sql .= " AND p.proveedor = '" . $conn->real_escape_string($filtroProveedor) . "'";
}

$sql .= " HAVING total_vendida > 0 ORDER BY p.nombre ASC";

$resultado = $conn->query($sql);

// --- Variables para la vista ---
$totalGanancia = $totalProveedor = $totalVendidos = $totalStock = $totalVentas = 0;
$productos = [];

if ($resultado) {
    while ($row = $resultado->fetch_assoc()) {
        $vendidos = (int)$row['total_vendida'];
        $stock = (int)$row['cantidad'];

        $ganancia = ($row['precio_venta'] - $row['precio_compra']) * $vendidos;
        
        // Para la deuda, si es producto especial, la deuda es 0
        if ($row['es_producto_especial']) {
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
            'es_especial' => $row['es_producto_especial']
        ];
    }
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
    if ($v['es_producto_especial']) {
        $hayProductoEspecial = true;
        break;
    }
}

?>

<style>
/* Mantener el estilo original de AdminLTE */
.table td, .table th {
    white-space: nowrap;
    padding: 12px 16px !important;
    font-size: 14px;
}

.table-responsive {
    overflow-x: auto;
}

.content-wrapper {
    min-height: 100vh;
    padding-bottom: 40px;
}

table {
    width: 100% !important;
    white-space: normal !important;
}

/* Pequeñas mejoras visuales */
.small-box {
    border-radius: 8px;
}

.small-box .inner {
    padding: 15px;
}

.small-box h3 {
    font-size: 2rem;
    font-weight: bold;
}

.small-box p {
    font-size: 0.9rem;
}

.small-box-footer {
    background: rgba(0,0,0,0.1);
    padding: 8px;
    font-size: 0.85rem;
    color: white;
}

.badge {
    font-size: 12px;
    padding: 5px 8px;
}

/* Estilo para el botón de reinicio */
.btn-reset {
    background-color: #6c757d;
    border-color: #6c757d;
    color: white;
}

.btn-reset:hover {
    background-color: #5a6268;
    border-color: #545b62;
    color: white;
}

/* Mini gráfico de porcentaje en cards */
.mini-progress {
    height: 4px;
    background: rgba(255,255,255,0.3);
    border-radius: 2px;
    margin-top: 8px;
}

.mini-progress-bar {
    height: 4px;
    border-radius: 2px;
    background: white;
}

/* Resumen financiero */
.financial-summary {
    background: #f8f9fa;
    border-radius: 8px;
    padding: 15px;
    margin-top: 15px;
}

.financial-summary table {
    margin-bottom: 0;
}

.financial-summary td {
    padding: 8px 10px;
    border: none;
}

.financial-summary .label {
    font-weight: 600;
    color: #495057;
}

.financial-summary .value {
    font-weight: bold;
    text-align: right;
}

/* ESTILOS PARA EL DROPDOWN PDF */
.pdf-dropdown-container {
    position: relative;
    display: inline-block;
}

.pdf-dropdown-menu {
    position: absolute;
    top: 100%;
    right: 0;
    margin-top: 8px;
    background: white;
    border-radius: 12px;
    box-shadow: 0 10px 40px rgba(0, 0, 0, 0.15), 0 0 0 1px rgba(0, 0, 0, 0.05);
    width: 320px;
    opacity: 0;
    visibility: hidden;
    transform: translateY(-10px);
    transition: all 0.2s ease;
    z-index: 1000;
    pointer-events: none;
}

.pdf-dropdown-menu.active {
    opacity: 1;
    visibility: visible;
    transform: translateY(0);
    pointer-events: all;
}

/* Flecha superior del dropdown */
.pdf-dropdown-menu::before {
    content: '';
    position: absolute;
    top: -6px;
    right: 20px;
    width: 12px;
    height: 12px;
    background: white;
    transform: rotate(45deg);
    box-shadow: -2px -2px 5px rgba(0, 0, 0, 0.04);
    border-left: 1px solid rgba(0, 0, 0, 0.05);
    border-top: 1px solid rgba(0, 0, 0, 0.05);
}

.pdf-dropdown-header {
    padding: 16px 20px;
    border-bottom: 1px solid #e9ecef;
    display: flex;
    align-items: center;
    justify-content: space-between;
}

.pdf-dropdown-header h3 {
    margin: 0;
    font-size: 1.1rem;
    font-weight: 600;
    color: #2c3e50;
}

.pdf-dropdown-header .close-btn {
    background: none;
    border: none;
    font-size: 20px;
    cursor: pointer;
    color: #95a5a6;
    transition: color 0.2s;
    line-height: 1;
    padding: 0 5px;
}

.pdf-dropdown-header .close-btn:hover {
    color: #e74c3c;
}

.pdf-dropdown-body {
    padding: 16px;
}

.pdf-dropdown-options {
    display: flex;
    flex-direction: column;
    gap: 8px;
}

.pdf-dropdown-btn {
    padding: 12px 16px;
    border: 1px solid #e9ecef;
    border-radius: 8px;
    background: white;
    font-size: 0.95rem;
    font-weight: 500;
    color: #2c3e50;
    cursor: pointer;
    transition: all 0.2s;
    display: flex;
    align-items: center;
    width: 100%;
    text-align: left;
}

.pdf-dropdown-btn:hover {
    background: #f8f9fa;
    border-color: #3498db;
    transform: translateX(2px);
    box-shadow: 0 2px 8px rgba(52, 152, 219, 0.1);
}

.pdf-dropdown-btn .btn-icon {
    font-size: 1.2rem;
    margin-right: 12px;
    width: 24px;
    text-align: center;
}

.pdf-dropdown-btn .btn-text {
    flex: 1;
}

.pdf-dropdown-btn .btn-text strong {
    display: block;
    font-size: 0.95rem;
    margin-bottom: 2px;
}

.pdf-dropdown-btn .btn-text small {
    font-size: 0.8rem;
    color: #7f8c8d;
    font-weight: normal;
}

.pdf-dropdown-footer {
    padding: 12px 20px;
    border-top: 1px solid #e9ecef;
    font-size: 0.8rem;
    color: #7f8c8d;
    background: #f8f9fa;
    border-bottom-left-radius: 12px;
    border-bottom-right-radius: 12px;
}

/* Colores para cada opción */
.btn-proveedor:hover {
    border-left: 4px solid #e74c3c;
}

.btn-general:hover {
    border-left: 4px solid #27ae60;
}

.btn-ambos:hover {
    border-left: 4px solid #3498db;
}

/* Overlay invisible para cerrar al hacer clic fuera */
.dropdown-overlay {
    position: fixed;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    z-index: 999;
    display: none;
}

.dropdown-overlay.active {
    display: block;
}

/* Estilos para el calendario */
#calendario {
    background: white;
    border-radius: 8px;
    padding: 15px;
    box-shadow: 0 2px 10px rgba(0,0,0,0.05);
    min-height: 400px;
}

.fc-toolbar-title {
    font-size: 1.2rem !important;
    font-weight: 600 !important;
    color: #2c3e50;
}

.fc-button-primary {
    background-color: #3498db !important;
    border-color: #3498db !important;
}

.fc-button-primary:hover {
    background-color: #2980b9 !important;
    border-color: #2980b9 !important;
}

.fc-day-today {
    background-color: #fff3e0 !important;
}

.fc-daygrid-day {
    cursor: pointer;
    transition: all 0.2s;
}

.fc-daygrid-day:hover {
    background-color: #f8f9fa !important;
}

/* Ajuste para móviles */
@media (max-width: 768px) {
    .pdf-dropdown-menu {
        width: 290px;
        right: -10px;
    }
    
    .pdf-dropdown-menu::before {
        right: 25px;
    }
}

#calendario {
    background: white;
    border-radius: 8px;
    padding: 15px;
    box-shadow: 0 2px 10px rgba(0,0,0,0.05);
    min-height: 450px;
}

.fc-toolbar-title {
    font-size: 1.2rem !important;
    font-weight: 600 !important;
    color: #2c3e50;
}

.fc-button-primary {
    background-color: #3498db !important;
    border-color: #3498db !important;
}

.fc-button-primary:hover {
    background-color: #2980b9 !important;
    border-color: #2980b9 !important;
}

.fc-day-today {
    background-color: #fff3e0 !important;
}

.fc-daygrid-day {
    cursor: pointer;
    transition: all 0.2s;
}

.fc-daygrid-day:hover {
    background-color: #f8f9fa !important;
}

/* Estilo para los días marcados */
.fc-daygrid-day.fc-day-venta .fc-daygrid-day-number {
    background-color: #28a745;
    color: white;
    border-radius: 50%;
    width: 28px;
    height: 28px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    margin: 2px;
}

.fc-daygrid-day.fc-day-reporte .fc-daygrid-day-number {
    background-color: #dc3545;
    color: white;
    border-radius: 50%;
    width: 28px;
    height: 28px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    margin: 2px;
}

.fc-daygrid-day.fc-day-ambos .fc-daygrid-day-number {
    background-color: #ffc107;
    color: #000;
    border-radius: 50%;
    width: 28px;
    height: 28px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    margin: 2px;
    font-weight: bold;
}

/* Tooltip personalizado */
.fc-daygrid-day[data-tooltip] {
    position: relative;
}

.fc-daygrid-day[data-tooltip]:hover::after {
    content: attr(data-tooltip);
    position: absolute;
    bottom: 100%;
    left: 50%;
    transform: translateX(-50%);
    background: #2c3e50;
    color: white;
    padding: 4px 8px;
    border-radius: 4px;
    font-size: 11px;
    white-space: nowrap;
    z-index: 1000;
    margin-bottom: 5px;
    pointer-events: none;
    box-shadow: 0 2px 5px rgba(0,0,0,0.2);
}

/* Forzar colores sólidos en los eventos de fondo */
.evento-venta {
    background-color: #38aa5389 !important;
    opacity: 1 !important;
}

.evento-reporte {
    background-color: #dc35469e !important;
    opacity: 1 !important;
}

.evento-ambos {
    background-color: #ffc107a7 !important;
    opacity: 1 !important;
}

/* Asegurar que no haya opacidad heredada */
.fc-bg-event {
    opacity: 1 !important;
}

.fc-daygrid-bg-harness {
    opacity: 1 !important;
}

/* =====================================================
   ESTILOS PARA PAGINACIÓN Y ORDENAMIENTO
   ===================================================== */
/* Controles de paginación */
.pagination-controls {
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-wrap: wrap;
    gap: 15px;
    margin-top: 20px;
    padding-top: 15px;
    border-top: 1px solid #e9ecef;
}

.records-per-page {
    display: flex;
    align-items: center;
    gap: 8px;
}

.records-per-page label {
    margin: 0;
    font-size: 0.85rem;
    color: #6c757d;
}

.records-per-page select {
    padding: 5px 10px;
    border-radius: 6px;
    border: 1px solid #dee2e6;
    background: white;
    font-size: 0.85rem;
    cursor: pointer;
}

.pagination {
    margin: 0;
}

.pagination .page-link {
    color: #f97316;
    border-radius: 8px;
    margin: 0 3px;
    padding: 6px 12px;
    transition: all 0.2s ease;
}

.pagination .page-item.active .page-link {
    background-color: #f97316;
    border-color: #f97316;
    color: white;
}

.pagination .page-link:hover {
    background-color: #ffedd5;
    color: #ea580c;
    transform: translateY(-1px);
}

.pagination-info {
    text-align: center;
    font-size: 0.85rem;
    color: #6c757d;
    margin-top: 10px;
}

/* Estilos para ordenamiento de columnas */
th.sortable {
    cursor: pointer;
    user-select: none;
    transition: all 0.2s ease;
    position: relative;
    padding-right: 25px !important;
}


th.sortable::after {
    content: "⇅";
    position: absolute;
    right: 8px;
    top: 50%;
    transform: translateY(-50%);
    font-size: 11px;
    opacity: 0.7;
    color: white;
}

th.sortable.asc::after {
    content: "↑";
    opacity: 1;
}

th.sortable.desc::after {
    content: "↓";
    opacity: 1;
}

/* Animación de filas al cargar */
.table tbody tr {
    transition: all 0.2s ease;
}

.table tbody tr:hover {
    background-color: #fff3e0 !important;
}

</style>

<div class="content-wrapper">

    <!-- HEADER -->
    <section class="content-header">
        <div class="container-fluid">
            <div class="row align-items-center">

                <!-- TITULO -->
                <div class="col-12 col-md-6 mb-2 mb-md-0">
                    <h1 class="mb-0 font-weight-bold">
                        <i class="fas fa-chart-line text-primary mr-2"></i>
                        Reporte de Ventas
                    </h1>
                    <small class="text-muted">
                        Resumen financiero y desempeño comercial
                    </small>
                </div>

                <!-- BOTONES -->
                <div class="col-12 col-md-6 text-md-right text-left">
                    <!-- Botón Reiniciar Filtros -->
                    <a href="?" class="btn btn-reset btn-sm shadow-sm mr-2">
                        <i class="fas fa-sync-alt mr-1"></i> Reiniciar filtros
                    </a>
                    
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


            <!-- KPIs con información adicional -->
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
           
            <!-- FILTROS -->
            <div class="card card-outline card-primary shadow-sm mb-4">
                <div class="card-header">
                    <h3 class="card-title font-weight-bold">
                        <i class="fas fa-filter mr-2"></i>Filtros de búsqueda
                    </h3>
                </div>

                <div class="card-body">
                    <form method="GET" class="row">

                        <div class="col-12 col-md-4 mb-2">
                            <label class="text-muted">Proveedor</label>
                            <select name="proveedor" class="form-control">
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
                            <input type="date" name="fecha_inicio" value="<?= htmlspecialchars($filtroInicio) ?>" class="form-control">
                        </div>

                        <div class="col-6 col-md-3 mb-2">
                            <label class="text-muted">Fecha fin</label>
                            <input type="date" name="fecha_fin" value="<?= htmlspecialchars($filtroFin) ?>" class="form-control">
                        </div>

                        <div class="col-12 col-md-2">
                            <button class="btn btn-success btn-block">
                                <i class="fas fa-search mr-1"></i> Aplicar
                            </button>
                        </div>

                    </form>
                </div>
            </div>

            <!-- Mensaje si no hay ventas -->
            <?php if (empty($productos)): ?>
            <div class="alert alert-info text-center mt-3">
                <i class="fas fa-info-circle mr-2"></i>
                No hay ventas registradas en el período seleccionado.
                <?php if ($filtroProveedor !== '' || ($filtroInicio !== '' && $filtroFin !== '')): ?>
                    <br><small>Intenta con otros filtros de búsqueda o <a href="?">reinicia los filtros</a>.</small>
                <?php endif; ?>
            </div>
            <?php endif; ?>
            
<!-- TABLA PRODUCTOS CON PAGINACIÓN Y ORDENAMIENTO -->
<div class="card card-outline card-warning shadow-sm mt-4">
    <div class="card-header">
        <h3 class="card-title font-weight-bold">
            <i class="fas fa-boxes mr-2"></i>Productos y Ventas
        </h3>
        <div class="card-tools">
            <span class="badge badge-warning p-2">
                Total ganancias: $<?= number_format(array_sum(array_column($productos, 'ganancia')), 2) ?>
            </span>
        </div>
    </div>
    <div class="card-body table-responsive p-0">
        <table class="table table-hover table-sm mb-0" id="tablaProductos">
            <thead class="thead-dark">
                <tr>
                    <th class="sortable" data-column="0">Producto</th>
                    <th class="sortable" data-column="1">Proveedor</th>
                    <th class="sortable text-center" data-column="2">Vendidos</th>
                    <th class="sortable text-center" data-column="3">Stock Restante</th>
                    <th class="sortable text-right" data-column="4">Compra</th>
                    <th class="sortable text-right" data-column="5">Venta</th>
                    <th class="sortable text-right" data-column="6">Ganancia</th>
                </tr>
            </thead>
            <tbody id="tablaProductosBody">
                <?php foreach ($productos as $p): ?>
                <tr class="<?= $p['es_especial'] ? 'table-success' : '' ?>">
                    <td><?= $p['nombre'] ?>
                        <?php if ($p['es_especial']): ?>
                            <span class="badge badge-success ml-1"><i class="fas fa-check-circle"></i> Pagado</span>
                        <?php endif; ?>
                    </td>
                    <td><?= $p['proveedor'] ?></td>
                    <td class="text-center"><?= $p['vendidos'] ?></td>
                    <td class="text-center">
                        <span class="badge <?= $p['stock'] <= 0 ? 'badge-danger' : ($p['stock'] <= 5 ? 'badge-warning' : 'badge-success') ?>">
                            <?= $p['stock'] ?>
                        </span>
                    </td>
                    <td class="text-right">$<?= number_format($p['precio_compra'], 2) ?></td>
                    <td class="text-right">$<?= number_format($p['precio_venta'], 2) ?></td>
                    <td class="text-right font-weight-bold text-success">
                        $<?= number_format($p['ganancia'], 2) ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
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
        <!-- ALERTA PARA EL PRODUCTO ESPECIAL -->
        <div class="alert alert-success alert-dismissible fade show" id="alertaProductoEspecial">
            <button type="button" class="close" onclick="ocultarAlertaProductoEspecial()" aria-label="Close">
                <span aria-hidden="true">&times;</span>
            </button>
            <h5><i class="icon fas fa-check-circle"></i> Producto pagado por adelantado</h5>
            <p>
                Las <strong>libretas del proveedor Nevaris 3D</strong> están excluidas de esta deuda porque se pagaron por adelantado. 
                Sin embargo, su ganancia SÍ está incluida en el reporte de ventas.
            </p>
        </div>
        <!-- BOTÓN PARA MOSTRAR LA ALERTA (OCULTO POR DEFECTO) -->
<div class="text-center mb-3" id="btnMostrarAlertaEspecial" style="display: none;">
    <button type="button" class="btn btn-sm btn-outline-success" id="mostrarAlertaBtn">
        <i class="fas fa-info-circle mr-1"></i> Ver información sobre productos pagados
    </button>
</div>

        <div class="table-responsive p-0">
            <table class="table table-hover table-sm mb-0" id="tablaDeuda">
                <thead class="thead-dark">
                    <tr>
                        <th class="sortable" data-column="0">Producto</th>
                        <th class="sortable" data-column="1">Proveedor</th>
                        <th class="sortable text-center" data-column="2">Vendidos</th>
                        <th class="sortable text-right" data-column="3">Costo unitario</th>
                        <th class="sortable text-right" data-column="4">Deuda total</th>
                    </tr>
                </thead>
                <tbody id="tablaDeudaBody">
                    <?php foreach ($ventasAgrupadas as $p): 
                        $deuda = $p['deuda_total'];
                        $esEspecial = $p['es_producto_especial'];
                        if ($deuda > 0 || $esEspecial):
                    ?>
                    <tr class="<?= $esEspecial ? 'table-success' : '' ?>">
                        <td><?= $p['producto'] ?></td>
                        <td><?= $p['proveedor'] ?></td>
                        <td class="text-center"><?= $p['total_vendido'] ?></td>
                        <td class="text-right">$<?= number_format($p['precio_compra'], 2) ?></td>
                        <td class="text-right font-weight-bold <?= $esEspecial ? 'text-success' : 'text-danger' ?>">
                            <?php if ($esEspecial): ?>
                                <span class="badge badge-success">PAGADO</span>
                            <?php else: ?>
                                $<?= number_format($deuda, 2) ?>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endif; endforeach; ?>
                </tbody>
            </table>
        </div>
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
    </div>
    <div class="card-footer text-right">
        <small class="text-muted">
            <i class="fas fa-info-circle mr-1"></i>
            Este monto representa el total pendiente de pago a proveedores. 
            Las <strong>libretas de Nevaris 3D</strong> están excluidas (ya pagadas).
        </small>
    </div>
</div>

            <!-- CALENDARIO DE ACTIVIDAD -->
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
                        <!-- Calendario - 9 columnas -->
                        <div class="col-lg-9 col-md-8">
                            <div id="calendario" style="min-height: 500px; width: 100%;"></div>
                        </div>
                        
                        <!-- Panel lateral - 3 columnas -->
                        <div class="col-lg-3 col-md-4">
                            <div class="info-box bg-light p-3 h-100" style="border-left: 4px solid #17a2b8;">
                                <div class="info-box-content">
                                    <div class="d-flex align-items-center justify-content-between mb-2">
                                        <h5 class="text-dark mb-0">
                                            <i class="fas fa-calendar-alt mr-2 text-info"></i>
                                            Calendario
                                        </h5>
                                    </div>
                                    <br>
                                    <p class="text-muted small mb-3">
                                        <i class="fas fa-chart-line mr-1"></i>
                                        Visualiza en el calendario los días con ventas (verde) y los días donde generaste reportes (rojo)
                                    </p>
                                    
                                    <hr class="my-2">
                                    
                                    <div class="d-flex align-items-center mb-3">
                                        <div style="width: 20px; height: 20px; background: #28a745; border-radius: 4px; margin-right: 10px; border: 1px solid #1e7e34; flex-shrink: 0;"></div>
                                        <span style="font-size: 0.9rem;"><strong>Ventas</strong> - Días con ventas</span>
                                    </div>
                                    
                                    <div class="d-flex align-items-center mb-3">
                                        <div style="width: 20px; height: 20px; background: #dc3545; border-radius: 4px; margin-right: 10px; border: 1px solid #a71d2a; flex-shrink: 0;"></div>
                                        <span style="font-size: 0.9rem;"><strong>Reportes</strong> - Días con reportes</span>
                                    </div>
                                    
                                    <div class="d-flex align-items-center mb-3">
                                        <div style="width: 20px; height: 20px; background: #ffc107; border-radius: 4px; margin-right: 10px; border: 1px solid #d39e00; flex-shrink: 0;"></div>
                                        <span style="font-size: 0.9rem;"><strong>Ambos</strong> - Ventas y reportes</span>
                                    </div>
                                    
                                    <hr class="my-3">
                                    
                                    <div class="mt-2">
                                        <h6 class="text-dark">
                                            <i class="fas fa-chart-bar mr-2 text-info"></i>Estadísticas
                                        </h6>
                                        <table class="table table-sm table-borderless mb-0">
                                            <tr>
                                                <td class="pl-0 py-1">Días con ventas:</td>
                                                <td class="text-success font-weight-bold py-1" id="diasVentas">0</td>
                                            </tr>
                                            <tr>
                                                <td class="pl-0 py-1">Días con reportes:</td>
                                                <td class="text-danger font-weight-bold py-1" id="diasReportes">0</td>
                                            </tr>
                                            <tr>
                                                <td class="pl-0 py-1">Total días activos:</td>
                                                <td class="text-primary font-weight-bold py-1" id="diasActivos">0</td>
                                            </tr>
                                        </table>
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
const STORAGE_KEY_ESPECIAL = 'ocultarAlertaProductoEspecial';

// ===== FUNCIONES PARA LA ALERTA DEL PRODUCTO ESPECIAL =====
function ocultarAlertaProductoEspecial() {
    console.log('Cerrando alerta...'); // Para depuración
    var alerta = document.getElementById('alertaProductoEspecial');
    var btnContainer = document.getElementById('btnMostrarAlertaEspecial');
    
    if (alerta) {
        alerta.style.display = 'none';
        console.log('Alerta oculta');
    }
    if (btnContainer) {
        btnContainer.style.display = 'block';
        console.log('Botón mostrar visible');
    }
    // Guardar en localStorage que la alerta está oculta
    localStorage.setItem('alertaEspecialOculta', 'true');
}

function mostrarAlertaProductoEspecial() {
    console.log('Mostrando alerta...'); // Para depuración
    var alerta = document.getElementById('alertaProductoEspecial');
    var btnContainer = document.getElementById('btnMostrarAlertaEspecial');
    
    if (alerta) {
        alerta.style.display = 'block';
        console.log('Alerta visible');
    }
    if (btnContainer) {
        btnContainer.style.display = 'none';
        console.log('Botón mostrar oculto');
    }
    localStorage.removeItem('alertaEspecialOculta');
}

// ===== FUNCIONES PARA LA ALERTA DEL PRODUCTO ESPECIAL =====
document.addEventListener('DOMContentLoaded', function() {
    var alerta = document.getElementById('alertaProductoEspecial');
    var btnMostrar = document.getElementById('mostrarAlertaBtn');
    var btnContainer = document.getElementById('btnMostrarAlertaEspecial');
    var btnCerrar = document.getElementById('cerrarAlertaEspecial');
    
    // Función para cerrar alerta
    function cerrarAlerta() {
        if (alerta) alerta.style.display = 'none';
        if (btnContainer) btnContainer.style.display = 'block';
        localStorage.setItem('alertaEspecialOculta', 'true');
    }
    
    // Función para mostrar alerta
    function mostrarAlerta() {
        if (alerta) alerta.style.display = 'block';
        if (btnContainer) btnContainer.style.display = 'none';
        localStorage.removeItem('alertaEspecialOculta');
    }
    
    // Evento para el botón de cerrar (X)
    if (btnCerrar) {
        btnCerrar.addEventListener('click', cerrarAlerta);
    }
    
    // Evento para el botón de mostrar
    if (btnMostrar) {
        btnMostrar.addEventListener('click', mostrarAlerta);
    }
    
    // Verificar estado guardado
    var alertaOculta = localStorage.getItem('alertaEspecialOculta');
    if (alertaOculta === 'true') {
        if (alerta) alerta.style.display = 'none';
        if (btnContainer) btnContainer.style.display = 'block';
    } else {
        if (alerta) alerta.style.display = 'block';
        if (btnContainer) btnContainer.style.display = 'none';
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
btnPDF.addEventListener('click', (e) => {
    e.preventDefault();
    e.stopPropagation();
    if (pdfDropdown.classList.contains('active')) {
        closeDropdown();
    } else {
        openDropdown();
    }
});

// Cerrar al hacer clic en la X
closeDropdownBtn.addEventListener('click', (e) => {
    e.preventDefault();
    e.stopPropagation();
    closeDropdown();
});

// Cerrar al hacer clic en el overlay
dropdownOverlay.addEventListener('click', closeDropdown);

// Prevenir que el dropdown se cierre al hacer clic dentro de él
pdfDropdown.addEventListener('click', (e) => {
    e.stopPropagation();
});

// Cerrar con tecla Escape
document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape' && pdfDropdown.classList.contains('active')) {
        closeDropdown();
    }
});

// Función para registrar un día de reporte
function registrarDiaReporte() {
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

// Función para cargar eventos en el calendario
function cargarEventosCalendario() {
    if (!calendar) return;
    
    calendar.removeAllEvents();
    
    const eventosMap = new Map();
    
    // Agregar días con ventas (desde PHP)
    fechasVentasPHP.forEach(fecha => {
        const fechaStr = fecha.split('T')[0];
        eventosMap.set(fechaStr, {
            venta: true,
            reporte: eventosMap.get(fechaStr)?.reporte || false
        });
    });
    
    // Agregar días con reportes (desde localStorage)
    const reportesGuardados = JSON.parse(localStorage.getItem('diasReportes') || '[]');
    reportesGuardados.forEach(fecha => {
        const fechaStr = fecha.split('T')[0];
        eventosMap.set(fechaStr, {
            venta: eventosMap.get(fechaStr)?.venta || false,
            reporte: true
        });
    });
    
    const eventos = [];
    let contVentas = 0;
    let contReportes = 0;
    
    eventosMap.forEach((valor, fecha) => {
        const [year, month, day] = fecha.split('-').map(Number);
        const fechaObj = new Date(year, month - 1, day, 12, 0, 0);
        
        if (valor.venta) contVentas++;
        if (valor.reporte) contReportes++;
        
        let className = '';
        let tooltip = '';
        
        if (valor.venta && valor.reporte) {
            className = 'evento-ambos';
            tooltip = 'Ventas y reportes';
        } else if (valor.venta) {
            className = 'evento-venta';
            tooltip = 'Día con ventas';
        } else if (valor.reporte) {
            className = 'evento-reporte';
            tooltip = 'Día con reportes';
        }
        
        eventos.push({
            start: fechaObj,
            allDay: true,
            display: 'background',
            classNames: [className],
            extendedProps: {
                tooltip: tooltip,
                tieneVenta: valor.venta,
                tieneReporte: valor.reporte
            }
        });
    });
    
    document.getElementById('diasVentas').textContent = contVentas;
    document.getElementById('diasReportes').textContent = contReportes;
    document.getElementById('diasActivos').textContent = eventosMap.size;
    
    eventos.forEach(evento => {
        calendar.addEvent(evento);
    });
}

// Inicializar calendario
document.addEventListener('DOMContentLoaded', function() {
    verificarEstadoAlertaEspecial();
    
    const calendarEl = document.getElementById('calendario');
    
    if (calendarEl) {
        calendar = new FullCalendar.Calendar(calendarEl, {
            initialView: 'dayGridMonth',
            locale: 'es',
            headerToolbar: {
                left: 'prev,next today',
                center: 'title',
                right: 'dayGridMonth,dayGridWeek'
            },
            buttonText: {
                today: 'Hoy',
                month: 'Mes',
                week: 'Semana'
            },
            height: 'auto',
            firstDay: 1,
            eventDidMount: function(info) {
                if (info.event.extendedProps.tooltip) {
                    info.el.setAttribute('data-tooltip', info.event.extendedProps.tooltip);
                }
            },
            eventClick: function(info) {
                const fecha = info.event.start;
                const fechaStr = fecha.toISOString().split('T')[0];
                const fechaLocal = fecha.toLocaleDateString('es-ES', {
                    weekday: 'long',
                    year: 'numeric',
                    month: 'long',
                    day: 'numeric'
                });
                
                const fechaCapitalizada = fechaLocal.charAt(0).toUpperCase() + fechaLocal.slice(1);
                
                const reportesGuardados = JSON.parse(localStorage.getItem('reportesPorFecha') || '{}');
                const reportesFecha = reportesGuardados[fechaStr] || [];
                
                let icono = 'info';
                let titulo = 'Detalle del día';
                let iconoHeader = '';
                
                if (info.event.extendedProps.tieneVenta && info.event.extendedProps.tieneReporte) {
                    icono = 'warning';
                    titulo = 'Día con ventas y reportes';
                } else if (info.event.extendedProps.tieneVenta) {
                    icono = 'success';
                    titulo = 'Día con ventas';
                } else if (info.event.extendedProps.tieneReporte) {
                    icono = 'info';
                    titulo = 'Día con reportes';
                }
                
                let html = `<div style="text-align: left;">`;
                html += `<p><i class="fas fa-calendar-alt text-info mr-2"></i> <strong>Fecha:</strong> ${fechaCapitalizada}</p>`;
                
                if (info.event.extendedProps.tieneVenta) {
                    html += `<p><i class="fas fa-shopping-cart text-success mr-2"></i> <strong>Ventas:</strong> Hay ventas registradas</p>`;
                }
                
                if (info.event.extendedProps.tieneReporte) {
                    html += `<p><i class="fas fa-file-pdf text-danger mr-2"></i> <strong>Reportes generados:</strong></p>`;
                    
                    if (reportesFecha.length > 0) {
                        html += `<div style="margin-left: 25px; margin-top: 5px;">`;
                        
                        reportesFecha.forEach(reporte => {
                            if (reporte.tipo === 'general') {
                                if (reporte.proveedor === 'TODOS') {
                                    html += `
                                        <div class="mb-2 p-2" style="background: #f8f9fa; border-left: 4px solid #28a745; border-radius: 4px;">
                                            <div class="d-flex align-items-center">
                                                <i class="fas fa-chart-line text-success mr-2"></i>
                                                <strong> Reporte General</strong>
                                                <span class="ml-2 badge badge-success">Todos los proveedores</span>
                                            </div>
                                        </div>
                                    `;
                                } else {
                                    html += `
                                        <div class="mb-2 p-2" style="background: #f8f9fa; border-left: 4px solid #28a745; border-radius: 4px;">
                                            <div class="d-flex align-items-center">
                                                <i class="fas fa-chart-line text-success mr-2"></i>
                                                <strong> ${reporte.proveedor}</strong>
                                                <span class="ml-2 badge badge-success">Reporte General</span>
                                            </div>
                                        </div>
                                    `;
                                }
                            } else if (reporte.tipo === 'proveedor') {
                                if (reporte.proveedor === 'TODOS_PROVEEDORES') {
                                    html += `
                                        <div class="mb-2 p-2" style="background: #f8f9fa; border-left: 4px solid #dc3545; border-radius: 4px;">
                                            <div class="d-flex align-items-center">
                                                <i class="fas fa-truck text-danger mr-2"></i>
                                                <strong>Reporte por proveedor</strong>
                                                <span class="ml-2 badge badge-danger">Todos los proveedores</span>
                                            </div>
                                        </div>
                                    `;
                                } else {
                                    html += `
                                        <div class="mb-2 p-2" style="background: #f8f9fa; border-left: 4px solid #dc3545; border-radius: 4px;">
                                            <div class="d-flex align-items-center">
                                                <i class="fas fa-truck text-danger mr-2"></i>
                                                <strong>${reporte.proveedor}</strong>
                                                <span class="ml-2 badge badge-danger">Reporte por proveedor</span>
                                            </div>
                                        </div>
                                    `;
                                }
                            }
                        });
                        
                        html += `</div>`;
                    }
                }
                
                if (!info.event.extendedProps.tieneVenta && !info.event.extendedProps.tieneReporte) {
                    html += `<p class="text-muted"><i class="fas fa-minus-circle text-muted mr-2"></i> No hay actividad registrada en este día</p>`;
                }
                
                html += `</div>`;
                
                Swal.fire({
                    title: `<i class="fas ${iconoHeader} mr-2"></i>${titulo}`,
                    html: html,
                    icon: icono,
                    confirmButtonText: 'Cerrar',
                    confirmButtonColor: '#3085d6',
                    width: 500,
                    background: '#fff',
                    customClass: {
                        popup: 'rounded-lg shadow-lg',
                        title: 'font-weight-bold'
                    }
                });
            }
        });
        
        calendar.render();
        cargarEventosCalendario();
    }
    
    // Evento para limpiar historial con SweetAlert2
    document.getElementById('limpiarHistorialBtn')?.addEventListener('click', function() {
        Swal.fire({
            title: '¿Limpiar historial de reportes?',
            html: '<p>Esta acción eliminará todos los registros de reportes generados.</p><p class="text-muted small"><i class="fas fa-info-circle mr-1"></i> Los días con ventas no se verán afectados.</p>',
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#dc3545',
            cancelButtonColor: '#6c757d',
            confirmButtonText: 'Sí, limpiar',
            cancelButtonText: 'Cancelar',
            reverseButtons: true,
            customClass: {
                confirmButton: 'btn btn-danger',
                cancelButton: 'btn btn-secondary'
            }
        }).then((result) => {
            if (result.isConfirmed) {
                limpiarHistorialReportes();
                Swal.fire({
                    title: '<i class="fas fa-check-circle text-success mr-2"></i>¡Historial limpiado!',
                    text: 'Los registros de reportes han sido eliminados.',
                    icon: 'success',
                    confirmButtonColor: '#28a745',
                    confirmButtonText: 'Aceptar',
                    timer: 2000,
                    timerProgressBar: true
                });
            }
        });
    });
});

// Modificar la función registrarRangoReporte para recibir el tipo de reporte
function registrarRangoReporte(tipoGenerado) {
    const filtroInicio = '<?= $filtroInicio ?>';
    const filtroFin = '<?= $filtroFin ?>';
    const filtroProveedor = '<?= $filtroProveedor ?>';
    
    // Obtener lista de proveedores con ventas en el período
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
    
    // Función para obtener fecha local en formato YYYY-MM-DD
    function getFechaLocal(date) {
        const year = date.getFullYear();
        const month = String(date.getMonth() + 1).padStart(2, '0');
        const day = String(date.getDate()).padStart(2, '0');
        return `${year}-${month}-${day}`;
    }
    
    // Fecha actual en zona local
    const hoy = new Date();
    const hoyStr = getFechaLocal(hoy);
    
    // Sistema de almacenamiento mejorado para reportes por fecha
    let reportesPorFecha = JSON.parse(localStorage.getItem('reportesPorFecha') || '{}');
    
    if (filtroInicio && filtroFin) {
        // Crear fechas en zona local
        const [inicioYear, inicioMonth, inicioDay] = filtroInicio.split('-').map(Number);
        const [finYear, finMonth, finDay] = filtroFin.split('-').map(Number);
        
        let fechaActual = new Date(inicioYear, inicioMonth - 1, inicioDay);
        const fechaFin = new Date(finYear, finMonth - 1, finDay);
        
        let fechasProcesadas = [];
        
        // Recorrer todas las fechas del rango
        while (fechaActual <= fechaFin) {
            const fechaStr = getFechaLocal(fechaActual);
            
            // SOLO registrar si la fecha es menor o igual a hoy
            if (fechaActual <= new Date()) {
                fechasProcesadas.push(fechaStr);
                
                // Crear entrada para esta fecha si no existe
                if (!reportesPorFecha[fechaStr]) {
                    reportesPorFecha[fechaStr] = [];
                }
                
                // Determinar qué tipo de reporte según el botón presionado
                if (tipoGenerado === 'proveedor') {
                    // Reporte SOLO de proveedor
                    if (filtroProveedor) {
                        // Reporte de UN proveedor específico
                        const reporteInfo = {
                            fecha: fechaStr,
                            proveedor: filtroProveedor,
                            tipo: 'proveedor',
                            descripcion: 'Reporte por proveedor',
                            timestamp: new Date().toISOString()
                        };
                        
                        // Verificar si ya existe para evitar duplicados
                        const existe = reportesPorFecha[fechaStr].some(r => 
                            r.proveedor === filtroProveedor && r.tipo === 'proveedor'
                        );
                        
                        if (!existe) {
                            reportesPorFecha[fechaStr].push(reporteInfo);
                        }
                    } else {
                        // Reporte por proveedor de TODOS los proveedores
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
                    // Reporte GENERAL - puede ser con o sin filtro de proveedor
                    if (filtroProveedor) {
                        // Reporte general de UN proveedor específico
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
                        // Reporte general de TODOS los proveedores
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
                    // AMBOS reportes: general y de proveedor
                    
                    // Agregar reporte de proveedor
                    if (filtroProveedor) {
                        // Reporte de UN proveedor específico
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
                        // Reporte por proveedor de TODOS
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
                        // Reporte general de UN proveedor
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
                        // Reporte general de TODOS
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
            
            // Avanzar al siguiente día
            fechaActual.setDate(fechaActual.getDate() + 1);
        }
        
        // Guardar en localStorage
        localStorage.setItem('reportesPorFecha', JSON.stringify(reportesPorFecha));
        
        // Actualizar también el sistema antiguo de días reporte para compatibilidad
        let diasReporte = JSON.parse(localStorage.getItem('diasReportes') || '[]');
        fechasProcesadas.forEach(fecha => {
            if (!diasReporte.includes(fecha)) {
                diasReporte.push(fecha);
            }
        });
        localStorage.setItem('diasReportes', JSON.stringify(diasReporte));
        
        // Actualizar el calendario
        if (calendar) {
            cargarEventosCalendario();
        }
        
    } else {
        // Sin filtros: solo registrar el día de HOY (local)
        if (!reportesPorFecha[hoyStr]) {
            reportesPorFecha[hoyStr] = [];
        }
        
        // Determinar qué tipo de reporte según el botón presionado
        if (tipoGenerado === 'proveedor') {
            if (filtroProveedor) {
                // Reporte de UN proveedor específico
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
                // Reporte por proveedor de TODOS
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
                // Reporte general de UN proveedor
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
                // Reporte general de TODOS
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
            // Agregar reporte de proveedor
            if (filtroProveedor) {
                // Reporte de UN proveedor específico
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
                // Reporte por proveedor de TODOS
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
            
            // Agregar reporte general
            if (filtroProveedor) {
                // Reporte general de UN proveedor
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
                // Reporte general de TODOS
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
        
        // Actualizar días de reporte
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

// Función para limpiar historial
function limpiarHistorialReportes() {
    localStorage.removeItem('diasReportes');
    localStorage.removeItem('reportesPorFecha');
    cargarEventosCalendario();
}

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

// Función para generar PDFs - VERSIÓN CON ARCHIVO SEPARADO
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
    $fechaActual = date('Y-m-d_H-i-s');
    $usuario_nombre = $_SESSION['nombre'] ?? 'Sistema';
    $usuario_id = $_SESSION['usuario_id'] ?? 0;
    $totalRegistros = count($ventasAgrupadas);
    
    // Determinar nombres de archivo según los filtros
    $nombreArchivoGeneral = "reporte_admin_{$fechaActual}.pdf";
    $nombreArchivoProveedor = $filtroProveedor ? 
        "reporte_proveedor_" . preg_replace('/[^a-zA-Z0-9]/', '_', $filtroProveedor) . "_{$fechaActual}.pdf" : 
        "reporte_proveedores_todos_{$fechaActual}.pdf";
    ?>

    // Función para guardar el PDF en el servidor usando fetch
    function guardarPDFenServidor(pdfBlob, nombreArchivo, tipoPDF) {
        return new Promise((resolve, reject) => {
            const formData = new FormData();
            formData.append('pdf_file', pdfBlob, nombreArchivo);
            formData.append('carpeta', 'reportes_ventas');
            formData.append('tipo', tipoPDF);
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

    // Función para generar PDF General (Administrador)
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

        function addFooter(doc, pageNum, totalPages) {
            doc.setPage(pageNum);
            doc.setFontSize(8);
            doc.setTextColor(colors.medium[0], colors.medium[1], colors.medium[2]);
            doc.setFont("helvetica", "normal");
            
            doc.setDrawColor(220, 220, 220);
            doc.line(20, pageHeight - 12, pageWidth - 20, pageHeight - 12);
            
            doc.text(
                `Documento generado el ${new Date().toLocaleDateString()} a las ${new Date().toLocaleTimeString()}`,
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
        docAdmin.text("REPORTE DE VENTAS", pageWidth / 2, 60, { align: "center" });

        docAdmin.setFontSize(14);
        docAdmin.setTextColor(colors.medium[0], colors.medium[1], colors.medium[2]);
        docAdmin.text("No hay ventas registradas en el período seleccionado", pageWidth / 2, 100, { align: "center" });

        docAdmin.setFontSize(10);
        docAdmin.text("Por favor, seleccione un rango de fechas con ventas para visualizar el reporte", pageWidth / 2, 120, { align: "center" });

        addFooter(docAdmin, 1, 1);
        
        // Guardar el PDF en el servidor y abrir en nueva ventana
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
        docAdmin.setFillColor(colors.primary[0], colors.primary[1], colors.primary[2]);
        docAdmin.rect(0, 0, pageWidth, 12, "F");

        docAdmin.setFontSize(28);
        docAdmin.setTextColor(colors.dark[0], colors.dark[1], colors.dark[2]);
        docAdmin.setFont("helvetica", "bold");
        docAdmin.text("REPORTE DE VENTAS", pageWidth / 2, 35, { align: "center" });

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

        docAdmin.text(periodoTexto, pageWidth / 2, 70, { align: "center" });

        docAdmin.setFillColor(colors.light[0], colors.light[1], colors.light[2]);
        docAdmin.roundedRect(20, 85, pageWidth - 40, 45, 3, 3, "F");

        docAdmin.setFontSize(10);
        docAdmin.setTextColor(colors.medium[0], colors.medium[1], colors.medium[2]);
        docAdmin.text("TOTAL INGRESOS", 35, 100);
        docAdmin.text("DEUDA TOTAL", pageWidth / 2 - 25, 100);
        docAdmin.text("GANANCIA NETA", pageWidth - 55, 100);

        docAdmin.setFontSize(18);
        docAdmin.setTextColor(colors.dark[0], colors.dark[1], colors.dark[2]);
        docAdmin.setFont("helvetica", "bold");
        docAdmin.text("$<?= number_format($totalVentas, 2) ?>", 35, 115);
        docAdmin.text("$<?= number_format($totalProveedor, 2) ?>", pageWidth / 2 - 25, 115);
        docAdmin.text("$<?= number_format($totalGanancia, 2) ?>", pageWidth - 55, 115);

        y = 145;

        // ============================================
        // TABLA PRINCIPAL - VENTAS AGRUPADAS POR PROVEEDOR Y PRODUCTO
        // ============================================
        if (y > 200) { docAdmin.addPage(); y = 25; pageNum++; }
        
        docAdmin.setFontSize(16);
        docAdmin.setTextColor(colors.success[0], colors.success[1], colors.success[2]);
        docAdmin.setFont("helvetica", "bold");
        docAdmin.text("Resumen de Ventas por Producto", 20, y);
        y += 8;

        // Datos agrupados
        const ventasAgrupadasData = [
            <?php foreach ($ventasAgrupadas as $venta): ?>
            [
                '<?= addslashes($venta['proveedor']) ?>',
                '<?= addslashes($venta['producto']) ?>',
                <?= $venta['total_vendido'] ?>,
                <?= $venta['stock_actual'] ?>,
                '$<?= number_format($venta['precio_compra'], 2) ?>',
                '$<?= number_format($venta['precio_venta'], 2) ?>',
                '<?= $venta['es_producto_especial'] ? "PAGADO" : "$".number_format($venta['deuda_total'], 2) ?>',
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
        // TABLA DE STOCK COMPLETO (INCLUYE PRODUCTOS SIN VENTAS)
        // ============================================
        if (y > 240) { docAdmin.addPage(); y = 25; pageNum++; }

        docAdmin.setFontSize(16);
        docAdmin.setTextColor(colors.secondary[0], colors.secondary[1], colors.secondary[2]);
        docAdmin.setFont("helvetica", "bold");
        docAdmin.text("Inventario Completo por Proveedor", 20, y);
        y += 8;

        // Crear datos de stock completo (todos los productos)
        const stockCompletoData = [
            <?php foreach ($todosProductos as $producto): 
                // Buscar si este producto tiene ventas en el período
                $vendido = 0;
                $deuda = 0;
                foreach ($ventasAgrupadas as $venta) {
                    if ($venta['proveedor'] == $producto['proveedor'] && $venta['producto'] == $producto['nombre']) {
                        $vendido = $venta['total_vendido'];
                        $deuda = $venta['deuda_total'];
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
                '<?= $producto['es_producto_especial'] ? "PAGADO" : "" ?>'
            ],
            <?php endforeach; ?>
        ];

        docAdmin.autoTable({
            head: [['Proveedor', 'Producto', 'Stock Inicial', 'Vendidos', 'Stock Restante', 'P.Venta', 'Estado']],
            body: stockCompletoData,
            startY: y,
            theme: "grid",
            headStyles: { fillColor: colors.secondary, textColor: colors.white, fontSize: 8 },
            styles: { fontSize: 7, cellPadding: 2 },
            columnStyles: {
                0: { cellWidth: 30, fontStyle: 'bold' },
                1: { cellWidth: 40 },
                2: { cellWidth: 20, halign: 'center' },
                3: { cellWidth: 18, halign: 'center' },
                4: { cellWidth: 22, halign: 'center', fontStyle: 'bold' },
                5: { cellWidth: 20, halign: 'right' },
                6: { cellWidth: 20, halign: 'center' }
            },
            margin: { left: 10, right: 10 }
        });

        y = docAdmin.lastAutoTable.finalY + 15;

        // --- RESUMEN POR PROVEEDOR ---
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

        // Guardar el PDF en el servidor y abrir en nueva ventana
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
    }

    // ============================================
    // PDF PARA PROVEEDORES
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

        <?php
        $nombreProveedorParaArchivo = 'todos';
        if ($filtroProveedor !== '') {
            $nombreProveedorParaArchivo = preg_replace('/[^a-zA-Z0-9]/', '_', $filtroProveedor);
        }
        ?>

        // --- ENCABEZADO ---
        docProv.setFillColor(colors.danger[0], colors.danger[1], colors.danger[2]);
        docProv.rect(0, 0, provPageWidth, 10, "F");
        
        docProv.setFontSize(24);
        docProv.setTextColor(colors.danger[0], colors.danger[1], colors.danger[2]);
        docProv.setFont("helvetica", "bold");
        docProv.text("ESTADO DE CUENTA", provPageWidth / 2, 30, { align: "center" });
        docProv.text("PAGO A PROVEEDORES", provPageWidth / 2, 42, { align: "center" });
        
        docProv.setFontSize(10);
        docProv.setTextColor(colors.medium[0], colors.medium[1], colors.medium[2]);
        docProv.text(`Generado: ${new Date().toLocaleString()}`, provPageWidth / 2, 55, { align: "center" });
        
        <?php if ($filtroProveedor !== ''): ?>
        docProv.setFontSize(12);
        docProv.setTextColor(colors.danger[0], colors.danger[1], colors.danger[2]);
        docProv.setFont("helvetica", "bold");
        docProv.text("Proveedor: <?= htmlspecialchars($filtroProveedor) ?>", provPageWidth / 2, 65, { align: "center" });
        <?php endif; ?>
        
        <?php if ($filtroInicio !== '' && $filtroFin !== ''): ?>
        docProv.setFontSize(10);
        docProv.setTextColor(colors.medium[0], colors.medium[1], colors.medium[2]);
        docProv.text(`Período: <?= htmlspecialchars($filtroInicio) ?> al <?= htmlspecialchars($filtroFin) ?>`, provPageWidth / 2, 72, { align: "center" });
        <?php endif; ?>
        
        // --- RESUMEN DE DEUDA ---
        provY = 85;
        
        docProv.setFillColor(colors.light[0], colors.light[1], colors.light[2]);
        docProv.roundedRect(30, provY - 10, provPageWidth - 60, 35, 3, 3, "F");
        docProv.setDrawColor(colors.danger[0], colors.danger[1], colors.danger[2]);
        docProv.setLineWidth(0.5);
        docProv.roundedRect(30, provY - 10, provPageWidth - 60, 35, 3, 3, "S");
        
        docProv.setFontSize(10);
        docProv.setTextColor(colors.medium[0], colors.medium[1], colors.medium[2]);
        docProv.text("TOTAL A PAGAR A PROVEEDORES", provPageWidth / 2, provY, { align: "center" });
        
        docProv.setFontSize(26);
        docProv.setTextColor(colors.danger[0], colors.danger[1], colors.danger[2]);
        docProv.setFont("helvetica", "bold");
        docProv.text("$<?= number_format($totalProveedor, 2) ?>", provPageWidth / 2, provY + 15, { align: "center" });
        
        provY += 45;

        // ============================================
        // TABLA PRINCIPAL - VENTAS AGRUPADAS PARA PROVEEDORES
        // ============================================
        docProv.setFontSize(14);
        docProv.setTextColor(colors.dark[0], colors.dark[1], colors.dark[2]);
        docProv.setFont("helvetica", "bold");
        docProv.text("Detalle de Productos Vendidos", 20, provY);
        provY += 8;

        // Datos agrupados para proveedores
        const proveedoresDataAgrupados = [
            <?php foreach ($ventasAgrupadas as $venta): ?>
            [
                '<?= addslashes($venta['proveedor']) ?>',
                '<?= addslashes($venta['producto']) ?>',
                <?= $venta['total_vendido'] ?>,
                <?= $venta['stock_actual'] ?>,
                '$<?= number_format($venta['precio_compra'], 2) ?>',
                '<?= $venta['es_producto_especial'] ? "PAGADO" : "$".number_format($venta['deuda_total'], 2) ?>'
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
                    overflow: 'linebreak'
                },
                columnStyles: {
                    0: { cellWidth: 30, fontStyle: 'bold', halign: 'left' },
                    1: { cellWidth: 35, halign: 'left' },
                    2: { cellWidth: 20, halign: 'center' },
                    3: { cellWidth: 20, halign: 'center' },
                    4: { cellWidth: 23, halign: 'right' },
                    5: { cellWidth: 25, halign: 'right', fontStyle: 'bold' }
                },
                margin: { left: 15, right: 15 }
            });
            
            provY = docProv.lastAutoTable.finalY + 15;

            // ============================================
            // STOCK RESTANTE POR PRODUCTO
            // ============================================

            if (provY > 220) { docProv.addPage(); provY = 25; provPageNum++; }

            docProv.setFontSize(14);
            docProv.setTextColor(colors.danger[0], colors.danger[1], colors.danger[2]);
            docProv.setFont("helvetica", "bold");
            docProv.text("Stock Restante por Producto", 20, provY);
            provY += 8;

            const stockRestanteData = [
                <?php foreach ($todosProductos as $producto): 
                    // Si hay filtro de proveedor, solo mostrar ese proveedor
                    if ($filtroProveedor !== '' && $producto['proveedor'] != $filtroProveedor) continue;
                    
                    // Buscar ventas de este producto
                    $vendido = 0;
                    foreach ($ventasAgrupadas as $venta) {
                        if ($venta['proveedor'] == $producto['proveedor'] && $venta['producto'] == $producto['nombre']) {
                            $vendido = $venta['total_vendido'];
                            break;
                        }
                    }
                    $stockActual = $producto['stock_actual'];  // Stock restante actual
                    $stockInicial = $stockActual + $vendido;   // Calcular stock inicial
                ?>
                [
                    '<?= addslashes($producto['proveedor']) ?>',
                    '<?= addslashes($producto['nombre']) ?>',
                    <?= $stockInicial ?>,
                    <?= $vendido ?>,
                    <?= $stockActual ?>,  // Ahora es el stock restante correcto
                    '<?= $producto['es_producto_especial'] ? "PAGADO" : "" ?>'
                ],
                <?php endforeach; ?>
            ];

            docProv.autoTable({
                head: [['Proveedor', 'Producto', 'Stock Inicial', 'Vendidos', 'Stock Restante', 'Estado']],
                body: stockRestanteData,
                startY: provY,
                theme: "striped",
                headStyles: { fillColor: colors.danger, textColor: colors.white, fontSize: 8 },
                styles: { fontSize: 7, cellPadding: 3 },
                columnStyles: {
                    0: { cellWidth: 35, fontStyle: 'bold' },
                    1: { cellWidth: 45 },
                    2: { cellWidth: 22, halign: 'center' },
                    3: { cellWidth: 20, halign: 'center' },
                    4: { cellWidth: 22, halign: 'center', fontStyle: 'bold' },
                    5: { cellWidth: 20, halign: 'center' }
                },
                margin: { left: 15, right: 15 }
            });
            
            provY = docProv.lastAutoTable.finalY + 15;

            // --- RESUMEN POR PROVEEDOR ---
            if (provY > 240) { docProv.addPage(); provY = 25; provPageNum++; }
            
            docProv.setFontSize(14);
            docProv.setTextColor(colors.danger[0], colors.danger[1], colors.danger[2]);
            docProv.setFont("helvetica", "bold");
            docProv.text("Resumen por Proveedor", 20, provY);
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

            docProv.autoTable({
                startY: provY,
                body: resumenProvData,
                theme: "plain",
                styles: { fontSize: 10, cellPadding: 4 },
                columnStyles: {
                    0: { cellWidth: 120, fontStyle: 'bold', halign: 'left' },
                    1: { cellWidth: 50, halign: 'right', fontStyle: 'bold' }
                },
                margin: { left: 30, right: 30 }
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

        // Guardar el PDF en el servidor y abrir en nueva ventana
        const pdfBlob = docProv.output('blob');
        const pdfUrl = URL.createObjectURL(pdfBlob);
        window.open(pdfUrl, '_blank');
        
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
document.getElementById('pdfProveedor').addEventListener('click', () => generarPDF('proveedor'));
document.getElementById('pdfGeneral').addEventListener('click', () => generarPDF('general'));
document.getElementById('pdfAmbos').addEventListener('click', () => generarPDF('ambos'));

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
        
        if (this.itemsPorPaginaSelect) {
            this.itemsPorPaginaSelect.addEventListener('change', (e) => {
                this.itemsPorPagina = parseInt(e.target.value);
                this.paginaActual = 1;
                this.renderizar();
            });
        }
        
        this.tabla.querySelectorAll('.sortable').forEach((th, index) => {
            th.addEventListener('click', () => this.ordenarPor(index));
        });
        
        this.renderizar();
    }
    
    cargarDatos() {
        const filas = this.tbody.querySelectorAll('tr');
        this.datos = Array.from(filas).map(fila => {
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
        
        this.tabla.querySelectorAll('.sortable').forEach(th => {
            th.classList.remove('asc', 'desc');
        });
        const thActual = this.tabla.querySelector(`.sortable[data-column="${columna}"]`);
        thActual.classList.add(this.direccionOrden);
        
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
        const inicio = (this.paginaActual - 1) * this.itemsPorPagina;
        const fin = inicio + this.itemsPorPagina;
        const paginaDatos = this.datos.slice(inicio, fin);
        const totalPaginas = Math.ceil(this.datos.length / this.itemsPorPagina);
        
        this.tbody.innerHTML = '';
        paginaDatos.forEach(filaData => {
            const fila = document.createElement('tr');
            filaData.forEach(celdaData => {
                const celda = document.createElement('td');
                if (celdaData.match(/^\$?[\d,]+\.\d{2}$/)) celda.className = 'text-right';
                else if (celdaData.match(/^\d+$/)) celda.className = 'text-center';
                celda.innerHTML = celdaData;
                fila.appendChild(celda);
            });
            this.tbody.appendChild(fila);
        });
        
        const desde = this.datos.length > 0 ? inicio + 1 : 0;
        const hasta = Math.min(fin, this.datos.length);
        this.infoDesde.textContent = desde;
        this.infoHasta.textContent = hasta;
        this.infoTotal.textContent = this.datos.length;
        
        this.renderizarPaginacion(totalPaginas);
    }
    
    renderizarPaginacion(totalPaginas) {
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

// Inicializar tablas
document.addEventListener('DOMContentLoaded', function() {
    if (document.getElementById('tablaProductos') && document.getElementById('tablaProductosBody').children.length > 0) {
        new TablaDinamica(
            'tablaProductos', 'tablaProductosBody', 'productosPorPagina', 'paginacionProductos',
            { desde: 'productosDesde', hasta: 'productosHasta', total: 'productosTotal' }
        );
    }
    
    if (document.getElementById('tablaDeuda') && document.getElementById('tablaDeudaBody').children.length > 0) {
        new TablaDinamica(
            'tablaDeuda', 'tablaDeudaBody', 'deudaPorPagina', 'paginacionDeuda',
            { desde: 'deudaDesde', hasta: 'deudaHasta', total: 'deudaTotal' }
        );
    }
});
</script>

<?php include('includes/footer.php'); ?>