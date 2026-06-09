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
    
    if (in_array($primerNombre, $nombresFemeninos)) return 'femenino';
    if (in_array($primerNombre, $nombresMasculinos)) return 'masculino';
    if (substr($primerNombre, -1) === 'a') return 'femenino';
    if (substr($primerNombre, -1) === 'o' || substr($primerNombre, -1) === 'e') return 'masculino';
    
    return 'masculino';
}

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

// Ventas por mes
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
$resTicketsHoy = $conn->query("SELECT COUNT(*) AS tickets FROM ventas WHERE DATE(fecha_venta) = CURDATE()");
$ticketsHoy = $resTicketsHoy ? $resTicketsHoy->fetch_assoc()['tickets'] : 0;
$ticketPromedio = $ticketsHoy > 0 ? $totalVentasDia / $ticketsHoy : 0;

// Ventas por día de la semana para el modal
$ventasPorDiaSemana = [];
$diasSemana = ['Lunes', 'Martes', 'Miércoles', 'Jueves', 'Viernes', 'Sábado', 'Domingo'];
for ($i = 0; $i < 7; $i++) {
    $ventasPorDiaSemana[] = ['dia' => $diasSemana[$i], 'total' => $ventasPorDia[$i]];
}

// Obtener imagen del dashboard desde configuración
$result_config = $conn->query("SELECT imagen_dashboard FROM configuracion_galeria WHERE id = 1");
$config_data = $result_config->fetch_assoc();
$imagen_dashboard = isset($config_data['imagen_dashboard']) && file_exists($config_data['imagen_dashboard']) 
    ? $config_data['imagen_dashboard'] 
    : '';

?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Pescadores de la Prehistoria</title>
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
    
    <!-- Estilos del Dashboard -->
    <link rel="stylesheet" href="css/dashboard-admin.css">
