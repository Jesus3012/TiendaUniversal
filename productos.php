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
        $cantidad = intval($_POST['cantidad_insumo'] ?? 0);
        $precio_compra = floatval($_POST['precio_compra_insumo'] ?? 0);
        $precio_venta = 0; // Los insumos no tienen precio de venta
        $tipo_codigo = 'unico'; // Los insumos usan código único
        $atributos_json = null;
    }

    // Validaciones básicas
    if ($nombre === '') {
        $errors[] = "El nombre del producto es obligatorio.";
    }

    // Validaciones según el tipo de inventario
    if ($tipo_inventario === 'producto') {
        // Validaciones específicas para productos
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
        // Validaciones específicas para insumos
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
        
        // Para insumos, el precio_venta debe ser 0
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
            $stmt = $conn->prepare("INSERT INTO productos (nombre, categoria, atributos, proveedor, imagen, cantidad, precio_compra, precio_venta, tipo_codigo, tipo_inventario) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->bind_param("sssssiddss", $nombre, $categoria, $atributos_json, $proveedor, $imagen_path, $cantidad, $precio_compra, $precio_venta, $tipo_codigo, $tipo_inventario);
            
            if ($stmt->execute()) {
                $producto_id = $stmt->insert_id;
                $stmt->close();

                // Crear subcarpeta para códigos si no existe
                $codigos_dir = __DIR__.'/uploads/codigos/';
                if (!is_dir($codigos_dir)) mkdir($codigos_dir, 0777, true);

                // Generar códigos de barras
                generarCodigosBarras($conn, $nombre, $producto_id, $cantidad, $tipo_codigo);

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
            } else {
                $errors[] = "Error al insertar producto: " . $conn->error;
            }
        }
    }
}

