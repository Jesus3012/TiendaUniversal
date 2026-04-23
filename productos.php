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

// ========================= AGREGAR PROVEEDOR =========================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add_proveedor') {
    ob_clean();
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
    
    if ($nombre === '') {
        echo json_encode(['success' => false, 'error' => 'El nombre del proveedor es obligatorio.']);
        exit;
    }
    
    $stmt = $conn->prepare("SELECT id FROM proveedores WHERE nombre = ? AND activo = 1");
    $stmt->bind_param("s", $nombre);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows > 0) {
        echo json_encode(['success' => false, 'error' => 'Ya existe un proveedor con este nombre.']);
        exit;
    }
    
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
    $nombre = trim($_POST['nombre'] ?? '');
    $categoria = trim($_POST['categoria'] ?? 'General');
    $proveedor_id = intval($_POST['proveedor_id'] ?? 0);
    
    $proveedor_nombre = '';
    if ($proveedor_id > 0) {
        $stmt = $conn->prepare("SELECT nombre FROM proveedores WHERE id = ? AND activo = 1");
        $stmt->bind_param("i", $proveedor_id);
        $stmt->execute();
        $result = $stmt->get_result();
        if ($row = $result->fetch_assoc()) {
            $proveedor_nombre = $row['nombre'];
        }
    }
    
    if ($tipo_inventario === 'producto') {
        $cantidad = intval($_POST['cantidad'] ?? 0);
        $precio_compra = floatval($_POST['precio_compra'] ?? 0);
        $precio_venta = floatval($_POST['precio_venta'] ?? 0);
        $tipo_codigo = $_POST['tipo_codigo'] ?? 'multiple';
        
        $atributos = [];
        $campos_atributos = ['marca', 'modelo', 'color', 'talla', 'peso', 'material'];
        foreach ($campos_atributos as $campo) {
            if (!empty($_POST[$campo])) {
                $atributos[$campo] = $_POST[$campo];
            }
        }
        $atributos_json = !empty($atributos) ? json_encode($atributos, JSON_UNESCAPED_UNICODE) : null;
    } else {
        $cantidad = floatval($_POST['cantidad_insumo'] ?? 0);
        $precio_compra = floatval($_POST['precio_compra_insumo'] ?? 0);
        $precio_venta = 0;
        $tipo_codigo = 'unico';
        $atributos_json = null;
        
        $tipo_unidad_insumo = $_POST['tipo_unidad_insumo'] ?? 'unidad';
        $ancho_insumo = isset($_POST['ancho_insumo']) ? floatval($_POST['ancho_insumo']) : null;
        
        $atributos = ['tipo_unidad' => $tipo_unidad_insumo];
        if ($ancho_insumo && $ancho_insumo > 0) {
            $atributos['ancho'] = $ancho_insumo;
        }
        $atributos_json = json_encode($atributos, JSON_UNESCAPED_UNICODE);
    }

    if ($nombre === '') {
        $errors[] = "El nombre del producto es obligatorio.";
    }

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
    } else {
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
            
            $imagen_path = 'uploads/productos/' . $imagen_name;
            
            if (!move_uploaded_file($_FILES['imagen']['tmp_name'], $upload_dir . $imagen_name)) {
                $errors[] = "Error al subir la imagen.";
                $imagen_path = '';
            }
        }

        if (empty($errors)) {
            $conn->begin_transaction();
            
            try {
                $stmt = $conn->prepare("INSERT INTO productos (nombre, categoria, atributos, proveedor, imagen, cantidad, precio_compra, precio_venta, tipo_codigo, tipo_inventario) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                $stmt->bind_param("sssssiddss", $nombre, $categoria, $atributos_json, $proveedor_nombre, $imagen_path, $cantidad, $precio_compra, $precio_venta, $tipo_codigo, $tipo_inventario);
                
                if (!$stmt->execute()) {
                    throw new Exception("Error al insertar producto: " . $conn->error);
                }
                
                $producto_id = $stmt->insert_id;
                $stmt->close();

                $cero = 0;
                $stmt_historial = $conn->prepare("INSERT INTO historial_stock (producto_id, cantidad_anterior, cantidad_nueva, cantidad_agregada, tipo_movimiento, nota, usuario_id) VALUES (?, ?, ?, ?, 'entrada', 'Registro inicial de producto', ?)");
                $stmt_historial->bind_param("idddi", $producto_id, $cero, $cantidad, $cantidad, $_SESSION['usuario_id']);
                
                if (!$stmt_historial->execute()) {
                    throw new Exception("Error al registrar historial: " . $conn->error);
                }
                $stmt_historial->close();

                $codigos_dir = __DIR__.'/uploads/codigos/';
                if (!is_dir($codigos_dir)) mkdir($codigos_dir, 0777, true);

                if ($tipo_inventario === 'producto') {
                    generarCodigosBarras($conn, $nombre, $producto_id, $cantidad, $tipo_codigo, $tipo_inventario);
                }
                
                $conn->commit();
                
                echo "<script>
                Swal.fire({
                    icon: 'success',
                    title: 'Producto agregado',
                    text: 'El producto se agregó correctamente.',
                    confirmButtonText: 'Aceptar',
                    confirmButtonColor: '#f97316'
                }).then(() => {
                    window.location = 'dashboard_productos.php';
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
<link rel="stylesheet" href="css/productos.css">

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
                                    <div class="form-group">
                                        <label>Nombre <span class="text-danger">*</span></label>
                                        <input type="text" name="nombre" id="nombre_input" class="form-control" placeholder="Ej. Llaveros, playeras, tazas, etc." required>
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
    
    fetch('nuevo_producto.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.text())
    .then(text => {
        try {
            const data = JSON.parse(text);
            if (data.success) {
                Swal.fire({
                    icon: 'success',
                    title: 'Éxito',
                    text: 'Proveedor agregado correctamente',
                    confirmButtonColor: '#f97316'
                });
                agregarProveedorAlSelect(data.id, data.nombre);
                $('#modalProveedor').modal('hide');
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
                text: 'Error en el servidor',
                confirmButtonColor: '#f97316'
            });
        }
    })
    .catch(error => {
        console.error('Error:', error);
        Swal.fire({
            icon: 'error',
            title: 'Error',
            text: 'Error al procesar la solicitud',
            confirmButtonColor: '#f97316'
        });
    });
});

// ===== FORMULARIO =====
function limpiarFormulario() {
    const form = document.getElementById('formProducto');
    form.reset();
    document.getElementById('previewImg').classList.add('d-none');
}

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

// Inicialización para insumos
<?php if ($tipo_seleccionado == 'insumo'): ?>
document.addEventListener('DOMContentLoaded', function() {
    const unidadPorDefecto = document.querySelector('input[name="tipo_unidad_insumo"]:checked');
    if (unidadPorDefecto) {
        cambiarUnidadInsumo(unidadPorDefecto.value);
    }
});
<?php endif; ?>
</script>

<?php
ob_end_flush();
?>