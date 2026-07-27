<?php
error_reporting(E_ALL & ~E_DEPRECATED);
ob_start();

require_once __DIR__ . '/includes/session.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/permisos.php';
require_once __DIR__ . '/includes/csrf.php';

/*
|--------------------------------------------------------------------------
| Acceso al módulo
|--------------------------------------------------------------------------
| Este módulo puede ser usado por vendedor, administrador y
| superadministrador. El superadministrador recibe acceso total.
*/

$usuarioId = function_exists('permisos_usuario_id')
    ? permisos_usuario_id()
    : (int) (
        $_SESSION['usuario_id']
        ?? $_SESSION['id_usuario']
        ?? $_SESSION['id']
        ?? 0
    );

$rol = function_exists('permisos_normalizar_rol')
    ? permisos_normalizar_rol($_SESSION['rol'] ?? '')
    : strtolower(trim((string) ($_SESSION['rol'] ?? '')));

$rolesPermitidos = [
    'administrador',
    'super_administrador',
    'vendedor',
];

if ($usuarioId <= 0) {
    header('Location: login.php?expired=1');
    exit;
}

if (!in_array($rol, $rolesPermitidos, true)) {
    header('Location: sin_permiso.php?modulo=vendedor_ajustes_productos.php');
    exit;
}

/*
 * Unificar la sesión para archivos antiguos como navbar.php.
 */
$_SESSION['usuario_id'] = $usuarioId;
$_SESSION['id_usuario'] = $usuarioId;
$_SESSION['id'] = $usuarioId;
$_SESSION['rol'] = $rol;
$_SESSION['last_activity'] = time();

$errors = [];

