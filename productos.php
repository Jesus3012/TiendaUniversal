<?php
ob_start();
session_start();
require_once 'includes/csrf.php';
require_once 'includes/db.php';

if (!isset($_SESSION['usuario_id']) || $_SESSION['rol'] !== 'administrador') {
    header("Location: login.php");
    exit;
}

include 'includes/header.php';
include 'includes/navbar.php';
require_once 'includes/fpdf.php';
require_once __DIR__.'/vendor/autoload.php';

use Picqer\Barcode\BarcodeGeneratorPNG;

$success = '';
$errors = [];

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
    $result = $conn->query("SELECT DISTINCT proveedor FROM productos WHERE activo = 1 AND proveedor IS NOT NULL ORDER BY proveedor");
    $proveedores = [];
    while ($row = $result->fetch_assoc()) {
        $proveedores[] = $row['proveedor'];
    }
    return $proveedores;
}

// ========================= AGREGAR PRODUCTO =========================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'create') {
    csrf_check();

    $tipo_inventario = $_POST['tipo_inventario'] ?? 'producto';
    $nombre = trim($_POST['nombre'] ?? '');
    $categoria = trim($_POST['categoria'] ?? 'General');
    $proveedor = trim($_POST['proveedor'] ?? '');
    
    // Campos según tipo
    if ($tipo_inventario === 'producto') {
        $cantidad = intval($_POST['cantidad'] ?? 0);
        $precio_compra = floatval($_POST['precio_compra'] ?? 0);
        $precio_venta = floatval($_POST['precio_venta'] ?? 0);
        $tipo_codigo = $_POST['tipo_codigo'] ?? 'multiple';
        
        // Procesar atributos como JSON
        $atributos = [];
        $campos_atributos = ['marca', 'modelo', 'color', 'talla', 'peso', 'material'];
        foreach ($campos_atributos as $campo) {
            if (!empty($_POST[$campo])) {
                $atributos[$campo] = $_POST[$campo];
            }
        }
        $atributos_json = !empty($atributos) ? json_encode($atributos, JSON_UNESCAPED_UNICODE) : null;
    } else {
        // Insumo - solo datos básicos
        $cantidad = floatval($_POST['cantidad_insumo'] ?? 0);
        $precio_compra = floatval($_POST['precio_compra_insumo'] ?? 0);
        $precio_venta = 0; // Los insumos no tienen precio de venta
        $tipo_codigo = 'unico'; // Los insumos usan código único
        $atributos_json = null;
        
        // Obtener tipo de unidad para insumos
        $tipo_unidad_insumo = $_POST['tipo_unidad_insumo'] ?? 'unidad';
        $ancho_insumo = isset($_POST['ancho_insumo']) ? floatval($_POST['ancho_insumo']) : null;
        
        // Guardar información de unidad en atributos
        $atributos = ['tipo_unidad' => $tipo_unidad_insumo];
        if ($ancho_insumo && $ancho_insumo > 0) {
            $atributos['ancho'] = $ancho_insumo;
        }
        $atributos_json = json_encode($atributos, JSON_UNESCAPED_UNICODE);
    }

    // Validaciones básicas
    if ($nombre === '') {
        $errors[] = "El nombre del producto es obligatorio.";
    }

    // Validaciones según el tipo de inventario
    if ($tipo_inventario === 'producto') {
        if (!isset($_POST['cantidad']) || $_POST['cantidad'] === '') {
            $errors[] = "La cantidad del producto es obligatoria.";
        } elseif ($cantidad <= 0) {
            $errors[] = "La cantidad debe ser mayor a 0.";
        }
        
        if (!isset($_POST['precio_compra']) || $_POST['precio_compra'] === '') {
            $errors[] = "El precio de compra es obligatorio.";
        } elseif ($precio_compra <= 0) {
            $errors[] = "El precio de compra debe ser mayor a 0.";
        }
        
        if (!isset($_POST['precio_venta']) || $_POST['precio_venta'] === '') {
            $errors[] = "El precio de venta es obligatorio.";
        } elseif ($precio_venta <= 0) {
            $errors[] = "El precio de venta debe ser mayor a 0.";
        }
    } else { // insumo
        if (!isset($_POST['cantidad_insumo']) || $_POST['cantidad_insumo'] === '') {
            $errors[] = "La cantidad del insumo es obligatoria.";
        } elseif ($cantidad <= 0) {
            $errors[] = "La cantidad debe ser mayor a 0.";
        }
        
        if (!isset($_POST['precio_compra_insumo']) || $_POST['precio_compra_insumo'] === '') {
            $errors[] = "El precio de compra es obligatorio.";
        } elseif ($precio_compra <= 0) {
            $errors[] = "El precio de compra debe ser mayor a 0.";
        }
        
        $precio_venta = 0;
    }

    if (empty($errors)) {
        $imagen_path = '';
        if (!empty($_FILES['imagen']['name'])) {
            $upload_dir = __DIR__.'/uploads/productos/';
            if (!is_dir($upload_dir)) mkdir($upload_dir, 0777, true);

            $extension = strtolower(pathinfo($_FILES['imagen']['name'], PATHINFO_EXTENSION));
            $imagen_name = time().'_'.uniqid().'.'.$extension;
            $imagen_path = 'uploads/productos/'.$imagen_name;
            
            if (!move_uploaded_file($_FILES['imagen']['tmp_name'], $imagen_path)) {
                $errors[] = "Error al subir la imagen.";
                $imagen_path = '';
            }
        }

        if (empty($errors)) {
            $conn->begin_transaction();
            
            try {
                // Insertar producto
                $stmt = $conn->prepare("INSERT INTO productos (nombre, categoria, atributos, proveedor, imagen, cantidad, precio_compra, precio_venta, tipo_codigo, tipo_inventario) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                $stmt->bind_param("sssssiddss", $nombre, $categoria, $atributos_json, $proveedor, $imagen_path, $cantidad, $precio_compra, $precio_venta, $tipo_codigo, $tipo_inventario);
                
                if (!$stmt->execute()) {
                    throw new Exception("Error al insertar producto: " . $conn->error);
                }
                
                $producto_id = $stmt->insert_id;
                $stmt->close();

                // Registrar entrada inicial en historial - CORREGIDO
                $cero = 0; // Variable para el valor 0
                $stmt_historial = $conn->prepare("INSERT INTO historial_stock (producto_id, cantidad_anterior, cantidad_nueva, cantidad_agregada, tipo_movimiento, nota, usuario_id) VALUES (?, ?, ?, ?, 'entrada', 'Registro inicial de producto', ?)");
                $stmt_historial->bind_param("idddi", $producto_id, $cero, $cantidad, $cantidad, $_SESSION['usuario_id']);
                
                if (!$stmt_historial->execute()) {
                    throw new Exception("Error al registrar historial: " . $conn->error);
                }
                $stmt_historial->close();

                // Crear subcarpeta para códigos si no existe
                $codigos_dir = __DIR__.'/uploads/codigos/';
                if (!is_dir($codigos_dir)) mkdir($codigos_dir, 0777, true);

                // Generar códigos de barras solo para productos
                if ($tipo_inventario === 'producto') {
                    generarCodigosBarras($conn, $nombre, $producto_id, $cantidad, $tipo_codigo, $tipo_inventario);
                }
                
                $conn->commit();
                
                echo "<script>
                Swal.fire({
                    icon: 'success',
                    title: 'Producto agregado',
                    text: 'El producto se agregó correctamente.',
                    confirmButtonText: 'Aceptar'
                }).then(() => {
                    window.location = 'productos.php';
                });
                </script>";
                exit;
                
            } catch (Exception $e) {
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
    
    if ($tipo_codigo === 'unico') {
        $codigo = "P" . str_pad($producto_id, 8, '0', STR_PAD_LEFT);
        $stmt = $conn->prepare("INSERT INTO codigos_barras (producto_id, codigo, disponible) VALUES (?, ?, 1)");
        $stmt->bind_param("is", $producto_id, $codigo);
        $stmt->execute();
        $stmt->close();
    } else {
        for ($i = 1; $i <= $cantidad; $i++) {
            $codigo = $producto_id . str_pad($i, 5, '0', STR_PAD_LEFT);
            $stmt = $conn->prepare("INSERT INTO codigos_barras (producto_id, codigo, disponible) VALUES (?, ?, 1)");
            $stmt->bind_param("is", $producto_id, $codigo);
            $stmt->execute();
            $stmt->close();
        }
    }
    
    generarPDFCodigos($conn, $nombre, $producto_id, $cantidad, $tipo_codigo);
}

// ========================= GENERAR PDF CÓDIGOS =========================
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

        $stmt = $conn->prepare("SELECT codigo FROM codigos_barras WHERE producto_id = ? ORDER BY codigo");
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

// ========================= AGREGAR STOCK =========================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add_stock') {
    csrf_check();
    
    $producto_id = intval($_POST['producto_id']);
    $cantidad_agregar = floatval($_POST['cantidad']);
    $nota = trim($_POST['nota'] ?? '');
    $usuario_id = $_SESSION['usuario_id'] ?? null;
    
    // Validaciones
    if ($producto_id <= 0) {
        $errors[] = "ID de producto inválido.";
    }
    
    if ($cantidad_agregar <= 0) {
        $errors[] = "La cantidad debe ser mayor a 0.";
    }
    
    if (empty($errors)) {
        // Obtener producto actual
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
            
            // Iniciar transacción
            $conn->begin_transaction();
            
            try {
                // Actualizar cantidad en productos
                $stmt = $conn->prepare("UPDATE productos SET cantidad = ? WHERE id = ?");
                $stmt->bind_param("di", $cantidad_nueva, $producto_id);
                
                if (!$stmt->execute()) {
                    throw new Exception("Error al actualizar cantidad: " . $conn->error);
                }
                
                // Registrar en historial
                $stmt = $conn->prepare("INSERT INTO historial_stock (producto_id, cantidad_anterior, cantidad_nueva, cantidad_agregada, tipo_movimiento, nota, usuario_id) VALUES (?, ?, ?, ?, 'entrada', ?, ?)");
                $stmt->bind_param("idddsi", $producto_id, $cantidad_anterior, $cantidad_nueva, $cantidad_agregar, $nota, $usuario_id);
                
                if (!$stmt->execute()) {
                    throw new Exception("Error al registrar historial: " . $conn->error);
                }
                
                // Para productos con código múltiple, generar nuevos códigos
                if ($tipo_inventario === 'producto' && $producto['tipo_codigo'] === 'multiple') {
                    // Obtener el último código generado para este producto
                    $stmt = $conn->prepare("SELECT codigo FROM codigos_barras WHERE producto_id = ? ORDER BY codigo DESC LIMIT 1");
                    $stmt->bind_param("i", $producto_id);
                    $stmt->execute();
                    $res_ultimo = $stmt->get_result();
                    
                    $ultimo_codigo = 0;
                    if ($res_ultimo->num_rows > 0) {
                        $row = $res_ultimo->fetch_assoc();
                        $ultimo_codigo = intval(substr($row['codigo'], strlen($producto_id)));
                    }
                    
                    // Generar nuevos códigos
                    for ($i = $ultimo_codigo + 1; $i <= $ultimo_codigo + $cantidad_agregar; $i++) {
                        $nuevo_codigo = $producto_id . str_pad($i, 5, '0', STR_PAD_LEFT);
                        $stmt = $conn->prepare("INSERT INTO codigos_barras (producto_id, codigo, disponible) VALUES (?, ?, 1)");
                        $stmt->bind_param("is", $producto_id, $nuevo_codigo);
                        $stmt->execute();
                    }
                    
                    // Regenerar PDF del producto
                    generarPDFCodigos($conn, $producto['nombre'], $producto_id, $cantidad_nueva, 'multiple');
                }
                
                $conn->commit();
                
                echo "<script>
                Swal.fire({
                    icon: 'success',
                    title: 'Stock agregado',
                    text: 'Se agregaron " . number_format($cantidad_agregar, 2) . " unidades al stock.',
                    confirmButtonText: 'Aceptar'
                }).then(() => {
                    window.location = 'productos.php?tipo=" . urlencode($filtro_tipo ?? 'todos') . "';
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
    
    // Validaciones
    if ($producto_id <= 0) {
        $errors[] = "ID de producto inválido.";
    }
    
    if ($nueva_cantidad < 0) {
        $errors[] = "La cantidad no puede ser negativa.";
    }
    
    if (empty($errors)) {
        // Obtener producto actual
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
            
            // Iniciar transacción
            $conn->begin_transaction();
            
            try {
                // Actualizar cantidad en productos
                $stmt = $conn->prepare("UPDATE productos SET cantidad = ? WHERE id = ?");
                $stmt->bind_param("di", $nueva_cantidad, $producto_id);
                
                if (!$stmt->execute()) {
                    throw new Exception("Error al actualizar cantidad: " . $conn->error);
                }
                
                // Determinar tipo de movimiento según la diferencia
                $tipo_movimiento = ($diferencia > 0) ? 'entrada' : 'salida';
                $nota_completa = "AJUSTE: $razon_ajuste (diferencia: " . ($diferencia > 0 ? "+$diferencia" : $diferencia) . ")";
                
                // Registrar en historial como ajuste
                $cantidad_abs = abs($diferencia); // Guardar en variable primero
                $stmt = $conn->prepare("INSERT INTO historial_stock (producto_id, cantidad_anterior, cantidad_nueva, cantidad_agregada, tipo_movimiento, nota, usuario_id) VALUES (?, ?, ?, ?, 'ajuste', ?, ?)");

                if (!$stmt) {
                    throw new Exception("Error en prepare: " . $conn->error);
                }

                $stmt->bind_param("idddsi", 
                    $producto_id, 
                    $cantidad_anterior, 
                    $nueva_cantidad, 
                    $cantidad_abs,      // ✅ AHORA ES UNA VARIABLE
                    $nota_completa, 
                    $usuario_id
                );

                if (!$stmt->execute()) {
                    throw new Exception("Error al registrar historial: " . $stmt->error);
                }
                
                // Para productos con código múltiple, si se aumentó el stock, generar nuevos códigos
                if ($tipo_inventario === 'producto' && $producto['tipo_codigo'] === 'multiple' && $diferencia > 0) {
                    // Obtener el último código generado
                    $stmt = $conn->prepare("SELECT codigo FROM codigos_barras WHERE producto_id = ? ORDER BY codigo DESC LIMIT 1");
                    $stmt->bind_param("i", $producto_id);
                    $stmt->execute();
                    $res_ultimo = $stmt->get_result();
                    
                    $ultimo_codigo = 0;
                    if ($res_ultimo->num_rows > 0) {
                        $row = $res_ultimo->fetch_assoc();
                        $ultimo_codigo = intval(substr($row['codigo'], strlen($producto_id)));
                    }
                    
                    // Generar nuevos códigos
                    for ($i = $ultimo_codigo + 1; $i <= $ultimo_codigo + $diferencia; $i++) {
                        $nuevo_codigo = $producto_id . str_pad($i, 5, '0', STR_PAD_LEFT);
                        $stmt = $conn->prepare("INSERT INTO codigos_barras (producto_id, codigo, disponible) VALUES (?, ?, 1)");
                        $stmt->bind_param("is", $producto_id, $nuevo_codigo);
                        $stmt->execute();
                    }
                    
                    // Regenerar PDF
                    generarPDFCodigos($conn, $producto['nombre'], $producto_id, $nueva_cantidad, 'multiple');
                }
                
                $conn->commit();
                
                echo "<script>
                Swal.fire({
                    icon: 'success',
                    title: 'Stock ajustado',
                    text: 'Cantidad ajustada de " . number_format($cantidad_anterior, 2) . " a " . number_format($nueva_cantidad, 2) . "',
                    confirmButtonText: 'Aceptar'
                }).then(() => {
                    window.location = 'productos.php?tipo=" . urlencode($filtro_tipo ?? 'todos') . "';
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
    $categoria = trim($_POST['categoria']);
    $proveedor = trim($_POST['proveedor']);
    $precio_compra = floatval($_POST['precio_compra']);
    $precio_venta = floatval($_POST['precio_venta']);
    $tipo_codigo = $_POST['tipo_codigo'] ?? 'multiple';
    $tipo_inventario = $_POST['tipo_inventario'] ?? 'producto';
    
    // Procesar atributos
    $atributos = [];
    $campos_atributos = ['marca', 'modelo', 'color', 'talla', 'peso', 'material'];
    foreach ($campos_atributos as $campo) {
        if (!empty($_POST[$campo])) {
            $atributos[$campo] = $_POST[$campo];
        }
    }
    $atributos_json = !empty($atributos) ? json_encode($atributos, JSON_UNESCAPED_UNICODE) : null;

    // Obtener imagen actual
    $stmt = $conn->prepare("SELECT imagen, cantidad FROM productos WHERE id = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $result = $stmt->get_result();
    $producto_actual = $result->fetch_assoc();
    $imagen_path = $producto_actual['imagen'];
    $cantidad_actual = $producto_actual['cantidad']; // Mantener la cantidad actual

    // Procesar nueva imagen si se subió
    if (!empty($_FILES['imagen']['name'])) {
        $upload_dir = __DIR__.'/uploads/productos/';
        if (!is_dir($upload_dir)) mkdir($upload_dir, 0777, true);

        $extension = strtolower(pathinfo($_FILES['imagen']['name'], PATHINFO_EXTENSION));
        $imagen_name = time().'_'.uniqid().'.'.$extension;
        $nueva_imagen = 'uploads/productos/'.$imagen_name;
        
        if (move_uploaded_file($_FILES['imagen']['tmp_name'], $nueva_imagen)) {
            if ($imagen_path && file_exists($imagen_path)) {
                unlink($imagen_path);
            }
            $imagen_path = $nueva_imagen;
        }
    }

    if ($imagen_path) {
        $stmt = $conn->prepare("UPDATE productos SET nombre=?, categoria=?, atributos=?, proveedor=?, imagen=?, precio_compra=?, precio_venta=?, tipo_codigo=?, tipo_inventario=? WHERE id=?");
        $stmt->bind_param("sssssiddsi", $nombre, $categoria, $atributos_json, $proveedor, $imagen_path, $precio_compra, $precio_venta, $tipo_codigo, $tipo_inventario, $id);
    } else {
        $stmt = $conn->prepare("UPDATE productos SET nombre=?, categoria=?, atributos=?, proveedor=?, precio_compra=?, precio_venta=?, tipo_codigo=?, tipo_inventario=? WHERE id=?");
        $stmt->bind_param("ssssiddsi", $nombre, $categoria, $atributos_json, $proveedor, $precio_compra, $precio_venta, $tipo_codigo, $tipo_inventario, $id);
    }

    if ($stmt->execute()) {
        // Solo regenerar códigos si es PRODUCTO y solo si cambió el tipo de código
        if ($tipo_inventario === 'producto') {
            // Eliminar códigos existentes y regenerar
            $conn->query("DELETE FROM codigos_barras WHERE producto_id = $id");
            $old_pdf = __DIR__ . '/uploads/codigos/producto_' . $id . '.pdf';
            if (file_exists($old_pdf)) @unlink($old_pdf);
            
            // Regenerar con la cantidad actual (no la nueva)
            generarCodigosBarras($conn, $nombre, $id, $cantidad_actual, $tipo_codigo, $tipo_inventario);
        } else {
            // Si es insumo, eliminar cualquier código existente
            $conn->query("DELETE FROM codigos_barras WHERE producto_id = $id");
            $old_pdf = __DIR__ . '/uploads/codigos/producto_' . $id . '.pdf';
            if (file_exists($old_pdf)) @unlink($old_pdf);
        }
        
        echo "<script>
        Swal.fire({
            icon: 'success',
            title: 'Producto actualizado',
            text: 'Los cambios se guardaron correctamente.',
        }).then(() => {
            window.location='productos.php';
        });
        </script>";
        exit;
    } else {
        echo "<script>
        Swal.fire({
            icon: 'error',
            title: 'Error al actualizar',
            text: 'No se pudo actualizar el producto. Intenta nuevamente.',
            confirmButtonText: 'Aceptar'
        });
        </script>";
    }
}

// ========================= ELIMINAR PRODUCTO =========================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete') {
    csrf_check();
    $id = intval($_POST['id']);

    // Obtener imagen para eliminar
    $stmt = $conn->prepare("SELECT imagen FROM productos WHERE id = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $result = $stmt->get_result();
    $producto = $result->fetch_assoc();
    
    if ($producto['imagen'] && file_exists($producto['imagen'])) {
        unlink($producto['imagen']);
    }

    // Eliminar PDF de códigos
    $pdf_file = __DIR__ . '/uploads/codigos/producto_' . $id . '.pdf';
    if (file_exists($pdf_file)) unlink($pdf_file);

    // Soft delete
    $stmt = $conn->prepare("UPDATE productos SET activo = 0 WHERE id = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    
    echo "<script>
    Swal.fire({
        icon: 'success',
        title: 'Producto eliminado',
        text: 'El producto fue eliminado correctamente.'
    }).then(() => {
        window.location='productos.php';
    });
    </script>";
    exit;
}

// ========================= PDF CON TODOS LOS CÓDIGOS =========================
if (isset($_GET['action']) && $_GET['action'] === 'todos_codigos') {
    generarPDFTodosCodigos($conn);
}

function generarPDFTodosCodigos($conn) {
    $generator = new BarcodeGeneratorPNG();
    $pdf = new FPDF();
    $pdf->SetAutoPageBreak(false);
    $pdf->SetFont('Arial', '', 8);

    $query = "SELECT cb.codigo, p.nombre FROM codigos_barras cb 
              JOIN productos p ON cb.producto_id = p.id 
              WHERE p.activo = 1 AND p.tipo_inventario = 'producto'
              ORDER BY p.nombre ASC, cb.codigo ASC";
    $res = $conn->query($query);

    if ($res->num_rows === 0) {
        $pdf->AddPage();
        $pdf->SetFont('Arial', 'B', 16);
        $pdf->Cell(0, 10, 'No hay códigos de barras para mostrar', 0, 1, 'C');
        $pdf->SetFont('Arial', '', 12);
        $pdf->Cell(0, 10, 'Solo los productos generan códigos de barras.', 0, 1, 'C');
    } else {
        $pdf->AddPage();
        $margen_x = 20;
        $margen_y = 15;
        $espaciado_x = 45;
        $espaciado_y = 40;
        $ancho_codigo = 40;
        $alto_codigo = 20;
        $col = 0;
        $fila = 0;

        while ($row = $res->fetch_assoc()) {
            if ($fila >= 6) {
                $pdf->AddPage();
                $fila = 0;
                $col = 0;
            }

            $x = $margen_x + ($col * $espaciado_x);
            $y = $margen_y + ($fila * $espaciado_y);
            $pngData = $generator->getBarcode($row['codigo'], $generator::TYPE_CODE_128);
            $tmp = tempnam(sys_get_temp_dir(), 'bc_') . '.png';
            file_put_contents($tmp, $pngData);

            $pdf->SetXY($x, $y);
            $pdf->Cell($ancho_codigo, 4, utf8_decode(substr($row['nombre'], 0, 20)), 0, 2, 'C');
            $pdf->Image($tmp, $x + 2, $y + 5, $ancho_codigo - 4, $alto_codigo, 'PNG');
            $pdf->SetXY($x, $y + $alto_codigo + 8);
            $pdf->Cell($ancho_codigo, 4, $row['codigo'], 0, 0, 'C');

            @unlink($tmp);

            $col++;
            if ($col >= 4) {
                $col = 0;
                $fila++;
            }
        }
    }

    $codigos_dir = __DIR__ . '/uploads/codigos/';
    if (!is_dir($codigos_dir)) mkdir($codigos_dir, 0777, true);
    
    $file = $codigos_dir . 'todos_codigos.pdf';
    $pdf->Output('F', $file);
    header("Location: uploads/codigos/todos_codigos.pdf");
    exit;
}

// ========================= CONSULTAR PRODUCTOS =========================
$filtro_tipo = isset($_GET['tipo']) ? $_GET['tipo'] : 'todos';

$query = "SELECT * FROM productos WHERE activo = 1";
if ($filtro_tipo !== 'todos') {
    $query .= " AND tipo_inventario = '" . $conn->real_escape_string($filtro_tipo) . "'";
}
$query .= " ORDER BY id DESC";

$productos = [];
$res = $conn->query($query);
if ($res) {
    while ($row = $res->fetch_assoc()) {
        $row['atributos_array'] = $row['atributos'] ? json_decode($row['atributos'], true) : [];
        $productos[] = $row;
    }
    $res->free();
}

$categorias = obtenerCategorias($conn);
$proveedores = obtenerProveedores($conn);

// Mostrar errores si existen
if (!empty($errors)) {
    echo "<script>
    Swal.fire({
        icon: 'error',
        title: 'Error',
        html: '" . implode('<br>', $errors) . "',
        confirmButtonText: 'Aceptar'
    });
    </script>";
}
?>

<style>
/* Quitar completamente el margen automático de AdminLTE */
.layout-navbar-fixed .wrapper .content-wrapper {
    margin-top: 0 !important;
    padding-top: 70px !important;
}

.main-sidebar {
    margin-top: 0 !important;
    padding-top: 70px !important;
}

.content-wrapper .container,
.content-header,
.content {
    margin-top: 0 !important;
    padding-top: 0 !important;
}

/* Estilos para los filtros tipo inventario */
.btn-group-filtro {
    display: flex;
    justify-content: flex-end;
    margin-bottom: 15px;
}

.btn-filtro {
    border-radius: 30px;
    padding: 8px 20px;
    font-weight: 600;
    transition: all 0.3s;
    border: 2px solid transparent;
}

.btn-filtro.active {
    background: linear-gradient(135deg, #4361ee, #3a0ca3);
    color: white;
    box-shadow: 0 4px 10px rgba(67, 97, 238, 0.3);
}

.btn-filtro[data-tipo="producto"] {
    border-color: #06d6a0;
    color: #06d6a0;
}

.btn-filtro[data-tipo="producto"]:hover,
.btn-filtro[data-tipo="producto"].active {
    background: #06d6a0;
    color: white;
}

.btn-filtro[data-tipo="insumo"] {
    border-color: #ffb703;
    color: #ffb703;
}

.btn-filtro[data-tipo="insumo"]:hover,
.btn-filtro[data-tipo="insumo"].active {
    background: #ffb703;
    color: white;
}

/* Estilos para el formulario condicional */
.form-section {
    transition: all 0.3s ease;
    border-left: 4px solid transparent;
    padding-left: 15px;
}

.form-section.producto-section {
    border-left-color: #06d6a0;
}

.form-section.insumo-section {
    border-left-color: #ffb703;
}

/* Badges para tipo inventario */
.badge-tipo {
    padding: 5px 10px;
    border-radius: 20px;
    font-weight: 500;
    font-size: 0.75rem;
    display: inline-flex;
    align-items: center;
    gap: 4px;
}

.badge-producto {
    background-color: #e3f2fd;
    color: #0d47a1;
    border: 1px solid #90caf9;
}

.badge-producto i {
    color: #1976d2;
}

.badge-insumo {
    background-color: #e5f5e5;
    color: #148c20;
    border: 1px solid #93d89a;
}

.badge-insumo i {
    color: #1fa23b;
}

.btn-group-toggle .btn {
    border-radius: 30px;
    padding: 8px 15px;
    font-size: 0.9rem;
    transition: all 0.3s;
}

.btn-group-toggle .btn.active {
    transform: scale(1.02);
    box-shadow: 0 4px 8px rgba(0,0,0,0.1);
}

.unidad-label {
    background-color: #e9ecef;
    font-weight: 600;
    min-width: 45px;
    justify-content: center;
}

#ancho_insumo_group {
    animation: slideDown 0.3s ease;
}

@keyframes slideDown {
    from {
        opacity: 0;
        transform: translateY(-10px);
    }
    to {
        opacity: 1;
        transform: translateY(0);
    }
}

#stockInfoAlert {
    transition: all 0.3s ease;
    margin-bottom: 1rem;
}

#btnMostrarAlertaContainer {
    transition: all 0.3s ease;
    margin-top: 0.5rem;
}

#btnMostrarAlertaContainer .btn {
    border-radius: 20px;
    padding: 0.25rem 1rem;
    font-size: 0.9rem;
}

.close {
    opacity: 0.7;
    transition: opacity 0.2s;
    cursor: pointer;
}

.close:hover {
    opacity: 1;
}

#alertaImportante, #alertaInfo {
    transition: all 0.3s ease;
}

.btn-outline-warning, .btn-outline-info {
    border-width: 1px;
}

.btn-outline-warning:hover, .btn-outline-info:hover {
    color: white;
}

.btn-outline-dark {
    border: none;
    background: rgba(0,0,0,0.1);
}

.btn-outline-dark:hover {
    background: rgba(0,0,0,0.2);
}

/* Estilo para los botones de "No volver a mostrar" */
.btn-outline-dark {
    white-space: nowrap;
}

/* Animación suave al cambiar */
#alertaImportante, #alertaInfo,
#btnMostrarImportante, #btnMostrarInfo {
    transition: opacity 0.3s ease;
}
</style>

<div class="content-wrapper">
    <section>
        <div class="container-fluid">
            <div class="row">
                <!-- FORMULARIO ARRIBA -->
                <div class="col-12">
                    <div class="card card-primary card-outline shadow-sm">
                        <div class="card-header d-flex justify-content-between align-items-center">
                            <h3 class="card-title font-weight-bold mb-0">
                                <i class="fas fa-box-open mr-2"></i> Nuevo producto / insumo
                            </h3>
                            <button type="button" class="btn btn-light btn-sm ml-auto" title="Limpiar formulario" onclick="limpiarFormulario()">
                                <i class="fas fa-undo-alt"></i>
                            </button>
                        </div>

                        <div class="card-body">
                            <div class="d-flex justify-content-center mb-4">
                                <div class="btn-group" role="group">
                                    <button type="button" class="btn btn-outline-success btn-lg btn-filtro-tipo active" data-tipo="producto" onclick="cambiarTipoFormulario('producto')">
                                        <i class="fas fa-box mr-2"></i> Producto
                                    </button>
                                    <button type="button" class="btn btn-outline-warning btn-lg btn-filtro-tipo" data-tipo="insumo" onclick="cambiarTipoFormulario('insumo')">
                                        <i class="fas fa-cubes mr-2"></i> Insumo
                                    </button>
                                </div>
                            </div>

                            <form method="POST" enctype="multipart/form-data" id="formProducto">
                                <input type="hidden" name="action" value="create">
                                <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>">
                                <input type="hidden" name="tipo_inventario" id="tipo_inventario" value="producto">

                                <!-- Campos comunes -->
                                <div class="row">
                                    <div class="col-md-6">
                                        <div class="form-group">
                                            <label>Nombre <span class="text-danger">*</span></label>
                                            <input type="text" 
                                                name="nombre" 
                                                id="nombre_input"
                                                class="form-control" 
                                                placeholder="Ej. Llaveros, playeras, tazas, etc." 
                                                required>
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
                                            <input type="text" name="proveedor" class="form-control" placeholder="Ej. Proveedor S.A." list="proveedoresList">
                                            <datalist id="proveedoresList">
                                                <?php foreach ($proveedores as $prov): ?>
                                                    <option value="<?= htmlspecialchars($prov) ?>">
                                                <?php endforeach; ?>
                                            </datalist>
                                        </div>

                                        <div class="form-group">
                                            <label>Imagen</label>
                                            <input type="file" name="imagen" class="form-control" accept="image/*" onchange="previewImagen(event)">
                                            <img id="previewImg" class="img-thumbnail mt-2 d-none" style="max-height:120px;">
                                        </div>
                                    </div>

                                    <div class="col-md-6">
                                        <!-- SECCIÓN PRODUCTO -->
                                        <div id="producto-section" class="form-section producto-section">
                                            <h5 class="text-success"><i class="fas fa-box mr-2"></i> Datos del Producto</h5>
                                            
                                            <div class="form-group">
                                                <label>Cantidad <span class="text-danger">*</span></label>
                                                <input type="number" name="cantidad" class="form-control" min="1" placeholder="Ej. 10" required>
                                            </div>

                                            <div class="row">
                                                <div class="col-md-6">
                                                    <div class="form-group">
                                                        <label>Precio compra <span class="text-danger">*</span></label>
                                                        <input type="number" step="0.01" name="precio_compra" class="form-control" placeholder="0.00" required>
                                                    </div>
                                                </div>
                                                <div class="col-md-6">
                                                    <div class="form-group">
                                                        <label>Precio venta <span class="text-danger">*</span></label>
                                                        <input type="number" step="0.01" name="precio_venta" class="form-control" placeholder="0.00" required>
                                                    </div>
                                                </div>
                                            </div>

                                            <div class="form-group">
                                                <label>Tipo de código</label>
                                                <select name="tipo_codigo" class="form-control">
                                                    <option value="multiple">Múltiple (uno por unidad)</option>
                                                    <option value="unico">Único (un código para todo)</option>
                                                </select>
                                            </div>

                                            <!-- Atributos adicionales para producto -->
                                            <div class="card card-secondary mt-3">
                                                <div class="card-header">
                                                    <h6 class="mb-0">Atributos adicionales <small class="text-muted">(opcional)</small></h6>
                                                </div>
                                                <div class="card-body">
                                                    <div class="row">
                                                        <div class="col-md-6 mb-2">
                                                            <label>Marca</label>
                                                            <input type="text" name="marca" class="form-control form-control-sm" placeholder="Ej. Pescadores">
                                                        </div>
                                                        <div class="col-md-6 mb-2">
                                                            <label>Color</label>
                                                            <input type="text" name="color" class="form-control form-control-sm" placeholder="Ej. Negro">
                                                        </div>
                                                        <div class="col-md-6 mb-2">
                                                            <label>Talla</label>
                                                            <input type="text" name="talla" class="form-control form-control-sm" placeholder="Ej. M, L, XL">
                                                        </div>
                                                        <div class="col-md-6 mb-2">
                                                            <label>Material</label>
                                                            <input type="text" name="material" class="form-control form-control-sm" placeholder="Ej. Algodón">
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>

                                        <!-- SECCIÓN INSUMO -->
                                        <div id="insumo-section" class="form-section insumo-section" style="display: none;">
                                            <h5 class="text-warning"><i class="fas fa-cubes mr-2"></i> Datos del Insumo</h5>
                                            
                                            <div class="alert alert-warning">
                                                <i class="fas fa-ruler-combined mr-2"></i>
                                                <strong>Importante:</strong> Selecciona el tipo de unidad para el insumo.
                                            </div>

                                            <!-- Selector de tipo de unidad -->
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

                                            <!-- Campo de cantidad con unidad dinámica -->
                                            <div class="form-group">
                                                <label>Cantidad <span class="text-danger">*</span></label>
                                                <div class="input-group">
                                                    <input type="number" 
                                                        name="cantidad_insumo" 
                                                        id="cantidad_insumo"
                                                        class="form-control" 
                                                        min="0.1" 
                                                        step="1" 
                                                        placeholder="Ej. 100" 
                                                        required>
                                                    <div class="input-group-append">
                                                        <span class="input-group-text unidad-label" id="unidad_insumo_label">pz</span>
                                                    </div>
                                                </div>
                                                <small class="text-muted" id="unidad_insumo_help">
                                                    <i class="fas fa-info-circle mr-1"></i> Cantidad en piezas
                                                </small>
                                            </div>

                                            <!-- Campo de precio de compra -->
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

                                            <!-- Campo opcional para ancho (solo para metros) -->
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
                                    </div>
                                </div>

                                <!-- BOTÓN -->
                                <div class="mt-4">
                                    <button type="submit" class="btn btn-success btn-block btn-lg">
                                        <i class="fas fa-save mr-1"></i> Guardar
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>

                <!-- FILTROS Y TABLA DEBAJO -->
                <div class="col-12 mt-4">
                    <div class="card card-outline card-dark shadow-sm flex-fill">
                        <div class="card-header">
                            <div class="d-flex flex-column flex-md-row align-items-md-center justify-content-between">
                                <h3 class="card-title font-weight-bold mb-2 mb-md-0">
                                    <i class="fas fa-box mr-2"></i> Inventario
                                </h3>

                                <div class="d-flex gap-2">
                                    <div class="btn-group btn-group-sm mr-2" role="group">
                                        <button type="button" class="btn btn-outline-secondary btn-filtro-tabla <?= $filtro_tipo == 'todos' ? 'active' : '' ?>" data-tipo="todos">
                                            Todos
                                        </button>
                                        <button type="button" class="btn btn-outline-success btn-filtro-tabla <?= $filtro_tipo == 'producto' ? 'active' : '' ?>" data-tipo="producto">
                                            <i class="fas fa-box mr-1"></i> Productos
                                        </button>
                                        <button type="button" class="btn btn-outline-warning btn-filtro-tabla <?= $filtro_tipo == 'insumo' ? 'active' : '' ?>" data-tipo="insumo">
                                            <i class="fas fa-cubes mr-1"></i> Insumos
                                        </button>
                                    </div>

                                    <div style="max-width:250px;">
                                        <div class="input-group input-group-sm">
                                            <div class="input-group-prepend">
                                                <span class="input-group-text"><i class="fas fa-search"></i></span>
                                            </div>
                                            <input type="text" id="searchProductos" class="form-control" placeholder="Buscar...">
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="card-body p-0">
                            <div class="table-responsive">
                                <table class="table table-hover table-bordered table-sm mb-0 text-nowrap">
                                    <thead class="bg-secondary">
                                        <tr>
                                            <th>Tipo</th>
                                            <th>Nombre</th>
                                            <th>Categoría</th>
                                            <th>Proveedor</th>
                                            <th class="text-center">Imagen</th>
                                            <th class="text-center">Cantidad</th>
                                            <th class="text-right">Compra</th>
                                            <th class="text-right">Venta</th>
                                            <th class="text-center">PDF</th>
                                            <th class="text-center">Acciones</th>
                                        </tr>
                                    </thead>

                                    <tbody id="tablaBody">
                                        <?php if (empty($productos)): ?>
                                        <tr class="empty-row">
                                            <td colspan="10" class="text-center py-5">
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
                                            <tr data-id="<?= $p['id'] ?>" data-tipo="<?= $p['tipo_inventario'] ?>">
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

                                                <td class="font-weight-bold">
                                                    <?= htmlspecialchars($p['nombre']) ?>
                                                    <?php if (!empty($p['atributos_array'])): ?>
                                                        <i class="fas fa-info-circle text-primary ml-1" 
                                                        data-toggle="tooltip" 
                                                        title="<?= htmlspecialchars(implode(', ', array_map(function($k, $v) { return "$k: $v"; }, array_keys($p['atributos_array']), $p['atributos_array']))) ?>">
                                                        </i>
                                                    <?php endif; ?>
                                                </td>

                                                <td>
                                                    <span class="badge badge-info">
                                                        <?= htmlspecialchars($p['categoria']) ?>
                                                    </span>
                                                </td>

                                                <td><?= htmlspecialchars($p['proveedor'] ?? '-') ?></td>

                                                <!-- IMAGEN -->
                                                <td class="text-center">
                                                    <?php if ($p['imagen'] && file_exists($p['imagen'])): ?>
                                                        <img src="<?= $p['imagen'] ?>" class="img-thumbnail" style="width:50px;height:50px;object-fit:cover;" alt="Producto">
                                                    <?php else: ?>
                                                        <span class="badge badge-secondary">Sin imagen</span>
                                                    <?php endif; ?>
                                                </td>

                                                <!-- CANTIDAD -->
                                                <td class="text-center">
                                                    <span class="badge <?= $badgeClass ?>">
                                                        <?= $cantidadDisplay ?>
                                                    </span>
                                                </td>

                                                <!-- PRECIOS -->
                                                <td class="text-right">
                                                    $<?= number_format($p['precio_compra'], 2) ?>
                                                </td>

                                                <td class="text-right font-weight-bold text-success">
                                                    <?php if ($p['tipo_inventario'] == 'producto'): ?>
                                                        $<?= number_format($p['precio_venta'], 2) ?>
                                                    <?php else: ?>
                                                        <span class="text-muted">-</span>
                                                    <?php endif; ?>
                                                </td>

                                                <!-- PDF -->
                                                <td class="text-center">
                                                    <?php if ($p['tipo_inventario'] == 'producto'): ?>
                                                        <?php
                                                        $pdf_file = 'uploads/codigos/producto_' . $p['id'] . '.pdf';
                                                        if (file_exists($pdf_file)):
                                                        ?>
                                                            <a href="<?= $pdf_file ?>?v=<?= filemtime($pdf_file) ?>" class="btn btn-outline-success btn-sm" target="_blank">
                                                                <i class="far fa-file-pdf"></i>
                                                            </a>
                                                        <?php else: ?>
                                                            <span class="text-muted">—</span>
                                                        <?php endif; ?>
                                                    <?php else: ?>
                                                        <span class="text-muted">—</span>
                                                    <?php endif; ?>
                                                </td>

                                                <!-- ACCIONES - MODIFICADO: Ahora tiene 3 botones -->
                                                <td class="text-center">
                                                    <div class="btn-group btn-group-sm">
                                                        <!-- Botón agregar stock (existente) -->
                                                        <button class="btn btn-success" title="Agregar stock" onclick="abrirModalAgregarStock(<?= $p['id'] ?>, '<?= htmlspecialchars($p['nombre'], ENT_QUOTES) ?>', <?= $p['cantidad'] ?>, '<?= $p['tipo_inventario'] ?>')">
                                                            <i class="fas fa-plus-circle"></i>
                                                        </button>
                                                        
                                                        <!-- NUEVO BOTÓN: Ajustar stock -->
                                                        <button class="btn btn-warning" title="Ajustar stock" onclick="abrirModalAjustarStock(<?= $p['id'] ?>, '<?= htmlspecialchars($p['nombre'], ENT_QUOTES) ?>', <?= $p['cantidad'] ?>, '<?= $p['tipo_inventario'] ?>')">
                                                            <i class="fas fa-sliders-h"></i>
                                                        </button>
                                                        
                                                        <!-- Botón editar (existente) -->
                                                        <button class="btn btn-info" title="Editar" onclick="editarProducto(<?= $p['id'] ?>)">
                                                            <i class="fas fa-edit"></i>
                                                        </button>
                                                        
                                                        <!-- Botón eliminar (existente) -->
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

                            <!-- BOTÓN PDF TODOS LOS CÓDIGOS Y LINK A HISTORIAL -->
                            <div class="p-3 d-flex justify-content-between align-items-center">
                                <div>
                                    <a href="productos.php?action=todos_codigos" target="_blank" class="btn btn-success btn-sm">
                                        <i class="fas fa-file-pdf mr-1"></i> PDF con todos los códigos
                                    </a>
                                </div>
                                <div>
                                    <a href="historial_stock.php" class="btn btn-info btn-sm">
                                        <i class="fas fa-history mr-1"></i> Ver historial completo
                                    </a>
                                </div>
                            </div>
                        </div>

                        <div class="card-footer text-muted text-right">
                            <small id="totalRegistros">
                                Total: <strong><?= count($productos) ?></strong> registros
                            </small>
                        </div>
                    </div>
                </div>
            </div><!-- row -->
        </div><!-- container-fluid -->
    </section>
</div>

<!-- MODAL AGREGAR STOCK (NUEVO) - Versión corregida -->
<div class="modal fade" id="modalAgregarStock" tabindex="-1">
    <div class="modal-dialog">
        <form method="POST" class="modal-content" id="formAgregarStock">
            <div class="modal-header bg-success text-white">
                <h5 class="modal-title">
                    <i class="fas fa-plus-circle mr-2"></i> Agregar Stock
                </h5>
                <button type="button" class="close" data-dismiss="modal">&times;</button>
            </div>

            <div class="modal-body">
                <input type="hidden" name="action" value="add_stock">
                <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>">
                <input type="hidden" id="stock_producto_id" name="producto_id">
                <input type="hidden" id="stock_tipo_inventario" name="tipo_inventario">

                <div class="text-center mb-3" id="stock_producto_info">
                    <!-- Se llenará con JS -->
                </div>

                <div class="form-group">
                    <label for="stock_cantidad">Cantidad a agregar <span class="text-danger">*</span></label>
                    <div class="input-group">
                        <input type="number" 
                               id="stock_cantidad" 
                               name="cantidad" 
                               class="form-control form-control-lg" 
                               min="0.01" 
                               step="any" 
                               required>
                        <div class="input-group-append">
                            <span class="input-group-text unidad-label" id="stock_unidad">pz</span>
                        </div>
                    </div>
                    <small class="text-muted" id="stock_cantidad_actual">Stock actual: <span id="stock_actual_valor">0</span></small>
                </div>

                <div class="form-group">
                    <label for="stock_nota">Nota (opcional)</label>
                    <textarea id="stock_nota" 
                              name="nota" 
                              class="form-control" 
                              rows="2" 
                              placeholder="Ej. Nueva compra a proveedor, etc."></textarea>
                </div>

                <!-- ALERTA MODIFICADA -->
                <div class="alert alert-info p-3" id="stockInfoAlert" role="alert">
                    <div class="d-flex">
                        <i class="fas fa-info-circle mr-2"></i>
                        <div class="flex-grow-1">
                            <div class="d-flex justify-content-between align-items-start">
                                <h6 class="mb-2 font-weight-bold">Importante:</h6>
                                <button type="button" class="close" onclick="ocultarAlertaStockPermanente()">
                                    <span aria-hidden="true">&times;</span>
                                </button>
                            </div>
                            <div class="">
                                <div class="mb-2">
                                    <i class="text-success"></i>
                                    La cantidad se sumará al stock actual.
                                </div>
                                <div class="mb-0">
                                    <i class="text-primary"></i>
                                    Para productos con código múltiple, se generarán nuevos códigos automáticamente.
                                    <span id="alertaMensajeAdicional" class="d-block ml-4 text-muted"></span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- BOTÓN PARA MOSTRAR ALERTA -->
                <div class="text-center" id="btnMostrarAlertaContainer">
                    <button type="button" class="btn btn-sm btn-outline-info" onclick="mostrarAlertaStock()">
                        <i class="fas fa-question-circle mr-1"></i> ¿Cómo funciona?
                    </button>
                </div>
                
                <!-- Indicador de estado (solo para debug) -->
                <div class="text-muted small text-center" id="debugStatus" style="display: none;">
                    Estado: <span id="debugText"></span>
                </div>
            </div>

            <div class="modal-footer">
                <button type="submit" class="btn btn-success">
                    <i class="fas fa-save mr-1"></i> Agregar Stock
                </button>
                <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancelar</button>
            </div>
        </form>
    </div>
</div>

<!-- MODAL AJUSTAR STOCK -->
<div class="modal fade" id="modalAjustarStock" tabindex="-1">
    <div class="modal-dialog">
        <form method="POST" class="modal-content" id="formAjustarStock">
            <div class="modal-header bg-warning text-white">
                <h5 class="modal-title">
                    <i class="fas fa-sliders-h mr-2"></i> Ajustar Stock
                </h5>
                <button type="button" class="close" data-dismiss="modal">&times;</button>
            </div>

            <div class="modal-body">
                <input type="hidden" name="action" value="adjust_stock">
                <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>">
                <input type="hidden" id="ajuste_producto_id" name="producto_id">
                <input type="hidden" id="ajuste_tipo_inventario" name="tipo_inventario">

                <div class="text-center mb-3" id="ajuste_producto_info">
                    <!-- Se llenará con JS -->
                </div>

                <!-- Mensaje Importante -->
                <div id="mensajeImportanteContainer">
                    <div class="alert alert-warning" id="alertaImportante">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <i class="fas fa-exclamation-triangle mr-2"></i>
                                <strong>Importante:</strong> Usa esta opción SOLO para corregir errores. 
                                Establece la cantidad exacta que debería haber en stock.
                            </div>
                            <button type="button" class="btn btn-sm btn-outline-dark" onclick="ocultarAlerta('importante')">
                                <i class="fas fa-times"></i>
                            </button>
                        </div>
                    </div>
                    <!-- BOTÓN PARA MOSTRAR ALERTA (oculto por defecto) -->
                    <div class="text-center mb-3" id="btnMostrarImportante" style="display: none;">
                        <button type="button" class="btn btn-sm btn-outline-warning" onclick="mostrarAlerta('importante')">
                            <i class="fas fa-exclamation-triangle mr-1"></i> Ver información importante
                        </button>
                    </div>
                </div>

                <!-- Campos del formulario -->
                <div class="form-group">
                    <label>Stock actual <span class="text-danger">*</span></label>
                    <div class="input-group">
                        <input type="number" 
                               id="ajuste_stock_actual" 
                               class="form-control" 
                               readonly 
                               disabled>
                        <div class="input-group-append">
                            <span class="input-group-text unidad-label" id="ajuste_unidad_actual">pz</span>
                        </div>
                    </div>
                </div>

                <div class="form-group">
                    <label>Nueva cantidad <span class="text-danger">*</span></label>
                    <div class="input-group">
                        <input type="number" 
                               id="ajuste_nueva_cantidad" 
                               name="nueva_cantidad" 
                               class="form-control form-control-lg" 
                               min="0" 
                               step="any" 
                               required>
                        <div class="input-group-append">
                            <span class="input-group-text unidad-label" id="ajuste_unidad_nueva">pz</span>
                        </div>
                    </div>
                    <small class="text-muted" id="ajuste_diferencia">Diferencia: 0</small>
                </div>

                <div class="form-group">
                    <label>Razón del ajuste <span class="text-danger">*</span></label>
                    <textarea id="ajuste_razon" 
                              name="razon_ajuste" 
                              class="form-control" 
                              rows="2" 
                              placeholder="Ej. Error en conteo, producto dañado, corrección, etc." 
                              required></textarea>
                </div>

                <!-- Mensaje Información -->
                <div id="mensajeInfoContainer">
                    <div class="alert alert-info" id="alertaInfo">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <i class="fas fa-info-circle mr-2"></i>
                                Se registrará como un AJUSTE en el historial y aparecerá en color naranja.
                            </div>
                            <button type="button" class="btn btn-sm btn-outline-dark" onclick="ocultarAlerta('info')">
                                <i class="fas fa-times"></i>
                            </button>
                        </div>
                    </div>
                    <!-- BOTÓN PARA MOSTRAR ALERTA (oculto por defecto) -->
                    <div class="text-center mb-3" id="btnMostrarInfo" style="display: none;">
                        <button type="button" class="btn btn-sm btn-outline-info" onclick="mostrarAlerta('info')">
                            <i class="fas fa-info-circle mr-1"></i> Ver información del ajuste
                        </button>
                    </div>
                </div>
            </div>

            <div class="modal-footer">
                <button type="submit" class="btn btn-warning">
                    <i class="fas fa-check-circle mr-1"></i> Aplicar Ajuste
                </button>
                <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancelar</button>
            </div>
        </form>
    </div>
</div>

<!-- MODAL EDITAR PRODUCTO (MODIFICADO: sin campo cantidad) -->
<div class="modal fade" id="modalEditar" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <form method="POST" enctype="multipart/form-data" class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title">Editar Producto / Insumo</h5>
                <button type="button" class="close" data-dismiss="modal">&times;</button>
            </div>

            <div class="modal-body">
                <input type="hidden" name="action" value="update">
                <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>">
                <input type="hidden" id="edit_id" name="id">
                <input type="hidden" id="edit_tipo_inventario" name="tipo_inventario">

                <div class="row">
                    <div class="col-md-6">
                        <div class="form-group">
                            <label>Nombre:</label>
                            <input type="text" id="edit_nombre" name="nombre" class="form-control" required>
                        </div>

                        <div class="form-group">
                            <label>Categoría:</label>
                            <input type="text" id="edit_categoria" name="categoria" class="form-control" required>
                        </div>

                        <div class="form-group">
                            <label>Proveedor:</label>
                            <input type="text" id="edit_proveedor" name="proveedor" class="form-control">
                        </div>

                        <!-- ELIMINADO: campo cantidad -->
                    </div>

                    <div class="col-md-6">
                        <div class="form-group">
                            <label>Precio compra:</label>
                            <input type="number" step="0.01" id="edit_precio_compra" name="precio_compra" class="form-control" required>
                        </div>

                        <div class="form-group" id="edit_precio_venta_group">
                            <label>Precio venta:</label>
                            <input type="number" step="0.01" id="edit_precio_venta" name="precio_venta" class="form-control">
                        </div>

                        <div class="form-group" id="edit_tipo_codigo_group">
                            <label>Tipo código:</label>
                            <select id="edit_tipo_codigo" name="tipo_codigo" class="form-control">
                                <option value="unico">Código único</option>
                                <option value="multiple">Por artículo</option>
                            </select>
                        </div>

                        <div class="form-group">
                            <label>Nueva imagen (opcional):</label>
                            <input type="file" name="imagen" accept="image/*" class="form-control">
                        </div>
                    </div>
                </div>

                <!-- Mostrar cantidad actual como información (solo lectura) -->
                <div class="alert alert-info" id="edit_cantidad_info">
                    <i class="fas fa-info-circle mr-2"></i>
                    Stock actual: <strong id="edit_cantidad_actual">0</strong>
                    <small class="d-block mt-1">Para modificar el stock usa el botón <i class="fas fa-plus-circle text-success"></i> Agregar stock en la tabla.</small>
                </div>

                <!-- Atributos adicionales (solo para productos) -->
                <div id="edit_atributos_section" class="card card-secondary mt-3">
                    <div class="card-header">
                        <h6>Atributos adicionales</h6>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-6 mb-2">
                                <label>Marca</label>
                                <input type="text" id="edit_marca" name="marca" class="form-control form-control-sm">
                            </div>
                            <div class="col-md-6 mb-2">
                                <label>Color</label>
                                <input type="text" id="edit_color" name="color" class="form-control form-control-sm">
                            </div>
                            <div class="col-md-6 mb-2">
                                <label>Talla / tamaño</label>
                                <input type="text" id="edit_talla" name="talla" class="form-control form-control-sm">
                            </div>
                            <div class="col-md-6 mb-2">
                                <label>Material</label>
                                <input type="text" id="edit_material" name="material" class="form-control form-control-sm">
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="modal-footer">
                <button class="btn btn-success">Actualizar</button>
                <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancelar</button>
            </div>
        </form>
    </div>
</div>

<!-- Formulario oculto para eliminar -->
<form id="formEliminar" method="POST" style="display:none;">
    <input type="hidden" name="action" value="delete">
    <input type="hidden" id="delete_id" name="id">
    <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>">
</form>

<!-- Scripts -->
<script src="https://code.jquery.com/jquery-3.6.4.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@4.6.2/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

<script>
// Variables globales
let filtroActual = '<?= $filtro_tipo ?>';

// ===== INICIALIZACIÓN =====
document.addEventListener('DOMContentLoaded', function() {
    // Inicializar tooltips
    $('[data-toggle="tooltip"]').tooltip();
    
    // Configurar el formulario para que no tenga validación HTML5
    document.getElementById('formProducto').setAttribute('novalidate', 'novalidate');
    
    // Inicializar el tipo de formulario
    const tipoInicial = document.getElementById('tipo_inventario').value;
    configurarCamposPorTipo(tipoInicial);
    
    // Configurar el evento de envío del formulario
    document.getElementById('formProducto').addEventListener('submit', validarFormulario);
    
    // Asegurar que la sección de insumos tenga la unidad por defecto
    const unidadPorDefecto = document.querySelector('input[name="tipo_unidad_insumo"]:checked');
    if (unidadPorDefecto) {
        cambiarUnidadInsumo(unidadPorDefecto.value);
    }
});

// ===== CONFIGURACIÓN DE CAMPOS POR TIPO =====
function configurarCamposPorTipo(tipo) {
    const camposProducto = {
        cantidad: document.querySelector('input[name="cantidad"]'),
        precioCompra: document.querySelector('input[name="precio_compra"]'),
        precioVenta: document.querySelector('input[name="precio_venta"]')
    };
    
    const camposInsumo = {
        cantidad: document.querySelector('input[name="cantidad_insumo"]'),
        precioCompra: document.querySelector('input[name="precio_compra_insumo"]')
    };
    
    if (tipo === 'producto') {
        document.getElementById('producto-section').style.display = 'block';
        document.getElementById('insumo-section').style.display = 'none';
        
        camposProducto.cantidad.required = true;
        camposProducto.cantidad.disabled = false;
        camposProducto.precioCompra.required = true;
        camposProducto.precioCompra.disabled = false;
        camposProducto.precioVenta.required = true;
        camposProducto.precioVenta.disabled = false;
        
        camposInsumo.cantidad.required = false;
        camposInsumo.cantidad.disabled = true;
        camposInsumo.cantidad.value = '';
        
        camposInsumo.precioCompra.required = false;
        camposInsumo.precioCompra.disabled = true;
        camposInsumo.precioCompra.value = '';
        
    } else {
        document.getElementById('producto-section').style.display = 'none';
        document.getElementById('insumo-section').style.display = 'block';
        
        camposInsumo.cantidad.required = true;
        camposInsumo.cantidad.disabled = false;
        camposInsumo.precioCompra.required = true;
        camposInsumo.precioCompra.disabled = false;
        
        camposProducto.cantidad.required = false;
        camposProducto.cantidad.disabled = true;
        camposProducto.cantidad.value = '';
        
        camposProducto.precioCompra.required = false;
        camposProducto.precioCompra.disabled = true;
        camposProducto.precioCompra.value = '';
        
        camposProducto.precioVenta.required = false;
        camposProducto.precioVenta.disabled = true;
        camposProducto.precioVenta.value = '';
    }
}

// ===== CAMBIAR TIPO DE FORMULARIO =====
function cambiarTipoFormulario(tipo) {
    document.getElementById('tipo_inventario').value = tipo;
    
    // Actualizar botones
    document.querySelectorAll('.btn-filtro-tipo').forEach(btn => {
        btn.classList.remove('active');
    });
    document.querySelector(`.btn-filtro-tipo[data-tipo="${tipo}"]`).classList.add('active');
    
    // Cambiar el placeholder del campo nombre según el tipo
    const nombreInput = document.getElementById('nombre_input');
    if (tipo === 'producto') {
        nombreInput.placeholder = 'Ej. Llaveros, playeras, tazas, gorras, etc.';
    } else {
        nombreInput.placeholder = 'Ej. DTF blanco, Vinil brillante, etc.';
    }
    
    // Configurar campos según el tipo
    configurarCamposPorTipo(tipo);
    
    if (tipo === 'insumo') {
        const unidadPorDefecto = document.querySelector('input[name="tipo_unidad_insumo"]:checked');
        if (unidadPorDefecto) {
            cambiarUnidadInsumo(unidadPorDefecto.value);
        }
    }
}

// ===== VALIDACIÓN PERSONALIZADA DEL FORMULARIO =====
function validarFormulario(e) {
    e.preventDefault();
    
    const tipo = document.getElementById('tipo_inventario').value;
    let errores = [];
    
    if (tipo === 'producto') {
        const cantidad = document.querySelector('input[name="cantidad"]').value;
        const precioCompra = document.querySelector('input[name="precio_compra"]').value;
        const precioVenta = document.querySelector('input[name="precio_venta"]').value;
        const nombre = document.querySelector('input[name="nombre"]').value;
        
        if (!nombre.trim()) errores.push("El nombre del producto es obligatorio");
        if (!cantidad || cantidad <= 0) errores.push("La cantidad debe ser mayor a 0");
        if (!precioCompra || precioCompra <= 0) errores.push("El precio de compra debe ser mayor a 0");
        if (!precioVenta || precioVenta <= 0) errores.push("El precio de venta debe ser mayor a 0");
        
    } else {
        const cantidad = document.querySelector('input[name="cantidad_insumo"]').value;
        const precioCompra = document.querySelector('input[name="precio_compra_insumo"]').value;
        const nombre = document.querySelector('input[name="nombre"]').value;
        
        if (!nombre.trim()) errores.push("El nombre del insumo es obligatorio");
        if (!cantidad || cantidad <= 0) errores.push("La cantidad debe ser mayor a 0");
        if (!precioCompra || precioCompra <= 0) errores.push("El precio de compra debe ser mayor a 0");
    }
    
    if (errores.length > 0) {
        Swal.fire({
            icon: 'error',
            title: 'Error de validación',
            html: errores.join('<br>'),
            confirmButtonText: 'Aceptar'
        });
        return false;
    }
    
    if (tipo === 'producto') {
        document.querySelector('input[name="cantidad_insumo"]').disabled = false;
        document.querySelector('input[name="precio_compra_insumo"]').disabled = false;
    } else {
        document.querySelector('input[name="cantidad"]').disabled = false;
        document.querySelector('input[name="precio_compra"]').disabled = false;
        document.querySelector('input[name="precio_venta"]').disabled = false;
    }
    
    e.target.submit();
}

// ===== FUNCIÓN PARA ABRIR MODAL DE AGREGAR STOCK (NUEVA) =====
function abrirModalAgregarStock(id, nombre, cantidadActual, tipoInventario) {
    document.getElementById('stock_producto_id').value = id;
    document.getElementById('stock_tipo_inventario').value = tipoInventario;
    
    // Para insumos, necesitamos obtener la unidad desde el servidor
    if (tipoInventario === 'insumo') {
        // Hacer una petición AJAX para obtener los atributos del insumo
        fetch(`get_producto.php?id=${id}`)
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    const producto = data.producto;
                    let unidad = 'pz'; // Por defecto
                    
                    // Verificar si tiene atributos y tipo de unidad
                    if (producto.atributos_array) {
                        if (producto.atributos_array.tipo_unidad === 'metros') {
                            unidad = 'm';
                        }
                    }
                    
                    document.getElementById('stock_unidad').textContent = unidad;
                } else {
                    // Si falla, usar pz por defecto
                    document.getElementById('stock_unidad').textContent = 'pz';
                }
            })
            .catch(error => {
                console.error('Error:', error);
                document.getElementById('stock_unidad').textContent = 'pz';
            });
    } else {
        // Para productos, siempre es pz
        document.getElementById('stock_unidad').textContent = 'pz';
    }
    
    document.getElementById('stock_actual_valor').textContent = 
        tipoInventario === 'insumo' ? cantidadActual.toFixed(2) : cantidadActual;
    
    const infoHtml = `
        <div class="card card-success card-outline">
            <div class="card-body py-2">
                <h6 class="mb-1 font-weight-bold">${escapeHtml(nombre)}</h6>
                <small class="text-muted">
                    <i class="fas fa-tag mr-1"></i> ${tipoInventario === 'producto' ? 'Producto' : 'Insumo'}
                </small>
            </div>
        </div>
    `;
    document.getElementById('stock_producto_info').innerHTML = infoHtml;
    
    document.getElementById('stock_cantidad').value = '';
    document.getElementById('stock_nota').value = '';
    
    setTimeout(() => {
        document.getElementById('stock_cantidad').focus();
    }, 500);
    
    $('#modalAgregarStock').modal('show');
}

// ===== FUNCIÓN PARA ABRIR MODAL DE AJUSTAR STOCK =====
function abrirModalAjustarStock(id, nombre, cantidadActual, tipoInventario) {
    document.getElementById('ajuste_producto_id').value = id;
    document.getElementById('ajuste_tipo_inventario').value = tipoInventario;
    
    // Determinar la unidad
    let unidad = 'pz';
    if (tipoInventario === 'insumo') {
        // Aquí deberías obtener la unidad del insumo (similar a como lo haces en agregar stock)
        fetch(`get_producto.php?id=${id}`)
            .then(response => response.json())
            .then(data => {
                if (data.success && data.producto.atributos_array) {
                    if (data.producto.atributos_array.tipo_unidad === 'metros') {
                        unidad = 'm';
                    }
                }
                document.getElementById('ajuste_unidad_actual').textContent = unidad;
                document.getElementById('ajuste_unidad_nueva').textContent = unidad;
            })
            .catch(error => {
                console.error('Error:', error);
            });
    } else {
        document.getElementById('ajuste_unidad_actual').textContent = 'pz';
        document.getElementById('ajuste_unidad_nueva').textContent = 'pz';
    }
    
    document.getElementById('ajuste_stock_actual').value = cantidadActual;
    document.getElementById('ajuste_nueva_cantidad').value = cantidadActual;
    document.getElementById('ajuste_razon').value = '';
    
    const infoHtml = `
        <div class="card card-warning card-outline">
            <div class="card-body py-2">
                <h6 class="mb-1 font-weight-bold">${escapeHtml(nombre)}</h6>
                <small class="text-muted">
                    <i class="fas fa-tag mr-1"></i> ${tipoInventario === 'producto' ? 'Producto' : 'Insumo'}
                </small>
            </div>
        </div>
    `;
    document.getElementById('ajuste_producto_info').innerHTML = infoHtml;
    
    // Agregar evento para calcular diferencia en tiempo real
    document.getElementById('ajuste_nueva_cantidad').removeEventListener('input', calcularDiferencia);
    document.getElementById('ajuste_nueva_cantidad').addEventListener('input', function() {
        calcularDiferencia(cantidadActual, this.value);
    });
    
    $('#modalAjustarStock').modal('show');
}

function calcularDiferencia(actual, nueva) {
    const dif = parseFloat(nueva) - parseFloat(actual);
    const span = document.getElementById('ajuste_diferencia');
    if (dif > 0) {
        span.innerHTML = `Diferencia: <span class="text-success">+${dif.toFixed(2)}</span> (se agregará stock)`;
    } else if (dif < 0) {
        span.innerHTML = `Diferencia: <span class="text-danger">${dif.toFixed(2)}</span> (se quitará stock)`;
    } else {
        span.innerHTML = `Diferencia: 0 (sin cambios)`;
    }
}

// ===== FUNCIÓN PARA CAMBIAR UNIDAD DE INSUMO (MODIFICADA) =====
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
        unidadHelp.innerHTML = '<i class="fas fa-ruler mr-1"></i> Cantidad en metros (puede usar decimales)';
        infoAdicional.textContent = 'Se miden en metros (útil para DTF, telas, vinilos, etc.)';
        anchoGroup.style.display = 'block';
    } else {
        cantidadInput.step = '1';
        cantidadInput.min = '1';
        cantidadInput.placeholder = 'Ej. 100';
        unidadLabel.textContent = 'pz';
        precioUnidadLabel.textContent = '/pz';
        unidadHelp.innerHTML = '<i class="fas fa-cubes mr-1"></i> Cantidad en piezas (números enteros)';
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
    
    // Si el modal de stock está abierto, actualizar la unidad
    if ($('#modalAgregarStock').hasClass('show')) {
        document.getElementById('stock_unidad').textContent = tipo === 'metros' ? 'm' : 'pz';
    }
}

// ===== FUNCIONES PARA LA TABLA =====
function actualizarTabla(filtro) {
    const tablaBody = document.getElementById('tablaBody');
    tablaBody.style.opacity = '0.5';
    
    fetch(`ajax_get_productos.php?tipo=${filtro}`)
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                renderizarTabla(data.productos);
                document.getElementById('totalRegistros').innerHTML = `Total: <strong>${data.total}</strong> registros`;
            }
            tablaBody.style.opacity = '1';
        })
        .catch(error => {
            console.error('Error:', error);
            tablaBody.style.opacity = '1';
        });
}