</head>
<body>

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

    <!-- MODALES DE INFORMACIÓN (Mantener igual) -->
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
                            <h5 class="modal-title text-white fw-bold mb-0">Ventas del Día</h5>
                            <p class="text-white-50 small mb-0"><?= date('l, d/m/Y') ?></p>
                        </div>
                    </div>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body p-4">
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

                    <div class="mb-3">
                        <div class="input-group shadow-sm">
                            <span class="input-group-text bg-white border-end-0"><i class="fas fa-search text-muted"></i></span>
                            <input type="text" id="searchHoy" class="form-control border-start-0" placeholder="Buscar producto...">
                        </div>
                    </div>

                    <div class="table-responsive">
                        <table class="table table-hover align-middle" id="tablaVentasHoy">
                            <thead class="table-light"><tr><th class="py-3">Producto</th><th class="text-center py-3" width="120">Cantidad</th><th class="text-end py-3" width="150">Total</th></tr></thead>
                            <tbody id="tbodyVentasHoy">
                                <?php
                                $resDetalle = $conn->query("SELECT p.nombre, v.cantidad_vendida, (v.cantidad_vendida * p.precio_venta) AS total FROM ventas v JOIN productos p ON v.id_producto = p.id WHERE DATE(v.fecha_venta) = CURDATE() ORDER BY v.fecha_venta DESC LIMIT 100");
                                if($resDetalle && $resDetalle->num_rows > 0):
                                    while($v = $resDetalle->fetch_assoc()):
                                ?>
                                    <tr class="venta-row"><td class="fw-medium"><?= htmlspecialchars($v['nombre']) ?></td><td class="text-center"><span class="badge bg-secondary bg-opacity-10 text-dark px-3 py-2"><?= $v['cantidad_vendida'] ?> uds</span></td><td class="text-end fw-bold text-primary">$<?= number_format($v['total'], 2) ?></td></tr>
                                <?php endwhile; else: ?>
                                    <tr class="no-data-row"><td colspan="3" class="text-center py-5"><i class="fas fa-chart-line fa-3x text-muted mb-3 d-block"></i><p class="text-muted mb-0">No hay ventas registradas hoy</p></td></tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                        <div id="noResultsHoy" class="text-center py-5" style="display: none;"><i class="fas fa-search fa-3x text-muted mb-3 d-block"></i><p class="text-muted mb-0">No se encontraron productos con ese nombre</p></div>
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
                        <div><h5 class="modal-title text-white fw-bold mb-0">Ventas de la Semana</h5><p class="text-white-50 small mb-0"><?= date('d/m/Y', strtotime('monday this week')) ?> - <?= date('d/m/Y', strtotime('sunday this week')) ?></p></div>
                    </div>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body p-4">
                    <div class="row g-3 mb-4">
                        <div class="col-md-6"><div class="card border-0 shadow-sm"><div class="card-body"><div class="d-flex justify-content-between align-items-start"><div><p class="text-muted small mb-1">Total Semana</p><h3 class="fw-bold text-primary mb-0">$<?= number_format($totalVentasSemana, 2) ?></h3></div><div class="bg-primary bg-opacity-10 rounded p-2"><i class="fas fa-chart-line fa-lg text-primary"></i></div></div></div></div></div>
                        <div class="col-md-6"><div class="card border-0 shadow-sm"><div class="card-body"><div class="d-flex justify-content-between align-items-start"><div><p class="text-muted small mb-1">Promedio Diario</p><h3 class="fw-bold text-info mb-0">$<?= number_format($totalVentasSemana / 7, 2) ?></h3></div><div class="bg-info bg-opacity-10 rounded p-2"><i class="fas fa-chart-bar fa-lg text-info"></i></div></div></div></div></div>
                    </div>
                    <div class="mb-4" style="position: relative; height: 350px;"><canvas id="chartSemanaModal" style="height: 100%; width: 100%;"></canvas></div>
                    <div class="mb-3"><div class="input-group shadow-sm"><span class="input-group-text bg-white border-end-0"><i class="fas fa-search text-muted"></i></span><input type="text" id="searchSemana" class="form-control border-start-0" placeholder="Buscar producto..."></div></div>
                    <div class="table-responsive" style="max-height: 300px; overflow-y: auto;">
                        <table class="table table-hover align-middle" id="tablaTopSemana">
                            <thead class="table-light" style="position: sticky; top: 0; z-index: 10;"><tr><th class="py-2">Producto</th><th class="text-center py-2" width="100">Cantidad</th><th class="text-end py-2" width="120">Total</th></tr></thead>
                            <tbody id="tbodyTopSemana">
                                <?php
                                $resTopSemana = $conn->query("SELECT p.nombre, SUM(v.cantidad_vendida) AS cantidad, SUM(v.cantidad_vendida * p.precio_venta) AS total FROM ventas v JOIN productos p ON v.id_producto = p.id WHERE DATE(v.fecha_venta) BETWEEN '$inicioSemana' AND '$finSemana' GROUP BY p.id ORDER BY total DESC LIMIT 20");
                                if($resTopSemana && $resTopSemana->num_rows > 0):
                                    while($item = $resTopSemana->fetch_assoc()):
                                ?>
                                    <tr class="producto-semana-row"><td class="fw-medium"><?= htmlspecialchars($item['nombre']) ?></td><td class="text-center"><span class="badge bg-secondary bg-opacity-10 text-dark px-3 py-2"><?= $item['cantidad'] ?> uds</span></td><td class="text-end fw-bold text-primary">$<?= number_format($item['total'], 2) ?></td></tr>
                                <?php endwhile; endif; ?>
                            </tbody>
                        </table>
                        <div id="noResultsSemana" class="text-center py-5" style="display: none;"><i class="fas fa-search fa-3x text-muted mb-3 d-block"></i><p class="text-muted mb-0">No se encontraron productos con ese nombre</p></div>
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
                        <div><h5 class="modal-title text-white fw-bold mb-0">Ventas del Mes</h5><p class="text-white-50 small mb-0"><?= date('F Y') ?></p></div>
                    </div>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body p-4">
                    <div class="row g-3 mb-4">
                        <div class="col-md-4"><div class="card border-0 shadow-sm"><div class="card-body"><div class="d-flex justify-content-between align-items-start"><div><p class="text-muted small mb-1">Total del Mes</p><h3 class="fw-bold text-primary mb-0">$<?= number_format($totalVentasMes, 2) ?></h3></div><div class="bg-primary bg-opacity-10 rounded p-2"><i class="fas fa-chart-line fa-lg text-primary"></i></div></div></div></div></div>
                        <div class="col-md-4"><div class="card border-0 shadow-sm"><div class="card-body"><div class="d-flex justify-content-between align-items-start"><div><p class="text-muted small mb-1">Promedio Diario</p><h3 class="fw-bold text-info mb-0">$<?= number_format($totalVentasMes / date('t'), 2) ?></h3></div><div class="bg-info bg-opacity-10 rounded p-2"><i class="fas fa-chart-bar fa-lg text-info"></i></div></div></div></div></div>
                        <div class="col-md-4"><div class="card border-0 shadow-sm"><div class="card-body"><div class="d-flex justify-content-between align-items-start"><div><p class="text-muted small mb-1">Proyección</p><h3 class="fw-bold text-success mb-0">$<?= number_format(($totalVentasMes / date('j')) * date('t'), 2) ?></h3></div><div class="bg-success bg-opacity-10 rounded p-2"><i class="fas fa-chart-line fa-lg text-success"></i></div></div></div></div></div>
                    </div>
                    <div class="mb-4"><h6 class="fw-bold mb-3"><i class="fas fa-chart-pie me-2 text-primary"></i>Desempeño Semanal</h6>
                        <div class="table-responsive"><table class="table table-hover align-middle"><thead class="table-light"><tr><th>Semana</th><th class="text-end">Total Ventas</th><th class="text-end">% del Mes</th><th class="text-center">Progreso</th></tr></thead>
                        <tbody><?php $resSemanas = $conn->query("SELECT WEEK(v.fecha_venta, 1) - WEEK(DATE_FORMAT(v.fecha_venta, '%Y-%m-01'), 1) + 1 AS semana_num, SUM(v.cantidad_vendida * p.precio_venta) AS total FROM ventas v JOIN productos p ON v.id_producto = p.id WHERE MONTH(v.fecha_venta) = MONTH(CURDATE()) AND YEAR(v.fecha_venta) = YEAR(CURDATE()) GROUP BY semana_num ORDER BY semana_num");
                        if($resSemanas && $resSemanas->num_rows > 0): while($semana = $resSemanas->fetch_assoc()): $porcentaje = $totalVentasMes > 0 ? ($semana['total'] / $totalVentasMes) * 100 : 0; ?>
                            <tr><td class="fw-medium">Semana <?= $semana['semana_num'] ?></td><td class="text-end fw-bold text-primary">$<?= number_format($semana['total'], 2) ?></td><td class="text-end"><?= number_format($porcentaje, 1) ?>%</td><td class="text-center" width="200"><div class="progress" style="height: 8px;"><div class="progress-bar bg-primary" style="width: <?= $porcentaje ?>%"></div></div></td></tr>
                        <?php endwhile; endif; ?></tbody></table></div>
                    </div>
                    <div class="mb-3"><div class="input-group shadow-sm"><span class="input-group-text bg-white border-end-0"><i class="fas fa-search text-muted"></i></span><input type="text" id="searchMes" class="form-control border-start-0" placeholder="Buscar producto..."></div></div>
                    <div class="table-responsive"><table class="table table-hover align-middle" id="tablaTopMes"><thead class="table-light"><tr><th class="py-3">Producto</th><th class="text-center py-3" width="120">Cantidad</th><th class="text-end py-3" width="150">Total</th></tr></thead>
                    <tbody id="tbodyTopMes"><?php $resTopMes = $conn->query("SELECT p.nombre, SUM(v.cantidad_vendida) AS cantidad, SUM(v.cantidad_vendida * p.precio_venta) AS total FROM ventas v JOIN productos p ON v.id_producto = p.id WHERE MONTH(v.fecha_venta) = MONTH(CURDATE()) AND YEAR(v.fecha_venta) = YEAR(CURDATE()) GROUP BY p.id ORDER BY total DESC LIMIT 20");
                    if($resTopMes && $resTopMes->num_rows > 0): while($item = $resTopMes->fetch_assoc()): ?>
                        <tr class="producto-mes-row"><td class="fw-medium"><?= htmlspecialchars($item['nombre']) ?></td><td class="text-center"><span class="badge bg-secondary bg-opacity-10 text-dark px-3 py-2"><?= $item['cantidad'] ?> uds</span></td><td class="text-end fw-bold text-primary">$<?= number_format($item['total'], 2) ?></td></tr>
                    <?php endwhile; endif; ?></tbody></table><div id="noResultsMes" class="text-center py-5" style="display: none;"><i class="fas fa-search fa-3x text-muted mb-3 d-block"></i><p class="text-muted mb-0">No se encontraron productos con ese nombre</p></div></div>
                </div>
            </div>
        </div>
    </div>

    <!-- Modal Usuarios -->
    <div class="modal fade" id="modalUsuarios" tabindex="-1">
        <div class="modal-dialog modal-xl">
            <div class="modal-content border-0 shadow-lg">
                <div class="modal-header" style="background: linear-gradient(135deg, #8b5cf6, #7c3aed); border-bottom: none;">
                    <div class="d-flex align-items-center">
                        <div class="rounded-circle bg-white bg-opacity-25 p-2 me-3"><i class="fas fa-users fa-fw text-white"></i></div>
                        <div><h5 class="modal-title text-white fw-bold mb-0">Usuarios del Sistema</h5><p class="text-white-50 small mb-0">Gestión de acceso y roles</p></div>
                    </div>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body p-4">
                    <div class="row g-3 mb-4">
                        <div class="col-md-4"><div class="card border-0 shadow-sm"><div class="card-body"><div class="d-flex justify-content-between align-items-start"><div><p class="text-muted small mb-1">Total Usuarios</p><h3 class="fw-bold text-primary mb-0"><?= $totalUsuarios ?></h3></div><div class="bg-primary bg-opacity-10 rounded p-2"><i class="fas fa-users fa-lg text-primary"></i></div></div></div></div></div>
                        <div class="col-md-4"><div class="card border-0 shadow-sm"><div class="card-body"><div class="d-flex justify-content-between align-items-start"><div><p class="text-muted small mb-1">Usuarios Activos</p><h3 class="fw-bold text-success mb-0"><?= $usuariosActivos ?></h3></div><div class="bg-success bg-opacity-10 rounded p-2"><i class="fas fa-user-check fa-lg text-success"></i></div></div></div></div></div>
                        <div class="col-md-4"><div class="card border-0 shadow-sm"><div class="card-body"><div class="d-flex justify-content-between align-items-start"><div><p class="text-muted small mb-1">Administradores</p><h3 class="fw-bold text-warning mb-0"><?php $admins = $conn->query("SELECT COUNT(*) as total FROM usuarios WHERE rol='administrador'")->fetch_assoc(); echo $admins['total']; ?></h3></div><div class="bg-warning bg-opacity-10 rounded p-2"><i class="fas fa-user-shield fa-lg text-warning"></i></div></div></div></div></div>
                    </div>
                    <div class="mb-3"><div class="input-group shadow-sm"><span class="input-group-text bg-white border-end-0"><i class="fas fa-search text-muted"></i></span><input type="text" id="searchUsuarios" class="form-control border-start-0" placeholder="Buscar por nombre, email o rol..."></div></div>
                    <div class="table-responsive"><table class="table table-hover align-middle" id="tablaUsuarios"><thead class="table-light"><tr><th class="py-3"><i class="fas fa-user me-2"></i>Nombre</th><th class="py-3"><i class="fas fa-envelope me-2"></i>Email</th><th class="py-3"><i class="fas fa-tag me-2"></i>Rol</th><th class="py-3"><i class="fas fa-circle me-2"></i>Estado</th></tr></thead>
                    <tbody id="tbodyUsuarios"><?php $resUsers = $conn->query("SELECT nombre, email, rol, activo FROM usuarios ORDER BY nombre");
                    if($resUsers && $resUsers->num_rows > 0): while($user = $resUsers->fetch_assoc()): ?>
                        <tr class="usuario-row"><td class="fw-medium"><?= htmlspecialchars($user['nombre']) ?></td><td><?= htmlspecialchars($user['email']) ?></td><td><span class="badge <?= $user['rol'] == 'administrador' ? 'bg-warning text-dark' : 'bg-info' ?> px-3 py-2"><i class="fas <?= $user['rol'] == 'administrador' ? 'fa-crown' : 'fa-user' ?> me-1"></i><?= ucfirst($user['rol']) ?></span></td><td><span class="badge <?= $user['activo'] == 1 ? 'bg-success' : 'bg-secondary' ?> px-3 py-2"><i class="fas <?= $user['activo'] == 1 ? 'fa-check-circle' : 'fa-times-circle' ?> me-1"></i><?= $user['activo'] == 1 ? 'Activo' : 'Inactivo' ?></span></td></tr>
                    <?php endwhile; else: ?><tr class="no-data-row"><td colspan="4" class="text-center py-5"><i class="fas fa-users fa-3x text-muted mb-3 d-block"></i><p class="text-muted mb-0">No hay usuarios registrados</p></td></tr><?php endif; ?></tbody>}</table><div id="noResultsUsuarios" class="text-center py-5" style="display: none;"><i class="fas fa-search fa-3x text-muted mb-3 d-block"></i><p class="text-muted mb-0">No se encontraron usuarios con ese criterio de búsqueda</p></div></div>
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
                    <div class="alert alert-warning border-0 shadow-sm mb-4"><div class="d-flex align-items-center"><i class="fas fa-exclamation-triangle fa-2x me-3"></i><div><h6 class="fw-bold mb-1">Atención requerida</h6><p class="mb-0">Hay <strong><?= $totalStockBajo ?></strong> items con stock por debajo del nivel óptimo. Se recomienda realizar un pedido de reposición.</p></div></div></div>
                    <div class="row g-4">
                        <div class="col-md-6"><div class="card border-0 shadow-sm h-100"><div class="card-header bg-white border-0 pt-3 pb-2"><div class="d-flex justify-content-between align-items-center mb-2"><h6 class="fw-bold text-primary mb-0"><i class="fas fa-box me-2"></i>Productos con stock bajo</h6><span class="badge bg-primary" id="totalProductosBajo"><?= count(array_filter($itemsStockBajo, function($item) { return $item['tipo_inventario'] === 'producto'; })) ?></span></div><div class="input-group input-group-sm mt-2"><span class="input-group-text bg-white border-end-0"><i class="fas fa-search text-muted fa-sm"></i></span><input type="text" id="searchProductosStock" class="form-control border-start-0 form-control-sm" placeholder="Buscar producto..."><button class="btn btn-outline-secondary btn-sm" type="button" id="clearProductosSearch" style="display: none;"><i class="fas fa-times"></i></button></div></div><div class="card-body p-0"><div class="stock-scroll-container" style="max-height: 450px; overflow-y: auto;"><div class="list-group list-group-flush" id="listaProductosBajo"><?php $productosBajo = array_filter($itemsStockBajo, function($item) { return $item['tipo_inventario'] === 'producto'; });
                        if(!empty($productosBajo)): foreach($productosBajo as $p): ?><div class="list-group-item d-flex justify-content-between align-items-center p-3 producto-stock-item" data-nombre="<?= strtolower(htmlspecialchars($p['nombre'])) ?>"><div class="fw-medium"><i class="fas fa-box me-2 text-primary"></i><span class="producto-nombre"><?= htmlspecialchars($p['nombre']) ?></span></div><span class="badge bg-danger rounded-pill px-3 py-2"><i class="fas fa-chart-line me-1"></i><?= $p['cantidad'] ?> uds</span></div><?php endforeach; else: ?><div class="list-group-item text-center py-5"><i class="fas fa-check-circle fa-2x text-success mb-2 d-block"></i><p class="text-muted mb-0">No hay productos con stock crítico</p></div><?php endif; ?></div></div></div></div></div>
                        <div class="col-md-6"><div class="card border-0 shadow-sm h-100"><div class="card-header bg-white border-0 pt-3 pb-2"><div class="d-flex justify-content-between align-items-center mb-2"><h6 class="fw-bold text-info mb-0"><i class="fas fa-tint me-2"></i>Insumos con stock bajo</h6><span class="badge bg-info" id="totalInsumosBajo"><?= count(array_filter($itemsStockBajo, function($item) { return $item['tipo_inventario'] === 'insumo'; })) ?></span></div><div class="input-group input-group-sm mt-2"><span class="input-group-text bg-white border-end-0"><i class="fas fa-search text-muted fa-sm"></i></span><input type="text" id="searchInsumosStock" class="form-control border-start-0 form-control-sm" placeholder="Buscar insumo..."><button class="btn btn-outline-secondary btn-sm" type="button" id="clearInsumosSearch" style="display: none;"><i class="fas fa-times"></i></button></div></div><div class="card-body p-0"><div class="stock-scroll-container" style="max-height: 450px; overflow-y: auto;"><div class="list-group list-group-flush" id="listaInsumosBajo"><?php $insumosBajo = array_filter($itemsStockBajo, function($item) { return $item['tipo_inventario'] === 'insumo'; });
                        if(!empty($insumosBajo)): foreach($insumosBajo as $i): ?><div class="list-group-item d-flex justify-content-between align-items-center p-3 insumo-stock-item" data-nombre="<?= strtolower(htmlspecialchars($i['nombre'])) ?>"><div class="fw-medium"><i class="fas fa-tint me-2 text-info"></i><span class="insumo-nombre"><?= htmlspecialchars($i['nombre']) ?></span></div><span class="badge bg-warning rounded-pill px-3 py-2"><i class="fas fa-chart-line me-1"></i><span class="insumo-cantidad"><?= $i['cantidad'] ?></span> unidades</span></div><?php endforeach; else: ?><div class="list-group-item text-center py-5" id="noInsumosMsg"><i class="fas fa-check-circle fa-2x text-success mb-2 d-block"></i><p class="text-muted mb-0">No hay insumos con stock crítico</p></div><?php endif; ?></div></div></div></div></div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="container-fluid px-4">
        
