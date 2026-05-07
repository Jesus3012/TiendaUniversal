<?php
date_default_timezone_set('America/Mexico_City');
session_start();
include 'includes/db.php';
include 'guardar_historial_reporte.php';
require 'includes/fpdf.php';

// ======================= PRODUCTO ESPECIAL (PAGADO) =======================
define('PRODUCTO_ESPECIAL_NOMBRE', 'libretas');
define('PROVEEDOR_ESPECIAL', 'Nevaris 3D');

if (!isset($_SESSION['usuario_id'])) {
    die('Acceso no autorizado');
}

$usuario_id = $_SESSION['usuario_id'];
$usuario_nombre = $_SESSION['usuario_nombre'] ?? 'Usuario';

$proveedor = $_GET['proveedor'] ?? '';
$fecha_inicio = $_GET['fecha_inicio'] ?? '';
$fecha_fin = $_GET['fecha_fin'] ?? '';

if (!$proveedor || !$fecha_inicio || !$fecha_fin) {
    die('Parámetros incompletos');
}

if (strtotime($fecha_fin) < strtotime($fecha_inicio)) {
    die('La fecha fin no puede ser menor que la fecha inicio');
}

$fecha_inicio_full = $fecha_inicio;
$fecha_fin_full = $fecha_fin;

// Obtener datos de la tienda
$sqlConfig = "SELECT nombre, logo FROM configuracion_galeria LIMIT 1";
$resultConfig = $conn->query($sqlConfig);
$configTienda = $resultConfig->fetch_assoc();
$nombreTienda = $configTienda['nombre'] ?? 'PESCADORES DE LA PREHISTORIA';
$logoTiendaPath = $configTienda['logo'] ?? '';

if (empty($logoTiendaPath) || !file_exists($logoTiendaPath)) {
    $rutasPosibles = [
        '../img/logo.png', '../img/logo.jpg', '../img/panel_principal.jpg',
        '../img/panel_principal.png', '../dist/img/logo.png', '../dist/img/logo.jpg'
    ];
    foreach ($rutasPosibles as $ruta) {
        if (file_exists($ruta)) {
            $logoTiendaPath = $ruta;
            break;
        }
    }
}

// Obtener logo del proveedor
$logoProveedorPath = '';
$inicialesProveedor = '';
$sqlLogoProveedor = "SELECT logo FROM proveedores WHERE nombre = ? LIMIT 1";
$stmtLogo = $conn->prepare($sqlLogoProveedor);
$stmtLogo->bind_param("s", $proveedor);
$stmtLogo->execute();
$resultLogo = $stmtLogo->get_result();
if ($resultLogo && $row = $resultLogo->fetch_assoc()) {
    $logoProveedorPath = $row['logo'] ?? '';
    if (!empty($logoProveedorPath) && !file_exists($logoProveedorPath)) {
        $logoProveedorPath = '';
    }
}

// Iniciales del proveedor
$palabras = explode(' ', $proveedor);
if (count($palabras) >= 2) {
    $inicialesProveedor = strtoupper(substr($palabras[0], 0, 1) . substr($palabras[1], 0, 1));
} else {
    $inicialesProveedor = strtoupper(substr($proveedor, 0, 2));
}

// Consulta para totales
$query_datos = "
    SELECT 
        rp.ventas,
        p.precio_compra,
        p.nombre,
        (rp.ventas * p.precio_compra) as total_costo,
        CASE 
            WHEN LOWER(p.nombre) LIKE LOWER(?) 
                 AND LOWER(p.proveedor) LIKE LOWER(?) 
            THEN 1
            ELSE 0
        END AS es_producto_especial
    FROM reporte_proveedor rp
    INNER JOIN productos p ON rp.producto_id = p.id
    WHERE rp.proveedor = ?
    AND rp.fecha_conteo BETWEEN ? AND ?
";

$likeNombre = '%' . PRODUCTO_ESPECIAL_NOMBRE . '%';
$likeProveedorEsp = '%' . PROVEEDOR_ESPECIAL . '%';

$stmt = $conn->prepare($query_datos);
$stmt->bind_param("sssss", $likeNombre, $likeProveedorEsp, $proveedor, $fecha_inicio_full, $fecha_fin_full);
$stmt->execute();
$result_datos = $stmt->get_result();

$total_registros = $result_datos->num_rows;
$total_costos_historial = 0;
$total_unidades_vendidas = 0;
$productos_especiales_contados = 0;

