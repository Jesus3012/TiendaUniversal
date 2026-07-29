<?php
/**
 * Endpoint para renovar la actividad de una sesión existente.
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/session.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

if (empty($_SESSION['usuario_id'])) {
    http_response_code(401);
    echo json_encode([
        'success' => false,
        'expired' => true,
        'reason' => 'inactivity',
        'message' => 'La sesión ya no existe.',
        'redirect' => sesionUrl('login.php?expired=inactivity'),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$datos = sesionRenovarActividad();

echo json_encode([
    'success' => true,
    'message' => 'Sesión renovada correctamente.',
    'server_now' => $datos['server_now'],
    'expires_at' => $datos['expires_at'],
    'max_expires_at' => $datos['max_expires_at'],
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
