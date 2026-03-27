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

// Crear PDF con diseño mejorado
class PDF extends FPDF
{
    function __construct()
    {
        parent::__construct('L', 'mm', 'A4');
        $this->SetMargins(8, 8, 8);
        $this->SetAutoPageBreak(true, 15);
    }
    
    // Cabecera personalizada
    function Header()
    {
        // Línea decorativa superior naranja
        $this->SetDrawColor(255, 140, 0);
        $this->SetLineWidth(2);
        $this->Line($this->GetX(), 8, $this->GetPageWidth() - $this->GetX(), 8);
        
        // Título principal
        $this->SetY(15);
        $this->SetFont('Arial', 'B', 24);
        $this->SetTextColor(255, 140, 0);
        $this->Cell(0, 12, 'REPORTE DE INVENTARIO', 0, 1, 'C');
        
        // Subtítulo
        $this->SetFont('Arial', '', 11);
        $this->SetTextColor(100, 100, 100);
        $this->Cell(0, 6, 'Control de stock - Productos con y sin inventario', 0, 1, 'C');
        
        // Fecha de generación
        $this->SetFont('Arial', 'I', 9);
        $this->SetTextColor(120, 120, 120);
        $this->Cell(0, 5, 'Generado: ' . date('d/m/Y H:i:s'), 0, 1, 'C');
        
        // Línea decorativa inferior naranja
        $this->SetDrawColor(255, 140, 0);
        $this->SetLineWidth(0.5);
        $this->Line($this->GetX(), $this->GetY() + 2, $this->GetPageWidth() - $this->GetX(), $this->GetY() + 2);
        $this->Ln(10);
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
        
        $this->SetFont('Arial', 'B', 12);
        $this->SetFillColor($colorFondo[0], $colorFondo[1], $colorFondo[2]);
        $this->SetTextColor(255, 255, 255);
        
        // Anchos de columna - PROVEEDOR PRIMERO
        $anchoTotal = $this->GetPageWidth() - ($this->GetX() * 2);
        $anchoProveedor = $anchoTotal * 0.30; // 30% para proveedor
        $anchoNombre = $anchoTotal * 0.55;    // 55% para nombre
        $anchoCantidad = $anchoTotal * 0.15;  // 15% para cantidad
        
        $this->Cell($anchoProveedor, 12, 'PROVEEDOR', 1, 0, 'C', true);
        $this->Cell($anchoNombre, 12, 'NOMBRE DEL PRODUCTO', 1, 0, 'C', true);
        $this->Cell($anchoCantidad, 12, $titulo, 1, 1, 'C', true);
        
        $this->SetTextColor(0, 0, 0);
        
        return [$anchoProveedor, $anchoNombre, $anchoCantidad];
    }
    
    function TablaRow($nombre, $proveedor, $cantidad, $tipo_inventario, $anchos, $esAgotado = false)
    {
        list($anchoProveedor, $anchoNombre, $anchoCantidad) = $anchos;
        
        $this->SetFont('Arial', '', 10);
        
        // Alternar colores de fila
        static $alternar = 0;
        $fill = ($alternar % 2 == 0);
        if ($fill) {
            $this->SetFillColor(255, 245, 235);
        } else {
            $this->SetFillColor(255, 255, 255);
        }
        
        $this->SetTextColor(0, 0, 0);
        
        // Proveedor (primera columna)
        $proveedor_texto = $proveedor ? utf8_decode($proveedor) : 'No especificado';
        $this->Cell($anchoProveedor, 10, $proveedor_texto, 1, 0, 'L', $fill);
        
        // Nombre del producto (segunda columna)
        $nombre_corto = utf8_decode($nombre);
        $this->Cell($anchoNombre, 10, $nombre_corto, 1, 0, 'L', $fill);
        
        // Cantidad con formato (tercera columna)
        if ($esAgotado) {
            $this->SetFont('Arial', 'B', 11);
            $this->SetTextColor(220, 53, 69); // Rojo para agotados
            $cantidadDisplay = 'AGOTADO';
            $this->Cell($anchoCantidad, 10, $cantidadDisplay, 1, 1, 'C', $fill);
        } else {
            if ($tipo_inventario == 'insumo') {
                $cantidadDisplay = number_format($cantidad, 2);
                $unidad = ' m';
            } else {
                $cantidadDisplay = number_format($cantidad, 0);
                $unidad = ' piezas';
            }
            
            $this->SetFont('Arial', 'B', 11);
            $this->SetTextColor(40, 167, 69); // Verde para productos con stock
            $this->Cell($anchoCantidad, 10, $cantidadDisplay . $unidad, 1, 1, 'R', $fill);
        }
        
        $this->SetTextColor(0, 0, 0);
        $this->SetFont('Arial', '', 10);
        
        $alternar++;
    }
    
