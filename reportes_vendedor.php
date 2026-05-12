<?php
include 'includes/session.php';
include 'includes/db.php';
include 'includes/header.php';
include 'includes/navbar.php';

// Verificar rol - solo vendedor y administrador
if ($_SESSION['rol'] !== 'vendedor' && $_SESSION['rol'] !== 'administrador') {
    header("Location: index.php");
    exit;
}

$id_vendedor = $_SESSION['usuario_id'];
$nombre_vendedor = $_SESSION['nombre'] ?? 'Vendedor';

// ================== PROCESAR FILTROS ==================
$fecha_inicio = isset($_GET['fecha_inicio']) ? $_GET['fecha_inicio'] : date('Y-m-01');
$fecha_fin = isset($_GET['fecha_fin']) ? $_GET['fecha_fin'] : date('Y-m-d');
$tipo_reporte = isset($_GET['tipo_reporte']) ? $_GET['tipo_reporte'] : 'ventas';
$periodo = isset($_GET['periodo']) ? $_GET['periodo'] : 'personalizado';

// Ajustar fechas según período seleccionado
switch ($periodo) {
    case 'hoy':
        $fecha_inicio = date('Y-m-d');
        $fecha_fin = date('Y-m-d');
        break;
    case 'semana':
        $fecha_inicio = date('Y-m-d', strtotime('monday this week'));
        $fecha_fin = date('Y-m-d', strtotime('sunday this week'));
        break;
    case 'mes':
        $fecha_inicio = date('Y-m-01');
        $fecha_fin = date('Y-m-t');
        break;
    case 'mes_anterior':
        $fecha_inicio = date('Y-m-01', strtotime('-1 month'));
        $fecha_fin = date('Y-m-t', strtotime('-1 month'));
        break;
    case 'trimestre':
        $trimestre = ceil(date('n') / 3);
        $año = date('Y');
        $mes_inicio = ($trimestre - 1) * 3 + 1;
        $mes_fin = $trimestre * 3;
        $fecha_inicio = date("$año-$mes_inicio-01");
        $fecha_fin = date("$año-$mes_fin-", date("t", strtotime("$año-$mes_fin-01")));
        break;
    default:
        // personalizado - mantener fechas del GET
        break;
}

// ================== CONSULTAS PRINCIPALES ==================