<!-- HERO SECTION -->
<div class="hero-premium hero-modern" <?php if($imagen_dashboard): ?>style="background-image: url('<?= $imagen_dashboard ?>?v=<?= time() ?>'); background-size: cover; background-position: center;"<?php endif; ?>>
    <div class="hero-overlay"></div>
    <div class="hero-content text-center">
        <?php if($imagen_dashboard): ?>
            <div class="hero-logo mb-3">
                <?php 
                $logo_path = $config_general['logo'] ?? '';
                if($logo_path && file_exists($logo_path)): ?>
                    <img src="<?= $logo_path ?>?v=<?= time() ?>" alt="Logo" class="hero-logo-img" style="height: 80px; filter: drop-shadow(0 4px 6px rgba(0,0,0,0.2));">
                <?php endif; ?>
            </div>
        <?php endif; ?>
        <div class="hero-badge"><i class="fas fa-fish"></i><span>DIARIO FINANCIERO (POS)</span></div>
        <div class="hero-text"><span class="hero-hello"><?= $saludo ?></span><span class="hero-user"><?= htmlspecialchars($nombre_completo) ?></span></div>   
        <div class="hero-cta"><i class="fas fa-hand-peace"></i><span>¿Qué haremos hoy?</span></div>
    </div>
</div>

        <!-- ACCIONES RÁPIDAS -->
        <div class="white-container">
            <div class="section-header"><div class="section-title"><i class="fas fa-bolt"></i><span>Acciones Rápidas</span></div><div class="section-divider"></div></div>
            <div class="row g-3">
                <div class="col-md-4 col-lg-2"><div class="action-card" onclick="window.location.href='dashboard_ventas.php'"><div class="action-icon mx-auto"><i class="fas fa-cash-register"></i></div><h4>Realizar Venta</h4><p>Registrar nueva venta</p></div></div>
                <div class="col-md-4 col-lg-2"><div class="action-card" onclick="window.location.href='dashboard_inventario.php'"><div class="action-icon mx-auto"><i class="fas fa-boxes"></i></div><h4>Inventario</h4><p>Gestionar stock</p></div></div>
                <div class="col-md-4 col-lg-2"><div class="action-card" onclick="window.location.href='ver_ventas.php'"><div class="action-icon mx-auto"><i class="fas fa-chart-line"></i></div><h4>Estadísticas</h4><p>Ver métricas y KPI</p></div></div>
                <div class="col-md-4 col-lg-2"><div class="action-card" onclick="window.location.href='dashboard_productos.php'"><div class="action-icon mx-auto"><i class="fas fa-tags"></i></div><h4>Productos</h4><p>Registrar productos</p></div></div>
                <div class="col-md-4 col-lg-2"><div class="action-card" onclick="window.location.href='proveedores.php'"><div class="action-icon mx-auto"><i class="fas fa-truck"></i></div><h4>Proveedores</h4><p>Gestionar proveedores</p></div></div>
                <div class="col-md-4 col-lg-2"><div class="action-card" onclick="window.location.href='historial_reportes.php'"><div class="action-icon mx-auto"><i class="fas fa-file-alt"></i></div><h4>Reportes</h4><p>Reporte de ventas</p></div></div>
            </div>
        </div>

        <!-- MÉTRICAS CLAVE -->
        <div class="section-header"><div class="section-title"><i class="fas fa-chart-simple"></i><span>Métricas Clave</span></div><div class="section-divider"></div></div>
        <div class="row g-4 mb-4">
            <div class="col-md-6 col-lg-3"><div class="metric-card bg-metric-orange" data-bs-toggle="modal" data-bs-target="#modalVentasHoy"><div class="metric-icon-bg"><i class="fas fa-calendar-day"></i></div><h3>$<?= number_format($totalVentasDia, 2) ?></h3><p>Ventas Hoy</p><div class="metric-footer"><i class="fas fa-ticket-alt me-1"></i> <?= $ticketsHoy ?> tickets</div></div></div>
            <div class="col-md-6 col-lg-3"><div class="metric-card bg-metric-blue" data-bs-toggle="modal" data-bs-target="#modalVentasSemana"><div class="metric-icon-bg"><i class="fas fa-calendar-week"></i></div><h3>$<?= number_format($totalVentasSemana, 2) ?></h3><p>Ventas Semana</p><div class="metric-footer"><i class="fas fa-chart-line me-1"></i> últimos 7 días</div></div><div class="metric-card bg-metric-purple mt-4" data-bs-toggle="modal" data-bs-target="#modalUsuarios"><div class="metric-icon-bg"><i class="fas fa-users"></i></div><h3><?= $totalUsuarios ?></h3><p>Usuarios Registrados</p><div class="metric-footer"><i class="fas fa-user-check me-1"></i> <?= $usuariosActivos ?> activos</div></div></div>
            <div class="col-md-6 col-lg-3"><div class="metric-card bg-metric-teal" data-bs-toggle="modal" data-bs-target="#modalVentasMes"><div class="metric-icon-bg"><i class="fas fa-calendar-alt"></i></div><h3>$<?= number_format($totalVentasMes, 2) ?></h3><p>Ventas del Mes</p><div class="metric-footer"><i class="fas fa-chart-bar me-1"></i> <?= date('F Y') ?></div></div><div class="metric-card bg-metric-red mt-4" data-bs-toggle="modal" data-bs-target="#modalStockGeneral"><div class="metric-icon-bg"><i class="fas fa-exclamation-triangle"></i></div><h3><?= $totalStockBajo ?></h3><p>Stock Crítico</p><div class="metric-footer"><i class="fas fa-boxes me-1"></i> items por reponer</div></div></div>
            <div class="col-md-6 col-lg-3"><div class="metric-card bg-metric-green"><div class="metric-icon-bg"><i class="fas fa-wallet"></i></div><h3>$<?= number_format($utilidadHoy, 2) ?></h3><p>Utilidad Estimada Hoy</p><div class="metric-footer"><i class="fas fa-percentage me-1"></i> <?= $totalVentasDia > 0 ? number_format(($utilidadHoy / $totalVentasDia) * 100, 1) : 0 ?>% margen</div></div></div>
        </div>

        <!-- ANÁLISIS DE VENTAS -->
        <div class="section-header"><div class="section-title"><i class="fas fa-chart-line"></i><span>Análisis de Ventas</span></div><div class="section-divider"></div></div>
        <div class="row g-4">
            <div class="col-lg-8"><div class="chart-card" style="height: 100%; display: flex; flex-direction: column;"><div class="chart-header"><div class="chart-title"><i class="fas fa-chart-line"></i><span>Ventas - Últimos 7 días</span></div><div class="d-flex gap-3"><small class="text-muted">Promedio: $<?= number_format($totalVentasSemana / 7, 2) ?></small><small class="text-muted">Ticket: $<?= number_format($ticketPromedio, 2) ?></small></div></div><div style="flex: 1; position: relative; min-height: 300px;"><canvas id="chartVentas" style="position: absolute; top: 0; left: 0; width: 100%; height: 100%;"></canvas></div></div></div>
            <div class="col-lg-4"><div class="chart-card modern-stats-card" style="height: 100%; display: flex; flex-direction: column;">
                <div class="stats-section" style="flex-shrink: 0;"><div class="stats-section-header"><div class="stats-section-icon"><i class="fas fa-trophy"></i></div><h3 class="stats-section-title">Top Productos</h3><span class="stats-section-badge">Más vendidos</span></div>
                <?php if(count($topProductos) > 0): ?><div class="top-products-list" style="max-height: 140px; overflow-y: auto;"><?php foreach($topProductos as $index => $p): ?><div class="top-product-card"><div class="top-product-info"><div class="top-product-rank <?= $index == 0 ? 'rank-1' : ($index == 1 ? 'rank-2' : ($index == 2 ? 'rank-3' : '')) ?>"><?= $index + 1 ?></div><div class="top-product-name"><?= htmlspecialchars($p['nombre']) ?></div></div><div class="top-product-stats"><span class="top-product-quantity"><?= intval($p['total_vendido']) ?></span><span class="top-product-unit">uds</span></div></div><?php endforeach; ?></div><?php else: ?><div class="empty-state"><i class="fas fa-chart-simple"></i><p>No hay datos disponibles</p></div><?php endif; ?></div>
                <div class="section-separator" style="flex-shrink: 0;"><span class="separator-dot"></span><span class="separator-line"></span><span class="separator-dot"></span></div>
                <div class="stats-section" style="flex: 1; display: flex; flex-direction: column; min-height: 0;"><div class="stats-section-header" style="flex-shrink: 0;"><div class="stats-section-icon warning"><i class="fas fa-exclamation-triangle"></i></div><h3 class="stats-section-title">Stock Crítico</h3><span class="stats-section-badge danger"><?= $totalStockBajo ?> items</span></div>
                <?php if($totalStockBajo > 0): ?><div class="stock-list" style="flex: 1; overflow-y: auto; min-height: 0;"><?php $contador = 0; foreach($itemsStockBajo as $item): if($contador >= 2) break; $contador++; $stockPercent = min(100, ($item['cantidad'] / ($item['tipo_inventario'] === 'insumo' ? 2 : 20)) * 100); ?><div class="stock-card"><div class="stock-info"><div class="stock-icon <?= $item['tipo_inventario'] === 'insumo' ? 'insumo' : 'producto' ?>"><i class="fas <?= $item['tipo_inventario'] === 'insumo' ? 'fa-tint' : 'fa-box' ?>"></i></div><div class="stock-details"><div class="stock-name"><?= htmlspecialchars($item['nombre']) ?></div><div class="stock-type"><?= $item['tipo_inventario'] === 'insumo' ? 'Insumo' : 'Producto' ?></div></div></div><div class="stock-status"><div class="stock-quantity <?= $item['cantidad'] == 0 ? 'critical' : ($item['cantidad'] < 5 ? 'low' : 'warning') ?>"><?= $item['cantidad'] ?> <span>uds</span></div><div class="stock-progress"><div class="stock-progress-bar" style="width: <?= $stockPercent ?>%"></div></div></div></div><?php endforeach; if($totalStockBajo > 2): ?><div class="more-items"><i class="fas fa-ellipsis-h"></i><span><?= $totalStockBajo - 2 ?> items más</span></div><?php endif; ?></div><?php else: ?><div class="empty-state success"><i class="fas fa-check-circle"></i><p>No hay items con stock bajo</p></div><?php endif; ?>
                <button class="btn-view-all" style="flex-shrink: 0; margin-top: 0.75rem;" data-bs-toggle="modal" data-bs-target="#modalStockGeneral"><i class="fas fa-eye"></i><span>Ver todos los items</span><i class="fas fa-arrow-right"></i></button></div>
            </div></div>
        </div>
    </div>
