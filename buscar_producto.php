<?php
declare(strict_types=1);

date_default_timezone_set('America/Mexico_City');

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/session.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

function responder(array $data, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$usuarioId = (int) ($_SESSION['usuario_id'] ?? 0);

if ($usuarioId <= 0) {
    responder([
        'success' => false,
        'message' => 'La sesión ya no está activa.'
    ], 401);
}

$codigo = strtoupper(trim((string) ($_GET['codigo'] ?? '')));
$codigo = preg_replace('/\s+/', '', $codigo);

if ($codigo === '') {
    responder([
        'success' => false,
        'message' => 'Escribe o escanea un código de barras.'
    ], 400);
}

$sql = "
    SELECT
        p.id,
        p.nombre,
        p.precio_venta,
        p.cantidad AS stock,
        p.stock_especial,
        p.tipo_venta,
        p.unidad_medida,
        p.decimales_cantidad,
        p.imagen,
        p.categoria,
        cb.codigo
    FROM codigos_barras cb
    INNER JOIN productos p
        ON p.id = cb.producto_id
    WHERE UPPER(TRIM(cb.codigo)) = ?
      AND cb.disponible = 1
      AND p.activo = 1
      AND p.tipo_inventario = 'producto'
      AND (
          p.cantidad > 0
          OR p.stock_especial = 1
      )
    LIMIT 1
";

$stmt = $conn->prepare($sql);

if (!$stmt) {
    responder([
        'success' => false,
        'message' => 'No fue posible preparar la búsqueda del producto.'
    ], 500);
}

$stmt->bind_param('s', $codigo);
$stmt->execute();
$resultado = $stmt->get_result();
$producto = $resultado ? $resultado->fetch_assoc() : null;
$stmt->close();

if (!$producto) {
    responder([
        'success' => false,
        'message' => 'Producto no encontrado, inactivo o sin existencias.'
    ], 404);
}

$esEspecial = (int) ($producto['stock_especial'] ?? 0) === 1;

responder([
    'success' => true,
    'id' => (int) $producto['id'],
    'nombre' => (string) $producto['nombre'],
    'precio_venta' => (float) $producto['precio_venta'],
    'stock' => (float) $producto['stock'],
    'tipo_venta' => (string) ($producto['tipo_venta'] ?? 'unidad'),
    'unidad_medida' => (string) ($producto['unidad_medida'] ?? 'pz'),
    'decimales_cantidad' => (int) ($producto['decimales_cantidad'] ?? 0),
    'stock_especial' => $esEspecial ? 1 : 0,
    'imagen' => (string) ($producto['imagen'] ?? ''),
    'categoria' => (string) ($producto['categoria'] ?? ''),
    'codigo' => (string) $producto['codigo'],
    'stock_texto' => $esEspecial
        ? 'Disponible siempre'
        : ((string) number_format((float) $producto['stock'], (($producto['tipo_venta'] ?? 'unidad') === 'peso' ? 3 : 0), '.', '')
            . ' ' . (($producto['unidad_medida'] ?? 'pz') === 'kg' ? 'kg disponibles' : 'disponibles'))
]);
