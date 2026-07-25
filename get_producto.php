<?php
// Archivo: get_producto.php

declare(strict_types=1);

error_reporting(E_ALL & ~E_DEPRECATED);
ini_set('display_errors', '0');

ob_start();

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

require_once __DIR__ . '/includes/db.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Cache-Control: post-check=0, pre-check=0', false);
header('Pragma: no-cache');
header('Expires: Thu, 01 Jan 1970 00:00:00 GMT');

/**
 * Envía una respuesta JSON limpia.
 */
function responderJson(array $datos, int $codigoHttp = 200): void
{
    http_response_code($codigoHttp);

    if (ob_get_level() > 0) {
        ob_clean();
    }

    echo json_encode(
        $datos,
        JSON_UNESCAPED_UNICODE |
        JSON_UNESCAPED_SLASHES |
        JSON_INVALID_UTF8_SUBSTITUTE
    );

    exit;
}

/**
 * Normaliza el tipo de código del producto.
 */
function normalizarTipoCodigoRespuesta(
    string $tipoInventario,
    $tipoCodigo
): string {
    if ($tipoInventario !== 'producto') {
        return 'multiple';
    }

    $tipoCodigo = strtolower(trim((string) $tipoCodigo));

    return in_array(
        $tipoCodigo,
        ['unico', 'multiple'],
        true
    )
        ? $tipoCodigo
        : 'multiple';
}

/*
|--------------------------------------------------------------------------
| Validar sesión y rol
|--------------------------------------------------------------------------
*/

$usuarioId = (int) ($_SESSION['usuario_id'] ?? 0);
$rolActual = strtolower(trim((string) ($_SESSION['rol'] ?? '')));

$rolesPermitidos = [
    'administrador',
    'super_administrador',
];

if (
    $usuarioId <= 0 ||
    !in_array($rolActual, $rolesPermitidos, true)
) {
    responderJson(
        [
            'success' => false,
            'error' => 'No tienes autorización para consultar productos.',
        ],
        403
    );
}

/*
|--------------------------------------------------------------------------
| Validar ID
|--------------------------------------------------------------------------
*/

$id = isset($_GET['id'])
    ? (int) $_GET['id']
    : 0;

if ($id <= 0) {
    responderJson(
        [
            'success' => false,
            'error' => 'El ID del producto no es válido.',
        ],
        400
    );
}

/*
|--------------------------------------------------------------------------
| Consultar producto
|--------------------------------------------------------------------------
*/