// ========================= GENERAR CÓDIGOS DE BARRAS =========================
function generarCodigosBarras($conn, $nombre, $producto_id, $cantidad, $tipo_codigo) {
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

// ========================= ACTUALIZAR PRODUCTO =========================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update') {
    csrf_check();

    $id = intval($_POST['id']);
    $nombre = trim($_POST['nombre']);
    $categoria = trim($_POST['categoria']);
    $proveedor = trim($_POST['proveedor']);
    $cantidad = intval($_POST['cantidad']);
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
    $stmt = $conn->prepare("SELECT imagen FROM productos WHERE id = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $result = $stmt->get_result();
    $producto_actual = $result->fetch_assoc();
    $imagen_path = $producto_actual['imagen'];

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
        $stmt = $conn->prepare("UPDATE productos SET nombre=?, categoria=?, atributos=?, proveedor=?, imagen=?, cantidad=?, precio_compra=?, precio_venta=?, tipo_codigo=?, tipo_inventario=? WHERE id=?");
        $stmt->bind_param("sssssiddssi", $nombre, $categoria, $atributos_json, $proveedor, $imagen_path, $cantidad, $precio_compra, $precio_venta, $tipo_codigo, $tipo_inventario, $id);
    } else {
        $stmt = $conn->prepare("UPDATE productos SET nombre=?, categoria=?, atributos=?, proveedor=?, cantidad=?, precio_compra=?, precio_venta=?, tipo_codigo=?, tipo_inventario=? WHERE id=?");
        $stmt->bind_param("ssssiddssi", $nombre, $categoria, $atributos_json, $proveedor, $cantidad, $precio_compra, $precio_venta, $tipo_codigo, $tipo_inventario, $id);
    }

    if ($stmt->execute()) {
        $conn->query("DELETE FROM codigos_barras WHERE producto_id = $id");

        $old_pdf = __DIR__ . '/uploads/codigos/producto_' . $id . '.pdf';
        if (file_exists($old_pdf)) @unlink($old_pdf);

        generarCodigosBarras($conn, $nombre, $id, $cantidad, $tipo_codigo);

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
              WHERE p.activo = 1
              ORDER BY p.nombre ASC, cb.codigo ASC";
    $res = $conn->query($query);

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
    background-color: #e3f2fd;  /* Azul muy suave */
    color: #0d47a1;             /* Azul oscuro */
    border: 1px solid #90caf9;
}

.badge-producto i {
    color: #1976d2;
}

.badge-insumo {
    background-color: #e5f5e5;  /* Lila muy suave */
    color: #148c20;             /* Púrpura oscuro */
    border: 1px solid #93d89a;
}

.badge-insumo i {
    color: #1fa23b;
}

.badge-producto-neutral {
    background-color: #e8eaf6;  /* Azul grisáceo muy suave */
    color: #1a237e;
    border: 1px solid #9fa8da;
}

.badge-insumo-neutral {
    background-color: #f1f8e9;  /* Verde muy suave */
    color: #33691e;
    border: 1px solid #aed581;
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
</style>

<div class="content-wrapper">
    <section>
        <div class="container-fluid">
            <div class="row">
                <!-- FORMULARIO ARRIBA -->
                <div class="col-12">
                    <div class="card card-primary card-outline shadow-sm">
                        <!-- HEADER -->
                        <div class="card-header d-flex justify-content-between align-items-center">
                            <h3 class="card-title font-weight-bold mb-0">
                                <i class="fas fa-box-open mr-2"></i> Nuevo producto / insumo
                            </h3>
                            <button type="button" class="btn btn-light btn-sm ml-auto" title="Limpiar formulario" onclick="limpiarFormulario()">
                                <i class="fas fa-undo-alt"></i>
                            </button>
                        </div>

                        <!-- BODY -->
                        <div class="card-body">
                            <!-- Selector de tipo de inventario -->
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
                                            <input type="text" name="nombre" class="form-control" placeholder="Ej. Llaveros, playeras etc." required>
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

                                            <!-- Información adicional -->
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
                        <!-- HEADER con filtros -->
                        <div class="card-header">
                            <div class="d-flex flex-column flex-md-row align-items-md-center justify-content-between">
                                <h3 class="card-title font-weight-bold mb-2 mb-md-0">
                                    <i class="fas fa-box mr-2"></i> Inventario
                                </h3>

                                <div class="d-flex gap-2">
                                    <!-- Filtros por tipo (AHORA CON BOTONES QUE NO RECARGAN) -->
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

                                    <!-- Búsqueda -->
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

                        <!-- BODY -->
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
                                                </td>

                                                <!-- ACCIONES -->
                                                <td class="text-center">
                                                    <div class="btn-group btn-group-sm">
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

                            <!-- BOTÓN PDF TODOS LOS CÓDIGOS -->
                            <div class="p-3 d-flex justify-content-start">
                                <a href="productos.php?action=todos_codigos" target="_blank" class="btn btn-success btn-sm">
                                    <i class="fas fa-file-pdf mr-1"></i> PDF con todos los códigos
                                </a>
                            </div>
                        </div>

                        <!-- FOOTER -->
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

<!-- MODAL EDITAR PRODUCTO -->
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

                        <div class="form-group">
                            <label>Cantidad:</label>
                            <input type="number" id="edit_cantidad" name="cantidad" class="form-control" required>
                        </div>
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
                                <label>Talla</label>
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
});

// ===== CONFIGURACIÓN DE CAMPOS POR TIPO =====
function configurarCamposPorTipo(tipo) {
    // Obtener referencias a los campos
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
        // Mostrar sección de producto
        document.getElementById('producto-section').style.display = 'block';
        document.getElementById('insumo-section').style.display = 'none';
        
        // Configurar campos de PRODUCTO
        camposProducto.cantidad.required = true;
        camposProducto.cantidad.disabled = false;
        camposProducto.precioCompra.required = true;
        camposProducto.precioCompra.disabled = false;
        camposProducto.precioVenta.required = true;
        camposProducto.precioVenta.disabled = false;
        
        // Configurar campos de INSUMO (deshabilitados y sin required)
        camposInsumo.cantidad.required = false;
        camposInsumo.cantidad.disabled = true;
        camposInsumo.cantidad.value = ''; // Limpiar valor
        
        camposInsumo.precioCompra.required = false;
        camposInsumo.precioCompra.disabled = true;
        camposInsumo.precioCompra.value = ''; // Limpiar valor
        
    } else { // insumo
        // Mostrar sección de insumo
        document.getElementById('producto-section').style.display = 'none';
        document.getElementById('insumo-section').style.display = 'block';
        
        // Configurar campos de INSUMO
        camposInsumo.cantidad.required = true;
        camposInsumo.cantidad.disabled = false;
        camposInsumo.precioCompra.required = true;
        camposInsumo.precioCompra.disabled = false;
        
        // Configurar campos de PRODUCTO (deshabilitados y sin required)
        camposProducto.cantidad.required = false;
        camposProducto.cantidad.disabled = true;
        camposProducto.cantidad.value = ''; // Limpiar valor
        
        camposProducto.precioCompra.required = false;
        camposProducto.precioCompra.disabled = true;
        camposProducto.precioCompra.value = ''; // Limpiar valor
        
        camposProducto.precioVenta.required = false;
        camposProducto.precioVenta.disabled = true;
        camposProducto.precioVenta.value = ''; // Limpiar valor
    }
}

