<?php
session_start();
include 'includes/db.php';
require 'includes/fpdf.php';

// Recibir parámetros
$id_orden = isset($_GET['id_orden']) ? $_GET['id_orden'] : '';
$solicitado_por = isset($_GET['solicitado_por']) ? $_GET['solicitado_por'] : '';
$estado = isset($_GET['estado']) ? $_GET['estado'] : 'todos';

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
// OBTENER ÓRDENES SEGÚN EL ESTADO
// ============================================

// Construir WHERE para órdenes
$ordenWhere = "WHERE 1=1";

if(!empty($id_orden)){
    $ordenWhere .= " AND p.id_orden = " . intval($id_orden);
}

if(!empty($solicitado_por)){
    $sol = $conn->real_escape_string($solicitado_por);
    $ordenWhere .= " AND p.solicitado_por = '$sol'";
}

// Obtener órdenes (id_orden) con sus datos
$sqlOrdenes = "SELECT 
                p.id_orden, 
                p.solicitado_por, 
                MIN(p.fecha) as fecha,
                MAX(CASE WHEN p.estado = 'completado' THEN 1 ELSE 0 END) as tiene_completados,
                MAX(CASE WHEN p.estado = 'cancelado' THEN 1 ELSE 0 END) as tiene_cancelados,
                op.fecha_cancelacion
              FROM pedidos p
              LEFT JOIN ordenes_pedido op ON p.id_orden = op.id_orden
              $ordenWhere
              GROUP BY p.id_orden, p.solicitado_por, op.fecha_cancelacion
              ORDER BY p.id_orden DESC";

$resOrdenes = $conn->query($sqlOrdenes);

if(!$resOrdenes){
    die("Error SQL: " . $conn->error);
}

// Filtrar órdenes por estado si es necesario
$ordenesFiltradas = [];
while($orden = $resOrdenes->fetch_assoc()){
    // Determinar el estado real de la orden
    $estadoOrden = 'pendiente';
    
    if($orden['tiene_cancelados'] == 1 && $orden['tiene_completados'] == 0){
        $estadoOrden = 'cancelado';
    } elseif($orden['tiene_completados'] == 1 && $orden['tiene_cancelados'] == 0){
        // Verificar si TODOS los productos están completados
        $sqlVerificar = "SELECT COUNT(*) as total, SUM(CASE WHEN estado = 'completado' THEN 1 ELSE 0 END) as completados 
                         FROM pedidos WHERE id_orden = " . $orden['id_orden'];
        $resVerificar = $conn->query($sqlVerificar);
        $verif = $resVerificar->fetch_assoc();
        
        if($verif['total'] == $verif['completados']){
            $estadoOrden = 'completado';
        } else {
            $estadoOrden = 'pendiente';
        }
    } else {
        $estadoOrden = 'pendiente';
    }
    
    $orden['estado'] = $estadoOrden;
    
    // Filtrar por estado si no es 'todos'
    if($estado !== 'todos' && $estadoOrden !== $estado){
        continue;
    }
    
    $ordenesFiltradas[] = $orden;
}

if(count($ordenesFiltradas) == 0){
    header('Content-Type: application/json');
    $mensaje = '';
    if($estado === 'pendiente') $mensaje = 'No hay pedidos pendientes';
    elseif($estado === 'completado') $mensaje = 'No hay pedidos completados';
    elseif($estado === 'cancelado') $mensaje = 'No hay pedidos cancelados';
    else $mensaje = 'No hay pedidos registrados';
    
    echo json_encode(['sin_pedidos' => true, 'mensaje' => $mensaje]);
    exit;
}

class PDF extends FPDF {
    var $logoTiendaPath;
    var $nombreTienda;
    
    function SetDatosTienda($logoTienda, $nombreTienda) {
        $this->logoTiendaPath = $logoTienda;
        $this->nombreTienda = $nombreTienda;
    }
    
    function Header(){
        $pageWidth = $this->GetPageWidth();
        $logoY = 8;
        $logoSize = 22;
        
        if (!empty($this->logoTiendaPath) && file_exists($this->logoTiendaPath)) {
            $this->Image($this->logoTiendaPath, 12, $logoY, $logoSize, $logoSize);
        }
        
        $this->SetY($logoY + 5);
        $this->SetFont('Arial', 'B', 11);
        $this->SetTextColor(60, 60, 60);
        $this->Cell(0, 8, utf8_decode(strtoupper($this->nombreTienda)), 0, 1, 'C');
        
        $this->SetDrawColor(200, 200, 200);
        $this->Line(12, $logoY + $logoSize + 6, $pageWidth - 12, $logoY + $logoSize + 6);
        
        $this->SetY($logoY + $logoSize + 14);
        $this->SetFont('Arial','B',16);
        $this->SetTextColor(44, 62, 80);
        
        $estado = $GLOBALS['estado'];
        if($estado === 'pendiente') $titulo = 'REPORTE DE PEDIDOS PENDIENTES';
        elseif($estado === 'completado') $titulo = 'REPORTE DE PEDIDOS COMPLETADOS';
        elseif($estado === 'cancelado') $titulo = 'REPORTE DE PEDIDOS CANCELADOS';
        else $titulo = 'REPORTE DE TODOS LOS PEDIDOS';
        
        $this->Cell(0, 10, utf8_decode($titulo), 0, 1, 'C');
        $this->Ln(5);
    }
    
