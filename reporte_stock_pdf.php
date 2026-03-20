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

// ===== CONTROL DE INSERCIONES CON SESIÓN =====
// Crear un identificador único para este reporte basado en los filtros
$reporte_id = md5($producto_id . $proveedor_filtro . $fecha_desde . $fecha_hasta . $_SESSION['usuario_id']);

// Inicializar el array de reportes generados en la sesión si no existe
if (!isset($_SESSION['reportes_generados'])) {
    $_SESSION['reportes_generados'] = [];
}

// Verificar si este reporte ya fue generado en esta sesión
$reporte_ya_generado = isset($_SESSION['reportes_generados'][$reporte_id]);

// Si no está en sesión, lo marcamos como generado
if (!$reporte_ya_generado) {
    $_SESSION['reportes_generados'][$reporte_id] = time();
}

// Limpiar reportes antiguos de la sesión (más de 1 hora)
foreach ($_SESSION['reportes_generados'] as $id => $timestamp) {
    if (time() - $timestamp > 3600) { // 1 hora
        unset($_SESSION['reportes_generados'][$id]);
    }
}

// ===== CREAR CARPETAS SI NO EXISTEN =====
$carpeta_base = 'uploads/';
$carpeta_reportes_stock = $carpeta_base . 'reportes_stock/';

// Crear carpeta uploads si no existe
if (!file_exists($carpeta_base)) {
    mkdir($carpeta_base, 0777, true);
}

// Crear carpeta reportes_stock si no existe
if (!file_exists($carpeta_reportes_stock)) {
    mkdir($carpeta_reportes_stock, 0777, true);
}

// Siempre guardar en reportes_stock
$carpeta_destino = $carpeta_reportes_stock;

// Determinar el prefijo del archivo según los filtros
if (!empty($proveedor_filtro)) {
    // Si hay filtro de proveedor
    $prefijo_archivo = 'Proveedor_' . preg_replace('/[^a-zA-Z0-9]/', '_', strtolower($proveedor_filtro));
} elseif ($producto_id > 0) {
    // Si hay filtro de producto específico
    $stmt = $conn->prepare("SELECT nombre FROM productos WHERE id = ?");
    $stmt->bind_param("i", $producto_id);
    $stmt->execute();
    $res = $stmt->get_result();
    $prod = $res->fetch_assoc();
    $stmt->close();
    
    $prefijo_archivo = 'Producto_' . preg_replace('/[^a-zA-Z0-9]/', '_', strtolower($prod['nombre'] ?? $producto_id));
} else {
    // Reporte general de stock
    $prefijo_archivo = 'Stock_general';
}

// ===== NUEVO: CONTROL DE ARCHIVOS GENERADOS CON SESIÓN =====
// Crear un hash único basado en los filtros para identificar el archivo
$hash_filtros = md5($producto_id . $proveedor_filtro . $fecha_desde . $fecha_hasta);

// Inicializar el array de archivos generados si no existe
if (!isset($_SESSION['archivos_generados'])) {
    $_SESSION['archivos_generados'] = [];
}

// Limpiar archivos antiguos de la sesión (más de 1 hora)
foreach ($_SESSION['archivos_generados'] as $hash => $info) {
    if (time() - $info['timestamp'] > 3600) { // 1 hora
        // Opcional: eliminar también el archivo físico
        $archivo_antiguo = $carpeta_destino . $info['archivo'];
        if (file_exists($archivo_antiguo)) {
            unlink($archivo_antiguo);
        }
        unset($_SESSION['archivos_generados'][$hash]);
    }
}

