<?php

date_default_timezone_set('America/Mexico_City');

session_start();
include '../includes/db.php';
require_once('../includes/fpdf.php');

// Verificar autenticacion
if (!isset($_SESSION['usuario_id'])) {
    die('No autorizado');
}

// Obtener parametros
$inicio = isset($_GET['inicio']) ? $_GET['inicio'] : '';
$fin = isset($_GET['fin']) ? $_GET['fin'] : '';
$busqueda = isset($_GET['busqueda']) ? trim($_GET['busqueda']) : '';

// Validar fechas
if (empty($inicio) || empty($fin)) {
    die('Se requieren fechas de inicio y fin');
}

// Obtener datos de la tienda
$sqlConfig = "SELECT nombre, telefono, email, direccion, logo FROM configuracion_galeria LIMIT 1";
$resultConfig = $conn->query($sqlConfig);
$config = $resultConfig->fetch_assoc();
$nombreTienda = $config['nombre'] ?? 'TIENDA PESCADORES';
$telefono = $config['telefono'] ?? '';
$email = $config['email'] ?? '';
$direccion = $config['direccion'] ?? '';
$logoBD = $config['logo'] ?? '';

// Buscar el logo en diferentes rutas
$logoPath = '';
$rutasBuscar = [
    $logoBD,
    '../img/panel_principal.jpg',
    '../img/panel_principal.png',
    '../img/logo.jpg',
    '../img/logo.png',
    'img/panel_principal.jpg',
    'img/panel_principal.png'
];

foreach ($rutasBuscar as $ruta) {
    if (!empty($ruta) && file_exists($ruta)) {
        $logoPath = $ruta;
        break;
    }
}

// ============================================
// CONSULTA DE VENTAS
// ============================================
$sql = "SELECT 
            v.folio_ticket,
            v.correo_cliente,
            v.fecha_venta,
            v.metodo_pago,
            v.referencia_pago,
            v.id_vendedor,
            u.nombre as vendedor_nombre,
            (SELECT SUM(v2.cantidad_vendida * p2.precio_venta) 
             FROM ventas v2 
             JOIN productos p2 ON v2.id_producto = p2.id 
             WHERE v2.folio_ticket = v.folio_ticket) as total_general,
            (SELECT COUNT(DISTINCT v2.id_producto) 
             FROM ventas v2 
             WHERE v2.folio_ticket = v.folio_ticket) as total_productos,
            (SELECT SUM(v2.cantidad_vendida) 
             FROM ventas v2 
             WHERE v2.folio_ticket = v.folio_ticket) as total_unidades
        FROM ventas v
        LEFT JOIN usuarios u ON v.id_vendedor = u.id
        WHERE DATE(v.fecha_venta) BETWEEN '$inicio' AND '$fin'
        GROUP BY v.folio_ticket, v.correo_cliente, v.fecha_venta, v.metodo_pago, 
                 v.referencia_pago, v.id_vendedor, u.nombre";

if (!empty($busqueda)) {
    $busqueda_escape = $conn->real_escape_string($busqueda);
    $sql .= " HAVING (folio_ticket LIKE '%$busqueda_escape%' 
                OR correo_cliente LIKE '%$busqueda_escape%'
                OR vendedor_nombre LIKE '%$busqueda_escape%')";
}

$sql .= " ORDER BY v.fecha_venta DESC";

$result = $conn->query($sql);

if (!$result) {
    die("Error en consulta: " . $conn->error);
}

// Variables para estadisticas
$totalGeneral = 0;
$totalVentas = 0;
$totalPedidos = 0;
$totalConteos = 0;
$metodosPago = array();
$ventasPorVendedor = array();
$ventasData = array();

