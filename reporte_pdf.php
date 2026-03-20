<?php
date_default_timezone_set('America/Mexico_City');
session_start();
include 'includes/db.php';
include 'guardar_historial_reporte.php';
require 'includes/fpdf.php';

// ======================= PRODUCTO ESPECIAL (PAGADO) =======================
// Este producto NO debe aparecer en la deuda con proveedores
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

// Validar fechas
if (strtotime($fecha_fin) < strtotime($fecha_inicio)) {
    die('La fecha fin no puede ser menor que la fecha inicio');
}

$fecha_inicio_full = $fecha_inicio;
$fecha_fin_full = $fecha_fin;

// Primero obtener datos para calcular totales
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
$likeProveedor = '%' . PROVEEDOR_ESPECIAL . '%';

$stmt = $conn->prepare($query_datos);
$stmt->bind_param("sssss", $likeNombre, $likeProveedor, $proveedor, $fecha_inicio_full, $fecha_fin_full);
$stmt->execute();
$result_datos = $stmt->get_result();

$total_registros = $result_datos->num_rows;
$total_costos_historial = 0;
$total_unidades_vendidas = 0;
$productos_especiales_contados = 0;

while ($row = $result_datos->fetch_assoc()) {
    // Solo sumar costos si NO es producto especial
    if (!$row['es_producto_especial']) {
        $total_costos_historial += $row['total_costo'];
    } else {
        $productos_especiales_contados++;
    }
    $total_unidades_vendidas += $row['ventas'];
}

// Crear carpeta si no existe
$carpeta_reportes = 'uploads/reportes_proveedor/';
if (!file_exists($carpeta_reportes)) {
    mkdir($carpeta_reportes, 0777, true);
}

// Guardar en historial (solo con los campos que existen en la tabla)
$nombre_archivo = 'reporte_' . $proveedor . '_' . $fecha_inicio . '_a_' . $fecha_fin . '_' . date('Ymd_His') . '.pdf'; // o .xls para excel
$ruta_completa = $carpeta_reportes . '/' . $nombre_archivo;

$historial_data = [
    'usuario_id' => $usuario_id,
    'usuario_nombre' => $usuario_nombre,
    'tipo_reporte' => 'pdf', // o 'excel' según el archivo
    'proveedor' => $proveedor,
    'fecha_generacion' => date('Y-m-d H:i:s'),
    'total_registros' => $total_registros,
    'nombre_archivo' => $nombre_archivo
];

guardarHistorialReporte($conn, $historial_data);

/* ================= CLASE PDF MEJORADA ================= */
class PDF extends FPDF {
    function Footer(){
        $this->SetY(-15);
        $this->SetFont('Arial','I',8);
        $this->SetTextColor(100,100,100);
        $this->Cell(0,10,
            utf8_decode('Tienda Pescadores - Reporte para Proveedor | Página '.$this->PageNo()),
            0,0,'C'
        );
    }
    
    // Función para formatear moneda
    function FormatMoney($amount) {
        return '$' . number_format($amount, 2);
    }
    
    // Título de sección
    function SectionTitle($title) {
        $this->SetFont('Arial','B',14);
        $this->SetFillColor(41, 128, 185);
        $this->SetTextColor(255,255,255);
        $this->Cell(0,10, utf8_decode($title), 1, 1, 'L', true);
        $this->SetTextColor(0,0,0);
        $this->Ln(3);
    }
    
    // Función para crear celda con texto en dos líneas
    function Cell2Lines($w, $h, $txt1, $txt2, $border, $align, $fill) {
        $x = $this->GetX();
        $y = $this->GetY();
        
        // Celda principal
        $this->Cell($w, $h, '', $border, 0, $align, $fill);
        
        // Guardar posición para el texto
        $this->SetXY($x, $y);
        
        // Primera línea
        $this->SetFont('Arial','B',10);
        $this->Cell($w, $h/2, utf8_decode($txt1), 0, 0, $align);
        
        // Segunda línea
        $this->SetXY($x, $y + $h/2);
        $this->SetFont('Arial','B',8);
        $this->Cell($w, $h/2, utf8_decode($txt2), 0, 0, $align);
        
        // Restaurar posición X
        $this->SetXY($x + $w, $y);
    }
}

$pdf = new PDF('L', 'mm', 'A4');
$pdf->AliasNbPages();
$pdf->AddPage();
$pdf->SetAutoPageBreak(true, 20);
$pdf->SetMargins(10, 20, 10);
$pdf->SetDrawColor(189, 195, 199);
$pdf->SetLineWidth(0.2);

// Formatear fecha para el encabezado
$fecha_encabezado = date('d/m/Y', strtotime($fecha_inicio));

/* ================= ENCABEZADO ================= */
$pdf->SetFont('Arial', 'B', 20);
$pdf->Cell(0, 12, utf8_decode('REPORTE DE INVENTARIO'), 0, 1, 'C');
$pdf->SetFont('Arial', 'B', 14);
$pdf->Cell(0, 10, utf8_decode('PROVEEDOR: ' . strtoupper($proveedor)), 0, 1, 'C');

// Cuadro de información del período
$pdf->SetDrawColor(189, 195, 199);
$pdf->Rect(10, $pdf->GetY(), 277, 20, 'D');

// Texto del período
$pdf->SetTextColor(0, 0, 0);
$pdf->SetXY(12, $pdf->GetY() + 2);
$pdf->Cell(0, 8, utf8_decode("Período: " . date('d/m/Y', strtotime($fecha_inicio)) . " al " . date('d/m/Y', strtotime($fecha_fin))), 0, 1, 'C');
$pdf->SetX(12);
$pdf->Cell(0, 8, "Generado: " . date('d/m/Y H:i'), 0, 1, 'C');