// Verificar si ya generamos este archivo
if (isset($_SESSION['archivos_generados'][$hash_filtros])) {
    $nombre_archivo = $_SESSION['archivos_generados'][$hash_filtros]['archivo'];
    $ruta_completa = $carpeta_destino . $nombre_archivo;
    
    // Verificar que el archivo aún existe físicamente
    if (file_exists($ruta_completa)) {
        // Actualizar el timestamp en la sesión
        $_SESSION['archivos_generados'][$hash_filtros]['timestamp'] = time();
        
        // Insertar en historial_reportes si es necesario (solo si no se hizo antes)
        if (!$reporte_ya_generado) {
            // Contar registros para el historial
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
            
            $count_query = "SELECT COUNT(*) as total " . $base_query;
            $total_result = $conn->query($count_query);
            $total_registros = $total_result->fetch_assoc()['total'];
            
            $usuario_id = $_SESSION['usuario_id'];
            $usuario_nombre = $_SESSION['usuario_nombre'] ?? 'Administrador';
            $fecha_generacion = date('Y-m-d H:i:s');

            $insert_query = "INSERT INTO historial_reportes 
                             (usuario_id, usuario_nombre, tipo_reporte, modulo, proveedor, fecha_generacion, total_registros, nombre_archivo) 
                             VALUES (?, ?, 'pdf', 'Historial_Stock', ?, ?, ?, ?)";

            $stmt = $conn->prepare($insert_query);
            $stmt->bind_param("isssis", 
                $usuario_id, 
                $usuario_nombre, 
                $proveedor_filtro, 
                $fecha_generacion, 
                $total_registros, 
                $nombre_archivo
            );
            $stmt->execute();
            $stmt->close();
        }
        
        // Mostrar el PDF existente
        header('Content-Type: application/pdf');
        header('Content-Disposition: inline; filename="' . $nombre_archivo . '"');
        header('Content-Length: ' . filesize($ruta_completa));
        readfile($ruta_completa);
        exit;
    } else {
        // El archivo no existe físicamente, eliminamos la referencia de la sesión
        unset($_SESSION['archivos_generados'][$hash_filtros]);
    }
}

// Si llegamos aquí, significa que el archivo no existe o expiró, generamos uno nuevo
$timestamp = date('Y-m-d_H-i-s');
$nombre_archivo = $prefijo_archivo . '_' . $timestamp . '.pdf';
$ruta_completa = $carpeta_destino . $nombre_archivo;

// Guardar en sesión para futuras referencias
$_SESSION['archivos_generados'][$hash_filtros] = [
    'archivo' => $nombre_archivo,
    'timestamp' => time()
];