    function DibujarOrden($orden, $productos) {
        $pageWidth = $this->GetPageWidth();
        
        // Fondo para el encabezado de la orden
        $this->SetFillColor(245, 245, 245);
        $this->Rect(10, $this->GetY(), $pageWidth - 20, 10, 'F');
        
        // Información de la orden
        $this->SetFont('Arial', 'B', 10);
        $this->SetTextColor(0, 0, 0);
        $this->SetXY(12, $this->GetY() + 2);
        $this->Cell(40, 6, "Folio: #" . $orden['id_orden'], 0, 0);
        $this->Cell(60, 6, "Solicitante: " . utf8_decode($orden['solicitado_por']), 0, 0);
        $this->Cell(60, 6, "Fecha: " . date('d/m/Y H:i', strtotime($orden['fecha'])), 0, 0);
        
        // Estado de la orden
        $estadoOrden = $orden['estado'];
        if($estadoOrden === 'pendiente') {
            $this->SetFillColor(255, 193, 7);
            $textoEstado = 'PENDIENTE';
        } elseif($estadoOrden === 'completado') {
            $this->SetFillColor(40, 167, 69);
            $textoEstado = 'COMPLETADO';
        } else {
            $this->SetFillColor(220, 53, 69);
            $textoEstado = 'CANCELADO';
        }
        
        $this->SetFont('Arial', 'B', 9);
        $this->SetTextColor(255, 255, 255);
        $this->Cell(50, 6, " " . $textoEstado . " ", 0, 1, 'C', true);
        $this->SetTextColor(0, 0, 0);
        
        $this->Ln(3);
        
        // Tabla de productos
        $this->SetFont('Arial', 'B', 9);
        $this->SetFillColor(52, 58, 64);
        $this->SetTextColor(255, 255, 255);
        
        $this->Cell(75, 8, 'Producto', 1, 0, 'C', true);
        $this->Cell(30, 8, 'Stock', 1, 0, 'C', true);
        $this->Cell(40, 8, 'Cantidad Pedida', 1, 0, 'C', true);
        $this->Cell(40, 8, 'Faltante', 1, 0, 'C', true);
        $this->Cell(55, 8, 'Estado', 1, 1, 'C', true);
        
        $this->SetFont('Arial', '', 8);
        $this->SetTextColor(0, 0, 0);
        $fill = false;
        
        foreach($productos as $p){
            $this->SetFillColor($fill ? 248 : 255, 249, 250);
            
            $this->Cell(75, 7, utf8_decode(substr($p['nombre_producto'], 0, 32)), 1, 0, 'L', $fill);
            $this->Cell(30, 7, $p['stock_actual'], 1, 0, 'C', $fill);
            $this->Cell(40, 7, $p['cantidad_pedida'], 1, 0, 'C', $fill);
            $this->Cell(40, 7, $p['faltante'] ?? 0, 1, 0, 'C', $fill);
            
            // Estado del producto
            $estadoProducto = $p['estado'];
            if($estadoProducto === 'completado') {
                $this->SetTextColor(40, 167, 69);
                $estadoTexto = 'COMPLETADO';
            } elseif($estadoProducto === 'pendiente') {
                $this->SetTextColor(255, 193, 7);
                $estadoTexto = 'PENDIENTE';
            } else {
                $this->SetTextColor(220, 53, 69);
                $estadoTexto = 'CANCELADO';
            }
            $this->Cell(55, 7, $estadoTexto, 1, 1, 'C', $fill);
            $this->SetTextColor(0, 0, 0);
            
            $fill = !$fill;
        }
        
        // Fecha de cancelación si aplica
        if($estadoOrden === 'cancelado' && !empty($orden['fecha_cancelacion'])){
            $this->SetFont('Arial', 'I', 8);
            $this->SetTextColor(220, 53, 69);
            $this->Cell(0, 5, "Cancelado el: " . date('d/m/Y H:i', strtotime($orden['fecha_cancelacion'])), 0, 1, 'R');
            $this->SetTextColor(0, 0, 0);
        }
        
        $this->Ln(5);
    }
    
    function Footer(){
        $this->SetY(-15);
        $this->SetFont('Arial','I',8);
        $this->SetTextColor(128,128,128);
        $this->Cell(0,10, utf8_decode('Tienda Pescadores - Documento Oficial | Página '.$this->PageNo().' | '.date('d/m/Y H:i')),0,0,'C');
    }
}

$pdf = new PDF('L');
$pdf->SetDatosTienda($logoTiendaPath, $nombreTienda);
$pdf->AddPage();
$pdf->SetAutoPageBreak(true, 25);

foreach($ordenesFiltradas as $orden){
    // Obtener productos de esta orden
    $sqlProductos = "SELECT * FROM pedidos 
                     WHERE id_orden = " . $orden['id_orden'] . "
                     AND cantidad_pedida > 0
                     ORDER BY nombre_producto ASC";
    
    $resProductos = $conn->query($sqlProductos);
    $productos = [];
    
    if($resProductos && $resProductos->num_rows > 0){
        while($p = $resProductos->fetch_assoc()){
            $productos[] = $p;
        }
    }
    
    $pdf->DibujarOrden($orden, $productos);
    
    // Verificar espacio para siguiente orden
    if($pdf->GetY() > 250){
        $pdf->AddPage();
    }
}

$pdf->Output('I', 'Reporte_Pedidos_' . date('Y-m-d') . '.pdf');
exit;
?>