if ($result->num_rows > 0) {
    while ($row = $result->fetch_assoc()) {
        $totalGeneral += floatval($row['total_general'] ?? 0);
        $totalVentas++;
        
        $tipoVenta = 'Directa';
        if (strpos($row['folio_ticket'], 'PEDIDO-') === 0) {
            $tipoVenta = 'Pedido';
            $totalPedidos++;
        } elseif (strpos($row['folio_ticket'], 'Venta_conteo') === 0) {
            $tipoVenta = 'Conteo';
            $totalConteos++;
        }
        
        $metodo = !empty($row['metodo_pago']) ? $row['metodo_pago'] : 'Efectivo';
        if (!isset($metodosPago[$metodo])) {
            $metodosPago[$metodo] = array('cantidad' => 0, 'total' => 0);
        }
        $metodosPago[$metodo]['cantidad']++;
        $metodosPago[$metodo]['total'] += floatval($row['total_general'] ?? 0);
        
        $vendedor = !empty($row['vendedor_nombre']) ? $row['vendedor_nombre'] : 'Sistema';
        if (!isset($ventasPorVendedor[$vendedor])) {
            $ventasPorVendedor[$vendedor] = array('cantidad' => 0, 'total' => 0);
        }
        $ventasPorVendedor[$vendedor]['cantidad']++;
        $ventasPorVendedor[$vendedor]['total'] += floatval($row['total_general'] ?? 0);
        
        $cliente = (!empty($row['correo_cliente']) && $row['correo_cliente'] != 'null') 
                    ? $row['correo_cliente'] 
                    : 'Venta general';
        
        $ventasData[] = array(
            'folio' => $row['folio_ticket'],
            'total' => $row['total_general'],
            'cliente' => $cliente,
            'fecha' => date('d/m/Y', strtotime($row['fecha_venta'])),
            'hora' => date('H:i:s', strtotime($row['fecha_venta'])),
            'metodo_pago' => $row['metodo_pago'] ?? 'Efectivo',
            'referencia' => $row['referencia_pago'] ?? '---',
            'vendedor' => $vendedor,
            'productos' => $row['total_productos'] ?? 0,
            'unidades' => $row['total_unidades'] ?? 0,
            'tipo' => $tipoVenta
        );
    }
}

// TABLA 2: TOP PRODUCTOS
$sqlTop = "SELECT 
                p.nombre as producto,
                SUM(v.cantidad_vendida) as total_vendido,
                SUM(v.cantidad_vendida * p.precio_venta) as total_ingresos
            FROM ventas v
            JOIN productos p ON v.id_producto = p.id
            WHERE DATE(v.fecha_venta) BETWEEN '$inicio' AND '$fin'
            GROUP BY v.id_producto, p.nombre
            ORDER BY total_vendido DESC
            LIMIT 10";

$resultTop = $conn->query($sqlTop);
$topProductos = array();
if ($resultTop && $resultTop->num_rows > 0) {
    while ($row = $resultTop->fetch_assoc()) {
        $topProductos[] = $row;
    }
}

// ============================================
// CREAR PDF
// ============================================
class PDF extends FPDF
{
    function Header()
    {
        global $nombreTienda, $telefono, $email, $direccion, $logoPath;
        
        // Logo mas pequeno (25mm de ancho)
        $logoCargado = false;
        if (!empty($logoPath) && file_exists($logoPath)) {
            $this->Image($logoPath, 10, 4, 20);
            $logoCargado = true;
        }
        
        // Si no hay logo, mostrar texto pequeno
        if (!$logoCargado) {
            $this->SetFont('Arial', 'B', 16);
            $this->SetTextColor(41, 128, 185);
            $this->SetXY(15, 12);
            $this->Cell(25, 8, substr($nombreTienda, 0, 2), 0, 0, 'C');
        }
        
        // Datos de la tienda (mas compactos)
        $this->SetY(6);
        $this->SetFont('Arial', 'B', 11);
        $this->SetTextColor(44, 62, 80);
        $this->Cell(0, 5, utf8_decode(strtoupper($nombreTienda)), 0, 1, 'R');
        
        $this->SetFont('Arial', '', 8);
        $this->SetTextColor(85, 85, 85);
        if (!empty($direccion)) {
            $this->Cell(0, 4, utf8_decode($direccion), 0, 1, 'R');
        }
        if (!empty($telefono)) {
            $this->Cell(0, 4, 'Tel: ' . $telefono, 0, 1, 'R');
        }
        if (!empty($email)) {
            $this->Cell(0, 4, 'Email: ' . $email, 0, 1, 'R');
        }
        
        $this->Ln(6);
        $this->SetDrawColor(52, 152, 219);
        $this->Line(10, $this->GetY(), 200, $this->GetY());
        $this->Ln(5);
    }
    
    function Footer()
    {
        $this->SetY(-15);
        $this->SetFont('Arial', 'I', 7);
        $this->SetTextColor(128, 128, 128);
        $this->Cell(0, 8, utf8_decode('Pagina ') . $this->PageNo() . ' | ' . date('d/m/Y H:i:s'), 0, 0, 'C');
    }
    