</div>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

<script>
    // ========== ALERTA STOCK BAJO - TOP RIGHT CON AUTO CIERRE ==========
    let alertTimeout = null;
    
    function mostrarAlertaStockBajo() {
    const totalStock = <?= $totalStockBajo ?>;
    if (totalStock === 0) return;
    
    // Eliminar alerta existente
    const existingAlert = document.querySelector('.stock-badge-alert');
    if (existingAlert) {
        existingAlert.remove();
        if (alertTimeout) clearTimeout(alertTimeout);
    }
    
    const itemsStock = <?= json_encode(array_map(function($item) {
        return [
            'nombre' => $item['nombre'],
            'cantidad' => $item['cantidad'],
            'tipo' => $item['tipo_inventario']
        ];
    }, array_slice($itemsStockBajo, 0, 5))) ?>;
    
    const totalItems = <?= $totalStockBajo ?>;
    const hasCritical = itemsStock.some(item => item.cantidad === 0);
    const alertType = hasCritical ? 'danger' : 'warning';
    const iconClass = hasCritical ? 'fa-exclamation' : 'fa-box-open';
    const badgeText = hasCritical ? 'crítico' : 'bajo';
    
    let itemsHtml = '';
    itemsStock.forEach(item => {
        const icon = item.tipo === 'producto' ? 'fa-box' : 'fa-tint';
        const colorClass = item.cantidad === 0 ? 'critical' : (item.cantidad < 5 ? 'low' : 'warning');
        itemsHtml += `
            <div class="stock-panel-item">
                <span class="stock-panel-name">
                    <i class="fas ${icon}"></i>
                    ${escapeHtml(item.nombre.length > 22 ? item.nombre.substring(0, 22) + '…' : item.nombre)}
                </span>
                <span class="stock-panel-qty ${colorClass}">${item.cantidad}</span>
            </div>
        `;
    });
    
    const moreItems = totalItems - itemsStock.length;
    const moreHtml = moreItems > 0 ? `<div class="stock-panel-more">+${moreItems} ${moreItems === 1 ? 'item' : 'items'} más</div>` : '';
    
    const alertDiv = document.createElement('div');
    alertDiv.className = 'stock-badge-alert';
    alertDiv.innerHTML = `
        <div class="stock-badge-main ${alertType}">
            <div class="stock-badge-icon">
                <i class="fas ${iconClass}"></i>
            </div>
            <span class="stock-badge-count">${totalStock}</span>
            <span class="stock-badge-text">stock <span>${badgeText}</span></span>
            <i class="fas fa-chevron-right stock-badge-arrow"></i>
            <button class="stock-badge-close" onclick="this.closest('.stock-badge-alert').remove()">
                <i class="fas fa-times"></i>
            </button>
        </div>
        <div class="stock-badge-panel">
            <div class="stock-panel-header">
                <div class="stock-panel-title ${alertType}">
                    <i class="fas ${hasCritical ? 'fa-exclamation-triangle' : 'fa-box'}"></i>
                    Items con stock ${badgeText}
                </div>
                <a href="#" class="stock-panel-view" data-bs-toggle="modal" data-bs-target="#modalStockGeneral">Ver todos →</a>
            </div>
            <div class="stock-panel-items">
                ${itemsHtml}
                ${moreHtml}
            </div>
        </div>
    `;
    
    document.body.appendChild(alertDiv);
    
    // AUTO-CERRAR DESPUÉS DE 5 SEGUNDOS
    alertTimeout = setTimeout(() => {
        const alert = document.querySelector('.stock-badge-alert');
        if (alert) {
            alert.style.animation = 'fadeOutUp 0.3s ease-out';
            setTimeout(() => alert.remove(), 300);
        }
    }, 5000); // 5000 milisegundos = 5 segundos
    
    // Cerrar al hacer clic en "Ver todos"
    alertDiv.querySelector('.stock-panel-view')?.addEventListener('click', () => {
        setTimeout(() => {
            if (alertDiv && alertDiv.parentNode) alertDiv.remove();
        }, 300);
    });
    
    // Pausar auto-cierre al hacer hover (si pasas el mouse, no se cierra)
    alertDiv.addEventListener('mouseenter', () => {
        if (alertTimeout) clearTimeout(alertTimeout);
    });
    
    // Reanudar auto-cierre al salir del hover (otros 5 segundos)
    alertDiv.addEventListener('mouseleave', () => {
        alertTimeout = setTimeout(() => {
            const alert = document.querySelector('.stock-badge-alert');
            if (alert) {
                alert.style.animation = 'fadeOutUp 0.3s ease-out';
                setTimeout(() => alert.remove(), 300);
            }
        }, 5000);
    });
}

