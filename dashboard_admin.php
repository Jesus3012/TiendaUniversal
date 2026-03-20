<?php
require_once 'includes/session.php';
require_once 'includes/db.php';
require_once 'includes/csrf.php'; // Solo lo necesario para AJAX

// Verificar autenticación
if (!isset($_SESSION['usuario_id']) || strtolower($_SESSION['rol'] ?? '') !== 'administrador') {
    header("Location: login.php");
    exit;
}

// ===== PROCESAR AJAX PRIMERO =====
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_cambio_password'])) {
    // Limpiar cualquier output anterior
    ob_clean();
    header('Content-Type: application/json');
    
    $response = ['success' => false, 'message' => ''];
    
    try {
        // Verificar CSRF
        if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
            $response['message'] = "Token de seguridad inválido.";
            echo json_encode($response);
            exit;
        }
        
        $password_nueva = $_POST['password_nueva'] ?? '';
        $password_confirmar = $_POST['password_confirmar'] ?? '';
        $id_usuario = $_SESSION['usuario_id'];
        
        if (empty($password_nueva) || empty($password_confirmar)) {
            $response['message'] = "Todos los campos son obligatorios.";
        } elseif ($password_nueva !== $password_confirmar) {
            $response['message'] = "Las contraseñas no coinciden.";
        } elseif (strlen($password_nueva) < 8) {
            $response['message'] = "La contraseña debe tener al menos 8 caracteres.";
        } elseif ($password_nueva === 'Pescadores1') {
            $response['message'] = "No puedes usar la contraseña por defecto.";
        } else {
            $hash_nuevo = password_hash($password_nueva, PASSWORD_DEFAULT);
            $update = $conn->prepare("UPDATE usuarios SET password = ?, debe_cambiar_password = 0 WHERE id = ?");
            $update->bind_param("si", $hash_nuevo, $id_usuario);
            
            if ($update->execute()) {
                $_SESSION['debe_cambiar_password'] = 0;
                $response['success'] = true;
                $response['message'] = "Contraseña cambiada exitosamente.";
            } else {
                $response['message'] = "Error al actualizar la contraseña.";
            }
            $update->close();
        }
    } catch (Exception $e) {
        $response['message'] = "Error interno: " . $e->getMessage();
    }
    
    // Limpiar y enviar JSON
    ob_clean();
    echo json_encode($response);
    exit;
}

// ===== AHORA SÍ, INCLUIR HEADER Y NAVBAR =====
require_once 'includes/header.php';
require_once 'includes/navbar.php';

// ===== VERIFICAR CAMBIO DE CONTRASEÑA OBLIGATORIO =====
$mostrar_modal_password = (isset($_SESSION['debe_cambiar_password']) && $_SESSION['debe_cambiar_password'] == 1);

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

// ---------------------------------------------------
// Clasificar Productos e Insumos por Stock
// ---------------------------------------------------
$productosStockBajo = [];
$productosStockSuficiente = [];
$insumosStockBajo = [];
$insumosStockSuficiente = [];

// Consulta que incluye el nuevo campo 'tipo_inventario'
$resItems = $conn->query("SELECT id, nombre, cantidad, tipo_inventario FROM productos WHERE activo = 1");

if ($resItems) {
    while ($item = $resItems->fetch_assoc()) {
        $item['cantidad'] = floatval($item['cantidad']); // Usamos float para manejar metros (ej. 2.5)
        $tipo = $item['tipo_inventario'];
        $cantidad = $item['cantidad'];
        $umbralStockBajo = 5; // Umbral por defecto para productos (piezas)

        // Si es un insumo, definimos un umbral diferente, por ejemplo, 2 metros o piezas
        if ($tipo === 'insumo') {
            $umbralStockBajo = 2.0; // Umbral en metros o piezas (puedes ajustarlo)
        }

        // Clasificar según el umbral correspondiente
        if ($cantidad < $umbralStockBajo) {
            if ($tipo === 'insumo') {
                $insumosStockBajo[] = $item;
            } else {
                $productosStockBajo[] = $item;
            }
        } else {
            if ($tipo === 'insumo') {
                $insumosStockSuficiente[] = $item;
            } else {
                $productosStockSuficiente[] = $item;
            }
        }
    }
}

