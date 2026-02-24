<?php
include 'includes/db.php';
require 'includes/fpdf.php';

// 🧠 Construimos el WHERE dinámico
$where = "WHERE 1=1";

if(isset($_GET['id_orden']) && $_GET['id_orden'] != ''){
    $id_orden = intval($_GET['id_orden']);
    $where .= " AND id_orden = $id_orden";
}

if(isset($_GET['solicitado_por']) && $_GET['solicitado_por'] != ''){
    $sol = $conn->real_escape_string($_GET['solicitado_por']);
    $where .= " AND solicitado_por = '$sol'";
}

$sql = "SELECT * FROM pedidos 
        $where 
        AND cantidad_pedida > 0
        ORDER BY fecha DESC";
$res = $conn->query($sql);

if(!$res){
    die("Error SQL: " . $conn->error);
}

// Verificar si realmente hay algo que pedir
if($res->num_rows == 0){
    header('Content-Type: application/json');
    echo json_encode([
        'sin_pedidos' => true,
        'mensaje' => 'No hay reportes pendientes por exportar.'
    ]);
    exit;
}


$res->data_seek(0);

class PDF extends FPDF {

    function Header(){
        $this->SetFont('Arial','B',18);
        $this->Cell(0,12,'REPORTE DE PEDIDOS / REABASTECIMIENTO',0,1,'C');
        $this->Ln(4);

        $this->SetFont('Arial','B',10);
        $this->SetFillColor(52,58,64);
        $this->SetTextColor(255,255,255);

        $this->Cell(60,10,'Producto',1,0,'C',true);
        $this->Cell(25,10,'Stock',1,0,'C',true);
        $this->Cell(40,10,'Ariticulos pedidos',1,0,'C',true);
        $this->Cell(40,10,'Articulos por hacer',1,0,'C',true);
        $this->Cell(40,10,'Solicitado por',1,0,'C',true);
        $this->Cell(40,10,'Fecha',1,1,'C',true);

        $this->SetTextColor(0,0,0);
    }

    function Footer(){
        $this->SetY(-15);
        $this->SetFont('Arial','I',8);
        $this->Cell(0,10,'Pagina '.$this->PageNo().' | '.date('d/m/Y H:i'),0,0,'C');
    }
}

$pdf = new PDF('L');
$pdf->AddPage();
$pdf->SetFont('Arial','',9);

while($row = $res->fetch_assoc()){
    $pdf->Cell(60,8,$row['nombre_producto'],1);
    $pdf->Cell(25,8,$row['stock_actual'],1,0,'C');
    $pdf->Cell(40,8,$row['cantidad_pedida'],1,0,'C');
    $pdf->Cell(40,8,$row['faltante'],1,0,'C');
    $pdf->Cell(40,8,$row['solicitado_por'],1);
    $pdf->Cell(40,8,date('d/m/Y H:i', strtotime($row['fecha'])),1);
    $pdf->Ln();
}

$pdf->Output('I','Reporte_Pedidos.pdf');
exit;
