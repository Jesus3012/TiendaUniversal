<?php
date_default_timezone_set('America/Mexico_City');
require_once 'includes/db.php';
require_once 'includes/fpdf.php';

// Verificar sesión
session_start();
if (!isset($_SESSION['usuario_id'])) {
    header("Location: login.php");
    exit;
}

// Obtener parámetros de filtro
$tipo = isset($_GET['tipo']) ? $_GET['tipo'] : 'todos';
$busqueda = isset($_GET['busqueda']) ? trim($_GET['busqueda']) : '';
$proveedor = isset($_GET['proveedor']) ? trim($_GET['proveedor']) : '';
$categoria = isset($_GET['categoria']) ? trim($_GET['categoria']) : '';

// Determinar la carpeta de destino según el proveedor
$esReporteGeneral = ($proveedor === '');
$carpetaDestino = $esReporteGeneral ? 'Stock_General' : 'Stock_Proveedor';
$rutaCarpeta = "uploads/" . $carpetaDestino . "/";

// Crear la carpeta si no existe
if (!file_exists($rutaCarpeta)) {
    mkdir($rutaCarpeta, 0777, true);
}

// ============================================
// FUNCIÓN PARA GUARDAR PDF EN EL SERVIDOR
// ============================================
function guardarPDFenServidor($pdfContent, $nombreArchivo, $carpeta, $tipo, $proveedor, $totalRegistros) {
    global $conn;
    
    $rutaCarpeta = "uploads/" . $carpeta . "/";
    $rutaCompleta = $rutaCarpeta . $nombreArchivo;
    
    // Verificar si el archivo ya existe y generar nombre único
    $contador = 1;
    $info = pathinfo($nombreArchivo);
    while (file_exists($rutaCompleta)) {
        $nombreArchivo = $info['filename'] . "_" . $contador . "." . $info['extension'];
        $rutaCompleta = $rutaCarpeta . $nombreArchivo;
        $contador++;
    }
    
    // Guardar el archivo
    if (file_put_contents($rutaCompleta, $pdfContent)) {
        // Guardar en base de datos
        $usuario_id = $_SESSION['usuario_id'] ?? 0;
        $usuario_nombre = $_SESSION['nombre'] ?? 'Sistema';
        $proveedor_val = $proveedor ?: 'todos';
        $modulo = ($carpeta === 'Stock_General') ? 'reporte inventario - general' : 'reporte inventario - proveedor';
        
        // Verificar si ya existe un registro con el mismo nombre
        $check_sql = "SELECT id FROM historial_reportes WHERE nombre_archivo = '$rutaCompleta'";
        $check_result = $conn->query($check_sql);
        
        if ($check_result && $check_result->num_rows == 0) {
            $sql = "INSERT INTO historial_reportes (
                usuario_id, 
                usuario_nombre, 
                tipo_reporte, 
                modulo, 
                proveedor, 
                fecha_generacion, 
                total_registros, 
                nombre_archivo
            ) VALUES (
                $usuario_id, 
                '$usuario_nombre', 
                'pdf', 
                '$modulo', 
                '$proveedor_val', 
                NOW(), 
                $totalRegistros, 
                '$rutaCompleta'
            )";
            $conn->query($sql);
        }
        
        return true;
    }
    
    return false;
}

// Obtener datos de la tienda
$sqlConfig = "SELECT nombre, logo FROM configuracion_galeria LIMIT 1";
$resultConfig = $conn->query($sqlConfig);
$configTienda = $resultConfig->fetch_assoc();
$nombreTienda = $configTienda['nombre'] ?? 'TIENDA PESCADORES';
$logoTiendaPath = $configTienda['logo'] ?? '';

