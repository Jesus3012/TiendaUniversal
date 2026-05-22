<?php
date_default_timezone_set('America/Mexico_City');

include('includes/db.php');
include('includes/session.php');
require_once('includes/csrf.php');

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

if ($_SESSION['rol'] !== 'administrador' && $_SESSION['rol'] !== 'vendedor') {
    header("Location: index.php");
    exit;
}

// Inicializar carrito en sesión si no existe
if (!isset($_SESSION['carrito'])) {
    $_SESSION['carrito'] = [];
}

// Verificar si hay una alerta guardada en sesión
$alerta = null;
if (isset($_SESSION['alerta'])) {
    $alerta = $_SESSION['alerta'];
    unset($_SESSION['alerta']);
}
$venta_exitosa = false;

// Verificar si viene de una venta exitosa para limpiar
if (isset($_GET['venta_exitosa'])) {
    $venta_exitosa = true;
    $_SESSION['carrito'] = [];
}

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
        $_SESSION['alerta'] = ['tipo' => 'error', 'titulo' => 'Carrito vacío', 'mensaje' => 'Agrega al menos un producto antes de registrar la venta.'];
        header("Location: " . $_SERVER['PHP_SELF']);
        exit;
    } else {
        $total = 0;
        foreach ($carrito as $item) $total += $item['precio'] * $item['cantidad'];
        $cambio = $monto_pagado - $total;

        if ($cambio < 0) {
            $_SESSION['alerta'] = ['tipo' => 'error', 'titulo' => 'Monto insuficiente', 'mensaje' => 'El monto pagado no cubre el total.'];
            header("Location: " . $_SERVER['PHP_SELF']);
            exit;
        } else {
            $errores = [];
            foreach ($carrito as $item) {
                $result = $conn->query("SELECT cantidad FROM productos WHERE id={$item['id']}");
                if ($result && $result->num_rows > 0) {
                    $producto = $result->fetch_assoc();
                    if ($producto['cantidad'] < $item['cantidad']) {
                        $errores[] = $item['nombre'];
                    }
                }
            }

            if (!empty($errores)) {
                $_SESSION['alerta'] = ['tipo' => 'error', 'titulo' => 'Sin stock', 'mensaje' => 'Stock insuficiente para: ' . implode(', ', $errores)];
                header("Location: " . $_SERVER['PHP_SELF']);
                exit;
            } else {
                $conn->begin_transaction();
                
                try {
                    $folioQuery = $conn->query("
                        SELECT folio_ticket FROM ventas 
                        WHERE folio_ticket LIKE 'Venta_codigo_%' 
                        ORDER BY id DESC LIMIT 1
                    ");

                    $ultimoNumero = 0;
                    if ($folioQuery && $folioQuery->num_rows > 0) {
                        $ultimoFolio = $folioQuery->fetch_assoc();
                        preg_match('/Venta_codigo_(\d+)/', $ultimoFolio['folio_ticket'], $matches);
                        if (isset($matches[1])) {
                            $ultimoNumero = intval($matches[1]);
                        }
                    }

                    $nuevoNumero = $ultimoNumero + 1;
                    $folio = 'Venta_codigo_' . $nuevoNumero;
                    
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

                    $sqlTienda = "SELECT nombre, telefono, email, direccion, logo FROM configuracion_galeria WHERE id = 1";
                    $resultTienda = $conn->query($sqlTienda);
                    $tienda = $resultTienda->fetch_assoc();
                    $tienda_nombre = $tienda['nombre'] ?? 'Tienda Pescadores';
                    $tienda_telefono = $tienda['telefono'] ?? '';
                    $tienda_email = $tienda['email'] ?? '';
                    $tienda_direccion = $tienda['direccion'] ?? '';
                    $tienda_logo = $tienda['logo'] ?? '';

                    $pdf = new FPDF('P', 'mm', array(80, 140 + count($carrito) * 8));
                    $pdf->AddPage();
                    $pdf->SetMargins(5, 5, 5);
                    $pdf->SetAutoPageBreak(true, 10);

                    if (!empty($tienda_logo) && file_exists($tienda_logo)) {
                        $pdf->Image($tienda_logo, 25, 5, 30);
                        $pdf->Ln(28);
                    } else {
                        $pdf->Ln(5);
                    }

                    $pdf->SetFont('Arial', 'B', 11);
                    $pdf->Cell(0, 5, utf8_decode(strtoupper($tienda_nombre)), 0, 1, 'C');
                    $pdf->Ln(2);

                    $pdf->SetFont('Arial', '', 7);
                    if (!empty($tienda_direccion)) {
                        $direccion_corta = strlen($tienda_direccion) > 35 ? substr($tienda_direccion, 0, 33) . '..' : $tienda_direccion;
                        $pdf->Cell(0, 3, utf8_decode($direccion_corta), 0, 1, 'C');
                    }
                    if (!empty($tienda_telefono)) {
                        $pdf->Cell(0, 3, "Tel: $tienda_telefono", 0, 1, 'C');
                    }
                    if (!empty($tienda_email)) {
                        $pdf->Cell(0, 3, utf8_decode($tienda_email), 0, 1, 'C');
                    }
                    $pdf->Ln(3);

                    $pdf->Line(5, $pdf->GetY(), 75, $pdf->GetY());
                    $pdf->Ln(3);

                    $pdf->SetFont('Arial', 'B', 8);
                    $pdf->Cell(0, 4, "TICKET DE COMPRA", 0, 1, 'C');
                    $pdf->SetFont('Arial', '', 7);
                    $pdf->Cell(0, 3, "Folio: $folio", 0, 1, 'C');
                    $pdf->Cell(0, 3, "Fecha: " . date('d/m/Y H:i:s'), 0, 1, 'C');
                    $pdf->Cell(0, 3, "Atendido por: " . ($_SESSION['nombre'] ?? 'Vendedor'), 0, 1, 'C');
                    $pdf->Ln(2);

                    $pdf->Line(5, $pdf->GetY(), 75, $pdf->GetY());
                    $pdf->Ln(2);

                    $pdf->SetFont('Arial', 'B', 7);
                    $pdf->Cell(38, 4, 'Producto', 0, 0, 'L');
                    $pdf->Cell(10, 4, 'Cant', 0, 0, 'C');
                    $pdf->Cell(10, 4, 'Precio', 0, 0, 'C');
                    $pdf->Cell(12, 4, 'Importe', 0, 1, 'R');

                    $pdf->Line(5, $pdf->GetY(), 75, $pdf->GetY());
                    $pdf->Ln(1);

                    $pdf->SetFont('Arial', '', 7);
                    foreach ($carrito as $p) {
                        $nombreProducto = utf8_decode($p['nombre']);
                        if (strlen($nombreProducto) > 18) {
                            $nombreProducto = substr($nombreProducto, 0, 16) . '..';
                        }
                        $importe = $p['precio'] * $p['cantidad'];
                        
                        $pdf->Cell(38, 4, $nombreProducto, 0, 0, 'L');
                        $pdf->Cell(10, 4, $p['cantidad'], 0, 0, 'C');
                        $pdf->Cell(10, 4, '$' . number_format($p['precio'], 2), 0, 0, 'C');
                        $pdf->Cell(12, 4, '$' . number_format($importe, 2), 0, 1, 'R');
                    }

                    $pdf->Ln(2);
                    $pdf->Line(5, $pdf->GetY(), 75, $pdf->GetY());
                    $pdf->Ln(2);

                    $pdf->SetFont('Arial', 'B', 9);
                    $pdf->Cell(48, 5, 'TOTAL:', 0, 0, 'R');
                    $pdf->Cell(22, 5, '$' . number_format($total, 2), 0, 1, 'R');

                    $pdf->Ln(2);
                    $pdf->SetFont('Arial', '', 7);
                    $metodo_texto = [
                        'efectivo' => 'Efectivo',
                        'transferencia' => 'Transferencia',
                        'tarjeta_debito' => 'Tarjeta Débito',
                        'tarjeta_credito' => 'Tarjeta Crédito'
                    ];
                    $pdf->Cell(0, 3, 'Pago: ' . ($metodo_texto[$metodo_pago] ?? $metodo_pago), 0, 1, 'L');

                    if ($referencia_pago) {
                        $ref_corta = strlen($referencia_pago) > 30 ? substr($referencia_pago, 0, 28) . '..' : $referencia_pago;
                        $pdf->Cell(0, 3, 'Ref: ' . utf8_decode($ref_corta), 0, 1, 'L');
                    }

                    if ($cambio > 0) {
                        $pdf->Cell(0, 3, 'Cambio: $' . number_format($cambio, 2), 0, 1, 'L');
                    }

                    $pdf->Ln(3);
                    $pdf->Line(5, $pdf->GetY(), 75, $pdf->GetY());
                    $pdf->Ln(2);

                    $pdf->SetFont('Arial', 'B', 8);
                    $pdf->Cell(0, 4, utf8_decode("Gracias por su compra"), 0, 1, 'C');
                    $pdf->SetFont('Arial', '', 6);
                    $pdf->Cell(0, 3, utf8_decode("Conserve este ticket para aclaraciones"), 0, 1, 'C');

                    $nombreArchivo = 'ticket_' . $folio . '.pdf';
                    $rutaArchivo = 'tickets/' . $nombreArchivo;
                    $pdf->Output('F', $rutaArchivo);
                                        
                    $ticketEnviado = false;
                    if (!empty($correo_cliente) && filter_var($correo_cliente, FILTER_VALIDATE_EMAIL)) {
                        require_once('includes/configuracion_correo.php');
                        $ticketEnviado = enviarCorreoTicket($conn, $correo_cliente, $rutaArchivo, $folio);
                    }
                    
                    $conn->commit();
                    
                    $_SESSION['carrito'] = [];
                    
                    $mensaje = "Venta registrada correctamente.\nCambio: $" . number_format($cambio, 2);
                    if ($ticketEnviado) $mensaje .= "\nTicket enviado a $correo_cliente";
                    
                    $_SESSION['alerta'] = [
                        'tipo' => 'success', 
                        'titulo' => 'Venta exitosa', 
                        'mensaje' => $mensaje,
                        'folio' => $folio
                    ];
                    
                    header("Location: " . strtok($_SERVER["REQUEST_URI"], '?') . "?venta_exitosa=1");
                    exit;
                    
                } catch (Exception $e) {
                    $conn->rollback();
                    $_SESSION['alerta'] = ['tipo' => 'error', 'titulo' => 'Error', 'mensaje' => 'Error al registrar la venta: ' . $e->getMessage()];
                    header("Location: " . $_SERVER['PHP_SELF']);
                    exit;
                }
            }
        }
    }
}

