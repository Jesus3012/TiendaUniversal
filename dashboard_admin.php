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

// Función para detectar género basado en el nombre
function detectarGenero($nombre) {
    // Lista de nombres femeninos comunes en español
    $nombresFemeninos = [
        'maria', 'ana', 'laura', 'carmen', 'josefa', 'isabel', 'marta', 'patricia',
        'lucia', 'paula', 'andrea', 'claudia', 'silvia', 'monica', 'cristina',
        'angela', 'rocio', 'beatriz', 'elena', 'irene', 'alicia', 'raquel',
        'susana', 'vera', 'lorena', 'miriam', 'eva', 'noelia', 'margarita',
        'ines', 'dolores', 'concepcion', 'mercedes', 'antonia', 'manuela',
        'francisca', 'encarnacion', 'pilar', 'luisa', 'julia', 'sofia',
        'valentina', 'camila', 'valeria', 'fernanda', 'daniela', 'samanta',
        'giselle', 'ximena', 'adriana', 'carolina', 'karen', 'yessica',
        'marisol', 'mayte', 'diana', 'luz', 'ana maria', 'gloria'
    ];
    
    // Lista de nombres masculinos comunes en español
    $nombresMasculinos = [
        'jose', 'juan', 'carlos', 'francisco', 'luis', 'antonio', 'manuel',
        'javier', 'angel', 'david', 'alejandro', 'daniel', 'rafael', 'miguel',
        'fernando', 'jorge', 'pedro', 'andres', 'ramon', 'sergio', 'alberto',
        'ricardo', 'pablo', 'ruben', 'eduardo', 'francisco', 'federico',
        'emilio', 'enrique', 'felix', 'gabriel', 'hector', 'ivan', 'jesus',
        'joaquin', 'julio', 'leonardo', 'marco', 'mario', 'martin', 'nicolas',
        'oscar', 'raul', 'roberto', 'rodrigo', 'salvador', 'vicente',
        'victor', 'alfredo', 'alvaro', 'armando', 'arturo', 'cristian',
        'esteban', 'gerardo', 'guillermo', 'hugo', 'ignacio', 'mauricio',
        'omar', 'rene', 'santiago', 'tomas'
    ];
    
    $nombreLower = strtolower(trim($nombre));
    $partes = explode(' ', $nombreLower);
    $primerNombre = $partes[0];
    
    // Verificar si es nombre femenino
    if (in_array($primerNombre, $nombresFemeninos)) {
        return 'femenino';
    }
    
    // Verificar si es nombre masculino
    if (in_array($primerNombre, $nombresMasculinos)) {
        return 'masculino';
    }
    
    // Verificar terminaciones comunes
    if (substr($primerNombre, -1) === 'a') {
        return 'femenino';
    }
    
    if (substr($primerNombre, -1) === 'o' || substr($primerNombre, -1) === 'e') {
        return 'masculino';
    }
    
    // Por defecto, masculino
    return 'masculino';
}