// Buscar logo tienda
if (empty($logoTiendaPath) || !file_exists($logoTiendaPath)) {
    $rutasPosibles = [
        '../img/logo.png', '../img/logo.jpg', '../img/panel_principal.jpg',
        '../img/panel_principal.png', '../dist/img/logo.png', '../dist/img/logo.jpg',
        'img/logo.png', 'img/logo.jpg'
    ];
    foreach ($rutasPosibles as $ruta) {
        if (file_exists($ruta)) {
            $logoTiendaPath = $ruta;
            break;
        }
    }
}

// ============================================
// LOGO DEL PROVEEDOR CON REDIMENSIONADO AUTOMÁTICO
// ============================================
$logoProveedorPath = '';
$logoProveedorWidth = 22;  // Ancho calculado
$logoProveedorHeight = 22; // Alto calculado
$inicialesProveedor = '';

if ($proveedor !== '') {
    $sqlLogoProveedor = "SELECT logo FROM proveedores WHERE nombre = ? LIMIT 1";
    $stmtLogo = $conn->prepare($sqlLogoProveedor);
    $stmtLogo->bind_param("s", $proveedor);
    $stmtLogo->execute();
    $resultLogo = $stmtLogo->get_result();
    if ($resultLogo && $row = $resultLogo->fetch_assoc()) {
        $logoProveedorPath = $row['logo'] ?? '';
        if (!empty($logoProveedorPath) && file_exists($logoProveedorPath)) {
            // Obtener dimensiones de la imagen
            $size = getimagesize($logoProveedorPath);
            if ($size !== false) {
                $anchoOriginal = $size[0];
                $altoOriginal = $size[1];
                $ratio = $anchoOriginal / $altoOriginal;
                
                // USAR EL LADO MÁS PEQUEÑO para decidir el tamaño
                $ladoMenor = min($anchoOriginal, $altoOriginal);
                
                if ($ladoMenor < 310) {
                    $maxSize = 45;  // Logo muy pequeño (menos de 300px)
                } elseif ($ladoMenor < 500) {
                    $maxSize = 28;  // Logo pequeño (300-500px)
                } elseif ($ladoMenor > 800) {
                    $maxSize = 18;  // Logo muy grande (más de 800px)
                } else {
                    $maxSize = 22;  // Tamaño normal (500-800px)
                }
                
                // Calcular tamaño proporcional
                if ($anchoOriginal > $altoOriginal) {
                    // Logo horizontal (más ancho que alto)
                    $logoProveedorWidth = $maxSize;
                    $logoProveedorHeight = $maxSize / $ratio;
                } else {
                    // Logo vertical o cuadrado
                    $logoProveedorHeight = $maxSize;
                    $logoProveedorWidth = $maxSize * $ratio;
                }
            }
        } else {
            $logoProveedorPath = ''; // No existe el archivo
        }
    }
    
    // Iniciales del proveedor (para cuando no tiene logo)
    $palabras = explode(' ', $proveedor);
    if (count($palabras) >= 2) {
        $inicialesProveedor = strtoupper(substr($palabras[0], 0, 1) . substr($palabras[1], 0, 1));
    } else {
        $inicialesProveedor = strtoupper(substr($proveedor, 0, 2));
    }
}

// Consulta 1: Productos con stock mayor a 0 (ordenados de mayor a menor)
$queryConStock = "SELECT nombre, proveedor, cantidad, tipo_inventario, categoria 
                  FROM productos 
                  WHERE activo = 1 AND cantidad > 0";
$paramsCon = [];
$typesCon = "";

if ($tipo !== 'todos') {
    $queryConStock .= " AND tipo_inventario = ?";
    $paramsCon[] = $tipo;
    $typesCon .= "s";
}

if ($proveedor !== '') {
    $queryConStock .= " AND proveedor = ?";
    $paramsCon[] = $proveedor;
    $typesCon .= "s";
}

if ($categoria !== '') {
    $queryConStock .= " AND categoria = ?";
    $paramsCon[] = $categoria;
    $typesCon .= "s";
}