function renderizarTabla(productos) {
    const tablaBody = document.getElementById('tablaBody');
    
    if (!productos || productos.length === 0) {
        tablaBody.innerHTML = `
            <tr class="empty-row">
                <td colspan="10" class="text-center py-5">
                    <div class="empty-state">
                        <i class="fas fa-box-open fa-4x text-muted mb-3"></i>
                        <h5 class="text-muted">No hay artículos registrados</h5>
                        <p class="text-muted mb-3">Comienza agregando tu primer producto o insumo</p>
                    </div>
                </td>
            </tr>
        `;
    } else {
        let html = '';
        productos.forEach(p => {
            const badgeClass = p.cantidad <= 0 ? 'badge-danger' : (p.cantidad <= 5 ? 'badge-warning' : 'badge-success');
            const cantidadDisplay = p.tipo_inventario == 'insumo' ? 
                parseFloat(p.cantidad).toFixed(2) + ' m' : 
                parseInt(p.cantidad) + ' pz';
            
            const atributosHtml = p.atributos_array && Object.keys(p.atributos_array).length > 0 ? 
                `<i class="fas fa-info-circle text-primary ml-1" data-toggle="tooltip" title="${Object.entries(p.atributos_array).map(([k,v]) => `${k}: ${v}`).join(', ')}"></i>` : '';
            
            const imagenHtml = p.imagen && p.imagen_exists ? 
                `<img src="${p.imagen}" class="img-thumbnail" style="width:50px;height:50px;object-fit:cover;" alt="Producto">` : 
                '<span class="badge badge-secondary">Sin imagen</span>';
            
            const precioVentaHtml = p.tipo_inventario == 'producto' ? 
                '$' + parseFloat(p.precio_venta).toFixed(2) : 
                '<span class="text-muted">-</span>';
            
            const pdfHtml = p.tipo_inventario == 'producto' && p.pdf_exists ? 
                `<a href="uploads/codigos/producto_${p.id}.pdf" class="btn btn-outline-success btn-sm" target="_blank"><i class="far fa-file-pdf"></i></a>` : 
                '<span class="text-muted">—</span>';
            
            html += `
                <tr data-id="${p.id}" data-tipo="${p.tipo_inventario}">
                    <td>
                        ${p.tipo_inventario == 'producto' ? 
                            '<span class="badge-tipo badge-producto"><i class="fas fa-box"></i> Producto</span>' : 
                            '<span class="badge-tipo badge-insumo"><i class="fas fa-cubes"></i> Insumo</span>'}
                    </td>
                    <td class="font-weight-bold">
                        ${escapeHtml(p.nombre)} ${atributosHtml}
                    </td>
                    <td><span class="badge badge-info">${escapeHtml(p.categoria || 'General')}</span></td>
                    <td>${escapeHtml(p.proveedor || '-')}</td>
                    <td class="text-center">${imagenHtml}</td>
                    <td class="text-center"><span class="badge ${badgeClass}">${cantidadDisplay}</span></td>
                    <td class="text-right">$${parseFloat(p.precio_compra).toFixed(2)}</td>
                    <td class="text-right font-weight-bold text-success">${precioVentaHtml}</td>
                    <td class="text-center">${pdfHtml}</td>
                    <td class="text-center">
                        <div class="btn-group btn-group-sm">
                            <button class="btn btn-success" title="Agregar stock" onclick="abrirModalAgregarStock(${p.id}, '${escapeHtml(p.nombre)}', ${p.cantidad}, '${p.tipo_inventario}')">
                                <i class="fas fa-plus-circle"></i>
                            </button>
                            <button class="btn btn-warning" title="Ajustar stock" onclick="abrirModalAjustarStock(${p.id}, '${escapeHtml(p.nombre)}', ${p.cantidad}, '${p.tipo_inventario}')">
                                <i class="fas fa-sliders-h"></i>
                            </button>
                            <button class="btn btn-info" title="Editar" onclick="editarProducto(${p.id})">
                                <i class="fas fa-edit"></i>
                            </button>
                            <button class="btn btn-danger" title="Eliminar" onclick="confirmarEliminar(${p.id})">
                                <i class="fas fa-trash"></i>
                            </button>
                        </div>
                    </td>
                </tr>
            `;
        });
        tablaBody.innerHTML = html;
        $('[data-toggle="tooltip"]').tooltip();
    }
}

