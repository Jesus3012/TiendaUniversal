<?php
date_default_timezone_set('America/Mexico_City');
ob_start(); // Iniciar buffer de salida

// Incluir sesión y DB primero
session_start();
include 'includes/db.php';

// Verificar sesión
if (!isset($_SESSION['usuario_id'])) {
    header("Location: login.php");
    exit;
}

// Verificar rol - solo vendedor y administrador
if ($_SESSION['rol'] !== 'vendedor' && $_SESSION['rol'] !== 'administrador') {
    header("Location: index.php");
    exit;
}

$id_vendedor = $_SESSION['usuario_id'];
$nombre_vendedor = $_SESSION['nombre'] ?? 'Vendedor';

// ================== FUNCIÓN PARA OBTENER DATOS ==================
function obtenerDatosReporte($conn, $id_vendedor, $fecha_inicio, $fecha_fin) {
    $resultados = [
        'resumen' => [
            'total_ventas' => 0,
            'total_unidades' => 0,
            'total_ingresos' => 0,
            'utilidad_estimada' => 0,
            'ticket_promedio' => 0
        ],
        'ventas_por_dia' => [],
        'top_productos' => [],
        'metodos_pago' => [],
        'clientes_top' => [],
        'ventas_por_hora' => array_fill(0, 24, 0),
        'ventas_por_dia_semana' => array_fill(0, 7, 0),
        'dias_semana' => ['Lunes', 'Martes', 'Miércoles', 'Jueves', 'Viernes', 'Sábado', 'Domingo']
    ];
    
    // 1. Resumen general
    $stmt = $conn->prepare("
        SELECT 
            COUNT(DISTINCT v.id) AS total_ventas,
            IFNULL(SUM(v.cantidad_vendida), 0) AS total_unidades,
            IFNULL(SUM(v.cantidad_vendida * p.precio_venta), 0) AS total_ingresos,
            IFNULL(SUM((p.precio_venta - p.precio_compra) * v.cantidad_vendida), 0) AS utilidad_estimada,
            IFNULL(AVG(v.cantidad_vendida * p.precio_venta), 0) AS ticket_promedio
        FROM ventas v
        JOIN productos p ON v.id_producto = p.id
        WHERE v.id_vendedor = ?
        AND DATE(v.fecha_venta) BETWEEN ? AND ?
    ");
    
    if ($stmt) {
        $stmt->bind_param("iss", $id_vendedor, $fecha_inicio, $fecha_fin);
        $stmt->execute();
        $res = $stmt->get_result();
        if ($row = $res->fetch_assoc()) {
            $resultados['resumen'] = $row;
        }
        $stmt->close();
    }
    
    // 2. Ventas por día
    $stmt = $conn->prepare("
        SELECT 
            DATE(v.fecha_venta) AS fecha,
            IFNULL(SUM(v.cantidad_vendida * p.precio_venta), 0) AS total,
            COUNT(DISTINCT v.id) AS num_ventas
        FROM ventas v
        JOIN productos p ON v.id_producto = p.id
        WHERE v.id_vendedor = ?
        AND DATE(v.fecha_venta) BETWEEN ? AND ?
        GROUP BY DATE(v.fecha_venta)
        ORDER BY fecha ASC
    ");
    
    if ($stmt) {
        $stmt->bind_param("iss", $id_vendedor, $fecha_inicio, $fecha_fin);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($row = $res->fetch_assoc()) {
            $resultados['ventas_por_dia'][] = $row;
        }
        $stmt->close();
    }
    
    // 3. Top productos
    $stmt = $conn->prepare("
        SELECT 
            p.nombre,
            SUM(v.cantidad_vendida) AS unidades,
            SUM(v.cantidad_vendida * p.precio_venta) AS ingresos,
            COUNT(DISTINCT v.id) AS veces_vendido
        FROM ventas v
        JOIN productos p ON v.id_producto = p.id
        WHERE v.id_vendedor = ?
        AND DATE(v.fecha_venta) BETWEEN ? AND ?
        GROUP BY p.id
        ORDER BY ingresos DESC
        LIMIT 10
    ");
    
    if ($stmt) {
        $stmt->bind_param("iss", $id_vendedor, $fecha_inicio, $fecha_fin);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($row = $res->fetch_assoc()) {
            $resultados['top_productos'][] = $row;
        }
        $stmt->close();
    }
    
    // 4. Métodos de pago
    $stmt = $conn->prepare("
        SELECT 
            metodo_pago,
            COUNT(*) AS cantidad,
            SUM(cantidad_vendida * p.precio_venta) AS total
        FROM ventas v
        JOIN productos p ON v.id_producto = p.id
        WHERE v.id_vendedor = ?
        AND DATE(v.fecha_venta) BETWEEN ? AND ?
        GROUP BY metodo_pago
    ");
    
    if ($stmt) {
        $stmt->bind_param("iss", $id_vendedor, $fecha_inicio, $fecha_fin);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($row = $res->fetch_assoc()) {
            $resultados['metodos_pago'][] = $row;
        }
        $stmt->close();
    }
    
    // 5. Clientes frecuentes
    $stmt = $conn->prepare("
        SELECT 
            v.correo_cliente AS email,
            COUNT(DISTINCT v.id) AS compras,
            SUM(v.cantidad_vendida * p.precio_venta) AS total_gastado
        FROM ventas v
        JOIN productos p ON v.id_producto = p.id
        WHERE v.id_vendedor = ?
        AND v.correo_cliente IS NOT NULL 
        AND v.correo_cliente != ''
        AND DATE(v.fecha_venta) BETWEEN ? AND ?
        GROUP BY v.correo_cliente
        ORDER BY total_gastado DESC
        LIMIT 5
    ");
    
    if ($stmt) {
        $stmt->bind_param("iss", $id_vendedor, $fecha_inicio, $fecha_fin);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($row = $res->fetch_assoc()) {
            $resultados['clientes_top'][] = $row;
        }
        $stmt->close();
    }
    
    // 6. Ventas por hora
    $stmt = $conn->prepare("
        SELECT 
            HOUR(v.fecha_venta) AS hora,
            SUM(v.cantidad_vendida * p.precio_venta) AS total
        FROM ventas v
        JOIN productos p ON v.id_producto = p.id
        WHERE v.id_vendedor = ?
        AND DATE(v.fecha_venta) BETWEEN ? AND ?
        GROUP BY HOUR(v.fecha_venta)
    ");
    
    if ($stmt) {
        $stmt->bind_param("iss", $id_vendedor, $fecha_inicio, $fecha_fin);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($row = $res->fetch_assoc()) {
            $resultados['ventas_por_hora'][(int)$row['hora']] = (float)$row['total'];
        }
        $stmt->close();
    }
    
    // 7. Ventas por día de semana
    $stmt = $conn->prepare("
        SELECT 
            DAYOFWEEK(v.fecha_venta) AS dia_num,
            SUM(v.cantidad_vendida * p.precio_venta) AS total
        FROM ventas v
        JOIN productos p ON v.id_producto = p.id
        WHERE v.id_vendedor = ?
        AND DATE(v.fecha_venta) BETWEEN ? AND ?
        GROUP BY DAYOFWEEK(v.fecha_venta)
    ");
    
    if ($stmt) {
        $stmt->bind_param("iss", $id_vendedor, $fecha_inicio, $fecha_fin);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($row = $res->fetch_assoc()) {
            $pos = ($row['dia_num'] == 1) ? 6 : $row['dia_num'] - 2;
            $resultados['ventas_por_dia_semana'][$pos] = (float)$row['total'];
        }
        $stmt->close();
    }
    
    return $resultados;
}

// ================== PROCESAR PETICIÓN AJAX ==================
// IMPORTANTE: Esto debe ir ANTES de cualquier salida HTML
if (isset($_GET['ajax']) && $_GET['ajax'] == 1) {
    // Limpiar buffer y cabeceras
    if (ob_get_level()) ob_clean();
    header('Content-Type: application/json');
    header('Cache-Control: no-cache, must-revalidate');
    
    try {
        $periodo_ajax = isset($_GET['periodo']) ? $_GET['periodo'] : 'personalizado';
        $fecha_inicio_ajax = isset($_GET['fecha_inicio']) ? $_GET['fecha_inicio'] : date('Y-m-01');
        $fecha_fin_ajax = isset($_GET['fecha_fin']) ? $_GET['fecha_fin'] : date('Y-m-d');
        
        // Ajustar fechas según período
        switch ($periodo_ajax) {
            case 'hoy':
                $fecha_inicio_ajax = date('Y-m-d');
                $fecha_fin_ajax = date('Y-m-d');
                break;
            case 'semana':
                $fecha_inicio_ajax = date('Y-m-d', strtotime('monday this week'));
                $fecha_fin_ajax = date('Y-m-d', strtotime('sunday this week'));
                break;
            case 'mes':
                $fecha_inicio_ajax = date('Y-m-01');
                $fecha_fin_ajax = date('Y-m-t');
                break;
            case 'mes_anterior':
                $fecha_inicio_ajax = date('Y-m-01', strtotime('-1 month'));
                $fecha_fin_ajax = date('Y-m-t', strtotime('-1 month'));
                break;
            case 'trimestre':
                $mes_actual = date('n');
                $trimestre = ceil($mes_actual / 3);
                $año = date('Y');
                $mes_inicio = ($trimestre - 1) * 3 + 1;
                $mes_fin = $trimestre * 3;
                $fecha_inicio_ajax = date("$año-$mes_inicio-01");
                $fecha_fin_ajax = date("$año-$mes_fin-") . date('t', strtotime("$año-$mes_fin-01"));
                break;
            default:
                break;
        }
        
        $datos = obtenerDatosReporte($conn, $id_vendedor, $fecha_inicio_ajax, $fecha_fin_ajax);
        echo json_encode($datos);
        
    } catch (Exception $e) {
        echo json_encode(['error' => $e->getMessage()]);
    }
    
    ob_end_flush();
    exit;
}

// ================== PROCESAR FILTROS PARA VISTA HTML ==================
$fecha_inicio = isset($_GET['fecha_inicio']) ? $_GET['fecha_inicio'] : date('Y-m-01');
$fecha_fin = isset($_GET['fecha_fin']) ? $_GET['fecha_fin'] : date('Y-m-d');
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
        break;
}

// Obtener datos para mostrar inicialmente
$datos = obtenerDatosReporte($conn, $id_vendedor, $fecha_inicio, $fecha_fin);
$resumen = $datos['resumen'];
$ventas_por_dia = $datos['ventas_por_dia'];
$top_productos = $datos['top_productos'];
$metodos_pago = $datos['metodos_pago'];
$clientes_top = $datos['clientes_top'];
$ventas_por_hora = $datos['ventas_por_hora'];
$ventas_por_dia_semana = $datos['ventas_por_dia_semana'];
$dias_semana = $datos['dias_semana'];

// Ahora incluimos header y navbar
include 'includes/header.php';
include 'includes/navbar.php';
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

.btn-clear {
    background: #6c757d;
    border: none;
    padding: 10px 25px;
    border-radius: 12px;
    color: white;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.3s;
}

.btn-clear:hover {
    background: #5a6268;
    transform: translateY(-2px);
}

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

.loading-overlay {
    position: fixed;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    background: rgba(0, 0, 0, 0.5);
    display: flex;
    align-items: center;
    justify-content: center;
    z-index: 9999;
    opacity: 0;
    visibility: hidden;
    transition: all 0.3s;
}

.loading-overlay.active {
    opacity: 1;
    visibility: visible;
}

.loading-spinner {
    background: white;
    padding: 20px 30px;
    border-radius: 16px;
    display: flex;
    align-items: center;
    gap: 15px;
    box-shadow: 0 10px 25px rgba(0,0,0,0.2);
}

.loading-spinner i {
    font-size: 1.8rem;
    color: #f97316;
}

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

.fecha-range {
    display: flex;
    gap: 10px;
    align-items: center;
}

.fecha-range .filter-item {
    flex: 1;
}

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

/* Asegurar que los canvas tengan el mismo tamaño */
.chart-container canvas {
    max-width: 100% !important;
    height: 300px !important;
    width: auto !important;
}

/* Para el gráfico de pastel, centrarlo */
#chartMetodosPago {
    margin: 0 auto;
    display: block;
}

/* ==========================================
   BREADCRUMB MODERNO
========================================== */

.breadcrumb-card{
    background:#fff;
    border:1px solid #e7edf5;
    border-radius:22px;

    min-height:64px;

    padding:0 24px;
    margin-bottom:22px;

    display:flex;
    align-items:center;
    gap:10px;

    box-shadow:0 4px 18px rgba(15,23,42,.05);

    color:#64748b;
    font-size:15px;
    font-weight:600;
}

.breadcrumb-card a{
    display:flex;
    align-items:center;
    gap:8px;
    text-decoration:none;
    color:#64748b;
    transition:.25s;
}

.breadcrumb-card a:hover{
    color:#f97316;
}

.breadcrumb-card strong{
    display:flex;
    align-items:center;
    gap:8px;
    color:#f97316;
    font-weight:800;
}

.breadcrumb-card span{
    color:#94a3b8;
}

/* MOVIL */

@media(max-width:768px){

    .breadcrumb-card{
        min-height:54px;
        padding:0 16px;
        border-radius:18px;
        font-size:13px;
        gap:6px;
        overflow-x:auto;
        white-space:nowrap;
    }

    .breadcrumb-card::-webkit-scrollbar{
        display:none;
    }
}

</style>

<!-- Loading Overlay -->
<div class="loading-overlay" id="loadingOverlay">
    <div class="loading-spinner">
        <i class="fas fa-spinner fa-spin"></i>
        <span>Cargando datos...</span>
    </div>
</div>

<div class="content-wrapper">

    <div class="breadcrumb-card">
        <a href="index.php">
            <i class="fas fa-home"></i>
            Inicio
        </a>

        <span>/</span>

        <a href="dashboard_reportes_ventas.php">
            <i class="fas fa-chart-pie"></i>
            Reportes
        </a>

        <span>/</span>

        <strong>
            <i class="fas fa-store"></i>
            Reporte general
        </strong>
    </div>
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

    <div class="filter-card">
        <div class="filter-group">
            <div class="filter-item">
                <label><i class="fas fa-calendar-alt mr-1"></i> Período</label>
                <select name="periodo" id="periodo">
                    <option value="personalizado" <?= $periodo == 'personalizado' ? 'selected' : '' ?>>Personalizado</option>
                    <option value="hoy" <?= $periodo == 'hoy' ? 'selected' : '' ?>>Hoy</option>
                    <option value="semana" <?= $periodo == 'semana' ? 'selected' : '' ?>>Esta semana</option>
                    <option value="mes" <?= $periodo == 'mes' ? 'selected' : '' ?>>Este mes</option>
                    <option value="mes_anterior" <?= $periodo == 'mes_anterior' ? 'selected' : '' ?>>Mes anterior</option>
                    <option value="trimestre" <?= $periodo == 'trimestre' ? 'selected' : '' ?>>Este trimestre</option>
                </select>
            </div>
            
            <div id="fechasPersonalizadas" class="fecha-range" style="display: <?= $periodo == 'personalizado' ? 'flex' : 'none' ?>; flex: 2;">
                <div class="filter-item">
                    <label><i class="fas fa-calendar-day"></i> Fecha inicio</label>
                    <input type="date" name="fecha_inicio" id="fecha_inicio" value="<?= $fecha_inicio ?>">
                </div>
                <div class="filter-item">
                    <label><i class="fas fa-calendar-day"></i> Fecha fin</label>
                    <input type="date" name="fecha_fin" id="fecha_fin" value="<?= $fecha_fin ?>">
                </div>
            </div>
            
            <div class="filter-item">
                <label>&nbsp;</label>
                <button type="button" class="btn-clear w-100" id="btnLimpiarFiltros">
                    <i class="fas fa-eraser mr-2"></i> Limpiar filtros
                </button>
            </div>
        </div>
    </div>

    <div class="metrics-grid">
        <div class="metric-card-report">
            <div class="metric-icon-report"><i class="fas fa-chart-line"></i></div>
            <div class="metric-label-report">Total Ventas</div>
            <div class="metric-value-report" id="totalIngresos">$<?= number_format($resumen['total_ingresos'], 2) ?></div>
            <div class="metric-sub" id="totalVentas"><?= $resumen['total_ventas'] ?> transacciones</div>
        </div>
        <div class="metric-card-report">
            <div class="metric-icon-report"><i class="fas fa-boxes"></i></div>
            <div class="metric-label-report">Unidades vendidas</div>
            <div class="metric-value-report" id="totalUnidades"><?= number_format($resumen['total_unidades']) ?></div>
            <div class="metric-sub" id="promedioUnidad"><?= $resumen['total_unidades'] > 0 ? number_format($resumen['total_ingresos'] / $resumen['total_unidades'], 2) : 0 ?> promedio por unidad</div>
        </div>
        <div class="metric-card-report">
            <div class="metric-icon-report"><i class="fas fa-wallet"></i></div>
            <div class="metric-label-report">Utilidad estimada</div>
            <div class="metric-value-report" id="utilidadEstimada">$<?= number_format($resumen['utilidad_estimada'], 2) ?></div>
            <div class="metric-sub" id="margenUtilidad"><?= $resumen['total_ingresos'] > 0 ? number_format(($resumen['utilidad_estimada'] / $resumen['total_ingresos']) * 100, 1) : 0 ?>% margen</div>
        </div>
        <div class="metric-card-report">
            <div class="metric-icon-report"><i class="fas fa-receipt"></i></div>
            <div class="metric-label-report">Ticket promedio</div>
            <div class="metric-value-report" id="ticketPromedio">$<?= number_format($resumen['ticket_promedio'], 2) ?></div>
            <div class="metric-sub">Por transacción</div>
        </div>
    </div>

    <div class="row">
        <div class="col-lg-6">
            <div class="chart-container">
                <div class="chart-title">
                    <i class="fas fa-chart-line"></i>
                    <span>Ventas por día</span>
                </div>
                <canvas id="chartVentasDiarias" style="height: 300px; width: 100%;"></canvas>
                <div id="noDataVentasDiarias" class="text-center text-muted py-5" style="display: none;">No hay datos en el período seleccionado</div>
            </div>
        </div>
        <div class="col-lg-6">
            <div class="chart-container">
                <div class="chart-title">
                    <i class="fas fa-chart-pie"></i>
                    <span>Métodos de pago</span>
                </div>
                <canvas id="chartMetodosPago" style="height: 300px; width: 100%; max-width: 100%;"></canvas>
                <div id="noDataMetodosPago" class="text-center text-muted py-5" style="display: none;">No hay datos en el período seleccionado</div>
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
                <tbody id="topProductosBody">
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
                <tbody id="clientesBody">
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
                        <tr>
                            <td colspan="6" class="text-center py-4 text-muted">No hay datos disponibles</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

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
let chartVentasDiarias = null;
let chartMetodosPago = null;
let chartVentasPorHora = null;
let chartVentasDiaSemana = null;

function initCharts(datos) {
    const ctx1 = document.getElementById('chartVentasDiarias').getContext('2d');
    if (chartVentasDiarias) chartVentasDiarias.destroy();
    
    if (datos.ventas_por_dia && datos.ventas_por_dia.length > 0) {
        document.getElementById('chartVentasDiarias').style.display = 'block';
        document.getElementById('noDataVentasDiarias').style.display = 'none';
        chartVentasDiarias = new Chart(ctx1, {
            type: 'line',
            data: {
                labels: datos.ventas_por_dia.map(v => v.fecha),
                datasets: [{
                    label: 'Ventas ($)',
                    data: datos.ventas_por_dia.map(v => v.total),
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
                plugins: { tooltip: { callbacks: { label: (ctx) => '$' + ctx.raw.toLocaleString() } } },
                scales: { y: { beginAtZero: true, ticks: { callback: (v) => '$' + v.toLocaleString() } } }
            }
        });
    } else {
        document.getElementById('chartVentasDiarias').style.display = 'none';
        document.getElementById('noDataVentasDiarias').style.display = 'block';
    }

    const ctx2 = document.getElementById('chartMetodosPago').getContext('2d');
    if (chartMetodosPago) chartMetodosPago.destroy();
    
    if (datos.metodos_pago && datos.metodos_pago.length > 0) {
        document.getElementById('chartMetodosPago').style.display = 'block';
        document.getElementById('noDataMetodosPago').style.display = 'none';
        const colores = ['#f97316', '#3b82f6', '#10b981', '#8b5cf6', '#ef4444'];
        const nombres = { efectivo: 'Efectivo', transferencia: 'Transferencia', tarjeta_debito: 'Tarjeta Débito', tarjeta_credito: 'Tarjeta Crédito' };
        chartMetodosPago = new Chart(ctx2, {
            type: 'pie',
            data: {
                labels: datos.metodos_pago.map(m => nombres[m.metodo_pago] || m.metodo_pago),
                datasets: [{
                    data: datos.metodos_pago.map(m => m.total),
                    backgroundColor: colores.slice(0, datos.metodos_pago.length),
                    borderWidth: 0
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: true,
                plugins: {
                    legend: { position: 'bottom' },
                    tooltip: { callbacks: { label: (ctx) => `$${ctx.raw.toLocaleString()} (${((ctx.raw / datos.resumen.total_ingresos) * 100).toFixed(1)}%)` } }
                }
            }
        });
    } else {
        document.getElementById('chartMetodosPago').style.display = 'none';
        document.getElementById('noDataMetodosPago').style.display = 'block';
    }

    const ctx3 = document.getElementById('chartVentasPorHora').getContext('2d');
    if (chartVentasPorHora) chartVentasPorHora.destroy();
    const horas = Array.from({length: 24}, (_, i) => i + ':00');
    chartVentasPorHora = new Chart(ctx3, {
        type: 'bar',
        data: {
            labels: horas,
            datasets: [{
                label: 'Ventas ($)',
                data: datos.ventas_por_hora,
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

    const ctx4 = document.getElementById('chartVentasDiaSemana').getContext('2d');
    if (chartVentasDiaSemana) chartVentasDiaSemana.destroy();
    chartVentasDiaSemana = new Chart(ctx4, {
        type: 'bar',
        data: {
            labels: datos.dias_semana,
            datasets: [{
                label: 'Ventas ($)',
                data: datos.ventas_por_dia_semana,
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
}

function actualizarReporte() {
    const periodo = document.getElementById('periodo').value;
    let fechaInicio = document.getElementById('fecha_inicio')?.value || '';
    let fechaFin = document.getElementById('fecha_fin')?.value || '';
    
    let url = window.location.pathname + '?ajax=1&periodo=' + encodeURIComponent(periodo);
    
    if (periodo === 'personalizado') {
        if (fechaInicio) url += '&fecha_inicio=' + encodeURIComponent(fechaInicio);
        if (fechaFin) url += '&fecha_fin=' + encodeURIComponent(fechaFin);
    }
    
    url += '&_=' + Date.now();
    
    document.getElementById('loadingOverlay').classList.add('active');
    
    fetch(url, {
        method: 'GET',
        credentials: 'same-origin',
        headers: {
            'X-Requested-With': 'XMLHttpRequest',
            'Accept': 'application/json'
        }
    })
    .then(response => {
        if (!response.ok) {
            throw new Error('Error HTTP: ' + response.status);
        }
        return response.json();
    })
    .then(data => {
        if (data.error) {
            throw new Error(data.error);
        }
        
        // Actualizar métricas
        document.getElementById('totalIngresos').innerHTML = '$' + parseFloat(data.resumen.total_ingresos || 0).toLocaleString('es-MX', {minimumFractionDigits: 2});
        document.getElementById('totalVentas').innerHTML = (data.resumen.total_ventas || 0) + ' transacciones';
        document.getElementById('totalUnidades').innerHTML = parseInt(data.resumen.total_unidades || 0).toLocaleString();
        const promedioUnidad = (data.resumen.total_unidades || 0) > 0 ? ((data.resumen.total_ingresos || 0) / (data.resumen.total_unidades || 1)).toFixed(2) : 0;
        document.getElementById('promedioUnidad').innerHTML = '$' + parseFloat(promedioUnidad).toLocaleString() + ' promedio por unidad';
        document.getElementById('utilidadEstimada').innerHTML = '$' + parseFloat(data.resumen.utilidad_estimada || 0).toLocaleString('es-MX', {minimumFractionDigits: 2});
        const margen = (data.resumen.total_ingresos || 0) > 0 ? (((data.resumen.utilidad_estimada || 0) / (data.resumen.total_ingresos || 1)) * 100).toFixed(1) : 0;
        document.getElementById('margenUtilidad').innerHTML = margen + '% margen';
        document.getElementById('ticketPromedio').innerHTML = '$' + parseFloat(data.resumen.ticket_promedio || 0).toLocaleString('es-MX', {minimumFractionDigits: 2});
        
        // Actualizar tabla de top productos
        const topBody = document.getElementById('topProductosBody');
        if (data.top_productos && data.top_productos.length > 0) {
            topBody.innerHTML = '';
            data.top_productos.forEach((p, index) => {
                const porcentaje = (data.resumen.total_ingresos || 0) > 0 ? ((p.ingresos || 0) / (data.resumen.total_ingresos || 1)) * 100 : 0;
                topBody.innerHTML += `
                    <tr>
                        <td class="fw-bold">${index + 1}</td>
                        <td><strong>${escapeHtml(p.nombre)}</strong></td>
                        <td class="text-center">${parseInt(p.unidades || 0).toLocaleString()}</td>
                        <td class="text-center">${p.veces_vendido || 0} veces</td>
                        <td class="text-end text-success">$${parseFloat(p.ingresos || 0).toLocaleString('es-MX', {minimumFractionDigits: 2})}</td>
                        <td class="text-end">${porcentaje.toFixed(1)}%</td>
                    </tr>
                `;
            });
        } else {
            topBody.innerHTML = '<tr><td colspan="6" class="text-center py-4 text-muted">No hay datos disponibles</td></tr>';
        }
        
        // Actualizar tabla de clientes
        const clientesBody = document.getElementById('clientesBody');
        if (data.clientes_top && data.clientes_top.length > 0) {
            clientesBody.innerHTML = '';
            data.clientes_top.forEach((c, index) => {
                clientesBody.innerHTML += `
                    <tr>
                        <td class="fw-bold">${index + 1}</td>
                        <td>${escapeHtml(c.email)}</td>
                        <td class="text-center">${c.compras || 0} compras</td>
                        <td class="text-end text-success">$${parseFloat(c.total_gastado || 0).toLocaleString('es-MX', {minimumFractionDigits: 2})}</td>
                    </tr>
                `;
            });
        } else {
            clientesBody.innerHTML = '<tr><td colspan="4" class="text-center py-4 text-muted">No hay datos de clientes en este período</td></tr>';
        }
        
        initCharts(data);
        document.getElementById('loadingOverlay').classList.remove('active');
    })
    .catch(error => {
        console.error('Error:', error);
        document.getElementById('loadingOverlay').classList.remove('active');
        Swal.fire({ 
            icon: 'error', 
            title: 'Error', 
            text: error.message || 'No se pudieron cargar los datos',
            confirmButtonColor: '#f97316' 
        });
    });
}

function limpiarFiltros() {
    document.getElementById('periodo').value = 'personalizado';
    document.getElementById('fechasPersonalizadas').style.display = 'flex';
    
    const hoy = new Date();
    const primerDiaMes = new Date(hoy.getFullYear(), hoy.getMonth(), 1);
    const ultimoDiaMes = new Date(hoy.getFullYear(), hoy.getMonth() + 1, 0);
    
    document.getElementById('fecha_inicio').value = primerDiaMes.toISOString().split('T')[0];
    document.getElementById('fecha_fin').value = ultimoDiaMes.toISOString().split('T')[0];
    
    actualizarReporte();
}

document.addEventListener('DOMContentLoaded', function() {
    const datosIniciales = <?= json_encode($datos) ?>;
    initCharts(datosIniciales);
    
    const periodoSelect = document.getElementById('periodo');
    if (periodoSelect) {
        periodoSelect.addEventListener('change', function() {
            const periodo = this.value;
            const fechasDiv = document.getElementById('fechasPersonalizadas');
            
            if (periodo === 'personalizado') {
                fechasDiv.style.display = 'flex';
                if (document.getElementById('fecha_inicio').value && document.getElementById('fecha_fin').value) {
                    actualizarReporte();
                }
            } else {
                fechasDiv.style.display = 'none';
                actualizarReporte();
            }
        });
    }
    
    const fechaInicio = document.getElementById('fecha_inicio');
    const fechaFin = document.getElementById('fecha_fin');
    
    if (fechaInicio) {
        fechaInicio.addEventListener('change', function() {
            if (document.getElementById('periodo').value === 'personalizado') {
                actualizarReporte();
            }
        });
    }
    
    if (fechaFin) {
        fechaFin.addEventListener('change', function() {
            if (document.getElementById('periodo').value === 'personalizado') {
                actualizarReporte();
            }
        });
    }
    
    const btnLimpiar = document.getElementById('btnLimpiarFiltros');
    if (btnLimpiar) {
        btnLimpiar.addEventListener('click', limpiarFiltros);
    }
});

function abrirModalExportacion() {
    document.getElementById('modalExport').classList.add('active');
}

function cerrarModalExportacion() {
    document.getElementById('modalExport').classList.remove('active');
}

function exportarReporte(tipo) {
    cerrarModalExportacion();
    
    const periodo = document.getElementById('periodo').value;
    let fechaInicio = document.getElementById('fecha_inicio')?.value || '';
    let fechaFin = document.getElementById('fecha_fin')?.value || '';
    
    let url = 'exportar_reporte.php?exportar=' + tipo + '&periodo=' + encodeURIComponent(periodo);
    
    if (periodo === 'personalizado') {
        if (fechaInicio) url += '&fecha_inicio=' + encodeURIComponent(fechaInicio);
        if (fechaFin) url += '&fecha_fin=' + encodeURIComponent(fechaFin);
    }
    
    window.location.href = url;
}

function escapeHtml(text) {
    if (!text) return '';
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}
</script>

<?php include 'includes/footer.php'; ?>