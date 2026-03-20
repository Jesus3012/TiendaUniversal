<?php
include 'includes/session.php'; 

if (!isset($_SESSION['usuario_id']) || $_SESSION['rol'] !== 'vendedor') {
    header("Location: login.php");
    exit;
}

include('includes/db.php');
include('includes/header.php');
include('includes/navbar.php');
include('includes/csrf.php');

$id_vendedor = $_SESSION['usuario_id'];

// ===== VERIFICAR CAMBIO DE CONTRASEÑA OBLIGATORIO =====
$mostrar_modal_password = (isset($_SESSION['debe_cambiar_password']) && $_SESSION['debe_cambiar_password'] == 1);
// Fechas base
$hoy = date('Y-m-d');
$inicioMes = date('Y-m-01');

// CONSULTAS PRINCIPALES
$inicioFiltro = $_GET['inicio'] ?? null;
$finFiltro = $_GET['fin'] ?? null;

$condicionFecha = "";
if ($inicioFiltro && $finFiltro) {
    $condicionFecha = "WHERE DATE(v.fecha_venta) BETWEEN '$inicioFiltro' AND '$finFiltro'";
}

// Ventas día, semana y mes
$ventasHoy = $conn->query("
    SELECT SUM(p.precio_venta * v.cantidad_vendida) AS total, COUNT(*) AS num
    FROM ventas v
    JOIN productos p ON v.id_producto = p.id
    WHERE DATE(v.fecha_venta) = CURDATE()
    AND v.id_vendedor = $id_vendedor
")->fetch_assoc();

$ventasSemana = $conn->query("
    SELECT SUM(p.precio_venta * v.cantidad_vendida) AS total, COUNT(*) AS num
    FROM ventas v
    JOIN productos p ON v.id_producto = p.id
    WHERE YEARWEEK(v.fecha_venta, 1) = YEARWEEK(CURDATE(), 1)
    AND v.id_vendedor = $id_vendedor
")->fetch_assoc();


$ventasMes = $conn->query("
    SELECT SUM(p.precio_venta * v.cantidad_vendida) AS total, COUNT(*) AS num
    FROM ventas v
    JOIN productos p ON v.id_producto = p.id
    WHERE DATE(v.fecha_venta) >= '$inicioMes'
    AND v.id_vendedor = $id_vendedor
")->fetch_assoc();

// Productos con bajo stock
$stockBajo = $conn->query("
    SELECT nombre, cantidad 
    FROM productos 
    WHERE cantidad < 5
    AND tipo_inventario = 'producto'
    ORDER BY cantidad ASC 
    LIMIT 5
");

// Guardar en un array para poder usarlo varias veces
$stockBajoArray = [];
while($s = $stockBajo->fetch_assoc()){
    $stockBajoArray[] = $s;
}

// Días sin vender
$ultimaVenta = $conn->query("SELECT MAX(fecha_venta) AS ultima FROM ventas")->fetch_assoc()['ultima'];
$diasSinVender = $ultimaVenta ? (new DateTime($ultimaVenta))->diff(new DateTime())->days : 'N/A';

// === NUEVO BLOQUE === //
date_default_timezone_set('America/Mexico_City');

// Fechas
$inicioMesActual = date('Y-m-01');
$finMesActual    = date('Y-m-t');

$inicioMesAnterior = date('Y-m-01', strtotime('-1 month'));
$finMesAnterior    = date('Y-m-t', strtotime('-1 month'));

// ===== VENTAS MES ANTERIOR =====
$resAnterior = $conn->query("
    SELECT IFNULL(SUM(p.precio_venta * v.cantidad_vendida),0) AS total
    FROM ventas v
    JOIN productos p ON v.id_producto = p.id
    WHERE DATE(v.fecha_venta) BETWEEN '$inicioMesAnterior' AND '$finMesAnterior'
    AND v.id_vendedor = $id_vendedor
")->fetch_assoc();

$ventasMesAnterior = (float)$resAnterior['total'];

// ===== META MENSUAL =====
$metaBase = 5000;
$metaMensual = ($ventasMesAnterior > 0)
    ? $ventasMesAnterior * 1.20
    : $metaBase;

// ===== VENTAS ACTUALES =====
$resActual = $conn->query("
    SELECT IFNULL(SUM(p.precio_venta * v.cantidad_vendida),0) AS total
    FROM ventas v
    JOIN productos p ON v.id_producto = p.id
    WHERE DATE(v.fecha_venta) BETWEEN '$inicioMesActual' AND CURDATE()
    AND v.id_vendedor = $id_vendedor
")->fetch_assoc();

$ventasActuales = (float)$resActual['total'];

// ===== PROGRESO REAL =====
$porcentaje = ($metaMensual > 0)
    ? min(100, ($ventasActuales / $metaMensual) * 100)
    : 0;

// ===== PREDICCIÓN =====
$diasDelMes  = (int)date('t');
$diaActual   = (int)date('j');
$promedioDia = ($diaActual > 0) ? $ventasActuales / $diaActual : 0;

$prediccionFinMes = $promedioDia * $diasDelMes;

// ===== COLOR DINÁMICO =====
if ($porcentaje < 40) {
    $colorBarra = 'bg-danger';
    $estado = 'Riesgo';
} elseif ($porcentaje < 75) {
    $colorBarra = 'bg-warning';
    $estado = 'En proceso';
} else {
    $colorBarra = 'bg-success';
    $estado = 'Buen ritmo';
}

// --- Clientes frecuentes ---
$clientesFrecuentes = $conn->query("
    SELECT 
        v.correo_cliente AS email, 
        COUNT(v.id) AS total_compras,
        SUM(p.precio_venta * v.cantidad_vendida) AS monto_gastado,
        MAX(v.id) AS ultimo_ticket
    FROM ventas v
    JOIN productos p ON v.id_producto = p.id
    WHERE v.correo_cliente IS NOT NULL 
    AND v.correo_cliente != ''
    AND v.id_vendedor = $id_vendedor
    GROUP BY v.correo_cliente
    ORDER BY monto_gastado DESC
    LIMIT 5
");

$ventasHoyDetalle = $conn->query("
    SELECT p.nombre, v.cantidad_vendida, (v.cantidad_vendida * p.precio_venta) AS total
    FROM ventas v 
    JOIN productos p ON v.id_producto = p.id
    WHERE DATE(v.fecha_venta) = CURDATE()
    AND v.id_vendedor = $id_vendedor
")->fetch_all(MYSQLI_ASSOC);

// Detalle Ventas Semana
$ventasSemanaDetalle = $conn->query("
    SELECT p.nombre, v.cantidad_vendida, (v.cantidad_vendida * p.precio_venta) AS total
    FROM ventas v 
    JOIN productos p ON v.id_producto = p.id
    WHERE YEARWEEK(v.fecha_venta,1) = YEARWEEK(CURDATE(),1)
    AND v.id_vendedor = $id_vendedor
")->fetch_all(MYSQLI_ASSOC);

// Detalle Ventas Mes
$ventasMesDetalle = $conn->query("
    SELECT p.nombre, v.cantidad_vendida, (v.cantidad_vendida * p.precio_venta) AS total
    FROM ventas v 
    JOIN productos p ON v.id_producto = p.id
    WHERE DATE(v.fecha_venta) >= '$inicioMes'
    AND v.id_vendedor = $id_vendedor
")->fetch_all(MYSQLI_ASSOC);

$clientes = [];
$maxCompras = 0;

while ($row = $clientesFrecuentes->fetch_assoc()) {
    $clientes[] = $row;
    $maxCompras = max($maxCompras, $row['total_compras']);
}

?>

<!-- Reemplaza TODO el bloque de estilos con esto -->
<style>
.content-wrapper {
    min-height: 100vh;
    padding: 20px;
    overflow-x: auto;
    background: #f8f9fa;
}

/* Eliminamos la clase blurred y usamos pointer-events: none directamente */
.content-wrapper.modal-active {
    pointer-events: none;
}

.progress {
    height: 25px;
    border-radius: 20px;
    overflow: hidden;
}

.progress-bar {
    line-height: 25px;
    font-weight: bold;
}

.small-box {
    transition: transform .2s ease, box-shadow .2s ease;
    border-radius:14px !important;
    padding:22px !important;
    color:#fff !important;
    box-shadow: 0 4px 12px rgba(0,0,0,0.10) !important;
    border:none !important;
    position:relative;
    overflow:hidden;
}

.small-box:hover {
    transform: translateY(-4px);
    box-shadow: 0 8px 25px rgba(0,0,0,0.18);
}

.small-box h4 {
    font-size:16px;
    font-weight:600;
    margin-bottom:8px;
    opacity:0.9;
}

.small-box h2 {
    font-size:28px;
    font-weight:800;
    margin:0;
}

.small-box .badge {
    margin-top:10px;
    font-size:12px;
    padding:6px 12px;
    border-radius:20px;
    background:rgba(255,255,255,0.2);
    color:#fff;
    border:1px solid rgba(255,255,255,0.25);
}

.small-box .icon {
    position:absolute;
    right:18px;
    bottom:15px;
    font-size:55px;
    opacity:0.15;
}

.small-box-footer {
    margin-top:14px;
    font-size:13px;
    color:rgba(255,255,255,0.85);
    background:none !important;
    padding:0 !important;
}

.alert-danger {
    background: #f4bbb7ff !important;
    color: #313130ff !important;
    border-left: 5px solid #ff624dff;
}

/* Estilos mejorados para el modal de cambio de contraseña - SIN BORROSO */
#modalCambiarPassword {
    position: fixed;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    z-index: 9999;
    background: rgba(0, 0, 0, 0.7); /* Fondo oscuro sólido, sin blur */
    display: flex !important;
    align-items: center;
    justify-content: center;
    backdrop-filter: none; /* Eliminar cualquier filtro */
    -webkit-backdrop-filter: none;
}

#modalCambiarPassword .modal-dialog {
    margin: 0;
    width: 100%;
    max-width: 500px; /* Reducido un poco para mejor legibilidad */
    pointer-events: auto;
    animation: modalFadeIn 0.3s ease;
}

@keyframes modalFadeIn {
    from {
        opacity: 0;
        transform: translateY(-30px);
    }
    to {
        opacity: 1;
        transform: translateY(0);
    }
}

#modalCambiarPassword .modal-content {
    border-radius: 15px;
    overflow: hidden;
    border: none;
    box-shadow: 0 20px 40px rgba(0,0,0,0.3);
    background: white;
}

#modalCambiarPassword .modal-header {
    background: linear-gradient(135deg, #ff7b00, #ff9800);
    color: white;
    padding: 1.2rem 1.5rem;
    border-bottom: none;
}

#modalCambiarPassword .modal-header h5 {
    font-size: 1.3rem;
    font-weight: 600;
    margin: 0;
}

#modalCambiarPassword .modal-body {
    padding: 2rem;
    background: white;
}

#modalCambiarPassword .alert-warning {
    background: #fff3cd;
    border-left: 4px solid #ff7b00;
    color: #856404;
    margin-bottom: 1.5rem;
    padding: 1rem;
    border-radius: 8px;
    font-size: 0.95rem;
}

#modalCambiarPassword .alert-danger {
    background: #f8d7da;
    border-left: 4px solid #dc3545;
    color: #721c24;
    margin-bottom: 1.5rem;
    padding: 1rem;
    border-radius: 8px;
}

#modalCambiarPassword .alert-success {
    background: #d4edda;
    border-left: 4px solid #28a745;
    color: #155724;
    margin-bottom: 1.5rem;
    padding: 1rem;
    border-radius: 8px;
}

#modalCambiarPassword .form-group {
    margin-bottom: 1.5rem;
}

#modalCambiarPassword .form-group label {
    font-weight: 600;
    color: #495057;
    margin-bottom: 0.5rem;
    display: block;
    font-size: 0.95rem;
}

