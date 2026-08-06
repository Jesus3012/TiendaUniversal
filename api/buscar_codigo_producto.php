<?php
/**
 * Consulta un código de barras sin exponer el token de la API al navegador.
 *
 * Orden:
 * 1. Inventario local para evitar productos duplicados.
 * 2. UPC Database para sugerir datos.
 * 3. Captura manual si no hay resultado o la API no está disponible.
 */

declare(strict_types=1);

ob_start();

require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/upc_database.php';

while (ob_get_level() > 0) {
    ob_end_clean();
}

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

function responderBarcode(array $respuesta, int $httpStatus = 200): void
{
    http_response_code($httpStatus);
    echo json_encode($respuesta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$rolRecibido = strtolower(trim((string) ($_SESSION['rol'] ?? '')));
$aliasRoles = [
    'admin' => 'administrador',
    'administrador' => 'administrador',
    'super_admin' => 'super_administrador',
    'superadministrador' => 'super_administrador',
    'super-administrador' => 'super_administrador',
    'super_administrador' => 'super_administrador',
];
$rolActual = $aliasRoles[$rolRecibido] ?? $rolRecibido;

if (empty($_SESSION['usuario_id']) || !in_array($rolActual, ['administrador', 'super_administrador'], true)) {
    responderBarcode([
        'success' => false,
        'status' => 'sin_sesion',
        'message' => 'Tu sesión no es válida para consultar productos.',
    ], 401);
}

$codigo = barcode_normalizar_codigo($_GET['codigo'] ?? '');
if ($codigo === '') {
    responderBarcode([
        'success' => false,
        'status' => 'codigo_invalido',
        'message' => 'Escanea o escribe un código válido.',
    ], 422);
}

if (strlen($codigo) > 50) {
    responderBarcode([
        'success' => false,
        'status' => 'codigo_invalido',
        'message' => 'El código supera el máximo permitido de 50 caracteres.',
    ], 422);
}

try {
    $productoLocal = barcode_buscar_producto_local($conn, $codigo);
    if ($productoLocal) {
        responderBarcode([
            'success' => true,
            'status' => 'inventario',
            'message' => 'Este código ya está registrado en el inventario.',
            'producto' => $productoLocal,
        ]);
    }

    $consulta = barcode_consultar_api($codigo);
    $statusApi = (string) ($consulta['status'] ?? 'error_api');
    $producto = $consulta['producto'] ?? null;
    $mensaje = (string) ($consulta['message'] ?? 'Completa los datos manualmente.');
    $quota = $consulta['quota'] ?? null;
    $desdeCache = (bool) ($consulta['desde_cache'] ?? false);

    if ($statusApi === 'encontrado' || $statusApi === 'datos_incompletos') {
        responderBarcode([
            'success' => true,
            'status' => 'externo',
            'api_status' => $statusApi,
            'message' => $mensaje,
            'producto' => $producto,
            'quota' => $quota,
            'desde_cache' => $desdeCache,
        ]);
    }

    responderBarcode([
        'success' => true,
        'status' => 'captura_manual',
        'api_status' => $statusApi,
        'message' => $mensaje,
        'producto' => [
            'codigo_barra' => $codigo,
            'nombre' => '',
            'categoria' => 'General',
            'marca' => '',
            'modelo' => '',
            'presentacion' => '',
            'color' => '',
            'talla' => '',
            'peso' => '',
            'material' => '',
            'descripcion' => '',
            'imagen_url' => '',
            'fuente' => 'manual',
        ],
        'quota' => $quota,
        'desde_cache' => $desdeCache,
    ]);
} catch (Throwable $e) {
    responderBarcode([
        'success' => false,
        'status' => 'error',
        'message' => 'No fue posible consultar el código: ' . $e->getMessage(),
    ], 500);
}
