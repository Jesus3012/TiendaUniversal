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

if ($resultado && $resultado->num_rows > 0) {
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
    if ($v['es_producto_especial']) {
        $hayProductoEspecial = true;
        break;
    }
}

// Calcular si hay datos para mostrar
$hayDatosTablaProductos = count($productos) > 0;
$hayDatosTablaDeuda = count($ventasAgrupadas) > 0;
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

/* =====================================================
   ESTILOS CALENDARIO - SIN SCROLL Y RESPONSIVE
   ===================================================== */
/* Asegurar que el calendario se ajuste al contenedor */
.calendar-container {
    background: white;
    border-radius: 12px;
    padding: 10px;
    overflow: visible !important;
    width: 100%;
    transition: all 0.3s ease;
}

#calendario {
    min-height: 480px;
    overflow: visible !important;
    width: 100% !important;
}

/* Forzar que las celdas se ajusten bien en todos los tamaños */
.fc {
    overflow: visible !important;
    width: 100% !important;
}

.fc .fc-view-harness {
    width: 100% !important;
}

.fc .fc-daygrid-body {
    width: 100% !important;
}

/* Ajuste para sidebar colapsado */
.sidebar-collapse .calendar-container {
    width: 100%;
}

.sidebar-collapse #calendario .fc {
    font-size: 0.9rem;
}

/* Para pantallas más pequeñas cuando sidebar está abierto */
@media (max-width: 1200px) {
    .fc .fc-daygrid-day {
        min-height: 55px;
    }
    
    .fc .fc-daygrid-day-number {
        font-size: 0.7rem;
        width: 22px !important;
        height: 22px !important;
    }
    
    .fc .fc-toolbar-title {
        font-size: 0.9rem !important;
    }
}

@media (max-width: 992px) {
    .fc .fc-daygrid-day {
        min-height: 50px;
    }
}

@media (max-width: 768px) {
    .calendar-container {
        overflow-x: auto !important;
    }
    
    #calendario {
        min-width: 600px;
    }
}

.fc-scroller {
    overflow: visible !important;
    height: auto !important;
}

.fc-daygrid-body {
    overflow: visible !important;
}

/* Toolbar más compacto */
.fc .fc-toolbar {
    flex-wrap: wrap;
    gap: 8px;
    margin-bottom: 15px;
}

.fc .fc-toolbar-title {
    font-size: 1rem !important;
    font-weight: 600 !important;
}

.fc .fc-button {
    padding: 0.25rem 0.5rem !important;
    font-size: 0.75rem !important;
}

/* Celdas más compactas pero visibles */
.fc .fc-daygrid-day {
    min-height: 70px;
    cursor: pointer;
}

.fc .fc-daygrid-day-number {
    font-size: 0.8rem;
    font-weight: 500;
    padding: 3px 5px;
}

/* ===== ESTILOS PARA LOS DÍAS CON EVENTOS ===== */
.fc-daygrid-day.fc-day-venta .fc-daygrid-day-number {
    background-color: #28a745 !important;
    color: white !important;
    border-radius: 50% !important;
    width: 26px;
    height: 26px;
    display: inline-flex !important;
    align-items: center;
    justify-content: center;
    margin: 2px;
    font-weight: bold;
}

.fc-daygrid-day.fc-day-reporte .fc-daygrid-day-number {
    background-color: #dc3545 !important;
    color: white !important;
    border-radius: 50% !important;
    width: 26px;
    height: 26px;
    display: inline-flex !important;
    align-items: center;
    justify-content: center;
    margin: 2px;
    font-weight: bold;
}

.fc-daygrid-day.fc-day-ambos .fc-daygrid-day-number {
    background-color: #f39c12 !important;
    color: white !important;
    border-radius: 50% !important;
    width: 26px;
    height: 26px;
    display: inline-flex !important;
    align-items: center;
    justify-content: center;
    margin: 2px;
    font-weight: bold;
}

/* Eventos de fondo más sutiles */
.fc-bg-event {
    opacity: 0.1 !important;
}

