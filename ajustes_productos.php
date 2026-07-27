<?php
error_reporting(E_ALL & ~E_DEPRECATED);
ob_start();
session_start();
require_once 'includes/csrf.php';
require_once 'includes/db.php';

$rol_actual = strtolower(trim((string)($_SESSION['rol'] ?? '')));
$roles_administrativos = ['administrador', 'super_administrador'];

if (
    !isset($_SESSION['usuario_id']) ||
    !in_array($rol_actual, $roles_administrativos, true)
) {
    header("Location: login.php");
    exit;
}


// Evita que Hostinger/LiteSpeed/navegador sirvan una versión vieja del modal o del POST.
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Cache-Control: post-check=0, pre-check=0', false);
header('Pragma: no-cache');
header('Expires: Thu, 01 Jan 1970 00:00:00 GMT');

include 'includes/header.php';
include 'includes/navbar.php';
require_once 'includes/fpdf.php';
require_once __DIR__.'/vendor/autoload.php';

use Picqer\Barcode\BarcodeGeneratorPNG;

$errors = [];

function debugTipoCodigoHostinger($mensaje, array $data = []) {
    $dir = __DIR__ . '/logs';
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }

    $linea = '[' . date('Y-m-d H:i:s') . '] ' . $mensaje;
    if (!empty($data)) {
        $linea .= ' | ' . json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
    @file_put_contents($dir . '/tipo_codigo_hostinger.log', $linea . PHP_EOL, FILE_APPEND);
}

function valorTipoCodigoValido($valor) {
    $valor = strtolower(trim((string)$valor));
    return in_array($valor, ['unico', 'multiple'], true) ? $valor : null;
}

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
    $result = $conn->query("SELECT id, nombre FROM proveedores WHERE activo = 1 ORDER BY nombre");
    $proveedores = [];
    while ($row = $result->fetch_assoc()) {
        $proveedores[] = ['id' => $row['id'], 'nombre' => $row['nombre']];
    }
    return $proveedores;
}


// ======================= CÓDIGOS DE BARRAS SEGUROS =======================
// Regla del sistema:
// - tipo_codigo = "unico": solo debe existir 1 código por producto: P + 8 dígitos.
// - tipo_codigo = "multiple": debe existir 1 código por cada pieza actual en stock.
// - Cada vez que se actualiza producto / tipo de código / stock, se reemplazan los códigos del producto,
//   no se agregan encima de los anteriores. Así evitamos duplicados infinitos.
function normalizarTipoCodigoProducto($tipo_inventario, $tipo_codigo) {
    if ($tipo_inventario !== 'producto') {
        return 'multiple';
    }

    return trim((string)$tipo_codigo) === 'unico' ? 'unico' : 'multiple';
}

function cantidadEnteraParaCodigos($cantidad) {
    $cantidad = (float)$cantidad;

    if ($cantidad <= 0) {
        return 0;
    }

    return (int)floor($cantidad);
}

function normalizarCodigoBarra($codigo) {
    $codigo = trim((string)$codigo);
    $codigo = preg_replace('/\s+/', '', $codigo);
    return strtoupper($codigo);
}

function codigoUnicoProducto($producto_id) {
    return 'P' . str_pad((string)(int)$producto_id, 8, '0', STR_PAD_LEFT);
}

function codigoMultipleProducto($producto_id, $consecutivo) {
    // Formato único y consistente para TODOS los códigos del sistema:
    // - Código único:   P00000048
    // - Código múltiple: P000048001, P000048002, etc.
    // Así todos conservan la letra P y evitamos mezclas con códigos solo numéricos.
    return 'P' . str_pad((string)(int)$producto_id, 6, '0', STR_PAD_LEFT) . str_pad((string)(int)$consecutivo, 3, '0', STR_PAD_LEFT);
}

function productoActivoParaCodigos($conn, $producto_id) {
    $producto_id = (int)$producto_id;
    if ($producto_id <= 0) {
        return false;
    }

    $stmt = $conn->prepare("SELECT activo, tipo_inventario FROM productos WHERE id = ? LIMIT 1");
    if (!$stmt) {
        throw new Exception('Error validando producto activo: ' . $conn->error);
    }
    $stmt->bind_param('i', $producto_id);
    $stmt->execute();
    $res = $stmt->get_result();
    $producto = $res ? $res->fetch_assoc() : null;
    $stmt->close();

    return $producto && (int)$producto['activo'] === 1 && ($producto['tipo_inventario'] ?? '') === 'producto';
}

/**
 * Detecta productos que ya tienen uno o más códigos históricos sin la letra P.
 *
 * Esos códigos son permanentes: nunca se eliminan, reemplazan, renombran ni
 * convierten al formato nuevo. El campo "disponible" sí puede seguir cambiando
 * durante una venta porque eso no modifica el valor del código.
 */