// Detectar género basado en el nombre
$genero = detectarGenero($nombre_usuario);
$saludo = ($genero == 'femenino') ? "Bienvenida" : "Bienvenido";

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

        /* Hero Section - Versión Moderna CON IMAGEN */
        .hero-premium {
            position: relative;
            background: url('img/panel_principal.png') center center / cover no-repeat fixed;
            border-radius: 0 0 50px 50px;
            overflow: hidden;
            margin-bottom: 2.5rem;
            min-height: 500px;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        /* Overlay oscuro para mejorar legibilidad */
        .hero-overlay {
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0, 0, 0, 0.6);
            z-index: 1;
        }

        .hero-content {
            position: relative;
            z-index: 2;
            padding: 2rem;
            max-width: 900px;
            margin: 0 auto;
        }

        /* Badge superior */
        .hero-badge {
            display: inline-flex;
            align-items: center;
            gap: 10px;
            background: rgba(0, 0, 0, 0.5);
            backdrop-filter: blur(10px);
            padding: 0.6rem 1.8rem;
            border-radius: 50px;
            font-size: 0.85rem;
            font-weight: 600;
            letter-spacing: 2px;
            margin-bottom: 2rem;
            border: 1px solid rgba(255, 255, 255, 0.2);
            color: #fbbf24;
            animation: pulse 2s ease-in-out infinite;
        }

        .hero-badge i {
            font-size: 1rem;
            color: #f97316;
        }

        /* Contenedor del texto */
        .hero-text {
            display: flex;
            flex-direction: column;
            gap: 0.5rem;
            margin-bottom: 2rem;
        }

        /* Saludo */
        .hero-hello {
            font-size: 1.6rem;
            font-weight: 500;
            color: rgba(255, 255, 255, 0.9);
            letter-spacing: 1px;
        }

        /* Nombre del usuario - Sin degradado */
        .hero-user {
            font-size: 4rem;
            font-weight: 800;
            color: rgb(251, 154, 36);
            line-height: 1.2;
            text-shadow: 0 2px 10px rgba(0, 0, 0, 0.3);
            animation: float 3s ease-in-out infinite;
        }

        /* Línea decorativa - Sin degradado */
        .hero-divider {
            width: 80px;
            height: 3px;
            background: #f97316;
            margin: 1.5rem auto;
            border-radius: 3px;
        }

        /* Botón CTA - Sin degradado */
        .hero-cta {
            display: inline-flex;
            align-items: center;
            gap: 12px;
            background: #f97316;
            padding: 0.8rem 2rem;
            border-radius: 50px;
            font-size: 1.1rem;
            font-weight: 600;
            color: white;
            transition: all 0.3s ease;
            cursor: pointer;
            border: none;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.2);
        }

        .hero-cta:hover {
            background: #ea580c;
            transform: scale(1.05);
            box-shadow: 0 8px 25px rgba(249, 115, 22, 0.4);
        }

        .hero-cta i {
            font-size: 1.2rem;
        }

        /* Animaciones */
        @keyframes fadeInUp {
            from {
                opacity: 0;
                transform: translateY(40px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        @keyframes pulse {
            0%, 100% { 
                transform: scale(1);
            }
            50% { 
                transform: scale(1.03);
            }
        }

        @keyframes float {
            0%, 100% { 
                transform: translateY(0px);
            }
            50% { 
                transform: translateY(-8px);
            }
        }

        /* Animación de entrada */
        .hero-content {
            animation: fadeInUp 0.8s ease-out;
        }

        /* Responsive */
        @media (max-width: 992px) {
            .hero-premium {
                min-height: 420px;
            }
            
            .hero-user {
                font-size: 3rem;
            }
            
            .hero-hello {
                font-size: 1.3rem;
            }
            
            .hero-cta {
                font-size: 1rem;
                padding: 0.7rem 1.8rem;
            }
            
            .hero-badge {
                font-size: 0.75rem;
                padding: 0.5rem 1.5rem;
            }
        }

        @media (max-width: 768px) {
            .hero-premium {
                min-height: 380px;
                border-radius: 0 0 30px 30px;
            }
            
            .hero-user {
                font-size: 2.2rem;
            }
            
            .hero-hello {
                font-size: 1.1rem;
            }
            
            .hero-cta {
                font-size: 0.9rem;
                padding: 0.5rem 1.5rem;
                gap: 8px;
            }
            
            .hero-badge {
                font-size: 0.65rem;
                padding: 0.4rem 1.2rem;
                margin-bottom: 1.5rem;
                gap: 6px;
            }
            
            .hero-divider {
                width: 60px;
                margin: 1rem auto;
            }
        }

        @media (max-width: 576px) {
            .hero-premium {
                min-height: 320px;
            }
            
            .hero-user {
                font-size: 1.6rem;
            }
            
            .hero-hello {
                font-size: 0.9rem;
            }
            
            .hero-cta {
                font-size: 0.8rem;
                padding: 0.4rem 1.2rem;
            }
            
            .hero-badge {
                font-size: 0.55rem;
                padding: 0.3rem 1rem;
            }
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

        /* ========== CARDS DE MÉTRICAS ========== */
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

        /* Gradientes para métricas */
        .bg-metric-orange { background: linear-gradient(135deg, #f97316, #ea580c); }
        .bg-metric-blue { background: linear-gradient(135deg, #3b82f6, #2563eb); }
        .bg-metric-green { background: linear-gradient(135deg, #10b981, #059669); }
        .bg-metric-purple { background: linear-gradient(135deg, #8b5cf6, #7c3aed); }
        .bg-metric-teal { background: linear-gradient(135deg, #14b8a6, #0d9488); }
        .bg-metric-red { background: linear-gradient(135deg, #ef4444, #dc2626); }

        /* ========== CHART CARD ========== */
        .chart-card {
            background: white;
            border-radius: 20px;
            padding: 1.2rem;
            border: 1px solid #eef2f6;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.04);
            height: 100%;
            display: flex;
            flex-direction: column;
        }

        .chart-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1rem;
            flex-wrap: wrap;
        }

        .chart-title {
            font-size: 0.9rem;
            font-weight: 700;
            color: #1e293b;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .chart-title i {
            color: #f97316;
            font-size: 1rem;
        }

        /* ========== TOP PRODUCTOS ========== */
        .top-product-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 0.5rem 0;
            border-bottom: 1px solid #f1f5f9;
        }

        .top-product-rank {
            width: 24px;
            height: 24px;
            background: #fef3e8;
            border-radius: 8px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 0.7rem;
            font-weight: 700;
            color: #f97316;
        }

        .top-productos-scroll {
            overflow-y: auto;
            padding-right: 5px;
        }

        .top-productos-scroll::-webkit-scrollbar {
            width: 4px;
        }

        .top-productos-scroll::-webkit-scrollbar-track {
            background: #f1f5f9;
            border-radius: 10px;
        }

        .top-productos-scroll::-webkit-scrollbar-thumb {
            background: #5e5d5c;
            border-radius: 10px;
        }

        /* ========== STOCK CRÍTICO ========== */
        .stock-scroll-container {
            overflow-y: auto;
            padding-right: 5px;
        }

        .stock-scroll-container::-webkit-scrollbar {
            width: 4px;
        }

        .stock-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 0.5rem 0;
            border-bottom: 1px solid #f1f5f9;
            gap: 0.5rem;
        }

        .stock-item:last-child {
            border-bottom: none;
        }

        .stock-name {
            font-size: 0.8rem;
            font-weight: 500;
            color: #334155;
            flex: 1;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .stock-badge {
            padding: 0.2rem 0.6rem;
            border-radius: 20px;
            font-size: 0.65rem;
            font-weight: 600;
            white-space: nowrap;
            display: inline-flex;
            align-items: center;
            gap: 0.25rem;
        }

        .stock-badge.bg-warning {
            background: #fef3c7;
            color: #d97706;
        }

        .stock-badge.bg-danger {
            background: #fee2e2;
            color: #dc2626;
        }

        /* ========== GRÁFICA ========== */
        #chartVentas {
            width: 100% !important;
            height: 100% !important;
            min-height: 280px;
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

        /* Modal cambio contraseña */
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

        /* ========== BUSCADORES STOCK ========== */
        .producto-stock-item,
        .insumo-stock-item {
            display: flex !important;
        }

        .producto-stock-item.hidden-item,
        .insumo-stock-item.hidden-item {
            display: none !important;
        }

        .producto-stock-item[style*="display: none"],
        .insumo-stock-item[style*="display: none"] {
            display: none !important;
        }

        #searchProductosStock:focus,
        #searchInsumosStock:focus {
            box-shadow: none;
            border-color: #10b981;
        }

        .input-group-text {
            background-color: white;
        }

        #totalProductosBajo, #totalInsumosBajo {
            transition: all 0.3s ease;
        }

        #clearProductosSearch, #clearInsumosSearch {
            transition: all 0.3s ease;
        }

        #clearProductosSearch:hover, #clearInsumosSearch:hover {
            background-color: #dc3545;
            color: white;
            border-color: #dc3545;
        }

        #noResultsProductos, #noResultsInsumos {
            animation: fadeIn 0.3s ease-in-out;
        }

        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(-10px); }
            to { opacity: 1; transform: translateY(0); }
        }

        /* ========== RESPONSIVE ========== */
        @media (max-width: 991px) {
            .chart-card {
                margin-bottom: 1rem;
            }
            
            #chartVentas {
                min-height: 280px;
            }
        }

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
            
            .stock-name {
                white-space: normal;
                word-break: break-word;
            }
            
            .stock-badge {
                white-space: nowrap;
                font-size: 0.6rem;
                padding: 0.15rem 0.4rem;
            }
        }

        @media (max-width: 576px) {
            .modal-dialog {
                margin: 1rem auto !important;
                width: calc(100% - 2rem);
            }
        }

        /* Modern Stats Card */
.modern-stats-card {
    background: linear-gradient(135deg, #ffffff 0%, #f8fafc 100%);
    border-radius: 24px;
    padding: 1.25rem;
    border: none;
    box-shadow: 0 10px 25px -5px rgba(0, 0, 0, 0.05), 0 8px 10px -6px rgba(0, 0, 0, 0.02);
    height: 100%;
    display: flex;
    flex-direction: column;
}

/* Stats Section */
.stats-section {
    margin-bottom: 0;
}

.stats-section-header {
    display: flex;
    align-items: center;
    gap: 0.75rem;
    margin-bottom: 0.75rem;
}

.stats-section-icon {
    width: 32px;
    height: 32px;
    background: linear-gradient(135deg, #fef3e8, #ffe4d6);
    border-radius: 10px;
    display: flex;
    align-items: center;
    justify-content: center;
    color: #f97316;
    font-size: 0.9rem;
}

.stats-section-icon.warning {
    background: linear-gradient(135deg, #fef2f2, #fee2e2);
    color: #dc2626;
}

.stats-section-title {
    font-size: 0.9rem;
    font-weight: 700;
    color: #1e293b;
    margin: 0;
    flex: 1;
}

.stats-section-badge {
    font-size: 0.65rem;
    font-weight: 600;
    padding: 0.2rem 0.6rem;
    background: #eef2ff;
    color: #4f46e5;
    border-radius: 20px;
}

.stats-section-badge.danger {
    background: #fef2f2;
    color: #dc2626;
}

/* Top Products List */
.top-products-list {
    max-height: 140px;
    overflow-y: auto;
    padding-right: 5px;
}

.top-products-list::-webkit-scrollbar {
    width: 4px;
}

.top-products-list::-webkit-scrollbar-track {
    background: #f1f5f9;
    border-radius: 10px;
}

.top-products-list::-webkit-scrollbar-thumb {
    background: #f97316;
    border-radius: 10px;
}

.top-product-card {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 0.5rem 0.5rem;
    background: #ffffff;
    border-radius: 12px;
    transition: all 0.3s ease;
    border: 1px solid #f1f5f9;
    margin-bottom: 0.5rem;
}

.top-product-card:last-child {
    margin-bottom: 0;
}

.top-product-info {
    display: flex;
    align-items: center;
    gap: 0.6rem;
}

.top-product-rank {
    width: 24px;
    height: 24px;
    background: #f1f5f9;
    border-radius: 8px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 0.7rem;
    font-weight: 700;
    color: #64748b;
}

.top-product-rank.rank-1 {
    background: linear-gradient(135deg, #fbbf24, #f59e0b);
    color: white;
}

.top-product-rank.rank-2 {
    background: linear-gradient(135deg, #94a3b8, #64748b);
    color: white;
}

.top-product-rank.rank-3 {
    background: linear-gradient(135deg, #f97316, #ea580c);
    color: white;
}

.top-product-name {
    font-size: 0.8rem;
    font-weight: 500;
    color: #334155;
}

.top-product-stats {
    display: flex;
    align-items: baseline;
    gap: 0.2rem;
}

.top-product-quantity {
    font-size: 0.9rem;
    font-weight: 700;
    color: #f97316;
}

.top-product-unit {
    font-size: 0.65rem;
    color: #94a3b8;
}

/* Separador */
.section-separator {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 0.5rem;
    margin: 1rem 0;
}

.separator-line {
    width: 60px;
    height: 1px;
    background: linear-gradient(90deg, #e2e8f0, #cbd5e1, #e2e8f0);
}

.separator-dot {
    width: 5px;
    height: 5px;
    background: #cbd5e1;
    border-radius: 50%;
}

/* Stock List */
.stock-list {
    flex: 1;
    overflow-y: auto;
    min-height: 0;
    padding-right: 5px;
}

.stock-list::-webkit-scrollbar {
    width: 4px;
}

.stock-list::-webkit-scrollbar-track {
    background: #f1f5f9;
    border-radius: 10px;
}

.stock-list::-webkit-scrollbar-thumb {
    background: #dc2626;
    border-radius: 10px;
}

.stock-card {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 0.5rem 0.5rem;
    background: #ffffff;
    border-radius: 12px;
    transition: all 0.3s ease;
    border: 1px solid #f1f5f9;
    margin-bottom: 0.5rem;
}

.stock-card:last-child {
    margin-bottom: 0;
}

.stock-info {
    display: flex;
    align-items: center;
    gap: 0.6rem;
}

.stock-icon {
    width: 28px;
    height: 28px;
    border-radius: 8px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 0.75rem;
}

.stock-icon.producto {
    background: #fef3e8;
    color: #f97316;
}

.stock-icon.insumo {
    background: #e0f2fe;
    color: #06b6d4;
}

.stock-details {
    display: flex;
    flex-direction: column;
}

.stock-name {
    font-size: 0.8rem;
    font-weight: 600;
    color: #1e293b;
}

.stock-type {
    font-size: 0.6rem;
    color: #94a3b8;
}

.stock-status {
    text-align: right;
}

.stock-quantity {
    font-size: 0.9rem;
    font-weight: 700;
    margin-bottom: 0.2rem;
}

.stock-quantity span {
    font-size: 0.6rem;
    font-weight: 400;
    color: #94a3b8;
}

.stock-quantity.critical {
    color: #dc2626;
}

.stock-quantity.low {
    color: #ea580c;
}

.stock-quantity.warning {
    color: #d97706;
}

.stock-progress {
    width: 70px;
    height: 3px;
    background: #f1f5f9;
    border-radius: 3px;
    overflow: hidden;
}

.stock-progress-bar {
    height: 100%;
    background: #dc2626;
    border-radius: 3px;
    transition: width 0.3s ease;
}

/* More items */
.more-items {
    text-align: center;
    padding: 0.4rem;
    color: #94a3b8;
    font-size: 0.7rem;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 0.4rem;
}

/* Empty state */
.empty-state {
    text-align: center;
    padding: 1.5rem;
    color: #94a3b8;
}

.empty-state i {
    font-size: 1.5rem;
    margin-bottom: 0.5rem;
    opacity: 0.5;
}

.empty-state p {
    font-size: 0.75rem;
    margin: 0;
}

/* Botón ver todos */
.btn-view-all {
    width: 100%;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 0.5rem;
    padding: 0.5rem;
    background: transparent;
    border: 1px solid #e2e8f0;
    border-radius: 12px;
    color: #475569;
    font-size: 0.75rem;
    font-weight: 500;
    transition: all 0.3s ease;
    cursor: pointer;
    margin-top: 0.75rem;
}

.btn-view-all:hover {
    background: #f97316;
    border-color: #f97316;
    color: white;
}

.btn-view-all:hover i {
    color: white;
}

.btn-view-all i {
    font-size: 0.7rem;
    transition: all 0.3s ease;
}

.btn-view-all i:last-child {
    opacity: 0;
    transform: translateX(-5px);
}

.btn-view-all:hover i:last-child {
    opacity: 1;
    transform: translateX(0);
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
    <!-- Modal Ventas Hoy Mejorado -->
    <div class="modal fade" id="modalVentasHoy" tabindex="-1">
        <div class="modal-dialog modal-xl">
            <div class="modal-content border-0 shadow-lg">
                <div class="modal-header" style="background: linear-gradient(135deg, #f97316, #ea580c); border-bottom: none;">
                    <div class="d-flex align-items-center">
                        <div class="rounded-circle bg-white bg-opacity-25 p-2 me-3">
                            <i class="fas fa-calendar-day fa-fw text-white"></i>
                        </div>
                        <div>
                            <h5 class="modal-title text-white fw-bold mb-0">Ventas del Día</h5>
                            <p class="text-white-50 small mb-0"><?= date('l, d/m/Y') ?></p>
                        </div>
                    </div>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body p-4">
                    <!-- Stats Cards -->
                    <div class="row g-3 mb-4">
                        <div class="col-md-4">
                            <div class="card border-0 shadow-sm h-100">
                                <div class="card-body">
                                    <div class="d-flex justify-content-between align-items-start">
                                        <div>
                                            <p class="text-muted small mb-1">Total Ventas</p>
                                            <h3 class="fw-bold text-primary mb-0">$<?= number_format($totalVentasDia, 2) ?></h3>
                                        </div>
                                        <div class="bg-primary bg-opacity-10 rounded p-2">
                                            <i class="fas fa-chart-line fa-lg text-primary"></i>
                                        </div>
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
                                        <div class="bg-success bg-opacity-10 rounded p-2">
                                            <i class="fas fa-ticket-alt fa-lg text-success"></i>
                                        </div>
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
                                        <div class="bg-info bg-opacity-10 rounded p-2">
                                            <i class="fas fa-receipt fa-lg text-info"></i>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Search Input -->
                    <div class="mb-3">
                        <div class="input-group shadow-sm">
                            <span class="input-group-text bg-white border-end-0">
                                <i class="fas fa-search text-muted"></i>
                            </span>
                            <input type="text" id="searchHoy" class="form-control border-start-0" placeholder="Buscar producto...">
                        </div>
                    </div>

                    <!-- Table -->
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
                                <?php
                                $resDetalle = $conn->query("
                                    SELECT p.nombre, v.cantidad_vendida, (v.cantidad_vendida * p.precio_venta) AS total
                                    FROM ventas v JOIN productos p ON v.id_producto = p.id
                                    WHERE DATE(v.fecha_venta) = CURDATE() ORDER BY v.fecha_venta DESC LIMIT 100
                                ");
                                if($resDetalle && $resDetalle->num_rows > 0):
                                    while($v = $resDetalle->fetch_assoc()):
                                ?>
                                    <tr class="venta-row">
                                        <td class="fw-medium"><?= htmlspecialchars($v['nombre']) ?></td>
                                        <td class="text-center">
                                            <span class="badge bg-secondary bg-opacity-10 text-dark px-3 py-2"><?= $v['cantidad_vendida'] ?> uds</span>
                                        </td>
                                        <td class="text-end fw-bold text-primary">$<?= number_format($v['total'], 2) ?></td>
                                    </tr>
                                <?php endwhile; else: ?>
                                    <tr class="no-data-row">
                                        <td colspan="3" class="text-center py-5">
                                            <i class="fas fa-chart-line fa-3x text-muted mb-3 d-block"></i>
                                            <p class="text-muted mb-0">No hay ventas registradas hoy</p>
                                        </td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                        <div id="noResultsHoy" class="text-center py-5" style="display: none;">
                            <i class="fas fa-search fa-3x text-muted mb-3 d-block"></i>
                            <p class="text-muted mb-0">No se encontraron productos con ese nombre</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Modal Ventas Semana Mejorado -->
    <div class="modal fade" id="modalVentasSemana" tabindex="-1">
        <div class="modal-dialog modal-xl">
            <div class="modal-content border-0 shadow-lg">
                <div class="modal-header" style="background: linear-gradient(135deg, #3b82f6, #2563eb); border-bottom: none;">
                    <div class="d-flex align-items-center">
                        <div class="rounded-circle bg-white bg-opacity-25 p-2 me-3">
                            <i class="fas fa-calendar-week fa-fw text-white"></i>
                        </div>
                        <div>
                            <h5 class="modal-title text-white fw-bold mb-0">Ventas de la Semana</h5>
                            <p class="text-white-50 small mb-0"><?= date('d/m/Y', strtotime('monday this week')) ?> - <?= date('d/m/Y', strtotime('sunday this week')) ?></p>
                        </div>
                    </div>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body p-4">
                    <!-- Stats Cards -->
                    <div class="row g-3 mb-4">
                        <div class="col-md-6">
                            <div class="card border-0 shadow-sm">
                                <div class="card-body">
                                    <div class="d-flex justify-content-between align-items-start">
                                        <div>
                                            <p class="text-muted small mb-1">Total Semana</p>
                                            <h3 class="fw-bold text-primary mb-0">$<?= number_format($totalVentasSemana, 2) ?></h3>
                                        </div>
                                        <div class="bg-primary bg-opacity-10 rounded p-2">
                                            <i class="fas fa-chart-line fa-lg text-primary"></i>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="card border-0 shadow-sm">
                                <div class="card-body">
                                    <div class="d-flex justify-content-between align-items-start">
                                        <div>
                                            <p class="text-muted small mb-1">Promedio Diario</p>
                                            <h3 class="fw-bold text-info mb-0">$<?= number_format($totalVentasSemana / 7, 2) ?></h3>
                                        </div>
                                        <div class="bg-info bg-opacity-10 rounded p-2">
                                            <i class="fas fa-chart-bar fa-lg text-info"></i>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Chart Canvas for Week -->
                    <div class="mb-4">
                        <canvas id="chartSemanaModal" style="height: 250px;"></canvas>
                    </div>

                    <!-- Search Input -->
                    <div class="mb-3">
                        <div class="input-group shadow-sm">
                            <span class="input-group-text bg-white border-end-0">
                                <i class="fas fa-search text-muted"></i>
                            </span>
                            <input type="text" id="searchSemana" class="form-control border-start-0" placeholder="Buscar producto...">
                        </div>
                    </div>

                    <!-- Top Products Table -->
                    <div class="table-responsive">
                        <table class="table table-hover align-middle" id="tablaTopSemana">
                            <thead class="table-light">
                                <tr>
                                    <th class="py-3">Producto</th>
                                    <th class="text-center py-3" width="120">Cantidad</th>
                                    <th class="text-end py-3" width="150">Total</th>
                                </tr>
                            </thead>
                            <tbody id="tbodyTopSemana">
                                <?php
                                $resTopSemana = $conn->query("
                                    SELECT p.nombre, SUM(v.cantidad_vendida) AS cantidad, SUM(v.cantidad_vendida * p.precio_venta) AS total
                                    FROM ventas v JOIN productos p ON v.id_producto = p.id
                                    WHERE DATE(v.fecha_venta) BETWEEN '$inicioSemana' AND '$finSemana'
                                    GROUP BY p.id ORDER BY total DESC LIMIT 20
                                ");
                                if($resTopSemana && $resTopSemana->num_rows > 0):
                                    while($item = $resTopSemana->fetch_assoc()):
                                ?>
                                    <tr class="producto-semana-row">
                                        <td class="fw-medium"><?= htmlspecialchars($item['nombre']) ?></td>
                                        <td class="text-center">
                                            <span class="badge bg-secondary bg-opacity-10 text-dark px-3 py-2"><?= $item['cantidad'] ?> uds</span>
                                        </td>
                                        <td class="text-end fw-bold text-primary">$<?= number_format($item['total'], 2) ?></td>
                                    </tr>
                                <?php endwhile; endif; ?>
                            </tbody>
                        </table>
                        <div id="noResultsSemana" class="text-center py-5" style="display: none;">
                            <i class="fas fa-search fa-3x text-muted mb-3 d-block"></i>
                            <p class="text-muted mb-0">No se encontraron productos con ese nombre</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Modal Ventas Mes Mejorado -->
    <div class="modal fade" id="modalVentasMes" tabindex="-1">
        <div class="modal-dialog modal-xl">
            <div class="modal-content border-0 shadow-lg">
                <div class="modal-header" style="background: linear-gradient(135deg, #14b8a6, #0d9488); border-bottom: none;">
                    <div class="d-flex align-items-center">
                        <div class="rounded-circle bg-white bg-opacity-25 p-2 me-3">
                            <i class="fas fa-calendar-alt fa-fw text-white"></i>
                        </div>
                        <div>
                            <h5 class="modal-title text-white fw-bold mb-0">Ventas del Mes</h5>
                            <p class="text-white-50 small mb-0"><?= date('F Y') ?></p>
                        </div>
                    </div>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body p-4">
                    <!-- Stats Cards -->
                    <div class="row g-3 mb-4">
                        <div class="col-md-4">
                            <div class="card border-0 shadow-sm">
                                <div class="card-body">
                                    <div class="d-flex justify-content-between align-items-start">
                                        <div>
                                            <p class="text-muted small mb-1">Total del Mes</p>
                                            <h3 class="fw-bold text-primary mb-0">$<?= number_format($totalVentasMes, 2) ?></h3>
                                        </div>
                                        <div class="bg-primary bg-opacity-10 rounded p-2">
                                            <i class="fas fa-chart-line fa-lg text-primary"></i>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="card border-0 shadow-sm">
                                <div class="card-body">
                                    <div class="d-flex justify-content-between align-items-start">
                                        <div>
                                            <p class="text-muted small mb-1">Promedio Diario</p>
                                            <h3 class="fw-bold text-info mb-0">$<?= number_format($totalVentasMes / date('t'), 2) ?></h3>
                                        </div>
                                        <div class="bg-info bg-opacity-10 rounded p-2">
                                            <i class="fas fa-chart-bar fa-lg text-info"></i>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="card border-0 shadow-sm">
                                <div class="card-body">
                                    <div class="d-flex justify-content-between align-items-start">
                                        <div>
                                            <p class="text-muted small mb-1">Proyección</p>
                                            <h3 class="fw-bold text-success mb-0">$<?= number_format(($totalVentasMes / date('j')) * date('t'), 2) ?></h3>
                                        </div>
                                        <div class="bg-success bg-opacity-10 rounded p-2">
                                            <i class="fas fa-chart-line fa-lg text-success"></i>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Weekly Performance -->
                    <div class="mb-4">
                        <h6 class="fw-bold mb-3"><i class="fas fa-chart-pie me-2 text-primary"></i>Desempeño Semanal</h6>
                        <div class="table-responsive">
                            <table class="table table-hover align-middle">
                                <thead class="table-light">
                                    <tr>
                                        <th>Semana</th>
                                        <th class="text-end">Total Ventas</th>
                                        <th class="text-end">% del Mes</th>
                                        <th class="text-center">Progreso</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php
                                    $resSemanas = $conn->query("
                                        SELECT 
                                            WEEK(v.fecha_venta, 1) - WEEK(DATE_FORMAT(v.fecha_venta, '%Y-%m-01'), 1) + 1 AS semana_num,
                                            SUM(v.cantidad_vendida * p.precio_venta) AS total
                                        FROM ventas v
                                        JOIN productos p ON v.id_producto = p.id
                                        WHERE MONTH(v.fecha_venta) = MONTH(CURDATE()) 
                                        AND YEAR(v.fecha_venta) = YEAR(CURDATE())
                                        GROUP BY semana_num
                                        ORDER BY semana_num
                                    ");
                                    if($resSemanas && $resSemanas->num_rows > 0):
                                        while($semana = $resSemanas->fetch_assoc()):
                                            $porcentaje = $totalVentasMes > 0 ? ($semana['total'] / $totalVentasMes) * 100 : 0;
                                    ?>
                                        <tr>
                                            <td class="fw-medium">Semana <?= $semana['semana_num'] ?></td>
                                            <td class="text-end fw-bold text-primary">$<?= number_format($semana['total'], 2) ?></td>
                                            <td class="text-end"><?= number_format($porcentaje, 1) ?>%</td>
                                            <td class="text-center" width="200">
                                                <div class="progress" style="height: 8px;">
                                                    <div class="progress-bar bg-primary" style="width: <?= $porcentaje ?>%"></div>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endwhile; endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <!-- Search Input -->
                    <div class="mb-3">
                        <div class="input-group shadow-sm">
                            <span class="input-group-text bg-white border-end-0">
                                <i class="fas fa-search text-muted"></i>
                            </span>
                            <input type="text" id="searchMes" class="form-control border-start-0" placeholder="Buscar producto...">
                        </div>
                    </div>

                    <!-- Top Products Table -->
                    <div class="table-responsive">
                        <table class="table table-hover align-middle" id="tablaTopMes">
                            <thead class="table-light">
                                <tr>
                                    <th class="py-3">Producto</th>
                                    <th class="text-center py-3" width="120">Cantidad</th>
                                    <th class="text-end py-3" width="150">Total</th>
                                </tr>
                            </thead>
                            <tbody id="tbodyTopMes">
                                <?php
                                $resTopMes = $conn->query("
                                    SELECT p.nombre, SUM(v.cantidad_vendida) AS cantidad, SUM(v.cantidad_vendida * p.precio_venta) AS total
                                    FROM ventas v 
                                    JOIN productos p ON v.id_producto = p.id
                                    WHERE MONTH(v.fecha_venta) = MONTH(CURDATE()) 
                                    AND YEAR(v.fecha_venta) = YEAR(CURDATE())
                                    GROUP BY p.id 
                                    ORDER BY total DESC 
                                    LIMIT 20
                                ");
                                if($resTopMes && $resTopMes->num_rows > 0):
                                    while($item = $resTopMes->fetch_assoc()):
                                ?>
                                    <tr class="producto-mes-row">
                                        <td class="fw-medium"><?= htmlspecialchars($item['nombre']) ?></td>
                                        <td class="text-center">
                                            <span class="badge bg-secondary bg-opacity-10 text-dark px-3 py-2"><?= $item['cantidad'] ?> uds</span>
                                        </td>
                                        <td class="text-end fw-bold text-primary">$<?= number_format($item['total'], 2) ?></td>
                                    </tr>
                                <?php endwhile; endif; ?>
                            </tbody>
                        </table>
                        <div id="noResultsMes" class="text-center py-5" style="display: none;">
                            <i class="fas fa-search fa-3x text-muted mb-3 d-block"></i>
                            <p class="text-muted mb-0">No se encontraron productos con ese nombre</p>
                        </div>
                    </div>
                </div>

            </div>
        </div>
    </div>

    <!-- Modal Usuarios Mejorado -->
    <div class="modal fade" id="modalUsuarios" tabindex="-1">
        <div class="modal-dialog modal-xl">
            <div class="modal-content border-0 shadow-lg">
                <div class="modal-header" style="background: linear-gradient(135deg, #8b5cf6, #7c3aed); border-bottom: none;">
                    <div class="d-flex align-items-center">
                        <div class="rounded-circle bg-white bg-opacity-25 p-2 me-3">
                            <i class="fas fa-users fa-fw text-white"></i>
                        </div>
                        <div>
                            <h5 class="modal-title text-white fw-bold mb-0">Usuarios del Sistema</h5>
                            <p class="text-white-50 small mb-0">Gestión de acceso y roles</p>
                        </div>
                    </div>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body p-4">
                    <!-- Stats Cards -->
                    <div class="row g-3 mb-4">
                        <div class="col-md-4">
                            <div class="card border-0 shadow-sm">
                                <div class="card-body">
                                    <div class="d-flex justify-content-between align-items-start">
                                        <div>
                                            <p class="text-muted small mb-1">Total Usuarios</p>
                                            <h3 class="fw-bold text-primary mb-0"><?= $totalUsuarios ?></h3>
                                        </div>
                                        <div class="bg-primary bg-opacity-10 rounded p-2">
                                            <i class="fas fa-users fa-lg text-primary"></i>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="card border-0 shadow-sm">
                                <div class="card-body">
                                    <div class="d-flex justify-content-between align-items-start">
                                        <div>
                                            <p class="text-muted small mb-1">Usuarios Activos</p>
                                            <h3 class="fw-bold text-success mb-0"><?= $usuariosActivos ?></h3>
                                        </div>
                                        <div class="bg-success bg-opacity-10 rounded p-2">
                                            <i class="fas fa-user-check fa-lg text-success"></i>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="card border-0 shadow-sm">
                                <div class="card-body">
                                    <div class="d-flex justify-content-between align-items-start">
                                        <div>
                                            <p class="text-muted small mb-1">Administradores</p>
                                            <h3 class="fw-bold text-warning mb-0">
                                                <?php 
                                                $admins = $conn->query("SELECT COUNT(*) as total FROM usuarios WHERE rol='administrador'")->fetch_assoc();
                                                echo $admins['total'];
                                                ?>
                                            </h3>
                                        </div>
                                        <div class="bg-warning bg-opacity-10 rounded p-2">
                                            <i class="fas fa-user-shield fa-lg text-warning"></i>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Search Input -->
                    <div class="mb-3">
                        <div class="input-group shadow-sm">
                            <span class="input-group-text bg-white border-end-0">
                                <i class="fas fa-search text-muted"></i>
                            </span>
                            <input type="text" id="searchUsuarios" class="form-control border-start-0" placeholder="Buscar por nombre, email o rol...">
                        </div>
                    </div>

                    <!-- Users Table -->
                    <div class="table-responsive">
                        <table class="table table-hover align-middle" id="tablaUsuarios">
                            <thead class="table-light">
                                <tr>
                                    <th class="py-3"><i class="fas fa-user me-2"></i>Nombre</th>
                                    <th class="py-3"><i class="fas fa-envelope me-2"></i>Email</th>
                                    <th class="py-3"><i class="fas fa-tag me-2"></i>Rol</th>
                                    <th class="py-3"><i class="fas fa-circle me-2"></i>Estado</th>
                                </tr>
                            </thead>
                            <tbody id="tbodyUsuarios">
                                <?php
                                $resUsers = $conn->query("SELECT nombre, email, rol, activo FROM usuarios ORDER BY nombre");
                                if($resUsers && $resUsers->num_rows > 0):
                                    while($user = $resUsers->fetch_assoc()):
                                ?>
                                    <tr class="usuario-row">
                                        <td class="fw-medium"><?= htmlspecialchars($user['nombre']) ?></td>
                                        <td><?= htmlspecialchars($user['email']) ?></td>
                                        <td>
                                            <span class="badge <?= $user['rol'] == 'administrador' ? 'bg-warning text-dark' : 'bg-info' ?> px-3 py-2">
                                                <i class="fas <?= $user['rol'] == 'administrador' ? 'fa-crown' : 'fa-user' ?> me-1"></i>
                                                <?= ucfirst($user['rol']) ?>
                                            </span>
                                        </td>
                                        <td>
                                            <span class="badge <?= $user['activo'] == 1 ? 'bg-success' : 'bg-secondary' ?> px-3 py-2">
                                                <i class="fas <?= $user['activo'] == 1 ? 'fa-check-circle' : 'fa-times-circle' ?> me-1"></i>
                                                <?= $user['activo'] == 1 ? 'Activo' : 'Inactivo' ?>
                                            </span>
                                        </td>
                                    </tr>
                                <?php endwhile; else: ?>
                                    <tr class="no-data-row">
                                        <td colspan="4" class="text-center py-5">
                                            <i class="fas fa-users fa-3x text-muted mb-3 d-block"></i>
                                            <p class="text-muted mb-0">No hay usuarios registrados</p>
                                        </td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                        <div id="noResultsUsuarios" class="text-center py-5" style="display: none;">
                            <i class="fas fa-search fa-3x text-muted mb-3 d-block"></i>
                            <p class="text-muted mb-0">No se encontraron usuarios con ese criterio de búsqueda</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Modal Stock Crítico Mejorado (Con buscadores independientes) -->
    <div class="modal fade" id="modalStockGeneral" tabindex="-1">
        <div class="modal-dialog modal-xl">
            <div class="modal-content border-0 shadow-lg">
                <div class="modal-header" style="background: linear-gradient(135deg, #10b981, #059669); border-bottom: none;">
                    <div class="d-flex align-items-center">
                        <div class="rounded-circle bg-white bg-opacity-25 p-2 me-3">
                            <i class="fas fa-boxes fa-fw text-white"></i>
                        </div>
                        <div>
                            <h5 class="modal-title text-white fw-bold mb-0">Stock Crítico</h5>
                            <p class="text-white-50 small mb-0">Inventario por debajo del nivel óptimo</p>
                        </div>
                    </div>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body p-4">
                    <!-- Alert -->
                    <div class="alert alert-warning border-0 shadow-sm mb-4">
                        <div class="d-flex align-items-center">
                            <i class="fas fa-exclamation-triangle fa-2x me-3"></i>
                            <div>
                                <h6 class="fw-bold mb-1">Atención requerida</h6>
                                <p class="mb-0">Hay <strong><?= $totalStockBajo ?></strong> items con stock por debajo del nivel óptimo. Se recomienda realizar un pedido de reposición.</p>
                            </div>
                        </div>
                    </div>

                    <!-- Products and Supplies -->
                    <div class="row g-4">
                        <!-- Productos Column -->
                        <div class="col-md-6">
                            <div class="card border-0 shadow-sm h-100">
                                <div class="card-header bg-white border-0 pt-3 pb-2">
                                    <div class="d-flex justify-content-between align-items-center mb-2">
                                        <h6 class="fw-bold text-primary mb-0">
                                            <i class="fas fa-box me-2"></i>Productos con stock bajo
                                        </h6>
                                        <span class="badge bg-primary" id="totalProductosBajo"><?= count(array_filter($itemsStockBajo, function($item) { return $item['tipo_inventario'] === 'producto'; })) ?></span>
                                    </div>
                                    <!-- Buscador interno para productos -->
                                    <div class="input-group input-group-sm mt-2">
                                        <span class="input-group-text bg-white border-end-0">
                                            <i class="fas fa-search text-muted fa-sm"></i>
                                        </span>
                                        <input type="text" id="searchProductosStock" class="form-control border-start-0 form-control-sm" placeholder="Buscar producto...">
                                        <button class="btn btn-outline-secondary btn-sm" type="button" id="clearProductosSearch" style="display: none;">
                                            <i class="fas fa-times"></i>
                                        </button>
                                    </div>
                                </div>
                                <div class="card-body p-0">
                                    <div class="stock-scroll-container" style="max-height: 450px; overflow-y: auto;">
                                        <div class="list-group list-group-flush" id="listaProductosBajo">
                                            <?php 
                                            $productosBajo = array_filter($itemsStockBajo, function($item) { return $item['tipo_inventario'] === 'producto'; });
                                            if(!empty($productosBajo)):
                                                foreach($productosBajo as $p): 
                                            ?>
                                                <div class="list-group-item d-flex justify-content-between align-items-center p-3 producto-stock-item" data-nombre="<?= strtolower(htmlspecialchars($p['nombre'])) ?>">
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
                                                <div class="list-group-item d-flex justify-content-between align-items-center p-3 insumo-stock-item" data-nombre="<?= strtolower(htmlspecialchars($i['nombre'])) ?>">
                                                    <div class="fw-medium">
                                                        <i class="fas fa-tint me-2 text-info"></i>
                                                        <span class="insumo-nombre"><?= htmlspecialchars($i['nombre']) ?></span>
                                                    </div>
                                                    <span class="badge bg-warning rounded-pill px-3 py-2">
                                                        <i class="fas fa-chart-line me-1"></i><?= $i['cantidad'] ?> unidades
                                                    </span>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Insumos Column -->
                        <div class="col-md-6">
                            <div class="card border-0 shadow-sm h-100">
                                <div class="card-header bg-white border-0 pt-3 pb-2">
                                    <div class="d-flex justify-content-between align-items-center mb-2">
                                        <h6 class="fw-bold text-info mb-0">
                                            <i class="fas fa-tint me-2"></i>Insumos con stock bajo
                                        </h6>
                                        <span class="badge bg-info" id="totalInsumosBajo"><?= count(array_filter($itemsStockBajo, function($item) { return $item['tipo_inventario'] === 'insumo'; })) ?></span>
                                    </div>
                                    <!-- Buscador interno para insumos -->
                                    <div class="input-group input-group-sm mt-2">
                                        <span class="input-group-text bg-white border-end-0">
                                            <i class="fas fa-search text-muted fa-sm"></i>
                                        </span>
                                        <input type="text" id="searchInsumosStock" class="form-control border-start-0 form-control-sm" placeholder="Buscar insumo...">
                                        <button class="btn btn-outline-secondary btn-sm" type="button" id="clearInsumosSearch" style="display: none;">
                                            <i class="fas fa-times"></i>
                                        </button>
                                    </div>
                                </div>
                                <div class="card-body p-0">
                                    <div class="stock-scroll-container" style="max-height: 450px; overflow-y: auto;">
                                        <div class="list-group list-group-flush" id="listaInsumosBajo">
                                            <?php 
                                            $insumosBajo = array_filter($itemsStockBajo, function($item) { return $item['tipo_inventario'] === 'insumo'; });
                                            if(!empty($insumosBajo)):
                                                foreach($insumosBajo as $i): 
                                            ?>
                                                <div class="list-group-item d-flex justify-content-between align-items-center p-3 insumo-stock-item" data-nombre="<?= strtolower(htmlspecialchars($i['nombre'])) ?>">
                                                    <div class="fw-medium">
                                                        <i class="fas fa-tint me-2 text-info"></i>
                                                        <span class="insumo-nombre"><?= htmlspecialchars($i['nombre']) ?></span>
                                                    </div>
                                                    <span class="badge bg-warning rounded-pill px-3 py-2">
                                                        <i class="fas fa-chart-line me-1"></i><span class="insumo-cantidad"><?= $i['cantidad'] ?></span> unidades
                                                    </span>
                                                </div>
                                            <?php 
                                                endforeach;
                                            else: 
                                            ?>
                                                <div class="list-group-item text-center py-5" id="noInsumosMsg">
                                                    <i class="fas fa-check-circle fa-2x text-success mb-2 d-block"></i>
                                                    <p class="text-muted mb-0">No hay insumos con stock crítico</p>
                                                </div>
                                            <?php endif; ?>
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

    <div class="container-fluid px-4">
        
        <!-- HERO SECTION SOLO CON IMAGEN DE FONDO -->
        <div class="hero-premium hero-modern">
            <div class="hero-overlay"></div>
                <div class="hero-content text-center">
                    <div class="hero-badge">
                        <i class="fas fa-fish"></i>
                        <span>PESCADORES DE LA PREHISTORIA</span>
                    </div>
                    
                    <div class="hero-text">
                        <span class="hero-hello"><?= $saludo ?></span>
                        <span class="hero-user"><?= htmlspecialchars($nombre_completo) ?></span>
                    </div>   
                <div class="hero-cta">
                    <i class="fas fa-hand-peace"></i>
                    <span>¿Qué haremos hoy?</span>
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
            <!-- Columna 1 -->
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
            
            <!-- Columna 2 -->
            <div class="col-md-6 col-lg-3">
                <div class="metric-card bg-metric-blue" data-bs-toggle="modal" data-bs-target="#modalVentasSemana">
                    <div class="metric-icon-bg"><i class="fas fa-calendar-week"></i></div>
                    <h3>$<?= number_format($totalVentasSemana, 2) ?></h3>
                    <p>Ventas Semana</p>
                    <div class="metric-footer">
                        <i class="fas fa-chart-line me-1"></i> últimos 7 días
                    </div>
                </div>
                <!-- Usuarios debajo de Ventas Semana -->
                <div class="metric-card bg-metric-purple mt-4" data-bs-toggle="modal" data-bs-target="#modalUsuarios">
                    <div class="metric-icon-bg"><i class="fas fa-users"></i></div>
                    <h3><?= $totalUsuarios ?></h3>
                    <p>Usuarios Registrados</p>
                    <div class="metric-footer">
                        <i class="fas fa-user-check me-1"></i> <?= $usuariosActivos ?> activos
                    </div>
                </div>
            </div>
            
            <!-- Columna 3 -->
            <div class="col-md-6 col-lg-3">
                <div class="metric-card bg-metric-teal" data-bs-toggle="modal" data-bs-target="#modalVentasMes">
                    <div class="metric-icon-bg"><i class="fas fa-calendar-alt"></i></div>
                    <h3>$<?= number_format($totalVentasMes, 2) ?></h3>
                    <p>Ventas del Mes</p>
                    <div class="metric-footer">
                        <i class="fas fa-chart-bar me-1"></i> <?= date('F Y') ?>
                    </div>
                </div>
                <!-- Stock Crítico debajo de Ventas del Mes -->
                <div class="metric-card bg-metric-red mt-4" data-bs-toggle="modal" data-bs-target="#modalStockGeneral">
                    <div class="metric-icon-bg"><i class="fas fa-exclamation-triangle"></i></div>
                    <h3><?= $totalStockBajo ?></h3>
                    <p>Stock Crítico</p>
                    <div class="metric-footer">
                        <i class="fas fa-boxes me-1"></i> items por reponer
                    </div>
                </div>
            </div>
            
            <!-- Columna 4 -->
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
            
            <div class="col-lg-4">
                <div class="chart-card modern-stats-card" style="height: 100%; display: flex; flex-direction: column;">
                    <!-- Top Productos Section -->
                    <div class="stats-section" style="flex-shrink: 0;">
                        <div class="stats-section-header">
                            <div class="stats-section-icon">
                                <i class="fas fa-trophy"></i>
                            </div>
                            <h3 class="stats-section-title">Top Productos</h3>
                            <span class="stats-section-badge">Más vendidos</span>
                        </div>
                        
                        <?php if(count($topProductos) > 0): ?>
                            <div class="top-products-list" style="max-height: 140px; overflow-y: auto;">
                                <?php foreach($topProductos as $index => $p): ?>
                                    <div class="top-product-card">
                                        <div class="top-product-info">
                                            <div class="top-product-rank <?= $index == 0 ? 'rank-1' : ($index == 1 ? 'rank-2' : ($index == 2 ? 'rank-3' : '')) ?>">
                                                <?= $index + 1 ?>
                                            </div>
                                            <div class="top-product-name">
                                                <?= htmlspecialchars($p['nombre']) ?>
                                            </div>
                                        </div>
                                        <div class="top-product-stats">
                                            <span class="top-product-quantity"><?= intval($p['total_vendido']) ?></span>
                                            <span class="top-product-unit">uds</span>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php else: ?>
                            <div class="empty-state">
                                <i class="fas fa-chart-simple"></i>
                                <p>No hay datos disponibles</p>
                            </div>
                        <?php endif; ?>
                    </div>
                    
                    <!-- Separador decorativo -->
                    <div class="section-separator" style="flex-shrink: 0;">
                        <span class="separator-dot"></span>
                        <span class="separator-line"></span>
                        <span class="separator-dot"></span>
                    </div>
                    
                    <!-- Stock Crítico Section -->
                    <div class="stats-section" style="flex: 1; display: flex; flex-direction: column; min-height: 0;">
                        <div class="stats-section-header" style="flex-shrink: 0;">
                            <div class="stats-section-icon warning">
                                <i class="fas fa-exclamation-triangle"></i>
                            </div>
                            <h3 class="stats-section-title">Stock Crítico</h3>
                            <span class="stats-section-badge danger"><?= $totalStockBajo ?> items</span>
                        </div>
                        
                        <?php if($totalStockBajo > 0): ?>
                            <div class="stock-list" style="flex: 1; overflow-y: auto; min-height: 0;">
                                <?php 
                                $contador = 0;
                                foreach($itemsStockBajo as $item): 
                                    if($contador >= 2) break;
                                    $contador++;
                                    $stockPercent = min(100, ($item['cantidad'] / ($item['tipo_inventario'] === 'insumo' ? 2 : 20)) * 100);
                                ?>
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
                                                <div class="stock-progress-bar" style="width: <?= $stockPercent ?>%"></div>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                                
                                <?php if($totalStockBajo > 4): ?>
                                    <div class="more-items">
                                        <i class="fas fa-ellipsis-h"></i>
                                        <span><?= $totalStockBajo - 2 ?> items más</span>
                                    </div>
                                <?php endif; ?>
                            </div>
                        <?php else: ?>
                            <div class="empty-state success">
                                <i class="fas fa-check-circle"></i>
                                <p>No hay items con stock bajo</p>
                            </div>
                        <?php endif; ?>
                        
                        <button class="btn-view-all" style="flex-shrink: 0; margin-top: 0.75rem;" data-bs-toggle="modal" data-bs-target="#modalStockGeneral">
                            <i class="fas fa-eye"></i>
                            <span>Ver todos los items</span>
                            <i class="fas fa-arrow-right"></i>
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

let chartVentas = null;

function initChart() {
    if (chartVentas) {
        chartVentas.destroy();
    }
    
    const canvas = document.getElementById('chartVentas');
    const container = canvas.parentElement;
    
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
}

// Inicializar gráfica
initChart();

// Redimensionar gráfica cuando cambie el tamaño de la ventana
window.addEventListener('resize', function() {
    setTimeout(function() {
        if (chartVentas) {
            chartVentas.resize();
        }
    }, 100);
});

// Redimensionar gráfica cuando se abra el modal si es necesario
document.getElementById('modalVentasSemana')?.addEventListener('shown.bs.modal', function() {
    setTimeout(function() {
        if (chartVentas) {
            chartVentas.resize();
        }
    }, 100);
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

// Buscador para Ventas Hoy
document.getElementById('searchHoy')?.addEventListener('keyup', function() {
    const searchTerm = this.value.toLowerCase().trim();
    const rows = document.querySelectorAll('#tbodyVentasHoy .venta-row');
    const noResultsDiv = document.getElementById('noResultsHoy');
    let hasResults = false;
    
    if(rows.length > 0) {
        rows.forEach(row => {
            const productName = row.querySelector('td:first-child')?.textContent.toLowerCase() || '';
            if(productName.includes(searchTerm)) {
                row.style.display = '';
                hasResults = true;
            } else {
                row.style.display = 'none';
            }
        });
    }
    
    if(noResultsDiv) {
        if(searchTerm !== '' && !hasResults && rows.length > 0) {
            noResultsDiv.style.display = 'block';
        } else {
            noResultsDiv.style.display = 'none';
        }
    }
});

// Buscador para Ventas Semana
document.getElementById('searchSemana')?.addEventListener('keyup', function() {
    const searchTerm = this.value.toLowerCase().trim();
    const rows = document.querySelectorAll('#tbodyTopSemana .producto-semana-row');
    const noResultsDiv = document.getElementById('noResultsSemana');
    let hasResults = false;
    
    if(rows.length > 0) {
        rows.forEach(row => {
            const productName = row.querySelector('td:first-child')?.textContent.toLowerCase() || '';
            if(productName.includes(searchTerm)) {
                row.style.display = '';
                hasResults = true;
            } else {
                row.style.display = 'none';
            }
        });
    }
    
    if(noResultsDiv) {
        if(searchTerm !== '' && !hasResults && rows.length > 0) {
            noResultsDiv.style.display = 'block';
        } else {
            noResultsDiv.style.display = 'none';
        }
    }
});

// Buscador para Ventas Mes
document.getElementById('searchMes')?.addEventListener('keyup', function() {
    const searchTerm = this.value.toLowerCase().trim();
    const rows = document.querySelectorAll('#tbodyTopMes .producto-mes-row');
    const noResultsDiv = document.getElementById('noResultsMes');
    let hasResults = false;
    
    if(rows.length > 0) {
        rows.forEach(row => {
            const productName = row.querySelector('td:first-child')?.textContent.toLowerCase() || '';
            if(productName.includes(searchTerm)) {
                row.style.display = '';
                hasResults = true;
            } else {
                row.style.display = 'none';
            }
        });
    }
    
    if(noResultsDiv) {
        if(searchTerm !== '' && !hasResults && rows.length > 0) {
            noResultsDiv.style.display = 'block';
        } else {
            noResultsDiv.style.display = 'none';
        }
    }
});

// Buscador para Usuarios
document.getElementById('searchUsuarios')?.addEventListener('keyup', function() {
    const searchTerm = this.value.toLowerCase().trim();
    const rows = document.querySelectorAll('#tbodyUsuarios .usuario-row');
    const noResultsDiv = document.getElementById('noResultsUsuarios');
    let hasResults = false;
    
    if(rows.length > 0) {
        rows.forEach(row => {
            const name = row.querySelector('td:first-child')?.textContent.toLowerCase() || '';
            const email = row.querySelector('td:nth-child(2)')?.textContent.toLowerCase() || '';
            const role = row.querySelector('td:nth-child(3)')?.textContent.toLowerCase() || '';
            
            if(name.includes(searchTerm) || email.includes(searchTerm) || role.includes(searchTerm)) {
                row.style.display = '';
                hasResults = true;
            } else {
                row.style.display = 'none';
            }
        });
    }
    
    if(noResultsDiv) {
        if(searchTerm !== '' && !hasResults && rows.length > 0) {
            noResultsDiv.style.display = 'block';
        } else {
            noResultsDiv.style.display = 'none';
        }
    }
});

// Gráfica para el modal de semana
if(document.getElementById('chartSemanaModal')) {
    const ventasDataSemana = <?= json_encode(array_map('floatval', $ventasPorDia)) ?>;
    const ctxSemana = document.getElementById('chartSemanaModal').getContext('2d');
    
    new Chart(ctxSemana, {
        type: 'bar',
        data: {
            labels: ['Lunes', 'Martes', 'Miércoles', 'Jueves', 'Viernes', 'Sábado', 'Domingo'],
            datasets: [{
                label: 'Ventas ($)',
                data: ventasDataSemana,
                backgroundColor: 'rgba(59, 130, 246, 0.7)',
                borderColor: '#3b82f6',
                borderWidth: 1,
                borderRadius: 8
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
                    }
                }
            },
            scales: {
                y: {
                    beginAtZero: true,
                    ticks: {
                        callback: function(value) {
                            return '$' + value.toLocaleString();
                        }
                    }
                }
            }
        }
    });
}

// ==================== BUSCADORES PARA STOCK CRÍTICO ====================
function inicializarBuscadoresStock() {
    // BUSCADOR DE PRODUCTOS
    const searchProductos = document.getElementById('searchProductosStock');
    const productosContainer = document.getElementById('listaProductosBajo');
    
    if(searchProductos && productosContainer) {
        // Función para actualizar la tabla de productos
        function actualizarTablaProductos() {
            const searchTerm = searchProductos.value.toLowerCase().trim();
            const items = productosContainer.querySelectorAll('.producto-stock-item');
            let visibleCount = 0;
            
            // Filtrar items
            items.forEach(item => {
                // Buscar el nombre del producto
                let nombre = '';
                
                // Buscar en el span con clase producto-nombre
                const nombreSpan = item.querySelector('.producto-nombre');
                if(nombreSpan && nombreSpan.textContent) {
                    nombre = nombreSpan.textContent.toLowerCase();
                }
                
                // Buscar en el div con clase fw-medium
                const fwDiv = item.querySelector('.fw-medium');
                if(fwDiv && !nombre) {
                    nombre = fwDiv.textContent.toLowerCase();
                }
                
                // Buscar en cualquier elemento que contenga el texto
                if(!nombre) {
                    nombre = item.textContent.toLowerCase();
                }
                
                // FORZAR la ocultación/mostrado
                if(searchTerm === '' || nombre.includes(searchTerm)) {
                    item.style.display = '';
                    item.style.cssText = 'display: flex !important;';
                    visibleCount++;
                } else {
                    item.style.display = 'none';
                    item.style.cssText = 'display: none !important;';
                    item.classList.add('hidden-item');
                }
            });
            
            // También ocultar el mensaje de "no hay productos" si existe
            const noProductosMsg = document.getElementById('noProductosMsg');
            if(noProductosMsg) {
                noProductosMsg.style.display = items.length === 0 ? 'block' : 'none';
            }
            
            // Actualizar contador
            const totalBadge = document.getElementById('totalProductosBajo');
            if(totalBadge) {
                if(!totalBadge.hasAttribute('data-original')) {
                    totalBadge.setAttribute('data-original', totalBadge.textContent);
                }
                totalBadge.textContent = visibleCount;
                totalBadge.style.backgroundColor = visibleCount === 0 && searchTerm !== '' ? '#dc3545' : '#0d6efd';
            }
            
            // Mostrar/ocultar botón de limpiar
            const clearBtn = document.getElementById('clearProductosSearch');
            if(clearBtn) {
                clearBtn.style.display = searchTerm !== '' ? 'inline-flex' : 'none';
            }
            
            // Mostrar mensaje de no resultados
            let noResultsMsg = productosContainer.querySelector('#noResultsProductos');
            if(visibleCount === 0 && searchTerm !== '' && items.length > 0) {
                if(!noResultsMsg) {
                    noResultsMsg = document.createElement('div');
                    noResultsMsg.id = 'noResultsProductos';
                    noResultsMsg.className = 'text-center py-5';
                    noResultsMsg.style.cssText = 'display: block !important;';
                    noResultsMsg.innerHTML = `
                        <i class="fas fa-search fa-2x text-muted mb-2 d-block"></i>
                        <p class="text-muted mb-0 small">No se encontraron productos con "<strong>${searchTerm}</strong>"</p>
                    `;
                    productosContainer.appendChild(noResultsMsg);
                } else {
                    noResultsMsg.style.display = 'block';
                    noResultsMsg.style.cssText = 'display: block !important;';
                    noResultsMsg.innerHTML = `
                        <i class="fas fa-search fa-2x text-muted mb-2 d-block"></i>
                        <p class="text-muted mb-0 small">No se encontraron productos con "<strong>${searchTerm}</strong>"</p>
                    `;
                }
            } else if(noResultsMsg) {
                noResultsMsg.style.display = 'none';
                noResultsMsg.style.cssText = 'display: none !important;';
            }
        }
        
        // Evento input para filtrar en tiempo real
        searchProductos.addEventListener('input', actualizarTablaProductos);
        
        // Botón de limpiar
        const clearBtn = document.getElementById('clearProductosSearch');
        if(clearBtn) {
            clearBtn.addEventListener('click', function() {
                searchProductos.value = '';
                actualizarTablaProductos();
                searchProductos.focus();
            });
        }
    }
    
    // BUSCADOR DE INSUMOS
    const searchInsumos = document.getElementById('searchInsumosStock');
    const insumosContainer = document.getElementById('listaInsumosBajo');
    
    if(searchInsumos && insumosContainer) {
        // Función para actualizar la tabla de insumos
        function actualizarTablaInsumos() {
            const searchTerm = searchInsumos.value.toLowerCase().trim();
            const items = insumosContainer.querySelectorAll('.insumo-stock-item');
            let visibleCount = 0;
            
            // Filtrar items
            items.forEach(item => {
                // Buscar el nombre del insumo
                let nombre = '';
                
                // Buscar en el span con clase insumo-nombre
                const nombreSpan = item.querySelector('.insumo-nombre');
                if(nombreSpan && nombreSpan.textContent) {
                    nombre = nombreSpan.textContent.toLowerCase();
                }
                
                // Buscar en el div con clase fw-medium
                const fwDiv = item.querySelector('.fw-medium');
                if(fwDiv && !nombre) {
                    nombre = fwDiv.textContent.toLowerCase();
                }
                
                // Buscar en cualquier elemento que contenga el texto
                if(!nombre) {
                    nombre = item.textContent.toLowerCase();
                }
                
                // FORZAR la ocultación/mostrado
                if(searchTerm === '' || nombre.includes(searchTerm)) {
                    item.style.display = '';
                    item.style.cssText = 'display: flex !important;';
                    visibleCount++;
                } else {
                    item.style.display = 'none';
                    item.style.cssText = 'display: none !important;';
                    item.classList.add('hidden-item');
                }
            });
            
            // También ocultar el mensaje de "no hay insumos" si existe
            const noInsumosMsg = document.getElementById('noInsumosMsg');
            if(noInsumosMsg) {
                noInsumosMsg.style.display = items.length === 0 ? 'block' : 'none';
            }
            
            // Actualizar contador
            const totalBadge = document.getElementById('totalInsumosBajo');
            if(totalBadge) {
                if(!totalBadge.hasAttribute('data-original')) {
                    totalBadge.setAttribute('data-original', totalBadge.textContent);
                }
                totalBadge.textContent = visibleCount;
                totalBadge.style.backgroundColor = visibleCount === 0 && searchTerm !== '' ? '#dc3545' : '#0dcaf0';
            }
            
            // Mostrar/ocultar botón de limpiar
            const clearBtn = document.getElementById('clearInsumosSearch');
            if(clearBtn) {
                clearBtn.style.display = searchTerm !== '' ? 'inline-flex' : 'none';
            }
            
            // Mostrar mensaje de no resultados
            let noResultsMsg = insumosContainer.querySelector('#noResultsInsumos');
            if(visibleCount === 0 && searchTerm !== '' && items.length > 0) {
                if(!noResultsMsg) {
                    noResultsMsg = document.createElement('div');
                    noResultsMsg.id = 'noResultsInsumos';
                    noResultsMsg.className = 'text-center py-5';
                    noResultsMsg.style.cssText = 'display: block !important;';
                    noResultsMsg.innerHTML = `
                        <i class="fas fa-search fa-2x text-muted mb-2 d-block"></i>
                        <p class="text-muted mb-0 small">No se encontraron insumos con "<strong>${searchTerm}</strong>"</p>
                    `;
                    insumosContainer.appendChild(noResultsMsg);
                } else {
                    noResultsMsg.style.display = 'block';
                    noResultsMsg.style.cssText = 'display: block !important;';
                    noResultsMsg.innerHTML = `
                        <i class="fas fa-search fa-2x text-muted mb-2 d-block"></i>
                        <p class="text-muted mb-0 small">No se encontraron insumos con "<strong>${searchTerm}</strong>"</p>
                    `;
                }
            } else if(noResultsMsg) {
                noResultsMsg.style.display = 'none';
                noResultsMsg.style.cssText = 'display: none !important;';
            }
        }
        
        // Evento input para filtrar en tiempo real
        searchInsumos.addEventListener('input', actualizarTablaInsumos);
        
        // Botón de limpiar
        const clearBtn = document.getElementById('clearInsumosSearch');
        if(clearBtn) {
            clearBtn.addEventListener('click', function() {
                searchInsumos.value = '';
                actualizarTablaInsumos();
                searchInsumos.focus();
            });
        }
    }
}

// Inicializar buscadores cuando el modal se abre
const modalStockGeneral = document.getElementById('modalStockGeneral');
if(modalStockGeneral) {
    modalStockGeneral.addEventListener('shown.bs.modal', function() {
        inicializarBuscadoresStock();
    });
}

// También inicializar en DOMContentLoaded
document.addEventListener('DOMContentLoaded', function() {
    const modal = document.getElementById('modalStockGeneral');
    if(modal && modal.classList.contains('show')) {
        inicializarBuscadoresStock();
    }
});

</script>

</body>
</html>