#modalCambiarPassword .input-group {
    box-shadow: 0 2px 4px rgba(0,0,0,0.02);
}

#modalCambiarPassword .form-control {
    border: 1px solid #ced4da;
    border-right: none;
    padding: 0.75rem;
    font-size: 1rem;
    border-radius: 8px 0 0 8px;
    transition: all 0.2s;
}

#modalCambiarPassword .form-control:focus {
    border-color: #ff7b00;
    outline: none;
    box-shadow: none;
}

#modalCambiarPassword .input-group-append .btn {
    border: 1px solid #ced4da;
    border-left: none;
    border-radius: 0 8px 8px 0;
    padding: 0.75rem 1rem;
    background: white;
    color: #6c757d;
}

#modalCambiarPassword .input-group-append .btn:hover {
    background: #f8f9fa;
    color: #ff7b00;
}

#modalCambiarPassword .progress {
    height: 4px;
    margin-top: 0.5rem;
    border-radius: 2px;
    background: #e9ecef;
}

#modalCambiarPassword .progress-bar {
    border-radius: 2px;
    transition: width 0.3s ease;
}

#modalCambiarPassword .requirements {
    background: #f8f9fa;
    padding: 1.2rem;
    border-radius: 10px;
    border: 1px solid #e9ecef;
    margin: 1.5rem 0;
}

