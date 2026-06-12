<?php
require_once 'includes/session.php';
require_once 'includes/db.php';
require_once 'includes/csrf.php';

// Verificar autenticación - SOLO VENDEDOR
if (!isset($_SESSION['usuario_id']) || strtolower($_SESSION['rol'] ?? '') !== 'vendedor') {
    header("Location: login.php");
    exit;
}

$id_vendedor = $_SESSION['usuario_id'];
$nombre_usuario = $_SESSION['nombre'] ?? 'Vendedor';
$nombre_completo = $nombre_usuario;

// Función para detectar género basado en el nombre
function detectarGenero($nombre) {
    $nombresFemeninos = [
        'maria', 'ana', 'laura', 'carmen', 'josefa', 'isabel', 'marta', 'patricia',
        'lucia', 'paula', 'andrea', 'claudia', 'silvia', 'monica', 'cristina',
        'angela', 'rocio', 'beatriz', 'elena', 'irene', 'alicia', 'raquel'
    ];
    
    $nombresMasculinos = [
        'jose', 'juan', 'carlos', 'francisco', 'luis', 'antonio', 'manuel',
        'javier', 'angel', 'david', 'alejandro', 'daniel', 'rafael', 'miguel'
    ];
    
    $nombreLower = strtolower(trim($nombre));
    $partes = explode(' ', $nombreLower);
    $primerNombre = $partes[0];
    
    if (in_array($primerNombre, $nombresFemeninos)) return 'femenino';
    if (in_array($primerNombre, $nombresMasculinos)) return 'masculino';
    if (substr($primerNombre, -1) === 'a') return 'femenino';
    if (substr($primerNombre, -1) === 'o' || substr($primerNombre, -1) === 'e') return 'masculino';
    
    return 'masculino';
}

$genero = detectarGenero($nombre_usuario);
$saludo = ($genero == 'femenino') ? "Bienvenida" : "Bienvenido";

// ===== PROCESAR AJAX PRIMERO (cambio de contraseña) =====
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_cambio_password'])) {
    ob_clean();
    header('Content-Type: application/json');
    
    $response = ['success' => false, 'message' => ''];
    
    try {
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
    
    echo json_encode($response);
    exit;
}

// ===== INCLUDES =====
require_once 'includes/header.php';
require_once 'includes/navbar.php';

// ===== VERIFICAR CAMBIO DE CONTRASEÑA OBLIGATORIO =====
$mostrar_modal_password = (isset($_SESSION['debe_cambiar_password']) && $_SESSION['debe_cambiar_password'] == 1);

// Zona horaria
date_default_timezone_set('America/Mexico_City');
$hoy = date('Y-m-d');
$inicioSemana = date('Y-m-d', strtotime('monday this week'));
$finSemana = date('Y-m-d', strtotime('sunday this week'));

// ==================== CONSULTAS PARA VENDEDOR ====================