    function TablaVentas($data)
    {
        $this->SetFont('Arial', 'B', 8);
        $this->SetFillColor(41, 128, 185);
        $this->SetTextColor(255, 255, 255);
        
        // Cabecera mas compacta
        $this->Cell(30, 6, 'FOLIO', 1, 0, 'C', true);
        $this->Cell(16, 6, 'TOTAL', 1, 0, 'C', true);
        $this->Cell(38, 6, utf8_decode('CLIENTE'), 1, 0, 'C', true);
        $this->Cell(18, 6, 'FECHA', 1, 0, 'C', true);
        $this->Cell(14, 6, 'HORA', 1, 0, 'C', true);
        $this->Cell(22, 6, utf8_decode('METODO'), 1, 0, 'C', true);
        $this->Cell(18, 6, 'VENDEDOR', 1, 0, 'C', true);
        $this->Cell(15, 6, 'PROD', 1, 0, 'C', true);
        $this->Cell(15, 6, 'UDS', 1, 1, 'C', true);
        
        $this->SetFont('Arial', '', 7);
        $this->SetTextColor(0, 0, 0);
        $fill = false;
        
        foreach ($data as $row) {
            $this->Cell(30, 5, $row['folio'], 1, 0, 'L', $fill);
            $this->Cell(16, 5, '$' . number_format($row['total'], 2), 1, 0, 'R', $fill);
            $cliente = strlen($row['cliente']) > 16 ? substr($row['cliente'], 0, 14) . '..' : $row['cliente'];
            $this->Cell(38, 5, utf8_decode($cliente), 1, 0, 'L', $fill);
            $this->Cell(18, 5, $row['fecha'], 1, 0, 'C', $fill);
            $this->Cell(14, 5, $row['hora'], 1, 0, 'C', $fill);
            $this->Cell(22, 5, utf8_decode($row['metodo_pago']), 1, 0, 'C', $fill);
            $vendedor = strlen($row['vendedor']) > 10 ? substr($row['vendedor'], 0, 8) . '..' : $row['vendedor'];
            $this->Cell(18, 5, utf8_decode($vendedor), 1, 0, 'L', $fill);
            $this->Cell(15, 5, $row['productos'], 1, 0, 'C', $fill);
            $this->Cell(15, 5, $row['unidades'], 1, 1, 'C', $fill);
            $fill = !$fill;
        }
        
        $this->SetFont('Arial', 'B', 7);
        $this->SetFillColor(236, 240, 241);
        $this->Cell(56, 6, 'TOTAL: ' . count($data) . ' ventas', 1, 0, 'L', true);
        $this->Cell(130, 6, '', 1, 1, 'R', true);
    }
    
    function TablaTopProductos($data)
    {
        $this->SetFont('Arial', 'B', 8);
        $this->SetFillColor(41, 128, 185);
        $this->SetTextColor(255, 255, 255);
        
        $this->Cell(16, 6, 'POS', 1, 0, 'C', true);
        $this->Cell(100, 6, utf8_decode('PRODUCTO'), 1, 0, 'C', true);
        $this->Cell(35, 6, 'UNIDADES', 1, 0, 'C', true);
        $this->Cell(35, 6, 'INGRESOS', 1, 1, 'C', true);
        
        $this->SetFont('Arial', '', 8);
        $this->SetTextColor(0, 0, 0);
        $pos = 1;
        
        foreach ($data as $row) {
            $producto = strlen($row['producto']) > 38 ? substr($row['producto'], 0, 35) . '...' : $row['producto'];
            $this->Cell(16, 6, $pos, 1, 0, 'C');
            $this->Cell(100, 6, utf8_decode($producto), 1, 0, 'L');
            $this->Cell(35, 6, $row['total_vendido'], 1, 0, 'R');
            $this->Cell(35, 6, '$' . number_format($row['total_ingresos'], 2), 1, 1, 'R');
            $pos++;
        }
    }
    
    function TablaResumen($titulo, $data)
    {
        $this->SetFont('Arial', 'B', 8);
        $this->SetFillColor(41, 128, 185);
        $this->SetTextColor(255, 255, 255);
        
        $this->Cell(100, 6, utf8_decode($titulo), 1, 0, 'C', true);
        $this->Cell(45, 6, 'CANTIDAD', 1, 0, 'C', true);
        $this->Cell(45, 6, 'MONTO', 1, 1, 'C', true);
        
        $this->SetFont('Arial', '', 8);
        $this->SetTextColor(0, 0, 0);
        
        foreach ($data as $nombre => $valores) {
            $this->Cell(100, 6, utf8_decode($nombre), 1, 0, 'L');
            $this->Cell(45, 6, $valores['cantidad'], 1, 0, 'R');
            $this->Cell(45, 6, '$' . number_format($valores['total'], 2), 1, 1, 'R');
        }
    }
    