#modalCambiarPassword .requirements div {
    font-size: 0.9rem;
    margin-bottom: 0.75rem;
    display: flex;
    align-items: center;
}

#modalCambiarPassword .requirements div:last-child {
    margin-bottom: 0;
}

#modalCambiarPassword .requirements i {
    width: 20px;
    font-size: 0.9rem;
    margin-right: 0.5rem;
}

#modalCambiarPassword .requirements .requirement-met {
    color: #28a745;
}

#modalCambiarPassword .requirements .requirement-met i {
    color: #28a745;
}

#modalCambiarPassword .btn-warning {
    background: linear-gradient(135deg, #ff7b00, #ff9800);
    border: none;
    color: white;
    font-weight: 600;
    padding: 1rem;
    font-size: 1.1rem;
    border-radius: 8px;
    transition: all 0.3s;
    text-transform: uppercase;
    letter-spacing: 1px;
}

#modalCambiarPassword .btn-warning:hover:not(:disabled) {
    transform: translateY(-2px);
    box-shadow: 0 5px 15px rgba(255,123,0,0.4);
}

#modalCambiarPassword .btn-warning:disabled {
    opacity: 0.6;
    cursor: not-allowed;
}

.list-group::-webkit-scrollbar {
    width: 6px;
}

.list-group::-webkit-scrollbar-thumb {
    background: #c7d2fe;
    border-radius: 6px;
}

/* Estilos adicionales para mejorar la apariencia */
.modal-content {
  border-radius: 12px;
  overflow: hidden;
}

.modal-header {
  border-bottom: 0;
}

.table th {
  font-weight: 600;
  text-transform: uppercase;
  font-size: 0.75rem;
  letter-spacing: 0.5px;
  color: #6c757d;
  border-bottom-width: 1px;
}

.table td {
  padding: 1rem 0.5rem;
  border-bottom: 1px solid rgba(0,0,0,0.05);
}

.table tbody tr:hover {
  background-color: rgba(0,0,0,0.02);
}

.summary-card {
  background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
}

.badge {
  font-weight: 500;
  letter-spacing: 0.3px;
}

.progress {
  border-radius: 10px;
  background-color: rgba(0,0,0,0.05);
}

.empty-state {
  opacity: 0.8;
}

/* Gradientes personalizados */
.bg-gradient-primary {
  background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
}