// Para el tooltip y contadores generales
$totalStockBajo = count($productosStockBajo) + count($insumosStockBajo);
$mensajeStockBajo = "";
if ($totalStockBajo > 0) {
    $mensajeStockBajo = "Atención: ";
    if (count($productosStockBajo) > 0) {
        $mensajeStockBajo .= count($productosStockBajo) . " producto(s) con stock bajo. ";
    }
    if (count($insumosStockBajo) > 0) {
        $mensajeStockBajo .= count($insumosStockBajo) . " insumo(s) con stock bajo.";
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
// SOLO Productos sin movimiento (7 días) - EXCLUYE INSUMOS
// ---------------------------------------------------
$productosSinMovimiento = [];
$resSinMovimiento = $conn->query("
    SELECT nombre
    FROM productos
    WHERE tipo_inventario = 'producto'
    AND id NOT IN (
        SELECT DISTINCT id_producto
        FROM ventas
        WHERE fecha_venta >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)
    )
");
if ($resSinMovimiento) {
    while ($item = $resSinMovimiento->fetch_assoc()) {
        $productosSinMovimiento[] = $item['nombre'];
    }
}

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

$result = $conn->query($sql);
$row = $result->fetch_assoc();
$utilidadHoy = $row['utilidadHoy'] ?? 0;

// ---------------------------------------------------
// Variación semanal
// ---------------------------------------------------
$resSemanaAnterior = $conn->query("
    SELECT IFNULL(SUM(v.cantidad_vendida*p.precio_venta),0) AS total
    FROM ventas v 
    JOIN productos p ON v.id_producto=p.id
    WHERE YEARWEEK(v.fecha_venta,1) = YEARWEEK(DATE_SUB(CURDATE(), INTERVAL 1 WEEK),1)
");
$ventasSemanaAnterior = ($resSemanaAnterior && $resSemanaAnterior->num_rows) ? floatval($resSemanaAnterior->fetch_assoc()['total']) : 0.0;

// ---------------------------------------------------
// Variación mensual
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
// Ticket promedio y utilidad semanal
// ---------------------------------------------------
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

// Utilidad de la semana
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
$minVentasSemana = 10;
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
} elseif ($totalVentasSemana > 0 && $totalVentasSemana < $minVentasSemana) {
    $estadoColor = 'warning';
    $estadoTexto = 'Operación débil';
    $estadoIcon = 'fa-battery-quarter';
    $mensajeCentral = 'Las ventas existen, pero son demasiado bajas.';
    $riesgo = true;
} elseif ($variacionMes < -10) {
    $estadoColor = 'danger';
    $estadoTexto = 'Caída crítica';
    $estadoIcon = 'fa-arrow-down';
    $mensajeCentral = 'Las ventas muestran una caída pronunciada.';
    $riesgo = true;
} elseif ($variacionMes < 0) {
    $estadoColor = 'warning';
    $estadoTexto = 'Desaceleración';
    $estadoIcon = 'fa-exclamation-circle';
    $mensajeCentral = 'El crecimiento perdió fuerza este mes.';
}

$accion = 'Monitorear comportamiento del negocio.';

/* ===================== NIVEL CRÍTICO ===================== */
if ($totalVentasSemana <= 0) {
    $accion = 'Revisar precios, canales de venta, visibilidad y posibles fallas operativas URGENTE.';
} elseif ($totalVentasSemana > 0 && $totalVentasSemana < $minVentasSemana) {
    $accion = 'Ventas demasiado bajas. Revisar precios y mejorar visibilidad del negocio.';
} elseif ($variacionMes < -10) {
    if (count($productosSinMovimiento) > 0) {
        $accion = 'Revisar causas de la caída, depurar productos muertos y ajustar estrategia.';
    } 
} elseif ($variacionMes < 0) {
    if (count($productosStockBajo) > 0 || count($insumosStockBajo) > 0) {
        $accion = 'Reabastecer productos/insumos de alta rotación.';
    }
} elseif ($variacionMes <= 10) {
    $accion = 'Optimizar procesos y monitorear crecimiento.';
} else {
    if (count($productosStockBajo) > 0 || count($insumosStockBajo) > 0) {
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
    overflow-x: auto;
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

/* Estilo especial SOLO para pantallas pequeñas */
@media (max-width: 768px) {
    .quick-config {
        gap: 6px;
    }

    .quick-config .config-item {
        font-size: 13px;
        padding: 8px 14px;
        width: calc(50% - 12px);
        text-align: left;
    }

    .config-title {
        width: 100%;
        margin-bottom: 6px;
        font-size: 15px;
        color: #3c8dbc;
    }
}

/* Estilos mínimos pero efectivos */
.modal-content {
    border: none;
    border-radius: 0.5rem;
}

.modal-header.bg-primary, .modal-header.bg-info {
    background: linear-gradient(135deg, #007bff 0%, #0056b3 100%) !important;
    border-bottom: none;
    padding: 1rem 1.5rem;
}

.modal-header .close {
    color: white;
    opacity: 0.8;
    text-shadow: none;
}

.modal-header .close:hover {
    opacity: 1;
}

.modal-body {
    padding: 1.5rem;
    background: #f8f9fa;
}

/* Mini resumen de ventas */
.sales-mini-summary {
    background: white;
    border-radius: 0.5rem;
    padding: 1rem;
    margin-bottom: 1.5rem;
    border-left: 4px solid;
    box-shadow: 0 2px 4px rgba(0,0,0,0.04);
}

.sales-mini-summary.primary { border-left-color: #007bff; }
.sales-mini-summary.info { border-left-color: #17a2b8; }

/* Tablas limpias */
.table {
    margin-bottom: 0;
    background: white;
    border-radius: 0.5rem;
    overflow: hidden;
}

.table thead th {
    background: #e9ecef;
    border-bottom: none;
    color: #495057;
    font-weight: 600;
    font-size: 0.85rem;
    text-transform: uppercase;
    letter-spacing: 0.3px;
    padding: 0.75rem 1rem;
}

.table tbody td {
    padding: 0.75rem 1rem;
    vertical-align: middle;
    border-bottom: 1px solid #e9ecef;
    color: #212529;
}

.table tbody tr:last-child td {
    border-bottom: none;
}

.table tbody tr:hover {
    background: rgba(0,123,255,0.02);
}

/* Badges para tipo de item */
.badge-type {
    display: inline-block;
    width: 8px;
    height: 8px;
    border-radius: 50%;
    margin-right: 0.5rem;
}

.badge-type.product { background: #007bff; }
.badge-type.insumo { background: #17a2b8; }

/* Lista de usuarios */
.user-list {
    list-style: none;
    padding: 0;
    margin: 0;
    background: white;
    border-radius: 0.5rem;
    overflow: hidden;
}

.user-list li {
    padding: 0.75rem 1rem;
    border-bottom: 1px solid #e9ecef;
    display: flex;
    align-items: center;
}

.user-list li:last-child {
    border-bottom: none;
}

.user-list li i {
    color: #6c757d;
    width: 24px;
    font-size: 1rem;
}

.user-list li:hover {
    background: #f8f9fa;
}

/* Mensaje sin datos */
.no-data-message {
    text-align: center;
    color: #6c757d;
    padding: 2rem;
    background: white;
    border-radius: 0.5rem;
}

.no-data-message i {
    font-size: 2rem;
    margin-bottom: 0.5rem;
    color: #adb5bd;
}

.small-box {
    min-height: 200px; /* Ajusta según necesites */
}
.small-box .inner {
    padding-bottom: 0.5rem;
}

/* Mensaje cuando no hay resultados */
.table-danger {
    position: relative;
}

.table-danger::after {
    content: 'No se encontraron usuarios que coincidan con la búsqueda';
    position: absolute;
    top: 50%;
    left: 50%;
    transform: translate(-50%, -50%);
    background: #dc3545;
    color: white;
    padding: 10px 20px;
    border-radius: 5px;
    font-size: 1rem;
    z-index: 1000;
    white-space: nowrap;
}
.bg-warning,
.bg-warning h3,
.bg-warning p,
.bg-warning small{
    color: #fff !important;
}

/* ESTILOS PARA MODAL DE CAMBIO DE CONTRASEÑA */
#modalCambiarPassword {
    position: fixed;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    z-index: 20000;
    background: rgba(0, 0, 0, 0.7);
    display: flex !important;
    align-items: center;
    justify-content: center;
    padding: 10px;
}

#modalCambiarPassword[style*="display: none"] {
    display: none !important;
}

#modalCambiarPassword .modal-dialog {
    margin: 0;
    width: 100%;
    max-width: 500px;
    position: relative;
    z-index: 20001;
}

#modalCambiarPassword .modal-content {
    border-radius: 10px;
    overflow: hidden;
    border: none;
    box-shadow: 0 15px 35px rgba(0,0,0,0.3);
    background: white;
}

#modalCambiarPassword .modal-header {
    background: #ff7b00;
    color: white;
    padding: 12px 15px;
    border-bottom: none;
}

#modalCambiarPassword .modal-header h5 {
    font-size: 1rem;
    margin: 0;
    font-weight: 600;
}

#modalCambiarPassword .modal-body {
    padding: 15px;
}

#modalCambiarPassword .alert-warning {
    background: #fff3cd;
    border-left: 4px solid #ff7b00;
    color: #856404;
    margin-bottom: 15px;
    padding: 10px;
    border-radius: 6px;
    font-size: 0.85rem;
}

#modalCambiarPassword .alert-danger {
    background: #f8d7da;
    border-left: 4px solid #dc3545;
    color: #721c24;
    margin-bottom: 15px;
    padding: 10px;
    border-radius: 6px;
    font-size: 0.85rem;
    display: none;
}

#modalCambiarPassword .form-group {
    margin-bottom: 12px;
}

#modalCambiarPassword .form-group label {
    font-size: 0.8rem;
    font-weight: 600;
    color: #495057;
    margin-bottom: 4px;
    display: block;
}

#modalCambiarPassword .input-group-sm .form-control {
    font-size: 0.85rem;
    padding: 0.4rem 0.5rem;
    border: 1px solid #ced4da;
    border-right: none;
}

#modalCambiarPassword .input-group-sm .form-control:focus {
    border-color: #ff7b00;
    box-shadow: none;
}

#modalCambiarPassword .input-group-sm .btn {
    padding: 0.4rem 0.8rem;
    font-size: 0.85rem;
    border: 1px solid #ced4da;
    border-left: none;
    background: white;
}

#modalCambiarPassword .input-group-sm .btn:hover {
    background: #f8f9fa;
    color: #ff7b00;
}

#modalCambiarPassword .progress {
    height: 3px;
    margin-top: 5px;
    border-radius: 2px;
    background: #e9ecef;
}

#modalCambiarPassword .progress-bar {
    border-radius: 2px;
    transition: width 0.3s ease;
}

#modalCambiarPassword .requirements {
    background: #f8f9fa;
    border-radius: 6px;
    border: 1px solid #dee2e6;
    font-size: 0.75rem;
    padding: 10px;
    margin: 15px 0;
}

#modalCambiarPassword .requirements div {
    display: flex;
    align-items: center;
    margin-bottom: 6px;
}

#modalCambiarPassword .requirements div:last-child {
    margin-bottom: 0;
}

#modalCambiarPassword .requirements i {
    width: 16px;
    font-size: 0.7rem;
    margin-right: 6px;
}

#modalCambiarPassword .requirements .requirement-met i {
    color: #28a745 !important;
}

#modalCambiarPassword .btn-warning {
    background: #ff7b00;
    border: none;
    color: white;
    font-weight: 600;
    font-size: 0.85rem;
    padding: 0.5rem;
    width: 100%;
    border-radius: 6px;
    transition: all 0.3s;
}

#modalCambiarPassword .btn-warning:hover:not(:disabled) {
    background: #e66a00;
    transform: translateY(-2px);
    box-shadow: 0 5px 15px rgba(255,123,0,0.3);
}

#modalCambiarPassword .btn-warning:disabled {
    opacity: 0.6;
    cursor: not-allowed;
}

/* Asegurar que el content-wrapper se desactive cuando el modal está visible */
.content-wrapper.modal-active {
    pointer-events: none;
}

/* SweetAlert por encima de todo */
.swal2-container {
    z-index: 30000 !important;
}
</style>