// ===== CAMBIAR TIPO DE FORMULARIO =====
function cambiarTipoFormulario(tipo) {
    // Actualizar campo oculto
    document.getElementById('tipo_inventario').value = tipo;
    
    // Actualizar botones
    document.querySelectorAll('.btn-filtro-tipo').forEach(btn => {
        btn.classList.remove('active');
    });
    document.querySelector(`.btn-filtro-tipo[data-tipo="${tipo}"]`).classList.add('active');
    
    // Configurar campos según el tipo
    configurarCamposPorTipo(tipo);
    
    // Resetear la unidad de insumo si es necesario
    if (tipo === 'insumo') {
        const unidadPorDefecto = document.querySelector('input[name="tipo_unidad_insumo"]:checked');
        if (unidadPorDefecto) {
            cambiarUnidadInsumo(unidadPorDefecto.value);
        }
    }
}

// ===== VALIDACIÓN PERSONALIZADA DEL FORMULARIO =====
function validarFormulario(e) {
    e.preventDefault(); // Prevenir envío por defecto
    
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
        
    } else { // insumo
        const cantidad = document.querySelector('input[name="cantidad_insumo"]').value;
        const precioCompra = document.querySelector('input[name="precio_compra_insumo"]').value;
        const nombre = document.querySelector('input[name="nombre"]').value;
        
        if (!nombre.trim()) errores.push("El nombre del insumo es obligatorio");
        if (!cantidad || cantidad <= 0) errores.push("La cantidad debe ser mayor a 0");
        if (!precioCompra || precioCompra <= 0) errores.push("El precio de compra debe ser mayor a 0");
    }
    
    if (errores.length > 0) {
        // Mostrar errores con SweetAlert
        Swal.fire({
            icon: 'error',
            title: 'Error de validación',
            html: errores.join('<br>'),
            confirmButtonText: 'Aceptar'
        });
        return false;
    }
    
    // Si no hay errores, habilitar campos necesarios temporalmente para el envío
    if (tipo === 'producto') {
        document.querySelector('input[name="cantidad_insumo"]').disabled = false;
        document.querySelector('input[name="precio_compra_insumo"]').disabled = false;
    } else {
        document.querySelector('input[name="cantidad"]').disabled = false;
        document.querySelector('input[name="precio_compra"]').disabled = false;
        document.querySelector('input[name="precio_venta"]').disabled = false;
    }
    
    // Enviar el formulario
    e.target.submit();
}

// ===== FUNCIONES EXISTENTES (mantener igual) =====
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

function previewImagen(event) {
    const img = document.getElementById('previewImg');
    img.src = URL.createObjectURL(event.target.files[0]);
    img.classList.remove('d-none');
}

function limpiarFormulario() {
    const form = document.getElementById('formProducto');
    form.reset();
    document.getElementById('previewImg').classList.add('d-none');
    cambiarTipoFormulario('producto');
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
    
    // Actualizar el estado visual de los botones
    document.querySelectorAll('[name="tipo_unidad_insumo"]').forEach(radio => {
        if (radio.value === tipo) {
            radio.closest('label').classList.add('active');
            radio.checked = true;
        } else {
            radio.closest('label').classList.remove('active');
        }
    });
}

