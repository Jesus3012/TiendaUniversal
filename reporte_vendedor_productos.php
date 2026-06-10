<?php
error_reporting(E_ALL & ~E_DEPRECATED);
session_start();
require_once 'includes/db.php';

if (!isset($_SESSION['usuario_id']) || !in_array($_SESSION['rol'] ?? '', ['administrador', 'vendedor'], true)) {
    header('Location: login.php');
    exit;
}

$usuarioId = (int)$_SESSION['usuario_id'];
$rol = $_SESSION['rol'] ?? 'vendedor';
$filtroInicio = $_GET['fecha_inicio'] ?? '';
$filtroFin = $_GET['fecha_fin'] ?? '';
$filtroVendedor = $rol === 'administrador' ? (int)($_GET['vendedor_id'] ?? 0) : $usuarioId;

function e($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

$vendedores = [];
if ($rol === 'administrador') {
    $resVend = $conn->query("SELECT id, nombre, email FROM usuarios WHERE rol = 'vendedor' AND activo = 1 ORDER BY nombre ASC");
    while ($row = $resVend->fetch_assoc()) $vendedores[] = $row;
}

$where = "p.activo = 1 AND p.tipo_inventario = 'producto'";
$params = [];
$types = '';

if ($filtroVendedor > 0) {
    $where .= " AND vp.vendedor_id = ?";
    $params[] = $filtroVendedor;
    $types .= 'i';
}

$fechaVentaJoin = '';
if ($filtroInicio !== '' && $filtroFin !== '') {
    $fechaVentaJoin = " AND DATE(v.fecha_venta) BETWEEN ? AND ?";
    $params[] = $filtroInicio;
    $params[] = $filtroFin;
    $types .= 'ss';
}

$sql = "\nSELECT\n    p.id,\n    p.nombre AS producto,\n    p.proveedor,\n    p.cantidad AS stock_actual,\n    p.precio_compra,\n    p.precio_venta,\n    p.tipo_adquisicion,\n    u.nombre AS vendedor,\n    IFNULL(SUM(v.cantidad_vendida), 0) AS vendidos,\n    COUNT(v.id) AS numero_ventas,\n    CASE WHEN p.tipo_adquisicion = 'pagado' THEN 0 ELSE p.precio_compra * IFNULL(SUM(v.cantidad_vendida), 0) END AS deuda_total,\n    p.precio_venta * IFNULL(SUM(v.cantidad_vendida), 0) AS venta_total,\n    (p.precio_venta - p.precio_compra) * IFNULL(SUM(v.cantidad_vendida), 0) AS ganancia_total\nFROM vendedor_productos vp\nINNER JOIN productos p ON p.id = vp.producto_id\nINNER JOIN usuarios u ON u.id = vp.vendedor_id\nLEFT JOIN ventas v ON v.id_producto = p.id $fechaVentaJoin\nWHERE vp.activo = 1 AND $where\nGROUP BY p.id, p.nombre, p.proveedor, p.cantidad, p.precio_compra, p.precio_venta, p.tipo_adquisicion, u.nombre\nORDER BY u.nombre ASC, p.proveedor ASC, p.nombre ASC\n";

$stmt = $conn->prepare($sql);
if (!empty($params)) $stmt->bind_param($types, ...$params);
$stmt->execute();
$res = $stmt->get_result();
$rows = [];
$totales = ['vendidos' => 0, 'ventas' => 0, 'deuda' => 0, 'ganancia' => 0, 'stock' => 0];
while ($row = $res->fetch_assoc()) {
    $rows[] = $row;
    $totales['vendidos'] += (float)$row['vendidos'];
    $totales['ventas'] += (float)$row['venta_total'];
    $totales['deuda'] += (float)$row['deuda_total'];
    $totales['ganancia'] += (float)$row['ganancia_total'];
    $totales['stock'] += (float)$row['stock_actual'];
}

include 'includes/header.php';
include 'includes/navbar.php';
?>
<link rel="stylesheet" href="css/modulo_vendedores.css?v=<?= time() ?>">
<div class="content-wrapper vendedor-module">
    <section class="content-header">
        <div class="container-fluid">
            <div class="row align-items-center">
                <div class="col-md-8">
                    <h1 class="mb-0"><i class="fas fa-chart-line mr-2"></i>Reporte de productos asignados</h1>
                    <small class="text-muted">Ventas, stock, ganancia y deuda calculadas por productos autorizados.</small>
                </div>
                <div class="col-md-4 text-md-right mt-2 mt-md-0">
                    <button class="btn btn-danger btn-sm" onclick="window.print()"><i class="fas fa-file-pdf mr-1"></i> Imprimir / PDF</button>
                </div>
            </div>
        </div>
    </section>

    <section class="content">
        <div class="container-fluid">
            <div class="card card-modern shadow-sm mb-4 no-print">
                <div class="card-body">
                    <form method="GET" class="row align-items-end">
                        <?php if ($rol === 'administrador'): ?>
                        <div class="col-md-4 mb-2">
                            <label>Vendedor</label>
                            <select name="vendedor_id" class="form-control">
                                <option value="0">Todos los vendedores</option>
                                <?php foreach ($vendedores as $v): ?>
                                    <option value="<?= (int)$v['id'] ?>" <?= $filtroVendedor === (int)$v['id'] ? 'selected' : '' ?>><?= e($v['nombre']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <?php endif; ?>
                        <div class="col-md-3 mb-2">
                            <label>Fecha inicio</label>
                            <input type="date" name="fecha_inicio" value="<?= e($filtroInicio) ?>" class="form-control">
                        </div>
                        <div class="col-md-3 mb-2">
                            <label>Fecha fin</label>
                            <input type="date" name="fecha_fin" value="<?= e($filtroFin) ?>" class="form-control">
                        </div>
                        <div class="col-md-2 mb-2">
                            <button class="btn btn-primary btn-block"><i class="fas fa-filter mr-1"></i>Filtrar</button>
                        </div>
                    </form>
                </div>
            </div>

            <div class="row">
                <div class="col-md-3 mb-3"><div class="kpi-card bg-blue"><span>Vendidos</span><strong><?= number_format($totales['vendidos']) ?></strong><i class="fas fa-shopping-cart"></i></div></div>
                <div class="col-md-3 mb-3"><div class="kpi-card bg-red"><span>Deuda</span><strong>$<?= number_format($totales['deuda'], 2) ?></strong><i class="fas fa-hand-holding-usd"></i></div></div>
                <div class="col-md-3 mb-3"><div class="kpi-card bg-green"><span>Ganancia</span><strong>$<?= number_format($totales['ganancia'], 2) ?></strong><i class="fas fa-chart-line"></i></div></div>
                <div class="col-md-3 mb-3"><div class="kpi-card bg-orange"><span>Stock</span><strong><?= number_format($totales['stock']) ?></strong><i class="fas fa-warehouse"></i></div></div>
            </div>

            <div class="card card-outline card-primary shadow-sm">
                <div class="card-header"><h3 class="card-title font-weight-bold"><i class="fas fa-table mr-2"></i>Detalle</h3></div>
                <div class="card-body table-responsive p-0">
                    <table class="table table-hover table-sm mb-0 reporte-table">
                        <thead class="thead-dark">
                            <tr>
                                <th>Vendedor</th>
                                <th>Producto</th>
                                <th>Proveedor</th>
                                <th>Vendidos</th>
                                <th>Stock</th>
                                <th>Compra</th>
                                <th>Venta</th>
                                <th>Venta total</th>
                                <th>Ganancia</th>
                                <th>Deuda</th>
                                <th>Adquisición</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($rows)): ?>
                                <tr><td colspan="11" class="text-center py-5 text-muted">No hay información para mostrar.</td></tr>
                            <?php endif; ?>
                            <?php foreach ($rows as $r): ?>
                                <tr class="<?= $r['tipo_adquisicion'] === 'pagado' ? 'table-success' : '' ?>">
                                    <td><?= e($r['vendedor']) ?></td>
                                    <td><?= e($r['producto']) ?></td>
                                    <td><?= e($r['proveedor']) ?></td>
                                    <td><?= number_format((float)$r['vendidos']) ?></td>
                                    <td><?= number_format((float)$r['stock_actual']) ?></td>
                                    <td>$<?= number_format((float)$r['precio_compra'], 2) ?></td>
                                    <td>$<?= number_format((float)$r['precio_venta'], 2) ?></td>
                                    <td>$<?= number_format((float)$r['venta_total'], 2) ?></td>
                                    <td class="text-success font-weight-bold">$<?= number_format((float)$r['ganancia_total'], 2) ?></td>
                                    <td class="font-weight-bold <?= $r['tipo_adquisicion'] === 'pagado' ? 'text-success' : 'text-danger' ?>">
                                        <?= $r['tipo_adquisicion'] === 'pagado' ? 'PAGADO' : '$'.number_format((float)$r['deuda_total'], 2) ?>
                                    </td>
                                    <td><?= e(ucfirst($r['tipo_adquisicion'])) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </section>
</div>
<?php include 'includes/footer.php'; ?>