.bg-gradient-info {
  background: linear-gradient(135deg, #6b8cff 0%, #3a7bd5 100%);
}

.bg-gradient-success {
  background: linear-gradient(135deg, #43e97b 0%, #38f9d7 100%);
}

.bg-gradient-danger {
  background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%);
}

/* Animaciones */
.modal.fade .modal-dialog {
  transform: scale(0.95);
  transition: transform 0.2s ease-out;
}

.modal.show .modal-dialog {
  transform: scale(1);
}
</style>

<!-- Reemplaza TODO el bloque del modal con esto -->
<div class="content-wrapper <?= $mostrar_modal_password ? 'modal-active' : '' ?>">
    <!-- MODAL DE CAMBIO DE CONTRASEÑA OBLIGATORIO - VERSIÓN CORREGIDA -->
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
                        
                        <div class="form-group mb-3">
                            <label for="password_nueva" class="small font-weight-bold">
                                <i class="fas fa-lock mr-1"></i>Nueva Contraseña
                            </label>
                            <div class="input-group input-group-sm">
                                <input type="password" 
                                      class="form-control" 
                                      id="password_nueva" 
                                      name="password_nueva" 
                                      placeholder="Mínimo 8 caracteres"
                                      autocomplete="off"
                                      required>
                                <div class="input-group-append">
                                    <button class="btn btn-outline-secondary" type="button" onclick="togglePassword('password_nueva', this)">
                                        <i class="fas fa-eye"></i>
                                    </button>
                                </div>
                            </div>
                            <div class="progress mt-1" style="height: 3px;">
                                <div class="progress-bar" id="strengthBar" style="width: 0%;"></div>
                            </div>
                        </div>
                        
                        <div class="form-group mb-3">
                            <label for="password_confirmar" class="small font-weight-bold">
                                <i class="fas fa-check-circle mr-1"></i>Confirmar
                            </label>
                            <div class="input-group input-group-sm">
                                <input type="password" 
                                      class="form-control" 
                                      id="password_confirmar" 
                                      name="password_confirmar" 
                                      placeholder="Repite la contraseña"
                                      autocomplete="off"
                                      required>
                                <div class="input-group-append">
                                    <button class="btn btn-outline-secondary" type="button" onclick="togglePassword('password_confirmar', this)">
                                        <i class="fas fa-eye"></i>
                                    </button>
                                </div>
                            </div>
                        </div>
                        
                        <div class="requirements small p-2 mb-3" id="requisitos">
                            <div id="req-length" class="mb-1">
                                <i class="fas fa-times text-danger mr-1"></i>Mínimo 8 caracteres
                            </div>
                            <div id="req-different" class="mb-1">
                                <i class="fas fa-times text-danger mr-1"></i>No "Pescadores1"
                            </div>
                            <div id="req-match" class="mb-1">
                                <i class="fas fa-times text-danger mr-1"></i>Coinciden
                            </div>
                            <div id="req-strong">
                                <i class="fas fa-times text-danger mr-1"></i>Segura
                            </div>
                        </div>
                        
                        <button type="submit" class="btn btn-warning btn-sm btn-block" id="btnCambiar">
                            <i class="fas fa-sync-alt mr-2"></i>Cambiar Contraseña
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <style>
    /* ESTILOS CORREGIDOS PARA EL MODAL */
    #modalCambiarPassword {
        position: fixed;
        top: 0;
        left: 0;
        right: 0;
        bottom: 0;
        z-index: 9999;
        background: rgba(0, 0, 0, 0.5);
        display: flex !important;
        align-items: center;
        justify-content: center;
        padding: 10px;
    }

    /* Cuando el modal está oculto */
    #modalCambiarPassword[style*="display: none"] {
        display: none !important;
    }

    #modalCambiarPassword .modal-dialog {
        margin: 0;
        width: 100%;
        max-width: 500px;
        position: relative;
        z-index: 10000;
    }

    #modalCambiarPassword .modal-content {
        border-radius: 10px;
        overflow: hidden;
        border: none;
        box-shadow: 0 10px 25px rgba(0,0,0,0.2);
        background: white;
    }

    #modalCambiarPassword .modal-header {
        background: #ff7b00;
        color: white;
        padding: 10px 15px;
    }

    #modalCambiarPassword .modal-header h5 {
        font-size: 1rem;
        margin: 0;
    }

    #modalCambiarPassword .modal-body {
        padding: 15px;
    }

    #modalCambiarPassword .alert {
        padding: 8px 12px;
        margin-bottom: 15px;
        font-size: 0.85rem;
        border-radius: 6px;
    }

    #modalCambiarPassword .form-group {
        margin-bottom: 12px;
    }

    #modalCambiarPassword .form-group label {
        margin-bottom: 4px;
        font-size: 0.8rem;
    }

    #modalCambiarPassword .input-group-sm .form-control {
        font-size: 0.85rem;
        padding: 0.25rem 0.5rem;
    }

    #modalCambiarPassword .input-group-sm .btn {
        padding: 0.25rem 0.5rem;
        font-size: 0.85rem;
    }

    #modalCambiarPassword .requirements {
        background: #f8f9fa;
        border-radius: 6px;
        border: 1px solid #dee2e6;
        font-size: 0.75rem;
    }

    #modalCambiarPassword .requirements div {
        display: flex;
        align-items: center;
    }

    #modalCambiarPassword .requirements i {
        width: 16px;
        font-size: 0.7rem;
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
        padding: 0.4rem;
    }

    #modalCambiarPassword .btn-warning:hover {
        background: #e66a00;
    }

    .content-wrapper.modal-active {
        pointer-events: none;
    }

    /* Asegurar que SweetAlert esté por encima */
    .swal2-container {
        z-index: 20000 !important;
    }
    </style>

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

    // Validar requisitos en tiempo real
    document.getElementById('password_nueva').addEventListener('keyup', validarRequisitos);
    document.getElementById('password_confirmar').addEventListener('keyup', validarRequisitos);

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
            reqLength.innerHTML = '<i class="fas fa-check text-success mr-1"></i>Mínimo 8 caracteres ✓';
            reqLength.classList.add('requirement-met');
        } else {
            reqLength.innerHTML = '<i class="fas fa-times text-danger mr-1"></i>Mínimo 8 caracteres';
            reqLength.classList.remove('requirement-met');
        }
        
        // No Pescadores1
        if (password !== 'Pescadores1' && password.length > 0) {
            reqDifferent.innerHTML = '<i class="fas fa-check text-success mr-1"></i>No "Pescadores1" ✓';
            reqDifferent.classList.add('requirement-met');
        } else {
            reqDifferent.innerHTML = '<i class="fas fa-times text-danger mr-1"></i>No "Pescadores1"';
            reqDifferent.classList.remove('requirement-met');
        }
        
        // Coincidencia
        if (password === confirm && password.length > 0) {
            reqMatch.innerHTML = '<i class="fas fa-check text-success mr-1"></i>Coinciden ✓';
            reqMatch.classList.add('requirement-met');
        } else {
            reqMatch.innerHTML = '<i class="fas fa-times text-danger mr-1"></i>Coinciden';
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
            reqStrong.innerHTML = '<i class="fas fa-times text-danger mr-1"></i>Débil';
            reqStrong.classList.remove('requirement-met');
        } else if (strength <= 3) {
            strengthBar.style.width = strengthPercent + '%';
            strengthBar.className = 'progress-bar bg-warning';
            reqStrong.innerHTML = '<i class="fas fa-exclamation-triangle text-warning mr-1"></i>Media';
            reqStrong.classList.remove('requirement-met');
        } else {
            strengthBar.style.width = strengthPercent + '%';
            strengthBar.className = 'progress-bar bg-success';
            reqStrong.innerHTML = '<i class="fas fa-check text-success mr-1"></i>Segura ✓';
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
        submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin mr-2"></i>Cambiando...';
        
        // Crear FormData
        const formData = new FormData();
        formData.append('csrf_token', document.querySelector('input[name="csrf_token"]').value);
        formData.append('password_nueva', password);
        formData.append('password_confirmar', confirm);
        
        // Enviar vía AJAX al archivo separado
        fetch('cambiar_password_ajax.php', {
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
            // Primero ocultar el modal
            const modal = document.getElementById('modalCambiarPassword');
            modal.style.display = 'none';
            
            // Quitar la clase modal-active del content-wrapper
            document.querySelector('.content-wrapper').classList.remove('modal-active');
            
            // Pequeño retraso para asegurar que el modal se ocultó
            setTimeout(() => {
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
                    // Recargar la página después del SweetAlert
                    window.location.reload();
                });
            }, 100);
        } else {
            // Mostrar error
            errorDiv.textContent = data.message;
            errorDiv.style.display = 'block';
            
            // Rehabilitar botón
            submitBtn.disabled = false;
            submitBtn.innerHTML = '<i class="fas fa-sync-alt mr-2"></i>Cambiar Contraseña';
        }
    })
        .catch(error => {
            console.error('Error:', error);
            errorDiv.textContent = 'Error al conectar con el servidor';
            errorDiv.style.display = 'block';
            
            // Rehabilitar botón
            submitBtn.disabled = false;
            submitBtn.innerHTML = '<i class="fas fa-sync-alt mr-2"></i>Cambiar Contraseña';
        });
    });
    </script>
    <?php endif; ?>

    <section>
        <div class="container-fluid">
            <h2 class="mb-4 text-center">Panel de Ventas</h2>

            <!-- ALERTAS -->
            <?php if (!empty($stockBajoArray)): ?>
                  <div class="alert alert-danger alert-dismissible fade show d-flex align-items-center position-relative shadow-sm" role="alert">
                      <div class="mx-auto d-flex align-items-center">
                          <i class="fas fa-exclamation-triangle mr-2"></i>
                          <span>Productos con <strong>stock bajo</strong>. Contacta al administrador.</span>
                      </div>
                      <button type="button" class="close position-absolute" 
                              style="right:10px; top:50%; transform:translateY(-50%);" 
                              data-dismiss="alert" aria-label="Cerrar">
                          <span aria-hidden="true">&times;</span>
                      </button>
                  </div>
              <?php endif; ?>

              <?php if ($diasSinVender !== 'N/A' && $diasSinVender > 3): ?>
                  <div class="alert alert-secondary alert-dismissible fade show d-flex align-items-center position-relative shadow-sm" role="alert">
                      <div class="mx-auto d-flex align-items-center">
                          <i class="fas fa-clock mr-2"></i>
                          <span>Última venta hace <strong><?php echo $diasSinVender; ?> días</strong>.</span>
                      </div>
                      <button type="button" class="close position-absolute" 
                              style="right:10px; top:50%; transform:translateY(-50%);" 
                              data-dismiss="alert" aria-label="Cerrar">
                          <span aria-hidden="true">&times;</span>
                      </button>
                  </div>
              <?php endif; ?>

            <!-- CARDS DE VENTAS -->
            <div class="row">
              <!-- Ventas Hoy -->
              <div class="col-lg-3 col-md-6 col-12">
                  <div class="small-box bg-info" style="cursor:pointer"
                      data-toggle="modal" data-target="#modalVentasHoy">
                      <div class="inner text-center">
                          <h4>Ventas Hoy</h4>
                          <h2 class="fw-bold">
                              $<?php echo number_format($ventasHoy['total'] ?? 0, 2); ?>
                          </h2>
                          <span class="badge badge-light">
                              <?php echo $ventasHoy['num'] ?? 0; ?> ventas
                          </span>
                      </div>
                      <div class="icon">
                          <i class="fas fa-calendar-day"></i>
                      </div>
                      <div class="small-box-footer">
                          Ver detalle <i class="fas fa-arrow-circle-right"></i>
                      </div>
                  </div>
              </div>

              <!-- Ventas Semana -->
              <div class="col-lg-3 col-md-6 col-12">
                  <div class="small-box bg-primary" style="cursor:pointer"
                      data-toggle="modal" data-target="#modalVentasSemana">
                      <div class="inner text-center">
                          <h4>Ventas Semana</h4>
                          <h2 class="fw-bold">
                              $<?php echo number_format($ventasSemana['total'] ?? 0, 2); ?>
                          </h2>
                          <span class="badge badge-light">
                              <?php echo $ventasSemana['num'] ?? 0; ?> ventas
                          </span>
                      </div>
                      <div class="icon">
                          <i class="fas fa-calendar-week"></i>
                      </div>
                      <div class="small-box-footer">
                          Ver detalle <i class="fas fa-arrow-circle-right"></i>
                      </div>
                  </div>
              </div>

              <!-- Ventas Mes -->
              <div class="col-lg-3 col-md-6 col-12">
                  <div class="small-box bg-success" style="cursor:pointer"
                      data-toggle="modal" data-target="#modalVentasMes">
                      <div class="inner text-center">
                          <h4>Ventas Mes</h4>
                          <h2 class="fw-bold">
                              $<?php echo number_format($ventasMes['total'] ?? 0, 2); ?>
                          </h2>
                          <span class="badge badge-light">
                              <?php echo $ventasMes['num'] ?? 0; ?> ventas
                          </span>
                      </div>
                      <div class="icon">
                          <i class="fas fa-calendar-alt"></i>
                      </div>
                      <div class="small-box-footer">
                          Ver detalle <i class="fas fa-arrow-circle-right"></i>
                      </div>
                  </div>
              </div>

              <!-- Stock -->
              <?php
              $stockBajo = !empty($stockBajoArray);
              $stockColor = $stockBajo ? 'bg-danger' : 'bg-success';
              $stockIcon = $stockBajo ? 'fa-exclamation-triangle' : 'fa-check-circle';
              $stockText = $stockBajo ? 'Stock bajo' : 'Stock suficiente';
              ?>
              <div class="col-lg-3 col-md-6 col-12">
                  <div class="small-box <?php echo $stockColor; ?>"
                      style="cursor:pointer"
                      <?php echo $stockBajo ? 'data-toggle="modal" data-target="#modalStockBajo"' : ''; ?>>
                      <div class="inner text-center">
                          <h4>Stock</h4>
                          <h2>
                              <i class="fas <?php echo $stockIcon; ?>"></i>
                          </h2>
                          <span class="badge badge-light">
                              <?php echo $stockText; ?>
                          </span>
                      </div>
                      <div class="icon">
                          <i class="fas fa-boxes"></i>
                      </div>
                      <div class="small-box-footer">
                          <?php echo $stockBajo ? 'Revisar productos' : 'Todo en orden'; ?>
                      </div>
                  </div>
              </div>
            </div>

            <!-- FILTROS -->
            <div class="row mb-4 justify-content-center align-items-center">
                <div class="col-md-3 col-5 mb-2">
                    <div class="input-group input-group-sm">
                        <div class="input-group-prepend">
                            <span class="input-group-text bg-info text-white"><i class="fas fa-calendar-alt"></i></span>
                        </div>
                        <input type="date" class="form-control" id="inicio" placeholder="Fecha inicio">
                    </div>
                </div>
                <div class="col-md-1 col-1 text-center align-self-center mb-2 font-weight-bold">a</div>
                <div class="col-md-3 col-5 mb-2">
                    <div class="input-group input-group-sm">
                        <div class="input-group-prepend">
                            <span class="input-group-text bg-info text-white"><i class="fas fa-calendar-alt"></i></span>
                        </div>
                        <input type="date" class="form-control" id="fin" placeholder="Fecha fin">
                    </div>
                </div>
                <div class="col-md-2 col-12 mb-2 text-center">
                    <button class="btn btn-success btn-sm w-100" onclick="filtrar()"><i class="fas fa-filter"></i> Filtrar Ventas</button>
                </div>
            </div>

            <!-- GRAFICO -->
            <div class="row mb-4">
                <div class="col-12">
                    <div class="card card-outline card-info shadow">
                        <div class="card-header bg-info text-white">
                            <h5 class="card-title mb-0"><i class="fas fa-chart-bar"></i> Ventas por Producto</h5>
                        </div>
                        <div class="card-body">
                            <canvas id="graficoVentas" style="height:300px;"></canvas>
                        </div>
                    </div>
                </div>
            </div>

            <!-- META + CLIENTES -->
            <div class="row">
                <!-- Productividad del Mes -->
                <div class="col-lg-6 col-12 mb-4">
                    <div class="card card-outline card-success shadow-sm h-100">
                        <div class="card-header">
                            <h5 class="card-title mb-0">
                                <i class="fas fa-bullseye text-success"></i> Productividad del Mes
                            </h5>
                        </div>

                        <div class="card-body">

                            <div class="row text-center mb-3">
                                <div class="col-4">
                                    <small class="text-muted">Meta</small>
                                    <div class="font-weight-bold">
                                        $<?php echo number_format($metaMensual, 2); ?>
                                    </div>
                                </div>
                                <div class="col-4">
                                    <small class="text-muted">Ventas</small>
                                    <div class="font-weight-bold">
                                        $<?php echo number_format($ventasActuales, 2); ?>
                                    </div>
                                </div>
                                <div class="col-4">
                                    <small class="text-muted">Estado</small>
                                    <div class="font-weight-bold text-<?php echo str_replace('bg-', '', $colorBarra); ?>">
                                        <?php echo $estado; ?>
                                    </div>
                                </div>
                            </div>

                            <div class="progress mb-2" style="height:22px; border-radius:12px;">
                                <div class="progress-bar <?php echo $colorBarra; ?> progress-bar-striped progress-bar-animated"
                                    style="width:<?php echo round($porcentaje); ?>%">
                                    <?php echo round($porcentaje); ?>%
                                </div>
                            </div>

                            <div class="d-flex justify-content-between">
                                <small class="text-muted">
                                    Progreso real
                                </small>
                                <small class="text-muted">
                                    Predicción: $<?php echo number_format($prediccionFinMes, 2); ?>
                                </small>
                            </div>

                            <hr>

                            <small class="text-muted d-block">
                                <?php if ($ventasMesAnterior > 0): ?>
                                    Meta basada en mes anterior +20%
                                <?php else: ?>
                                    Meta base aplicada (sin historial previo)
                                <?php endif; ?>
                            </small>

                        </div>
                    </div>
                </div>

                <!-- Clientes Frecuentes -->
                <div class="col-lg-6 col-12 mb-4">
                  <div class="card h-100 border-0 shadow-sm">

                    <div class="card-header bg-white border-bottom">
                      <h5 class="mb-0 font-weight-semibold text-dark">
                        <i class="fas fa-users text-muted mr-2"></i>Clientes Frecuentes
                      </h5>
                      <small class="text-muted">Actividad y valor acumulado</small>
                    </div>

                    <div class="card-body p-0">
                      <div class="list-group list-group-flush" style="max-height:320px; overflow:auto;">

                        <?php foreach ($clientes as $i => $cliente): 
                          $porcentaje = ($maxCompras > 0)
                            ? ($cliente['total_compras'] / $maxCompras) * 100
                            : 0;

                          $inicial = strtoupper(substr($cliente['email'],0,1));
                        ?>

                        <div class="list-group-item border-0 border-bottom py-3">

                          <div class="d-flex justify-content-between align-items-center">

                            <div class="d-flex align-items-center" style="min-width:55%;">
                              <div class="rounded-circle bg-light text-secondary d-flex align-items-center justify-content-center mr-3"
                                  style="width:36px;height:36px;font-weight:600;">
                                <?= $inicial ?>
                              </div>

                              <div class="text-truncate">
                                <div class="font-weight-semibold text-dark text-truncate"
                                    style="max-width:200px;">
                                  <?= htmlspecialchars($cliente['email']) ?>
                                </div>
                                <small class="text-muted">
                                  <?= $cliente['total_compras'] ?> compras
                                </small>
                              </div>
                            </div>

                            <div class="text-right">
                              <div class="font-weight-semibold text-success">
                                $<?= number_format($cliente['monto_gastado'],2) ?>
                              </div>
                              <small class="text-muted">Total</small>
                            </div>

                          </div>

                          <div class="mt-2">
                            <div class="progress" style="height:6px; background:#f1f5f9;">
                              <div class="progress-bar"
                                  style="
                                    width:<?= round($porcentaje) ?>%;
                                    background:#64748b;
                                  ">
                              </div>
                            </div>
                          </div>

                        </div>

                        <?php endforeach; ?>

                      </div>
                    </div>
                    <div class="card-footer bg-white border-top small text-muted d-flex justify-content-between">
                      <span><i class="fas fa-chart-bar mr-1"></i>Dia con mayor venta</span>
                      <span><?= date('d/m/Y') ?></span>
                    </div>
                  </div>
                </div>
            </div>
        </div>
    </section>