function escapeHtml(text) {
    if (!text) return '';
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

// Eventos para filtros
document.querySelectorAll('.btn-filtro-tabla').forEach(btn => {
    btn.addEventListener('click', function(e) {
        e.preventDefault();
        document.querySelectorAll('.btn-filtro-tabla').forEach(b => b.classList.remove('active'));
        this.classList.add('active');
        filtroActual = this.dataset.tipo;
        actualizarTabla(filtroActual);
        const url = new URL(window.location);
        url.searchParams.set('tipo', filtroActual);
        window.history.pushState({}, '', url);
    });
});

// Búsqueda en tabla
document.getElementById('searchProductos').addEventListener('keyup', function() {
    const filtro = this.value.toLowerCase();
    const filas = document.querySelectorAll('#tablaBody tr:not(.empty-row)');
    let visibles = 0;
    
    filas.forEach(fila => {
        const texto = fila.innerText.toLowerCase();
        if (texto.includes(filtro)) {
            fila.style.display = '';
            visibles++;
        } else {
            fila.style.display = 'none';
        }
    });
    
    const emptyRow = document.querySelector('#tablaBody tr.empty-row');
    if (visibles === 0 && !emptyRow) {
        const tablaBody = document.getElementById('tablaBody');
        tablaBody.innerHTML += `
            <tr class="empty-row">
                <td colspan="10" class="text-center py-4">
                    <i class="fas fa-search fa-2x text-muted mb-2"></i>
                    <p class="text-muted">No se encontraron resultados para "${filtro}"</p>
                </td>
            </tr>
        `;
    } else if (visibles > 0 && emptyRow) {
        emptyRow.remove();
    }
});

// Funciones para editar y eliminar
function editarProducto(id) {
    fetch(`get_producto.php?id=${id}`)
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                const p = data.producto;
                
                $('#edit_id').val(p.id);
                $('#edit_nombre').val(p.nombre);
                $('#edit_categoria').val(p.categoria);
                $('#edit_proveedor').val(p.proveedor || '');
                $('#edit_precio_compra').val(p.precio_compra);
                $('#edit_precio_venta').val(p.precio_venta);
                $('#edit_tipo_codigo').val(p.tipo_codigo);
                $('#edit_tipo_inventario').val(p.tipo_inventario);
                
                // Mostrar cantidad actual con la unidad correcta
                let cantidadDisplay = '';
                if (p.tipo_inventario == 'insumo') {
                    // Verificar si es metros o piezas
                    if (p.atributos_array && p.atributos_array.tipo_unidad === 'metros') {
                        cantidadDisplay = parseFloat(p.cantidad).toFixed(2) + ' m';
                    } else {
                        cantidadDisplay = parseInt(p.cantidad) + ' pz';
                    }
                } else {
                    cantidadDisplay = parseInt(p.cantidad) + ' pz';
                }
                $('#edit_cantidad_actual').text(cantidadDisplay);
                
                if (p.atributos_array) {
                    $('#edit_marca').val(p.atributos_array.marca || '');
                    $('#edit_color').val(p.atributos_array.color || '');
                    $('#edit_talla').val(p.atributos_array.talla || '');
                    $('#edit_material').val(p.atributos_array.material || '');
                }
                
                if (p.tipo_inventario === 'producto') {
                    $('#edit_precio_venta_group').show();
                    $('#edit_tipo_codigo_group').show();
                    $('#edit_atributos_section').show();
                } else {
                    $('#edit_precio_venta_group').hide();
                    $('#edit_tipo_codigo_group').hide();
                    $('#edit_atributos_section').hide();
                }
                
                $('#modalEditar').modal('show');
            }
        })
        .catch(error => {
            Swal.fire('Error', 'No se pudo cargar el producto', 'error');
        });
}

