<?php
ob_start();
session_start();
date_default_timezone_set('America/Mexico_City');

require_once 'includes/db.php';
require 'includes/fpdf.php';

if (!isset($_SESSION['usuario_id']) || $_SESSION['rol'] !== 'administrador') {
    header("Location: login.php");
    exit;
}

// Limpiar cualquier salida anterior
ob_clean();

// Obtener filtros
$producto_id = isset($_GET['producto_id']) ? intval($_GET['producto_id']) : 0;
$proveedor_filtro = isset($_GET['proveedor']) ? trim($_GET['proveedor']) : '';
$fecha_desde = isset($_GET['fecha_desde']) ? $_GET['fecha_desde'] : '';
$fecha_hasta = isset($_GET['fecha_hasta']) ? $_GET['fecha_hasta'] : '';

// ===== CREAR CARPETAS SI NO EXISTEN =====
$carpeta_base = 'uploads/';
$carpeta_reportes_stock = $carpeta_base . 'reportes_stock/';

if (!file_exists($carpeta_base)) {
    mkdir($carpeta_base, 0777, true);
}
if (!file_exists($carpeta_reportes_stock)) {
    mkdir($carpeta_reportes_stock, 0777, true);
}

// Determinar el prefijo del archivo
if (!empty($proveedor_filtro)) {
    $prefijo_archivo = 'Proveedor_' . preg_replace('/[^a-zA-Z0-9]/', '_', strtolower($proveedor_filtro));
} elseif ($producto_id > 0) {
    $stmt = $conn->prepare("SELECT nombre FROM productos WHERE id = ?");
    $stmt->bind_param("i", $producto_id);
    $stmt->execute();
    $res = $stmt->get_result();
    $prod = $res->fetch_assoc();
    $stmt->close();
    $prefijo_archivo = 'Producto_' . preg_replace('/[^a-zA-Z0-9]/', '_', strtolower($prod['nombre'] ?? $producto_id));
} else {
    $prefijo_archivo = 'Stock_general';
}

$timestamp = date('Y-m-d_H-i-s');
$nombre_archivo = $prefijo_archivo . '_' . $timestamp . '.pdf';
$ruta_completa = $carpeta_reportes_stock . $nombre_archivo;

// Clase PDF personalizada
class PDF extends FPDF {
    var $fila_actual = 0;
    
    function Header() {
        $logo_path = 'includes/logo.png';
        if (file_exists($logo_path)) {
            $this->Image($logo_path, 10, 8, 30);
        }
        
        $this->SetFont('Arial', 'B', 16);
        $this->Cell(0, 10, 'REPORTE DE STOCK', 0, 1, 'C');
        $this->SetFont('Arial', '', 10);
        $this->Cell(0, 6, 'Fecha de generacion: ' . date('d/m/Y H:i:s'), 0, 1, 'C');
        
        $usuario_nombre = isset($_SESSION['usuario_nombre']) ? $_SESSION['usuario_nombre'] : 'Administrador';
        $this->Cell(0, 6, 'Generado por: ' . $usuario_nombre, 0, 1, 'C');
        $this->Ln(8);
    }
    
    function Footer() {
        $this->SetY(-15);
        $this->SetFont('Arial', 'I', 8);
        $this->Cell(0, 10, 'Pagina ' . $this->PageNo() . '/{nb}', 0, 0, 'C');
    }
    
    function decodeText($text) {
        return utf8_decode($text);
    }
    
    // Calcular líneas necesarias para una celda
    function GetNbLines($w, $txt) {
        $cw = &$this->CurrentFont['cw'];
        if($w == 0)
            $w = $this->w - $this->rMargin - $this->x;
        $wmax = ($w - 2 * $this->cMargin) * 1000 / $this->FontSize;
        $s = str_replace("\r", '', $txt);
        $nb = strlen($s);
        if($nb > 0 && $s[$nb-1] == "\n")
            $nb--;
        $sep = -1;
        $i = 0;
        $j = 0;
        $l = 0;
        $nl = 1;
        while($i < $nb) {
            $c = $s[$i];
            if($c == "\n") {
                $i++;
                $sep = -1;
                $j = $i;
                $l = 0;
                $nl++;
                continue;
            }
            if($c == ' ')
                $sep = $i;
            $l += $cw[$c];
            if($l > $wmax) {
                if($sep == -1) {
                    if($i == $j)
                        $i++;
                } else
                    $i = $sep+1;
                $sep = -1;
                $j = $i;
                $l = 0;
                $nl++;
            } else
                $i++;
        }
        return $nl;
    }
}

// ===== CONSTRUIR CONSULTA =====
$base_query = "FROM historial_stock h 
               LEFT JOIN productos p ON h.producto_id = p.id
               WHERE 1=1";