    function TablaTiposVenta($directas, $pedidos, $conteos, $total)
    {
        $this->SetFont('Arial', 'B', 8);
        $this->SetFillColor(41, 128, 185);
        $this->SetTextColor(255, 255, 255);
        
        $this->Cell(100, 6, 'TIPO DE VENTA', 1, 0, 'C', true);
        $this->Cell(45, 6, 'CANTIDAD', 1, 0, 'C', true);
        $this->Cell(45, 6, 'PORCENTAJE', 1, 1, 'C', true);
        
        $this->SetFont('Arial', '', 8);
        $this->SetTextColor(0, 0, 0);
        
        $this->Cell(100, 6, 'Ventas directas', 1, 0, 'L');
        $this->Cell(45, 6, $directas, 1, 0, 'R');
        $porc = $total > 0 ? ($directas / $total) * 100 : 0;
        $this->Cell(45, 6, number_format($porc, 1) . '%', 1, 1, 'R');
        
        $this->Cell(100, 6, 'Pedidos', 1, 0, 'L');
        $this->Cell(45, 6, $pedidos, 1, 0, 'R');
        $porc = $total > 0 ? ($pedidos / $total) * 100 : 0;
        $this->Cell(45, 6, number_format($porc, 1) . '%', 1, 1, 'R');
        
        $this->Cell(100, 6, 'Conteos rapidos', 1, 0, 'L');
        $this->Cell(45, 6, $conteos, 1, 0, 'R');
        $porc = $total > 0 ? ($conteos / $total) * 100 : 0;
        $this->Cell(45, 6, number_format($porc, 1) . '%', 1, 1, 'R');
        
        $this->SetFont('Arial', 'B', 8);
        $this->SetFillColor(236, 240, 241);
        $this->Cell(100, 6, 'TOTAL GENERAL', 1, 0, 'L', true);
        $this->Cell(45, 6, $total, 1, 0, 'R', true);
        $this->Cell(45, 6, '100%', 1, 1, 'R', true);
    }
}

// Crear PDF
$pdf = new PDF('P', 'mm', 'A4');
$pdf->AddPage();
$pdf->SetAutoPageBreak(true, 20);

// Titulo
$pdf->SetFont('Arial', 'B', 14);
$pdf->SetTextColor(44, 62, 80);
$pdf->Cell(0, 8, 'REPORTE DE VENTAS', 0, 1, 'C');
$pdf->Ln(3);

// Informacion del periodo
$pdf->SetFont('Arial', '', 9);
$pdf->SetTextColor(0, 0, 0);
$pdf->Cell(0, 5, 'Periodo: ' . date('d/m/Y', strtotime($inicio)) . ' al ' . date('d/m/Y', strtotime($fin)), 0, 1);
$pdf->Cell(0, 5, 'Generado: ' . date('d/m/Y H:i:s'), 0, 1);
if (!empty($busqueda)) {
    $pdf->Cell(0, 5, 'Filtro: ' . utf8_decode($busqueda), 0, 1);
}
$pdf->Ln(5);

// TABLA 1: LISTADO DE VENTAS
$pdf->SetFont('Arial', 'B', 11);
$pdf->SetTextColor(52, 73, 94);
$pdf->Cell(0, 6, '1. LISTADO DE VENTAS (' . count($ventasData) . ' registros)', 0, 1);
$pdf->Ln(2);
$pdf->TablaVentas($ventasData);
$pdf->Ln(6);

// TABLA 2: TOP PRODUCTOS
$pdf->SetFont('Arial', 'B', 11);
$pdf->Cell(0, 6, '2. TOP 10 PRODUCTOS MAS VENDIDOS', 0, 1);
$pdf->Ln(2);
$pdf->TablaTopProductos($topProductos);
$pdf->Ln(6);

// TABLA 3: VENTAS POR METODO DE PAGO
if (!empty($metodosPago)) {
    $pdf->SetFont('Arial', 'B', 11);
    $pdf->Cell(0, 6, '3. VENTAS POR METODO DE PAGO', 0, 1);
    $pdf->Ln(2);
    $pdf->TablaResumen('METODO DE PAGO', $metodosPago);
    $pdf->Ln(6);
}

// TABLA 4: VENTAS POR VENDEDOR
if (!empty($ventasPorVendedor)) {
    $pdf->SetFont('Arial', 'B', 11);
    $pdf->Cell(0, 6, '4. VENTAS POR VENDEDOR', 0, 1);
    $pdf->Ln(2);
    $pdf->TablaResumen('VENDEDOR', $ventasPorVendedor);
    $pdf->Ln(6);
}

// TABLA 5: VENTAS POR TIPO
$pdf->SetFont('Arial', 'B', 11);
$pdf->Cell(0, 6, '5. VENTAS POR TIPO', 0, 1);
$pdf->Ln(2);
$pdf->TablaTiposVenta($totalVentas - $totalPedidos - $totalConteos, $totalPedidos, $totalConteos, $totalVentas);

// Salida del PDF
$pdf->Output('D', 'reporte_ventas_' . date('Y-m-d') . '.pdf');

$conn->close();
?>