<!-- Content Wrapper -->
<div class="content-wrapper <?= $mostrar_modal_password ? 'modal-active' : '' ?>">
    <!-- MODAL DE CAMBIO DE CONTRASEÑA OBLIGATORIO -->
    <?php if ($mostrar_modal_password): ?>
    <div id="modalCambiarPassword">
        <div class="modal-dialog">
            <div class="modal-content">
                
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="fas fa-shield-alt mr-2"></i>
                        Cambiar Contraseña
                    </h5>
                </div>
                
                <div class="modal-body">
                    <div class="alert alert-warning">
                        <i class="fas fa-exclamation-triangle mr-2"></i>
                        <strong>¡Seguridad!</strong> Cambia tu contraseña por defecto 
                        <strong>"Pescadores1"</strong>
                    </div>
                    
                    <!-- Mensajes de error -->
                    <div id="error-mensaje" class="alert alert-danger py-2" style="display: none;"></div>
                    
                    <form id="formCambiarPassword">
                        <input type="hidden" name="csrf_token" value="<?= csrf_token(); ?>">
                        <input type="hidden" name="ajax_cambio_password" value="1">
                        
                        <div class="form-group">
                            <label for="password_nueva">
                                <i class="fas fa-lock mr-1"></i>Nueva Contraseña
                            </label>
                            <div class="input-group input-group-sm">
                                <input type="password" 
                                    class="form-control" 
                                    id="password_nueva" 
                                    name="password_nueva" 
                                    placeholder="Mínimo 8 caracteres"
                                    autocomplete="off"
                                    required
                                    onkeyup="validarRequisitos()">
                                <div class="input-group-append">
                                    <button class="btn btn-outline-secondary" type="button" onclick="togglePassword('password_nueva', this)">
                                        <i class="fas fa-eye"></i>
                                    </button>
                                </div>
                            </div>
                            <div class="progress">
                                <div class="progress-bar" id="strengthBar" style="width: 0%;"></div>
                            </div>
                        </div>
                        
                        <div class="form-group">
                            <label for="password_confirmar">
                                <i class="fas fa-check-circle mr-1"></i>Confirmar Contraseña
                            </label>
                            <div class="input-group input-group-sm">
                                <input type="password" 
                                    class="form-control" 
                                    id="password_confirmar" 
                                    name="password_confirmar" 
                                    placeholder="Repite la contraseña"
                                    autocomplete="off"
                                    required
                                    onkeyup="validarRequisitos()">
                                <div class="input-group-append">
                                    <button class="btn btn-outline-secondary" type="button" onclick="togglePassword('password_confirmar', this)">
                                        <i class="fas fa-eye"></i>
                                    </button>
                                </div>
                            </div>
                        </div>
                        
                        <div class="requirements">
                            <div id="req-length">
                                <i class="fas fa-times text-danger"></i> Mínimo 8 caracteres
                            </div>
                            <div id="req-different">
                                <i class="fas fa-times text-danger"></i> No puede ser "Pescadores1"
                            </div>
                            <div id="req-match">
                                <i class="fas fa-times text-danger"></i> Las contraseñas coinciden
                            </div>
                            <div id="req-strong">
                                <i class="fas fa-times text-danger"></i> Contraseña segura
                            </div>
                        </div>
                        
                        <button type="submit" class="btn btn-warning" id="btnCambiar">
                            <i class="fas fa-sync-alt mr-2"></i> Cambiar Contraseña
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <script>
    function togglePassword(fieldId, button) {
        const field = document.getElementById(fieldId);
        const icon = button.querySelector('i');
        
        if (field.type === 'password') {
            field.type = 'text';
            icon.classList.remove('fa-eye');
            icon.classList.add('fa-eye-slash');
        } else {
            field.type = 'password';
            icon.classList.remove('fa-eye-slash');
            icon.classList.add('fa-eye');
        }
    }

    function validarRequisitos() {
        const password = document.getElementById('password_nueva').value;
        const confirm = document.getElementById('password_confirmar').value;
        
        const reqLength = document.getElementById('req-length');
        const reqDifferent = document.getElementById('req-different');
        const reqMatch = document.getElementById('req-match');
        const reqStrong = document.getElementById('req-strong');
        const strengthBar = document.getElementById('strengthBar');
        
        // Longitud
        if (password.length >= 8) {
            reqLength.innerHTML = '<i class="fas fa-check text-success"></i> Mínimo 8 caracteres ✓';
            reqLength.classList.add('requirement-met');
        } else {
            reqLength.innerHTML = '<i class="fas fa-times text-danger"></i> Mínimo 8 caracteres';
            reqLength.classList.remove('requirement-met');
        }
        
        // No Pescadores1
        if (password !== 'Pescadores1' && password.length > 0) {
            reqDifferent.innerHTML = '<i class="fas fa-check text-success"></i> No puede ser "Pescadores1" ✓';
            reqDifferent.classList.add('requirement-met');
        } else {
            reqDifferent.innerHTML = '<i class="fas fa-times text-danger"></i> No puede ser "Pescadores1"';
            reqDifferent.classList.remove('requirement-met');
        }
        
        // Coincidencia
        if (password === confirm && password.length > 0) {
            reqMatch.innerHTML = '<i class="fas fa-check text-success"></i> Las contraseñas coinciden ✓';
            reqMatch.classList.add('requirement-met');
        } else {
            reqMatch.innerHTML = '<i class="fas fa-times text-danger"></i> Las contraseñas coinciden';
            reqMatch.classList.remove('requirement-met');
        }
        
        // Fortaleza
        let strength = 0;
        if (password.length >= 8) strength++;
        if (password !== 'Pescadores1' && password.length > 0) strength++;
        if (/[A-Z]/.test(password)) strength++;
        if (/[0-9]/.test(password)) strength++;
        if (/[^A-Za-z0-9]/.test(password)) strength++;
        
        const strengthPercent = (strength / 5) * 100;
        
        if (strength <= 2) {
            strengthBar.style.width = strengthPercent + '%';
            strengthBar.className = 'progress-bar bg-danger';
            reqStrong.innerHTML = '<i class="fas fa-times text-danger"></i> Contraseña débil';
            reqStrong.classList.remove('requirement-met');
        } else if (strength <= 3) {
            strengthBar.style.width = strengthPercent + '%';
            strengthBar.className = 'progress-bar bg-warning';
            reqStrong.innerHTML = '<i class="fas fa-exclamation-triangle text-warning"></i> Contraseña media';
            reqStrong.classList.remove('requirement-met');
        } else {
            strengthBar.style.width = strengthPercent + '%';
            strengthBar.className = 'progress-bar bg-success';
            reqStrong.innerHTML = '<i class="fas fa-check text-success"></i> Contraseña segura ✓';
            reqStrong.classList.add('requirement-met');
        }
    }

    // Envío AJAX del formulario
    document.getElementById('formCambiarPassword').addEventListener('submit', function(e) {
        e.preventDefault();
        
        const password = document.getElementById('password_nueva').value;
        const confirm = document.getElementById('password_confirmar').value;
        const errorDiv = document.getElementById('error-mensaje');
        const submitBtn = document.getElementById('btnCambiar');
        
        // Ocultar error anterior
        errorDiv.style.display = 'none';
        
        // Validaciones básicas
        if (!password || !confirm) {
            errorDiv.textContent = 'Completa todos los campos';
            errorDiv.style.display = 'block';
            return;
        }
        
        if (password !== confirm) {
            errorDiv.textContent = 'Las contraseñas no coinciden';
            errorDiv.style.display = 'block';
            return;
        }
        
        if (password.length < 8) {
            errorDiv.textContent = 'Mínimo 8 caracteres';
            errorDiv.style.display = 'block';
            return;
        }
        
        if (password === 'Pescadores1') {
            errorDiv.textContent = 'Usa una contraseña diferente';
            errorDiv.style.display = 'block';
            return;
        }
        
        // Deshabilitar botón y mostrar loading
        submitBtn.disabled = true;
        submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin mr-2"></i> Cambiando...';
        
        // Crear FormData
        const formData = new FormData();
        formData.append('csrf_token', document.querySelector('input[name="csrf_token"]').value);
        formData.append('ajax_cambio_password', '1');
        formData.append('password_nueva', password);
        formData.append('password_confirmar', confirm);
        
        // Enviar vía AJAX al mismo archivo
        fetch(window.location.href, {
            method: 'POST',
            body: formData
        })
        .then(response => {
            if (!response.ok) {
                throw new Error('Error en la respuesta del servidor');
            }
            return response.json();
        })
        .then(data => {
            if (data.success) {
                // Ocultar el modal
                const modal = document.getElementById('modalCambiarPassword');
                modal.style.display = 'none';
                
                // Quitar clase modal-active
                document.querySelector('.content-wrapper').classList.remove('modal-active');
                
                // Mostrar SweetAlert de éxito
                Swal.fire({
                    icon: 'success',
                    title: '¡Contraseña cambiada!',
                    text: data.message,
                    timer: 2000,
                    showConfirmButton: false,
                    timerProgressBar: true,
                    didOpen: () => {
                        Swal.showLoading();
                    }
                }).then(() => {
                    window.location.reload();
                });
            } else {
                errorDiv.textContent = data.message;
                errorDiv.style.display = 'block';
                
                // Rehabilitar botón
                submitBtn.disabled = false;
                submitBtn.innerHTML = '<i class="fas fa-sync-alt mr-2"></i> Cambiar Contraseña';
            }
        })
        .catch(error => {
            console.error('Error:', error);
            errorDiv.textContent = 'Error al conectar con el servidor';
            errorDiv.style.display = 'block';
            
            // Rehabilitar botón
            submitBtn.disabled = false;
            submitBtn.innerHTML = '<i class="fas fa-sync-alt mr-2"></i> Cambiar Contraseña';
        });
    });
    </script>
    <?php endif; ?>
    <section>
        <div class="container-fluid">
            <!-- ALERTAS -->
            <?php if($totalStockBajo > 0): ?>
            <div class="alert alert-warning alert-dismissible fade show shadow-sm" role="alert">
                <i class="fas fa-exclamation-triangle mr-2"></i>
                <strong><?= htmlspecialchars($mensajeStockBajo) ?></strong>
                <button type="button" class="close" data-dismiss="alert">
                    <span>&times;</span>
                </button>
            </div>
            <?php endif; ?>

            <?php if(count($productosSinMovimiento) > 0): ?>
            <div class="alert alert-info shadow-sm">
                <i class="fas fa-info-circle"></i>
                Productos sin ventas en 7 días:
                <strong><?= htmlspecialchars(implode(", ", array_slice($productosSinMovimiento, 0, 5))) ?><?= count($productosSinMovimiento) > 5 ? '...' : '' ?></strong>
                <button type="button" class="close" data-dismiss="alert">
                    <span>&times;</span>
                </button>
            </div>
            <?php endif; ?>

            <!-- GRID DE CARDS -->
            <div class="row">
                <!-- Ventas Hoy -->
                <div class="col-lg-4 col-md-6 col-sm-12">
                    <div class="small-box bg-primary" data-toggle="modal" data-target="#modalVentasHoy" style="cursor:pointer">
                        <div class="inner">
                            <h3>$<?= number_format($totalVentasDia, 2) ?></h3>
                            <p>Ventas Hoy</p>
                            <div class="d-flex justify-content-between align-items-center mt-2">
                                <span class="badge badge-light"><i class="fas fa-ticket-alt mr-1"></i><?= intval($ticketsHoy) ?> tickets</span>      
                            </div>
                        </div>
                        <div class="icon">
                            <i class="fas fa-calendar-day"></i>
                        </div>
                        <a href="#" class="small-box-footer" data-toggle="modal" data-target="#modalVentasHoy">
                            Ver detalles <i class="fas fa-arrow-circle-right"></i>
                        </a>
                    </div>
                </div>
                
                <!-- Ventas Semana -->
                <div class="col-lg-4 col-md-6 col-sm-12">
                    <div class="small-box bg-info" data-toggle="modal" data-target="#modalVentasSemana" style="cursor:pointer">
                        <div class="inner">
                            <h3>$<?= number_format($totalVentasSemana, 2) ?></h3>
                            <p>Ventas Semana</p>
                            <div class="d-flex justify-content-between align-items-center mt-2">
                                <span class="badge badge-light"><i class="fas fa-chart-line mr-1"></i>vs semana ant.</span>
                            </div>
                        </div>
                        <div class="icon">
                            <i class="fas fa-calendar-week"></i>
                        </div>
                        <a href="#" class="small-box-footer" data-toggle="modal" data-target="#modalVentasSemana">
                            Ver detalles <i class="fas fa-arrow-circle-right"></i>
                        </a>
                    </div>
                </div>
                
                <!-- Ventas Mes -->
                <div class="col-lg-4 col-md-6 col-sm-12">
                    <div class="small-box bg-secondary" data-toggle="modal" data-target="#modalVentasMes" style="cursor:pointer">
                        <div class="inner">
                            <h3>$<?= number_format($totalVentasMes, 2) ?></h3>
                            <p>Ventas Mes</p>
                            <div class="d-flex justify-content-between align-items-center mt-2">
                                <span class="badge badge-light"><i class="fas fa-calendar-alt mr-1"></i>mes actual</span>
                            </div>
                        </div>
                        <div class="icon">
                            <i class="fas fa-calendar-alt"></i>
                        </div>  
                        <a href="#" class="small-box-footer" data-toggle="modal" data-target="#modalVentasMes">
                            Ver detalles <i class="fas fa-arrow-circle-right"></i>
                        </a>
                    </div>
                </div>
            </div>

            <!-- Segunda fila de cards -->
            <div class="row">
                <!-- Usuarios Registrados -->
                <div class="col-lg-4 col-md-6 col-sm-12">
                    <div class="small-box bg-success" data-toggle="modal" data-target="#modalUsuarios" style="cursor:pointer">
                        <div class="inner">
                            <h3><?= intval($totalUsuarios) ?></h3>
                            <p>Usuarios Registrados</p>
                            <div class="d-flex justify-content-between align-items-center mt-2">
                                <span class="badge badge-light"><i class="fas fa-user-check mr-1"></i>activos</span>
                            </div>
                        </div>
                        <div class="icon">
                            <i class="fas fa-users"></i>
                        </div>
                        <a href="#" class="small-box-footer" data-toggle="modal" data-target="#modalUsuarios">
                            Ver detalles <i class="fas fa-arrow-circle-right"></i>
                        </a>
                    </div>
                </div>
                
                <!-- Utilidad Estimada -->
                <div class="col-lg-4 col-md-6 col-sm-12">
                    <div class="small-box bg-warning" data-toggle="modal" data-target="#modalUtilidad" style="cursor:pointer">
                        <div class="inner">
                            <h3>$<?= number_format($utilidadHoy, 2) ?></h3>
                            <p>Utilidad Estimada Hoy</p>
                            <div class="d-flex justify-content-between align-items-center mt-2">
                                <span class="badge badge-light"><i class="fas fa-wallet mr-1"></i>proyección</span>
                                <small class="text-white opacity-75">
                                    <i class="fas fa-percentage mr-1"></i>
                                    <?= $totalVentasDia > 0 ? number_format(($utilidadHoy / $totalVentasDia) * 100, 1) : 0 ?>%
                                </small>
                            </div>
                        </div>
                        <div class="icon">
                            <i class="fas fa-wallet"></i>
                        </div>
                    </div>
                </div>

                <!-- Card ÚNICA para Productos e Insumos -->
                <?php
                $totalItemsBajo = count($productosStockBajo) + count($insumosStockBajo);
                $totalItems = count($productosStockBajo) + count($productosStockSuficiente) + 
                            count($insumosStockBajo) + count($insumosStockSuficiente);
                $colorItems = $totalItemsBajo > 0 ? 'bg-danger' : 'bg-success';
                $badgeColor = $totalItemsBajo > 0 ? 'badge-warning' : 'badge-success';
                $porcentajeOptimo = $totalItems > 0 ? round((($totalItems - $totalItemsBajo) / $totalItems) * 100) : 100;
                ?>
                <div class="col-lg-4 col-md-6 col-sm-12">
                    <div class="small-box <?= $colorItems ?>" style="cursor:pointer" data-toggle="modal" data-target="#modalStockGeneral">
                        <div class="inner">
                            <h3><?= intval($totalItems) ?></h3>
                            <p>Productos e Insumos</p>
                            <div class="d-flex justify-content-between align-items-center mt-2">
                                <?php if($totalItemsBajo > 0): ?>
                                    <span class="badge <?= $badgeColor ?>"><i class="fas fa-exclamation-triangle mr-1"></i><?= $totalItemsBajo ?> stock bajo</span>
                                    <small class="text-white opacity-75"><?= $porcentajeOptimo ?>% óptimo</small>
                                <?php else: ?>
                                    <span class="badge badge-light"><i class="fas fa-check-circle mr-1"></i>stock óptimo</span>
                                    <small class="text-white opacity-75">100%</small>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div class="icon">
                            <i class="fas fa-boxes"></i>
                        </div>
                        <a href="#" class="small-box-footer" data-toggle="modal" data-target="#modalStockGeneral">
                            Ver detalles <i class="fas fa-arrow-circle-right"></i>
                        </a>
                    </div>
                </div>
            </div>

            <!-- CONFIGURACIÓN RÁPIDA -->
            <div class="card mb-3">
                <div class="card-body quick-config d-flex flex-wrap flex-lg-nowrap align-items-center">
                    <div class="config-title"><strong>Configuración:</strong></div>
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
            <button id="exportCSV" class="btn btn-primary mb-3">
                <i class="fas fa-file-csv"></i> Exportar CSV
            </button>

            <!-- FILA 1: Gráfica semanal + Donut -->
            <div class="row">
                <!-- GRÁFICA SEMANAL -->
                <div class="col-lg-8 col-md-12 mb-4" data-widget="grafica">
                    <div class="card card-primary card-outline shadow-sm h-100">
                        <div class="card-header bg-white border-bottom-0 pt-3 pb-0">
                            <div class="d-flex justify-content-between align-items-center">
                                <h5 class="card-title text-bold">
                                    <i class="fas fa-chart-line mr-2 text-primary"></i> 
                                    Ventas - Últimos 7 días
                                </h5>
                                <span class="badge badge-success px-3 py-2">
                                    <i class="fas fa-calendar-week mr-1"></i>
                                    Total semana: $<?= number_format($totalVentasSemana,2) ?>
                                </span>
                            </div>
                        </div>
                        
                        <div class="card-body pt-3">
                            <!-- Leyenda interactiva -->
                            <div class="d-flex justify-content-end mb-2">
                                <small class="text-muted mr-3">
                                    <i class="fas fa-circle text-primary mr-1"></i> Ventas diarias
                                </small>
                            </div>
                            
                            <!-- Gráfica -->
                            <div class="chart" style="height: 220px;">
                                <canvas id="chartVentasSemana" style="min-height: 200px; height: 200px; max-height: 200px; max-width: 100%;"></canvas>
                            </div>
                        </div>
                        
                        <!-- Footer con métricas mejorado -->
                        <div class="card-footer bg-light border-top-0">
                            <div class="row">
                                <div class="col-6">
                                    <div class="d-flex align-items-center">
                                        <div class="mr-3">
                                            <i class="fas fa-receipt fa-2x text-info opacity-50"></i>
                                        </div>
                                        <div>
                                            <span class="text-muted text-sm">Venta total</span>
                                            <h6 class="mb-0 text-bold">$<?= number_format($ticketPromedio,2) ?></h6>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-6">
                                    <div class="d-flex align-items-center">
                                        <div class="mr-3">
                                            <i class="fas fa-chart-bar fa-2x text-success opacity-50"></i>
                                        </div>
                                        <div>
                                            <span class="text-muted text-sm">Utilidad (semana)</span>
                                            <h6 class="mb-0 text-success text-bold">$<?= number_format($utilidadSemana,2) ?></h6>
                                        </div>
                                    </div>
                                </div>
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
                <div class="col-md-6 mb-3" data-widget="producto/top">
                    <div class="card card-warning card-outline h-100">
                        <div class="card-header border-0">
                            <h3 class="card-title">
                                <i class="fas fa-star text-warning mr-2"></i> Destacados del Mes
                            </h3>
                        </div>
                        <div class="card-body pt-0">
                            <?php if(count($topProductos) > 0): ?>
                                <div class="row">
                                    <?php foreach($topProductos as $index => $p): ?>
                                        <?php 
                                        $colors = ['warning', 'secondary', 'info', 'primary', 'success'];
                                        $icons = ['crown', 'star', 'thumbs-up', 'fire', 'bolt'];
                                        $color = $colors[$index % count($colors)];
                                        $icon = $icons[$index % count($icons)];
                                        $numero = $index + 1; // Números del 1 al 5
                                        ?>
                                        <div class="col-12 mb-3">
                                            <div class="info-box bg-light shadow-sm position-relative">
                                                <!-- Número destacado -->
                                                <div class="position-absolute" style="top: -5px; left: -5px;">
                                                    <span class="badge badge-<?= $color ?> rounded-circle p-2" style="width: 30px; height: 30px; font-size: 1rem;">
                                                        <?= $numero ?>
                                                    </span>
                                                </div>
                                                
                                                <span class="info-box-icon bg-<?= $color ?> elevation-1">
                                                    <i class="fas fa-<?= $icon ?>"></i>
                                                </span>
                                                <div class="info-box-content">
                                                    <span class="info-box-text"><?= htmlspecialchars($p['nombre']) ?></span>
                                                    <div class="row">
                                                        <div class="col-6">
                                                            <small class="text-muted">
                                                                <i class="fas fa-shopping-cart mr-1"></i> Ventas
                                                            </small>
                                                            <span class="info-box-number"><?= intval($p['total_vendido']) ?></span>
                                                        </div>
                                                        <div class="col-6">
                                                            <small class="text-muted">
                                                                <i class="fas fa-dollar-sign mr-1"></i> Ingreso
                                                            </small>
                                                            <span class="info-box-number text-success">$<?= number_format(floatval($p['total_ingreso']),2) ?></span>
                                                        </div>
                                                    </div>
                                                    <div class="progress">
                                                        <div class="progress-bar bg-<?= $color ?>" style="width: <?= 100 - ($index * 15) ?>%"></div>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php else: ?>
                                <div class="text-center py-4">
                                    <i class="fas fa-chart-bar fa-4x text-muted mb-3"></i>
                                    <h6 class="text-muted">No hay datos disponibles</h6>
                                    <p class="text-muted small">Los productos destacados aparecerán aquí cuando haya ventas</p>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- RESUMEN -->
                <div class="col-md-6 mb-3" data-widget="producto/resumen">
                    <div class="card shadow-lg border-0 h-100">
                        <div class="card-header bg-<?= $estadoColor ?> text-white">
                            <h3 class="card-title">
                                <i class="fas <?= $estadoIcon ?> mr-2"></i>
                                Resumen Ejecutivo
                            </h3>
                        </div>

                        <div class="card-body">
                            <!-- ESTADO GENERAL - Más claro y directo -->
                            <div class="alert alert-<?= $estadoColor === 'success' ? 'success' : ($estadoColor === 'warning' ? 'warning' : 'danger') ?> mb-4">
                                <div class="d-flex align-items-center">
                                    <div class="mr-3">
                                        <i class="fas <?= $estadoIcon ?> fa-2x"></i>
                                    </div>
                                    <div>
                                        <strong><?= $estadoTexto ?></strong><br>
                                        <?= $mensajeCentral ?>
                                    </div>
                                </div>
                            </div>

                            <!-- MÉTRICAS INTERPRETADAS - Más visuales -->
                            <div class="row mb-4">
                                <div class="col-6 mb-3">
                                    <div class="bg-light p-3 rounded border-left border-info">
                                        <div class="small text-muted text-uppercase">
                                            <i class="mr-1 text-info"></i> Ritmo diario
                                        </div>
                                        <div class="h4 font-weight-bold mb-0">
                                            $<?= number_format($totalVentasDia,2) ?>
                                        </div>
                                        <small class="text-muted">Ventas hoy</small>
                                    </div>
                                </div>

                                <div class="col-6 mb-3">
                                    <div class="bg-light p-3 rounded border-left border-success">
                                        <div class="small text-muted text-uppercase">
                                            <i class="mr-1 text-success"></i> Proyección mensual
                                        </div>
                                        <div class="h4 font-weight-bold mb-0">
                                            $<?= number_format($totalVentasMes * 1.1,2) ?>
                                        </div>
                                        <small class="text-muted">Estimado fin de mes</small>
                                    </div>
                                </div>

                                <div class="col-6 mb-3">
                                    <div class="bg-light p-3 rounded border-left border-warning">
                                        <div class="small text-muted text-uppercase">
                                            <i class="mr-1 text-warning"></i> Ticket promedio
                                        </div>
                                        <div class="h4 font-weight-bold mb-0">
                                            $<?= $ticketsHoy > 0 ? round($ingresosHoy / $ticketsHoy,2) : 0 ?>
                                        </div>
                                        <small class="text-muted">Por cada venta</small>
                                    </div>
                                </div>

                                <div class="col-6 mb-3">
                                    <div class="bg-light p-3 rounded border-left border-danger">
                                        <div class="small text-muted text-uppercase">
                                            <i class="mr-1 text-danger"></i> Utilidad del día
                                        </div>
                                        <div class="h4 font-weight-bold text-success mb-0">
                                            $<?= number_format($utilidadHoy,2) ?>
                                        </div>
                                        <small class="text-muted">Ganancia neta</small>
                                    </div>
                                </div>
                            </div>

                            <!-- ALERTAS INTELIGENTES - Más organizadas -->
                            <div class="mb-4">
                                <h6 class="text-muted mb-3">
                                    <i class="fas fa-exclamation-triangle mr-2"></i>
                                    ¿Qué necesita atención?
                                </h6>

                                <?php if ($riesgo): ?>
                                    <div class="alert alert-danger mb-2">
                                        <i class="fas fa-exclamation-circle mr-2"></i>
                                        <strong>¡Alerta!</strong> Detectamos un riesgo operativo
                                    </div>
                                <?php endif; ?>

                                <?php if (count($productosStockBajo) > 0): ?>
                                    <div class="alert alert-warning mb-2">
                                        <i class="fas fa-boxes mr-2"></i>
                                        <strong><?= count($productosStockBajo) ?> productos</strong> están por agotarse
                                    </div>
                                <?php endif; ?>

                                <?php if (count($insumosStockBajo) > 0): ?>
                                    <div class="alert alert-warning mb-2">
                                        <i class="fas fa-flask mr-2"></i>
                                        <strong><?= count($insumosStockBajo) ?> insumos</strong> necesitan reabastecimiento
                                    </div>
                                <?php endif; ?>

                                <?php if (!$riesgo && count($productosStockBajo) === 0 && count($insumosStockBajo) === 0): ?>
                                    <div class="alert alert-success">
                                        <i class="fas fa-check-circle mr-2"></i>
                                        <strong>Todo en orden</strong> - No hay alertas pendientes
                                    </div>
                                <?php endif; ?>
                            </div>

                            <!-- ACCIÓN EJECUTIVA - Más destacada -->
                            <div class="card bg-light border-0">
                                <div class="card-body p-3">
                                    <div class="d-flex">
                                        <div class="mr-3">
                                            <span class="badge badge-<?= $estadoColor ?> p-3">
                                                <i class="fas fa-bullseye fa-lg"></i>
                                            </span>
                                        </div>
                                        <div>
                                            <small class="text-muted text-uppercase">Sugerencia para ahora</small>
                                            <div class="font-weight-bold h5 mb-0">
                                                <?= $accion ?>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>