// Ventas por día (solo del vendedor)
$ventasPorDia = array_fill(0, 7, 0);
$resVentasDias = $conn->query("
    SELECT DAYOFWEEK(v.fecha_venta) AS dia, 
           SUM(v.cantidad_vendida * p.precio_venta) AS total
    FROM ventas v
    JOIN productos p ON v.id_producto = p.id
    WHERE DATE(v.fecha_venta) BETWEEN '$inicioSemana' AND '$finSemana'
    AND v.id_vendedor = $id_vendedor
    GROUP BY dia
");
if ($resVentasDias) {
    while ($row = $resVentasDias->fetch_assoc()) {
        $dia = intval($row['dia']);
        if ($dia < 1 || $dia > 7) continue;
        $posicion = ($dia == 1) ? 6 : $dia - 2;
        $ventasPorDia[$posicion] = floatval($row['total']);
    }
}

// Ventas totales del día
$resVentasDia = $conn->query("
    SELECT IFNULL(SUM(v.cantidad_vendida * p.precio_venta),0) AS total_dia,
           COUNT(DISTINCT v.id) AS tickets_dia,
           SUM(v.cantidad_vendida) AS unidades_dia
    FROM ventas v
    JOIN productos p ON v.id_producto = p.id
    WHERE DATE(v.fecha_venta) = CURDATE()
    AND v.id_vendedor = $id_vendedor
");
$ventasDia = $resVentasDia->fetch_assoc();
$totalVentasDia = floatval($ventasDia['total_dia'] ?? 0);
$ticketsHoy = intval($ventasDia['tickets_dia'] ?? 0);
$unidadesHoy = intval($ventasDia['unidades_dia'] ?? 0);
$ticketPromedio = $ticketsHoy > 0 ? $totalVentasDia / $ticketsHoy : 0;

// Ventas de la semana
$resVentasSemana = $conn->query("
    SELECT IFNULL(SUM(v.cantidad_vendida * p.precio_venta),0) AS total_semana,
           COUNT(DISTINCT v.id) AS tickets_semana
    FROM ventas v
    JOIN productos p ON v.id_producto = p.id
    WHERE YEARWEEK(v.fecha_venta,1) = YEARWEEK(CURDATE(),1)
    AND v.id_vendedor = $id_vendedor
");
$ventasSemana = $resVentasSemana->fetch_assoc();
$totalVentasSemana = floatval($ventasSemana['total_semana'] ?? 0);
$ticketsSemana = intval($ventasSemana['tickets_semana'] ?? 0);

// Ventas del mes
$resVentasMes = $conn->query("
    SELECT IFNULL(SUM(v.cantidad_vendida * p.precio_venta),0) AS total_mes,
           COUNT(DISTINCT v.id) AS tickets_mes
    FROM ventas v
    JOIN productos p ON v.id_producto = p.id
    WHERE MONTH(v.fecha_venta) = MONTH(CURDATE()) 
    AND YEAR(v.fecha_venta) = YEAR(CURDATE())
    AND v.id_vendedor = $id_vendedor
");
$ventasMes = $resVentasMes->fetch_assoc();
$totalVentasMes = floatval($ventasMes['total_mes'] ?? 0);
$ticketsMes = intval($ventasMes['tickets_mes'] ?? 0);

// Meta mensual (basada en mes anterior +20% o base 5000)
$inicioMesAnterior = date('Y-m-01', strtotime('-1 month'));
$finMesAnterior = date('Y-m-t', strtotime('-1 month'));
$resMesAnterior = $conn->query("
    SELECT IFNULL(SUM(v.cantidad_vendida * p.precio_venta),0) AS total
    FROM ventas v
    JOIN productos p ON v.id_producto = p.id
    WHERE DATE(v.fecha_venta) BETWEEN '$inicioMesAnterior' AND '$finMesAnterior'
    AND v.id_vendedor = $id_vendedor
");
$ventasMesAnterior = floatval($resMesAnterior->fetch_assoc()['total'] ?? 0);
$metaBase = 5000;
$metaMensual = ($ventasMesAnterior > 0) ? $ventasMesAnterior * 1.20 : $metaBase;
$porcentajeMeta = $metaMensual > 0 ? min(100, ($totalVentasMes / $metaMensual) * 100) : 0;

// Predicción fin de mes
$diaActual = (int)date('j');
$diasDelMes = (int)date('t');
$promedioDia = $diaActual > 0 ? $totalVentasMes / $diaActual : 0;
$prediccionFinMes = $promedioDia * $diasDelMes;

// Estado de la meta
if ($porcentajeMeta < 40) {
    $colorMeta = 'danger';
    $estadoMeta = 'Riesgo';
} elseif ($porcentajeMeta < 75) {
    $colorMeta = 'warning';
    $estadoMeta = 'En proceso';
} else {
    $colorMeta = 'success';
    $estadoMeta = 'Buen ritmo';
}

// Utilidad estimada del día (solo vendedor)
$sqlUtilidad = "SELECT SUM((p.precio_venta - p.precio_compra) * v.cantidad_vendida) AS utilidadHoy
                FROM ventas v
                INNER JOIN productos p ON p.id = v.id_producto
                WHERE DATE(v.fecha_venta) = CURDATE()
                AND v.id_vendedor = $id_vendedor";
$resultUtilidad = $conn->query($sqlUtilidad);
$utilidadHoy = floatval($resultUtilidad->fetch_assoc()['utilidadHoy'] ?? 0);

// Top productos del vendedor
$topProductos = [];
$resTop = $conn->query("
    SELECT p.nombre, SUM(v.cantidad_vendida) AS total_vendido,
           SUM(v.cantidad_vendida * p.precio_venta) AS total_ingreso
    FROM ventas v
    JOIN productos p ON v.id_producto = p.id
    WHERE v.id_vendedor = $id_vendedor
    GROUP BY p.id
    ORDER BY total_vendido DESC
    LIMIT 5
");
if ($resTop) {
    while ($row = $resTop->fetch_assoc()) {
        $topProductos[] = $row;
    }
}

// Productos con stock bajo (independiente del vendedor, es global)
$itemsStockBajo = [];
$resItems = $conn->query("SELECT id, nombre, cantidad, tipo_inventario FROM productos WHERE activo = 1 ORDER BY cantidad ASC");
if ($resItems) {
    while ($item = $resItems->fetch_assoc()) {
        $item['cantidad'] = floatval($item['cantidad']);
        $tipo = $item['tipo_inventario'];
        $cantidad = $item['cantidad'];
        $umbralStockBajo = ($tipo === 'insumo') ? 2.0 : 20;
        
        if ($cantidad < $umbralStockBajo) {
            $itemsStockBajo[] = $item;
        }
    }
}
$totalStockBajo = count($itemsStockBajo);

// Clientes frecuentes del vendedor
$clientesFrecuentes = [];
$resClientes = $conn->query("
    SELECT 
        v.correo_cliente AS email,
        COUNT(DISTINCT v.id) AS total_compras,
        SUM(v.cantidad_vendida * p.precio_venta) AS monto_gastado,
        MAX(v.fecha_venta) AS ultima_compra
    FROM ventas v
    JOIN productos p ON v.id_producto = p.id
    WHERE v.correo_cliente IS NOT NULL 
    AND v.correo_cliente != ''
    AND v.id_vendedor = $id_vendedor
    GROUP BY v.correo_cliente
    ORDER BY monto_gastado DESC
    LIMIT 5
");
if ($resClientes) {
    while ($row = $resClientes->fetch_assoc()) {
        $clientesFrecuentes[] = $row;
    }
}
$totalClientes = count($clientesFrecuentes);

// Días desde última venta
$resUltimaVenta = $conn->query("
    SELECT MAX(fecha_venta) AS ultima 
    FROM ventas 
    WHERE id_vendedor = $id_vendedor
");
$ultimaVenta = $resUltimaVenta->fetch_assoc();
$diasSinVender = $ultimaVenta['ultima'] ? (new DateTime($ultimaVenta['ultima']))->diff(new DateTime())->days : 'N/A';

// Ventas por día de la semana para el modal (datos completos)
$ventasPorDiaSemana = [];
$diasSemana = ['Lunes', 'Martes', 'Miércoles', 'Jueves', 'Viernes', 'Sábado', 'Domingo'];
for ($i = 0; $i < 7; $i++) {
    $ventasPorDiaSemana[] = ['dia' => $diasSemana[$i], 'total' => $ventasPorDia[$i]];
}

// Obtener imagen y título del dashboard desde configuración
$result_config = $conn->query("
    SELECT nombre, logo, imagen_dashboard
    FROM configuracion_galeria
    WHERE id = 1
");

$config_data = $result_config->fetch_assoc();

$nombre_tienda = $config_data['nombre'] ?? 'Mi Tienda';
$logo_tienda   = $config_data['logo'] ?? '';

$imagen_dashboard = !empty($config_data['imagen_dashboard']) &&
                    file_exists($config_data['imagen_dashboard'])
    ? $config_data['imagen_dashboard']
    : '';

// Ventas recientes para el modal de hoy
$detalleVentasHoy = [];
$resDetalleHoy = $conn->query("
    SELECT p.nombre, v.cantidad_vendida, (v.cantidad_vendida * p.precio_venta) AS total, v.fecha_venta
    FROM ventas v
    JOIN productos p ON v.id_producto = p.id
    WHERE DATE(v.fecha_venta) = CURDATE()
    AND v.id_vendedor = $id_vendedor
    ORDER BY v.fecha_venta DESC
    LIMIT 100
");
if ($resDetalleHoy) {
    while ($row = $resDetalleHoy->fetch_assoc()) {
        $detalleVentasHoy[] = $row;
    }
}

// Top productos semana
$topProductosSemana = [];
$resTopSemana = $conn->query("
    SELECT p.nombre, SUM(v.cantidad_vendida) AS cantidad, 
           SUM(v.cantidad_vendida * p.precio_venta) AS total
    FROM ventas v
    JOIN productos p ON v.id_producto = p.id
    WHERE DATE(v.fecha_venta) BETWEEN '$inicioSemana' AND '$finSemana'
    AND v.id_vendedor = $id_vendedor
    GROUP BY p.id
    ORDER BY total DESC
    LIMIT 20
");
if ($resTopSemana) {
    while ($row = $resTopSemana->fetch_assoc()) {
        $topProductosSemana[] = $row;
    }
}

// Top productos mes
$topProductosMes = [];
$resTopMes = $conn->query("
    SELECT p.nombre, SUM(v.cantidad_vendida) AS cantidad,
           SUM(v.cantidad_vendida * p.precio_venta) AS total
    FROM ventas v
    JOIN productos p ON v.id_producto = p.id
    WHERE MONTH(v.fecha_venta) = MONTH(CURDATE()) 
    AND YEAR(v.fecha_venta) = YEAR(CURDATE())
    AND v.id_vendedor = $id_vendedor
    GROUP BY p.id
    ORDER BY total DESC
    LIMIT 20
");
if ($resTopMes) {
    while ($row = $resTopMes->fetch_assoc()) {
        $topProductosMes[] = $row;
    }
}

// Ventas por semana del mes
$ventasPorSemanaMes = [];
$resSemanas = $conn->query("
    SELECT WEEK(v.fecha_venta, 1) - WEEK(DATE_FORMAT(v.fecha_venta, '%Y-%m-01'), 1) + 1 AS semana_num,
           SUM(v.cantidad_vendida * p.precio_venta) AS total
    FROM ventas v
    JOIN productos p ON v.id_producto = p.id
    WHERE MONTH(v.fecha_venta) = MONTH(CURDATE()) 
    AND YEAR(v.fecha_venta) = YEAR(CURDATE())
    AND v.id_vendedor = $id_vendedor
    GROUP BY semana_num
    ORDER BY semana_num
");
if ($resSemanas) {
    while ($row = $resSemanas->fetch_assoc()) {
        $ventasPorSemanaMes[] = $row;
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>PescaVentas - Dashboard Vendedor</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    
    <!-- Google Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    
    <!-- Bootstrap 5 -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <!-- Chart.js -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    
    <!-- SweetAlert -->
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    
</head>
<body>

<link rel="stylesheet" href="css/dashboard_vendedor.css">

<div class="content-wrapper <?= $mostrar_modal_password ? 'modal-active' : '' ?>">
    
    <!-- MODAL CAMBIO CONTRASEÑA -->
    <?php if ($mostrar_modal_password): ?>
    <div id="modalCambiarPassword" style="position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.85) !important; backdrop-filter: blur(4px); z-index: 99999; display: flex; align-items: center; justify-content: center;">
        <div class="modal-dialog" style="max-width: 450px; width: 90%; margin: 0;">
            <div class="modal-content" style="background: white; border-radius: 20px; overflow: hidden; box-shadow: 0 20px 40px rgba(0,0,0,0.3);">
                <div class="modal-header" style="background: linear-gradient(135deg, #f97316, #ea580c); border: none; padding: 18px 24px;">
                    <h5 class="modal-title" style="color: white; margin: 0; font-size: 1.1rem; font-weight: 600;">
                        <i class="fas fa-shield-alt me-2"></i> Cambiar Contraseña
                    </h5>
                </div>
                <div class="modal-body" style="background: white; padding: 24px;">
                    <div class="alert alert-warning py-2" style="background: #fef3c7; border-left: 4px solid #f59e0b; border-radius: 12px; padding: 12px 16px; margin-bottom: 20px;">
                        <small style="color: #92400e;"><i class="fas fa-exclamation-triangle me-2"></i> Cambia tu contraseña por defecto <strong>"Pescadores1"</strong></small>
                    </div>
                    <div id="error-mensaje" class="alert alert-danger py-2" style="display: none; border-radius: 12px; font-size: 0.75rem; padding: 10px 14px;"></div>
                    
                    <form id="formCambiarPassword">
                        <input type="hidden" name="csrf_token" value="<?= csrf_token(); ?>">
                        <input type="hidden" name="ajax_cambio_password" value="1">
                        
                        <div class="mb-3">
                            <label class="form-label" style="font-weight: 600; font-size: 0.75rem; color: #374151; margin-bottom: 6px;">Nueva Contraseña</label>
                            <div class="input-group">
                                <input type="password" class="form-control" id="password_nueva" name="password_nueva" style="border-radius: 12px; border: 1px solid #e2e8f0; padding: 10px 14px;">
                                <button class="btn btn-outline-secondary" type="button" onclick="togglePasswordField('password_nueva', this)" style="border-radius: 0 12px 12px 0;">
                                    <i class="fas fa-eye"></i>
                                </button>
                            </div>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label" style="font-weight: 600; font-size: 0.75rem; color: #374151; margin-bottom: 6px;">Confirmar Contraseña</label>
                            <div class="input-group">
                                <input type="password" class="form-control" id="password_confirmar" name="password_confirmar" style="border-radius: 12px; border: 1px solid #e2e8f0; padding: 10px 14px;">
                                <button class="btn btn-outline-secondary" type="button" onclick="togglePasswordField('password_confirmar', this)" style="border-radius: 0 12px 12px 0;">
                                    <i class="fas fa-eye"></i>
                                </button>
                            </div>
                        </div>
                        
                        <button type="submit" class="btn w-100" id="btnCambiar" style="background: linear-gradient(135deg, #f97316, #ea580c); border: none; border-radius: 12px; padding: 12px; font-weight: 600; color: white;">
                            <i class="fas fa-sync-alt me-2"></i> Cambiar Contraseña
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- HERO SECTION -->
    <div class="hero-premium" <?= $imagen_dashboard ? "style='background-image: url(\"$imagen_dashboard?v=".time()."\"); background-size: cover; background-position: center;'" : '' ?>>
        <div class="hero-overlay"></div>
        <div class="hero-content text-center">
            <div class="hero-badge">
                <i class="fas fa-fish"></i>
                <span><?= htmlspecialchars($nombre_tienda) ?></span>
            </div>
            <div class="hero-text">
                <span class="hero-hello"><?= $saludo ?></span>
                <span class="hero-user"><?= htmlspecialchars($nombre_completo) ?></span>
            </div>
            <div class="hero-cta" onclick="window.location.href='ventas.php'">
                <i class="fas fa-hand-peace"></i>
                <span>Registrar nueva venta</span>
            </div>
        </div>
    </div>

    <div class="container-fluid px-4"> 
        <!-- ACCIONES RÁPIDAS -->
        <div class="white-container">
            <div class="section-header">
                <div class="section-title">
                    <i class="fas fa-bolt"></i>
                    <span>Acciones Rápidas</span>
                </div>
                <div class="section-divider"></div>
            </div>
            <div class="d-flex flex-wrap justify-content-center gap-3">
                <div class="action-card" onclick="window.location.href='ventas.php'" style="width: 170px;">
                    <div class="action-icon mx-auto"><i class="fas fa-cash-register"></i></div>
                    <h4>Realizar Venta</h4>
                    <p>Registrar nueva venta</p>
                </div>
                <div class="action-card" onclick="window.location.href='inventario.php'" style="width: 170px;">
                    <div class="action-icon mx-auto"><i class="fas fa-boxes"></i></div>
                    <h4>Inventario</h4>
                    <p>Ver catálogo</p>
                </div>
                <div class="action-card" onclick="window.location.href='historial_ventas.php'" style="width: 170px;">
                    <div class="action-icon mx-auto"><i class="fas fa-chart-line"></i></div>
                    <h4>Mis Ventas</h4>
                    <p>Ver historial</p>
                </div>
                <div class="action-card" onclick="window.location.href='mi_perfil.php'" style="width: 170px;">
                    <div class="action-icon mx-auto"><i class="fas fa-user-cog"></i></div>
                    <h4>Mi Perfil</h4>
                    <p>Configuración</p>
                </div>
                <div class="action-card" onclick="window.location.href='reportes_vendedor.php'" style="width: 170px;">
                    <div class="action-icon mx-auto"><i class="fas fa-file-alt"></i></div>
                    <h4>Reportes</h4>
                    <p>Mis estadísticas</p>
                </div>
            </div>
        </div>

        <!-- MÉTRICAS CLAVE -->
        <div class="section-header">
            <div class="section-title">
                <i class="fas fa-chart-simple"></i>
                <span>Mis Métricas</span>
            </div>
            <div class="section-divider"></div>
        </div>
        
        <div class="row g-4 mb-4">
            <!-- Ventas Hoy -->
            <div class="col-md-6 col-lg-3">
                <div class="metric-card bg-metric-orange" data-bs-toggle="modal" data-bs-target="#modalVentasHoy">
                    <div class="metric-icon-bg"><i class="fas fa-calendar-day"></i></div>
                    <h3>$<?= number_format($totalVentasDia, 2) ?></h3>
                    <p>Ventas Hoy</p>
                    <div class="metric-footer">
                        <i class="fas fa-ticket-alt me-1"></i> <?= $ticketsHoy ?> tickets • 
                        <i class="fas fa-box me-1"></i> <?= $unidadesHoy ?> uds
                    </div>
                </div>
            </div>
            
            <!-- Ventas Semana -->
            <div class="col-md-6 col-lg-3">
                <div class="metric-card bg-metric-blue" data-bs-toggle="modal" data-bs-target="#modalVentasSemana">
                    <div class="metric-icon-bg"><i class="fas fa-calendar-week"></i></div>
                    <h3>$<?= number_format($totalVentasSemana, 2) ?></h3>
                    <p>Ventas Semana</p>
                    <div class="metric-footer">
                        <i class="fas fa-ticket-alt me-1"></i> <?= $ticketsSemana ?> tickets
                    </div>
                </div>
            </div>
            
            <!-- Ventas Mes con Meta -->
            <div class="col-md-6 col-lg-3">
                <div class="metric-card bg-metric-teal" data-bs-toggle="modal" data-bs-target="#modalVentasMes">
                    <div class="metric-icon-bg"><i class="fas fa-calendar-alt"></i></div>
                    <h3>$<?= number_format($totalVentasMes, 2) ?></h3>
                    <p>Ventas del Mes</p>
                    <div class="metric-footer">
                        <i class="fas fa-bullseye me-1"></i> Meta: $<?= number_format($metaMensual, 0) ?>
                    </div>
                </div>
            </div>
            
            <!-- Utilidad Hoy -->
            <div class="col-md-6 col-lg-3">
                <div class="metric-card bg-metric-green">
                    <div class="metric-icon-bg"><i class="fas fa-wallet"></i></div>
                    <h3>$<?= number_format($utilidadHoy, 2) ?></h3>
                    <p>Utilidad Estimada Hoy</p>
                    <div class="metric-footer">
                        <i class="fas fa-percentage me-1"></i> 
                        <?= $totalVentasDia > 0 ? number_format(($utilidadHoy / $totalVentasDia) * 100, 1) : 0 ?>% margen
                    </div>
                </div>
            </div>
        </div>

        <!-- ANÁLISIS DE VENTAS -->
        <div class="section-header">
            <div class="section-title">
                <i class="fas fa-chart-line"></i>
                <span>Análisis de Ventas</span>
            </div>
            <div class="section-divider"></div>
        </div>
        
        <div class="row g-4">
            <!-- Gráfico de Ventas Semanales -->
            <div class="col-lg-8">
                <div class="chart-card" style="height: 100%; display: flex; flex-direction: column;">
                    <div class="chart-header">
                        <div class="chart-title">
                            <i class="fas fa-chart-line"></i>
                            <span>Ventas - Últimos 7 días</span>
                        </div>
                        <div class="d-flex gap-3">
                            <small class="text-muted">Promedio: $<?= number_format($totalVentasSemana / 7, 2) ?></small>
                            <small class="text-muted">Ticket: $<?= number_format($ticketPromedio, 2) ?></small>
                        </div>
                    </div>
                    <div style="flex: 1; position: relative; min-height: 300px;">
                        <canvas id="chartVentas" style="position: absolute; top: 0; left: 0; width: 100%; height: 100%;"></canvas>
                    </div>
                </div>
            </div>
            
            <!-- Meta Mensual y Progreso -->
            <div class="col-lg-4">
                <div class="chart-card" style="height: 100%;">
                    <div class="chart-title mb-3">
                        <i class="fas fa-bullseye"></i>
                        <span>Meta Mensual</span>
                    </div>
                    <div class="text-center mb-3">
                        <div class="row">
                            <div class="col-6">
                                <small class="text-muted">Meta</small>
                                <h5 class="text-primary fw-bold">$<?= number_format($metaMensual, 2) ?></h5>
                            </div>
                            <div class="col-6">
                                <small class="text-muted">Alcanzado</small>
                                <h5 class="text-success fw-bold">$<?= number_format($totalVentasMes, 2) ?></h5>
                            </div>
                        </div>
                    </div>
                    <div class="progress mb-2" style="height: 12px; border-radius: 10px;">
                        <div class="progress-bar bg-<?= $colorMeta ?> progress-bar-striped progress-bar-animated" 
                             style="width: <?= round($porcentajeMeta) ?>%; border-radius: 10px;">
                            <?= round($porcentajeMeta) ?>%
                        </div>
                    </div>
                    <div class="d-flex justify-content-between small text-muted">
                        <span>Estado: <strong class="text-<?= $colorMeta ?>"><?= $estadoMeta ?></strong></span>
                        <span>Predicción: $<?= number_format($prediccionFinMes, 2) ?></span>
                    </div>
                    <hr class="my-3">
                    <div class="small text-muted">
                        <i class="fas fa-chart-line me-1"></i>
                        <?php if ($ventasMesAnterior > 0): ?>
                            Meta basada en mes anterior +20%
                        <?php else: ?>
                            Meta base aplicada (sin historial previo)
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- TOP PRODUCTOS Y STOCK CRÍTICO -->
        <div class="row g-4 mt-2">
            <div class="col-lg-6">
                <div class="modern-stats-card" style="height: 100%;">
                    <div class="stats-section">
                        <div class="stats-section-header">
                            <div class="stats-section-icon"><i class="fas fa-trophy"></i></div>
                            <h3 class="stats-section-title">Mis Top Productos</h3>
                            <span class="stats-section-badge">Más vendidos</span>
                        </div>
                        <?php if(count($topProductos) > 0): ?>
                            <div class="top-products-list">
                                <?php foreach($topProductos as $index => $p): ?>
                                    <div class="top-product-card">
                                        <div class="top-product-info">
                                            <div class="top-product-rank <?= $index == 0 ? 'rank-1' : ($index == 1 ? 'rank-2' : ($index == 2 ? 'rank-3' : '')) ?>">
                                                <?= $index + 1 ?>
                                            </div>
                                            <div class="top-product-name"><?= htmlspecialchars($p['nombre']) ?></div>
                                        </div>
                                        <div class="top-product-stats">
                                            <span class="top-product-quantity"><?= intval($p['total_vendido']) ?></span>
                                            <span class="top-product-unit">uds</span>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php else: ?>
                            <div class="empty-state text-center py-4">
                                <i class="fas fa-chart-simple fa-2x text-muted mb-2"></i>
                                <p class="text-muted mb-0">Aún no has realizado ventas</p>
                            </div>
                        <?php endif; ?>
                    </div>
                    
                    <div class="section-separator">
                        <span class="separator-dot"></span>
                        <span class="separator-line"></span>
                        <span class="separator-dot"></span>
                    </div>
                    
                    <div class="stats-section" style="flex: 1; display: flex; flex-direction: column; min-height: 0;">
                        <div class="stats-section-header">
                            <div class="stats-section-icon warning"><i class="fas fa-exclamation-triangle"></i></div>
                            <h3 class="stats-section-title">Stock Crítico</h3>
                            <span class="stats-section-badge danger"><?= $totalStockBajo ?> items</span>
                        </div>
                        <?php if($totalStockBajo > 0): ?>
                            <div class="stock-list" style="flex: 1; overflow-y: auto; min-height: 0;">
                                <?php $contador = 0; foreach($itemsStockBajo as $item): if($contador >= 3) break; $contador++; ?>
                                    <div class="stock-card">
                                        <div class="stock-info">
                                            <div class="stock-icon <?= $item['tipo_inventario'] === 'insumo' ? 'insumo' : 'producto' ?>">
                                                <i class="fas <?= $item['tipo_inventario'] === 'insumo' ? 'fa-tint' : 'fa-box' ?>"></i>
                                            </div>
                                            <div class="stock-details">
                                                <div class="stock-name"><?= htmlspecialchars($item['nombre']) ?></div>
                                                <div class="stock-type"><?= $item['tipo_inventario'] === 'insumo' ? 'Insumo' : 'Producto' ?></div>
                                            </div>
                                        </div>
                                        <div class="stock-status">
                                            <div class="stock-quantity <?= $item['cantidad'] == 0 ? 'critical' : ($item['cantidad'] < 5 ? 'low' : 'warning') ?>">
                                                <?= $item['cantidad'] ?> <span>uds</span>
                                            </div>
                                            <div class="stock-progress">
                                                <?php $stockPercent = min(100, ($item['cantidad'] / ($item['tipo_inventario'] === 'insumo' ? 2 : 20)) * 100); ?>
                                                <div class="stock-progress-bar" style="width: <?= $stockPercent ?>%"></div>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                                <?php if($totalStockBajo > 3): ?>
                                    <div class="more-items text-center py-2">
                                        <i class="fas fa-ellipsis-h"></i>
                                        <span class="ms-1"><?= $totalStockBajo - 3 ?> items más</span>
                                    </div>
                                <?php endif; ?>
                            </div>
                        <?php else: ?>
                            <div class="empty-state success text-center py-4">
                                <i class="fas fa-check-circle fa-2x text-success mb-2"></i>
                                <p class="text-muted mb-0">No hay items con stock bajo</p>
                            </div>
                        <?php endif; ?>
                        <button class="btn-view-all" style="flex-shrink: 0; margin-top: 0.75rem;" data-bs-toggle="modal" data-bs-target="#modalStockGeneral">
                            <i class="fas fa-eye"></i><span>Ver todos los items</span><i class="fas fa-arrow-right"></i>
                        </button>
                    </div>
                </div>
            </div>
            
            <!-- Clientes Frecuentes -->
            <div class="col-lg-6">
                <div class="modern-stats-card" style="height: 100%;">
                    <div class="stats-section-header">
                        <div class="stats-section-icon"><i class="fas fa-users"></i></div>
                        <h3 class="stats-section-title">Mis Clientes Frecuentes</h3>
                        <span class="stats-section-badge">Top <?= count($clientesFrecuentes) ?></span>
                    </div>
                    <?php if(count($clientesFrecuentes) > 0): ?>
                        <div class="top-products-list" style="max-height: 280px;">
                            <?php foreach($clientesFrecuentes as $index => $c): ?>
                                <div class="top-product-card">
                                    <div class="top-product-info">
                                        <div class="top-product-rank <?= $index == 0 ? 'rank-1' : ($index == 1 ? 'rank-2' : ($index == 2 ? 'rank-3' : '')) ?>">
                                            <?= $index + 1 ?>
                                        </div>
                                        <div class="top-product-name" style="max-width: 180px; overflow: hidden; text-overflow: ellipsis;">
                                            <?= htmlspecialchars($c['email']) ?>
                                        </div>
                                    </div>
                                    <div class="top-product-stats">
                                        <span class="top-product-quantity">$<?= number_format($c['monto_gastado'], 0) ?></span>
                                        <span class="top-product-unit"><?= $c['total_compras'] ?> compras</span>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        <?php if($diasSinVender !== 'N/A' && $diasSinVender > 3): ?>
                            <div class="alert alert-warning mt-3 mb-0 py-2 small">
                                <i class="fas fa-clock me-2"></i>
                                Última venta hace <strong><?= $diasSinVender ?> días</strong>
                            </div>
                        <?php endif; ?>
                    <?php else: ?>
                        <div class="empty-state text-center py-5">
                            <i class="fas fa-users fa-3x text-muted mb-3"></i>
                            <p class="text-muted mb-0">Aún no tienes clientes registrados</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- ==================== MODALES ==================== -->

<!-- Modal Ventas Hoy -->
<div class="modal fade" id="modalVentasHoy" tabindex="-1">
    <div class="modal-dialog modal-xl">
        <div class="modal-content border-0 shadow-lg">
            <div class="modal-header" style="background: linear-gradient(135deg, #f97316, #ea580c); border-bottom: none;">
                <div class="d-flex align-items-center">
                    <div class="rounded-circle bg-white bg-opacity-25 p-2 me-3">
                        <i class="fas fa-calendar-day fa-fw text-white"></i>
                    </div>
                    <div>
                        <h5 class="modal-title text-white fw-bold mb-0">Mis Ventas del Día</h5>
                        <p class="text-white-50 small mb-0"><?= date('l, d/m/Y') ?></p>
                    </div>
                </div>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-4">
                <!-- Tarjetas de resumen -->
                <div class="row g-3 mb-4">
                    <div class="col-md-4">
                        <div class="card border-0 shadow-sm h-100">
                            <div class="card-body">
                                <div class="d-flex justify-content-between align-items-start">
                                    <div>
                                        <p class="text-muted small mb-1">Total Ventas</p>
                                        <h3 class="fw-bold text-primary mb-0">$<?= number_format($totalVentasDia, 2) ?></h3>
                                    </div>
                                    <div class="bg-primary bg-opacity-10 rounded p-2"><i class="fas fa-chart-line fa-lg text-primary"></i></div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="card border-0 shadow-sm h-100">
                            <div class="card-body">
                                <div class="d-flex justify-content-between align-items-start">
                                    <div>
                                        <p class="text-muted small mb-1">Tickets</p>
                                        <h3 class="fw-bold text-success mb-0"><?= $ticketsHoy ?></h3>
                                    </div>
                                    <div class="bg-success bg-opacity-10 rounded p-2"><i class="fas fa-ticket-alt fa-lg text-success"></i></div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="card border-0 shadow-sm h-100">
                            <div class="card-body">
                                <div class="d-flex justify-content-between align-items-start">
                                    <div>
                                        <p class="text-muted small mb-1">Ticket Promedio</p>
                                        <h3 class="fw-bold text-info mb-0">$<?= number_format($ticketPromedio, 2) ?></h3>
                                    </div>
                                    <div class="bg-info bg-opacity-10 rounded p-2"><i class="fas fa-receipt fa-lg text-info"></i></div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Buscador en tiempo real -->
                <div class="mb-3">
                    <div class="input-group shadow-sm">
                        <span class="input-group-text bg-white border-end-0"><i class="fas fa-search text-muted"></i></span>
                        <input type="text" id="searchHoy" class="form-control border-start-0" placeholder="Buscar producto en tiempo real...">
                        <button class="btn btn-outline-secondary" type="button" id="clearSearchHoy" style="display: none;">
                            <i class="fas fa-times"></i>
                        </button>
                    </div>
                </div>
                
                <!-- Tabla con paginación -->
                <div class="table-responsive">
                    <table class="table table-hover align-middle" id="tablaVentasHoy">
                        <thead class="table-light">
                            <tr>
                                <th class="py-3">Producto</th>
                                <th class="text-center py-3" width="120">Cantidad</th>
                                <th class="text-end py-3" width="150">Total</th>
                            </tr>
                        </thead>
                        <tbody id="tbodyVentasHoy">
                            <?php foreach($detalleVentasHoy as $v): ?>
                                <tr class="venta-row" data-nombre="<?= strtolower(htmlspecialchars($v['nombre'])) ?>">
                                    <td class="fw-medium"><?= htmlspecialchars($v['nombre']) ?></td>
                                    <td class="text-center"><span class="badge bg-secondary bg-opacity-10 text-dark px-3 py-2"><?= $v['cantidad_vendida'] ?> uds</span></td>
                                    <td class="text-end fw-bold text-primary">$<?= number_format($v['total'], 2) ?></td>
                                </tr>
                            <?php endforeach; ?>
                            <?php if(empty($detalleVentasHoy)): ?>
                                <tr class="no-data-row">
                                    <td colspan="3" class="text-center py-5">
                                        <i class="fas fa-chart-line fa-3x text-muted mb-3 d-block"></i>
                                        <p class="text-muted mb-0">No hay ventas registradas hoy</p>
                                        <small class="text-muted">Registra tu primera venta del día</small>
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
                <div id="noResultsHoy" class="text-center py-5" style="display: none;"></div>
                
                <!-- Paginación -->
                <div class="pagination-container" id="paginationHoy" style="display: none;">
                    <div class="pagination-info">
                        Mostrando <span id="hoyStart">0</span> - <span id="hoyEnd">0</span> de <span id="hoyTotal">0</span> productos
                    </div>
                    <div class="pagination-controls">
                        <button class="pagination-btn" id="prevPageHoy" disabled>◀ Anterior</button>
                        <div class="pagination-numbers" id="paginationNumbersHoy"></div>
                        <button class="pagination-btn" id="nextPageHoy" disabled>Siguiente ▶</button>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Modal Ventas Semana -->
<div class="modal fade" id="modalVentasSemana" tabindex="-1">
    <div class="modal-dialog modal-xl">
        <div class="modal-content border-0 shadow-lg">
            <div class="modal-header" style="background: linear-gradient(135deg, #3b82f6, #2563eb); border-bottom: none;">
                <div class="d-flex align-items-center">
                    <div class="rounded-circle bg-white bg-opacity-25 p-2 me-3"><i class="fas fa-calendar-week fa-fw text-white"></i></div>
                    <div><h5 class="modal-title text-white fw-bold mb-0">Mis Ventas de la Semana</h5><p class="text-white-50 small mb-0"><?= date('d/m/Y', strtotime('monday this week')) ?> - <?= date('d/m/Y', strtotime('sunday this week')) ?></p></div>
                </div>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-4">
                <div class="row g-3 mb-4">
                    <div class="col-md-6"><div class="card border-0 shadow-sm"><div class="card-body"><div class="d-flex justify-content-between align-items-start"><div><p class="text-muted small mb-1">Total Semana</p><h3 class="fw-bold text-primary mb-0">$<?= number_format($totalVentasSemana, 2) ?></h3></div><div class="bg-primary bg-opacity-10 rounded p-2"><i class="fas fa-chart-line fa-lg text-primary"></i></div></div></div></div></div>
                    <div class="col-md-6"><div class="card border-0 shadow-sm"><div class="card-body"><div class="d-flex justify-content-between align-items-start"><div><p class="text-muted small mb-1">Promedio Diario</p><h3 class="fw-bold text-info mb-0">$<?= number_format($totalVentasSemana / 7, 2) ?></h3></div><div class="bg-info bg-opacity-10 rounded p-2"><i class="fas fa-chart-bar fa-lg text-info"></i></div></div></div></div></div>
                </div>
                
                <!-- Gráfica -->
                <div class="mb-4" style="background: #f8fafc; border-radius: 16px; padding: 20px;">
                    <div class="text-center mb-3">
                        <h6 class="fw-bold mb-0"><i class="fas fa-chart-bar me-2 text-primary"></i>Ventas por día (Lunes a Domingo)</h6>
                        <small class="text-muted">Montos en pesos mexicanos</small>
                    </div>
                    <div style="display: flex; justify-content: center; align-items: center;">
                        <div style="width: 100%; max-width: 800px; height: 350px;">
                            <canvas id="chartSemanaModal" style="display: block; width: 100% !important; height: 100% !important;"></canvas>
                        </div>
                    </div>
                </div>
                
                <!-- Buscador en tiempo real -->
                <div class="mb-3">
                    <div class="input-group shadow-sm">
                        <span class="input-group-text bg-white border-end-0"><i class="fas fa-search text-muted"></i></span>
                        <input type="text" id="searchSemana" class="form-control border-start-0" placeholder="Buscar producto en tiempo real...">
                        <button class="btn btn-outline-secondary" type="button" id="clearSearchSemana" style="display: none;">
                            <i class="fas fa-times"></i>
                        </button>
                    </div>
                </div>
                
                <!-- Tabla con paginación -->
                <div class="table-responsive" style="max-height: 400px; overflow-y: auto;">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-light" style="position: sticky; top: 0; z-index: 10;">
                            <tr>
                                <th class="py-3">Producto</th>
                                <th class="text-center py-3" width="120">Cantidad</th>
                                <th class="text-end py-3" width="150">Total</th>
                            </tr>
                        </thead>
                        <tbody id="tbodyTopSemana">
                            <?php if (!empty($topProductosSemana)): ?>
                                <?php foreach($topProductosSemana as $item): ?>
                                    <tr class="producto-semana-row" data-nombre="<?= strtolower(htmlspecialchars($item['nombre'])) ?>">
                                        <td class="fw-medium"><?= htmlspecialchars($item['nombre']) ?></td>
                                        <td class="text-center">
                                            <span class="badge bg-secondary bg-opacity-10 text-dark px-3 py-2">
                                                <?= $item['cantidad'] ?> uds
                                            </span>
                                        </td>
                                        <td class="text-end fw-bold text-primary">
                                            $<?= number_format($item['total'], 2) ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr class="no-data-row">
                                    <td colspan="3" class="text-center py-5">
                                        <i class="fas fa-chart-line fa-3x text-muted mb-3 d-block"></i>
                                        <p class="text-muted mb-0">No hay ventas registradas esta semana</p>
                                        <small class="text-muted">Realiza tu primera venta para ver estadísticas</small>
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
                <div id="noResultsSemana" class="text-center py-5" style="display: none;"></div>
                
                <!-- Paginación -->
                <div class="pagination-container" id="paginationSemana" style="display: none;">
                    <div class="pagination-info">
                        Mostrando <span id="semanaStart">0</span> - <span id="semanaEnd">0</span> de <span id="semanaTotal">0</span> productos
                    </div>
                    <div class="pagination-controls">
                        <button class="pagination-btn" id="prevPageSemana" disabled>◀ Anterior</button>
                        <div class="pagination-numbers" id="paginationNumbersSemana"></div>
                        <button class="pagination-btn" id="nextPageSemana" disabled>Siguiente ▶</button>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Modal Ventas Mes -->
<div class="modal fade" id="modalVentasMes" tabindex="-1">
    <div class="modal-dialog modal-xl">
        <div class="modal-content border-0 shadow-lg">
            <div class="modal-header" style="background: linear-gradient(135deg, #14b8a6, #0d9488); border-bottom: none;">
                <div class="d-flex align-items-center">
                    <div class="rounded-circle bg-white bg-opacity-25 p-2 me-3"><i class="fas fa-calendar-alt fa-fw text-white"></i></div>
                    <div><h5 class="modal-title text-white fw-bold mb-0">Mis Ventas del Mes</h5><p class="text-white-50 small mb-0"><?= date('F Y') ?></p></div>
                </div>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-4">
                <!-- Tarjetas de resumen -->
                <div class="row g-3 mb-4">
                    <div class="col-md-4">
                        <div class="card border-0 shadow-sm h-100">
                            <div class="card-body">
                                <div class="d-flex justify-content-between align-items-start">
                                    <div>
                                        <p class="text-muted small mb-1">Total del Mes</p>
                                        <h3 class="fw-bold text-primary mb-0">$<?= number_format($totalVentasMes, 2) ?></h3>
                                    </div>
                                    <div class="bg-primary bg-opacity-10 rounded p-2"><i class="fas fa-chart-line fa-lg text-primary"></i></div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="card border-0 shadow-sm h-100">
                            <div class="card-body">
                                <div class="d-flex justify-content-between align-items-start">
                                    <div>
                                        <p class="text-muted small mb-1">Promedio Diario</p>
                                        <h3 class="fw-bold text-info mb-0">$<?= number_format($totalVentasMes / max(1, date('t')), 2) ?></h3>
                                    </div>
                                    <div class="bg-info bg-opacity-10 rounded p-2"><i class="fas fa-chart-bar fa-lg text-info"></i></div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="card border-0 shadow-sm h-100">
                            <div class="card-body">
                                <div class="d-flex justify-content-between align-items-start">
                                    <div>
                                        <p class="text-muted small mb-1">Proyección</p>
                                        <h3 class="fw-bold text-success mb-0">$<?= number_format(($totalVentasMes / max(1, date('j'))) * date('t'), 2) ?></h3>
                                    </div>
                                    <div class="bg-success bg-opacity-10 rounded p-2"><i class="fas fa-chart-line fa-lg text-success"></i></div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Desempeño Semanal -->
                <div class="mb-4">
                    <h6 class="fw-bold mb-3"><i class="fas fa-chart-pie me-2 text-primary"></i>Desempeño Semanal</h6>
                    <div class="table-responsive">
                        <table class="table table-hover mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th class="py-2">Semana</th>
                                    <th class="text-end py-2">Total Ventas</th>
                                    <th class="text-end py-2">% del Mes</th>
                                    <th class="text-center py-2" width="200">Progreso</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (!empty($ventasPorSemanaMes)): ?>
                                    <?php foreach($ventasPorSemanaMes as $semana): 
                                        $porcentaje = $totalVentasMes > 0 ? ($semana['total'] / $totalVentasMes) * 100 : 0; 
                                    ?>
                                        <tr>
                                            <td class="fw-medium">Semana <?= $semana['semana_num'] ?></td>
                                            <td class="text-end text-primary fw-bold">$<?= number_format($semana['total'], 2) ?></td>
                                            <td class="text-end"><?= number_format($porcentaje, 1) ?>%</td>
                                            <td class="text-center">
                                                <div class="progress" style="height: 6px;">
                                                    <div class="progress-bar bg-primary" style="width: <?= $porcentaje ?>%"></div>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <tr class="no-data-row">
                                        <td colspan="4" class="text-center py-4">
                                            <i class="fas fa-chart-line fa-2x text-muted mb-2 d-block"></i>
                                            <p class="text-muted mb-0">No hay datos de ventas por semana</p>
                                        </td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
                
                <!-- Buscador -->
                <div class="mb-3">
                    <div class="input-group shadow-sm">
                        <span class="input-group-text bg-white border-end-0"><i class="fas fa-search text-muted"></i></span>
                        <input type="text" id="searchMes" class="form-control border-start-0" placeholder="Buscar producto...">
                    </div>
                </div>
                
                <!-- Tabla de Top Productos del Mes -->
                <div class="table-responsive" style="max-height: 300px; overflow-y: auto;">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-light" style="position: sticky; top: 0; z-index: 10;">
                            <tr>
                                <th class="py-3">Producto</th>
                                <th class="text-center py-3" width="120">Cantidad</th>
                                <th class="text-end py-3" width="150">Total</th>
                            </tr>
                        </thead>
                        <tbody id="tbodyTopMes">
                            <?php if (!empty($topProductosMes)): ?>
                                <?php foreach($topProductosMes as $item): ?>
                                    <tr class="producto-mes-row">
                                        <td class="fw-medium"><?= htmlspecialchars($item['nombre']) ?></td>
                                        <td class="text-center">
                                            <span class="badge bg-secondary bg-opacity-10 text-dark px-3 py-2">
                                                <?= $item['cantidad'] ?> uds
                                            </span>
                                        </td>
                                        <td class="text-end fw-bold text-primary">
                                            $<?= number_format($item['total'], 2) ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr class="no-data-row">
                                    <td colspan="3" class="text-center py-5">
                                        <i class="fas fa-chart-line fa-3x text-muted mb-3 d-block"></i>
                                        <p class="text-muted mb-0">No hay ventas registradas este mes</p>
                                        <small class="text-muted">Realiza tu primera venta para ver estadísticas</small>
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                    <div id="noResultsMes" class="text-center py-5" style="display: none;">
                        <i class="fas fa-search fa-3x text-muted mb-3 d-block"></i>
                        <p class="text-muted mb-0">No se encontraron productos con ese nombre</p>
                    </div>
                </div>
                <div id="noResultsMes" class="text-center py-5" style="display: none;"></div>
            </div>
        </div>
    </div>
</div>

<!-- Modal Stock Crítico -->
<div class="modal fade" id="modalStockGeneral" tabindex="-1">
    <div class="modal-dialog modal-xl">
        <div class="modal-content border-0 shadow-lg">
            <div class="modal-header" style="background: linear-gradient(135deg, #10b981, #059669); border-bottom: none;">
                <div class="d-flex align-items-center">
                    <div class="rounded-circle bg-white bg-opacity-25 p-2 me-3"><i class="fas fa-boxes fa-fw text-white"></i></div>
                    <div><h5 class="modal-title text-white fw-bold mb-0">Stock Crítico</h5><p class="text-white-50 small mb-0">Inventario por debajo del nivel óptimo</p></div>
                </div>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-4">
                <div class="alert alert-warning border-0 shadow-sm mb-4">
                    <div class="d-flex align-items-center">
                        <i class="fas fa-exclamation-triangle fa-2x me-3"></i>
                        <div>
                            <h6 class="fw-bold mb-1">Atención requerida</h6>
                            <p class="mb-0">Hay <strong><?= $totalStockBajo ?></strong> items con stock por debajo del nivel óptimo. Se recomienda realizar un pedido de reposición.</p>
                        </div>
                    </div>
                </div>
                <div class="row g-4">
                    <!-- Productos con stock bajo -->
                    <div class="col-md-6">
                        <div class="card border-0 shadow-sm h-100">
                            <div class="card-header bg-white border-0 pt-3 pb-2">
                                <div class="d-flex justify-content-between align-items-center mb-2">
                                    <h6 class="fw-bold text-primary mb-0"><i class="fas fa-box me-2"></i>Productos con stock bajo</h6>
                                    <span class="badge bg-primary" id="totalProductosBajo"><?= count(array_filter($itemsStockBajo, function($item) { return $item['tipo_inventario'] === 'producto'; })) ?></span>
                                </div>
                                <div class="input-group input-group-sm mt-2">
                                    <span class="input-group-text bg-white border-end-0"><i class="fas fa-search text-muted fa-sm"></i></span>
                                    <input type="text" id="searchProductosStock" class="form-control border-start-0 form-control-sm" placeholder="Buscar producto...">
                                    <button class="btn btn-outline-secondary btn-sm" type="button" id="clearProductosSearch" style="display: none;"><i class="fas fa-times"></i></button>
                                </div>
                            </div>
                            <div class="card-body p-0">
                                <div class="stock-scroll-container" style="max-height: 450px; overflow-y: auto;">
                                    <!-- Contenedor de productos SIN list-group -->
                                    <div id="listaProductosBajo">
                                        <?php 
                                        $productosBajo = array_filter($itemsStockBajo, function($item) { 
                                            return $item['tipo_inventario'] === 'producto'; 
                                        });
                                        if(!empty($productosBajo)): 
                                            foreach($productosBajo as $p): 
                                        ?>
                                            <div class="producto-stock-item" data-nombre="<?= strtolower(htmlspecialchars($p['nombre'])) ?>" style="display: flex; justify-content: space-between; align-items: center; padding: 12px 16px; border-bottom: 1px solid #eef2f6;">
                                                <div class="fw-medium">
                                                    <i class="fas fa-box me-2 text-primary"></i>
                                                    <span class="producto-nombre"><?= htmlspecialchars($p['nombre']) ?></span>
                                                </div>
                                                <span class="badge bg-danger rounded-pill px-3 py-2">
                                                    <i class="fas fa-chart-line me-1"></i><?= $p['cantidad'] ?> uds
                                                </span>
                                            </div>
                                        <?php 
                                            endforeach; 
                                        else: 
                                        ?>
                                            <div class="text-center py-5" id="noProductosMsg">
                                                <i class="fas fa-check-circle fa-2x text-success mb-2 d-block"></i>
                                                <p class="text-muted mb-0">No hay productos con stock crítico</p>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                    <!-- Mensaje sin resultados para productos -->
                                    <div id="noResultadosProductos" class="text-center py-5" style="display: none;">
                                        <i class="fas fa-search fa-3x text-muted mb-3 d-block"></i>
                                        <p class="text-muted mb-0">No se encontraron productos con ese nombre</p>
                                        <small class="text-muted">Intenta con otro término de búsqueda</small>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Insumos con stock bajo -->
                    <div class="col-md-6">
                        <div class="card border-0 shadow-sm h-100">
                            <div class="card-header bg-white border-0 pt-3 pb-2">
                                <div class="d-flex justify-content-between align-items-center mb-2">
                                    <h6 class="fw-bold text-info mb-0"><i class="fas fa-tint me-2"></i>Insumos con stock bajo</h6>
                                    <span class="badge bg-info" id="totalInsumosBajo"><?= count(array_filter($itemsStockBajo, function($item) { return $item['tipo_inventario'] === 'insumo'; })) ?></span>
                                </div>
                                <div class="input-group input-group-sm mt-2">
                                    <span class="input-group-text bg-white border-end-0"><i class="fas fa-search text-muted fa-sm"></i></span>
                                    <input type="text" id="searchInsumosStock" class="form-control border-start-0 form-control-sm" placeholder="Buscar insumo...">
                                    <button class="btn btn-outline-secondary btn-sm" type="button" id="clearInsumosSearch" style="display: none;"><i class="fas fa-times"></i></button>
                                </div>
                            </div>
                            <div class="card-body p-0">
                                <div class="stock-scroll-container" style="max-height: 450px; overflow-y: auto;">
                                    <!-- Contenedor de insumos SIN list-group -->
                                    <div id="listaInsumosBajo">
                                        <?php 
                                        $insumosBajo = array_filter($itemsStockBajo, function($item) { 
                                            return $item['tipo_inventario'] === 'insumo'; 
                                        });
                                        if(!empty($insumosBajo)): 
                                            foreach($insumosBajo as $i): 
                                        ?>
                                            <div class="insumo-stock-item" data-nombre="<?= strtolower(htmlspecialchars($i['nombre'])) ?>" style="display: flex; justify-content: space-between; align-items: center; padding: 12px 16px; border-bottom: 1px solid #eef2f6;">
                                                <div class="fw-medium">
                                                    <i class="fas fa-tint me-2 text-info"></i>
                                                    <span class="insumo-nombre"><?= htmlspecialchars($i['nombre']) ?></span>
                                                </div>
                                                <span class="badge bg-warning rounded-pill px-3 py-2">
                                                    <i class="fas fa-chart-line me-1"></i><?= $i['cantidad'] ?> unidades
                                                </span>
                                            </div>
                                        <?php 
                                            endforeach; 
                                        else: 
                                        ?>
                                            <div class="text-center py-5" id="noInsumosMsg">
                                                <i class="fas fa-check-circle fa-2x text-success mb-2 d-block"></i>
                                                <p class="text-muted mb-0">No hay insumos con stock crítico</p>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                    <!-- Mensaje sin resultados para insumos -->
                                    <div id="noResultadosInsumos" class="text-center py-5" style="display: none;">
                                        <i class="fas fa-search fa-3x text-muted mb-3 d-block"></i>
                                        <p class="text-muted mb-0">No se encontraron insumos con ese nombre</p>
                                        <small class="text-muted">Intenta con otro término de búsqueda</small>
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

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

<script>
    // Datos para la gráfica
    const ventasData = <?= json_encode(array_map('floatval', $ventasPorDia)) ?>;
    const ctxVentas = document.getElementById('chartVentas').getContext('2d');
    let chartVentas = null;
    
    function initChart() {
        if (chartVentas) chartVentas.destroy();
        chartVentas = new Chart(ctxVentas, {
            type: 'line',
            data: {
                labels: ['Lunes', 'Martes', 'Miércoles', 'Jueves', 'Viernes', 'Sábado', 'Domingo'],
                datasets: [{
                    label: 'Ventas ($)',
                    data: ventasData,
                    borderColor: '#f97316',
                    backgroundColor: 'rgba(249, 115, 22, 0.05)',
                    borderWidth: 3,
                    fill: true,
                    tension: 0.4,
                    pointBackgroundColor: '#f97316',
                    pointBorderColor: '#fff',
                    pointRadius: 5,
                    pointHoverRadius: 8,
                    pointBorderWidth: 2
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        callbacks: { label: function(context) { return '$' + context.raw.toLocaleString(); } }
                    }
                },
                scales: {
                    y: { beginAtZero: true, ticks: { callback: function(value) { return '$' + value.toLocaleString(); } } },
                    x: { grid: { display: false } }
                }
            }
        });
    }
    
    initChart();
    window.addEventListener('resize', function() { setTimeout(function() { if (chartVentas) chartVentas.resize(); }, 100); });
    
    // Gráfica semana modal
    if(document.getElementById('chartSemanaModal')) {
        new Chart(document.getElementById('chartSemanaModal').getContext('2d'), {
            type: 'bar',
            data: { labels: ['Lunes', 'Martes', 'Miércoles', 'Jueves', 'Viernes', 'Sábado', 'Domingo'], datasets: [{ label: 'Ventas ($)', data: ventasData, backgroundColor: 'rgba(59, 130, 246, 0.7)', borderRadius: 8 }] },
            options: { responsive: true, maintainAspectRatio: true, plugins: { legend: { display: false }, tooltip: { callbacks: { label: function(ctx) { return '$' + ctx.raw.toLocaleString(); } } } }, scales: { y: { ticks: { callback: function(v) { return '$' + v.toLocaleString(); } } } } }
        });
    }
    
    // Toggle password
    function togglePasswordField(fieldId, button) {
        const field = document.getElementById(fieldId);
        const icon = button.querySelector('i');
        if (field.type === 'password') { field.type = 'text'; icon.classList.remove('fa-eye'); icon.classList.add('fa-eye-slash'); }
        else { field.type = 'password'; icon.classList.remove('fa-eye-slash'); icon.classList.add('fa-eye'); }
    }
    
    // Cambio de contraseña
    <?php if ($mostrar_modal_password): ?>
    document.getElementById('formCambiarPassword')?.addEventListener('submit', function(e) {
        e.preventDefault();
        const password = document.getElementById('password_nueva').value;
        const confirm = document.getElementById('password_confirmar').value;
        const errorDiv = document.getElementById('error-mensaje');
        const submitBtn = document.getElementById('btnCambiar');
        errorDiv.style.display = 'none';
        if (!password || !confirm) { errorDiv.textContent = 'Completa todos los campos'; errorDiv.style.display = 'block'; return; }
        if (password !== confirm) { errorDiv.textContent = 'Las contraseñas no coinciden'; errorDiv.style.display = 'block'; return; }
        if (password.length < 8) { errorDiv.textContent = 'Mínimo 8 caracteres'; errorDiv.style.display = 'block'; return; }
        if (password === 'Pescadores1') { errorDiv.textContent = 'Usa una contraseña diferente'; errorDiv.style.display = 'block'; return; }
        submitBtn.disabled = true;
        submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i> Cambiando...';
        const formData = new FormData();
        formData.append('csrf_token', document.querySelector('input[name="csrf_token"]').value);
        formData.append('ajax_cambio_password', '1');
        formData.append('password_nueva', password);
        formData.append('password_confirmar', confirm);
        fetch(window.location.href, { method: 'POST', body: formData })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                document.getElementById('modalCambiarPassword').style.display = 'none';
                document.querySelector('.content-wrapper').classList.remove('modal-active');
                Swal.fire({ icon: 'success', title: '¡Contraseña cambiada!', text: data.message, timer: 2000, showConfirmButton: false }).then(() => { window.location.reload(); });
            } else {
                errorDiv.textContent = data.message;
                errorDiv.style.display = 'block';
                submitBtn.disabled = false;
                submitBtn.innerHTML = '<i class="fas fa-sync-alt me-2"></i> Cambiar Contraseña';
            }
        })
        .catch(error => { errorDiv.textContent = 'Error al conectar con el servidor'; errorDiv.style.display = 'block'; submitBtn.disabled = false; submitBtn.innerHTML = '<i class="fas fa-sync-alt me-2"></i> Cambiar Contraseña'; });
    });
    <?php endif; ?>
    
    // Buscadores
    document.getElementById('searchHoy')?.addEventListener('keyup', function() {
        const term = this.value.toLowerCase().trim();
        const rows = document.querySelectorAll('#tbodyVentasHoy .venta-row');
        let hasResults = false;
        rows.forEach(row => { const name = row.querySelector('td:first-child')?.textContent.toLowerCase() || ''; if(name.includes(term)) { row.style.display = ''; hasResults = true; } else { row.style.display = 'none'; } });
        document.getElementById('noResultsHoy').style.display = (term !== '' && !hasResults && rows.length > 0) ? 'block' : 'none';
    });
    
    document.getElementById('searchSemana')?.addEventListener('keyup', function() {
        const term = this.value.toLowerCase().trim();
        const rows = document.querySelectorAll('#tbodyTopSemana .producto-semana-row');
        let hasResults = false;
        rows.forEach(row => { const name = row.querySelector('td:first-child')?.textContent.toLowerCase() || ''; if(name.includes(term)) { row.style.display = ''; hasResults = true; } else { row.style.display = 'none'; } });
    });
    
    document.getElementById('searchMes')?.addEventListener('keyup', function() {
        const term = this.value.toLowerCase().trim();
        const rows = document.querySelectorAll('#tbodyTopMes .producto-mes-row');
        let hasResults = false;
        rows.forEach(row => { const name = row.querySelector('td:first-child')?.textContent.toLowerCase() || ''; if(name.includes(term)) { row.style.display = ''; hasResults = true; } else { row.style.display = 'none'; } });
    });
    
    // Alerta stock bajo
let alertTimeout = null;
let alertHideTimeout = null;

function mostrarAlertaStockBajo() {
    const totalStock = <?= $totalStockBajo ?>;
    if (totalStock === 0) return;
    
    const existingAlert = document.querySelector('.stock-badge-alert');
    if (existingAlert) { 
        existingAlert.remove(); 
        if (alertTimeout) clearTimeout(alertTimeout);
        if (alertHideTimeout) clearTimeout(alertHideTimeout);
    }
    
    const itemsStock = <?= json_encode(array_map(function($item) { 
        return [
            'nombre' => $item['nombre'], 
            'cantidad' => $item['cantidad'], 
            'tipo' => $item['tipo_inventario']
        ]; 
    }, array_slice($itemsStockBajo, 0, 5))) ?>;
    
    const hasCritical = itemsStock.some(item => item.cantidad === 0);
    const alertType = hasCritical ? 'danger' : 'warning';
    const iconClass = hasCritical ? 'fa-exclamation-triangle' : 'fa-box-open';
    const badgeText = hasCritical ? 'crítico' : 'bajo';
    
    let itemsHtml = '';
    itemsStock.forEach(item => {
        const icon = item.tipo === 'producto' ? 'fa-box' : 'fa-tint';
        const qtyClass = item.cantidad === 0 ? 'critical' : 'low';
        itemsHtml += `
            <div class="stock-panel-item">
                <span class="stock-panel-name">
                    <i class="fas ${icon}"></i> 
                    ${escapeHtml(item.nombre.length > 22 ? item.nombre.substring(0,22)+'…' : item.nombre)}
                </span>
                <span class="stock-panel-qty ${qtyClass}">${item.cantidad}</span>
            </div>
        `;
    });
    
    const moreCount = totalStock - itemsStock.length;
    const moreHtml = moreCount > 0 ? `
        <div class="stock-panel-more">
            <i class="fas fa-ellipsis-h"></i> ${moreCount} ${moreCount === 1 ? 'item más' : 'items más'}
        </div>
    ` : '';
    
    const alertDiv = document.createElement('div');
    alertDiv.className = 'stock-badge-alert';
    alertDiv.innerHTML = `
        <div class="stock-badge-main ${alertType}">
            <div class="stock-badge-icon"><i class="fas ${iconClass}"></i></div>
            <span class="stock-badge-count">${totalStock}</span>
            <span class="stock-badge-text">stock <span>${badgeText}</span></span>
            <i class="fas fa-chevron-right stock-badge-arrow"></i>
            <button class="stock-badge-close" onclick="cerrarAlerta(this)"><i class="fas fa-times"></i></button>
        </div>
        <div class="stock-badge-panel">
            <div class="stock-panel-header">
                <div class="stock-panel-title ${alertType}">
                    <i class="fas ${hasCritical ? 'fa-exclamation-triangle' : 'fa-box'}"></i> 
                    Items con stock ${badgeText}
                </div>
                <a href="#" class="stock-panel-view" id="verTodosStockBtn">Ver todos →</a>
            </div>
            <div class="stock-panel-items">
                ${itemsHtml}
                ${moreHtml}
            </div>
        </div>
    `;
    document.body.appendChild(alertDiv);
    
    // Agregar event listener para el botón "Ver todos"
    const verTodosBtn = alertDiv.querySelector('#verTodosStockBtn');
    if (verTodosBtn) {
        verTodosBtn.addEventListener('click', function(e) {
            e.preventDefault();
            e.stopPropagation();
            // Cerrar la alerta
            cerrarAlerta(null);
            // Abrir el modal usando Bootstrap 5
            const modalElement = document.getElementById('modalStockGeneral');
            if (modalElement) {
                // Para Bootstrap 5
                const modal = new bootstrap.Modal(modalElement);
                modal.show();
            } else {
                console.log('Modal no encontrado');
            }
        });
    }
    
    // Auto-cerrar después de 5 segundos
    alertHideTimeout = setTimeout(() => { 
        cerrarAlerta(null);
    }, 5000);
    
    // Pausar auto-cierre al hacer hover
    alertDiv.addEventListener('mouseenter', () => { 
        if (alertHideTimeout) clearTimeout(alertHideTimeout); 
    });
    
    // Reanudar auto-cierre al salir
    alertDiv.addEventListener('mouseleave', () => { 
        alertHideTimeout = setTimeout(() => { 
            cerrarAlerta(null);
        }, 3000);
    });
}

function cerrarAlerta(btn) {
    const alert = document.querySelector('.stock-badge-alert');
    if (alert) {
        alert.style.animation = 'fadeOutUp 0.3s ease-out';
        setTimeout(() => {
            if (alert.parentNode) alert.remove();
        }, 300);
    }
    if (alertTimeout) clearTimeout(alertTimeout);
    if (alertHideTimeout) clearTimeout(alertHideTimeout);
}

function escapeHtml(text) { 
    const div = document.createElement('div'); 
    div.textContent = text; 
    return div.innerHTML; 
}

// Inicializar
setTimeout(function() { 
    mostrarAlertaStockBajo(); 
}, 1000);

    // ==================== FUNCIONES DE PAGINACIÓN Y BÚSQUEDA ====================

    // Clase para manejar paginación
    class TablePagination {
        constructor(tableId, tbodyId, searchInputId, clearSearchId, paginationContainerId, noResultsId, rowsPerPage = 10) {
            this.tableId = tableId;
            this.tbodyId = tbodyId;
            this.searchInputId = searchInputId;
            this.clearSearchId = clearSearchId;
            this.paginationContainerId = paginationContainerId;
            this.noResultsId = noResultsId;
            this.rowsPerPage = rowsPerPage;
            this.currentPage = 1;
            this.allRows = [];
            this.filteredRows = [];
            this.searchTerm = '';
            this.hasOriginalData = false;
            
            this.init();
        }
        
        init() {
            // Obtener todas las filas (excluyendo la fila de "no datos" original)
            const tbody = document.getElementById(this.tbodyId);
            if (!tbody) return;
            
            const rows = tbody.querySelectorAll('tr:not(.no-data-row)');
            this.allRows = Array.from(rows);
            this.hasOriginalData = this.allRows.length > 0;
            
            // Configurar buscador
            const searchInput = document.getElementById(this.searchInputId);
            const clearSearch = document.getElementById(this.clearSearchId);
            
            if (searchInput) {
                searchInput.addEventListener('input', (e) => {
                    this.searchTerm = e.target.value.toLowerCase().trim();
                    this.filterRows();
                    this.currentPage = 1;
                    this.render();
                    
                    // Mostrar/ocultar botón de limpiar
                    if (clearSearch) {
                        clearSearch.style.display = this.searchTerm !== '' ? 'inline-flex' : 'none';
                    }
                });
            }
            
            // Botón de limpiar búsqueda
            if (clearSearch) {
                clearSearch.addEventListener('click', () => {
                    if (searchInput) {
                        searchInput.value = '';
                        this.searchTerm = '';
                        this.filterRows();
                        this.currentPage = 1;
                        this.render();
                        clearSearch.style.display = 'none';
                        searchInput.focus();
                    }
                });
            }
            
            // Filtrar y renderizar
            this.filterRows();
            this.render();
        }
        
        filterRows() {
            if (this.searchTerm === '') {
                this.filteredRows = [...this.allRows];
            } else {
                this.filteredRows = this.allRows.filter(row => {
                    const nombre = row.getAttribute('data-nombre') || '';
                    const textContent = row.querySelector('td:first-child')?.textContent.toLowerCase() || '';
                    return nombre.includes(this.searchTerm) || textContent.includes(this.searchTerm);
                });
            }
        }
        
        render() {
            const totalRows = this.filteredRows.length;
            const totalPages = Math.ceil(totalRows / this.rowsPerPage);
            const start = (this.currentPage - 1) * this.rowsPerPage;
            const end = start + this.rowsPerPage;
            const pageRows = this.filteredRows.slice(start, end);
            
            // Ocultar todas las filas primero
            this.allRows.forEach(row => row.style.display = 'none');
            
            // Ocultar la fila de "no datos" original si existe
            const tbody = document.getElementById(this.tbodyId);
            const noDataOriginalRow = tbody?.querySelector('.no-data-row');
            if (noDataOriginalRow) {
                noDataOriginalRow.style.display = 'none';
            }
            
            // Gestionar mensaje de "sin resultados" de búsqueda
            const noResultsDiv = document.getElementById(this.noResultsId);
            
            if (!this.hasOriginalData) {
                // No hay datos originales, mostrar mensaje de "sin datos"
                if (noResultsDiv) {
                    noResultsDiv.style.display = 'block';
                    noResultsDiv.innerHTML = `
                        <i class="fas fa-chart-line fa-3x text-muted mb-3 d-block"></i>
                        <p class="text-muted mb-0">No hay ventas registradas</p>
                        <small class="text-muted">Realiza tu primera venta para ver estadísticas</small>
                    `;
                }
                // Ocultar paginación
                const paginationContainer = document.getElementById(this.paginationContainerId);
                if (paginationContainer) paginationContainer.style.display = 'none';
                return;
            }
            
            // Hay datos originales, verificar si hay resultados de búsqueda
            if (this.searchTerm !== '' && totalRows === 0) {
                // Búsqueda sin resultados
                if (noResultsDiv) {
                    noResultsDiv.style.display = 'block';
                    noResultsDiv.innerHTML = `
                        <i class="fas fa-search fa-3x text-muted mb-3 d-block"></i>
                        <p class="text-muted mb-0">No se encontraron productos con "<strong>${escapeHtml(this.searchTerm)}</strong>"</p>
                        <small class="text-muted">Intenta con otro término de búsqueda</small>
                    `;
                }
                // Ocultar paginación
                const paginationContainer = document.getElementById(this.paginationContainerId);
                if (paginationContainer) paginationContainer.style.display = 'none';
            } else {
                // Hay resultados, mostrar filas
                if (noResultsDiv) noResultsDiv.style.display = 'none';
                
                // Mostrar filas de la página actual
                pageRows.forEach(row => row.style.display = '');
                
                // Mostrar/ocultar contenedor de paginación
                const paginationContainer = document.getElementById(this.paginationContainerId);
                if (paginationContainer) {
                    paginationContainer.style.display = totalRows > this.rowsPerPage ? 'flex' : 'none';
                }
                
                // Actualizar información de paginación
                this.updatePaginationInfo(start, end, totalRows);
                this.updatePaginationButtons(totalPages);
            }
        }
        
        updatePaginationInfo(start, end, total) {
            const startSpan = document.getElementById(`${this.getPrefix()}Start`);
            const endSpan = document.getElementById(`${this.getPrefix()}End`);
            const totalSpan = document.getElementById(`${this.getPrefix()}Total`);
            
            if (startSpan) startSpan.textContent = total > 0 ? start + 1 : 0;
            if (endSpan) endSpan.textContent = Math.min(end, total);
            if (totalSpan) totalSpan.textContent = total;
        }
        
        updatePaginationButtons(totalPages) {
            const prevBtn = document.getElementById(`${this.getPrefix()}PrevPage`);
            const nextBtn = document.getElementById(`${this.getPrefix()}NextPage`);
            const numbersContainer = document.getElementById(`${this.getPrefix()}PaginationNumbers`);
            
            if (prevBtn) prevBtn.disabled = this.currentPage === 1;
            if (nextBtn) nextBtn.disabled = this.currentPage === totalPages || totalPages === 0;
            
            if (numbersContainer) {
                numbersContainer.innerHTML = '';
                
                if (totalPages > 0) {
                    let startPage = Math.max(1, this.currentPage - 2);
                    let endPage = Math.min(totalPages, startPage + 4);
                    
                    if (endPage - startPage < 4) {
                        startPage = Math.max(1, endPage - 4);
                    }
                    
                    for (let i = startPage; i <= endPage; i++) {
                        const btn = document.createElement('button');
                        btn.className = `pagination-number ${i === this.currentPage ? 'active' : ''}`;
                        btn.textContent = i;
                        btn.addEventListener('click', () => {
                            this.currentPage = i;
                            this.render();
                        });
                        numbersContainer.appendChild(btn);
                    }
                }
            }
        }
        
        getPrefix() {
            if (this.tbodyId === 'tbodyVentasHoy') return 'hoy';
            if (this.tbodyId === 'tbodyTopSemana') return 'semana';
            if (this.tbodyId === 'tbodyTopMes') return 'mes';
            return '';
        }
        
        goToPrevPage() {
            if (this.currentPage > 1) {
                this.currentPage--;
                this.render();
            }
        }
        
        goToNextPage(totalPages) {
            if (this.currentPage < totalPages) {
                this.currentPage++;
                this.render();
            }
        }
    }

    // Función de escape para HTML
    function escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }

    // Inicializar paginación cuando los modales se abren
    document.addEventListener('DOMContentLoaded', function() {
        let paginationHoy = null;
        let paginationSemana = null;
        let paginationMes = null;
        
        // Inicializar al abrir cada modal
        const modalHoy = document.getElementById('modalVentasHoy');
        if (modalHoy) {
            modalHoy.addEventListener('shown.bs.modal', function() {
                setTimeout(() => {
                    if (!paginationHoy) {
                        paginationHoy = new TablePagination(
                            'tablaVentasHoy', 'tbodyVentasHoy', 
                            'searchHoy', 'clearSearchHoy', 'paginationHoy', 'noResultsHoy', 10
                        );
                    }
                }, 100);
            });
        }
        
        const modalSemana = document.getElementById('modalVentasSemana');
        if (modalSemana) {
            modalSemana.addEventListener('shown.bs.modal', function() {
                setTimeout(() => {
                    if (!paginationSemana) {
                        paginationSemana = new TablePagination(
                            null, 'tbodyTopSemana', 
                            'searchSemana', 'clearSearchSemana', 'paginationSemana', 'noResultsSemana', 8
                        );
                    }
                    // Inicializar gráfica
                    initSemanaChart();
                }, 100);
            });
        }
        
        const modalMes = document.getElementById('modalVentasMes');
        if (modalMes) {
            modalMes.addEventListener('shown.bs.modal', function() {
                setTimeout(() => {
                    if (!paginationMes) {
                        paginationMes = new TablePagination(
                            null, 'tbodyTopMes', 
                            'searchMes', 'clearSearchMes', 'paginationMes', 'noResultsMes', 10
                        );
                    }
                }, 100);
            });
        }
        
        // Botones de navegación globales
        document.getElementById('prevPageHoy')?.addEventListener('click', () => paginationHoy?.goToPrevPage());
        document.getElementById('nextPageHoy')?.addEventListener('click', () => {
            const totalPages = Math.ceil((paginationHoy?.filteredRows.length || 0) / 10);
            paginationHoy?.goToNextPage(totalPages);
        });
        
        document.getElementById('prevPageSemana')?.addEventListener('click', () => paginationSemana?.goToPrevPage());
        document.getElementById('nextPageSemana')?.addEventListener('click', () => {
            const totalPages = Math.ceil((paginationSemana?.filteredRows.length || 0) / 8);
            paginationSemana?.goToNextPage(totalPages);
        });
        
        document.getElementById('prevPageMes')?.addEventListener('click', () => paginationMes?.goToPrevPage());
        document.getElementById('nextPageMes')?.addEventListener('click', () => {
            const totalPages = Math.ceil((paginationMes?.filteredRows.length || 0) / 10);
            paginationMes?.goToNextPage(totalPages);
        });
    });

    // Función para la gráfica de semana
    function initSemanaChart() {
        const canvas = document.getElementById('chartSemanaModal');
        if (!canvas) return;
        
        if (window.semanaChart) {
            window.semanaChart.destroy();
            window.semanaChart = null;
        }
        
        const ventasDataSemana = <?= json_encode(array_map('floatval', $ventasPorDia)) ?>;
        const diasSemana = ['Lunes', 'Martes', 'Miércoles', 'Jueves', 'Viernes', 'Sábado', 'Domingo'];
        
        window.semanaChart = new Chart(canvas.getContext('2d'), {
            type: 'bar',
            data: {
                labels: diasSemana,
                datasets: [{
                    label: 'Ventas ($ MXN)',
                    data: ventasDataSemana,
                    backgroundColor: 'rgba(59, 130, 246, 0.7)',
                    borderColor: '#3b82f6',
                    borderWidth: 1,
                    borderRadius: 8,
                    barPercentage: 0.65,
                    categoryPercentage: 0.8
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: true,
                plugins: {
                    legend: { display: true, position: 'top' },
                    tooltip: {
                        callbacks: {
                            label: function(context) {
                                if (context.raw === 0) return 'Sin ventas';
                                return '$ ' + context.raw.toLocaleString('es-MX', {
                                    minimumFractionDigits: 2,
                                    maximumFractionDigits: 2
                                });
                            }
                        }
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        ticks: { 
                            callback: (value) => {
                                if (value === 0) return '$0';
                                return '$' + value.toLocaleString('es-MX');
                            }
                        }
                    }
                }
            }
        });
    }

// ==================== BUSCADORES STOCK CRÍTICO - TIEMPO REAL INMEDIATO ====================

function inicializarBuscadoresStock() {
    // ========== BUSCADOR DE PRODUCTOS ==========
    const searchProductos = document.getElementById('searchProductosStock');
    const productosContainer = document.getElementById('listaProductosBajo');
    const noResultadosProductos = document.getElementById('noResultadosProductos');
    const badgeProductos = document.getElementById('totalProductosBajo');
    const clearProductosBtn = document.getElementById('clearProductosSearch');
    
    if (searchProductos && productosContainer) {
        const filtrarProductos = () => {
            const term = searchProductos.value.toLowerCase().trim();
            const items = productosContainer.querySelectorAll('.producto-stock-item');
            let visible = 0;
            
            items.forEach(item => {
                const nombre = item.getAttribute('data-nombre') || '';
                if (term === '' || nombre.includes(term)) {
                    item.style.display = 'flex';
                    visible++;
                } else {
                    item.style.display = 'none';
                }
            });
            
            // Actualizar badge
            if (badgeProductos) badgeProductos.textContent = visible;
            
            // Mostrar/ocultar mensaje de sin resultados
            const hayItems = items.length > 0;
            if (noResultadosProductos) {
                if (term !== '' && visible === 0 && hayItems) {
                    noResultadosProductos.style.display = 'block';
                } else {
                    noResultadosProductos.style.display = 'none';
                }
            }
            
            // Mostrar/ocultar botón de limpiar
            if (clearProductosBtn) {
                clearProductosBtn.style.display = term !== '' ? 'inline-flex' : 'none';
            }
        };
        
        // Evento en tiempo real INMEDIATO (sin debounce)
        searchProductos.addEventListener('keyup', filtrarProductos);
        searchProductos.addEventListener('input', filtrarProductos);
        
        // Botón limpiar
        if (clearProductosBtn) {
            clearProductosBtn.addEventListener('click', function() {
                searchProductos.value = '';
                filtrarProductos();
                searchProductos.focus();
            });
        }
        
        // Ejecutar al inicio
        filtrarProductos();
    }
    
    // ========== BUSCADOR DE INSUMOS ==========
    const searchInsumos = document.getElementById('searchInsumosStock');
    const insumosContainer = document.getElementById('listaInsumosBajo');
    const noResultadosInsumos = document.getElementById('noResultadosInsumos');
    const badgeInsumos = document.getElementById('totalInsumosBajo');
    const clearInsumosBtn = document.getElementById('clearInsumosSearch');
    
    if (searchInsumos && insumosContainer) {
        const filtrarInsumos = () => {
            const term = searchInsumos.value.toLowerCase().trim();
            const items = insumosContainer.querySelectorAll('.insumo-stock-item');
            let visible = 0;
            
            items.forEach(item => {
                const nombre = item.getAttribute('data-nombre') || '';
                if (term === '' || nombre.includes(term)) {
                    item.style.display = 'flex';
                    visible++;
                } else {
                    item.style.display = 'none';
                }
            });
            
            // Actualizar badge
            if (badgeInsumos) badgeInsumos.textContent = visible;
            
            // Mostrar/ocultar mensaje de sin resultados
            const hayItems = items.length > 0;
            if (noResultadosInsumos) {
                if (term !== '' && visible === 0 && hayItems) {
                    noResultadosInsumos.style.display = 'block';
                } else {
                    noResultadosInsumos.style.display = 'none';
                }
            }
            
            // Mostrar/ocultar botón de limpiar
            if (clearInsumosBtn) {
                clearInsumosBtn.style.display = term !== '' ? 'inline-flex' : 'none';
            }
        };
        
        // Evento en tiempo real INMEDIATO
        searchInsumos.addEventListener('keyup', filtrarInsumos);
        searchInsumos.addEventListener('input', filtrarInsumos);
        
        // Botón limpiar
        if (clearInsumosBtn) {
            clearInsumosBtn.addEventListener('click', function() {
                searchInsumos.value = '';
                filtrarInsumos();
                searchInsumos.focus();
            });
        }
        
        // Ejecutar al inicio
        filtrarInsumos();
    }
}

// Inicializar cuando el modal se abre
document.addEventListener('DOMContentLoaded', function() {
    const modalStock = document.getElementById('modalStockGeneral');
    if (modalStock) {
        modalStock.addEventListener('shown.bs.modal', function() {
            // Limpiar campos de búsqueda al abrir el modal
            const searchProductos = document.getElementById('searchProductosStock');
            const searchInsumos = document.getElementById('searchInsumosStock');
            if (searchProductos) searchProductos.value = '';
            if (searchInsumos) searchInsumos.value = '';
            
            // Inicializar buscadores
            setTimeout(inicializarBuscadoresStock, 50);
        });
    }
});
</script>

</body>
</html>