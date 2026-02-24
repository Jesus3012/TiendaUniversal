<?php
// includes/session.php

if (session_status() == PHP_SESSION_NONE) {
    $secure = false; 
    $httponly = true;
    
    session_set_cookie_params(0, '/', null, $secure, $httponly);
    session_start();
}

// Timeout de 30 MINUTOS (1800 segundos)
$timeout_seconds = 1800; // 30 minutos para producción

if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity']) > $timeout_seconds) {
    $_SESSION = array();
    
    if (ini_get("session.use_cookies")) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000,
            $params["path"], $params["domain"],
            $params["secure"], $params["httponly"]
        );
    }
    
    session_destroy();
    
    // Redirigir con mensaje de inactividad
    header("Location: login.php?expired=inactivity");
    exit;
}

// Solo actualizar si hay usuario logueado
if (isset($_SESSION['usuario_id'])) {
    $_SESSION['last_activity'] = time();
}
?>