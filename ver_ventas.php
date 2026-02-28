<?php
include('includes/header.php');
include('includes/navbar.php');
include 'includes/db.php';

// --- Filtros (proveedor y fechas) ---
$filtroProveedor = $_GET['proveedor'] ?? '';
$filtroInicio = $_GET['fecha_inicio'] ?? '';
$filtroFin = $_GET['fecha_fin'] ?? '';

// --- CONSULTA PARA PRODUCTOS CON VENTAS (para la vista) ---
$sql = "
SELECT 
    p.id,
    p.nombre,
    p.proveedor,
    p.precio_compra,
    p.precio_venta,
    p.cantidad,
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
$totalGanancia = $totalProveedor = $totalVendidos = $totalStock = 0;
$productos = [];

if ($resultado) {
    while ($row = $resultado->fetch_assoc()) {
        $vendidos = (int)$row['total_vendida'];
        $stock = (int)$row['cantidad'];

        $ganancia = ($row['precio_venta'] - $row['precio_compra']) * $vendidos;
        $costoProveedor = $row['precio_compra'] * $vendidos;

        $totalGanancia += $ganancia;
        $totalProveedor += $costoProveedor;
        $totalVendidos += $vendidos;
        $totalStock += $stock;

        $productos[] = [
            'nombre' => $row['nombre'],
            'proveedor' => $row['proveedor'],
            'vendidos' => $vendidos,
            'stock' => $stock,
            'precio_compra' => $row['precio_compra'],
            'precio_venta' => $row['precio_venta'],
            'ganancia' => $ganancia
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

/* ESTILOS PARA EL DROPDOWN PDF (MODAL QUE SALE DEL BOTÓN) */
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
                        
                        <!-- DROPDOWN MENU (sale del botón) -->
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

            <!-- KPIs con información adicional -->
            <div class="row">

                <div class="col-12 col-md-6 col-lg-3 mb-3">
                    <div class="small-box bg-info">
                        <div class="inner">
                            <h3><?= number_format($totalVendidos) ?></h3>
                            <p class="mb-0">Productos vendidos</p>
                            <small><?= count($productos) ?> productos diferentes</small>
                            <div class="mini-progress">
                                <div class="mini-progress-bar" style="width: 100%"></div>
                            </div>
                        </div>
                        <div class="icon"><i class="fas fa-box"></i></div>
                        <div class="small-box-footer">
                            <i class="fas fa-chart-bar mr-1"></i> Total del período
                        </div>
                    </div>
                </div>

                <div class="col-12 col-md-6 col-lg-3 mb-3">
                    <div class="small-box bg-danger">
                        <div class="inner">
                            <h3>$<?= number_format($totalProveedor, 2) ?></h3>
                            <p class="mb-0">Deuda con proveedores</p>
                            <small>Costo de productos vendidos</small>
                            <div class="mini-progress">
                                <?php $porcentajeCosto = ($totalGanancia + $totalProveedor) > 0 ? ($totalProveedor / ($totalGanancia + $totalProveedor)) * 100 : 0; ?>
                                <div class="mini-progress-bar" style="width: <?= $porcentajeCosto ?>%"></div>
                            </div>
                        </div>
                        <div class="icon"><i class="fas fa-hand-holding-usd"></i></div>
                        <div class="small-box-footer">
                            <i class="fas fa-percentage mr-1"></i> <?= number_format($porcentajeCosto, 1) ?>% del costo total
                        </div>
                    </div>
                </div>

                <div class="col-12 col-md-6 col-lg-3 mb-3">
                    <div class="small-box bg-success">
                        <div class="inner">
                            <h3>$<?= number_format($totalGanancia, 2) ?></h3>
                            <p class="mb-0">Ganancia neta</p>
                            <small>Margen: <?= $totalProveedor > 0 ? number_format(($totalGanancia / $totalProveedor) * 100, 1) : 0 ?>%</small>
                            <div class="mini-progress">
                                <?php $porcentajeGanancia = ($totalGanancia + $totalProveedor) > 0 ? ($totalGanancia / ($totalGanancia + $totalProveedor)) * 100 : 0; ?>
                                <div class="mini-progress-bar" style="width: <?= $porcentajeGanancia ?>%"></div>
                            </div>
                        </div>
                        <div class="icon"><i class="fas fa-chart-line"></i></div>
                        <div class="small-box-footer">
                            <i class="fas fa-arrow-up mr-1"></i> Rentabilidad
                        </div>
                    </div>
                </div>

                <div class="col-12 col-md-6 col-lg-3 mb-3">
                    <div class="small-box bg-warning">
                        <div class="inner">
                            <h3><?= number_format($totalStock) ?></h3>
                            <p class="mb-0">Stock restante</p>
                            <small>Valor: $<?= number_format($totalStock * 100, 2) ?></small>
                            <div class="mini-progress">
                                <?php $porcentajeStock = $totalVendidos > 0 ? min(100, ($totalStock / $totalVendidos) * 100) : 0; ?>
                                <div class="mini-progress-bar" style="width: <?= $porcentajeStock ?>%"></div>
                            </div>
                        </div>
                        <div class="icon"><i class="fas fa-warehouse"></i></div>
                        <div class="small-box-footer">
                            <i class="fas fa-boxes mr-1"></i> Inventario actual
                        </div>
                    </div>
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
            
            <!-- TABLA PRODUCTOS -->
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
                    <table class="table table-hover table-sm mb-0 text-nowrap">
                        <thead class="thead-dark">
                            <tr>
                                <th>Producto</th>
                                <th>Proveedor</th>
                                <th class="text-center">Vendidos</th>
                                <th class="text-center">Stock</th>
                                <th class="text-right">Compra</th>
                                <th class="text-right">Venta</th>
                                <th class="text-right">Ganancia</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($productos as $p): ?>
                            <tr>
                                <td><?= $p['nombre'] ?></td>
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
            </div>

            <!-- DEUDA CON PROVEEDORES -->
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

                <div class="card-body table-responsive p-0">
                    <table class="table table-hover table-sm mb-0 text-nowrap">
                        <thead class="thead-dark">
                            <tr>
                                <th>Producto</th>
                                <th>Proveedor</th>
                                <th class="text-center">Vendidos</th>
                                <th class="text-right">Costo unitario</th>
                                <th class="text-right">Deuda total</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($productos as $p): ?>
                            <?php $deuda = $p['precio_compra'] * $p['vendidos']; ?>
                            <tr>
                                <td><?= $p['nombre'] ?></td>
                                <td><?= $p['proveedor'] ?></td>
                                <td class="text-center"><?= $p['vendidos'] ?></td>
                                <td class="text-right">$<?= number_format($p['precio_compra'], 2) ?></td>
                                <td class="text-right font-weight-bold text-danger">
                                    $<?= number_format($deuda, 2) ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <div class="card-footer text-right">
                    <small class="text-muted">
                        Este monto representa el total pendiente de pago a proveedores.
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
                        <button type="button" class="btn btn-tool" data-card-widget="collapse">
                            <i class="fas fa-minus"></i>
                        </button>
                    </div>
                </div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-9">
                            <div id="calendario"></div>
                        </div>
                        <div class="col-md-3">
                            <div class="info-box bg-light p-3" style="border-left: 4px solid #17a2b8;">
                                <h5 class="text-dark mb-3"><i class="fas fa-info-circle mr-2 text-info"></i>Leyenda</h5>
                                <div class="d-flex align-items-center mb-3">
                                    <div style="width: 20px; height: 20px; background: #28a745; border-radius: 4px; margin-right: 10px; border: 1px solid #1e7e34;"></div>
                                    <span><strong>Ventas</strong> - Días con ventas registradas</span>
                                </div>
                                <div class="d-flex align-items-center mb-3">
                                    <div style="width: 20px; height: 20px; background: #dc3545; border-radius: 4px; margin-right: 10px; border: 1px solid #a71d2a;"></div>
                                    <span><strong>Reportes</strong> - Días donde se generaron reportes</span>
                                </div>
                                <div class="d-flex align-items-center mb-3">
                                    <div style="width: 20px; height: 20px; background: #ffc107; border-radius: 4px; margin-right: 10px; border: 1px solid #d39e00;"></div>
                                    <span><strong>Ambos</strong> - Ventas y reportes el mismo día</span>
                                </div>
                                <hr class="my-3">
                                <div class="mt-2">
                                    <h6 class="text-dark"><i class="fas fa-chart-bar mr-2 text-info"></i>Estadísticas</h6>
                                    <table class="table table-sm table-borderless">
                                        <tr>
                                            <td class="pl-0">Días con ventas:</td>
                                            <td class="text-success font-weight-bold text-right" id="diasVentas">0</td>
                                        </tr>
                                        <tr>
                                            <td class="pl-0">Días con reportes:</td>
                                            <td class="text-danger font-weight-bold text-right" id="diasReportes">0</td>
                                        </tr>
                                        <tr>
                                            <td class="pl-0">Total días activos:</td>
                                            <td class="text-primary font-weight-bold text-right" id="diasActivos">0</td>
                                        </tr>
                                    </table>
                                </div>
                                
                                <!-- Información del filtro actual -->
                                <div class="mt-3 p-2 bg-white rounded" style="border: 1px solid #dee2e6;">
                                    <h6 class="text-dark"><i class="fas fa-filter mr-2 text-info"></i>Filtro actual</h6>
                                    <p class="mb-1 small">
                                        <strong>Proveedor:</strong> 
                                        <span id="filtroProveedor"><?= $filtroProveedor ?: 'Todos' ?></span>
                                    </p>
                                    <p class="mb-0 small">
                                        <strong>Período:</strong> 
                                        <span id="filtroPeriodo">
                                            <?php 
                                            if ($filtroInicio && $filtroFin) {
                                                echo date('d/m/Y', strtotime($filtroInicio)) . ' - ' . date('d/m/Y', strtotime($filtroFin));
                                            } else {
                                                echo 'Histórico completo';
                                            }
                                            ?>
                                        </span>
                                    </p>
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
        eventosMap.set(fecha, {
            venta: true,
            reporte: eventosMap.get(fecha)?.reporte || false
        });
    });
    
    // Agregar días con reportes (desde localStorage)
    const reportesGuardados = JSON.parse(localStorage.getItem('diasReportes') || '[]');
    reportesGuardados.forEach(fecha => {
        eventosMap.set(fecha, {
            venta: eventosMap.get(fecha)?.venta || false,
            reporte: true
        });
    });
    
    const eventos = [];
    let contVentas = 0;
    let contReportes = 0;
    
    eventosMap.forEach((valor, fecha) => {
        const fechaObj = new Date(fecha + 'T12:00:00');
        
        if (valor.venta) contVentas++;
        if (valor.reporte) contReportes++;
        
        let className = '';
        let tooltip = '';
        
        if (valor.venta && valor.reporte) {
            className = 'fc-day-ambos';
            tooltip = 'Ventas y reportes';
        } else if (valor.venta) {
            className = 'fc-day-venta';
            tooltip = 'Día con ventas';
        } else if (valor.reporte) {
            className = 'fc-day-reporte';
            tooltip = 'Día con reportes';
        }
        
        eventos.push({
            start: fechaObj,
            allDay: true,
            display: 'background',
            color: valor.venta && valor.reporte ? '#ffc107' : (valor.venta ? '#28a745' : '#dc3545'),
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

// Función para limpiar historial de reportes
function limpiarHistorialReportes() {
    if (confirm('¿Estás seguro de limpiar el historial de reportes?')) {
        localStorage.removeItem('diasReportes');
        cargarEventosCalendario();
    }
}

// Inicializar calendario
document.addEventListener('DOMContentLoaded', function() {
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
                const fecha = info.event.start.toLocaleDateString('es-ES', {
                    weekday: 'long',
                    year: 'numeric',
                    month: 'long',
                    day: 'numeric'
                });
                
                let mensaje = `Fecha: ${fecha}\n`;
                if (info.event.extendedProps.tieneVenta) {
                    mensaje += '✓ Hay ventas registradas\n';
                }
                if (info.event.extendedProps.tieneReporte) {
                    mensaje += '📄 Se generaron reportes\n';
                }
                
                alert(mensaje);
            }
        });
        
        calendar.render();
        cargarEventosCalendario();
    }
    
    // Evento para limpiar historial
    document.getElementById('limpiarHistorialBtn')?.addEventListener('click', limpiarHistorialReportes);
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

// Función para registrar el RANGO de fechas del reporte
function registrarRangoReporte() {
    // Obtener las fechas del filtro actual
    const filtroInicio = '<?= $filtroInicio ?>';
    const filtroFin = '<?= $filtroFin ?>';
    
    // Si hay un rango de fechas seleccionado
    if (filtroInicio && filtroFin) {
        let fechaActual = new Date(filtroInicio);
        const fechaFin = new Date(filtroFin);
        
        let fechasReporte = [];
        
        // Recorrer todas las fechas del rango
        while (fechaActual <= fechaFin) {
            const fechaStr = fechaActual.toISOString().split('T')[0];
            fechasReporte.push(fechaStr);
            
            // Avanzar al siguiente día
            fechaActual.setDate(fechaActual.getDate() + 1);
        }
        
        // Guardar en localStorage
        let reportesGuardados = JSON.parse(localStorage.getItem('diasReportes') || '[]');
        
        // Agregar las nuevas fechas (sin duplicados)
        fechasReporte.forEach(fecha => {
            if (!reportesGuardados.includes(fecha)) {
                reportesGuardados.push(fecha);
            }
        });
        
        localStorage.setItem('diasReportes', JSON.stringify(reportesGuardados));
        
        // Actualizar el calendario
        if (calendar) {
            cargarEventosCalendario();
        }
    } else {
        // Si no hay filtro de fechas, solo registrar el día actual
        const hoy = new Date();
        const fechaStr = hoy.toISOString().split('T')[0];
        
        let reportesGuardados = JSON.parse(localStorage.getItem('diasReportes') || '[]');
        if (!reportesGuardados.includes(fechaStr)) {
            reportesGuardados.push(fechaStr);
            localStorage.setItem('diasReportes', JSON.stringify(reportesGuardados));
            
            if (calendar) {
                cargarEventosCalendario();
            }
        }
    }
}

// Función para generar PDFs
function generarPDF(tipo) {
    // Registrar el rango de fechas del reporte (NO solo el día actual)
    registrarRangoReporte();
    
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
    // Consulta detallada para proveedores
    $sqlProveedores = "
    SELECT 
        p.nombre AS producto,
        p.proveedor,
        v.fecha_venta,
        DATE_FORMAT(v.fecha_venta, '%d/%m/%Y') AS fecha_venta_formateada,
        DATE_FORMAT(v.fecha_venta, '%Y-%m-%d') AS fecha_venta_iso,
        v.cantidad_vendida,
        p.cantidad AS stock_actual,
        p.precio_compra,
        p.precio_venta,
        (p.cantidad + v.cantidad_vendida) AS stock_inicial,
        (p.precio_compra * v.cantidad_vendida) AS total_deuda,
        (p.precio_venta * v.cantidad_vendida) AS total_venta,
        ((p.precio_venta - p.precio_compra) * v.cantidad_vendida) AS ganancia
    FROM ventas v
    INNER JOIN productos p ON v.id_producto = p.id
    WHERE 1
    ";
    
    if ($filtroProveedor !== '') {
        $sqlProveedores .= " AND p.proveedor = '" . $conn->real_escape_string($filtroProveedor) . "'";
    }
    
    if ($filtroInicio !== '' && $filtroFin !== '') {
        $sqlProveedores .= " AND DATE(v.fecha_venta) BETWEEN '" . $conn->real_escape_string($filtroInicio) . "' 
        AND '" . $conn->real_escape_string($filtroFin) . "'";
    }
    
    $sqlProveedores .= " ORDER BY v.fecha_venta DESC, p.proveedor, p.nombre";
    
    $resultadoProveedores = $conn->query($sqlProveedores);
    $ventasDetalle = [];
    $deudaPorProveedor = [];
    $ventasPorFecha = [];
    $resumenMensual = [];
    
    if ($resultadoProveedores) {
        while ($row = $resultadoProveedores->fetch_assoc()) {
            $ventasDetalle[] = $row;
            $prov = $row['proveedor'];
            if (!isset($deudaPorProveedor[$prov])) {
                $deudaPorProveedor[$prov] = 0;
            }
            $deudaPorProveedor[$prov] += $row['total_deuda'];
            
            $fecha = $row['fecha_venta_iso'];
            if (!isset($ventasPorFecha[$fecha])) {
                $ventasPorFecha[$fecha] = [
                    'fecha' => $row['fecha_venta_formateada'],
                    'total_ventas' => 0,
                    'total_deuda' => 0,
                    'total_ganancia' => 0,
                    'cantidad_productos' => 0
                ];
            }
            $ventasPorFecha[$fecha]['total_ventas'] += $row['total_venta'];
            $ventasPorFecha[$fecha]['total_deuda'] += $row['total_deuda'];
            $ventasPorFecha[$fecha]['total_ganancia'] += $row['ganancia'];
            $ventasPorFecha[$fecha]['cantidad_productos'] += $row['cantidad_vendida'];
            
            $mes = date('Y-m', strtotime($row['fecha_venta']));
            if (!isset($resumenMensual[$mes])) {
                $resumenMensual[$mes] = [
                    'mes' => date('F Y', strtotime($row['fecha_venta'])),
                    'total_ventas' => 0,
                    'total_deuda' => 0,
                    'total_ganancia' => 0
                ];
            }
            $resumenMensual[$mes]['total_ventas'] += $row['total_venta'];
            $resumenMensual[$mes]['total_deuda'] += $row['total_deuda'];
            $resumenMensual[$mes]['total_ganancia'] += $row['ganancia'];
        }
    }
    
    ksort($ventasPorFecha);
    ?>

    // Función para generar PDF General
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

        <?php if (empty($productos)): ?>
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
        docAdmin.save("Reporte_Ejecutivo_Ventas.pdf");
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
        docAdmin.text("TOTAL VENTAS", 35, 100);
        docAdmin.text("DEUDA TOTAL", pageWidth / 2 - 25, 100);
        docAdmin.text("GANANCIA NETA", pageWidth - 55, 100);

        docAdmin.setFontSize(18);
        docAdmin.setTextColor(colors.dark[0], colors.dark[1], colors.dark[2]);
        docAdmin.setFont("helvetica", "bold");
        docAdmin.text("$<?= number_format($totalVendidos * 100, 2) ?>", 35, 115);
        docAdmin.text("$<?= number_format($totalProveedor, 2) ?>", pageWidth / 2 - 25, 115);
        docAdmin.text("$<?= number_format($totalGanancia, 2) ?>", pageWidth - 55, 115);

        y = 145;

        // --- ANÁLISIS TEMPORAL ---
        if (Object.keys(<?= json_encode($ventasPorFecha) ?>).length > 0) {
            if (y > 200) { docAdmin.addPage(); y = 25; pageNum++; }
            
            docAdmin.setFontSize(16);
            docAdmin.setTextColor(colors.secondary[0], colors.secondary[1], colors.secondary[2]);
            docAdmin.setFont("helvetica", "bold");
            docAdmin.text("Análisis Temporal de Ventas", 20, y);
            y += 8;

            <?php
            $sqlDetallado = "
            SELECT 
                DATE_FORMAT(v.fecha_venta, '%d/%m/%Y') AS fecha,
                p.proveedor,
                p.nombre AS producto,
                v.cantidad_vendida,
                (p.precio_venta * v.cantidad_vendida) AS total_venta,
                (p.precio_compra * v.cantidad_vendida) AS total_costo,
                ((p.precio_venta - p.precio_compra) * v.cantidad_vendida) AS ganancia
            FROM ventas v
            INNER JOIN productos p ON v.id_producto = p.id
            WHERE 1
            ";
            
            if ($filtroProveedor !== '') {
                $sqlDetallado .= " AND p.proveedor = '" . $conn->real_escape_string($filtroProveedor) . "'";
            }
            
            if ($filtroInicio !== '' && $filtroFin !== '') {
                $sqlDetallado .= " AND DATE(v.fecha_venta) BETWEEN '" . $conn->real_escape_string($filtroInicio) . "' 
                AND '" . $conn->real_escape_string($filtroFin) . "'";
            }
            
            $sqlDetallado .= " ORDER BY v.fecha_venta DESC";
            
            $resultadoDetallado = $conn->query($sqlDetallado);
            $detalleVentas = [];
            $totalGananciaDetalle = 0;
            
            if ($resultadoDetallado) {
                while ($row = $resultadoDetallado->fetch_assoc()) {
                    $detalleVentas[] = $row;
                    $totalGananciaDetalle += $row['ganancia'];
                }
            }
            ?>

            const detalleVentasData = [
                <?php foreach ($detalleVentas as $venta): ?>
                [
                    '<?= $venta['fecha'] ?>',
                    '<?= addslashes($venta['proveedor']) ?>',
                    '<?= addslashes($venta['producto']) ?>',
                    <?= $venta['cantidad_vendida'] ?>,
                    '$<?= number_format($venta['total_venta'], 2) ?>',
                    '$<?= number_format($venta['total_costo'], 2) ?>',
                    '$<?= number_format($venta['ganancia'], 2) ?>'
                ],
                <?php endforeach; ?>
            ];

            docAdmin.autoTable({
                head: [['Fecha', 'Proveedor', 'Artículo', 'Cant', 'Ventas', 'Costo', 'Ganancia']],
                body: detalleVentasData,
                startY: y,
                theme: "grid",
                headStyles: { fillColor: colors.secondary, textColor: colors.white, fontSize: 9 },
                styles: { fontSize: 7, cellPadding: 2 },
                columnStyles: {
                    0: { cellWidth: 20 },
                    1: { cellWidth: 30 },
                    2: { cellWidth: 40 },
                    3: { cellWidth: 12, halign: 'center' },
                    4: { cellWidth: 22, halign: 'right' },
                    5: { cellWidth: 22, halign: 'right' },
                    6: { cellWidth: 22, halign: 'right', fontStyle: 'bold' }
                },
                margin: { left: 10, right: 10 }
            });

            y = docAdmin.lastAutoTable.finalY + 5;
            docAdmin.setFontSize(9);
            docAdmin.setFont("helvetica", "bold");
            docAdmin.setTextColor(colors.secondary[0], colors.secondary[1], colors.secondary[2]);
            docAdmin.text(`TOTAL GANANCIA ACUMULADA: $<?= number_format($totalGananciaDetalle, 2) ?>`, pageWidth - 30, y, { align: "right" });
            y += 15;
        }

        // --- DETALLE DE PRODUCTOS VENDIDOS ---
        if (y > 240) { docAdmin.addPage(); y = 25; pageNum++; }

        docAdmin.setFontSize(16);
        docAdmin.setTextColor(colors.success[0], colors.success[1], colors.success[2]);
        docAdmin.setFont("helvetica", "bold");
        docAdmin.text("Detalle de Productos Vendidos", 20, y);
        y += 8;

        docAdmin.autoTable({
            html: document.querySelector(".card-warning table"),
            startY: y,
            theme: "striped",
            headStyles: { fillColor: colors.success, textColor: colors.white, fontSize: 9 },
            styles: { fontSize: 8, cellPadding: 4 },
            margin: { left: 15, right: 15 },
            didDrawPage: function(data) {
                y = data.cursor.y;
            }
        });

        let totalGananciasTabla = 0;
        const filasTabla = document.querySelectorAll(".card-warning table tbody tr");
        filasTabla.forEach(fila => {
            const celdas = fila.querySelectorAll("td");
            if (celdas.length >= 6) {
                const ganancia = parseFloat(celdas[6].textContent.replace(/[$,]/g, '')) || 0;
                totalGananciasTabla += ganancia;
            }
        });

        y = docAdmin.lastAutoTable.finalY + 5;
        docAdmin.setFontSize(9);
        docAdmin.setFont("helvetica", "bold");
        docAdmin.setTextColor(colors.success[0], colors.success[1], colors.success[2]);
        docAdmin.text(`TOTAL GANANCIAS: $${totalGananciasTabla.toFixed(2)}`, pageWidth - 30, y, { align: "right" });
        y += 15;

        // --- ANÁLISIS DE COSTOS ---
        if (y > 240) { docAdmin.addPage(); y = 25; pageNum++; }

        docAdmin.setFontSize(16);
        docAdmin.setTextColor(colors.warning[0], colors.warning[1], colors.warning[2]);
        docAdmin.setFont("helvetica", "bold");
        docAdmin.text("Análisis de Costos por Producto", 20, y);
        y += 8;

        docAdmin.autoTable({
            html: document.querySelector(".card-danger table"),
            startY: y,
            theme: "striped",
            headStyles: { fillColor: colors.warning, textColor: colors.dark, fontSize: 9 },
            styles: { fontSize: 8, cellPadding: 4 },
            margin: { left: 15, right: 15 }
        });

        let totalDeudaTabla = 0;
        const filasCosto = document.querySelectorAll(".card-danger table tbody tr");
        filasCosto.forEach(fila => {
            const celdas = fila.querySelectorAll("td");
            if (celdas.length >= 4) {
                const deuda = parseFloat(celdas[4].textContent.replace(/[$,]/g, '')) || 0;
                totalDeudaTabla += deuda;
            }
        });

        y = docAdmin.lastAutoTable.finalY + 5;
        docAdmin.setFontSize(9);
        docAdmin.setFont("helvetica", "bold");
        docAdmin.setTextColor(colors.warning[0], colors.warning[1], colors.warning[2]);
        docAdmin.text(`TOTAL DEUDA ACUMULADA: $${totalDeudaTabla.toFixed(2)}`, pageWidth - 30, y, { align: "right" });
        y += 15;

        // --- GRÁFICA ---
        <?php if ($totalGanancia > 0 || $totalProveedor > 0): ?>
        if (y > 200) { docAdmin.addPage(); y = 25; pageNum++; }

        docAdmin.setFontSize(16);
        docAdmin.setTextColor(colors.dark[0], colors.dark[1], colors.dark[2]);
        docAdmin.setFont("helvetica", "bold");
        docAdmin.text("Visualización de Rendimiento", 20, y);
        y += 8;

        const canvas = document.getElementById("graficaVentas");
        if (canvas) {
            const imgData = canvas.toDataURL("image/png");
            const imgWidth = 150;
            const imgHeight = (canvas.height * imgWidth) / canvas.width;
            docAdmin.addImage(imgData, "PNG", (pageWidth - imgWidth) / 2, y, imgWidth, imgHeight);
            y += imgHeight + 15;
        }
        <?php endif; ?>

        const totalPagesAdmin = docAdmin.internal.getNumberOfPages();
        for (let i = 1; i <= totalPagesAdmin; i++) {
            addFooter(docAdmin, i, totalPagesAdmin);
        }

        docAdmin.save("Reporte_Administrador.pdf");
    }

    // Función para generar PDF de Proveedores
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
        
        // --- RESUMEN ---
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

        // --- TABLA DETALLADA ---
        docProv.setFontSize(14);
        docProv.setTextColor(colors.dark[0], colors.dark[1], colors.dark[2]);
        docProv.setFont("helvetica", "bold");
        docProv.text("Detalle de Ventas por Producto", 20, provY);
        provY += 8;

        const proveedoresData = [
            <?php foreach ($ventasDetalle as $venta): ?>
            [
                '<?= addslashes($venta['producto']) ?>',
                '<?= addslashes($venta['proveedor']) ?>',
                '<?= $venta['fecha_venta_formateada'] ?>',
                <?= $venta['cantidad_vendida'] ?>,
                '$<?= number_format($venta['precio_compra'], 2) ?>',
                '$<?= number_format($venta['total_deuda'], 2) ?>'
            ],
            <?php endforeach; ?>
        ];

        if (proveedoresData.length > 0) {
            docProv.autoTable({
                head: [['Producto', 'Proveedor', 'Fecha Venta', 'Cant.', 'P.Compra', 'Deuda']],
                body: proveedoresData,
                startY: provY,
                theme: "grid",
                headStyles: { 
                    fillColor: colors.danger, 
                    textColor: colors.white, 
                    fontSize: 9, 
                    halign: 'center',
                    fontStyle: 'bold'
                },
                styles: { 
                    fontSize: 8, 
                    cellPadding: 3,
                    overflow: 'linebreak',
                    cellWidth: 'wrap'
                },
                columnStyles: {
                    0: { cellWidth: 40, halign: 'left' },
                    1: { cellWidth: 35, halign: 'left' },
                    2: { cellWidth: 25, halign: 'center' },
                    3: { cellWidth: 15, halign: 'center' },
                    4: { cellWidth: 25, halign: 'right' },
                    5: { cellWidth: 30, halign: 'right', fontStyle: 'bold' }
                },
                margin: { left: 15, right: 15 }
            });
            
            provY = docProv.lastAutoTable.finalY + 15;

            // --- RESUMEN POR PROVEEDOR ---
            if (provY > 220) { docProv.addPage(); provY = 25; provPageNum++; }
            
            docProv.setFontSize(14);
            docProv.setTextColor(colors.danger[0], colors.danger[1], colors.danger[2]);
            docProv.setFont("helvetica", "bold");
            docProv.text("Resumen por Proveedor", 20, provY);
            provY += 8;

            const resumenProvData = [
                <?php foreach ($deudaPorProveedor as $prov => $total): ?>
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
            
            provY = docProv.lastAutoTable.finalY + 20;
        } else {
            docProv.setFontSize(14);
            docProv.setTextColor(colors.medium[0], colors.medium[1], colors.medium[2]);
            docProv.text("No hay ventas registradas en el período seleccionado.", provPageWidth / 2, provY + 20, { align: "center" });
        }

        const totalPagesProv = docProv.internal.getNumberOfPages();
        for (let i = 1; i <= totalPagesProv; i++) {
            addProvFooter(docProv, i, totalPagesProv);
        }

        <?php if ($filtroProveedor !== ''): ?>
        docProv.save("reporte_deuda_proveedor_<?= $nombreProveedorParaArchivo ?>.pdf");
        <?php else: ?>
        docProv.save("reporte_deuda_todos_proveedores.pdf");
        <?php endif; ?>
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
</script>
<?php
include('includes/footer.php');
?>