if ($busqueda !== '') {
    $queryConStock .= " AND (nombre LIKE ? OR proveedor LIKE ? OR categoria LIKE ?)";
    $search = "%$busqueda%";
    $paramsCon[] = $search;
    $paramsCon[] = $search;
    $paramsCon[] = $search;
    $typesCon .= "sss";
}

$queryConStock .= " ORDER BY cantidad DESC, nombre ASC";

// Ejecutar consulta con stock
$stmt = $conn->prepare($queryConStock);
if (!empty($paramsCon)) {
    $stmt->bind_param($typesCon, ...$paramsCon);
}
$stmt->execute();
$resultCon = $stmt->get_result();

$productosConStock = [];
$totalCantidadConStock = 0;
while ($row = $resultCon->fetch_assoc()) {
    $productosConStock[] = $row;
    $totalCantidadConStock += floatval($row['cantidad']);
}

// Consulta 2: Productos sin stock (cantidad = 0) ordenados alfabéticamente
$querySinStock = "SELECT nombre, proveedor, cantidad, tipo_inventario, categoria 
                  FROM productos 
                  WHERE activo = 1 AND cantidad = 0";
$paramsSin = [];
$typesSin = "";

if ($tipo !== 'todos') {
    $querySinStock .= " AND tipo_inventario = ?";
    $paramsSin[] = $tipo;
    $typesSin .= "s";
}

if ($proveedor !== '') {
    $querySinStock .= " AND proveedor = ?";
    $paramsSin[] = $proveedor;
    $typesSin .= "s";
}

if ($categoria !== '') {
    $querySinStock .= " AND categoria = ?";
    $paramsSin[] = $categoria;
    $typesSin .= "s";
}

if ($busqueda !== '') {
    $querySinStock .= " AND (nombre LIKE ? OR proveedor LIKE ? OR categoria LIKE ?)";
    $search = "%$busqueda%";
    $paramsSin[] = $search;
    $paramsSin[] = $search;
    $paramsSin[] = $search;
    $typesSin .= "sss";
}

$querySinStock .= " ORDER BY nombre ASC";

// Ejecutar consulta sin stock
$stmt = $conn->prepare($querySinStock);
if (!empty($paramsSin)) {
    $stmt->bind_param($typesSin, ...$paramsSin);
}
$stmt->execute();
$resultSin = $stmt->get_result();

$productosSinStock = [];
while ($row = $resultSin->fetch_assoc()) {
    $productosSinStock[] = $row;
}

// Calcular estadísticas
$totalConStock = count($productosConStock);
$totalSinStock = count($productosSinStock);
$stockTotalGeneral = 0;

foreach ($productosConStock as $producto) {
    $stockTotalGeneral += floatval($producto['cantidad']);
}

// Crear PDF con diseño mejorado
class PDF extends FPDF
{
    var $logoTiendaPath;
    var $logoProveedorPath;
    var $logoProveedorWidth;
    var $logoProveedorHeight;
    var $inicialesProveedor;
    var $nombreTienda;
    var $proveedorNombre;
    var $colorPrimary;
    
    function SetLogos($logoTienda, $logoProveedor, $logoWidth, $logoHeight, $iniciales, $nombreTienda, $proveedorNombre) {
        $this->logoTiendaPath = $logoTienda;
        $this->logoProveedorPath = $logoProveedor;
        $this->logoProveedorWidth = $logoWidth;
        $this->logoProveedorHeight = $logoHeight;
        $this->inicialesProveedor = $iniciales;
        $this->nombreTienda = $nombreTienda;
        $this->proveedorNombre = $proveedorNombre;
        $this->colorPrimary = [255, 140, 0];
    }
    
    function __construct()
    {
        parent::__construct('L', 'mm', 'A4');
        $this->SetMargins(8, 8, 8);
        $this->SetAutoPageBreak(true, 15);
    }
    
