<?php
require_once 'includes/session.php';

// AHORA sí, incluir archivos que puedan generar output
require_once 'includes/db.php';
require_once 'includes/header.php';
require_once 'includes/navbar.php';

// Verificar autenticación (ahora session.php ya redirige si expiró)
if (!isset($_SESSION['usuario_id']) || strtolower($_SESSION['rol'] ?? '') !== 'administrador') {
    header("Location: login.php");
    exit;
}

// Zona horaria y fechas
date_default_timezone_set('America/Mexico_City');
$hoy = date('Y-m-d');
$inicioSemana = date('Y-m-d', strtotime('monday this week'));
$finSemana    = date('Y-m-d', strtotime('sunday this week'));

// Ventas por día (Lun..Dom) — protecciones y validaciones
$ventasPorDia = array_fill(0, 7, 0); // 0=Lun ... 6=Dom

$resVentasDias = $conn->query("
    SELECT DAYOFWEEK(fecha_venta) AS dia, 
           SUM(v.cantidad_vendida * p.precio_venta) AS total
    FROM ventas v
    JOIN productos p ON v.id_producto = p.id
    WHERE DATE(fecha_venta) BETWEEN '$inicioSemana' AND '$finSemana'
    GROUP BY dia
");

if ($resVentasDias) {
    while ($row = $resVentasDias->fetch_assoc()) {
        if (!isset($row['dia'])) continue;
        $dia = intval($row['dia']); // 1=Dom, 2=Lun, ... 7=Sab

        if ($dia < 1 || $dia > 7) continue;
        $posicion = ($dia == 1) ? 6 : $dia - 2; // 0..6 (Lun..Dom)
        $ventasPorDia[$posicion] = floatval($row['total']);
    }
}

// Ventas totales (día, semana, mes)
$resVentasDia = $conn->query("
    SELECT IFNULL(SUM(v.cantidad_vendida*p.precio_venta),0) AS total_dia
    FROM ventas v
    JOIN productos p ON v.id_producto = p.id
    WHERE DATE(v.fecha_venta)=CURDATE()
");
$totalVentasDia = ($resVentasDia && $resVentasDia->num_rows) ? floatval($resVentasDia->fetch_assoc()['total_dia']) : 0.0;

$resVentasSemana = $conn->query("
    SELECT IFNULL(SUM(v.cantidad_vendida*p.precio_venta),0) AS total_semana
    FROM ventas v
    JOIN productos p ON v.id_producto = p.id
    WHERE YEARWEEK(v.fecha_venta,1) = YEARWEEK(CURDATE(),1)
");
$totalVentasSemana = ($resVentasSemana && $resVentasSemana->num_rows) ? floatval($resVentasSemana->fetch_assoc()['total_semana']) : 0.0;

$resVentasMes = $conn->query("
    SELECT IFNULL(SUM(v.cantidad_vendida*p.precio_venta),0) AS total_mes
    FROM ventas v
    JOIN productos p ON v.id_producto = p.id
    WHERE MONTH(v.fecha_venta) = MONTH(CURDATE()) AND YEAR(v.fecha_venta) = YEAR(CURDATE())
");
$totalVentasMes = ($resVentasMes && $resVentasMes->num_rows) ? floatval($resVentasMes->fetch_assoc()['total_mes']) : 0.0;

// Productos: stock suficiente / bajo (una sola consulta)
$productosStockBajo = [];
$productosStockSuficiente = [];
$resProductos = $conn->query("SELECT id, nombre, cantidad FROM productos");

if ($resProductos) {
    while ($p = $resProductos->fetch_assoc()) {
        $p['cantidad'] = intval($p['cantidad']);
        if ($p['cantidad'] < 5) $productosStockBajo[] = $p;
        else $productosStockSuficiente[] = $p;
    }
}

// Usuarios
$resUsuarios = $conn->query("SELECT COUNT(*) AS total_usuarios FROM usuarios");
$totalUsuarios = $resUsuarios ? $resUsuarios->fetch_assoc()['total_usuarios'] : 0;

// Nombres de usuarios
$resUsuariosNombres = $conn->query("SELECT nombre FROM usuarios");
$listaUsuarios = [];
if($resUsuariosNombres){
    while($row = $resUsuariosNombres->fetch_assoc()) {
        $listaUsuarios[] = $row['nombre'];
    }
}
$tooltipUsuarios = implode(", ", $listaUsuarios);

// ---------------------------------------------------
// Productos con stock bajo (para tooltip y lista)
// ---------------------------------------------------
$stockBajo = $productosStockBajo; // ya lo tenemos
$tooltipStock = "No hay productos con stock bajo.";
if (count($stockBajo) > 5) {
    $tooltipStock = "Productos con poco stock:\n";
    foreach ($stockBajo as $p) {
        $tooltipStock .= "- " . $p['nombre'] . " (" . intval($p['cantidad']) . ")\n";
    }
}

// ---------------------------------------------------
// Productos sin movimiento (7 días)
// ---------------------------------------------------
$sinMovimiento = [];
$resSinMovimiento = $conn->query("
    SELECT nombre 
    FROM productos 
    WHERE id NOT IN (
        SELECT DISTINCT id_producto 
        FROM ventas 
        WHERE fecha_venta >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)
    )
");
if ($resSinMovimiento) {
    while ($p = $resSinMovimiento->fetch_assoc()) {
        $sinMovimiento[] = $p['nombre'];
    }
}
$productosSinMovimiento = $sinMovimiento; // para compatibilidad con la vista

// ---------------------------------------------------
// Últimas ventas
// ---------------------------------------------------
$ultimasVentas = [];
$resUltimasVentas = $conn->query("
    SELECT v.id, p.nombre AS producto, v.cantidad_vendida, v.fecha_venta
    FROM ventas v
    JOIN productos p ON v.id_producto = p.id
    ORDER BY v.fecha_venta DESC
    LIMIT 5
");
if ($resUltimasVentas) {
    while ($row = $resUltimasVentas->fetch_assoc()) {
        $ultimasVentas[] = $row;
    }
}

// ---------------------------------------------------
// Top productos
// ---------------------------------------------------
$topProductos = [];
$resTopProductos = $conn->query("
    SELECT p.nombre, SUM(v.cantidad_vendida) AS total_vendido, IFNULL(SUM(v.cantidad_vendida*p.precio_venta),0) AS total_ingreso
    FROM ventas v
    JOIN productos p ON v.id_producto = p.id
    GROUP BY p.id
    ORDER BY total_vendido DESC
    LIMIT 5
");
if ($resTopProductos) {
    while ($row = $resTopProductos->fetch_assoc()) {
        $topProductos[] = $row;
    }
}

// ---------------------------------------------------
// KPI extra: ingresos, tickets e utilidad (hoy)
// ---------------------------------------------------
$resTicketsHoy = $conn->query("
    SELECT COUNT(*) AS tickets, 
           IFNULL(SUM(v.cantidad_vendida*p.precio_venta),0) AS ingresos
    FROM ventas v
    JOIN productos p ON v.id_producto = p.id
    WHERE DATE(v.fecha_venta)=CURDATE()
");

$ingresosHoy = 0;
$ticketsHoy = 0;
if ($resTicketsHoy && $resTicketsHoy->num_rows) {
    $kpi = $resTicketsHoy->fetch_assoc();
    $ingresosHoy = floatval($kpi['ingresos']);
    $ticketsHoy = intval($kpi['tickets']);
}

// Utilidad estimada
$sql = "SELECT 
            SUM((p.precio_venta - p.precio_compra) * v.cantidad_vendida) AS utilidadHoy
        FROM ventas v
        INNER JOIN productos p ON p.id = v.id_producto
        WHERE DATE(v.fecha_venta) = CURDATE()";

$result = $conn->query($sql); // Ejecutar consulta
$row = $result->fetch_assoc();    // Obtener resultado

$utilidadHoy = $row['utilidadHoy'] ?? 0; // Si es null, poner 0

// ---------------------------------------------------
// Variación semanal (compare con la semana anterior correctamente)
// ---------------------------------------------------
$resSemanaAnterior = $conn->query("
    SELECT IFNULL(SUM(v.cantidad_vendida*p.precio_venta),0) AS total
    FROM ventas v 
    JOIN productos p ON v.id_producto=p.id
    WHERE YEARWEEK(v.fecha_venta,1) = YEARWEEK(DATE_SUB(CURDATE(), INTERVAL 1 WEEK),1)
");
$ventasSemanaAnterior = ($resSemanaAnterior && $resSemanaAnterior->num_rows) ? floatval($resSemanaAnterior->fetch_assoc()['total']) : 0.0;


// ---------------------------------------------------
// Variación mensual (mes anterior usando DATE_SUB para evitar enero->0)
// ---------------------------------------------------
$resMesAnterior = $conn->query("
    SELECT IFNULL(SUM(v.cantidad_vendida*p.precio_venta),0) AS total
    FROM ventas v 
    JOIN productos p ON v.id_producto=p.id
    WHERE MONTH(v.fecha_venta) = MONTH(DATE_SUB(CURDATE(), INTERVAL 1 MONTH))
      AND YEAR(v.fecha_venta) = YEAR(DATE_SUB(CURDATE(), INTERVAL 1 MONTH))
");
$ventasMesAnterior = ($resMesAnterior && $resMesAnterior->num_rows) ? floatval($resMesAnterior->fetch_assoc()['total']) : 0.0;

// Cálculo de variación mensual con protecciones
    if ($ventasMesAnterior > 0) {
    $variacionMes = (($totalVentasMes - $ventasMesAnterior) / $ventasMesAnterior) * 100;
} else {
    if ($totalVentasMes > 0) {
        $variacionMes = 100.0; // inicio de actividad
    } else {
        $variacionMes = null; // sin historial real
    }
}


// ---------------------------------------------------
// Ticket promedio y utilidad semanal (estimaciones seguras)
// ---------------------------------------------------
// tickets en la semana (conteo de filas ventas; si usas otra entidad para tickets, ajusta)
// Tickets de la semana
$resTicketsSemana = $conn->query("
    SELECT COUNT(*) AS tickets_semana
    FROM ventas v
    WHERE YEARWEEK(v.fecha_venta,1) = YEARWEEK(CURDATE(),1)
");

$ticketsSemana = ($resTicketsSemana && $resTicketsSemana->num_rows) 
    ? intval($resTicketsSemana->fetch_assoc()['tickets_semana']) 
    : 0;

// Ticket promedio
$ticketPromedio = $ticketsSemana > 0 
    ? ($totalVentasSemana / $ticketsSemana) 
    : 0.0;

// Utilidad de la semana (REAL)
$resUtilidadSemana = $conn->query("
    SELECT SUM((p.precio_venta - p.precio_compra) * v.cantidad_vendida) AS utilidad_semana
    FROM ventas v
    INNER JOIN productos p ON p.id = v.id_producto
    WHERE YEARWEEK(v.fecha_venta,1) = YEARWEEK(CURDATE(),1)
");

$rowUtilidadSemana = $resUtilidadSemana->fetch_assoc();
$utilidadSemana = $rowUtilidadSemana['utilidad_semana'] ?? 0;

$resUsuariosActivos = $conn->query("
    SELECT COUNT(*) AS total 
    FROM usuarios 
    WHERE activo = 1
");
$usuariosActivos = $resUsuariosActivos->fetch_assoc()['total'];

// Tickets por día de la semana
$ticketsPorDia = array_fill(0, 7, 0);

$resTicketsSemana = $conn->query("
    SELECT DAYOFWEEK(fecha_venta) AS dia, COUNT(*) AS total
    FROM ventas
    WHERE DATE(fecha_venta) BETWEEN '$inicioSemana' AND '$finSemana'
    GROUP BY dia
");

if ($resTicketsSemana) {
    while ($row = $resTicketsSemana->fetch_assoc()) {
        $dia = intval($row['dia']); // 1=domingo, 7=sabado
        $pos = ($dia == 1) ? 6 : $dia - 2;
        $ticketsPorDia[$pos] = intval($row['total']);
    }
}

// ---------- INTELIGENCIA ----------

$minVentasSemana = 10;     // o monto mínimo de ventas semanal
// $minIngresoSemana = 1000;    

$estadoColor = 'success';
$estadoTexto = 'Operación saludable';
$estadoIcon = 'fa-rocket';
$mensajeCentral = 'El negocio mantiene un ritmo sólido.';
$riesgo = false;

if ($totalVentasSemana <= 0) {
    $estadoColor = 'danger';
    $estadoTexto = 'Operación detenida';
    $estadoIcon = 'fa-skull-crossbones';
    $mensajeCentral = 'No se registraron ventas esta semana.';
    $riesgo = true;
}

elseif ($totalVentasSemana > 0 && $totalVentasSemana < $minVentasSemana) {
    $estadoColor = 'warning';
    $estadoTexto = 'Operación débil';
    $estadoIcon = 'fa-battery-quarter';
    $mensajeCentral = 'Las ventas existen, pero son demasiado bajas.';
    $riesgo = true;
}

elseif ($variacionMes < -10) {
    $estadoColor = 'danger';
    $estadoTexto = 'Caída crítica';
    $estadoIcon = 'fa-arrow-down';
    $mensajeCentral = 'Las ventas muestran una caída pronunciada.';
    $riesgo = true;
}
elseif ($variacionMes < 0) {
    $estadoColor = 'warning';
    $estadoTexto = 'Desaceleración';
    $estadoIcon = 'fa-exclamation-circle';
    $mensajeCentral = 'El crecimiento perdió fuerza este mes.';
}

$accion = 'Monitorear comportamiento del negocio.';

/* ===================== NIVEL CRÍTICO ===================== */
if ($totalVentasSemana <= 0) {
    $accion = 'Revisar precios, canales de venta, visibilidad y posibles fallas operativas URGENTE.';
}

/* ===================== OPERACIÓN DÉBIL ===================== */
elseif ($totalVentasSemana > 0 && $totalVentasSemana < $minVentasSemana) {
    $accion = 'Ventas demasiado bajas. Revisar precios y mejorar visibilidad del negocio.';
}

/* ===================== CAÍDA FUERTE ===================== */
elseif ($variacionMes < -10) {
    if (count($productosSinMovimiento) > 0) {
        $accion = 'Revisar causas de la caída, depurar productos muertos y ajustar estrategia.';
    } 
}

/* ===================== DESACELERACIÓN ===================== */
elseif ($variacionMes < 0) {
    if (count($productosStockBajo) > 0) {
        $accion = 'Reabastecer productos de alta rotación.';
    }
}

/* ===================== CRECIMIENTO MODERADO ===================== */
elseif ($variacionMes <= 10) {
    $accion = 'Optimizar procesos y monitorear crecimiento.';
}

/* ===================== CRECIMIENTO FUERTE ===================== */
else {
    if (count($productosStockBajo) > 0) {
        $accion = 'Aumentar inventario y asegurar disponibilidad.';
    } else {
        $accion = 'Escalar ventas y reforzar canales que están funcionando.';
    }
}


?>
<style>
/* ============================
   CONTENEDOR GENERAL
============================ */
.content-wrapper {
    min-height: 100vh;
    padding: 20px;
    overflow-x: auto; /* scroll horizontal si algo se desborda */
    background: #f8f9fa;
}

/* ============================
   GRID DE CARDS
============================ */
.dashboard-cards {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(230px, 1fr));
    gap: 20px;
    margin-bottom: 25px;
}

.metric-card, .small-box {
    border-radius: 14px !important;
    padding: 20px;
    color: white;
    transition: 0.25s ease-in-out;
    box-shadow: 0 4px 10px rgba(0,0,0,0.12);
}

.small-box:hover, 
.metric-card:hover {
    transform: translateY(-4px);
    box-shadow: 0 8px 25px rgba(0,0,0,0.15);
}

/* ============================
   TARJETAS (Cards normales)
============================ */
.card {
    border-radius: 14px !important;
    box-shadow: 0 4px 12px rgba(0,0,0,0.08);
    border: none !important;
}

.card-header {
    background: #ffffff !important;
    border-bottom: none !important;
    padding: 18px 22px;
}

.card-title {
    font-size: 18px;
    font-weight: 600;
    color: #333;
}

/* ============================
   PANEL DE PRODUCTOS / TABLAS
============================ */
.panel-productos,
.table-container {
    background: white;
    padding: 20px;
    border-radius: 14px;
    box-shadow: 0 4px 12px rgba(0,0,0,0.08);
    margin-bottom: 25px;
}

.panel-productos h3,
.table-container h3 {
    border-bottom: 2px solid #ff7b00;
    padding-bottom: 8px;
    margin-bottom: 15px;
    font-size: 20px;
    color: #333;
}

/* ============================
   TABLAS
============================ */
table {
    width: 100%;
    border-collapse: collapse;
    font-size: 15px;
}

table th {
    background: #ff7b00;
    color: white;
    padding: 12px;
    text-align: left;
    font-weight: 600;
}

table td {
    padding: 10px;
    border-bottom: 1px solid #eee;
}

table tr:hover td {
    background: #fff4e6;
    transition: 0.15s;
}

/* ============================
   ALERTAS
============================ */
.alert {
    border-radius: 12px !important;
    font-size: 15px;
    padding: 15px 20px;
    box-shadow: 0 4px 12px rgba(255, 165, 0, 0.15);
}

.alert-warning {
    background: #fff8e5 !important;
    color: #a05a00 !important;
    border-left: 5px solid #ffb84d;
}
    .chart-box-lg {
        position: relative;
        height: 350px;
        width: 100%;
    }

    canvas {
        display: block !important;
        max-height: 100% !important;
    }

    /* Estilo general de los ítems */
.quick-config .config-item {
    padding: 6px 12px;
    background: #f4f6f9;
    border-radius: 12px;
    border: 1px solid #dcdfe3;
    margin: 4px;
    font-size: 14px;
    display: flex;
    align-items: center;
    cursor: pointer;
    transition: 0.25s;
    white-space: nowrap;
}

/* Checkbox separado */
.quick-config .config-item input {
    margin-right: 6px;
}

/* Hover suave */
.quick-config .config-item:hover {
    background: #e8ebee;
}

/* Título con mejor apariencia */
.config-title {
    margin-right: 12px;
    white-space: nowrap;
}

/*  Estilo especial SOLO para pantallas pequeñas */
@media (max-width: 768px) {
    .quick-config {
        gap: 6px;
    }

    .quick-config .config-item {
        font-size: 13px;
        padding: 8px 14px;
        width: calc(50% - 12px); /* 2 columnas bonitas */
        text-align: left;
    }

    /* El título ocupa toda la línea y se separa */
    .config-title {
        width: 100%;
        margin-bottom: 6px;
        font-size: 15px;
        color: #3c8dbc;
    }
}

</style>
<!-- Content Wrapper -->
<div class="content-wrapper">
    <section>
        <div class="container-fluid">
            <!-- ALERTAS -->
            <?php if(count($stockBajo) > 0): ?>
            <div class="alert alert-warning alert-dismissible fade show shadow-sm" role="alert">
                <i class="fas fa-exclamation-triangle mr-2"></i>
                <strong>Atención:</strong> Hay productos con stock bajo.
                <button type="button" class="close" data-dismiss="alert">
                    <span>&times;</span>
                </button>
            </div>
            <?php endif; ?>

            <?php if(count($productosSinMovimiento) > 0): ?>
            <div class="alert alert-info shadow-sm">
                <i class="fas fa-info-circle"></i>
                Productos sin ventas en 7 días:
                <strong><?= htmlspecialchars(implode(", ", $productosSinMovimiento)) ?></strong>
                <button type="button" class="close" data-dismiss="alert">
                    <span>&times;</span>
                </button>
            </div>
            <?php endif; ?>
            <!-- GRID DE CARDS -->
            <div class="row">
                <!-- Ventas Hoy -->
                <div class="col-lg-4 col-md-4 col-sm-6">
                    <div class="small-box bg-primary shadow" data-toggle="modal" data-target="#modalVentasHoy" style="cursor:pointer">
                        <div class="inner">
                            <h3>$<?= number_format($totalVentasDia,2) ?></h3>
                            <p>Ventas Hoy</p>
                            <span class="badge badge-light"> Tickets: <?= intval($ticketsHoy) ?></span>
                        </div>
                        <div class="icon"><i class="fas fa-calendar-day"></i></div>
                    </div>
                </div>
                <!-- Ventas Semana -->
                <div class="col-lg-4 col-md-4 col-sm-6">
                    <div class="small-box bg-info shadow" data-toggle="modal" data-target="#modalVentasSemana" style="cursor:pointer">
                        <div class="inner">
                            <h3>$<?= number_format($totalVentasSemana,2) ?></h3>
                            <p>Ventas Semana</p>
                        </div>
                        <div class="icon"><i class="fas fa-calendar-week"></i></div>
                    </div>
                </div>
                <!-- Ventas Mes -->
                <div class="col-lg-4 col-md-4 col-sm-6">
                    <div class="small-box bg-secondary shadow" data-toggle="modal" data-target="#modalVentasMes" style="cursor:pointer">
                        <div class="inner">
                            <h3>$<?= number_format($totalVentasMes,2) ?></h3>
                            <p>Ventas Mes</p>
                        </div>
                        <div class="icon"><i class="fas fa-calendar-alt"></i></div>
                    </div>
                </div>
                <!-- Usuarios Registrados -->
                <div class="row justify-content-center w-100">
                    <div class="col-lg-4 col-md-4 col-sm-6">
                        <div class="small-box bg-success shadow" data-toggle="modal" data-target="#modalUsuarios" style="cursor:pointer">
                            <div class="inner">
                                <h3><?= intval($totalUsuarios) ?></h3>
                                <p>Usuarios Registrados</p>
                            </div>
                            <div class="icon"><i class="fas fa-users"></i></div>
                        </div>
                    </div>
                    <!-- Utilidad Estimada -->
                    <div class="col-lg-4 col-md-4 col-sm-6">
                        <div class="small-box bg-warning shadow">
                            <div class="inner text-white">
                                <h3>$<?= number_format($utilidadHoy,2) ?></h3>
                                <p>Utilidad de hoy estimada</p>
                            </div>
                            <div class="icon"><i class="fas fa-wallet"></i></div>
                        </div>
                    </div>

                    <!-- Stock: dos cards separadas -->
                    <?php
                    $hayStockBajo = count($productosStockBajo) > 0;
                    $color = $hayStockBajo ? 'bg-danger' : 'bg-success';
                    $titulo = $hayStockBajo ? 'Productos con Stock Bajo' : 'Stock Suficiente';
                    $contador = $hayStockBajo ? count($productosStockBajo) : count($productosStockSuficiente);
                    $modalTarget = $hayStockBajo ? '#modalStockBajo' : '#modalStockBueno';
                    ?>
                    <div class="col-lg-4 col-md-4 col-sm-12">
                        <div class="small-box <?= $color ?> shadow" style="cursor:pointer" data-toggle="modal" data-target="<?= htmlspecialchars($modalTarget) ?>">
                            <div class="inner">
                                <h3><?= intval($contador) ?></h3>
                                <p><?= htmlspecialchars($titulo) ?></p>
                            </div>
                            <div class="icon"><i class="fas fa-boxes"></i></div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- CONFIGURACIÓN RÁPIDA -->
            <div class="card mb-3">
                <div class="card-body quick-config d-flex flex-wrap flex-lg-nowrap align-items-center">

                    <div class="config-title"><strong>Configuracion:</strong></div>

                    <label class="config-item">
                        <input type="checkbox" class="chk-widget" data-id="grafica"> Gráfica Semanal
                    </label>

                    <label class="config-item">
                        <input type="checkbox" class="chk-widget" data-id="donut"> Gráfica Circular
                    </label>

                    <label class="config-item">
                        <input type="checkbox" class="chk-widget" data-id="indicadores"> Indicadores
                    </label>
                    
                    <label class="config-item">
                        <input type="checkbox" class="chk-widget" data-id="venta/ticket"> Ventas / Tickets
                    </label>

                    <label class="config-item">
                        <input type="checkbox" class="chk-widget" data-id="producto/resumen"> Productos / Resumen
                    </label>
                </div>
            </div>

            <!-- EXPORT CSV -->
            <button id="exportCSV" class="btn btn-primary">
                <i class="fas fa-file-csv"></i> Exportar CSV
            </button>

            <!-- FILA 1: Gráfica semanal + Donut -->
            <div class="row mt-3">

                <!-- GRÁFICA SEMANAL -->
                <div class="col-lg-8 col-md-12 mb-3" data-widget="grafica">
                    <div class="card h-100">
                        <div class="card-header d-flex justify-content-between align-items-center">
                            <h3 class="card-title"><i class="fas fa-chart-area mr-2 text-primary"></i> Ventas - Últimos 7 días</h3>
                            <small class="text-muted">Total semana: $<?= number_format($totalVentasSemana,2) ?></small>
                        </div>
                        <div class="card-body">
                            <div class="chart-box">
                                <canvas id="chartVentasSemana"></canvas>
                            </div>
                        </div>
                        <div class="card-footer bg-white d-flex justify-content-between">
                            <div>
                                <small class="text-muted">Ticket promedio:</small><br>
                                <strong>$<?= number_format($ticketPromedio,2) ?></strong>
                            </div>
                            <div>
                                <small class="text-muted">Utilidad (semana):</small><br>
                                <strong>$<?= number_format($utilidadSemana,2) ?></strong>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- DONUT -->
                <div class="col-lg-4 col-md-12 mb-3" data-widget="donut">
                    <div class="card h-100">
                        <div class="card-header">
                            <h3 class="card-title"><i class="fas fa-chart-pie mr-2 text-danger"></i> Documentos</h3>
                        </div>
                        <div class="card-body">
                            <div class="chart-box-sm">
                                <canvas id="donutDocumentos"></canvas>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- FILA 2: KPI Horizontales -->
            <div class="row">

                <div class="col-lg-12 mb-3" data-widget="indicadores">
                    <div class="card">
                        <div class="card-header">
                            <h3 class="card-title"><i class="fas fa-chart-bar mr-2 text-primary"></i> Indicadores</h3>
                        </div>
                        <div class="card-body">
                            <div class="chart-box-lg">
                                <canvas id="kpiHorizontales"></canvas>
                            </div>
                        </div>
                    </div>
                </div>

            </div>

            <!-- FILA 3: Mix -->
            <div class="row">

                <div class="col-lg-12 mb-3" data-widget="venta/ticket">
                    <div class="card">
                        <div class="card-header">
                            <h3 class="card-title"><i class="fas fa-chart-line mr-2 text-info"></i> Ventas + Tickets</h3>
                        </div>
                        <div class="card-body">
                            <div class="chart-box">
                                <canvas id="chartMix"></canvas>
                            </div>
                        </div>
                    </div>
                </div>

            </div>

            <!-- FILA 4: Top Productos + Resumen -->
            <div class="row">

                <!-- TOP PRODUCTOS -->
                <div class="col-lg-5 col-md-12 mb-3" data-widget="producto/top">
                    <div class="card h-100">
                        <div class="card-header">
                            <h3 class="card-title"><i class="fas fa-fire mr-2 text-danger"></i> Top Productos</h3>
                        </div>
                        <div class="card-body p-0">
                            <?php if(count($topProductos) > 0): ?>
                            <div class="table-responsive">
                                <table class="table table-sm mb-0">
                                    <thead class="thead-light">
                                        <tr><th>Producto</th><th class="text-right">Vendidos</th><th class="text-right">Ingreso</th></tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach($topProductos as $p): ?>
                                        <tr>
                                            <td><?= htmlspecialchars($p['nombre']) ?></td>
                                            <td class="text-right"><?= intval($p['total_vendido']) ?></td>
                                            <td class="text-right">$<?= number_format(floatval($p['total_ingreso']),2) ?></td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                            <?php else: ?>
                            <div class="p-3 text-muted">No hay datos de top productos.</div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- RESUMEN -->
                <div class="card shadow-lg border-0 h-100 col-lg-7 col-md-12 mb-3" data-widget="producto/resumen">

                    <div class="card-header bg-<?= $estadoColor ?> text-white">
                        <h3 class="card-title">
                            <i class="fas <?= $estadoIcon ?> mr-2"></i>
                            Centro de Comando
                        </h3>
                    </div>

                    <div class="card-body">

                        <!-- ESTADO GENERAL -->
                        <div class="text-center mb-4">
                            <h2 class="font-weight-bold text-<?= $estadoColor ?>">
                                <?= $estadoTexto ?>
                            </h2>
                            <p class="text-muted mb-0">
                                <?= $mensajeCentral ?>
                            </p>
                        </div>

                        <!-- MÉTRICAS INTERPRETADAS -->
                        <div class="row text-center">

                            <div class="col-6 col-lg-3 mb-3">
                                <div class="small text-muted">Ritmo diario</div>
                                <div class="h5">
                                    $<?= number_format($totalVentasDia,2) ?>
                                </div>
                            </div>

                            <div class="col-6 col-lg-3 mb-3">
                                <div class="small text-muted">Proyección mensual</div>
                                <div class="h5">
                                    $<?= number_format($totalVentasMes * 1.1,2) ?>
                                </div>
                            </div>

                            <div class="col-6 col-lg-3 mb-3">
                                <div class="small text-muted">Eficiencia</div>
                                <div class="h5">
                                    <?= $ticketsHoy > 0 ? round($ingresosHoy / $ticketsHoy,2) : 0 ?> / ticket
                                </div>
                            </div>

                            <div class="col-6 col-lg-3 mb-3">
                                <div class="small text-muted">Utilidad hoy</div>
                                <div class="h5 text-success">
                                    $<?= number_format($utilidadHoy,2) ?>
                                </div>
                            </div>

                        </div>

                        <hr>

                        <!-- ALERTAS INTELIGENTES -->
                        <div class="mb-3">
                            <h6 class="text-muted">Alertas prioritarias</h6>

                            <?php if ($riesgo): ?>
                                <div class="alert alert-danger">
                                    <i class="fas fa-bell mr-2"></i>
                                    Riesgo operativo detectado
                                </div>
                            <?php endif; ?>

                            <?php if (count($productosStockBajo) > 0): ?>
                                <div class="alert alert-warning">
                                    <i class="fas fa-box mr-2"></i>
                                    <?= count($productosStockBajo) ?> productos en stock crítico
                                </div>
                            <?php endif; ?>

                            <?php if (!$riesgo && count($productosStockBajo) === 0): ?>
                                <div class="alert alert-success">
                                    <i class="fas fa-check-circle mr-2"></i>
                                    Operación estable sin riesgos inmediatos
                                </div>
                            <?php endif; ?>
                        </div>

                        <!-- ACCIÓN EJECUTIVA -->
                        <div class="p-3 bg-light rounded border-left border-<?= $estadoColor ?>">
                            <small class="text-muted">Decisión recomendada</small>
                            <div class="font-weight-bold mt-1">
                                <?= $accion ?>
                            </div>
                        </div>

                    </div>

                </div>
            </div>
        </div>
    </section>
</div>
<!-- Modal Ventas Día -->
<div class="modal fade" id="modalVentasHoy" tabindex="-1">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">

      <!-- ENCABEZADO -->
      <div class="modal-header bg-primary text-white">
        <h5 class="modal-title">
          <i class="fas fa-calendar-day mr-2"></i> Ventas del Día
        </h5>
        <button type="button" class="close text-white" data-dismiss="modal">&times;</button>
      </div>

      <!-- CUERPO -->
      <div class="modal-body" style="max-height: 450px; overflow-y: auto;">

        <div class="table-responsive">
          <table class="table table-hover table-borderless">

            <thead class="thead-light">
              <tr>
                <th>Producto</th>
                <th class="text-center">Cantidad</th>
                <th class="text-right">Total</th>
              </tr>
            </thead>

            <tbody>
            <?php 
            $res = $conn->query("
                SELECT p.nombre, v.cantidad_vendida, (v.cantidad_vendida*p.precio_venta) AS total
                FROM ventas v
                JOIN productos p ON v.id_producto = p.id
                WHERE DATE(v.fecha_venta)='$hoy'
            ");
            while($v = $res->fetch_assoc()): ?>
                <tr>
                    <td><?= htmlspecialchars($v['nombre']) ?></td>
                    <td class="text-center"><?= $v['cantidad_vendida'] ?></td>
                    <td class="text-right">$<?= number_format($v['total'],2) ?></td>
                </tr>
            <?php endwhile; ?>
            </tbody>

          </table>
        </div>
      </div>
    </div>
  </div>
</div>

<!-- Modal Usuarios -->
<div class="modal fade" id="modalUsuarios" tabindex="-1">
  <div class="modal-dialog modal-md">
    <div class="modal-content">

      <div class="modal-header">
        <h5 class="modal-title"><i class="fas fa-users"></i> Usuarios Registrados</h5>
        <button type="button" class="close" data-dismiss="modal">&times;</button>
      </div>

      <div class="modal-body">
        <ul class="user-list">
            <?php foreach($listaUsuarios as $u): ?>
                <li><i class="fas fa-user-circle mr-2"></i> <?= htmlspecialchars($u) ?></li>
            <?php endforeach; ?>
        </ul>
      </div>

    </div>
  </div>
</div>

<!-- Modal Ventas Semana -->
<div class="modal fade" id="modalVentasSemana" tabindex="-1">
  <div class="modal-dialog modal-xl">
    <div class="modal-content">

      <!-- HEADER -->
      <div class="modal-header bg-info text-white">
        <h5 class="modal-title">
          <i class="fas fa-calendar-week mr-2"></i>
          Ventas de la Semana (<?= $inicioSemana ?> – <?= $finSemana ?>)
        </h5>
        <button type="button" class="close text-white" data-dismiss="modal">&times;</button>
      </div>

      <!-- BODY -->
      <div class="modal-body" style="max-height: 500px; overflow-y: auto;">

        <?php
        $res = $conn->query("
            SELECT p.nombre, SUM(v.cantidad_vendida) AS cantidad, 
                   SUM(v.cantidad_vendida*p.precio_venta) AS total
            FROM ventas v
            JOIN productos p ON v.id_producto = p.id
            WHERE DATE(v.fecha_venta) BETWEEN '$inicioSemana' AND '$finSemana'
            GROUP BY p.id
            ORDER BY total DESC
        ");

        if($res && $res->num_rows > 0): ?>

        <div class="table-responsive">
          <table class="table table-hover table-borderless">
            <thead class="thead-light">
              <tr>
                <th>Producto</th>
                <th class="text-center">Cantidad</th>
                <th class="text-right">Total</th>
              </tr>
            </thead>

            <tbody>
              <?php while($r = $res->fetch_assoc()): ?>
              <tr>
                <td><?= htmlspecialchars($r['nombre']) ?></td>
                <td class="text-center"><?= $r['cantidad'] ?></td>
                <td class="text-right">$<?= number_format($r['total'],2) ?></td>
              </tr>
              <?php endwhile; ?>
            </tbody>
          </table>
        </div>

        <?php else: ?>
          <p class="text-muted text-center my-3">
            <i class="fas fa-info-circle mr-2"></i>
            No hay ventas registradas en este rango semanal.
          </p>
        <?php endif; ?>

      </div>
    </div>
  </div>
</div>

<!-- Modal Ventas Mes -->
<div class="modal fade" id="modalVentasMes" tabindex="-1">
  <div class="modal-dialog modal-xl">
    <div class="modal-content">

      <!-- HEADER -->
      <div class="modal-header bg-primary text-white">
        <h5 class="modal-title">
          <i class="fas fa-calendar-alt mr-2"></i>
          Ventas del Mes (<?= date('F Y') ?>)
        </h5>
        <button type="button" class="close text-white" data-dismiss="modal">&times;</button>
      </div>

      <!-- BODY -->
      <div class="modal-body" style="max-height: 500px; overflow-y: auto;">

        <?php
        $res = $conn->query("
            SELECT p.nombre, SUM(v.cantidad_vendida) AS cantidad, 
                   SUM(v.cantidad_vendida*p.precio_venta) AS total
            FROM ventas v
            JOIN productos p ON v.id_producto = p.id
            WHERE MONTH(v.fecha_venta) = MONTH(CURDATE()) 
              AND YEAR(v.fecha_venta)=YEAR(CURDATE())
            GROUP BY p.id
            ORDER BY total DESC
        ");

        if($res && $res->num_rows > 0): ?>

        <div class="table-responsive">
          <table class="table table-hover table-borderless">
            <thead class="thead-light">
              <tr>
                <th>Producto</th>
                <th class="text-center">Cantidad</th>
                <th class="text-right">Total</th>
              </tr>
            </thead>

            <tbody>
              <?php while($r = $res->fetch_assoc()): ?>
              <tr>
                <td><?= htmlspecialchars($r['nombre']) ?></td>
                <td class="text-center"><?= $r['cantidad'] ?></td>
                <td class="text-right">$<?= number_format($r['total'],2) ?></td>
              </tr>
              <?php endwhile; ?>
            </tbody>
          </table>
        </div>

        <?php else: ?>
          <p class="text-muted text-center my-3">
            <i class="fas fa-info-circle mr-2"></i>
            No hay ventas registradas este mes.
          </p>
        <?php endif; ?>

      </div>
    </div>
  </div>
</div>

<!-- Modal Stock Bajo -->
<div class="modal fade" id="modalStockBajo" tabindex="-1">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <div class="modal-header bg-danger text-white">
        <h5 class="modal-title">Productos con Stock Bajo</h5>
        <button type="button" class="close text-white" data-dismiss="modal">&times;</button>
      </div>
      <div class="modal-body">
        <?php if(count($productosStockBajo) == 0): ?>
            <p class="text-center text-muted">No hay productos con stock bajo.</p>
        <?php else: ?>
            <ul>
                <?php foreach($productosStockBajo as $p): ?>
                    <li class="text-danger">
                        <?= htmlspecialchars($p['nombre']) ?> (<?= $p['cantidad'] ?> unidades)
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>

<!-- Modal Stock Suficiente -->
<div class="modal fade" id="modalStockBueno" tabindex="-1">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <div class="modal-header bg-success text-white">
        <h5 class="modal-title">Productos con Stock Suficiente</h5>
        <button type="button" class="close text-white" data-dismiss="modal">&times;</button>
      </div>
      <div class="modal-body">
        <?php if(count($productosStockSuficiente) == 0): ?>
            <p class="text-center text-muted">No hay productos con stock suficiente.</p>
        <?php else: ?>
            <ul>
                <?php foreach($productosStockSuficiente as $p): ?>
                    <li class="text-success">
                        <?= htmlspecialchars($p['nombre']) ?> (<?= $p['cantidad'] ?> unidades)
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>

<!-- SCRIPTS -->
<script src="https://code.jquery.com/jquery-3.5.1.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/popper.js@1.16.1/dist/umd/popper.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@4.6.2/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

<script>
// Guardas para widgets (persistencia)
document.querySelectorAll(".chk-widget").forEach(chk => {
    const id = chk.dataset.id;
    // default: visible
    const stored = localStorage.getItem("widget_" + id);
    chk.checked = stored !== "0";
    // apply initial state
    document.querySelectorAll("[data-widget='" + id + "']").forEach(el => el.style.display = chk.checked ? "" : "none");

    chk.addEventListener("change", () => {
        localStorage.setItem("widget_" + id, chk.checked ? "1" : "0");
        document.querySelectorAll("[data-widget='" + id + "']").forEach(el => el.style.display = chk.checked ? "" : "none");
    });
});

// Export CSV
document.getElementById("exportCSV").addEventListener("click", () => {
    window.location = "export_ultimas_ventas.php";
});

// Tooltips Bootstrap
$(function () {
    $('[data-toggle="tooltip"]').tooltip();
});

// Chart: Ventas - últimos 7 días
const ventasSemana = <?= json_encode(array_map('floatval', $ventasPorDia)) ?>;
const ctx = document.getElementById('chartVentasSemana').getContext('2d');

new Chart(ctx, {
    type: 'line',
    data: {
        labels: ["Lun","Mar","Mié","Jue","Vie","Sáb","Dom"],
        datasets: [{
            label: "Ventas",
            data: ventasSemana,
            borderWidth: 2,
            fill: false,
            tension: 0.2,
            pointRadius: 3
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
            legend: { display: false },
            tooltip: { mode: 'index', intersect: false }
        },
        scales: {
            y: {
                beginAtZero: true,
                ticks: { callback: function(value){ return '$' + value; } }
            }
        }
    }
});

// Chart: KPI Horizontales
const kpi = document.getElementById("kpiHorizontales");

new Chart(kpi, {
    type: "bar",
    data: {
        labels: ["Ingresos Hoy", "Tickets", "Utilidad"],
        datasets: [{
            label: "Valores",
            data: [
                <?= $totalVentasDia ?>,
                <?= $ticketsHoy ?>,
                <?= $utilidadHoy ?>
            ],
            backgroundColor: ["#3B82F6", "#8B5CF6", "#F59E0B"]
        }]
    },
    options: {
        indexAxis: 'y',
        scales: { x: { beginAtZero: true } },
        plugins: { legend: { display: false } }
    }
});

</script>

<?php 
if (!isset($diasSemana)) {
    $diasSemana = ["Lunes","Martes","Miércoles","Jueves","Viernes","Sábado","Domingo"];
}
?>
<script>
document.addEventListener("DOMContentLoaded", function () {

    const canvas = document.getElementById("chartMix");

    if (!canvas) {
        console.error("No se encontró el canvas chartMix.");
        return;
    }

    const ctx = canvas.getContext("2d");

    const labels = <?= json_encode($diasSemana, JSON_UNESCAPED_UNICODE) ?>;
    const ventas = <?= json_encode($ventasPorDia ?? [], JSON_NUMERIC_CHECK) ?>;
    const tickets = <?= json_encode($ticketsPorDia ?? [], JSON_NUMERIC_CHECK) ?>;

    new Chart(ctx, {
        data: {
            labels,
            datasets: [
                {
                    type: "line",
                    label: "Ventas",
                    data: ventas,
                    borderColor: "#3B82F6",
                    borderWidth: 3,
                    tension: 0.35,
                    pointRadius: 4,
                    pointBackgroundColor: "#3B82F6",
                    yAxisID: 'yVentas'
                },
                {
                    type: "bar",
                    label: "Tickets",
                    data: tickets,
                    backgroundColor: "rgba(167, 139, 250, 0.7)",
                    borderRadius: 5,
                    yAxisID: 'yTickets'
                }
            ]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            interaction: { mode: 'index', intersect: false },
            plugins: {
                legend: {
                    display: true,
                    position: "bottom"
                }
            },
            scales: {
                yVentas: {
                    beginAtZero: true,
                    type: "linear",
                    position: "left"
                },
                yTickets: {
                    beginAtZero: true,
                    type: "linear",
                    position: "right",
                    grid: {
                        drawOnChartArea: false
                    }
                }
            }
        }
    });

});
</script>

<script>
const centerTextPlugin = {
    id: 'centerText',
    afterDraw(chart) {
        const { ctx, chartArea } = chart;
        const data = chart.options.plugins.centerText;
        if (!data) return;

        const x = chartArea.left + chartArea.width / 2;
        const y = chartArea.top + chartArea.height / 2;

        ctx.save();
        ctx.textAlign = 'center';
        ctx.textBaseline = 'middle';

        ctx.font = 'bold 18px Arial';
        ctx.fillStyle = data.color;
        ctx.fillText(data.title, x, y - 10);

        ctx.font = '16px Arial';
        ctx.fillText(data.value, x, y + 14);

        ctx.restore();
    }
};
</script>

<script>
document.addEventListener("DOMContentLoaded", function () {

    const ctx = document.getElementById("donutDocumentos");
    if (!ctx) return;

    const valores = [
        <?= $totalVentasDia ?>,
        <?= $totalVentasSemana ?>,
        <?= $totalVentasMes ?>
    ];

    const labels = [
        "Ventas del Día",
        "Ventas de Semana",
        "Ventas del Mes"
    ];

    const colores = ["#38BDF8", "#A78BFA", "#eba459ff"];

    const donut = new Chart(ctx, {
        type: "doughnut",
        plugins: [centerTextPlugin],
        data: {
            labels: labels,
            datasets: [{
                data: valores,
                backgroundColor: colores,
                hoverOffset: 8
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            cutout: "72%",
            plugins: {
                legend: {
                    position: "bottom",
                    labels: {
                        boxWidth: 12
                    }
                },
                centerText: {
                    title: "Ventas del Mes",
                    value: "$" + valores[2].toLocaleString(),
                    color: colores[2]
                }
            },
            onClick: (evt, elements) => {
                if (!elements.length) return;

                const i = elements[0].index;

                donut.options.plugins.centerText = {
                    title: labels[i],
                    value: "$" + valores[i].toLocaleString(),
                    color: colores[i]
                };

                donut.update();
            }
        }
    });

// ⏰ 30 MINUTOS DE INACTIVIDAD
const TIEMPO_EXPIRACION = 30 * 60 * 1000; // 30 minutos en milisegundos
const TIEMPO_ADVERTENCIA = 29 * 60 * 1000; // 29 minutos (2 minutos antes)
const TIEMPO_ALERTA = 1 * 60 * 1000; // 1 minutos para la alerta (60000 ms)

let tiempoInactivo = 0;
let advertenciaMostrada = false;

function reiniciarContador() {
    tiempoInactivo = 0;
    advertenciaMostrada = false;
    console.log(' Contador reiniciado por actividad');
}

// Verificar inactividad cada segundo
setInterval(() => {
    tiempoInactivo += 1000;
    
    // Mostrar advertencia a los 28 minutos (faltan 2 minutos)
    if (tiempoInactivo >= TIEMPO_ADVERTENCIA && !advertenciaMostrada) {
        advertenciaMostrada = true;
        console.log(' Mostrando advertencia de expiración');
        
        Swal.fire({
            icon: 'warning',
            title: ' Sesión por expirar',
            text: 'Tu sesión expirará en 2 minutos por inactividad',
            showCancelButton: true,
            confirmButtonText: 'Seguir aquí',
            cancelButtonText: 'Salir ahora',
            confirmButtonColor: '#28a745',
            cancelButtonColor: '#d33',
            timer: TIEMPO_ALERTA, // 2 minutos de temporizador
            timerProgressBar: true,
            allowOutsideClick: false
        }).then((result) => {
            if (result.isConfirmed) {
                // Mantener sesión activa
                fetch('mantener_sesion.php')
                    .then(() => {
                        reiniciarContador();
                        Swal.fire({
                            icon: 'success',
                            title: '¡Sesión renovada!',
                            text: 'Puedes continuar trabajando',
                            timer: 1500,
                            showConfirmButton: false
                        });
                    })
                    .catch(() => {
                        // Si hay error en la petición, asumir que la sesión ya expiró
                        window.location.href = 'login.php?expired=1';
                    });
            } else if (result.dismiss === Swal.DismissReason.timer) {
                // Si se acaba el tiempo de la alerta, cerrar sesión
                window.location.href = 'logout.php';
            } else {
                // Si canceló, cerrar sesión
                window.location.href = 'logout.php';
            }
        });
    }
    
    // EXPIRAR a los 30 minutos
    if (tiempoInactivo >= TIEMPO_EXPIRACION) {
        console.log('Sesión expirada por inactividad');
        Swal.fire({
            icon: 'info',
            title: 'Sesión expirada',
            text: 'Redirigiendo al login...',
            timer: 2000,
            showConfirmButton: false,
            allowOutsideClick: false
        }).then(() => {
            window.location.href = 'login.php?expired=inactivity';
        });
    }
}, 1000);

// Eventos que reinician el contador
['mousemove', 'keydown', 'mousedown', 'touchstart', 'scroll', 'click'].forEach(event => {
    document.addEventListener(event, reiniciarContador);
});

// Mostrar tiempo restante en consola (para depuración - opcional)
setInterval(() => {
    if (tiempoInactivo > 0) {
        const minutosInactivo = Math.floor(tiempoInactivo / 60000);
        const segundosInactivo = Math.floor((tiempoInactivo % 60000) / 1000);
        const minutosRestantes = Math.max(0, 30 - minutosInactivo);
        const segundosRestantes = Math.max(0, 60 - segundosInactivo);
        
        if (minutosInactivo >= 25) { // Solo mostrar cuando se acerque el tiempo
            console.log(`Inactivo: ${minutosInactivo}m ${segundosInactivo}s | Restante: ${minutosRestantes}m ${segundosRestantes}s`);
        }
    }
}, 10000); // Cada 10 segundos
});
</script>