</div>

<!-- MODALES -->

<!-- Modal Ventas Día - SIMPLE Y FUNCIONAL -->
<div class="modal fade" id="modalVentasHoy" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title">
                    <i class="fas fa-calendar-day mr-2"></i> 
                    Ventas del Día - <?= date('d/m/Y') ?>
                </h5>
                <button type="button" class="close text-white" data-dismiss="modal">
                    <span>&times;</span>
                </button>
            </div>
            <div class="modal-body">
                <!-- Mini resumen -->
                <div class="sales-mini-summary primary d-flex justify-content-between align-items-center">
                    <span class="text-muted">Total ventas hoy:</span>
                    <span class="h5 mb-0 font-weight-bold text-primary">$<?= number_format($totalVentasDia, 2) ?></span>
                </div>

                <div class="table-responsive">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Producto</th>
                                <th class="text-center">Cantidad</th>
                                <th class="text-right">Total</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php 
                        $res = $conn->query("
                            SELECT p.nombre, v.cantidad_vendida, 
                                   (v.cantidad_vendida * p.precio_venta) AS total,
                                   p.tipo_inventario
                            FROM ventas v
                            JOIN productos p ON v.id_producto = p.id
                            WHERE DATE(v.fecha_venta) = '$hoy'
                            ORDER BY v.fecha_venta DESC
                        ");
                        if($res && $res->num_rows > 0):
                            while($v = $res->fetch_assoc()): 
                        ?>
                            <tr>
                                <td>
                                    <span class="badge-type <?= $v['tipo_inventario'] ?>"></span>
                                    <?= htmlspecialchars($v['nombre']) ?>
                                </td>
                                <td class="text-center"><?= $v['cantidad_vendida'] ?></td>
                                <td class="text-right">$<?= number_format($v['total'], 2) ?></td>
                            </tr>
                        <?php 
                            endwhile; 
                        else:
                        ?>
                            <tr>
                                <td colspan="3" class="no-data-message">
                                    <i class="fas fa-shopping-cart"></i>
                                    <p class="mb-0">No hay ventas registradas hoy</p>
                                </td>
                            </tr>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Modal Usuarios - Versión Profesional con Búsqueda -->
<div class="modal fade" id="modalUsuarios" tabindex="-1" role="dialog" aria-labelledby="modalUsuariosTitle">
    <div class="modal-dialog modal-xl modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header bg-gradient-success text-white py-3">
                <h5 class="modal-title" id="modalUsuariosTitle">
                    <i class="fas fa-users-cog mr-2"></i>
                    Gestión de Usuarios del Sistema
                </h5>
                <button type="button" class="close text-white" data-dismiss="modal" aria-label="Cerrar">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
            
            <div class="modal-body p-0">
                <!-- Barra de herramientas fija -->
                <div class="bg-light p-3 border-bottom sticky-top">
                    <div class="row align-items-center">
                        <!-- Buscador en tiempo real -->
                        <div class="col-md-5">
                            <div class="input-group">
                                <div class="input-group-prepend">
                                    <span class="input-group-text bg-white border-right-0">
                                        <i class="fas fa-search text-success"></i>
                                    </span>
                                </div>
                                <input type="text" 
                                       class="form-control border-left-0 pl-0" 
                                       id="buscadorUsuarios" 
                                       placeholder="Buscar por nombre, email o rol..."
                                       autocomplete="off">
                                <div class="input-group-append">
                                    <button class="btn btn-outline-success" type="button" id="limpiarBusqueda">
                                        <i class="fas fa-times"></i>
                                    </button>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Resumen Estadístico Compacto -->
                        <div class="col-md-7">
                            <div class="d-flex justify-content-end align-items-center">
                                <div class="mr-4 text-center px-3">
                                    <span class="d-block text-muted small">Total</span>
                                    <span class="h5 mb-0 font-weight-bold text-success">
                                        <i class="fas fa-users mr-1"></i><span id="totalUsuarios"><?= $totalUsuarios ?></span>
                                    </span>
                                </div>
                                <div class="mr-4 text-center px-3">
                                    <span class="d-block text-muted small">Admins</span>
                                    <span class="h5 mb-0 font-weight-bold text-warning">
                                        <i class="fas fa-crown mr-1"></i>
                                        <?php 
                                        $admins = $conn->query("SELECT COUNT(*) as total FROM usuarios WHERE rol='administrador' AND activo=1")->fetch_assoc();
                                        echo $admins['total'];
                                        ?>
                                    </span>
                                </div>
                                <div class="text-center px-3">
                                    <span class="d-block text-muted small">Vendedores</span>
                                    <span class="h5 mb-0 font-weight-bold text-info">
                                        <i class="fas fa-user-tie mr-1"></i>
                                        <?php 
                                        $vendedores = $conn->query("SELECT COUNT(*) as total FROM usuarios WHERE rol='vendedor' AND activo=1")->fetch_assoc();
                                        echo $vendedores['total'];
                                        ?>
                                    </span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Contenedor de tabla con altura fija y scroll -->
                <div style="height: 300px; overflow-y: auto;" id="tablaContainer">
                    <table class="table table-hover table-sm mb-0" id="tablaUsuarios">
                        <thead class="thead-light sticky-top" style="top: 0; z-index: 10;">
                            <tr>
                                <th>Usuario</th>
                                <th>Email</th>
                                <th>Rol</th>
                                <th>Estado</th>
                                <th>Registro</th>
                                <th>Creado por</th>
                            </tr>
                        </thead>
                        <tbody id="cuerpoTabla">
                            <?php 
                            $resUsuarios = $conn->query("
                                SELECT u.*, c.nombre as creador_nombre 
                                FROM usuarios u 
                                LEFT JOIN usuarios c ON u.created_by = c.id 
                                ORDER BY u.activo DESC, u.rol, u.nombre
                            ");
                            while($u = $resUsuarios->fetch_assoc()): 
                                $iconColor = ($u['rol'] == 'administrador') ? 'text-warning' : 'text-info';
                                $estadoColor = ($u['activo'] == 1) ? 'success' : 'secondary';
                                $estadoTexto = ($u['activo'] == 1) ? 'Activo' : 'Inactivo';
                            ?>
                            <tr class="fila-usuario" 
                                data-nombre="<?= strtolower(htmlspecialchars($u['nombre'])) ?>"
                                data-email="<?= strtolower(htmlspecialchars($u['email'])) ?>"
                                data-rol="<?= strtolower($u['rol']) ?>"
                                data-estado="<?= $estadoTexto ?>">
                                <td class="pl-4">
                                    <div class="d-flex align-items-center">
                                        <i class="fas fa-user-circle fa-lg <?= $iconColor ?> mr-2"></i>
                                        <div class="text-truncate">
                                            <strong><?= htmlspecialchars($u['nombre']) ?></strong>
                                        </div>
                                    </div>
                                </td>
                                <td>
                                    <div class="text-truncate">
                                        <a href="mailto:<?= htmlspecialchars($u['email']) ?>" class="text-dark">
                                            <i class="fas fa-envelope mr-1 text-muted small"></i>
                                            <?= htmlspecialchars($u['email']) ?>
                                        </a>
                                    </div>
                                </td>
                                <td>
                                    <span class="badge badge-<?= ($u['rol'] == 'administrador') ? 'warning' : 'info' ?> badge-pill px-2 py-1" style="font-size: 0.75rem;">
                                        <i class="fas fa-<?= ($u['rol'] == 'administrador') ? 'crown' : 'user-tie' ?> mr-1"></i>
                                        <?= ucfirst($u['rol']) ?>
                                    </span>
                                </td>
                                <td>
                                    <span class="badge badge-<?= $estadoColor ?> badge-pill px-2 py-1" style="font-size: 0.75rem; min-width: 50px;">
                                        <i class="fas fa-<?= ($u['activo'] == 1) ? 'check-circle' : 'circle' ?> mr-1"></i>
                                        <?= $estadoTexto ?>
                                    </span>
                                </td>
                                <td>
                                    <small class="text-muted">
                                        <i class="fas fa-calendar-alt mr-1 small"></i>
                                        <?= date('d/m/Y', strtotime($u['fecha_registro'])) ?>
                                    </small>
                                </td>
                                <td>
                                    <?php if($u['creador_nombre']): ?>
                                        <small class="text-muted text-truncate d-inline-block" style="max-width: 500px;">
                                            <i class="fas fa-user-plus mr-1 small"></i>
                                            <?= htmlspecialchars($u['creador_nombre']) ?>
                                        </small>
                                    <?php else: ?>
                                        <small class="text-muted">-</small>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endwhile; ?>
                        </tbody>
                    </table>
                </div>

                <!-- Barra de paginación y resultados -->
                <div class="bg-light p-2 border-top">
                    <div class="row align-items-center">
                        <div class="col-md-6">
                            <small class="text-muted">
                                <i class="fas fa-filter mr-1"></i>
                                Mostrando <span id="resultadosMostrados">0</span> de <span id="totalRegistros"><?= $totalUsuarios ?></span> usuarios
                            </small>
                        </div>
                        <div class="col-md-6">
                            <div class="d-flex justify-content-end align-items-center">
                                <small class="text-muted mr-3" id="infoSeleccion"></small>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Modal Ventas Semana - SIMPLE Y FUNCIONAL -->
<div class="modal fade" id="modalVentasSemana" tabindex="-1">
    <div class="modal-dialog modal-xl">
        <div class="modal-content">
            <div class="modal-header bg-info text-white">
                <h5 class="modal-title">
                    <i class="fas fa-calendar-week mr-2"></i>
                    Ventas de la Semana
                    <small class="ml-2">(<?= date('d/m', strtotime($inicioSemana)) ?> - <?= date('d/m/Y', strtotime($finSemana)) ?>)</small>
                </h5>
                <button type="button" class="close text-white" data-dismiss="modal">
                    <span>&times;</span>
                </button>
            </div>
            <div class="modal-body">
                <!-- Mini resumen -->
                <div class="sales-mini-summary info d-flex justify-content-between align-items-center">
                    <span class="text-muted">Total ventas semana:</span>
                    <span class="h5 mb-0 font-weight-bold text-info">$<?= number_format($totalVentasSemana, 2) ?></span>
                </div>

                <div class="table-responsive">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Producto</th>
                                <th class="text-center">Cantidad</th>
                                <th class="text-right">Total</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php
                        $res = $conn->query("
                            SELECT p.nombre, SUM(v.cantidad_vendida) AS cantidad, 
                                   SUM(v.cantidad_vendida * p.precio_venta) AS total,
                                   p.tipo_inventario
                            FROM ventas v
                            JOIN productos p ON v.id_producto = p.id
                            WHERE DATE(v.fecha_venta) BETWEEN '$inicioSemana' AND '$finSemana'
                            GROUP BY p.id
                            ORDER BY total DESC
                        ");
                        if($res && $res->num_rows > 0):
                            while($r = $res->fetch_assoc()): 
                        ?>
                            <tr>
                                <td>
                                    <span class="badge-type <?= $r['tipo_inventario'] ?>"></span>
                                    <?= htmlspecialchars($r['nombre']) ?>
                                </td>
                                <td class="text-center"><?= $r['cantidad'] ?></td>
                                <td class="text-right">$<?= number_format($r['total'], 2) ?></td>
                            </tr>
                        <?php 
                            endwhile; 
                        else:
                        ?>
                            <tr>
                                <td colspan="3" class="no-data-message">
                                    <i class="fas fa-calendar-week"></i>
                                    <p class="mb-0">No hay ventas registradas esta semana</p>
                                </td>
                            </tr>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Modal Ventas Mes - SIMPLE Y FUNCIONAL -->
<div class="modal fade" id="modalVentasMes" tabindex="-1">
    <div class="modal-dialog modal-xl">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title">
                    <i class="fas fa-calendar-alt mr-2"></i>
                    Ventas del Mes - <?= date('F Y') ?>
                </h5>
                <button type="button" class="close text-white" data-dismiss="modal">
                    <span>&times;</span>
                </button>
            </div>
            <div class="modal-body">
                <!-- Mini resumen -->
                <div class="sales-mini-summary primary d-flex justify-content-between align-items-center">
                    <span class="text-muted">Total ventas mes:</span>
                    <span class="h5 mb-0 font-weight-bold text-primary">$<?= number_format($totalVentasMes, 2) ?></span>
                </div>

                <div class="table-responsive">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Producto</th>
                                <th class="text-center">Cantidad</th>
                                <th class="text-right">Total</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php
                        $res = $conn->query("
                            SELECT p.nombre, SUM(v.cantidad_vendida) AS cantidad, 
                                   SUM(v.cantidad_vendida * p.precio_venta) AS total,
                                   p.tipo_inventario
                            FROM ventas v
                            JOIN productos p ON v.id_producto = p.id
                            WHERE MONTH(v.fecha_venta) = MONTH(CURDATE()) 
                              AND YEAR(v.fecha_venta) = YEAR(CURDATE())
                            GROUP BY p.id
                            ORDER BY total DESC
                        ");
                        if($res && $res->num_rows > 0):
                            while($r = $res->fetch_assoc()): 
                        ?>
                            <tr>
                                <td>
                                    <span class="badge-type <?= $r['tipo_inventario'] ?>"></span>
                                    <?= htmlspecialchars($r['nombre']) ?>
                                </td>
                                <td class="text-center"><?= $r['cantidad'] ?></td>
                                <td class="text-right">$<?= number_format($r['total'], 2) ?></td>
                            </tr>
                        <?php 
                            endwhile; 
                        else:
                        ?>
                            <tr>
                                <td colspan="3" class="no-data-message">
                                    <i class="fas fa-calendar-alt"></i>
                                    <p class="mb-0">No hay ventas registradas este mes</p>
                                </td>
                            </tr>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Modal General de Stock -->
<div class="modal fade" id="modalStockGeneral" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-warning text-white">
                <h5 class="modal-title">
                    <i class="fas fa-boxes mr-2"></i>
                    Inventario General
                </h5>
                <button type="button" class="close text-white" data-dismiss="modal">
                    <span>&times;</span>
                </button>
            </div>
            <div class="modal-body" style="max-height: 500px; overflow-y: auto;">
                <!-- Mini resumen de stock -->
                <div class="d-flex justify-content-between mb-3">
                    <span class="badge badge-primary p-2">
                        <i class="fas fa-box mr-1"></i> Productos: <?= count($productosStockBajo) + count($productosStockSuficiente) ?>
                    </span>
                    <span class="badge badge-info p-2">
                        <i class="fas fa-tint mr-1"></i> Insumos: <?= count($insumosStockBajo) + count($insumosStockSuficiente) ?>
                    </span>
                    <?php if($totalStockBajo > 0): ?>
                    <span class="badge badge-danger p-2">
                        <i class="fas fa-exclamation-triangle mr-1"></i> Stock bajo: <?= $totalStockBajo ?>
                    </span>
                    <?php endif; ?>
                </div>

                <!-- Productos -->
                <div class="card mb-3">
                    <div class="card-header bg-light py-2">
                        <h5 class="mb-0 text-primary"><i class="fas fa-box mr-2"></i> Productos</h5>
                    </div>
                    <div class="card-body p-3">
                        <?php if(count($productosStockBajo) > 0): ?>
                            <div class="mb-3">
                                <h6 class="text-danger mb-2"><i class="fas fa-exclamation-circle mr-1"></i> Stock Bajo:</h6>
                                <div class="row">
                                    <?php foreach($productosStockBajo as $p): ?>
                                    <div class="col-md-6">
                                        <div class="d-flex justify-content-between align-items-center mb-2 p-2 bg-light rounded">
                                            <span><?= htmlspecialchars($p['nombre']) ?></span>
                                            <span class="badge badge-danger"><?= $p['cantidad'] ?> uds</span>
                                        </div>
                                    </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        <?php endif; ?>
                        
                        <?php if(count($productosStockSuficiente) > 0): ?>
                            <div>
                                <h6 class="text-success mb-2"><i class="fas fa-check-circle mr-1"></i> Stock Suficiente:</h6>
                                <div class="row">
                                    <?php foreach(array_slice($productosStockSuficiente, 0, 8) as $p): ?>
                                    <div class="col-md-6">
                                        <div class="d-flex justify-content-between align-items-center mb-2 p-2 border-bottom">
                                            <span><?= htmlspecialchars($p['nombre']) ?></span>
                                            <span class="badge badge-success"><?= $p['cantidad'] ?> uds</span>
                                        </div>
                                    </div>
                                    <?php endforeach; ?>
                                    <?php if(count($productosStockSuficiente) > 8): ?>
                                    <div class="col-12 text-center text-muted small mt-2">
                                        ... y <?= count($productosStockSuficiente) - 8 ?> productos más
                                    </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Insumos -->
                <div class="card">
                    <div class="card-header bg-light py-2">
                        <h5 class="mb-0 text-info"><i class="fas fa-tint mr-2"></i> Insumos</h5>
                    </div>
                    <div class="card-body p-3">
                        <?php if(count($insumosStockBajo) > 0): ?>
                            <div class="mb-3">
                                <h6 class="text-danger mb-2"><i class="fas fa-exclamation-circle mr-1"></i> Stock Bajo:</h6>
                                <div class="row">
                                    <?php foreach($insumosStockBajo as $i): ?>
                                    <div class="col-md-6">
                                        <div class="d-flex justify-content-between align-items-center mb-2 p-2 bg-light rounded">
                                            <span><?= htmlspecialchars($i['nombre']) ?></span>
                                            <span class="badge badge-danger"><?= $i['cantidad'] ?></span>
                                        </div>
                                    </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        <?php endif; ?>
                        
                        <?php if(count($insumosStockSuficiente) > 0): ?>
                            <div>
                                <h6 class="text-success mb-2"><i class="fas fa-check-circle mr-1"></i> Stock Suficiente:</h6>
                                <div class="row">
                                    <?php foreach(array_slice($insumosStockSuficiente, 0, 8) as $i): ?>
                                    <div class="col-md-6">
                                        <div class="d-flex justify-content-between align-items-center mb-2 p-2 border-bottom">
                                            <span><?= htmlspecialchars($i['nombre']) ?></span>
                                            <span class="badge badge-success"><?= $i['cantidad'] ?></span>
                                        </div>
                                    </div>
                                    <?php endforeach; ?>
                                    <?php if(count($insumosStockSuficiente) > 8): ?>
                                    <div class="col-12 text-center text-muted small mt-2">
                                        ... y <?= count($insumosStockSuficiente) - 8 ?> insumos más
                                    </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <?php if($totalStockBajo == 0 && count($productosStockSuficiente) == 0 && count($insumosStockSuficiente) == 0): ?>
                    <div class="text-center text-muted py-4">
                        <i class="fas fa-box-open fa-3x mb-3"></i>
                        <p class="mb-0">No hay items en el inventario</p>
                    </div>
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
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

<script>
// Guardas para widgets (persistencia)
document.querySelectorAll(".chk-widget").forEach(chk => {
    const id = chk.dataset.id;
    const stored = localStorage.getItem("widget_" + id);
    chk.checked = stored !== "0";
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

// Chart Mix
<?php 
if (!isset($diasSemana)) {
    $diasSemana = ["Lunes","Martes","Miércoles","Jueves","Viernes","Sábado","Domingo"];
}
?>

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

// Plugin para texto central en donut
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

// Donut Documentos
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
});

// ⏰ 30 MINUTOS DE INACTIVIDAD
const TIEMPO_EXPIRACION = 30 * 60 * 1000; // 30 minutos en milisegundos
const TIEMPO_ADVERTENCIA = 29 * 60 * 1000; // 29 minutos (1 minuto antes)
const TIEMPO_ALERTA = 1 * 60 * 1000; // 1 minuto para la alerta

let tiempoInactivo = 0;
let advertenciaMostrada = false;

function reiniciarContador() {
    tiempoInactivo = 0;
    advertenciaMostrada = false;
    console.log('Contador reiniciado por actividad');
}

// Verificar inactividad cada segundo
setInterval(() => {
    tiempoInactivo += 1000;
    
    // Mostrar advertencia a los 29 minutos (falta 1 minuto)
    if (tiempoInactivo >= TIEMPO_ADVERTENCIA && !advertenciaMostrada) {
        advertenciaMostrada = true;
        console.log('Mostrando advertencia de expiración');
        
        Swal.fire({
            icon: 'warning',
            title: 'Sesión por expirar',
            text: 'Tu sesión expirará en 1 minuto por inactividad',
            showCancelButton: true,
            confirmButtonText: 'Seguir aquí',
            cancelButtonText: 'Salir ahora',
            confirmButtonColor: '#28a745',
            cancelButtonColor: '#d33',
            timer: TIEMPO_ALERTA,
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
                        window.location.href = 'login.php?expired=1';
                    });
            } else if (result.dismiss === Swal.DismissReason.timer) {
                window.location.href = 'logout.php';
            } else {
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

// Script para búsqueda en tiempo real 
document.addEventListener('DOMContentLoaded', function() {
    const buscador = document.getElementById('buscadorUsuarios');
    const tabla = document.getElementById('tablaUsuarios');
    const filas = document.querySelectorAll('.fila-usuario');
    const resultadosSpan = document.getElementById('resultadosMostrados');
    const totalRegistros = filas.length;
    
    // Actualizar contador inicial
    document.getElementById('totalRegistros').textContent = totalRegistros;
    actualizarContador();
    
    // Función de búsqueda
    function buscarUsuarios() {
        const texto = buscador.value.toLowerCase().trim();
        
        if(texto === '') {
            // Mostrar todas las filas
            filas.forEach(fila => {
                fila.style.display = '';
            });
        } else {
            // Filtrar filas
            filas.forEach(fila => {
                const nombre = fila.dataset.nombre || '';
                const email = fila.dataset.email || '';
                const rol = fila.dataset.rol || '';
                const estado = fila.dataset.estado || '';
                
                if(nombre.includes(texto) || email.includes(texto) || rol.includes(texto) || estado.includes(texto)) {
                    fila.style.display = '';
                } else {
                    fila.style.display = 'none';
                }
            });
        }
        
        actualizarContador();
    }
    
    // Actualizar contador de resultados
    function actualizarContador() {
        const visibles = document.querySelectorAll('.fila-usuario:not([style*="display: none"])').length;
        resultadosSpan.textContent = visibles;
        
        // Resaltar si no hay resultados
        if(visibles === 0) {
            tabla.classList.add('table-danger');
        } else {
            tabla.classList.remove('table-danger');
        }
    }
    
    // Event listeners
    buscador.addEventListener('keyup', buscarUsuarios);
    
    // Botón limpiar búsqueda
    document.getElementById('limpiarBusqueda').addEventListener('click', function() {
        buscador.value = '';
        buscarUsuarios();
        buscador.focus();
    });
    
    // Atajo de teclado (Ctrl+F para buscar)
    document.addEventListener('keydown', function(e) {
        if(e.ctrlKey && e.key === 'f' && document.getElementById('modalUsuarios').classList.contains('show')) {
            e.preventDefault();
            buscador.focus();
        }
    });
    
    // Inicializar
    buscarUsuarios();
});
</script>