    // Cabecera personalizada con logos
    function Header()
    {
        $pageWidth = $this->GetPageWidth();
        $logoY = 8;
        $logoTiendaSize = 22;
        
        // Logo Tienda (izquierda) - tamaño fijo
        if (!empty($this->logoTiendaPath) && file_exists($this->logoTiendaPath)) {
            $this->Image($this->logoTiendaPath, 12, $logoY, $logoTiendaSize, $logoTiendaSize);
        }
        
        // Logo Proveedor o Iniciales (derecha) - CON TAMAÑO PROPORCIONAL
        if (!empty($this->proveedorNombre)) {
            if (!empty($this->logoProveedorPath) && file_exists($this->logoProveedorPath)) {
                // Posición X: borde derecho - ancho del logo - margen
                $logoX = $pageWidth - $this->logoProveedorWidth - 12;
                $this->Image($this->logoProveedorPath, $logoX, $logoY, $this->logoProveedorWidth, $this->logoProveedorHeight);
            } else {
                // Solo texto con las iniciales
                $textX = $pageWidth - 50;
                $textY = $logoY + 10;
                $this->SetFont('Arial', 'B', 24);
                $this->SetTextColor($this->colorPrimary[0], $this->colorPrimary[1], $this->colorPrimary[2]);
                $this->Text($textX, $textY, $this->inicialesProveedor);
                $this->SetTextColor(0, 0, 0);
            }
        }
        
        // Nombre Tienda centrado
        $this->SetY($logoY + 5);
        $this->SetFont('Arial', 'B', 12);
        $this->SetTextColor(60, 60, 60);
        $this->Cell(0, 8, utf8_decode(strtoupper($this->nombreTienda)), 0, 1, 'C');
        
        // Línea decorativa superior naranja
        $this->SetDrawColor(255, 140, 0);
        $this->SetLineWidth(1.5);
        $this->Line(12, $logoY + $logoTiendaSize + 6, $pageWidth - 12, $logoY + $logoTiendaSize + 6);
        
        // Título principal
        $this->SetY($logoY + $logoTiendaSize + 14);
        $this->SetFont('Arial', 'B', 20);
        $this->SetTextColor(255, 140, 0);
        $this->Cell(0, 10, 'REPORTE DE INVENTARIO', 0, 1, 'C');
        
        // Subtítulo
        $this->SetFont('Arial', '', 10);
        $this->SetTextColor(100, 100, 100);
        $this->Cell(0, 6, 'Control de stock - Productos con y sin inventario', 0, 1, 'C');
        
        // Fecha de generación
        $this->SetFont('Arial', 'I', 9);
        $this->SetTextColor(120, 120, 120);
        $this->Cell(0, 5, 'Generado: ' . date('d/m/Y H:i'), 0, 1, 'C');
        
        $this->Ln(8);
    }
    
    function Footer()
    {
        $this->SetY(-12);
        
        // Línea decorativa naranja
        $this->SetDrawColor(255, 140, 0);
        $this->SetLineWidth(0.3);
        $this->Line($this->GetX(), $this->GetY(), $this->GetPageWidth() - $this->GetX(), $this->GetY());
        
        // Número de página
        $this->SetFont('Arial', 'I', 8);
        $this->SetTextColor(100, 100, 100);
        $this->Cell(0, 5, 'Página ' . $this->PageNo() . ' | Sistema de Inventario', 0, 0, 'C');
    }
    
