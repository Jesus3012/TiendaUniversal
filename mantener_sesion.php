<?php
// mantener_sesion.php
require_once 'includes/session.php';

header('Content-Type: application/json');

if (isset($_SESSION['usuario_id'])) {
    $_SESSION['last_activity'] = time();
    echo json_encode([
        'success' => true, 
        'time' => time(),
        'message' => 'Sesión mantenida activa'
    ]);
} else {
    echo json_encode([
        'success' => false, 
        'message' => 'Sesión no existe',
        'redirect' => 'login.php?expired=inactivity'
    ]);
}
?>