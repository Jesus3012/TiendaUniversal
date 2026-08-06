<?php
ob_start();

// La sesión siempre debe iniciarse desde el controlador central.
// No uses session_start() directamente porque en local puede abrir una sesión
// distinta cuando includes/session.php utiliza storage/sessions.
require_once __DIR__ . '/includes/session.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/includes/upc_database.php';

$rol_recibido = strtolower(trim((string) ($_SESSION['rol'] ?? '')));

$alias_roles = [
    'admin' => 'administrador',
    'administrador' => 'administrador',
    'super_admin' => 'super_administrador',
    'superadministrador' => 'super_administrador',
    'super-administrador' => 'super_administrador',
    'super_administrador' => 'super_administrador',
];

$rol_actual = $alias_roles[$rol_recibido] ?? $rol_recibido;
$roles_administrativos = ['administrador', 'super_administrador'];

if (
    empty($_SESSION['usuario_id']) ||
    !in_array($rol_actual, $roles_administrativos, true)
) {
    header('Location: login.php');
    exit;
}

// Mantener el rol canónico para navbar, permisos y siguientes peticiones.
$_SESSION['rol'] = $rol_actual;


include 'includes/header.php';
include 'includes/navbar.php';
require_once 'includes/fpdf.php';
require_once __DIR__.'/vendor/autoload.php';

use Picqer\Barcode\BarcodeGeneratorPNG;

$success = '';
$errors = [];

/**
 * Permite instalar primero los archivos PHP y después ejecutar la migración
 * sin dejar inutilizable el alta normal de productos.
 */
function productosTieneColumnaFijadoVenta(mysqli $conn): bool
{
    try {
        $resultado = $conn->query("SHOW COLUMNS FROM productos LIKE 'fijado_venta'");
        return $resultado instanceof mysqli_result && $resultado->num_rows > 0;
    } catch (Throwable $e) {
        return false;
    }
}

$productosSoportaFijadoVenta = productosTieneColumnaFijadoVenta($conn);

// Obtener parámetro de tipo desde la URL
$tipo_seleccionado = isset($_GET['tipo']) ? $_GET['tipo'] : 'producto';

// ======================= FUNCIONES AUXILIARES =======================
function obtenerCategorias($conn) {
    $result = $conn->query("SELECT DISTINCT categoria FROM productos WHERE activo = 1 AND categoria != '' ORDER BY categoria");
    $categorias = [];
    while ($row = $result->fetch_assoc()) {
        $categorias[] = $row['categoria'];
    }
    return $categorias;
}

function obtenerProveedores($conn) {
    $result = $conn->query("SELECT id, nombre, correo, telefono, calle, numero, colonia, ciudad, estado, codigo_postal, pais, logo FROM proveedores WHERE activo = 1 ORDER BY nombre");
    $proveedores = [];
    while ($row = $result->fetch_assoc()) {
        $row['direccion_completa'] = trim(
            ($row['calle'] ?? '') . ' ' . 
            ($row['numero'] ?? '') . ', ' . 
            ($row['colonia'] ?? '') . ', ' . 
            ($row['ciudad'] ?? '') . ', ' . 
            ($row['estado'] ?? '') . ', ' . 
            ($row['codigo_postal'] ?? '') . ', ' . 
            ($row['pais'] ?? 'México')
        );
        $row['direccion_completa'] = trim(preg_replace('/,\s*,/', ',', $row['direccion_completa']), ', ');
        $proveedores[] = $row;
    }
    return $proveedores;
}


// ======================= FORMATO NUEVO DE CÓDIGOS =======================
// Todos los productos nuevos usan el ID real, sin ceros a la izquierda:
// - Código único:    P200
// - Código múltiple: P200, P200-2, P200-3...
//
// El primer código siempre es P + producto_id. Los consecutivos adicionales
// solo se crean cuando el producto usa código múltiple.
function normalizarTipoCodigoProducto($tipo_inventario, $tipo_codigo) {
    if ($tipo_inventario !== 'producto') {
        return 'multiple';
    }

    $tipo_codigo = strtolower(trim((string)$tipo_codigo));
    return $tipo_codigo === 'unico' ? 'unico' : 'multiple';
}

function normalizarCodigoBarra($codigo) {
    $codigo = trim((string)$codigo);
    $codigo = preg_replace('/\s+/', '', $codigo);
    return strtoupper($codigo);
}

function codigoUnicoProducto($producto_id) {
    return 'P' . (string)(int)$producto_id;
}

function codigoMultipleProducto($producto_id, $consecutivo) {
    $producto_id = (int)$producto_id;
    $consecutivo = max(1, (int)$consecutivo);

    // Primera pieza: P200. Las siguientes: P200-2, P200-3...
    if ($consecutivo === 1) {
        return codigoUnicoProducto($producto_id);
    }

    return codigoUnicoProducto($producto_id) . '-' . $consecutivo;
}

function aplicarTresPorcientoPrecioVenta($precio_base) {
    $precio_base = round((float)$precio_base, 2);

    if ($precio_base <= 0) {
        return 0.00;
    }

    // Se aplica una sola vez y el resultado final se cierra al peso entero.
    // Ejemplos: 301 x 1.03 = 310.03 -> 310; 299 x 1.03 = 307.97 -> 308.
    return (float) round($precio_base * 1.03, 0, PHP_ROUND_HALF_UP);
}

function insertarCodigoBarraSeguro($conn, $producto_id, $codigo, $disponible = 1, $origen = 'interno', $es_principal = 1) {
    $producto_id = (int)$producto_id;
    $codigo = barcode_normalizar_codigo($codigo);
    $disponible = (int)$disponible;
    $es_principal = (int)$es_principal;
    $origen = strtolower(trim((string)$origen));
    $origenesPermitidos = ['fabricante', 'interno', 'bascula', 'manual'];
    if (!in_array($origen, $origenesPermitidos, true)) {
        $origen = 'manual';
    }

    if ($codigo === '') {
        throw new Exception('Se intentó guardar un código vacío.');
    }

    if (strlen($codigo) > 50) {
        throw new Exception('El código no puede superar 50 caracteres.');
    }

    $stmt = $conn->prepare("SELECT id, producto_id FROM codigos_barras WHERE codigo = ? LIMIT 1");
    if (!$stmt) {
        throw new Exception('Error preparando validación de código: ' . $conn->error);
    }
    $stmt->bind_param('s', $codigo);
    $stmt->execute();
    $res = $stmt->get_result();
    $existente = $res ? $res->fetch_assoc() : null;
    $stmt->close();

    if ($existente && (int)$existente['producto_id'] !== $producto_id) {
        throw new Exception("El código {$codigo} ya está asignado a otro producto.");
    }

    $tieneOrigen = barcode_tiene_columna($conn, 'codigos_barras', 'origen');
    $tienePrincipal = barcode_tiene_columna($conn, 'codigos_barras', 'es_principal');

    if ($existente) {
        $idExistente = (int)$existente['id'];

        if ($tieneOrigen && $tienePrincipal) {
            $stmt = $conn->prepare("UPDATE codigos_barras SET disponible = ?, origen = ?, es_principal = ? WHERE id = ?");
            $stmt->bind_param('isii', $disponible, $origen, $es_principal, $idExistente);
        } elseif ($tieneOrigen) {
            $stmt = $conn->prepare("UPDATE codigos_barras SET disponible = ?, origen = ? WHERE id = ?");
            $stmt->bind_param('isi', $disponible, $origen, $idExistente);
        } elseif ($tienePrincipal) {
            $stmt = $conn->prepare("UPDATE codigos_barras SET disponible = ?, es_principal = ? WHERE id = ?");
            $stmt->bind_param('iii', $disponible, $es_principal, $idExistente);
        } else {
            $stmt = $conn->prepare("UPDATE codigos_barras SET disponible = ? WHERE id = ?");
            $stmt->bind_param('ii', $disponible, $idExistente);
        }

        if (!$stmt || !$stmt->execute()) {
            $error = $stmt ? $stmt->error : $conn->error;
            if ($stmt) $stmt->close();
            throw new Exception('Error actualizando código existente: ' . $error);
        }
        $stmt->close();
        return;
    }

    if ($tieneOrigen && $tienePrincipal) {
        $stmt = $conn->prepare("INSERT INTO codigos_barras (producto_id, codigo, origen, es_principal, disponible) VALUES (?, ?, ?, ?, ?)");
        if ($stmt) $stmt->bind_param('issii', $producto_id, $codigo, $origen, $es_principal, $disponible);
    } elseif ($tieneOrigen) {
        $stmt = $conn->prepare("INSERT INTO codigos_barras (producto_id, codigo, origen, disponible) VALUES (?, ?, ?, ?)");
        if ($stmt) $stmt->bind_param('issi', $producto_id, $codigo, $origen, $disponible);
    } elseif ($tienePrincipal) {
        $stmt = $conn->prepare("INSERT INTO codigos_barras (producto_id, codigo, es_principal, disponible) VALUES (?, ?, ?, ?)");
        if ($stmt) $stmt->bind_param('isii', $producto_id, $codigo, $es_principal, $disponible);
    } else {
        $stmt = $conn->prepare("INSERT INTO codigos_barras (producto_id, codigo, disponible) VALUES (?, ?, ?)");
        if ($stmt) $stmt->bind_param('isi', $producto_id, $codigo, $disponible);
    }

    if (!$stmt) {
        throw new Exception('Error preparando inserción de código: ' . $conn->error);
    }
    if (!$stmt->execute()) {
        $error = $stmt->error;
        $stmt->close();
        throw new Exception('Error insertando código de barras: ' . $error);
    }
    $stmt->close();
}

function limpiarArchivosCodigosProducto($producto_id) {
    $codigos_dir = __DIR__ . '/uploads/codigos/';
    foreach (glob($codigos_dir . 'producto_' . intval($producto_id) . '*.{png,zip,pdf}', GLOB_BRACE) as $archivo) {
        if (is_file($archivo)) {
            @unlink($archivo);
        }
    }
}

function textoCortoPNG($texto, $max = 60) {
    $texto = trim((string)$texto);
    $texto = preg_replace('/\s+/u', ' ', $texto);
    if (function_exists('mb_strlen') && mb_strlen($texto, 'UTF-8') > $max) {
        return mb_substr($texto, 0, $max, 'UTF-8');
    }
    if (!function_exists('mb_strlen') && strlen($texto) > $max) {
        return substr($texto, 0, $max);
    }
    return $texto;
}

function gdTextCentered($img, $size, $y, $color, $font, $text, $canvasWidth) {
    $text = (string)$text;
    if ($font && file_exists($font)) {
        $box = imagettfbbox($size, 0, $font, $text);
        $textWidth = abs($box[2] - $box[0]);
        $x = intval(($canvasWidth - $textWidth) / 2);
        imagettftext($img, $size, 0, $x, $y, $color, $font, $text);
    } else {
        $fontSize = max(1, min(5, intval($size / 3)));
        $textWidth = imagefontwidth($fontSize) * strlen($text);
        imagestring($img, $fontSize, intval(($canvasWidth - $textWidth) / 2), $y - 12, $text, $color);
    }
}

function medirTextoGD($font, $size, $text) {
    $text = (string)$text;
    if ($font && file_exists($font)) {
        $box = imagettfbbox($size, 0, $font, $text);
        return abs($box[2] - $box[0]);
    }
    $fontSize = max(1, min(5, intval($size / 3)));
    return imagefontwidth($fontSize) * strlen($text);
}

function truncarTextoPorAncho($texto, $font, $size, $maxWidth) {
    $texto = trim((string)$texto);
    if ($texto === '') {
        return '';
    }
    if (medirTextoGD($font, $size, $texto) <= $maxWidth) {
        return $texto;
    }
    $ellipsis = '…';
    while ($texto !== '' && medirTextoGD($font, $size, $texto . $ellipsis) > $maxWidth) {
        if (function_exists('mb_substr')) {
            $texto = rtrim(mb_substr($texto, 0, mb_strlen($texto, 'UTF-8') - 1, 'UTF-8'));
        } else {
            $texto = rtrim(substr($texto, 0, strlen($texto) - 1));
        }
    }
    return $texto === '' ? $ellipsis : $texto . $ellipsis;
}

