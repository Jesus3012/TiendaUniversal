<?php
session_start();
include 'includes/db.php';

header('Content-Type: application/json');

try {

    // MODIFICADO: Agregado filtro para solo mostrar PRODUCTOS (no insumos)
    $res = $conn->query("
        SELECT id, nombre, cantidad
        FROM productos
        WHERE tipo_inventario = 'producto' 
        AND cantidad <= 5
        ORDER BY cantidad ASC
    ");

    if(!$res){
        throw new Exception($conn->error);
    }

    $productos = [];

    while($r = $res->fetch_assoc()){
        $productos[] = $r;
    }

    echo json_encode($productos);

}catch(Exception $e){
    echo json_encode(["error" => $e->getMessage()]);
}
?>