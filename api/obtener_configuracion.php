<?php
header('Content-Type: application/json');
require_once '../includes/db.php';
session_start();

if (!isset($_SESSION['usuario_id'])) {
    echo json_encode(['error' => 'No autorizado']);
    exit;
}

$sql = "SELECT nombre, telefono, email, direccion, logo FROM configuracion_galeria WHERE id = 1";
$result = $conn->query($sql);

if ($result && $row = $result->fetch_assoc()) {
    $logoBase64 = '';
    if (!empty($row['logo']) && file_exists('../' . $row['logo'])) {
        $logoPath = '../' . $row['logo'];
        $tipo = mime_content_type($logoPath);
        $data = file_get_contents($logoPath);
        $logoBase64 = 'data:' . $tipo . ';base64,' . base64_encode($data);
    }
    
    echo json_encode([
        'nombre' => $row['nombre'],
        'telefono' => $row['telefono'],
        'email' => $row['email'],
        'direccion' => $row['direccion'],
        'logo' => $row['logo'],
        'logo_base64' => $logoBase64
    ]);
} else {
    echo json_encode([
        'nombre' => 'TIENDA PESCADORES',
        'telefono' => '',
        'email' => '',
        'direccion' => '',
        'logo' => '',
        'logo_base64' => ''
    ]);
}
?>