while ($row = $result_datos->fetch_assoc()) {
    if (!$row['es_producto_especial']) {
        $total_costos_historial += $row['total_costo'];
    } else {
        $productos_especiales_contados++;
    }
    $total_unidades_vendidas += $row['ventas'];
}

// Crear carpeta
$carpeta_reportes = 'uploads/reportes_proveedor/';
if (!file_exists($carpeta_reportes)) {
    mkdir($carpeta_reportes, 0777, true);
}

$nombre_archivo = 'reporte_' . preg_replace('/[^a-zA-Z0-9]/', '_', $proveedor) . '_' . $fecha_inicio . '_a_' . $fecha_fin . '_' . date('Ymd_His') . '.pdf';
$ruta_completa = $carpeta_reportes . '/' . $nombre_archivo;

$historial_data = [
    'usuario_id' => $usuario_id,
    'usuario_nombre' => $usuario_nombre,
    'tipo_reporte' => 'pdf',
    'proveedor' => $proveedor,
    'fecha_generacion' => date('Y-m-d H:i:s'),
    'total_registros' => $total_registros,
    'nombre_archivo' => $nombre_archivo
];

guardarHistorialReporte($conn, $historial_data);

/* ================= CLASE PDF ================= */
class PDF extends FPDF {
    var $logoTiendaPath;
    var $logoProveedorPath;
    var $inicialesProveedor;
    var $nombreTienda;
    var $proveedorNombre;
    var $colorPrimary;
    var $colorSecondary;
    
    function SetLogos($logoTienda, $logoProveedor, $iniciales, $nombreTienda, $proveedorNombre) {
        $this->logoTiendaPath = $logoTienda;
        $this->logoProveedorPath = $logoProveedor;
        $this->inicialesProveedor = $iniciales;
        $this->nombreTienda = $nombreTienda;
        $this->proveedorNombre = $proveedorNombre;
        $this->colorPrimary = [0, 102, 204];
        $this->colorSecondary = [255, 102, 0];
    }
    
    function Header() {
        $pageWidth = $this->GetPageWidth();
        $logoY = 8;
        $logoSize = 22;
        
        // Logo Tienda (izquierda)
        if (!empty($this->logoTiendaPath) && file_exists($this->logoTiendaPath)) {
            $this->Image($this->logoTiendaPath, 12, $logoY, $logoSize, $logoSize);
        }
        
        // Logo Proveedor o Iniciales (derecha)
        if (!empty($this->logoProveedorPath) && file_exists($this->logoProveedorPath)) {
            $this->Image($this->logoProveedorPath, $pageWidth - 35, $logoY, $logoSize, $logoSize);
        } else {
            // Solo las iniciales del proveedor, sin cuadro, más grandes
            $textX = $pageWidth - 50;
            $textY = $logoY + 10;
            $this->SetFont('Arial', 'B', 26); // Tamaño grande
            $this->SetTextColor($this->colorPrimary[0], $this->colorPrimary[1], $this->colorPrimary[2]); // Azul
            $this->Text($textX, $textY, $this->inicialesProveedor);
            $this->SetTextColor(0, 0, 0);
        }
        
        // Nombre Tienda centrado
        $this->SetY($logoY + 5);
        $this->SetFont('Arial', 'B', 11);
        $this->SetTextColor(60, 60, 60);
        $this->Cell(0, 8, utf8_decode(strtoupper($this->nombreTienda)), 0, 1, 'C');
        
        // Línea decorativa
        $this->SetDrawColor(200, 200, 200);
        $this->Line(12, $logoY + $logoSize + 6, $pageWidth - 12, $logoY + $logoSize + 6);
        
        // Título
        $this->SetY($logoY + $logoSize + 14);
        $this->SetFont('Arial', 'B', 16);
        $this->SetTextColor(44, 62, 80);
        $this->Cell(0, 10, utf8_decode('REPORTE DE INVENTARIO'), 0, 1, 'C');
        
        // Proveedor
        $this->SetFont('Arial', 'B', 12);
        $this->SetTextColor(231, 76, 60);
        $this->Cell(0, 8, utf8_decode('PROVEEDOR: ' . strtoupper($this->proveedorNombre)), 0, 1, 'C');
        
        // Período
        $this->SetFont('Arial', '', 9);
        $this->SetTextColor(100, 100, 100);
        $this->Cell(0, 6, utf8_decode("Período: " . date('d/m/Y', strtotime($GLOBALS['fecha_inicio'])) . " al " . date('d/m/Y', strtotime($GLOBALS['fecha_fin']))), 0, 1, 'C');
        
        $this->Ln(8);
    }
    
