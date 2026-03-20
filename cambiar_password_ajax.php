<?php
session_start();
include('includes/db.php');
include('includes/csrf.php');

header('Content-Type: application/json');

// Verificar sesión
if (!isset($_SESSION['usuario_id'])) {
    echo json_encode(['success' => false, 'message' => 'Sesión no válida']);
    exit;
}

$response = ['success' => false, 'message' => ''];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    
    $password_nueva = $_POST['password_nueva'] ?? '';
    $password_confirmar = $_POST['password_confirmar'] ?? '';
    $id_usuario = $_SESSION['usuario_id'];
    
    // Validaciones
    if (empty($password_nueva) || empty($password_confirmar)) {
        $response['message'] = "Todos los campos son obligatorios.";
    } elseif ($password_nueva !== $password_confirmar) {
        $response['message'] = "Las contraseñas no coinciden.";
    } elseif (strlen($password_nueva) < 8) {
        $response['message'] = "La contraseña debe tener al menos 8 caracteres.";
    } elseif ($password_nueva === 'Pescadores1') {
        $response['message'] = "No puedes usar la contraseña por defecto.";
    } else {
        $hash_nuevo = password_hash($password_nueva, PASSWORD_DEFAULT);
        $update = $conn->prepare("UPDATE usuarios SET password = ?, debe_cambiar_password = 0 WHERE id = ?");
        $update->bind_param("si", $hash_nuevo, $id_usuario);
        
        if ($update->execute()) {
            $_SESSION['debe_cambiar_password'] = 0;
            $response['success'] = true;
            $response['message'] = "Contraseña cambiada exitosamente.";
        } else {
            $response['message'] = "Error al actualizar la contraseña.";
        }
        $update->close();
    }
    
    echo json_encode($response);
    exit;
}