$pdf->Ln(5);

/* ================= DETALLE DE INVENTARIO POR PRODUCTO ================= */
$pdf->SectionTitle('DETALLE DE INVENTARIO POR PRODUCTO');

$pdf->SetFillColor(52,58,64);
$pdf->SetTextColor(255,255,255);

// Cabeceras
$pdf->SetFont('Arial','B',10);

// Producto
$pdf->Cell(55,12,'Producto',1,0,'C',true);

// Stock Inicial
$pdf->Cell(35,12,'Stock Inicial',1,0,'C',true);

// Ventas
$pdf->Cell(40,12,'Productos Vendidos',1,0,'C',true);

// Stock Restante con fecha del período (usando función especial)
$pdf->Cell2Lines(45,12,'Stock Restante',$fecha_encabezado,1,'C',true);

// Precio Compra
$pdf->Cell(35,12,'Precio Compra',1,0,'C',true);

// Costo Total / Estado
$pdf->Cell(45,12,'Deuda Total',1,1,'C',true);

$pdf->SetFont('Arial','',9);
$pdf->SetTextColor(0,0,0);

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
    ORDER BY 
        es_producto_especial ASC, 
        rp.fecha_conteo DESC, 
        p.nombre ASC
");

$qDetalle->bind_param("sssss", $likeNombre, $likeProveedor, $proveedor, $fecha_inicio_full, $fecha_fin_full);
$qDetalle->execute();
$rDetalle = $qDetalle->get_result();

if ($rDetalle->num_rows == 0) {
    $pdf->SetFont('Arial','I',10);
    $pdf->SetFillColor(255,243,205);
    $pdf->Cell(0,10, utf8_decode('No hay registros en el período seleccionado'), 1, 1, 'C', true);
} else {
    $fill = false;
    $total_general_costos = 0;
    $total_ventas_con_deuda = 0;
    $productos_especiales_lista = [];
    
    while($row = $rDetalle->fetch_assoc()){
        // Si es producto especial, el costo es 0
        $costo_total = $row['es_producto_especial'] ? 0 : ($row['ventas'] * $row['precio_compra']);
        
        if (!$row['es_producto_especial']) {
            $total_general_costos += $costo_total;
            $total_ventas_con_deuda += $row['ventas'];
        } else {
            $productos_especiales_lista[] = $row['nombre'];
        }
        
        $pdf->SetFillColor($fill ? 248 : 255, 249, 250);
        
        // Producto (con indicador si es especial)
        $nombre = utf8_decode(substr($row['nombre'], 0, 22));
        if ($row['es_producto_especial']) {
            $nombre = '' . $nombre;
        }
        $pdf->Cell(55,8, $nombre, 1, 0, 'L', $fill);
        
        // Stock Inicial
        $pdf->Cell(35,8, $row['stock_inicial'], 1, 0, 'C', $fill);
        
        // Ventas
        $pdf->Cell(40,8, $row['ventas'], 1, 0, 'C', $fill);
        
        // Stock Restante
        $pdf->Cell(45,8, $row['stock_restante'], 1, 0, 'C', $fill);
        
        // Precio compra
        $pdf->Cell(35,8, $pdf->FormatMoney($row['precio_compra']), 1, 0, 'R', $fill);
        
        // Costo total o PAGADO
        if ($row['es_producto_especial']) {
            $pdf->SetFillColor(209, 231, 221); // Verde claro
            $pdf->SetTextColor(40, 167, 69);    // Verde oscuro
            $pdf->Cell(45,8, 'PAGADO', 1, 1, 'C', true);
            $pdf->SetTextColor(0,0,0);
        } else {
            $pdf->Cell(45,8, $pdf->FormatMoney($costo_total), 1, 1, 'R', $fill);
        }
        
        $fill = !$fill;
    }
    
    // Espacio antes del resumen
    $pdf->Ln(5);
    
    // Resumen de productos especiales si existen
    if (!empty($productos_especiales_lista)) {
        $pdf->SetFont('Arial','B',10);
        $pdf->SetFillColor(212, 237, 218);
        $pdf->SetTextColor(21, 87, 36);
        $pdf->Cell(0,8, utf8_decode('Productos pagados por adelantado:'), 1, 1, 'L', true);
        $pdf->SetFont('Arial','',9);
        $pdf->SetTextColor(0,0,0);
        
        $productos_texto = implode(', ', array_unique($productos_especiales_lista));
        $pdf->MultiCell(0,6, utf8_decode($productos_texto), 1, 'L', false);
        $pdf->Ln(3);
    }
    
    // Total de costos (excluyendo productos especiales)
    $pdf->SetFont('Arial','B',9);
    $pdf->SetFillColor(233,236,239);
    $pdf->Cell(60,8,'',0,0,'L');
    $pdf->Cell(35,8,'',0,0,'C');
    $pdf->Cell(35,8,'',0,0,'C');
    $pdf->Cell(45,8,'',0,0,'C');
    $pdf->Cell(35,8,'DEUDA TOTAL',1,0,'R',true);
    $pdf->Cell(45,8, $pdf->FormatMoney($total_general_costos), 1, 1,'R',true);
   
}

$pdf->Ln(8);

// Reporte generado automáticamente
$pdf->SetFont('Arial','I',9);
$pdf->Cell(0,6, utf8_decode('Reporte generado automáticamente - Sistema Tienda Pescadores'), 0, 1, 'C');

// Guardar el PDF en la carpeta
$pdf->Output('F', $ruta_completa);

// Mostrar el PDF en el navegador
$pdf->Output('I', $nombre_archivo);
?>