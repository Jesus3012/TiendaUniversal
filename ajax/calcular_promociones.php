<?php
declare(strict_types=1);

session_start();

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/permisos.php';
require_once __DIR__ . '/../includes/promociones_helper.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

function promociones_ajax_responder(array $respuesta, int $estado = 200): void
{
    http_response_code($estado);
    echo json_encode(
        $respuesta,
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );
    exit;
}

permisos_proteger_ruta($conn, basename(__FILE__));

$entrada = file_get_contents('php://input');
$datos = json_decode((string) $entrada, true);

if (!is_array($datos)) {
    $datos = $_POST;
}

$items = $datos['items'] ?? [];

if (!is_array($items) || empty($items)) {
    promociones_ajax_responder([
        'success' => false,
        'message' => 'No se recibieron productos para calcular.',
    ], 400);
}

try {
    $calculo = promociones_calcular_carrito($conn, $items);

    promociones_ajax_responder([
        'success' => true,
        'data' => $calculo,
    ]);
} catch (Throwable $error) {
    error_log('Error calculando promociones: ' . $error->getMessage());

    promociones_ajax_responder([
        'success' => false,
        'message' => 'No fue posible calcular las promociones.',
    ], 500);
}
