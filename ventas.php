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
            <input type="number" class="form-control form-control-sm pago-numero" name="monto_pagado" id="monto_pagado" step="0.01" min="0" placeholder="0.00" oninput="calcularCambio()">
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
let ventaEnProceso = false;
let buscandoProducto = false;
let timerCodigo = null;

// ============ UTILIDADES ============
function escapeHtml(text) { 
    const div = document.createElement('div'); 
    div.textContent = text ?? ''; 
    return div.innerHTML; 
}

function enfocarCodigo() {
    const input = document.getElementById('codigo');
    if (input) {
        setTimeout(() => {
            input.focus();
            input.select();
        }, 80);
    }
}

function resetBotonVenta() {
    const btn = document.getElementById('btnConfirmar');
    if (btn) {
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-check-circle me-2"></i> Confirmar venta';
    }
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
function mostrarCamposPago() {
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
                    <input type="text" class="form-control pos-input" name="referencia_pago" id="folio_transferencia" required placeholder="Ej: TRX87439210" maxlength="20" oninput="formatearFolioTransferencia(this)">
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
                <div class="campos-tarjeta">
                    <div class="form-group">
                        <label><i class="fas fa-credit-card me-1"></i> Últimos 4 dígitos *</label>
                        <input type="text" class="form-control pos-input" id="ultimos4" name="ultimos4" maxlength="4" required placeholder="Ej: 4921" oninput="this.value = this.value.replace(/\\D/g,''); detectarTipoTarjeta();">
                    </div>
                    <div class="form-group">
                        <label><i class="fas fa-tag me-1"></i> Tipo</label>
                        <input type="text" class="form-control pos-input" id="tipo_tarjeta" name="tipo_tarjeta" readonly placeholder="Detectado..." style="background-color: #f8fafc;">
                        <input type="hidden" name="tipo_tarjeta_detectada" id="tipo_tarjeta_detectada">
                    </div>
                    <div class="form-group">
                        <label><i class="fas fa-check-circle me-1"></i> Folio autorización *</label>
                        <input type="text" class="form-control pos-input" name="folio_autorizacion" id="folio_autorizacion" required maxlength="16" placeholder="Ej: AUTH938492" oninput="validarFolio(this)">
                    </div>
                </div>
            `;
            break;
    }

    extra.innerHTML = html;
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

// ============ CONFIRMAR VENTA ============
function confirmarVenta() {
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

    if (carrito.length === 0) {
        Swal.fire({
            icon: 'warning',
            title: 'Carrito vacío',
            text: 'Agrega productos antes de continuar',
            confirmButtonColor: '#f97316',
            confirmButtonText: 'Entendido'
        });
        return;
    }

    const total = parseFloat(document.getElementById('total').value) || 0;
    const pago = parseFloat(document.getElementById('monto_pagado').value) || 0;

    if (!pago || pago <= 0) {
        Swal.fire({
            icon: 'warning',
            title: 'Monto inválido',
            text: 'Ingresa un monto válido',
            confirmButtonColor: '#f97316',
            confirmButtonText: 'Corregir'
        });

        document.getElementById('monto_pagado').focus();
        return;
    }

    if (pago < total) {
        Swal.fire({
            icon: 'error',
            title: 'Monto insuficiente',
            text: `Faltan $${(total - pago).toFixed(2)} para completar la venta`,
            confirmButtonColor: '#f97316',
            confirmButtonText: 'Aumentar pago'
        });

        document.getElementById('monto_pagado').focus();
        return;
    }

    const metodo = document.querySelector('input[name="metodo_pago"]:checked')?.value;

    if (metodo === 'transferencia') {
        const folio = document.getElementById('folio_transferencia')?.value;

        if (!folio || folio.length < 5) {
            Swal.fire({
                icon: 'warning',
                title: 'Folio requerido',
                text: 'Ingresa el folio de la transferencia',
                confirmButtonColor: '#f97316',
                confirmButtonText: 'Completar'
            });
            return;
        }
    }

    if (metodo === 'tarjeta_debito' || metodo === 'tarjeta_credito') {
        const ultimos4 = document.getElementById('ultimos4')?.value;
        const auth = document.getElementById('folio_autorizacion')?.value;

        if (!ultimos4 || ultimos4.length !== 4) {
            Swal.fire({
                icon: 'warning',
                title: 'Datos incompletos',
                text: 'Ingresa los últimos 4 dígitos de la tarjeta',
                confirmButtonColor: '#f97316',
                confirmButtonText: 'Completar'
            });
            return;
        }

        if (!auth || auth.length < 4) {
            Swal.fire({
                icon: 'warning',
                title: 'Folio requerido',
                text: 'Ingresa el folio de autorización',
                confirmButtonColor: '#f97316',
                confirmButtonText: 'Completar'
            });
            return;
        }
    }

    let ticketItems = '';

    carrito.forEach(item => {
        const subtotal = item.precio * item.cantidad;

        ticketItems += `
            <div style="display: flex; justify-content: space-between; font-size: 13px; margin-bottom: 6px;">
                <span style="color: #334155;">${escapeHtml(item.nombre)} x${item.cantidad}</span>
                <span style="font-weight: 600; color: #f97316;">$${subtotal.toFixed(2)}</span>
            </div>
        `;
    });

    const cambio = pago - total;
    const fecha = new Date().toLocaleString();

    Swal.fire({
        title: 'Confirmar venta',
        html: `
            <div style="background: #ffffff; border-radius: 20px; padding: 20px; max-width: 400px; margin: 0 auto;">
                <div style="text-align: center; border-bottom: 2px solid #e5e7eb; padding-bottom: 12px; margin-bottom: 16px;">
                    <div style="font-size: 16px; font-weight: 800; color: #1e293b;">TIENDA PESCADORES</div>
                    <div style="font-size: 10px; color: #6b7280;">${fecha}</div>
                </div>

                <div style="margin-bottom: 16px; max-height: 200px; overflow-y: auto;">
                    <div style="display: flex; justify-content: space-between; font-size: 11px; color: #9ca3af; border-bottom: 1px solid #e5e7eb; padding-bottom: 6px; margin-bottom: 8px;">
                        <span>PRODUCTO</span>
                        <span>IMPORTE</span>
                    </div>
                    ${ticketItems}
                </div>

                <div style="background: #f8fafc; border-radius: 12px; padding: 12px; margin-bottom: 16px;">
                    <div style="display: flex; justify-content: space-between; font-size: 13px; margin-bottom: 8px;">
                        <span style="color: #475569;">TOTAL</span>
                        <span style="font-weight: 700;">$${total.toFixed(2)}</span>
                    </div>
                    <div style="display: flex; justify-content: space-between; font-size: 13px; margin-bottom: 8px;">
                        <span style="color: #475569;">PAGO CON</span>
                        <span style="font-weight: 700;">$${pago.toFixed(2)}</span>
                    </div>
                </div>

                <div style="background: #16a34a; border-radius: 16px; padding: 20px; text-align: center; margin-bottom: 16px;">
                    <div style="font-size: 14px; color: white; opacity: 0.9; margin-bottom: 8px;">SU CAMBIO</div>
                    <div style="font-size: 42px; font-weight: 800; color: white;">$${cambio.toFixed(2)}</div>
                    <div style="font-size: 11px; color: white; opacity: 0.7; margin-top: 8px;">Entregue esta cantidad al cliente</div>
                </div>

                <div style="display: flex; justify-content: space-between; background: #f1f5f9; border-radius: 10px; padding: 10px 12px;">
                    <span style="color: #475569;">MÉTODO DE PAGO</span>
                    <span style="font-weight: 700;">
                        ${metodo === 'efectivo' ? 'EFECTIVO' : metodo === 'transferencia' ? 'TRANSFERENCIA' : metodo === 'tarjeta_debito' ? 'TARJETA DÉBITO' : 'TARJETA CRÉDITO'}
                    </span>
                </div>
            </div>
        `,
        icon: 'question',
        showCancelButton: true,
        confirmButtonText: 'Registrar venta',
        cancelButtonText: 'Cancelar',
        confirmButtonColor: '#f97316',
        cancelButtonColor: '#94a3b8',
        allowOutsideClick: false,
        allowEscapeKey: false,
        preConfirm: () => {
            ventaEnProceso = true;

            const btn = document.getElementById('btnConfirmar');

            if (btn) {
                btn.disabled = true;
                btn.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i> Procesando...';
            }

            return true;
        }
    }).then(result => {
        if (result.isConfirmed) {
            Swal.fire({
                title: 'Procesando venta',
                text: 'Por favor espere...',
                allowOutsideClick: false,
                allowEscapeKey: false,
                didOpen: () => {
                    Swal.showLoading();
                }
            });

            try {
                guardarCarrito();

                document.getElementById('carrito_json').value = JSON.stringify(carrito);

                const audio = document.getElementById('sonidoCaja');
                if (audio) audio.play().catch(e => console.log('Audio error:', e));

                document.getElementById('ventaForm').submit();

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
        } else {
            ventaEnProceso = false;
            resetBotonVenta();
            enfocarCodigo();
        }
    });
}

// ============ DATOS BANCARIOS ============
function mostrarDatosBancarios() {
const datosQR = `4152314179374577`;

    Swal.fire({
        title: '',
        html: `
            <div class="contenedor-bancario-final">
                <div class="tarjeta-real-bbva">
                    <div class="fondo-bbva-real"></div>

                    <div class="logo-bbva-real">
                        <img src="img/bbva-logo.png" alt="BBVA">
                    </div>

                    <div class="logo-visa-real">
                        <img src="https://cdn.simpleicons.org/visa" alt="VISA">
                    </div>

                    <div class="chip-real">
                        <div class="chip-interno">
                            <div class="chip-linea"></div>
                            <div class="chip-linea"></div>
                            <div class="chip-linea"></div>
                            <div class="chip-linea"></div>
                        </div>
                    </div>

                    <div class="contactless-real">
                        <i class="fas fa-wifi"></i>
                    </div>

                    <div class="numero-real">
                        <div class="bloque-numero">
                            <span>****</span>
                            <span>****</span>
                            <span>****</span>
                            <span class="ultimo-numero">4477</span>
                        </div>
                    </div>

                    <div class="fecha-real">
                        <span class="label-fecha">VÁLIDA HASTA</span>
                        <span class="valor-fecha">04/32</span>
                    </div>

                    <div class="titular-real">
                        <span class="label-titular">TITULAR</span>
                        <span class="valor-titular">KARMINA ARANGUTHY GARCIA</span>
                    </div>
                </div>

                <div class="qr-datos-final">
                    <div class="qr-header">
                        <i class="fas fa-qrcode"></i>
                        <span>ESCANEA EL QR</span>
                    </div>

                    <div id="qrContainerFinal" class="qr-big-container" style="background:#fff; padding:16px; border-radius:16px;"></div>

                    <div class="qr-info">
                        <i class="fas fa-mobile-alt"></i>
                        <span>Usa tu app bancaria</span>
                    </div>

                    <div class="datos-bancarios-final">
                        <div class="item-banco" onclick="copyToClipboard('4152 3141 7937 4577')">
                            <div class="item-icon">
                                <i class="fas fa-credit-card"></i>
                            </div>
                            <div class="item-texto">
                                <label>Numero de tarjeta</label>
                                <div class="valor-copy">
                                    <span>4152 3141 7937 4577</span>
                                    <i class="fas fa-copy"></i>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        `,
        showConfirmButton: true,
        confirmButtonText: 'Aceptar',
        confirmButtonColor: '#004481',
        background: 'transparent',
        width: '800px',
        customClass: {
            popup: 'swal-final-popup'
        },
        didOpen: () => {
            setTimeout(() => {
                const qrContainer = document.getElementById("qrContainerFinal");

                if (qrContainer && typeof QRCode !== 'undefined') {
                    qrContainer.innerHTML = '';

                    new QRCode(qrContainer, {
                        text: datosQR,
                        width: 220,
                        height: 220,
                        colorDark: "#000000",
                        colorLight: "#ffffff",
                        correctLevel: QRCode.CorrectLevel.H
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
    const contenedor = document.querySelector('.contenedor-bancario');

    if (!contenedor) {
        Swal.fire({
            icon: 'warning',
            title: 'No disponible',
            text: 'No se encontró el contenedor bancario para imprimir.',
            confirmButtonColor: '#f97316'
        });
        return;
    }

    const ventana = window.open('', '_blank');

    ventana.document.write(`
        <!DOCTYPE html>
        <html>
        <head>
            <title>BBVA - Datos Bancarios</title>
            <meta charset="UTF-8">
            <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
            <style>
                * { margin: 0; padding: 0; box-sizing: border-box; }
                body {
                    background: white;
                    display: flex;
                    justify-content: center;
                    align-items: center;
                    min-height: 100vh;
                    font-family: 'Segoe UI', Arial, sans-serif;
                }
                ${document.querySelector('style')?.innerHTML || ''}
            </style>
            <script src="https://cdn.jsdelivr.net/npm/qrcodejs@1.0.0/qrcode.min.js"><\/script>
        </head>
        <body>
            ${contenedor.outerHTML}
            <script>
                window.onload = () => {
                    setTimeout(() => {
                        window.print();
                        window.close();
                    }, 500);
                };
            <\/script>
        </body>
        </html>
    `);

    ventana.document.close();
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

// ============ EVENTOS ============
document.addEventListener('DOMContentLoaded', function() {
    renderCarrito();
    mostrarCamposPago();
    enfocarCodigo();

    const modalFlotante = document.getElementById('modalFlotante');

    if (modalFlotante) {
        modalFlotante.addEventListener('click', function(e) {
            if (e.target === this) cerrarModalFlotante();
        });
    }

    const buscador = document.getElementById('buscadorProductos');
    const filtro = document.getElementById('filtroCategoriaProductos');

    if (buscador) buscador.addEventListener('input', filtrarProductos);
    if (filtro) filtro.addEventListener('change', filtrarProductos);

    const inputCodigo = document.getElementById('codigo');

    if (inputCodigo) {
        inputCodigo.addEventListener('keydown', function(e) {
            if (e.key === 'Enter' || e.key === 'Tab') {
                e.preventDefault();
                clearTimeout(timerCodigo);

                if (this.value.trim() !== '') {
                    agregarProducto();
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
    if (montoPagado) montoPagado.addEventListener('input', calcularCambio);

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

            metodos.forEach(el => el.classList.remove('selected'));
            this.classList.add('selected');

            const radio = this.querySelector('input[type="radio"]');

            if (radio) radio.checked = true;

            mostrarCamposPago();
            enfocarCodigo();
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