// Clase PDF personalizada
class PDF extends FPDF {
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
        $this->Ln(10);
    }
    
    function Footer() {
        $this->SetY(-15);
        $this->SetFont('Arial', 'I', 8);
        $this->Cell(0, 10, 'Pagina ' . $this->PageNo() . '/{nb}', 0, 0, 'C');
    }
    
    function decodeText($text) {
        return utf8_decode($text);
    }
    
    // Función para calcular el número de líneas que ocupará un texto
    function GetNumberLines($w, $txt) {
        $cw = &$this->CurrentFont['cw'];
        if($w == 0)
            $w = $this->w - $this->rMargin - $this->x;
        $wmax = ($w - 2 * $this->cMargin) * 1000 / $this->FontSize;
        $s = str_replace("\r", '', $txt);
        $nb = strlen($s);
        if($nb > 0 && $s[$nb - 1] == "\n")
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
                    $i = $sep + 1;
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

// ===== CONSTRUIR CONSULTA PARA CONTAR REGISTROS =====
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

// ===== GUARDAR EN HISTORIAL_REPORTES (SOLO SI ES LA PRIMERA VEZ EN LA SESIÓN) =====
if (!$reporte_ya_generado) {
    $usuario_id = $_SESSION['usuario_id'];
    $usuario_nombre = $_SESSION['usuario_nombre'] ?? 'Administrador';
    $fecha_generacion = date('Y-m-d H:i:s');

    $insert_query = "INSERT INTO historial_reportes 
                     (usuario_id, usuario_nombre, tipo_reporte, modulo, proveedor, fecha_generacion, total_registros, nombre_archivo) 
                     VALUES (?, ?, 'pdf', 'Historial_Stock', ?, ?, ?, ?)";

    $stmt = $conn->prepare($insert_query);
    $stmt->bind_param("isssis", 
        $usuario_id, 
        $usuario_nombre, 
        $proveedor_filtro, 
        $fecha_generacion, 
        $total_registros, 
        $nombre_archivo
    );
    $stmt->execute();
    $stmt->close();
}

// Crear PDF
$pdf = new PDF('L', 'mm', 'A4');
$pdf->AliasNbPages();
$pdf->AddPage();
$pdf->SetAutoPageBreak(true, 20);

// ===== INFORMACIÓN DE FILTROS APLICADOS =====
$pdf->SetFont('Arial', 'B', 11);

// Mostrar filtros de fecha
if (!empty($fecha_desde) && !empty($fecha_hasta)) {
    if ($fecha_desde == $fecha_hasta) {
        $pdf->Cell(0, 6, 'Fecha: ' . date('d/m/Y', strtotime($fecha_desde)), 0, 1, 'C');
    } else {
        $pdf->Cell(0, 6, 'Rango de fechas: ' . date('d/m/Y', strtotime($fecha_desde)) . ' al ' . date('d/m/Y', strtotime($fecha_hasta)), 0, 1, 'C');
    }
} elseif (!empty($fecha_desde)) {
    $pdf->Cell(0, 6, 'Fecha desde el: ' . date('d/m/Y', strtotime($fecha_desde)), 0, 1, 'C');
} elseif (!empty($fecha_hasta)) {
    $pdf->Cell(0, 6, 'Fecha hasta: ' . date('d/m/Y', strtotime($fecha_hasta)), 0, 1, 'C');
}

// Mostrar otros filtros
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
    $pdf->Cell(0, 5, 'Filtros aplicados: ' . implode(' | ', $filtros_texto), 0, 1, 'C');
}

$pdf->Ln(5);

// Mostrar total de registros
$pdf->SetFont('Arial', 'B', 10);
$pdf->Cell(0, 6, 'Total de registros encontrados: ' . number_format($total_registros), 0, 1, 'R');
$pdf->Ln(2);

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
    $pdf->Output('F', $ruta_completa); // Guardar en archivo
    $pdf->Output('I', $nombre_archivo); // Mostrar en navegador
    exit;
}

if ($historial->num_rows === 0) {
    $pdf->SetFont('Arial', 'B', 12);
    $pdf->Cell(0, 10, 'No hay movimientos para mostrar con los filtros seleccionados', 1, 1, 'C');
    $pdf->Output('F', $ruta_completa); // Guardar en archivo
    $pdf->Output('I', $nombre_archivo); // Mostrar en navegador
    exit;
}

// ===== TABLA PRINCIPAL =====
$pdf->SetFillColor(52, 152, 219);
$pdf->SetTextColor(255, 255, 255);
$pdf->SetFont('Arial', 'B', 9);

// Definir anchos de columna
$colWidths = [25, 38, 27, 22, 27, 22, 20, 55, 41];

// Cabeceras
$headers = ['Fecha', 'Producto', 'Proveedor', 'Stock Ant.', 'Agregado/Ajuste', 'Stock Nuevo', 'Tipo', 'Nota', 'Usuario'];
foreach ($headers as $i => $header) {
    $align = ($i == 1 || $i == 2 || $i == 7 || $i == 8) ? 'L' : 'C';
    $pdf->Cell($colWidths[$i], 9, $header, 1, 0, $align, true);
}
$pdf->Ln();

$pdf->SetFillColor(245, 245, 245);
$pdf->SetTextColor(0, 0, 0);
$pdf->SetFont('Arial', '', 8);

$fill = false;
$total_entradas = 0;
$total_salidas = 0;
$total_ajustes = 0;