    function FiltrosAplicados($filtros)
    {
        // Fondo naranja claro
        $this->SetFillColor(255, 245, 235);
        $this->Rect($this->GetX(), $this->GetY(), $this->GetPageWidth() - ($this->GetX() * 2), 20, 'F');
        
        $this->SetFont('Arial', 'B', 10);
        $this->SetTextColor(255, 140, 0);
        $this->SetY($this->GetY() + 4);
        $this->Cell(0, 6, 'FILTROS APLICADOS:', 0, 1, 'L');
        
        $this->SetFont('Arial', '', 9);
        $this->SetTextColor(80, 80, 80);
        
        $texto = '';
        if (!empty($filtros['tipo']) && $filtros['tipo'] !== 'todos') {
            $texto .= 'Tipo: ' . ($filtros['tipo'] == 'producto' ? 'Productos' : 'Insumos') . '   ';
        }
        if (!empty($filtros['proveedor'])) {
            $texto .= 'Proveedor: ' . htmlspecialchars($filtros['proveedor']) . '   ';
        }
        if (!empty($filtros['categoria'])) {
            $texto .= 'Categoría: ' . htmlspecialchars($filtros['categoria']) . '   ';
        }
        if (!empty($filtros['busqueda'])) {
            $texto .= 'Búsqueda: "' . htmlspecialchars($filtros['busqueda']) . '"';
        }
        
        if ($texto === '') {
            $texto = 'Ningun filtro aplicado - Reporte completo';
        }
        
        $this->SetX($this->GetX() + 5);
        $this->MultiCell($this->GetPageWidth() - ($this->GetX() * 2), 5, $texto);
        $this->Ln(8);
    }
    
    function TablaHeader($titulo, $colorFondo = null)
    {
        if ($colorFondo === null) {
            $colorFondo = [255, 140, 0];
        }
        
        $this->SetFont('Arial', 'B', 11);
        $this->SetFillColor($colorFondo[0], $colorFondo[1], $colorFondo[2]);
        $this->SetTextColor(255, 255, 255);
        
        // Anchos de columna - PROVEEDOR PRIMERO
        $anchoTotal = $this->GetPageWidth() - ($this->GetX() * 2);
        $anchoProveedor = $anchoTotal * 0.30;
        $anchoNombre = $anchoTotal * 0.55;
        $anchoCantidad = $anchoTotal * 0.15;
        
        $this->Cell($anchoProveedor, 10, 'PROVEEDOR', 1, 0, 'C', true);
        $this->Cell($anchoNombre, 10, 'NOMBRE DEL PRODUCTO', 1, 0, 'C', true);
        $this->Cell($anchoCantidad, 10, $titulo, 1, 1, 'C', true);
        
        $this->SetTextColor(0, 0, 0);
        
        return [$anchoProveedor, $anchoNombre, $anchoCantidad];
    }
    
    function TablaRow($nombre, $proveedor, $cantidad, $tipo_inventario, $anchos, $esAgotado = false)
    {
        list($anchoProveedor, $anchoNombre, $anchoCantidad) = $anchos;
        
        $this->SetFont('Arial', '', 9);
        
        static $alternar = 0;
        $fill = ($alternar % 2 == 0);
        if ($fill) {
            $this->SetFillColor(255, 245, 235);
        } else {
            $this->SetFillColor(255, 255, 255);
        }
        
        $this->SetTextColor(0, 0, 0);
        
        // Proveedor
        $proveedor_texto = $proveedor ? utf8_decode($proveedor) : 'No especificado';
        $this->Cell($anchoProveedor, 9, $proveedor_texto, 1, 0, 'L', $fill);
        
        // Nombre del producto
        $nombre_corto = utf8_decode(substr($nombre, 0, 50));
        $this->Cell($anchoNombre, 9, $nombre_corto, 1, 0, 'L', $fill);
        
        // Cantidad
        if ($esAgotado) {
            $this->SetFont('Arial', 'B', 10);
            $this->SetTextColor(220, 53, 69);
            $this->Cell($anchoCantidad, 9, 'AGOTADO', 1, 1, 'C', $fill);
        } else {
            if ($tipo_inventario == 'insumo') {
                $cantidadDisplay = number_format($cantidad, 2) . ' m';
            } else {
                $cantidadDisplay = number_format($cantidad, 0);
            }
            
            $this->SetFont('Arial', 'B', 10);
            $this->SetTextColor(40, 167, 69);
            $this->Cell($anchoCantidad, 9, $cantidadDisplay, 1, 1, 'R', $fill);
        }
        
        $this->SetTextColor(0, 0, 0);
        $this->SetFont('Arial', '', 9);
        
        $alternar++;
    }
    
