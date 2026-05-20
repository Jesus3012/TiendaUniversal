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
    $result = $conn->query("SELECT id, nombre FROM proveedores WHERE activo = 1 ORDER BY nombre");
    $proveedores = [];
    while ($row = $result->fetch_assoc()) {
        $proveedores[] = ['id' => $row['id'], 'nombre' => $row['nombre']];
    }
    return $proveedores;
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
                
                if ($tipo_inventario === 'producto' && $producto['tipo_codigo'] === 'multiple') {
                    $stmt = $conn->prepare("SELECT codigo FROM codigos_barras WHERE producto_id = ? ORDER BY codigo DESC LIMIT 1");
                    $stmt->bind_param("i", $producto_id);
                    $stmt->execute();
                    $res_ultimo = $stmt->get_result();
                    
                    $ultimo_codigo = 0;
                    if ($res_ultimo->num_rows > 0) {
                        $row = $res_ultimo->fetch_assoc();
                        $ultimo_codigo = intval(substr($row['codigo'], strlen($producto_id)));
                    }
                    
                    for ($i = $ultimo_codigo + 1; $i <= $ultimo_codigo + $cantidad_agregar; $i++) {
                        $nuevo_codigo = $producto_id . str_pad($i, 5, '0', STR_PAD_LEFT);
                        $stmt = $conn->prepare("INSERT INTO codigos_barras (producto_id, codigo, disponible) VALUES (?, ?, 1)");
                        $stmt->bind_param("is", $producto_id, $nuevo_codigo);
                        $stmt->execute();
                    }
                    
                    generarPDFCodigos($conn, $producto['nombre'], $producto_id, $cantidad_nueva, 'multiple');
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
                
                // ... resto del código (códigos de barras, etc.)
                
                $conn->commit();
                
                // ... mensaje de éxito
                
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
    
    // Si el select envió '__NUEVA__', tomar del campo categoria_nueva
    if ($categoria === '__NUEVA__') {
        if (isset($_POST['categoria_nueva']) && !empty($_POST['categoria_nueva'])) {
            $categoria = trim($_POST['categoria_nueva']);
        } else {
            $errors[] = "Debe escribir el nombre de la nueva categoría.";
        }
    }
    
    // Validar categoría
    if (empty($categoria)) {
        $errors[] = "La categoría es requerida.";
    }
    
    // ===== PROVEEDOR =====
    $proveedor_id = null;
    $proveedor_nombre = null;
    
    // Si es un proveedor existente (viene como ID numérico)
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
    // Si es un nuevo proveedor
    elseif (isset($_POST['proveedor_nuevo']) && !empty($_POST['proveedor_nuevo'])) {
        $nuevo_nombre = trim($_POST['proveedor_nuevo']);
        
        // Verificar si ya existe
        $stmt = $conn->prepare("SELECT id, nombre FROM proveedores WHERE nombre = ?");
        $stmt->bind_param("s", $nuevo_nombre);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($row = $result->fetch_assoc()) {
            $proveedor_id = $row['id'];
            $proveedor_nombre = $row['nombre'];
        } else {
            // Insertar nuevo proveedor
            $stmt = $conn->prepare("INSERT INTO proveedores (nombre, activo) VALUES (?, 1)");
            $stmt->bind_param("s", $nuevo_nombre);
            if ($stmt->execute()) {
                $proveedor_id = $conn->insert_id;
                $proveedor_nombre = $nuevo_nombre;
            }
        }
    }
    
    // Si hay errores, mostrarlos
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
        // Resto del código de actualización...
        $precio_compra = floatval($_POST['precio_compra']);
        $precio_venta = floatval($_POST['precio_venta']);
        $tipo_codigo = $_POST['tipo_codigo'] ?? 'multiple';
        $tipo_inventario = $_POST['tipo_inventario'] ?? 'producto';
        
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
        $cantidad_actual = $producto_actual['cantidad'];

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

        $tipo_adquisicion = $_POST['tipo_adquisicion'] ?? 'pagado';

        // Actualizar producto
        if ($imagen_path) {
            $stmt = $conn->prepare("UPDATE productos SET nombre=?, categoria=?, atributos=?, proveedor=?, proveedor_id=?, imagen=?, precio_compra=?, precio_venta=?, tipo_codigo=?, tipo_inventario=?, tipo_adquisicion=? WHERE id=?");
            $stmt->bind_param("ssssissddssi", 
                $nombre, $categoria, $atributos_json, $proveedor_nombre, $proveedor_id, 
                $imagen_path, $precio_compra, $precio_venta, $tipo_codigo, $tipo_inventario, $tipo_adquisicion, $id
            );
        } else {
            $stmt = $conn->prepare("UPDATE productos SET nombre=?, categoria=?, atributos=?, proveedor=?, proveedor_id=?, precio_compra=?, precio_venta=?, tipo_codigo=?, tipo_inventario=?, tipo_adquisicion=? WHERE id=?");
            $stmt->bind_param("sssssiddssi", 
                $nombre, $categoria, $atributos_json, $proveedor_nombre, $proveedor_id, 
                $precio_compra, $precio_venta, $tipo_codigo, $tipo_inventario, $tipo_adquisicion, $id
            );
        }

        if ($stmt->execute()) {
            if ($tipo_inventario === 'producto') {
                $conn->query("DELETE FROM codigos_barras WHERE producto_id = $id");
                $old_pdf = __DIR__ . '/uploads/codigos/producto_' . $id . '.pdf';
                if (file_exists($old_pdf)) @unlink($old_pdf);
                generarCodigosBarras($conn, $nombre, $id, $cantidad_actual, $tipo_codigo, $tipo_inventario);
            }
            
            echo "<script>
            Swal.fire({
                icon: 'success',
                title: 'Producto actualizado',
                text: 'Los cambios se guardaron correctamente.',
                confirmButtonColor: '#f97316'
            }).then(() => {
                window.location='ajustes_productos.php';
            });
            </script>";
            exit;
        } else {
            echo "<script>
            Swal.fire({
                icon: 'error',
                title: 'Error al actualizar',
                text: 'Error: " . addslashes($stmt->error) . "',
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

    $pdf_file = __DIR__ . '/uploads/codigos/producto_' . $id . '.pdf';
    if (file_exists($pdf_file)) unlink($pdf_file);

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
        window.location='inventario.php';
    });
    </script>";
    exit;
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

// ========================= PDF CON TODOS LOS CÓDIGOS =========================
if (isset($_GET['action']) && $_GET['action'] === 'todos_codigos') {
    // Limpiar cualquier salida previa
    ob_clean();
    header('Content-Type: application/pdf');
    header('Content-Disposition: inline; filename="todos_codigos_barras.pdf"');
    
    generarPDFTodosCodigos($conn);
    exit;
}

function generarPDFTodosCodigos($conn) {
    $generator = new BarcodeGeneratorPNG();
    $pdf = new FPDF('P', 'mm', 'A4');
    $pdf->SetAutoPageBreak(false); // 🔥 DESACTIVAR auto page break
    $pdf->SetMargins(8, 15, 8);
    
    // Obtener datos de la tienda
    $sqlConfig = "SELECT nombre, logo, telefono, direccion FROM configuracion_galeria LIMIT 1";
    $resultConfig = $conn->query($sqlConfig);
    $configTienda = $resultConfig->fetch_assoc();
    $nombreTienda = $configTienda['nombre'] ?? 'TIENDA PESCADORES';
    $telefono = $configTienda['telefono'] ?? '';
    $direccion = $configTienda['direccion'] ?? '';
    $logoTiendaPath = $configTienda['logo'] ?? '';
    
    // Buscar logo tienda
    $logoPathAbsoluto = '';
    if (!empty($logoTiendaPath) && file_exists($logoTiendaPath)) {
        $logoPathAbsoluto = $logoTiendaPath;
    } else {
        $rutasPosibles = [
            '../img/logo.png', '../img/logo.jpg', '../img/panel_principal.jpg',
            '../img/panel_principal.png', '../dist/img/logo.png', '../dist/img/logo.jpg',
            'img/logo.png', 'img/logo.jpg'
        ];
        foreach ($rutasPosibles as $ruta) {
            if (file_exists($ruta)) {
                $logoPathAbsoluto = $ruta;
                break;
            }
        }
    }
    
    // Consulta para obtener productos
    $query = "SELECT 
                p.id,
                p.nombre, 
                p.imagen,
                p.categoria,
                p.proveedor,
                GROUP_CONCAT(cb.codigo ORDER BY cb.codigo SEPARATOR ',') as codigos
              FROM productos p
              JOIN codigos_barras cb ON p.id = cb.producto_id
              WHERE p.activo = 1 AND p.tipo_inventario = 'producto'
              GROUP BY p.id, p.nombre, p.imagen, p.categoria, p.proveedor
              ORDER BY p.nombre ASC";
    $res = $conn->query($query);
    
    if ($res->num_rows === 0) {
        $pdf->AddPage();
        $pdf->SetFont('Arial', 'B', 16);
        $pdf->Cell(0, 10, 'No hay códigos de barras para mostrar', 0, 1, 'C');
        $pdf->Output('I', 'todos_codigos_barras.pdf');
        exit;
    }
    
    $productos = [];
    while ($row = $res->fetch_assoc()) {
        $row['codigos_array'] = explode(',', $row['codigos']);
        $productos[] = $row;
    }
    
    // Colores
    $colorNaranja = [255, 140, 0];
    $colorGris = [100, 100, 100];
    
    // Configuración de página
    $anchoCard = 92;
    $altoCard = 85;
    $margenX = 10;
    $margenY = 68;
    $espaciadoX = 97;
    $espaciadoY = 90;
    $codigosPorPagina = 4;
    
    $totalProductos = count($productos);
    $productoIndex = 0;
    $paginaActual = 1;
    $totalPaginas = ceil($totalProductos / $codigosPorPagina);
    
    // Función para dibujar el encabezado
    function drawHeader($pdf, $nombreTienda, $direccion, $telefono, $logoPathAbsoluto, $colorNaranja, $colorGris) {
        if (!empty($logoPathAbsoluto) && file_exists($logoPathAbsoluto)) {
            $pdf->Image($logoPathAbsoluto, 12, 8, 22, 22);
        }
        
        $pdf->SetY(10);
        $pdf->SetFont('Arial', 'B', 13);
        $pdf->SetTextColor($colorNaranja[0], $colorNaranja[1], $colorNaranja[2]);
        $pdf->Cell(0, 6, utf8_decode(strtoupper($nombreTienda)), 0, 1, 'C');
        
        $pdf->SetFont('Arial', '', 7);
        $pdf->SetTextColor($colorGris[0], $colorGris[1], $colorGris[2]);
        if (!empty($direccion)) {
            $pdf->Cell(0, 4, utf8_decode($direccion), 0, 1, 'C');
        }
        if (!empty($telefono)) {
            $pdf->Cell(0, 4, 'Tel: ' . $telefono, 0, 1, 'C');
        }
        
        $pdf->SetDrawColor($colorNaranja[0], $colorNaranja[1], $colorNaranja[2]);
        $pdf->SetLineWidth(0.5);
        $pdf->Line(10, 38, 200, 38);
        
        $pdf->SetY(44);
        $pdf->SetFont('Arial', 'B', 14);
        $pdf->SetTextColor(0, 0, 0);
        $pdf->Cell(0, 7, 'CODIGOS DE BARRAS POR PRODUCTO', 0, 1, 'C');
        
        $pdf->SetFont('Arial', 'I', 7);
        $pdf->SetTextColor(128, 128, 128);
        $pdf->Cell(0, 4, 'Generado: ' . date('d/m/Y H:i'), 0, 1, 'C');
        $pdf->Ln(6);
    }
    
    // Función para dibujar el pie de página
    function drawFooter($pdf, $nombreTienda, $paginaActual, $totalPaginas, $colorNaranja) {
        $pdf->SetDrawColor(220, 220, 220);
        $pdf->Line(10, 260, 200, 260);
        
        $pdf->SetFont('Arial', 'I', 7);
        $pdf->SetTextColor(128, 128, 128);
        $pdf->SetY(263);
        $pdf->Cell(0, 4, utf8_decode($nombreTienda) . ' - Sistema de Inventario', 0, 0, 'C');
        $pdf->Cell(0, 4, 'Página ' . $paginaActual . ' de ' . $totalPaginas, 0, 1, 'R');
        
        $pdf->SetY(270);
        $pdf->SetFont('Arial', 'I', 6);
        $pdf->Cell(0, 3, 'Escanee el código de barras para registrar la venta del producto.', 0, 1, 'C');
        $pdf->Cell(0, 3, 'Productos ordenados alfabéticamente de la A a la Z.', 0, 1, 'C');
    }
    
    // Bucle para crear páginas
    while ($productoIndex < $totalProductos) {
        $pdf->AddPage();
        
        // Dibujar encabezado
        drawHeader($pdf, $nombreTienda, $direccion, $telefono, $logoPathAbsoluto, $colorNaranja, $colorGris);
        
        // Dibujar productos en esta página (máximo 4)
        $productosEnPagina = min($codigosPorPagina, $totalProductos - $productoIndex);
        
        for ($i = 0; $i < $productosEnPagina; $i++) {
            $producto = $productos[$productoIndex];
            $col = $i % 2;
            $fila = floor($i / 2);
            $x = $margenX + ($col * $espaciadoX);
            $y = $margenY + ($fila * $espaciadoY);
            
            // Fondo de tarjeta
            $pdf->SetDrawColor(220, 220, 220);
            $pdf->SetFillColor(255, 255, 255);
            $pdf->Rect($x, $y, $anchoCard, $altoCard, 'DF');
            
            // Borde superior naranja
            $pdf->SetDrawColor($colorNaranja[0], $colorNaranja[1], $colorNaranja[2]);
            $pdf->SetLineWidth(1.5);
            $pdf->Line($x, $y, $x + $anchoCard, $y);
            $pdf->SetLineWidth(0.2);
            
            // ==== IMAGEN O INICIALES ====
            $imgX = $x + 6;
            $imgY = $y + 6;
            $imgSize = 38;
            
            $pdf->SetDrawColor(230, 230, 230);
            $pdf->SetFillColor(250, 250, 250);
            $pdf->Rect($imgX, $imgY, $imgSize, $imgSize, 'DF');
            
            // Buscar imagen
            $rutaImagen = '';
            $imageFound = false;
            
            if (!empty($producto['imagen']) && file_exists($producto['imagen'])) {
                $rutaImagen = $producto['imagen'];
                $imageFound = true;
            }
            
            if (!$imageFound) {
                $nombreImagenBase = strtolower(str_replace(' ', '_', $producto['nombre']));
                $extensiones = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
                $rutasBuscar = ['uploads/productos/', '../uploads/productos/'];
                
                foreach ($rutasBuscar as $rutaBase) {
                    foreach ($extensiones as $ext) {
                        $ruta = $rutaBase . $nombreImagenBase . '.' . $ext;
                        if (file_exists($ruta)) {
                            $rutaImagen = $ruta;
                            $imageFound = true;
                            break 2;
                        }
                    }
                }
            }
            
            if (!$imageFound) {
                foreach ($rutasBuscar as $rutaBase) {
                    foreach ($extensiones as $ext) {
                        $ruta = $rutaBase . $producto['id'] . '.' . $ext;
                        if (file_exists($ruta)) {
                            $rutaImagen = $ruta;
                            $imageFound = true;
                            break 2;
                        }
                    }
                }
            }
            
            if ($imageFound && file_exists($rutaImagen)) {
                $pdf->Image($rutaImagen, $imgX + 2, $imgY + 2, $imgSize - 4, $imgSize - 4);
            } else {
                $iniciales = strtoupper(substr($producto['nombre'], 0, 2));
                $pdf->SetFont('Arial', 'B', 20);
                $pdf->SetTextColor($colorNaranja[0], $colorNaranja[1], $colorNaranja[2]);
                $pdf->SetXY($imgX + $imgSize/2 - 8, $imgY + $imgSize/2 - 8);
                $pdf->Cell(16, 16, $iniciales, 0, 0, 'C');
                $pdf->SetTextColor(0, 0, 0);
            }
            
            // ==== INFORMACIÓN ====
            $infoX = $x + 50;
            $infoY = $y + 8;
            
            $pdf->SetFont('Arial', 'B', 9);
            $pdf->SetTextColor(40, 40, 40);
            $pdf->SetXY($infoX, $infoY);
            $nombre = utf8_decode(substr($producto['nombre'], 0, 28));
            $pdf->Cell(36, 5, $nombre, 0, 1);
            
            $pdf->SetFont('Arial', '', 7);
            $pdf->SetTextColor($colorGris[0], $colorGris[1], $colorGris[2]);
            $pdf->SetXY($infoX, $infoY + 7);
            $pdf->Cell(36, 4, 'Cat: ' . utf8_decode(substr($producto['categoria'], 0, 20)), 0, 1);
            
            if (!empty($producto['proveedor'])) {
                $pdf->SetXY($infoX, $infoY + 12);
                $pdf->Cell(36, 4, 'Prov: ' . utf8_decode(substr($producto['proveedor'], 0, 18)), 0, 1);
            }
            
            // ==== CÓDIGO DE BARRAS ====
            $codigoPrincipal = $producto['codigos_array'][0];
            $pngData = $generator->getBarcode($codigoPrincipal, $generator::TYPE_CODE_128);
            $tmp = tempnam(sys_get_temp_dir(), 'bc_') . '.png';
            file_put_contents($tmp, $pngData);
            
            $barcodeX = $x + ($anchoCard / 2) - 35;
            $barcodeY = $y + 48;
            $pdf->Image($tmp, $barcodeX, $barcodeY, 70, 18, 'PNG');
            @unlink($tmp);
            
            $pdf->SetFont('Arial', '', 7);
            $pdf->SetTextColor($colorGris[0], $colorGris[1], $colorGris[2]);
            $pdf->SetXY($x + 10, $barcodeY + 20);
            $pdf->Cell($anchoCard - 20, 5, $codigoPrincipal, 0, 1, 'C');
            
            $totalCodigos = count($producto['codigos_array']);
            $pdf->SetFont('Arial', 'I', 6);
            $pdf->SetTextColor(150, 150, 150);
            $pdf->SetXY($x + 10, $barcodeY + 26);
            $pdf->Cell($anchoCard - 20, 4, $totalCodigos . ' código(s)', 0, 1, 'C');
            
            $productoIndex++;
        }
        
        // Dibujar pie de página
        drawFooter($pdf, $nombreTienda, $paginaActual, $totalPaginas, $colorNaranja);
        $paginaActual++;
    }
    
    $pdf->Output('I', 'todos_codigos_barras.pdf');
    exit;
}

// ========================= CONSULTAR PRODUCTOS =========================
$query = "SELECT * FROM productos WHERE activo = 1 ORDER BY id DESC";

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

<link rel="stylesheet" href="css/ajustes_productos.css">

<div class="content-wrapper">
    <div class="container-fluid">
        
        <!-- BREADCRUMB -->
        <div class="custom-breadcrumb">
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb">
                    <li class="breadcrumb-item">
                        <a href="<?= $_SESSION['rol'] === 'administrador' ? 'dashboard_admin.php' : 'dashboard_vendedor.php' ?>">
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
                                        <th class="text-white text-center">PDF</th>
                                        <th class="text-white text-center">Acciones</th>
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
                                        <tr class="producto-fila" data-tipo="<?= $p['tipo_inventario'] ?>" data-nombre="<?= strtolower(htmlspecialchars($p['nombre'])) ?>" data-categoria="<?= strtolower(htmlspecialchars($p['categoria'] ?? '')) ?>" data-proveedor="<?= strtolower(htmlspecialchars($p['proveedor'] ?? '')) ?>">
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
                                                    <?php $pdf_file = 'uploads/codigos/producto_' . $p['id'] . '.pdf'; ?>
                                                    <?php if (file_exists($pdf_file)): ?>
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
                                            <td class="text-center">
                                                <div class="btn-group btn-group-sm">
                                                    <button class="btn btn-success" title="Agregar stock" onclick="abrirModalAgregarStock(<?= $p['id'] ?>, '<?= htmlspecialchars($p['nombre'], ENT_QUOTES) ?>', <?= $p['cantidad'] ?>, '<?= $p['tipo_inventario'] ?>')">
                                                        <i class="fas fa-plus-circle"></i>
                                                    </button>
                                                    <button class="btn btn-warning" title="Ajustar stock" onclick="abrirModalAjustarStock(<?= $p['id'] ?>, '<?= htmlspecialchars($p['nombre'], ENT_QUOTES) ?>', <?= $p['cantidad'] ?>, '<?= $p['tipo_inventario'] ?>')">
                                                        <i class="fas fa-sliders-h"></i>
                                                    </button>
                                                    <button class="btn btn-info" title="Editar" onclick="editarProducto(<?= $p['id'] ?>)">
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

                        <div class="p-3 d-flex justify-content-between align-items-center border-top">
                            <div>
                                <a href="?action=todos_codigos" target="_blank" class="btn btn-primary btn-sm">
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
                                        <select id="edit_tipo_codigo" name="tipo_codigo" class="form-control form-control-sm" style="border-radius: 8px; font-size: 0.85rem;">
                                            <option value="unico" selected>Código único (un código para todo el producto)</option>
                                            <option value="multiple">Múltiple (un código por unidad)</option>
                                        </select>
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
// ===== FILTROS Y BÚSQUEDA =====
let filtroActual = 'todos';
let busquedaActual = '';
let filasVisibles = [];
let paginaActual = 1;
let filasPorPagina = 15;

function aplicarFiltros() {
    const filasTabla = document.querySelectorAll('#tablaBody .producto-fila');
    let visibles = 0;
    
    filasTabla.forEach(fila => {
        const tipo = fila.getAttribute('data-tipo');
        const nombre = fila.getAttribute('data-nombre') || '';
        const categoria = fila.getAttribute('data-categoria') || '';
        const proveedor = fila.getAttribute('data-proveedor') || '';
        
        let mostrar = true;
        
        if (filtroActual !== 'todos') {
            if (tipo !== filtroActual) {
                mostrar = false;
            }
        }
        
        if (mostrar && busquedaActual !== '') {
            const texto = (nombre + ' ' + categoria + ' ' + proveedor).toLowerCase();
            if (!texto.includes(busquedaActual.toLowerCase())) {
                mostrar = false;
            }
        }
        
        if (mostrar) {
            fila.style.display = '';
            visibles++;
        } else {
            fila.style.display = 'none';
        }
    });
    
    const sinResultadosMsg = document.getElementById('sinResultadosMsg');
    const paginacionWrapper = document.getElementById('paginacionWrapper');
    
    if (visibles === 0) {
        sinResultadosMsg.style.display = 'block';
        paginacionWrapper.style.display = 'none';
        document.getElementById('paginacion_desde').textContent = '0';
        document.getElementById('paginacion_hasta').textContent = '0';
        document.getElementById('paginacion_total').textContent = '0';
    } else {
        sinResultadosMsg.style.display = 'none';
        paginacionWrapper.style.display = 'flex';
        
        filasVisibles = Array.from(filasTabla).filter(f => f.style.display !== 'none');
        paginaActual = 1;
        actualizarPaginacion();
    }
}

function actualizarPaginacion() {
    const totalFilas = filasVisibles.length;
    const totalPaginas = Math.ceil(totalFilas / filasPorPagina);
    
    document.querySelectorAll('#tablaBody .producto-fila').forEach(f => f.style.display = 'none');
    
    const inicio = (paginaActual - 1) * filasPorPagina;
    const fin = Math.min(inicio + filasPorPagina, totalFilas);
    for (let i = inicio; i < fin; i++) {
        if (filasVisibles[i]) filasVisibles[i].style.display = '';
    }
    
    document.getElementById('paginacion_desde').textContent = totalFilas > 0 ? inicio + 1 : 0;
    document.getElementById('paginacion_hasta').textContent = fin;
    document.getElementById('paginacion_total').textContent = totalFilas;
    
    const paginacionUl = document.getElementById('paginacion');
    paginacionUl.innerHTML = '';
    
    if (totalPaginas === 0) return;
    
    const liPrev = document.createElement('li');
    liPrev.className = `page-item ${paginaActual === 1 ? 'disabled' : ''}`;
    liPrev.innerHTML = `<a class="page-link" href="#" ${paginaActual !== 1 ? 'onclick="cambiarPagina(' + (paginaActual - 1) + ')"' : ''}>«</a>`;
    paginacionUl.appendChild(liPrev);
    
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
    
    const liNext = document.createElement('li');
    liNext.className = `page-item ${paginaActual === totalPaginas ? 'disabled' : ''}`;
    liNext.innerHTML = `<a class="page-link" ${paginaActual !== totalPaginas ? 'onclick="cambiarPagina(' + (paginaActual + 1) + ')"' : ''}>»</a>`;
    paginacionUl.appendChild(liNext);
}

function cambiarPagina(pagina) {
    paginaActual = pagina;
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
    document.getElementById('ajuste_nueva_cantidad').addEventListener('input', function() { calcularDiferencia(cantidadActual, this.value); });
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

    // Categoría - NO deshabilitar el select
    if (categoriaSelect && categoriaNueva) {
        categoriaSelect.addEventListener('change', function() {
            if (this.value === '__NUEVA__') {
                categoriaNueva.style.display = 'block';
                categoriaNueva.focus();
                // IMPORTANTE: NO deshabilitar el select
                // this.disabled = true;  <--- ELIMINAR ESTA LÍNEA
            } else {
                categoriaNueva.style.display = 'none';
                categoriaNueva.value = '';
                // this.disabled = false; <--- ELIMINAR ESTA LÍNEA
            }
        });
    }

    // Proveedor
    if (proveedorSelect && proveedorNuevo) {
        proveedorSelect.addEventListener('change', function() {
            if (this.value === '__NUEVO__') {
                proveedorNuevo.style.display = 'block';
                proveedorNuevo.focus();
                this.value = ''; // Limpiar para no enviar '__NUEVO__'
            } else {
                proveedorNuevo.style.display = 'none';
                proveedorNuevo.value = '';
            }
        });
    }

    // Submit del formulario
    const formEditar = document.getElementById('formEditarProducto');
    if (formEditar) {
        formEditar.addEventListener('submit', function() {
            const categoriaSelect = document.getElementById('edit_categoria');
            const categoriaNueva = document.getElementById('edit_categoria_nueva');
            const categoriaHidden = document.getElementById('edit_categoria_nueva_input');
            
            // Si hay nueva categoría, guardarla en el hidden input
            if (categoriaSelect.value === '__NUEVA__') {
                if (categoriaNueva.value.trim() !== '') {
                    categoriaHidden.value = categoriaNueva.value.trim();
                    console.log('Nueva categoría guardada:', categoriaHidden.value);
                }
            } else {
                categoriaHidden.value = '';
            }
            
            // Proveedor
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

function editarProducto(id) {
    fetch(`get_producto.php?id=${id}`)
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
                
                // Resetear campos
                categoriaSelect.disabled = false;
                categoriaNueva.style.display = 'none';
                categoriaNueva.value = '';
                proveedorSelect.disabled = false;
                proveedorNuevo.style.display = 'none';
                proveedorNuevo.value = '';
                
                // Limpiar input de imagen
                if (inputImagen) inputImagen.value = '';
                
                // Datos básicos
                document.getElementById('edit_id').value = p.id;
                document.getElementById('edit_nombre').value = p.nombre;
                document.getElementById('edit_precio_compra').value = p.precio_compra;
                document.getElementById('edit_precio_venta').value = p.precio_venta;
                document.getElementById('edit_tipo_codigo').value = p.tipo_codigo || 'unico';
                document.getElementById('edit_tipo_inventario').value = p.tipo_inventario;
                
                const stockText = p.tipo_inventario == 'insumo' ? parseFloat(p.cantidad).toFixed(2) + ' m' : parseInt(p.cantidad) + ' pz';
                document.getElementById('edit_cantidad_actual').textContent = stockText;
                
                // ===== CARGAR IMAGEN ACTUAL =====
                if (p.imagen && p.imagen_exists) {
                    preview.src = p.imagen;
                    preview.style.display = 'block';
                } else {
                    preview.style.display = 'none';
                    preview.src = '';
                }
                
                // ===== CARGAR CATEGORÍA =====
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
                
                // ===== CARGAR PROVEEDOR =====
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
                
                // ===== ATRIBUTOS =====
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
                
                // Mostrar/ocultar según tipo
                const isProducto = p.tipo_inventario === 'producto';
                const ventaGroup = document.getElementById('edit_precio_venta_group');
                const codigoGroup = document.getElementById('edit_tipo_codigo_group');
                const atributosSection = document.getElementById('edit_atributos_section');
                const adquisicionGroup = document.getElementById('edit_adquisicion_group');
                
                if (ventaGroup) ventaGroup.style.display = isProducto ? 'block' : 'none';
                if (codigoGroup) codigoGroup.style.display = isProducto ? 'block' : 'none';
                if (atributosSection) atributosSection.style.display = isProducto ? 'block' : 'none';
                if (adquisicionGroup) adquisicionGroup.style.display = isProducto ? 'block' : 'none';
                
                // Abrir modal
                $('#modalEditar').modal('show');
            }
        })
        .catch(error => console.error('Error:', error));
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

// ===== INICIALIZACIÓN =====
document.addEventListener('DOMContentLoaded', function() {
    $('[data-toggle="tooltip"]').tooltip();
    
    filasVisibles = Array.from(document.querySelectorAll('#tablaBody .producto-fila'));
    actualizarPaginacion();
    
    const filtrosBtns = document.querySelectorAll('.btn-filtro-tabla');
    filtrosBtns.forEach(btn => {
        btn.addEventListener('click', function() {
            filtrosBtns.forEach(b => b.classList.remove('active'));
            this.classList.add('active');
            filtroActual = this.getAttribute('data-filtro');
            aplicarFiltros();
        });
    });
    
    const buscadorInput = document.getElementById('buscadorInput');
    const limpiarBusqueda = document.getElementById('limpiarBusqueda');
    
    buscadorInput.addEventListener('keyup', function() {
        busquedaActual = this.value;
        limpiarBusqueda.style.display = busquedaActual ? 'block' : 'none';
        aplicarFiltros();
    });
    
    limpiarBusqueda.addEventListener('click', function() {
        buscadorInput.value = '';
        busquedaActual = '';
        this.style.display = 'none';
        aplicarFiltros();
        buscadorInput.focus();
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