<?php
include('includes/db.php');
include('includes/header.php');
include('includes/navbar.php');
include('includes/session.php');
require_once('includes/csrf.php');

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

if ($_SESSION['rol'] !== 'vendedor') {
    header("Location: index.php");
    exit;
}

$alerta = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['registrar_venta'])) {
    csrf_check();

    $carrito = json_decode($_POST['carrito_json'] ?? '[]', true);
    $monto_pagado = floatval($_POST['monto_pagado']);
    $correo_cliente = trim($_POST['correo_cliente'] ?? '');
    $metodo_pago = $_POST['metodo_pago'] ?? null;

    // Inicializamos referencia vacía
    $referencia_pago = null;

    // Si el pago es con tarjeta (terminal física)
    if ($metodo_pago === "tarjeta_debito" || $metodo_pago === "tarjeta_credito") {
        $ultimos4 = $_POST['ultimos4'] ?? '';
        $tipo = $_POST['tipo_tarjeta_detectada'] ?? 'OTRA';
        $auth = $_POST['folio_autorizacion'] ?? '';
        $ref = $_POST['referencia_pago'] ?? '';
        $referencia_pago = "$tipo ****$ultimos4 | AUTH: $auth | REF: $ref";
    }
    // Si es transferencia
    else if ($metodo_pago === "transferencia") {
        $referencia_pago = trim($_POST['referencia_pago'] ?? '');
    }
    // Si es efectivo → no se guarda referencia
    else if ($metodo_pago === "efectivo") {
        $referencia_pago = null;
    }

    $id_vendedor = $_SESSION['usuario_id'];

    if (empty($carrito)) {
        $alerta = ['tipo' => 'error', 'titulo' => 'Carrito vacío', 'mensaje' => 'Agrega al menos un producto antes de registrar la venta.'];
    } else {
        $total = 0;
        foreach ($carrito as $item) $total += $item['precio'] * $item['cantidad'];
        $cambio = $monto_pagado - $total;

        if ($cambio < 0) {
            $alerta = ['tipo' => 'error', 'titulo' => 'Monto insuficiente', 'mensaje' => 'El monto pagado no cubre el total.'];
        } else {
            $errores = [];
            foreach ($carrito as $item) {
                $producto = $conn->query("SELECT cantidad FROM productos WHERE id={$item['id']}")->fetch_assoc();
                if ($producto['cantidad'] < $item['cantidad']) {
                    $errores[] = $item['nombre'];
                }
            }

            if (!empty($errores)) {
                $alerta = ['tipo' => 'error', 'titulo' => 'Sin stock', 'mensaje' => 'Stock insuficiente para: ' . implode(', ', $errores)];
            } else {
                $conn->begin_transaction();
                
                try {
                    // Generar folio único para el ticket
                    $folio = 'VENTA_' . date('Ymd') . '_' . uniqid();
                    
                    // Insertar cada producto como registro individual en ventas
                    foreach ($carrito as $item) {
                        $stmt = $conn->prepare("
                            INSERT INTO ventas (
                                id_producto, cantidad_vendida, correo_cliente, folio_ticket, 
                                id_vendedor, metodo_pago, referencia_pago
                            ) VALUES (?, ?, ?, ?, ?, ?, ?)
                        ");
                        
                        $referencia_safe = $referencia_pago ?? '';
                        $stmt->bind_param(
                            "iisssss",
                            $item['id'],
                            $item['cantidad'],
                            $correo_cliente,
                            $folio,
                            $id_vendedor,
                            $metodo_pago,
                            $referencia_safe
                        );
                        $stmt->execute();
                        $stmt->close();
                        
                        // Actualizar stock
                        $conn->query("UPDATE productos SET cantidad = cantidad - {$item['cantidad']} WHERE id={$item['id']}");
                    }
                    
                    // Obtener el ID de la primera venta para el ticket
                    $idVentaPrincipal = $conn->insert_id;
                    
                    // === Generar ticket PDF ===
                    require_once('includes/fpdf.php');
                    
                    if (!is_dir('tickets')) mkdir('tickets', 0777, true);
                    
                    $subtotal = $total / 1.16;
                    $iva = $total - $subtotal;
                    
                    $pdf = new FPDF('P', 'mm', array(80, 140 + count($carrito) * 8));
                    $pdf->AddPage();
                    $pdf->SetMargins(5, 5, 5);
                    
                    // Logo
                    if (file_exists('includes/logo.png')) {
                        $pdf->Image('includes/logo.png', 25, 5, 30);
                        $pdf->Ln(25);
                    }
                    
                    // Encabezado
                    $pdf->SetFont('Arial', 'B', 14);
                    $pdf->Cell(0, 6, utf8_decode('TIENDA PESCADORES'), 0, 1, 'C');
                    $pdf->SetFont('Arial', '', 8);
                    $pdf->Cell(0, 4, utf8_decode('RFC: PESC123456789'), 0, 1, 'C');
                    $pdf->Cell(0, 4, utf8_decode('Av. Principal #45, Col. Centro'), 0, 1, 'C');
                    $pdf->Cell(0, 4, utf8_decode('Tel: 222-555-8899'), 0, 1, 'C');
                    $pdf->Ln(3);
                    $pdf->Line(5, $pdf->GetY(), 75, $pdf->GetY());
                    $pdf->Ln(3);
                    
                    // Folio y fecha
                    $pdf->SetFont('Arial', 'B', 9);
                    $pdf->Cell(0, 5, utf8_decode('TICKET DE COMPRA'), 0, 1, 'C');
                    $pdf->SetFont('Arial', '', 8);
                    $pdf->Cell(0, 4, 'Folio: ' . $folio, 0, 1, 'C');
                    $pdf->Cell(0, 4, 'Fecha: ' . date('d/m/Y H:i:s'), 0, 1, 'C');
                    $pdf->Cell(0, 4, utf8_decode('Cliente: ') . ($correo_cliente ?: 'Público general'), 0, 1, 'C');
                    $pdf->Cell(0, 4, 'Atendido por: ' . ($_SESSION['nombre'] ?? 'Vendedor'), 0, 1, 'C');
                    $pdf->Ln(3);
                    $pdf->Line(5, $pdf->GetY(), 75, $pdf->GetY());
                    $pdf->Ln(3);
                    
                    // Encabezados de productos
                    $pdf->SetFont('Arial', 'B', 8);
                    $pdf->Cell(32, 4, utf8_decode('Producto'), 0, 0, 'L');
                    $pdf->Cell(10, 4, 'Cant', 0, 0, 'C');
                    $pdf->Cell(13, 4, 'P.U.', 0, 0, 'C');
                    $pdf->Cell(15, 4, 'Importe', 0, 1, 'R');
                    $pdf->Line(5, $pdf->GetY(), 75, $pdf->GetY());
                    $pdf->Ln(2);
                    
                    // Productos
                    $pdf->SetFont('Arial', '', 8);
                    foreach ($carrito as $p) {
                        $nombreProducto = utf8_decode($p['nombre']);
                        if (strlen($nombreProducto) > 16) $nombreProducto = substr($nombreProducto, 0, 16) . '...';
                        $importe = $p['precio'] * $p['cantidad'];
                        
                        $pdf->Cell(32, 4, $nombreProducto, 0, 0, 'L');
                        $pdf->Cell(10, 4, $p['cantidad'], 0, 0, 'C');
                        $pdf->Cell(13, 4, '$' . number_format($p['precio'], 2), 0, 0, 'C');
                        $pdf->Cell(15, 4, '$' . number_format($importe, 2), 0, 1, 'R');
                    }
                    
                    $pdf->Ln(2);
                    $pdf->Line(5, $pdf->GetY(), 75, $pdf->GetY());
                    $pdf->Ln(3);
                    
                    // Totales
                    $pdf->SetFont('Arial', '', 8);
                    $pdf->Cell(45, 4, 'Subtotal:', 0, 0, 'R');
                    $pdf->Cell(25, 4, '$' . number_format($subtotal, 2), 0, 1, 'R');
                    $pdf->Cell(45, 4, 'IVA (16%):', 0, 0, 'R');
                    $pdf->Cell(25, 4, '$' . number_format($iva, 2), 0, 1, 'R');
                    $pdf->SetFont('Arial', 'B', 9);
                    $pdf->Cell(45, 5, 'TOTAL:', 0, 0, 'R');
                    $pdf->Cell(25, 5, '$' . number_format($total, 2), 0, 1, 'R');
                    
                    // Método de pago
                    $pdf->Ln(2);
                    $pdf->SetFont('Arial', '', 7);
                    $metodo_texto = [
                        'efectivo' => 'Efectivo',
                        'transferencia' => 'Transferencia',
                        'tarjeta_debito' => 'Tarjeta Débito',
                        'tarjeta_credito' => 'Tarjeta Crédito'
                    ];
                    $pdf->Cell(0, 4, 'Metodo de pago: ' . ($metodo_texto[$metodo_pago] ?? $metodo_pago), 0, 1, 'L');
                    if ($referencia_pago) {
                        $pdf->Cell(0, 4, 'Ref: ' . utf8_decode($referencia_pago), 0, 1, 'L');
                    }
                    
                    $pdf->Ln(4);
                    $pdf->Line(5, $pdf->GetY(), 75, $pdf->GetY());
                    $pdf->Ln(4);
                    
                    // Pie de página
                    $pdf->SetFont('Arial', 'B', 8);
                    $pdf->MultiCell(0, 4, utf8_decode("¡Gracias por su compra!"), 0, 'C');
                    $pdf->SetFont('Arial', '', 7);
                    $pdf->MultiCell(0, 3, utf8_decode("Conserve este ticket para aclaraciones."), 0, 'C');
                    
                    $nombreArchivo = 'ticket_' . $folio . '.pdf';
                    $rutaArchivo = 'tickets/' . $nombreArchivo;
                    $pdf->Output('F', $rutaArchivo);
                    
                    // Enviar ticket por correo (opcional)
                    $ticketEnviado = false;
                    if (!empty($correo_cliente) && filter_var($correo_cliente, FILTER_VALIDATE_EMAIL)) {
                        require_once('includes/PHPMailer/src/Exception.php');
                        require_once('includes/PHPMailer/src/PHPMailer.php');
                        require_once('includes/PHPMailer/src/SMTP.php');
                        
                        $mail = new PHPMailer(true);
                        try {
                            $mail->isSMTP();
                            $mail->Host = 'smtp.gmail.com';
                            $mail->SMTPAuth = true;
                            $mail->Username = 'jesusgabrielmtz78@gmail.com';
                            $mail->Password = 'iwdf uyqu erzq wvbm';
                            $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
                            $mail->Port = 587;
                            $mail->setFrom('jesusgabrielmtz78@gmail.com', 'Tienda Pescadores');
                            $mail->addAddress($correo_cliente);
                            $mail->Subject = 'Ticket de compra - Tienda Pescadores';
                            $mail->Body = "Hola,\n\nGracias por su compra en Tienda Pescadores.\nAdjuntamos su ticket en formato PDF para su referencia.\n\nSaludos cordiales.";
                            $mail->addAttachment($rutaArchivo);
                            $mail->send();
                            $ticketEnviado = true;
                        } catch (Exception $e) {
                            error_log("Error al enviar correo: " . $e->getMessage());
                        }
                    }
                    
                    $conn->commit();
                    
                    $mensaje = "✅ Venta registrada correctamente.\nCambio: $" . number_format($cambio, 2);
                    if ($ticketEnviado) $mensaje .= "\nTicket enviado a $correo_cliente";
                    
                    $alerta = [
                        'tipo' => 'success', 
                        'titulo' => '¡Venta exitosa!', 
                        'mensaje' => $mensaje,
                        'folio' => $folio
                    ];
                    
                } catch (Exception $e) {
                    $conn->rollback();
                    $alerta = ['tipo' => 'error', 'titulo' => 'Error', 'mensaje' => 'Error al registrar la venta: ' . $e->getMessage()];
                }
            }
        }
    }
}
?>

<style>
/* =====================================================
   VARIABLES Y RESET
===================================================== */
:root {
    --primary: #f4a261;
    --primary-dark: #e76f51;
    --success: #22c55e;
    --success-dark: #16a34a;
    --danger: #ef4444;
    --warning: #f59e0b;
    --gray-50: #f9fafb;
    --gray-100: #f3f4f6;
    --gray-200: #e5e7eb;
    --gray-300: #d1d5db;
    --gray-600: #4b5563;
    --gray-800: #1f2937;
    --shadow-sm: 0 1px 2px 0 rgb(0 0 0 / 0.05);
    --shadow-md: 0 4px 6px -1px rgb(0 0 0 / 0.1);
    --shadow-lg: 0 10px 15px -3px rgb(0 0 0 / 0.1);
    --shadow-xl: 0 20px 25px -5px rgb(0 0 0 / 0.1);
    --radius-lg: 24px;
    --radius-md: 18px;
    --radius-sm: 12px;
}

/* =====================================================
   BASE GENERAL
===================================================== */
.content-wrapper {
    min-height: 100vh;
    padding: 20px;
    background: radial-gradient(circle at top, #fff7ed, #f8f9fa);
}

/* =====================================================
   CARD PRINCIPAL
===================================================== */
.pos-card {
    min-height: 95vh;
    border: none;
    border-radius: var(--radius-lg);
    box-shadow: var(--shadow-xl);
    background: #f8fafc;
    overflow: hidden;
}

/* =====================================================
   HEADER
===================================================== */
.pos-header {
    background: linear-gradient(135deg, var(--primary), var(--primary-dark));
    color: white;
    padding: 18px 28px;
    box-shadow: 0 6px 18px rgba(0,0,0,.18);
}

.pos-header h3 {
    font-weight: 900;
    letter-spacing: 0.6px;
    margin: 0;
}

/* =====================================================
   BUSCADOR
===================================================== */
.pos-buscador {
    display: flex;
    gap: 14px;
    background: white;
    padding: 18px;
    border-radius: var(--radius-md);
    box-shadow: var(--shadow-md);
    align-items: center;
    transition: all 0.2s;
}

.pos-buscador:focus-within {
    box-shadow: 0 0 0 3px rgba(244,162,97,.35), var(--shadow-lg);
}

.pos-buscador input {
    font-size: 18px;
    border: none;
    outline: none;
    background: transparent;
}

.pos-buscador input:focus {
    box-shadow: none;
}

/* =====================================================
   TABLA CARRITO
===================================================== */
.table-responsive {
    width: 100%;
    overflow-x: auto;
    max-height: 60vh;
    border-radius: var(--radius-md);
}

.pos-tabla {
    background: white;
    border-radius: var(--radius-md);
    overflow: hidden;
    box-shadow: var(--shadow-md);
}

.pos-tabla thead {
    background: linear-gradient(135deg, var(--primary), var(--primary-dark));
    color: white;
    position: sticky;
    top: 0;
    z-index: 10;
}

.pos-tabla thead th {
    font-weight: 600;
    text-transform: uppercase;
    font-size: 0.85rem;
    letter-spacing: 0.5px;
    padding: 15px !important;
    border: none !important;
}

.table-hover tbody tr {
    background: #fff;
    transition: all 0.2s;
}

.table-hover tbody tr:hover {
    background: #fff2e6;
    transform: scale(1.01);
    box-shadow: var(--shadow-sm);
}

.table-hover tbody tr td {
    border: none !important;
    vertical-align: middle;
    padding: 12px !important;
}

/* =====================================================
   INPUTS DE CANTIDAD
===================================================== */
.cantidad-input {
    width: 80px;
    text-align: center;
    border: 2px solid var(--gray-200);
    border-radius: var(--radius-sm);
    padding: 6px;
    font-weight: 600;
    transition: all 0.2s;
}

.cantidad-input:focus {
    border-color: var(--primary);
    outline: none;
    box-shadow: 0 0 0 3px rgba(244,162,97,.25);
}

.cantidad-input.error {
    border-color: var(--danger);
    animation: shake 0.3s;
}

/* =====================================================
   TOTALES
===================================================== */
.pos-totales {
    margin-bottom: 24px;
}

.pos-totales .form-group {
    background: white;
    padding: 20px;
    border-radius: var(--radius-md);
    box-shadow: var(--shadow-sm);
    margin-bottom: 0;
}

.pos-totales label {
    font-weight: 600;
    color: var(--gray-600);
    margin-bottom: 8px;
    font-size: 0.9rem;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

.pos-total {
    font-size: 32px;
    font-weight: 900;
    text-align: center;
    color: var(--success-dark);
    background: #ecfdf5;
    border: 2px solid var(--success);
    border-radius: var(--radius-sm);
    padding: 10px;
}

.pos-input {
    border: 2px solid var(--gray-200);
    background: var(--gray-50);
    border-radius: var(--radius-sm);
    font-weight: 600;
    padding: 12px;
    font-size: 1.2rem;
    transition: all 0.2s;
}

.pos-input:focus {
    border-color: var(--primary);
    background: white;
    outline: none;
    box-shadow: 0 0 0 3px rgba(244,162,97,.25);
}

.pos-input[readonly] {
    background: #fff2e6;
    border-color: var(--primary);
    font-weight: bold;
}

/* =====================================================
   CORREO
===================================================== */
.pos-correo {
    background: white;
    padding: 20px;
    border-radius: var(--radius-md);
    box-shadow: var(--shadow-sm);
}

.pos-correo label {
    font-weight: 600;
    color: var(--gray-600);
    margin-bottom: 8px;
}

/* =====================================================
   MÉTODOS DE PAGO
===================================================== */
.metodos-container {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
    gap: 15px;
    padding: 10px 0;
    margin-bottom: 20px;
}

.metodo-radio {
    background: white;
    border-radius: var(--radius-md);
    padding: 20px 15px;
    text-align: center;
    box-shadow: var(--shadow-sm);
    transition: all 0.3s;
    cursor: pointer;
    border: 3px solid transparent;
    position: relative;
    overflow: hidden;
}

.metodo-radio:hover {
    transform: translateY(-4px);
    border-color: var(--primary);
    background: #fff7ed;
    box-shadow: var(--shadow-lg);
}

.metodo-radio input {
    display: none;
}

.metodo-radio input:checked + .metodo-content {
    filter: drop-shadow(0 0 8px var(--primary));
}

.metodo-radio input:checked ~ .check-indicator {
    opacity: 1;
    transform: scale(1);
}

.metodo-radio .check-indicator {
    position: absolute;
    top: 10px;
    right: 10px;
    color: var(--success);
    font-size: 20px;
    opacity: 0;
    transform: scale(0);
    transition: all 0.2s;
}

.metodo-radio .icono-metodo {
    width: 45px;
    height: 45px;
    object-fit: contain;
    margin-bottom: 10px;
    transition: all 0.3s;
}

.metodo-radio:hover .icono-metodo {
    transform: scale(1.1);
}

.metodo-radio span {
    display: block;
    font-weight: 700;
    color: var(--gray-800);
}

/* Método seleccionado */
.metodo-radio.selected {
    border-color: var(--primary);
    background: #fff7ed;
    box-shadow: var(--shadow-md);
}

/* =====================================================
   CAMPOS EXTRAS DE PAGO
===================================================== */
#extraCampos {
    background: white;
    border-radius: var(--radius-md);
    padding: 25px;
    margin: 20px 0;
    box-shadow: var(--shadow-sm);
    border: 1px dashed var(--primary);
    animation: slideDown 0.3s ease;
}

.tarjeta-container {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
    gap: 20px;
}

.tarjeta-container .form-group {
    margin-bottom: 0;
}

.tarjeta-container label {
    font-weight: 600;
    color: var(--gray-600);
    margin-bottom: 5px;
    font-size: 0.9rem;
}

/* =====================================================
   BOTONES
===================================================== */
.btn-agregar {
    background: var(--primary);
    color: white;
    font-weight: 700;
    border-radius: var(--radius-sm);
    padding: 12px 24px;
    border: none;
    transition: all 0.2s;
}

.btn-agregar:hover {
    background: var(--primary-dark);
    transform: scale(1.05);
}

.btn-agregar i {
    margin-right: 8px;
}

.btn-eliminar {
    background: transparent;
    border: none;
    color: var(--danger);
    font-size: 1.2rem;
    transition: all 0.2s;
    padding: 8px;
}

.btn-eliminar:hover {
    transform: scale(1.2);
    color: #dc2626;
}

.pos-btn-venta {
    background: linear-gradient(135deg, var(--success), var(--success-dark));
    border: none;
    padding: 20px 32px;
    font-size: 24px;
    font-weight: 900;
    border-radius: var(--radius-md);
    color: white;
    box-shadow: 0 12px 28px rgba(34,197,94,.45);
    transition: all 0.3s;
    width: 100%;
    letter-spacing: 1px;
    cursor: pointer;
}

.pos-btn-venta:hover:not(:disabled) {
    transform: scale(1.02);
    box-shadow: 0 15px 35px rgba(34,197,94,.6);
}

.pos-btn-venta:disabled {
    opacity: 0.5;
    cursor: not-allowed;
    transform: none;
}

/* =====================================================
   ANIMACIONES
===================================================== */
@keyframes slideIn {
    from {
        opacity: 0;
        transform: translateX(30px) scale(.95);
    }
    to {
        opacity: 1;
        transform: translateX(0) scale(1);
    }
}

@keyframes slideDown {
    from {
        opacity: 0;
        transform: translateY(-20px);
    }
    to {
        opacity: 1;
        transform: translateY(0);
    }
}

@keyframes shake {
    0%, 100% { transform: translateX(0); }
    25% { transform: translateX(-5px); }
    75% { transform: translateX(5px); }
}

@keyframes blinkTotal {
    0% { box-shadow: 0 0 0 rgba(34,197,94,0); }
    50% { box-shadow: 0 0 25px rgba(34,197,94,.9); }
    100% { box-shadow: 0 0 0 rgba(34,197,94,0); }
}

@keyframes pagoPulse {
    0% { transform: scale(1); }
    50% { transform: scale(1.07); }
    100% { transform: scale(1); }
}

.producto-animado {
    animation: slideIn .35s cubic-bezier(.4,0,.2,1);
}

.total-flash {
    animation: blinkTotal .45s ease;
}

.pago-ok {
    animation: pagoPulse .6s ease;
}

/* =====================================================
   BADGES Y UTILIDADES
===================================================== */
.badge-stock-bajo {
    background: var(--warning);
    color: white;
    font-size: 0.7rem;
    padding: 3px 8px;
    border-radius: 20px;
    margin-left: 5px;
}

.badge-stock-agotado {
    background: var(--danger);
    color: white;
    font-size: 0.7rem;
    padding: 3px 8px;
    border-radius: 20px;
    margin-left: 5px;
}

/* =====================================================
   RESPONSIVE
===================================================== */
@media (max-width: 768px) {
    .pos-buscador {
        flex-direction: column;
    }
    
    .pos-buscador button {
        width: 100%;
    }
    
    .metodos-container {
        grid-template-columns: repeat(2, 1fr);
    }
    
    .pos-totales .row > div {
        margin-bottom: 15px;
    }
    
    .tarjeta-container {
        grid-template-columns: 1fr;
    }
    
    .pos-btn-venta {
        font-size: 18px;
        padding: 15px;
    }
}

@media (max-width: 480px) {
    .metodos-container {
        grid-template-columns: 1fr;
    }
    
    .pos-header h3 {
        font-size: 1.2rem;
    }
}

/* ===== BOTÓN CERRAR PREMIUM ===== */
.swal2-close {
    color: #999 !important;
    font-size: 40px !important;
    font-weight: 300 !important;
    transition: all 0.2s ease !important;
    width: auto !important;
    height: auto !important;
    background: transparent !important;
    box-shadow: none !important;
    top: 10px !important;
    right: 15px !important;
}

.swal2-close:hover {
    color: #dc3545 !important;
    transform: scale(1.1) !important;
    background: transparent !important;
}

/* Ajuste general del popup */
.swal-adminlte {
    border-radius: 25px !important;
    padding: 30px !important;
}

</style>

<div class="content-wrapper">
    <div class="container-fluid">
        <h2 class="mb-4 text-center font-weight-bold" style="letter-spacing:.5px;">
            <i class="fas fa-cash-register mr-2" style="color: var(--primary);"></i> 
            Punto de venta
        </h2>

        <div class="row justify-content-center">
            <div class="col-12">
                <div class="card pos-card">
                    <div class="card-header pos-header">
                        <h3 class="card-title mb-0">
                            <i class="fas fa-shopping-cart mr-2"></i> 
                            Nueva venta
                        </h3>
                    </div>

                    <div class="card-body">
                        <form method="POST" id="ventaForm">
                            <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>">
                            <input type="hidden" name="registrar_venta" value="1">
                            <input type="hidden" name="carrito_json" id="carrito_json">

                            <!-- BUSCADOR -->
                            <div class="pos-buscador mb-4">
                                <i class="fas fa-barcode text-muted fa-lg"></i>
                                <input type="text" class="form-control" id="codigo" 
                                       placeholder="Escanea o escribe el código del producto" 
                                       autocomplete="off" autofocus>
                                <button type="button" class="btn btn-agregar" onclick="agregarProducto()">
                                    <i class="fas fa-plus"></i> Agregar
                                </button>
                            </div>

                            <!-- TABLA DE PRODUCTOS -->
                            <div class="table-responsive mb-4 pos-tabla">
                                <table class="table table-hover text-center mb-0">
                                    <thead>
                                        <tr>
                                            <th>Producto</th>
                                            <th width="130">Cantidad</th>
                                            <th width="120">Precio</th>
                                            <th width="120">Subtotal</th>
                                            <th width="100">Acción</th>
                                        </tr>
                                    </thead>
                                    <tbody id="carritoBody">
                                        <tr id="emptyCartRow">
                                            <td colspan="5" class="text-center py-5">
                                                <i class="fas fa-shopping-cart fa-3x text-muted mb-3"></i>
                                                <p class="text-muted">El carrito está vacío. Agrega productos para comenzar.</p>
                                            </td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>

                            <!-- TOTALES -->
                            <div class="row mb-4 pos-totales">
                                <div class="col-md-4">
                                    <div class="form-group">
                                        <label><i class="fas fa-calculator mr-1"></i> Total</label>
                                        <input type="text" class="form-control pos-total" id="total" value="0.00" readonly>
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="form-group">
                                        <label><i class="fas fa-money-bill mr-1"></i> Monto pagado</label>
                                        <input type="number" class="form-control pos-input" name="monto_pagado" 
                                               id="monto_pagado" step="0.01" min="0" required 
                                               placeholder="0.00" oninput="calcularCambio()">
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="form-group">
                                        <label><i class="fas fa-exchange-alt mr-1"></i> Cambio</label>
                                        <input type="text" class="form-control pos-input" id="cambio" value="0.00" readonly>
                                    </div>
                                </div>
                            </div>

                            <!-- CORREO DEL CLIENTE -->
                            <div class="form-group mb-4 pos-correo">
                                <label><i class="fas fa-envelope mr-2"></i> Correo del cliente (para enviar ticket)</label>
                                <input type="email" class="form-control pos-input" name="correo_cliente" 
                                       id="correo_cliente" placeholder="cliente@ejemplo.com">
                                <small class="text-muted">Opcional - si se proporciona, enviaremos el ticket por correo</small>
                            </div>

                            <!-- MÉTODOS DE PAGO -->
                            <div class="metodos-container">
                                <label class="metodo-radio" onclick="seleccionarMetodo(this)">
                                    <input type="radio" name="metodo_pago" value="efectivo" checked>
                                    <div class="check-indicator"><i class="fas fa-check-circle"></i></div>
                                    <div class="metodo-content">
                                        <img src="https://cdn.jsdelivr.net/gh/twitter/twemoji@latest/assets/svg/1f4b5.svg" 
                                             class="icono-metodo" alt="Efectivo">
                                        <span>Efectivo</span>
                                    </div>
                                </label>

                                <label class="metodo-radio" onclick="seleccionarMetodo(this)">
                                    <input type="radio" name="metodo_pago" value="transferencia">
                                    <div class="check-indicator"><i class="fas fa-check-circle"></i></div>
                                    <div class="metodo-content">
                                        <img src="https://cdn-icons-png.flaticon.com/512/2331/2331947.png" 
                                             class="icono-metodo" alt="Transferencia">
                                        <span>Transferencia</span>
                                    </div>
                                </label>

                                <label class="metodo-radio" onclick="seleccionarMetodo(this)">
                                    <input type="radio" name="metodo_pago" value="tarjeta_debito">
                                    <div class="check-indicator"><i class="fas fa-check-circle"></i></div>
                                    <div class="metodo-content">
                                        <img src="https://upload.wikimedia.org/wikipedia/commons/4/41/Visa_Logo.png" 
                                             class="icono-metodo" alt="Débito">
                                        <span>Tarjeta Débito</span>
                                    </div>
                                </label>

                                <label class="metodo-radio" onclick="seleccionarMetodo(this)">
                                    <input type="radio" name="metodo_pago" value="tarjeta_credito">
                                    <div class="check-indicator"><i class="fas fa-check-circle"></i></div>
                                    <div class="metodo-content">
                                        <img src="https://upload.wikimedia.org/wikipedia/commons/0/04/Mastercard-logo.png" 
                                             class="icono-metodo" alt="Crédito">
                                        <span>Tarjeta Crédito</span>
                                    </div>
                                </label>
                            </div>

                            <!-- CAMPOS EXTRA SEGÚN MÉTODO DE PAGO -->
                            <div id="extraCampos"></div>

                            <!-- BOTÓN DE CONFIRMAR -->
                            <div class="form-group text-right mt-4">
                                <button type="button" class="pos-btn-venta" onclick="confirmarVenta()" id="btnConfirmar">
                                    <i class="fas fa-check-circle mr-2"></i> Confirmar venta
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- AUDIO PARA EFECTO DE CAJA -->
<audio id="sonidoCaja" preload="auto">
    <source src="https://assets.mixkit.co/sfx/preview/mixkit-cash-register-purchase-2759.mp3" type="audio/mpeg">
</audio>

<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

<script>
// =====================================================
// VARIABLES GLOBALES
// =====================================================
let carrito = [];
let productoActual = null;

// Cargar carrito al iniciar
document.addEventListener('DOMContentLoaded', function() {
    cargarCarrito();
    mostrarCamposPago(); // Mostrar campos iniciales (efectivo)
    
    // Enfocar el input de código
    document.getElementById('codigo').focus();
});

// =====================================================
// FUNCIONES DEL CARRITO
// =====================================================

async function agregarProducto() {
    const codigo = document.getElementById('codigo').value.trim();
    
    if (!codigo) {
        Swal.fire({
            icon: 'warning',
            title: 'Atención',
            text: 'Ingresa o escanea un código de producto.',
            timer: 2000,
            showConfirmButton: false
        });
        return;
    }

    try {
        const res = await fetch(`buscar_producto.php?codigo=${encodeURIComponent(codigo)}`);
        const data = await res.json();
        
        if (!data.success) {
            Swal.fire({
                icon: 'error',
                title: 'Producto no encontrado',
                text: data.message || 'El código ingresado no corresponde a ningún producto.',
                timer: 2000,
                showConfirmButton: false
            });
            document.getElementById('codigo').value = '';
            return;
        }

        const producto = {
            id: data.id,
            nombre: data.nombre,
            precio: parseFloat(data.precio_venta),
            cantidad: 1,
            stock: parseInt(data.stock),
            imagen: data.imagen || 'uploads/no-image.png'
        };

        const existente = carrito.find(p => p.id === producto.id);
        
        if (existente) {
            if (existente.cantidad < producto.stock) {
                existente.cantidad++;
            } else {
                Swal.fire({
                    icon: 'warning',
                    title: 'Stock insuficiente',
                    text: `Solo hay ${producto.stock} unidades disponibles de ${producto.nombre}.`,
                    timer: 2000,
                    showConfirmButton: false
                });
                document.getElementById('codigo').value = '';
                return;
            }
        } else {
            carrito.push(producto);
        }

        document.getElementById('codigo').value = '';
        guardarCarrito();
        renderCarrito();
        
        // Enfocar de nuevo el input
        document.getElementById('codigo').focus();
        
    } catch (error) {
        console.error('Error:', error);
        Swal.fire({
            icon: 'error',
            title: 'Error',
            text: 'Error al buscar el producto. Intenta de nuevo.',
            timer: 2000,
            showConfirmButton: false
        });
    }
}

function renderCarrito() {
    const body = document.getElementById('carritoBody');
    const emptyRow = document.getElementById('emptyCartRow');
    
    if (carrito.length === 0) {
        if (emptyRow) emptyRow.style.display = '';
        body.innerHTML = '<tr id="emptyCartRow"><td colspan="5" class="text-center py-5"><i class="fas fa-shopping-cart fa-3x text-muted mb-3"></i><p class="text-muted">El carrito está vacío. Agrega productos para comenzar.</p></td></tr>';
        document.getElementById('total').value = '0.00';
        document.getElementById('cambio').value = '0.00';
        return;
    }
    
    if (emptyRow) emptyRow.style.display = 'none';
    
    let html = '';
    let total = 0;
    
    carrito.forEach((item, index) => {
        const subtotal = item.precio * item.cantidad;
        total += subtotal;
        
        const stockClass = item.cantidad >= item.stock ? 'text-danger' : '';
        const stockBadge = item.stock <= 5 ? `<span class="badge-stock-bajo">Stock bajo: ${item.stock}</span>` : '';
        
        html += `
            <tr class="producto-animado">
                <td style="display: flex; align-items: center; gap: 12px;">
                    <img src="${item.imagen}" width="45" height="45" 
                         style="border-radius: 8px; object-fit: cover; border: 2px solid var(--primary);"
                         onerror="this.src='uploads/no-image.png'">
                    <div style="text-align: left;">
                        <strong>${item.nombre}</strong>
                        <br>
                        <small class="${stockClass}">Stock: ${item.stock} ${stockBadge}</small>
                    </div>
                </td>
                <td>
                    <input type="number" class="cantidad-input" value="${item.cantidad}" 
                           min="1" max="${item.stock}" 
                           onchange="actualizarCantidad(${index}, this.value)"
                           onfocus="this.select()">
                </td>
                <td><strong>$${item.precio.toFixed(2)}</strong></td>
                <td><strong class="text-success">$${subtotal.toFixed(2)}</strong></td>
                <td>
                    <button type="button" class="btn-eliminar" onclick="eliminarProducto(${index})" title="Eliminar producto">
                        <i class="fas fa-trash-alt"></i>
                    </button>
                </td>
            </tr>
        `;
    });
    
    body.innerHTML = html;
    document.getElementById('total').value = total.toFixed(2);
    document.getElementById('total').classList.add('total-flash');
    setTimeout(() => {
        document.getElementById('total').classList.remove('total-flash');
    }, 500);
    
    calcularCambio();
}

function guardarCarrito() {
    localStorage.setItem('carrito', JSON.stringify(carrito));
}

function cargarCarrito() {
    const saved = localStorage.getItem('carrito');
    if (saved) {
        try {
            carrito = JSON.parse(saved);
            renderCarrito();
        } catch (e) {
            console.error('Error al cargar carrito:', e);
            carrito = [];
        }
    }
}

function actualizarCantidad(index, valor) {
    const cantidad = parseInt(valor);
    if (isNaN(cantidad) || cantidad < 1) {
        Swal.fire({
            icon: 'warning',
            title: 'Cantidad inválida',
            text: 'La cantidad debe ser al menos 1.',
            timer: 1500,
            showConfirmButton: false
        });
        renderCarrito();
        return;
    }
    
    if (cantidad > carrito[index].stock) {
        Swal.fire({
            icon: 'warning',
            title: 'Stock insuficiente',
            text: `Solo hay ${carrito[index].stock} unidades disponibles.`,
            timer: 1500,
            showConfirmButton: false
        });
        renderCarrito();
        return;
    }
    
    carrito[index].cantidad = cantidad;
    guardarCarrito();
    renderCarrito();
}

function eliminarProducto(index) {
    Swal.fire({
        title: '¿Eliminar producto?',
        text: `¿Quitar ${carrito[index].nombre} del carrito?`,
        icon: 'question',
        showCancelButton: true,
        confirmButtonColor: '#e76f51',
        cancelButtonColor: '#6c757d',
        confirmButtonText: 'Sí, eliminar',
        cancelButtonText: 'Cancelar'
    }).then(result => {
        if (result.isConfirmed) {
            carrito.splice(index, 1);
            guardarCarrito();
            renderCarrito();
            
            Swal.fire({
                icon: 'success',
                title: 'Eliminado',
                text: 'Producto eliminado del carrito',
                timer: 1500,
                showConfirmButton: false
            });
        }
    });
}

// =====================================================
// FUNCIONES DE PAGO
// =====================================================

function calcularCambio() {
    const total = parseFloat(document.getElementById('total').value) || 0;
    const pago = parseFloat(document.getElementById('monto_pagado').value) || 0;
    const cambio = pago - total;
    document.getElementById('cambio').value = cambio.toFixed(2);
    
    // Cambiar color según si es suficiente o no
    const cambioInput = document.getElementById('cambio');
    if (cambio < 0) {
        cambioInput.style.color = '#ef4444';
        cambioInput.style.fontWeight = 'bold';
    } else {
        cambioInput.style.color = '#16a34a';
        cambioInput.style.fontWeight = 'bold';
    }
}

function seleccionarMetodo(elemento) {
    // Remover selected de todos
    document.querySelectorAll('.metodo-radio').forEach(el => {
        el.classList.remove('selected');
    });
    // Agregar selected al actual
    elemento.classList.add('selected');
    // Marcar el radio
    const radio = elemento.querySelector('input[type="radio"]');
    radio.checked = true;
    // Mostrar campos específicos
    mostrarCamposPago();
}

function mostrarCamposPago() {
    const metodo = document.querySelector('input[name="metodo_pago"]:checked').value;
    const extra = document.getElementById('extraCampos');
    
    let html = '';
    
    switch(metodo) {
        case 'efectivo':
            html = `
                <div class="alert alert-success mb-0">
                    <i class="fas fa-money-bill-wave mr-2"></i>
                    <strong>Pago en efectivo</strong>
                    <p class="mb-0 mt-2 small">No se requiere referencia. Calcula el cambio automáticamente.</p>
                </div>
            `;
            break;
            
        case 'transferencia':
            html = `
                <div class="tarjeta-container">
                    <div class="form-group">
                        <label><i class="fas fa-hashtag mr-1"></i> Folio de transferencia *</label>
                        <input type="text" class="form-control" name="referencia_pago" 
                            id="folio_transferencia" required
                            placeholder="Ej: TRX87439210" maxlength="20" 
                            oninput="formatearFolioTransferencia(this)">
                        <small class="text-muted">Máximo 20 caracteres, solo letras y números</small>
                    </div>
                    <div class="form-group">
                        <label><i class="fas fa-info-circle mr-1"></i> Datos bancarios (clic para ampliar)</label>
                        <div class="alert alert-info p-2 small" style="cursor: pointer;" onclick="mostrarDatosBancarios()">
                            <i class="fas fa-university mr-1"></i> 
                            Banco: BBVA<br>
                            Cuenta: 1234 5678 9012 3456<br>
                            CLABE: 012 180 00123456789
                            <div class="text-center mt-1">
                                <small class="text-white"><i class="fas fa-search-plus"></i> Clic para ver detalles</small>
                            </div>
                        </div>
                    </div>
                </div>
            `;
            break;
            
        case 'tarjeta_debito':
        case 'tarjeta_credito':
            html = `
                <div class="tarjeta-container">
                    <div class="form-group">
                        <label><i class="fas fa-credit-card mr-1"></i> Últimos 4 dígitos *</label>
                        <input type="text" class="form-control" id="ultimos4"
                               name="ultimos4" maxlength="4" required
                               placeholder="Ej: 4921"
                               oninput="this.value = this.value.replace(/\\D/g,''); detectarTipoTarjeta();">
                    </div>
                    
                    <div class="form-group">
                        <label><i class="fas fa-tag mr-1"></i> Tipo de tarjeta</label>
                        <select class="form-control" id="tipo_tarjeta" name="tipo_tarjeta" disabled>
                            <option value="">Detectando...</option>
                            <option value="VISA">VISA</option>
                            <option value="MASTERCARD">MASTERCARD</option>
                            <option value="AMEX">AMERICAN EXPRESS</option>
                            <option value="DISCOVER">DISCOVER</option>
                            <option value="CARNET">CARNET</option>
                            <option value="VALES">VALES</option>
                            <option value="OTRA">OTRA</option>
                        </select>
                        <input type="hidden" name="tipo_tarjeta_detectada" id="tipo_tarjeta_detectada">
                    </div>
                    
                    <div class="form-group">
                        <label><i class="fas fa-check-circle mr-1"></i> Folio de autorización *</label>
                        <input type="text" class="form-control" name="folio_autorizacion"
                               id="folio_autorizacion" required
                               maxlength="16" placeholder="Ej: AUTH-938492"
                               oninput="validarFolio(this)">
                        <small class="text-muted">Hasta 16 caracteres</small>
                    </div>
                    
                    <div class="form-group">
                        <label><i class="fas fa-hashtag mr-1"></i> Número de referencia *</label>
                        <input type="text" class="form-control" name="referencia_pago"
                               id="referencia_pago" required
                               maxlength="20" placeholder="Ej: REF-39482091"
                               oninput="validarReferencia(this)">
                        <small class="text-muted">Hasta 20 caracteres</small>
                    </div>
                </div>
            `;
            break;
    }
    
    extra.innerHTML = html;
    
    // Si es efectivo, enfocar el monto pagado
    if (metodo === 'efectivo') {
        document.getElementById('monto_pagado').focus();
    }
}

// =====================================================
// FUNCIONES DE VALIDACIÓN DE CAMPOS
// =====================================================

function formatearFolioTransferencia(input) {
    let valor = input.value.toUpperCase().replace(/[^A-Z0-9]/g, "");
    input.value = valor.substring(0, 20);
}

function validarFolio(input) {
    let valor = input.value.toUpperCase().replace(/[^A-Z0-9]/g, "");
    input.value = valor.substring(0, 16);
}

function validarReferencia(input) {
    let valor = input.value.toUpperCase().replace(/[^A-Z0-9]/g, "");
    input.value = valor.substring(0, 20);
}

function detectarTipoTarjeta() {
    const ultimos4 = document.getElementById('ultimos4').value;
    const tipoSelect = document.getElementById('tipo_tarjeta');
    const oculto = document.getElementById('tipo_tarjeta_detectada');
    
    if (ultimos4.length < 1) {
        tipoSelect.value = '';
        oculto.value = '';
        return;
    }
    
    let tipo = "OTRA";
    
    // VISA (empieza con 4)
    if (/^4/.test(ultimos4)) tipo = "VISA";
    // Mastercard (51–55)
    else if (/^(5[1-5]|22[2-9]|2[3-7])/.test(ultimos4)) tipo = "MASTERCARD";
    // American Express (34 o 37)
    else if (/^(34|37)/.test(ultimos4)) tipo = "AMEX";
    // Discover (6011, 65)
    else if (/^(6011|65|64[4-9])/.test(ultimos4)) tipo = "DISCOVER";
    // CARNET (tarjetas mexicanas)
    else if (/^(2869|5020|5043|5060|5887)/.test(ultimos4)) tipo = "CARNET";
    // VALES
    else if (/^(6060|6061|6062|6277)/.test(ultimos4)) tipo = "VALES";
    
    tipoSelect.value = tipo;
    oculto.value = tipo;
}

// =====================================================
// CONFIRMACIÓN DE VENTA
// =====================================================

function confirmarVenta() {
    // Validar carrito
    if (carrito.length === 0) {
        Swal.fire({
            icon: 'warning',
            title: 'Carrito vacío',
            text: 'Agrega productos al carrito antes de registrar la venta.',
            confirmButtonColor: '#e76f51'
        });
        return;
    }
    
    // Validar monto pagado
    const total = parseFloat(document.getElementById('total').value);
    const pago = parseFloat(document.getElementById('monto_pagado').value);
    
    if (!pago || pago <= 0) {
        Swal.fire({
            icon: 'warning',
            title: 'Monto inválido',
            text: 'Ingresa un monto pagado válido.',
            confirmButtonColor: '#e76f51'
        });
        document.getElementById('monto_pagado').focus();
        return;
    }
    
    if (pago < total) {
        Swal.fire({
            icon: 'error',
            title: 'Monto insuficiente',
            text: `El pago ($${pago.toFixed(2)}) no cubre el total ($${total.toFixed(2)}).`,
            confirmButtonColor: '#e76f51'
        });
        document.getElementById('monto_pagado').focus();
        return;
    }
    
    // Validar campos según método de pago
    const metodo = document.querySelector('input[name="metodo_pago"]:checked').value;
    
    if (metodo === 'transferencia') {
        const folio = document.getElementById('folio_transferencia')?.value;
        if (!folio || folio.length < 5) {
            Swal.fire({
                icon: 'warning',
                title: 'Folio requerido',
                text: 'Ingresa el folio de la transferencia.',
                confirmButtonColor: '#e76f51'
            });
            return;
        }
    }
    
    if (metodo === 'tarjeta_debito' || metodo === 'tarjeta_credito') {
        const ultimos4 = document.getElementById('ultimos4')?.value;
        const auth = document.getElementById('folio_autorizacion')?.value;
        const ref = document.getElementById('referencia_pago')?.value;
        
        if (!ultimos4 || ultimos4.length !== 4) {
            Swal.fire({
                icon: 'warning',
                title: 'Datos incompletos',
                text: 'Ingresa los últimos 4 dígitos de la tarjeta.',
                confirmButtonColor: '#e76f51'
            });
            return;
        }
        
        if (!auth || auth.length < 4) {
            Swal.fire({
                icon: 'warning',
                title: 'Datos incompletos',
                text: 'Ingresa el folio de autorización.',
                confirmButtonColor: '#e76f51'
            });
            return;
        }
        
        if (!ref || ref.length < 4) {
            Swal.fire({
                icon: 'warning',
                title: 'Datos incompletos',
                text: 'Ingresa el número de referencia.',
                confirmButtonColor: '#e76f51'
            });
            return;
        }
    }
    
    // Crear resumen para la alerta (formato ORIGINAL)
    let resumen = carrito.map(p => `${p.nombre} x${p.cantidad} - $${(p.precio * p.cantidad).toFixed(2)}`).join('<br>');
    const cambio = (pago - total).toFixed(2);
    
    // ALERTA ORIGINAL - exactamente como la tenías antes
    Swal.fire({
        title: 'Confirmar venta',
        html: `${resumen}<hr><strong>Total:</strong> $${total.toFixed(2)}<br><strong>Pago:</strong> $${pago.toFixed(2)}<br><strong>Cambio:</strong> $${cambio}`,
        icon: 'question',
        showCancelButton: true,
        confirmButtonText: 'Registrar',
        cancelButtonText: 'Cancelar',
        confirmButtonColor: '#e76f51',
        preConfirm: () => {
            // Reproducir sonido de caja
            document.getElementById('sonidoCaja').play().catch(e => console.log('Error al reproducir sonido:', e));
            
            // Preparar y enviar formulario
            document.getElementById('carrito_json').value = JSON.stringify(carrito);
            document.getElementById('ventaForm').submit();
        }
    });
}

// =====================================================
// EVENTOS
// =====================================================

// Buscar al presionar Enter
document.getElementById('codigo').addEventListener('keydown', function(e) {
    if (e.key === 'Enter') {
        e.preventDefault();
        agregarProducto();
    }
});

// Calcular cambio al cambiar monto
document.getElementById('monto_pagado').addEventListener('input', calcularCambio);

// Validar correo si se ingresa
document.getElementById('correo_cliente').addEventListener('blur', function() {
    const correo = this.value;
    if (correo && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(correo)) {
        Swal.fire({
            icon: 'warning',
            title: 'Correo inválido',
            text: 'El formato del correo no es válido. El ticket no podrá enviarse.',
            timer: 2000,
            showConfirmButton: false
        });
    }
});

// =====================================================
// ALERTAS PHP
// =====================================================
<?php if(isset($alerta)): ?>
document.addEventListener('DOMContentLoaded', function() {
    const alerta = <?php echo json_encode($alerta); ?>;
    
    Swal.fire({
        icon: alerta.tipo,
        title: alerta.titulo,
        html: alerta.mensaje.replace(/\n/g, '<br>'),
        confirmButtonColor: '#e76f51',
        confirmButtonText: 'Aceptar',
        showCloseButton: true
    }).then(() => {
        <?php if($alerta['tipo'] === 'success'): ?>
        // Limpiar carrito después de venta exitosa
        localStorage.removeItem('carrito');
        carrito = [];
        renderCarrito();
        document.getElementById('monto_pagado').value = '';
        document.getElementById('correo_cliente').value = '';
        document.getElementById('codigo').focus();
        <?php endif; ?>
    });
});
<?php endif; ?>

// =====================================================
// FUNCIÓN PARA MOSTRAR DATOS BANCARIOS EN MODAL
// =====================================================
function mostrarDatosBancarios() {

    const datosQR = `
    BBVA

    TITULAR: KARMINA ARANGUTHY GARCÍA

    CUENTA: 1234 5678 9012 3456

    CLABE: 01218000123456789`;

    const qrURL = `https://api.qrserver.com/v1/create-qr-code/?size=250x250&data=${encodeURIComponent(datosQR)}`;

    // Verificar que Swal esté disponible
    if (typeof Swal === 'undefined') {
        mostrarToast('Error al cargar ventana modal', 'error');
        return;
    }

    Swal.fire({
        title: '<strong class="text-primary">Datos Bancarios</strong>',
        width: 1000,
        showConfirmButton: false,
        showCloseButton: true,
        customClass: {
            popup: 'swal-adminlte'
        },
        html: `
        <style>
            @keyframes float {
                0% { transform: translateY(0px); }
                50% { transform: translateY(-10px); }
                100% { transform: translateY(0px); }
            }
            
            @keyframes shine {
                0% { left: -100%; }
                20% { left: 120%; }
                100% { left: 120%; }
            }
            
            @keyframes pulse {
                0% { transform: scale(1); }
                50% { transform: scale(1.05); }
                100% { transform: scale(1); }
            }
            
            @keyframes glow {
                0% { box-shadow: 0 20px 40px rgba(0,68,129,0.3); }
                50% { box-shadow: 0 25px 50px rgba(0,68,129,0.5); }
                100% { box-shadow: 0 20px 40px rgba(0,68,129,0.3); }
            }
            
            @keyframes gradientMove {
                0% { background-position: 0% 50%; }
                50% { background-position: 100% 50%; }
                100% { background-position: 0% 50%; }
            }
            
            .tarjeta-bbva {
                animation: float 6s ease-in-out infinite, glow 3s ease-in-out infinite;
                transition: all 0.3s ease;
                background: linear-gradient(135deg, #004481, #0066b3, #0095da, #004481);
                background-size: 300% 300%;
                animation: float 6s ease-in-out infinite, glow 3s ease-in-out infinite, gradientMove 8s ease infinite;
            }
            
            .tarjeta-bbva:hover {
                transform: scale(1.02) translateY(-5px);
                animation: none;
                box-shadow: 0 30px 60px rgba(0,68,129,0.6);
            }
            
            .chip-tarjeta {
                animation: pulse 2s ease-in-out infinite;
                background: linear-gradient(135deg, #e3c484, #f5e6b8, #b49450);
                border-radius: 8px;
                width: 45px;
                height: 35px;
                position: relative;
                overflow: hidden;
            }
            
            .chip-tarjeta::after {
                content: '';
                position: absolute;
                top: -50%;
                left: -50%;
                width: 200%;
                height: 200%;
                background: linear-gradient(
                    to bottom right,
                    rgba(255,255,255,0) 0%,
                    rgba(255,255,255,0.3) 50%,
                    rgba(255,255,255,0) 100%
                );
                transform: rotate(45deg);
                animation: shine 3s infinite;
            }
            
            .qr-container {
                transition: all 0.3s ease;
                animation: float 6s ease-in-out infinite;
                animation-delay: 0.5s;
            }
            
            .qr-container:hover {
                transform: scale(1.05) rotate(2deg);
                box-shadow: 0 15px 40px rgba(0,68,129,0.25);
            }
            
            .numero-tarjeta {
                position: relative;
                display: inline-block;
                animation: pulse 3s ease-in-out infinite;
                animation-delay: 1s;
            }
            
            .chip-tarjeta i {
                font-size: 20px;
                color: #004481;
                opacity: 0;
                animation: pulse 2s ease-in-out infinite;
            }
            
            .badge-verificado {
                animation: pulse 2s ease-in-out infinite;
                background: linear-gradient(135deg, #00b09b, #96c93d);
                transition: all 0.3s ease;
            }
            
            .badge-verificado:hover {
                transform: scale(1.05);
                background: linear-gradient(135deg, #96c93d, #00b09b);
            }
            
            .logo-visa {
                animation: float 4s ease-in-out infinite;
                opacity: 0.2;
                transition: all 0.3s ease;
            }
            
            .logo-visa:hover {
                opacity: 0.4;
                transform: scale(1.1);
            }
            
            .dato-clabe, .dato-tarjeta {
                transition: all 0.3s ease;
                cursor: pointer;
                position: relative;
                overflow: hidden;
            }
            
            .dato-clabe:hover, .dato-tarjeta:hover {
                transform: scale(1.02);
                color: #004481;
            }
            
            .dato-clabe::after, .dato-tarjeta::after {
                content: '📋';
                position: absolute;
                right: -20px;
                top: 50%;
                transform: translateY(-50%);
                opacity: 0;
                transition: all 0.3s ease;
            }
            
            .dato-clabe:hover::after, .dato-tarjeta:hover::after {
                right: -30px;
                opacity: 1;
            }
            
            @keyframes ripple {
                0% { transform: scale(0); opacity: 1; }
                100% { transform: scale(4); opacity: 0; }
            }
            
            .ripple-effect {
                position: relative;
                overflow: hidden;
            }
            
            .ripple-effect:active::after {
                content: '';
                position: absolute;
                top: 50%;
                left: 50%;
                width: 5px;
                height: 5px;
                background: rgba(255,255,255,0.5);
                border-radius: 50%;
                transform: translate(-50%, -50%);
                animation: ripple 0.6s ease-out;
            }
        </style>
        
            <div style="padding: 10px; max-width: 100%;">

                <!-- Header BBVA con animación -->
                <div style="display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; margin-bottom: 20px;">
                    <div style="font-size: 28px; font-weight: 800; background: linear-gradient(135deg, #004481, #0095da); -webkit-background-clip: text; -webkit-text-fill-color: transparent; background-clip: text;">
                        
                    </div>
                    <div class="badge-verificado" style="background: linear-gradient(135deg, #004481, #0095da); padding: 8px 20px; border-radius: 30px; color: white; font-weight: 600; font-size: 14px; cursor: pointer;">
                        <i class="fas fa-check-circle" style="margin-right: 5px;"></i>VERIFICADO
                    </div>
                </div>

                <!-- CONTENEDOR FLEX RESPONSIVO -->
                <div style="
                    display: flex;
                    flex-wrap: wrap;
                    gap: 35px;
                    align-items: flex-start;   /* 🔥 CLAVE */
                    justify-content: center;
                ">

                    <!-- TARJETA CON ANIMACIONES MEJORADAS -->
                    <div class="tarjeta-bbva" style="
                       flex: 1 1 300px;
                        min-width: 280px;
                        max-width: 380px;
                        padding: 20px;
                        border-radius: 20px;
                        position: relative;
                        overflow: hidden;
                        cursor: pointer;
                    ">
                        
                        <div style="position: absolute; top: -50px; right: -50px; width: 200px; height: 200px; 
                                    background: radial-gradient(circle, rgba(255,255,255,0.15) 0%, transparent 70%); border-radius: 50%; 
                                    animation: pulse 4s ease-in-out infinite;"></div>
                        
                        <div style="position: absolute; bottom: -30px; left: -30px; width: 150px; height: 150px; 
                                    background: radial-gradient(circle, rgba(255,255,255,0.1) 0%, transparent 70%); border-radius: 50%;
                                    animation: pulse 5s ease-in-out infinite reverse;"></div>

                        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 30px;">
                            <div style="font-size: 24px; font-weight: 800; color: white; animation: pulse 3s ease-in-out infinite;">BBVA</div>
                            <div style="color: white; font-size: 14px; background: rgba(255,255,255,0.2); padding: 5px 15px; border-radius: 30px; backdrop-filter: blur(5px);">
                                <i class="fas fa-wifi" style="transform: rotate(90deg); margin-right: 5px; animation: pulse 2s ease-in-out infinite;"></i>DEBITO
                            </div>
                        </div>
                        
                        <div style="margin-bottom: 25px;">
                            <div class="chip-tarjeta">
                                <i class="fas fa-microchip"></i>
                            </div>
                            <div class="numero-tarjeta" style="font-size: 24px; font-family: monospace; letter-spacing: 3px; color: white; margin-top: 20px;">
                                1234 5678 9012 3456
                            </div>
                        </div>
                        
                        <div style="display: flex; justify-content: space-between; align-items: center; color: white;">
                            <div class="ripple-effect">
                                <div style="font-size: 10px; opacity: 0.7;">TITULAR</div>
                                <div style="font-weight: 600; font-size: 16px;">KARMINA ARANGUTHY GARCIA</div>
                            </div>
                            <div class="ripple-effect">
                                <div style="font-size: 10px; opacity: 0.7;">VENCE</div>
                                <div style="font-weight: 600; font-size: 16px;">12/26</div>
                            </div>
                        </div>

                        <div class="logo-visa" style="position: absolute; bottom: 20px; right: 20px; font-size: 32px; font-weight: 800; font-style: italic; color: rgba(255,255,255,0.2);">
                            VISA
                        </div>
                    </div>

                    <!-- QR CON ANIMACIONES -->
                    <div class="qr-container" style="
                     flex: 0 1 300px;
                        min-width: 280px;
                        max-width: 320px;
                        background: linear-gradient(135deg, #ffffff, #f8f9fa);
                        padding: 25px;
                        border-radius: 25px;
                        box-shadow: 0 10px 30px rgba(0,68,129,0.15);
                        border: 2px solid #004481;
                        text-align: center;
                        position: relative;
                        overflow: hidden;
                    ">

                        <div style="position: absolute; top: -20px; right: -20px; width: 100px; height: 100px; 
                                    background: radial-gradient(circle, rgba(0,68,129,0.05) 0%, transparent 70%); border-radius: 50%;"></div>

                        <div style="display: flex; align-items: center; justify-content: center; gap: 10px; margin-bottom: 20px;">
                            <i class="fas fa-qrcode" style="color: #004481; font-size: 24px; animation: pulse 2s ease-in-out infinite;"></i>
                            <span style="font-size: 18px; color: #004481; font-weight: 700;">ESCANEA Y TRANSFIERE</span>
                        </div>

                        <img src="${qrURL}" 
                        alt="QR Code"
                        style="
                            width: 180px;
                            height: 140px;
                            object-fit: contain;
                            border-radius: 15px;
                            box-shadow: 0 10px 20px rgba(0,0,0,0.1);
                            margin: 0 auto 20px auto;
                            display: block;
                            transition: all 0.3s ease;
                        "
                        onmouseover="this.style.transform='scale(1.05) rotate(2deg)'"
                        onmouseout="this.style.transform='scale(1) rotate(0deg)'">


                        <div style="font-family: monospace;">
                            <div class="dato-clabe" style="margin-bottom: 15px; padding: 5px; border-radius: 8px;">
                                <div style="font-size: 13px; color: #004481; font-weight: 700;">CLABE</div>
                                <div style="font-size: 16px; font-weight: 500;">01218000123456789</div>
                            </div>
                            <div class="dato-tarjeta" style="padding: 5px; border-radius: 8px;">
                                <div style="font-size: 13px; color: #004481; font-weight: 700;">CUENTA</div>
                                <div style="font-size: 16px; font-weight: 500;">1234567890123456</div>
                            </div>
                        </div>

                        <div style="margin-top: 20px; font-size: 12px; color: #6c757d; animation: pulse 3s ease-in-out infinite;">
                            <i class="fas fa-shield-alt"></i> Transferencia segura
                        </div>

                    </div>
                </div>
            </div>
        `
    });
}

</script>

<?php include('includes/footer.php'); ?>