    function SeccionTabla($titulo, $productos, $colorHeader, $esSeccionAgotados = false)
    {
        if (empty($productos)) {
            return false;
        }
        
        // Título de sección con fondo
        $this->Ln(8);
        $this->SetFont('Arial', 'B', 14);
        $this->SetTextColor($colorHeader[0], $colorHeader[1], $colorHeader[2]);
        $this->Cell(0, 10, $titulo, 0, 1, 'L');
        
        // Línea decorativa
        $this->SetDrawColor($colorHeader[0], $colorHeader[1], $colorHeader[2]);
        $this->SetLineWidth(0.5);
        $this->Line($this->GetX(), $this->GetY(), $this->GetPageWidth() - $this->GetX(), $this->GetY());
        $this->Ln(5);
        
        // Cabecera de tabla
        if ($esSeccionAgotados) {
            $anchos = $this->TablaHeader('ESTADO', $colorHeader);
        } else {
            $anchos = $this->TablaHeader('CANTIDAD', $colorHeader);
        }
        
        // Variable para acumular el total de cantidad
        $totalCantidadSeccion = 0;
        $totalRegistros = 0;
        
        // Filas de la tabla
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
            
            // Control de salto de página
            if ($this->GetY() > 200) {
                $this->AddPage();
                $anchos = $this->TablaHeader($esSeccionAgotados ? 'ESTADO' : 'CANTIDAD', $colorHeader);
            }
        }
        
        // Línea separadora
        $this->SetDrawColor(200, 200, 200);
        $this->Line($this->GetX(), $this->GetY(), $this->GetPageWidth() - $this->GetX(), $this->GetY());
        $this->Ln(3);
    
        
        // Mostrar contador de registros
        $this->SetFont('Arial', 'I', 9);
        $this->SetTextColor(100, 100, 100);
        $this->Cell(0, 6, 'Total de registros: ' . $totalRegistros, 0, 1, 'R');
        