while($row = $historial->fetch_assoc()) {
    // Calcular altura necesaria para la nota
    $nota = !empty($row['nota']) ? $row['nota'] : '-';
    $nota_decoded = $pdf->decodeText($nota);
    
    // Número de líneas que ocupará la nota (con un margen de seguridad)
    $nbLines = $pdf->GetNumberLines($colWidths[7] - 2, $nota_decoded);
    $alturaFila = max(7, $nbLines * 4.5); // Mínimo 7mm, luego 4.5mm por línea
    
    // Verificar si hay espacio en la página
    if ($pdf->GetY() + $alturaFila > 280) {
        $pdf->AddPage();
        // Repetir cabeceras
        $pdf->SetFillColor(52, 152, 219);
        $pdf->SetTextColor(255, 255, 255);
        $pdf->SetFont('Arial', 'B', 9);
        foreach ($headers as $i => $header) {
            $align = ($i == 1 || $i == 2 || $i == 7 || $i == 8) ? 'L' : 'C';
            $pdf->Cell($colWidths[$i], 9, $header, 1, 0, $align, true);
        }
        $pdf->Ln();
        $pdf->SetFillColor(245, 245, 245);
        $pdf->SetTextColor(0, 0, 0);
        $pdf->SetFont('Arial', '', 8);
    }
    
    // Guardar posición inicial
    $x = $pdf->GetX();
    $y = $pdf->GetY();
    
    // Determinar color de texto para toda la fila según el tipo
    $esEntrada = ($row['tipo_movimiento'] == 'entrada');
    $esAjuste = ($row['tipo_movimiento'] == 'ajuste');
    $esSalida = ($row['tipo_movimiento'] == 'salida');
    
    // Establecer color base para la fila
    if ($esEntrada) {
        $pdf->SetTextColor(0, 128, 0); // Verde para entradas
    } elseif ($esAjuste || $esSalida) {
        $pdf->SetTextColor(255, 0, 0); // Rojo para ajustes y salidas
    } else {
        $pdf->SetTextColor(0, 0, 0); // Negro por defecto
    }
    
    // Fecha
    $fecha = date('d/m/Y H:i', strtotime($row['fecha_movimiento']));
    $pdf->Cell($colWidths[0], $alturaFila, $fecha, 1, 0, 'C', $fill);
    
    // Producto
    $producto = substr($pdf->decodeText($row['producto_nombre'] ?? 'N/A'), 0, 28);
    $pdf->Cell($colWidths[1], $alturaFila, $producto, 1, 0, 'L', $fill);
    
    // Proveedor
    $proveedor = substr($pdf->decodeText($row['proveedor'] ?? '-'), 0, 18);
    $pdf->Cell($colWidths[2], $alturaFila, $proveedor, 1, 0, 'L', $fill);
    
    // Stock Anterior
    $pdf->Cell($colWidths[3], $alturaFila, number_format($row['cantidad_anterior'], 0), 1, 0, 'C', $fill);
    
    // Agregado/Ajuste - Mantener color específico (verde para entrada, rojo para salida/ajuste)
    if ($row['tipo_movimiento'] == 'entrada') {
        $pdf->SetTextColor(0, 128, 0); // Verde para entradas
        $valor_mostrar = '+' . number_format($row['cantidad_agregada'], 0);
        $total_entradas += $row['cantidad_agregada'];
    } else {
        $pdf->SetTextColor(255, 0, 0); // Rojo para salidas y ajustes
        $valor_mostrar = '-' . number_format($row['cantidad_agregada'], 0);
        if ($row['tipo_movimiento'] == 'salida') {
            $total_salidas += $row['cantidad_agregada'];
        } else {
            $total_ajustes += $row['cantidad_agregada'];
        }
    }
    $pdf->Cell($colWidths[4], $alturaFila, $valor_mostrar, 1, 0, 'C', $fill);
    
    // Restaurar color de la fila para las siguientes celdas
    if ($esEntrada) {
        $pdf->SetTextColor(0, 128, 0); // Verde para entradas
    } elseif ($esAjuste || $esSalida) {
        $pdf->SetTextColor(255, 0, 0); // Rojo para ajustes y salidas
    }
    
    // Stock Nuevo
    $pdf->Cell($colWidths[5], $alturaFila, number_format($row['cantidad_nueva'], 0), 1, 0, 'C', $fill);
    
    // Tipo - Mantener color específico
    if ($row['tipo_movimiento'] == 'entrada') {
        $pdf->SetTextColor(0, 128, 0);
        $tipo_texto = 'ENTRADA';
    } else {
        $pdf->SetTextColor(255, 0, 0);
        $tipo_texto = ($row['tipo_movimiento'] == 'salida') ? 'SALIDA' : 'AJUSTE';
    }
    $pdf->Cell($colWidths[6], $alturaFila, $tipo_texto, 1, 0, 'C', $fill);
    
    // Restaurar color de la fila para la nota
    if ($esEntrada) {
        $pdf->SetTextColor(0, 128, 0); // Verde para entradas
    } elseif ($esAjuste || $esSalida) {
        $pdf->SetTextColor(255, 0, 0); // Rojo para ajustes y salidas
    }
    
    // Nota
    $pdf->SetXY($x + array_sum(array_slice($colWidths, 0, 7)), $y);
    
    // Dividir el texto en líneas manualmente
    $lineas = explode("\n", wordwrap($nota_decoded, 30, "\n"));
    $lineas_mostradas = 0;
    
    foreach ($lineas as $i => $linea) {
        if ($i >= $nbLines) break;
        $pdf->Cell($colWidths[7], 4.5, $linea, 0, 0, 'L', $fill);
        $pdf->SetXY($x + array_sum(array_slice($colWidths, 0, 7)), $y + (($i + 1) * 4.5));
        $lineas_mostradas++;
    }
    
    // Dibujar el borde completo
    $pdf->Rect($x + array_sum(array_slice($colWidths, 0, 7)), $y, $colWidths[7], $alturaFila);
    
    // Restaurar color de la fila para el usuario
    if ($esEntrada) {
        $pdf->SetTextColor(0, 128, 0); // Verde para entradas
    } elseif ($esAjuste || $esSalida) {
        $pdf->SetTextColor(255, 0, 0); // Rojo para ajustes y salidas
    }
    
    // Usuario
    $pdf->SetXY($x + array_sum(array_slice($colWidths, 0, 8)), $y);
    $usuario = substr($pdf->decodeText($row['usuario_nombre'] ?? 'Sistema'), 0, 23);
    $pdf->Cell($colWidths[8], $alturaFila, $usuario, 1, 0, 'L', $fill);
    
    // Mover a la siguiente línea y restaurar color negro por defecto
    $pdf->SetY($y + $alturaFila);
    $pdf->SetX($x);
    $pdf->SetTextColor(0, 0, 0); // Restaurar color negro
    
    $fill = !$fill;
}