function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

// Gráfica de ventas
const ventasData = <?= json_encode(array_map('floatval', $ventasPorDia)) ?>;
const ctxVentas = document.getElementById('chartVentas').getContext('2d');
let chartVentas = null;

function initChart() {
    if (chartVentas) chartVentas.destroy();
    chartVentas = new Chart(ctxVentas, {
        type: 'line',
        data: { labels: ['Lunes', 'Martes', 'Miércoles', 'Jueves', 'Viernes', 'Sábado', 'Domingo'], datasets: [{ label: 'Ventas ($)', data: ventasData, borderColor: '#f97316', backgroundColor: 'rgba(249, 115, 22, 0.05)', borderWidth: 3, fill: true, tension: 0.4, pointBackgroundColor: '#f97316', pointBorderColor: '#fff', pointRadius: 5, pointHoverRadius: 8, pointBorderWidth: 2 }] },
        options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false }, tooltip: { callbacks: { label: function(context) { return '$' + context.raw.toLocaleString(); } }, backgroundColor: '#1e293b', titleColor: '#fff', bodyColor: '#f97316' } }, scales: { y: { beginAtZero: true, grid: { color: '#e2e8f0' }, ticks: { callback: function(value) { return '$' + value.toLocaleString(); } } }, x: { grid: { display: false } } } }
    });
}