function e($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function vendedorPuedeGestionarProducto($conn, $usuarioId, $rol, $productoId) {
    if (in_array($rol, ['administrador', 'super_administrador'], true)) return true;

    $stmt = $conn->prepare("
        SELECT id
        FROM vendedor_productos
        WHERE vendedor_id = ?
          AND producto_id = ?
          AND activo = 1
        LIMIT 1
    ");

    if (!$stmt) return false;

    $stmt->bind_param('ii', $usuarioId, $productoId);
    $stmt->execute();

    return (bool)$stmt->get_result()->fetch_assoc();
}

function registrarMovimientoStock($conn, $productoId, $anterior, $nueva, $diferencia, $tipo, $nota, $usuarioId) {
    $stmt = $conn->prepare("
        INSERT INTO historial_stock
            (producto_id, cantidad_anterior, cantidad_nueva, cantidad_agregada, tipo_movimiento, nota, usuario_id)
        VALUES
            (?, ?, ?, ?, ?, ?, ?)
    ");

    if (!$stmt) throw new Exception('Error al preparar historial: '.$conn->error);

    $stmt->bind_param('idddssi', $productoId, $anterior, $nueva, $diferencia, $tipo, $nota, $usuarioId);

    if (!$stmt->execute()) {
        throw new Exception('Error al registrar historial: '.$stmt->error);
    }
}

function obtenerCategoriasAsignadas($conn, $usuarioId, $rol) {
    $categorias = [];

    if (in_array($rol, ['administrador', 'super_administrador'], true)) {
        $res = $conn->query("
            SELECT DISTINCT categoria
            FROM productos
            WHERE activo = 1
              AND tipo_inventario = 'producto'
              AND categoria IS NOT NULL
              AND categoria != ''
            ORDER BY categoria ASC
        ");
    } else {
        $stmt = $conn->prepare("
            SELECT DISTINCT p.categoria
            FROM productos p
            INNER JOIN vendedor_productos vp
                ON vp.producto_id = p.id
               AND vp.activo = 1
               AND vp.vendedor_id = ?
            WHERE p.activo = 1
              AND p.tipo_inventario = 'producto'
              AND p.categoria IS NOT NULL
              AND p.categoria != ''
            ORDER BY p.categoria ASC
        ");
        $stmt->bind_param('i', $usuarioId);
        $stmt->execute();
        $res = $stmt->get_result();
    }

    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $categorias[] = $row['categoria'];
        }
    }

    return $categorias;
}

function obtenerProveedoresAsignados($conn, $usuarioId, $rol) {
    $proveedores = [];

    if (in_array($rol, ['administrador', 'super_administrador'], true)) {
        $res = $conn->query("
            SELECT DISTINCT proveedor
            FROM productos
            WHERE activo = 1
              AND tipo_inventario = 'producto'
              AND proveedor IS NOT NULL
              AND proveedor != ''
            ORDER BY proveedor ASC
        ");
    } else {
        $stmt = $conn->prepare("
            SELECT DISTINCT p.proveedor
            FROM productos p
            INNER JOIN vendedor_productos vp
                ON vp.producto_id = p.id
               AND vp.activo = 1
               AND vp.vendedor_id = ?
            WHERE p.activo = 1
              AND p.tipo_inventario = 'producto'
              AND p.proveedor IS NOT NULL
              AND p.proveedor != ''
            ORDER BY p.proveedor ASC
        ");
        $stmt->bind_param('i', $usuarioId);
        $stmt->execute();
        $res = $stmt->get_result();
    }

    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $proveedores[] = $row['proveedor'];
        }
    }

    return $proveedores;
}

function crearProveedorSiNoExiste($conn, $nombreProveedor) {
    $nombreProveedor = trim($nombreProveedor);

    if ($nombreProveedor === '') return [null, null];

    $stmt = $conn->prepare("SELECT id, nombre FROM proveedores WHERE nombre = ? LIMIT 1");
    if (!$stmt) return [null, $nombreProveedor];

    $stmt->bind_param('s', $nombreProveedor);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();

    if ($row) return [(int)$row['id'], $row['nombre']];

    $stmtInsert = $conn->prepare("INSERT INTO proveedores (nombre, activo) VALUES (?, 1)");
    if (!$stmtInsert) return [null, $nombreProveedor];

    $stmtInsert->bind_param('s', $nombreProveedor);
    $stmtInsert->execute();

    return [$conn->insert_id, $nombreProveedor];
}

function generarCodigosNuevosSiAplica($conn, $productoId, $cantidadAgregar, $tipoCodigo, $tipoInventario) {
    if ($tipoInventario !== 'producto' || $tipoCodigo !== 'multiple' || $cantidadAgregar <= 0) return;

    $stmt = $conn->prepare("
        SELECT codigo
        FROM codigos_barras
        WHERE producto_id = ?
        ORDER BY codigo DESC
        LIMIT 1
    ");
    if (!$stmt) return;

    $stmt->bind_param('i', $productoId);
    $stmt->execute();
    $resUltimo = $stmt->get_result();

    $ultimoCodigo = 0;
    if ($resUltimo && $resUltimo->num_rows > 0) {
        $row = $resUltimo->fetch_assoc();
        $ultimoCodigo = (int)substr($row['codigo'], strlen((string)$productoId));
    }

    $cantidadEntera = (int)floor($cantidadAgregar);
    if ($cantidadEntera <= 0) return;

    $stmtInsert = $conn->prepare("
        INSERT INTO codigos_barras (producto_id, codigo, disponible)
        VALUES (?, ?, 1)
    ");

    if (!$stmtInsert) return;

    for ($i = $ultimoCodigo + 1; $i <= $ultimoCodigo + $cantidadEntera; $i++) {
        $nuevoCodigo = $productoId . str_pad((string)$i, 5, '0', STR_PAD_LEFT);
        $stmtInsert->bind_param('is', $productoId, $nuevoCodigo);
        $stmtInsert->execute();
    }
}

/* ========================= ACCIONES ========================= */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    $action = $_POST['action'] ?? '';
    $productoId = (int)($_POST['producto_id'] ?? 0);

    if ($productoId <= 0 || !vendedorPuedeGestionarProducto($conn, $usuarioId, $rol, $productoId)) {
        $errors[] = 'No tienes permiso para gestionar este producto.';
    }

    $producto = null;

    if (empty($errors)) {
        $stmt = $conn->prepare("
            SELECT 
                id, nombre, categoria, proveedor, proveedor_id, cantidad,
                precio_compra, precio_venta, tipo_codigo, tipo_inventario,
                tipo_adquisicion, imagen
            FROM productos
            WHERE id = ?
              AND activo = 1
              AND tipo_inventario = 'producto'
            LIMIT 1
        ");

        if (!$stmt) {
            $errors[] = 'Error al consultar producto: '.$conn->error;
        } else {
            $stmt->bind_param('i', $productoId);
            $stmt->execute();
            $producto = $stmt->get_result()->fetch_assoc();

            if (!$producto) {
                $errors[] = 'Producto no encontrado.';
            }
        }
    }

    if (empty($errors) && $action === 'add_stock') {
        $cantidadAgregar = (float)($_POST['cantidad'] ?? 0);
        $nota = trim($_POST['nota'] ?? '');

        if ($cantidadAgregar <= 0) $errors[] = 'La cantidad a agregar debe ser mayor a 0.';

        if (empty($errors)) {
            $conn->begin_transaction();

            try {
                $anterior = (float)$producto['cantidad'];
                $nueva = $anterior + $cantidadAgregar;
                $notaFinal = $nota !== '' ? $nota : 'Entrada de stock por vendedor';

                $stmt = $conn->prepare("UPDATE productos SET cantidad = ? WHERE id = ?");
                if (!$stmt) throw new Exception($conn->error);

                $stmt->bind_param('di', $nueva, $productoId);
                if (!$stmt->execute()) throw new Exception($stmt->error);

                registrarMovimientoStock($conn, $productoId, $anterior, $nueva, $cantidadAgregar, 'entrada', 'ENTRADA VENDEDOR: '.$notaFinal, $usuarioId);

                generarCodigosNuevosSiAplica($conn, $productoId, $cantidadAgregar, $producto['tipo_codigo'], $producto['tipo_inventario']);

                $conn->commit();

                $_SESSION['flash_success'] = 'Stock agregado correctamente.';
                header('Location: vendedor_ajustes_productos.php');
                exit;
            } catch (Exception $e) {
                $conn->rollback();
                $errors[] = 'Error al agregar stock: '.$e->getMessage();
            }
        }
    }

    if (empty($errors) && $action === 'adjust_stock') {
        $nuevaCantidad = (float)($_POST['nueva_cantidad'] ?? 0);
        $razon = trim($_POST['razon_ajuste'] ?? '');

        if ($nuevaCantidad < 0) $errors[] = 'La cantidad no puede ser negativa.';
        if ($razon === '') $errors[] = 'La razón del ajuste es obligatoria.';

        if (empty($errors)) {
            $conn->begin_transaction();

            try {
                $anterior = (float)$producto['cantidad'];
                $diferencia = $nuevaCantidad - $anterior;

                $stmt = $conn->prepare("UPDATE productos SET cantidad = ? WHERE id = ?");
                if (!$stmt) throw new Exception($conn->error);

                $stmt->bind_param('di', $nuevaCantidad, $productoId);
                if (!$stmt->execute()) throw new Exception($stmt->error);

                $nota = 'AJUSTE VENDEDOR: '.$razon.' (diferencia: '.($diferencia >= 0 ? '+'.$diferencia : $diferencia).')';
                registrarMovimientoStock($conn, $productoId, $anterior, $nuevaCantidad, $diferencia, 'ajuste', $nota, $usuarioId);

                if ($diferencia > 0) {
                    generarCodigosNuevosSiAplica($conn, $productoId, $diferencia, $producto['tipo_codigo'], $producto['tipo_inventario']);
                }

                $conn->commit();

                $_SESSION['flash_success'] = 'Stock ajustado correctamente.';
                header('Location: vendedor_ajustes_productos.php');
                exit;
            } catch (Exception $e) {
                $conn->rollback();
                $errors[] = 'Error al ajustar stock: '.$e->getMessage();
            }
        }
    }

    if (empty($errors) && $action === 'update_product') {
        $nombre = trim($_POST['nombre'] ?? '');
        $categoria = trim($_POST['categoria'] ?? '');
        $proveedorNombre = trim($_POST['proveedor'] ?? '');
        $precioCompra = (float)($_POST['precio_compra'] ?? 0);
        $precioVenta = (float)($_POST['precio_venta'] ?? 0);
        $tipoAdquisicion = $_POST['tipo_adquisicion'] ?? 'concesion';

        if ($nombre === '') $errors[] = 'El nombre es obligatorio.';
        if ($categoria === '') $errors[] = 'La categoría es obligatoria.';
        if ($precioCompra < 0 || $precioVenta < 0) $errors[] = 'Los precios no pueden ser negativos.';
        if (!in_array($tipoAdquisicion, ['pagado', 'concesion'], true)) $tipoAdquisicion = 'concesion';

        if (empty($errors)) {
            [$proveedorId, $proveedorFinal] = crearProveedorSiNoExiste($conn, $proveedorNombre);

            $stmt = $conn->prepare("
                UPDATE productos
                SET nombre = ?, categoria = ?, proveedor = ?, proveedor_id = ?,
                    precio_compra = ?, precio_venta = ?, tipo_adquisicion = ?
                WHERE id = ?
            ");

            if (!$stmt) {
                $errors[] = 'Error al preparar actualización: '.$conn->error;
            } else {
                $stmt->bind_param('sssiddsi', $nombre, $categoria, $proveedorFinal, $proveedorId, $precioCompra, $precioVenta, $tipoAdquisicion, $productoId);

                if ($stmt->execute()) {
                    $_SESSION['flash_success'] = 'Producto actualizado correctamente.';
                    header('Location: vendedor_ajustes_productos.php');
                    exit;
                }

                $errors[] = 'Error al actualizar producto: '.$stmt->error;
            }
        }
    }
}

/* ========================= CONSULTA PRINCIPAL ========================= */
$params = [$usuarioId, $usuarioId, $usuarioId, $usuarioId];
$types = 'iiii';

$sql = "
    SELECT
        p.id, p.nombre, p.categoria, p.proveedor, p.proveedor_id, p.cantidad,
        p.precio_compra, p.precio_venta, p.tipo_codigo, p.tipo_adquisicion,
        p.imagen, p.fecha_registro,

        /* Ventas propias: ventas realizadas por el vendedor actual */
        IFNULL(SUM(CASE WHEN v.id_vendedor = ? THEN v.cantidad_vendida ELSE 0 END), 0) AS total_vendido_propio,

        /* Ventas de terceros: mismo producto vendido por otro usuario o ventas antiguas sin vendedor */
        IFNULL(SUM(CASE WHEN v.id_vendedor IS NULL OR v.id_vendedor <> ? THEN v.cantidad_vendida ELSE 0 END), 0) AS total_vendido_terceros,

        IFNULL(MAX(CASE WHEN v.id_vendedor = ? THEN v.fecha_venta ELSE NULL END), '') AS ultima_venta_propia,
        IFNULL(MAX(CASE WHEN v.id_vendedor IS NULL OR v.id_vendedor <> ? THEN v.fecha_venta ELSE NULL END), '') AS ultima_venta_terceros
    FROM productos p
";

if ($rol === 'vendedor') {
    $sql .= "
        INNER JOIN vendedor_productos vp
            ON vp.producto_id = p.id
           AND vp.activo = 1
           AND vp.vendedor_id = ?
    ";
    $params[] = $usuarioId;
    $types .= 'i';
}

$sql .= "
    LEFT JOIN ventas v ON v.id_producto = p.id
    WHERE p.activo = 1
      AND p.tipo_inventario = 'producto'
    GROUP BY
        p.id, p.nombre, p.categoria, p.proveedor, p.proveedor_id, p.cantidad,
        p.precio_compra, p.precio_venta, p.tipo_codigo, p.tipo_adquisicion,
        p.imagen, p.fecha_registro
    ORDER BY p.proveedor ASC, p.nombre ASC
";

$productos = [];
$stmt = $conn->prepare($sql);

if ($stmt) {
    if (!empty($params)) $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $res = $stmt->get_result();

    while ($row = $res->fetch_assoc()) {
        $productos[] = $row;
    }
} else {
    $errors[] = 'Error al consultar productos asignados: '.$conn->error;
}

$categorias = obtenerCategoriasAsignadas($conn, $usuarioId, $rol);
$proveedores = obtenerProveedoresAsignados($conn, $usuarioId, $rol);

$totalProductos = count($productos);
$totalStock = 0;
$totalValorInventario = 0;
$totalVendidos = 0;
$totalVendidosTerceros = 0;
$productosStockBajo = 0;

foreach ($productos as $p) {
    $stock = (float)$p['cantidad'];
    $totalStock += $stock;
    $totalValorInventario += $stock * (float)$p['precio_venta'];
    $totalVendidos += (int)$p['total_vendido_propio'];
    $totalVendidosTerceros += (int)$p['total_vendido_terceros'];
    if ($stock <= 5) $productosStockBajo++;
}

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/navbar.php';
?>

<link rel="stylesheet" href="css/vendedor_ajustes_productos.css?v=<?= time() ?>">

<div class="content-wrapper vendedor-ajustes-page">
    <section class="content">
        <div class="container-fluid">

            <div class="ajustes-header-card">
                <div class="header-icon">
                    <i class="fas fa-sliders-h"></i>
                </div>

                <div class="header-copy">
                    <span>Gestión autorizada</span>
                    <h1>Mis productos asignados</h1>
                    <p>Agrega stock, ajusta inventario y edita datos básicos únicamente de productos autorizados.</p>
                </div>

                <div class="header-actions">
                    <a href="reporte_vendedor_productos.php" class="btn btn-outline-primary">
                        <i class="fas fa-chart-line mr-1"></i> Reporte
                    </a>
                </div>
            </div>

            <?php if (!empty($_SESSION['flash_success'])): ?>
                <script>
                    document.addEventListener('DOMContentLoaded', function () {
                        Swal.fire({
                            icon: 'success',
                            title: 'Proceso correcto',
                            text: <?= json_encode($_SESSION['flash_success']) ?>,
                            confirmButtonColor: '#f97316'
                        });
                    });
                </script>
                <?php unset($_SESSION['flash_success']); ?>
            <?php endif; ?>

            <?php if (!empty($errors)): ?>
                <div class="alert alert-danger shadow-sm">
                    <strong><i class="fas fa-exclamation-triangle mr-1"></i> Revisa lo siguiente:</strong><br>
                    <?= implode('<br>', array_map('e', $errors)) ?>
                </div>
            <?php endif; ?>

            <div class="row metric-row">
                <div class="col-xl-3 col-md-6 mb-3">
                    <div class="metric-card metric-orange">
                        <div>
                            <span>Productos</span>
                            <strong><?= number_format($totalProductos) ?></strong>
                            <small>asignados actualmente</small>
                        </div>
                        <i class="fas fa-boxes"></i>
                    </div>
                </div>

                <div class="col-xl-3 col-md-6 mb-3">
                    <div class="metric-card metric-blue">
                        <div>
                            <span>Stock total</span>
                            <strong><?= number_format($totalStock) ?></strong>
                            <small>unidades disponibles</small>
                        </div>
                        <i class="fas fa-warehouse"></i>
                    </div>
                </div>

                <div class="col-xl-3 col-md-6 mb-3">
                    <div class="metric-card metric-green">
                        <div>
                            <span>Vendidos</span>
                            <strong><?= number_format($totalVendidos) ?></strong>
                            <small>Propias · <?= number_format($totalVendidosTerceros) ?> de terceros</small>
                        </div>
                        <i class="fas fa-chart-line"></i>
                    </div>
                </div>

                <div class="col-xl-3 col-md-6 mb-3">
                    <div class="metric-card metric-dark">
                        <div>
                            <span>Valor inventario</span>
                            <strong>$<?= number_format($totalValorInventario, 2) ?></strong>
                            <small><?= number_format($productosStockBajo) ?> con stock bajo</small>
                        </div>
                        <i class="fas fa-dollar-sign"></i>
                    </div>
                </div>
            </div>

            <div class="filters-card">
                <div class="row align-items-end">
                    <div class="col-lg-5 col-md-12 mb-3">
                        <label>Buscar</label>
                        <input 
                            type="text" 
                            id="buscarProducto"
                            class="form-control form-control-lg" 
                            placeholder="Producto, proveedor o categoría"
                            autocomplete="off"
                        >
                    </div>

                    <div class="col-lg-3 col-md-6 mb-3">
                        <label>Proveedor</label>
                        <select id="filtroProveedor" class="form-control form-control-lg">
                            <option value="">Todos los proveedores</option>
                            <?php foreach ($proveedores as $prov): ?>
                                <option value="<?= e(strtolower($prov)) ?>"><?= e($prov) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="col-lg-2 col-md-6 mb-3">
                        <label>Categoría</label>
                        <select id="filtroCategoria" class="form-control form-control-lg">
                            <option value="">Todas</option>
                            <?php foreach ($categorias as $cat): ?>
                                <option value="<?= e(strtolower($cat)) ?>"><?= e($cat) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="col-lg-2 col-md-12 mb-3">
                        <div class="filter-buttons">
                            <button type="button" id="btnLimpiarFiltros" class="btn btn-outline-secondary btn-lg">
                                <i class="fas fa-eraser"></i> Borrar filtros
                            </button>
                        </div>
                    </div>
                </div>
            </div>

            <div class="inventory-panel">
                <div class="inventory-header">
                    <div>
                        <h3><i class="fas fa-list mr-2"></i>Inventario asignado</h3>
                        <p>Usa las acciones para agregar, ajustar o editar tus productos.</p>
                    </div>
                    <span class="inventory-count"><span id="contadorProductos"><?= number_format($totalProductos) ?></span> productos</span>
                </div>

                <?php if (empty($productos)): ?>
                    <div class="empty-state">
                        <i class="fas fa-box-open"></i>
                        <h4>No hay productos asignados</h4>
                        <p>Aún no tienes productos asignados o no existen productos activos.</p>
                    </div>
                <?php else: ?>

                    <div class="table-responsive inventory-table-wrap">
                        <table class="table table-hover inventory-table" id="tablaProductos">
                            <thead>
                                <tr>
                                    <th>Producto</th>
                                    <th>Proveedor</th>
                                    <th>Categoría</th>
                                    <th class="text-center">Stock</th>
                                    <th class="text-right">Compra</th>
                                    <th class="text-right">Venta</th>
                                    <th class="text-center">Adquisición</th>
                                    <th class="text-center">Vendidos<br><small>Propios / terceros</small></th>
                                    <th class="text-center">Acciones</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($productos as $p): ?>
                                    <?php
                                        $stock = (float)$p['cantidad'];
                                        $stockClass = $stock <= 0 ? 'stock-danger' : ($stock <= 5 ? 'stock-warning' : 'stock-success');
                                        $adqClass = $p['tipo_adquisicion'] === 'pagado' ? 'adq-pagado' : 'adq-concesion';
                                        $proveedorTxt = $p['proveedor'] ?: 'Sin proveedor';
                                        $categoriaTxt = $p['categoria'] ?: 'Sin categoría';
                                        $searchText = strtolower($p['nombre'].' '.$proveedorTxt.' '.$categoriaTxt.' '.$p['tipo_adquisicion']);
                                    ?>
                                    <tr 
                                        class="producto-row"
                                        data-search="<?= e($searchText) ?>"
                                        data-proveedor="<?= e(strtolower($proveedorTxt)) ?>"
                                        data-categoria="<?= e(strtolower($categoriaTxt)) ?>"
                                    >
                                        <td>
                                            <div class="product-cell">
                                                <div class="product-avatar">
                                                    <?php if (!empty($p['imagen']) && file_exists($p['imagen'])): ?>
                                                        <img src="<?= e($p['imagen']) ?>" alt="<?= e($p['nombre']) ?>">
                                                    <?php else: ?>
                                                        <i class="fas fa-box"></i>
                                                    <?php endif; ?>
                                                </div>
                                                <div>
                                                    <strong><?= e($p['nombre']) ?></strong>
                                                    <small>ID #<?= (int)$p['id'] ?> · <?= e(ucfirst($p['tipo_codigo'])) ?></small>
                                                </div>
                                            </div>
                                        </td>
                                        <td><?= e($proveedorTxt) ?></td>
                                        <td><span class="soft-badge"><?= e($categoriaTxt) ?></span></td>
                                        <td class="text-center">
                                            <span class="stock-pill <?= $stockClass ?>">
                                                <?= number_format($stock) ?> pz
                                            </span>
                                        </td>
                                        <td class="text-right">$<?= number_format((float)$p['precio_compra'], 2) ?></td>
                                        <td class="text-right font-weight-bold text-success">$<?= number_format((float)$p['precio_venta'], 2) ?></td>
                                        <td class="text-center">
                                            <span class="adq-badge <?= $adqClass ?>">
                                                <?= $p['tipo_adquisicion'] === 'pagado' ? 'Pagado' : 'Concesión' ?>
                                            </span>
                                        </td>
                                        <td class="text-center">
                                            <div class="ventas-indicador">
                                                <span class="venta-propia" title="Ventas realizadas por este vendedor">
                                                    <i class="fas fa-user-check"></i>
                                                    <?= number_format((int)$p['total_vendido_propio']) ?>
                                                </span>

                                                <?php if ((int)$p['total_vendido_terceros'] > 0): ?>
                                                    <span class="venta-terceros" title="Ventas de este producto realizadas por otro vendedor o ventas antiguas sin vendedor registrado">
                                                        <i class="fas fa-users"></i>
                                                        <?= number_format((int)$p['total_vendido_terceros']) ?>
                                                    </span>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                        <td class="text-center">
                                            <div class="action-group">
                                                <button type="button" class="btn-action btn-add js-open-modal" data-modal="modalAgregar<?= (int)$p['id'] ?>" title="Agregar stock">
                                                    <i class="fas fa-plus"></i>
                                                </button>
                                                <button type="button" class="btn-action btn-adjust js-open-modal" data-modal="modalAjustar<?= (int)$p['id'] ?>" title="Ajustar stock">
                                                    <i class="fas fa-sliders-h"></i>
                                                </button>
                                                <button type="button" class="btn-action btn-edit js-open-modal" data-modal="modalEditar<?= (int)$p['id'] ?>" title="Editar producto">
                                                    <i class="fas fa-edit"></i>
                                                </button>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                    <div class="mobile-products-grid" id="mobileProductsGrid">
                        <?php foreach ($productos as $p): ?>
                            <?php
                                $stock = (float)$p['cantidad'];
                                $stockClass = $stock <= 0 ? 'stock-danger' : ($stock <= 5 ? 'stock-warning' : 'stock-success');
                                $adqClass = $p['tipo_adquisicion'] === 'pagado' ? 'adq-pagado' : 'adq-concesion';
                                $proveedorTxt = $p['proveedor'] ?: 'Sin proveedor';
                                $categoriaTxt = $p['categoria'] ?: 'Sin categoría';
                                $searchText = strtolower($p['nombre'].' '.$proveedorTxt.' '.$categoriaTxt.' '.$p['tipo_adquisicion']);
                            ?>
                            <div 
                                class="mobile-product-card producto-card"
                                data-search="<?= e($searchText) ?>"
                                data-proveedor="<?= e(strtolower($proveedorTxt)) ?>"
                                data-categoria="<?= e(strtolower($categoriaTxt)) ?>"
                            >
                                <div class="mobile-card-top">
                                    <div class="product-avatar">
                                        <?php if (!empty($p['imagen']) && file_exists($p['imagen'])): ?>
                                            <img src="<?= e($p['imagen']) ?>" alt="<?= e($p['nombre']) ?>">
                                        <?php else: ?>
                                            <i class="fas fa-box"></i>
                                        <?php endif; ?>
                                    </div>
                                    <div>
                                        <h4><?= e($p['nombre']) ?></h4>
                                        <p><?= e($proveedorTxt) ?></p>
                                    </div>
                                </div>

                                <div class="mobile-info-grid">
                                    <div>
                                        <small>Stock</small>
                                        <strong class="<?= $stockClass ?>"><?= number_format($stock) ?> pz</strong>
                                    </div>
                                    <div>
                                        <small>Venta</small>
                                        <strong>$<?= number_format((float)$p['precio_venta'], 2) ?></strong>
                                    </div>
                                    <div>
                                        <small>Compra</small>
                                        <strong>$<?= number_format((float)$p['precio_compra'], 2) ?></strong>
                                    </div>
                                    <div>
                                        <small>Vendidos</small>
                                        <strong>
                                            <?= number_format((int)$p['total_vendido_propio']) ?>
                                            <?php if ((int)$p['total_vendido_terceros'] > 0): ?>
                                                <span class="mobile-terceros">+<?= number_format((int)$p['total_vendido_terceros']) ?> terceros</span>
                                            <?php endif; ?>
                                        </strong>
                                    </div>
                                </div>

                                <div class="mobile-card-footer">
                                    <span class="adq-badge <?= $adqClass ?>">
                                        <?= $p['tipo_adquisicion'] === 'pagado' ? 'Pagado' : 'Concesión' ?>
                                    </span>
                                    <div class="action-group">
                                        <button type="button" class="btn-action btn-add js-open-modal" data-modal="modalAgregar<?= (int)$p['id'] ?>">
                                            <i class="fas fa-plus"></i>
                                        </button>
                                        <button type="button" class="btn-action btn-adjust js-open-modal" data-modal="modalAjustar<?= (int)$p['id'] ?>">
                                            <i class="fas fa-sliders-h"></i>
                                        </button>
                                        <button type="button" class="btn-action btn-edit js-open-modal" data-modal="modalEditar<?= (int)$p['id'] ?>">
                                            <i class="fas fa-edit"></i>
                                        </button>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>

                    <div id="sinResultados" class="empty-state" style="display:none;">
                        <i class="fas fa-search"></i>
                        <h4>No se encontraron productos</h4>
                        <p>Prueba limpiando filtros o buscando otro producto.</p>
                    </div>

                <?php endif; ?>
            </div>

            <?php foreach ($productos as $p): ?>
                <?php
                    $stock = (float)$p['cantidad'];
                    $proveedorActual = $p['proveedor'] ?: '';
                ?>

                <div class="modal fade vendedor-custom-modal" id="modalAgregar<?= (int)$p['id'] ?>" tabindex="-1">
                    <div class="modal-dialog modal-dialog-centered">
                        <form method="POST" class="modal-content vendedor-modal js-confirm-form">
                            <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                            <input type="hidden" name="action" value="add_stock">
                            <input type="hidden" name="producto_id" value="<?= (int)$p['id'] ?>">

                            <div class="modal-header">
                                <h5 class="modal-title"><i class="fas fa-plus-circle mr-2"></i>Agregar stock</h5>
                                <button type="button" class="close text-white js-close-modal" data-modal="modalAgregar<?= (int)$p['id'] ?>">&times;</button>
                            </div>

                            <div class="modal-body">
                                <div class="modal-product-name"><?= e($p['nombre']) ?></div>

                                <label>Stock actual</label>
                                <input type="number" class="form-control mb-3" value="<?= e($stock) ?>" readonly>

                                <label>Cantidad a agregar</label>
                                <input type="number" name="cantidad" min="1" step="1" class="form-control form-control-lg mb-3" required>

                                <label>Nota</label>
                                <textarea name="nota" rows="3" class="form-control" placeholder="Ej. Entrada por conteo físico, reposición, entrega..."></textarea>
                            </div>

                            <div class="modal-footer">
                                <button type="submit" class="btn btn-primary">
                                    <i class="fas fa-save mr-1"></i>Guardar entrada
                                </button>
                            </div>
                        </form>
                    </div>
                </div>

                <div class="modal fade vendedor-custom-modal" id="modalAjustar<?= (int)$p['id'] ?>" tabindex="-1">
                    <div class="modal-dialog modal-dialog-centered">
                        <form method="POST" class="modal-content vendedor-modal js-confirm-form">
                            <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                            <input type="hidden" name="action" value="adjust_stock">
                            <input type="hidden" name="producto_id" value="<?= (int)$p['id'] ?>">

                            <div class="modal-header">
                                <h5 class="modal-title"><i class="fas fa-sliders-h mr-2"></i>Ajustar stock</h5>
                                <button type="button" class="close text-white js-close-modal" data-modal="modalAjustar<?= (int)$p['id'] ?>">&times;</button>
                            </div>

                            <div class="modal-body">
                                <div class="modal-product-name"><?= e($p['nombre']) ?></div>

                                <label>Stock actual</label>
                                <input type="number" class="form-control mb-3" value="<?= e($stock) ?>" readonly>

                                <label>Nueva cantidad</label>
                                <input 
                                    type="number" 
                                    name="nueva_cantidad" 
                                    min="0" 
                                    step="1" 
                                    class="form-control form-control-lg mb-2 js-ajuste-cantidad" 
                                    value="<?= e($stock) ?>" 
                                    data-stock-actual="<?= e($stock) ?>"
                                    required
                                >

                                <div class="ajuste-preview neutral">
                                    <i class="fas fa-equals"></i>
                                    <span>Sin cambios en inventario</span>
                                </div>

                                <label>Razón del ajuste</label>
                                <textarea name="razon_ajuste" rows="3" class="form-control" required placeholder="Ej. Producto dañado, corrección de inventario, conteo físico..."></textarea>
                            </div>

                            <div class="modal-footer">
                                <button type="submit" class="btn btn-primary">
                                    <i class="fas fa-save mr-1"></i>Guardar ajuste
                                </button>
                            </div>
                        </form>
                    </div>
                </div>

                <div class="modal fade vendedor-custom-modal" id="modalEditar<?= (int)$p['id'] ?>" tabindex="-1">
                    <div class="modal-dialog modal-dialog-centered modal-lg">
                        <form method="POST" class="modal-content vendedor-modal js-confirm-form">
                            <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                            <input type="hidden" name="action" value="update_product">
                            <input type="hidden" name="producto_id" value="<?= (int)$p['id'] ?>">

                            <div class="modal-header">
                                <h5 class="modal-title"><i class="fas fa-edit mr-2"></i>Editar producto</h5>
                                <button type="button" class="close text-white js-close-modal" data-modal="modalEditar<?= (int)$p['id'] ?>">&times;</button>
                            </div>

                            <div class="modal-body">
                                <div class="row">
                                    <div class="col-md-12 mb-3">
                                        <label>Nombre</label>
                                        <input type="text" name="nombre" class="form-control" value="<?= e($p['nombre']) ?>" required>
                                    </div>

                                    <div class="col-md-6 mb-3">
                                        <label>Categoría</label>
                                        <input type="text" name="categoria" class="form-control" value="<?= e($p['categoria']) ?>" required>
                                    </div>

                                    <div class="col-md-6 mb-3">
                                        <label>Proveedor</label>
                                        <input type="text" name="proveedor" class="form-control" value="<?= e($proveedorActual) ?>" placeholder="Sin proveedor">
                                    </div>

                                    <div class="col-md-4 mb-3">
                                        <label>Precio compra</label>
                                        <input type="number" name="precio_compra" min="0" step="0.01" class="form-control" value="<?= e($p['precio_compra']) ?>" required>
                                    </div>

                                    <div class="col-md-4 mb-3">
                                        <label>Precio venta</label>
                                        <input type="number" name="precio_venta" min="0" step="0.01" class="form-control" value="<?= e($p['precio_venta']) ?>" required>
                                    </div>

                                    <div class="col-md-4 mb-3">
                                        <label>Adquisición</label>
                                        <select name="tipo_adquisicion" class="form-control">
                                            <option value="concesion" <?= $p['tipo_adquisicion'] === 'concesion' ? 'selected' : '' ?>>Concesión</option>
                                            <option value="pagado" <?= $p['tipo_adquisicion'] === 'pagado' ? 'selected' : '' ?>>Pagado</option>
                                        </select>
                                    </div>
                                </div>

                                <div class="alert alert-info mb-0">
                                    <i class="fas fa-info-circle mr-1"></i>
                                    Solo puedes editar información básica del producto asignado.
                                </div>
                            </div>

                            <div class="modal-footer">
                                <button type="submit" class="btn btn-primary">
                                    <i class="fas fa-save mr-1"></i>Guardar cambios
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            <?php endforeach; ?>

        </div>
    </section>
</div>

<div class="modal-backdrop-custom" id="modalBackdropCustom"></div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const buscador = document.getElementById('buscarProducto');
    const filtroProveedor = document.getElementById('filtroProveedor');
    const filtroCategoria = document.getElementById('filtroCategoria');
    const btnLimpiar = document.getElementById('btnLimpiarFiltros');
    const contadorProductos = document.getElementById('contadorProductos');
    const sinResultados = document.getElementById('sinResultados');
    const backdropCustom = document.getElementById('modalBackdropCustom');

    const tableRows = Array.from(document.querySelectorAll('.producto-row'));
    const mobileCards = Array.from(document.querySelectorAll('.producto-card'));


    function actualizarPreviewAjuste(input) {
        const actual = parseFloat(input.dataset.stockActual || '0');
        const nueva = parseFloat(input.value || '0');
        const preview = input.closest('.modal-body')?.querySelector('.ajuste-preview');

        if (!preview) return;

        const diferencia = nueva - actual;

        preview.classList.remove('increase', 'decrease', 'neutral');

        if (isNaN(nueva) || diferencia === 0) {
            preview.classList.add('neutral');
            preview.innerHTML = '<i class="fas fa-equals"></i><span>Sin cambios en inventario</span>';
            return;
        }

        if (diferencia > 0) {
            preview.classList.add('increase');
            preview.innerHTML = '<i class="fas fa-arrow-up"></i><span>Aumentará +' + diferencia.toLocaleString('es-MX') + ' piezas</span>';
            return;
        }

        preview.classList.add('decrease');
        preview.innerHTML = '<i class="fas fa-arrow-down"></i><span>Se descontarán ' + Math.abs(diferencia).toLocaleString('es-MX') + ' piezas</span>';
    }

    document.querySelectorAll('.js-ajuste-cantidad').forEach(input => {
        actualizarPreviewAjuste(input);
        input.addEventListener('input', function () {
            actualizarPreviewAjuste(this);
        });
        input.addEventListener('change', function () {
            actualizarPreviewAjuste(this);
        });
    });


    function aplicarFiltros() {
        const q = (buscador?.value || '').trim().toLowerCase();
        const prov = (filtroProveedor?.value || '').trim().toLowerCase();
        const cat = (filtroCategoria?.value || '').trim().toLowerCase();

        let visiblesTabla = 0;

        tableRows.forEach(row => {
            const okSearch = !q || row.dataset.search.includes(q);
            const okProv = !prov || row.dataset.proveedor === prov;
            const okCat = !cat || row.dataset.categoria === cat;
            const visible = okSearch && okProv && okCat;

            row.style.display = visible ? '' : 'none';
            if (visible) visiblesTabla++;
        });

        mobileCards.forEach(card => {
            const okSearch = !q || card.dataset.search.includes(q);
            const okProv = !prov || card.dataset.proveedor === prov;
            const okCat = !cat || card.dataset.categoria === cat;
            const visible = okSearch && okProv && okCat;

            card.style.display = visible ? '' : 'none';
        });

        if (contadorProductos) contadorProductos.textContent = visiblesTabla;

        if (sinResultados) {
            sinResultados.style.display = visiblesTabla === 0 ? '' : 'none';
        }
    }

    [buscador, filtroProveedor, filtroCategoria].forEach(el => {
        if (el) {
            el.addEventListener('input', aplicarFiltros);
            el.addEventListener('change', aplicarFiltros);
        }
    });

    if (btnLimpiar) {
        btnLimpiar.addEventListener('click', function () {
            if (buscador) buscador.value = '';
            if (filtroProveedor) filtroProveedor.value = '';
            if (filtroCategoria) filtroCategoria.value = '';
            aplicarFiltros();
        });
    }

    function openModal(modalId) {
        const modal = document.getElementById(modalId);
        if (!modal) return;

        // Bootstrap 5
        if (window.bootstrap && bootstrap.Modal) {
            const bsModal = bootstrap.Modal.getOrCreateInstance(modal);
            bsModal.show();
            return;
        }

        // Bootstrap 4 / jQuery
        if (window.jQuery && typeof jQuery(modal).modal === 'function') {
            jQuery(modal).modal('show');
            return;
        }

        // Fallback sin Bootstrap JS
        modal.classList.add('show');
        modal.style.display = 'block';
        modal.removeAttribute('aria-hidden');
        modal.setAttribute('aria-modal', 'true');
        document.body.classList.add('modal-open');
        if (backdropCustom) backdropCustom.classList.add('show');
    }

    function closeModal(modalId) {
        const modal = document.getElementById(modalId);
        if (!modal) return;

        if (window.bootstrap && bootstrap.Modal) {
            const instance = bootstrap.Modal.getInstance(modal);
            if (instance) instance.hide();
            return;
        }

        if (window.jQuery && typeof jQuery(modal).modal === 'function') {
            jQuery(modal).modal('hide');
            return;
        }

        modal.classList.remove('show');
        modal.style.display = 'none';
        modal.setAttribute('aria-hidden', 'true');
        modal.removeAttribute('aria-modal');
        document.body.classList.remove('modal-open');
        if (backdropCustom) backdropCustom.classList.remove('show');
    }

    document.querySelectorAll('.js-open-modal').forEach(btn => {
        btn.addEventListener('click', function () {
            openModal(this.dataset.modal);
        });
    });

    document.querySelectorAll('.js-close-modal').forEach(btn => {
        btn.addEventListener('click', function () {
            closeModal(this.dataset.modal);
        });
    });

    if (backdropCustom) {
        backdropCustom.addEventListener('click', function () {
            document.querySelectorAll('.vendedor-custom-modal.show').forEach(modal => {
                closeModal(modal.id);
            });
        });
    }

    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape') {
            document.querySelectorAll('.vendedor-custom-modal.show').forEach(modal => {
                closeModal(modal.id);
            });
        }
    });

    document.querySelectorAll('.js-confirm-form').forEach(function (form) {
        form.addEventListener('submit', function (event) {
            if (typeof Swal === 'undefined') return;

            event.preventDefault();

            Swal.fire({
                icon: 'question',
                title: 'Confirmar cambios',
                text: '¿Deseas guardar esta modificación?',
                showCancelButton: true,
                confirmButtonText: 'Sí, guardar',
                cancelButtonText: 'Cancelar',
                confirmButtonColor: '#f97316'
            }).then((result) => {
                if (result.isConfirmed) {
                    form.submit();
                }
            });
        });
    });

    aplicarFiltros();
});
</script>