if ($producto_id > 0) {
    $base_query .= " AND h.producto_id = $producto_id";
}
if (!empty($proveedor_filtro)) {
    $proveedor_filtro_escaped = $conn->real_escape_string($proveedor_filtro);
    $base_query .= " AND p.proveedor = '$proveedor_filtro_escaped'";
}
if (!empty($fecha_desde)) {
    $base_query .= " AND DATE(h.fecha_movimiento) >= '" . $conn->real_escape_string($fecha_desde) . "'";
}
if (!empty($fecha_hasta)) {
    $base_query .= " AND DATE(h.fecha_movimiento) <= '" . $conn->real_escape_string($fecha_hasta) . "'";
}

// Obtener total de registros
$count_query = "SELECT COUNT(*) as total " . $base_query;
$total_result = $conn->query($count_query);
$total_registros = $total_result->fetch_assoc()['total'];

// Crear PDF (Landscape A4)
$pdf = new PDF('L', 'mm', 'A4');
$pdf->AliasNbPages();
$pdf->AddPage();
$pdf->SetAutoPageBreak(true, 25); // Margen inferior de 25mm

// ===== INFORMACIÓN DE FILTROS =====
$pdf->SetFont('Arial', 'B', 11);

if (!empty($fecha_desde) && !empty($fecha_hasta)) {
    if ($fecha_desde == $fecha_hasta) {
        $pdf->Cell(0, 6, 'Fecha: ' . date('d/m/Y', strtotime($fecha_desde)), 0, 1, 'C');
    } else {
        $pdf->Cell(0, 6, 'Rango de fechas: ' . date('d/m/Y', strtotime($fecha_desde)) . ' al ' . date('d/m/Y', strtotime($fecha_hasta)), 0, 1, 'C');
    }
} elseif (!empty($fecha_desde)) {
    $pdf->Cell(0, 6, 'Fecha desde: ' . date('d/m/Y', strtotime($fecha_desde)), 0, 1, 'C');
} elseif (!empty($fecha_hasta)) {
    $pdf->Cell(0, 6, 'Fecha hasta: ' . date('d/m/Y', strtotime($fecha_hasta)), 0, 1, 'C');
}

$filtros_texto = [];
if ($producto_id > 0) {
    $stmt = $conn->prepare("SELECT nombre FROM productos WHERE id = ?");
    $stmt->bind_param("i", $producto_id);
    $stmt->execute();
    $res = $stmt->get_result();
    $prod = $res->fetch_assoc();
    $filtros_texto[] = "Producto: " . ($prod['nombre'] ?? '');
    $stmt->close();
}
if (!empty($proveedor_filtro)) {
    $filtros_texto[] = "Proveedor: " . $proveedor_filtro;
}

if (!empty($filtros_texto)) {
    $pdf->SetFont('Arial', 'I', 9);
    $pdf->Cell(0, 5, 'Filtros: ' . implode(' | ', $filtros_texto), 0, 1, 'C');
}

$pdf->Ln(5);
$pdf->SetFont('Arial', 'B', 10);
$pdf->Cell(0, 6, 'Total registros: ' . number_format($total_registros), 0, 1, 'R');
$pdf->Ln(3);

// ===== CONSULTA PRINCIPAL =====
$query = "SELECT h.*, u.nombre as usuario_nombre, p.nombre as producto_nombre, 
                 p.tipo_inventario, p.proveedor 
          FROM historial_stock h 
          LEFT JOIN productos p ON h.producto_id = p.id
          LEFT JOIN usuarios u ON h.usuario_id = u.id
          WHERE 1=1";

if ($producto_id > 0) {
    $query .= " AND h.producto_id = $producto_id";
}
if (!empty($proveedor_filtro)) {
    $proveedor_filtro_escaped = $conn->real_escape_string($proveedor_filtro);
    $query .= " AND p.proveedor = '$proveedor_filtro_escaped'";
}
if (!empty($fecha_desde)) {
    $query .= " AND DATE(h.fecha_movimiento) >= '" . $conn->real_escape_string($fecha_desde) . "'";
}
if (!empty($fecha_hasta)) {
    $query .= " AND DATE(h.fecha_movimiento) <= '" . $conn->real_escape_string($fecha_hasta) . "'";
}

$query .= " ORDER BY h.fecha_movimiento DESC";

$historial = $conn->query($query);

if (!$historial) {
    $pdf->SetFont('Arial', 'B', 12);
    $pdf->Cell(0, 10, 'Error en la consulta: ' . $conn->error, 1, 1, 'C');
    $pdf->Output('F', $ruta_completa);
    $pdf->Output('I', $nombre_archivo);
    exit;
}