        return $totalCantidadSeccion;
    }
    
    function TarjetaResumen($totalConStock, $totalSinStock, $totalCantidadConStock, $stockTotalGeneral)
    {
        $this->Ln(10);
        
        // Fondo naranja suave
        $this->SetFillColor(255, 245, 235);
        $this->Rect($this->GetX(), $this->GetY(), $this->GetPageWidth() - ($this->GetX() * 2), 70, 'F');
        
        $this->SetFont('Arial', 'B', 14);
        $this->SetTextColor(255, 140, 0);
        $this->SetY($this->GetY() + 5);
        $this->Cell(0, 10, 'RESUMEN DEL INVENTARIO', 0, 1, 'C');
        
        $this->SetFont('Arial', '', 11);
        $this->SetTextColor(80, 80, 80);
        
        // Calcular ancho para distribución
        $anchoTotal = $this->GetPageWidth() - ($this->GetX() * 2);
        $anchoColumna = $anchoTotal / 2;
        
        $this->SetY($this->GetY() + 5);
        
        // Fila 1: Productos con stock
        $this->SetX($this->GetX() + 10);
        $this->Cell($anchoColumna - 10, 10, 'Productos con stock:', 0, 0, 'R');
        $this->SetFont('Arial', 'B', 13);
        $this->SetTextColor(40, 167, 69);
        $this->Cell(30, 10, number_format($totalConStock), 0, 0, 'L');
        
        // Cantidad total en stock
        $this->SetFont('Arial', '', 11);
        $this->SetTextColor(80, 80, 80);
        $this->Cell(10, 10, '', 0, 0);
        $this->SetFont('Arial', 'B', 13);
        $this->SetTextColor(40, 167, 69);
        $this->Cell(30, 10, '', 0, 1, 'L');
        
        $this->SetY($this->GetY() + 5);
        
        // Fila 2: Productos agotados
        $this->SetX($this->GetX() + 10);
        $this->SetFont('Arial', '', 11);
        $this->SetTextColor(80, 80, 80);
        $this->Cell($anchoColumna - 10, 10, 'Productos agotados:', 0, 0, 'R');
        $this->SetFont('Arial', 'B', 13);
        $this->SetTextColor(220, 53, 69);
        $this->Cell(30, 10, number_format($totalSinStock), 0, 0, 'L');
        
        // Cantidad agotada (siempre 0)
        $this->SetFont('Arial', '', 11);
        $this->SetTextColor(80, 80, 80);
        $this->Cell(10, 10, '', 0, 0);
        $this->SetFont('Arial', 'B', 13);
        $this->SetTextColor(220, 53, 69);
        $this->Cell(30, 10, '', 0, 1, 'L');
        
        $this->SetY($this->GetY() + 5);
        
        // Línea separadora
        $this->SetDrawColor(255, 140, 0);
        $this->SetLineWidth(0.5);
        $this->Line($this->GetX() + 10, $this->GetY(), $this->GetPageWidth() - $this->GetX() - 10, $this->GetY());
        $this->Ln(5);
        
         // Fila 3: Inventario total (más a la izquierda)
        $this->SetX($this->GetX() + 15);
        $this->SetFont('Arial', 'B', 13);
        $this->SetTextColor(80, 80, 80);
        $this->Cell($anchoColumna + 20, 10, 'INVENTARIO TOTAL:', 0, 0, 'L');
        $this->SetFont('Arial', 'B', 18);
        $this->SetTextColor(255, 140, 0);
        $this->Cell(80, 10, number_format($stockTotalGeneral) . ' unidades', 0, 1, 'L');
        
        $this->SetY($this->GetY() + 5);
    }
    
    function MensajeSinDatos($mensaje)
    {
        $this->SetFont('Arial', 'I', 10);
        $this->SetTextColor(150, 150, 150);
        $this->Cell(0, 20, $mensaje, 0, 1, 'C');
    }
    
    function getPageWidth()
    {
        return $this->w;
    }
}

// Calcular estadísticas
$totalConStock = count($productosConStock);
$totalSinStock = count($productosSinStock);
$stockTotalGeneral = 0;

foreach ($productosConStock as $producto) {
    $stockTotalGeneral += floatval($producto['cantidad']);
}

// Crear instancia del PDF
$pdf = new PDF();
$pdf->AddPage();

// Mostrar filtros aplicados
$filtros = [
    'tipo' => $tipo,
    'proveedor' => $proveedor,
    'categoria' => $categoria,
    'busqueda' => $busqueda
];
$pdf->FiltrosAplicados($filtros);

// Sección 1: Productos con stock (verde)
$totalCantidadConStock = $pdf->SeccionTabla(
    'PRODUCTOS CON STOCK (Mayor a menor)',
    $productosConStock,
    [40, 167, 69], // Verde
    false
);

// Sección 2: Productos sin stock (rojo)
$pdf->SeccionTabla(
    'PRODUCTOS AGOTADOS (Stock = 0)',
    $productosSinStock,
    [220, 53, 69], // Rojo
    true
);

// Mostrar resumen con cantidades
$pdf->TarjetaResumen($totalConStock, $totalSinStock, $totalCantidadConStock, $stockTotalGeneral);

// Información adicional
$pdf->SetY($pdf->GetY() + 8);
$pdf->SetFont('Arial', 'I', 8);
$pdf->SetTextColor(150, 150, 150);
$pdf->Cell(0, 5, 'Este reporte muestra los productos ordenados por cantidad disponible.', 0, 1, 'C');
$pdf->Cell(0, 5, 'Los productos en rojo requieren reabastecimiento inmediato.', 0, 1, 'C');

// Salida del PDF
$pdf->Output('I', 'reporte_inventario_' . date('Ymd_His') . '.pdf');
?>