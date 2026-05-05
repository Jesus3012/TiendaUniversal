<?php
session_start();
include 'includes/db.php';

header('Content-Type: application/json');

try {
    if(!isset($_GET['id_orden'])){
        throw new Exception("Falta id_orden");
    }

    $id_orden = intval($_GET['id_orden']);

    $res = $conn->query("
        SELECT accion, detalle, usuario, DATE_FORMAT(fecha, '%d/%m/%Y %H:%i:%s') as fecha
        FROM pedidos_log
        WHERE id_pedido = $id_orden
        ORDER BY fecha ASC
    ");

    if(!$res){
        throw new Exception($conn->error);
    }

    $logs = [];
    while($r = $res->fetch_assoc()){
        $logs[] = [
            'accion' => $r['accion'],
            'descripcion' => $r['detalle'],
            'usuario' => $r['usuario'],
            'fecha' => $r['fecha']
        ];
    }

    echo json_encode($logs);

} catch(Exception $e){
    echo json_encode([
        "error" => $e->getMessage()
    ]);
}
?>