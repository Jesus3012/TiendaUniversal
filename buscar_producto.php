<?php
declare(strict_types=1);

session_start();

require_once __DIR__ . '/includes/db.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

function responderJson(array $respuesta, int $codigoHttp = 200): void
{
    http_response_code($codigoHttp);

    echo json_encode(
        $respuesta,
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );

    exit;
}

if (!isset($_SESSION['usuario_id'])) {
    responderJson([
        'success' => false,
        'message' => 'No autorizado'
    ], 401);
}

$codigo = strtoupper(trim((string) ($_GET['codigo'] ?? '')));
$codigo = preg_replace('/\s+/', '', $codigo);

if ($codigo === '') {
    responderJson([
        'success' => false,
        'message' => 'Código vacío'
    ], 400);
}

/*
 * Regla definitiva:
 * - stock_especial = 1: producto sin cantidad fija.
 * - stock_especial = 0: producto con stock controlado.
 *
 * Un producto normal con cantidad 0 NO se considera especial y no puede venderse.
 */
$query = "
    SELECT
        p.id,
        p.nombre,
        p.precio_venta,
        p.cantidad AS stock,
        p.stock_especial,
        p.imagen,
        p.categoria,
        c.codigo
    FROM productos p
    INNER JOIN codigos_barras c
        ON c.producto_id = p.id
    WHERE UPPER(TRIM(c.codigo)) = ?
      AND c.disponible = 1
      AND p.activo = 1
      AND p.tipo_inventario = 'producto'
      AND (
          p.stock_especial = 1
          OR p.cantidad > 0
      )
    LIMIT 1
";

$stmt = $conn->prepare($query);

if (!$stmt) {
    responderJson([
        'success' => false,
        'message' => 'No fue posible preparar la búsqueda del producto.'
    ], 500);
}

$stmt->bind_param('s', $codigo);
$stmt->execute();

$result = $stmt->get_result();
$producto = $result ? $result->fetch_assoc() : null;

$stmt->close();

if (!$producto) {
    responderJson([
        'success' => false,
        'message' => 'Producto no encontrado, inactivo o sin stock.'
    ], 404);
}

$stock = (int) ($producto['stock'] ?? 0);
$esEspecial = (int) ($producto['stock_especial'] ?? 0) === 1;

// Verificar si tiene una imagen válida.
$tieneImagen = false;
$imagenUrl = '';
$imagenGuardada = trim((string) ($producto['imagen'] ?? ''));

if ($imagenGuardada !== '') {
    $rutaImagen = __DIR__ . '/' . ltrim($imagenGuardada, '/\\');

    if (is_file($rutaImagen)) {
        $tieneImagen = true;
        $imagenUrl = $imagenGuardada;
    }
}

function getIconoPorCategoria(string $categoria, string $nombre): string
{
    $texto = strtolower(trim($categoria !== '' ? $categoria : $nombre));

    if (preg_match('/(electronica|telefono|celular|smartphone|tablet|computadora|laptop|pc|monitor|teclado|mouse|audifonos|pantalla|impresora)/', $texto)) {
        return 'fas fa-microchip';
    }

    if (preg_match('/(ropa|camisa|pantalon|vestido|chaqueta|sueter|short|falda|jean|blusa|camiseta)/', $texto)) {
        return 'fas fa-tshirt';
    }

    if (preg_match('/(calzado|zapato|tenis|sandalia|botas|zapatilla|chancla)/', $texto)) {
        return 'fas fa-shoe-prints';
    }

    if (preg_match('/(alimento|comida|bebida|refresco|agua|snack|galleta|pan|leche|jugo|gaseosa)/', $texto)) {
        return 'fas fa-utensils';
    }

    if (preg_match('/(hogar|mueble|silla|mesa|escritorio|estante|cocina|baño|sofa|cama|ropero|armario)/', $texto)) {
        return 'fas fa-couch';
    }

    if (preg_match('/(papeleria|oficina|papel|lapiz|pluma|cuaderno|libreta|escritura|marcador|borrador|regla|folder|carpeta)/', $texto)) {
        return 'fas fa-pen';
    }

    if (preg_match('/(herramienta|martillo|destornillador|pinza|taladro|sierra|llave|alicate|nivel|cincel)/', $texto)) {
        return 'fas fa-tools';
    }

    if (preg_match('/(belleza|shampoo|jabon|crema|maquillaje|perfume|cosmetico|desodorante|pasta|cepillo|peine)/', $texto)) {
        return 'fas fa-spa';
    }

    if (preg_match('/(deporte|pelota|bicicleta|pesa|gimnasio|balon|raqueta|casco|guante)/', $texto)) {
        return 'fas fa-futbol';
    }

    if (preg_match('/(libro|revista|lectura|texto|manual|guia|diccionario|enciclopedia)/', $texto)) {
        return 'fas fa-book';
    }

    if (preg_match('/(juguete|muñeca|carro|peluche|lego|rompecabezas|bloques|consola|videojuego)/', $texto)) {
        return 'fas fa-gamepad';
    }

    if (preg_match('/(limpieza|limpia|detergente|cloro|escoba|trapeador|recogedor|bolsa)/', $texto)) {
        return 'fas fa-pump-soap';
    }

    return 'fas fa-box';
}

function getIconoColor(string $icono): string
{
    $colors = [
        'fa-microchip' => 'primary',
        'fa-tshirt' => 'info',
        'fa-shoe-prints' => 'warning',
        'fa-utensils' => 'success',
        'fa-couch' => 'secondary',
        'fa-pen' => 'indigo',
        'fa-tools' => 'danger',
        'fa-spa' => 'pink',
        'fa-futbol' => 'teal',
        'fa-book' => 'purple',
        'fa-gamepad' => 'orange',
        'fa-pump-soap' => 'cyan',
        'fa-box' => 'gray'
    ];

    $nombreIcono = str_replace('fas ', '', $icono);

    return $colors[$nombreIcono] ?? 'gray';
}

$icono = getIconoPorCategoria(
    (string) ($producto['categoria'] ?? ''),
    (string) $producto['nombre']
);

$iconoColor = getIconoColor($icono);

responderJson([
    'success' => true,
    'id' => (int) $producto['id'],
    'nombre' => (string) $producto['nombre'],
    'precio_venta' => (float) $producto['precio_venta'],
    'stock' => $stock,
    'stock_especial' => $esEspecial ? 1 : 0,
    'stock_texto' => $esEspecial
        ? 'Disponible siempre'
        : ($stock . ' disponibles'),
    'imagen' => $imagenUrl,
    'categoria' => (string) ($producto['categoria'] ?? ''),
    'tiene_imagen' => $tieneImagen,
    'icono' => $icono,
    'iconoColor' => $iconoColor,
    'codigo' => (string) ($producto['codigo'] ?? $codigo)
]);