try {
    $stmt = $conn->prepare("
        SELECT *
        FROM productos
        WHERE id = ?
          AND activo = 1
        LIMIT 1
    ");

    if (!$stmt) {
        throw new RuntimeException(
            'No fue posible preparar la consulta: ' . $conn->error
        );
    }

    $stmt->bind_param('i', $id);

    if (!$stmt->execute()) {
        throw new RuntimeException(
            'No fue posible consultar el producto: ' . $stmt->error
        );
    }

    $resultado = $stmt->get_result();

    $producto = $resultado
        ? $resultado->fetch_assoc()
        : null;

    $stmt->close();

    if (!is_array($producto)) {
        responderJson(
            [
                'success' => false,
                'error' => 'El producto no existe o está inactivo.',
            ],
            404
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Normalizar datos
    |--------------------------------------------------------------------------
    */

    $tipoInventario = strtolower(
        trim((string) ($producto['tipo_inventario'] ?? 'producto'))
    );

    $tipoCodigoOriginal = strtolower(
        trim((string) ($producto['tipo_codigo'] ?? ''))
    );

    $producto['tipo_codigo'] = normalizarTipoCodigoRespuesta(
        $tipoInventario,
        $tipoCodigoOriginal
    );

    $producto['tipo_codigo_original'] = $tipoCodigoOriginal;

    /*
    |--------------------------------------------------------------------------
    | Corregir un ENUM vacío únicamente cuando sea necesario
    |--------------------------------------------------------------------------
    |
    | Esto solo corrige productos cuyo campo tipo_codigo quedó vacío.
    | No modifica los códigos de barras existentes.
    |
    */

    if (
        $tipoInventario === 'producto' &&
        !in_array(
            $tipoCodigoOriginal,
            ['unico', 'multiple'],
            true
        )
    ) {
        $tipoCodigoCorregido = $producto['tipo_codigo'];

        $stmtFix = $conn->prepare("
            UPDATE productos
            SET tipo_codigo = ?
            WHERE id = ?
            LIMIT 1
        ");

        if ($stmtFix) {
            $stmtFix->bind_param(
                'si',
                $tipoCodigoCorregido,
                $id
            );

            $stmtFix->execute();
            $stmtFix->close();
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Decodificar atributos
    |--------------------------------------------------------------------------
    */

    $producto['atributos_array'] = [];

    if (!empty($producto['atributos'])) {
        $atributosDecodificados = json_decode(
            (string) $producto['atributos'],
            true
        );

        if (is_array($atributosDecodificados)) {
            $producto['atributos_array'] = $atributosDecodificados;
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Verificar archivos
    |--------------------------------------------------------------------------
    */

    $imagenRelativa = trim(
        (string) ($producto['imagen'] ?? '')
    );

    $imagenFisica = $imagenRelativa !== ''
        ? __DIR__ . '/' . ltrim($imagenRelativa, '/\\')
        : '';

    $producto['imagen_exists'] =
        $imagenFisica !== '' &&
        is_file($imagenFisica);

    $directorioCodigos = __DIR__ . '/uploads/codigos/';

    $pngFile = $directorioCodigos
        . 'producto_'
        . $id
        . '.png';

    $zipFile = $directorioCodigos
        . 'producto_'
        . $id
        . '.zip';

    $pdfFile = $directorioCodigos
        . 'producto_'
        . $id
        . '.pdf';

    $producto['png_exists'] = is_file($pngFile);
    $producto['zip_exists'] = is_file($zipFile);
    $producto['pdf_exists'] = is_file($pdfFile);

    /*
    |--------------------------------------------------------------------------
    | Detectar códigos históricos sin P
    |--------------------------------------------------------------------------
    */

    $producto['codigo_legacy_protegido'] = false;
    $producto['codigos_legacy'] = [];

    $stmtCodigos = $conn->prepare("
        SELECT codigo
        FROM codigos_barras
        WHERE producto_id = ?
          AND UPPER(codigo) NOT LIKE 'P%'
        ORDER BY id ASC
    ");

    if ($stmtCodigos) {
        $stmtCodigos->bind_param('i', $id);
        $stmtCodigos->execute();

        $resultadoCodigos = $stmtCodigos->get_result();

        if ($resultadoCodigos) {
            while ($codigoRow = $resultadoCodigos->fetch_assoc()) {
                $codigoLegacy = trim(
                    (string) ($codigoRow['codigo'] ?? '')
                );

                if ($codigoLegacy !== '') {
                    $producto['codigos_legacy'][] = $codigoLegacy;
                }
            }
        }

        $stmtCodigos->close();
    }

    $producto['codigo_legacy_protegido'] =
        count($producto['codigos_legacy']) > 0;

    /*
    |--------------------------------------------------------------------------
    | Respuesta
    |--------------------------------------------------------------------------
    */

    responderJson([
        'success' => true,
        'producto' => $producto,
        'rol_actual' => $rolActual,
        'server_time' => date('Y-m-d H:i:s'),
    ]);
} catch (Throwable $e) {
    error_log(
        'Error en get_producto.php: ' . $e->getMessage()
    );

    responderJson(
        [
            'success' => false,
            'error' => 'No fue posible cargar los datos del producto.',
            'detalle' => $e->getMessage(),
        ],
        500
    );
}