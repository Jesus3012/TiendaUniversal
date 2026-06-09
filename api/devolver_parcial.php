<?php
// Limpiar cualquier output previo
if (ob_get_level()) ob_clean();
header('Content-Type: application/json');
header('Cache-Control: no-cache, must-revalidate');

include '../includes/db.php';
require_once('../includes/fpdf.php');

// Función para buscar logo con diferentes extensiones
function buscarLogo($basePath = 'img/panel_principal') {
    $extensiones = ['png', 'jpg', 'jpeg', 'webp', 'gif'];
    $ubicaciones = [
        $basePath,
        '../' . $basePath,
        __DIR__ . '/../' . $basePath,
        $_SERVER['DOCUMENT_ROOT'] . '/' . $basePath
    ];
    
    foreach ($extensiones as $ext) {
        foreach ($ubicaciones as $ubicacion) {
            $ruta = $ubicacion . '.' . $ext;
            if (file_exists($ruta)) {
                return $ruta;
            }
        }
    }
    return null;
}

$input = json_decode(file_get_contents('php://input'), true);

$folio = $conn->real_escape_string($input['folio'] ?? '');
$id_producto = intval($input['id_producto'] ?? 0);
$cantidad_devuelta = intval($input['cantidad'] ?? 0);
$motivo = $conn->real_escape_string($input['motivo'] ?? '');

if (!$folio || $id_producto <= 0 || $cantidad_devuelta <= 0) {
    echo json_encode(['success' => false, 'message' => 'Datos inválidos. Parámetros requeridos: folio, id_producto, cantidad.']);
    exit;
}

// Obtener configuración de la tienda
$sql_config = "SELECT nombre, telefono, email, direccion FROM configuracion_galeria WHERE id = 1";
$result_config = $conn->query($sql_config);
$config = $result_config->fetch_assoc();

if (!$config) {
    $config = [
        'nombre' => 'TIENDA PESCADORES',
        'telefono' => '',
        'email' => '',
        'direccion' => ''
    ];
}