function productoTieneCodigosLegados($conn, $producto_id) {
    $producto_id = (int)$producto_id;

    if ($producto_id <= 0) {
        return false;
    }

    $stmt = $conn->prepare("
        SELECT id
        FROM codigos_barras
        WHERE producto_id = ?
          AND UPPER(TRIM(codigo)) NOT LIKE 'P%'
        LIMIT 1
    ");

    if (!$stmt) {
        throw new Exception('Error validando códigos históricos: ' . $conn->error);
    }

    $stmt->bind_param('i', $producto_id);
    $stmt->execute();
    $res = $stmt->get_result();
    $tiene_codigo_legado = $res && $res->num_rows > 0;
    $stmt->close();

    return $tiene_codigo_legado;
}

/**
 * Elimina códigos únicamente cuando el producto usa el formato nuevo con P.
 *
 * Los códigos históricos numéricos quedan protegidos incluso si se edita,
 * ajusta, aumenta o disminuye el stock. Al desactivar el producto solo se
 * eliminan sus archivos PNG/ZIP/PDF, pero el código permanece en la BD.
 */
function eliminarCodigosProducto($conn, $producto_id) {
    $producto_id = (int)$producto_id;

    if (productoTieneCodigosLegados($conn, $producto_id)) {
        limpiarArchivosCodigosProducto($producto_id);
        return;
    }

    $stmt = $conn->prepare("DELETE FROM codigos_barras WHERE producto_id = ?");
    if (!$stmt) {
        throw new Exception('Error preparando limpieza de códigos: ' . $conn->error);
    }

    $stmt->bind_param('i', $producto_id);
    if (!$stmt->execute()) {
        $error = $stmt->error;
        $stmt->close();
        throw new Exception('Error eliminando códigos anteriores: ' . $error);
    }
    $stmt->close();

    limpiarArchivosCodigosProducto($producto_id);
}

function insertarCodigoBarraSeguro($conn, $producto_id, $codigo, $disponible = 1) {
    $producto_id = (int)$producto_id;
    $codigo = normalizarCodigoBarra($codigo);
    $disponible = (int)$disponible;

    if ($codigo === '') {
        throw new Exception('Se intentó guardar un código vacío.');
    }

    // Protección aunque todavía no hayas agregado el índice UNIQUE en MySQL.
    // Si el mismo código ya existe para otro producto, se detiene para no cruzar artículos.
    $stmt = $conn->prepare("SELECT id, producto_id FROM codigos_barras WHERE codigo = ? LIMIT 1");
    if (!$stmt) {
        throw new Exception('Error preparando validación de código: ' . $conn->error);
    }
    $stmt->bind_param('s', $codigo);
    $stmt->execute();
    $res = $stmt->get_result();
    $existente = $res ? $res->fetch_assoc() : null;
    $stmt->close();

    if ($existente) {
        if ((int)$existente['producto_id'] !== $producto_id) {
            throw new Exception("El código {$codigo} ya está asignado a otro producto.");
        }

        $stmt = $conn->prepare("UPDATE codigos_barras SET disponible = ? WHERE id = ?");
        if (!$stmt) {
            throw new Exception('Error preparando actualización de código existente: ' . $conn->error);
        }
        $idExistente = (int)$existente['id'];
        $stmt->bind_param('ii', $disponible, $idExistente);
        if (!$stmt->execute()) {
            $error = $stmt->error;
            $stmt->close();
            throw new Exception('Error actualizando código existente: ' . $error);
        }
        $stmt->close();
        return;
    }

    $stmt = $conn->prepare("INSERT INTO codigos_barras (producto_id, codigo, disponible) VALUES (?, ?, ?)");
    if (!$stmt) {
        throw new Exception('Error preparando inserción de código: ' . $conn->error);
    }

    $stmt->bind_param('isi', $producto_id, $codigo, $disponible);
    if (!$stmt->execute()) {
        $error = $stmt->error;
        $stmt->close();
        throw new Exception('Error insertando código de barras: ' . $error);
    }
    $stmt->close();
}

function reemplazarCodigosProducto($conn, $producto_id, $cantidad, $tipo_codigo, $tipo_inventario = 'producto') {
    $producto_id = (int)$producto_id;
    $tipo_codigo = normalizarTipoCodigoProducto($tipo_inventario, $tipo_codigo);

    /*
     * REGLA PERMANENTE PARA CÓDIGOS HISTÓRICOS:
     * Si el producto tiene aunque sea un código que no comienza con P,
     * se conserva exactamente como está. No se elimina ni se vuelve a crear
     * al editar datos, agregar stock, disminuirlo, ajustarlo o regenerar todos.
     *
     * Solo volvemos a generar la imagen usando el mismo valor guardado.
     */
    if (productoTieneCodigosLegados($conn, $producto_id)) {
        if (
            $tipo_inventario === 'producto' &&
            productoActivoParaCodigos($conn, $producto_id)
        ) {
            generarPNGCodigosProducto($conn, $producto_id);
        } else {
            limpiarArchivosCodigosProducto($producto_id);
        }

        return;
    }

    // Los productos del formato nuevo con P continúan funcionando igual.
    eliminarCodigosProducto($conn, $producto_id);

    if ($tipo_inventario !== 'producto' || !productoActivoParaCodigos($conn, $producto_id)) {
        return;
    }

    if ($tipo_codigo === 'unico') {
        insertarCodigoBarraSeguro(
            $conn,
            $producto_id,
            codigoUnicoProducto($producto_id),
            1
        );
    } else {
        $cantidad_codigos = cantidadEnteraParaCodigos($cantidad);

        for ($i = 1; $i <= $cantidad_codigos; $i++) {
            insertarCodigoBarraSeguro(
                $conn,
                $producto_id,
                codigoMultipleProducto($producto_id, $i),
                1
            );
        }
    }

    generarPNGCodigosProducto($conn, $producto_id);
}
// ========================= AGREGAR STOCK =========================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add_stock') {
    csrf_check();
    
    $producto_id = intval($_POST['producto_id']);
    $cantidad_agregar = floatval($_POST['cantidad']);
    $nota = trim($_POST['nota'] ?? '');
    $usuario_id = $_SESSION['usuario_id'] ?? null;
    
    if ($producto_id <= 0) {
        $errors[] = "ID de producto inválido.";
    }
    
    if ($cantidad_agregar <= 0) {
        $errors[] = "La cantidad debe ser mayor a 0.";
    }
    
    if (empty($errors)) {
        $stmt = $conn->prepare("SELECT nombre, cantidad, tipo_inventario, tipo_codigo FROM productos WHERE id = ? AND activo = 1");
        $stmt->bind_param("i", $producto_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $producto = $result->fetch_assoc();
        
        if (!$producto) {
            $errors[] = "Producto no encontrado.";
        } else {
            $cantidad_anterior = $producto['cantidad'];
            $cantidad_nueva = $cantidad_anterior + $cantidad_agregar;
            $tipo_inventario = $producto['tipo_inventario'];
            
            $conn->begin_transaction();
            
            try {
                $stmt = $conn->prepare("UPDATE productos SET cantidad = ? WHERE id = ?");
                $stmt->bind_param("di", $cantidad_nueva, $producto_id);
                
                if (!$stmt->execute()) {
                    throw new Exception("Error al actualizar cantidad: " . $conn->error);
                }
                
                $stmt = $conn->prepare("INSERT INTO historial_stock (producto_id, cantidad_anterior, cantidad_nueva, cantidad_agregada, tipo_movimiento, nota, usuario_id) VALUES (?, ?, ?, ?, 'entrada', ?, ?)");
                $stmt->bind_param("idddsi", $producto_id, $cantidad_anterior, $cantidad_nueva, $cantidad_agregar, $nota, $usuario_id);
                
                if (!$stmt->execute()) {
                    throw new Exception("Error al registrar historial: " . $conn->error);
                }
                
                if ($tipo_inventario === 'producto') {
                    // Se regeneran los códigos exactos del producto según el stock nuevo.
                    // Esto evita que cada actualización vaya acumulando registros duplicados.
                    reemplazarCodigosProducto(
                        $conn,
                        $producto_id,
                        $cantidad_nueva,
                        $producto['tipo_codigo'] ?? 'multiple',
                        $tipo_inventario
                    );
                }
                
                $conn->commit();
                
                echo "<script>
                Swal.fire({
                    icon: 'success',
                    title: 'Stock agregado',
                    text: 'Se agregaron " . number_format($cantidad_agregar, 2) . " unidades al stock.',
                    confirmButtonText: 'Aceptar',
                    confirmButtonColor: '#f97316'
                }).then(() => {
                    window.location = 'ajustes_productos.php';
                });
                </script>";
                exit;
                
            } catch (Exception $e) {
                $conn->rollback();
                $errors[] = "Error al procesar: " . $e->getMessage();
            }
        }
    }
}

// ========================= AJUSTAR STOCK =========================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'adjust_stock') {
    csrf_check();
    
    $producto_id = intval($_POST['producto_id']);
    $nueva_cantidad = floatval($_POST['nueva_cantidad']);
    $razon_ajuste = trim($_POST['razon_ajuste'] ?? 'Ajuste manual');
    $usuario_id = $_SESSION['usuario_id'] ?? null;
    
    if ($producto_id <= 0) {
        $errors[] = "ID de producto inválido.";
    }
    
    if ($nueva_cantidad < 0) {
        $errors[] = "La cantidad no puede ser negativa.";
    }
    
    if (empty($errors)) {
        $stmt = $conn->prepare("SELECT nombre, cantidad, tipo_inventario, tipo_codigo FROM productos WHERE id = ? AND activo = 1");
        $stmt->bind_param("i", $producto_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $producto = $result->fetch_assoc();
        
        if (!$producto) {
            $errors[] = "Producto no encontrado.";
        } else {
            $cantidad_anterior = $producto['cantidad'];
            $diferencia = $nueva_cantidad - $cantidad_anterior;
            $tipo_inventario = $producto['tipo_inventario'];
            
            $conn->begin_transaction();
            
            try {
                $stmt = $conn->prepare("UPDATE productos SET cantidad = ? WHERE id = ?");
                $stmt->bind_param("di", $nueva_cantidad, $producto_id);
                
                if (!$stmt->execute()) {
                    throw new Exception("Error al actualizar cantidad: " . $conn->error);
                }
                
                $nota_completa = "AJUSTE: $razon_ajuste (diferencia: " . ($diferencia > 0 ? "+$diferencia" : $diferencia) . ")";
                
                // CORRECCIÓN: Guardar la diferencia CON SIGNO en cantidad_agregada
                $cantidad_agregada_con_signo = $diferencia; // Esto ya puede ser positivo o negativo
                
                $stmt = $conn->prepare("INSERT INTO historial_stock (producto_id, cantidad_anterior, cantidad_nueva, cantidad_agregada, tipo_movimiento, nota, usuario_id) VALUES (?, ?, ?, ?, 'ajuste', ?, ?)");
                $stmt->bind_param("idddsi", $producto_id, $cantidad_anterior, $nueva_cantidad, $cantidad_agregada_con_signo, $nota_completa, $usuario_id);

                if (!$stmt->execute()) {
                    throw new Exception("Error al registrar historial: " . $stmt->error);
                }
                
                if ($tipo_inventario === 'producto') {
                    // Igual que en editar/agregar stock: se reemplazan todos los códigos del producto
                    // para que coincidan exactamente con el stock ajustado.
                    reemplazarCodigosProducto(
                        $conn,
                        $producto_id,
                        $nueva_cantidad,
                        $producto['tipo_codigo'] ?? 'multiple',
                        $tipo_inventario
                    );
                }
                
                $conn->commit();
                
                echo "<script>
                Swal.fire({
                    icon: 'success',
                    title: 'Stock ajustado',
                    text: 'El stock se actualizó correctamente.',
                    confirmButtonText: 'Aceptar',
                    confirmButtonColor: '#f97316'
                }).then(() => {
                    window.location = 'ajustes_productos.php';
                });
                </script>";
                exit;
                
            } catch (Exception $e) {
                $conn->rollback();
                $errors[] = "Error al procesar: " . $e->getMessage();
            }
        }
    }
}

// ========================= ACTUALIZAR PRODUCTO =========================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update') {
    csrf_check();

    $id = intval($_POST['id']);
    $nombre = trim($_POST['nombre']);
    
    // ===== CATEGORÍA: Verificar si es nueva =====
    $categoria = trim($_POST['categoria']);
    
    if ($categoria === '__NUEVA__') {
        if (isset($_POST['categoria_nueva']) && !empty($_POST['categoria_nueva'])) {
            $categoria = trim($_POST['categoria_nueva']);
        } else {
            $errors[] = "Debe escribir el nombre de la nueva categoría.";
        }
    }
    
    if (empty($categoria)) {
        $errors[] = "La categoría es requerida.";
    }
    
    // ===== PROVEEDOR =====
    $proveedor_id = null;
    $proveedor_nombre = null;
    
    if (isset($_POST['proveedor']) && !empty($_POST['proveedor']) && is_numeric($_POST['proveedor'])) {
        $proveedor_id = intval($_POST['proveedor']);
        $stmt = $conn->prepare("SELECT nombre FROM proveedores WHERE id = ? AND activo = 1");
        $stmt->bind_param("i", $proveedor_id);
        $stmt->execute();
        $result = $stmt->get_result();
        if ($row = $result->fetch_assoc()) {
            $proveedor_nombre = $row['nombre'];
        }
    } 
    elseif (isset($_POST['proveedor_nuevo']) && !empty($_POST['proveedor_nuevo'])) {
        $nuevo_nombre = trim($_POST['proveedor_nuevo']);
        $stmt = $conn->prepare("SELECT id, nombre FROM proveedores WHERE nombre = ?");
        $stmt->bind_param("s", $nuevo_nombre);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($row = $result->fetch_assoc()) {
            $proveedor_id = $row['id'];
            $proveedor_nombre = $row['nombre'];
        } else {
            $stmt = $conn->prepare("INSERT INTO proveedores (nombre, activo) VALUES (?, 1)");
            $stmt->bind_param("s", $nuevo_nombre);
            if ($stmt->execute()) {
                $proveedor_id = $conn->insert_id;
                $proveedor_nombre = $nuevo_nombre;
            }
        }
    }
    
    if (!empty($errors)) {
        echo "<script>
        Swal.fire({
            icon: 'error',
            title: 'Error de validación',
            html: '" . implode('<br>', $errors) . "',
            confirmButtonColor: '#f97316'
        });
        </script>";
    } else {
        $precio_compra = floatval($_POST['precio_compra']);
        $precio_venta = floatval($_POST['precio_venta']);

        // Obtener datos actuales ANTES de decidir tipo_codigo.
        // Esto evita que el producto se cambie a múltiple si por alguna razón el select no viaja en el POST.
        $stmt = $conn->prepare("SELECT imagen, cantidad, tipo_codigo, tipo_inventario FROM productos WHERE id = ? AND activo = 1");
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $result = $stmt->get_result();
        $producto_actual = $result->fetch_assoc();
        $stmt->close();

        if (!$producto_actual) {
            echo "<script>
            Swal.fire({
                icon: 'error',
                title: 'Producto no encontrado',
                text: 'No se pudo cargar la configuración actual del producto.',
                confirmButtonColor: '#f97316'
            });
            </script>";
            exit;
        }

        $tipo_inventario_actual = $producto_actual['tipo_inventario'] ?? 'producto';
        $tipo_inventario = trim((string)($_POST['tipo_inventario'] ?? $tipo_inventario_actual));
        if (!in_array($tipo_inventario, ['producto', 'insumo'], true)) {
            $tipo_inventario = $tipo_inventario_actual;
        }

        $tipo_codigo_actual = normalizarTipoCodigoProducto(
            $tipo_inventario_actual,
            $producto_actual['tipo_codigo'] ?? 'multiple'
        );

        // En Hostinger puede quedar cacheado el JS o pueden viajar ocultos con valor viejo.
        // Por eso SOLO se toma el select principal si llegó válido.
        // Si no llegó, se conserva lo que ya tenía la BD; no se fuerza a multiple.
        $tipo_codigo_enviado = isset($_POST['tipo_codigo']) ? valorTipoCodigoValido($_POST['tipo_codigo']) : null;
        $tipo_codigo_respaldo = isset($_POST['tipo_codigo_respaldo']) ? valorTipoCodigoValido($_POST['tipo_codigo_respaldo']) : null;
        $tipo_codigo_forzado = isset($_POST['tipo_codigo_forzado']) ? valorTipoCodigoValido($_POST['tipo_codigo_forzado']) : null;
        $tipo_codigo_tocado = isset($_POST['tipo_codigo_tocado']) ? trim((string)$_POST['tipo_codigo_tocado']) : '0';

        $tipo_codigo = $tipo_codigo_actual;

        if ($tipo_inventario === 'producto') {
            if ($tipo_codigo_enviado !== null) {
                $tipo_codigo = $tipo_codigo_enviado;
            } elseif ($tipo_codigo_tocado === '1' && $tipo_codigo_forzado !== null) {
                $tipo_codigo = $tipo_codigo_forzado;
            } elseif ($tipo_codigo_tocado === '1' && $tipo_codigo_respaldo !== null) {
                $tipo_codigo = $tipo_codigo_respaldo;
            }
        } else {
            $tipo_codigo = 'multiple';
        }

        
        /*
         * SEGURIDAD DE PRODUCCIÓN:
         * Los artículos con códigos históricos sin P conservan tanto el valor
         * de su código como su configuración actual. Aunque un formulario viejo,
         * caché o manipulación del POST intente cambiar el tipo, no se permite
         * convertirlos ni regenerarlos.
         */
        $tiene_codigo_legado = productoTieneCodigosLegados($conn, $id);
        if ($tiene_codigo_legado) {
            $tipo_codigo = $tipo_codigo_actual;
        }

        debugTipoCodigoHostinger('POST update producto', [
            'id' => $id,
            'tipo_codigo_actual_bd' => $tipo_codigo_actual,
            'post_tipo_codigo' => $_POST['tipo_codigo'] ?? null,
            'post_tipo_codigo_respaldo' => $_POST['tipo_codigo_respaldo'] ?? null,
            'post_tipo_codigo_forzado' => $_POST['tipo_codigo_forzado'] ?? null,
            'post_tipo_codigo_tocado' => $_POST['tipo_codigo_tocado'] ?? null,
            'tipo_codigo_decidido' => $tipo_codigo,
                    'tiene_codigo_legado' => $tiene_codigo_legado ? 1 : 0,
        ]);
        
        $atributos = [];
        $campos_atributos = ['marca', 'modelo', 'color', 'talla', 'peso', 'material'];
        foreach ($campos_atributos as $campo) {
            if (!empty($_POST[$campo])) {
                $atributos[$campo] = $_POST[$campo];
            }
        }
        $atributos_json = !empty($atributos) ? json_encode($atributos, JSON_UNESCAPED_UNICODE) : null;

        $imagen_path = $producto_actual['imagen'] ?? '';
        $cantidad_actual = (float)($producto_actual['cantidad'] ?? 0);

        // Procesar nueva imagen
        if (!empty($_FILES['imagen']['name'])) {
            $upload_dir = __DIR__.'/uploads/productos/';
            if (!is_dir($upload_dir)) mkdir($upload_dir, 0777, true);

            $extension = strtolower(pathinfo($_FILES['imagen']['name'], PATHINFO_EXTENSION));
            
            $nombre_limpio = preg_replace('/[^a-zA-Z0-9áéíóúüñÁÉÍÓÚÜÑ\s-]/u', '', $nombre);
            $nombre_limpio = preg_replace('/[\s]+/', '_', $nombre_limpio);
            $nombre_limpio = trim($nombre_limpio, '_');
            
            if (empty($nombre_limpio)) {
                $nombre_limpio = 'producto';
            }
            
            $nombre_base = $nombre_limpio;
            $imagen_name = $nombre_base . '.' . $extension;
            $contador = 1;
            
            while (file_exists($upload_dir . $imagen_name)) {
                $imagen_name = $nombre_base . '_' . $contador . '.' . $extension;
                $contador++;
            }
            
            $nueva_imagen = 'uploads/productos/' . $imagen_name;
            
            if (move_uploaded_file($_FILES['imagen']['tmp_name'], $upload_dir . $imagen_name)) {
                if ($imagen_path && file_exists($imagen_path)) {
                    unlink($imagen_path);
                }
                $imagen_path = $nueva_imagen;
            }
        }

        $tipo_adquisicion = $_POST['tipo_adquisicion'] ?? 'concesion';

        // ===== ACTUALIZAR PRODUCTO Y REGENERAR CÓDIGOS SIN DUPLICAR =====
        $sql = "UPDATE productos SET 
            nombre = ?,
            categoria = ?,
            atributos = ?,
            proveedor = ?,
            proveedor_id = ?,
            imagen = ?,
            precio_compra = ?,
            precio_venta = ?,
            tipo_codigo = ?,
            tipo_inventario = ?,
            tipo_adquisicion = ?
            WHERE id = ?";

        // Asegurar que $tipo_codigo nunca sea null ni vacío.
        $tipo_codigo_final = normalizarTipoCodigoProducto($tipo_inventario, $tipo_codigo ?? 'multiple');
        $imagen_valor = $imagen_path ?? '';

        try {
            $conn->begin_transaction();

            $stmt = $conn->prepare($sql);
            if (!$stmt) {
                throw new Exception('Error preparando actualización: ' . $conn->error);
            }

            // Tipos correctos:
            // s nombre, s categoria, s atributos, s proveedor, i proveedor_id, s imagen,
            // d precio_compra, d precio_venta, s tipo_codigo, s tipo_inventario, s tipo_adquisicion, i id.
            // OJO: antes tipo_codigo se estaba mandando como double por un bind_param incorrecto,
            // y eso hacía que MySQL ENUM guardara vacío o perdiera el valor.
            $stmt->bind_param(
                "ssssisddsssi",
                $nombre,
                $categoria,
                $atributos_json,
                $proveedor_nombre,
                $proveedor_id,
                $imagen_valor,
                $precio_compra,
                $precio_venta,
                $tipo_codigo_final,
                $tipo_inventario,
                $tipo_adquisicion,
                $id
            );

            if (!$stmt->execute()) {
                throw new Exception('Error al actualizar producto: ' . $stmt->error);
            }
            $stmt->close();

            // Re-guardado directo y separado para Hostinger.
            // Aunque el UPDATE grande se ejecute bien, este UPDATE deja tipo_codigo explícito
            // como texto válido del ENUM y evita pérdidas por bind/caché/formularios ocultos.
            if ($tipo_inventario === 'producto') {
                $stmtHostingerTipo = $conn->prepare("UPDATE productos SET tipo_codigo = ? WHERE id = ? AND activo = 1 LIMIT 1");
                if (!$stmtHostingerTipo) {
                    throw new Exception('Error preparando guardado directo de tipo_codigo: ' . $conn->error);
                }
                $stmtHostingerTipo->bind_param('si', $tipo_codigo_final, $id);
                if (!$stmtHostingerTipo->execute()) {
                    throw new Exception('Error guardando directo tipo_codigo: ' . $stmtHostingerTipo->error);
                }
                $stmtHostingerTipo->close();
            }

            // Verificación real: confirmar que el ENUM quedó guardado como texto correcto.
            $stmtCheckTipo = $conn->prepare("SELECT tipo_codigo FROM productos WHERE id = ? LIMIT 1");
            if (!$stmtCheckTipo) {
                throw new Exception('Error verificando tipo de código: ' . $conn->error);
            }
            $stmtCheckTipo->bind_param('i', $id);
            $stmtCheckTipo->execute();
            $resCheckTipo = $stmtCheckTipo->get_result();
            $rowCheckTipo = $resCheckTipo ? $resCheckTipo->fetch_assoc() : null;
            $stmtCheckTipo->close();

            $tipoCodigoGuardado = strtolower(trim((string)($rowCheckTipo['tipo_codigo'] ?? '')));
            if ($tipo_inventario === 'producto' && $tipoCodigoGuardado !== $tipo_codigo_final) {
                // Reintento directo por si algún modo de MySQL/ENUM dejó el valor vacío.
                $stmtFixTipo = $conn->prepare("UPDATE productos SET tipo_codigo = ? WHERE id = ?");
                if (!$stmtFixTipo) {
                    throw new Exception('Error preparando corrección de tipo de código: ' . $conn->error);
                }
                $stmtFixTipo->bind_param('si', $tipo_codigo_final, $id);
                if (!$stmtFixTipo->execute()) {
                    throw new Exception('No se pudo corregir tipo_codigo: ' . $stmtFixTipo->error);
                }
                $stmtFixTipo->close();

                $stmtRecheckTipo = $conn->prepare("SELECT tipo_codigo FROM productos WHERE id = ? LIMIT 1");
                $stmtRecheckTipo->bind_param('i', $id);
                $stmtRecheckTipo->execute();
                $resRecheckTipo = $stmtRecheckTipo->get_result();
                $rowRecheckTipo = $resRecheckTipo ? $resRecheckTipo->fetch_assoc() : null;
                $stmtRecheckTipo->close();
                $tipoCodigoRevisado = strtolower(trim((string)($rowRecheckTipo['tipo_codigo'] ?? '')));
                if ($tipoCodigoRevisado !== $tipo_codigo_final) {
                    throw new Exception('Hostinger no permitió guardar tipo_codigo. Esperado: ' . $tipo_codigo_final . ', guardado: ' . $tipoCodigoRevisado);
                }
            }

            debugTipoCodigoHostinger('BD despues de update', [
                'id' => $id,
                'tipo_codigo_final' => $tipo_codigo_final,
                'tipo_codigo_guardado' => $tipoCodigoGuardado,
            ]);

            // Punto clave:
            // No se agregan códigos encima de los anteriores.
            // Se eliminan los códigos del producto y se recrean según el tipo seleccionado.
            // Si cambias de múltiple a único, quedan eliminados todos los anteriores y solo queda P000000XX.
            reemplazarCodigosProducto(
                $conn,
                $id,
                $cantidad_actual,
                $tipo_codigo_final,
                $tipo_inventario
            );

            $conn->commit();

            echo "<script>
            Swal.fire({
                icon: 'success',
                title: 'Producto actualizado',
                text: 'Los cambios se guardaron correctamente. Los códigos históricos sin P se conservaron sin cambios.',
                confirmButtonColor: '#f97316'
            }).then(() => {
                window.location='ajustes_productos.php';
            });
            </script>";
            exit;
        } catch (Throwable $e) {
            if ($conn->errno === 0) {
                // no-op
            }

            try {
                $conn->rollback();
            } catch (Throwable $rollbackError) {}

            echo "<script>
            Swal.fire({
                icon: 'error',
                title: 'Error al actualizar',
                text: '" . addslashes($e->getMessage()) . "',
                confirmButtonColor: '#f97316'
            });
            </script>";
        }
    }
}