</div>

<!-- MODALES EXISTENTES MEJORADOS -->
<div class="modal fade" id="modalVentasHoy" tabindex="-1">
  <div class="modal-dialog modal-lg modal-dialog-scrollable">
    <div class="modal-content shadow-lg border-0">
      <div class="modal-header bg-gradient-primary text-white py-3">
        <h5 class="modal-title fw-bold">
          <i class="fas fa-calendar-day me-2"></i> Ventas de Hoy
        </h5>
      </div>
      <div class="modal-body p-0">
        <?php if (!empty($ventasHoyDetalle)): ?>
        <div class="summary-card bg-light p-4 border-bottom">
          <div class="d-flex justify-content-between align-items-center">
            <span class="text-muted">Resumen del día:</span>
            <div class="text-end">
              <small class="text-muted d-block">Total ventas</small>
              <span class="h4 text-success fw-bold">
                $<?= number_format(array_sum(array_column($ventasHoyDetalle,'total')),2) ?>
              </span>
            </div>
          </div>
        </div>
        
        <div class="table-responsive">
          <table class="table table-hover align-middle mb-0">
            <thead class="table-light">
              <tr>
                <th class="ps-4">Producto</th>
                <th class="text-center">Cantidad</th>
                <th class="text-end pe-4">Importe</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($ventasHoyDetalle as $index => $v): ?>
              <tr>
                <td class="ps-4 fw-medium">
                  <div class="d-flex align-items-center">
                    <span class="product-indicator bg-primary me-2"></span>
                    <?= htmlspecialchars($v['nombre']) ?>
                  </div>
                </td>
                <td class="text-center">
                  <span class="badge bg-info bg-opacity-10 text-info px-3 py-2 rounded-pill">
                    <i class="fas fa-box me-1"></i> <?= $v['cantidad_vendida'] ?>
                  </span>
                </td>
                <td class="text-end pe-4">
                  <span class="fw-bold text-success">
                    $<?= number_format($v['total'], 2) ?>
                  </span>
                </td>
              </tr>
              <?php endforeach; ?>
            </tbody>
            <tfoot class="table-light">
              <tr>
                <td colspan="2" class="text-end ps-4 fw-bold">Total:</td>
                <td class="text-end pe-4 fw-bold text-success h6 mb-0">
                  $<?= number_format(array_sum(array_column($ventasHoyDetalle,'total')),2) ?>
                </td>
              </tr>
            </tfoot>
          </table>
        </div>
        <?php else: ?>
          <div class="text-center text-muted py-5">
            <div class="empty-state">
              <div class="empty-state-icon mb-3">
                <i class="fas fa-receipt fa-4x text-muted opacity-50"></i>
              </div>
              <h6 class="fw-normal">No hay ventas registradas hoy</h6>
              <p class="small text-muted">Las ventas aparecerán aquí a medida que se realicen.</p>
            </div>
          </div>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>

