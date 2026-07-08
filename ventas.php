<?php
date_default_timezone_set('America/Mexico_City');

include('includes/db.php');
include('includes/session.php');
require_once('includes/csrf.php');

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;


// ==========================================================
// INTEGRACIÓN CAJÓN DE EFECTIVO USB/RJ11 POR PUERTO COM
// Confirmado: abre con PowerShell usando COM3 y escribiendo "1".
// Flujo: confirmar venta -> guardar venta -> commit -> abrir cajón.
// ==========================================================
if (!defined('CAJON_ENABLED')) {
    define('CAJON_ENABLED', true);
}

// Opciones: "all" para abrir en cualquier venta, "efectivo" para abrir solo en efectivo.
if (!defined('CAJON_OPEN_MODE')) {
    define('CAJON_OPEN_MODE', 'all');
}

// Puerto confirmado por prueba en PowerShell.
if (!defined('CAJON_COM_PORT')) {
    define('CAJON_COM_PORT', 'COM3');
}

if (!defined('CAJON_COM_BAUD')) {
    define('CAJON_COM_BAUD', 9600);
}

if (!defined('CAJON_LOG_FILE')) {
    define('CAJON_LOG_FILE', __DIR__ . '/logs/cajon_dinero.log');
}

function cajon_log(string $mensaje): void
{
    $dir = dirname(CAJON_LOG_FILE);

    if (!is_dir($dir)) {
        @mkdir($dir, 0777, true);
    }

    @file_put_contents(
        CAJON_LOG_FILE,
        '[' . date('Y-m-d H:i:s') . '] ' . $mensaje . PHP_EOL,
        FILE_APPEND
    );
}

function debe_abrir_cajon(?string $metodoPago): bool
{
    if (!CAJON_ENABLED) {
        return false;
    }

    $modo = strtolower((string) CAJON_OPEN_MODE);
    $metodo = strtolower((string) $metodoPago);

    if ($modo === 'all') {
        return true;
    }

    if ($modo === 'efectivo') {
        return $metodo === 'efectivo';
    }

    return false;
}