// Totales
$pdf->SetFont('Arial', 'B', 8);
$pdf->SetFillColor(52, 152, 219);
$pdf->SetTextColor(255, 255, 255);

$pdf->Cell(90, 7, 'TOTALES ENTRADAS / AJUSTES DE STOCK', 1, 0, 'R', true);
$pdf->Cell(22, 7, '', 1, 0, 'C', true);
$pdf->Cell(27, 7, 'Entradas: +' . number_format($total_entradas, 0), 1, 0, 'C', true);
$pdf->Cell(22, 7, '', 1, 0, 'C', true);
$pdf->Cell(20, 7, '', 1, 0, 'C', true);
$pdf->Cell(55, 7, ' Ajustes: -' . number_format($total_ajustes, 0), 1, 0, 'L', true);
$pdf->Cell(41, 7, '', 1, 1, 'L', true);

$pdf->Ln(12);

// ===== RESUMEN POR TIPO =====
$pdf->SetFont('Arial', 'B', 11);
$pdf->Cell(0, 7, 'RESUMEN POR TIPO DE MOVIMIENTO', 0, 1, 'L');
$pdf->Ln(2);

// Consulta de resumen
$resumen_query = "SELECT 
                    h.tipo_movimiento,
                    COUNT(*) as cantidad,
                    SUM(h.cantidad_agregada) as total
                  FROM historial_stock h
                  LEFT JOIN productos p ON h.producto_id = p.id
                  WHERE 1=1";