initChart();
window.addEventListener('resize', function() { setTimeout(function() { if (chartVentas) chartVentas.resize(); }, 100); });

// Función toggle contraseña
function togglePasswordField(fieldId, button) {
    const field = document.getElementById(fieldId);
    const icon = button.querySelector('i');
    if (field.type === 'password') { field.type = 'text'; icon.classList.remove('fa-eye'); icon.classList.add('fa-eye-slash'); }
    else { field.type = 'password'; icon.classList.remove('fa-eye-slash'); icon.classList.add('fa-eye'); }
}

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
            Swal.fire({ icon: 'success', title: '¡Contraseña cambiada!', text: data.message, timer: 2000, showConfirmButton: false, timerProgressBar: true }).then(() => { window.location.reload(); });
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

// Control de inactividad
let tiempoInactivo = 0;
const TIEMPO_EXPIRACION = 30 * 60 * 1000;
let advertenciaMostrada = false;
function reiniciarContador() { tiempoInactivo = 0; advertenciaMostrada = false; }
setInterval(() => {
    tiempoInactivo += 1000;
    if (tiempoInactivo >= 29 * 60 * 1000 && !advertenciaMostrada) {
        advertenciaMostrada = true;
        Swal.fire({ icon: 'warning', title: 'Sesión por expirar', text: 'Tu sesión expirará en 1 minuto por inactividad', confirmButtonText: 'Seguir aquí', cancelButtonText: 'Salir', confirmButtonColor: '#f97316', background: '#fff', borderRadius: '16px' }).then((result) => {
            if (result.isConfirmed) { fetch('mantener_sesion.php').catch(() => {}); reiniciarContador(); }
            else { window.location.href = 'logout.php'; }
        });
    }
    if (tiempoInactivo >= TIEMPO_EXPIRACION) {
        Swal.fire({ icon: 'info', title: 'Sesión expirada', text: 'Redirigiendo al login...', timer: 2000, showConfirmButton: false, background: '#fff', borderRadius: '16px' }).then(() => { window.location.href = 'login.php?expired=1'; });
    }
}, 1000);
['mousemove', 'keydown', 'click', 'scroll', 'touchstart'].forEach(event => { document.addEventListener(event, reiniciarContador); });