function abrir_cajon_dinero(): array
{
    if (PHP_OS_FAMILY !== 'Windows') {
        $mensaje = 'La apertura por COM está configurada para Windows. Sistema actual: ' . PHP_OS_FAMILY;
        cajon_log($mensaje);

        return [
            'ok' => false,
            'message' => $mensaje
        ];
    }

    $com = CAJON_COM_PORT;
    $baud = (int) CAJON_COM_BAUD;

    /**
     * Script basado en la prueba confirmada:
     * $port = New-Object System.IO.Ports.SerialPort "COM3",9600,None,8,one
     * $port.Open()
     * $port.Write("1")
     * Start-Sleep -Milliseconds 300
     * $port.Close()
     */
    $script = '$ErrorActionPreference = "Stop"' . PHP_EOL
        . '$port = $null' . PHP_EOL
        . 'try {' . PHP_EOL
        . '    $port = New-Object System.IO.Ports.SerialPort "' . addslashes($com) . '",' . $baud . ',None,8,one' . PHP_EOL
        . '    $port.Open()' . PHP_EOL
        . '    $port.Write("1")' . PHP_EOL
        . '    Start-Sleep -Milliseconds 300' . PHP_EOL
        . '    $port.Close()' . PHP_EOL
        . '    exit 0' . PHP_EOL
        . '} catch {' . PHP_EOL
        . '    if ($port -ne $null -and $port.IsOpen) { $port.Close() }' . PHP_EOL
        . '    Write-Output $_.Exception.Message' . PHP_EOL
        . '    exit 1' . PHP_EOL
        . '}';

    $archivoTemporalBase = tempnam(sys_get_temp_dir(), 'cajon_com_');

    if (!$archivoTemporalBase) {
        return [
            'ok' => false,
            'message' => 'No se pudo crear el archivo temporal para abrir el cajón.'
        ];
    }

    $archivoTemporal = $archivoTemporalBase . '.ps1';
    @rename($archivoTemporalBase, $archivoTemporal);
    file_put_contents($archivoTemporal, $script);

    $cmd = 'powershell -NoProfile -ExecutionPolicy Bypass -WindowStyle Hidden -File '
        . escapeshellarg($archivoTemporal)
        . ' 2>&1';

    $salida = [];
    $codigo = 1;

    exec($cmd, $salida, $codigo);

    @unlink($archivoTemporal);

    if ($codigo === 0) {
        cajon_log('Cajón abierto correctamente por puerto ' . $com . ' a ' . $baud . ' baudios.');

        return [
            'ok' => true,
            'message' => 'Cajón abierto correctamente.'
        ];
    }

    $error = trim(implode(' | ', $salida));
    if ($error === '') {
        $error = 'PowerShell terminó con código ' . $codigo . ' sin devolver detalle.';
    }

    cajon_log('Error al abrir cajón por ' . $com . ': ' . $error);

    return [
        'ok' => false,
        'message' => 'No se pudo abrir el cajón por ' . $com . '. Detalle: ' . $error
    ];
}

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

    $mp_order_id = trim($_POST['mp_order_id'] ?? '');
    $mp_payment_id = trim($_POST['mp_payment_id'] ?? '');
    $mp_payment_status = trim($_POST['mp_payment_status'] ?? '');
    $mp_payment_status_detail = trim($_POST['mp_payment_status_detail'] ?? '');
    $mp_payment_method_id = trim($_POST['mp_payment_method_id'] ?? '');

    if ($metodo_pago === "tarjeta_debito" || $metodo_pago === "tarjeta_credito") {
        // Para tarjeta, la venta solo se registra si Mercado Pago ya devolvió pago aprobado/procesado.
        if ($mp_order_id === '' || !in_array($mp_payment_status, ['processed', 'approved'], true)) {
            $_SESSION['alerta'] = [
                'tipo' => 'error',
                'titulo' => 'Pago no confirmado',
                'mensaje' => 'No se registró la venta porque el pago con terminal Mercado Pago no fue aprobado.'
            ];
            header("Location: " . $_SERVER['PHP_SELF']);
            exit;
        }

        $referencia_pago = "Mercado Pago | Orden: {$mp_order_id}";
        if ($mp_payment_id !== '') {
            $referencia_pago .= " | Pago: {$mp_payment_id}";
        }
        if ($mp_payment_method_id !== '') {
            $referencia_pago .= " | Método: {$mp_payment_method_id}";
        }
        if ($mp_payment_status_detail !== '') {
            $referencia_pago .= " | Detalle: {$mp_payment_status_detail}";
        }
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

                    /**
                     * Hostinger corre en Linux y NO tiene acceso al COM3 de la PC del cajero.
                     * Por eso aquí ya no intentamos abrir el cajón desde PHP.
                     * Enviamos una bandera a JavaScript para que el navegador llame al servicio local:
                     * http://127.0.0.1:8787/abrir-cajon
                     */
                    $abrirCajonLocal = debe_abrir_cajon($metodo_pago);

                    $productosAlerta = [];
                    foreach ($carrito as $itemAlerta) {
                        $cantidadAlerta = (int) ($itemAlerta['cantidad'] ?? 0);
                        $precioAlerta = (float) ($itemAlerta['precio'] ?? 0);
                        $productosAlerta[] = [
                            'id' => (int) ($itemAlerta['id'] ?? 0),
                            'nombre' => (string) ($itemAlerta['nombre'] ?? ''),
                            'cantidad' => $cantidadAlerta,
                            'precio' => $precioAlerta,
                            'importe' => $precioAlerta * $cantidadAlerta
                        ];
                    }
                    
                    $_SESSION['carrito'] = [];
                    
                    $mensaje = "Venta registrada correctamente.
Cambio: $" . number_format($cambio, 2);
                    if ($ticketEnviado) $mensaje .= "
Ticket enviado a $correo_cliente";

                    if ($abrirCajonLocal) {
                        $mensaje .= "
El sistema intentará abrir el cajón en la PC del cajero.";
                        cajon_log('Venta registrada. Folio: ' . $folio . ' | Apertura de cajón delegada al servicio local del navegador.');
                    }
                    
                    $_SESSION['alerta'] = [
                        'tipo' => 'success',
                        'titulo' => 'Venta exitosa',
                        'mensaje' => $mensaje,
                        'folio' => $folio,
                        'fecha' => date('d/m/Y H:i:s'),
                        'productos' => $productosAlerta,
                        'total' => (float) $total,
                        'monto_pagado' => (float) $monto_pagado,
                        'cambio' => (float) $cambio,
                        'metodo_pago' => (string) $metodo_pago,
                        'correo_cliente' => $correo_cliente,
                        'ticket_enviado' => $ticketEnviado,
                        'cajon_abierto' => false,
                        'cajon_mensaje' => $abrirCajonLocal ? 'Apertura delegada al servicio local.' : 'No requerido.',
                        'abrir_cajon_local' => $abrirCajonLocal
                    ];
                    
                    header("Location: " . strtok($_SERVER["REQUEST_URI"], '?') . "?venta_exitosa=1");
                    exit;
                    
                } catch (Throwable $e) {
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

<link rel="stylesheet" href="css/venta-codigo.css?v=<?= time() ?>">


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

        <!-- Botón flotante circular para móvil -->
        <button class="btn-flotante-productos" onclick="abrirModalFlotante()">
            <i class="fas fa-plus"></i>
        </button>

        <!-- Modal flotante para productos en móvil -->
        <div id="modalFlotante" class="modal-productos-flotante">
            <div class="modal-flotante-content">
                <div class="modal-flotante-header">
                    <h5><i class="fas fa-box-open me-2"></i> Seleccionar Producto</h5>
                    <button class="modal-flotante-close" onclick="cerrarModalFlotante()">&times;</button>
                </div>
                <div class="modal-flotante-body">
                    <div class="modal-buscador-wrapper">
                        <input type="text" id="buscadorModal" placeholder="Buscar producto..." autocomplete="off">
                        <select id="filtroCategoriaModal">
                            <option value="">Todas las categorías</option>
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
                    <div class="modal-productos-grid" id="modalProductosGrid"></div>
                </div>
            </div>
        </div>

        <!-- DISEÑO DE DOS COLUMNAS -->
        <div class="row">
            <!-- COLUMNA IZQUIERDA: PRODUCTOS (Desktop) -->
            <div class="col-lg-6 productos-desktop">
    <div class="card pos-card">
        <div class="card-header pos-header">
            <h3 class="card-title mb-0">
                <i class="fas fa-box-open"></i>
                Seleccionar Producto
            </h3>
        </div>
        <div class="card-body">
            <div class="buscador-wrapper">
                <input type="text" id="buscadorProductos" placeholder="Buscar producto por nombre..." autocomplete="off">
                <select id="filtroCategoriaProductos">
                    <option value="">Todas las categorías</option>
                    <?php
                    $cat_query2 = "SELECT DISTINCT categoria FROM productos WHERE categoria IS NOT NULL AND categoria != '' AND cantidad > 0 ORDER BY categoria";
                    $cat_result2 = $conn->query($cat_query2);
                    if ($cat_result2) {
                        while($cat = $cat_result2->fetch_assoc()) {
                            echo '<option value="' . htmlspecialchars($cat['categoria']) . '">' . htmlspecialchars($cat['categoria']) . '</option>';
                        }
                    }
                    ?>
                </select>
            </div>
            
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

<!-- COLUMNA DERECHA: CARRITO - SCROLL HORIZONTAL ELEGANTE -->
<div class="col-lg-6">
    <div class="card pos-card">
        <div class="card-header pos-header">
            <h3 class="card-title mb-0">
                <i class="fas fa-shopping-cart"></i>
                Carrito de Ventas
            </h3>
        </div>

        <div class="card-body">
            <form method="POST" id="ventaForm">
                <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>">
                <input type="hidden" name="registrar_venta" value="1">
                <input type="hidden" name="carrito_json" id="carrito_json">
                <input type="hidden" name="mp_order_id" id="mp_order_id">
                <input type="hidden" name="mp_payment_id" id="mp_payment_id">
                <input type="hidden" name="mp_payment_status" id="mp_payment_status">
                <input type="hidden" name="mp_payment_status_detail" id="mp_payment_status_detail">
                <input type="hidden" name="mp_payment_method_id" id="mp_payment_method_id">

                <div class="pos-buscador mb-3">
                    <div class="input-group">
                        <span class="input-group-text"><i class="fas fa-barcode"></i></span>
                        <input type="text" class="form-control" id="codigo" 
                               placeholder="Escanea o escribe el código" 
                               autocomplete="off" autofocus>
                        <button type="button" class="btn btn-agregar" onclick="agregarProducto()">
                            <i class="fas fa-plus-circle me-2"></i> Agregar
                        </button>
                    </div>
                </div>

                <!-- Contenedor con scroll horizontal y vertical elegante -->
                <div class="pos-tabla-elegante">
                    <div class="tabla-wrapper">
                        <table class="tabla-carrito">
                            <thead>
                                <tr>
                                    <th class="th-num">#</th>
                                    <th class="th-producto">Producto</th>
                                    <th class="th-cantidad">Cantidad</th>
                                    <th class="th-precio">Precio</th>
                                    <th class="th-subtotal">Subtotal</th>
                                    <th class="th-accion">Acción</th>
                                </tr>
                            </thead>
                            <tbody id="carritoBody">
                                <tr id="emptyCartRow">
                                    <td colspan="6" class="text-center py-4">
                                        <i class="fas fa-shopping-cart fa-2x text-muted mb-2"></i>
                                        <p class="text-muted mb-0 small">El carrito está vacío</p>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>

                <div class="row g-2 mb-3 pos-totales">
                    <div class="col-md-4 col-6">
                        <div class="form-group">
                            <label class="small-label"><i class="fas fa-calculator me-1"></i> Total</label>
                            <input type="text" class="form-control form-control-sm total-numero" id="total" value="0.00" readonly>
                        </div>
                    </div>
                    <div class="col-md-4 col-6">
                        <div class="form-group">
                            <label class="small-label"><i class="fas fa-money-bill me-1"></i> Pagado</label>
                            <input type="number" class="form-control form-control-sm pago-numero" name="monto_pagado" id="monto_pagado" step="0.50" min="0" placeholder="0.00" oninput="calcularCambio()">
                        </div>
                    </div>
                    <div class="col-md-4 col-12">
                        <div class="form-group">
                            <label class="small-label"><i class="fas fa-exchange-alt me-1"></i> Cambio</label>
                            <input type="text" class="form-control form-control-sm cambio-numero" id="cambio" value="0.00" readonly>
                        </div>
                    </div>
                </div>

                <div class="form-group mb-3 pos-correo">
                    <label class="small-label"><i class="fas fa-envelope me-2"></i> Correo del cliente</label>
                    <input type="email" class="form-control form-control-sm" name="correo_cliente" id="correo_cliente" placeholder="cliente@ejemplo.com">
                    <small class="text-muted mt-1 d-block small">Opcional - envío de ticket</small>
                </div>
                
                <!-- MÉTODOS DE PAGO - SIN NINGÚN CUADRO DE COLOR DETRÁS -->
                <div class="metodos-pago-wrapper">
                    <h6 class="small-title"><i class="fas fa-credit-card me-2"></i> Método de pago</h6>
                    <div class="metodos-container">
                        <label class="metodo-radio">
                            <input type="radio" name="metodo_pago" value="efectivo" checked>
                            <div class="check-indicator"><i class="fas fa-check-circle"></i></div>
                            <div class="metodo-content">
                                <img src="https://cdn.jsdelivr.net/gh/twitter/twemoji@latest/assets/svg/1f4b5.svg" class="icono-metodo-color" alt="efectivo">
                                <span>Efectivo</span>
                            </div>
                        </label>

                        <label class="metodo-radio">
                            <input type="radio" name="metodo_pago" value="transferencia">
                            <div class="check-indicator"><i class="fas fa-check-circle"></i></div>
                            <div class="metodo-content">
                                <img src="https://cdn-icons-png.flaticon.com/512/2331/2331947.png" class="icono-metodo-color" alt="transferencia">
                                <span>Transf.</span>
                            </div>
                        </label>

                        <label class="metodo-radio">
                            <input type="radio" name="metodo_pago" value="tarjeta_debito">
                            <div class="check-indicator"><i class="fas fa-check-circle"></i></div>
                            <div class="metodo-content">
                                <img src="https://brandeps.com/logo-download/V/Visa-logo-vector-01.svg" class="icono-metodo-color" onerror="this.src='https://cdn-icons-png.flaticon.com/512/349/349221.png'" alt="visa">
                                <span>Débito</span>
                            </div>
                        </label>

                        <label class="metodo-radio">
                            <input type="radio" name="metodo_pago" value="tarjeta_credito">
                            <div class="check-indicator"><i class="fas fa-check-circle"></i></div>
                            <div class="metodo-content">
                                <img src="https://upload.wikimedia.org/wikipedia/commons/0/04/Mastercard-logo.png" class="icono-metodo-color" alt="mastercard">
                                <span>Crédito</span>
                            </div>
                        </label>
                    </div>
                </div>
                
                <div id="extraCampos"></div>

                <div class="d-flex gap-2 mb-2 flex-wrap">
                    <button type="button" class="btn btn-warning btn-sm flex-fill" onclick="guardarVentaPendiente()">
                        <i class="fas fa-pause-circle"></i> Pausar venta
                    </button>

                    <button type="button" class="btn btn-primary btn-sm flex-fill" onclick="verVentasPendientes()">
                        <i class="fas fa-list"></i> Pendientes
                    </button>

                    <button type="button" class="btn btn-secondary btn-sm flex-fill" onclick="nuevaVentaLimpia()">
                        <i class="fas fa-plus"></i> Nueva venta
                    </button>
                </div>

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

<script src="https://cdn.jsdelivr.net/npm/qrcodejs@1.0.0/qrcode.min.js"></script>

<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>

<script>
let carrito = <?php echo $carrito_json; ?>;
const VENTA_EXITOSA = <?= $venta_exitosa ? 'true' : 'false' ?>;
const ALERTA_SESION = <?= json_encode($alerta ?? null, JSON_UNESCAPED_UNICODE) ?>;
const POS_STORAGE_ACTIVA = 'pos_venta_activa';
const POS_STORAGE_PENDIENTES = 'pos_ventas_pendientes';
const CAJON_LOCAL_URL = 'http://127.0.0.1:8787/abrir-cajon';
let ventaEnProceso = false;
let buscandoProducto = false;
let timerCodigo = null;

// ============ UTILIDADES ============
function escapeHtml(text) { 
    const div = document.createElement('div'); 
    div.textContent = text ?? ''; 
    return div.innerHTML; 
}

function obtenerScrollActual() {
    return {
        x: window.pageXOffset || document.documentElement.scrollLeft || 0,
        y: window.pageYOffset || document.documentElement.scrollTop || 0
    };
}

function restaurarScroll(scroll) {
    if (!scroll) return;

    requestAnimationFrame(() => {
        window.scrollTo(scroll.x, scroll.y);

        setTimeout(() => {
            window.scrollTo(scroll.x, scroll.y);
        }, 0);
    });
}

function enfocarCodigo() {
    const input = document.getElementById('codigo');

    if (!input) return;

    const scrollActual = obtenerScrollActual();

    setTimeout(() => {
        try {
            input.focus({ preventScroll: true });
        } catch (e) {
            input.focus();
        }

        try {
            input.select();
        } catch (e) {}

        restaurarScroll(scrollActual);
    }, 80);
}


// ============ MODO ESCÁNER INTELIGENTE ============
// Objetivo:
// 1) Después de usar buscadores, categorías o métodos de pago, regresar el foco al input #codigo.
// 2) Si el lector dispara teclas mientras el foco quedó en otro campo, detectar la lectura rápida
//    y moverla automáticamente al buscador de código de barras.
let scannerFocusTimer = null;
let scannerBuffer = '';
let scannerLastKeyTime = 0;
let scannerStartTime = 0;
let scannerFlushTimer = null;
let scannerTargetElement = null;
let scannerTargetInitialValue = '';
const SCANNER_MIN_LENGTH = 4;
const SCANNER_MAX_INTERVAL_MS = 80;
const SCANNER_FLUSH_MS = 120;

function esElementoEditable(el) {
    if (!el) return false;
    const tag = (el.tagName || '').toLowerCase();
    return tag === 'input' || tag === 'textarea' || tag === 'select' || el.isContentEditable;
}

function estaDentroDeSweetAlert(el) {
    return !!(el && el.closest && el.closest('.swal2-container'));
}

function esCampoManualProtegido(el) {
    if (!el) return false;

    const id = el.id || '';
    const name = el.name || '';

    // En estos campos el usuario puede escribir con calma sin que el sistema le robe el foco.
    return [
        'buscadorProductos',
        'buscadorModal',
        'folio_transferencia',
        'correo_cliente',
        'monto_pagado'
    ].includes(id) || name === 'referencia_pago';
}

function programarEnfoqueEscaner(delay = 250, forzar = false) {
    clearTimeout(scannerFocusTimer);
    scannerFocusTimer = setTimeout(() => {
        if (ventaEnProceso) return;
        if (document.body.classList.contains('swal2-shown')) return;
        if (document.getElementById('modalFlotante')?.classList.contains('active')) return;

        // Si el usuario sigue escribiendo en buscador o folio, no le quitamos el foco.
        if (!forzar && esCampoManualProtegido(document.activeElement)) return;

        enfocarCodigo();
    }, delay);
}

function limpiarBufferScanner() {
    scannerBuffer = '';
    scannerLastKeyTime = 0;
    scannerStartTime = 0;
    scannerTargetElement = null;
    scannerTargetInitialValue = '';
    clearTimeout(scannerFlushTimer);
}

function limpiarLecturaDeCampoOrigen(codigo) {
    const el = scannerTargetElement;

    if (!el || el === document.getElementById('codigo')) return;
    if (!('value' in el)) return;

    // Durante las primeras teclas el navegador pudo haber escrito parte del código
    // en el buscador o folio. Quitamos solo el prefijo del código que quedó al final.
    setTimeout(() => {
        const valorActual = String(el.value || '');
        let mejorCoincidencia = '';

        for (let i = Math.min(codigo.length, valorActual.length); i >= 1; i--) {
            const prefijo = codigo.substring(0, i);
            if (valorActual.endsWith(prefijo)) {
                mejorCoincidencia = prefijo;
                break;
            }
        }

        if (mejorCoincidencia) {
            el.value = valorActual.substring(0, valorActual.length - mejorCoincidencia.length);
            el.dispatchEvent(new Event('input', { bubbles: true }));
        }
    }, 0);
}

function enviarBufferAlCodigo() {
    const codigo = scannerBuffer.trim();

    if (codigo.length < SCANNER_MIN_LENGTH) {
        limpiarBufferScanner();
        return;
    }

    limpiarLecturaDeCampoOrigen(codigo);

    const inputCodigo = document.getElementById('codigo');
    if (!inputCodigo) {
        limpiarBufferScanner();
        return;
    }

    const scrollActual = obtenerScrollActual();
    inputCodigo.value = codigo;
    limpiarBufferScanner();

    try {
        inputCodigo.focus({ preventScroll: true });
    } catch (e) {
        inputCodigo.focus();
    }

    restaurarScroll(scrollActual);

    if (!buscandoProducto) {
        agregarProducto();
    }
}

function manejarTeclaGlobalScanner(e) {
    if (ventaEnProceso || buscandoProducto) return;
    if (e.ctrlKey || e.altKey || e.metaKey) return;
    if (estaDentroDeSweetAlert(e.target)) return;

    const inputCodigo = document.getElementById('codigo');
    if (!inputCodigo) return;

    // Si ya estamos en el input de código, el flujo normal se encarga.
    if (document.activeElement === inputCodigo) return;

    const key = e.key;
    const ahora = Date.now();

    if (key === 'Enter') {
        if (scannerBuffer.length >= SCANNER_MIN_LENGTH) {
            e.preventDefault();
            enviarBufferAlCodigo();
        } else {
            limpiarBufferScanner();
        }
        return;
    }

    if (!key || key.length !== 1) return;

    const intervalo = scannerLastKeyTime ? (ahora - scannerLastKeyTime) : 0;

    // Si la escritura fue lenta, asumimos que es una persona escribiendo en buscador/folio.
    if (!scannerLastKeyTime || intervalo > SCANNER_MAX_INTERVAL_MS) {
        scannerBuffer = key;
        scannerStartTime = ahora;
        scannerTargetElement = e.target;
        scannerTargetInitialValue = (e.target && 'value' in e.target) ? String(e.target.value || '') : '';
    } else {
        scannerBuffer += key;
    }

    scannerLastKeyTime = ahora;

    clearTimeout(scannerFlushTimer);
    scannerFlushTimer = setTimeout(() => {
        const duracion = Date.now() - scannerStartTime;
        const promedio = scannerBuffer.length > 1 ? duracion / (scannerBuffer.length - 1) : duracion;

        // Un lector normalmente manda muchos caracteres muy rápido. Si cumple, movemos al input de código.
        if (scannerBuffer.length >= SCANNER_MIN_LENGTH && promedio <= SCANNER_MAX_INTERVAL_MS) {
            e.preventDefault?.();
            enviarBufferAlCodigo();
        } else {
            limpiarBufferScanner();
        }
    }, SCANNER_FLUSH_MS);

    // Si ya parece lectura de escáner, evitamos que siga llenando el buscador/filtro actual.
    if (esElementoEditable(e.target) && scannerBuffer.length >= SCANNER_MIN_LENGTH && intervalo > 0 && intervalo <= SCANNER_MAX_INTERVAL_MS) {
        e.preventDefault();
    }
}

function resetBotonVenta() {
    const btn = document.getElementById('btnConfirmar');
    if (btn) {
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-check-circle me-2"></i> Confirmar venta';
    }
}


// ============ LOCAL STORAGE: VENTAS ACTIVAS Y PENDIENTES ============
function obtenerPendientesLocal() {
    try {
        return JSON.parse(localStorage.getItem(POS_STORAGE_PENDIENTES) || '[]');
    } catch (e) {
        console.error('Error leyendo pendientes:', e);
        return [];
    }
}

function guardarPendientesLocal(pendientes) {
    localStorage.setItem(POS_STORAGE_PENDIENTES, JSON.stringify(pendientes || []));
}

function obtenerDatosFormularioVenta() {
    const metodo = document.querySelector('input[name="metodo_pago"]:checked')?.value || 'efectivo';

    return {
        id: Date.now(),
        fecha: new Date().toISOString(),
        carrito: carrito,
        monto_pagado: document.getElementById('monto_pagado')?.value || '',
        correo_cliente: document.getElementById('correo_cliente')?.value || '',
        metodo_pago: metodo,
        referencia_pago: document.getElementById('folio_transferencia')?.value || '',
        ultimos4: document.getElementById('ultimos4')?.value || '',
        tipo_tarjeta_detectada: document.getElementById('tipo_tarjeta_detectada')?.value || '',
        folio_autorizacion: document.getElementById('folio_autorizacion')?.value || ''
    };
}

function guardarVentaActivaLocal() {
    if (ventaEnProceso || VENTA_EXITOSA) return;

    if (!Array.isArray(carrito) || carrito.length === 0) {
        localStorage.removeItem(POS_STORAGE_ACTIVA);
        return;
    }

    localStorage.setItem(POS_STORAGE_ACTIVA, JSON.stringify(obtenerDatosFormularioVenta()));
}

function limpiarVentaActivaLocal() {
    localStorage.removeItem(POS_STORAGE_ACTIVA);
}

function calcularTotalCarrito(items) {
    return (items || []).reduce((sum, item) => sum + ((parseFloat(item.precio) || 0) * (parseInt(item.cantidad) || 0)), 0);
}

function resumenVenta(data) {
    const total = calcularTotalCarrito(data.carrito || []);
    const productos = (data.carrito || []).length;
    const fecha = data.fecha ? new Date(data.fecha).toLocaleString() : 'Sin fecha';
    const nombre = data.nombre || `Venta ${fecha}`;

    return { total, productos, fecha, nombre };
}

function aplicarVentaGuardada(data) {
    carrito = Array.isArray(data.carrito) ? data.carrito : [];

    const monto = document.getElementById('monto_pagado');
    const correo = document.getElementById('correo_cliente');

    if (monto) monto.value = data.monto_pagado || '';
    if (correo) correo.value = data.correo_cliente || '';

    const metodo = data.metodo_pago || 'efectivo';
    const radio = document.querySelector(`input[name="metodo_pago"][value="${metodo}"]`);

    if (radio) {
        radio.checked = true;
        document.querySelectorAll('.metodo-radio').forEach(el => el.classList.remove('selected'));
        radio.closest('.metodo-radio')?.classList.add('selected');
    }

    mostrarCamposPago();

    setTimeout(() => {
        const folioTransfer = document.getElementById('folio_transferencia');
        const ultimos4 = document.getElementById('ultimos4');
        const tipoDetectado = document.getElementById('tipo_tarjeta_detectada');
        const tipoVisible = document.getElementById('tipo_tarjeta');
        const auth = document.getElementById('folio_autorizacion');

        if (folioTransfer) folioTransfer.value = data.referencia_pago || '';
        if (ultimos4) ultimos4.value = data.ultimos4 || '';
        if (tipoDetectado) tipoDetectado.value = data.tipo_tarjeta_detectada || '';
        if (tipoVisible) tipoVisible.value = data.tipo_tarjeta_detectada || '';
        if (auth) auth.value = data.folio_autorizacion || '';

        renderCarrito();
        guardarCarrito();
        guardarVentaActivaLocal();
        enfocarCodigo();
    }, 50);
}

function limpiarFormularioVenta() {
    carrito = [];

    const monto = document.getElementById('monto_pagado');
    const correo = document.getElementById('correo_cliente');
    const codigo = document.getElementById('codigo');

    if (monto) monto.value = '';
    if (correo) correo.value = '';
    if (codigo) codigo.value = '';

    const efectivo = document.querySelector('input[name="metodo_pago"][value="efectivo"]');
    if (efectivo) efectivo.checked = true;

    document.querySelectorAll('.metodo-radio').forEach(el => el.classList.remove('selected'));
    efectivo?.closest('.metodo-radio')?.classList.add('selected');

    mostrarCamposPago();
    renderCarrito();
    guardarCarrito();
    limpiarVentaActivaLocal();
    enfocarCodigo();
}

function guardarVentaPendiente(nombreManual = null) {
    if (!Array.isArray(carrito) || carrito.length === 0) {
        Swal.fire({
            icon: 'warning',
            title: 'Carrito vacío',
            text: 'No hay productos para pausar.',
            confirmButtonColor: '#f97316'
        });
        return;
    }

    Swal.fire({
        title: 'Pausar venta',
        input: 'text',
        inputLabel: 'Nombre o referencia de la venta pendiente',
        inputValue: nombreManual || `Venta pendiente ${new Date().toLocaleTimeString()}`,
        inputPlaceholder: 'Ej: Cliente Juan / Mesa 2',
        showCancelButton: true,
        confirmButtonText: 'Guardar pendiente',
        cancelButtonText: 'Cancelar',
        confirmButtonColor: '#f97316',
        inputValidator: value => !value.trim() ? 'Escribe una referencia para identificarla.' : null
    }).then(result => {
        if (!result.isConfirmed) return;

        const pendientes = obtenerPendientesLocal();
        const venta = obtenerDatosFormularioVenta();
        venta.id = Date.now();
        venta.nombre = result.value.trim();
        venta.fecha = new Date().toISOString();

        pendientes.unshift(venta);
        guardarPendientesLocal(pendientes);
        limpiarFormularioVenta();

        Swal.fire({
            icon: 'success',
            title: 'Venta pausada',
            text: 'Ya puedes iniciar otra venta sin perder la anterior.',
            toast: true,
            position: 'top-end',
            showConfirmButton: false,
            timer: 1800
        });
    });
}

function nuevaVentaLimpia() {
    if (!Array.isArray(carrito) || carrito.length === 0) {
        limpiarFormularioVenta();
        return;
    }

    Swal.fire({
        title: 'Iniciar nueva venta',
        text: 'Tienes una venta en proceso. ¿Qué deseas hacer?',
        icon: 'question',
        showDenyButton: true,
        showCancelButton: true,
        confirmButtonText: 'Pausar y nueva',
        denyButtonText: 'Nueva sin guardar',
        cancelButtonText: 'Cancelar',
        confirmButtonColor: '#f97316',
        denyButtonColor: '#dc2626',
        cancelButtonColor: '#6c757d'
    }).then(result => {
        if (result.isConfirmed) {
            guardarVentaPendiente();
        } else if (result.isDenied) {
            limpiarFormularioVenta();
            Swal.fire({
                icon: 'success',
                title: 'Nueva venta lista',
                toast: true,
                position: 'top-end',
                showConfirmButton: false,
                timer: 1300
            });
        }
    });
}

function verVentasPendientes() {
    const pendientes = obtenerPendientesLocal();

    if (pendientes.length === 0) {
        Swal.fire({
            icon: 'info',
            title: 'Sin ventas pendientes',
            text: 'No tienes ventas pausadas por el momento.',
            confirmButtonColor: '#f97316'
        });
        return;
    }

    const html = pendientes.map(v => {
        const r = resumenVenta(v);
        return `
            <div style="border:1px solid #e5e7eb; border-radius:14px; padding:12px; margin-bottom:10px; text-align:left; background:#fff;">
                <div style="display:flex; justify-content:space-between; gap:10px; align-items:flex-start;">
                    <div>
                        <strong style="color:#1e293b; font-size:14px;">${escapeHtml(r.nombre)}</strong><br>
                        <small style="color:#64748b;">${r.fecha} · ${r.productos} producto(s)</small><br>
                        <strong style="color:#16a34a;">Total: $${r.total.toFixed(2)}</strong>
                    </div>
                    <div style="display:flex; gap:6px; flex-wrap:wrap; justify-content:flex-end;">
                        <button type="button" onclick="recuperarVentaPendiente(${v.id})" style="border:0; border-radius:10px; padding:7px 10px; background:#f97316; color:white; cursor:pointer; font-size:12px;">
                            Recuperar
                        </button>
                        <button type="button" onclick="eliminarVentaPendiente(${v.id})" style="border:0; border-radius:10px; padding:7px 10px; background:#ef4444; color:white; cursor:pointer; font-size:12px;">
                            Eliminar
                        </button>
                    </div>
                </div>
            </div>
        `;
    }).join('');

    Swal.fire({
        title: 'Ventas pendientes',
        html: `<div style="max-height:420px; overflow-y:auto; padding-right:4px;">${html}</div>`,
        width: '650px',
        showConfirmButton: true,
        confirmButtonText: 'Cerrar',
        confirmButtonColor: '#f97316'
    });
}

function recuperarVentaPendiente(id) {
    const pendientes = obtenerPendientesLocal();
    const venta = pendientes.find(v => Number(v.id) === Number(id));

    if (!venta) return;

    const cargar = () => {
        const nuevosPendientes = pendientes.filter(v => Number(v.id) !== Number(id));
        guardarPendientesLocal(nuevosPendientes);
        aplicarVentaGuardada(venta);

        Swal.fire({
            icon: 'success',
            title: 'Venta recuperada',
            text: 'La venta pendiente volvió al carrito.',
            toast: true,
            position: 'top-end',
            showConfirmButton: false,
            timer: 1600
        });
    };

    if (Array.isArray(carrito) && carrito.length > 0) {
        Swal.fire({
            title: 'Ya hay una venta activa',
            text: 'Para recuperar esta pendiente, primero debes pausar o descartar la venta actual.',
            icon: 'warning',
            showDenyButton: true,
            showCancelButton: true,
            confirmButtonText: 'Pausar actual',
            denyButtonText: 'Descartar actual',
            cancelButtonText: 'Cancelar',
            confirmButtonColor: '#f97316',
            denyButtonColor: '#dc2626'
        }).then(result => {
            if (result.isConfirmed) {
                const actuales = obtenerPendientesLocal();
                const actual = obtenerDatosFormularioVenta();
                actual.id = Date.now();
                actual.nombre = `Venta pausada ${new Date().toLocaleTimeString()}`;
                actual.fecha = new Date().toISOString();
                actuales.unshift(actual);
                guardarPendientesLocal(actuales);
                cargar();
            } else if (result.isDenied) {
                cargar();
            }
        });
    } else {
        cargar();
    }
}

function eliminarVentaPendiente(id) {
    Swal.fire({
        title: 'Eliminar pendiente',
        text: 'Esta venta pausada se eliminará definitivamente.',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonText: 'Sí, eliminar',
        cancelButtonText: 'Cancelar',
        confirmButtonColor: '#dc2626',
        cancelButtonColor: '#6c757d'
    }).then(result => {
        if (!result.isConfirmed) return;

        const pendientes = obtenerPendientesLocal().filter(v => Number(v.id) !== Number(id));
        guardarPendientesLocal(pendientes);
        verVentasPendientes();
    });
}

function restaurarVentaActivaLocal() {
    if (VENTA_EXITOSA) {
        limpiarVentaActivaLocal();
        return;
    }

    if (Array.isArray(carrito) && carrito.length > 0) {
        guardarVentaActivaLocal();
        return;
    }

    const guardadaRaw = localStorage.getItem(POS_STORAGE_ACTIVA);
    if (!guardadaRaw) return;

    let guardada = null;
    try {
        guardada = JSON.parse(guardadaRaw);
    } catch (e) {
        limpiarVentaActivaLocal();
        return;
    }

    if (!guardada || !Array.isArray(guardada.carrito) || guardada.carrito.length === 0) {
        limpiarVentaActivaLocal();
        return;
    }

    const r = resumenVenta(guardada);

    Swal.fire({
        title: 'Venta sin terminar',
        html: `Encontré una venta anterior sin finalizar:<br><br><strong>${escapeHtml(r.nombre)}</strong><br>${r.productos} producto(s) · Total: <strong>$${r.total.toFixed(2)}</strong><br><small>${r.fecha}</small>`,
        icon: 'question',
        showDenyButton: true,
        showCancelButton: true,
        confirmButtonText: 'Recuperar',
        denyButtonText: 'Descartar',
        cancelButtonText: 'Después',
        confirmButtonColor: '#f97316',
        denyButtonColor: '#dc2626'
    }).then(result => {
        if (result.isConfirmed) {
            aplicarVentaGuardada(guardada);
        } else if (result.isDenied) {
            limpiarVentaActivaLocal();
        }
    });
}

// ============ FUNCIONES DEL MODAL FLOTANTE ============
function abrirModalFlotante() {
    cargarProductosEnModal();
    document.getElementById('modalFlotante')?.classList.add('active');
    document.body.style.overflow = 'hidden';
}

function cerrarModalFlotante() {
    document.getElementById('modalFlotante')?.classList.remove('active');
    document.body.style.overflow = '';
    enfocarCodigo();
}

function cargarProductosEnModal() {
    const container = document.getElementById('modalProductosGrid');
    const productosOriginales = document.querySelectorAll('#productosGrid .producto-card');

    if (!container) return;

    container.innerHTML = '';

    productosOriginales.forEach(p => {
        const clone = p.cloneNode(true);

        clone.onclick = function(e) {
            if (!e.target.classList.contains('btn-agregar-card')) {
                agregarProductoCard(this);
                cerrarModalFlotante();
            }
        };

        const btn = clone.querySelector('.btn-agregar-card');

        if (btn) {
            btn.onclick = function(e) {
                e.stopPropagation();
                agregarProductoCard(clone);
                cerrarModalFlotante();
            };
        }

        container.appendChild(clone);
    });

    const buscadorModal = document.getElementById('buscadorModal');
    const filtroModal = document.getElementById('filtroCategoriaModal');

    const filtrarModal = () => {
        const busqueda = buscadorModal ? buscadorModal.value.toLowerCase() : '';
        const categoria = filtroModal ? filtroModal.value.toLowerCase() : '';
        const productos = container.querySelectorAll('.producto-card');

        productos.forEach(p => {
            const nombre = (p.dataset.nombre || '').toLowerCase();
            const cat = (p.dataset.categoria || '').toLowerCase();
            const match = nombre.includes(busqueda) && (!categoria || cat === categoria);
            p.style.display = match ? '' : 'none';
        });

    };

    if (buscadorModal) buscadorModal.oninput = filtrarModal;
    if (filtroModal) filtroModal.onchange = filtrarModal;
}

// ============ FUNCIONES DE BÚSQUEDA DESKTOP ============
function filtrarProductos() {
    const buscador = document.getElementById('buscadorProductos');
    const filtro = document.getElementById('filtroCategoriaProductos');

    const busqueda = buscador ? buscador.value.toLowerCase() : '';
    const categoria = filtro ? filtro.value.toLowerCase() : '';
    const productos = document.querySelectorAll('#productosGrid .producto-card');

    productos.forEach(p => {
        const nombre = (p.dataset.nombre || '').toLowerCase();
        const cat = (p.dataset.categoria || '').toLowerCase();

        const matchBusqueda = nombre.includes(busqueda);
        const matchCategoria = !categoria || cat === categoria;

        p.style.display = matchBusqueda && matchCategoria ? '' : 'none';
    });

    actualizarDespuesDeFiltrar();
}

// ============ ICONOS ============
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

// ============ AGREGAR DESDE GRID ============
function agregarProductoCard(element) {
    const producto = {
        id: parseInt(element.dataset.id),
        nombre: element.dataset.nombre,
        precio: parseFloat(element.dataset.precio),
        stock: parseInt(element.dataset.stock),
        imagen: element.dataset.imagen || '',
        categoria: element.dataset.categoria || ''
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

    agregarAlCarrito(nuevoProducto);
    enfocarCodigo();
}

function agregarAlCarrito(producto) {
    const existente = carrito.find(p => p.id === producto.id);

    if (existente) {
        const nuevaCantidad = existente.cantidad + 1;

        if (nuevaCantidad <= producto.stock) {
            existente.cantidad++;

            Swal.fire({
                icon: 'success',
                title: 'Agregado',
                text: `${producto.nombre} x${existente.cantidad}`,
                toast: true,
                position: 'top-end',
                showConfirmButton: false,
                timer: 1200
            });

            guardarCarrito();
            renderCarrito();
        } else {
            Swal.fire({
                icon: 'warning',
                title: 'Stock insuficiente',
                text: `Solo hay ${producto.stock} disponibles`,
                toast: true,
                position: 'top-end',
                showConfirmButton: false,
                timer: 1800
            });
        }
    } else {
        if (producto.cantidad <= producto.stock) {
            carrito.push(producto);

            Swal.fire({
                icon: 'success',
                title: 'Agregado',
                text: `${producto.nombre} agregado`,
                toast: true,
                position: 'top-end',
                showConfirmButton: false,
                timer: 1200
            });

            guardarCarrito();
            renderCarrito();
        } else {
            Swal.fire({
                icon: 'warning',
                title: 'Sin stock',
                text: `No hay stock de ${producto.nombre}`,
                toast: true,
                position: 'top-end',
                showConfirmButton: false,
                timer: 1800
            });
        }
    }
}

// ============ AGREGAR POR CÓDIGO DE BARRAS ============
async function agregarProducto() {
    if (buscandoProducto) return;

    const inputCodigo = document.getElementById('codigo');
    const codigo = inputCodigo ? inputCodigo.value.trim() : '';

    if (!codigo) {
        enfocarCodigo();
        return;
    }

    buscandoProducto = true;

    try {
        const res = await fetch(`buscar_producto.php?codigo=${encodeURIComponent(codigo)}`);

        if (!res.ok) {
            throw new Error('Error HTTP: ' + res.status);
        }

        const data = await res.json();

        if (!data.success) {
            Swal.fire({
                icon: 'error',
                title: 'No encontrado',
                text: data.message || 'Producto no encontrado',
                toast: true,
                position: 'top-end',
                showConfirmButton: false,
                timer: 1800
            });

            if (inputCodigo) inputCodigo.value = '';
            enfocarCodigo();
            return;
        }

        const iconoData = getIconoPorCategoria(data.categoria, data.nombre);

        const producto = {
            id: parseInt(data.id),
            nombre: data.nombre,
            precio: parseFloat(data.precio_venta),
            cantidad: 1,
            stock: parseInt(data.stock),
            imagen: data.imagen || '',
            categoria: data.categoria || '',
            icono: iconoData.icono,
            iconoColor: iconoData.color
        };

        agregarAlCarrito(producto);

    } catch (error) {
        console.error('Error al buscar producto:', error);

        Swal.fire({
            icon: 'error',
            title: 'Error',
            text: 'Error al buscar el producto. Revisa buscar_producto.php.',
            confirmButtonColor: '#f97316'
        });
    } finally {
        buscandoProducto = false;

        if (inputCodigo) inputCodigo.value = '';
        enfocarCodigo();
    }
}

// ============ CARRITO ============
function renderCarrito() {
    const body = document.getElementById('carritoBody');

    if (!body) return;

    if (carrito.length === 0) {
        body.innerHTML = `
            <tr id="emptyCartRow">
                <td colspan="6" class="text-center py-4">
                    <i class="fas fa-shopping-cart fa-2x text-muted mb-2"></i>
                    <p class="text-muted mb-0 small">El carrito está vacío</p>
                </td>
            </tr>
        `;

        document.getElementById('total').value = '0.00';
        document.getElementById('cambio').value = '0.00';
        return;
    }

    let html = '';
    let total = 0;
    let contador = 1;

    carrito.forEach((item, index) => {
        const subtotal = item.precio * item.cantidad;
        total += subtotal;

        let imagenHtml = '';

        if (item.imagen && item.imagen !== '' && item.imagen !== 'uploads/noimage.png' && !item.imagen.includes('no-image')) {
            imagenHtml = `<img src="${item.imagen}" style="width: 32px; height: 32px; object-fit: cover; border-radius: 6px;">`;
        } else {
            const icono = item.icono || 'fas fa-box';
            imagenHtml = `
                <div style="width: 32px; height: 32px; display: flex; align-items: center; justify-content: center; background: #f8fafc; border-radius: 6px;">
                    <i class="${icono}" style="color: #f97316;"></i>
                </div>
            `;
        }

        html += `
            <tr>
                <td style="text-align: center; font-size: 12px;">${contador++}</td>
                <td>
                    <div style="display: flex; align-items: center; gap: 8px;">
                        ${imagenHtml}
                        <div>
                            <strong style="font-size: 12px;">${escapeHtml(item.nombre)}</strong>
                            <br>
                            <small style="font-size: 9px; color: #64748b;">Stock: ${item.stock}</small>
                        </div>
                    </div>
                </td>
                <td style="text-align: center;">
                    <input type="number" class="cantidad-input" value="${item.cantidad}"
                           min="1" max="${item.stock}"
                           onchange="actualizarCantidad(${index}, this.value)">
                </td>
                <td style="text-align: center;"><strong>$${item.precio.toFixed(2)}</strong></td>
                <td style="text-align: center;"><strong style="color: #16a34a;">$${subtotal.toFixed(2)}</strong></td>
                <td style="text-align: center;">
                    <button type="button" class="btn-eliminar" onclick="eliminarProducto(${index})" title="Eliminar">
                        <i class="fas fa-trash-alt"></i>
                    </button>
                </td>
            </tr>
        `;
    });

    body.innerHTML = html;
    document.getElementById('total').value = total.toFixed(2);
    calcularCambio();
}

function guardarCarrito() {
    $.ajax({
        url: 'ajax/guardar_carrito.php',
        method: 'POST',
        data: {
            carrito: JSON.stringify(carrito)
        },
        async: false
    });

    guardarVentaActivaLocal();
}

function actualizarCantidad(index, valor) {
    const cantidad = parseInt(valor);

    if (isNaN(cantidad) || cantidad < 1) {
        renderCarrito();
        return;
    }

    if (cantidad > carrito[index].stock) {
        Swal.fire({
            icon: 'warning',
            title: 'Stock insuficiente',
            text: `Solo hay ${carrito[index].stock} unidades`,
            toast: true,
            position: 'top-end',
            showConfirmButton: false,
            timer: 1800
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
        title: 'Eliminar',
        text: `¿Quitar ${carrito[index].nombre}?`,
        icon: 'question',
        showCancelButton: true,
        confirmButtonColor: '#f97316',
        cancelButtonColor: '#6c757d',
        confirmButtonText: 'Sí',
        cancelButtonText: 'No'
    }).then(result => {
        if (result.isConfirmed) {
            carrito.splice(index, 1);
            guardarCarrito();
            renderCarrito();

            Swal.fire({
                icon: 'success',
                title: 'Eliminado',
                text: 'Producto eliminado',
                toast: true,
                position: 'top-end',
                showConfirmButton: false,
                timer: 1200
            });
        }

        enfocarCodigo();
    });
}

function calcularCambio() {
    const total = parseFloat(document.getElementById('total').value) || 0;
    const pago = parseFloat(document.getElementById('monto_pagado').value) || 0;
    const cambio = pago - total;

    const cambioInput = document.getElementById('cambio');

    if (cambioInput) {
        cambioInput.value = cambio.toFixed(2);
        cambioInput.style.color = cambio < 0 ? '#ef4444' : '#16a34a';
    }
}

// ============ MÉTODOS DE PAGO ============
function mostrarCamposPago(mantenerScroll = false) {
    const scrollActual = mantenerScroll ? obtenerScrollActual() : null;
    const metodo = document.querySelector('input[name="metodo_pago"]:checked')?.value;
    const extra = document.getElementById('extraCampos');

    if (!metodo || !extra) return;

    let html = '';

    switch (metodo) {
        case 'efectivo':
            html = `
                <div class="alert alert-success mb-0" style="background: #dcfce7; border: 1px solid #86efac; border-radius: 16px; color: #166534;">
                    <div style="display: flex; align-items: center; gap: 12px; padding: 8px 0;">
                        <i class="fas fa-money-bill-wave" style="font-size: 22px; color: #16a34a;"></i>
                        <div>
                            <strong style="font-size: 14px; color: #166534;">Pago en efectivo</strong>
                            <p style="font-size: 12px; color: #6b7280; margin: 0;">No requiere referencia</p>
                        </div>
                    </div>
                </div>
            `;
            break;

        case 'transferencia':
            html = `
                <div class="form-group mb-3">
                    <label><i class="fas fa-hashtag me-1"></i> Folio de transferencia *</label>
                    <input type="text" class="form-control pos-input" name="referencia_pago" id="folio_transferencia" required placeholder="Ej: TRX87439210" maxlength="20" oninput="formatearFolioTransferencia(this); guardarVentaActivaLocal();">
                    <small class="text-muted">Máx. 20 caracteres</small>
                </div>
                <div class="text-center mb-2">
                    <button type="button" class="btn btn-sm btn-outline-primary" onclick="mostrarDatosBancarios()" style="border-radius: 12px;">
                        <i class="fas fa-university"></i> Datos bancarios
                    </button>
                </div>
            `;
            break;

        case 'tarjeta_debito':
        case 'tarjeta_credito':
            html = `
                <div class="alert mb-3" style="background:#eef6ff; border:1px solid #93c5fd; border-radius:16px; color:#1e3a8a;">
                    <div style="display:flex; gap:12px; align-items:flex-start;">
                        <i class="fas fa-credit-card" style="font-size:22px; margin-top:3px;"></i>
                        <div>
                            <strong style="font-size:14px;">Cobro con terminal Mercado Pago</strong>
                            <p style="font-size:12px; margin:2px 0 0;">
                                Al confirmar, el total se enviará a la terminal. La venta se registrará solo cuando el pago sea aprobado.
                            </p>
                        </div>
                    </div>
                </div>
            `;
            break;
    }

    extra.innerHTML = html;

    if (mantenerScroll) {
        restaurarScroll(scrollActual);
    }
}

function formatearFolioTransferencia(input) {
    let valor = input.value.toUpperCase().replace(/[^A-Z0-9]/g, "");
    input.value = valor.substring(0, 20);
}

function validarFolio(input) {
    let valor = input.value.toUpperCase().replace(/[^A-Z0-9]/g, "");
    input.value = valor.substring(0, 16);
}

function detectarTipoTarjeta() {
    const ultimos4 = document.getElementById('ultimos4')?.value || '';
    const tipoInput = document.getElementById('tipo_tarjeta');
    const oculto = document.getElementById('tipo_tarjeta_detectada');

    if (!tipoInput || !oculto) return;

    if (ultimos4.length < 1) {
        tipoInput.value = '';
        oculto.value = '';
        return;
    }

    let tipo = "OTRA";
    let textoMostrar = "OTRA";

    if (/^4/.test(ultimos4)) {
        tipo = "VISA";
        textoMostrar = "VISA";
    } else if (/^(5[1-5]|22[2-9]|2[3-7])/.test(ultimos4)) {
        tipo = "MASTERCARD";
        textoMostrar = "MASTERCARD";
    } else if (/^(34|37)/.test(ultimos4)) {
        tipo = "AMEX";
        textoMostrar = "AMEX";
    }

    tipoInput.value = textoMostrar;
    oculto.value = tipo;
}


// ============ MERCADO PAGO POINT ============
function limpiarDatosMercadoPago() {
    ['mp_order_id', 'mp_payment_id', 'mp_payment_status', 'mp_payment_status_detail', 'mp_payment_method_id'].forEach(id => {
        const el = document.getElementById(id);
        if (el) el.value = '';
    });
}

function setDatosMercadoPago(order) {
    const pago = order?.transactions?.payments?.[0] || {};

    const orderId = document.getElementById('mp_order_id');
    const paymentId = document.getElementById('mp_payment_id');
    const status = document.getElementById('mp_payment_status');
    const detail = document.getElementById('mp_payment_status_detail');
    const method = document.getElementById('mp_payment_method_id');

    if (orderId) orderId.value = order?.id || '';
    if (paymentId) paymentId.value = pago?.reference_id || pago?.id || '';
    if (status) status.value = pago?.status || order?.status || '';
    if (detail) detail.value = pago?.status_detail || order?.status_detail || '';
    if (method) method.value = pago?.payment_method?.id || pago?.payment_method?.type || '';
}

async function mpPost(url, payload) {
    const response = await fetch(url, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(payload || {})
    });

    const data = await response.json().catch(() => ({}));

    if (!response.ok || !data.success) {
        throw new Error(data.message || 'Error al conectar con Mercado Pago.');
    }

    return data;
}

async function esperarPagoMercadoPago(orderId) {
    const inicio = Date.now();
    const tiempoMaximoMs = 180000; // 3 minutos
    const pausa = ms => new Promise(resolve => setTimeout(resolve, ms));

    while ((Date.now() - inicio) < tiempoMaximoMs) {
        const data = await mpPost('ajax/mercadopago_consultar_orden.php', { order_id: orderId });
        const order = data.order || {};
        const pago = order?.transactions?.payments?.[0] || {};
        const estadoOrden = order.status || '';
        const estadoPago = pago.status || '';

        Swal.update({
            title: 'Esperando pago en terminal',
            html: `
                <div style="text-align:center;">
                    <p>Orden enviada a Mercado Pago.</p>
                    <p><strong>${escapeHtml(orderId)}</strong></p>
                    <p>Estado: <strong>${escapeHtml(estadoPago || estadoOrden || 'pendiente')}</strong></p>
                    <small>Realiza el cobro en la terminal. No cierres esta ventana.</small>
                </div>
            `
        });

        if (['processed', 'approved'].includes(estadoPago) || ['processed', 'paid'].includes(estadoOrden)) {
            setDatosMercadoPago(order);
            return order;
        }

        if (['canceled', 'cancelled', 'expired', 'failed', 'rejected'].includes(estadoPago) || ['canceled', 'cancelled', 'expired', 'failed', 'rejected'].includes(estadoOrden)) {
            throw new Error('El pago fue cancelado, rechazado o expiró en Mercado Pago.');
        }

        await pausa(3000);
    }

    throw new Error('No se confirmó el pago en la terminal dentro del tiempo permitido.');
}

async function procesarPagoMercadoPago(total, metodo) {
    limpiarDatosMercadoPago();

    Swal.fire({
        title: 'Enviando cobro a terminal',
        html: 'Preparando orden en Mercado Pago...',
        allowOutsideClick: false,
        allowEscapeKey: false,
        didOpen: () => Swal.showLoading()
    });

    const data = await mpPost('ajax/mercadopago_crear_orden.php', {
        total: total.toFixed(2),
        metodo_pago: metodo,
        carrito: carrito
    });

    const orderId = data.order_id;
    if (!orderId) {
        throw new Error('Mercado Pago no devolvió el ID de la orden.');
    }

    return await esperarPagoMercadoPago(orderId);
}


// ============ TICKET / MENSAJE FINAL DE VENTA ============
function formatearDinero(valor) {
    const numero = Number(valor);
    return Number.isFinite(numero) ? numero.toFixed(2) : '0.00';
}

function obtenerMetodoPagoTexto(metodo) {
    switch (metodo) {
        case 'efectivo':
            return 'EFECTIVO';
        case 'transferencia':
            return 'TRANSFERENCIA';
        case 'tarjeta_debito':
            return 'TARJETA DÉBITO';
        case 'tarjeta_credito':
            return 'TARJETA CRÉDITO';
        default:
            return metodo ? String(metodo).toUpperCase() : 'NO CAPTURADO';
    }
}

function construirHtmlVentaRegistrada(data) {
    const productos = Array.isArray(data?.productos) ? data.productos : [];
    const total = Number(data?.total || 0);
    const pago = Number(data?.monto_pagado || 0);
    const cambio = Number(data?.cambio || 0);
    const metodo = data?.metodo_pago || '';
    const metodoTexto = obtenerMetodoPagoTexto(metodo);
    const fecha = data?.fecha || new Date().toLocaleString();
    const folio = data?.folio || 'Sin folio';
    const correoCliente = data?.correo_cliente || '';
    const ticketEnviado = data?.ticket_enviado === true;

    const ticketItems = productos.length > 0
        ? productos.map(item => {
            const cantidad = Number(item?.cantidad || 0);
            const precio = Number(item?.precio || 0);
            const importe = Number(item?.importe ?? (precio * cantidad));
            const nombre = item?.nombre || 'Producto';

            return `
                <div style="display:flex; justify-content:space-between; font-size:13px; margin-bottom:6px; gap:12px;">
                    <span style="color:#334155; text-align:left;">${escapeHtml(nombre)} x${cantidad}</span>
                    <span style="font-weight:700; color:#f97316; white-space:nowrap;">$${formatearDinero(importe)}</span>
                </div>
            `;
        }).join('')
        : `
            <div style="text-align:center; color:#64748b; font-size:13px; padding:8px 0;">
                Venta registrada correctamente.
            </div>
        `;

    return `
        <div style="background:#ffffff; border-radius:20px; padding:20px; max-width:420px; margin:0 auto;">
            <div style="text-align:center; border-bottom:2px solid #e5e7eb; padding-bottom:12px; margin-bottom:16px;">
                <div style="width:74px; height:74px; border-radius:50%; border:4px solid #dcfce7; display:flex; align-items:center; justify-content:center; margin:0 auto 14px;">
                    <i class="fas fa-check" style="font-size:34px; color:#16a34a;"></i>
                </div>
                <div style="font-size:23px; font-weight:900; color:#3f3f46; margin-bottom:12px;">Venta registrada</div>
                <div style="font-size:16px; font-weight:900; color:#1e293b;">TIENDA PESCADORES</div>
                <div style="font-size:10px; color:#6b7280;">${escapeHtml(fecha)}</div>
                <div style="font-size:10px; color:#94a3b8; margin-top:4px;">Folio: <strong style="color:#334155;">${escapeHtml(folio)}</strong></div>
            </div>

            <div style="margin-bottom:16px; max-height:200px; overflow-y:auto;">
                <div style="display:flex; justify-content:space-between; font-size:11px; color:#9ca3af; border-bottom:1px solid #e5e7eb; padding-bottom:6px; margin-bottom:8px;">
                    <span>PRODUCTO</span>
                    <span>IMPORTE</span>
                </div>
                ${ticketItems}
            </div>

            <div style="background:#f8fafc; border-radius:12px; padding:12px; margin-bottom:16px;">
                <div style="display:flex; justify-content:space-between; font-size:13px; margin-bottom:8px;">
                    <span style="color:#475569;">TOTAL</span>
                    <span style="font-weight:800;">$${formatearDinero(total)}</span>
                </div>
                <div style="display:flex; justify-content:space-between; font-size:13px;">
                    <span style="color:#475569;">PAGO CON</span>
                    <span style="font-weight:800;">$${formatearDinero(pago)}</span>
                </div>
            </div>

            ${
                metodo === 'efectivo'
                    ? `
                        <div style="background:#16a34a; border-radius:16px; padding:20px; text-align:center; margin-bottom:16px; box-shadow:0 12px 28px rgba(22,163,74,.20);">
                            <div style="font-size:14px; color:white; opacity:0.92; margin-bottom:8px;">SU CAMBIO</div>
                            <div style="font-size:42px; font-weight:900; color:white;">$${formatearDinero(cambio)}</div>
                            <div style="font-size:11px; color:white; opacity:0.78; margin-top:8px;">Entregue esta cantidad al cliente</div>
                        </div>
                    `
                    : ''
            }

            <div style="display:flex; justify-content:space-between; background:#f1f5f9; border-radius:10px; padding:10px 12px; margin-bottom:10px;">
                <span style="color:#475569;">MÉTODO DE PAGO</span>
                <span style="font-weight:800;">${escapeHtml(metodoTexto)}</span>
            </div>

            ${
                correoCliente
                    ? `
                        <div style="background:${ticketEnviado ? '#ecfdf5' : '#fff7ed'}; border:1px solid ${ticketEnviado ? '#bbf7d0' : '#fed7aa'}; color:${ticketEnviado ? '#15803d' : '#9a3412'}; border-radius:10px; padding:10px; font-size:13px; font-weight:700; line-height:1.45;">
                            ${ticketEnviado
                                ? `Ticket enviado correctamente<br><span style="font-weight:600;">${escapeHtml(correoCliente)}</span>`
                                : `Venta registrada, pero no se pudo enviar el ticket al correo capturado.`}
                        </div>
                    `
                    : `
                        <div style="background:#f8fafc; border:1px solid #e2e8f0; color:#64748b; border-radius:10px; padding:10px; font-size:13px;">
                            No se capturó correo. Solo se registró la venta.
                        </div>
                    `
            }
        </div>
    `;
}


// ============ CONFIRMAR VENTA ============
async function confirmarVenta() {
    if (ventaEnProceso) {
        Swal.fire({
            icon: 'info',
            title: 'Procesando venta',
            text: 'Por favor espera...',
            toast: true,
            position: 'top-end',
            showConfirmButton: false,
            timer: 1800
        });
        return;
    }

    if (!Array.isArray(carrito) || carrito.length === 0) {
        Swal.fire({
            icon: 'warning',
            title: 'Carrito vacío',
            text: 'Agrega productos antes de continuar',
            confirmButtonColor: '#f97316',
            confirmButtonText: 'Entendido'
        });
        return;
    }

    const total = parseFloat(document.getElementById('total')?.value) || 0;
    const pago = parseFloat(document.getElementById('monto_pagado')?.value) || 0;
    const metodo = document.querySelector('input[name="metodo_pago"]:checked')?.value;
    const correoCliente = document.getElementById('correo_cliente')?.value.trim() || '';

    if (!metodo) {
        Swal.fire({
            icon: 'warning',
            title: 'Método de pago requerido',
            text: 'Selecciona un método de pago para continuar.',
            confirmButtonColor: '#f97316',
            confirmButtonText: 'Entendido'
        });
        return;
    }

    if (!pago || pago <= 0) {
        Swal.fire({
            icon: 'warning',
            title: 'Monto inválido',
            text: 'Ingresa un monto válido',
            confirmButtonColor: '#f97316',
            confirmButtonText: 'Corregir'
        }).then(() => {
            document.getElementById('monto_pagado')?.focus();
        });
        return;
    }

    if (pago < total) {
        Swal.fire({
            icon: 'error',
            title: 'Monto insuficiente',
            text: `Faltan $${(total - pago).toFixed(2)} para completar la venta`,
            confirmButtonColor: '#f97316',
            confirmButtonText: 'Aumentar pago'
        }).then(() => {
            document.getElementById('monto_pagado')?.focus();
        });
        return;
    }

    if (metodo === 'transferencia') {
        const folio = document.getElementById('folio_transferencia')?.value.trim();

        if (!folio || folio.length < 5) {
            Swal.fire({
                icon: 'warning',
                title: 'Folio requerido',
                text: 'Ingresa el folio de la transferencia',
                confirmButtonColor: '#f97316',
                confirmButtonText: 'Completar'
            }).then(() => {
                document.getElementById('folio_transferencia')?.focus();
            });
            return;
        }
    }

    ventaEnProceso = true;

    const btn = document.getElementById('btnConfirmar');
    if (btn) {
        btn.disabled = true;
        btn.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i> Procesando...';
    }

    try {
        guardarCarrito();

        if (metodo === 'tarjeta_debito' || metodo === 'tarjeta_credito') {
            await procesarPagoMercadoPago(total, metodo);
        } else {
            limpiarDatosMercadoPago();
        }

        const carritoJson = document.getElementById('carrito_json');
        if (carritoJson) {
            carritoJson.value = JSON.stringify(carrito);
        }

        const audio = document.getElementById('sonidoCaja');
        if (audio) {
            audio.play().catch(e => console.log('Audio error:', e));
        }

        limpiarVentaActivaLocal();
        document.getElementById('ventaForm')?.submit();
    } catch (error) {
        Swal.close();
        ventaEnProceso = false;
        resetBotonVenta();

        Swal.fire({
            icon: 'error',
            title: 'Error al procesar',
            text: error.message || 'Ocurrió un error inesperado',
            confirmButtonColor: '#f97316'
        });
    }
}

// ============ DATOS BANCARIOS ============
function mostrarDatosBancarios() {
    const datosQR = `4152314179374577`;

    Swal.fire({
        title: '',
        html: `
            <section class="bank-neo-stage bank-neo-stage-clean" id="bankPrintableArea">
                <div class="bank-soft-blob bank-soft-blob-one"></div>
                <div class="bank-soft-blob bank-soft-blob-two"></div>
                <div class="bank-soft-grid"></div>

                <div class="bank-neo-layout">
                    <article class="bank-card-3d bank-card-real-ratio" id="bankCard3D">
                        <div class="bank-card-ambient ambient-one"></div>
                        <div class="bank-card-ambient ambient-two"></div>
                        <div class="bank-card-sheen"></div>
                        <div class="bank-card-noise"></div>

                        <div class="bank-card-top">
                            <div class="bank-brand-block">
                                <span class="bank-brand-kicker">Tarjeta de depósito</span>
                                <img src="img/bbva-logo.png" alt="BBVA" class="bank-logo-img" onerror="this.outerHTML='<strong class=&quot;bank-logo-text&quot;>BBVA</strong>'">
                            </div>

                            <div class="bank-card-badge">
                                <span></span>
                                <strong>TRANSFERENCIA</strong>
                            </div>
                        </div>

                        <div class="bank-card-mid">
                            <div class="chip-neo" aria-hidden="true">
                                <span></span>
                                <span></span>
                                <span></span>
                                <span></span>
                            </div>

                            <div class="contactless-neo" aria-hidden="true">
                                <i class="fas fa-wifi"></i>
                            </div>
                        </div>

                        <div class="bank-card-number" aria-label="Número de tarjeta">
                            <span>4152</span>
                            <span>3141</span>
                            <span>7937</span>
                            <span>4577</span>
                        </div>

                        <div class="bank-card-bottom">
                            <div>
                                <small>TITULAR</small>
                                <strong>KARMINA ARANGUTHY GARCIA</strong>
                            </div>
                            <div>
                                <small>VÁLIDA HASTA</small>
                                <strong>04/32</strong>
                            </div>
                            <div class="visa-neo">
                                <img src="https://cdn.simpleicons.org/visa/ffffff" alt="VISA" onerror="this.outerHTML='<b>VISA</b>'">
                            </div>
                        </div>
                    </article>

                    <aside class="bank-pay-panel bank-glass-panel">
                        <div class="panel-glow"></div>

                        <header class="bank-panel-header">
                            <div class="panel-icon-ring">
                                <i class="fas fa-qrcode"></i>
                            </div>
                            <div>
                                <h3>Escanea para transferir</h3>
                                <p>Datos bancarios listos para compartir o imprimir.</p>
                            </div>
                        </header>

                        <div class="qr-premium-shell qr-premium-clean">
                            <div class="qr-corner corner-a"></div>
                            <div class="qr-corner corner-b"></div>
                            <div class="qr-corner corner-c"></div>
                            <div class="qr-corner corner-d"></div>
                            <div id="qrContainerFinal" class="qr-big-container"></div>
                        </div>

                        <div class="bank-data-list">
                            <button type="button" class="bank-data-item" onclick="copyToClipboard('4152 3141 7937 4577')">
                                <span class="data-icon"><i class="fas fa-credit-card"></i></span>
                                <span class="data-text">
                                    <small>Número de tarjeta</small>
                                    <strong>4152 3141 7937 4577</strong>
                                </span>
                                <i class="fas fa-copy copy-icon"></i>
                            </button>

                            <button type="button" class="bank-data-item" onclick="copyToClipboard('KARMINA ARANGUTHY GARCIA')">
                                <span class="data-icon"><i class="fas fa-user"></i></span>
                                <span class="data-text">
                                    <small>Titular</small>
                                    <strong>KARMINA ARANGUTHY GARCIA</strong>
                                </span>
                                <i class="fas fa-copy copy-icon"></i>
                            </button>
                        </div>

                        <div class="bank-actions-row bank-actions-single">
                            <button type="button" class="bank-print-btn bank-print-btn-clean" onclick="imprimirTarjetaQR()">
                                <i class="fas fa-print"></i>
                                Imprimir datos
                            </button>
                        </div>
                    </aside>
                </div>

                <footer class="bank-print-footer">
                    <strong>Datos para transferencia</strong>
                    <span>Presente esta hoja al cliente o escanee el código QR.</span>
                </footer>
            </section>
        `,
        showConfirmButton: true,
        confirmButtonText: 'Cerrar',
        confirmButtonColor: '#f97316',
        background: 'transparent',
        width: '1120px',
        padding: '0',
        customClass: {
            popup: 'swal-final-popup bank-swal-popup bank-swal-clean'
        },
        didOpen: () => {
            setTimeout(() => {
                const qrContainer = document.getElementById("qrContainerFinal");

                if (qrContainer && typeof QRCode !== 'undefined') {
                    qrContainer.innerHTML = '';

                    new QRCode(qrContainer, {
                        text: datosQR,
                        width: 210,
                        height: 210,
                        colorDark: "#0f172a",
                        colorLight: "#ffffff",
                        correctLevel: QRCode.CorrectLevel.H
                    });
                }

                const card = document.getElementById('bankCard3D');
                const stage = document.querySelector('.bank-neo-stage');

                if (card && stage && window.matchMedia('(hover: hover)').matches) {
                    stage.addEventListener('mousemove', (e) => {
                        const rect = card.getBoundingClientRect();
                        const x = e.clientX - rect.left;
                        const y = e.clientY - rect.top;
                        const rotateY = ((x / rect.width) - 0.5) * 10;
                        const rotateX = ((0.5 - (y / rect.height)) * 8);

                        card.style.setProperty('--rx', `${rotateX}deg`);
                        card.style.setProperty('--ry', `${rotateY}deg`);
                        card.style.setProperty('--mx', `${x}px`);
                        card.style.setProperty('--my', `${y}px`);
                    });

                    stage.addEventListener('mouseleave', () => {
                        card.style.setProperty('--rx', '0deg');
                        card.style.setProperty('--ry', '0deg');
                        card.style.setProperty('--mx', '50%');
                        card.style.setProperty('--my', '50%');
                    });
                }
            }, 100);
        }
    });
}
function copyToClipboard(texto) {
    navigator.clipboard.writeText(texto).then(() => {
        Swal.fire({
            icon: 'success',
            title: 'Copiado',
            text: 'Dato copiado',
            toast: true,
            position: 'top-end',
            showConfirmButton: false,
            timer: 1500
        });
    });
}

function copiarTexto(texto) {
    copyToClipboard(texto);
}

function imprimirTarjetaQR() {
    const contenedor = document.querySelector('#bankPrintableArea');

    if (!contenedor) {
        Swal.fire({
            icon: 'warning',
            title: 'No disponible',
            text: 'Abre primero los datos bancarios para poder imprimirlos.',
            confirmButtonColor: '#f97316'
        });
        return;
    }

    window.print();
}
// ============ AJUSTE DE PRODUCTOS ============
function ajustarUnaFilaMas() {
    const cardBody = document.querySelector('.productos-desktop .card-body');
    const grid = document.querySelector('.productos-grid');

    if (cardBody && grid && window.innerWidth >= 992) {
        const primerProducto = grid.querySelector('.producto-card');

        if (primerProducto) {
            const altoProducto = primerProducto.offsetHeight || 120;
            const gap = 10;
            const productosVisibles = grid.querySelectorAll('.producto-card:not([style*="display: none"])');
            const productosPorFila = Math.max(1, Math.floor(grid.offsetWidth / 130));
            const filasActuales = Math.ceil(productosVisibles.length / productosPorFila);
            const filasMostrar = filasActuales + 1;
            const alturaMostrar = filasMostrar * (altoProducto + gap) + 30;

            cardBody.style.maxHeight = alturaMostrar + 'px';
            cardBody.style.overflowY = 'auto';
            grid.style.overflowY = 'visible';
            cardBody.style.scrollBehavior = 'smooth';
        }
    }
}

function actualizarDespuesDeFiltrar() {
    setTimeout(ajustarUnaFilaMas, 50);
}


// ============ CAJÓN LOCAL ============
function mostrarAlertaCajonError(mensaje) {
    Swal.fire({
        icon: 'warning',
        title: 'Cajón no abierto',
        html: `
            <div style="text-align:left; line-height:1.5;">
                La venta sí se guardó correctamente, pero no se pudo abrir el cajón local.<br><br>
                ${escapeHtml(mensaje || 'Revisa que el servicio local esté abierto y que el puerto sea COM3.')}<br><br>
                Verifica que en la PC del cajero esté abierto el archivo:<br>
                <b>iniciar-cajon.bat</b><br><br>
                Servicio esperado:<br>
                <code>http://127.0.0.1:8787/abrir-cajon</code>
            </div>
        `,
        confirmButtonColor: '#f97316',
        confirmButtonText: 'Entendido'
    });
}

async function abrirCajonLocalEnSegundoPlano(mostrarAlerta = true) {
    try {
        const res = await fetch(CAJON_LOCAL_URL, {
            method: 'POST',
            mode: 'cors',
            cache: 'no-store'
        });

        let data = null;
        try {
            data = await res.json();
        } catch (e) {
            data = null;
        }

        if (!res.ok || !data || data.ok !== true) {
            const detalle = data?.message || `HTTP ${res.status}`;
            console.warn('No se pudo abrir el cajón local:', detalle);

            if (mostrarAlerta) {
                mostrarAlertaCajonError(detalle);
            }

            return {
                ok: false,
                message: detalle
            };
        }

        console.log('Cajón abierto:', data.message || 'OK');
        return {
            ok: true,
            message: data.message || 'Cajón abierto correctamente.'
        };
    } catch (error) {
        console.error('Servicio local del cajón no disponible:', error);

        const mensaje = error?.message || 'No se encontró el servicio local del cajón.';

        if (mostrarAlerta) {
            mostrarAlertaCajonError(mensaje);
        }

        return {
            ok: false,
            message: mensaje
        };
    }
}


function manejarEnterConfirmarVenta(e) {
    if (e.key !== 'Enter') return;
    if (e.ctrlKey || e.altKey || e.metaKey) return;
    if (ventaEnProceso || buscandoProducto) return;
    if (estaDentroDeSweetAlert(e.target)) return;
    if (document.getElementById('modalFlotante')?.classList.contains('active')) return;

    const target = e.target;
    const tag = (target?.tagName || '').toLowerCase();
    const id = target?.id || '';

    if (tag === 'textarea') return;

    if (tag === 'button') {
        if (id === 'btnConfirmar') {
            e.preventDefault();
            e.stopPropagation();
            confirmarVenta();
        }
        return;
    }

    if (id === 'buscadorProductos' || id === 'buscadorModal') {
        return;
    }

    if (target?.classList?.contains('cantidad-input')) {
        target.dispatchEvent(new Event('change', { bubbles: true }));
    }

    if (id === 'codigo') {
        const codigo = target.value.trim();
        if (codigo !== '') return;

        if (Array.isArray(carrito) && carrito.length > 0) {
            e.preventDefault();
            e.stopPropagation();
            confirmarVenta();
        }
        return;
    }

    if ((target?.closest && target.closest('#ventaForm')) || !esElementoEditable(target)) {
        if (Array.isArray(carrito) && carrito.length > 0) {
            e.preventDefault();
            e.stopPropagation();
            confirmarVenta();
        }
    }
}

// ============ EVENTOS ============
document.addEventListener('DOMContentLoaded', function() {
    if (ALERTA_SESION) {
        const mensaje = ALERTA_SESION.mensaje || '';
        const esSuccess = (ALERTA_SESION.tipo || 'success') === 'success';
        const abrirCajonLocal = ALERTA_SESION.abrir_cajon_local === true;
        let cajonPromise = null;

        if (esSuccess && abrirCajonLocal) {
            cajonPromise = abrirCajonLocalEnSegundoPlano(false);
        }

        if (esSuccess) {
            limpiarVentaActivaLocal();

            Swal.fire({
                html: construirHtmlVentaRegistrada(ALERTA_SESION),
                width: '520px',
                confirmButtonText: 'Entendido',
                confirmButtonColor: '#16a34a',
                allowOutsideClick: false,
                allowEscapeKey: false
            }).then(async () => {
                if (cajonPromise) {
                    const resultadoCajon = await cajonPromise;
                    if (!resultadoCajon.ok) {
                        mostrarAlertaCajonError(resultadoCajon.message);
                    }
                }
            });
        } else {
            Swal.fire({
                icon: ALERTA_SESION.tipo || 'error',
                title: ALERTA_SESION.titulo || 'Aviso',
                html: `
                    <div style="text-align:center; padding:4px 8px;">
                        <div style="font-size:14px; color:#475569; line-height:1.6; white-space:pre-line;">
                            ${escapeHtml(mensaje)}
                        </div>
                    </div>
                `,
                width: '440px',
                confirmButtonText: 'Entendido',
                confirmButtonColor: '#f97316',
                allowOutsideClick: false,
                allowEscapeKey: false
            });
        }
    }

    renderCarrito();
    mostrarCamposPago();
    restaurarVentaActivaLocal();
    enfocarCodigo();
    document.addEventListener('keydown', manejarTeclaGlobalScanner, true);
    document.addEventListener('keydown', manejarEnterConfirmarVenta, true);

    const modalFlotante = document.getElementById('modalFlotante');

    if (modalFlotante) {
        modalFlotante.addEventListener('click', function(e) {
            if (e.target === this) cerrarModalFlotante();
        });
    }

    const buscador = document.getElementById('buscadorProductos');
    const filtro = document.getElementById('filtroCategoriaProductos');

    if (buscador) {
        buscador.addEventListener('input', filtrarProductos);
        buscador.addEventListener('blur', () => programarEnfoqueEscaner(150));
    }

    if (filtro) {
        filtro.addEventListener('change', function() {
            filtrarProductos();
            programarEnfoqueEscaner(250, true);
        });
        filtro.addEventListener('blur', () => programarEnfoqueEscaner(150));
    }

    const inputCodigo = document.getElementById('codigo');

    if (inputCodigo) {
        inputCodigo.addEventListener('keydown', function(e) {
            if (e.key === 'Enter' || e.key === 'Tab') {
                e.preventDefault();
                clearTimeout(timerCodigo);

                const codigoActual = this.value.trim();

                if (codigoActual !== '') {
                    agregarProducto();
                    return;
                }

                if (Array.isArray(carrito) && carrito.length > 0) {
                    confirmarVenta();
                }
            }
        });

        inputCodigo.addEventListener('input', function() {
            clearTimeout(timerCodigo);

            timerCodigo = setTimeout(() => {
                const codigo = inputCodigo.value.trim();

                if (codigo.length >= 4 && !buscandoProducto) {
                    agregarProducto();
                }
            }, 500);
        });
    }

    const montoPagado = document.getElementById('monto_pagado');
    if (montoPagado) {
        montoPagado.addEventListener('input', calcularCambio);
        montoPagado.addEventListener('input', guardarVentaActivaLocal);
        montoPagado.addEventListener('blur', () => programarEnfoqueEscaner(150));
    }

    const correoCliente = document.getElementById('correo_cliente');
    if (correoCliente) {
        correoCliente.addEventListener('input', guardarVentaActivaLocal);
        correoCliente.addEventListener('blur', () => programarEnfoqueEscaner(150));
    }

    const extraCampos = document.getElementById('extraCampos');
    if (extraCampos) {
        extraCampos.addEventListener('input', guardarVentaActivaLocal);
        extraCampos.addEventListener('focusout', () => programarEnfoqueEscaner(150));
    }

    const ventaForm = document.getElementById('ventaForm');

    if (ventaForm) {
        ventaForm.addEventListener('submit', function() {
            document.getElementById('carrito_json').value = JSON.stringify(carrito);
        });
    }

    const metodos = document.querySelectorAll('.metodo-radio');

    metodos.forEach(metodo => {
        metodo.addEventListener('click', function(e) {
            e.preventDefault();

            const scrollActual = obtenerScrollActual();

            metodos.forEach(el => el.classList.remove('selected'));
            this.classList.add('selected');

            const radio = this.querySelector('input[type="radio"]');

            if (radio) {
                radio.checked = true;
            }

            mostrarCamposPago(true);
            guardarVentaActivaLocal();

            // Regresamos al modo escáner sin mover la pantalla.
            restaurarScroll(scrollActual);
            programarEnfoqueEscaner(350, true);
        });

        const radio = metodo.querySelector('input[type="radio"]');

        if (radio && radio.checked) {
            metodo.classList.add('selected');
        }
    });

    ajustarUnaFilaMas();
    ventaEnProceso = false;
    resetBotonVenta();
});

window.addEventListener('resize', function() {
    setTimeout(ajustarUnaFilaMas, 100);
});
</script>