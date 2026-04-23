<?php
session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['usuario_id'])) {
    echo json_encode(['success' => false]);
    exit;
}

$carrito = json_decode($_POST['carrito'] ?? '[]', true);
$_SESSION['carrito'] = $carrito;

echo json_encode(['success' => true]);
?>