function convertirBlancoATransparente($src, $tolerancia = 245) {
    // FIX HOSTINGER/GD:
    // En algunos servidores, Picqer genera el fondo del barcode como transparente,
    // pero con RGB negro. Si solo se revisa RGB, el fondo transparente se convierte
    // en negro y el código queda como un rectángulo sólido.
    // Para etiquetas de la Nimbot es más seguro dejar fondo blanco real.
    if (!$src) {
        return null;
    }

    $w = imagesx($src);
    $h = imagesy($src);
    if ($w <= 0 || $h <= 0) {
        return $src;
    }

    $dst = imagecreatetruecolor($w, $h);
    imagealphablending($dst, true);
    imagesavealpha($dst, false);

    $white = imagecolorallocate($dst, 255, 255, 255);
    $black = imagecolorallocate($dst, 0, 0, 0);
    imagefilledrectangle($dst, 0, 0, $w, $h, $white);

    for ($y = 0; $y < $h; $y++) {
        for ($x = 0; $x < $w; $x++) {
            $rgba = imagecolorat($src, $x, $y);
            $c = imagecolorsforindex($src, $rgba);
            $alpha = isset($c['alpha']) ? (int)$c['alpha'] : 0;

            // Alpha alto = transparente => blanco.
            // Blanco/casi blanco => blanco.
            // Todo lo demás son barras => negro.
            if (
                $alpha >= 120 ||
                ($c['red'] >= $tolerancia && $c['green'] >= $tolerancia && $c['blue'] >= $tolerancia)
            ) {
                imagesetpixel($dst, $x, $y, $white);
            } else {
                imagesetpixel($dst, $x, $y, $black);
            }
        }
    }

    return $dst;
}

function copiarBarcodeSinDistorsion($dst, $barcode, $dstX, $dstY, $dstW, $dstH) {
    $srcW = imagesx($barcode);
    $srcH = imagesy($barcode);
    if ($srcW <= 0 || $srcH <= 0) return;

    if ($srcW <= $dstW && $srcH <= $dstH) {
        $x = $dstX + intval(($dstW - $srcW) / 2);
        $y = $dstY + intval(($dstH - $srcH) / 2);
        imagecopy($dst, $barcode, $x, $y, 0, 0, $srcW, $srcH);
        return;
    }

    $scale = min($dstW / $srcW, $dstH / $srcH);
    $newW = max(1, intval($srcW * $scale));
    $newH = max(1, intval($srcH * $scale));
    $x = $dstX + intval(($dstW - $newW) / 2);
    $y = $dstY + intval(($dstH - $newH) / 2);
    imagecopyresized($dst, $barcode, $x, $y, 0, 0, $newW, $newH, $srcW, $srcH);
}

function crearBarcodePNGExacto($generator, $codigo, $maxWidth, $height) {
    foreach ([5, 4, 3, 2, 1] as $factor) {
        $pngData = $generator->getBarcode($codigo, $generator::TYPE_CODE_128, $factor, $height);
        $barcode = @imagecreatefromstring($pngData);
        if ($barcode && imagesx($barcode) <= $maxWidth) {
            $barcodeTransparente = convertirBlancoATransparente($barcode);
            imagedestroy($barcode);
            return $barcodeTransparente;
        }
        if ($barcode) {
            imagedestroy($barcode);
        }
    }

    $pngData = $generator->getBarcode($codigo, $generator::TYPE_CODE_128, 1, $height);
    $barcode = @imagecreatefromstring($pngData);
    $barcodeTransparente = convertirBlancoATransparente($barcode);
    if ($barcode) {
        imagedestroy($barcode);
    }
    return $barcodeTransparente;
}

function dibujarEtiquetaCodigoEnCanvas($img, $item, $topY, $generator, $fontRegular, $fontBold) {
    $canvasW = 240;
    $bloqueH = 133;
    $bottomY = $topY + $bloqueH - 1;

    $white = imagecolorallocate($img, 255, 255, 255);
    $black = imagecolorallocate($img, 0, 0, 0);
    $lightGray = imagecolorallocate($img, 232, 232, 232);

    imagefilledrectangle($img, 0, $topY, $canvasW - 1, $bottomY, $white);

    $nombre = textoCortoPNG($item['nombre'] ?? '', 68);
    $precio = '$' . number_format((float)($item['precio_venta'] ?? 0), 0);
    $codigo = (string)($item['codigo'] ?? '');

    $titulo = trim($nombre . '  ' . $precio);
    $titulo = truncarTextoPorAncho($titulo, $fontBold, 8, 232);
    gdTextCentered($img, 8, $topY + 11, $black, $fontBold, $titulo, $canvasW);

    $barcodeMaxW = 234;
    $barcodeMaxH = 86;
    $barcode = crearBarcodePNGExacto($generator, $codigo, $barcodeMaxW, 82);
    if ($barcode) {
        copiarBarcodeSinDistorsion($img, $barcode, 3, $topY + 16, $barcodeMaxW, $barcodeMaxH);
        imagedestroy($barcode);
    }

    gdTextCentered($img, 10, $topY + 112, $black, $fontBold, $codigo, $canvasW);

    if ($bottomY < 399) {
        imageline($img, 4, $bottomY, $canvasW - 4, $bottomY, $lightGray);
    }
}

function completarGrupoTresCodigos($grupo) {
    if (empty($grupo)) {
        return $grupo;
    }
    $grupo = array_values($grupo);
    while (count($grupo) < 3) {
        $grupo[] = end($grupo);
    }
    return array_slice($grupo, 0, 3);
}

function crearPNGTripleCodigos($items, $destino) {
    $generator = new BarcodeGeneratorPNG();

    $w = 240;
    $h = 400;
    $img = imagecreatetruecolor($w, $h);

    $fontRegular = null;
    $fontBold = null;
    $fontCandidatesRegular = [
        __DIR__ . '/assets/fonts/Arial.ttf',
        __DIR__ . '/assets/fonts/DejaVuSans.ttf',
        '/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf',
        'C:/Windows/Fonts/arial.ttf'
    ];
    $fontCandidatesBold = [
        __DIR__ . '/assets/fonts/Arial-Bold.ttf',
        __DIR__ . '/assets/fonts/DejaVuSans-Bold.ttf',
        '/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf',
        'C:/Windows/Fonts/arialbd.ttf'
    ];

    foreach ($fontCandidatesRegular as $f) {
        if (file_exists($f)) { $fontRegular = $f; break; }
    }
    foreach ($fontCandidatesBold as $f) {
        if (file_exists($f)) { $fontBold = $f; break; }
    }

    $items = completarGrupoTresCodigos($items);
    for ($i = 0; $i < 3; $i++) {
        if (isset($items[$i])) {
            dibujarEtiquetaCodigoEnCanvas($img, $items[$i], $i * 133, $generator, $fontRegular, $fontBold);
        }
    }

    imagepng($img, $destino, 0);
    imagedestroy($img);
}

function obtenerCodigosProductoParaPNG($conn, $producto_id) {
    $stmt = $conn->prepare("SELECT p.id, p.nombre, p.precio_venta, cb.codigo
        FROM productos p
        JOIN codigos_barras cb ON p.id = cb.producto_id
        WHERE p.id = ? AND p.activo = 1 AND p.tipo_inventario = 'producto'
        ORDER BY cb.id ASC");
    if (!$stmt) {
        throw new Exception('Error consultando códigos para PNG: ' . $conn->error);
    }
    $stmt->bind_param('i', $producto_id);
    $stmt->execute();
    $res = $stmt->get_result();

    $items = [];
    while ($row = $res->fetch_assoc()) {
        $items[] = $row;
    }
    $stmt->close();
    return $items;
}

function generarPNGCodigosProducto($conn, $producto_id) {
    $codigos_dir = __DIR__ . '/uploads/codigos/';
    if (!is_dir($codigos_dir)) {
        mkdir($codigos_dir, 0777, true);
    }

    limpiarArchivosCodigosProducto($producto_id);

    $items = obtenerCodigosProductoParaPNG($conn, $producto_id);
    if (empty($items)) {
        return;
    }

    $chunks = array_chunk($items, 3);
    $archivos = [];

    foreach ($chunks as $index => $grupo) {
        $numero = str_pad($index + 1, 3, '0', STR_PAD_LEFT);
        $archivo = $codigos_dir . 'producto_' . intval($producto_id) . '_' . $numero . '.png';
        crearPNGTripleCodigos($grupo, $archivo);
        $archivos[] = $archivo;
    }

    if (!empty($archivos[0])) {
        @copy($archivos[0], $codigos_dir . 'producto_' . intval($producto_id) . '.png');
    }

    if (count($archivos) > 1 && class_exists('ZipArchive')) {
        $zipPath = $codigos_dir . 'producto_' . intval($producto_id) . '.zip';
        $zip = new ZipArchive();
        if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) === true) {
            foreach ($archivos as $archivo) {
                $zip->addFile($archivo, basename($archivo));
            }
            $zip->close();
        }
    }
}

// ========================= AGREGAR PROVEEDOR =========================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add_proveedor') {
    // Limpiar completamente el buffer
    while (ob_get_level()) ob_end_clean();
    ob_start();
    header('Content-Type: application/json');
    
    $nombre = trim($_POST['nombre'] ?? '');
    $correo = trim($_POST['correo'] ?? '');
    $telefono = trim($_POST['telefono'] ?? '');
    $calle = trim($_POST['calle'] ?? '');
    $numero = trim($_POST['numero'] ?? '');
    $colonia = trim($_POST['colonia'] ?? '');
    $ciudad = trim($_POST['ciudad'] ?? '');
    $estado = trim($_POST['estado'] ?? '');
    $codigo_postal = trim($_POST['codigo_postal'] ?? '');
    $pais = trim($_POST['pais'] ?? 'México');
    
    // Validar
    if ($nombre === '') {
        echo json_encode(['success' => false, 'error' => 'El nombre del proveedor es obligatorio.']);
        exit;
    }
    
    // Verificar si ya existe
    $stmt = $conn->prepare("SELECT id FROM proveedores WHERE nombre = ? AND activo = 1");
    $stmt->bind_param("s", $nombre);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows > 0) {
        echo json_encode(['success' => false, 'error' => 'Ya existe un proveedor con este nombre.']);
        exit;
    }
    
    // Procesar logo
    $logo_path = '';
    if (!empty($_FILES['logo']['name'])) {
        $upload_dir = __DIR__.'/uploads/proveedores/';
        if (!is_dir($upload_dir)) mkdir($upload_dir, 0777, true);
        
        $extension = strtolower(pathinfo($_FILES['logo']['name'], PATHINFO_EXTENSION));
        $nombre_limpio = preg_replace('/[^a-zA-Z0-9áéíóúüñÁÉÍÓÚÜÑ\s-]/u', '', $nombre);
        $nombre_limpio = preg_replace('/[\s]+/', '_', $nombre_limpio);
        $logo_name = $nombre_limpio . '.' . $extension;
        $contador = 1;
        
        while (file_exists($upload_dir . $logo_name)) {
            $logo_name = $nombre_limpio . '_' . $contador . '.' . $extension;
            $contador++;
        }
        
        $logo_path = 'uploads/proveedores/' . $logo_name;
        
        if (!move_uploaded_file($_FILES['logo']['tmp_name'], $upload_dir . $logo_name)) {
            echo json_encode(['success' => false, 'error' => 'Error al subir el logo.']);
            exit;
        }
    }
    
    // Insertar proveedor
    $stmt = $conn->prepare("INSERT INTO proveedores (nombre, correo, telefono, calle, numero, colonia, ciudad, estado, codigo_postal, pais, logo, activo) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1)");
    $stmt->bind_param("sssssssssss", $nombre, $correo, $telefono, $calle, $numero, $colonia, $ciudad, $estado, $codigo_postal, $pais, $logo_path);
    
    if ($stmt->execute()) {
        $nuevo_id = $stmt->insert_id;
        echo json_encode(['success' => true, 'id' => $nuevo_id, 'nombre' => $nombre]);
    } else {
        echo json_encode(['success' => false, 'error' => 'Error al guardar el proveedor: ' . $conn->error]);
    }
    exit;
}