    function SeccionTabla($titulo, $productos, $colorHeader, $esSeccionAgotados = false)
    {
        if (empty($productos)) {
            return false;
        }
        
        $this->Ln(6);
        $this->SetFont('Arial', 'B', 13);
        $this->SetTextColor($colorHeader[0], $colorHeader[1], $colorHeader[2]);
        $this->Cell(0, 8, $titulo, 0, 1, 'L');
        
        $this->SetDrawColor($colorHeader[0], $colorHeader[1], $colorHeader[2]);
        $this->SetLineWidth(0.5);
        $this->Line($this->GetX(), $this->GetY(), $this->GetPageWidth() - $this->GetX(), $this->GetY());
        $this->Ln(4);
        
        if ($esSeccionAgotados) {
            $anchos = $this->TablaHeader('ESTADO', $colorHeader);
        } else {
            $anchos = $this->TablaHeader('CANTIDAD', $colorHeader);
        }
        
        $totalCantidadSeccion = 0;
        $totalRegistros = 0;
        
        foreach ($productos as $producto) {
            if (!$esSeccionAgotados) {
                $totalCantidadSeccion += floatval($producto['cantidad']);
            }
            $totalRegistros++;
            
            $this->TablaRow(
                $producto['nombre'],
                $producto['proveedor'],
                $producto['cantidad'],
                $producto['tipo_inventario'],
                $anchos,
                $esSeccionAgotados
            );
            
            if ($this->GetY() > 220) {
                $this->AddPage();
                $anchos = $this->TablaHeader($esSeccionAgotados ? 'ESTADO' : 'CANTIDAD', $colorHeader);
            }
        }
        
        $this->SetDrawColor(200, 200, 200);
        $this->Line($this->GetX(), $this->GetY(), $this->GetPageWidth() - $this->GetX(), $this->GetY());
        $this->Ln(2);
        
        $this->SetFont('Arial', 'I', 9);
        $this->SetTextColor(100, 100, 100);
        $this->Cell(0, 5, 'Total de registros: ' . $totalRegistros, 0, 1, 'R');
        
        return $totalCantidadSeccion;
    }
    
    function TarjetaResumen($totalConStock, $totalSinStock, $stockTotalGeneral)
    {
        $this->Ln(8);
        
        $this->SetFillColor(255, 245, 235);
        $this->Rect($this->GetX(), $this->GetY(), $this->GetPageWidth() - ($this->GetX() * 2), 55, 'F');
        
        $this->SetFont('Arial', 'B', 13);
        $this->SetTextColor(255, 140, 0);
        $this->SetY($this->GetY() + 5);
        $this->Cell(0, 8, 'RESUMEN DEL INVENTARIO', 0, 1, 'C');
        
        $this->SetFont('Arial', '', 10);
        $this->SetTextColor(80, 80, 80);
        
        // Distribución en dos filas
        $this->SetY($this->GetY() + 5);
        
        // Fila 1
        $this->SetX($this->GetX() + 20);
        $this->Cell(50, 8, 'Productos con stock:', 0, 0, 'L');
        $this->SetFont('Arial', 'B', 12);
        $this->SetTextColor(40, 167, 69);
        $this->Cell(40, 8, number_format($totalConStock), 0, 0, 'L');
        
        $this->SetX($this->GetX() + 60);
        $this->SetFont('Arial', '', 10);
        $this->SetTextColor(80, 80, 80);
        $this->Cell(50, 8, 'Productos agotados:', 0, 0, 'L');
        $this->SetFont('Arial', 'B', 12);
        $this->SetTextColor(220, 53, 69);
        $this->Cell(40, 8, number_format($totalSinStock), 0, 1, 'L');
        
        $this->SetY($this->GetY() + 5);
        
        // Línea separadora
        $this->SetDrawColor(255, 140, 0);
        $this->SetLineWidth(0.3);
        $this->Line($this->GetX() + 15, $this->GetY(), $this->GetPageWidth() - $this->GetX() - 15, $this->GetY());
        $this->Ln(5);
        
        // Inventario total
        $this->SetX($this->GetX() + 20);
        $this->SetFont('Arial', 'B', 12);
        $this->SetTextColor(80, 80, 80);
        $this->Cell(70, 8, 'INVENTARIO TOTAL:', 0, 0, 'L');
        $this->SetFont('Arial', 'B', 16);
        $this->SetTextColor(255, 140, 0);
        $this->Cell(80, 8, number_format($stockTotalGeneral) . ' unidades', 0, 1, 'L');
        
        $this->Ln(5);
    }
}