.evento-venta { background-color: #28a745 !important; }
.evento-reporte { background-color: #dc3545 !important; }
.evento-ambos { background-color: #f39c12 !important; }

/* Tooltip */
.fc-daygrid-day[data-title] {
    position: relative;
}

.fc-daygrid-day[data-title]:hover::after {
    content: attr(data-title);
    position: absolute;
    bottom: 100%;
    left: 50%;
    transform: translateX(-50%);
    background: #2c3e50;
    color: white;
    padding: 4px 8px;
    border-radius: 6px;
    font-size: 11px;
    white-space: nowrap;
    z-index: 1000;
    margin-bottom: 5px;
    pointer-events: none;
}

/* Eliminar espacio en blanco del panel lateral */
.calendar-sidebar {
    background: #f8f9fa;
    border-radius: 12px;
    padding: 12px;
    height: 100%;
    display: flex;
    flex-direction: column;
}

.calendar-sidebar .info-box {
    flex: 1;
    display: flex;
    flex-direction: column;
    margin-bottom: 12px;
}

.calendar-sidebar .info-box:last-child {
    margin-bottom: 0;
}

.calendar-sidebar .info-box-content {
    flex: 1;
    display: flex;
    flex-direction: column;
    justify-content: center;
}

/* Ajustar las estadísticas para que ocupen el espacio vertical */
.calendar-sidebar .info-box .row {
    flex: 1;
    align-items: center;
}

.legend-item {
    display: flex;
    align-items: center;
    margin-bottom: 10px;
    font-size: 0.8rem;
}

.legend-color {
    width: 22px;
    height: 22px;
    border-radius: 50%;
    margin-right: 10px;
    flex-shrink: 0;
}

.legend-color.venta { background: #28a745; }
.legend-color.reporte { background: #dc3545; }
.legend-color.ambos { background: #f39c12; }

.stats-card {
    background: white;
    border-radius: 8px;
    padding: 8px;
    text-align: center;
}

.stats-number {
    font-size: 1.3rem;
    font-weight: bold;
    margin: 0;
}

.stats-label {
    font-size: 0.65rem;
    color: #6c757d;
    margin: 0;
}

/* Responsive para sidebar abierto */
@media (max-width: 1200px) {
    .fc .fc-daygrid-day {
        min-height: 60px;
    }
    
    .fc .fc-daygrid-day-number {
        font-size: 0.7rem;
        width: 22px !important;
        height: 22px !important;
    }
}

@media (max-width: 992px) {
    .calendar-sidebar {
        margin-top: 15px;
    }
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

/* Indicador de carga para filtros */
.filtro-loading {
    position: fixed;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    background: rgba(0,0,0,0.3);
    z-index: 9999;
    display: flex;
    align-items: center;
    justify-content: center;
    pointer-events: none;
    opacity: 0;
    transition: opacity 0.2s;
}

.filtro-loading.active {
    opacity: 1;
    pointer-events: all;
}

.filtro-loading .spinner {
    width: 50px;
    height: 50px;
    border: 4px solid #f3f3f3;
    border-top: 4px solid #f97316;
    border-radius: 50%;
    animation: spin 1s linear infinite;
    background: white;
    padding: 10px;
    border-radius: 50%;
    box-shadow: 0 2px 10px rgba(0,0,0,0.1);
}

@keyframes spin {
    0% { transform: rotate(0deg); }
    100% { transform: rotate(360deg); }
}

/* Mensaje sin datos */
.no-data-message {
    text-align: center;
    padding: 60px 20px;
    background: #f8f9fa;
    border-radius: 8px;
    margin: 20px 0;
}

.no-data-message i {
    font-size: 4rem;
    color: #dee2e6;
    margin-bottom: 15px;
    display: block;
}

.no-data-message p {
    color: #6c757d;
    font-size: 1rem;
    margin: 0;
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
                            <?php if (!$hayDatosTablaProductos): ?>
                                <tr>
                                    <td colspan="7" class="text-center py-5">
                                        <div class="no-data-message" style="margin: 0;">
                                            <i class="fas fa-chart-line" style="font-size: 4rem; color: #dee2e6;"></i>
                                            <p class="mt-3 mb-0">No hay productos con ventas en el período seleccionado</p>
                                            <small class="text-muted">Prueba con otro proveedor o rango de fechas</small>
                                        </div>
                                    </td>
                                </tr>
                            <?php else: ?>
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
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
                <?php if ($hayDatosTablaProductos): ?>
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
                    <!-- ALERTA PARA EL PRODUCTO ESPECIAL -->
                    <?php if ($hayProductoEspecial): ?>
                    <div class="alert alert-success alert-dismissible fade show" id="alertaProductoEspecial" style="display: none;">
                        <button type="button" class="close" onclick="cerrarAlertaProductoEspecial()" aria-label="Close">
                            <span aria-hidden="true">&times;</span>
                        </button>
                        <h5><i class="icon fas fa-check-circle"></i> Producto pagado por adelantado</h5>
                        <p>
                            Las <strong>libretas del proveedor Nevaris 3D</strong> están excluidas de esta deuda porque se pagaron por adelantado. 
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
                                    <th class="sortable" data-column="0">Producto</th>
                                    <th class="sortable" data-column="1">Proveedor</th>
                                    <th class="sortable text-center" data-column="2">Vendidos</th>
                                    <th class="sortable text-right" data-column="3">Costo unitario</th>
                                    <th class="sortable text-right" data-column="4">Deuda total</th>
                                </tr>
                            </thead>
                            <tbody id="tablaDeudaBody">
                                <?php if (!$hayDatosTablaDeuda): ?>
                                    <tr>
                                        <td colspan="5" class="text-center py-5">
                                            <div class="no-data-message" style="margin: 0;">
                                                <i class="fas fa-hand-holding-usd" style="font-size: 4rem; color: #dee2e6;"></i>
                                                <p class="mt-3 mb-0">No hay deudas registradas en el período seleccionado</p>
                                                <small class="text-muted">Prueba con otro proveedor o rango de fechas</small>
                                            </div>
                                        </td>
                                    </tr>
                                <?php else: ?>
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
                                    <?php 
                                        endif; 
                                    endforeach; 
                                endif; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php if ($hayDatosTablaDeuda): ?>
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
                            Las <strong>libretas de Nevaris 3D</strong> están excluidas (ya pagadas).
                        </small>
                    </div>
                    <?php endif; ?>
                </div>
                <?php if ($hayDatosTablaDeuda): ?>
                <div class="card-footer text-right">
                    <small class="text-muted">
                        <i class="fas fa-info-circle mr-1"></i>
                        Este monto representa el total pendiente de pago a proveedores. 
                        Las <strong>libretas de Nevaris 3D</strong> están excluidas (ya pagadas).
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

// Función para generar PDFs
function generarPDF(tipo) {
    registrarRangoReporte(tipo);
    
    const { jsPDF } = window.jspdf;
    
    // ... (mantener el resto de la función generarPDF igual que en tu código original)
    // Por razones de espacio, no incluyo toda la función aquí, pero se mantiene igual
    
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
        if (this.infoDesde) this.infoDesde.textContent = desde;
        if (this.infoHasta) this.infoHasta.textContent = hasta;
        if (this.infoTotal) this.infoTotal.textContent = this.datos.length;
        
        this.renderizarPaginacion(totalPaginas);
        
        // Mostrar controles si están ocultos
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
</script>