// Buscadores
document.getElementById('searchHoy')?.addEventListener('keyup', function() { const term = this.value.toLowerCase().trim(); const rows = document.querySelectorAll('#tbodyVentasHoy .venta-row'); const noResults = document.getElementById('noResultsHoy'); let hasResults = false; rows.forEach(row => { const name = row.querySelector('td:first-child')?.textContent.toLowerCase() || ''; if(name.includes(term)) { row.style.display = ''; hasResults = true; } else { row.style.display = 'none'; } }); if(noResults) { if(term !== '' && !hasResults && rows.length > 0) noResults.style.display = 'block'; else noResults.style.display = 'none'; } });
document.getElementById('searchSemana')?.addEventListener('keyup', function() { const term = this.value.toLowerCase().trim(); const rows = document.querySelectorAll('#tbodyTopSemana .producto-semana-row'); const noResults = document.getElementById('noResultsSemana'); let hasResults = false; rows.forEach(row => { const name = row.querySelector('td:first-child')?.textContent.toLowerCase() || ''; if(name.includes(term)) { row.style.display = ''; hasResults = true; } else { row.style.display = 'none'; } }); if(noResults) { if(term !== '' && !hasResults && rows.length > 0) noResults.style.display = 'block'; else noResults.style.display = 'none'; } });
document.getElementById('searchMes')?.addEventListener('keyup', function() { const term = this.value.toLowerCase().trim(); const rows = document.querySelectorAll('#tbodyTopMes .producto-mes-row'); const noResults = document.getElementById('noResultsMes'); let hasResults = false; rows.forEach(row => { const name = row.querySelector('td:first-child')?.textContent.toLowerCase() || ''; if(name.includes(term)) { row.style.display = ''; hasResults = true; } else { row.style.display = 'none'; } }); if(noResults) { if(term !== '' && !hasResults && rows.length > 0) noResults.style.display = 'block'; else noResults.style.display = 'none'; } });
document.getElementById('searchUsuarios')?.addEventListener('keyup', function() { const term = this.value.toLowerCase().trim(); const rows = document.querySelectorAll('#tbodyUsuarios .usuario-row'); const noResults = document.getElementById('noResultsUsuarios'); let hasResults = false; rows.forEach(row => { const name = row.querySelector('td:first-child')?.textContent.toLowerCase() || ''; const email = row.querySelector('td:nth-child(2)')?.textContent.toLowerCase() || ''; const role = row.querySelector('td:nth-child(3)')?.textContent.toLowerCase() || ''; if(name.includes(term) || email.includes(term) || role.includes(term)) { row.style.display = ''; hasResults = true; } else { row.style.display = 'none'; } }); if(noResults) { if(term !== '' && !hasResults && rows.length > 0) noResults.style.display = 'block'; else noResults.style.display = 'none'; } });