// Crear instancia del PDF
$pdf = new PDF();
$pdf->SetLogos($logoTiendaPath, $logoProveedorPath, $logoProveedorWidth, $logoProveedorHeight, $inicialesProveedor, $nombreTienda, $proveedor);
$pdf->AddPage();

// Mostrar filtros aplicados
$filtros = [
    'tipo' => $tipo,
    'proveedor' => $proveedor,
    'categoria' => $categoria,
    'busqueda' => $busqueda
];
$pdf->FiltrosAplicados($filtros);

// Sección 1: Productos con stock
if (!empty($productosConStock)) {
    $pdf->SeccionTabla('PRODUCTOS CON STOCK', $productosConStock, [40, 167, 69], false);
}

// Sección 2: Productos sin stock
if (!empty($productosSinStock)) {
    $pdf->SeccionTabla('PRODUCTOS AGOTADOS', $productosSinStock, [220, 53, 69], true);
}

// Mostrar resumen
$pdf->TarjetaResumen($totalConStock, $totalSinStock, $stockTotalGeneral);

// Información adicional
$pdf->SetY($pdf->GetY() + 5);
$pdf->SetFont('Arial', 'I', 8);
$pdf->SetTextColor(150, 150, 150);
$pdf->Cell(0, 5, 'Este reporte muestra los productos ordenados por cantidad disponible.', 0, 1, 'C');
$pdf->Cell(0, 5, 'Los productos en rojo requieren reabastecimiento inmediato.', 0, 1, 'C');

// Generar el contenido del PDF
$pdfContent = $pdf->Output('S'); // 'S' para obtener el PDF como string

// ============================================
// GUARDAR EL PDF EN EL SERVIDOR
// ============================================
$fechaActual = date('Y-m-d');
$horaActual = date('H-i-s');
$nombreArchivo = "reporte_inventario_{$fechaActual}.pdf";

// Si es reporte por proveedor, agregar el nombre del proveedor al archivo
if ($proveedor !== '') {
    $proveedorLimpio = preg_replace('/[^a-zA-Z0-9]/', '_', $proveedor);
    $nombreArchivo = "reporte_inventario_{$proveedorLimpio}_{$fechaActual}.pdf";
}

// Guardar en el servidor
$totalRegistros = $totalConStock + $totalSinStock;
$guardadoExitoso = guardarPDFenServidor($pdfContent, $nombreArchivo, $carpetaDestino, 'inventario', $proveedor, $totalRegistros);

// Mostrar mensaje de éxito/error (opcional - para depuración)
if ($guardadoExitoso) {
    // El PDF se guardó correctamente en el servidor
    // Puedes agregar un log si lo deseas
}

// ============================================
// SALIDA DEL PDF (para mostrar/descargar)
// ============================================
// Determinar si debe descargarse o mostrarse
$disposition = isset($_GET['download']) ? 'D' : 'I';

// Enviar headers para el PDF
header('Content-Type: application/pdf');
header('Content-Disposition: ' . ($disposition == 'D' ? 'attachment' : 'inline') . '; filename="' . $nombreArchivo . '"');
header('Cache-Control: private, max-age=0, must-revalidate');
header('Pragma: public');

// Enviar el contenido del PDF
echo $pdfContent;
?>