// 1. Resumen general del período
$resumen = $conn->query("
    SELECT 
        COUNT(DISTINCT v.id) AS total_ventas,
        IFNULL(SUM(v.cantidad_vendida), 0) AS total_unidades,
        IFNULL(SUM(v.cantidad_vendida * p.precio_venta), 0) AS total_ingresos,
        IFNULL(SUM((p.precio_venta - p.precio_compra) * v.cantidad_vendida), 0) AS utilidad_estimada,
        IFNULL(AVG(v.cantidad_vendida * p.precio_venta), 0) AS ticket_promedio
    FROM ventas v
    JOIN productos p ON v.id_producto = p.id
    WHERE v.id_vendedor = $id_vendedor
    AND DATE(v.fecha_venta) BETWEEN '$fecha_inicio' AND '$fecha_fin'
")->fetch_assoc();

// 2. Ventas por día (para gráfico)
$ventas_por_dia = [];
$resDias = $conn->query("
    SELECT 
        DATE(v.fecha_venta) AS fecha,
        IFNULL(SUM(v.cantidad_vendida * p.precio_venta), 0) AS total,
        COUNT(DISTINCT v.id) AS num_ventas
    FROM ventas v
    JOIN productos p ON v.id_producto = p.id
    WHERE v.id_vendedor = $id_vendedor
    AND DATE(v.fecha_venta) BETWEEN '$fecha_inicio' AND '$fecha_fin'
    GROUP BY DATE(v.fecha_venta)
    ORDER BY fecha ASC
");
while ($row = $resDias->fetch_assoc()) {
    $ventas_por_dia[] = $row;
}

// 3. Top productos más vendidos
$top_productos = [];
$resTop = $conn->query("
    SELECT 
        p.nombre,
        SUM(v.cantidad_vendida) AS unidades,
        SUM(v.cantidad_vendida * p.precio_venta) AS ingresos,
        COUNT(DISTINCT v.id) AS veces_vendido
    FROM ventas v
    JOIN productos p ON v.id_producto = p.id
    WHERE v.id_vendedor = $id_vendedor
    AND DATE(v.fecha_venta) BETWEEN '$fecha_inicio' AND '$fecha_fin'
    GROUP BY p.id
    ORDER BY ingresos DESC
    LIMIT 10
");
while ($row = $resTop->fetch_assoc()) {
    $top_productos[] = $row;
}

// 4. Métodos de pago utilizados
$metodos_pago = [];
$resMetodos = $conn->query("
    SELECT 
        metodo_pago,
        COUNT(*) AS cantidad,
        SUM(cantidad_vendida * p.precio_venta) AS total
    FROM ventas v
    JOIN productos p ON v.id_producto = p.id
    WHERE v.id_vendedor = $id_vendedor
    AND DATE(v.fecha_venta) BETWEEN '$fecha_inicio' AND '$fecha_fin'
    GROUP BY metodo_pago
");
while ($row = $resMetodos->fetch_assoc()) {
    $metodos_pago[] = $row;
}

// 5. Clientes frecuentes del período
$clientes_top = [];
$resClientes = $conn->query("
    SELECT 
        v.correo_cliente AS email,
        COUNT(DISTINCT v.id) AS compras,
        SUM(v.cantidad_vendida * p.precio_venta) AS total_gastado
    FROM ventas v
    JOIN productos p ON v.id_producto = p.id
    WHERE v.id_vendedor = $id_vendedor
    AND v.correo_cliente IS NOT NULL 
    AND v.correo_cliente != ''
    AND DATE(v.fecha_venta) BETWEEN '$fecha_inicio' AND '$fecha_fin'
    GROUP BY v.correo_cliente
    ORDER BY total_gastado DESC
    LIMIT 5
");
while ($row = $resClientes->fetch_assoc()) {
    $clientes_top[] = $row;
}

// 6. Ventas por hora del día (para saber horarios más productivos)
$ventas_por_hora = array_fill(0, 24, 0);
$resHoras = $conn->query("
    SELECT 
        HOUR(v.fecha_venta) AS hora,
        SUM(v.cantidad_vendida * p.precio_venta) AS total
    FROM ventas v
    JOIN productos p ON v.id_producto = p.id
    WHERE v.id_vendedor = $id_vendedor
    AND DATE(v.fecha_venta) BETWEEN '$fecha_inicio' AND '$fecha_fin'
    GROUP BY HOUR(v.fecha_venta)
");
while ($row = $resHoras->fetch_assoc()) {
    $ventas_por_hora[(int)$row['hora']] = (float)$row['total'];
}

// 7. Resumen por día de la semana
$dias_semana = ['Lunes', 'Martes', 'Miércoles', 'Jueves', 'Viernes', 'Sábado', 'Domingo'];
$ventas_por_dia_semana = array_fill(0, 7, 0);
$resDiasSemana = $conn->query("
    SELECT 
        DAYOFWEEK(v.fecha_venta) AS dia_num,
        SUM(v.cantidad_vendida * p.precio_venta) AS total
    FROM ventas v
    JOIN productos p ON v.id_producto = p.id
    WHERE v.id_vendedor = $id_vendedor
    AND DATE(v.fecha_venta) BETWEEN '$fecha_inicio' AND '$fecha_fin'
    GROUP BY DAYOFWEEK(v.fecha_venta)
");
while ($row = $resDiasSemana->fetch_assoc()) {
    $pos = ($row['dia_num'] == 1) ? 6 : $row['dia_num'] - 2;
    $ventas_por_dia_semana[$pos] = (float)$row['total'];
}
?>

<style>
/* ================== ESTILOS DEL MÓDULO DE REPORTES ================== */
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

/* Tarjeta de filtros */
.filter-card {
    background: white;
    border-radius: 20px;
    padding: 20px;
    margin-bottom: 30px;
    box-shadow: 0 4px 15px rgba(0, 0, 0, 0.05);
    border: 1px solid #eef2f6;
}

.filter-group {
    display: flex;
    flex-wrap: wrap;
    gap: 15px;
    align-items: flex-end;
}

.filter-item {
    flex: 1;
    min-width: 150px;
}

.filter-item label {
    font-size: 0.75rem;
    font-weight: 600;
    text-transform: uppercase;
    color: #6c757d;
    margin-bottom: 5px;
    display: block;
}

.filter-item select, 
.filter-item input {
    width: 100%;
    padding: 10px 12px;
    border: 1px solid #e2e8f0;
    border-radius: 12px;
    font-size: 0.85rem;
    transition: all 0.2s;
}

.filter-item select:focus,
.filter-item input:focus {
    border-color: #f97316;
    outline: none;
    box-shadow: 0 0 0 3px rgba(249, 115, 22, 0.1);
}

.btn-filter {
    background: #f97316;
    border: none;
    padding: 10px 25px;
    border-radius: 12px;
    color: white;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.3s;
}

.btn-filter:hover {
    background: #ea580c;
    transform: translateY(-2px);
}

/* Tarjetas de métricas */
.metrics-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
    gap: 20px;
    margin-bottom: 30px;
}

.metric-card-report {
    background: white;
    border-radius: 20px;
    padding: 20px;
    box-shadow: 0 4px 15px rgba(0, 0, 0, 0.05);
    border: 1px solid #eef2f6;
    transition: all 0.3s;
    position: relative;
    overflow: hidden;
}

.metric-card-report:hover {
    transform: translateY(-3px);
    box-shadow: 0 8px 25px rgba(0, 0, 0, 0.1);
}

.metric-icon-report {
    position: absolute;
    right: 20px;
    top: 20px;
    font-size: 2rem;
    opacity: 0.15;
}

.metric-label-report {
    font-size: 0.7rem;
    text-transform: uppercase;
    font-weight: 600;
    color: #6c757d;
    letter-spacing: 0.5px;
}

.metric-value-report {
    font-size: 1.8rem;
    font-weight: 800;
    color: #1e293b;
    margin: 10px 0;
}

.metric-sub {
    font-size: 0.7rem;
    color: #6c757d;
}

/* Gráficos */
.chart-container {
    background: white;
    border-radius: 20px;
    padding: 20px;
    margin-bottom: 30px;
    box-shadow: 0 4px 15px rgba(0, 0, 0, 0.05);
    border: 1px solid #eef2f6;
}

.chart-title {
    font-size: 1rem;
    font-weight: 700;
    color: #1e293b;
    margin-bottom: 20px;
    display: flex;
    align-items: center;
    gap: 10px;
}

.chart-title i {
    color: #f97316;
    font-size: 1.2rem;
}

/* Tablas */
.table-report {
    background: white;
    border-radius: 20px;
    overflow: hidden;
    box-shadow: 0 4px 15px rgba(0, 0, 0, 0.05);
    border: 1px solid #eef2f6;
}

.table-report thead th {
    background: #f8fafc;
    padding: 15px;
    font-size: 0.75rem;
    font-weight: 700;
    text-transform: uppercase;
    color: #475569;
    border-bottom: 1px solid #eef2f6;
}

.table-report tbody td {
    padding: 12px 15px;
    font-size: 0.85rem;
    border-bottom: 1px solid #f1f5f9;
}

.table-report tbody tr:hover {
    background: #fef3e8;
}

/* Botón exportar */
.btn-export {
    background: #10b981;
    border: none;
    padding: 8px 20px;
    border-radius: 10px;
    color: white;
    font-weight: 500;
    font-size: 0.8rem;
    cursor: pointer;
    transition: all 0.3s;
}

.btn-export:hover {
    background: #059669;
    transform: translateY(-2px);
}

/* Rango de fechas personalizado */
.fecha-range {
    display: flex;
    gap: 10px;
    align-items: center;
}

.fecha-range .filter-item {
    flex: 1;
}

/* Responsive */
@media (max-width: 768px) {
    .metrics-grid {
        grid-template-columns: repeat(2, 1fr);
    }
    .metric-value-report {
        font-size: 1.3rem;
    }
}

@media (max-width: 576px) {
    .metrics-grid {
        grid-template-columns: 1fr;
    }
    .filter-group {
        flex-direction: column;
    }
    .filter-item {
        width: 100%;
    }
}

/* Modal de exportación */
.modal-export {
    position: fixed;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    background: rgba(0, 0, 0, 0.5);
    display: flex;
    align-items: center;
    justify-content: center;
    z-index: 1000;
    opacity: 0;
    visibility: hidden;
    transition: all 0.3s;
}

.modal-export.active {
    opacity: 1;
    visibility: visible;
}

.modal-export-content {
    background: white;
    border-radius: 24px;
    padding: 25px;
    max-width: 500px;
    width: 90%;
    max-height: 80vh;
    overflow-y: auto;
}

.modal-export-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 20px;
    padding-bottom: 15px;
    border-bottom: 1px solid #eef2f6;
}

.modal-export-title {
    font-size: 1.2rem;
    font-weight: 700;
    color: #1e293b;
}

.modal-export-close {
    cursor: pointer;
    font-size: 1.2rem;
    color: #94a3b8;
    transition: color 0.2s;
}

.modal-export-close:hover {
    color: #ef4444;
}

.export-option {
    display: flex;
    align-items: center;
    gap: 15px;
    padding: 15px;
    border: 1px solid #eef2f6;
    border-radius: 16px;
    margin-bottom: 10px;
    cursor: pointer;
    transition: all 0.2s;
}

.export-option:hover {
    background: #f8fafc;
    border-color: #f97316;
}

.export-option-icon {
    width: 45px;
    height: 45px;
    background: #fef3e8;
    border-radius: 12px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.3rem;
    color: #f97316;
}

.export-option-info h4 {
    font-size: 0.9rem;
    font-weight: 600;
    margin-bottom: 3px;
}

.export-option-info p {
    font-size: 0.7rem;
    color: #6c757d;
    margin: 0;
}
</style>

<div class="content-wrapper">
    <!-- Header -->
    <section class="content-header mb-4">
        <div class="container-fluid">
            <div class="row align-items-center">
                <div class="col-md-6">
                    <h1 class="page-title">
                        <i class="fas fa-chart-line mr-2"></i> Mis Reportes
                    </h1>
                    <p class="text-muted mt-2">
                        Análisis detallado de tu desempeño como vendedor
                    </p>
                </div>
                <div class="col-md-6 d-flex justify-content-md-end mt-3 mt-md-0">
                    <button class="btn-export" onclick="abrirModalExportacion()">
                        <i class="fas fa-download mr-2"></i> Exportar reporte
                    </button>
                </div>
            </div>
        </div>
    </section>

    <!-- Filtros -->
    <div class="filter-card">
        <form method="GET" action="" id="formFiltros">
            <div class="filter-group">
                <div class="filter-item">
                    <label><i class="fas fa-calendar-alt mr-1"></i> Período</label>
                    <select name="periodo" id="periodo" onchange="cambiarPeriodo()">
                        <option value="personalizado" <?= $periodo == 'personalizado' ? 'selected' : '' ?>>Personalizado</option>
                        <option value="hoy" <?= $periodo == 'hoy' ? 'selected' : '' ?>>Hoy</option>
                        <option value="semana" <?= $periodo == 'semana' ? 'selected' : '' ?>>Esta semana</option>
                        <option value="mes" <?= $periodo == 'mes' ? 'selected' : '' ?>>Este mes</option>
                        <option value="mes_anterior" <?= $periodo == 'mes_anterior' ? 'selected' : '' ?>>Mes anterior</option>
                        <option value="trimestre" <?= $periodo == 'trimestre' ? 'selected' : '' ?>>Este trimestre</option>
                    </select>
                </div>
                
                <div id="fechasPersonalizadas" style="display: <?= $periodo == 'personalizado' ? 'flex' : 'none' ?>; gap: 10px; flex: 2;">
                    <div class="filter-item">
                        <label><i class="fas fa-calendar-day"></i> Fecha inicio</label>
                        <input type="date" name="fecha_inicio" value="<?= $fecha_inicio ?>">
                    </div>
                    <div class="filter-item">
                        <label><i class="fas fa-calendar-day"></i> Fecha fin</label>
                        <input type="date" name="fecha_fin" value="<?= $fecha_fin ?>">
                    </div>
                </div>
                
                <div class="filter-item">
                    <label>&nbsp;</label>
                    <button type="submit" class="btn-filter w-100">
                        <i class="fas fa-sync-alt mr-2"></i> Aplicar filtros
                    </button>
                </div>
            </div>
        </form>
    </div>

    <!-- Métricas clave -->
    <div class="metrics-grid">
        <div class="metric-card-report">
            <div class="metric-icon-report"><i class="fas fa-chart-line"></i></div>
            <div class="metric-label-report">Total Ventas</div>
            <div class="metric-value-report">$<?= number_format($resumen['total_ingresos'], 2) ?></div>
            <div class="metric-sub"><?= $resumen['total_ventas'] ?> transacciones</div>
        </div>
        <div class="metric-card-report">
            <div class="metric-icon-report"><i class="fas fa-boxes"></i></div>
            <div class="metric-label-report">Unidades vendidas</div>
            <div class="metric-value-report"><?= number_format($resumen['total_unidades']) ?></div>
            <div class="metric-sub"><?= $resumen['total_unidades'] > 0 ? number_format($resumen['total_ingresos'] / $resumen['total_unidades'], 2) : 0 ?> promedio por unidad</div>
        </div>
        <div class="metric-card-report">
            <div class="metric-icon-report"><i class="fas fa-wallet"></i></div>
            <div class="metric-label-report">Utilidad estimada</div>
            <div class="metric-value-report">$<?= number_format($resumen['utilidad_estimada'], 2) ?></div>
            <div class="metric-sub"><?= $resumen['total_ingresos'] > 0 ? number_format(($resumen['utilidad_estimada'] / $resumen['total_ingresos']) * 100, 1) : 0 ?>% margen</div>
        </div>
        <div class="metric-card-report">
            <div class="metric-icon-report"><i class="fas fa-receipt"></i></div>
            <div class="metric-label-report">Ticket promedio</div>
            <div class="metric-value-report">$<?= number_format($resumen['ticket_promedio'], 2) ?></div>
            <div class="metric-sub">Por transacción</div>
        </div>
    </div>

    <!-- Gráficos principales -->
    <div class="row">
        <div class="col-lg-8">
            <div class="chart-container">
                <div class="chart-title">
                    <i class="fas fa-chart-line"></i>
                    <span>Ventas por día</span>
                </div>
                <canvas id="chartVentasDiarias" style="height: 300px;"></canvas>
                <?php if (empty($ventas_por_dia)): ?>
                    <div class="text-center text-muted py-5">No hay datos en el período seleccionado</div>
                <?php endif; ?>
            </div>
        </div>
        <div class="col-lg-4">
            <div class="chart-container">
                <div class="chart-title">
                    <i class="fas fa-chart-pie"></i>
                    <span>Métodos de pago</span>
                </div>
                <canvas id="chartMetodosPago" style="height: 300px;"></canvas>
                <?php if (empty($metodos_pago)): ?>
                    <div class="text-center text-muted py-5">No hay datos en el período seleccionado</div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="row">
        <div class="col-lg-6">
            <div class="chart-container">
                <div class="chart-title">
                    <i class="fas fa-chart-bar"></i>
                    <span>Ventas por hora del día</span>
                </div>
                <canvas id="chartVentasPorHora" style="height: 250px;"></canvas>
            </div>
        </div>
        <div class="col-lg-6">
            <div class="chart-container">
                <div class="chart-title">
                    <i class="fas fa-calendar-week"></i>
                    <span>Ventas por día de la semana</span>
                </div>
                <canvas id="chartVentasDiaSemana" style="height: 250px;"></canvas>
            </div>
        </div>
    </div>

    <!-- Top productos -->
    <div class="chart-container">
        <div class="chart-title">
            <i class="fas fa-trophy"></i>
            <span>Top 10 productos más vendidos</span>
        </div>
        <div class="table-responsive">
            <table class="table table-report">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Producto</th>
                        <th class="text-center">Unidades</th>
                        <th class="text-center">Veces vendido</th>
                        <th class="text-end">Ingresos</th>
                        <th class="text-end">% del total</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!empty($top_productos)): ?>
                        <?php foreach ($top_productos as $index => $p): 
                            $porcentaje = $resumen['total_ingresos'] > 0 ? ($p['ingresos'] / $resumen['total_ingresos']) * 100 : 0;
                        ?>
                            <tr>
                                <td><?= $index + 1 ?></td>
                                <td><strong><?= htmlspecialchars($p['nombre']) ?></strong></td>
                                <td class="text-center"><?= number_format($p['unidades']) ?></td>
                                <td class="text-center"><?= $p['veces_vendido'] ?> veces</td>
                                <td class="text-end text-success">$<?= number_format($p['ingresos'], 2) ?></td>
                                <td class="text-end"><?= number_format($porcentaje, 1) ?>%</td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr><td colspan="6" class="text-center py-4 text-muted">No hay datos disponibles</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Clientes frecuentes -->
    <div class="chart-container">
        <div class="chart-title">
            <i class="fas fa-users"></i>
            <span>Top 5 clientes por monto gastado</span>
        </div>
        <div class="table-responsive">
            <table class="table table-report">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Cliente</th>
                        <th class="text-center">Compras</th>
                        <th class="text-end">Total gastado</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!empty($clientes_top)): ?>
                        <?php foreach ($clientes_top as $index => $c): ?>
                            <tr>
                                <td><?= $index + 1 ?></td>
                                <td><?= htmlspecialchars($c['email']) ?></td>
                                <td class="text-center"><?= $c['compras'] ?> compras</td>
                                <td class="text-end text-success">$<?= number_format($c['total_gastado'], 2) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr><td colspan="4" class="text-center py-4 text-muted">No hay datos de clientes en este período</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Modal de exportación -->
<div id="modalExport" class="modal-export">
    <div class="modal-export-content">
        <div class="modal-export-header">
            <div class="modal-export-title">
                <i class="fas fa-download mr-2 text-primary"></i> Exportar reporte
            </div>
            <div class="modal-export-close" onclick="cerrarModalExportacion()">
                <i class="fas fa-times"></i>
            </div>
        </div>
        <div class="modal-export-body">
            <div class="export-option" onclick="exportarReporte('pdf')">
                <div class="export-option-icon"><i class="fas fa-file-pdf"></i></div>
                <div class="export-option-info">
                    <h4>Exportar a PDF</h4>
                    <p>Descarga un reporte completo en formato PDF</p>
                </div>
            </div>
            <div class="export-option" onclick="exportarReporte('excel')">
                <div class="export-option-icon"><i class="fas fa-file-excel"></i></div>
                <div class="export-option-info">
                    <h4>Exportar a Excel</h4>
                    <p>Descarga los datos en formato Excel (CSV)</p>
                </div>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

<script>
// Inicializar gráficos
document.addEventListener('DOMContentLoaded', function() {
    // Gráfico de ventas diarias
    const ventasDiarias = <?= json_encode($ventas_por_dia) ?>;
    if (ventasDiarias.length > 0) {
        const ctx = document.getElementById('chartVentasDiarias').getContext('2d');
        new Chart(ctx, {
            type: 'line',
            data: {
                labels: ventasDiarias.map(v => v.fecha),
                datasets: [{
                    label: 'Ventas ($)',
                    data: ventasDiarias.map(v => v.total),
                    borderColor: '#f97316',
                    backgroundColor: 'rgba(249, 115, 22, 0.1)',
                    fill: true,
                    tension: 0.4,
                    pointBackgroundColor: '#f97316',
                    pointBorderColor: '#fff',
                    pointRadius: 4,
                    pointHoverRadius: 6
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: true,
                plugins: {
                    tooltip: { callbacks: { label: (ctx) => '$' + ctx.raw.toLocaleString() } }
                },
                scales: { y: { beginAtZero: true, ticks: { callback: (v) => '$' + v.toLocaleString() } } }
            }
        });
    }

    // Gráfico de métodos de pago
    const metodosPago = <?= json_encode($metodos_pago) ?>;
    if (metodosPago.length > 0) {
        const ctx2 = document.getElementById('chartMetodosPago').getContext('2d');
        const colores = ['#f97316', '#3b82f6', '#10b981', '#8b5cf6', '#ef4444'];
        new Chart(ctx2, {
            type: 'pie',
            data: {
                labels: metodosPago.map(m => {
                    const nombres = { efectivo: 'Efectivo', transferencia: 'Transferencia', tarjeta_debito: 'Tarjeta Débito', tarjeta_credito: 'Tarjeta Crédito' };
                    return nombres[m.metodo_pago] || m.metodo_pago;
                }),
                datasets: [{
                    data: metodosPago.map(m => m.total),
                    backgroundColor: colores.slice(0, metodosPago.length),
                    borderWidth: 0
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: true,
                plugins: {
                    legend: { position: 'bottom' },
                    tooltip: { callbacks: { label: (ctx) => `$${ctx.raw.toLocaleString()} (${((ctx.raw / <?= $resumen['total_ingresos'] ?: 1 ?>) * 100).toFixed(1)}%)` } }
                }
            }
        });
    }

    // Gráfico de ventas por hora
    const ventasPorHora = <?= json_encode($ventas_por_hora) ?>;
    const horas = Array.from({length: 24}, (_, i) => i + ':00');
    const ctx3 = document.getElementById('chartVentasPorHora').getContext('2d');
    new Chart(ctx3, {
        type: 'bar',
        data: {
            labels: horas,
            datasets: [{
                label: 'Ventas ($)',
                data: ventasPorHora,
                backgroundColor: '#f97316',
                borderRadius: 6
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: true,
            scales: { y: { beginAtZero: true, ticks: { callback: (v) => '$' + v.toLocaleString() } } }
        }
    });

    // Gráfico de ventas por día de semana
    const ventasDiaSemana = <?= json_encode($ventas_por_dia_semana) ?>;
    const diasSemana = ['Lunes', 'Martes', 'Miércoles', 'Jueves', 'Viernes', 'Sábado', 'Domingo'];
    const ctx4 = document.getElementById('chartVentasDiaSemana').getContext('2d');
    new Chart(ctx4, {
        type: 'bar',
        data: {
            labels: diasSemana,
            datasets: [{
                label: 'Ventas ($)',
                data: ventasDiaSemana,
                backgroundColor: '#10b981',
                borderRadius: 6
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: true,
            scales: { y: { beginAtZero: true, ticks: { callback: (v) => '$' + v.toLocaleString() } } }
        }
    });
});

function cambiarPeriodo() {
    const periodo = document.getElementById('periodo').value;
    const fechasDiv = document.getElementById('fechasPersonalizadas');
    
    if (periodo === 'personalizado') {
        fechasDiv.style.display = 'flex';
    } else {
        fechasDiv.style.display = 'none';
        document.getElementById('formFiltros').submit();
    }
}

function abrirModalExportacion() {
    document.getElementById('modalExport').classList.add('active');
}

function cerrarModalExportacion() {
    document.getElementById('modalExport').classList.remove('active');
}

function exportarReporte(tipo) {
    cerrarModalExportacion();
    
    const params = new URLSearchParams(window.location.search);
    params.set('exportar', tipo);
    
    window.location.href = 'exportar_reporte.php?' + params.toString();
}

<?php if (isset($_GET['exportado']) && $_GET['exportado'] == 1): ?>
Swal.fire({
    icon: 'success',
    title: 'Reporte exportado',
    text: 'El reporte se ha descargado correctamente',
    confirmButtonColor: '#f97316'
});
<?php endif; ?>
</script>

<?php include 'includes/footer.php'; ?>