// ========================= ELIMINAR PRODUCTO =========================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete') {
    csrf_check();
    $id = intval($_POST['id']);

    $stmt = $conn->prepare("SELECT imagen FROM productos WHERE id = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $result = $stmt->get_result();
    $producto = $result->fetch_assoc();
    
    if ($producto['imagen'] && file_exists($producto['imagen'])) {
        unlink($producto['imagen']);
    }

    // Al desactivar un producto también eliminamos sus códigos y PNG.
    // Así ya no aparecen en descargas ni se regeneran etiquetas de productos inactivos.
    eliminarCodigosProducto($conn, $id);

    $stmt = $conn->prepare("UPDATE productos SET activo = 0 WHERE id = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    
    echo "<script>
    Swal.fire({
        icon: 'success',
        title: 'Producto eliminado',
        text: 'El producto fue eliminado correctamente.',
        confirmButtonColor: '#f97316'
    }).then(() => {
        window.location='ajustes_productos.php';
    });
    </script>";
    exit;
}


// ========================= REGENERAR TODOS LOS CÓDIGOS SIN DUPLICADOS =========================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'regenerate_all_codes') {
    csrf_check();

    $conn->begin_transaction();

    try {
        $res = $conn->query("SELECT id, cantidad, tipo_codigo, tipo_inventario FROM productos WHERE activo = 1 ORDER BY id ASC");

        if (!$res) {
            throw new Exception('No se pudieron consultar los productos: ' . $conn->error);
        }

        $regenerados = 0;

        while ($producto = $res->fetch_assoc()) {
            reemplazarCodigosProducto(
                $conn,
                (int)$producto['id'],
                (float)$producto['cantidad'],
                $producto['tipo_codigo'] ?? 'multiple',
                $producto['tipo_inventario'] ?? 'producto'
            );
            $regenerados++;
        }

        $conn->commit();

        echo "<script>
        Swal.fire({
            icon: 'success',
            title: 'Códigos regenerados',
            text: 'Se sincronizaron los códigos de {$regenerados} artículos. Los códigos históricos sin P se conservaron intactos.',
            confirmButtonColor: '#f97316'
        }).then(() => {
            window.location='ajustes_productos.php';
        });
        </script>";
        exit;
    } catch (Throwable $e) {
        try {
            $conn->rollback();
        } catch (Throwable $rollbackError) {}

        echo "<script>
        Swal.fire({
            icon: 'error',
            title: 'Error al regenerar códigos',
            text: '" . addslashes($e->getMessage()) . "',
            confirmButtonColor: '#f97316'
        });
        </script>";
    }
}

