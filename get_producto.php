<?php
// get_producto.php - versión Hostinger/no-cache
error_reporting(E_ALL & ~E_DEPRECATED);
session_start();
require_once 'includes/db.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Cache-Control: post-check=0, pre-check=0', false);
header('Pragma: no-cache');
header('Expires: Thu, 01 Jan 1970 00:00:00 GMT');

if (!isset($_SESSION['usuario_id']) || ($_SESSION['rol'] ?? '') !== 'administrador') {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'No autorizado'], JSON_UNESCAPED_UNICODE);
    exit;
}

$id = isset($_GET['id']) ? intval($_GET['id']) : 0;
if ($id <= 0) {
    echo json_encode(['success' => false, 'error' => 'ID inválido'], JSON_UNESCAPED_UNICODE);
    exit;
}

function normalizarTipoCodigoRespuesta($tipo_inventario, $tipo_codigo) {
    if ($tipo_inventario !== 'producto') {
        return 'multiple';
    }

    $tipo_codigo = strtolower(trim((string)$tipo_codigo));
    return in_array($tipo_codigo, ['unico', 'multiple'], true) ? $tipo_codigo : 'multiple';
}

try {
    $stmt = $conn->prepare("SELECT * FROM productos WHERE id = ? AND activo = 1 LIMIT 1");
    if (!$stmt) {
        throw new Exception('Error preparando consulta: ' . $conn->error);
    }
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $result = $stmt->get_result();
    $producto = $result ? $result->fetch_assoc() : null;
    $stmt->close();

    if (!$producto) {
        echo json_encode(['success' => false, 'error' => 'Producto no encontrado'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $tipoOriginal = $producto['tipo_codigo'] ?? '';
    $producto['tipo_codigo'] = normalizarTipoCodigoRespuesta(
        $producto['tipo_inventario'] ?? 'producto',
        $producto['tipo_codigo'] ?? 'multiple'
    );
    $producto['tipo_codigo_original'] = $tipoOriginal;

    // Si en BD quedó vacío por algún guardado viejo, lo repara para no seguir mostrando mal el modal.
    if (($producto['tipo_inventario'] ?? '') === 'producto' && !in_array(strtolower(trim((string)$tipoOriginal)), ['unico', 'multiple'], true)) {
        $stmtFix = $conn->prepare("UPDATE productos SET tipo_codigo = ? WHERE id = ? LIMIT 1");
        if ($stmtFix) {
            $stmtFix->bind_param('si', $producto['tipo_codigo'], $id);
            $stmtFix->execute();
            $stmtFix->close();
        }
    }

    $producto['atributos_array'] = [];
    if (!empty($producto['atributos'])) {
        $decoded = json_decode($producto['atributos'], true);
        $producto['atributos_array'] = is_array($decoded) ? $decoded : [];
    }

    $producto['imagen_exists'] = !empty($producto['imagen']) && file_exists($producto['imagen']);

    $png_file = __DIR__ . '/uploads/codigos/producto_' . $producto['id'] . '.png';
    $zip_file = __DIR__ . '/uploads/codigos/producto_' . $producto['id'] . '.zip';
    $pdf_file = __DIR__ . '/uploads/codigos/producto_' . $producto['id'] . '.pdf';
    $producto['png_exists'] = file_exists($png_file);
    $producto['zip_exists'] = file_exists($zip_file);
    $producto['pdf_exists'] = file_exists($pdf_file);

    echo json_encode([
        'success' => true,
        'producto' => $producto,
        'server_time' => date('Y-m-d H:i:s')
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}
