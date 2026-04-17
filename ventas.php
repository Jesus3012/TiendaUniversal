<?php
include('includes/db.php');
include('includes/header.php');
include('includes/navbar.php');
include('includes/session.php');
require_once('includes/csrf.php');

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

if ($_SESSION['rol'] !== 'administrador' && $_SESSION['rol'] !== 'vendedor') {
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

    $referencia_pago = null;

    if ($metodo_pago === "tarjeta_debito" || $metodo_pago === "tarjeta_credito") {
        $ultimos4 = $_POST['ultimos4'] ?? '';
        $tipo = $_POST['tipo_tarjeta_detectada'] ?? 'OTRA';
        $auth = $_POST['folio_autorizacion'] ?? '';
        $referencia_pago = "$tipo ****$ultimos4 | AUTH: $auth";
    } else if ($metodo_pago === "transferencia") {
        $referencia_pago = trim($_POST['referencia_pago'] ?? '');
    } else if ($metodo_pago === "efectivo") {
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
                    $folio = 'VENTA_' . date('Ymd') . '_' . uniqid();
                    
                    foreach ($carrito as $item) {
                        $stmt = $conn->prepare("
                            INSERT INTO ventas (
                                id_producto, cantidad_vendida, correo_cliente, folio_ticket, 
                                id_vendedor, metodo_pago, referencia_pago, ticket_pdf
                            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                        ");
                        
                        $referencia_safe = $referencia_pago ?? '';
                        $nombre_ticket = 'ticket_' . $folio . '.pdf';
                        
                        $stmt->bind_param(
                            "iissssss",
                            $item['id'],
                            $item['cantidad'],
                            $correo_cliente,
                            $folio,
                            $id_vendedor,
                            $metodo_pago,
                            $referencia_safe,
                            $nombre_ticket
                        );
                        $stmt->execute();
                        $stmt->close();
                        
                        $conn->query("UPDATE productos SET cantidad = cantidad - {$item['cantidad']} WHERE id={$item['id']}");
                    }
                    
                    require_once('includes/fpdf.php');
                    
                    if (!is_dir('tickets')) mkdir('tickets', 0777, true);
                    
                    $subtotal = $total / 1.16;
                    $iva = $total - $subtotal;
                    
                    $pdf = new FPDF('P', 'mm', array(80, 140 + count($carrito) * 8));
                    $pdf->AddPage();
                    $pdf->SetMargins(5, 5, 5);
                    
                    if (file_exists('includes/logo.png')) {
                        $pdf->Image('includes/logo.png', 25, 5, 30);
                        $pdf->Ln(25);
                    }
                    
                    $pdf->SetFont('Arial', 'B', 14);
                    $pdf->Cell(0, 6, utf8_decode('TIENDA PESCADORES'), 0, 1, 'C');
                    $pdf->SetFont('Arial', '', 8);
                    $pdf->Cell(0, 4, utf8_decode('RFC: PESC123456789'), 0, 1, 'C');
                    $pdf->Cell(0, 4, utf8_decode('Av. Principal #45, Col. Centro'), 0, 1, 'C');
                    $pdf->Cell(0, 4, utf8_decode('Tel: 222-555-8899'), 0, 1, 'C');
                    $pdf->Ln(3);
                    $pdf->Line(5, $pdf->GetY(), 75, $pdf->GetY());
                    $pdf->Ln(3);
                    
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
                    
                    $pdf->SetFont('Arial', 'B', 8);
                    $pdf->Cell(32, 4, utf8_decode('Producto'), 0, 0, 'L');
                    $pdf->Cell(10, 4, 'Cant', 0, 0, 'C');
                    $pdf->Cell(13, 4, 'P.U.', 0, 0, 'C');
                    $pdf->Cell(15, 4, 'Importe', 0, 1, 'R');
                    $pdf->Line(5, $pdf->GetY(), 75, $pdf->GetY());
                    $pdf->Ln(2);
                    
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
                    
                    $pdf->SetFont('Arial', '', 8);
                    $pdf->Cell(45, 4, 'Subtotal:', 0, 0, 'R');
                    $pdf->Cell(25, 4, '$' . number_format($subtotal, 2), 0, 1, 'R');
                    $pdf->Cell(45, 4, 'IVA (16%):', 0, 0, 'R');
                    $pdf->Cell(25, 4, '$' . number_format($iva, 2), 0, 1, 'R');
                    $pdf->SetFont('Arial', 'B', 9);
                    $pdf->Cell(45, 5, 'TOTAL:', 0, 0, 'R');
                    $pdf->Cell(25, 5, '$' . number_format($total, 2), 0, 1, 'R');
                    
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
                    
                    $pdf->SetFont('Arial', 'B', 8);
                    $pdf->MultiCell(0, 4, utf8_decode("¡Gracias por su compra!"), 0, 'C');
                    $pdf->SetFont('Arial', '', 7);
                    $pdf->MultiCell(0, 3, utf8_decode("Conserve este ticket para aclaraciones."), 0, 'C');
                    
                    $nombreArchivo = 'ticket_' . $folio . '.pdf';
                    $rutaArchivo = 'tickets/' . $nombreArchivo;
                    $pdf->Output('F', $rutaArchivo);
                    
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
:root {
    --primary: #f97316;
    --primary-dark: #ea580c;
    --primary-light: #ffedd5;
    --primary-glow: rgba(249, 115, 22, 0.2);
    --success: #22c55e;
    --success-dark: #16a34a;
    --danger: #ef4444;
    --warning: #f59e0b;
    --gray-50: #f9fafb;
    --gray-100: #f3f4f6;
    --gray-200: #e5e7eb;
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

.content-wrapper {
    min-height: 100vh;
    padding: 20px;
    background: linear-gradient(135deg, #fef9f1 0%, #f8fafc 100%);
}

.pos-card {
    border: none;
    border-radius: var(--radius-lg);
    box-shadow: var(--shadow-xl);
    background: #ffffff;
    overflow: hidden;
}

.pos-header {
    background: linear-gradient(135deg, var(--primary), var(--primary-dark));
    color: white;
    padding: 20px 28px;
    position: relative;
    overflow: hidden;
}
.pos-header::before {
    content: '';
    position: absolute;
    top: -50%;
    right: -20%;
    width: 200px;
    height: 200px;
    background: rgba(255, 255, 255, 0.1);
    border-radius: 50%;
}
.pos-header h3 {
    font-weight: 800;
    letter-spacing: 0.5px;
    margin: 0;
    display: flex;
    align-items: center;
    gap: 12px;
}

.pos-buscador {
    background: white;
    padding: 8px;
    border-radius: var(--radius-md);
    box-shadow: var(--shadow-md);
    border: 1px solid var(--gray-200);
    transition: all 0.3s ease;
}
.pos-buscador:focus-within {
    border-color: var(--primary);
    box-shadow: 0 0 0 3px var(--primary-glow), var(--shadow-lg);
}
.pos-buscador .input-group-text {
    background: transparent;
    border: none;
    color: var(--primary);
    font-size: 1.2rem;
}
.pos-buscador input {
    border: none;
    outline: none;
    font-size: 1rem;
    padding: 12px 0;
}
.btn-agregar {
    background: var(--primary);
    color: white;
    font-weight: 600;
    border-radius: var(--radius-sm);
    padding: 10px 24px;
    border: none;
    transition: all 0.3s ease;
}
.btn-agregar:hover {
    background: var(--primary-dark);
    transform: translateY(-2px);
    box-shadow: 0 4px 12px var(--primary-glow);
}

.pos-tabla {
    background: white;
    border-radius: var(--radius-md);
    overflow: hidden;
    box-shadow: var(--shadow-sm);
    border: 1px solid var(--gray-200);
}
.pos-tabla thead {
    background: linear-gradient(135deg, var(--primary), var(--primary-dark));
    color: white;
}
.pos-tabla thead th {
    font-weight: 600;
    text-transform: uppercase;
    font-size: 0.75rem;
    letter-spacing: 0.5px;
    padding: 14px !important;
    border: none !important;
}
.pos-tabla tbody tr {
    transition: all 0.2s ease;
    border-bottom: 1px solid var(--gray-100);
}
.pos-tabla tbody tr:hover {
    background: var(--primary-light);
    transform: scale(1.01);
}
.pos-tabla tbody tr td {
    vertical-align: middle;
    padding: 12px !important;
}

.producto-imagen {
    width: 45px;
    height: 45px;
    border-radius: 10px;
    object-fit: cover;
    border: 2px solid var(--primary);
}
.producto-imagen-fallback {
    width: 45px;
    height: 45px;
    border-radius: 10px;
    background: linear-gradient(135deg, var(--primary), var(--primary-dark));
    display: flex;
    align-items: center;
    justify-content: center;
    font-weight: bold;
    font-size: 1.2rem;
    color: white;
}
.cantidad-input {
    width: 80px;
    text-align: center;
    border: 2px solid var(--gray-200);
    border-radius: var(--radius-sm);
    padding: 6px;
    font-weight: 600;
    transition: all 0.2s ease;
}
.cantidad-input:focus {
    border-color: var(--primary);
    outline: none;
    box-shadow: 0 0 0 3px var(--primary-glow);
}
.btn-eliminar {
    background: transparent;
    border: none;
    color: var(--danger);
    font-size: 1.1rem;
    transition: all 0.2s ease;
    padding: 8px;
    border-radius: 8px;
}
.btn-eliminar:hover {
    background: #fee2e2;
    transform: scale(1.1);
}

.pos-totales .form-group {
    background: white;
    padding: 16px;
    border-radius: var(--radius-md);
    box-shadow: var(--shadow-sm);
    border: 1px solid var(--gray-200);
}
.pos-totales label {
    font-weight: 600;
    color: var(--gray-600);
    margin-bottom: 8px;
    font-size: 0.75rem;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}
.pos-total {
    font-size: 28px;
    font-weight: 800;
    text-align: center;
    color: var(--primary-dark);
    background: var(--primary-light);
    border-radius: var(--radius-sm);
    padding: 12px;
}
.pos-input {
    border: 2px solid var(--gray-200);
    border-radius: var(--radius-sm);
    padding: 12px;
    font-size: 1.1rem;
    transition: all 0.2s ease;
}
.pos-input:focus {
    border-color: var(--primary);
    outline: none;
    box-shadow: 0 0 0 3px var(--primary-glow);
}
.pos-input[readonly] {
    background: var(--primary-light);
    border-color: var(--primary);
    font-weight: bold;
}

.pos-correo {
    background: white;
    padding: 16px;
    border-radius: var(--radius-md);
    box-shadow: var(--shadow-sm);
    border: 1px solid var(--gray-200);
}
.pos-correo label {
    font-weight: 600;
    color: var(--gray-600);
    margin-bottom: 8px;
    font-size: 0.75rem;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

.metodos-container {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(160px, 1fr));
    gap: 16px;
    margin-bottom: 20px;
}
.metodo-radio {
    background: white;
    border-radius: var(--radius-md);
    padding: 20px 15px;
    text-align: center;
    box-shadow: var(--shadow-sm);
    transition: all 0.3s ease;
    cursor: pointer;
    border: 2px solid transparent;
    position: relative;
}
.metodo-radio:hover {
    transform: translateY(-4px);
    border-color: var(--primary);
    background: var(--primary-light);
    box-shadow: var(--shadow-lg);
}
.metodo-radio.selected {
    border-color: var(--primary);
    background: var(--primary-light);
    box-shadow: 0 0 0 3px var(--primary-glow);
}
.metodo-radio input { display: none; }
.metodo-radio .check-indicator {
    position: absolute;
    top: 12px;
    right: 12px;
    color: var(--success);
    font-size: 18px;
    opacity: 0;
    transform: scale(0);
    transition: all 0.2s ease;
}
.metodo-radio.selected .check-indicator {
    opacity: 1;
    transform: scale(1);
}
.metodo-radio .icono-metodo {
    width: 48px;
    height: 48px;
    object-fit: contain;
    margin-bottom: 12px;
    transition: all 0.3s ease;
}
.metodo-radio:hover .icono-metodo { transform: scale(1.1); }
.metodo-radio span {
    display: block;
    font-weight: 700;
    color: var(--gray-800);
    font-size: 0.9rem;
}

#extraCampos {
    background: var(--gray-50);
    border-radius: var(--radius-md);
    padding: 20px;
    margin: 20px 0;
    border: 1px solid var(--gray-200);
    animation: slideDown 0.3s ease;
}

/* Tarjeta BBVA para mostrar en el modal */
.tarjeta-bbva-modal {
    background: linear-gradient(135deg, #004481, #0066b3);
    border-radius: 20px;
    padding: 20px;
    color: white;
    position: relative;
    overflow: hidden;
    box-shadow: 0 10px 25px rgba(0, 68, 129, 0.2);
}
.tarjeta-bbva-modal .header {
    display: flex;
    justify-content: space-between;
    margin-bottom: 30px;
}
.tarjeta-bbva-modal .banco {
    font-size: 24px;
    font-weight: 800;
}
.tarjeta-bbva-modal .tipo {
    background: rgba(255,255,255,0.2);
    padding: 4px 12px;
    border-radius: 20px;
    font-size: 12px;
}
.tarjeta-bbva-modal .chip {
    width: 45px;
    height: 35px;
    background: linear-gradient(135deg, #e3c484, #f5e6b8);
    border-radius: 8px;
    margin-bottom: 20px;
}
.tarjeta-bbva-modal .numero {
    font-size: 20px;
    font-family: monospace;
    letter-spacing: 3px;
    margin-bottom: 20px;
}
.tarjeta-bbva-modal .footer {
    display: flex;
    justify-content: space-between;
    align-items: center;
}
.tarjeta-bbva-modal .titular {
    font-size: 11px;
}
.tarjeta-bbva-modal .titular span {
    font-size: 14px;
    font-weight: 600;
    display: block;
}
.tarjeta-bbva-modal .logo {
    font-size: 28px;
    font-weight: 800;
    font-style: italic;
    opacity: 0.3;
}

/* Campos para tarjeta de crédito/débito */
.campos-tarjeta {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
    gap: 16px;
}
.campos-tarjeta .form-group { margin-bottom: 0; }
.campos-tarjeta label {
    font-weight: 600;
    color: var(--gray-600);
    margin-bottom: 6px;
    font-size: 0.8rem;
}

/* Botón para ver datos bancarios */
.btn-ver-datos {
    background: #f0fdf4;
    border: 1px solid #bbf7d0;
    border-radius: 30px;
    padding: 8px 16px;
    font-size: 0.75rem;
    font-weight: 600;
    color: #16a34a;
    cursor: pointer;
    transition: all 0.2s ease;
    display: inline-flex;
    align-items: center;
    gap: 6px;
}
.btn-ver-datos:hover {
    background: #dcfce7;
    transform: scale(1.02);
}

.pos-btn-venta {
    background: linear-gradient(135deg, var(--success), var(--success-dark));
    border: none;
    padding: 18px 32px;
    font-size: 1.2rem;
    font-weight: 800;
    border-radius: var(--radius-md);
    color: white;
    box-shadow: 0 8px 20px rgba(34, 197, 94, 0.3);
    transition: all 0.3s ease;
    width: 100%;
    letter-spacing: 1px;
    cursor: pointer;
}
.pos-btn-venta:hover:not(:disabled) {
    transform: translateY(-3px);
    box-shadow: 0 12px 28px rgba(34, 197, 94, 0.4);
}

@keyframes slideDown {
    from { opacity: 0; transform: translateY(-20px); }
    to { opacity: 1; transform: translateY(0); }
}
@keyframes slideIn {
    from { opacity: 0; transform: translateX(30px) scale(0.95); }
    to { opacity: 1; transform: translateX(0) scale(1); }
}
@keyframes blinkTotal {
    0% { box-shadow: 0 0 0 rgba(34, 197, 94, 0); }
    50% { box-shadow: 0 0 20px rgba(34, 197, 94, 0.5); }
    100% { box-shadow: 0 0 0 rgba(34, 197, 94, 0); }
}
.producto-animado { animation: slideIn 0.3s ease; }
.total-flash { animation: blinkTotal 0.5s ease; }

/* BREADCRUMB BLANCO PURO */
.custom-breadcrumb {
    background: white;
    border-radius: 16px;
    padding: 0.85rem 1.5rem;
    margin-bottom: 2rem;
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.04);
    border: 1px solid #eef2f6;
}
.custom-breadcrumb .breadcrumb { margin-bottom: 0; }
.custom-breadcrumb .breadcrumb-item {
    font-size: 0.85rem;
    display: inline-flex;
    align-items: center;
}
.custom-breadcrumb .breadcrumb-item a {
    color: #64748b;
    text-decoration: none;
    display: inline-flex;
    align-items: center;
    gap: 6px;
    transition: color 0.2s ease;
}
.custom-breadcrumb .breadcrumb-item a:hover { color: #f97316; }
.custom-breadcrumb .breadcrumb-item.active {
    color: #f97316;
    font-weight: 600;
    display: inline-flex;
    align-items: center;
    gap: 6px;
}
.custom-breadcrumb .breadcrumb-item i { font-size: 0.8rem; }
.custom-breadcrumb .breadcrumb-item + .breadcrumb-item::before {
    content: "›";
    font-size: 1.2rem;
    color: #cbd5e1;
    margin: 0 8px;
}

@media (max-width: 768px) {
    .metodos-container { grid-template-columns: repeat(2, 1fr); }
    .pos-totales .row > div { margin-bottom: 12px; }
    .campos-tarjeta { grid-template-columns: 1fr; }
    .pos-btn-venta { font-size: 1rem; padding: 14px; }
    .pos-header h3 { font-size: 1.2rem; }
}
@media (max-width: 480px) { .metodos-container { grid-template-columns: 1fr; } }
</style>

<div class="content-wrapper">
    <div class="container-fluid">
        
        <!-- BREADCRUMB BLANCO -->
        <div class="custom-breadcrumb">
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb">
                    <li class="breadcrumb-item">
                        <a href="<?= $_SESSION['rol'] === 'administrador' ? 'dashboard_admin.php' : 'dashboard_vendedor.php' ?>">
                            <i class="fas fa-home"></i> Inicio
                        </a>
                    </li>
                    <li class="breadcrumb-item">
                        <a href="dashboard_ventas.php">
                            <i class="fas fa-cash-register"></i> Registrar Venta
                        </a>
                    </li>
                    <li class="breadcrumb-item active">
                        <i class="fas fa-barcode"></i> Por Código de Barras
                    </li>
                </ol>
            </nav>
        </div>

        <div class="row justify-content-center">
            <div class="col-12">
                <div class="card pos-card">
                    <div class="card-header pos-header">
                        <h3 class="card-title mb-0">
                            <i class="fas fa-barcode"></i>
                            Venta por Código de Barras
                        </h3>
                    </div>

                    <div class="card-body p-4">
                        <form method="POST" id="ventaForm">
                            <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>">
                            <input type="hidden" name="registrar_venta" value="1">
                            <input type="hidden" name="carrito_json" id="carrito_json">

                            <div class="pos-buscador mb-4">
                                <div class="input-group">
                                    <span class="input-group-text"><i class="fas fa-barcode"></i></span>
                                    <input type="text" class="form-control" id="codigo" 
                                           placeholder="Escanea o escribe el código del producto" 
                                           autocomplete="off" autofocus>
                                    <button type="button" class="btn btn-agregar" onclick="agregarProducto()">
                                        <i class="fas fa-plus-circle me-2"></i> Agregar
                                    </button>
                                </div>
                            </div>

                            <div class="table-responsive mb-4 pos-tabla">
                                <table class="table table-hover text-center mb-0">
                                    <thead><tr><th width="50">#</th><th>Producto</th><th width="130">Cantidad</th><th width="120">Precio</th><th width="120">Subtotal</th><th width="80">Acción</th></tr></thead>
                                    <tbody id="carritoBody">
                                        <tr id="emptyCartRow"><td colspan="6" class="text-center py-5"><i class="fas fa-shopping-cart fa-3x text-muted mb-3"></i><p class="text-muted mb-0">El carrito está vacío. Agrega productos para comenzar.</p></tr>
                                    </tbody>
                                </table>
                            </div>

                            <div class="row g-3 mb-4 pos-totales">
                                <div class="col-md-4"><div class="form-group"><label><i class="fas fa-calculator me-1"></i> Total</label><input type="text" class="form-control pos-total" id="total" value="0.00" readonly></div></div>
                                <div class="col-md-4"><div class="form-group"><label><i class="fas fa-money-bill me-1"></i> Monto pagado</label><input type="number" class="form-control pos-input" name="monto_pagado" id="monto_pagado" step="0.01" min="0" placeholder="0.00" oninput="calcularCambio()"></div></div>
                                <div class="col-md-4"><div class="form-group"><label><i class="fas fa-exchange-alt me-1"></i> Cambio</label><input type="text" class="form-control pos-input" id="cambio" value="0.00" readonly></div></div>
                            </div>

                            <div class="form-group mb-4 pos-correo">
                                <label><i class="fas fa-envelope me-2"></i> Correo del cliente</label>
                                <input type="email" class="form-control pos-input" name="correo_cliente" id="correo_cliente" placeholder="cliente@ejemplo.com">
                                <small class="text-muted mt-2 d-block"><i class="fas fa-info-circle me-1"></i> Opcional - enviaremos el ticket por correo</small>
                            </div>

                            <!-- MÉTODOS DE PAGO -->
                            <div class="metodos-container">
                                <label class="metodo-radio" onclick="seleccionarMetodo(this)">
                                    <input type="radio" name="metodo_pago" value="efectivo" checked>
                                    <div class="check-indicator"><i class="fas fa-check-circle"></i></div>
                                    <div class="metodo-content">
                                        <img src="https://cdn.jsdelivr.net/gh/twitter/twemoji@latest/assets/svg/1f4b5.svg" class="icono-metodo">
                                        <span>Efectivo</span>
                                    </div>
                                </label>

                                <label class="metodo-radio" onclick="seleccionarMetodo(this)">
                                    <input type="radio" name="metodo_pago" value="transferencia">
                                    <div class="check-indicator"><i class="fas fa-check-circle"></i></div>
                                    <div class="metodo-content">
                                        <img src="https://cdn-icons-png.flaticon.com/512/2331/2331947.png" class="icono-metodo">
                                        <span>Transferencia</span>
                                    </div>
                                </label>

                                <label class="metodo-radio" onclick="seleccionarMetodo(this)">
                                    <input type="radio" name="metodo_pago" value="tarjeta_debito">
                                    <div class="check-indicator"><i class="fas fa-check-circle"></i></div>
                                    <div class="metodo-content">
                                        <img src="https://upload.wikimedia.org/wikipedia/commons/4/41/Visa_Logo.png" class="icono-metodo">
                                        <span>Tarjeta Débito</span>
                                    </div>
                                </label>

                                <label class="metodo-radio" onclick="seleccionarMetodo(this)">
                                    <input type="radio" name="metodo_pago" value="tarjeta_credito">
                                    <div class="check-indicator"><i class="fas fa-check-circle"></i></div>
                                    <div class="metodo-content">
                                        <img src="https://upload.wikimedia.org/wikipedia/commons/0/04/Mastercard-logo.png" class="icono-metodo">
                                        <span>Tarjeta Crédito</span>
                                    </div>
                                </label>
                            </div>

                            <div id="extraCampos"></div>

                            <button type="button" class="pos-btn-venta" onclick="confirmarVenta()" id="btnConfirmar">
                                <i class="fas fa-check-circle me-2"></i> Confirmar venta
                            </button>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<audio id="sonidoCaja" preload="auto"><source src="https://assets.mixkit.co/sfx/preview/mixkit-cash-register-purchase-2759.mp3" type="audio/mpeg"></audio>

<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>

<script>
let carrito = [];

document.addEventListener('DOMContentLoaded', function() {
    cargarCarrito();
    mostrarCamposPago();
    document.getElementById('codigo').focus();
});

async function agregarProducto() {
    const codigo = document.getElementById('codigo').value.trim();
    if (!codigo) { Swal.fire({ icon: 'warning', title: 'Atención', text: 'Ingresa o escanea un código de producto.', toast: true, position: 'top-end', showConfirmButton: false, timer: 2000 }); return; }

    try {
        const res = await fetch(`buscar_producto.php?codigo=${encodeURIComponent(codigo)}`);
        const data = await res.json();
        
        if (!data.success) {
            Swal.fire({ icon: 'error', title: 'Producto no encontrado', text: data.message, toast: true, position: 'top-end', showConfirmButton: false, timer: 2000 });
            document.getElementById('codigo').value = '';
            document.getElementById('codigo').focus();
            return;
        }

        const producto = {
            id: data.id,
            nombre: data.nombre,
            precio: parseFloat(data.precio_venta),
            cantidad: 1,
            stock: parseInt(data.stock),
            imagen: data.imagen || '',
            inicial: data.inicial || data.nombre.charAt(0).toUpperCase()
        };

        const existente = carrito.find(p => p.id === producto.id);
        
        if (existente) {
            if (existente.cantidad < producto.stock) {
                existente.cantidad++;
                Swal.fire({ icon: 'success', title: 'Producto agregado', text: `${producto.nombre} x${existente.cantidad}`, toast: true, position: 'top-end', showConfirmButton: false, timer: 1500 });
            } else {
                Swal.fire({ icon: 'warning', title: 'Stock insuficiente', text: `Solo hay ${producto.stock} unidades disponibles.`, toast: true, position: 'top-end', showConfirmButton: false, timer: 2000 });
                document.getElementById('codigo').value = '';
                document.getElementById('codigo').focus();
                return;
            }
        } else {
            carrito.push(producto);
            Swal.fire({ icon: 'success', title: 'Producto agregado', text: `${producto.nombre} agregado al carrito`, toast: true, position: 'top-end', showConfirmButton: false, timer: 1500 });
        }

        document.getElementById('codigo').value = '';
        guardarCarrito();
        renderCarrito();
        document.getElementById('codigo').focus();
    } catch (error) {
        Swal.fire({ icon: 'error', title: 'Error', text: 'Error al buscar el producto.', confirmButtonColor: '#f97316' });
    }
}

function renderCarrito() {
    const body = document.getElementById('carritoBody');
    if (carrito.length === 0) { body.innerHTML = '<tr id="emptyCartRow"><td colspan="6" class="text-center py-5"><i class="fas fa-shopping-cart fa-3x text-muted mb-3"></i><p class="text-muted mb-0">El carrito está vacío. Agrega productos para comenzar.</p></td>'; document.getElementById('total').value = '0.00'; document.getElementById('cambio').value = '0.00'; return; }
    
    let html = '', total = 0, contador = 1;
    carrito.forEach((item, index) => {
        const subtotal = item.precio * item.cantidad;
        total += subtotal;
        const imagenHtml = item.imagen && item.imagen !== '' ? `<img src="${item.imagen}" class="producto-imagen" onerror="this.style.display='none'; this.parentElement.innerHTML='<div class=\'producto-imagen-fallback\'>${item.inicial}</div>'">` : `<div class="producto-imagen-fallback">${item.inicial}</div>`;
        html += `<tr class="producto-animado"><td class="fw-bold">${contador++}</td><td style="text-align: left;"><div style="display: flex; align-items: center; gap: 12px;">${imagenHtml}<div><strong>${escapeHtml(item.nombre)}</strong><br><small>Stock: ${item.stock}</small></div></div></td><td><input type="number" class="cantidad-input" value="${item.cantidad}" min="1" max="${item.stock}" onchange="actualizarCantidad(${index}, this.value)" onfocus="this.select()"></td><td><strong class="text-primary">$${item.precio.toFixed(2)}</strong></td><td><strong class="text-success">$${subtotal.toFixed(2)}</strong></td><td><button type="button" class="btn-eliminar" onclick="eliminarProducto(${index})"><i class="fas fa-trash-alt"></i></button></td></tr>`;
    });
    body.innerHTML = html;
    document.getElementById('total').value = total.toFixed(2);
    document.getElementById('total').classList.add('total-flash');
    setTimeout(() => document.getElementById('total').classList.remove('total-flash'), 500);
    calcularCambio();
}

function guardarCarrito() { localStorage.setItem('carrito', JSON.stringify(carrito)); }
function cargarCarrito() { const saved = localStorage.getItem('carrito'); if (saved) { try { carrito = JSON.parse(saved); renderCarrito(); } catch(e) { carrito = []; } } }
function actualizarCantidad(index, valor) { const cantidad = parseInt(valor); if (isNaN(cantidad) || cantidad < 1) { renderCarrito(); return; } if (cantidad > carrito[index].stock) { renderCarrito(); return; } carrito[index].cantidad = cantidad; guardarCarrito(); renderCarrito(); }
function eliminarProducto(index) { Swal.fire({ title: '¿Eliminar producto?', text: `¿Quitar ${carrito[index].nombre} del carrito?`, icon: 'question', showCancelButton: true, confirmButtonColor: '#f97316', cancelButtonColor: '#6c757d', confirmButtonText: 'Sí, eliminar', cancelButtonText: 'Cancelar' }).then(result => { if (result.isConfirmed) { carrito.splice(index, 1); guardarCarrito(); renderCarrito(); Swal.fire({ icon: 'success', title: 'Eliminado', text: 'Producto eliminado del carrito', toast: true, position: 'top-end', showConfirmButton: false, timer: 1500 }); } }); }

function calcularCambio() { const total = parseFloat(document.getElementById('total').value) || 0; const pago = parseFloat(document.getElementById('monto_pagado').value) || 0; const cambio = pago - total; const cambioInput = document.getElementById('cambio'); cambioInput.value = cambio.toFixed(2); cambioInput.style.color = cambio < 0 ? '#ef4444' : '#16a34a'; cambioInput.style.fontWeight = 'bold'; }

function seleccionarMetodo(elemento) { document.querySelectorAll('.metodo-radio').forEach(el => el.classList.remove('selected')); elemento.classList.add('selected'); elemento.querySelector('input[type="radio"]').checked = true; mostrarCamposPago(); }

function mostrarCamposPago() {
    const metodo = document.querySelector('input[name="metodo_pago"]:checked').value;
    const extra = document.getElementById('extraCampos');
    let html = '';
    switch(metodo) {
        case 'efectivo':
            html = `<div class="alert alert-success mb-0" style="background: #f0fdf4; border: 1px solid #bbf7d0; border-radius: 16px;"><i class="fas fa-money-bill-wave me-2"></i><strong>Pago en efectivo</strong><p class="mb-0 mt-2 small text-muted">No se requiere referencia.</p></div>`;
            break;
        case 'transferencia':
            html = `
                <div class="form-group mb-3">
                    <label><i class="fas fa-hashtag me-1"></i> Folio de transferencia *</label>
                    <input type="text" class="form-control pos-input" name="referencia_pago" id="folio_transferencia" required placeholder="Ej: TRX87439210" maxlength="20" oninput="formatearFolioTransferencia(this)">
                    <small class="text-muted">Máximo 20 caracteres</small>
                </div>
                <div class="text-end">
                    <button type="button" class="btn-ver-datos" onclick="mostrarDatosBancarios()">
                        <i class="fas fa-university"></i> Ver datos bancarios
                    </button>
                </div>
            `;
            break;
        case 'tarjeta_debito':
        case 'tarjeta_credito':
            html = `
                <div class="campos-tarjeta">
                    <div class="form-group">
                        <label><i class="fas fa-credit-card me-1"></i> Últimos 4 dígitos *</label>
                        <input type="text" class="form-control pos-input" id="ultimos4" name="ultimos4" maxlength="4" required placeholder="Ej: 4921" oninput="this.value = this.value.replace(/\\D/g,''); detectarTipoTarjeta();">
                    </div>
                    <div class="form-group">
                        <label><i class="fas fa-tag me-1"></i> Tipo de tarjeta</label>
                        <select class="form-control pos-input" id="tipo_tarjeta" name="tipo_tarjeta" disabled>
                            <option value="">Detectando...</option>
                            <option value="VISA">VISA</option>
                            <option value="MASTERCARD">MASTERCARD</option>
                            <option value="AMEX">AMEX</option>
                            <option value="OTRA">OTRA</option>
                        </select>
                        <input type="hidden" name="tipo_tarjeta_detectada" id="tipo_tarjeta_detectada">
                    </div>
                    <div class="form-group">
                        <label><i class="fas fa-check-circle me-1"></i> Folio de autorización *</label>
                        <input type="text" class="form-control pos-input" name="folio_autorizacion" id="folio_autorizacion" required maxlength="16" placeholder="Ej: AUTH-938492" oninput="validarFolio(this)">
                        <small class="text-muted">Hasta 16 caracteres</small>
                    </div>
                </div>
            `;
            break;
    }
    extra.innerHTML = html;
    if (metodo === 'efectivo') document.getElementById('monto_pagado').focus();
}

function formatearFolioTransferencia(input) { let valor = input.value.toUpperCase().replace(/[^A-Z0-9]/g, ""); input.value = valor.substring(0, 20); }
function validarFolio(input) { let valor = input.value.toUpperCase().replace(/[^A-Z0-9]/g, ""); input.value = valor.substring(0, 16); }
function detectarTipoTarjeta() {
    const ultimos4 = document.getElementById('ultimos4').value;
    const tipoSelect = document.getElementById('tipo_tarjeta');
    const oculto = document.getElementById('tipo_tarjeta_detectada');
    if (ultimos4.length < 1) { tipoSelect.value = ''; oculto.value = ''; return; }
    let tipo = "OTRA";
    if (/^4/.test(ultimos4)) tipo = "VISA";
    else if (/^(5[1-5]|22[2-9]|2[3-7])/.test(ultimos4)) tipo = "MASTERCARD";
    else if (/^(34|37)/.test(ultimos4)) tipo = "AMEX";
    tipoSelect.value = tipo;
    oculto.value = tipo;
}

function confirmarVenta() {
    if (carrito.length === 0) { Swal.fire({ icon: 'warning', title: 'Carrito vacío', text: 'Agrega productos antes de registrar la venta.', confirmButtonColor: '#f97316' }); return; }
    const total = parseFloat(document.getElementById('total').value); const pago = parseFloat(document.getElementById('monto_pagado').value);
    if (!pago || pago <= 0) { Swal.fire({ icon: 'warning', title: 'Monto inválido', text: 'Ingresa un monto pagado válido.', confirmButtonColor: '#f97316' }); document.getElementById('monto_pagado').focus(); return; }
    if (pago < total) { Swal.fire({ icon: 'error', title: 'Monto insuficiente', text: `El pago ($${pago.toFixed(2)}) no cubre el total ($${total.toFixed(2)}).`, confirmButtonColor: '#f97316' }); document.getElementById('monto_pagado').focus(); return; }
    
    const metodo = document.querySelector('input[name="metodo_pago"]:checked').value;
    if (metodo === 'transferencia') { const folio = document.getElementById('folio_transferencia')?.value; if (!folio || folio.length < 5) { Swal.fire({ icon: 'warning', title: 'Folio requerido', text: 'Ingresa el folio de la transferencia.', confirmButtonColor: '#f97316' }); return; } }
    if (metodo === 'tarjeta_debito' || metodo === 'tarjeta_credito') { const ultimos4 = document.getElementById('ultimos4')?.value; const auth = document.getElementById('folio_autorizacion')?.value; if (!ultimos4 || ultimos4.length !== 4) { Swal.fire({ icon: 'warning', title: 'Datos incompletos', text: 'Ingresa los últimos 4 dígitos.', confirmButtonColor: '#f97316' }); return; } if (!auth || auth.length < 4) { Swal.fire({ icon: 'warning', title: 'Datos incompletos', text: 'Ingresa el folio de autorización.', confirmButtonColor: '#f97316' }); return; } }
    
    let resumen = carrito.map(p => `${p.nombre} x${p.cantidad} - $${(p.precio * p.cantidad).toFixed(2)}`).join('<br>');
    Swal.fire({ title: 'Confirmar venta', html: `<div style="max-height: 300px; overflow-y: auto;">${resumen}</div><hr><strong>Total:</strong> $${total.toFixed(2)}<br><strong>Pago:</strong> $${pago.toFixed(2)}<br><strong>Cambio:</strong> $${(pago - total).toFixed(2)}`, icon: 'question', showCancelButton: true, confirmButtonText: '<i class="fas fa-check me-2"></i>Registrar', cancelButtonText: 'Cancelar', confirmButtonColor: '#f97316', cancelButtonColor: '#6c757d' }).then(result => { if (result.isConfirmed) { document.getElementById('sonidoCaja').play().catch(e => console.log('Error:', e)); document.getElementById('carrito_json').value = JSON.stringify(carrito); document.getElementById('ventaForm').submit(); } });
}

document.getElementById('codigo').addEventListener('keydown', function(e) { if (e.key === 'Enter') { e.preventDefault(); agregarProducto(); } });
document.getElementById('monto_pagado').addEventListener('input', calcularCambio);

function escapeHtml(text) { const div = document.createElement('div'); div.textContent = text; return div.innerHTML; }

function mostrarDatosBancarios() {
    const datosQR = `BBVA\nTITULAR: KARMINA ARANGUTHY GARCÍA\nCUENTA: 1234 5678 9012 3456\nCLABE: 01218000123456789`;
    const qrURL = `https://api.qrserver.com/v1/create-qr-code/?size=200x200&data=${encodeURIComponent(datosQR)}`;
    Swal.fire({
        title: '<strong class="text-primary">Datos Bancarios BBVA</strong>',
        width: 700,
        showConfirmButton: true,
        confirmButtonText: 'Cerrar',
        confirmButtonColor: '#f97316',
        showCloseButton: true,
        html: `
            <div style="display: flex; flex-wrap: wrap; gap: 25px; align-items: center; justify-content: center; padding: 15px;">
                <div class="tarjeta-bbva-modal" style="flex: 1; min-width: 260px;">
                    <div class="header">
                        <span class="banco">BBVA</span>
                        <span class="tipo">DÉBITO</span>
                    </div>
                    <div class="chip"></div>
                    <div class="numero">1234 5678 9012 3456</div>
                    <div class="footer">
                        <div class="titular">
                            TITULAR
                            <span>KARMINA ARANGUTHY GARCÍA</span>
                        </div>
                        <div class="logo">VISA</div>
                    </div>
                </div>
                <div style="text-align: center;">
                    <img src="${qrURL}" style="width: 150px; border-radius: 15px; box-shadow: 0 5px 15px rgba(0,0,0,0.1);">
                    <p class="mt-2 text-muted small">Escanea para transferir</p>
                    <div class="mt-3 text-start" style="background: #f8fafc; padding: 12px; border-radius: 12px;">
                        <div class="small text-primary fw-bold">CLABE:</div>
                        <div class="fw-mono" style="font-family: monospace;">012 180 00123456789</div>
                        <div class="small text-primary fw-bold mt-2">CUENTA:</div>
                        <div class="fw-mono" style="font-family: monospace;">1234 5678 9012 3456</div>
                    </div>
                </div>
            </div>
        `
    });
}

<?php if(isset($alerta)): ?>
document.addEventListener('DOMContentLoaded', function() { Swal.fire({ icon: '<?= $alerta['tipo'] ?>', title: '<?= $alerta['titulo'] ?>', html: '<?= str_replace("\n", '<br>', $alerta['mensaje']) ?>', confirmButtonColor: '#f97316', confirmButtonText: 'Aceptar' }).then(() => { <?php if($alerta['tipo'] === 'success'): ?> localStorage.removeItem('carrito'); carrito = []; renderCarrito(); document.getElementById('monto_pagado').value = ''; document.getElementById('correo_cliente').value = ''; document.getElementById('codigo').focus(); <?php endif; ?> }); });
<?php endif; ?>
</script>

<?php include('includes/footer.php'); ?>