<div class="modal fade" id="modalVentasSemana" tabindex="-1">
  <div class="modal-dialog modal-lg modal-dialog-scrollable">
    <div class="modal-content shadow-lg border-0">
      <div class="modal-header bg-gradient-info text-white py-3">
        <h5 class="modal-title fw-bold">
          <i class="fas fa-calendar-week me-2"></i> Ventas de la Semana
        </h5>
      </div>
      <div class="modal-body p-0">
        <?php if (!empty($ventasSemanaDetalle)): ?>
        <div class="summary-card bg-light p-4 border-bottom">
          <div class="row">
            <div class="col-6">
              <span class="text-muted">Productos vendidos:</span>
              <span class="d-block fw-bold"><?= count($ventasSemanaDetalle) ?> diferentes</span>
            </div>
            <div class="col-6 text-end">
              <small class="text-muted d-block">Total semanal</small>
              <span class="h4 text-success fw-bold">
                $<?= number_format(array_sum(array_column($ventasSemanaDetalle,'total')),2) ?>
              </span>
            </div>
          </div>
        </div>
        
        <div class="table-responsive">
          <table class="table table-hover align-middle mb-0">
            <thead class="table-light">
              <tr>
                <th class="ps-4">#</th>
                <th>Producto</th>
                <th class="text-center">Cantidad</th>
                <th class="text-end pe-4">Importe</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($ventasSemanaDetalle as $index => $v): ?>
              <tr>
                <td class="ps-4 text-muted" style="width: 50px;"><?= $index + 1 ?></td>
                <td class="fw-medium"><?= htmlspecialchars($v['nombre']) ?></td>
                <td class="text-center">
                  <div class="progress" style="height: 6px;">
                    <div class="progress-bar bg-info" role="progressbar" 
                         style="width: <?= min(100, ($v['cantidad_vendida'] / 10) * 100) ?>%"></div>
                  </div>
                  <span class="small text-muted mt-1 d-block"><?= $v['cantidad_vendida'] ?> unidades</span>
                </td>
                <td class="text-end pe-4 fw-bold text-info">
                  $<?= number_format($v['total'], 2) ?>
                </td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
        <?php else: ?>
          <div class="text-center text-muted py-5">
            <div class="empty-state">
              <i class="fas fa-calendar-times fa-4x mb-3 text-muted opacity-50"></i>
              <h6 class="fw-normal">No hay ventas esta semana</h6>
            </div>
          </div>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>

