<?php
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require 'includes/PHPMailer/src/Exception.php';
require 'includes/PHPMailer/src/PHPMailer.php';
require 'includes/PHPMailer/src/SMTP.php';
require 'includes/fpdf.php';
include('includes/db.php');
include('includes/session.php');

header('Content-Type: application/json');

// --- VALIDACIÓN ---
$folio = $_GET['folio'] ?? '';
if (!$folio) {
    echo json_encode(['success' => false, 'message' => 'Folio no recibido.']);
    exit;
}

// ========== OBTENER CONFIGURACIÓN DE LA TIENDA ==========
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

// ========== OBTENER VENTAS ==========
$sql = "
    SELECT v.id, v.folio_ticket, v.correo_cliente, v.fecha_venta,
           p.nombre, v.cantidad_vendida, p.precio_venta
    FROM ventas v
    JOIN productos p ON v.id_producto = p.id
    WHERE v.folio_ticket = ?
";
$stmt = $conn->prepare($sql);
$stmt->bind_param("s", $folio);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows == 0) {
    echo json_encode(['success' => false, 'message' => 'No existe una venta con este folio.']);
    exit;
}

// Agrupar ventas
$ventas = [];
$total = 0;
$correo_cliente = "";
$fecha_venta = "";

while ($row = $result->fetch_assoc()) {
    $ventas[] = $row;
    $correo_cliente = trim($row['correo_cliente']);
    $fecha_venta = $row['fecha_venta'];
    $total += $row['cantidad_vendida'] * $row['precio_venta'];
}

if (empty($correo_cliente) || $correo_cliente == 'Cliente no registrado') {
    echo json_encode(['success' => false, 'message' => 'La venta no tiene correo registrado.']);
    exit;
}

$subtotal = $total / 1.16;
$iva = $total - $subtotal;

// === GENERAR TICKET PDF ===
if (!is_dir('tickets')) mkdir('tickets', 0777, true);

$nombreArchivo = 'ticket_' . $folio . '.pdf';
$rutaArchivo = 'tickets/' . $nombreArchivo;

$alto = 80 + (count($ventas) * 10);
if ($alto < 120) $alto = 120;

$pdf = new FPDF('P', 'mm', array(80, $alto));
$pdf->AddPage();
$pdf->SetMargins(5, 5, 5);
$pdf->SetAutoPageBreak(true, 5);

// ========== LOGO (BUSCAR img/panel_principal CON CUALQUIER EXTENSIÓN) ==========
$logoPath = null;
$baseLogo = 'img/panel_principal';
$extensiones = ['png', 'jpg', 'jpeg', 'webp', 'gif'];

foreach ($extensiones as $ext) {
    if (file_exists($baseLogo . '.' . $ext)) {
        $logoPath = $baseLogo . '.' . $ext;
        break;
    }
}

// Si no se encuentra, buscar en ../img/
if (!$logoPath) {
    foreach ($extensiones as $ext) {
        if (file_exists('../' . $baseLogo . '.' . $ext)) {
            $logoPath = '../' . $baseLogo . '.' . $ext;
            break;
        }
    }
}

if ($logoPath) {
    $anchoLogo = 20;
    $anchoPagina = $pdf->GetPageWidth();
    $x = ($anchoPagina - $anchoLogo) / 2;
    $pdf->Image($logoPath, $x, 4, $anchoLogo);
    $pdf->Ln(18);
} else {
    $pdf->Ln(8);
}

// ========== ENCABEZADO DE TIENDA ==========
$nombreTienda = !empty($config['nombre']) ? $config['nombre'] : 'TIENDA PESCADORES';
$pdf->SetFont('Arial', 'B', 13);
$pdf->Cell(0, 6, utf8_decode($nombreTienda), 0, 1, 'C');

$pdf->SetFont('Arial', '', 8);

if (!empty($config['direccion'])) {
    $pdf->Cell(0, 4, utf8_decode($config['direccion']), 0, 1, 'C');
}
if (!empty($config['telefono'])) {
    $pdf->Cell(0, 4, 'Tel: ' . $config['telefono'], 0, 1, 'C');
}
if (!empty($config['email'])) {
    $pdf->Cell(0, 4, $config['email'], 0, 1, 'C');
}

$pdf->Ln(2);
$pdf->SetFont('Arial', '', 8);
$pdf->Cell(0, 4, str_repeat('-', 45), 0, 1, 'C');

// ========== INFO GENERAL ==========
$pdf->SetFont('Arial', '', 9);
$pdf->Cell(0, 5, 'FOLIO: ' . $folio, 0, 1, 'C');
$pdf->Cell(0, 5, 'FECHA: ' . date('d/m/Y H:i:s', strtotime($fecha_venta)), 0, 1, 'C');
$pdf->Cell(0, 5, utf8_decode('CLIENTE: ') . $correo_cliente, 0, 1, 'C');

