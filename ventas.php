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
    // Limpiar carrito de sesión
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
                $producto = $conn->query("SELECT cantidad FROM productos WHERE id={$item['id']}")->fetch_assoc();
                if ($producto['cantidad'] < $item['cantidad']) {
                    $errores[] = $item['nombre'];
                }
            }

            if (!empty($errores)) {
                $_SESSION['alerta'] = ['tipo' => 'error', 'titulo' => 'Sin stock', 'mensaje' => 'Stock insuficiente para: ' . implode(', ', $errores)];
                header("Location: " . $_SERVER['PHP_SELF']);
                exit;
            } else {
                $conn->begin_transaction();
                
                try {
                    // ================= GENERAR FOLIO NUMÉRICO SECUENCIAL =================
                    // Obtener el último folio con formato 'Venta_codigo_X' de la tabla ventas
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

                    // Obtener configuración de la tienda para el ticket
                    $sqlTienda = "SELECT nombre, telefono, email, direccion, logo FROM configuracion_galeria WHERE id = 1";
                    $resultTienda = $conn->query($sqlTienda);
                    $tienda = $resultTienda->fetch_assoc();
                    $tienda_nombre = $tienda['nombre'] ?? 'Tienda Pescadores';
                    $tienda_telefono = $tienda['telefono'] ?? '';
                    $tienda_email = $tienda['email'] ?? '';
                    $tienda_direccion = $tienda['direccion'] ?? '';
                    $tienda_logo = $tienda['logo'] ?? '';

                    // Crear ticket térmico (80mm) - SIN IVA
                    $pdf = new FPDF('P', 'mm', array(80, 140 + count($carrito) * 8));
                    $pdf->AddPage();
                    $pdf->SetMargins(5, 5, 5);
                    $pdf->SetAutoPageBreak(true, 10);

                    // Logo
                    if (!empty($tienda_logo) && file_exists($tienda_logo)) {
                        $pdf->Image($tienda_logo, 25, 5, 30);
                        $pdf->Ln(28);
                    } else {
                        $pdf->Ln(5);
                    }

                    // Encabezado - NOMBRE DE LA TIENDA
                    $pdf->SetFont('Arial', 'B', 11);
                    $pdf->Cell(0, 5, utf8_decode(strtoupper($tienda_nombre)), 0, 1, 'C');
                    $pdf->Ln(2);

                    // Datos de contacto
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

                    // Línea separadora
                    $pdf->Line(5, $pdf->GetY(), 75, $pdf->GetY());
                    $pdf->Ln(3);

                    // Folio y fecha
                    $pdf->SetFont('Arial', 'B', 8);
                    $pdf->Cell(0, 4, "TICKET DE COMPRA", 0, 1, 'C');
                    $pdf->SetFont('Arial', '', 7);
                    $pdf->Cell(0, 3, "Folio: $folio", 0, 1, 'C');
                    $pdf->Cell(0, 3, "Fecha: " . date('d/m/Y H:i:s'), 0, 1, 'C');
                    $pdf->Cell(0, 3, "Atendido por: " . ($_SESSION['nombre'] ?? 'Vendedor'), 0, 1, 'C');
                    $pdf->Ln(2);

                    // Línea separadora
                    $pdf->Line(5, $pdf->GetY(), 75, $pdf->GetY());
                    $pdf->Ln(2);

                    // Encabezados de productos
                    $pdf->SetFont('Arial', 'B', 7);
                    $pdf->Cell(38, 4, 'Producto', 0, 0, 'L');
                    $pdf->Cell(10, 4, 'Cant', 0, 0, 'C');
                    $pdf->Cell(10, 4, 'Precio', 0, 0, 'C');
                    $pdf->Cell(12, 4, 'Importe', 0, 1, 'R');

                    // Línea separadora
                    $pdf->Line(5, $pdf->GetY(), 75, $pdf->GetY());
                    $pdf->Ln(1);

                    // Productos
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

                    // TOTAL (sin IVA)
                    $pdf->SetFont('Arial', 'B', 9);
                    $pdf->Cell(48, 5, 'TOTAL:', 0, 0, 'R');
                    $pdf->Cell(22, 5, '$' . number_format($total, 2), 0, 1, 'R');

                    // Método de pago
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

                    // Pie de página
                    $pdf->SetFont('Arial', 'B', 8);
                    $pdf->Cell(0, 4, utf8_decode("¡Gracias por su compra!"), 0, 1, 'C');
                    $pdf->SetFont('Arial', '', 6);
                    $pdf->Cell(0, 3, utf8_decode("Conserve este ticket para aclaraciones."), 0, 1, 'C');

                    $nombreArchivo = 'ticket_' . $folio . '.pdf';
                    $rutaArchivo = 'tickets/' . $nombreArchivo;
                    $pdf->Output('F', $rutaArchivo);
                                        
                    $ticketEnviado = false;
                    if (!empty($correo_cliente) && filter_var($correo_cliente, FILTER_VALIDATE_EMAIL)) {
                        require_once('includes/configuracion_correo.php');
                        $ticketEnviado = enviarCorreoTicket($conn, $correo_cliente, $rutaArchivo, $folio);
                    }
                    
                    $conn->commit();
                    
                    // Limpiar carrito después de venta exitosa
                    $_SESSION['carrito'] = [];
                    
                    $mensaje = "Venta registrada correctamente.\nCambio: $" . number_format($cambio, 2);
                    if ($ticketEnviado) $mensaje .= "\nTicket enviado a $correo_cliente";
                    
                    // Guardar alerta en sesión
                    $_SESSION['alerta'] = [
                        'tipo' => 'success', 
                        'titulo' => '¡Venta exitosa!', 
                        'mensaje' => $mensaje,
                        'folio' => $folio
                    ];
                    
                    // Redirigir para evitar reenvío del formulario (PRG pattern)
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

// AHORA incluimos header y navbar (después de cualquier posible redirección)
include('includes/header.php');
include('includes/navbar.php');

// Pasar el carrito de sesión a JavaScript
$carrito_json = json_encode($_SESSION['carrito']);
?>

<!-- ESTILOS EXTERNOS -->
<link rel="stylesheet" href="css/venta-codigo.css">

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
                                        <tr id="emptyCartRow"><td colspan="6" class="text-center py-5"><i class="fas fa-shopping-cart fa-3x text-muted mb-3"></i><p class="text-muted mb-0">El carrito está vacío. Agrega productos para comenzar.</p></td></tr>
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
let carrito = <?php echo $carrito_json; ?>;

document.addEventListener('DOMContentLoaded', function() {
    renderCarrito();
    mostrarCamposPago();
    document.getElementById('codigo').focus();
});

<?php if(isset($alerta)): ?>
// Mostrar alerta si existe
Swal.fire({ 
    icon: '<?= $alerta['tipo'] ?>', 
    title: '<?= $alerta['titulo'] ?>', 
    html: '<?= str_replace("\n", '<br>', $alerta['mensaje']) ?>', 
    confirmButtonColor: '#f97316', 
    confirmButtonText: 'Aceptar',
    allowOutsideClick: false
}).then(() => { 
    <?php if($alerta['tipo'] === 'success'): ?>
    // Limpiar todo después de la venta
    carrito = []; 
    renderCarrito(); 
    document.getElementById('monto_pagado').value = ''; 
    document.getElementById('correo_cliente').value = ''; 
    document.getElementById('codigo').focus();
    
    // Limpiar la URL para evitar reenvío
    window.history.replaceState({}, document.title, window.location.pathname);
    <?php endif; ?>
});
<?php endif; ?>

async function agregarProducto() {
    const codigo = document.getElementById('codigo').value.trim();
    if (!codigo) { 
        Swal.fire({ icon: 'warning', title: 'Atención', text: 'Ingresa o escanea un código de producto.', toast: true, position: 'top-end', showConfirmButton: false, timer: 2000 }); 
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
        body.innerHTML = '<tr id="emptyCartRow"><td colspan="6" class="text-center py-5"><i class="fas fa-shopping-cart fa-3x text-muted mb-3"></i><p class="text-muted mb-0">El carrito está vacío. Agrega productos para comenzar.</p></td>'; 
        document.getElementById('total').value = '0.00'; 
        document.getElementById('cambio').value = '0.00'; 
        return; 
    }
    
    let html = '', total = 0, contador = 1;
    carrito.forEach((item, index) => {
        const subtotal = item.precio * item.cantidad;
        total += subtotal;
        
        let imagenHtml = '';
        if (item.imagen && item.imagen !== '' && item.imagen !== 'uploads/noimage.png') {
            imagenHtml = `<img src="${item.imagen}" class="producto-imagen" 
                         onerror="this.style.display='none'; this.parentElement.innerHTML='<div class=\'producto-imagen-fallback\'>${item.inicial}</div>'">`;
        } else {
            imagenHtml = `<div class="producto-imagen-fallback">${item.inicial}</div>`;
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
    if (cantidad > carrito[index].stock) { renderCarrito(); return; } 
    carrito[index].cantidad = cantidad; 
    guardarCarrito(); 
    renderCarrito(); 
}

function eliminarProducto(index) { 
    Swal.fire({ title: '¿Eliminar producto?', text: `¿Quitar ${carrito[index].nombre} del carrito?`, icon: 'question', showCancelButton: true, confirmButtonColor: '#f97316', cancelButtonColor: '#6c757d', confirmButtonText: 'Sí, eliminar', cancelButtonText: 'Cancelar' }).then(result => { 
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

function seleccionarMetodo(elemento) { 
    document.querySelectorAll('.metodo-radio').forEach(el => el.classList.remove('selected')); 
    elemento.classList.add('selected'); 
    elemento.querySelector('input[type="radio"]').checked = true; 
    mostrarCamposPago(); 
}

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
                                <p class="mb-0 mt-1 small" style="color: #4b5563;">No se requiere referencia. Calcula el cambio automáticamente.</p>
                            </div>
                        </div>
                    </div>`;
            break;
        case 'transferencia':
            html = `
                <div class="form-group mb-3">
                    <label><i class="fas fa-hashtag me-1"></i> Folio de transferencia *</label>
                    <input type="text" class="form-control pos-input" name="referencia_pago" id="folio_transferencia" required placeholder="Ej: TRX87439210" maxlength="20" oninput="formatearFolioTransferencia(this)">
                    <small class="text-muted">Máximo 20 caracteres, solo letras y números</small>
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
                            <label><i class="fas fa-credit-card me-1"></i> Últimos 4 dígitos *</label>
                            <input type="text" class="form-control pos-input" id="ultimos4" name="ultimos4" maxlength="4" required placeholder="Ej: 4921" oninput="this.value = this.value.replace(/\\D/g,''); detectarTipoTarjeta();">
                            <small class="text-muted">Ingresa los últimos 4 dígitos de la tarjeta</small>
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-tag me-1"></i> Tipo de tarjeta</label>
                            <input type="text" class="form-control pos-input" id="tipo_tarjeta" name="tipo_tarjeta" readonly placeholder="Detectado...." style="background-color: #f8fafc;">
                            <input type="hidden" name="tipo_tarjeta_detectada" id="tipo_tarjeta_detectada">
                            <small class="text-muted">Se detecta automáticamente según los dígitos ingresados</small>
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-check-circle me-1"></i> Folio de autorización *</label>
                            <input type="text" class="form-control pos-input" name="folio_autorizacion" id="folio_autorizacion" required maxlength="16" placeholder="Ej: AUTH-938492" oninput="validarFolio(this)">
                            <small class="text-muted">Máximo 16 caracteres, solo letras y números</small>
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
</script>