// ========================= GENERAR CÓDIGOS DE BARRAS =========================
function generarCodigosBarras($conn, $nombre, $producto_id, $cantidad, $tipo_codigo, $tipo_inventario = 'producto') {
    // Función conservada por compatibilidad con otras partes del sistema.
    // Los códigos nuevos con P se sincronizan; los históricos sin P se conservan intactos.
    reemplazarCodigosProducto($conn, $producto_id, $cantidad, $tipo_codigo, $tipo_inventario);
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

function gdText($img, $size, $angle, $x, $y, $color, $font, $text) {
    $text = (string)$text;
    if ($font && file_exists($font)) {
        imagettftext($img, $size, $angle, $x, $y, $color, $font, $text);
    } else {
        imagestring($img, max(1, min(5, intval($size / 3))), $x, $y - 12, $text, $color);
    }
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

function partirTextoEtiqueta($texto, $font, $size, $maxWidth, $maxLines = 2) {
    $texto = textoCortoPNG($texto, 90);
    if ($texto === '') {
        return [];
    }

    $palabras = preg_split('/\s+/u', $texto);
    $lineas = [];
    $lineaActual = '';

    foreach ($palabras as $palabra) {
        $candidata = trim($lineaActual === '' ? $palabra : $lineaActual . ' ' . $palabra);

        if (medirTextoGD($font, $size, $candidata) <= $maxWidth) {
            $lineaActual = $candidata;
            continue;
        }

        if ($lineaActual === '') {
            $lineas[] = truncarTextoPorAncho($palabra, $font, $size, $maxWidth);
        } else {
            $lineas[] = $lineaActual;
            $lineaActual = $palabra;
        }

        if (count($lineas) >= $maxLines - 1) {
            break;
        }
    }

    $restante = trim($lineaActual);
    if ($restante !== '' && count($lineas) < $maxLines) {
        $lineas[] = truncarTextoPorAncho($restante, $font, $size, $maxWidth);
    }

    if (count($lineas) < $maxLines && count($palabras) > 0) {
        $consumido = implode(' ', $lineas);
        $consumidoNorm = preg_replace('/\s+/u', ' ', trim($consumido));
        $textoNorm = preg_replace('/\s+/u', ' ', trim($texto));
        if ($consumidoNorm !== $textoNorm) {
            $faltante = trim(substr($textoNorm, strlen($consumidoNorm)));
            if ($faltante !== '') {
                $lineas[count($lineas) - 1] = truncarTextoPorAncho(rtrim($lineas[count($lineas) - 1] . ' ' . $faltante), $font, $size, $maxWidth);
            }
        }
    }

    if (empty($lineas)) {
        $lineas[] = truncarTextoPorAncho($texto, $font, $size, $maxWidth);
    }

    return array_slice($lineas, 0, $maxLines);
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
    // Versión enfocada en MÁXIMA LEGIBILIDAD del código para la Nimbot B1.
    // El bloque sigue dentro del PNG total 30 x 50 mm con 3 etiquetas,
    // pero se le da prioridad casi total al barcode para que el lector lo detecte fácil.
    $canvasW = 240;
    $bloqueH = 133;
    $bottomY = $topY + $bloqueH - 1;

    $white = imagecolorallocate($img, 255, 255, 255);
    $black = imagecolorallocate($img, 0, 0, 0);
    $lightGray = imagecolorallocate($img, 232, 232, 232);

    imagefilledrectangle($img, 0, $topY, $canvasW - 1, $bottomY, $white);

    $nombre = textoCortoPNG($item['nombre'] ?? '', 68);
    $precio = '$' . number_format((float)($item['precio_venta'] ?? 0), 2);
    $codigo = (string)($item['codigo'] ?? '');

    // Encabezado super compacto para dejar el máximo espacio útil al código.
    $titulo = trim($nombre . '  ' . $precio);
    $titulo = truncarTextoPorAncho($titulo, $fontBold, 8, 232);
    gdTextCentered($img, 8, $topY + 11, $black, $fontBold, $titulo, $canvasW);

    // Barcode súper dominante: casi todo el bloque es código.
    // Mayor alto + mayor ancho con márgenes mínimos.
    $barcodeMaxW = 234;
    $barcodeMaxH = 86;
    $barcode = crearBarcodePNGExacto($generator, $codigo, $barcodeMaxW, 82);
    if ($barcode) {
        copiarBarcodeSinDistorsion($img, $barcode, 3, $topY + 16, $barcodeMaxW, $barcodeMaxH);
        imagedestroy($barcode);
    }

    // Valor del código pequeño pero bien legible, centrado abajo.
    gdTextCentered($img, 10, $topY + 112, $black, $fontBold, $codigo, $canvasW);

    // Separador muy discreto para recorte.
    if ($bottomY < 399) {
        imageline($img, 4, $bottomY, $canvasW - 4, $bottomY, $lightGray);
    }
}

function limpiarNombreArchivo($texto, $fallback = 'producto') {
    $texto = trim((string)$texto);
    if ($texto === '') {
        return $fallback;
    }

    if (function_exists('iconv')) {
        $convertido = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $texto);
        if ($convertido !== false) {
            $texto = $convertido;
        }
    }

    $texto = preg_replace('/[^A-Za-z0-9]+/', '_', $texto);
    $texto = trim($texto, '_');
    $texto = preg_replace('/_+/', '_', $texto);

    if ($texto === '') {
        return $fallback;
    }

    return substr($texto, 0, 80);
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

    $w = 240;   // 30 mm a 203 DPI aprox.
    $h = 400;   // 50 mm a 203 DPI aprox. EN TOTAL, repartidos entre 3 etiquetas.
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
    $stmt = $conn->prepare("SELECT 
            p.id,
            p.nombre,
            p.precio_venta,
            cb.codigo
        FROM productos p
        JOIN codigos_barras cb ON p.id = cb.producto_id
        WHERE p.id = ? AND p.activo = 1 AND p.tipo_inventario = 'producto'
        ORDER BY cb.codigo ASC");
    $stmt->bind_param("i", $producto_id);
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

    if (!productoActivoParaCodigos($conn, $producto_id)) {
        return;
    }

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

function generarZIPTodosCodigosPNG($conn) {
    $codigos_dir = __DIR__ . '/uploads/codigos/';
    if (!is_dir($codigos_dir)) {
        mkdir($codigos_dir, 0777, true);
    }

    if (!class_exists('ZipArchive')) {
        header('Content-Type: text/plain; charset=utf-8');
        echo 'Tu servidor no tiene habilitada la extensión ZipArchive de PHP.';
        exit;
    }

    $query = "SELECT 
            p.id,
            p.nombre,
            p.precio_venta,
            cb.codigo
        FROM productos p
        JOIN codigos_barras cb ON p.id = cb.producto_id
        WHERE p.activo = 1 AND p.tipo_inventario = 'producto'
        ORDER BY p.nombre ASC, cb.codigo ASC";

    $res = $conn->query($query);

    if (!$res || $res->num_rows === 0) {
        header('Content-Type: text/plain; charset=utf-8');
        echo 'No hay códigos de barras para generar.';
        exit;
    }

    $items = [];
    while ($row = $res->fetch_assoc()) {
        $items[] = $row;
    }

    // Se agrupan de 3 etiquetas por imagen, tal como el usuario las imprime manualmente.
    $porProducto = [];
    foreach ($items as $item) {
        $porProducto[$item['id']][] = $item;
    }

    $grupos = [];
    foreach ($porProducto as $productoId => $codigosProducto) {
        $nombreProducto = $codigosProducto[0]['nombre'] ?? ('producto_' . $productoId);
        $chunksProducto = array_chunk($codigosProducto, 3);
        $totalPartesProducto = count($chunksProducto);

        foreach ($chunksProducto as $indiceParte => $grupoProducto) {
            $grupos[] = [
                'producto_id' => (int)$productoId,
                'nombre_producto' => $nombreProducto,
                'parte' => $indiceParte + 1,
                'total_partes' => $totalPartesProducto,
                'items' => completarGrupoTresCodigos($grupoProducto)
            ];
        }
    }

    $tmpZip = $codigos_dir . 'todos_codigos_png_' . date('Ymd_His') . '.zip';
    $zip = new ZipArchive();

    if ($zip->open($tmpZip, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        header('Content-Type: text/plain; charset=utf-8');
        echo 'No se pudo crear el archivo ZIP.';
        exit;
    }

    $archivosTemporales = [];
    foreach ($grupos as $index => $grupoInfo) {
        $numero = str_pad($index + 1, 4, '0', STR_PAD_LEFT);
        $nombreSeguro = limpiarNombreArchivo($grupoInfo['nombre_producto'] ?? 'producto');
        $productoId = (int)($grupoInfo['producto_id'] ?? 0);
        $parte = (int)($grupoInfo['parte'] ?? 1);
        $totalPartes = (int)($grupoInfo['total_partes'] ?? 1);

        $nombreArchivoZip = $numero . '_' . $nombreSeguro;
        if ($totalPartes > 1) {
            $nombreArchivoZip .= '_parte_' . $parte;
        }
        $nombreArchivoZip .= '.png';

        $pngPath = $codigos_dir . 'tmp_' . $nombreArchivoZip;
        crearPNGTripleCodigos($grupoInfo['items'], $pngPath);
        $zip->addFile($pngPath, $nombreArchivoZip);
        $archivosTemporales[] = $pngPath;
    }

    $zip->close();

    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="todos_codigos_png.zip"');
    header('Content-Length: ' . filesize($tmpZip));
    readfile($tmpZip);

    foreach ($archivosTemporales as $archivoTmp) {
        @unlink($archivoTmp);
    }
    @unlink($tmpZip);
    exit;
}

// ========================= PNG CON TODOS LOS CÓDIGOS =========================
if (isset($_GET['action']) && $_GET['action'] === 'todos_codigos') {
    ob_clean();
    generarZIPTodosCodigosPNG($conn);
    exit;
}

// ========================= CONSULTAR PRODUCTOS =========================
$query = "
    SELECT
        p.*,
        EXISTS (
            SELECT 1
            FROM codigos_barras cb
            WHERE cb.producto_id = p.id
              AND UPPER(TRIM(cb.codigo)) NOT LIKE 'P%'
        ) AS tiene_codigo_legado
    FROM productos p
    WHERE p.activo = 1
    ORDER BY p.id DESC
";

$productos = [];
$res = $conn->query($query);
if ($res) {
    while ($row = $res->fetch_assoc()) {
        $row['atributos_array'] = $row['atributos'] ? json_decode($row['atributos'], true) : [];
        $row['tipo_codigo'] = normalizarTipoCodigoProducto(
            $row['tipo_inventario'] ?? 'producto',
            $row['tipo_codigo'] ?? 'multiple'
        );
        $productos[] = $row;
    }
    $res->free();
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

<link rel="stylesheet" href="css/ajustes_productos.css?v=<?= time() ?>">

<style>
.filtro-proveedor-wrap {
    min-width: 250px;
}

#filtroProveedor {
    height: 42px;
    border-radius: 12px;
    border: 1px solid #fed7aa;
    font-size: 0.9rem;
    color: #334155;
    box-shadow: 0 4px 14px rgba(249, 115, 22, 0.10);
}

#filtroProveedor:focus {
    border-color: #f97316;
    box-shadow: 0 0 0 0.2rem rgba(249, 115, 22, 0.18);
}

.btn-limpiar-filtros {
    border: 1px solid #f97316;
    background: linear-gradient(135deg, #f97316, #ea580c);
    color: #fff;
    height: 42px;
    padding: 0 18px;
    border-radius: 12px;
    font-weight: 700;
    transition: all 0.2s ease;
    white-space: nowrap;
    box-shadow: 0 8px 18px rgba(249, 115, 22, 0.22);
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 7px;
}

.btn-limpiar-filtros:hover {
    background: linear-gradient(135deg, #fb923c, #f97316);
    color: #fff;
    transform: translateY(-1px);
    box-shadow: 0 10px 22px rgba(249, 115, 22, 0.30);
}

.btn-limpiar-filtros:active {
    transform: translateY(0);
}

@media (max-width: 768px) {
    .toolbar-filtros {
        gap: 10px;
    }

    .filtro-proveedor-wrap,
    .buscador,
    .btn-limpiar-filtros {
        width: 100%;
    }
}
</style>

<div class="content-wrapper">
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
                        <i class="fas fa-sliders-h"></i> Ajustes de Productos
                    </li>
                </ol>
            </nav>
        </div>
        
        <div class="section-header">
            <div class="section-title">
                <i class="fas fa-sliders-h"></i>
                <span>Ajustes de Productos</span>
            </div>
            <div class="section-divider"></div>
            <p class="text-muted mt-2 mb-0">Gestiona el stock, precios y edita tus productos e insumos</p>
        </div>

        <div class="row">
            <div class="col-12">
                <div class="card card-outline shadow-sm flex-fill">
                    <div class="card-header">
                        <h3 class="card-title font-weight-bold mb-0">
                            <i class="fas fa-box mr-2" style="color: #f97316;"></i> Inventario
                        </h3>
                    </div>

                    <div class="card-body p-0">
                        <!-- Toolbar de filtros -->
                        <div class="toolbar-filtros">
                            <div class="filtros-botones">
                                <button class="btn-filtro-tabla active" data-filtro="todos">
                                    <i class="fas fa-th-large"></i> Todos
                                </button>
                                <button class="btn-filtro-tabla" data-filtro="producto">
                                    <i class="fas fa-box"></i> Productos
                                </button>
                                <button class="btn-filtro-tabla" data-filtro="insumo">
                                    <i class="fas fa-cubes"></i> Insumos
                                </button>
                            </div>
                            <div class="buscador">
                                <input type="text" id="buscadorInput" placeholder="Buscar por nombre, categoría o proveedor..." autocomplete="off">
                                <i class="fas fa-times limpiar-busqueda" id="limpiarBusqueda"></i>
                            </div>

                            <div class="filtro-proveedor-wrap">
                                <select id="filtroProveedor" class="form-control form-control-sm">
                                    <option value="todos">Todos los proveedores</option>
                                    <?php foreach ($proveedores as $prov): ?>
                                        <option value="<?= strtolower(htmlspecialchars($prov['nombre'], ENT_QUOTES)) ?>">
                                            <?= htmlspecialchars($prov['nombre']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                    <option value="sin proveedor">Sin proveedor</option>
                                </select>
                            </div>

                            <button type="button" class="btn-limpiar-filtros" id="btnLimpiarFiltros">
                                <i class="fas fa-eraser"></i> Borrar filtros
                            </button>
                        </div>

                        <div class="table-responsive">
                            <table class="table table-hover mb-0" id="tablaInventario">
                                <thead style="background: #f97316;">
                                    <tr>
                                        <th class="text-white">Tipo</th>
                                        <th class="text-white">Nombre</th>
                                        <th class="text-white">Categoría</th>
                                        <th class="text-white">Proveedor</th>
                                        <th class="text-white text-center">Imagen</th>
                                        <th class="text-white text-center">Cantidad</th>
                                        <th class="text-white text-right">Compra</th>
                                        <th class="text-white text-right">Venta</th>
                                        <th class="text-white text-center">Adquisición</th>
                                        <th class="text-white text-center">PNG</th>
                                        <th class="text-white text-center">Acciones</th>
                                    </tr>
                                </thead>
                                <tbody id="tablaBody">
                                    <?php if (empty($productos)): ?>
                                    <tr class="empty-row">
                                        <td colspan="11" class="text-center py-5">
                                            <div class="empty-state">
                                                <i class="fas fa-box-open fa-4x text-muted mb-3"></i>
                                                <h5 class="text-muted">No hay artículos registrados</h5>
                                                <p class="text-muted mb-3">Comienza agregando tu primer producto o insumo</p>
                                            </div>
                                        </td>
                                    </tr>
                                    <?php else: ?>
                                        <?php foreach ($productos as $p): 
                                            $badgeClass = $p['cantidad'] <= 0 ? 'badge-danger' : ($p['cantidad'] <= 5 ? 'badge-warning' : 'badge-success');
                                            $cantidadDisplay = $p['tipo_inventario'] == 'insumo' ? number_format($p['cantidad'], 2) . ' m' : number_format($p['cantidad'], 0) . ' pz';
                                        ?>
                                        <tr class="producto-fila" data-tipo="<?= $p['tipo_inventario'] ?>" data-tipo-codigo="<?= htmlspecialchars($p['tipo_codigo'] ?? 'multiple', ENT_QUOTES) ?>" data-nombre="<?= strtolower(htmlspecialchars($p['nombre'])) ?>" data-categoria="<?= strtolower(htmlspecialchars($p['categoria'] ?? '')) ?>" data-proveedor="<?= strtolower(htmlspecialchars($p['proveedor'] ?? '')) ?>">
                                            <td>
                                                <?php if ($p['tipo_inventario'] == 'producto'): ?>
                                                    <span class="badge-tipo badge-producto">
                                                        <i class="fas fa-box"></i> Producto
                                                    </span>
                                                <?php else: ?>
                                                    <span class="badge-tipo badge-insumo">
                                                        <i class="fas fa-cubes"></i> Insumo
                                                    </span>
                                                <?php endif; ?>
                                            </td>
                                            <td class="fw-bold">
                                                <?= htmlspecialchars($p['nombre']) ?>
                                                <?php if (!empty($p['atributos_array'])): ?>
                                                    <i class="fas fa-info-circle text-primary ml-1" data-toggle="tooltip" title="<?= htmlspecialchars(implode(', ', array_map(function($k, $v) { return "$k: $v"; }, array_keys($p['atributos_array']), $p['atributos_array']))) ?>"></i>
                                                <?php endif; ?>
                                            </td>
                                            <td><span class="badge-info"><?= htmlspecialchars($p['categoria']) ?></span></td>
                                            <td><?= htmlspecialchars($p['proveedor'] ?? '-') ?></td>
                                            <td class="text-center">
                                                <?php if ($p['imagen'] && file_exists($p['imagen'])): ?>
                                                    <img src="<?= $p['imagen'] ?>" class="img-thumbnail" alt="Producto">
                                                <?php else: ?>
                                                    <span class="badge-secondary">S. img</span>
                                                <?php endif; ?>
                                            </td>
                                            <td class="text-center"><span class="<?= $badgeClass ?>"><?= $cantidadDisplay ?></span></td>
                                            <td class="text-right">$<?= number_format($p['precio_compra'], 2) ?></td>
                                            <td class="text-right fw-bold text-success">
                                                <?php if ($p['tipo_inventario'] == 'producto'): ?>
                                                    $<?= number_format($p['precio_venta'], 2) ?>
                                                <?php else: ?>
                                                    <span class="text-muted">-</span>
                                                <?php endif; ?>
                                            </td>
                                            <!-- NUEVA COLUMNA: Tipo de Adquisición -->
                                            <td class="text-center">
                                                <?php if ($p['tipo_inventario'] == 'producto'): ?>
                                                    <?php if ($p['tipo_adquisicion'] == 'pagado'): ?>
                                                        <span class="badge-adquisicion badge-pagado">
                                                            <i class="fas fa-check-circle"></i> Pagado
                                                        </span>
                                                    <?php else: ?>
                                                        <span class="badge-adquisicion badge-concesion">
                                                            <i class="fas fa-handshake"></i> Por concesión
                                                        </span>
                                                    <?php endif; ?>
                                                <?php else: ?>
                                                    <span class="text-muted">—</span>
                                                <?php endif; ?>
                                            </td>
                                            <td class="text-center">
                                                <?php if ($p['tipo_inventario'] == 'producto'): ?>
                                                    <?php 
                                                        $png_file = 'uploads/codigos/producto_' . $p['id'] . '.png';
                                                        $zip_file = 'uploads/codigos/producto_' . $p['id'] . '.zip';
                                                    ?>
                                                    <?php if (file_exists($zip_file)): ?>
                                                        <a href="<?= $zip_file ?>?v=<?= filemtime($zip_file) ?>" class="btn btn-outline-success btn-sm" title="Descargar PNGs del producto, 3 etiquetas por imagen">
                                                            <i class="fas fa-file-archive"></i>
                                                        </a>
                                                    <?php elseif (file_exists($png_file)): ?>
                                                        <a href="<?= $png_file ?>?v=<?= filemtime($png_file) ?>" class="btn btn-outline-success btn-sm" target="_blank" title="Ver PNG del producto">
                                                            <i class="far fa-image"></i>
                                                        </a>
                                                    <?php else: ?>
                                                        <span class="text-muted">—</span>
                                                    <?php endif; ?>
                                                <?php else: ?>
                                                    <span class="text-muted">—</span>
                                                <?php endif; ?>
                                            </td>
                                            <td class="text-center">
                                                <div class="btn-group btn-group-sm">
                                                    <button class="btn btn-success" title="Agregar stock" onclick="abrirModalAgregarStock(<?= $p['id'] ?>, '<?= htmlspecialchars($p['nombre'], ENT_QUOTES) ?>', <?= $p['cantidad'] ?>, '<?= $p['tipo_inventario'] ?>')">
                                                        <i class="fas fa-plus-circle"></i>
                                                    </button>
                                                    <button class="btn btn-warning" title="Ajustar stock" onclick="abrirModalAjustarStock(<?= $p['id'] ?>, '<?= htmlspecialchars($p['nombre'], ENT_QUOTES) ?>', <?= $p['cantidad'] ?>, '<?= $p['tipo_inventario'] ?>')">
                                                        <i class="fas fa-sliders-h"></i>
                                                    </button>
                                                    <button class="btn btn-info" title="Editar" onclick="editarProducto(<?= (int)$p['id'] ?>, '<?= htmlspecialchars($p['tipo_codigo'] ?? 'multiple', ENT_QUOTES) ?>', <?= (int)($p['tiene_codigo_legado'] ?? 0) ?>)">
                                                        <i class="fas fa-edit"></i>
                                                    </button>
                                                    <button class="btn btn-danger" title="Eliminar" onclick="confirmarEliminar(<?= $p['id'] ?>)">
                                                        <i class="fas fa-trash"></i>
                                                    </button>
                                                </div>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                        <!-- VISTA MÓVIL - TARJETAS COMPACTAS -->
                        <div class="productos-grid-mobile" id="productosGridMobile">
                            <?php foreach ($productos as $p): 
                                $stockClass = $p['cantidad'] <= 0 ? 'stock-bajo' : ($p['cantidad'] <= 5 ? 'stock-bajo' : 'stock-alto');
                                $stockText = $p['tipo_inventario'] == 'insumo' ? number_format($p['cantidad'], 2) . ' m' : number_format($p['cantidad'], 0) . ' pz';
                            ?>
                            <div class="producto-card-mobile" data-tipo="<?= $p['tipo_inventario'] ?>" data-tipo-codigo="<?= htmlspecialchars($p['tipo_codigo'] ?? 'multiple', ENT_QUOTES) ?>" data-nombre="<?= strtolower(htmlspecialchars($p['nombre'], ENT_QUOTES)) ?>" data-categoria="<?= strtolower(htmlspecialchars($p['categoria'] ?? '', ENT_QUOTES)) ?>" data-proveedor="<?= strtolower(htmlspecialchars($p['proveedor'] ?? 'sin proveedor', ENT_QUOTES)) ?>">
                                
                                <!-- Fila 1: Tipo + Nombre + Stock -->
                                <div class="card-row-top">
                                    <span class="tipo-badge <?= $p['tipo_inventario'] == 'producto' ? 'tipo-producto' : 'tipo-insumo' ?>">
                                        <i class="fas <?= $p['tipo_inventario'] == 'producto' ? 'fa-box' : 'fa-cubes' ?>"></i>
                                        <?= $p['tipo_inventario'] == 'producto' ? 'Producto' : 'Insumo' ?>
                                    </span>
                                    <span class="nombre-producto-mobile" title="<?= htmlspecialchars($p['nombre']) ?>">
                                        <?= htmlspecialchars(substr($p['nombre'], 0, 30)) ?>
                                    </span>
                                    <span class="stock-badge-mobile <?= $stockClass ?>">
                                        <i class="fas fa-boxes"></i> <?= $stockText ?>
                                    </span>
                                </div>
                                
                                <!-- Fila 2: Categoría + Proveedor + Imagen -->
                                <div class="card-row-middle">
                                    <div class="info-group">
                                        <span class="info-item">
                                            <i class="fas fa-tag"></i>
                                            <span class="badge"><?= htmlspecialchars($p['categoria'] ?? 'Sin categoría') ?></span>
                                        </span>
                                        <span class="info-item">
                                            <i class="fas fa-truck"></i>
                                            <span class="badge"><?= htmlspecialchars($p['proveedor'] ?? 'Sin proveedor') ?></span>
                                        </span>
                                    </div>
                                    <div class="imagen-miniatura">
                                        <?php if ($p['imagen'] && file_exists($p['imagen'])): ?>
                                            <img src="<?= $p['imagen'] ?>" style="width: 100%; height: 100%; object-fit: cover; border-radius: 6px;">
                                        <?php else: ?>
                                            <i class="fas fa-image"></i>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                
                                <!-- Fila 3: Precios + Adquisición + Acciones -->
                                <div class="card-row-bottom">
                                    <div class="precios-group">
                                        <div class="precio-item">
                                            <span>Compra</span>
                                            <span class="precio-compra">$<?= number_format($p['precio_compra'], 2) ?></span>
                                        </div>
                                        <?php if ($p['tipo_inventario'] == 'producto'): ?>
                                        <div class="precio-item">
                                            <span>Venta</span>
                                            <span class="precio-venta">$<?= number_format($p['precio_venta'], 2) ?></span>
                                        </div>
                                        <?php endif; ?>
                                    </div>
                                    
                                    <?php if ($p['tipo_inventario'] == 'producto'): ?>
                                    <span class="adquisicion-badge <?= $p['tipo_adquisicion'] == 'pagado' ? 'adq-pagado' : 'adq-concesion' ?>">
                                        <i class="fas <?= $p['tipo_adquisicion'] == 'pagado' ? 'fa-check-circle' : 'fa-handshake' ?>"></i>
                                        <?= $p['tipo_adquisicion'] == 'pagado' ? 'Pagado' : 'Concesión' ?>
                                    </span>
                                    <?php endif; ?>
                                    
                                    <div class="acciones-group">
                                        <?php if ($p['tipo_inventario'] == 'producto'): ?>
                                        <button class="accion-btn pdf-link" onclick="window.open('uploads/codigos/producto_<?= $p['id'] ?>.png', '_blank')">
                                            <i class="far fa-image"></i>
                                        </button>
                                        <?php endif; ?>
                                        <button class="accion-btn agregar" onclick="abrirModalAgregarStock(<?= $p['id'] ?>, '<?= htmlspecialchars($p['nombre'], ENT_QUOTES) ?>', <?= $p['cantidad'] ?>, '<?= $p['tipo_inventario'] ?>')">
                                            <i class="fas fa-plus"></i>
                                        </button>
                                        <button class="accion-btn ajustar" onclick="abrirModalAjustarStock(<?= $p['id'] ?>, '<?= htmlspecialchars($p['nombre'], ENT_QUOTES) ?>', <?= $p['cantidad'] ?>, '<?= $p['tipo_inventario'] ?>')">
                                            <i class="fas fa-sliders-h"></i>
                                        </button>
                                        <button class="accion-btn editar" onclick="editarProducto(<?= (int)$p['id'] ?>, '<?= htmlspecialchars($p['tipo_codigo'] ?? 'multiple', ENT_QUOTES) ?>', <?= (int)($p['tiene_codigo_legado'] ?? 0) ?>)">
                                            <i class="fas fa-edit"></i>
                                        </button>
                                        <button class="accion-btn eliminar" onclick="confirmarEliminar(<?= $p['id'] ?>)">
                                            <i class="fas fa-trash"></i>
                                        </button>
                                    </div>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>

                        <!-- Mensaje sin resultados -->
                        <div id="sinResultadosMsg" class="sin-resultados" style="display: none;">
                            <i class="fas fa-search"></i>
                            <h5>No se encontraron resultados</h5>
                            <p>No hay productos o insumos que coincidan con los filtros seleccionados.</p>
                        </div>

                        <!-- PAGINACIÓN -->
                        <div class="pagination-wrapper" id="paginacionWrapper">
                            <div class="pagination-info">
                                Mostrando <span id="paginacion_desde">0</span> a <span id="paginacion_hasta">0</span> de <span id="paginacion_total">0</span> registros
                            </div>
                            <nav>
                                <ul class="pagination" id="paginacion"></ul>
                            </nav>
                        </div>

                        <div class="p-3 d-flex justify-content-between align-items-center border-top flex-wrap" style="gap:10px;">
                            <div class="d-flex flex-wrap" style="gap:8px;">
                                <a href="?action=todos_codigos" target="_blank" class="btn btn-primary btn-sm">
                                    <i class="fas fa-file-archive mr-1"></i> PNG con todos los códigos
                                </a>

                                <form method="POST" id="formRegenerarCodigos" class="d-inline">
                                    <input type="hidden" name="action" value="regenerate_all_codes">
                                    <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>">
                                    <button type="button" class="btn btn-warning btn-sm" onclick="confirmarRegenerarCodigos()">
                                        <i class="fas fa-sync-alt mr-1"></i> Regenerar códigos sin duplicados
                                    </button>
                                </form>
                            </div>
                            <div>
                                <a href="historial_stock.php" class="btn btn-info btn-sm">
                                    <i class="fas fa-history mr-1"></i> Ver historial completo
                                </a>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- MODAL AGREGAR STOCK -->
<div class="modal fade" id="modalAgregarStock" tabindex="-1">
    <div class="modal-dialog">
        <form method="POST" class="modal-content" id="formAgregarStock">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-plus-circle mr-2"></i> Agregar Stock</h5>
                <button type="button" class="close text-white" data-dismiss="modal">&times;</button>
            </div>
            <div class="modal-body">
                <input type="hidden" name="action" value="add_stock">
                <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>">
                <input type="hidden" id="stock_producto_id" name="producto_id">
                <input type="hidden" id="stock_tipo_inventario" name="tipo_inventario">
                <div class="text-center mb-3" id="stock_producto_info"></div>
                <div class="form-group">
                    <label>Cantidad a agregar <span class="text-danger">*</span></label>
                    <div class="input-group">
                        <input type="number" id="stock_cantidad" name="cantidad" class="form-control form-control-lg" min="0.01" step="any" required>
                        <div class="input-group-append"><span class="input-group-text unidad-label" id="stock_unidad">pz</span></div>
                    </div>
                    <small class="text-muted">Stock actual: <span id="stock_actual_valor">0</span></small>
                </div>
                <div class="form-group">
                    <label>Nota (opcional)</label>
                    <textarea id="stock_nota" name="nota" class="form-control" rows="2" placeholder="Ej. Nueva compra a proveedor, etc."></textarea>
                </div>
                <div class="alert alert-info"><i class="fas fa-info-circle mr-2"></i> La cantidad se sumará al stock actual.</div>
            </div>
            <div class="modal-footer">
                <button type="submit" class="btn btn-primary">Agregar Stock</button>
                <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancelar</button>
            </div>
        </form>
    </div>
</div>

<!-- MODAL AJUSTAR STOCK -->
<div class="modal fade" id="modalAjustarStock" tabindex="-1">
    <div class="modal-dialog">
        <form method="POST" class="modal-content" id="formAjustarStock">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-sliders-h mr-2"></i> Ajustar Stock</h5>
                <button type="button" class="close text-white" data-dismiss="modal">&times;</button>
            </div>
            <div class="modal-body">
                <input type="hidden" name="action" value="adjust_stock">
                <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>">
                <input type="hidden" id="ajuste_producto_id" name="producto_id">
                <input type="hidden" id="ajuste_tipo_inventario" name="tipo_inventario">
                <div class="text-center mb-3" id="ajuste_producto_info"></div>
                <div class="form-group">
                    <label>Stock actual</label>
                    <div class="input-group">
                        <input type="number" id="ajuste_stock_actual" class="form-control" readonly disabled>
                        <div class="input-group-append"><span class="input-group-text unidad-label" id="ajuste_unidad_actual">pz</span></div>
                    </div>
                </div>
                <div class="form-group">
                    <label>Nueva cantidad <span class="text-danger">*</span></label>
                    <div class="input-group">
                        <input type="number" id="ajuste_nueva_cantidad" name="nueva_cantidad" class="form-control form-control-lg" min="0" step="any" required>
                        <div class="input-group-append"><span class="input-group-text unidad-label" id="ajuste_unidad_nueva">pz</span></div>
                    </div>
                    <small class="text-muted" id="ajuste_diferencia">Diferencia: 0</small>
                </div>
                <div class="form-group">
                    <label>Razón del ajuste <span class="text-danger">*</span></label>
                    <textarea id="ajuste_razon" name="razon_ajuste" class="form-control" rows="2" placeholder="Ej. Error en conteo, producto dañado, corrección, etc." required></textarea>
                </div>
                <div class="alert alert-warning"><i class="fas fa-exclamation-triangle mr-2"></i> Usa esta opción SOLO para corregir errores.</div>
            </div>
            <div class="modal-footer">
                <button type="submit" class="btn btn-primary">Aplicar Ajuste</button>
                <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancelar</button>
            </div>
        </form>
    </div>
</div>

<!-- MODAL EDITAR PRODUCTO - VERSIÓN COMPACTA -->
<div class="modal fade" id="modalEditar" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content" style="overflow: hidden; box-shadow: 0 10px 30px rgba(0,0,0,0.2);">
            
            <!-- HEADER COMPACTO -->
            <div class="modal-header" style="background: linear-gradient(135deg, #f97316, #ea580c); border: none; padding: 12px 20px;">
                <div>
                    <h5 class="modal-title" style="color: white; font-size: 1.1rem; font-weight: 600;">
                        <i class="fas fa-edit mr-2"></i> Editar Producto / Insumo
                    </h5>
                </div>
                <button type="button" class="close text-white" data-dismiss="modal" style="font-size: 1.5rem;">&times;</button>
            </div>

            <form method="POST" enctype="multipart/form-data" id="formEditarProducto">
                <div class="modal-body" style="padding: 20px; background: #f8fafc;">
                    <input type="hidden" name="action" value="update">
                    <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>">
                    <input type="hidden" id="edit_id" name="id">
                    <input type="hidden" id="edit_tipo_inventario" name="tipo_inventario">
                    <input type="hidden" id="edit_categoria_nueva_input" name="categoria_nueva">
                    <input type="hidden" id="edit_proveedor_nuevo_input" name="proveedor_nuevo">

                    <!-- STOCK ACTUAL - COMPACTO -->
                    <div class="d-flex align-items-center mb-4" style="background: #eff6ff; border-left: 4px solid #f97316; border-radius: 10px; padding: 10px 15px;">
                        <div class="rounded-circle bg-white p-2 mr-3">
                            <i class="fas fa-boxes" style="color: #f97316; font-size: 1rem;"></i>
                        </div>
                        <div>
                            <small style="color: #475569;">Stock actual</small>
                            <strong id="edit_cantidad_actual" style="font-size: 1.2rem; color: #f97316; margin-left: 5px;">0</strong>
                        </div>
                    </div>

                    <div class="row">
                        <!-- COLUMNA IZQUIERDA -->
                        <div class="col-md-6">
                            <div class="card shadow-sm border-0 mb-3" style="border-radius: 12px;">
                                <div class="card-header bg-white border-0 pt-2 pb-0 px-3">
                                    <h6 style="color: #f97316; font-size: 0.85rem; margin: 0;">
                                        <i class="fas fa-info-circle mr-1"></i> Información Básica
                                    </h6>
                                </div>
                                <div class="card-body pt-2 px-3 pb-3">
                                    <div class="form-group mb-2">
                                        <label style="font-size: 0.75rem; font-weight: 600;">Nombre *</label>
                                        <input type="text" id="edit_nombre" name="nombre" class="form-control form-control-sm" style="border-radius: 8px; font-size: 0.85rem;" required>
                                    </div>

                                    <div class="form-group mb-2">
                                        <label style="font-size: 0.75rem; font-weight: 600;">Categoría *</label>
                                        <select id="edit_categoria" name="categoria" class="form-control form-control-sm" required>
                                            <option value="">Seleccionar categoría</option>
                                            <?php foreach ($categorias as $cat): ?>
                                                <option value="<?= htmlspecialchars($cat) ?>"><?= htmlspecialchars($cat) ?></option>
                                            <?php endforeach; ?>
                                            <option value="__NUEVA__">+ Crear nueva categoría</option>
                                        </select>
                                        <input type="text" id="edit_categoria_nueva" class="form-control form-control-sm mt-1" style="border-radius: 8px; font-size: 0.85rem; display: none;" placeholder="Nueva categoría">
                                    </div>

                                    <div class="form-group mb-2">
                                        <label style="font-size: 0.75rem; font-weight: 600;">Proveedor</label>
                                        <select id="edit_proveedor" name="proveedor" class="form-control form-control-sm" style="border-radius: 8px; font-size: 0.85rem;">
                                            <option value="">Seleccionar proveedor</option>
                                            <?php foreach ($proveedores as $prov): ?>
                                                <option value="<?= $prov['id'] ?>"><?= htmlspecialchars($prov['nombre']) ?></option>
                                            <?php endforeach; ?>
                                            <option value="__NUEVO__">+ Crear nuevo proveedor</option>
                                        </select>
                                        <input type="text" id="edit_proveedor_nuevo" name="proveedor_nuevo" class="form-control form-control-sm mt-1" style="border-radius: 8px; font-size: 0.85rem; display: none;" placeholder="Nuevo proveedor">
                                    </div>

                                    <div class="form-group">
                                        <label style="font-size: 0.75rem; font-weight: 600;">Imagen actual</label>
                                        <div id="imagen_preview_container" style="text-align: center; margin-bottom: 10px;">
                                            <img id="imagen_preview" src="" alt="Vista previa" style="max-width: 100%; max-height: 150px; border-radius: 8px; display: none; object-fit: cover; border: 1px solid #e2e8f0; padding: 5px;">
                                        </div>
                                        <label style="font-size: 0.75rem; font-weight: 600;">Cambiar imagen</label>
                                        <input type="file" name="imagen" id="edit_imagen" class="form-control-file form-control-sm" style="font-size: 0.8rem; padding: 4px;" accept="image/*">
                                        <small class="text-muted" style="font-size: 0.7rem;">Dejar en blanco para mantener actual. Tamaño recomendado: 500x500px</small>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- COLUMNA DERECHA -->
                        <div class="col-md-6">
                            <div class="card shadow-sm border-0 mb-3" style="border-radius: 12px;">
                                <div class="card-header bg-white border-0 pt-2 pb-0 px-3">
                                    <h6 style="color: #f97316; font-size: 0.85rem; margin: 0;">
                                        <i class="fas fa-chart-line mr-1"></i> Precios y Configuración
                                    </h6>
                                </div>
                                <div class="card-body pt-2 px-3 pb-3">
                                    <div class="row">
                                        <div class="col-6">
                                            <div class="form-group mb-2">
                                                <label style="font-size: 0.75rem; font-weight: 600;">Precio compra *</label>
                                                <div class="input-group input-group-sm">
                                                    <div class="input-group-prepend">
                                                        <span class="input-group-text" style="padding: 0 8px; font-size: 0.85rem;">$</span>
                                                    </div>
                                                    <input type="number" step="0.01" id="edit_precio_compra" name="precio_compra" class="form-control form-control-sm" style="font-size: 0.85rem;" required>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="col-6">
                                            <div class="form-group mb-2" id="edit_precio_venta_group">
                                                <label style="font-size: 0.75rem; font-weight: 600;">Precio venta</label>
                                                <div class="input-group input-group-sm">
                                                    <div class="input-group-prepend">
                                                        <span class="input-group-text" style="padding: 0 8px; font-size: 0.85rem;">$</span>
                                                    </div>
                                                    <input type="number" step="0.01" id="edit_precio_venta" name="precio_venta" class="form-control form-control-sm" style="font-size: 0.85rem;">
                                                </div>
                                            </div>
                                        </div>
                                    </div>

                                    <div class="form-group mb-2" id="edit_tipo_codigo_group">
    <label style="font-size: 0.75rem; font-weight: 600;">Tipo de código</label>
    <select id="edit_tipo_codigo" name="tipo_codigo" class="form-control form-control-sm">
        <option value="unico">Código único (un código para todo el producto)</option>
        <option value="multiple">Múltiple (un código por unidad)</option>
    </select>
    <input type="hidden" id="edit_tipo_codigo_respaldo" name="tipo_codigo_respaldo" value="">
    <input type="hidden" id="edit_tipo_codigo_forzado" name="tipo_codigo_forzado" value="">
    <input type="hidden" id="edit_tipo_codigo_tocado" name="tipo_codigo_tocado" value="0">
    <small id="edit_tipo_codigo_legado_aviso" class="text-muted" style="display:none;">
        <i class="fas fa-lock mr-1"></i>
        Este artículo usa un código histórico sin P. Su código y tipo quedan protegidos.
    </small>
</div>

                                    <div class="form-group" id="edit_adquisicion_group">
                                        <label style="font-size: 0.75rem; font-weight: 600;">Adquisición</label>
                                        <div class="d-flex" style="gap: 8px;">
                                            <label class="btn btn-outline-success btn-sm" style="flex: 1; cursor: pointer; text-align: center; margin: 0; padding: 5px; font-size: 0.8rem;">
                                                <input type="radio" name="tipo_adquisicion" value="pagado" class="mr-1"> Pagado
                                            </label>
                                            <label class="btn btn-outline-warning btn-sm" style="flex: 1; cursor: pointer; text-align: center; margin: 0; padding: 5px; font-size: 0.8rem;">
                                                <input type="radio" name="tipo_adquisicion" value="concesion" class="mr-1"> Concesión
                                            </label>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- ATRIBUTOS COMPACTOS -->
                            <div id="edit_atributos_section" class="card shadow-sm border-0" style="border-radius: 12px;">
                                <div class="card-header bg-white border-0 pt-2 pb-0 px-3">
                                    <h6 style="color: #f97316; font-size: 0.85rem; margin: 0;">
                                        <i class="fas fa-cog mr-1"></i> Atributos <small class="text-muted">(opcional)</small>
                                    </h6>
                                </div>
                                <div class="card-body pt-2 px-3 pb-3">
                                    <div class="row">
                                        <div class="col-6 mb-1">
                                            <input type="text" id="edit_marca" name="marca" class="form-control form-control-sm" placeholder="Marca" style="border-radius: 6px; font-size: 0.8rem;">
                                        </div>
                                        <div class="col-6 mb-1">
                                            <input type="text" id="edit_color" name="color" class="form-control form-control-sm" placeholder="Color" style="border-radius: 6px; font-size: 0.8rem;">
                                        </div>
                                        <div class="col-6">
                                            <input type="text" id="edit_talla" name="talla" class="form-control form-control-sm" placeholder="Talla" style="border-radius: 6px; font-size: 0.8rem;">
                                        </div>
                                        <div class="col-6">
                                            <input type="text" id="edit_material" name="material" class="form-control form-control-sm" placeholder="Material" style="border-radius: 6px; font-size: 0.8rem;">
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- FOOTER COMPACTO -->
                <div class="modal-footer" style="background: white; border-top: 1px solid #e2e8f0; padding: 12px 20px;">
                    <button type="button" class="btn btn-secondary btn-sm" data-dismiss="modal" style="border-radius: 6px;">Cancelar</button>
                    <button type="submit" class="btn btn-primary btn-sm" style="background: #f97316; border: none; border-radius: 6px;">Guardar Cambios</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Formulario oculto para eliminar -->
<form id="formEliminar" method="POST" style="display:none;">
    <input type="hidden" name="action" value="delete">
    <input type="hidden" id="delete_id" name="id">
    <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>">
</form>

<script src="https://code.jquery.com/jquery-3.6.4.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@4.6.2/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

<script>
// ===== FILTROS Y BÚSQUEDA - VERSIÓN COMPLETA (TABLA + TARJETAS MÓVIL) =====
let filtroActual = 'todos';
let busquedaActual = '';
let proveedorActual = 'todos';
const FILTROS_STORAGE_KEY = 'ajustes_productos_filtros';
const PAGINA_STORAGE_KEY = 'ajustes_productos_pagina_actual';
let filasVisibles = [];
let tarjetasVisibles = [];
let paginaActual = 1;
let paginaGuardadaPendiente = 1;
let restaurarPaginaEnCarga = true;
let filasPorPagina = 10;

// Detectar si es móvil
function isMobile() {
    return window.innerWidth <= 768;
}

function normalizarTexto(texto) {
    return (texto || '')
        .toString()
        .toLowerCase()
        .normalize('NFD')
        .replace(/[\u0300-\u036f]/g, '')
        .trim();
}

function guardarFiltrosEnStorage() {
    // Solo se conserva el filtro de proveedor al recargar o actualizar producto.
    // La búsqueda por artículo se limpia siempre al cargar la página.
    localStorage.setItem(FILTROS_STORAGE_KEY, JSON.stringify({
        proveedor: proveedorActual
    }));
}

function guardarPaginaActualEnStorage() {
    localStorage.setItem(PAGINA_STORAGE_KEY, String(Math.max(1, parseInt(paginaActual, 10) || 1)));
}

function leerPaginaGuardada() {
    const pagina = parseInt(localStorage.getItem(PAGINA_STORAGE_KEY) || '1', 10);
    return Number.isFinite(pagina) && pagina > 0 ? pagina : 1;
}

function cargarFiltrosDesdeStorage() {
    try {
        const filtrosGuardados = JSON.parse(localStorage.getItem(FILTROS_STORAGE_KEY) || '{}');
        filtroActual = 'todos';
        busquedaActual = '';
        proveedorActual = filtrosGuardados.proveedor || 'todos';
        paginaGuardadaPendiente = leerPaginaGuardada();
    } catch (e) {
        filtroActual = 'todos';
        busquedaActual = '';
        proveedorActual = 'todos';
        paginaGuardadaPendiente = 1;
    }
}

function pintarFiltrosEnPantalla() {
    document.querySelectorAll('.btn-filtro-tabla').forEach(btn => {
        btn.classList.toggle('active', btn.getAttribute('data-filtro') === filtroActual);
    });

    const buscadorInput = document.getElementById('buscadorInput');
    const limpiarBusqueda = document.getElementById('limpiarBusqueda');
    if (buscadorInput) buscadorInput.value = busquedaActual;
    if (limpiarBusqueda) limpiarBusqueda.style.display = busquedaActual ? 'block' : 'none';

    const filtroProveedor = document.getElementById('filtroProveedor');
    if (filtroProveedor) {
        const existeOpcion = Array.from(filtroProveedor.options).some(opt => opt.value === proveedorActual);
        filtroProveedor.value = existeOpcion ? proveedorActual : 'todos';
        proveedorActual = filtroProveedor.value;
    }
}

function borrarFiltros() {
    filtroActual = 'todos';
    busquedaActual = '';
    proveedorActual = 'todos';
    paginaActual = 1;
    paginaGuardadaPendiente = 1;
    restaurarPaginaEnCarga = false;
    localStorage.removeItem(FILTROS_STORAGE_KEY);
    localStorage.removeItem(PAGINA_STORAGE_KEY);
    pintarFiltrosEnPantalla();
    aplicarFiltros(false);
}

function aplicarFiltros(debeGuardar = true) {
    const esMovil = isMobile();
    const busquedaNormalizada = normalizarTexto(busquedaActual);
    const proveedorNormalizado = normalizarTexto(proveedorActual);
    
    // ===== FILTRAR TABLA (DESKTOP) =====
    const filasTabla = document.querySelectorAll('#tablaBody .producto-fila');
    let visiblesTabla = 0;
    
    filasTabla.forEach(fila => {
        const tipo = fila.getAttribute('data-tipo');
        const nombre = normalizarTexto(fila.getAttribute('data-nombre') || '');
        const categoria = normalizarTexto(fila.getAttribute('data-categoria') || '');
        const proveedor = normalizarTexto(fila.getAttribute('data-proveedor') || 'sin proveedor');
        
        let mostrar = true;
        
        if (filtroActual !== 'todos') {
            if (tipo !== filtroActual) {
                mostrar = false;
            }
        }
        
        if (mostrar && proveedorNormalizado !== 'todos') {
            if (proveedor !== proveedorNormalizado) {
                mostrar = false;
            }
        }

        if (mostrar && busquedaNormalizada !== '') {
            const texto = nombre + ' ' + categoria + ' ' + proveedor;
            if (!texto.includes(busquedaNormalizada)) {
                mostrar = false;
            }
        }
        
        if (mostrar) {
            fila.style.display = '';
            visiblesTabla++;
        } else {
            fila.style.display = 'none';
        }
    });
    
    // ===== FILTRAR TARJETAS (MÓVIL) =====
    const tarjetas = document.querySelectorAll('.producto-card-mobile');
    let visiblesTarjetas = 0;
    
    tarjetas.forEach(tarjeta => {
        const tipo = tarjeta.getAttribute('data-tipo');
        const nombre = normalizarTexto(tarjeta.getAttribute('data-nombre') || '');
        const categoria = normalizarTexto(tarjeta.getAttribute('data-categoria') || '');
        const proveedor = normalizarTexto(tarjeta.getAttribute('data-proveedor') || 'sin proveedor');
        
        let mostrar = true;
        
        if (filtroActual !== 'todos') {
            if (tipo !== filtroActual) {
                mostrar = false;
            }
        }
        
        if (mostrar && proveedorNormalizado !== 'todos') {
            if (proveedor !== proveedorNormalizado) {
                mostrar = false;
            }
        }

        if (mostrar && busquedaNormalizada !== '') {
            const texto = nombre + ' ' + categoria + ' ' + proveedor;
            if (!texto.includes(busquedaNormalizada)) {
                mostrar = false;
            }
        }
        
        if (mostrar) {
            tarjeta.style.display = '';
            visiblesTarjetas++;
        } else {
            tarjeta.style.display = 'none';
        }
    });
    
    const sinResultadosMsg = document.getElementById('sinResultadosMsg');
    const paginacionWrapper = document.getElementById('paginacionWrapper');
    const totalVisibles = esMovil ? visiblesTarjetas : visiblesTabla;
    
    if (totalVisibles === 0) {
        if (sinResultadosMsg) sinResultadosMsg.style.display = 'block';
        if (paginacionWrapper) paginacionWrapper.style.display = 'none';
        document.getElementById('paginacion_desde').textContent = '0';
        document.getElementById('paginacion_hasta').textContent = '0';
        document.getElementById('paginacion_total').textContent = '0';
    } else {
        if (sinResultadosMsg) sinResultadosMsg.style.display = 'none';
        if (paginacionWrapper) paginacionWrapper.style.display = 'flex';
        
        if (esMovil) {
            tarjetasVisibles = Array.from(tarjetas).filter(t => t.style.display !== 'none');
        } else {
            filasVisibles = Array.from(filasTabla).filter(f => f.style.display !== 'none');
        }

        const elementosVisibles = esMovil ? tarjetasVisibles : filasVisibles;
        const totalPaginas = Math.max(1, Math.ceil(elementosVisibles.length / filasPorPagina));

        if (restaurarPaginaEnCarga) {
            paginaActual = Math.min(Math.max(1, paginaGuardadaPendiente), totalPaginas);
            restaurarPaginaEnCarga = false;
        } else {
            paginaActual = 1;
        }

        actualizarPaginacion();
    }

    if (debeGuardar) {
        guardarFiltrosEnStorage();
    }
}

function actualizarPaginacion() {
    const esMovil = isMobile();
    const elementos = esMovil ? tarjetasVisibles : filasVisibles;
    const totalElementos = elementos.length;
    const totalPaginas = Math.ceil(totalElementos / filasPorPagina);
    
    // Ocultar todos los elementos
    if (esMovil) {
        document.querySelectorAll('.producto-card-mobile').forEach(el => el.style.display = 'none');
    } else {
        document.querySelectorAll('#tablaBody .producto-fila').forEach(el => el.style.display = 'none');
    }
    
    // Mostrar solo los de la página actual
    const inicio = (paginaActual - 1) * filasPorPagina;
    const fin = Math.min(inicio + filasPorPagina, totalElementos);
    for (let i = inicio; i < fin; i++) {
        if (elementos[i]) elementos[i].style.display = '';
    }
    
    // Actualizar contadores
    document.getElementById('paginacion_desde').textContent = totalElementos > 0 ? inicio + 1 : 0;
    document.getElementById('paginacion_hasta').textContent = fin;
    document.getElementById('paginacion_total').textContent = totalElementos;
    
    const paginacionUl = document.getElementById('paginacion');
    paginacionUl.innerHTML = '';
    
    if (totalPaginas === 0) return;
    
    // Botón anterior
    const liPrev = document.createElement('li');
    liPrev.className = `page-item ${paginaActual === 1 ? 'disabled' : ''}`;
    liPrev.innerHTML = `<a class="page-link" href="#" ${paginaActual !== 1 ? 'onclick="cambiarPagina(' + (paginaActual - 1) + ')"' : ''}>«</a>`;
    paginacionUl.appendChild(liPrev);
    
    // Números de página
    const maxBotones = 5;
    let inicioPaginas = Math.max(1, paginaActual - Math.floor(maxBotones / 2));
    let finPaginas = Math.min(totalPaginas, inicioPaginas + maxBotones - 1);
    
    if (inicioPaginas > 1) {
        const liFirst = document.createElement('li');
        liFirst.className = 'page-item';
        liFirst.innerHTML = '<a class="page-link" onclick="cambiarPagina(1)">1</a>';
        paginacionUl.appendChild(liFirst);
        if (inicioPaginas > 2) {
            const liDots = document.createElement('li');
            liDots.className = 'page-item disabled';
            liDots.innerHTML = '<span class="page-link">...</span>';
            paginacionUl.appendChild(liDots);
        }
    }
    
    for (let i = inicioPaginas; i <= finPaginas; i++) {
        const li = document.createElement('li');
        li.className = `page-item ${i === paginaActual ? 'active' : ''}`;
        li.innerHTML = `<a class="page-link" onclick="cambiarPagina(${i})">${i}</a>`;
        paginacionUl.appendChild(li);
    }
    
    if (finPaginas < totalPaginas) {
        if (finPaginas < totalPaginas - 1) {
            const liDots = document.createElement('li');
            liDots.className = 'page-item disabled';
            liDots.innerHTML = '<span class="page-link">...</span>';
            paginacionUl.appendChild(liDots);
        }
        const liLast = document.createElement('li');
        liLast.className = 'page-item';
        liLast.innerHTML = `<a class="page-link" onclick="cambiarPagina(${totalPaginas})">${totalPaginas}</a>`;
        paginacionUl.appendChild(liLast);
    }
    
    // Botón siguiente
    const liNext = document.createElement('li');
    liNext.className = `page-item ${paginaActual === totalPaginas ? 'disabled' : ''}`;
    liNext.innerHTML = `<a class="page-link" ${paginaActual !== totalPaginas ? 'onclick="cambiarPagina(' + (paginaActual + 1) + ')"' : ''}>»</a>`;
    paginacionUl.appendChild(liNext);
}

function cambiarPagina(pagina) {
    paginaActual = pagina;
    guardarPaginaActualEnStorage();
    actualizarPaginacion();
    window.scrollTo({ top: 0, behavior: 'smooth' });
}

// ===== FUNCIONES DE MODALES =====
function abrirModalAgregarStock(id, nombre, cantidadActual, tipoInventario) {
    document.getElementById('stock_producto_id').value = id;
    document.getElementById('stock_tipo_inventario').value = tipoInventario;
    document.getElementById('stock_unidad').textContent = tipoInventario === 'insumo' ? 'm' : 'pz';
    document.getElementById('stock_actual_valor').textContent = cantidadActual;
    document.getElementById('stock_producto_info').innerHTML = `<div class="card border-success"><div class="card-body py-2"><h6 class="mb-1 font-weight-bold">${escapeHtml(nombre)}</h6><small class="text-muted">${tipoInventario === 'producto' ? 'Producto' : 'Insumo'}</small></div></div>`;
    document.getElementById('stock_cantidad').value = '';
    document.getElementById('stock_nota').value = '';
    $('#modalAgregarStock').modal('show');
}

function abrirModalAjustarStock(id, nombre, cantidadActual, tipoInventario) {
    document.getElementById('ajuste_producto_id').value = id;
    document.getElementById('ajuste_tipo_inventario').value = tipoInventario;
    document.getElementById('ajuste_unidad_actual').textContent = tipoInventario === 'insumo' ? 'm' : 'pz';
    document.getElementById('ajuste_unidad_nueva').textContent = tipoInventario === 'insumo' ? 'm' : 'pz';
    document.getElementById('ajuste_stock_actual').value = cantidadActual;
    document.getElementById('ajuste_nueva_cantidad').value = cantidadActual;
    document.getElementById('ajuste_razon').value = '';
    document.getElementById('ajuste_producto_info').innerHTML = `<div class="card border-warning"><div class="card-body py-2"><h6 class="mb-1 font-weight-bold">${escapeHtml(nombre)}</h6><small class="text-muted">${tipoInventario === 'producto' ? 'Producto' : 'Insumo'}</small></div></div>`;
    // Remover event listener anterior para evitar duplicados
    const nuevaCantidadInput = document.getElementById('ajuste_nueva_cantidad');
    nuevaCantidadInput.removeEventListener('input', window._calcularDiferenciaHandler);
    window._calcularDiferenciaHandler = function() { calcularDiferencia(cantidadActual, this.value); };
    nuevaCantidadInput.addEventListener('input', window._calcularDiferenciaHandler);
    $('#modalAjustarStock').modal('show');
}

function calcularDiferencia(actual, nueva) {
    const dif = parseFloat(nueva) - parseFloat(actual);
    const span = document.getElementById('ajuste_diferencia');
    if (dif > 0) span.innerHTML = `Diferencia: <span class="text-success">+${dif.toFixed(2)}</span> (se agregará stock)`;
    else if (dif < 0) span.innerHTML = `Diferencia: <span class="text-danger">${dif.toFixed(2)}</span> (se quitará stock)`;
    else span.innerHTML = `Diferencia: 0 (sin cambios)`;
}

// Manejo de selects dinámicos
document.addEventListener('DOMContentLoaded', function() {
    const categoriaSelect = document.getElementById('edit_categoria');
    const categoriaNueva = document.getElementById('edit_categoria_nueva');
    const proveedorSelect = document.getElementById('edit_proveedor');
    const proveedorNuevo = document.getElementById('edit_proveedor_nuevo');

    if (categoriaSelect && categoriaNueva) {
        categoriaSelect.addEventListener('change', function() {
            if (this.value === '__NUEVA__') {
                categoriaNueva.style.display = 'block';
                categoriaNueva.focus();
            } else {
                categoriaNueva.style.display = 'none';
                categoriaNueva.value = '';
            }
        });
    }

    if (proveedorSelect && proveedorNuevo) {
        proveedorSelect.addEventListener('change', function() {
            if (this.value === '__NUEVO__') {
                proveedorNuevo.style.display = 'block';
                proveedorNuevo.focus();
                this.value = '';
            } else {
                proveedorNuevo.style.display = 'none';
                proveedorNuevo.value = '';
            }
        });
    }

    const tipoCodigoSelectModal = document.getElementById('edit_tipo_codigo');
    if (tipoCodigoSelectModal) {
        tipoCodigoSelectModal.addEventListener('change', function() {
            const valorSeguro = normalizarTipoCodigoJS(this.value, 'multiple');
            this.value = valorSeguro;
            const respaldo = document.getElementById('edit_tipo_codigo_respaldo');
            const forzado = document.getElementById('edit_tipo_codigo_forzado');
            if (respaldo) respaldo.value = valorSeguro;
            if (forzado) forzado.value = valorSeguro;
            const tocado = document.getElementById('edit_tipo_codigo_tocado');
            if (tocado) tocado.value = '1';
        });
    }

    const formEditar = document.getElementById('formEditarProducto');
    if (formEditar) {
        formEditar.addEventListener('submit', function() {
            guardarPaginaActualEnStorage();

            const tipoCodigoSelect = document.getElementById('edit_tipo_codigo');
            const tipoCodigoRespaldo = document.getElementById('edit_tipo_codigo_respaldo');
            const tipoCodigoForzado = document.getElementById('edit_tipo_codigo_forzado');
            if (tipoCodigoSelect) {
                const valorSeguro = normalizarTipoCodigoJS(tipoCodigoSelect.value, 'multiple');
                tipoCodigoSelect.value = valorSeguro;
                if (tipoCodigoRespaldo) tipoCodigoRespaldo.value = valorSeguro;
                if (tipoCodigoForzado) tipoCodigoForzado.value = valorSeguro;
                const tipoCodigoTocado = document.getElementById('edit_tipo_codigo_tocado');
                if (tipoCodigoTocado) tipoCodigoTocado.value = '1';
            }

            const categoriaSelect = document.getElementById('edit_categoria');
            const categoriaNueva = document.getElementById('edit_categoria_nueva');
            const categoriaHidden = document.getElementById('edit_categoria_nueva_input');
            
            if (categoriaSelect.value === '__NUEVA__') {
                if (categoriaNueva.value.trim() !== '') {
                    categoriaHidden.value = categoriaNueva.value.trim();
                }
            } else {
                categoriaHidden.value = '';
            }
            
            const proveedorSelect = document.getElementById('edit_proveedor');
            const proveedorNuevo = document.getElementById('edit_proveedor_nuevo');
            const proveedorHidden = document.getElementById('edit_proveedor_nuevo_input');
            
            if (proveedorSelect.value === '__NUEVO__') {
                if (proveedorNuevo.value.trim() !== '') {
                    proveedorHidden.value = proveedorNuevo.value.trim();
                    proveedorSelect.value = '';
                }
            } else {
                proveedorHidden.value = '';
            }
        });
    }
});

function normalizarTipoCodigoJS(valor, fallback = 'multiple') {
    const v = (valor || '').toString().trim().toLowerCase();
    if (v === 'unico' || v === 'multiple') return v;
    return fallback === 'unico' ? 'unico' : 'multiple';
}

function obtenerTipoCodigoDesdeFila(id) {
    const btn = document.querySelector(`[onclick*="editarProducto(${id}"]`);
    const fila = btn?.closest?.('.producto-fila, .producto-card-mobile');
    return fila?.getAttribute?.('data-tipo-codigo') || '';
}

function editarProducto(id, tipoCodigoActual = null, tieneCodigoLegado = 0) {
    guardarPaginaActualEnStorage();

    fetch(`get_producto.php?id=${id}&t=${Date.now()}`, { cache: 'no-store' })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                const p = data.producto;
                
                const categoriaSelect = document.getElementById('edit_categoria');
                const categoriaNueva = document.getElementById('edit_categoria_nueva');
                const proveedorSelect = document.getElementById('edit_proveedor');
                const proveedorNuevo = document.getElementById('edit_proveedor_nuevo');
                const preview = document.getElementById('imagen_preview');
                const inputImagen = document.getElementById('edit_imagen');
                
                categoriaNueva.style.display = 'none';
                categoriaNueva.value = '';
                proveedorNuevo.style.display = 'none';
                proveedorNuevo.value = '';
                
                if (inputImagen) inputImagen.value = '';
                
                document.getElementById('edit_id').value = p.id;
                document.getElementById('edit_nombre').value = p.nombre;
                document.getElementById('edit_precio_compra').value = p.precio_compra;
                document.getElementById('edit_precio_venta').value = p.precio_venta;
                
                const tipoCodigoSelect = document.getElementById('edit_tipo_codigo');
                if (tipoCodigoSelect) {
                    const fallbackTipoCodigo = normalizarTipoCodigoJS(
                        tipoCodigoActual || obtenerTipoCodigoDesdeFila(id),
                        'multiple'
                    );
                    const valorTipoCodigo = normalizarTipoCodigoJS(p.tipo_codigo, fallbackTipoCodigo);
                    tipoCodigoSelect.value = valorTipoCodigo;
                    const tipoCodigoRespaldo = document.getElementById('edit_tipo_codigo_respaldo');
                    const tipoCodigoForzado = document.getElementById('edit_tipo_codigo_forzado');
                    if (tipoCodigoRespaldo) tipoCodigoRespaldo.value = valorTipoCodigo;
                    if (tipoCodigoForzado) tipoCodigoForzado.value = valorTipoCodigo;
                    const tipoCodigoTocado = document.getElementById('edit_tipo_codigo_tocado');
                    if (tipoCodigoTocado) tipoCodigoTocado.value = '0';
                    
                    const esCodigoLegado = String(tieneCodigoLegado) === '1';
                    tipoCodigoSelect.disabled = esCodigoLegado;

                    const avisoLegado = document.getElementById('edit_tipo_codigo_legado_aviso');
                    if (avisoLegado) {
                        avisoLegado.style.display = esCodigoLegado ? 'block' : 'none';
                    }

                    tipoCodigoSelect.dispatchEvent(new Event('change', { bubbles: true }));
                    if (tipoCodigoTocado) tipoCodigoTocado.value = '0';
                }
                
                document.getElementById('edit_tipo_inventario').value = p.tipo_inventario;
                
                const stockText = p.tipo_inventario == 'insumo' ? parseFloat(p.cantidad).toFixed(2) + ' m' : parseInt(p.cantidad) + ' pz';
                document.getElementById('edit_cantidad_actual').textContent = stockText;
                
                if (p.imagen && p.imagen_exists) {
                    preview.src = p.imagen;
                    preview.style.display = 'block';
                } else {
                    preview.style.display = 'none';
                    preview.src = '';
                }
                
                // Cargar categoría
                if (p.categoria && p.categoria.trim() !== '') {
                    let existe = false;
                    for (let i = 0; i < categoriaSelect.options.length; i++) {
                        if (categoriaSelect.options[i].value === p.categoria) {
                            existe = true;
                            break;
                        }
                    }
                    if (existe) {
                        categoriaSelect.value = p.categoria;
                    } else {
                        categoriaSelect.value = '__NUEVA__';
                        categoriaNueva.value = p.categoria;
                        categoriaNueva.style.display = 'block';
                    }
                } else {
                    categoriaSelect.value = '';
                }
                
                // Cargar proveedor
                if (p.proveedor_id && p.proveedor_id > 0) {
                    let existe = false;
                    for (let i = 0; i < proveedorSelect.options.length; i++) {
                        if (proveedorSelect.options[i].value == p.proveedor_id) {
                            existe = true;
                            break;
                        }
                    }
                    if (existe) {
                        proveedorSelect.value = p.proveedor_id;
                    } else if (p.proveedor) {
                        proveedorNuevo.value = p.proveedor;
                        proveedorNuevo.style.display = 'block';
                    }
                } else if (p.proveedor) {
                    proveedorNuevo.value = p.proveedor;
                    proveedorNuevo.style.display = 'block';
                }
                
                // Atributos
                if (p.atributos_array) {
                    document.getElementById('edit_marca').value = p.atributos_array.marca || '';
                    document.getElementById('edit_color').value = p.atributos_array.color || '';
                    document.getElementById('edit_talla').value = p.atributos_array.talla || '';
                    document.getElementById('edit_material').value = p.atributos_array.material || '';
                } else {
                    document.getElementById('edit_marca').value = '';
                    document.getElementById('edit_color').value = '';
                    document.getElementById('edit_talla').value = '';
                    document.getElementById('edit_material').value = '';
                }
                
                // Adquisición
                if (p.tipo_adquisicion === 'pagado') {
                    document.querySelector('input[name="tipo_adquisicion"][value="pagado"]').checked = true;
                } else {
                    document.querySelector('input[name="tipo_adquisicion"][value="concesion"]').checked = true;
                }
                
                const isProducto = p.tipo_inventario === 'producto';
                const ventaGroup = document.getElementById('edit_precio_venta_group');
                const codigoGroup = document.getElementById('edit_tipo_codigo_group');
                const atributosSection = document.getElementById('edit_atributos_section');
                const adquisicionGroup = document.getElementById('edit_adquisicion_group');
                
                if (ventaGroup) ventaGroup.style.display = isProducto ? 'block' : 'none';
                if (codigoGroup) codigoGroup.style.display = isProducto ? 'block' : 'none';
                if (atributosSection) atributosSection.style.display = isProducto ? 'block' : 'none';
                if (adquisicionGroup) adquisicionGroup.style.display = isProducto ? 'block' : 'none';
                
                $('#modalEditar').modal('show');
            }
        })
        .catch(error => console.error('Error:', error));
}


function confirmarRegenerarCodigos() {
    Swal.fire({
        title: 'Regenerar códigos',
        html: `
            <div style="text-align:left; line-height:1.5;">
                Esto limpiará los códigos actuales de cada artículo activo y los volverá a crear según su configuración:<br><br>
                <b>Código único:</b> dejará solo un código P000000XX.<br>
                <b>Múltiple:</b> dejará un código por pieza actual en stock.<br><br>
                <b>Códigos históricos sin P:</b> no se eliminan, renombran ni convierten.<br><br>
                Esta acción ayuda a sincronizar los artículos con formato nuevo.
            </div>
        `,
        icon: 'warning',
        showCancelButton: true,
        confirmButtonText: 'Sí, regenerar',
        cancelButtonText: 'Cancelar',
        confirmButtonColor: '#f97316',
        cancelButtonColor: '#6c757d'
    }).then((result) => {
        if (result.isConfirmed) {
            document.getElementById('formRegenerarCodigos')?.submit();
        }
    });
}

function confirmarEliminar(id) {
    Swal.fire({
        title: "¿Eliminar?",
        text: "Esta acción no se puede deshacer",
        icon: "warning",
        showCancelButton: true,
        confirmButtonText: "Sí, eliminar",
        cancelButtonText: "Cancelar",
        confirmButtonColor: "#f97316"
    }).then((result) => {
        if (result.isConfirmed) {
            document.getElementById("delete_id").value = id;
            document.getElementById("formEliminar").submit();
        }
    });
}

function escapeHtml(text) {
    if (!text) return '';
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

// Previsualización de imagen
function initImagePreview() {
    const inputImagen = document.getElementById('edit_imagen');
    const preview = document.getElementById('imagen_preview');
    
    if (inputImagen) {
        inputImagen.addEventListener('change', function(e) {
            const file = e.target.files[0];
            if (file) {
                const reader = new FileReader();
                reader.onload = function(event) {
                    preview.src = event.target.result;
                    preview.style.display = 'block';
                };
                reader.readAsDataURL(file);
            } else {
                const productoId = document.getElementById('edit_id').value;
                if (productoId) {
                    fetch(`get_producto.php?id=${productoId}`)
                        .then(response => response.json())
                        .then(data => {
                            if (data.success && data.producto.imagen && data.producto.imagen_exists) {
                                preview.src = data.producto.imagen;
                                preview.style.display = 'block';
                            } else {
                                preview.style.display = 'none';
                            }
                        })
                        .catch(() => preview.style.display = 'none');
                } else {
                    preview.style.display = 'none';
                }
            }
        });
    }
}

// ===== INICIALIZACIÓN =====
document.addEventListener('DOMContentLoaded', function() {
    $('[data-toggle="tooltip"]').tooltip();
    
    // Inicializar arrays y recuperar filtros guardados
    filasVisibles = Array.from(document.querySelectorAll('#tablaBody .producto-fila'));
    tarjetasVisibles = Array.from(document.querySelectorAll('.producto-card-mobile'));

    cargarFiltrosDesdeStorage();
    pintarFiltrosEnPantalla();
    aplicarFiltros(false);
    
    // Filtros por tipo
    const filtrosBtns = document.querySelectorAll('.btn-filtro-tabla');
    filtrosBtns.forEach(btn => {
        btn.addEventListener('click', function() {
            filtrosBtns.forEach(b => b.classList.remove('active'));
            this.classList.add('active');
            filtroActual = this.getAttribute('data-filtro');
            aplicarFiltros();
        });
    });
    
    // Buscador
    const buscadorInput = document.getElementById('buscadorInput');
    const limpiarBusqueda = document.getElementById('limpiarBusqueda');
    
    if (buscadorInput) {
        buscadorInput.addEventListener('keyup', function() {
            busquedaActual = this.value;
            if (limpiarBusqueda) limpiarBusqueda.style.display = busquedaActual ? 'block' : 'none';
            aplicarFiltros();
        });
    }
    
    if (limpiarBusqueda) {
        limpiarBusqueda.addEventListener('click', function() {
            if (buscadorInput) buscadorInput.value = '';
            busquedaActual = '';
            this.style.display = 'none';
            aplicarFiltros();
            if (buscadorInput) buscadorInput.focus();
        });
    }

    const filtroProveedor = document.getElementById('filtroProveedor');
    if (filtroProveedor) {
        filtroProveedor.addEventListener('change', function() {
            proveedorActual = this.value || 'todos';
            aplicarFiltros();
        });
    }

    const btnLimpiarFiltros = document.getElementById('btnLimpiarFiltros');
    if (btnLimpiarFiltros) {
        btnLimpiarFiltros.addEventListener('click', borrarFiltros);
    }
    
    // Detectar cambio de tamaño (responsive)
    window.addEventListener('resize', function() {
        actualizarPaginacion();
    });
    
    initImagePreview();
});

// Previsualización de imagen
function initImagePreview() {
    const inputImagen = document.getElementById('edit_imagen');
    const preview = document.getElementById('imagen_preview');
    
    if (inputImagen) {
        inputImagen.addEventListener('change', function(e) {
            const file = e.target.files[0];
            if (file) {
                const reader = new FileReader();
                reader.onload = function(event) {
                    preview.src = event.target.result;
                    preview.style.display = 'block';
                };
                reader.readAsDataURL(file);
            } else {
                // Si se cancela, mostrar la imagen actual si existe
                const productoId = document.getElementById('edit_id').value;
                if (productoId) {
                    fetch(`get_producto.php?id=${productoId}`)
                        .then(response => response.json())
                        .then(data => {
                            if (data.success && data.producto.imagen && data.producto.imagen_exists) {
                                preview.src = data.producto.imagen;
                                preview.style.display = 'block';
                            } else {
                                preview.style.display = 'none';
                            }
                        })
                        .catch(() => preview.style.display = 'none');
                } else {
                    preview.style.display = 'none';
                }
            }
        });
    }
}

</script>

<?php
ob_end_flush();
?>