<div class="modal fade" id="modalVentasMes" tabindex="-1">
  <div class="modal-dialog modal-lg modal-dialog-scrollable">
    <div class="modal-content shadow-lg border-0">
      <div class="modal-header bg-gradient-success text-white py-3">
        <h5 class="modal-title fw-bold">
          <i class="fas fa-calendar-alt me-2"></i> Ventas del Mes
        </h5>
      </div>
      <div class="modal-body p-0">
        <?php if (!empty($ventasMesDetalle)): ?>
        <div class="bg-light p-4 border-bottom">
          <div class="d-flex justify-content-between align-items-center">
            <div>
              <span class="badge bg-success mb-2">Período actual</span>
              <h3 class="h5 mb-0">Total mensual</h3>
            </div>
            <span class="display-6 text-success fw-bold">
              $<?= number_format(array_sum(array_column($ventasMesDetalle,'total')),2) ?>
            </span>
          </div>
        </div>
        
        <div class="table-responsive">
          <table class="table table-hover align-middle mb-0">
            <thead class="table-light">
              <tr>
                <th class="ps-4">Producto</th>
                <th class="text-center">Cantidad</th>
                <th class="text-end pe-4">Importe</th>
                <th class="text-end pe-4">%</th>
              </tr>
            </thead>
            <tbody>
              <?php 
              $totalMes = array_sum(array_column($ventasMesDetalle,'total'));
              foreach ($ventasMesDetalle as $v): 
                $porcentaje = ($v['total'] / $totalMes) * 100;
              ?>
              <tr>
                <td class="ps-4">
                  <div class="d-flex align-items-center">
                    <i class="text-success me-2"></i>
                    <?= htmlspecialchars($v['nombre']) ?>
                  </div>
                </td>
                <td class="text-center">
                  <span class="badge bg-secondary bg-opacity-10 text-secondary px-3 py-2">
                    <?= $v['cantidad_vendida'] ?>
                  </span>
                </td>
                <td class="text-end pe-4 fw-bold text-success">
                  $<?= number_format($v['total'], 2) ?>
                </td>
                <td class="text-end pe-4">
                  <span class="text-muted small">
                    <?= number_format($porcentaje, 1) ?>%
                  </span>
                </td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
        <?php else: ?>
          <div class="text-center text-muted py-5">
            <i class="fas fa-calendar-minus fa-4x mb-3"></i>
            <p class="mb-0">No hay ventas este mes.</p>
          </div>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>

