<?php
include('includes/db.php');
session_start();

header('Content-Type: application/json');

if (!isset($_SESSION['usuario_id'])) {
    echo json_encode(['success' => false, 'message' => 'No autorizado']);
    exit;
}

$codigo = $_GET['codigo'] ?? '';

if ($codigo === '') {
    echo json_encode(['success' => false, 'message' => 'Código vacío']);
    exit;
}

// Buscar producto por código de barras o ID
$query = "
    SELECT p.id, p.nombre, p.precio_venta, p.cantidad AS stock, p.imagen, p.categoria
    FROM productos p
    JOIN codigos_barras c ON c.producto_id = p.id
    WHERE c.codigo = '$codigo' AND c.disponible = 1
";

$result = $conn->query($query);

if ($result && $result->num_rows > 0) {
    $producto = $result->fetch_assoc();
    
    // Verificar si tiene imagen válida
    $tiene_imagen = false;
    $imagen_url = '';
    if (!empty($producto['imagen']) && file_exists($producto['imagen'])) {
        $tiene_imagen = true;
        $imagen_url = $producto['imagen'];
    }
    
    // Función para obtener icono según categoría
    function getIconoPorCategoria($categoria, $nombre) {
        $texto = strtolower($categoria ?: $nombre);
        
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
    
    function getIconoColor($icono) {
        $colors = [
            'fa-microchip' => 'primary', 'fa-tshirt' => 'info', 'fa-shoe-prints' => 'warning',
            'fa-utensils' => 'success', 'fa-couch' => 'secondary', 'fa-pen' => 'indigo',
            'fa-tools' => 'danger', 'fa-spa' => 'pink', 'fa-futbol' => 'teal',
            'fa-book' => 'purple', 'fa-gamepad' => 'orange', 'fa-pump-soap' => 'cyan', 'fa-box' => 'gray'
        ];
        return $colors[$icono] ?? 'gray';
    }
    
    $icono = getIconoPorCategoria($producto['categoria'], $producto['nombre']);
    $iconoColor = getIconoColor($icono);
    
    echo json_encode([
        'success' => true,
        'id' => $producto['id'],
        'nombre' => $producto['nombre'],
        'precio_venta' => floatval($producto['precio_venta']),
        'stock' => intval($producto['stock']),
        'imagen' => $imagen_url,
        'categoria' => $producto['categoria'] ?? '',
        'tiene_imagen' => $tiene_imagen,
        'icono' => $icono,
        'iconoColor' => $iconoColor
    ]);
} else {
    echo json_encode(['success' => false, 'message' => 'Producto no encontrado o sin stock.']);
}
?>