include('includes/header.php');
include('includes/navbar.php');

$carrito_json = json_encode($_SESSION['carrito']);
$rol_usuario = $_SESSION['rol'] ?? 'vendedor';

// Obtener productos para selección visual
$productos_query = "SELECT id, nombre, precio_venta, cantidad as stock, imagen, categoria 
                    FROM productos 
                    WHERE cantidad > 0 
                    ORDER BY nombre ASC";
$productos_result = $conn->query($productos_query);
$productos = [];
if ($productos_result) {
    while ($row = $productos_result->fetch_assoc()) {
        $productos[] = $row;
    }
}
?>

<link rel="stylesheet" href="css/venta-codigo.css">

<style>
/* ============================================
   POS RESPONSIVE CORREGIDO
   - Métodos de pago en una sola línea en PC
   - Ambas columnas con misma altura visual
   - Grid derecha con más productos visibles
   - Optimizado para zoom 100% y móvil
   ============================================ */

html { scroll-behavior: auto !important; }

.content-wrapper .container-fluid {
    max-width: 1600px;
    margin: 0 auto;
}

/* Layout principal */
.content-wrapper .row {
    display: flex;
    align-items: stretch;
}

.content-wrapper .row > .col-lg-6 {
    display: flex;
    margin-bottom: 20px;
}

.pos-card {
    width: 100%;
    display: flex;
    flex-direction: column;
    border: 0;
    border-radius: 18px;
    box-shadow: 0 8px 24px rgba(15, 23, 42, 0.08);
    overflow: hidden;
}

.pos-card .card-body {
    flex: 1;
    display: flex;
    flex-direction: column;
}

