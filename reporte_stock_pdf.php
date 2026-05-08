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

// ===== OBTENER DATOS DE CONFIGURACIÓN DE LA TIENDA =====
$sqlConfig = "SELECT nombre, logo FROM configuracion_galeria LIMIT 1";
$resultConfig = $conn->query($sqlConfig);
$configTienda = $resultConfig->fetch_assoc();
$nombreTienda = $configTienda['nombre'] ?? 'SISTEMA DE INVENTARIO';
$logoTiendaPath = $configTienda['logo'] ?? '';

// Buscar logo tienda si no existe en la ruta guardada
if (empty($logoTiendaPath) || !file_exists($logoTiendaPath)) {
    $rutasPosibles = [
        '../img/logo.png', '../img/logo.jpg', '../img/panel_principal.jpg',
        '../img/panel_principal.png', '../dist/img/logo.png', '../dist/img/logo.jpg',
        'img/logo.png', 'img/logo.jpg', 'includes/logo.png', 'includes/logo.jpg'
    ];
    foreach ($rutasPosibles as $ruta) {
        if (file_exists($ruta)) {
            $logoTiendaPath = $ruta;
            break;
        }
    }
}

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
    var $logoTiendaPath;
    var $nombreTienda;
    
    function SetTiendaData($logoTiendaPath, $nombreTienda) {
        $this->logoTiendaPath = $logoTiendaPath;
        $this->nombreTienda = $nombreTienda;
    }
    
    function Header() {
        $pageWidth = $this->GetPageWidth();
        $logoY = 8;
        $logoTiendaSize = 22;
        
        // Logo Tienda (izquierda)
        if (!empty($this->logoTiendaPath) && file_exists($this->logoTiendaPath)) {
            $this->Image($this->logoTiendaPath, 12, $logoY, $logoTiendaSize, $logoTiendaSize);
        }
        
        // Nombre Tienda centrado
        $this->SetY($logoY + 5);
        $this->SetFont('Arial', 'B', 12);
        $this->SetTextColor(60, 60, 60);
        $this->Cell(0, 8, utf8_decode(strtoupper($this->nombreTienda)), 0, 1, 'C');
        
        // Línea decorativa superior naranja
        $this->SetDrawColor(249, 115, 22);
        $this->SetLineWidth(1);
        $this->Line(12, $logoY + $logoTiendaSize + 6, $pageWidth - 12, $logoY + $logoTiendaSize + 6);
        
        // Título del reporte
        $this->SetY($logoY + $logoTiendaSize + 14);
        $this->SetFont('Arial', 'B', 16);
        $this->SetTextColor(249, 115, 22);
        $this->Cell(0, 10, 'REPORTE DE HISTORIAL DE STOCK', 0, 1, 'C');
        
        // Fecha de generación
        $this->SetFont('Arial', '', 9);
        $this->SetTextColor(100, 100, 100);
        $this->Cell(0, 5, 'Generado: ' . date('d/m/Y H:i:s'), 0, 1, 'C');
        
        $usuario_nombre = isset($_SESSION['usuario_nombre']) ? $_SESSION['usuario_nombre'] : 'Administrador';
        $this->Cell(0, 5, 'Generado por: ' . utf8_decode($usuario_nombre), 0, 1, 'C');
        
        $this->Ln(6);
    }
    
    function Footer() {
        $this->SetY(-15);
        $this->SetFont('Arial', 'I', 8);
        $this->SetTextColor(128, 128, 128);
        $this->Cell(0, 10, utf8_decode($this->nombreTienda) . ' - Página ' . $this->PageNo() . '/{nb}', 0, 0, 'C');
    }
    
    function decodeText($text) {
        return utf8_decode($text);
    }
    
    // Función para centrar la tabla
    function CenteredTable($headers, $colWidths, $data, $alturaFila) {
        $pageWidth = $this->GetPageWidth();
        $tableWidth = array_sum($colWidths);
        $leftMargin = ($pageWidth - $tableWidth) / 2;
        
        $this->SetX($leftMargin);
        
        // Cabeceras
        $this->SetFillColor(249, 115, 22);
        $this->SetTextColor(255, 255, 255);
        $this->SetFont('Arial', 'B', 8);
        
        foreach ($headers as $i => $header) {
            $align = ($i == 1 || $i == 2 || $i == 7 || $i == 8) ? 'L' : 'C';
            $this->Cell($colWidths[$i], $alturaFila, $header, 1, 0, $align, true);
        }
        $this->Ln();
        
        // Datos
        $this->SetFillColor(255, 255, 255);
        $this->SetTextColor(0, 0, 0);
        $this->SetFont('Arial', '', 7);
        
        $fill = false;
        foreach ($data as $row) {
            $this->SetX($leftMargin);
            $bgColor = $fill ? [255, 245, 235] : [255, 255, 255];
            $this->SetFillColor($bgColor[0], $bgColor[1], $bgColor[2]);
            
            for ($i = 0; $i < count($row); $i++) {
                $align = ($i == 1 || $i == 2 || $i == 7 || $i == 8) ? 'L' : 'C';
                $this->Cell($colWidths[$i], $alturaFila, $row[$i], 1, 0, $align, true);
            }
            $this->Ln();
            $fill = !$fill;
        }
        
        return $leftMargin;
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
$pdf->SetTiendaData($logoTiendaPath, $nombreTienda);
$pdf->AliasNbPages();
$pdf->AddPage();
$pdf->SetAutoPageBreak(true, 20);
$pdf->SetMargins(10, 10, 10);

// ===== INFORMACIÓN DE FILTROS =====
$pdf->SetFont('Arial', 'B', 10);

if (!empty($fecha_desde) && !empty($fecha_hasta)) {
    if ($fecha_desde == $fecha_hasta) {
        $pdf->Cell(0, 5, 'Fecha: ' . date('d/m/Y', strtotime($fecha_desde)), 0, 1, 'C');
    } else {
        $pdf->Cell(0, 5, 'Rango de fechas: ' . date('d/m/Y', strtotime($fecha_desde)) . ' al ' . date('d/m/Y', strtotime($fecha_hasta)), 0, 1, 'C');
    }
} elseif (!empty($fecha_desde)) {
    $pdf->Cell(0, 5, 'Fecha desde: ' . date('d/m/Y', strtotime($fecha_desde)), 0, 1, 'C');
} elseif (!empty($fecha_hasta)) {
    $pdf->Cell(0, 5, 'Fecha hasta: ' . date('d/m/Y', strtotime($fecha_hasta)), 0, 1, 'C');
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
    $pdf->SetFont('Arial', 'I', 8);
    $pdf->Cell(0, 5, 'Filtros: ' . implode(' | ', $filtros_texto), 0, 1, 'C');
}

$pdf->Ln(4);
$pdf->SetFont('Arial', 'B', 9);
$pdf->Cell(0, 5, 'Total registros: ' . number_format($total_registros), 0, 1, 'R');
$pdf->Ln(4);

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

// ===== PREPARAR DATOS PARA TABLA CENTRADA =====
$headers = ['Fecha', 'Producto', 'Proveedor', 'Stock Ant.', 'Cantidad', 'Stock Nuevo', 'Tipo', 'Nota', 'Usuario'];
$colWidths = [28, 42, 35, 22, 22, 22, 20, 48, 32];
$alturaFila = 8;

$data = [];
$total_entradas = 0;
$total_salidas = 0;
$total_ajustes = 0;

while($row = $historial->fetch_assoc()) {
    $esEntrada = ($row['tipo_movimiento'] == 'entrada');
    $esSalida = ($row['tipo_movimiento'] == 'salida');
    
    // Fecha
    $fecha = date('d/m/y H:i', strtotime($row['fecha_movimiento']));
    
    // Producto
    $producto = substr($pdf->decodeText($row['producto_nombre'] ?? 'N/A'), 0, 32);
    
    // Proveedor
    $proveedor = substr($pdf->decodeText($row['proveedor'] ?? '-'), 0, 25);
    
    // Stock Anterior
    $stock_anterior = number_format($row['cantidad_anterior'], 0);
    
    // Cantidad
    if ($esEntrada) {
        $cantidad = '+' . number_format($row['cantidad_agregada'], 0);
        $total_entradas += $row['cantidad_agregada'];
    } elseif ($esSalida) {
        $cantidad = '-' . number_format($row['cantidad_agregada'], 0);
        $total_salidas += $row['cantidad_agregada'];
    } else {
        $cantidad_val = $row['cantidad_agregada'];
        if ($cantidad_val >= 0) {
            $cantidad = '+' . number_format($cantidad_val, 0);
        } else {
            $cantidad = number_format($cantidad_val, 0);
        }
        $total_ajustes += $cantidad_val;
    }
    
    // Stock Nuevo
    $stock_nuevo = number_format($row['cantidad_nueva'], 0);
    
    // Tipo
    $tipo = ($esEntrada) ? 'ENTRADA' : (($esSalida) ? 'SALIDA' : 'AJUSTE');
    
    // Nota
    $nota = !empty($row['nota']) ? $row['nota'] : '-';
    $nota_corta = substr($pdf->decodeText($nota), 0, 38);
    if (strlen($pdf->decodeText($nota)) > 38) {
        $nota_corta .= '...';
    }
    
    // Usuario
    $usuario = substr($pdf->decodeText($row['usuario_nombre'] ?? 'Sistema'), 0, 20);
    
    $data[] = [$fecha, $producto, $proveedor, $stock_anterior, $cantidad, $stock_nuevo, $tipo, $nota_corta, $usuario];
}

// Mostrar tabla centrada (25 filas por página)
$rowsPerPage = 25;
$totalPages = ceil(count($data) / $rowsPerPage);

for ($page = 0; $page < $totalPages; $page++) {
    if ($page > 0) {
        $pdf->AddPage();
    }
    
    $start = $page * $rowsPerPage;
    $end = min($start + $rowsPerPage, count($data));
    $pageData = array_slice($data, $start, $end - $start);
    
    $pdf->CenteredTable($headers, $colWidths, $pageData, $alturaFila);
}

// ===== RESUMEN FINAL CORREGIDO =====
$pdf->AddPage();
$pdf->SetFont('Arial', 'B', 14);
$pdf->SetFillColor(249, 115, 22);
$pdf->SetTextColor(255, 255, 255);
$pdf->Cell(0, 12, 'RESUMEN DE MOVIMIENTOS', 0, 1, 'C', true);
$pdf->Ln(8);

// Calcular valores correctos
$total_entradas_abs = $total_entradas;
$total_salidas_abs = $total_salidas;
$total_ajustes_abs = abs($total_ajustes);
$balance_neto = $total_entradas_abs - $total_salidas_abs - $total_ajustes_abs;

// Tabla de resumen centrada
$resumenWidth = 120;
$resumenLeft = ($pdf->GetPageWidth() - $resumenWidth) / 2;
$pdf->SetX($resumenLeft);

$pdf->SetFillColor(255, 248, 240);
$pdf->SetTextColor(0, 0, 0);
$pdf->SetFont('Arial', '', 10);

// Fila 1: Entradas
$pdf->SetX($resumenLeft);
$pdf->Cell(80, 9, 'Total Entradas (Reabastecimientos):', 1, 0, 'L', true);
$pdf->SetTextColor(0, 128, 0);
$pdf->SetFont('Arial', 'B', 10);
$pdf->Cell(40, 9, '+' . number_format($total_entradas_abs) . ' unidades', 1, 1, 'R', true);

// Fila 2: Salidas
$pdf->SetX($resumenLeft);
$pdf->SetTextColor(0, 0, 0);
$pdf->SetFont('Arial', '', 10);
$pdf->Cell(80, 9, 'Total Salidas (Ventas):', 1, 0, 'L', true);
$pdf->SetTextColor(255, 0, 0);
$pdf->SetFont('Arial', 'B', 10);
$pdf->Cell(40, 9, '-' . number_format($total_salidas_abs) . ' unidades', 1, 1, 'R', true);

// Fila 3: Ajustes
$pdf->SetX($resumenLeft);
$pdf->SetTextColor(0, 0, 0);
$pdf->SetFont('Arial', '', 10);
$pdf->Cell(80, 9, 'Total Ajustes de Inventario:', 1, 0, 'L', true);
$ajuste_signo = ($total_ajustes >= 0) ? '+' : '-';
$ajuste_color = ($total_ajustes >= 0) ? [0, 128, 0] : [255, 0, 0];
$pdf->SetTextColor($ajuste_color[0], $ajuste_color[1], $ajuste_color[2]);
$pdf->SetFont('Arial', 'B', 10);
$pdf->Cell(40, 9, $ajuste_signo . number_format($total_ajustes_abs) . ' unidades', 1, 1, 'R', true);

// Línea separadora
$pdf->Ln(3);
$pdf->SetDrawColor(249, 115, 22);
$pdf->SetLineWidth(0.5);
$pdf->Line($resumenLeft, $pdf->GetY(), $resumenLeft + $resumenWidth, $pdf->GetY());
$pdf->Ln(4);

// Fila 4: Balance Neto
$pdf->SetX($resumenLeft);
$pdf->SetFillColor(249, 115, 22);
$pdf->SetTextColor(255, 255, 255);
$pdf->SetFont('Arial', 'B', 11);
$pdf->Cell(80, 10, 'BALANCE NETO:', 1, 0, 'C', true);
$balance_color = ($balance_neto >= 0) ? [0, 128, 0] : [255, 0, 0];
$pdf->SetTextColor($balance_color[0], $balance_color[1], $balance_color[2]);
$pdf->SetFont('Arial', 'B', 11);
$pdf->Cell(40, 10, number_format($balance_neto) . ' unidades', 1, 1, 'C', true);

// Guardar y mostrar PDF
$pdf->Output('F', $ruta_completa);
ob_end_clean();
$pdf->Output('I', $nombre_archivo);
?>