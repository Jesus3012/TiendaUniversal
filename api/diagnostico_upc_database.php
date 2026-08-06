<?php
/**
 * Diagnóstico seguro de la configuración de UPC Database.
 * No muestra el token completo. Elimina este archivo cuando termines las pruebas.
 */

declare(strict_types=1);

ob_start();
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/upc_database.php';
while (ob_get_level() > 0) { ob_end_clean(); }

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

$rol = strtolower(trim((string) ($_SESSION['rol'] ?? '')));
$permitidos = ['administrador', 'super_administrador', 'admin', 'super_admin'];
if (empty($_SESSION['usuario_id']) || !in_array($rol, $permitidos, true)) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Sesión no autorizada.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$config = barcode_obtener_configuracion();
$diag = barcode_diagnostico_token_api($config['api_token'] ?? '');

$respuesta = [
    'success' => true,
    'archivo_configuracion' => realpath(__DIR__ . '/../config/upc_database.php') ?: (__DIR__ . '/../config/upc_database.php'),
    'api_activa' => (bool) ($config['activo'] ?? false),
    'endpoint' => (string) ($config['endpoint_base'] ?? ''),
    'curl_disponible' => function_exists('curl_init'),
    'token' => [
        'configurado' => (bool) ($diag['configurado'] ?? false),
        'longitud' => (int) ($diag['longitud'] ?? 0),
        'mascara' => (string) ($diag['mascara'] ?? ''),
        'formato_local_valido' => (bool) ($diag['formato_local_valido'] ?? false),
    ],
    'indicacion' => 'La máscara debe corresponder al OAuth token generado en API Keys. No debe ser tu contraseña, nombre de aplicación ni Client Secret.',
];

$codigo = barcode_normalizar_codigo($_GET['codigo'] ?? '');
if (($_GET['probar'] ?? '') === '1') {
    if ($codigo === '') {
        $respuesta['prueba'] = [
            'ejecutada' => false,
            'message' => 'Agrega ?probar=1&codigo=EL_CODIGO para efectuar una consulta real. Esta prueba consume una consulta del cupo.',
        ];
    } else {
        $consulta = barcode_consultar_api($codigo);
        $respuesta['prueba'] = [
            'ejecutada' => true,
            'codigo' => $codigo,
            'status' => $consulta['status'] ?? 'desconocido',
            'message' => $consulta['message'] ?? '',
            'quota' => $consulta['quota'] ?? null,
            'producto' => $consulta['producto'] ?? null,
        ];
    }
}

echo json_encode($respuesta, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