    function Footer() {
        $this->SetY(-15);
        $this->SetFont('Arial', 'I', 8);
        $this->SetTextColor(128, 128, 128);
        $this->Cell(0, 8, utf8_decode('Tienda Pescadores - Documento Oficial | Página ' . $this->PageNo()), 0, 0, 'C');
    }
    
    function FormatMoney($amount) {
        return '$' . number_format($amount, 2);
    }
    
    function SectionTitle($title) {
        $this->SetFont('Arial', 'B', 12);
        $this->SetFillColor($this->colorPrimary[0], $this->colorPrimary[1], $this->colorPrimary[2]);
        $this->SetTextColor(255, 255, 255);
        $this->Cell(0, 9, utf8_decode($title), 1, 1, 'L', true);
        $this->SetTextColor(0, 0, 0);
        $this->Ln(4);
    }
    
    // Tabla centrada
    function CenteredTable($headers, $data, $colWidths, $specialRows = []) {
        $pageWidth = $this->GetPageWidth();
        $tableWidth = array_sum($colWidths);
        $leftMargin = ($pageWidth - $tableWidth) / 2;
        
        $this->SetX($leftMargin);
        
        // Cabeceras
        $this->SetFont('Arial', 'B', 9);
        $this->SetFillColor($this->colorPrimary[0], $this->colorPrimary[1], $this->colorPrimary[2]);
        $this->SetTextColor(255, 255, 255);
        
        for ($i = 0; $i < count($headers); $i++) {
            $this->Cell($colWidths[$i], 10, utf8_decode($headers[$i]), 1, 0, 'C', true);
        }
        $this->Ln();
        
        // Datos
        $this->SetFont('Arial', '', 8);
        $this->SetTextColor(0, 0, 0);
        $fill = false;
        
        foreach ($data as $index => $row) {
            $this->SetX($leftMargin);
            $isSpecial = in_array($index, $specialRows);
            
            $bgColor = $fill ? [248, 249, 250] : [255, 255, 255];
            if ($isSpecial) {
                $bgColor = [200, 230, 201];
                $this->SetTextColor(0, 128, 0);
            }
            
            $this->SetFillColor($bgColor[0], $bgColor[1], $bgColor[2]);
            
            for ($i = 0; $i < count($row); $i++) {
                $align = ($i == 0) ? 'L' : (in_array($i, [1, 2, 3]) ? 'C' : 'R');
                $this->Cell($colWidths[$i], 7, utf8_decode($row[$i]), 1, 0, $align, true);
            }
            $this->Ln();
            
            if ($isSpecial) {
                $this->SetTextColor(0, 0, 0);
            }
            $fill = !$fill;
        }
        
        $this->Ln(5);
        return $leftMargin;
    }
}

// Crear PDF
$pdf = new PDF('L', 'mm', 'A4');
$pdf->SetLogos($logoTiendaPath, $logoProveedorPath, $inicialesProveedor, $nombreTienda, $proveedor);
$pdf->AliasNbPages();
$pdf->AddPage();
$pdf->SetAutoPageBreak(true, 25);
$pdf->SetMargins(10, 10, 10);
$pdf->SetDrawColor(200, 200, 200);

/* ================= TOTALES EN UNA SOLA LÍNEA CON DISEÑO MEJORADO ================= */
$pageWidth = $pdf->GetPageWidth();
$pdf->SetY($pdf->GetY() + 10);

// Cuadro de totales con diseño mejorado
$pdf->SetFillColor(245, 245, 245);
$pdf->SetDrawColor(180, 180, 180);
$pdf->SetLineWidth(0.5);
$pdf->Rect(20, $pdf->GetY(), $pageWidth - 40, 24, 'DF');

$pdf->SetY($pdf->GetY() + 8);
$pdf->SetFont('Arial', 'B', 12);
$pdf->SetTextColor(52, 152, 219);

$pdf->Cell(($pageWidth - 40) / 2, 8, 'UNIDADES VENDIDAS: ' . number_format($total_unidades_vendidas), 0, 0, 'C');

$pdf->SetTextColor(231, 76, 60);
$pdf->Cell(($pageWidth - 40) / 2, 8, 'DEUDA TOTAL: ' . $pdf->FormatMoney($total_costos_historial), 0, 1, 'C');