// ===== FUNCIONES PARA LA TABLA (mantener igual) =====
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
                $('#edit_cantidad').val(p.cantidad);
                $('#edit_precio_compra').val(p.precio_compra);
                $('#edit_precio_venta').val(p.precio_venta);
                $('#edit_tipo_codigo').val(p.tipo_codigo);
                $('#edit_tipo_inventario').val(p.tipo_inventario);
                
                if (p.atributos_array) {
                    $('#edit_marca').val(p.atributos_array.marca || '');
                    $('#edit_modelo').val(p.atributos_array.modelo || '');
                    $('#edit_color').val(p.atributos_array.color || '');
                    $('#edit_talla').val(p.atributos_array.talla || '');
                    $('#edit_peso').val(p.atributos_array.peso || '');
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

// Búsqueda en tabla
document.getElementById('searchProductos').addEventListener('keyup', function () {
    const filtro = this.value.toLowerCase();
    const filas = document.querySelectorAll('table tbody tr');

    filas.forEach(fila => {
        const texto = fila.innerText.toLowerCase();
        fila.style.display = texto.includes(filtro) ? '' : 'none';
    });
});

// Limpiar formulario
function limpiarFormulario() {
    const form = document.getElementById('formProducto');
    form.reset();
    document.getElementById('previewImg').classList.add('d-none');
    cambiarTipoFormulario('producto'); // Reset a producto
}

// Preview imagen
function previewImagen(event) {
    const img = document.getElementById('previewImg');
    img.src = URL.createObjectURL(event.target.files[0]);
    img.classList.remove('d-none');
}

// Inicializar tooltips
$(function () {
    $('[data-toggle="tooltip"]').tooltip();
});

function cambiarUnidadInsumo(tipo) {
    const cantidadInput = document.getElementById('cantidad_insumo');
    const unidadLabel = document.getElementById('unidad_insumo_label');
    const precioUnidadLabel = document.getElementById('precio_unidad_label');
    const unidadHelp = document.getElementById('unidad_insumo_help');
    const infoAdicional = document.getElementById('insumo_info_adicional');
    const anchoGroup = document.getElementById('ancho_insumo_group');
    
    if (tipo === 'metros') {
        // Cambiar a metros
        cantidadInput.step = '0.1';
        cantidadInput.min = '0.1';
        cantidadInput.placeholder = 'Ej. 25.5';
        unidadLabel.textContent = 'm';
        precioUnidadLabel.textContent = '/m';
        unidadHelp.innerHTML = '<i class="fas fa-ruler mr-1"></i> Cantidad en metros (puede usar decimales)';
        infoAdicional.textContent = 'Se miden en metros (útil para DTF, telas, vinilos, etc.)';
        anchoGroup.style.display = 'block';
        
        // Cambiar estilo del botón activo
        document.querySelectorAll('[name="tipo_unidad_insumo"]').forEach(radio => {
            if (radio.value === 'metros') {
                radio.closest('label').classList.add('active');
                radio.checked = true;
            } else {
                radio.closest('label').classList.remove('active');
            }
        });
    } else {
        // Cambiar a unidades
        cantidadInput.step = '1';
        cantidadInput.min = '1';
        cantidadInput.placeholder = 'Ej. 100';
        unidadLabel.textContent = 'pz';
        precioUnidadLabel.textContent = '/pz';
        unidadHelp.innerHTML = '<i class="fas fa-cubes mr-1"></i> Cantidad en piezas (números enteros)';
        infoAdicional.textContent = 'Se cuentan por piezas y no generan códigos múltiples.';
        anchoGroup.style.display = 'none';
        
        // Cambiar estilo del botón activo
        document.querySelectorAll('[name="tipo_unidad_insumo"]').forEach(radio => {
            if (radio.value === 'unidad') {
                radio.closest('label').classList.add('active');
                radio.checked = true;
            } else {
                radio.closest('label').classList.remove('active');
            }
        });
    }
}

// Inicializar por defecto
document.addEventListener('DOMContentLoaded', function() {
    // Asegurar que la sección de insumos tenga la unidad por defecto
    const unidadPorDefecto = document.querySelector('input[name="tipo_unidad_insumo"]:checked');
    if (unidadPorDefecto) {
        cambiarUnidadInsumo(unidadPorDefecto.value);
    }
});

</script>

<?php
ob_end_flush();
?>