// === 1. Obtener venta específica ===
$stmt = $conn->prepare("
    SELECT id, cantidad_vendida, correo_cliente 
    FROM ventas 
    WHERE folio_ticket = ? AND id_producto = ?
");
$stmt->bind_param("si", $folio, $id_producto);
$stmt->execute();
$res = $stmt->get_result();

if ($res->num_rows == 0) {
    echo json_encode(['success'=>false, 'message'=>'Artículo no encontrado en este ticket']);
    exit;
}

$venta = $res->fetch_assoc();
$id_venta = $venta['id'];
$cantidad_vendida = $venta['cantidad_vendida'];
$correo = $venta['correo_cliente'];

// === 2. Validar cantidad ===
if ($cantidad_devuelta > $cantidad_vendida) {
    echo json_encode(['success'=>false, 'message'=>'La cantidad excede lo vendido.']);
    exit;
}

// === 3. Registrar devolución ===
$conn->query("
CREATE TABLE IF NOT EXISTS devoluciones_parciales(
    id INT AUTO_INCREMENT PRIMARY KEY,
    id_venta INT,
    cantidad_devuelta INT,
    motivo VARCHAR(255),
    fecha TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB
");

$stmt2 = $conn->prepare("
    INSERT INTO devoluciones_parciales (id_venta, cantidad_devuelta, motivo)
    VALUES (?, ?, ?)
");
$stmt2->bind_param("iis", $id_venta, $cantidad_devuelta, $motivo);
$stmt2->execute();

// === 4. Actualizar cantidad vendida ===
$nueva_cantidad = $cantidad_vendida - $cantidad_devuelta;

if ($nueva_cantidad > 0) {
    $stmt3 = $conn->prepare("UPDATE ventas SET cantidad_vendida = ? WHERE id = ?");
    $stmt3->bind_param("ii", $nueva_cantidad, $id_venta);
    $stmt3->execute();
} else {
    // si quedó en 0, eliminar fila de ventas
    $conn->query("DELETE FROM ventas WHERE id = $id_venta");
}

// === 5. Restaurar stock ===
$stmt4 = $conn->prepare("UPDATE productos SET cantidad = cantidad + ? WHERE id = ?");
$stmt4->bind_param("ii", $cantidad_devuelta, $id_producto);
$stmt4->execute();

// === 5.1 ACTUALIZAR PEDIDOS ===
$pedido = $conn->query("
    SELECT id, cantidad_pedida, faltante
    FROM pedidos
    WHERE id_producto = $id_producto
    ORDER BY fecha DESC
    LIMIT 1
")->fetch_assoc();

if($pedido){
    $nueva_cantidad_pedida = $pedido['cantidad_pedida'] - $cantidad_devuelta;
    if($nueva_cantidad_pedida < 0) $nueva_cantidad_pedida = 0;

    $nuevo_faltante = $pedido['faltante'] - $cantidad_devuelta;
    if($nuevo_faltante < 0) $nuevo_faltante = 0;

    $conn->query("
        UPDATE pedidos
        SET cantidad_pedida = $nueva_cantidad_pedida,
            faltante = $nuevo_faltante
        WHERE id = {$pedido['id']}
    ");
}

// === 6. Revisar si quedan artículos ===
$q2 = $conn->prepare("
    SELECT v.*, p.nombre, p.precio_venta
    FROM ventas v
    JOIN productos p ON v.id_producto = p.id
    WHERE folio_ticket = ?
");
$q2->bind_param("s", $folio);
$q2->execute();
$rest = $q2->get_result();

if ($rest->num_rows == 0) {
    echo json_encode(['success'=>true, 'message'=>'Devolución realizada. Ticket vacío.']);
    exit;
}

// === 7. Regenerar PDF ===
$carrito = [];
$total = 0;

while ($r = $rest->fetch_assoc()) {
    $carrito[] = $r;
    $total += $r['precio_venta'] * $r['cantidad_vendida'];
}

$subtotal = $total / 1.16;
$iva = $total - $subtotal;

if (!is_dir('../tickets')) mkdir('../tickets', 0777, true);
$ruta = "../tickets/ticket_$folio.pdf";

// Tamaño dinámico
$alto = 120 + (count($carrito) * 10);
if ($alto < 130) $alto = 130;

$pdf = new FPDF('P','mm',array(80,$alto));
$pdf->AddPage();
$pdf->SetMargins(5,3,5);

// ====== LOGO (buscando img/panel_principal con cualquier extensión) ======
$logoPath = buscarLogo('img/panel_principal');
if ($logoPath && file_exists($logoPath)) {
    $anchoLogo = 20;
    $anchoPagina = $pdf->GetPageWidth();
    $x = ($anchoPagina - $anchoLogo) / 2;
    $pdf->Image($logoPath, $x, 4, $anchoLogo);
    $pdf->Ln(18);
} else {
    $pdf->Ln(8);
}

// ====== ENCABEZADO DE TIENDA ======
$nombreTienda = !empty($config['nombre']) ? $config['nombre'] : 'TIENDA PESCADORES';
$pdf->SetFont('Arial','B',12);
$pdf->Cell(0,6,utf8_decode($nombreTienda),0,1,'C');

$pdf->SetFont('Arial','',8);

if (!empty($config['direccion'])) {
    $pdf->Cell(0,4,utf8_decode($config['direccion']),0,1,'C');
}
if (!empty($config['telefono'])) {
    $pdf->Cell(0,4,'Tel: ' . $config['telefono'],0,1,'C');
}
if (!empty($config['email'])) {
    $pdf->Cell(0,4,$config['email'],0,1,'C');
}

$pdf->Ln(2);
$pdf->Cell(0,4,str_repeat('-', 45),0,1,'C');

// ====== INFO DEL TICKET ======
$pdf->SetFont('Arial','B',9);
$pdf->Cell(0,5,'Folio: '.$folio,0,1,'L');

$pdf->SetFont('Arial','',9);
$pdf->Cell(0,5,'Fecha: '.date('d/m/Y H:i:s'),0,1,'L');
$pdf->Cell(0,5,'Cliente: '.$correo,0,1,'L');

$pdf->Ln(2);
$pdf->Cell(0,4,str_repeat('-', 45),0,1,'C');

// ====== TABLA DE PRODUCTOS ======
$pdf->SetFont('Arial','B',8);
$pdf->Cell(38,5,'Producto',0,0);
$pdf->Cell(10,5,'Cant',0,0,'C');
$pdf->Cell(14,5,'P.U.',0,0,'R');
$pdf->Cell(13,5,'Total',0,1,'R');

$pdf->SetFont('Arial','',8);

foreach ($carrito as $p) {
    $nombre = utf8_decode($p['nombre']);
    if (strlen($nombre) > 20) {
        $nombre = substr($nombre, 0, 18) . '...';
    }
    
    $pdf->Cell(38,5,$nombre,0,0);
    $pdf->Cell(10,5,$p['cantidad_vendida'],0,0,'C');
    $pdf->Cell(14,5,'$'.number_format($p['precio_venta'],2),0,0,'R');
    $pdf->Cell(13,5,'$'.number_format($p['precio_venta'] * $p['cantidad_vendida'],2),0,1,'R');
}

$pdf->Ln(2);
$pdf->Cell(0,4,str_repeat('-', 45),0,1,'C');

// ====== TOTALES ======
$pdf->SetFont('Arial','',9);
$pdf->Cell(45,6,'Subtotal:',0,0,'R');
$pdf->SetFont('Arial','B',9);
$pdf->Cell(20,6,'$'.number_format($subtotal,2),0,1,'R');

$pdf->SetFont('Arial','',9);
$pdf->Cell(45,6,'IVA 16%:',0,0,'R');
$pdf->SetFont('Arial','B',9);
$pdf->Cell(20,6,'$'.number_format($iva,2),0,1,'R');

$pdf->Ln(2);
$pdf->SetFont('Arial','B',11);
$pdf->Cell(45,7,'TOTAL:',0,0,'R');
$pdf->SetFont('Arial','B',11);
$pdf->Cell(20,7,'$'.number_format($total,2),0,1,'R');

$pdf->Ln(3);
$pdf->Cell(0,4,str_repeat('-', 45),0,1,'C');

// ====== MENSAJE FINAL ======
$pdf->SetFont('Arial','I',8);
$pdf->Cell(0,5,utf8_decode('¡Gracias por tu compra!'),0,1,'C');
if ($cantidad_devuelta > 0) {
    $pdf->SetFont('Arial','I',7);
    $pdf->Cell(0,4,'* Se realizó una devolución parcial *',0,1,'C');
}
$pdf->Cell(0,5,utf8_decode('¡Vuelva pronto!'),0,1,'C');

// Guardar PDF
$pdf->Output('F',$ruta);

// Guardar referencia en DB
$conn->query("UPDATE ventas SET ticket_pdf = 'ticket_$folio.pdf' WHERE folio_ticket = '$folio'");

// Limpiar buffer antes de enviar respuesta
if (ob_get_level()) ob_clean();

// Enviar respuesta JSON exitosa
echo json_encode(['success'=>true, 'message'=>'Devolución parcial realizada y ticket actualizado.']);
exit;
?>