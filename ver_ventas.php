<?php
include('includes/header.php');
include('includes/navbar.php');
include 'includes/db.php';

// --- Filtros (proveedor y fechas) ---
$filtroProveedor = $_GET['proveedor'] ?? '';
$filtroInicio = $_GET['fecha_inicio'] ?? '';
$filtroFin = $_GET['fecha_fin'] ?? '';

// --- CONSULTA PARA PRODUCTOS CON VENTAS (para la vista) ---
$sql = "
SELECT 
    p.id,
    p.nombre,
    p.proveedor,
    p.precio_compra,
    p.precio_venta,
    p.cantidad,
    IFNULL((
        SELECT SUM(v.cantidad_vendida)
        FROM ventas v
        WHERE v.id_producto = p.id
";

if ($filtroInicio !== '' && $filtroFin !== '') {
    $sql .= " AND DATE(v.fecha_venta) BETWEEN '" . $conn->real_escape_string($filtroInicio) . "' 
    AND '" . $conn->real_escape_string($filtroFin) . "'";
}

$sql .= "
    ), 0) AS total_vendida
FROM productos p
WHERE 1
";

if ($filtroProveedor !== '') {
    $sql .= " AND p.proveedor = '" . $conn->real_escape_string($filtroProveedor) . "'";
}

$sql .= " HAVING total_vendida > 0 ORDER BY p.nombre ASC";

$resultado = $conn->query($sql);

// --- Variables para la vista ---
$totalGanancia = $totalProveedor = $totalVendidos = $totalStock = 0;
$productos = [];

if ($resultado) {
    while ($row = $resultado->fetch_assoc()) {
        $vendidos = (int)$row['total_vendida'];
        $stock = (int)$row['cantidad'];

        $ganancia = ($row['precio_venta'] - $row['precio_compra']) * $vendidos;
        $costoProveedor = $row['precio_compra'] * $vendidos;

        $totalGanancia += $ganancia;
        $totalProveedor += $costoProveedor;
        $totalVendidos += $vendidos;
        $totalStock += $stock;

        $productos[] = [
            'nombre' => $row['nombre'],
            'proveedor' => $row['proveedor'],
            'vendidos' => $vendidos,
            'stock' => $stock,
            'precio_compra' => $row['precio_compra'],
            'precio_venta' => $row['precio_venta'],
            'ganancia' => $ganancia
        ];
    }
}

// Proveedores para filtro
$listaProveedores = $conn->query("SELECT DISTINCT proveedor FROM productos WHERE proveedor != '' ORDER BY proveedor");
?>

<style>
/* Mantener el estilo original de AdminLTE */
.table td, .table th {
    white-space: nowrap;
    padding: 12px 16px !important;
    font-size: 14px;
}

.table-responsive {
    overflow-x: auto;
}

.content-wrapper {
    min-height: 100vh;
    padding-bottom: 40px;
}

table {
    width: 100% !important;
    white-space: normal !important;
}

/* Pequeñas mejoras visuales */
.small-box {
    border-radius: 8px;
}

.small-box .inner {
    padding: 15px;
}

.small-box h3 {
    font-size: 2rem;
    font-weight: bold;
}

.small-box p {
    font-size: 0.9rem;
}

.small-box-footer {
    background: rgba(0,0,0,0.1);
    padding: 8px;
    font-size: 0.85rem;
    color: white;
}

.badge {
    font-size: 12px;
    padding: 5px 8px;
}

/* Estilo para el botón de reinicio */
.btn-reset {
    background-color: #6c757d;
    border-color: #6c757d;
    color: white;
}

.btn-reset:hover {
    background-color: #5a6268;
    border-color: #545b62;
    color: white;
}

/* Mini gráfico de porcentaje en cards */
.mini-progress {
    height: 4px;
    background: rgba(255,255,255,0.3);
    border-radius: 2px;
    margin-top: 8px;
}

.mini-progress-bar {
    height: 4px;
    border-radius: 2px;
    background: white;
}

/* Resumen financiero */
.financial-summary {
    background: #f8f9fa;
    border-radius: 8px;
    padding: 15px;
    margin-top: 15px;
}

.financial-summary table {
    margin-bottom: 0;
}

.financial-summary td {
    padding: 8px 10px;
    border: none;
}

.financial-summary .label {
    font-weight: 600;
    color: #495057;
}

.financial-summary .value {
    font-weight: bold;
    text-align: right;
}
</style>

