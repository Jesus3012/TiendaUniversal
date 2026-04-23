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
    $result = $conn->query("SELECT DISTINCT proveedor FROM productos WHERE activo = 1 AND proveedor IS NOT NULL AND proveedor != '' ORDER BY proveedor");
    $proveedores = [];
    while ($row = $result->fetch_assoc()) {
        $proveedores[] = $row['proveedor'];
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
                    window.location = 'inventario.php';
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
    $categoria = trim($_POST['categoria']);
    $proveedor = trim($_POST['proveedor']);
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

    $stmt = $conn->prepare("SELECT imagen, cantidad FROM productos WHERE id = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $result = $stmt->get_result();
    $producto_actual = $result->fetch_assoc();
    $imagen_path = $producto_actual['imagen'];
    $cantidad_actual = $producto_actual['cantidad'];

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

    if ($imagen_path) {
        $stmt = $conn->prepare("UPDATE productos SET nombre=?, categoria=?, atributos=?, proveedor=?, imagen=?, precio_compra=?, precio_venta=?, tipo_codigo=?, tipo_inventario=? WHERE id=?");
        $stmt->bind_param("sssssiddsi", $nombre, $categoria, $atributos_json, $proveedor, $imagen_path, $precio_compra, $precio_venta, $tipo_codigo, $tipo_inventario, $id);
    } else {
        $stmt = $conn->prepare("UPDATE productos SET nombre=?, categoria=?, atributos=?, proveedor=?, precio_compra=?, precio_venta=?, tipo_codigo=?, tipo_inventario=? WHERE id=?");
        $stmt->bind_param("ssssiddsi", $nombre, $categoria, $atributos_json, $proveedor, $precio_compra, $precio_venta, $tipo_codigo, $tipo_inventario, $id);
    }

    if ($stmt->execute()) {
        if ($tipo_inventario === 'producto') {
            $conn->query("DELETE FROM codigos_barras WHERE producto_id = $id");
            $old_pdf = __DIR__ . '/uploads/codigos/producto_' . $id . '.pdf';
            if (file_exists($old_pdf)) @unlink($old_pdf);
            
            generarCodigosBarras($conn, $nombre, $id, $cantidad_actual, $tipo_codigo, $tipo_inventario);
        } else {
            $conn->query("DELETE FROM codigos_barras WHERE producto_id = $id");
            $old_pdf = __DIR__ . '/uploads/codigos/producto_' . $id . '.pdf';
            if (file_exists($old_pdf)) @unlink($old_pdf);
        }
        
        echo "<script>
        Swal.fire({
            icon: 'success',
            title: 'Producto actualizado',
            text: 'Los cambios se guardaron correctamente.',
            confirmButtonColor: '#f97316'
        }).then(() => {
            window.location='inventario.php';
        });
        </script>";
        exit;
    } else {
        echo "<script>
        Swal.fire({
            icon: 'error',
            title: 'Error al actualizar',
            text: 'No se pudo actualizar el producto. Intenta nuevamente.',
            confirmButtonText: 'Aceptar',
            confirmButtonColor: '#f97316'
        });
        </script>";
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
                                <a href="inventario.php?action=todos_codigos" target="_blank" class="btn btn-primary btn-sm">
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

<!-- MODAL EDITAR PRODUCTO -->
<div class="modal fade" id="modalEditar" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <form method="POST" enctype="multipart/form-data" class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Editar Producto / Insumo</h5>
                <button type="button" class="close text-white" data-dismiss="modal">&times;</button>
            </div>
            <div class="modal-body">
                <input type="hidden" name="action" value="update">
                <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>">
                <input type="hidden" id="edit_id" name="id">
                <input type="hidden" id="edit_tipo_inventario" name="tipo_inventario">
                <div class="row">
                    <div class="col-md-6">
                        <div class="form-group"><label>Nombre:</label><input type="text" id="edit_nombre" name="nombre" class="form-control" required></div>
                        <div class="form-group"><label>Categoría:</label><input type="text" id="edit_categoria" name="categoria" class="form-control" required></div>
                        <div class="form-group"><label>Proveedor:</label><input type="text" id="edit_proveedor" name="proveedor" class="form-control"></div>
                    </div>
                    <div class="col-md-6">
                        <div class="form-group"><label>Precio compra:</label><input type="number" step="0.01" id="edit_precio_compra" name="precio_compra" class="form-control" required></div>
                        <div class="form-group" id="edit_precio_venta_group"><label>Precio venta:</label><input type="number" step="0.01" id="edit_precio_venta" name="precio_venta" class="form-control"></div>
                        <div class="form-group" id="edit_tipo_codigo_group"><label>Tipo código:</label><select id="edit_tipo_codigo" name="tipo_codigo" class="form-control"><option value="unico">Código único</option><option value="multiple">Por artículo</option></select></div>
                        <div class="form-group"><label>Nueva imagen (opcional):</label><input type="file" name="imagen" accept="image/*" class="form-control"></div>
                    </div>
                </div>
                <div class="alert alert-info"><i class="fas fa-info-circle mr-2"></i>Stock actual: <strong id="edit_cantidad_actual">0</strong><small class="d-block mt-1">Para modificar el stock usa el botón Agregar stock en la tabla.</small></div>
                <div id="edit_atributos_section" class="card card-secondary mt-3">
                    <div class="card-header"><h6>Atributos adicionales</h6></div>
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-6 mb-2"><label>Marca</label><input type="text" id="edit_marca" name="marca" class="form-control form-control-sm"></div>
                            <div class="col-md-6 mb-2"><label>Color</label><input type="text" id="edit_color" name="color" class="form-control form-control-sm"></div>
                            <div class="col-md-6 mb-2"><label>Talla</label><input type="text" id="edit_talla" name="talla" class="form-control form-control-sm"></div>
                            <div class="col-md-6 mb-2"><label>Material</label><input type="text" id="edit_material" name="material" class="form-control form-control-sm"></div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button class="btn btn-primary">Actualizar</button>
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
                $('#edit_cantidad_actual').text(p.tipo_inventario == 'insumo' ? parseFloat(p.cantidad).toFixed(2) + ' m' : parseInt(p.cantidad) + ' pz');
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
});
</script>

<?php
ob_end_flush();
?>