.pos-header {
    min-height: 58px;
    display: flex;
    align-items: center;
    background: linear-gradient(135deg, #f97316, #ea580c) !important;
    color: #fff !important;
    border: 0 !important;
}

.pos-header .card-title {
    font-size: 1.05rem;
    font-weight: 700;
}

/* Buscador superior */
.pos-buscador .input-group {
    gap: 8px;
    flex-wrap: nowrap;
}

.pos-buscador .input-group-text,
.pos-buscador .form-control,
.pos-buscador .btn-agregar {
    min-height: 44px;
}

.pos-buscador .btn-agregar {
    white-space: nowrap;
}

/* Tabla del carrito con altura controlada */
.pos-tabla {
    flex: 1;
    min-height: 260px;
    max-height: 410px;
    overflow: auto;
    border: 1px solid #eef2f7;
    border-radius: 14px;
}

.pos-tabla table {
    min-width: 690px;
}

.pos-tabla thead th {
    position: sticky;
    top: 0;
    z-index: 2;
    background: #f8fafc;
    color: #334155;
    font-size: 12px;
    text-transform: uppercase;
    letter-spacing: .03em;
}

.producto-icono {
    width: 46px;
    height: 46px;
    min-width: 46px;
    background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
    border-radius: 12px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 22px;
    color: #f97316;
}

.producto-imagen {
    width: 46px;
    height: 46px;
    min-width: 46px;
    object-fit: cover;
    border-radius: 12px;
}

.producto-icono.icon-primary { color: #007bff; background: linear-gradient(135deg, #e3f2fd, #bbdefb); }
.producto-icono.icon-info { color: #17a2b8; background: linear-gradient(135deg, #e0f7fa, #b2ebf2); }
.producto-icono.icon-warning { color: #ffc107; background: linear-gradient(135deg, #fff3e0, #ffe0b2); }
.producto-icono.icon-success { color: #28a745; background: linear-gradient(135deg, #e8f5e9, #c8e6c9); }
.producto-icono.icon-danger { color: #dc3545; background: linear-gradient(135deg, #ffebee, #ffcdd2); }
.producto-icono.icon-gray { color: #6c757d; background: linear-gradient(135deg, #f8f9fa, #e9ecef); }

/* Totales */
.pos-totales .form-group { margin-bottom: 0; }
.pos-totales label,
.pos-correo label {
    font-size: 13px;
    font-weight: 700;
    color: #334155;
}

.pos-total,
.pos-input {
    min-height: 42px;
    border-radius: 12px !important;
}

/* Métodos de pago: una sola línea en PC */
.metodos-container {
    display: grid !important;
    grid-template-columns: repeat(4, minmax(0, 1fr));
    gap: 10px;
    margin: 14px 0 16px;
    width: 100%;
}

.metodo-radio {
    position: relative;
    cursor: pointer;
    scroll-margin-top: 0 !important;
    min-width: 0;
    margin: 0 !important;
}

.metodo-radio input[type="radio"] {
    position: absolute;
    opacity: 0;
    pointer-events: none;
}

.metodo-radio .metodo-content {
    min-height: 86px;
    padding: 12px 8px;
    border: 2px solid #e5e7eb;
    border-radius: 16px;
    background: #fff;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    gap: 7px;
    text-align: center;
    transition: .18s ease;
}

.metodo-radio:hover .metodo-content {
    transform: translateY(-2px);
    border-color: #fdba74;
    box-shadow: 0 8px 18px rgba(249, 115, 22, .12);
}

.metodo-radio.selected .metodo-content,
.metodo-radio input[type="radio"]:checked + .check-indicator + .metodo-content {
    border-color: #f97316;
    background: #fff7ed;
    box-shadow: 0 8px 18px rgba(249, 115, 22, .16);
}

.metodo-radio .check-indicator {
    position: absolute;
    top: 7px;
    right: 8px;
    color: #f97316;
    opacity: 0;
    z-index: 3;
    transition: .18s ease;
}

.metodo-radio.selected .check-indicator,
.metodo-radio input[type="radio"]:checked + .check-indicator {
    opacity: 1;
}

.icono-metodo {
    width: 34px;
    height: 34px;
    object-fit: contain;
}

.metodo-content span {
    width: 100%;
    display: block;
    font-size: 12px;
    line-height: 1.15;
    font-weight: 800;
    color: #1f2937;
    white-space: normal;
}

#extraCampos {
    margin-bottom: 16px;
}

.campos-tarjeta {
    display: grid;
    grid-template-columns: repeat(3, minmax(0, 1fr));
    gap: 12px;
}

.pos-btn-venta {
    width: 100%;
    min-height: 52px;
    border-radius: 16px;
    border: 0;
    background: linear-gradient(135deg, #16a34a, #15803d);
    color: #fff;
    font-weight: 800;
    font-size: 1rem;
    margin-top: auto;
}

/* Buscador de productos */
.buscador-wrapper {
    display: grid;
    grid-template-columns: 1fr 190px;
    gap: 10px;
    margin-bottom: 14px;
}

.buscador-wrapper input,
.buscador-wrapper select {
    width: 100%;
    min-height: 42px;
    padding: 8px 12px;
    border: 1px solid #e5e7eb;
    border-radius: 12px;
    background: #fff;
    font-size: 13px;
}

/* Grid derecha: más productos visibles */
.productos-grid {
    flex: 1;
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(118px, 1fr));
    align-content: start;
    gap: 10px;
    height: 100%;
    min-height: 610px;
    max-height: 760px;
    overflow-y: auto;
    padding: 4px 6px 4px 2px;
}

.producto-card {
    min-width: 0;
    background: #fff;
    border: 1px solid #e5e7eb;
    border-radius: 14px;
    padding: 10px 8px;
    text-align: center;
    cursor: pointer;
    transition: all 0.18s ease;
    display: flex;
    flex-direction: column;
    align-items: center;
}

.producto-card:hover {
    transform: translateY(-2px);
    box-shadow: 0 8px 18px rgba(15, 23, 42, .10);
    border-color: #f97316;
}

.producto-imagen-card {
    width: 58px;
    height: 58px;
    min-height: 58px;
    margin: 0 auto 7px;
    border-radius: 12px;
    display: flex;
    align-items: center;
    justify-content: center;
    background: #f8fafc;
    overflow: hidden;
}

.producto-imagen-card img {
    width: 100%;
    height: 100%;
    object-fit: cover;
}

.producto-icono-card {
    font-size: 28px;
    color: #f97316;
}

.producto-nombre-card {
    width: 100%;
    min-height: 32px;
    font-size: 11.5px;
    font-weight: 800;
    color: #1e293b;
    line-height: 1.25;
    margin-bottom: 4px;
    display: -webkit-box;
    -webkit-line-clamp: 2;
    -webkit-box-orient: vertical;
    overflow: hidden;
}

.producto-precio-card {
    font-size: 13px;
    font-weight: 900;
    color: #f97316;
}

.producto-stock-card {
    font-size: 10px;
    color: #64748b;
    margin-bottom: 7px;
}

.btn-agregar-card {
    width: 100%;
    margin-top: auto;
    background: #f97316;
    color: white;
    border: none;
    border-radius: 10px;
    padding: 6px 5px;
    font-size: 11px;
    font-weight: 700;
    cursor: pointer;
    transition: background 0.2s;
}

.btn-agregar-card:hover { background: #ea580c; }

.cantidad-input {
    width: 72px;
    min-height: 34px;
    text-align: center;
    border: 1px solid #d1d5db;
    border-radius: 10px;
}

.btn-eliminar {
    width: 34px;
    height: 34px;
    border-radius: 10px;
    border: 0;
    background: #fee2e2;
    color: #dc2626;
}

/* Tablets */
@media (max-width: 1199px) {
    .metodos-container { gap: 8px; }
    .metodo-radio .metodo-content { min-height: 82px; padding: 10px 6px; }
    .icono-metodo { width: 30px; height: 30px; }
    .metodo-content span { font-size: 11px; }
    .productos-grid { grid-template-columns: repeat(auto-fill, minmax(108px, 1fr)); min-height: 560px; }
}

/* Móvil y tablet chica */
@media (max-width: 991.98px) {
    .content-wrapper .row { display: block; }
    .content-wrapper .row > .col-lg-6 { display: block; width: 100%; }
    .pos-card { min-height: auto; }
    .pos-card .card-body { display: block; padding: 16px !important; }
    .pos-tabla { max-height: 330px; min-height: 210px; }
    .productos-grid { min-height: auto; max-height: 520px; grid-template-columns: repeat(auto-fill, minmax(120px, 1fr)); }
}

@media (max-width: 767.98px) {
    .content-wrapper .container-fluid { padding-left: 10px; padding-right: 10px; }
    .custom-breadcrumb { display: none; }
    .pos-header { min-height: 50px; }
    .pos-header .card-title { font-size: .98rem; }

    .pos-buscador .input-group { flex-wrap: wrap; }
    .pos-buscador .input-group-text { display: none; }
    .pos-buscador .form-control { flex: 1 1 100%; width: 100%; }
    .pos-buscador .btn-agregar { width: 100%; }

    .pos-totales .col-md-4 { margin-bottom: 8px; }

    .metodos-container {
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 9px;
    }

    .metodo-radio .metodo-content { min-height: 78px; }

    .campos-tarjeta { grid-template-columns: 1fr; gap: 8px; }

    .buscador-wrapper { grid-template-columns: 1fr; }

    .productos-grid {
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 9px;
        max-height: 520px;
    }

    .producto-card { padding: 10px 7px; }
    .producto-imagen-card { width: 54px; height: 54px; min-height: 54px; }
}

@media (max-width: 380px) {
    .productos-grid { grid-template-columns: 1fr 1fr; }
    .metodos-container { grid-template-columns: 1fr 1fr; }
    .metodo-content span { font-size: 10.5px; }
}
</style>

<div class="content-wrapper">
    <div class="container-fluid">
        
        <?php if ($rol_usuario === 'administrador'): ?>
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
                        <i class="fas fa-cart-shopping"></i> Punto de Venta
                    </li>
                </ol>
            </nav>
        </div>
        <?php endif; ?>

        <!-- DISEÑO DE DOS COLUMNAS - MISMO TAMAÑO -->
        <div class="row">
            <!-- COLUMNA IZQUIERDA: CARRITO -->
            <div class="col-lg-6">
                <div class="card pos-card">
                    <div class="card-header pos-header">
                        <h3 class="card-title mb-0">
                            <i class="fas fa-shopping-cart"></i>
                            Carrito de Ventas
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
                                           placeholder="Escanea o escribe el codigo del producto" 
                                           autocomplete="off" autofocus>
                                    <button type="button" class="btn btn-agregar" onclick="agregarProducto()">
                                        <i class="fas fa-plus-circle me-2"></i> Agregar
                                    </button>
                                </div>
                            </div>

                            <div class="table-responsive mb-4 pos-tabla">
                                <table class="table table-hover text-center mb-0">
                                    <thead>
                                        <tr>
                                            <th width="50">#</th>
                                            <th>Producto</th>
                                            <th width="130">Cantidad</th>
                                            <th width="120">Precio</th>
                                            <th width="120">Subtotal</th>
                                            <th width="80">Accion</th>
                                        </tr>
                                    </thead>
                                    <tbody id="carritoBody">
                                        <tr id="emptyCartRow">
                                            <td colspan="6" class="text-center py-5">
                                                <i class="fas fa-shopping-cart fa-3x text-muted mb-3"></i>
                                                <p class="text-muted mb-0">El carrito esta vacio. Agrega productos para comenzar.</p>
                                            </td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>

                            <div class="row g-3 mb-4 pos-totales">
                                <div class="col-md-4">
                                    <div class="form-group">
                                        <label><i class="fas fa-calculator me-1"></i> Total</label>
                                        <input type="text" class="form-control pos-total" id="total" value="0.00" readonly>
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="form-group">
                                        <label><i class="fas fa-money-bill me-1"></i> Monto pagado</label>
                                        <input type="number" class="form-control pos-input" name="monto_pagado" id="monto_pagado" step="0.01" min="0" placeholder="0.00" oninput="calcularCambio()">
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="form-group">
                                        <label><i class="fas fa-exchange-alt me-1"></i> Cambio</label>
                                        <input type="text" class="form-control pos-input" id="cambio" value="0.00" readonly>
                                    </div>
                                </div>
                            </div>

                            <div class="form-group mb-4 pos-correo">
                                <label><i class="fas fa-envelope me-2"></i> Correo del cliente</label>
                                <input type="email" class="form-control pos-input" name="correo_cliente" id="correo_cliente" placeholder="cliente@ejemplo.com">
                                <small class="text-muted mt-2 d-block"><i class="fas fa-info-circle me-1"></i> Opcional - enviaremos el ticket por correo</small>
                            </div>
                            
                            <!-- METODOS DE PAGO ORIGINALES -->
                            <div class="metodos-container">
                                <label class="metodo-radio">
                                    <input type="radio" name="metodo_pago" value="efectivo" checked>
                                    <div class="check-indicator"><i class="fas fa-check-circle"></i></div>
                                    <div class="metodo-content">
                                        <img src="https://cdn.jsdelivr.net/gh/twitter/twemoji@latest/assets/svg/1f4b5.svg" class="icono-metodo">
                                        <span>Efectivo</span>
                                    </div>
                                </label>

                                <label class="metodo-radio">
                                    <input type="radio" name="metodo_pago" value="transferencia">
                                    <div class="check-indicator"><i class="fas fa-check-circle"></i></div>
                                    <div class="metodo-content">
                                        <img src="https://cdn-icons-png.flaticon.com/512/2331/2331947.png" class="icono-metodo">
                                        <span>Transferencia</span>
                                    </div>
                                </label>

                                <label class="metodo-radio">
                                    <input type="radio" name="metodo_pago" value="tarjeta_debito">
                                    <div class="check-indicator"><i class="fas fa-check-circle"></i></div>
                                    <div class="metodo-content">
                                        <img src="https://brandeps.com/logo-download/V/Visa-logo-vector-01.svg" class="icono-metodo visa-logo" onerror="this.src='https://cdn-icons-png.flaticon.com/512/349/349221.png'">
                                        <span>Tarjeta Debito</span>
                                    </div>
                                </label>

                                <label class="metodo-radio">
                                    <input type="radio" name="metodo_pago" value="tarjeta_credito">
                                    <div class="check-indicator"><i class="fas fa-check-circle"></i></div>
                                    <div class="metodo-content">
                                        <img src="https://upload.wikimedia.org/wikipedia/commons/0/04/Mastercard-logo.png" class="icono-metodo">
                                        <span>Tarjeta Credito</span>
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

            <!-- COLUMNA DERECHA: PRODUCTOS (MISMO TAMAÑO) -->
            <div class="col-lg-6">
                <div class="card pos-card">
                    <div class="card-header pos-header">
                        <h3 class="card-title mb-0">
                            <i class="fas fa-box-open"></i>
                            Seleccionar Producto
                        </h3>
                    </div>
                    <div class="card-body p-4">
                        <!-- Buscador y filtros -->
                        <div class="buscador-wrapper">
                            <input type="text" id="buscadorProductos" placeholder="Buscar producto por nombre...">
                            <select id="filtroCategoriaProductos">
                                <option value="">Todas las categorias</option>
                                <?php
                                $cat_query = "SELECT DISTINCT categoria FROM productos WHERE categoria IS NOT NULL AND categoria != '' AND cantidad > 0 ORDER BY categoria";
                                $cat_result = $conn->query($cat_query);
                                if ($cat_result) {
                                    while($cat = $cat_result->fetch_assoc()) {
                                        echo '<option value="' . htmlspecialchars($cat['categoria']) . '">' . htmlspecialchars($cat['categoria']) . '</option>';
                                    }
                                }
                                ?>
                            </select>
                        </div>
                        
                        <!-- Grid de productos - MUESTRA MUCHOS PRODUCTOS -->
                        <div class="productos-grid" id="productosGrid">
                            <?php if(count($productos) > 0): ?>
                                <?php foreach($productos as $p): ?>
                                <div class="producto-card" 
                                     data-id="<?= $p['id'] ?>"
                                     data-nombre="<?= htmlspecialchars($p['nombre']) ?>"
                                     data-precio="<?= $p['precio_venta'] ?>"
                                     data-stock="<?= $p['stock'] ?>"
                                     data-imagen="<?= htmlspecialchars($p['imagen'] ?? '') ?>"
                                     data-categoria="<?= htmlspecialchars($p['categoria'] ?? '') ?>"
                                     onclick="agregarProductoCard(this)">
                                    <div class="producto-imagen-card">
                                        <?php if(!empty($p['imagen']) && $p['imagen'] != 'uploads/noimage.png' && file_exists($p['imagen'])): ?>
                                            <img src="<?= $p['imagen'] ?>" alt="<?= htmlspecialchars($p['nombre']) ?>">
                                        <?php else: ?>
                                            <i class="fas fa-box producto-icono-card"></i>
                                        <?php endif; ?>
                                    </div>
                                    <div class="producto-nombre-card" title="<?= htmlspecialchars($p['nombre']) ?>">
                                        <?= htmlspecialchars(mb_substr($p['nombre'], 0, 22)) ?>
                                    </div>
                                    <div class="producto-precio-card">$<?= number_format($p['precio_venta'], 2) ?></div>
                                    <div class="producto-stock-card">Stock: <?= $p['stock'] ?></div>
                                    <button class="btn-agregar-card" onclick="event.stopPropagation(); agregarProductoCard(this.parentElement)">
                                        <i class="fas fa-cart-plus me-1"></i> Agregar
                                    </button>
                                </div>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <div class="text-center py-5" style="grid-column: 1/-1;">
                                    <i class="fas fa-box-open fa-3x text-muted"></i>
                                    <p class="mt-3 text-muted">No hay productos disponibles</p>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<audio id="sonidoCaja" preload="auto">
    <source src="https://assets.mixkit.co/sfx/preview/mixkit-cash-register-purchase-2759.mp3" type="audio/mpeg">
</audio>

<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>

<script>
let carrito = <?php echo $carrito_json; ?>;
let ventaEnProceso = false;

// ============ FUNCIONES DE BUSQUEDA ============
function filtrarProductos() {
    const busqueda = document.getElementById('buscadorProductos').value.toLowerCase();
    const categoria = document.getElementById('filtroCategoriaProductos').value.toLowerCase();
    const productos = document.querySelectorAll('#productosGrid .producto-card');
    
    productos.forEach(p => {
        const nombre = p.dataset.nombre.toLowerCase();
        const cat = (p.dataset.categoria || '').toLowerCase();
        const matchBusqueda = nombre.includes(busqueda);
        const matchCategoria = !categoria || cat === categoria;
        p.style.display = matchBusqueda && matchCategoria ? '' : 'none';
    });
}

// ============ AGREGAR DESDE GRID ============
function agregarProductoCard(element) {
    const producto = {
        id: parseInt(element.dataset.id),
        nombre: element.dataset.nombre,
        precio: parseFloat(element.dataset.precio),
        stock: parseInt(element.dataset.stock),
        imagen: element.dataset.imagen,
        categoria: element.dataset.categoria
    };
    
    const iconoData = getIconoPorCategoria(producto.categoria, producto.nombre);
    
    const nuevoProducto = {
        id: producto.id,
        nombre: producto.nombre,
        precio: producto.precio,
        cantidad: 1,
        stock: producto.stock,
        imagen: producto.imagen,
        categoria: producto.categoria,
        icono: iconoData.icono,
        iconoColor: iconoData.color
    };

    const existente = carrito.find(p => p.id === nuevoProducto.id);
    
    if (existente) {
        const nuevaCantidad = existente.cantidad + 1;
        if (nuevaCantidad <= nuevoProducto.stock) {
            existente.cantidad++;
            Swal.fire({ 
                icon: 'success', 
                title: 'Producto agregado', 
                text: `${nuevoProducto.nombre} x${existente.cantidad}`, 
                toast: true, 
                position: 'top-end', 
                showConfirmButton: false, 
                timer: 1500 
            });
            guardarCarrito();
            renderCarrito();
        } else {
            Swal.fire({ 
                icon: 'warning', 
                title: 'Stock insuficiente', 
                text: `Solo hay ${nuevoProducto.stock} unidades disponibles.`, 
                toast: true, 
                position: 'top-end', 
                showConfirmButton: false, 
                timer: 2000 
            });
        }
    } else {
        if (nuevoProducto.cantidad <= nuevoProducto.stock) {
            carrito.push(nuevoProducto);
            Swal.fire({ 
                icon: 'success', 
                title: 'Producto agregado', 
                text: `${nuevoProducto.nombre} agregado al carrito`, 
                toast: true, 
                position: 'top-end', 
                showConfirmButton: false, 
                timer: 1500 
            });
            guardarCarrito();
            renderCarrito();
        } else {
            Swal.fire({ 
                icon: 'warning', 
                title: 'Sin stock', 
                text: `No hay stock disponible de ${nuevoProducto.nombre}.`, 
                toast: true, 
                position: 'top-end', 
                showConfirmButton: false, 
                timer: 2000 
            });
        }
    }
}

// ============ FUNCIONES ORIGINALES ============
function getIconoPorCategoria(categoria, nombre) {
    const texto = (categoria || nombre || '').toLowerCase();
    
    if (/(electronica|telefono|celular|smartphone|tablet|computadora|laptop|pc|monitor|teclado|mouse|audifonos)/.test(texto)) {
        return { icono: 'fas fa-microchip', color: 'icon-primary' };
    }
    if (/(ropa|camisa|pantalon|vestido|chaqueta|sueter|short|falda|jean|blusa)/.test(texto)) {
        return { icono: 'fas fa-tshirt', color: 'icon-info' };
    }
    if (/(calzado|zapato|tenis|sandalia|botas|zapatilla)/.test(texto)) {
        return { icono: 'fas fa-shoe-prints', color: 'icon-warning' };
    }
    if (/(alimento|comida|bebida|refresco|agua|snack|galleta|pan|leche|jugo)/.test(texto)) {
        return { icono: 'fas fa-utensils', color: 'icon-success' };
    }
    if (/(herramienta|martillo|destornillador|pinza|taladro|sierra|llave)/.test(texto)) {
        return { icono: 'fas fa-tools', color: 'icon-danger' };
    }
    
    return { icono: 'fas fa-box', color: 'icon-gray' };
}

async function agregarProducto() {
    const codigo = document.getElementById('codigo').value.trim();
    if (!codigo) { 
        Swal.fire({ icon: 'warning', title: 'Atencion', text: 'Ingresa o escanea un codigo de producto.', toast: true, position: 'top-end', showConfirmButton: false, timer: 2000 }); 
        return; 
    }

    try {
        const res = await fetch(`buscar_producto.php?codigo=${encodeURIComponent(codigo)}`);
        const data = await res.json();
        
        if (!data.success) {
            Swal.fire({ icon: 'error', title: 'Producto no encontrado', text: data.message, toast: true, position: 'top-end', showConfirmButton: false, timer: 2000 });
            document.getElementById('codigo').value = '';
            document.getElementById('codigo').focus();
            return;
        }

        const iconoData = getIconoPorCategoria(data.categoria, data.nombre);
        
        const producto = {
            id: data.id,
            nombre: data.nombre,
            precio: parseFloat(data.precio_venta),
            cantidad: 1,
            stock: parseInt(data.stock),
            imagen: data.imagen || '',
            categoria: data.categoria || '',
            icono: iconoData.icono,
            iconoColor: iconoData.color,
            inicial: data.inicial || data.nombre.charAt(0).toUpperCase()
        };

        const existente = carrito.find(p => p.id === producto.id);
        
        if (existente) {
            const nuevaCantidad = existente.cantidad + 1;
            if (nuevaCantidad <= producto.stock) {
                existente.cantidad++;
                Swal.fire({ icon: 'success', title: 'Producto agregado', text: `${producto.nombre} x${existente.cantidad}`, toast: true, position: 'top-end', showConfirmButton: false, timer: 1500 });
                guardarCarrito();
                renderCarrito();
            } else {
                Swal.fire({ icon: 'warning', title: 'Stock insuficiente', text: `Solo hay ${producto.stock} unidades disponibles.`, toast: true, position: 'top-end', showConfirmButton: false, timer: 2000 });
            }
        } else {
            if (producto.cantidad <= producto.stock) {
                carrito.push(producto);
                Swal.fire({ icon: 'success', title: 'Producto agregado', text: `${producto.nombre} agregado al carrito`, toast: true, position: 'top-end', showConfirmButton: false, timer: 1500 });
                guardarCarrito();
                renderCarrito();
            } else {
                Swal.fire({ icon: 'warning', title: 'Sin stock', text: `No hay stock disponible de ${producto.nombre}.`, toast: true, position: 'top-end', showConfirmButton: false, timer: 2000 });
            }
        }

        document.getElementById('codigo').value = '';
        document.getElementById('codigo').focus();
        
    } catch (error) {
        console.error('Error:', error);
        Swal.fire({ icon: 'error', title: 'Error', text: 'Error al buscar el producto.', confirmButtonColor: '#f97316' });
    }
}

function renderCarrito() {
    const body = document.getElementById('carritoBody');
    if (carrito.length === 0) { 
        body.innerHTML = '<tr id="emptyCartRow"><td colspan="6" class="text-center py-5"><i class="fas fa-shopping-cart fa-3x text-muted mb-3"></i><p class="text-muted mb-0">El carrito esta vacio. Agrega productos para comenzar.</p></td></tr>'; 
        document.getElementById('total').value = '0.00'; 
        document.getElementById('cambio').value = '0.00'; 
        return; 
    }
    
    let html = '', total = 0, contador = 1;
    carrito.forEach((item, index) => {
        const subtotal = item.precio * item.cantidad;
        total += subtotal;
        
        let imagenHtml = '';
        if (item.imagen && item.imagen !== '' && item.imagen !== 'uploads/noimage.png' && !item.imagen.includes('no-image')) {
            imagenHtml = `<img src="${item.imagen}" class="producto-imagen" 
                         onerror="this.style.display='none'; this.parentElement.innerHTML='<div class=\'producto-icono ${item.iconoColor}\'><i class=\'${item.icono}\'></i></div>'">`;
        } else {
            imagenHtml = `<div class="producto-icono ${item.iconoColor}"><i class="${item.icono}"></i></div>`;
        }
        
        html += `<tr class="producto-animado">
                    <td class="fw-bold">${contador++}</td>
                    <td style="text-align: left;">
                        <div style="display: flex; align-items: center; gap: 12px;">
                            ${imagenHtml}
                            <div>
                                <strong>${escapeHtml(item.nombre)}</strong>
                                <br>
                                <small>Stock: ${item.stock}</small>
                            </div>
                        </div>
                      </td>
                      <td>
                        <input type="number" class="cantidad-input" value="${item.cantidad}" 
                               min="1" max="${item.stock}" 
                               onchange="actualizarCantidad(${index}, this.value)"
                               onfocus="this.select()">
                      </td>
                      <td><strong class="text-primary">$${item.precio.toFixed(2)}</strong></td>
                      <td><strong class="text-success">$${subtotal.toFixed(2)}</strong></td>
                      <td>
                        <button type="button" class="btn-eliminar" onclick="eliminarProducto(${index})">
                            <i class="fas fa-trash-alt"></i>
                        </button>
                      </td>
                 </tr>`;
    });
    body.innerHTML = html;
    document.getElementById('total').value = total.toFixed(2);
    document.getElementById('total').classList.add('total-flash');
    setTimeout(() => document.getElementById('total').classList.remove('total-flash'), 500);
    calcularCambio();
}

function guardarCarrito() { 
    $.ajax({
        url: 'ajax/guardar_carrito.php',
        method: 'POST',
        data: { carrito: JSON.stringify(carrito) },
        async: false
    });
}

function actualizarCantidad(index, valor) { 
    const cantidad = parseInt(valor); 
    if (isNaN(cantidad) || cantidad < 1) { renderCarrito(); return; } 
    if (cantidad > carrito[index].stock) { 
        Swal.fire({ icon: 'warning', title: 'Stock insuficiente', text: `Solo hay ${carrito[index].stock} unidades`, toast: true, position: 'top-end', showConfirmButton: false, timer: 2000 });
        renderCarrito(); 
        return; 
    } 
    carrito[index].cantidad = cantidad; 
    guardarCarrito(); 
    renderCarrito(); 
}

function eliminarProducto(index) { 
    Swal.fire({ 
        title: 'Eliminar producto', 
        text: `Quitar ${carrito[index].nombre} del carrito?`, 
        icon: 'question', 
        showCancelButton: true, 
        confirmButtonColor: '#f97316', 
        cancelButtonColor: '#6c757d', 
        confirmButtonText: 'Si, eliminar', 
        cancelButtonText: 'Cancelar' 
    }).then(result => { 
        if (result.isConfirmed) { 
            carrito.splice(index, 1); 
            guardarCarrito(); 
            renderCarrito(); 
            Swal.fire({ icon: 'success', title: 'Eliminado', text: 'Producto eliminado del carrito', toast: true, position: 'top-end', showConfirmButton: false, timer: 1500 }); 
        } 
    }); 
}

function calcularCambio() { 
    const total = parseFloat(document.getElementById('total').value) || 0; 
    const pago = parseFloat(document.getElementById('monto_pagado').value) || 0; 
    const cambio = pago - total; 
    const cambioInput = document.getElementById('cambio'); 
    cambioInput.value = cambio.toFixed(2); 
    cambioInput.style.color = cambio < 0 ? '#ef4444' : '#16a34a'; 
    cambioInput.style.fontWeight = 'bold'; 
}

// ============ METODOS DE PAGO ============
function mostrarCamposPago() {
    const metodo = document.querySelector('input[name="metodo_pago"]:checked').value;
    const extra = document.getElementById('extraCampos');
    let html = '';
    switch(metodo) {
       case 'efectivo':
            html = `<div class="alert alert-success mb-0" style="background: #dcfce7; border: 1px solid #86efac; border-radius: 16px; color: #166534;">
                        <div class="d-flex align-items-center gap-2">  
                            <div>
                                <i class="fas fa-money-bill-wave" style="font-size:1.2rem; color: #16a34a;"></i>
                                <strong style="color: #14532d;">Pago en efectivo</strong>
                                <p class="mb-0 mt-1 small" style="color: #4b5563;">No se requiere referencia. Calcula el cambio automaticamente.</p>
                            </div>
                        </div>
                    </div>`;
            break;
        case 'transferencia':
            html = `
                <div class="form-group mb-3">
                    <label><i class="fas fa-hashtag me-1"></i> Folio de transferencia *</label>
                    <input type="text" class="form-control pos-input" name="referencia_pago" id="folio_transferencia" required placeholder="Ej: TRX87439210" maxlength="20" oninput="formatearFolioTransferencia(this)">
                    <small class="text-muted">Maximo 20 caracteres, solo letras y numeros</small>
                </div>
                <div class="text-center">
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
                            <label><i class="fas fa-credit-card me-1"></i> Ultimos 4 digitos *</label>
                            <input type="text" class="form-control pos-input" id="ultimos4" name="ultimos4" maxlength="4" required placeholder="Ej: 4921" oninput="this.value = this.value.replace(/\\D/g,''); detectarTipoTarjeta();">
                            <small class="text-muted">Ingresa los ultimos 4 digitos de la tarjeta</small>
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-tag me-1"></i> Tipo de tarjeta</label>
                            <input type="text" class="form-control pos-input" id="tipo_tarjeta" name="tipo_tarjeta" readonly placeholder="Detectado...." style="background-color: #f8fafc;">
                            <input type="hidden" name="tipo_tarjeta_detectada" id="tipo_tarjeta_detectada">
                            <small class="text-muted">Se detecta automaticamente segun los digitos ingresados</small>
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-check-circle me-1"></i> Folio de autorizacion *</label>
                            <input type="text" class="form-control pos-input" name="folio_autorizacion" id="folio_autorizacion" required maxlength="16" placeholder="Ej: AUTH-938492" oninput="validarFolio(this)">
                            <small class="text-muted">Maximo 16 caracteres, solo letras y numeros</small>
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
    const tipoInput = document.getElementById('tipo_tarjeta');
    const oculto = document.getElementById('tipo_tarjeta_detectada');
    if (ultimos4.length < 1) { tipoInput.value = ''; oculto.value = ''; return; }
    let tipo = "OTRA";
    let textoMostrar = "OTRA";
    if (/^4/.test(ultimos4)) { tipo = "VISA"; textoMostrar = "VISA"; }
    else if (/^(5[1-5]|22[2-9]|2[3-7])/.test(ultimos4)) { tipo = "MASTERCARD"; textoMostrar = "MASTERCARD"; }
    else if (/^(34|37)/.test(ultimos4)) { tipo = "AMEX"; textoMostrar = "AMERICAN EXPRESS"; }
    else { textoMostrar = "OTRA (No reconocida)"; }
    tipoInput.value = textoMostrar;
    oculto.value = tipo;
}

function confirmarVenta() {
    if (ventaEnProceso) {
        Swal.fire({
            icon: 'info',
            title: 'Procesando',
            text: 'Ya hay una venta en proceso, por favor espera...',
            toast: true,
            position: 'top-end',
            showConfirmButton: false,
            timer: 2000
        });
        return;
    }
    
    if (carrito.length === 0) { 
        Swal.fire({ icon: 'warning', title: 'Carrito vacio', text: 'Agrega productos antes de registrar la venta.', confirmButtonColor: '#f97316' }); 
        return; 
    }
    
    const total = parseFloat(document.getElementById('total').value); 
    const pago = parseFloat(document.getElementById('monto_pagado').value);
    
    if (!pago || pago <= 0) { 
        Swal.fire({ icon: 'warning', title: 'Monto invalido', text: 'Ingresa un monto pagado valido.', confirmButtonColor: '#f97316' }); 
        document.getElementById('monto_pagado').focus(); 
        return; 
    }
    
    if (pago < total) { 
        Swal.fire({ icon: 'error', title: 'Monto insuficiente', text: `El pago ($${pago.toFixed(2)}) no cubre el total ($${total.toFixed(2)}).`, confirmButtonColor: '#f97316' }); 
        document.getElementById('monto_pagado').focus(); 
        return; 
    }
    
    const metodo = document.querySelector('input[name="metodo_pago"]:checked').value;
    
    if (metodo === 'transferencia') { 
        const folio = document.getElementById('folio_transferencia')?.value; 
        if (!folio || folio.length < 5) { 
            Swal.fire({ icon: 'warning', title: 'Folio requerido', text: 'Ingresa el folio de la transferencia.', confirmButtonColor: '#f97316' }); 
            return; 
        } 
    }
    
    if (metodo === 'tarjeta_debito' || metodo === 'tarjeta_credito') { 
        const ultimos4 = document.getElementById('ultimos4')?.value; 
        const auth = document.getElementById('folio_autorizacion')?.value; 
        if (!ultimos4 || ultimos4.length !== 4) { 
            Swal.fire({ icon: 'warning', title: 'Datos incompletos', text: 'Ingresa los ultimos 4 digitos.', confirmButtonColor: '#f97316' }); 
            return; 
        } 
        if (!auth || auth.length < 4) { 
            Swal.fire({ icon: 'warning', title: 'Datos incompletos', text: 'Ingresa el folio de autorizacion.', confirmButtonColor: '#f97316' }); 
            return; 
        } 
    }
    
    let resumen = carrito.map(p => `${p.nombre} x${p.cantidad} - $${(p.precio * p.cantidad).toFixed(2)}`).join('<br>');
    
    Swal.fire({ 
        title: 'Confirmar venta', 
        html: `<div style="max-height: 300px; overflow-y: auto;">${resumen}</div><hr><strong>Total:</strong> $${total.toFixed(2)}<br><strong>Pago:</strong> $${pago.toFixed(2)}<br><strong>Cambio:</strong> $${(pago - total).toFixed(2)}`, 
        icon: 'question', 
        showCancelButton: true, 
        confirmButtonText: '<i class="fas fa-check me-2"></i>Registrar', 
        cancelButtonText: 'Cancelar', 
        confirmButtonColor: '#f97316', 
        cancelButtonColor: '#6c757d',
        preConfirm: () => {
            ventaEnProceso = true;
            const btnConfirmar = document.getElementById('btnConfirmar');
            if (btnConfirmar) {
                btnConfirmar.disabled = true;
                btnConfirmar.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i> Procesando venta...';
                btnConfirmar.style.opacity = '0.7';
                btnConfirmar.style.cursor = 'not-allowed';
            }
            return true;
        }
    }).then(result => { 
        if (result.isConfirmed) { 
            try {
                document.getElementById('sonidoCaja').play().catch(e => console.log('Error:', e));
                document.getElementById('carrito_json').value = JSON.stringify(carrito);
                document.getElementById('ventaForm').submit();
            } catch(error) {
                ventaEnProceso = false;
                const btnConfirmar = document.getElementById('btnConfirmar');
                if (btnConfirmar) {
                    btnConfirmar.disabled = false;
                    btnConfirmar.innerHTML = '<i class="fas fa-check-circle me-2"></i> Confirmar venta';
                    btnConfirmar.style.opacity = '1';
                    btnConfirmar.style.cursor = 'pointer';
                }
                Swal.fire({ icon: 'error', title: 'Error', text: 'Ocurrio un error al procesar la venta.', confirmButtonColor: '#f97316' });
            }
        } else if (result.dismiss === Swal.DismissReason.cancel) {
            ventaEnProceso = false;
            const btnConfirmar = document.getElementById('btnConfirmar');
            if (btnConfirmar) {
                btnConfirmar.disabled = false;
                btnConfirmar.innerHTML = '<i class="fas fa-check-circle me-2"></i> Confirmar venta';
                btnConfirmar.style.opacity = '1';
                btnConfirmar.style.cursor = 'pointer';
            }
        }
    });
}

// ============ EVENTOS ============
document.addEventListener('DOMContentLoaded', function() {
    renderCarrito();
    mostrarCamposPago();
    document.getElementById('codigo').focus();
    
    // Eventos de busqueda
    const buscador = document.getElementById('buscadorProductos');
    const filtroCategoria = document.getElementById('filtroCategoriaProductos');
    
    if (buscador) {
        buscador.addEventListener('input', filtrarProductos);
    }
    if (filtroCategoria) {
        filtroCategoria.addEventListener('change', filtrarProductos);
    }
    
    // Inicializar eventos de metodos de pago
    const metodos = document.querySelectorAll('.metodo-radio');
    metodos.forEach(metodo => {
        metodo.addEventListener('click', function(e) {
            e.preventDefault();
            e.stopPropagation();
            
            metodos.forEach(el => el.classList.remove('selected'));
            this.classList.add('selected');
            
            const radio = this.querySelector('input[type="radio"]');
            if (radio) {
                radio.checked = true;
            }
            
            mostrarCamposPago();
        });
        
        if (metodo.querySelector('input[type="radio"]').checked) {
            metodo.classList.add('selected');
        }
    });
    
    const btnConfirmar = document.getElementById('btnConfirmar');
    if (btnConfirmar) {
        btnConfirmar.disabled = false;
        btnConfirmar.innerHTML = '<i class="fas fa-check-circle me-2"></i> Confirmar venta';
        btnConfirmar.style.opacity = '1';
        btnConfirmar.style.cursor = 'pointer';
    }
    ventaEnProceso = false;
});

document.getElementById('ventaForm')?.addEventListener('submit', function() {
    document.getElementById('carrito_json').value = JSON.stringify(carrito);
});

document.getElementById('codigo').addEventListener('keydown', function(e) { if (e.key === 'Enter') { e.preventDefault(); agregarProducto(); } });
document.getElementById('monto_pagado').addEventListener('input', calcularCambio);

function escapeHtml(text) { const div = document.createElement('div'); div.textContent = text; return div.innerHTML; }

function mostrarDatosBancarios() {
    Swal.fire({
        title: 'Datos Bancarios BBVA',
        html: `
            <div class="text-start">
                <p><strong>Banco:</strong> BBVA</p>
                <p><strong>Titular:</strong> KARMINA ARANGUTHY GARCIA</p>
                <p><strong>Cuenta:</strong> 1234 5678 9012 3456</p>
                <p><strong>CLABE:</strong> 012 180 00123456789</p>
            </div>
        `,
        icon: 'info',
        confirmButtonColor: '#f97316'
    });
}

document.querySelectorAll('.metodo-radio').forEach(el => {
    el.addEventListener('click', function(e) {
        const scrollY = window.scrollY;
        setTimeout(() => {
            window.scrollTo(0, scrollY);
        }, 0);
    });
});
</script>