<div class="content-wrapper">

    <!-- HEADER -->
    <section class="content-header">
        <div class="container-fluid">
            <div class="row align-items-center">

                <!-- TITULO -->
                <div class="col-12 col-md-6 mb-2 mb-md-0">
                    <h1 class="mb-0 font-weight-bold">
                        <i class="fas fa-chart-line text-primary mr-2"></i>
                        Reporte de Ventas
                    </h1>
                    <small class="text-muted">
                        Resumen financiero y desempeño comercial
                    </small>
                </div>

                <!-- BOTONES -->
                <div class="col-12 col-md-6 text-md-right text-left">
                    <!-- Botón Reiniciar Filtros -->
                    <a href="?" class="btn btn-reset btn-sm shadow-sm mr-2">
                        <i class="fas fa-sync-alt mr-1"></i> Reiniciar filtros
                    </a>
                    
                    <!-- Botón PDF -->
                    <button id="btnPDF" class="btn btn-danger btn-sm shadow-sm">
                        <i class="fas fa-file-pdf mr-1"></i> Exportar PDF
                    </button>
                </div>

            </div>
        </div>
    </section>

    <!-- MAIN -->
    <section class="content">
        <div class="container-fluid">

            <!-- FILTROS -->
            <div class="card card-outline card-primary shadow-sm mb-4">
                <div class="card-header">
                    <h3 class="card-title font-weight-bold">
                        <i class="fas fa-filter mr-2"></i>Filtros de búsqueda
                    </h3>
                </div>

                <div class="card-body">
                    <form method="GET" class="row">

                        <div class="col-12 col-md-4 mb-2">
                            <label class="text-muted">Proveedor</label>
                            <select name="proveedor" class="form-control">
                                <option value="">Todos los proveedores</option>
                                <?php while ($p = $listaProveedores->fetch_assoc()): ?>
                                    <option value="<?= htmlspecialchars($p['proveedor']) ?>" <?= $filtroProveedor == $p['proveedor'] ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($p['proveedor']) ?>
                                    </option>
                                <?php endwhile; ?>
                            </select>
                        </div>

                        <div class="col-6 col-md-3 mb-2">
                            <label class="text-muted">Fecha inicio</label>
                            <input type="date" name="fecha_inicio" value="<?= htmlspecialchars($filtroInicio) ?>" class="form-control">
                        </div>

                        <div class="col-6 col-md-3 mb-2">
                            <label class="text-muted">Fecha fin</label>
                            <input type="date" name="fecha_fin" value="<?= htmlspecialchars($filtroFin) ?>" class="form-control">
                        </div>

                        <div class="col-12 col-md-2">
                            <button class="btn btn-success btn-block">
                                <i class="fas fa-search mr-1"></i> Aplicar
                            </button>
                        </div>

                    </form>
                </div>
            </div>

            <!-- KPIs con información adicional -->
            <div class="row">

                <div class="col-12 col-md-6 col-lg-3 mb-3">
                    <div class="small-box bg-info">
                        <div class="inner">
                            <h3><?= number_format($totalVendidos) ?></h3>
                            <p class="mb-0">Productos vendidos</p>
                            <small><?= count($productos) ?> productos diferentes</small>
                            <div class="mini-progress">
                                <div class="mini-progress-bar" style="width: 100%"></div>
                            </div>
                        </div>
                        <div class="icon"><i class="fas fa-box"></i></div>
                        <div class="small-box-footer">
                            <i class="fas fa-chart-bar mr-1"></i> Total del período
                        </div>
                    </div>
                </div>

                <div class="col-12 col-md-6 col-lg-3 mb-3">
                    <div class="small-box bg-danger">
                        <div class="inner">
                            <h3>$<?= number_format($totalProveedor, 2) ?></h3>
                            <p class="mb-0">Deuda con proveedores</p>
                            <small>Costo de productos vendidos</small>
                            <div class="mini-progress">
                                <?php $porcentajeCosto = ($totalGanancia + $totalProveedor) > 0 ? ($totalProveedor / ($totalGanancia + $totalProveedor)) * 100 : 0; ?>
                                <div class="mini-progress-bar" style="width: <?= $porcentajeCosto ?>%"></div>
                            </div>
                        </div>
                        <div class="icon"><i class="fas fa-hand-holding-usd"></i></div>
                        <div class="small-box-footer">
                            <i class="fas fa-percentage mr-1"></i> <?= number_format($porcentajeCosto, 1) ?>% del costo total
                        </div>
                    </div>
                </div>

                <div class="col-12 col-md-6 col-lg-3 mb-3">
                    <div class="small-box bg-success">
                        <div class="inner">
                            <h3>$<?= number_format($totalGanancia, 2) ?></h3>
                            <p class="mb-0">Ganancia neta</p>
                            <small>Margen: <?= $totalProveedor > 0 ? number_format(($totalGanancia / $totalProveedor) * 100, 1) : 0 ?>%</small>
                            <div class="mini-progress">
                                <?php $porcentajeGanancia = ($totalGanancia + $totalProveedor) > 0 ? ($totalGanancia / ($totalGanancia + $totalProveedor)) * 100 : 0; ?>
                                <div class="mini-progress-bar" style="width: <?= $porcentajeGanancia ?>%"></div>
                            </div>
                        </div>
                        <div class="icon"><i class="fas fa-chart-line"></i></div>
                        <div class="small-box-footer">
                            <i class="fas fa-arrow-up mr-1"></i> Rentabilidad
                        </div>
                    </div>
                </div>

                <div class="col-12 col-md-6 col-lg-3 mb-3">
                    <div class="small-box bg-warning">
                        <div class="inner">
                            <h3><?= number_format($totalStock) ?></h3>
                            <p class="mb-0">Stock restante</p>
                            <small>Valor: $<?= number_format($totalStock * 100, 2) ?></small>
                            <div class="mini-progress">
                                <?php $porcentajeStock = $totalVendidos > 0 ? min(100, ($totalStock / $totalVendidos) * 100) : 0; ?>
                                <div class="mini-progress-bar" style="width: <?= $porcentajeStock ?>%"></div>
                            </div>
                        </div>
                        <div class="icon"><i class="fas fa-warehouse"></i></div>
                        <div class="small-box-footer">
                            <i class="fas fa-boxes mr-1"></i> Inventario actual
                        </div>
                    </div>
                </div>

            </div>
            
            <!-- Mensaje si no hay ventas -->
            <?php if (empty($productos)): ?>
            <div class="alert alert-info text-center mt-3">
                <i class="fas fa-info-circle mr-2"></i>
                No hay ventas registradas en el período seleccionado.
                <?php if ($filtroProveedor !== '' || ($filtroInicio !== '' && $filtroFin !== '')): ?>
                    <br><small>Intenta con otros filtros de búsqueda o <a href="?">reinicia los filtros</a>.</small>
                <?php endif; ?>
            </div>
            <?php endif; ?>
            
            <!-- TABLA PRODUCTOS -->
            <div class="card card-outline card-warning shadow-sm mt-4">
                <div class="card-header">
                    <h3 class="card-title font-weight-bold">
                        <i class="fas fa-boxes mr-2"></i>Productos y Ventas
                    </h3>
                    <div class="card-tools">
                        <span class="badge badge-warning p-2">
                            Total ganancias: $<?= number_format(array_sum(array_column($productos, 'ganancia')), 2) ?>
                        </span>
                    </div>
                </div>
                <div class="card-body table-responsive p-0">
                    <table class="table table-hover table-sm mb-0 text-nowrap">
                        <thead class="thead-dark">
                            <tr>
                                <th>Producto</th>
                                <th>Proveedor</th>
                                <th class="text-center">Vendidos</th>
                                <th class="text-center">Stock</th>
                                <th class="text-right">Compra</th>
                                <th class="text-right">Venta</th>
                                <th class="text-right">Ganancia</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($productos as $p): ?>
                            <tr>
                                <td><?= $p['nombre'] ?></td>
                                <td><?= $p['proveedor'] ?></td>
                                <td class="text-center"><?= $p['vendidos'] ?></td>
                                <td class="text-center">
                                    <span class="badge <?= $p['stock'] <= 0 ? 'badge-danger' : ($p['stock'] <= 5 ? 'badge-warning' : 'badge-success') ?>">
                                        <?= $p['stock'] ?>
                                    </span>
                                </td>
                                <td class="text-right">$<?= number_format($p['precio_compra'], 2) ?></td>
                                <td class="text-right">$<?= number_format($p['precio_venta'], 2) ?></td>
                                <td class="text-right font-weight-bold text-success">
                                    $<?= number_format($p['ganancia'], 2) ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- DEUDA CON PROVEEDORES -->
            <div class="card card-outline card-danger shadow-sm mt-4">
                <div class="card-header d-flex flex-column flex-md-row align-items-md-center">
                    <h3 class="card-title font-weight-bold mb-2 mb-md-0">
                        <i class="fas fa-hand-holding-usd mr-2"></i>
                        Deuda con Proveedores
                    </h3>

                    <span class="badge badge-danger p-2 ml-md-auto">
                        Total adeudo: $<?= number_format($totalProveedor, 2) ?>
                    </span>
                </div>

                <div class="card-body table-responsive p-0">
                    <table class="table table-hover table-sm mb-0 text-nowrap">
                        <thead class="thead-dark">
                            <tr>
                                <th>Producto</th>
                                <th>Proveedor</th>
                                <th class="text-center">Vendidos</th>
                                <th class="text-right">Costo unitario</th>
                                <th class="text-right">Deuda total</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($productos as $p): ?>
                            <?php $deuda = $p['precio_compra'] * $p['vendidos']; ?>
                            <tr>
                                <td><?= $p['nombre'] ?></td>
                                <td><?= $p['proveedor'] ?></td>
                                <td class="text-center"><?= $p['vendidos'] ?></td>
                                <td class="text-right">$<?= number_format($p['precio_compra'], 2) ?></td>
                                <td class="text-right font-weight-bold text-danger">
                                    $<?= number_format($deuda, 2) ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <div class="card-footer text-right">
                    <small class="text-muted">
                        Este monto representa el total pendiente de pago a proveedores.
                    </small>
                </div>
            </div>

            <!-- GRAFICA CON MÁS INFORMACIÓN -->
            <?php if (!empty($productos) && ($totalGanancia > 0 || $totalProveedor > 0)): ?>
            <div class="card card-outline card-info shadow-sm mt-4">
                <div class="card-header">
                    <h3 class="card-title font-weight-bold">
                        <i class="fas fa-chart-pie mr-2"></i>Distribución financiera
                    </h3>
                </div>

                <div class="card-body">
                    <div class="row">
                        <div class="col-md-7">
                            <div style="position: relative; height: 300px;">
                                <canvas id="graficaVentas"></canvas>
                            </div>
                        </div>
                        <div class="col-md-5">
                            <div class="financial-summary">
                                <h5 class="mb-3"><i class="fas fa-calculator mr-2"></i>Resumen Financiero</h5>
                                <table class="table table-sm">
                                    <tr>
                                        <td class="label">Ingresos totales:</td>
                                        <td class="value text-primary">$<?= number_format($totalGanancia + $totalProveedor, 2) ?></td>
                                    </tr>
                                    <tr>
                                        <td class="label">Costo de ventas:</td>
                                        <td class="value text-danger">$<?= number_format($totalProveedor, 2) ?></td>
                                    </tr>
                                    <tr>
                                        <td class="label">Ganancia neta:</td>
                                        <td class="value text-success">$<?= number_format($totalGanancia, 2) ?></td>
                                    </tr>
                                    <tr>
                                        <td class="label">Margen de ganancia:</td>
                                        <td class="value text-success">
                                            <strong><?= $totalProveedor > 0 ? number_format(($totalGanancia / $totalProveedor) * 100, 1) : 0 ?>%</strong>
                                        </td>
                                    </tr>
                                    <tr>
                                        <td class="label">Relación costo/ingreso:</td>
                                        <td class="value text-info">
                                            <?= ($totalGanancia + $totalProveedor) > 0 ? number_format(($totalProveedor / ($totalGanancia + $totalProveedor)) * 100, 1) : 0 ?>%
                                        </td>
                                    </tr>
                                </table>
                                
                                <div class="mt-3 p-2 bg-light rounded">
                                    <small class="text-muted">
                                        <i class="fas fa-info-circle mr-1"></i>
                                        La ganancia representa el <?= ($totalGanancia + $totalProveedor) > 0 ? number_format(($totalGanancia / ($totalGanancia + $totalProveedor)) * 100, 1) : 0 ?>% 
                                        de los ingresos totales.
                                    </small>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <?php endif; ?>

        </div>
    </section>
