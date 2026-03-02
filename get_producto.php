<?php
session_start();
require_once "includes/db.php";

if (!isset($_SESSION["usuario_id"])) {
    http_response_code(403);
    echo json_encode(["success" => false, "error" => "No autorizado"]);
    exit;
}

$id = isset($_GET["id"]) ? intval($_GET["id"]) : 0;

if ($id <= 0) {
    echo json_encode(["success" => false, "error" => "ID inválido"]);
    exit;
}

$stmt = $conn->prepare("SELECT * FROM productos WHERE id = ? AND activo = 1");
$stmt->bind_param("i", $id);
$stmt->execute();
$result = $stmt->get_result();

if ($row = $result->fetch_assoc()) {
    $row["atributos_array"] = $row["atributos"] ? json_decode($row["atributos"], true) : [];
    echo json_encode(["success" => true, "producto" => $row]);
} else {
    echo json_encode(["success" => false, "error" => "Producto no encontrado"]);
}
