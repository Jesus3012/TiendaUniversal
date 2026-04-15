<?php
require_once 'includes/session.php';
require_once 'includes/db.php';
require_once 'includes/csrf.php';

// Verificar autenticación
if (!isset($_SESSION['usuario_id']) || strtolower($_SESSION['rol'] ?? '') !== 'administrador') {
    header("Location: login.php");
    exit;
}

$nombre_usuario = $_SESSION['nombre'] ?? 'Administrador';
$nombre_completo = $nombre_usuario;

// ===== PROCESAR AJAX PRIMERO =====
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
    
    ob_clean();
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

// Ventas por día
$ventasPorDia = array_fill(0, 7, 0);
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
        $dia = intval($row['dia']);
        if ($dia < 1 || $dia > 7) continue;
        $posicion = ($dia == 1) ? 6 : $dia - 2;
        $ventasPorDia[$posicion] = floatval($row['total']);
    }
}

// Ventas totales
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

// Ventas por mes para el modal
$resVentasMes = $conn->query("
    SELECT IFNULL(SUM(v.cantidad_vendida*p.precio_venta),0) AS total_mes
    FROM ventas v
    JOIN productos p ON v.id_producto = p.id
    WHERE MONTH(v.fecha_venta) = MONTH(CURDATE()) AND YEAR(v.fecha_venta) = YEAR(CURDATE())
");
$totalVentasMes = ($resVentasMes && $resVentasMes->num_rows) ? floatval($resVentasMes->fetch_assoc()['total_mes']) : 0.0;

// Stock bajo
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

// Usuarios
$resUsuarios = $conn->query("SELECT COUNT(*) AS total_usuarios FROM usuarios");
$totalUsuarios = $resUsuarios ? $resUsuarios->fetch_assoc()['total_usuarios'] : 0;

// Usuarios activos
$resUsuariosActivos = $conn->query("SELECT COUNT(*) AS total FROM usuarios WHERE activo = 1");
$usuariosActivos = $resUsuariosActivos ? $resUsuariosActivos->fetch_assoc()['total'] : 0;

// Utilidad hoy
$sqlUtilidad = "SELECT SUM((p.precio_venta - p.precio_compra) * v.cantidad_vendida) AS utilidadHoy
                FROM ventas v
                INNER JOIN productos p ON p.id = v.id_producto
                WHERE DATE(v.fecha_venta) = CURDATE()";
$resultUtilidad = $conn->query($sqlUtilidad);
$utilidadHoy = $resultUtilidad->fetch_assoc()['utilidadHoy'] ?? 0;

// Top productos
$topProductos = [];
$resTop = $conn->query("
    SELECT p.nombre, SUM(v.cantidad_vendida) AS total_vendido
    FROM ventas v
    JOIN productos p ON v.id_producto = p.id
    GROUP BY p.id
    ORDER BY total_vendido DESC
    LIMIT 5
");
if ($resTop) {
    while ($row = $resTop->fetch_assoc()) {
        $topProductos[] = $row;
    }
}

// Tickets hoy
$resTicketsHoy = $conn->query("
    SELECT COUNT(*) AS tickets FROM ventas WHERE DATE(fecha_venta) = CURDATE()
");
$ticketsHoy = $resTicketsHoy ? $resTicketsHoy->fetch_assoc()['tickets'] : 0;

// Ticket promedio
$ticketPromedio = $ticketsHoy > 0 ? $totalVentasDia / $ticketsHoy : 0;

// Ventas por día de la semana para el modal
$ventasPorDiaSemana = [];
$diasSemana = ['Lunes', 'Martes', 'Miércoles', 'Jueves', 'Viernes', 'Sábado', 'Domingo'];
for ($i = 0; $i < 7; $i++) {
    $ventasPorDiaSemana[] = [
        'dia' => $diasSemana[$i],
        'total' => $ventasPorDia[$i]
    ];
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Dashboard | Pescadores de la Prehistoria</title>
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
    
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Inter', sans-serif;
            background: #f0f2f5;
        }

        /* ========== HERO SECTION CON IMAGEN PERSONALIZADA ========== */
        .hero-premium {
            position: relative;
            background: linear-gradient(135deg, #1e3a5f 0%, #0f2b45 100%);
            border-radius: 0 0 40px 40px;
            overflow: hidden;
            margin-bottom: 2rem;
            min-height: 320px;
            display: flex;
            align-items: center;
        }

        .hero-premium::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: url('img/panel_principal.png') center center / cover no-repeat;
            background-size: cover;
            opacity: 0.25;
            pointer-events: none;
        }

        /* Intentar diferentes formatos de imagen */
        @supports (background-image: url('img/panel_principal.webp')) {
            .hero-premium::before {
                background-image: url('img/panel_principal.webp');
            }
        }

        .hero-content {
            position: relative;
            z-index: 2;
            padding: 3rem 2rem;
            animation: fadeInUp 0.8s ease-out;
        }

        @keyframes fadeInUp {
            from { opacity: 0; transform: translateY(40px); }
            to { opacity: 1; transform: translateY(0); }
        }

        @keyframes pulse {
            0%, 100% { transform: scale(1); }
            50% { transform: scale(1.05); }
        }

        @keyframes float {
            0%, 100% { transform: translateY(0px); }
            50% { transform: translateY(-10px); }
        }

        .hero-greeting {
            display: inline-block;
            background: rgba(249, 115, 22, 0.2);
            backdrop-filter: blur(10px);
            padding: 0.5rem 1.2rem;
            border-radius: 50px;
            font-size: 0.8rem;
            letter-spacing: 1px;
            margin-bottom: 1.2rem;
            border: 1px solid rgba(249, 115, 22, 0.4);
            color: #fbbf24;
            animation: pulse 2s ease-in-out infinite;
        }

        .hero-premium h1 {
            font-size: 2.5rem;
            font-weight: 800;
            margin-bottom: 0.5rem;
            color: white;
        }

        .hero-premium h1 span {
            background: linear-gradient(135deg, #fbbf24, #f97316);
            -webkit-background-clip: text;
            background-clip: text;
            color: transparent;
            display: inline-block;
            animation: float 3s ease-in-out infinite;
        }

        .hero-premium p {
            font-size: 1.1rem;
            color: rgba(255, 255, 255, 0.85);
            margin-bottom: 1.5rem;
        }

        .hero-stats {
            display: flex;
            gap: 1.5rem;
            flex-wrap: wrap;
        }

        .hero-stat-item {
            background: rgba(255, 255, 255, 0.1);
            backdrop-filter: blur(10px);
            padding: 0.6rem 1.5rem;
            border-radius: 16px;
            border: 1px solid rgba(255, 255, 255, 0.15);
            transition: all 0.3s ease;
            cursor: pointer;
        }

        .hero-stat-item:hover {
            background: rgba(255, 255, 255, 0.15);
            transform: translateY(-3px);
        }

        .hero-stat-label {
            font-size: 0.65rem;
            text-transform: uppercase;
            letter-spacing: 1px;
            color: rgba(255, 255, 255, 0.7);
        }

        .hero-stat-value {
            font-size: 1.1rem;
            font-weight: 700;
            color: #fbbf24;
        }

        /* ========== SECCIONES ========== */
        .section-header {
            margin-bottom: 1.5rem;
            margin-top: 0.5rem;
        }

        .section-title {
            font-size: 1.3rem;
            font-weight: 700;
            color: #1e293b;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .section-title i {
            color: #f97316;
            font-size: 1.3rem;
        }

        .section-divider {
            height: 3px;
            width: 50px;
            background: linear-gradient(90deg, #f97316, #ffb347);
            border-radius: 3px;
            margin-top: 0.5rem;
        }

        /* ========== CONTENEDOR BLANCO ========== */
        .white-container {
            background: white;
            border-radius: 24px;
            padding: 1.8rem;
            margin-bottom: 2rem;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.05);
            border: 1px solid #eef2f6;
        }

        /* ========== CARDS DE ACCIONES ========== */
        .action-card {
            background: #f8fafc;
            border-radius: 20px;
            padding: 1.2rem 0.8rem;
            text-align: center;
            transition: all 0.3s ease;
            cursor: pointer;
            border: 1px solid #eef2f6;
            height: 130px;
            display: flex;
            flex-direction: column;
            justify-content: center;
            align-items: center;
        }

        .action-card:hover {
            transform: translateY(-5px);
            background: white;
            box-shadow: 0 12px 24px -10px rgba(249, 115, 22, 0.2);
            border-color: #f97316;
        }

        .action-icon {
            width: 55px;
            height: 55px;
            background: #fef3e8;
            border-radius: 16px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            margin-bottom: 0.8rem;
            transition: all 0.3s ease;
        }

        .action-card:hover .action-icon {
            background: #f97316;
        }

        .action-icon i {
            font-size: 1.5rem;
            color: #f97316;
            transition: all 0.3s ease;
        }

        .action-card:hover .action-icon i {
            color: white;
        }

        .action-card h4 {
            font-size: 0.9rem;
            font-weight: 700;
            margin-bottom: 0.2rem;
            color: #1e293b;
        }

        .action-card p {
            font-size: 0.7rem;
            color: #64748b;
            margin-bottom: 0;
        }

        /* ========== CARDS DE MÉTRICAS CLICKEABLES ========== */
        .metric-card {
            border-radius: 20px;
            padding: 1.2rem;
            transition: all 0.3s ease;
            height: 140px;
            position: relative;
            overflow: hidden;
            color: white;
            cursor: pointer;
        }

        .metric-card::before {
            content: '';
            position: absolute;
            top: -20px;
            right: -20px;
            width: 100px;
            height: 100px;
            background: rgba(255, 255, 255, 0.1);
            border-radius: 50%;
            pointer-events: none;
        }

        .metric-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 12px 25px rgba(0, 0, 0, 0.15);
        }

        .metric-card .metric-icon-bg {
            position: absolute;
            right: 15px;
            top: 50%;
            transform: translateY(-50%);
            font-size: 3rem;
            opacity: 0.15;
        }

        .metric-card h3 {
            font-size: 1.8rem;
            font-weight: 800;
            margin: 0 0 5px 0;
        }

        .metric-card p {
            font-size: 0.8rem;
            margin: 0;
            opacity: 0.9;
        }

        .metric-footer {
            margin-top: 0.8rem;
            font-size: 0.7rem;
            opacity: 0.8;
        }

        .bg-metric-orange { background: #f97316; }
        .bg-metric-blue { background: #3b82f6; }
        .bg-metric-green { background: #10b981; }
        .bg-metric-purple { background: #8b5cf6; }

        /* ========== CHART CARD ========== */
        .chart-card {
            background: white;
            border-radius: 20px;
            padding: 1.5rem;
            border: 1px solid #eef2f6;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.04);
            height: 100%;
        }

        .chart-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1.5rem;
            flex-wrap: wrap;
        }

        .chart-title {
            font-size: 1rem;
            font-weight: 700;
            color: #1e293b;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .chart-title i {
            color: #f97316;
        }

        /* ========== STOCK CRÍTICO CON SCROLL ========== */
        .stock-scroll-container {
            max-height: 280px;
            overflow-y: auto;
            padding-right: 5px;
        }

        .stock-scroll-container::-webkit-scrollbar {
            width: 6px;
        }

        .stock-scroll-container::-webkit-scrollbar-track {
            background: #f1f5f9;
            border-radius: 10px;
        }

        .stock-scroll-container::-webkit-scrollbar-thumb {
            background: #f97316;
            border-radius: 10px;
        }

        .stock-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 0.8rem 0;
            border-bottom: 1px solid #f1f5f9;
        }

        .stock-item:last-child {
            border-bottom: none;
        }

        .stock-name {
            font-size: 0.85rem;
            font-weight: 500;
            color: #334155;
        }

        .stock-badge {
            background: #fee2e2;
            color: #dc2626;
            padding: 0.2rem 0.7rem;
            border-radius: 20px;
            font-size: 0.7rem;
            font-weight: 600;
        }

        /* ========== TOP PRODUCTOS ========== */
        .top-product-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 0.7rem 0;
            border-bottom: 1px solid #f1f5f9;
        }

        .top-product-rank {
            width: 28px;
            height: 28px;
            background: #fef3e8;
            border-radius: 10px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 0.75rem;
            font-weight: 700;
            color: #f97316;
        }

        /* ========== MODALES ========== */
        .modal-content {
            border-radius: 20px;
            overflow: hidden;
        }
        
        .modal-header {
            border-bottom: none;
            padding: 1.2rem 1.5rem;
        }
        
        .modal-body {
            padding: 1.5rem;
        }
        
        .table-detalle {
            font-size: 0.85rem;
        }
        
        .table-detalle th {
            background: #f8fafc;
            font-weight: 600;
        }

        #modalCambiarPassword {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            z-index: 20000;
            background: rgba(0, 0, 0, 0.7);
            backdrop-filter: blur(4px);
            display: flex !important;
            align-items: center;
            justify-content: center;
            padding: 10px;
        }

        #modalCambiarPassword[style*="display: none"] {
            display: none !important;
        }

        .content-wrapper.modal-active {
            pointer-events: none;
        }

        .swal2-container {
            z-index: 30000 !important;
        }

        /* ========== RESPONSIVE ========== */
        @media (max-width: 768px) {
            .hero-premium h1 { font-size: 1.5rem; }
            .hero-premium p { font-size: 0.9rem; }
            .hero-content { padding: 1.5rem; }
            .hero-stats { gap: 0.8rem; }
            .hero-stat-item { padding: 0.4rem 1rem; }
            .hero-stat-value { font-size: 0.9rem; }
            .white-container { padding: 1.2rem; }
            .metric-card h3 { font-size: 1.3rem; }
            .metric-card { height: 130px; }
        }
    </style>
</head>
<body>

<div class="content-wrapper <?= $mostrar_modal_password ? 'modal-active' : '' ?>">
    
    <!-- MODAL CAMBIO CONTRASEÑA -->
    <?php if ($mostrar_modal_password): ?>
    <div id="modalCambiarPassword">
        <div class="modal-dialog" style="max-width: 450px;">
            <div class="modal-content">
                <div class="modal-header" style="background: #f97316; color: white;">
                    <h5 class="modal-title"><i class="fas fa-shield-alt me-2"></i> Cambiar Contraseña</h5>
                </div>
                <div class="modal-body">
                    <div class="alert alert-warning py-2" style="background: #fef3c7; border-left: 3px solid #f59e0b;">
                        <small><i class="fas fa-exclamation-triangle me-2"></i> Cambia tu contraseña por defecto <strong>"Pescadores1"</strong></small>
                    </div>
                    <div id="error-mensaje" class="alert alert-danger py-2" style="display: none; font-size: 0.8rem;"></div>
                    
                    <form id="formCambiarPassword">
                        <input type="hidden" name="csrf_token" value="<?= csrf_token(); ?>">
                        <input type="hidden" name="ajax_cambio_password" value="1">
                        
                        <div class="mb-3">
                            <label class="form-label small fw-bold">Nueva Contraseña</label>
                            <div class="input-group">
                                <input type="password" class="form-control" id="password_nueva" name="password_nueva">
                                <button class="btn btn-outline-secondary" type="button" onclick="togglePasswordField('password_nueva', this)">
                                    <i class="fas fa-eye"></i>
                                </button>
                            </div>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label small fw-bold">Confirmar Contraseña</label>
                            <div class="input-group">
                                <input type="password" class="form-control" id="password_confirmar" name="password_confirmar">
                                <button class="btn btn-outline-secondary" type="button" onclick="togglePasswordField('password_confirmar', this)">
                                    <i class="fas fa-eye"></i>
                                </button>
                            </div>
                        </div>
                        
                        <button type="submit" class="btn w-100" style="background: #f97316; color: white;" id="btnCambiar">
                            <i class="fas fa-sync-alt me-2"></i> Cambiar Contraseña
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- MODALES DE INFORMACIÓN -->
    <!-- Modal Ventas Hoy -->
    <div class="modal fade" id="modalVentasHoy" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header" style="background: #f97316; color: white;">
                    <h5 class="modal-title"><i class="fas fa-calendar-day me-2"></i> Ventas del Día - <?= date('d/m/Y') ?></h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="row mb-3">
                        <div class="col-md-4">
                            <div class="card bg-light">
                                <div class="card-body text-center">
                                    <h6>Total Ventas</h6>
                                    <h4 class="text-primary">$<?= number_format($totalVentasDia, 2) ?></h4>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="card bg-light">
                                <div class="card-body text-center">
                                    <h6>Tickets</h6>
                                    <h4 class="text-success"><?= $ticketsHoy ?></h4>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="card bg-light">
                                <div class="card-body text-center">
                                    <h6>Ticket Promedio</h6>
                                    <h4 class="text-info">$<?= number_format($ticketPromedio, 2) ?></h4>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="table-responsive">
                        <table class="table table-hover table-detalle">
                            <thead>
                                <tr><th>Producto</th><th class="text-center">Cantidad</th><th class="text-end">Total</th></tr>
                            </thead>
                            <tbody>
                            <?php
                            $resDetalle = $conn->query("
                                SELECT p.nombre, v.cantidad_vendida, (v.cantidad_vendida * p.precio_venta) AS total
                                FROM ventas v JOIN productos p ON v.id_producto = p.id
                                WHERE DATE(v.fecha_venta) = CURDATE() ORDER BY v.fecha_venta DESC LIMIT 50
                            ");
                            if($resDetalle && $resDetalle->num_rows > 0):
                                while($v = $resDetalle->fetch_assoc()):
                            ?>
                                <tr>
                                    <td><?= htmlspecialchars($v['nombre']) ?></td>
                                    <td class="text-center"><?= $v['cantidad_vendida'] ?></td>
                                    <td class="text-end">$<?= number_format($v['total'], 2) ?></td>
                                </tr>
                            <?php endwhile; else: ?>
                                <tr><td colspan="3" class="text-center text-muted py-4">No hay ventas hoy</td></tr>
                            <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Modal Ventas Semana -->
    <div class="modal fade" id="modalVentasSemana" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header" style="background: #3b82f6; color: white;">
                    <h5 class="modal-title"><i class="fas fa-calendar-week me-2"></i> Ventas de la Semana</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="row mb-3">
                        <div class="col-md-6">
                            <div class="card bg-light">
                                <div class="card-body text-center">
                                    <h6>Total Semana</h6>
                                    <h4 class="text-primary">$<?= number_format($totalVentasSemana, 2) ?></h4>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="card bg-light">
                                <div class="card-body text-center">
                                    <h6>Promedio Diario</h6>
                                    <h4 class="text-info">$<?= number_format($totalVentasSemana / 7, 2) ?></h4>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="table-responsive">
                        <table class="table table-hover table-detalle">
                            <thead>
                                <tr><th>Día</th><th class="text-end">Ventas</th></tr>
                            </thead>
                            <tbody>
                                <?php foreach($ventasPorDiaSemana as $dia): ?>
                                <tr>
                                    <td><?= $dia['dia'] ?></td>
                                    <td class="text-end">$<?= number_format($dia['total'], 2) ?></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <hr>
                    <h6 class="mt-3">Productos más vendidos de la semana</h6>
                    <div class="table-responsive">
                        <table class="table table-hover table-detalle">
                            <thead><tr><th>Producto</th><th class="text-center">Cantidad</th><th class="text-end">Total</th></tr></thead>
                            <tbody>
                            <?php
                            $resTopSemana = $conn->query("
                                SELECT p.nombre, SUM(v.cantidad_vendida) AS cantidad, SUM(v.cantidad_vendida * p.precio_venta) AS total
                                FROM ventas v JOIN productos p ON v.id_producto = p.id
                                WHERE DATE(v.fecha_venta) BETWEEN '$inicioSemana' AND '$finSemana'
                                GROUP BY p.id ORDER BY total DESC LIMIT 10
                            ");
                            if($resTopSemana && $resTopSemana->num_rows > 0):
                                while($item = $resTopSemana->fetch_assoc()):
                            ?>
                                <tr>
                                    <td><?= htmlspecialchars($item['nombre']) ?></td>
                                    <td class="text-center"><?= $item['cantidad'] ?></td>
                                    <td class="text-end">$<?= number_format($item['total'], 2) ?></td>
                                </tr>
                            <?php endwhile; endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Modal Usuarios -->
    <div class="modal fade" id="modalUsuarios" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header" style="background: #8b5cf6; color: white;">
                    <h5 class="modal-title"><i class="fas fa-users me-2"></i> Usuarios del Sistema</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="row mb-3">
                        <div class="col-md-4">
                            <div class="card bg-light">
                                <div class="card-body text-center">
                                    <h6>Total Usuarios</h6>
                                    <h4 class="text-primary"><?= $totalUsuarios ?></h4>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="card bg-light">
                                <div class="card-body text-center">
                                    <h6>Usuarios Activos</h6>
                                    <h4 class="text-success"><?= $usuariosActivos ?></h4>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="card bg-light">
                                <div class="card-body text-center">
                                    <h6>Administradores</h6>
                                    <h4 class="text-warning">
                                        <?php 
                                        $admins = $conn->query("SELECT COUNT(*) as total FROM usuarios WHERE rol='administrador'")->fetch_assoc();
                                        echo $admins['total'];
                                        ?>
                                    </h4>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="table-responsive">
                        <table class="table table-hover table-detalle">
                            <thead>
                                <tr><th>Nombre</th><th>Email</th><th>Rol</th><th>Estado</th></tr>
                            </thead>
                            <tbody>
                            <?php
                            $resUsers = $conn->query("SELECT nombre, email, rol, activo FROM usuarios ORDER BY nombre");
                            while($user = $resUsers->fetch_assoc()):
                            ?>
                                <tr>
                                    <td><?= htmlspecialchars($user['nombre']) ?></td>
                                    <td><?= htmlspecialchars($user['email']) ?></td>
                                    <td><span class="badge <?= $user['rol'] == 'administrador' ? 'bg-warning' : 'bg-info' ?>"><?= ucfirst($user['rol']) ?></span></td>
                                    <td><span class="badge <?= $user['activo'] == 1 ? 'bg-success' : 'bg-secondary' ?>"><?= $user['activo'] == 1 ? 'Activo' : 'Inactivo' ?></span></td>
                                </tr>
                            <?php endwhile; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Modal Stock Crítico -->
    <div class="modal fade" id="modalStockGeneral" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header" style="background: #10b981; color: white;">
                    <h5 class="modal-title"><i class="fas fa-boxes me-2"></i> Stock Crítico</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="alert alert-warning">
                        <i class="fas fa-exclamation-triangle me-2"></i>
                        Hay <strong><?= $totalStockBajo ?></strong> items con stock por debajo del nivel óptimo
                    </div>
                    <div class="row">
                        <div class="col-md-6">
                            <h6 class="text-primary"><i class="fas fa-box me-2"></i> Productos con stock bajo</h6>
                            <div class="border rounded p-2 mt-2" style="max-height: 300px; overflow-y: auto;">
                                <?php 
                                $productosBajo = array_filter($itemsStockBajo, function($item) { return $item['tipo_inventario'] === 'producto'; });
                                foreach($productosBajo as $p): 
                                ?>
                                    <div class="d-flex justify-content-between p-2 border-bottom">
                                        <span><?= htmlspecialchars($p['nombre']) ?></span>
                                        <span class="badge bg-danger"><?= $p['cantidad'] ?> uds</span>
                                    </div>
                                <?php endforeach; ?>
                                <?php if(empty($productosBajo)): ?>
                                    <p class="text-muted text-center py-3">Sin productos críticos</p>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <h6 class="text-info"><i class="fas fa-tint me-2"></i> Insumos con stock bajo</h6>
                            <div class="border rounded p-2 mt-2" style="max-height: 300px; overflow-y: auto;">
                                <?php 
                                $insumosBajo = array_filter($itemsStockBajo, function($item) { return $item['tipo_inventario'] === 'insumo'; });
                                foreach($insumosBajo as $i): 
                                ?>
                                    <div class="d-flex justify-content-between p-2 border-bottom">
                                        <span><?= htmlspecialchars($i['nombre']) ?></span>
                                        <span class="badge bg-danger"><?= $i['cantidad'] ?></span>
                                    </div>
                                <?php endforeach; ?>
                                <?php if(empty($insumosBajo)): ?>
                                    <p class="text-muted text-center py-3">Sin insumos críticos</p>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="container-fluid px-4">
        
        <!-- HERO SECTION CON IMAGEN PERSONALIZADA -->
        <div class="hero-premium">
            <div class="hero-content">
                <div class="hero-greeting">
                    <i class="fas fa-fish me-1"></i> PESCADORES DE LA PREHISTORIA
                </div>
                <h1>¡Bienvenido, <span><?= htmlspecialchars($nombre_completo) ?></span>!</h1>
                <p>¿Qué vamos a hacer hoy?</p>
                <div class="hero-stats">
                    <div class="hero-stat-item" data-bs-toggle="modal" data-bs-target="#modalVentasHoy">
                        <div class="hero-stat-label">Fecha</div>
                        <div class="hero-stat-value"><?= date('d/m/Y') ?></div>
                    </div>
                    <div class="hero-stat-item" data-bs-toggle="modal" data-bs-target="#modalUsuarios">
                        <div class="hero-stat-label">Usuarios</div>
                        <div class="hero-stat-value"><?= $totalUsuarios ?></div>
                    </div>
                    <div class="hero-stat-item" data-bs-toggle="modal" data-bs-target="#modalStockGeneral">
                        <div class="hero-stat-label">Stock Crítico</div>
                        <div class="hero-stat-value"><?= $totalStockBajo ?></div>
                    </div>
                </div>
            </div>
        </div>

        <!-- SECCIÓN 1: ACCIONES RÁPIDAS -->
        <div class="white-container">
            <div class="section-header">
                <div class="section-title">
                    <i class="fas fa-bolt"></i>
                    <span>Acciones Rápidas</span>
                </div>
                <div class="section-divider"></div>
            </div>
            <div class="row g-3">
                <div class="col-md-4 col-lg-2">
                    <div class="action-card" onclick="window.location.href='ventas.php'">
                        <div class="action-icon mx-auto">
                            <i class="fas fa-cash-register"></i>
                        </div>
                        <h4>Realizar Venta</h4>
                        <p>Registrar nueva venta</p>
                    </div>
                </div>
                <div class="col-md-4 col-lg-2">
                    <div class="action-card" onclick="window.location.href='inventario.php'">
                        <div class="action-icon mx-auto">
                            <i class="fas fa-boxes"></i>
                        </div>
                        <h4>Inventario</h4>
                        <p>Gestionar stock</p>
                    </div>
                </div>
                <div class="col-md-4 col-lg-2">
                    <div class="action-card" onclick="window.location.href='estadisticas.php'">
                        <div class="action-icon mx-auto">
                            <i class="fas fa-chart-line"></i>
                        </div>
                        <h4>Estadísticas</h4>
                        <p>Ver métricas y KPI</p>
                    </div>
                </div>
                <div class="col-md-4 col-lg-2">
                    <div class="action-card" onclick="window.location.href='productos.php'">
                        <div class="action-icon mx-auto">
                            <i class="fas fa-tags"></i>
                        </div>
                        <h4>Productos</h4>
                        <p>Registrar productos</p>
                    </div>
                </div>
                <div class="col-md-4 col-lg-2">
                    <div class="action-card" onclick="window.location.href='proveedores.php'">
                        <div class="action-icon mx-auto">
                            <i class="fas fa-truck"></i>
                        </div>
                        <h4>Proveedores</h4>
                        <p>Gestionar proveedores</p>
                    </div>
                </div>
                <div class="col-md-4 col-lg-2">
                    <div class="action-card" onclick="window.location.href='reportes.php'">
                        <div class="action-icon mx-auto">
                            <i class="fas fa-file-alt"></i>
                        </div>
                        <h4>Reportes</h4>
                        <p>Reporte de ventas</p>
                    </div>
                </div>
            </div>
        </div>

        <!-- SECCIÓN 2: MÉTRICAS CLAVE (CLICKEABLES) -->
        <div class="section-header">
            <div class="section-title">
                <i class="fas fa-chart-simple"></i>
                <span>Métricas Clave</span>
            </div>
            <div class="section-divider"></div>
        </div>
        <div class="row g-4 mb-4">
            <div class="col-md-6 col-lg-3">
                <div class="metric-card bg-metric-orange" data-bs-toggle="modal" data-bs-target="#modalVentasHoy">
                    <div class="metric-icon-bg"><i class="fas fa-calendar-day"></i></div>
                    <h3>$<?= number_format($totalVentasDia, 2) ?></h3>
                    <p>Ventas Hoy</p>
                    <div class="metric-footer">
                        <i class="fas fa-ticket-alt me-1"></i> <?= $ticketsHoy ?> tickets
                    </div>
                </div>
            </div>
            <div class="col-md-6 col-lg-3">
                <div class="metric-card bg-metric-blue" data-bs-toggle="modal" data-bs-target="#modalVentasSemana">
                    <div class="metric-icon-bg"><i class="fas fa-calendar-week"></i></div>
                    <h3>$<?= number_format($totalVentasSemana, 2) ?></h3>
                    <p>Ventas Semana</p>
                    <div class="metric-footer">
                        <i class="fas fa-chart-line me-1"></i> últimos 7 días
                    </div>
                </div>
            </div>
            <div class="col-md-6 col-lg-3">
                <div class="metric-card bg-metric-green">
                    <div class="metric-icon-bg"><i class="fas fa-wallet"></i></div>
                    <h3>$<?= number_format($utilidadHoy, 2) ?></h3>
                    <p>Utilidad Estimada Hoy</p>
                    <div class="metric-footer">
                        <i class="fas fa-percentage me-1"></i> <?= $totalVentasDia > 0 ? number_format(($utilidadHoy / $totalVentasDia) * 100, 1) : 0 ?>% margen
                    </div>
                </div>
            </div>
            <div class="col-md-6 col-lg-3">
                <div class="metric-card bg-metric-purple" data-bs-toggle="modal" data-bs-target="#modalUsuarios">
                    <div class="metric-icon-bg"><i class="fas fa-users"></i></div>
                    <h3><?= $totalUsuarios ?></h3>
                    <p>Usuarios Registrados</p>
                    <div class="metric-footer">
                        <i class="fas fa-user-check me-1"></i> <?= $usuariosActivos ?> activos
                    </div>
                </div>
            </div>
        </div>

        <!-- SECCIÓN 3: ANÁLISIS DE VENTAS Y STOCK CRÍTICO -->
        <div class="section-header">
            <div class="section-title">
                <i class="fas fa-chart-line"></i>
                <span>Análisis de Ventas</span>
            </div>
            <div class="section-divider"></div>
        </div>
        <div class="row g-4">
            <div class="col-lg-8">
                <div class="chart-card">
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
                    <canvas id="chartVentas" style="height: 320px; width: 100%;"></canvas>
                </div>
            </div>
            
            <div class="col-lg-4">
                <div class="chart-card">
                    <div class="chart-title mb-3">
                        <i class="fas fa-trophy"></i>
                        <span>Top Productos Más Vendidos</span>
                    </div>
                    <?php if(count($topProductos) > 0): ?>
                        <?php foreach($topProductos as $index => $p): ?>
                            <div class="top-product-item">
                                <div class="d-flex align-items-center gap-2">
                                    <div class="top-product-rank"><?= $index + 1 ?></div>
                                    <span class="fw-medium"><?= htmlspecialchars($p['nombre']) ?></span>
                                </div>
                                <span class="fw-bold" style="color: #f97316;"><?= intval($p['total_vendido']) ?> uds</span>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div class="text-center py-4 text-muted">
                            <i class="fas fa-chart-simple fa-2x mb-2 opacity-50"></i>
                            <p class="mb-0 small">No hay datos disponibles</p>
                        </div>
                    <?php endif; ?>
                    
                    <hr class="my-3">
                    
                    <div class="chart-title mb-3">
                        <i class="fas fa-exclamation-triangle"></i>
                        <span>Stock Crítico</span>
                        <span class="badge bg-danger ms-2"><?= $totalStockBajo ?> items</span>
                    </div>
                    <?php if($totalStockBajo > 0): ?>
                        <div class="stock-scroll-container">
                            <?php foreach(array_slice($itemsStockBajo, 0, 8) as $item): ?>
                                <div class="stock-item">
                                    <div class="stock-name">
                                        <i class="fas <?= $item['tipo_inventario'] === 'insumo' ? 'fa-tint' : 'fa-box' ?> me-2" style="color: <?= $item['tipo_inventario'] === 'insumo' ? '#06b6d4' : '#f97316' ?>;"></i>
                                        <?= htmlspecialchars($item['nombre']) ?>
                                        <small class="text-muted ms-1">(<?= $item['tipo_inventario'] === 'insumo' ? 'Insumo' : 'Producto' ?>)</small>
                                    </div>
                                    <div class="stock-badge"><?= $item['cantidad'] ?> uds</div>
                                </div>
                            <?php endforeach; ?>
                            <?php if($totalStockBajo > 8): ?>
                                <div class="text-center mt-2">
                                    <small class="text-muted">+<?= $totalStockBajo - 8 ?> items más</small>
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php else: ?>
                        <div class="text-center py-4 text-muted">
                            <i class="fas fa-check-circle fa-2x mb-2 text-success opacity-50"></i>
                            <p class="mb-0 small">No hay items con stock bajo</p>
                        </div>
                    <?php endif; ?>
                    
                    <div class="text-center mt-3">
                        <button class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#modalStockGeneral">
                            <i class="fas fa-eye me-1"></i> Ver todos
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

<script>
// Gráfica de ventas
const ventasData = <?= json_encode(array_map('floatval', $ventasPorDia)) ?>;
const ctxVentas = document.getElementById('chartVentas').getContext('2d');

new Chart(ctxVentas, {
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
        maintainAspectRatio: true,
        plugins: {
            legend: { display: false },
            tooltip: {
                callbacks: {
                    label: function(context) {
                        return '$' + context.raw.toLocaleString();
                    }
                },
                backgroundColor: '#1e293b',
                titleColor: '#fff',
                bodyColor: '#f97316'
            }
        },
        scales: {
            y: {
                beginAtZero: true,
                grid: { color: '#e2e8f0' },
                ticks: { callback: function(value) { return '$' + value.toLocaleString(); } }
            },
            x: { grid: { display: false } }
        }
    }
});

// Función para toggle de contraseña
function togglePasswordField(fieldId, button) {
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

// Envío AJAX cambio contraseña
<?php if ($mostrar_modal_password): ?>
document.getElementById('formCambiarPassword')?.addEventListener('submit', function(e) {
    e.preventDefault();
    
    const password = document.getElementById('password_nueva').value;
    const confirm = document.getElementById('password_confirmar').value;
    const errorDiv = document.getElementById('error-mensaje');
    const submitBtn = document.getElementById('btnCambiar');
    
    errorDiv.style.display = 'none';
    
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
    
    submitBtn.disabled = true;
    submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i> Cambiando...';
    
    const formData = new FormData();
    formData.append('csrf_token', document.querySelector('input[name="csrf_token"]').value);
    formData.append('ajax_cambio_password', '1');
    formData.append('password_nueva', password);
    formData.append('password_confirmar', confirm);
    
    fetch(window.location.href, {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            const modal = document.getElementById('modalCambiarPassword');
            modal.style.display = 'none';
            document.querySelector('.content-wrapper').classList.remove('modal-active');
            
            Swal.fire({
                icon: 'success',
                title: '¡Contraseña cambiada!',
                text: data.message,
                timer: 2000,
                showConfirmButton: false,
                timerProgressBar: true
            }).then(() => {
                window.location.reload();
            });
        } else {
            errorDiv.textContent = data.message;
            errorDiv.style.display = 'block';
            submitBtn.disabled = false;
            submitBtn.innerHTML = '<i class="fas fa-sync-alt me-2"></i> Cambiar Contraseña';
        }
    })
    .catch(error => {
        errorDiv.textContent = 'Error al conectar con el servidor';
        errorDiv.style.display = 'block';
        submitBtn.disabled = false;
        submitBtn.innerHTML = '<i class="fas fa-sync-alt me-2"></i> Cambiar Contraseña';
    });
});
<?php endif; ?>

// Control de inactividad
let tiempoInactivo = 0;
const TIEMPO_EXPIRACION = 30 * 60 * 1000;
let advertenciaMostrada = false;

function reiniciarContador() {
    tiempoInactivo = 0;
    advertenciaMostrada = false;
}

setInterval(() => {
    tiempoInactivo += 1000;
    
    if (tiempoInactivo >= 29 * 60 * 1000 && !advertenciaMostrada) {
        advertenciaMostrada = true;
        Swal.fire({
            icon: 'warning',
            title: 'Sesión por expirar',
            text: 'Tu sesión expirará en 1 minuto por inactividad',
            confirmButtonText: 'Seguir aquí',
            cancelButtonText: 'Salir',
            confirmButtonColor: '#f97316',
            background: '#fff',
            borderRadius: '16px'
        }).then((result) => {
            if (result.isConfirmed) {
                fetch('mantener_sesion.php').catch(() => {});
                reiniciarContador();
            } else {
                window.location.href = 'logout.php';
            }
        });
    }
    
    if (tiempoInactivo >= TIEMPO_EXPIRACION) {
        Swal.fire({
            icon: 'info',
            title: 'Sesión expirada',
            text: 'Redirigiendo al login...',
            timer: 2000,
            showConfirmButton: false,
            background: '#fff',
            borderRadius: '16px'
        }).then(() => {
            window.location.href = 'login.php?expired=1';
        });
    }
}, 1000);

['mousemove', 'keydown', 'click', 'scroll', 'touchstart'].forEach(event => {
    document.addEventListener(event, reiniciarContador);
});
</script>

</body>
</html>