</div>

<!-- SCRIPTS -->
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf-autotable/3.5.28/jspdf.plugin.autotable.min.js"></script>

<script>
// Gráfica
<?php if (!empty($productos) && ($totalGanancia > 0 || $totalProveedor > 0)): ?>
const ctx = document.getElementById('graficaVentas');

new Chart(ctx, {
    type: 'pie',
    data: {
        labels: [
            'Ganancia neta ($<?= number_format($totalGanancia, 2) ?>)',
            'Costo de ventas ($<?= number_format($totalProveedor, 2) ?>)'
        ],
        datasets: [{
            data: [
                <?= $totalGanancia ?>,
                <?= $totalProveedor ?>
            ],
            backgroundColor: [
                '#28a745',
                '#dc3545'
            ],
            borderColor: '#ffffff',
            borderWidth: 2,
            hoverOffset: 8
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
            legend: {
                position: 'bottom',
                labels: {
                    padding: 15,
                    boxWidth: 12,
                    font: { size: 12 }
                }
            },
            tooltip: {
                callbacks: {
                    label: function(context) {
                        const total = context.dataset.data.reduce((a, b) => a + b, 0);
                        const value = context.raw;
                        const percent = ((value / total) * 100).toFixed(1);
                        return `${context.label}: $${value.toLocaleString()} (${percent}%)`;
                    }
                }
            }
        }
    }
});
<?php endif; ?>
// Función para generar PDFs
document.getElementById("btnPDF").addEventListener("click", () => {
    const { jsPDF } = window.jspdf;
    
    // ============================================
    // CONSULTAS PARA PDF DE PROVEEDORES
    // ============================================
    <?php
    // Consulta detallada para proveedores con fecha de venta
    $sqlProveedores = "
    SELECT 
        p.nombre AS producto,
        p.proveedor,
        DATE_FORMAT(v.fecha_venta, '%d/%m/%Y') AS fecha_venta_formateada,
        v.cantidad_vendida,
        p.cantidad AS stock_actual,
        p.precio_compra,
        (p.cantidad + v.cantidad_vendida) AS stock_inicial,
        (p.precio_compra * v.cantidad_vendida) AS total_deuda
    FROM ventas v
    INNER JOIN productos p ON v.id_producto = p.id
    WHERE 1
    ";
    
    if ($filtroProveedor !== '') {
        $sqlProveedores .= " AND p.proveedor = '" . $conn->real_escape_string($filtroProveedor) . "'";
    }
    
    if ($filtroInicio !== '' && $filtroFin !== '') {
        $sqlProveedores .= " AND DATE(v.fecha_venta) BETWEEN '" . $conn->real_escape_string($filtroInicio) . "' 
        AND '" . $conn->real_escape_string($filtroFin) . "'";
    }
    
    $sqlProveedores .= " ORDER BY p.proveedor, v.fecha_venta DESC";
    
    $resultadoProveedores = $conn->query($sqlProveedores);
    $ventasDetalle = [];
    $deudaPorProveedor = [];
    
    if ($resultadoProveedores) {
        while ($row = $resultadoProveedores->fetch_assoc()) {
            $ventasDetalle[] = $row;
            $prov = $row['proveedor'];
            if (!isset($deudaPorProveedor[$prov])) {
                $deudaPorProveedor[$prov] = 0;
            }
            $deudaPorProveedor[$prov] += $row['total_deuda'];
        }
    }
    ?>
    
    // ============================================
    // PDF 1: PARA ADMINISTRADOR (Reporte General)
    // ============================================
    const docAdmin = new jsPDF("p", "mm", "a4");
    const pageWidth = docAdmin.internal.pageSize.getWidth();
    const pageHeight = docAdmin.internal.pageSize.getHeight();
    let y = 20;

    <?php if (empty($productos)): ?>
    docAdmin.setFontSize(16);
    docAdmin.text("REPORTE DE VENTAS", pageWidth / 2, 30, { align: "center" });
    docAdmin.setFontSize(12);
    docAdmin.text("No hay ventas registradas en el período seleccionado.", pageWidth / 2, 50, { align: "center" });
    docAdmin.save("Reporte_Ventas_Admin.pdf");
    return;
    <?php endif; ?>

    // --- ENCABEZADO ADMIN (Sobrio y profesional) ---
    docAdmin.setFontSize(20);
    docAdmin.setTextColor(44, 62, 80);
    docAdmin.setFont("helvetica", "bold");
    docAdmin.text("REPORTE GENERAL DE VENTAS", pageWidth / 2, 18, { align: "center" });
    
    // Línea separadora
    docAdmin.setDrawColor(200, 200, 200);
    docAdmin.setLineWidth(0.5);
    docAdmin.line(20, 24, pageWidth - 20, 24);
    
    docAdmin.setFontSize(10);
    docAdmin.setTextColor(100);
    docAdmin.setFont("helvetica", "normal");
    
    let filtrosTexto = "";
    <?php if ($filtroProveedor !== ''): ?>
    filtrosTexto += "Proveedor: " + "<?= htmlspecialchars($filtroProveedor) ?>";
    <?php endif; ?>
    <?php if ($filtroInicio !== '' && $filtroFin !== ''): ?>
    filtrosTexto += (filtrosTexto ? " | " : "") + "Período: <?= htmlspecialchars($filtroInicio) ?> al <?= htmlspecialchars($filtroFin) ?>";
    <?php endif; ?>
    <?php if ($filtroProveedor === '' && ($filtroInicio === '' || $filtroFin === '')): ?>
    filtrosTexto = "Todos los productos con ventas";
    <?php endif; ?>
    
    docAdmin.text("Filtros aplicados: " + (filtrosTexto || "Ninguno"), 20, 32);
    docAdmin.text(`Fecha de generación: ${new Date().toLocaleString()}`, pageWidth - 20, 32, { align: "right" });
    
    y = 42;

    // --- TABLA DE PRODUCTOS (ADMIN) ---
    docAdmin.setFontSize(14);
    docAdmin.setTextColor(41, 128, 185);
    docAdmin.setFont("helvetica", "bold");
    docAdmin.text("1. DETALLE DE PRODUCTOS VENDIDOS", 20, y);
    y += 6;

    docAdmin.autoTable({
        html: document.querySelector(".card-warning table"),
        startY: y,
        theme: "striped",
        headStyles: { fillColor: [41, 128, 185], textColor: 255, fontStyle: "bold", fontSize: 9 },
        styles: { fontSize: 8, cellPadding: 4 },
        margin: { left: 15, right: 15 },
        didDrawPage: data => y = data.cursor.y + 8
    });

    // --- RESUMEN FINANCIERO (ADMIN) ---
    if (y > 220) { docAdmin.addPage(); y = 25; }
    
    docAdmin.setFontSize(14);
    docAdmin.setTextColor(46, 204, 113);
    docAdmin.setFont("helvetica", "bold");
    docAdmin.text("2. RESUMEN FINANCIERO", 20, y);
    y += 8;

    // Crear tabla de resumen
    const resumenBody = [
        ["Total de productos vendidos", "<?= number_format($totalVendidos) ?>", "unidades"],
        ["Costo total de ventas", "$<?= number_format($totalProveedor, 2) ?>", ""],
        ["Ganancia neta", "$<?= number_format($totalGanancia, 2) ?>", ""],
        ["Stock restante", "<?= number_format($totalStock) ?>", "unidades"],
        ["Productos diferentes", "<?= count($productos) ?>", ""]
    ];
    
    docAdmin.autoTable({
        startY: y,
        body: resumenBody,
        theme: "plain",
        styles: { fontSize: 10, cellPadding: 4 },
        columnStyles: {
            0: { cellWidth: 80, fontStyle: 'bold' },
            1: { cellWidth: 50, halign: 'right' },
            2: { cellWidth: 40 }
        },
        margin: { left: 20, right: 20 },
        didDrawPage: data => y = data.cursor.y + 8
    });
    y += 5;

    // --- TABLA DE COSTOS (ADMIN) ---
    if (y > 220) { docAdmin.addPage(); y = 25; }
    
    docAdmin.setFontSize(14);
    docAdmin.setTextColor(231, 76, 60);
    docAdmin.setFont("helvetica", "bold");
    docAdmin.text("3. DETALLE DE COSTOS POR PRODUCTO", 20, y);
    y += 6;

    docAdmin.autoTable({
        html: document.querySelector(".card-danger table"),
        startY: y,
        theme: "striped",
        headStyles: { fillColor: [231, 76, 60], textColor: 255, fontStyle: "bold", fontSize: 9 },
        styles: { fontSize: 8, cellPadding: 4 },
        margin: { left: 15, right: 15 },
        didDrawPage: data => y = data.cursor.y + 8
    });

    // --- GRÁFICA (ADMIN) ---
    <?php if ($totalGanancia > 0 || $totalProveedor > 0): ?>
    if (y > 200) { docAdmin.addPage(); y = 25; }
    
    docAdmin.setFontSize(14);
    docAdmin.setTextColor(52, 152, 219);
    docAdmin.setFont("helvetica", "bold");
    docAdmin.text("4. DISTRIBUCIÓN COSTOS VS GANANCIAS", 20, y);
    y += 8;

    const canvas = document.getElementById("graficaVentas");
    if (canvas) {
        const imgData = canvas.toDataURL("image/png");
        const imgWidth = 120;
        const imgHeight = (canvas.height * imgWidth) / canvas.width;
        docAdmin.addImage(imgData, "PNG", (pageWidth - imgWidth) / 2, y, imgWidth, imgHeight);
    }
    <?php endif; ?>

    // --- PIE DE PÁGINA ADMIN ---
    const totalPagesAdmin = docAdmin.internal.getNumberOfPages();
    for (let i = 1; i <= totalPagesAdmin; i++) {
        docAdmin.setPage(i);
        docAdmin.setFontSize(8);
        docAdmin.setTextColor(150);
        docAdmin.text(
            `Página ${i} de ${totalPagesAdmin} | Sistema Tienda Pescadores | Reporte Administrador`,
            pageWidth / 2,
            pageHeight - 8,
            { align: "center" }
        );
    }

    docAdmin.save("Reporte_Administrador.pdf");

    // ============================================
    // PDF 2: PARA PROVEEDORES (Reporte de Deuda)
    // ============================================
    setTimeout(() => {
        const docProv = new jsPDF("p", "mm", "a4");
        const provPageWidth = docProv.internal.pageSize.getWidth();
        const provPageHeight = docProv.internal.pageSize.getHeight();
        let provY = 20;

        // --- ENCABEZADO PROVEEDORES (Claro y directo) ---
        docProv.setFontSize(22);
        docProv.setTextColor(192, 57, 43);
        docProv.setFont("helvetica", "bold");
        docProv.text("REPORTE PARA PROVEEDORES", provPageWidth / 2, 18, { align: "center" });
        
        docProv.setFontSize(11);
        docProv.setTextColor(100);
        docProv.setFont("helvetica", "normal");
        docProv.text("Estado de cuenta - Productos vendidos", provPageWidth / 2, 26, { align: "center" });
        
        // Línea separadora
        docProv.setDrawColor(192, 57, 43);
        docProv.setLineWidth(0.3);
        docProv.line(20, 32, provPageWidth - 20, 32);
        
        docProv.setFontSize(9);
        docProv.setTextColor(80);
        docProv.text(`Fecha: ${new Date().toLocaleString()}`, provPageWidth - 20, 38, { align: "right" });
        
        <?php if ($filtroInicio !== '' && $filtroFin !== ''): ?>
        docProv.text(`Período: <?= htmlspecialchars($filtroInicio) ?> al <?= htmlspecialchars($filtroFin) ?>`, 20, 38);
        <?php endif; ?>
        
        provY = 48;

        // --- INFORMACIÓN CLARAMENTE DESTACADA DEL TOTAL ---
        docProv.setFillColor(192, 57, 43);
        docProv.setTextColor(255);
        docProv.setFont("helvetica", "bold");
        
        // Rectángulo destacado para el total
        docProv.setFillColor(192, 57, 43);
        docProv.roundedRect(20, provY - 5, provPageWidth - 40, 18, 3, 3, "F");
        
        docProv.setFontSize(12);
        docProv.text("TOTAL A PAGAR:", 30, provY + 5);
        docProv.setFontSize(16);
        docProv.text("$<?= number_format($totalProveedor, 2) ?>", provPageWidth - 30, provY + 5, { align: "right" });
        
        provY += 25;

        // --- TABLA DETALLADA PARA PROVEEDORES ---
        docProv.setFontSize(12);
        docProv.setTextColor(192, 57, 43);
        docProv.setFont("helvetica", "bold");
        docProv.text("DETALLE DE VENTAS POR PRODUCTO", 20, provY);
        provY += 6;

        // Preparar datos para proveedores con formato claro
        const proveedoresData = [
            <?php foreach ($ventasDetalle as $venta): ?>
            [
                '<?= addslashes($venta['producto']) ?>',
                '<?= addslashes($venta['proveedor']) ?>',
                '<?= $venta['fecha_venta_formateada'] ?>',
                <?= $venta['cantidad_vendida'] ?>,
                <?= $venta['stock_inicial'] ?>,
                <?= $venta['stock_actual'] ?>,
                '$<?= number_format($venta['precio_compra'], 2) ?>',
                '$<?= number_format($venta['total_deuda'], 2) ?>'
            ],
            <?php endforeach; ?>
        ];

        if (proveedoresData.length > 0) {
            docProv.autoTable({
                head: [['Producto', 'Proveedor', 'Fecha', 'Vend.', 'Stk.Ini', 'Stk.Fin', 'P.Compra', 'Deuda']],
                body: proveedoresData,
                startY: provY,
                theme: "grid",
                headStyles: { fillColor: [192, 57, 43], textColor: 255, fontStyle: "bold", fontSize: 8 },
                styles: { fontSize: 7, cellPadding: 3 },
                columnStyles: {
                    0: { cellWidth: 28 },
                    1: { cellWidth: 28 },
                    2: { cellWidth: 18, halign: 'center' },
                    3: { cellWidth: 12, halign: 'center' },
                    4: { cellWidth: 14, halign: 'center' },
                    5: { cellWidth: 14, halign: 'center' },
                    6: { cellWidth: 18, halign: 'right' },
                    7: { cellWidth: 22, halign: 'right', fontStyle: 'bold' }
                },
                margin: { left: 10, right: 10 },
                didDrawPage: data => provY = data.cursor.y + 8
            });

            // --- RESUMEN POR PROVEEDOR ---
            if (provY > 220) { docProv.addPage(); provY = 25; }
            
            docProv.setFontSize(12);
            docProv.setTextColor(192, 57, 43);
            docProv.setFont("helvetica", "bold");
            docProv.text("RESUMEN POR PROVEEDOR", 20, provY);
            provY += 6;

            // Tabla de resumen por proveedor
            const resumenProvData = [
                <?php 
                $contador = 0;
                foreach ($deudaPorProveedor as $prov => $total): 
                ?>
                ['<?= addslashes($prov) ?>', '$<?= number_format($total, 2) ?>'],
                <?php endforeach; ?>
            ];

            docProv.autoTable({
                startY: provY,
                body: resumenProvData,
                theme: "plain",
                styles: { fontSize: 9, cellPadding: 4 },
                columnStyles: {
                    0: { cellWidth: 120, fontStyle: 'bold' },
                    1: { cellWidth: 50, halign: 'right' }
                },
                margin: { left: 20, right: 20 },
                didDrawPage: data => provY = data.cursor.y + 5
            });

            // --- TOTAL GENERAL DESTACADO ---
            provY += 5;
            
            docProv.setFillColor(245, 245, 245);
            docProv.roundedRect(20, provY - 5, provPageWidth - 40, 15, 3, 3, "F");
            docProv.setDrawColor(192, 57, 43);
            docProv.roundedRect(20, provY - 5, provPageWidth - 40, 15, 3, 3, "S");
            
            docProv.setFontSize(11);
            docProv.setTextColor(192, 57, 43);
            docProv.setFont("helvetica", "bold");
            docProv.text("TOTAL A PAGAR:", 30, provY + 3);
            docProv.text("$<?= number_format($totalProveedor, 2) ?>", provPageWidth - 30, provY + 3, { align: "right" });
            
            provY += 20;

            // --- NOTA ACLARATORIA ---
            if (provY > provPageHeight - 30) {
                docProv.addPage();
                provY = 25;
            }
            
            docProv.setFontSize(8);
            docProv.setTextColor(100);
            docProv.setFont("helvetica", "italic");
            docProv.text("Nota: Este documento detalla los productos vendidos y la deuda pendiente de pago.", 20, provY);
            docProv.text("El pago debe realizarse según los términos acordados con cada proveedor.", 20, provY + 4);
            
        } else {
            docProv.setFontSize(12);
            docProv.setTextColor(192, 57, 43);
            docProv.text("No hay ventas registradas en el período seleccionado.", provPageWidth / 2, provY + 20, { align: "center" });
        }

        // --- PIE DE PÁGINA PROVEEDORES ---
        const totalPagesProv = docProv.internal.getNumberOfPages();
        for (let i = 1; i <= totalPagesProv; i++) {
            docProv.setPage(i);
            docProv.setFontSize(7);
            docProv.setTextColor(150);
            docProv.text(
                `Página ${i} de ${totalPagesProv} | Documento para proveedores - Sistema Tienda Pescadores`,
                provPageWidth / 2,
                provPageHeight - 6,
                { align: "center" }
            );
        }

        docProv.save("Reporte_Proveedores.pdf");
    }, 500);
});
</script>

<?php
include('includes/footer.php');
?>