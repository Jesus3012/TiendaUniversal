<?php
include('../includes/db.php');
session_start();

header('Content-Type: application/json');

$sql = "SELECT id, nombre, precio_venta, cantidad as stock, imagen, categoria FROM productos WHERE cantidad > 0 AND activo = 1 ORDER BY nombre ASC LIMIT 30";
$result = $conn->query($sql);
$productos = [];

if ($result) {
    while ($row = $result->fetch_assoc()) {
        $productos[] = $row;
    }
}

echo json_encode($productos);
?>