// ========================= AGREGAR PRODUCTO =========================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'create') {
    csrf_check();

    $tipo_inventario = $_POST['tipo_inventario'] ?? 'producto';
    $modo_codigo = strtolower(trim((string)($_POST['modo_codigo'] ?? 'comercial')));
    $modo_codigo = in_array($modo_codigo, ['comercial', 'interno'], true) ? $modo_codigo : 'comercial';
    $codigo_barra = barcode_normalizar_codigo($_POST['codigo_barra'] ?? '');
    $barcode_fuente = strtolower(trim((string)($_POST['barcode_fuente'] ?? 'manual')));
    $barcode_fuente = $barcode_fuente === 'upc_database' ? 'upc_database' : 'manual';
    $barcode_descripcion = trim((string)($_POST['barcode_descripcion'] ?? ''));
    $barcode_imagen_url = trim((string)($_POST['barcode_imagen_url'] ?? ''));

    $nombre = trim($_POST['nombre'] ?? '');
    $categoria = trim($_POST['categoria'] ?? 'General');
    $proveedor_id = intval($_POST['proveedor_id'] ?? 0);
    $tipo_adquisicion = $_POST['tipo_adquisicion'] ?? 'pagado';
    $stock_especial = (
        $tipo_inventario === 'producto' &&
        (string)($_POST['stock_especial'] ?? '0') === '1'
    ) ? 1 : 0;
    $fijado_venta = (
        $tipo_inventario === 'producto' &&
        (string)($_POST['fijado_venta'] ?? '0') === '1'
    ) ? 1 : 0;
    $tipo_venta = strtolower(trim((string)($_POST['tipo_venta'] ?? 'unidad')));
    $tipo_venta = in_array($tipo_venta, ['unidad', 'peso'], true) ? $tipo_venta : 'unidad';
    $unidad_medida = $tipo_venta === 'peso' ? 'kg' : 'pz';
    $decimales_cantidad = $tipo_venta === 'peso' ? 3 : 0;

    $proveedor_nombre = '';
    if ($proveedor_id > 0) {
        $stmt = $conn->prepare("SELECT nombre FROM proveedores WHERE id = ? AND activo = 1");
        if ($stmt) {
            $stmt->bind_param('i', $proveedor_id);
            $stmt->execute();
            $result = $stmt->get_result();
            if ($row = $result->fetch_assoc()) {
                $proveedor_nombre = $row['nombre'];
            }
            $stmt->close();
        }
    }

    if ($tipo_inventario === 'producto') {
        $cantidad = $tipo_venta === 'peso'
            ? round((float)($_POST['cantidad'] ?? 0), 3)
            : (float)intval($_POST['cantidad'] ?? 0);
        $precio_compra = round((float)($_POST['precio_compra'] ?? 0), 2);
        $precio_venta_base = round((float)($_POST['precio_venta'] ?? 0), 2);
        $precio_venta = aplicarTresPorcientoPrecioVenta($precio_venta_base);
        $tipo_codigo = normalizarTipoCodigoProducto($tipo_inventario, $_POST['tipo_codigo'] ?? 'unico');

        // Un código comercial pertenece a la presentación completa del fabricante.
        // Todas las piezas iguales comparten ese mismo código.
        if ($modo_codigo === 'comercial') {
            $tipo_codigo = 'unico';
        }

        if ($tipo_venta === 'peso') {
            $stock_especial = 0;
            $tipo_codigo = 'unico';
        } elseif ($stock_especial === 1) {
            $cantidad = 0;
            $tipo_codigo = 'unico';
        }

        $atributos = [];
        $campos_atributos = ['marca', 'modelo', 'color', 'talla', 'peso', 'material', 'presentacion'];
        foreach ($campos_atributos as $campo) {
            $valor = trim((string)($_POST[$campo] ?? ''));
            if ($valor !== '') {
                $atributos[$campo] = $valor;
            }
        }

        if ($modo_codigo === 'comercial') {
            $atributos['codigo_comercial'] = $codigo_barra;
            $atributos['fuente_datos'] = $barcode_fuente;
            if ($barcode_descripcion !== '') {
                $atributos['descripcion_referencia'] = function_exists('mb_substr')
                    ? mb_substr($barcode_descripcion, 0, 800, 'UTF-8')
                    : substr($barcode_descripcion, 0, 800);
            }
            if ($barcode_imagen_url !== '' && filter_var($barcode_imagen_url, FILTER_VALIDATE_URL)) {
                $atributos['imagen_referencia'] = $barcode_imagen_url;
            }
        }

        $atributos_json = !empty($atributos)
            ? json_encode($atributos, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            : null;
    } else {
        $modo_codigo = 'interno';
        $codigo_barra = '';
        $stock_especial = 0;
        $fijado_venta = 0;
        $tipo_venta = 'unidad';
        $unidad_medida = 'pz';
        $decimales_cantidad = 0;
        $cantidad = floatval($_POST['cantidad_insumo'] ?? 0);
        $precio_compra = round((float)($_POST['precio_compra_insumo'] ?? 0), 2);
        $precio_venta_base = 0.00;
        $precio_venta = 0.00;
        $tipo_codigo = 'multiple';

        $tipo_unidad_insumo = $_POST['tipo_unidad_insumo'] ?? 'unidad';
        $ancho_insumo = isset($_POST['ancho_insumo']) ? floatval($_POST['ancho_insumo']) : null;
        $atributos = ['tipo_unidad' => $tipo_unidad_insumo];
        if ($ancho_insumo && $ancho_insumo > 0) {
            $atributos['ancho'] = $ancho_insumo;
        }
        $atributos_json = json_encode($atributos, JSON_UNESCAPED_UNICODE);
    }

    if ($nombre === '') {
        $errors[] = 'El nombre del producto es obligatorio.';
    } elseif ((function_exists('mb_strlen') ? mb_strlen($nombre, 'UTF-8') : strlen($nombre)) > 100) {
        $errors[] = 'El nombre del producto no puede superar 100 caracteres.';
    }

    if ((function_exists('mb_strlen') ? mb_strlen($categoria, 'UTF-8') : strlen($categoria)) > 50) {
        $errors[] = 'La categoría no puede superar 50 caracteres.';
    }

    if ($tipo_inventario === 'producto' && $fijado_venta === 1 && !$productosSoportaFijadoVenta) {
        $errors[] = 'Antes de fijar productos ejecuta sql/agregar_fijado_venta.sql en la base de datos.';
    }

    if ($tipo_inventario === 'producto') {
        if ($stock_especial !== 1) {
            if (!isset($_POST['cantidad']) || $_POST['cantidad'] === '') {
                $errors[] = 'La cantidad del producto es obligatoria.';
            } elseif ($cantidad <= 0) {
                $errors[] = 'La cantidad debe ser mayor a 0.';
            }
        }

        if (!isset($_POST['precio_compra']) || $_POST['precio_compra'] === '') {
            $errors[] = 'El precio de compra es obligatorio.';
        } elseif ($precio_compra <= 0) {
            $errors[] = 'El precio de compra debe ser mayor a 0.';
        }

        if (!isset($_POST['precio_venta']) || $_POST['precio_venta'] === '') {
            $errors[] = 'El precio de venta base es obligatorio.';
        } elseif ($precio_venta_base <= 0) {
            $errors[] = 'El precio de venta base debe ser mayor a 0.';
        }

        if ($modo_codigo === 'comercial') {
            if ($codigo_barra === '') {
                $errors[] = 'Escanea o escribe el código del producto, o selecciona “Producto sin código”.';
            } elseif (strlen($codigo_barra) > 50) {
                $errors[] = 'El código comercial no puede superar 50 caracteres.';
            } else {
                try {
                    $productoExistenteCodigo = barcode_buscar_producto_local($conn, $codigo_barra);
                    if ($productoExistenteCodigo) {
                        $errors[] = 'El código ' . $codigo_barra . ' ya pertenece a “'
                            . (string)$productoExistenteCodigo['nombre']
                            . '”. No crees el producto nuevamente; agrega existencias al producto existente.';
                    }
                } catch (Throwable $e) {
                    $errors[] = $e->getMessage();
                }
            }
        } else {
            $codigo_barra = '';
        }
    } else {
        if (!isset($_POST['cantidad_insumo']) || $_POST['cantidad_insumo'] === '') {
            $errors[] = 'La cantidad del insumo es obligatoria.';
        } elseif ($cantidad <= 0) {
            $errors[] = 'La cantidad debe ser mayor a 0.';
        }

        if (!isset($_POST['precio_compra_insumo']) || $_POST['precio_compra_insumo'] === '') {
            $errors[] = 'El precio de compra es obligatorio.';
        } elseif ($precio_compra <= 0) {
            $errors[] = 'El precio de compra debe ser mayor a 0.';
        }
    }

    if (empty($errors)) {
        $imagen_path = '';
        if (!empty($_FILES['imagen']['name'])) {
            $upload_dir = __DIR__ . '/uploads/productos/';
            if (!is_dir($upload_dir) && !mkdir($upload_dir, 0777, true) && !is_dir($upload_dir)) {
                $errors[] = 'No fue posible crear la carpeta de imágenes.';
            }

            if (empty($errors)) {
                $extension = strtolower(pathinfo($_FILES['imagen']['name'], PATHINFO_EXTENSION));
                $extensionesPermitidas = ['jpg', 'jpeg', 'png', 'webp', 'gif'];
                if (!in_array($extension, $extensionesPermitidas, true)) {
                    $errors[] = 'La imagen debe ser JPG, JPEG, PNG, WEBP o GIF.';
                } elseif ((int)($_FILES['imagen']['size'] ?? 0) > 8 * 1024 * 1024) {
                    $errors[] = 'La imagen no puede superar 8 MB.';
                } else {
                    $nombre_limpio = preg_replace('/[^a-zA-Z0-9áéíóúüñÁÉÍÓÚÜÑ\s-]/u', '', $nombre);
                    $nombre_limpio = preg_replace('/[\s]+/', '_', (string)$nombre_limpio);
                    $nombre_limpio = trim((string)$nombre_limpio, '_') ?: 'producto';
                    $imagen_name = $nombre_limpio . '.' . $extension;
                    $contador = 1;
                    while (file_exists($upload_dir . $imagen_name)) {
                        $imagen_name = $nombre_limpio . '_' . $contador . '.' . $extension;
                        $contador++;
                    }
                    $imagen_path = 'uploads/productos/' . $imagen_name;
                    if (!move_uploaded_file($_FILES['imagen']['tmp_name'], $upload_dir . $imagen_name)) {
                        $errors[] = 'Error al subir la imagen.';
                        $imagen_path = '';
                    }
                }
            }
        }

        if (empty($errors)) {
            $conn->begin_transaction();

            try {
                $stmt = $conn->prepare("INSERT INTO productos (
                    nombre, categoria, atributos, proveedor, imagen, cantidad,
                    precio_compra, precio_venta, tipo_codigo, tipo_inventario,
                    tipo_adquisicion, stock_especial, fijado_venta,
                    tipo_venta, unidad_medida, decimales_cantidad
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                if (!$stmt) {
                    throw new Exception('Ejecuta primero sql/integracion_bascula.sql. Detalle: ' . $conn->error);
                }

                $stmt->bind_param(
                    'sssssdddsssiissi',
                    $nombre,
                    $categoria,
                    $atributos_json,
                    $proveedor_nombre,
                    $imagen_path,
                    $cantidad,
                    $precio_compra,
                    $precio_venta,
                    $tipo_codigo,
                    $tipo_inventario,
                    $tipo_adquisicion,
                    $stock_especial,
                    $fijado_venta,
                    $tipo_venta,
                    $unidad_medida,
                    $decimales_cantidad
                );

                if (!$stmt->execute()) {
                    throw new Exception('Error al insertar producto: ' . $stmt->error);
                }

                $producto_id = (int)$stmt->insert_id;
                $stmt->close();

                $cero = 0;
                $nota_historial = $stock_especial === 1
                    ? 'Registro inicial de artículo especial sin límite de stock'
                    : 'Registro inicial de producto';

                $stmt_historial = $conn->prepare("INSERT INTO historial_stock (
                    producto_id, cantidad_anterior, cantidad_nueva, cantidad_agregada,
                    tipo_movimiento, nota, usuario_id
                ) VALUES (?, ?, ?, ?, 'entrada', ?, ?)");
                if (!$stmt_historial) {
                    throw new Exception('No se pudo preparar el historial de stock: ' . $conn->error);
                }
                $usuarioId = (int)$_SESSION['usuario_id'];
                $stmt_historial->bind_param('idddsi', $producto_id, $cero, $cantidad, $cantidad, $nota_historial, $usuarioId);
                if (!$stmt_historial->execute()) {
                    throw new Exception('Error al registrar historial: ' . $stmt_historial->error);
                }
                $stmt_historial->close();

                $codigos_dir = __DIR__ . '/uploads/codigos/';
                if (!is_dir($codigos_dir)) {
                    @mkdir($codigos_dir, 0777, true);
                }

                if ($tipo_inventario === 'producto') {
                    if ($modo_codigo === 'comercial') {
                        insertarCodigoBarraSeguro($conn, $producto_id, $codigo_barra, 1, 'fabricante', 1);
                    } else {
                        generarCodigosBarras(
                            $conn,
                            $nombre,
                            $producto_id,
                            $stock_especial === 1 ? 1 : $cantidad,
                            $stock_especial === 1 ? 'unico' : $tipo_codigo,
                            $tipo_inventario
                        );
                    }
                }

                $conn->commit();

                $detalleCodigo = $modo_codigo === 'comercial'
                    ? '<br><small><b>Código del producto:</b> ' . htmlspecialchars($codigo_barra, ENT_QUOTES, 'UTF-8') . '. No se generó otro código.</small>'
                    : '<br><small><b>Código interno:</b> generado automáticamente por el sistema.</small>';

                echo "<script>
                Swal.fire({
                    icon: 'success',
                    title: 'Producto agregado',
                    html: 'El producto se agregó correctamente.<br><small>Precio final guardado: <b>$" . number_format($precio_venta, 0) . "</b> (incluye 3% y está redondeado).</small>" .
                          "'" . ($stock_especial === 1 ? "<br><small><b>Artículo especial:</b> sin límite de stock y con código único.</small>" : "") . "' +" .
                          "'" . ($fijado_venta === 1 ? "<br><small><b>Fijado en ventas:</b> aparecerá primero en Seleccionar producto.</small>" : "") . "' +" .
                          "'" . $detalleCodigo . "',
                    confirmButtonText: 'Aceptar',
                    confirmButtonColor: '#f97316'
                }).then(() => {
                    window.location = 'dashboard_productos.php';
                });
                </script>";
                exit;
            } catch (Throwable $e) {
                $conn->rollback();
                $errors[] = $e->getMessage();
            }
        }
    }
}

// ========================= GENERAR CÓDIGOS DE BARRAS =========================
function generarCodigosBarras($conn, $nombre, $producto_id, $cantidad, $tipo_codigo, $tipo_inventario = 'producto') {
    if ($tipo_inventario !== 'producto') {
        return;
    }

    $tipo_codigo = normalizarTipoCodigoProducto($tipo_inventario, $tipo_codigo);
    $producto_id = (int)$producto_id;
    $cantidad = max(0, (int)floor((float)$cantidad));

    if ($tipo_codigo === 'unico') {
        insertarCodigoBarraSeguro($conn, $producto_id, codigoUnicoProducto($producto_id), 1, 'interno', 1);
    } else {
        for ($i = 1; $i <= $cantidad; $i++) {
            insertarCodigoBarraSeguro($conn, $producto_id, codigoMultipleProducto($producto_id, $i), 1, 'interno', $i === 1 ? 1 : 0);
        }
    }

    generarPDFCodigos($conn, $nombre, $producto_id, $cantidad, $tipo_codigo);
    generarPNGCodigosProducto($conn, $producto_id);
}

function generarPDFCodigos($conn, $nombre, $producto_id, $cantidad, $tipo_codigo = 'multiple') {
    $generator = new BarcodeGeneratorPNG();
    
    $codigos_dir = __DIR__ . '/uploads/codigos/';
    if (!is_dir($codigos_dir)) mkdir($codigos_dir, 0777, true);
    
    $file = $codigos_dir . 'producto_' . $producto_id . '.pdf';
    if (file_exists($file)) @unlink($file);

    $pdf = new FPDF();
    $pdf->SetAutoPageBreak(false);
    $pdf->SetFont('Arial', '', 10);

    if ($tipo_codigo === 'unico') {
        $stmt = $conn->prepare("SELECT codigo FROM codigos_barras WHERE producto_id = ? LIMIT 1");
        $stmt->bind_param("i", $producto_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result->fetch_assoc();
        $codigo = $row['codigo'];

        $pdf->AddPage();
        $pngData = $generator->getBarcode($codigo, $generator::TYPE_CODE_128);
        $tmp = tempnam(sys_get_temp_dir(), 'bc_') . '.png';
        file_put_contents($tmp, $pngData);

        $pdf->Cell(0, 10, utf8_decode("Producto: $nombre"), 0, 1, 'C');
        $pdf->Image($tmp, 55, 40, 100, 30, 'PNG');
        $pdf->SetXY(55, 75);
        $pdf->Cell(100, 10, $codigo, 0, 0, 'C');

        @unlink($tmp);
    } else {
        $codigos_por_fila = 4;
        $filas_por_pagina = 5;
        $codigos_por_pagina = $codigos_por_fila * $filas_por_pagina;

        $ancho_codigo = 40;
        $alto_codigo = 20;
        $margen_x = 20;
        $margen_y = 15;
        $espaciado_x = 45;
        $espaciado_y = 45;

        $stmt = $conn->prepare("SELECT codigo FROM codigos_barras WHERE producto_id = ? ORDER BY id ASC");
        $stmt->bind_param("i", $producto_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $codigos = [];
        while ($row = $result->fetch_assoc()) {
            $codigos[] = $row['codigo'];
        }

        foreach ($codigos as $index => $codigo) {
            if ($index % $codigos_por_pagina == 0) $pdf->AddPage();

            $pos = $index % $codigos_por_pagina;
            $columna = $pos % $codigos_por_fila;
            $fila = intdiv($pos, $codigos_por_fila);
            $x = $margen_x + ($columna * $espaciado_x);
            $y = $margen_y + ($fila * $espaciado_y);

            $pngData = $generator->getBarcode($codigo, $generator::TYPE_CODE_128);
            $tmp = tempnam(sys_get_temp_dir(), 'bc_') . '.png';
            file_put_contents($tmp, $pngData);

            $pdf->SetXY($x, $y);
            $pdf->Cell($ancho_codigo, 5, utf8_decode(substr($nombre, 0, 20)), 0, 2, 'C');
            $pdf->Image($tmp, $x + 2, $y + 6, $ancho_codigo - 4, $alto_codigo, 'PNG');
            $pdf->SetXY($x, $y + $alto_codigo + 10);
            $pdf->Cell($ancho_codigo, 5, $codigo, 0, 0, 'C');

            @unlink($tmp);
        }
    }

    $pdf->Output('F', $file);
}

$categorias = obtenerCategorias($conn);
$proveedores = obtenerProveedores($conn);

if (!empty($errors)) {
    echo "<script>
    Swal.fire({
        icon: 'error',
        title: 'Error',
        html: '" . implode('<br>', $errors) . "',
        confirmButtonText: 'Aceptar',
        confirmButtonColor: '#f97316'
    });
    </script>";
}
?>
<link rel="stylesheet" href="css/productos.css?v=<?= time() ?>">
<link rel="stylesheet" href="css/productos-fijados.css?v=<?= time() ?>">



<div class="content-wrapper nuevo-producto-page">
    <div class="container-fluid">
        
        <!-- BREADCRUMB -->
        <div class="custom-breadcrumb">
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb">
                    <li class="breadcrumb-item">
                        <a href="<?= in_array($rol_actual, $roles_administrativos, true) ? 'dashboard_admin.php' : 'dashboard_vendedor.php' ?>">
                            <i class="fas fa-home"></i> Inicio
                        </a>
                    </li>
                    <li class="breadcrumb-item">
                        <a href="dashboard_productos.php">
                            <i class="fas fa-boxes"></i> Gestión de Productos
                        </a>
                    </li>
                    <li class="breadcrumb-item active">
                        <i class="fas fa-plus-circle"></i> Nuevo <?= $tipo_seleccionado == 'producto' ? 'Producto' : 'Insumo' ?>
                    </li>
                </ol>
            </nav>
        </div>
        
        <div class="section-header">
            <div class="section-title">
                <i class="fas fa-<?= $tipo_seleccionado == 'producto' ? 'box' : 'cubes' ?>"></i>
                <span>Nuevo <?= $tipo_seleccionado == 'producto' ? 'Producto' : 'Insumo' ?></span>
            </div>
            <div class="section-divider"></div>
            <p class="text-muted mt-2 mb-0">Completa los datos para agregar un nuevo <?= strtolower($tipo_seleccionado == 'producto' ? 'producto' : 'insumo') ?> al inventario</p>
        </div>
        
        <div class="row">
            <div class="col-12">
                <div class="card card-outline shadow-sm">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <h3 class="card-title font-weight-bold mb-0">
                            <i class="fas fa-<?= $tipo_seleccionado == 'producto' ? 'box-open' : 'cubes' ?> mr-2" style="color: #f97316;"></i> 
                            Datos del <?= $tipo_seleccionado == 'producto' ? 'Producto' : 'Insumo' ?>
                        </h3>
                        <button type="button" class="btn btn-light btn-sm ml-auto" title="Limpiar formulario" onclick="limpiarFormulario()">
                            <i class="fas fa-undo-alt"></i>
                        </button>
                    </div>

                    <div class="card-body">
                        <form method="POST" enctype="multipart/form-data" id="formProducto">
                            <input type="hidden" name="action" value="create">
                            <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>">
                            <input type="hidden" name="tipo_inventario" id="tipo_inventario" value="<?= $tipo_seleccionado ?>">

                            <div class="row">
                                <div class="col-md-6">
                                    <?php if ($tipo_seleccionado == 'producto'): ?>
                                    <section class="barcode-register-card" id="barcodeRegisterCard">
                                        <div class="barcode-register-heading">
                                            <span class="barcode-register-icon"><i class="fas fa-barcode"></i></span>
                                            <div>
                                                <strong>Identificación del producto</strong>
                                                <small>Usa el código original cuando exista o genera uno interno para artículos sin etiqueta.</small>
                                            </div>
                                        </div>

                                        <div class="codigo-mode-selector" role="radiogroup" aria-label="Origen del código">
                                            <label class="codigo-mode-option active" id="codigoModeComercialLabel">
                                                <input type="radio" name="modo_codigo" value="comercial" checked>
                                                <span><i class="fas fa-barcode"></i> Producto con código</span>
                                            </label>
                                            <label class="codigo-mode-option" id="codigoModeInternoLabel">
                                                <input type="radio" name="modo_codigo" value="interno">
                                                <span><i class="fas fa-tag"></i> Producto sin código</span>
                                            </label>
                                        </div>

                                        <div id="codigoComercialFields">
                                            <label for="codigo_barra_input">Código de barras del producto <span class="text-danger">*</span></label>
                                            <div class="barcode-search-row">
                                                <div class="barcode-input-wrap">
                                                    <i class="fas fa-barcode"></i>
                                                    <input
                                                        type="text"
                                                        name="codigo_barra"
                                                        id="codigo_barra_input"
                                                        class="form-control"
                                                        maxlength="50"
                                                        autocomplete="off"
                                                        inputmode="numeric"
                                                        placeholder="Escanea o escribe el código"
                                                    >
                                                </div>
                                                <button type="button" class="btn btn-primary" id="buscarCodigoBtn">
                                                    <i class="fas fa-search"></i>
                                                    <span>Buscar</span>
                                                </button>
                                            </div>
                                            <small class="barcode-key-hint">
                                                <i class="fas fa-keyboard"></i>
                                                El lector funciona como teclado y normalmente envía Enter. También puedes escribir el código y pulsar Buscar.
                                            </small>
                                        </div>

                                        <input type="hidden" name="barcode_fuente" id="barcode_fuente" value="manual">
                                        <input type="hidden" name="barcode_descripcion" id="barcode_descripcion" value="">
                                        <input type="hidden" name="barcode_imagen_url" id="barcode_imagen_url" value="">

                                        <div class="upc-database-status is-idle" id="upcDatabaseStatus" aria-live="polite">
                                            <i class="fas fa-info-circle"></i>
                                            <div>
                                                <strong>Listo para escanear</strong>
                                                <span>Primero se revisará tu inventario y después UPC Database.</span>
                                            </div>
                                        </div>
                                        <p class="barcode-source-note">
                                            El token se mantiene en el servidor. Si UPC Database no encuentra el artículo, no responde o alcanza su límite, podrás completar los datos manualmente y guardar exactamente el código leído.
                                        </p>
                                    </section>
                                    <?php endif; ?>

                                    <div class="form-group">
                                        <label>Nombre del producto <span class="text-danger">*</span></label>
                                        <input type="text" name="nombre" id="nombre_input" class="form-control" maxlength="100" placeholder="Ej. Llaveros, playeras, tazas, etc." required>
                                    </div>

                                    <div class="form-group">
                                        <label>Categoría</label>
                                        <div class="input-group">
                                            <select name="categoria" class="form-control" id="categoriaSelect">
                                                <option value="General">Seleccionar categoría</option>
                                                <?php foreach ($categorias as $cat): ?>
                                                    <option value="<?= htmlspecialchars($cat) ?>"><?= htmlspecialchars($cat) ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                            <div class="input-group-append">
                                                <button class="btn btn-outline-primary" type="button" onclick="agregarNuevaCategoria()">
                                                    <i class="fas fa-plus"></i>
                                                </button>
                                            </div>
                                        </div>
                                    </div>

                                    <div class="form-group">
                                        <label>Proveedor</label>
                                        <div class="input-group-btn">
                                            <select name="proveedor_id" id="proveedorSelect" class="form-control">
                                                <option value="">Seleccionar proveedor</option>
                                                <?php foreach ($proveedores as $prov): ?>
                                                    <option value="<?= $prov['id'] ?>"><?= htmlspecialchars($prov['nombre']) ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                            <button type="button" class="btn btn-outline-primary" onclick="abrirModalProveedor()">
                                                <i class="fas fa-plus"></i>
                                            </button>
                                        </div>
                                    </div>

                                    <!-- NUEVA SECCIÓN: TIPO DE ADQUISICIÓN -->
                                    <div class="form-group">
                                        <label><i class="fas fa-tag mr-1"></i> Tipo de adquisición <span class="text-danger">*</span></label>
                                        <div class="adquisicion-toggle">
                                            <div class="btn-adquisicion btn-adquisicion-pagado active" onclick="seleccionarAdquisicion('pagado')">
                                                <i class="fas fa-check-circle"></i> Pagado
                                            </div>
                                            <div class="btn-adquisicion btn-adquisicion-concesion" onclick="seleccionarAdquisicion('concesion')">
                                                <i class="fas fa-handshake"></i> Por concesión
                                            </div>
                                        </div>
                                        <input type="hidden" name="tipo_adquisicion" id="tipo_adquisicion" value="pagado">
                                        <div class="info-adquisicion" id="infoAdquisicion">
                                            <i class="fas fa-info-circle text-primary mr-1"></i>
                                            <strong>Pagado:</strong> Producto ya liquidado al proveedor. Aparece como disponible inmediatamente.
                                        </div>
                                    </div>

                                    <div class="form-group">
                                        <label>Imagen</label>
                                        <input type="file" name="imagen" class="form-control" accept="image/*" onchange="previewImagen(event)">
                                        <img id="previewImg" class="img-thumbnail mt-2 d-none" style="max-height:100px;">
                                    </div>
                                </div>

                                <div class="col-md-6">
                                    <?php if ($tipo_seleccionado == 'producto'): ?>
                                    <!-- SECCIÓN PRODUCTO -->
                                    <div id="producto-section" class="form-section producto-section">
                                        <h5 class="text-success"><i class="fas fa-box mr-2"></i> Datos del Producto</h5>

                                        <div class="form-group tipo-venta-group">
                                            <label class="tipo-venta-label">
                                                <i class="fas fa-scale-balanced"></i>
                                                Forma de venta <span class="text-danger">*</span>
                                            </label>

                                            <div
                                                class="tipo-venta-selector"
                                                id="tipoVentaSelector"
                                                role="radiogroup"
                                                aria-label="Forma de venta"
                                            >
                                                <label class="tipo-venta-option active" id="tipoVentaUnidadCard">
                                                    <input type="radio" name="tipo_venta" value="unidad" checked>
                                                    <span class="tipo-venta-icon" aria-hidden="true">
                                                        <i class="fas fa-cubes"></i>
                                                    </span>
                                                    <span class="tipo-venta-copy">
                                                        <strong>Por pieza</strong>
                                                        <small>Unidades enteras</small>
                                                    </span>
                                                </label>

                                                <label class="tipo-venta-option" id="tipoVentaPesoCard">
                                                    <input type="radio" name="tipo_venta" value="peso">
                                                    <span class="tipo-venta-icon" aria-hidden="true">
                                                        <i class="fas fa-weight-scale"></i>
                                                    </span>
                                                    <span class="tipo-venta-copy">
                                                        <strong>Por peso</strong>
                                                        <small>Precio por kilogramo</small>
                                                    </span>
                                                </label>
                                            </div>

                                            <div class="tipo-venta-help" id="tipoVentaHelp" aria-live="polite">
                                                <i class="fas fa-circle-info"></i>
                                                <span>Venta por unidades con código único o múltiple.</span>
                                            </div>
                                        </div>

                                        <div class="row product-stock-row">
                                            <div class="col-md-6">
                                                <div class="form-group" id="cantidad_producto_group">
                                                    <label id="cantidad_producto_label">Cantidad inicial (piezas) <span class="text-danger">*</span></label>
                                                    <div class="field-with-icon">
                                                        <i class="fas fa-cubes"></i>
                                                        <input
                                                            type="number"
                                                            name="cantidad"
                                                            id="cantidad_producto"
                                                            class="form-control"
                                                            min="1"
                                                            step="1"
                                                            placeholder="Ej. 10"
                                                            required
                                                        >
                                                    </div>
                                                </div>
                                            </div>

                                            <div class="col-md-6">
                                                <div class="form-group" id="tipo_codigo_group">
                                                    <label>Tipo de código</label>
                                                    <select name="tipo_codigo" id="tipo_codigo_select" class="form-control">
                                                        <option value="unico" selected>Código único</option>
                                                        <option value="multiple">Código múltiple</option>
                                                    </select>
                                                    <small class="tipo-codigo-help" id="tipo_codigo_help">
                                                        Usa un código para todo el producto o uno por cada pieza.
                                                    </small>
                                                </div>
                                            </div>
                                        </div>

                                        <div id="special_stock_wrapper" class="special-stock-wrapper">
                                            <label class="special-stock-toggle" id="special_stock_toggle" for="stock_especial">
                                                <input type="checkbox" name="stock_especial" id="stock_especial" value="1">
                                                <span class="special-stock-switch" aria-hidden="true"></span>
                                                <span class="special-stock-text">
                                                    <strong>Producto especial</strong>
                                                    <small>Sin cantidad fija ni descuento automático de existencias.</small>
                                                </span>
                                                <i class="fas fa-infinity special-stock-icon" aria-hidden="true"></i>
                                            </label>
                                        </div>

                                        <label class="pin-sale-toggle" id="pin_sale_toggle" for="fijado_venta">
                                            <input
                                                type="checkbox"
                                                name="fijado_venta"
                                                id="fijado_venta"
                                                value="1"
                                                <?= !$productosSoportaFijadoVenta ? 'disabled' : '' ?>
                                            >
                                            <span class="pin-sale-switch" aria-hidden="true"></span>
                                            <span class="pin-sale-copy">
                                                <strong><i class="fas fa-thumbtack"></i> Fijar en venta de productos</strong>
                                                <small>Aparecerá al inicio de “Seleccionar producto” en el punto de venta.</small>
                                                <?php if (!$productosSoportaFijadoVenta): ?>
                                                    <em>Ejecuta primero el SQL incluido para habilitar esta opción.</em>
                                                <?php endif; ?>
                                            </span>
                                        </label>

                                        <div class="row">
                                            <div class="col-md-6">
                                                <div class="form-group">
                                                    <label id="precio_compra_label">Precio compra por pieza <span class="text-danger">*</span></label>
                                                    <input type="number" step="0.01" name="precio_compra" id="precio_compra" class="form-control" placeholder="0.00" required>
                                                </div>
                                            </div>
                                            <div class="col-md-6">
                                                <div class="form-group">
                                                    <label id="precio_venta_label">Precio venta base por pieza <span class="text-danger">*</span></label>
                                                    <input
                                                        type="number"
                                                        step="0.01"
                                                        min="0.01"
                                                        name="precio_venta"
                                                        id="precio_venta_base"
                                                        class="form-control"
                                                        placeholder="0.00"
                                                        required
                                                    >
                                                    <small class="text-muted">Se agrega el 3% y se redondea.</small>
                                                    <div class="price-result-card">
                                                        <span><i class="fas fa-calculator"></i> Precio final</span>
                                                        <strong id="precio_venta_final">$0</strong>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>


                                        <div class="card card-secondary mt-3">
                                            <div class="card-header">
                                                <h6 class="mb-0">Atributos adicionales <small class="text-muted">(la API completa los disponibles)</small></h6>
                                            </div>
                                            <div class="card-body">
                                                <div class="row">
                                                    <div class="col-md-6 mb-2">
                                                        <label>Marca</label>
                                                        <input type="text" name="marca" id="marca_input" class="form-control form-control-sm" maxlength="120" placeholder="Ej. Marinela">
                                                    </div>
                                                    <div class="col-md-6 mb-2">
                                                        <label>Modelo</label>
                                                        <input type="text" name="modelo" id="modelo_input" class="form-control form-control-sm" maxlength="120" placeholder="Ej. ABC-123">
                                                    </div>
                                                    <div class="col-md-6 mb-2">
                                                        <label>Presentación</label>
                                                        <input type="text" name="presentacion" id="presentacion_input" class="form-control form-control-sm" maxlength="120" placeholder="Ej. 50 g, 600 ml, paquete con 6">
                                                    </div>
                                                    <div class="col-md-6 mb-2">
                                                        <label>Peso o contenido</label>
                                                        <input type="text" name="peso" id="peso_input" class="form-control form-control-sm" maxlength="80" placeholder="Ej. 50 g">
                                                    </div>
                                                    <div class="col-md-6 mb-2">
                                                        <label>Color</label>
                                                        <input type="text" name="color" id="color_input" class="form-control form-control-sm" maxlength="80" placeholder="Ej. Negro">
                                                    </div>
                                                    <div class="col-md-6 mb-2">
                                                        <label>Talla</label>
                                                        <input type="text" name="talla" id="talla_input" class="form-control form-control-sm" maxlength="80" placeholder="Ej. M, L, XL">
                                                    </div>
                                                    <div class="col-md-6 mb-2">
                                                        <label>Material</label>
                                                        <input type="text" name="material" id="material_input" class="form-control form-control-sm" maxlength="100" placeholder="Ej. Algodón">
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                    <?php else: ?>
                                    <!-- SECCIÓN INSUMO -->
                                    <div id="insumo-section" class="form-section insumo-section">
                                        <h5 class="text-warning"><i class="fas fa-cubes mr-2"></i> Datos del Insumo</h5>
                                        
                                        <div class="alert alert-warning">
                                            <i class="fas fa-ruler-combined mr-2"></i>
                                            <strong>Importante:</strong> Selecciona el tipo de unidad para el insumo.
                                        </div>

                                        <div class="form-group">
                                            <label>Tipo de unidad <span class="text-danger">*</span></label>
                                            <div class="btn-group btn-group-toggle w-100" data-toggle="buttons">
                                                <label class="btn btn-outline-primary active" onclick="cambiarUnidadInsumo('unidad')">
                                                    <input type="radio" name="tipo_unidad_insumo" value="unidad" checked> 
                                                    <i class="fas fa-cubes mr-1"></i> Por unidad (piezas)
                                                </label>
                                                <label class="btn btn-outline-warning" onclick="cambiarUnidadInsumo('metros')">
                                                    <input type="radio" name="tipo_unidad_insumo" value="metros"> 
                                                    <i class="fas fa-ruler mr-1"></i> Por metros (DTF, telas, etc.)
                                                </label>
                                            </div>
                                        </div>

                                        <div class="form-group">
                                            <label>Cantidad <span class="text-danger">*</span></label>
                                            <div class="input-group">
                                                <input type="number" name="cantidad_insumo" id="cantidad_insumo" class="form-control" min="0.1" step="1" placeholder="Ej. 100" required>
                                                <div class="input-group-append">
                                                    <span class="input-group-text unidad-label" id="unidad_insumo_label">pz</span>
                                                </div>
                                            </div>
                                            <small class="text-muted" id="unidad_insumo_help">Cantidad en piezas</small>
                                        </div>

                                        <div class="form-group">
                                            <label>Precio de compra <span class="text-danger">*</span></label>
                                            <div class="input-group">
                                                <div class="input-group-prepend">
                                                    <span class="input-group-text">$</span>
                                                </div>
                                                <input type="number" step="0.01" name="precio_compra_insumo" class="form-control" placeholder="0.00" required>
                                                <div class="input-group-append">
                                                    <span class="input-group-text" id="precio_unidad_label">/pz</span>
                                                </div>
                                            </div>
                                        </div>

                                        <div class="form-group" id="ancho_insumo_group" style="display: none;">
                                            <label>Ancho del material (opcional)</label>
                                            <div class="input-group">
                                                <input type="number" step="0.01" name="ancho_insumo" class="form-control" placeholder="Ej. 1.50" min="0">
                                                <div class="input-group-append">
                                                    <span class="input-group-text">m</span>
                                                </div>
                                            </div>
                                            <small class="text-muted">Útil para DTF, telas, vinilos, etc.</small>
                                        </div>

                                        <div class="alert alert-info mt-3">
                                            <i class="fas fa-info-circle mr-2"></i>
                                            <strong>Nota:</strong> Los insumos son para control interno. 
                                            <span id="insumo_info_adicional">Se cuentan por piezas y no generan códigos múltiples.</span>
                                        </div>
                                    </div>
                                    <?php endif; ?>
                                </div>
                            </div>

                            <div class="mt-4">
                                <button type="submit" class="btn btn-primary btn-block btn-lg">
                                    <i class="fas fa-save mr-1"></i> Guardar <?= $tipo_seleccionado == 'producto' ? 'Producto' : 'Insumo' ?>
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- MODAL AGREGAR PROVEEDOR -->
<div class="modal fade modal-proveedor" id="modalProveedor" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <form method="POST" enctype="multipart/form-data" class="modal-content" id="formProveedor">
            <div class="modal-header" style="background: linear-gradient(135deg, #f97316, #ea580c); color: white;">
                <h5 class="modal-title">
                    <i class="fas fa-truck mr-2"></i> Nuevo Proveedor
                </h5>
                <button type="button" class="close text-white" data-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
            <div class="modal-body">
                <input type="hidden" name="action" value="add_proveedor">
                <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>">
                
                <div class="row">
                    <div class="col-md-12">
                        <div class="form-group">
                            <label>Nombre <span class="text-danger">*</span></label>
                            <input type="text" name="nombre" id="prov_nombre" class="form-control" required>
                        </div>
                    </div>
                </div>
                
                <div class="row">
                    <div class="col-md-6">
                        <div class="form-group">
                            <label>Correo electrónico</label>
                            <input type="email" name="correo" id="prov_correo" class="form-control">
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="form-group">
                            <label>Teléfono</label>
                            <input type="text" name="telefono" id="prov_telefono" class="form-control">
                        </div>
                    </div>
                </div>
                
                <div class="row">
                    <div class="col-md-8">
                        <div class="form-group">
                            <label>Calle</label>
                            <input type="text" name="calle" id="prov_calle" class="form-control">
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="form-group">
                            <label>Número</label>
                            <input type="text" name="numero" id="prov_numero" class="form-control">
                        </div>
                    </div>
                </div>
                
                <div class="form-group">
                    <label>Colonia</label>
                    <input type="text" name="colonia" id="prov_colonia" class="form-control">
                </div>
                
                <div class="row">
                    <div class="col-md-6">
                        <div class="form-group">
                            <label>Ciudad</label>
                            <input type="text" name="ciudad" id="prov_ciudad" class="form-control">
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="form-group">
                            <label>Estado</label>
                            <input type="text" name="estado" id="prov_estado" class="form-control">
                        </div>
                    </div>
                </div>
                
                <div class="row">
                    <div class="col-md-6">
                        <div class="form-group">
                            <label>Código Postal</label>
                            <input type="text" name="codigo_postal" id="prov_codigo_postal" class="form-control">
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="form-group">
                            <label>País</label>
                            <select name="pais" id="prov_pais" class="form-control">
                                <option value="México">México</option>
                                <option value="Estados Unidos">Estados Unidos</option>
                                <option value="Canadá">Canadá</option>
                                <option value="España">España</option>
                                <option value="Colombia">Colombia</option>
                                <option value="Argentina">Argentina</option>
                                <option value="Chile">Chile</option>
                                <option value="Perú">Perú</option>
                                <option value="Otro">Otro</option>
                            </select>
                        </div>
                    </div>
                </div>
                
                <div class="form-group">
                    <label>Logo</label>
                    <input type="file" name="logo" id="prov_logo" class="form-control" accept="image/*" onchange="previewLogo(event)">
                    <img id="previewLogo" class="logo-preview d-none">
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancelar</button>
                <button type="submit" class="btn btn-primary" style="background: #f97316; border-color: #f97316;">Guardar Proveedor</button>
            </div>
        </form>
    </div>
</div>

<script src="https://code.jquery.com/jquery-3.6.4.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@4.6.2/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

<script>
// ===== TIPO DE ADQUISICIÓN =====
function seleccionarAdquisicion(tipo) {
    const pagadoBtn = document.querySelector('.btn-adquisicion-pagado');
    const concesionBtn = document.querySelector('.btn-adquisicion-concesion');
    const inputAdquisicion = document.getElementById('tipo_adquisicion');
    const infoDiv = document.getElementById('infoAdquisicion');
    
    if (tipo === 'pagado') {
        pagadoBtn.classList.add('active');
        concesionBtn.classList.remove('active');
        inputAdquisicion.value = 'pagado';
        infoDiv.innerHTML = `
            <i class="fas fa-check-circle text-success mr-1"></i>
            <strong>Pagado:</strong> Producto ya liquidado al proveedor.
            <span class="badge badge-success ml-2">✓ Stock disponible</span>
            <div class="mt-2 small text-muted">
                <i class="fas fa-info-circle"></i> Aparecerá en reporte de deuda con proveedores como pagado.
            </div>
        `;
    } else {
        concesionBtn.classList.add('active');
        pagadoBtn.classList.remove('active');
        inputAdquisicion.value = 'concesion';
        infoDiv.innerHTML = `
            <i class="fas fa-handshake text-warning mr-1"></i>
            <strong>Por concesión:</strong> Producto entregado por proveedor a cuenta. Se pagará al vender.
            <span class="badge badge-warning ml-2">Pendiente de pago</span>
            <div class="mt-2 small text-muted">
                <i class="fas fa-info-circle"></i> Este producto aparecerá en reportes de deuda con proveedores.
            </div>
        `;
    }
}

// ===== PROVEEDORES =====
function abrirModalProveedor() {
    document.getElementById('formProveedor').reset();
    document.getElementById('previewLogo').classList.add('d-none');
    $('#modalProveedor').modal('show');
}

function previewLogo(event) {
    const img = document.getElementById('previewLogo');
    img.src = URL.createObjectURL(event.target.files[0]);
    img.classList.remove('d-none');
}

function agregarProveedorAlSelect(id, nombre) {
    const select = document.getElementById('proveedorSelect');
    const option = document.createElement('option');
    option.value = id;
    option.text = nombre;
    option.selected = true;
    select.appendChild(option);
}

// Envío del formulario de proveedor
document.getElementById('formProveedor').addEventListener('submit', function(e) {
    e.preventDefault();
    
    Swal.fire({
        title: 'Guardando...',
        text: 'Por favor espere',
        allowOutsideClick: false,
        didOpen: () => {
            Swal.showLoading();
        }
    });
    
    const formData = new FormData(this);
    
    // IMPORTANTE: Usar la URL completa y asegurar que sea una petición AJAX
    fetch(window.location.href, {  // Cambiar a window.location.href en lugar de 'nuevo_producto.php'
        method: 'POST',
        body: formData,
        headers: {
            'X-Requested-With': 'XMLHttpRequest'
        }
    })
    .then(response => response.text())
    .then(text => {
        console.log('Respuesta del servidor:', text); // Para depuración
        
        // Limpiar cualquier salida HTML antes de parsear
        const jsonMatch = text.match(/\{[\s\S]*\}/);
        if (!jsonMatch) {
            throw new Error('No se recibió una respuesta JSON válida');
        }
        
        try {
            const data = JSON.parse(jsonMatch[0]);
            if (data.success) {
                Swal.fire({
                    icon: 'success',
                    title: 'Éxito',
                    text: 'Proveedor agregado correctamente',
                    confirmButtonColor: '#f97316'
                });
                agregarProveedorAlSelect(data.id, data.nombre);
                $('#modalProveedor').modal('hide');
                // Limpiar el formulario de proveedor
                document.getElementById('formProveedor').reset();
                document.getElementById('previewLogo').classList.add('d-none');
            } else {
                Swal.fire({
                    icon: 'error',
                    title: 'Error',
                    text: data.error || 'No se pudo guardar el proveedor',
                    confirmButtonColor: '#f97316'
                });
            }
        } catch (e) {
            console.error('Error parsing JSON:', e);
            Swal.fire({
                icon: 'error',
                title: 'Error',
                text: 'Error en el servidor: ' + text.substring(0, 200),
                confirmButtonColor: '#f97316'
            });
        }
    })
    .catch(error => {
        console.error('Error:', error);
        Swal.fire({
            icon: 'error',
            title: 'Error',
            text: 'Error al procesar la solicitud: ' + error.message,
            confirmButtonColor: '#f97316'
        });
    });
});

// ===== CÓDIGO COMERCIAL + UPC DATABASE =====
function normalizarCodigoComercial(valor) {
    return String(valor || '')
        .trim()
        .replace(/[\r\n\t\s]+/g, '')
        .toUpperCase();
}

function modoCodigoActual() {
    return document.querySelector('input[name="modo_codigo"]:checked')?.value === 'interno'
        ? 'interno'
        : 'comercial';
}

function escaparHtml(valor) {
    const div = document.createElement('div');
    div.textContent = String(valor ?? '');
    return div.innerHTML;
}

function establecerEstadoCodigo(tipo, titulo, mensaje) {
    const estado = document.getElementById('upcDatabaseStatus');
    if (!estado) return;

    const iconos = {
        idle: 'fa-info-circle',
        loading: 'fa-spinner fa-spin',
        success: 'fa-check-circle',
        warning: 'fa-exclamation-triangle',
        error: 'fa-times-circle'
    };

    estado.className = `upc-database-status is-${tipo}`;
    estado.innerHTML = `
        <i class="fas ${iconos[tipo] || iconos.idle}"></i>
        <div>
            <strong>${escaparHtml(titulo)}</strong>
            <span>${escaparHtml(mensaje)}</span>
        </div>
    `;
}

function limpiarDatosUPCDatabase({ conservarCodigo = true, conservarCamposManuales = true } = {}) {
    const codigo = document.getElementById('codigo_barra_input');
    if (!conservarCodigo && codigo) codigo.value = '';

    const idsCampos = [
        'nombre_input', 'marca_input', 'modelo_input', 'presentacion_input',
        'peso_input', 'color_input', 'talla_input', 'material_input'
    ];

    idsCampos.forEach(id => {
        const campo = document.getElementById(id);
        if (!campo) return;
        if (campo.dataset.barcodeAutofill === '1') {
            campo.value = '';
            delete campo.dataset.barcodeAutofill;
        }
    });

    const categoria = document.getElementById('categoriaSelect');
    if (categoria?.dataset.barcodeAutofill === '1') {
        categoria.value = 'General';
        delete categoria.dataset.barcodeAutofill;
    }

    const preview = document.getElementById('previewImg');
    if (preview?.dataset.externa === '1') {
        preview.removeAttribute('src');
        preview.classList.add('d-none');
        delete preview.dataset.externa;
    }

    const fuente = document.getElementById('barcode_fuente');
    const descripcion = document.getElementById('barcode_descripcion');
    const imagen = document.getElementById('barcode_imagen_url');
    if (fuente) fuente.value = 'manual';
    if (descripcion) descripcion.value = '';
    if (imagen) imagen.value = '';
}

function agregarOSeleccionarCategoria(categoria) {
    const select = document.getElementById('categoriaSelect');
    const valor = String(categoria || '').trim();
    if (!select || !valor) return;

    const opcionExistente = Array.from(select.options).find(
        option => option.value.toLowerCase() === valor.toLowerCase()
    );

    if (opcionExistente) {
        select.value = opcionExistente.value;
        select.dataset.barcodeAutofill = '1';
        return;
    }

    const option = document.createElement('option');
    option.value = valor;
    option.textContent = valor;
    option.selected = true;
    option.dataset.barcode = '1';
    select.appendChild(option);
    select.dataset.barcodeAutofill = '1';
}

function colocarSugerencia(id, valor) {
    const campo = document.getElementById(id);
    const sugerencia = String(valor || '').trim();
    if (!campo || !sugerencia) return;

    const puedeReemplazar = campo.value.trim() === '' || campo.dataset.barcodeAutofill === '1';
    if (puedeReemplazar) {
        campo.value = sugerencia;
        campo.dataset.barcodeAutofill = '1';
    }
}

function completarFormularioDesdeUPCDatabase(producto) {
    colocarSugerencia('nombre_input', producto.nombre);
    colocarSugerencia('marca_input', producto.marca);
    colocarSugerencia('modelo_input', producto.modelo);
    colocarSugerencia('presentacion_input', producto.presentacion);
    colocarSugerencia('peso_input', producto.peso);
    colocarSugerencia('color_input', producto.color);
    colocarSugerencia('talla_input', producto.talla);
    colocarSugerencia('material_input', producto.material);

    if (producto.categoria) agregarOSeleccionarCategoria(producto.categoria);

    const fuente = document.getElementById('barcode_fuente');
    const descripcion = document.getElementById('barcode_descripcion');
    const imagen = document.getElementById('barcode_imagen_url');
    if (fuente) fuente.value = producto.fuente === 'upc_database' ? 'upc_database' : 'manual';
    if (descripcion) descripcion.value = producto.descripcion || '';
    if (imagen) imagen.value = producto.imagen_url || '';

    const preview = document.getElementById('previewImg');
    if (preview && producto.imagen_url) {
        preview.src = producto.imagen_url;
        preview.classList.remove('d-none');
        preview.dataset.externa = '1';
        preview.title = 'Imagen de referencia devuelta por UPC Database. Para conservar una imagen propia, selecciónala en el campo Imagen.';
    }
}

function mensajeConCuota(mensaje, data) {
    const restantes = data?.quota?.restantes;
    const desdeCache = data?.desde_cache === true;
    const detalles = [];
    if (Number.isInteger(restantes)) detalles.push(`Consultas gratuitas restantes hoy: ${restantes}`);
    if (desdeCache) detalles.push('resultado reutilizado desde caché de sesión');
    return detalles.length ? `${mensaje} · ${detalles.join(' · ')}` : mensaje;
}

async function buscarCodigoComercial() {
    const input = document.getElementById('codigo_barra_input');
    const boton = document.getElementById('buscarCodigoBtn');
    if (!input || modoCodigoActual() !== 'comercial') return;

    const codigo = normalizarCodigoComercial(input.value);
    input.value = codigo;

    if (!codigo) {
        establecerEstadoCodigo('warning', 'Falta el código', 'Escanea el producto o escribe su código y pulsa Buscar.');
        input.focus();
        return;
    }

    if (codigo.length > 50) {
        establecerEstadoCodigo('error', 'Código demasiado largo', 'El código no puede superar 50 caracteres.');
        return;
    }

    boton?.setAttribute('disabled', 'disabled');
    input.dataset.duplicado = '0';
    establecerEstadoCodigo('loading', 'Buscando producto', 'Revisando inventario y consultando UPC Database…');

    try {
        const respuesta = await fetch(`api/buscar_codigo_producto.php?codigo=${encodeURIComponent(codigo)}`, {
            method: 'GET',
            headers: {
                'Accept': 'application/json',
                'X-Requested-With': 'XMLHttpRequest'
            },
            cache: 'no-store'
        });

        let data;
        try {
            data = await respuesta.json();
        } catch (errorJson) {
            throw new Error('El servidor no devolvió una respuesta JSON válida.');
        }

        if (!respuesta.ok || !data.success) {
            throw new Error(data.message || 'No fue posible consultar el código.');
        }

        if (data.status === 'inventario') {
            limpiarDatosUPCDatabase({ conservarCodigo: true, conservarCamposManuales: true });
            const producto = data.producto || {};
            input.dataset.duplicado = '1';
            establecerEstadoCodigo(
                'error',
                'Producto ya registrado',
                `El código pertenece a “${producto.nombre || 'producto existente'}”. Existencia actual: ${producto.cantidad ?? 0} ${producto.unidad_medida || 'pz'}. Agrega existencias al registro actual.`
            );
            return;
        }

        input.dataset.duplicado = '0';

        if (data.status === 'externo') {
            completarFormularioDesdeUPCDatabase(data.producto || {});
            establecerEstadoCodigo(
                data.api_status === 'datos_incompletos' ? 'warning' : 'success',
                data.api_status === 'datos_incompletos' ? 'Resultado incompleto' : 'Producto encontrado',
                mensajeConCuota(data.message || 'Verifica la información sugerida y completa precios, proveedor y existencias.', data)
            );
            document.getElementById('precio_compra')?.focus();
            return;
        }

        limpiarDatosUPCDatabase({ conservarCodigo: true, conservarCamposManuales: true });
        establecerEstadoCodigo(
            'warning',
            'Completa los datos manualmente',
            mensajeConCuota(data.message || 'No se encontró información, pero puedes guardar este mismo código sin generar otro.', data)
        );
        document.getElementById('nombre_input')?.focus();
    } catch (error) {
        limpiarDatosUPCDatabase({ conservarCodigo: true, conservarCamposManuales: true });
        establecerEstadoCodigo(
            'warning',
            'Consulta no disponible',
            `${error.message || 'No fue posible consultar la API.'} Puedes completar los datos manualmente y conservar el código.`
        );
        document.getElementById('nombre_input')?.focus();
    } finally {
        boton?.removeAttribute('disabled');
    }
}

function actualizarModoCodigoProducto() {
    const modo = modoCodigoActual();
    const esComercial = modo === 'comercial';
    const fields = document.getElementById('codigoComercialFields');
    const codigo = document.getElementById('codigo_barra_input');
    const tipoCodigo = document.getElementById('tipo_codigo_select');
    const tipoCodigoGroup = document.getElementById('tipo_codigo_group');
    const esPeso = document.querySelector('input[name="tipo_venta"]:checked')?.value === 'peso';
    const esEspecial = Boolean(document.getElementById('stock_especial')?.checked) && !esPeso;

    document.querySelectorAll('.codigo-mode-option').forEach(label => {
        const radio = label.querySelector('input[name="modo_codigo"]');
        label.classList.toggle('active', Boolean(radio?.checked));
    });

    if (fields) fields.hidden = !esComercial;
    if (codigo) {
        codigo.disabled = !esComercial;
        codigo.required = esComercial;
        if (!esComercial) {
            codigo.dataset.duplicado = '0';
            limpiarDatosUPCDatabase({ conservarCodigo: false, conservarCamposManuales: true });
        }
    }

    if (tipoCodigo) {
        if (esComercial || esPeso || esEspecial) {
            tipoCodigo.value = 'unico';
            tipoCodigo.disabled = true;
            tipoCodigo.classList.add('is-locked');
        } else {
            tipoCodigo.disabled = false;
            tipoCodigo.classList.remove('is-locked');
        }
    }

    if (tipoCodigoGroup) {
        tipoCodigoGroup.classList.toggle('commercial-code-active', esComercial);
    }

    if (esComercial) {
        establecerEstadoCodigo('idle', 'Listo para escanear', 'Se revisará tu inventario y después UPC Database.');
        window.setTimeout(() => codigo?.focus(), 80);
    } else {
        establecerEstadoCodigo('success', 'Producto sin código comercial', 'El sistema conservará la generación P{id} o un código por pieza, según el tipo seleccionado.');
    }
}

// Captura lectores que funcionan como teclado incluso cuando el campo no tiene foco.
let scannerBuffer = '';
let scannerUltimaTecla = 0;
document.addEventListener('keydown', function (event) {
    if (modoCodigoActual() !== 'comercial') return;

    const objetivo = event.target;
    const etiqueta = objetivo?.tagName?.toLowerCase() || '';
    const esCampoEditable = ['input', 'textarea', 'select'].includes(etiqueta) || objetivo?.isContentEditable;
    if (esCampoEditable) return;

    const ahora = performance.now();
    if (ahora - scannerUltimaTecla > 120) scannerBuffer = '';
    scannerUltimaTecla = ahora;

    if (event.key === 'Enter') {
        if (scannerBuffer.length >= 4) {
            event.preventDefault();
            const input = document.getElementById('codigo_barra_input');
            if (input) {
                input.value = normalizarCodigoComercial(scannerBuffer);
                scannerBuffer = '';
                buscarCodigoComercial();
            }
        }
        return;
    }

    if (event.key.length === 1 && !event.ctrlKey && !event.altKey && !event.metaKey) {
        scannerBuffer += event.key;
    }
});

// ===== FORMULARIO =====
function actualizarTipoVentaProducto() {
    const seleccionado = document.querySelector('input[name="tipo_venta"]:checked');
    const tipo = seleccionado?.value === 'peso' ? 'peso' : 'unidad';
    const esPeso = tipo === 'peso';

    const cantidad = document.getElementById('cantidad_producto');
    const cantidadLabel = document.getElementById('cantidad_producto_label');
    const precioCompraLabel = document.getElementById('precio_compra_label');
    const precioVentaLabel = document.getElementById('precio_venta_label');
    const stockEspecial = document.getElementById('stock_especial');
    const specialWrapper = document.getElementById('special_stock_wrapper');
    const tipoCodigo = document.getElementById('tipo_codigo_select');
    const tipoCodigoHelp = document.getElementById('tipo_codigo_help');
    const tipoVentaHelp = document.getElementById('tipoVentaHelp');
    const cantidadIcono = document.querySelector('#cantidad_producto_group .field-with-icon > i');

    document.querySelectorAll('.tipo-venta-option').forEach(label => {
        const radio = label.querySelector('input[name="tipo_venta"]');
        const estaActivo = radio?.checked === true;
        label.classList.toggle('active', estaActivo);
        label.setAttribute('aria-checked', estaActivo ? 'true' : 'false');
    });

    if (cantidad) {
        cantidad.step = esPeso ? '0.001' : '1';
        cantidad.min = esPeso ? '0.001' : '1';
        cantidad.placeholder = esPeso ? 'Ej. 25.500' : 'Ej. 10';
        cantidad.inputMode = 'decimal';
    }

    if (cantidadIcono) {
        cantidadIcono.className = esPeso
            ? 'fas fa-weight-scale'
            : 'fas fa-cubes';
    }

    if (cantidadLabel) {
        cantidadLabel.innerHTML = esPeso
            ? 'Existencia inicial (kg) <span class="text-danger">*</span>'
            : 'Cantidad inicial (piezas) <span class="text-danger">*</span>';
    }

    if (precioCompraLabel) {
        precioCompraLabel.innerHTML = esPeso
            ? 'Precio compra por kg <span class="text-danger">*</span>'
            : 'Precio compra por pieza <span class="text-danger">*</span>';
    }

    if (precioVentaLabel) {
        precioVentaLabel.innerHTML = esPeso
            ? 'Precio venta base por kg <span class="text-danger">*</span>'
            : 'Precio venta base por pieza <span class="text-danger">*</span>';
    }

    /*
     * Los artículos por peso siempre deben controlar existencias reales.
     * Por eso se desmarca y oculta completamente Producto especial.
     */
    if (stockEspecial) {
        if (esPeso) {
            stockEspecial.checked = false;
        }
        stockEspecial.disabled = esPeso;
    }

    if (specialWrapper) {
        specialWrapper.hidden = esPeso;
        specialWrapper.classList.toggle('is-hidden', esPeso);
        specialWrapper.setAttribute('aria-hidden', esPeso ? 'true' : 'false');
    }

    if (tipoCodigo) {
        if (esPeso) {
            if (!tipoCodigo.dataset.valorUnidad) {
                tipoCodigo.dataset.valorUnidad = tipoCodigo.value || 'unico';
            }
            tipoCodigo.value = 'unico';
            tipoCodigo.disabled = true;
            tipoCodigo.classList.add('is-locked');
        } else {
            tipoCodigo.disabled = false;
            tipoCodigo.classList.remove('is-locked');
            tipoCodigo.value = tipoCodigo.dataset.valorUnidad || tipoCodigo.value || 'unico';
            delete tipoCodigo.dataset.valorUnidad;
        }
    }

    if (tipoCodigoHelp) {
        tipoCodigoHelp.innerHTML = esPeso
            ? ''
            : 'Usa un código para todo el producto o uno por cada pieza.';
        tipoCodigoHelp.classList.toggle('is-locked', esPeso);
    }

    if (tipoVentaHelp) {
        tipoVentaHelp.innerHTML = esPeso
            ? '<i class="fas fa-weight-scale"></i><span>La báscula captura kg · código único · sin producto especial.</span>'
            : '<i class="fas fa-circle-info"></i><span>Venta por unidades con código único o múltiple.</span>';
        tipoVentaHelp.classList.toggle('is-weight', esPeso);
    }

    actualizarModoStock();
    actualizarModoCodigoProducto();
}

function actualizarModoStock() {
    const checkboxEspecial = document.getElementById('stock_especial');
    const esPeso = document.querySelector('input[name="tipo_venta"]:checked')?.value === 'peso';
    const esEspecial = !esPeso && Boolean(checkboxEspecial?.checked);
    const toggleEspecial = document.getElementById('special_stock_toggle');
    const specialWrapper = document.getElementById('special_stock_wrapper');
    const cantidadGroup = document.getElementById('cantidad_producto_group');
    const cantidadInput = document.getElementById('cantidad_producto');
    const tipoCodigoSelect = document.getElementById('tipo_codigo_select');

    if (toggleEspecial) {
        toggleEspecial.classList.toggle('active', esEspecial);
    }

    if (specialWrapper) {
        specialWrapper.hidden = esPeso;
        specialWrapper.classList.toggle('is-hidden', esPeso);
    }

    if (cantidadGroup && cantidadInput) {
        cantidadGroup.classList.toggle('is-disabled', esEspecial);
        cantidadInput.disabled = esEspecial;
        cantidadInput.required = !esEspecial;
        if (esEspecial) cantidadInput.value = '';
    }

    if (tipoCodigoSelect) {
        const esCodigoComercial = modoCodigoActual() === 'comercial';
        if (esCodigoComercial) {
            tipoCodigoSelect.value = 'unico';
            tipoCodigoSelect.disabled = true;
        } else if (esEspecial) {
            if (!tipoCodigoSelect.dataset.valorAnterior) {
                tipoCodigoSelect.dataset.valorAnterior = tipoCodigoSelect.value || 'unico';
            }
            tipoCodigoSelect.value = 'unico';
            tipoCodigoSelect.disabled = true;
        } else if (esPeso) {
            tipoCodigoSelect.value = 'unico';
            tipoCodigoSelect.disabled = true;
        } else {
            tipoCodigoSelect.disabled = false;
            tipoCodigoSelect.value = tipoCodigoSelect.dataset.valorAnterior || tipoCodigoSelect.value || 'unico';
            delete tipoCodigoSelect.dataset.valorAnterior;
        }
    }
}

function limpiarFormulario() {
    const form = document.getElementById('formProducto');
    form.reset();
    document.getElementById('previewImg').classList.add('d-none');
    seleccionarAdquisicion('pagado');
    actualizarTipoVentaProducto();
    actualizarModoCodigoProducto();
    actualizarPrecioVentaConTresPorciento();
}

function previewImagen(event) {
    const img = document.getElementById('previewImg');
    img.src = URL.createObjectURL(event.target.files[0]);
    img.classList.remove('d-none');
    delete img.dataset.externa;
}

function agregarNuevaCategoria() {
    Swal.fire({
        title: 'Nueva categoría',
        input: 'text',
        inputPlaceholder: 'Nombre de la categoría',
        showCancelButton: true,
        confirmButtonText: 'Agregar',
        cancelButtonText: 'Cancelar',
        confirmButtonColor: '#f97316'
    }).then((result) => {
        if (result.isConfirmed && result.value) {
            const select = document.getElementById('categoriaSelect');
            const option = document.createElement('option');
            option.value = result.value;
            option.text = result.value;
            option.selected = true;
            select.appendChild(option);
        }
    });
}

function cambiarUnidadInsumo(tipo) {
    const cantidadInput = document.getElementById('cantidad_insumo');
    const unidadLabel = document.getElementById('unidad_insumo_label');
    const precioUnidadLabel = document.getElementById('precio_unidad_label');
    const unidadHelp = document.getElementById('unidad_insumo_help');
    const infoAdicional = document.getElementById('insumo_info_adicional');
    const anchoGroup = document.getElementById('ancho_insumo_group');
    
    if (tipo === 'metros') {
        cantidadInput.step = '0.1';
        cantidadInput.min = '0.1';
        cantidadInput.placeholder = 'Ej. 25.5';
        unidadLabel.textContent = 'm';
        precioUnidadLabel.textContent = '/m';
        unidadHelp.innerHTML = 'Cantidad en metros (puede usar decimales)';
        infoAdicional.textContent = 'Se miden en metros (útil para DTF, telas, vinilos, etc.)';
        anchoGroup.style.display = 'block';
    } else {
        cantidadInput.step = '1';
        cantidadInput.min = '1';
        cantidadInput.placeholder = 'Ej. 100';
        unidadLabel.textContent = 'pz';
        precioUnidadLabel.textContent = '/pz';
        unidadHelp.innerHTML = 'Cantidad en piezas (números enteros)';
        infoAdicional.textContent = 'Se cuentan por piezas y no generan códigos múltiples.';
        anchoGroup.style.display = 'none';
    }
    
    document.querySelectorAll('[name="tipo_unidad_insumo"]').forEach(radio => {
        if (radio.value === tipo) {
            radio.closest('label').classList.add('active');
            radio.checked = true;
        } else {
            radio.closest('label').classList.remove('active');
        }
    });
}

function actualizarPrecioVentaConTresPorciento() {
    const inputBase = document.getElementById('precio_venta_base');
    const salidaFinal = document.getElementById('precio_venta_final');

    if (!inputBase || !salidaFinal) {
        return;
    }

    const precioBase = Number.parseFloat(inputBase.value || '0');
    const precioFinal = Number.isFinite(precioBase) && precioBase > 0
        ? Math.round(precioBase * 1.03)
        : 0;

    salidaFinal.textContent = precioFinal.toLocaleString('es-MX', {
        style: 'currency',
        currency: 'MXN',
        minimumFractionDigits: 0,
        maximumFractionDigits: 0
    });
}

// Inicialización
document.addEventListener('DOMContentLoaded', function() {
    // Seleccionar pagado por defecto
    seleccionarAdquisicion('pagado');

    document.querySelectorAll('input[name="modo_codigo"]').forEach(radio => {
        radio.addEventListener('change', actualizarModoCodigoProducto);
    });

    const codigoInput = document.getElementById('codigo_barra_input');
    const buscarCodigoBtn = document.getElementById('buscarCodigoBtn');
    if (codigoInput) {
        codigoInput.addEventListener('input', function () {
            this.value = normalizarCodigoComercial(this.value);
            this.dataset.duplicado = '0';
            limpiarDatosUPCDatabase({ conservarCodigo: true, conservarCamposManuales: true });
        });
        codigoInput.addEventListener('keydown', function (event) {
            if (event.key === 'Enter') {
                event.preventDefault();
                buscarCodigoComercial();
            }
        });
    }
    buscarCodigoBtn?.addEventListener('click', buscarCodigoComercial);

    [
        'nombre_input', 'marca_input', 'modelo_input', 'presentacion_input',
        'peso_input', 'color_input', 'talla_input', 'material_input'
    ].forEach(id => {
        const campo = document.getElementById(id);
        campo?.addEventListener('input', function () {
            delete this.dataset.barcodeAutofill;
        });
    });
    document.getElementById('categoriaSelect')?.addEventListener('change', function () {
        delete this.dataset.barcodeAutofill;
    });

    actualizarModoCodigoProducto();

    document.querySelectorAll('input[name="tipo_venta"]').forEach(radio => {
        radio.addEventListener('change', actualizarTipoVentaProducto);
    });
    actualizarTipoVentaProducto();

    const stockEspecial = document.getElementById('stock_especial');
    if (stockEspecial) {
        stockEspecial.addEventListener('change', actualizarModoStock);
    }
    actualizarModoStock();

    const precioVentaBase = document.getElementById('precio_venta_base');
    if (precioVentaBase) {
        precioVentaBase.addEventListener('input', actualizarPrecioVentaConTresPorciento);
        precioVentaBase.addEventListener('change', actualizarPrecioVentaConTresPorciento);
        actualizarPrecioVentaConTresPorciento();
    }

    const formProducto = document.getElementById('formProducto');
    if (formProducto) {
        formProducto.addEventListener('submit', function (event) {
            const codigoInput = document.getElementById('codigo_barra_input');
            if (
                modoCodigoActual() === 'comercial'
                && codigoInput
                && codigoInput.dataset.duplicado === '1'
            ) {
                event.preventDefault();
                Swal.fire({
                    icon: 'warning',
                    title: 'Producto ya registrado',
                    text: 'No puedes crear nuevamente un producto con el mismo código. Agrega existencias desde el inventario.',
                    confirmButtonColor: '#f97316'
                });
                return;
            }

            const tipoCodigo = document.getElementById('tipo_codigo_select');
            if (tipoCodigo?.disabled) {
                tipoCodigo.disabled = false;
                tipoCodigo.value = 'unico';
            }

            if (codigoInput?.disabled) {
                codigoInput.disabled = false;
                codigoInput.value = '';
            }
        });
    }
    
    <?php if ($tipo_seleccionado == 'insumo'): ?>
    const unidadPorDefecto = document.querySelector('input[name="tipo_unidad_insumo"]:checked');
    if (unidadPorDefecto) {
        cambiarUnidadInsumo(unidadPorDefecto.value);
    }
    <?php endif; ?>
});
</script>

<?php
ob_end_flush();
?>