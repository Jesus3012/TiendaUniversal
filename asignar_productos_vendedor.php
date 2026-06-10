<?php
error_reporting(E_ALL & ~E_DEPRECATED);
session_start();

require_once 'includes/db.php';
require_once 'includes/csrf.php';

if (!isset($_SESSION['usuario_id']) || ($_SESSION['rol'] ?? '') !== 'administrador') {
    header('Location: login.php');
    exit;
}

$adminId = (int)$_SESSION['usuario_id'];
$errors = [];

function e($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function registrarAuditoria($conn, $usuarioId, $accion, $detalle) {
    $ip = $_SERVER['REMOTE_ADDR'] ?? null;

    $stmt = $conn->prepare("INSERT INTO auditoria (usuario_id, accion, detalle, ip) VALUES (?, ?, ?, ?)");
    if ($stmt) {
        $stmt->bind_param('isss', $usuarioId, $accion, $detalle, $ip);
        $stmt->execute();
    }
}

/* ========================= GUARDAR ASIGNACIONES ========================= */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    $action = $_POST['action'] ?? '';
    $vendedorId = (int)($_POST['vendedor_id'] ?? 0);

    if ($vendedorId <= 0) {
        $errors[] = 'Selecciona un vendedor válido.';
    }

    $stmtValida = $conn->prepare("SELECT id, nombre FROM usuarios WHERE id = ? AND rol = 'vendedor' AND activo = 1");
    if (!$stmtValida) {
        $errors[] = 'Error al validar vendedor: '.$conn->error;
    } else {
        $stmtValida->bind_param('i', $vendedorId);
        $stmtValida->execute();
        $vendedor = $stmtValida->get_result()->fetch_assoc();

        if (!$vendedor) {
            $errors[] = 'El vendedor seleccionado no existe o está inactivo.';
        }
    }

    if (empty($errors) && $action === 'guardar_asignaciones') {
        $productosSeleccionados = $_POST['productos'] ?? [];
        $productosSeleccionados = array_values(array_unique(array_filter(array_map('intval', $productosSeleccionados))));

        if (!empty($productosSeleccionados)) {
            $placeholders = implode(',', array_fill(0, count($productosSeleccionados), '?'));
            $types = str_repeat('i', count($productosSeleccionados)) . 'i';
            $params = array_merge($productosSeleccionados, [$vendedorId]);

            $sqlCheck = "
                SELECT 
                    p.nombre AS producto,
                    u.nombre AS vendedor
                FROM vendedor_productos vp
                INNER JOIN productos p ON p.id = vp.producto_id
                INNER JOIN usuarios u ON u.id = vp.vendedor_id
                WHERE vp.activo = 1
                  AND vp.producto_id IN ($placeholders)
                  AND vp.vendedor_id <> ?
                ORDER BY p.nombre ASC
            ";

            $stmtCheck = $conn->prepare($sqlCheck);
            if (!$stmtCheck) {
                $errors[] = 'Error al validar productos asignados: '.$conn->error;
            } else {
                $stmtCheck->bind_param($types, ...$params);
                $stmtCheck->execute();
                $resCheck = $stmtCheck->get_result();

                while ($row = $resCheck->fetch_assoc()) {
                    $errors[] = 'El producto "'.$row['producto'].'" ya está asignado a '.$row['vendedor'].'.';
                }
            }
        }

        if (empty($errors)) {
            $conn->begin_transaction();

            try {
                $stmtDesactivar = $conn->prepare("UPDATE vendedor_productos SET activo = 0 WHERE vendedor_id = ?");
                if (!$stmtDesactivar) {
                    throw new Exception($conn->error);
                }

                $stmtDesactivar->bind_param('i', $vendedorId);
                $stmtDesactivar->execute();

                if (!empty($productosSeleccionados)) {
                    $stmtInsert = $conn->prepare("
                        INSERT INTO vendedor_productos 
                            (vendedor_id, producto_id, asignado_por, activo)
                        VALUES 
                            (?, ?, ?, 1)
                        ON DUPLICATE KEY UPDATE 
                            activo = 1,
                            asignado_por = VALUES(asignado_por),
                            fecha_actualizacion = CURRENT_TIMESTAMP
                    ");

                    if (!$stmtInsert) {
                        throw new Exception($conn->error);
                    }

                    foreach ($productosSeleccionados as $productoId) {
                        $stmtInsert->bind_param('iii', $vendedorId, $productoId, $adminId);
                        if (!$stmtInsert->execute()) {
                            throw new Exception($stmtInsert->error);
                        }
                    }
                }

                registrarAuditoria(
                    $conn,
                    $adminId,
                    'ASIGNACION_PRODUCTOS_VENDEDOR',
                    'Vendedor ID '.$vendedorId.' productos: '.implode(',', $productosSeleccionados)
                );

                $conn->commit();

                $_SESSION['flash_success'] = 'Productos asignados correctamente a '.$vendedor['nombre'].'.';
                header('Location: asignar_productos_vendedor.php?vendedor_id='.$vendedorId);
                exit;

            } catch (Exception $e) {
                $conn->rollback();
                $errors[] = 'Error al guardar asignaciones: '.$e->getMessage();
            }
        }
    }
}

/* ========================= DATOS BASE ========================= */
$vendedorSeleccionado = (int)($_GET['vendedor_id'] ?? ($_POST['vendedor_id'] ?? 0));

$vendedores = [];
$resVend = $conn->query("
    SELECT id, nombre, email 
    FROM usuarios 
    WHERE rol = 'vendedor' AND activo = 1 
    ORDER BY nombre ASC
");

if ($resVend) {
    while ($row = $resVend->fetch_assoc()) {
        $vendedores[] = $row;
    }
}

$asignados = [];
if ($vendedorSeleccionado > 0) {
    $stmt = $conn->prepare("
        SELECT producto_id 
        FROM vendedor_productos 
        WHERE vendedor_id = ? AND activo = 1
    ");
    if ($stmt) {
        $stmt->bind_param('i', $vendedorSeleccionado);
        $stmt->execute();
        $res = $stmt->get_result();

        while ($row = $res->fetch_assoc()) {
            $asignados[(int)$row['producto_id']] = true;
        }
    }
}

$productos = [];
$sqlProductos = "
    SELECT 
        p.id,
        p.nombre,
        p.categoria,
        p.proveedor,
        p.cantidad,
        p.precio_compra,
        p.precio_venta,
        p.tipo_codigo,
        p.tipo_adquisicion,

        /*
         * Ventas informativas del vendedor seleccionado:
         * Solo se suman ventas cuyo id_vendedor corresponde al vendedor seleccionado.
         * Las ventas con id_vendedor NULL o de otro vendedor no cuentan en la card.
         */
        IFNULL(SUM(v.cantidad_vendida), 0) AS total_vendido,
        IFNULL(MAX(v.fecha_venta), '') AS ultima_venta,

        MAX(CASE 
            WHEN vp_otro.vendedor_id IS NOT NULL THEN vp_otro.vendedor_id 
            ELSE NULL 
        END) AS asignado_otro_id,
        MAX(CASE 
            WHEN vp_otro.vendedor_id IS NOT NULL THEN u_otro.nombre 
            ELSE NULL 
        END) AS asignado_otro_nombre
    FROM productos p
    LEFT JOIN ventas v 
        ON v.id_producto = p.id
       AND v.id_vendedor = ?
    LEFT JOIN vendedor_productos vp_otro 
        ON vp_otro.producto_id = p.id
       AND vp_otro.activo = 1
       AND vp_otro.vendedor_id <> ?
    LEFT JOIN usuarios u_otro ON u_otro.id = vp_otro.vendedor_id
    WHERE p.activo = 1 
      AND p.tipo_inventario = 'producto'
    GROUP BY 
        p.id,
        p.nombre,
        p.categoria,
        p.proveedor,
        p.cantidad,
        p.precio_compra,
        p.precio_venta,
        p.tipo_codigo,
        p.tipo_adquisicion
    ORDER BY p.proveedor ASC, p.nombre ASC
";

$stmtProductos = $conn->prepare($sqlProductos);
if ($stmtProductos) {
    $stmtProductos->bind_param('ii', $vendedorSeleccionado, $vendedorSeleccionado);
    $stmtProductos->execute();
    $resProd = $stmtProductos->get_result();

    while ($row = $resProd->fetch_assoc()) {
        $productos[] = $row;
    }
} else {
    $errors[] = 'Error al consultar productos: '.$conn->error;
}

/* ========================= RESUMEN ========================= */
$totalProductos = count($productos);
$totalAsignados = count($asignados);
$proveedoresAsignados = [];
$valorAsignado = 0;
$ventasAsignadas = 0;

$categorias = [];
$proveedores = [];

foreach ($productos as $p) {
    $proveedorNormalizado = $p['proveedor'] ?: 'Sin proveedor';
    $categoriaNormalizada = $p['categoria'] ?: 'Sin categoría';

    $categorias[$categoriaNormalizada] = true;
    $proveedores[$proveedorNormalizado] = true;

    if (isset($asignados[(int)$p['id']])) {
        $proveedoresAsignados[$proveedorNormalizado] = true;
        $valorAsignado += ((float)$p['precio_venta'] * (float)$p['cantidad']);
        $ventasAsignadas += (int)$p['total_vendido'];
    }
}

ksort($categorias);
ksort($proveedores);

$nombreVendedorSeleccionado = '';
$emailVendedorSeleccionado = '';

foreach ($vendedores as $v) {
    if ((int)$v['id'] === $vendedorSeleccionado) {
        $nombreVendedorSeleccionado = $v['nombre'];
        $emailVendedorSeleccionado = $v['email'];
        break;
    }
}

include 'includes/header.php';
include 'includes/navbar.php';
?>

<link rel="stylesheet" href="css/asignar_productos_vendedor.css?v=<?= time() ?>">

<div class="content-wrapper asignacion-vendedores-page">

    <section class="content asignacion-content">
        <div class="container-fluid">

            <?php if (!empty($_SESSION['flash_success'])): ?>
                <script>
                    document.addEventListener('DOMContentLoaded', function () {

                        Swal.fire({
                            icon: 'success',
                            title: 'Asignaciones guardadas',
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

            <div class="admin-selector-card">
                <div class="selector-title">
                    <div>
                        <h1><i class="fas fa-user-tag mr-2"></i>Asignación de productos</h1>
                        <p>Selecciona un vendedor y define los productos que podrá gestionar.</p>
                    </div>

                    <div class="selector-actions">
                        <a href="ajustes_productos.php" class="btn btn-outline-secondary btn-sm">
                            <i class="fas fa-boxes mr-1"></i> Ajustes
                        </a>
                    </div>
                </div>

                <form method="GET" class="row align-items-end">
                    <div class="col-lg-8 col-md-7 mb-3 mb-md-0">
                        <label>Selecciona vendedor</label>
                        <select name="vendedor_id" id="vendedorSelect" class="form-control form-control-lg" required>
                            <option value="">Selecciona un vendedor</option>

                            <?php foreach ($vendedores as $v): ?>
                                <option value="<?= (int)$v['id'] ?>" <?= $vendedorSeleccionado === (int)$v['id'] ? 'selected' : '' ?>>
                                    <?= e($v['nombre']) ?> — <?= e($v['email']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="col-lg-4 col-md-5">
                        <a href="asignar_productos_vendedor.php"
                        class="btn btn-outline-secondary btn-lg btn-block">
                            <i class="fas fa-broom mr-1"></i>
                            Limpiar filtros
                        </a>
                    </div>
                </form>
            </div>

            <?php if ($vendedorSeleccionado > 0): ?>

                <div class="vendedor-profile-card">
                    <div class="profile-main">
                        <div class="profile-avatar">
                            <?= e(mb_strtoupper(mb_substr($nombreVendedorSeleccionado, 0, 1))) ?>
                        </div>

                        <div>
                            <span>Vendedor seleccionado</span>
                            <h2><?= e($nombreVendedorSeleccionado) ?></h2>
                            <p><?= e($emailVendedorSeleccionado) ?></p>
                        </div>
                    </div>

                    <div class="profile-status">
                        <i class="fas fa-circle"></i>
                        Activo
                    </div>
                </div>

                <div class="row resumen-row">
                    <div class="col-xl-3 col-md-6 mb-3">
                        <div class="metric-card metric-orange">
                            <div>
                                <span>Asignados</span>
                                <strong id="metricAsignados"><?= number_format($totalAsignados) ?></strong>
                                <small>de <?= number_format($totalProductos) ?> productos</small>
                            </div>
                            <i class="fas fa-check-double"></i>
                        </div>
                    </div>

                    <div class="col-xl-3 col-md-6 mb-3">
                        <div class="metric-card metric-blue">
                            <div>
                                <span>Proveedores</span>
                                <strong id="metricProveedores"><?= number_format(count($proveedoresAsignados)) ?></strong>
                                <small>relacionados al vendedor</small>
                            </div>
                            <i class="fas fa-truck"></i>
                        </div>
                    </div>

                    <div class="col-xl-3 col-md-6 mb-3">
                        <div class="metric-card metric-green">
                            <div>
                                <span>Ventas históricas</span>
                                <strong id="metricVentas"><?= number_format($ventasAsignadas) ?></strong>
                                <small>unidades vendidas</small>
                            </div>
                            <i class="fas fa-chart-line"></i>
                        </div>
                    </div>

                    <div class="col-xl-3 col-md-6 mb-3">
                        <div class="metric-card metric-dark">
                            <div>
                                <span>Valor inventario</span>
                                <strong id="metricValor">$<?= number_format($valorAsignado, 2) ?></strong>
                                <small>productos asignados</small>
                            </div>
                            <i class="fas fa-warehouse"></i>
                        </div>
                    </div>
                </div>

                <form method="POST" id="formAsignaciones">
                    <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                    <input type="hidden" name="action" value="guardar_asignaciones">
                    <input type="hidden" name="vendedor_id" value="<?= $vendedorSeleccionado ?>">

                    <div class="productos-panel">
                        <div class="productos-panel-header">
                            <div>
                                <h3>
                                    <i class="fas fa-boxes mr-2"></i>
                                    Productos disponibles
                                </h3>

                                <p>
                                    Los productos bloqueados ya pertenecen a otro vendedor activo.
                                </p>
                            </div>

                            <div class="panel-actions">
                                <button type="button" class="btn btn-outline-success btn-sm" id="seleccionarTodos">
                                    <i class="fas fa-check-double mr-1"></i>
                                    Todos visibles
                                </button>

                                <button type="button" class="btn btn-outline-secondary btn-sm" id="limpiarTodo">
                                    <i class="fas fa-eraser mr-1"></i>
                                    Limpiar
                                </button>
                            </div>
                        </div>

                        <div class="filter-toolbar">
                            <div class="filter-control search-control">
                                <i class="fas fa-search"></i>
                                <input type="text" id="buscarProducto" placeholder="Buscar producto, proveedor o categoría...">
                            </div>

                            <div class="filter-control">
                                <select id="filtroProveedor">
                                    <option value="">Todos los proveedores</option>
                                    <?php foreach (array_keys($proveedores) as $prov): ?>
                                        <option value="<?= e(strtolower($prov)) ?>"><?= e($prov) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="filter-control">
                                <select id="filtroCategoria">
                                    <option value="">Todas las categorías</option>
                                    <?php foreach (array_keys($categorias) as $cat): ?>
                                        <option value="<?= e(strtolower($cat)) ?>"><?= e($cat) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="filter-control">
                                <select id="filtroAdquisicion">
                                    <option value="">Todo tipo adquisición</option>
                                    <option value="pagado">Pagado</option>
                                    <option value="concesion">Concesión</option>
                                </select>
                            </div>
                        </div>

                        <div class="selection-summary">
                            <div>
                                <strong id="contadorAsignados"><?= number_format($totalAsignados) ?></strong>
                                productos asignados
                            </div>

                            <div>
                                <strong id="contadorVisibles"><?= number_format($totalProductos) ?></strong>
                                productos encontrados
                            </div>
                        </div>

                        <div class="productos-grid" id="productosGrid">
                            <?php foreach ($productos as $p): ?>
                                <?php
                                    $productoId = (int)$p['id'];
                                    $checked = isset($asignados[$productoId]);
                                    $asignadoOtro = !empty($p['asignado_otro_id']);
                                    $stock = (float)$p['cantidad'];
                                    $precioVenta = (float)$p['precio_venta'];
                                    // Venta informativa: solo ventas realizadas por el vendedor seleccionado.
                                    $totalVendido = (int)$p['total_vendido'];
                                    $valorInventarioProducto = $stock * $precioVenta;
                                    $proveedor = $p['proveedor'] ?: 'Sin proveedor';
                                    $categoria = $p['categoria'] ?: 'Sin categoría';
                                    $tipoAdquisicion = $p['tipo_adquisicion'] ?: 'concesion';
                                ?>

                                <div
                                    class="producto-item <?= $asignadoOtro ? 'item-disabled' : '' ?>"
                                    data-search="<?= e(strtolower($productoId.' '.$p['nombre'].' '.$proveedor.' '.$categoria.' '.$tipoAdquisicion.' '.$p['asignado_otro_nombre'])) ?>"
                                    data-proveedor="<?= e(strtolower($proveedor)) ?>"
                                    data-categoria="<?= e(strtolower($categoria)) ?>"
                                    data-adquisicion="<?= e(strtolower($tipoAdquisicion)) ?>"
                                    data-valor="<?= e($valorInventarioProducto) ?>"
                                    data-vendido="<?= e($totalVendido) ?>"
                                >
                                    <label class="producto-check-card <?= $checked ? 'is-selected' : '' ?> <?= $asignadoOtro ? 'is-disabled' : '' ?>">
                                        <input 
                                            type="checkbox" 
                                            name="productos[]" 
                                            value="<?= $productoId ?>" 
                                            <?= $checked ? 'checked' : '' ?>
                                            <?= $asignadoOtro ? 'disabled' : '' ?>
                                        >

                                        <span class="check-badge">
                                            <i class="<?= $asignadoOtro ? 'fas fa-lock' : 'fas fa-check' ?>"></i>
                                        </span>

                                        <div class="producto-top">
                                            <div class="producto-icon">
                                                <i class="fas fa-box"></i>
                                            </div>

                                            <span class="badge-tipo <?= $tipoAdquisicion === 'pagado' ? 'tipo-pagado' : 'tipo-concesion' ?>">
                                                <?= e(ucfirst($tipoAdquisicion)) ?>
                                            </span>
                                        </div>

                                        <?php if ($asignadoOtro): ?>
                                            <span class="badge-asignado">
                                                <i class="fas fa-lock"></i>
                                                <?= e($p['asignado_otro_nombre']) ?>
                                            </span>
                                        <?php endif; ?>

                                        <h4><?= e($p['nombre']) ?></h4>

                                        <div class="producto-meta">
                                            <span title="<?= e($proveedor) ?>">
                                                <i class="fas fa-truck"></i>
                                                <?= e($proveedor) ?>
                                            </span>

                                            <span title="<?= e($categoria) ?>">
                                                <i class="fas fa-layer-group"></i>
                                                <?= e($categoria) ?>
                                            </span>
                                        </div>

                                        <div class="producto-stats-grid">
                                            <div>
                                                <small>Stock</small>
                                                <strong><?= number_format($stock) ?></strong>
                                            </div>

                                            <div>
                                                <small>Venta</small>
                                                <strong>$<?= number_format($precioVenta, 2) ?></strong>
                                            </div>

                                            <div>
                                                <small>Vendidos</small>
                                                <strong><?= number_format($totalVendido) ?></strong>
                                            </div>
                                        </div>
                                    </label>
                                </div>
                            <?php endforeach; ?>
                        </div>

                        <div class="pagination-shell">
                            <div class="page-size-control">
                                <label>Mostrar</label>
                                <select id="productosPorPagina">
                                    <option value="8">8</option>
                                    <option value="12" selected>12</option>
                                    <option value="16">16</option>
                                    <option value="24">24</option>
                                </select>
                            </div>

                            <div class="pagination-info" id="paginationInfo">
                                Mostrando 0 a 0 de 0 productos
                            </div>

                            <div class="pagination-buttons">
                                <button type="button" class="btn-page" id="prevPage">
                                    <i class="fas fa-chevron-left"></i>
                                </button>
                                <div id="pageNumbers" class="page-numbers"></div>
                                <button type="button" class="btn-page" id="nextPage">
                                    <i class="fas fa-chevron-right"></i>
                                </button>
                            </div>
                        </div>

                        <div class="sticky-actions">
                            <div>
                                <strong id="footerAsignados"><?= number_format($totalAsignados) ?></strong>
                                productos seleccionados para <?= e($nombreVendedorSeleccionado) ?>
                            </div>

                            <button type="submit" class="btn btn-primary btn-lg">
                                <i class="fas fa-save mr-1"></i>
                                Guardar asignaciones
                            </button>
                        </div>
                    </div>
                </form>

            <?php else: ?>

                <div class="empty-state">
                    <i class="fas fa-user-tag"></i>
                    <h3>Selecciona un vendedor</h3>
                    <p>Elige un vendedor para ver y asignar los productos que podrá gestionar.</p>
                </div>

            <?php endif; ?>

        </div>
    </section>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {

    const vendedorSelect = document.getElementById('vendedorSelect');
    if (vendedorSelect) {
        vendedorSelect.addEventListener('change', function () {
            const vendedorId = this.value;

            if (vendedorId) {
                window.location.href = 'asignar_productos_vendedor.php?vendedor_id=' + encodeURIComponent(vendedorId);
            } else {
                window.location.href = 'asignar_productos_vendedor.php';
            }
        });
    }


    const buscar = document.getElementById('buscarProducto');
    const filtroProveedor = document.getElementById('filtroProveedor');
    const filtroCategoria = document.getElementById('filtroCategoria');
    const filtroAdquisicion = document.getElementById('filtroAdquisicion');

    const items = Array.from(document.querySelectorAll('.producto-item'));
    const contadorAsignados = document.getElementById('contadorAsignados');
    const footerAsignados = document.getElementById('footerAsignados');
    const contadorVisibles = document.getElementById('contadorVisibles');
    const metricAsignados = document.getElementById('metricAsignados');
    const metricProveedores = document.getElementById('metricProveedores');
    const metricVentas = document.getElementById('metricVentas');
    const metricValor = document.getElementById('metricValor');

    const productosPorPagina = document.getElementById('productosPorPagina');
    const prevPage = document.getElementById('prevPage');
    const nextPage = document.getElementById('nextPage');
    const pageNumbers = document.getElementById('pageNumbers');
    const paginationInfo = document.getElementById('paginationInfo');

    let paginaActual = 1;
    let filtrados = [...items];

    function money(value) {
        return new Intl.NumberFormat('es-MX', {
            style: 'currency',
            currency: 'MXN'
        }).format(value || 0);
    }

    function getPorPagina() {
        return parseInt(productosPorPagina?.value || '12', 10);
    }

    function actualizarTarjetas() {
        let totalSeleccionados = 0;
        let valor = 0;
        let ventas = 0;
        let proveedores = new Set();

        document.querySelectorAll('.producto-check-card').forEach(card => {
            const input = card.querySelector('input[type="checkbox"]');
            const item = card.closest('.producto-item');

            card.classList.toggle('is-selected', input.checked);

            if (input.checked && !input.disabled) {
                totalSeleccionados++;
                valor += parseFloat(item.dataset.valor || 0);
                ventas += parseInt(item.dataset.vendido || 0, 10);
                proveedores.add(item.dataset.proveedor || '');
            }
        });

        if (contadorAsignados) contadorAsignados.textContent = totalSeleccionados;
        if (footerAsignados) footerAsignados.textContent = totalSeleccionados;
        if (metricAsignados) metricAsignados.textContent = totalSeleccionados;
        if (metricProveedores) metricProveedores.textContent = proveedores.size;
        if (metricVentas) metricVentas.textContent = ventas.toLocaleString('es-MX');
        if (metricValor) metricValor.textContent = money(valor);
    }

    function renderPaginacion() {
        const porPagina = getPorPagina();
        const total = filtrados.length;
        const totalPaginas = Math.max(1, Math.ceil(total / porPagina));

        if (paginaActual > totalPaginas) paginaActual = totalPaginas;
        if (paginaActual < 1) paginaActual = 1;

        const inicio = (paginaActual - 1) * porPagina;
        const fin = inicio + porPagina;

        items.forEach(item => item.style.display = 'none');
        filtrados.slice(inicio, fin).forEach(item => item.style.display = '');

        if (contadorVisibles) contadorVisibles.textContent = total;

        const desde = total === 0 ? 0 : inicio + 1;
        const hasta = Math.min(fin, total);

        if (paginationInfo) {
            paginationInfo.textContent = 'Mostrando ' + desde + ' a ' + hasta + ' de ' + total + ' productos';
        }

        if (prevPage) prevPage.disabled = paginaActual <= 1;
        if (nextPage) nextPage.disabled = paginaActual >= totalPaginas;

        if (pageNumbers) {
            pageNumbers.innerHTML = '';

            let start = Math.max(1, paginaActual - 2);
            let end = Math.min(totalPaginas, paginaActual + 2);

            if (paginaActual <= 2) end = Math.min(totalPaginas, 5);
            if (paginaActual >= totalPaginas - 1) start = Math.max(1, totalPaginas - 4);

            for (let i = start; i <= end; i++) {
                const btn = document.createElement('button');
                btn.type = 'button';
                btn.className = 'btn-page-number' + (i === paginaActual ? ' active' : '');
                btn.textContent = i;
                btn.addEventListener('click', function () {
                    paginaActual = i;
                    renderPaginacion();
                    scrollToPanel();
                });
                pageNumbers.appendChild(btn);
            }
        }
    }

    function aplicarFiltros(resetPage = true) {
        const q = (buscar?.value || '').trim().toLowerCase();
        const prov = (filtroProveedor?.value || '').trim().toLowerCase();
        const cat = (filtroCategoria?.value || '').trim().toLowerCase();
        const tipo = (filtroAdquisicion?.value || '').trim().toLowerCase();

        filtrados = items.filter(item => {
            const matchSearch = !q || item.dataset.search.includes(q);
            const matchProv = !prov || item.dataset.proveedor === prov;
            const matchCat = !cat || item.dataset.categoria === cat;
            const matchTipo = !tipo || item.dataset.adquisicion === tipo;

            return matchSearch && matchProv && matchCat && matchTipo;
        });

        if (resetPage) paginaActual = 1;
        renderPaginacion();
    }

    function scrollToPanel() {
        const panel = document.querySelector('.productos-panel');
        if (!panel) return;

        const y = panel.getBoundingClientRect().top + window.pageYOffset - 90;
        window.scrollTo({ top: y, behavior: 'smooth' });
    }

    document.querySelectorAll('input[name="productos[]"]').forEach(input => {
        input.addEventListener('change', actualizarTarjetas);
    });

    [buscar, filtroProveedor, filtroCategoria, filtroAdquisicion].forEach(el => {
        if (el) el.addEventListener('input', () => aplicarFiltros(true));
        if (el) el.addEventListener('change', () => aplicarFiltros(true));
    });

    if (productosPorPagina) {
        productosPorPagina.addEventListener('change', function () {
            paginaActual = 1;
            renderPaginacion();
        });
    }

    if (prevPage) {
        prevPage.addEventListener('click', function () {
            if (paginaActual > 1) {
                paginaActual--;
                renderPaginacion();
                scrollToPanel();
            }
        });
    }

    if (nextPage) {
        nextPage.addEventListener('click', function () {
            const totalPaginas = Math.max(1, Math.ceil(filtrados.length / getPorPagina()));
            if (paginaActual < totalPaginas) {
                paginaActual++;
                renderPaginacion();
                scrollToPanel();
            }
        });
    }

    const seleccionarTodos = document.getElementById('seleccionarTodos');
    if (seleccionarTodos) {
        seleccionarTodos.addEventListener('click', function () {
            filtrados.forEach(item => {
                const input = item.querySelector('input[type="checkbox"]');
                if (input && !input.disabled) input.checked = true;
            });

            actualizarTarjetas();

            if (typeof Swal !== 'undefined') {
                Swal.fire({
                    icon: 'success',
                    title: 'Productos seleccionados',
                    text: 'Se seleccionaron todos los productos filtrados disponibles.',
                    timer: 1300,
                    showConfirmButton: false
                });
            }
        });
    }

    const limpiarTodo = document.getElementById('limpiarTodo');
    if (limpiarTodo) {
        limpiarTodo.addEventListener('click', function () {
            if (buscar) buscar.value = '';
            if (filtroProveedor) filtroProveedor.value = '';
            if (filtroCategoria) filtroCategoria.value = '';
            if (filtroAdquisicion) filtroAdquisicion.value = '';

            document.querySelectorAll('input[name="productos[]"]').forEach(i => {
                if (!i.disabled) i.checked = false;
            });

            paginaActual = 1;
            aplicarFiltros(true);
            actualizarTarjetas();
        });
    }

    const form = document.getElementById('formAsignaciones');
    if (form) {
        form.addEventListener('submit', function (e) {
            if (typeof Swal === 'undefined') return;

            e.preventDefault();

            const total = document.querySelectorAll('input[name="productos[]"]:checked:not(:disabled)').length;

            Swal.fire({
                icon: 'question',
                title: 'Guardar asignaciones',
                html: 'Se asignarán <strong>' + total + '</strong> productos al vendedor seleccionado.',
                showCancelButton: true,
                confirmButtonText: 'Guardar',
                cancelButtonText: 'Cancelar',
                confirmButtonColor: '#f97316'
            }).then((result) => {
                if (result.isConfirmed) {
                    form.submit();
                }
            });
        });
    }

    aplicarFiltros(true);
    actualizarTarjetas();
});
</script>