function confirmarEliminar(id) {
    Swal.fire({
        title: "¿Eliminar?",
        text: "Esta acción no se puede deshacer",
        icon: "warning",
        showCancelButton: true,
        confirmButtonText: "Sí, eliminar",
        cancelButtonText: "Cancelar"
    }).then((result) => {
        if (result.isConfirmed) {
            document.getElementById("delete_id").value = id;
            document.getElementById("formEliminar").submit();
        }
    });
}

// Limpiar formulario
function limpiarFormulario() {
    const form = document.getElementById('formProducto');
    form.reset();
    document.getElementById('previewImg').classList.add('d-none');
    cambiarTipoFormulario('producto');
}

// Preview imagen
function previewImagen(event) {
    const img = document.getElementById('previewImg');
    img.src = URL.createObjectURL(event.target.files[0]);
    img.classList.remove('d-none');
}

function agregarNuevaCategoria() {
    Swal.fire({
        title: 'Nueva categoría',
        input: 'text',
        inputPlaceholder: 'Nombre de la categoría',
        showCancelButton: true,
        confirmButtonText: 'Agregar',
        cancelButtonText: 'Cancelar'
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

// Script alerta
// Constante para la clave de localStorage
const STORAGE_KEY = 'ocultarAlertaStock';

// Función para ocultar la alerta permanentemente
function ocultarAlertaStockPermanente() {
    console.log('Función ocultarAlertaStockPermanente() ejecutada');
    
    const alerta = document.getElementById('stockInfoAlert');
    const btnContainer = document.getElementById('btnMostrarAlertaContainer');
    
    if (alerta && btnContainer) {
        alerta.style.display = 'none';
        btnContainer.style.display = 'block';
        
        // Guardar en localStorage
        localStorage.setItem(STORAGE_KEY, 'true');
        console.log('Alerta oculta. localStorage guardado con valor: true');
        
        // Verificar que se guardó
        console.log('Verificación localStorage:', localStorage.getItem(STORAGE_KEY));
    } else {
        console.log('Error: No se encontraron los elementos');
    }
}

// Función para mostrar la alerta
function mostrarAlertaStock() {
    console.log('Función mostrarAlertaStock() ejecutada');
    
    const alerta = document.getElementById('stockInfoAlert');
    const btnContainer = document.getElementById('btnMostrarAlertaContainer');
    
    if (alerta && btnContainer) {
        alerta.style.display = 'block';
        btnContainer.style.display = 'none';
        
        // Eliminar la preferencia
        localStorage.removeItem(STORAGE_KEY);
        console.log('Alerta visible. localStorage eliminado');
    } else {
        console.log('Error: No se encontraron los elementos');
    }
}

// Función para verificar el estado
function verificarEstadoAlerta() {
    console.log('Verificando estado de alerta...');
    
    const alerta = document.getElementById('stockInfoAlert');
    const btnContainer = document.getElementById('btnMostrarAlertaContainer');
    
    if (!alerta || !btnContainer) {
        console.log('Elementos no encontrados, esperando 200ms...');
        setTimeout(verificarEstadoAlerta, 200);
        return;
    }
    
    // Leer localStorage
    const alertaOculta = localStorage.getItem(STORAGE_KEY);
    console.log('localStorage value:', alertaOculta);
    
    if (alertaOculta === 'true') {
        alerta.style.display = 'none';
        btnContainer.style.display = 'block';
        console.log('Estado final: ALERTA OCULTA');
    } else {
        alerta.style.display = 'block';
        btnContainer.style.display = 'none';
        console.log(' Estado final: ALERTA VISIBLE');
    }
}

// Evento cuando se abre el modal
document.addEventListener('DOMContentLoaded', function() {
    console.log('DOM completamente cargado');
    
    const modal = document.getElementById('modalAgregarStock');
    
    if (modal) {
        modal.addEventListener('show.bs.modal', function() {
            console.log('Evento: Modal abierto');
            verificarEstadoAlerta();
        });
        
        console.log(' Event listener agregado al modal');
    } else {
        console.log('Modal no encontrado en el DOM');
    }
    
    // Verificar estado inicial también
    setTimeout(verificarEstadoAlerta, 500);
});

// Funciones de utilidad para consola
window.verificarStorage = function() {
    console.log('Contenido de localStorage:');
    console.log('-', STORAGE_KEY + ':', localStorage.getItem(STORAGE_KEY));
    console.log('- Todos los items:', {...localStorage});
};

window.forzarOcultar = function() {
    localStorage.setItem(STORAGE_KEY, 'true');
    verificarEstadoAlerta();
    console.log('Forzado a ocultar');
};

window.forzarMostrar = function() {
    localStorage.removeItem(STORAGE_KEY);
    verificarEstadoAlerta();
    console.log('Forzado a mostrar');
};

// Verificación inmediata
console.log('Script cargado, localStorage actual:', localStorage.getItem(STORAGE_KEY));

// Función para inicializar el estado de las alertas
function inicializarAlertas() {
    // Verificar estado guardado para alerta importante
    const importanteOculta = localStorage.getItem('alertaImportanteOculta');
    if (importanteOculta === 'true') {
        document.getElementById('alertaImportante').style.display = 'none';
        document.getElementById('btnMostrarImportante').style.display = 'block';
    } else {
        document.getElementById('alertaImportante').style.display = 'block';
        document.getElementById('btnMostrarImportante').style.display = 'none';
    }
    
    // Verificar estado guardado para alerta info
    const infoOculta = localStorage.getItem('alertaInfoOculta');
    if (infoOculta === 'true') {
        document.getElementById('alertaInfo').style.display = 'none';
        document.getElementById('btnMostrarInfo').style.display = 'block';
    } else {
        document.getElementById('alertaInfo').style.display = 'block';
        document.getElementById('btnMostrarInfo').style.display = 'none';
    }
}

// Función para ocultar alerta
function ocultarAlerta(tipo) {
    if (tipo === 'importante') {
        document.getElementById('alertaImportante').style.display = 'none';
        document.getElementById('btnMostrarImportante').style.display = 'block';
        localStorage.setItem('alertaImportanteOculta', 'true');
    } else if (tipo === 'info') {
        document.getElementById('alertaInfo').style.display = 'none';
        document.getElementById('btnMostrarInfo').style.display = 'block';
        localStorage.setItem('alertaInfoOculta', 'true');
    }
}

// Función para mostrar alerta
function mostrarAlerta(tipo) {
    if (tipo === 'importante') {
        document.getElementById('alertaImportante').style.display = 'block';
        document.getElementById('btnMostrarImportante').style.display = 'none';
        localStorage.setItem('alertaImportanteOculta', 'false');
    } else if (tipo === 'info') {
        document.getElementById('alertaInfo').style.display = 'block';
        document.getElementById('btnMostrarInfo').style.display = 'none';
        localStorage.setItem('alertaInfoOculta', 'false');
    }
}

// Inicializar cuando se carga la página
document.addEventListener('DOMContentLoaded', function() {
    inicializarAlertas();
});

// También inicializar cada vez que se abre el modal (por si cambió mientras estaba cerrado)
$('#modalAjustarStock').on('show.bs.modal', function() {
    inicializarAlertas();
});

</script>

<?php
ob_end_flush();
?>