<?php
session_start();
include 'includes/db.php';

header('Content-Type: application/json');

ini_set('display_errors', 1);
error_reporting(E_ALL);

try{

    if(!isset($_SESSION['nombre'])){
        throw new Exception("Sesión no válida");
    }

    if(!isset($_GET['id_orden'])){
        throw new Exception("Falta id_orden");
    }

    $id_orden = intval($_GET['id_orden']);

    $res = $conn->query("
        SELECT accion, detalle as descripcion, usuario, fecha
        FROM pedidos_log
        WHERE id_pedido = $id_orden
        ORDER BY fecha ASC
    ");


    if(!$res){
        throw new Exception($conn->error);
    }

    $logs = [];

    while($r = $res->fetch_assoc()){
        $logs[] = $r;
    }

    echo json_encode($logs);

}catch(Exception $e){
    echo json_encode([
        "error" => $e->getMessage()
    ]);
}