if ($historial->num_rows === 0) {
    $pdf->SetFont('Arial', 'B', 12);
    $pdf->Cell(0, 10, 'No hay movimientos para mostrar', 1, 1, 'C');
    $pdf->Output('F', $ruta_completa);
    $pdf->Output('I', $nombre_archivo);
    exit;
}

// ===== CABECERAS DE TABLA =====
$pdf->SetFillColor(249, 115, 22); // Naranja
$pdf->SetTextColor(255, 255, 255);
$pdf->SetFont('Arial', 'B', 9);

// Anchos de columna ajustados
$colWidths = [28, 42, 30, 22, 22, 22, 20, 50, 38];

$headers = ['Fecha', 'Producto', 'Proveedor', 'Stock Ant.', 'Cantidad', 'Stock Nuevo', 'Tipo', 'Nota', 'Usuario'];

foreach ($headers as $i => $header) {
    $align = ($i == 1 || $i == 2 || $i == 7 || $i == 8) ? 'L' : 'C';
    $pdf->Cell($colWidths[$i], 10, $header, 1, 0, $align, true);
}
$pdf->Ln();

$pdf->SetFillColor(255, 255, 255);
$pdf->SetTextColor(0, 0, 0);
$pdf->SetFont('Arial', '', 8);

$fill = false;
$total_entradas = 0;
$total_salidas = 0;
$total_ajustes = 0;

while($row = $historial->fetch_assoc()) {
    // Calcular altura de la fila basada en la nota
    $nota = !empty($row['nota']) ? $row['nota'] : '-';
    $nota_decoded = $pdf->decodeText($nota);
    
    // Calcular líneas que ocupará la nota
    $pdf->SetFont('Arial', '', 8);
    $nbLines = $pdf->GetNbLines($colWidths[7] - 2, $nota_decoded);
    $alturaFila = max(8, $nbLines * 4.5);
    
    // Verificar espacio en página (evitar que se salga)
    $limite_inferior = 280; // Altura máxima permitida (A4 landscape = 210mm, pero con márgenes)
    if ($pdf->GetY() + $alturaFila + 15 > $limite_inferior) {
        $pdf->AddPage();
        // Repetir cabeceras
        $pdf->SetFillColor(249, 115, 22);
        $pdf->SetTextColor(255, 255, 255);
        $pdf->SetFont('Arial', 'B', 9);
        foreach ($headers as $i => $header) {
            $align = ($i == 1 || $i == 2 || $i == 7 || $i == 8) ? 'L' : 'C';
            $pdf->Cell($colWidths[$i], 10, $header, 1, 0, $align, true);
        }
        $pdf->Ln();
        $pdf->SetFillColor(255, 255, 255);
        $pdf->SetTextColor(0, 0, 0);
        $pdf->SetFont('Arial', '', 8);
    }
    
    $x = $pdf->GetX();
    $y = $pdf->GetY();
    
    // Determinar color según tipo
    $esEntrada = ($row['tipo_movimiento'] == 'entrada');
    $esSalida = ($row['tipo_movimiento'] == 'salida');
    
    // Fecha
    $fecha = date('d/m/y H:i', strtotime($row['fecha_movimiento']));
    $pdf->Cell($colWidths[0], $alturaFila, $fecha, 1, 0, 'C', $fill);
    
    // Producto
    $producto = substr($pdf->decodeText($row['producto_nombre'] ?? 'N/A'), 0, 30);
    $pdf->Cell($colWidths[1], $alturaFila, $producto, 1, 0, 'L', $fill);
    
    // Proveedor
    $proveedor = substr($pdf->decodeText($row['proveedor'] ?? '-'), 0, 20);
    $pdf->Cell($colWidths[2], $alturaFila, $proveedor, 1, 0, 'L', $fill);
    
    // Stock Anterior
    $pdf->Cell($colWidths[3], $alturaFila, number_format($row['cantidad_anterior'], 0), 1, 0, 'C', $fill);
    
   // Cantidad (con color según tipo y valor)
    if ($esEntrada) {
        // ENTRADA: siempre positivo
        $pdf->SetTextColor(0, 128, 0);
        $valor_mostrar = '+' . number_format($row['cantidad_agregada'], 0);
        $total_entradas += $row['cantidad_agregada'];
    } elseif ($esSalida) {
        // SALIDA: siempre negativo
        $pdf->SetTextColor(255, 0, 0);
        $valor_mostrar = '-' . number_format($row['cantidad_agregada'], 0);
        $total_salidas += $row['cantidad_agregada'];
    } else {
        // AJUSTE: puede ser positivo o negativo según el valor almacenado
        $cantidad = $row['cantidad_agregada'];
        if ($cantidad >= 0) {
            // Ajuste positivo (se agregó stock)
            $pdf->SetTextColor(0, 128, 0);
            $valor_mostrar = '+' . number_format($cantidad, 0);
        } else {
            // Ajuste negativo (se quitó stock)
            $pdf->SetTextColor(255, 0, 0);
            $valor_mostrar = number_format($cantidad, 0); // ya viene con signo negativo
        }
        $total_ajustes += $cantidad;
    }
    $pdf->Cell($colWidths[4], $alturaFila, $valor_mostrar, 1, 0, 'C', $fill);
    
    // Restaurar color negro para stock nuevo
    $pdf->SetTextColor(0, 0, 0);
    
    // Stock Nuevo
    $pdf->Cell($colWidths[5], $alturaFila, number_format($row['cantidad_nueva'], 0), 1, 0, 'C', $fill);
    
    // Tipo (con color)
    if ($esEntrada) {
        $pdf->SetTextColor(0, 128, 0);
        $tipo_texto = 'ENTRADA';
    } elseif ($esSalida) {
        $pdf->SetTextColor(255, 0, 0);
        $tipo_texto = 'SALIDA';
    } else {
        $pdf->SetTextColor(255, 0, 0);
        $tipo_texto = 'AJUSTE';
    }
    $pdf->Cell($colWidths[6], $alturaFila, $tipo_texto, 1, 0, 'C', $fill);
    
    // Restaurar color para nota
    $pdf->SetTextColor(0, 0, 0);
    
    // NOTA: Celda con texto multilínea
    $nota_x = $pdf->GetX();
    $nota_y = $pdf->GetY();
    
    // Dibujar texto de nota línea por línea
    $lines = explode("\n", wordwrap($nota_decoded, 32, "\n"));
    $line_height = $alturaFila / max(1, count($lines));
    
    for ($i = 0; $i < count($lines); $i++) {
        $pdf->SetXY($nota_x, $nota_y + ($i * $line_height));
        $pdf->Cell($colWidths[7], $line_height, $lines[$i], 0, 0, 'L', $fill);
    }
    $pdf->Rect($nota_x, $nota_y, $colWidths[7], $alturaFila);
    $pdf->SetXY($nota_x + $colWidths[7], $nota_y);
    
    // Usuario
    $usuario = substr($pdf->decodeText($row['usuario_nombre'] ?? 'Sistema'), 0, 25);
    $pdf->Cell($colWidths[8], $alturaFila, $usuario, 1, 0, 'L', $fill);
    
    // Mover a siguiente fila
    $pdf->SetY($y + $alturaFila);
    $pdf->SetX($x);
    $pdf->SetTextColor(0, 0, 0);
    
    $fill = !$fill;
}