<div class="modal fade" id="modalStockBajo" tabindex="-1">
  <div class="modal-dialog modal-lg modal-dialog-centered">
    <div class="modal-content shadow-lg border-0">
      <div class="modal-header bg-gradient-danger text-white py-3">
        <h5 class="modal-title fw-bold">
          <i class="fas fa-exclamation-triangle me-2"></i> Productos con Stock Bajo
        </h5>
      </div>
      <div class="modal-body p-0">
        <?php if (!empty($stockBajoArray)): ?>
          <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
              <thead class="table-light">
                <tr>
                  <th class="ps-4">Producto</th>
                  <th class="text-center">Stock actual</th>
                  <th class="text-center">Estado</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($stockBajoArray as $s): ?>
                <tr>
                  <td class="ps-4 fw-medium">
                    <div class="d-flex align-items-center">
                      <i class="text-danger me-2"></i>
                      <?= htmlspecialchars($s['nombre']) ?>
                    </div>
                  </td>
                  <td class="text-center">
                    <span class="badge bg-danger rounded-pill px-3 py-2">
                      <?= $s['cantidad'] ?> unidades
                    </span>
                  </td>
                  <td class="text-center">
                    <div class="progress mx-auto" style="width: 80px; height: 8px;">
                      <div class="progress-bar bg-danger" role="progressbar" 
                           style="width: <?= min(100, ($s['cantidad'] / 20) * 100) ?>%"></div>
                    </div>
                  </td>
                </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        <?php else: ?>
          <div class="text-center py-5">
            <div class="success-state">
              <div class="bg-success bg-opacity-10 rounded-circle d-inline-flex p-3 mb-3">
                <i class="fas fa-check-circle text-success fa-3x"></i>
              </div>
              <h5 class="fw-normal text-success">¡Stock en niveles óptimos!</h5>
              <p class="small text-muted">Todos los productos tienen inventario suficiente.</p>
            </div>
          </div>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>

<script src="https://code.jquery.com/jquery-3.5.1.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/popper.js@1.16.1/dist/umd/popper.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@4.6.2/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

<script>
let chart;

async function filtrar() {
    const inicio = document.getElementById('inicio').value;
    const fin = document.getElementById('fin').value;

    if (!inicio || !fin) {
        Swal.fire({
            icon: 'warning',
            title: 'Fechas incompletas',
            text: 'Selecciona ambas fechas para filtrar las ventas.',
            confirmButtonText: 'Aceptar'
        });
        return;
    }

    const response = await fetch(`filtrar_ventas.php?inicio=${inicio}&fin=${fin}`);
    const data = await response.json();

    if (!data.success) {
        alert(data.message);
        return;
    }

    const ctx = document.getElementById('graficoVentas');
    const labels = data.grafica.map(g => g.producto);
    const values = data.grafica.map(g => g.total_vendida);

    if (chart) chart.destroy();

    chart = new Chart(ctx, {
        type: 'bar',
        data: {
            labels: labels,
            datasets: [{
                label: 'Cantidad vendida',
                data: values,
                backgroundColor: 'rgba(30,156,67,0.3)',
                borderColor: '#1e9c43',
                borderWidth: 2,
                borderRadius: 5
            }]
        },
        options: {
            responsive: true,
            scales: { y: { beginAtZero: true } },
            plugins: { legend: { display: false } }
        }
    });
}

// Gráfico inicial
window.addEventListener('DOMContentLoaded', () => {
    const ctx = document.getElementById('graficoVentas');
    chart = new Chart(ctx, {
        type: 'bar',
        data: {
            labels: [<?php 
                $res = $conn->query("
                    SELECT p.nombre, SUM(v.cantidad_vendida) AS total_vendida 
                    FROM ventas v
                    JOIN productos p ON v.id_producto = p.id 
                    WHERE v.id_vendedor = $id_vendedor
                    GROUP BY p.nombre 
                    ORDER BY total_vendida DESC 
                    LIMIT 6
                ");
                $labels = [];
                $values = [];
                while($r = $res->fetch_assoc()) { 
                    $labels[] = "'".$r['nombre']."'";
                    $values[] = $r['total_vendida'];
                }
                echo implode(',', $labels);
            ?>],
            datasets: [{
                label: 'Cantidad vendida',
                data: [<?php echo implode(',', $values); ?>],
                backgroundColor: 'rgba(30,156,67,0.3)',
                borderColor: '#1e9c43',
                borderWidth: 2,
                borderRadius: 5
            }]
        },
        options: {
            responsive: true,
            scales: { y: { beginAtZero: true } },
            plugins: { legend: { display: false } }
        }
    });
});
</script>