$pdf->Ln(18); // Espacio extra antes de la tabla

/* ================= TABLA PRINCIPAL ================= */
$pdf->SectionTitle('DETALLE DE INVENTARIO POR PRODUCTO');

$headers = ['Producto', 'Stock Inicial', 'Vendidos', 'Stock Restante', 'Precio Compra', 'Deuda Total'];
$colWidths = [60, 32, 32, 40, 32, 45];

// FILTRAR: Solo productos con ventas
$qDetalle = $conn->prepare("
    SELECT 
        p.nombre,
        rp.stock_inicial,
        rp.ventas,
        (rp.stock_inicial - rp.ventas) as stock_restante,
        p.precio_compra,
        (rp.ventas * p.precio_compra) as costo_total,
        CASE 
            WHEN LOWER(p.nombre) LIKE LOWER(?) 
                 AND LOWER(p.proveedor) LIKE LOWER(?) 
            THEN 1
            ELSE 0
        END AS es_producto_especial
    FROM reporte_proveedor rp
    INNER JOIN productos p ON rp.producto_id = p.id
    WHERE rp.proveedor = ?
    AND rp.fecha_conteo BETWEEN ? AND ?
    AND rp.ventas > 0
    ORDER BY 
        es_producto_especial ASC, 
        rp.fecha_conteo DESC, 
        p.nombre ASC
");

$qDetalle->bind_param("sssss", $likeNombre, $likeProveedorEsp, $proveedor, $fecha_inicio_full, $fecha_fin_full);
$qDetalle->execute();
$rDetalle = $qDetalle->get_result();

$data = [];
$specialRows = [];
$index = 0;

while ($row = $rDetalle->fetch_assoc()) {
    $costo = $row['es_producto_especial'] ? 'PAGADO' : $pdf->FormatMoney($row['ventas'] * $row['precio_compra']);
    $producto = substr($row['nombre'], 0, 28);
    if ($row['es_producto_especial']) {
        $producto = '✓ ' . $producto;
        $specialRows[] = $index;
    }
    
    $data[] = [
        $producto,
        number_format($row['stock_inicial']),
        number_format($row['ventas']),
        number_format($row['stock_restante']),
        $pdf->FormatMoney($row['precio_compra']),
        $costo
    ];
    $index++;
}

if (count($data) == 0) {
    $pdf->SetFont('Arial', 'I', 11);
    $pdf->SetFillColor(255, 243, 205);
    $pageWidth = $pdf->GetPageWidth();
    $pdf->SetX(($pageWidth - 200) / 2);
    $pdf->Cell(200, 12, utf8_decode('No hay productos con ventas en el período seleccionado'), 1, 1, 'C', true);
} else {
    $pdf->CenteredTable($headers, $data, $colWidths, $specialRows);
    
    // Línea de total
    $pageWidth = $pdf->GetPageWidth();
    $tableWidth = array_sum($colWidths);
    $leftMargin = ($pageWidth - $tableWidth) / 2;
    
    $pdf->SetX($leftMargin);
    $pdf->SetFont('Arial', 'B', 9);
    $pdf->SetFillColor(233, 236, 239);
    
    $pdf->Cell(array_sum(array_slice($colWidths, 0, 5)), 8, 'TOTAL DEUDA', 1, 0, 'R', true);
    $pdf->Cell($colWidths[5], 8, $pdf->FormatMoney($total_costos_historial), 1, 1, 'R', true);
}

// Productos especiales
if ($productos_especiales_contados > 0) {
    $pdf->Ln(8);
    $pdf->SetFont('Arial', 'B', 9);
    $pdf->SetFillColor(212, 237, 218);
    $pdf->SetTextColor(21, 87, 36);
    $pdf->Cell(0, 8, utf8_decode('✓ Productos pagados por adelantado (excluidos de la deuda)'), 1, 1, 'L', true);
    $pdf->SetTextColor(0, 0, 0);
}

$pdf->Ln(10);
$pdf->SetFont('Arial', 'I', 8);
$pdf->SetTextColor(128, 128, 128);
$pdf->Cell(0, 5, utf8_decode('Reporte generado automáticamente por el Sistema Tienda Pescadores'), 0, 1, 'C');
$pdf->Cell(0, 5, utf8_decode('Este documento es oficial y refleja el estado de inventario del período indicado.'), 0, 1, 'C');

// Guardar y mostrar
$pdf->Output('F', $ruta_completa);
$pdf->Output('I', $nombre_archivo);
?>