if ($producto_id > 0) {
    $resumen_query .= " AND h.producto_id = $producto_id";
}
if (!empty($proveedor_filtro)) {
    $proveedor_filtro_escaped = $conn->real_escape_string($proveedor_filtro);
    $resumen_query .= " AND p.proveedor = '$proveedor_filtro_escaped'";
}
if (!empty($fecha_desde)) {
    $resumen_query .= " AND DATE(h.fecha_movimiento) >= '$fecha_desde'";
}
if (!empty($fecha_hasta)) {
    $resumen_query .= " AND DATE(h.fecha_movimiento) <= '$fecha_hasta'";
}

$resumen_query .= " GROUP BY h.tipo_movimiento ORDER BY FIELD(tipo_movimiento, 'entrada', 'salida', 'ajuste')";

$resumen = $conn->query($resumen_query);

if ($resumen && $resumen->num_rows > 0) {
    $pdf->SetFillColor(46, 204, 113);
    $pdf->SetTextColor(255, 255, 255);
    $pdf->SetFont('Arial', 'B', 9);
    
    $pdf->Cell(70, 8, 'Tipo', 1, 0, 'C', true);
    $pdf->Cell(50, 8, 'Cantidad', 1, 0, 'C', true);
    $pdf->Cell(70, 8, 'Total Unidades agregadas / ajustadas', 1, 1, 'C', true);
    
    $pdf->SetFillColor(245, 245, 245);
    $pdf->SetTextColor(0, 0, 0);
    $pdf->SetFont('Arial', '', 9);
    
    $fill = false;
    while($row = $resumen->fetch_assoc()) {
        if ($row['tipo_movimiento'] == 'entrada') {
            $pdf->SetTextColor(0, 128, 0);
            $tipo_texto = 'Reabastecimientos de stock';
            $total_mostrar = '+' . number_format($row['total'], 0);
        } else {
            $pdf->SetTextColor(255, 0, 0);
            $tipo_texto = ($row['tipo_movimiento'] == 'salida') ? 'SALIDAS' : 'Ajustes de stock';
            $total_mostrar = '-' . number_format($row['total'], 0);
        }
        
        $pdf->Cell(70, 7, $tipo_texto, 1, 0, 'L', $fill);
        $pdf->SetTextColor(0, 0, 0);
        $pdf->Cell(50, 7, $row['cantidad'], 1, 0, 'C', $fill);
        $pdf->Cell(70, 7, $total_mostrar . ' unidades', 1, 1, 'R', $fill);
        
        $fill = !$fill;
    }
    
    // Balance
    $balance = $total_entradas - ($total_salidas + $total_ajustes);
    
    $pdf->SetFont('Arial', 'B', 9);
    $pdf->SetFillColor(52, 152, 219);
    $pdf->SetTextColor(255, 255, 255);
    
    $pdf->Cell(120, 7, '', 1, 0, 'R', true);
    $pdf->Cell(70, 7,  '', 1, 1, 'C', true);
}

// Guardar el PDF en archivo y mostrarlo en el navegador
$pdf->Output('F', $ruta_completa); // Guardar en archivo
ob_end_clean();
$pdf->Output('I', $nombre_archivo); // Mostrar en navegador
?>