// Gráfica semana modal
if(document.getElementById('chartSemanaModal')) {
    const ventasDataSemana = <?= json_encode(array_map('floatval', $ventasPorDia)) ?>;
    new Chart(document.getElementById('chartSemanaModal').getContext('2d'), { type: 'bar', data: { labels: ['Lunes', 'Martes', 'Miércoles', 'Jueves', 'Viernes', 'Sábado', 'Domingo'], datasets: [{ label: 'Ventas ($)', data: ventasDataSemana, backgroundColor: 'rgba(59, 130, 246, 0.7)', borderColor: '#3b82f6', borderWidth: 1, borderRadius: 8 }] }, options: { responsive: true, maintainAspectRatio: true, plugins: { legend: { display: false }, tooltip: { callbacks: { label: function(context) { return '$' + context.raw.toLocaleString(); } } } }, scales: { y: { beginAtZero: true, ticks: { callback: function(value) { return '$' + value.toLocaleString(); } } } } } });
}

// Buscadores stock
function inicializarBuscadoresStock() {
    const searchProductos = document.getElementById('searchProductosStock');
    const productosContainer = document.getElementById('listaProductosBajo');
    if(searchProductos && productosContainer) {
        function actualizarTablaProductos() {
            const term = searchProductos.value.toLowerCase().trim();
            const items = productosContainer.querySelectorAll('.producto-stock-item');
            let visible = 0;
            items.forEach(item => { let nombre = ''; const span = item.querySelector('.producto-nombre'); if(span && span.textContent) nombre = span.textContent.toLowerCase(); if(term === '' || nombre.includes(term)) { item.style.display = 'flex'; visible++; } else { item.style.display = 'none'; } });
            const badge = document.getElementById('totalProductosBajo'); if(badge) badge.textContent = visible;
            const clearBtn = document.getElementById('clearProductosSearch'); if(clearBtn) clearBtn.style.display = term !== '' ? 'inline-flex' : 'none';
        }
        searchProductos.addEventListener('input', actualizarTablaProductos);
        document.getElementById('clearProductosSearch')?.addEventListener('click', function() { searchProductos.value = ''; actualizarTablaProductos(); searchProductos.focus(); });
    }
    const searchInsumos = document.getElementById('searchInsumosStock');
    const insumosContainer = document.getElementById('listaInsumosBajo');
    if(searchInsumos && insumosContainer) {
        function actualizarTablaInsumos() {
            const term = searchInsumos.value.toLowerCase().trim();
            const items = insumosContainer.querySelectorAll('.insumo-stock-item');
            let visible = 0;
            items.forEach(item => { let nombre = ''; const span = item.querySelector('.insumo-nombre'); if(span && span.textContent) nombre = span.textContent.toLowerCase(); if(term === '' || nombre.includes(term)) { item.style.display = 'flex'; visible++; } else { item.style.display = 'none'; } });
            const badge = document.getElementById('totalInsumosBajo'); if(badge) badge.textContent = visible;
            const clearBtn = document.getElementById('clearInsumosSearch'); if(clearBtn) clearBtn.style.display = term !== '' ? 'inline-flex' : 'none';
        }
        searchInsumos.addEventListener('input', actualizarTablaInsumos);
        document.getElementById('clearInsumosSearch')?.addEventListener('click', function() { searchInsumos.value = ''; actualizarTablaInsumos(); searchInsumos.focus(); });
    }
}

// Inicializar
document.addEventListener('DOMContentLoaded', function() {
    const modal = document.getElementById('modalStockGeneral');
    if(modal) modal.addEventListener('shown.bs.modal', function() { inicializarBuscadoresStock(); });
    // Mostrar alerta de stock bajo después de 1 segundo
    setTimeout(function() { mostrarAlertaStockBajo(); }, 1000);
});
</script>

</body>
</html>