$pdf->Ln(2);
$pdf->Cell(0, 4, str_repeat('-', 45), 0, 1, 'C');

// ========== COLUMNAS ==========
$pdf->SetFont('Arial', 'B', 8);
$pdf->Cell(30, 5, 'Producto', 0, 0);
$pdf->Cell(10, 5, 'Cant', 0, 0, 'C');
$pdf->Cell(15, 5, 'P.U.', 0, 0, 'C');
$pdf->Cell(15, 5, 'Total', 0, 1, 'R');

$pdf->SetFont('Arial', '', 8);
$pdf->Cell(0, 4, str_repeat('-', 45), 0, 1, 'C');

// ========== PRODUCTOS ==========
foreach ($ventas as $v) {
    $nombre = utf8_decode($v['nombre']);
    if (strlen($nombre) > 18) {
        $nombre = substr($nombre, 0, 15) . '...';
    }

    $pdf->Cell(30, 5, $nombre, 0, 0);
    $pdf->Cell(10, 5, $v['cantidad_vendida'], 0, 0, 'C');
    $pdf->Cell(15, 5, '$' . number_format($v['precio_venta'], 2), 0, 0, 'C');
    $pdf->Cell(15, 5, '$' . number_format($v['cantidad_vendida'] * $v['precio_venta'], 2), 0, 1, 'R');
}

$pdf->Ln(2);
$pdf->Cell(0, 4, str_repeat('-', 45), 0, 1, 'C');

// ========== TOTALES ==========
$pdf->SetFont('Arial', '', 9);
$pdf->Cell(45, 5, 'Subtotal:', 0, 0, 'R');
$pdf->SetFont('Arial', 'B', 9);
$pdf->Cell(25, 5, '$' . number_format($subtotal, 2), 0, 1, 'R');

$pdf->SetFont('Arial', '', 9);
$pdf->Cell(45, 5, 'IVA (16%):', 0, 0, 'R');
$pdf->SetFont('Arial', 'B', 9);
$pdf->Cell(25, 5, '$' . number_format($iva, 2), 0, 1, 'R');

$pdf->Ln(2);
$pdf->SetFont('Arial', 'B', 11);
$pdf->Cell(45, 6, 'TOTAL:', 0, 0, 'R');
$pdf->SetFont('Arial', 'B', 11);
$pdf->Cell(25, 6, '$' . number_format($total, 2), 0, 1, 'R');

$pdf->Ln(3);

$pdf->SetFont('Arial', 'I', 8);
$pdf->Cell(0, 5, utf8_decode('¡Gracias por tu compra!'), 0, 1, 'C');
$pdf->Cell(0, 5, utf8_decode('¡Vuelve pronto!'), 0, 1, 'C');

$pdf->Output('F', $rutaArchivo);

// === ENVIAR CORREO ===
$mail = new PHPMailer(true);

try {
    $mail->isSMTP();
    $mail->Host = 'smtp.gmail.com';
    $mail->SMTPAuth = true;
    $mail->Username = 'jesusgabrielmtz78@gmail.com';
    $mail->Password = 'iwdf uyqu erzq wvbm';
    $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
    $mail->Port = 587;
    $mail->CharSet = 'UTF-8';
    $mail->Timeout = 30;

    $nombreTienda = !empty($config['nombre']) ? $config['nombre'] : 'Tienda Pescadores';
    $mail->setFrom('jesusgabrielmtz78@gmail.com', $nombreTienda);
    $mail->addAddress($correo_cliente);
    
    $mail->Subject = "Ticket de compra - Folio: {$folio}";
    $mail->Body = "Estimado cliente,\n\nAdjunto encontrará el ticket de su compra.\n\n";
    $mail->Body .= "Folio: {$folio}\n";
    $mail->Body .= "Fecha: " . date('d/m/Y H:i:s', strtotime($fecha_venta)) . "\n";
    $mail->Body .= "Total: $" . number_format($total, 2) . "\n\n";
    $mail->Body .= "¡Gracias por su preferencia!";
    
    if (file_exists($rutaArchivo)) {
        $mail->addAttachment($rutaArchivo);
    } else {
        throw new Exception('No se pudo generar el archivo PDF');
    }

    $mail->send();

    echo json_encode([
        'success' => true,
        'message' => "Ticket reenviado correctamente a {$correo_cliente}"
    ]);

} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => "Error al enviar correo: " . $mail->ErrorInfo
    ]);
}
?>