// ===== RESUMEN FINAL =====
$pdf->Ln(8);

// Totales destacados
$pdf->SetFont('Arial', 'B', 10);
$pdf->SetFillColor(249, 115, 22);
$pdf->SetTextColor(255, 255, 255);
$pdf->Cell(0, 8, 'RESUMEN DE MOVIMIENTOS', 1, 1, 'C', true);
$pdf->SetTextColor(0, 0, 0);

$pdf->SetFont('Arial', '', 10);
$pdf->SetFillColor(255, 248, 240);

$pdf->Cell(100, 7, 'Total Entradas (Reabastecimientos):', 1, 0, 'L', true);
$pdf->SetTextColor(0, 128, 0);
$pdf->Cell(50, 7, '+' . number_format($total_entradas) . ' unidades', 1, 1, 'R', true);

$pdf->SetTextColor(0, 0, 0);
$pdf->Cell(100, 7, 'Total Salidas (Ventas):', 1, 0, 'L', true);
$pdf->SetTextColor(255, 0, 0);
$pdf->Cell(50, 7, '-' . number_format($total_salidas) . ' unidades', 1, 1, 'R', true);

$pdf->SetTextColor(0, 0, 0);
$pdf->Cell(100, 7, 'Total Ajustes de Inventario:', 1, 0, 'L', true);
$pdf->SetTextColor(255, 0, 0);
$pdf->Cell(50, 7, '-' . number_format($total_ajustes) . ' unidades', 1, 1, 'R', true);

$balance = $total_entradas - ($total_salidas + $total_ajustes);

$pdf->SetTextColor(0, 0, 0);
$pdf->SetFillColor(249, 115, 22);
$pdf->SetFont('Arial', 'B', 10);
$pdf->Cell(100, 8, 'BALANCE NETO:', 1, 0, 'L', true);

if ($balance >= 0) {
    $pdf->SetTextColor(0, 128, 0);
} else {
    $pdf->SetTextColor(255, 0, 0);
}
$pdf->SetFont('Arial', 'B', 11);
$pdf->Cell(50, 8, number_format($balance) . ' unidades', 1, 1, 'R', true);

// Guardar y mostrar PDF
$pdf->Output('F', $ruta_completa);
ob_end_clean();
$pdf->Output('I', $nombre_archivo);
?>