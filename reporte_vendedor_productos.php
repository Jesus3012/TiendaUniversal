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
$filtroProveedor = $_GET['proveedor'] ?? '';

function e($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function money($value) {
    return '$' . number_format((float)$value, 2);
}

function inicialesProveedor($nombre) {
    $nombre = trim((string)$nombre);
    if ($nombre === '') return '';

    $palabras = preg_split('/\s+/', $nombre);
    if (count($palabras) >= 2) {
        return strtoupper(mb_substr($palabras[0], 0, 1) . mb_substr($palabras[1], 0, 1));
    }

    return strtoupper(mb_substr($nombre, 0, 2));
}

function inicialesTexto($nombre) {
    $nombre = trim((string)$nombre);
    if ($nombre === '') return 'NA';

    $palabras = preg_split('/\s+/', $nombre);

    if (count($palabras) >= 2) {
        return strtoupper(mb_substr($palabras[0], 0, 1) . mb_substr($palabras[1], 0, 1));
    }

    return strtoupper(mb_substr($nombre, 0, 2));
}

function imagenBase64($path) {
    if (empty($path) || !file_exists($path)) {
        return ['', 'png', 25, 25];
    }

    $tipo = mime_content_type($path);
    $data = file_get_contents($path);
    $base64 = 'data:' . $tipo . ';base64,' . base64_encode($data);

    $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
    $ext = $ext === 'jpg' ? 'jpeg' : $ext;

    $width = 25;
    $height = 25;

    $size = @getimagesize($path);
    if ($size) {
        $anchoOriginal = max((float)$size[0], 1);
        $altoOriginal = max((float)$size[1], 1);
        $ratio = $anchoOriginal / $altoOriginal;
        $ladoMenor = min($anchoOriginal, $altoOriginal);

        if ($ladoMenor < 310) {
            $maxSize = 45;
        } elseif ($ladoMenor < 500) {
            $maxSize = 35;
        } elseif ($ladoMenor > 800) {
            $maxSize = 20;
        } else {
            $maxSize = 25;
        }

        if ($anchoOriginal > $altoOriginal) {
            $width = $maxSize;
            $height = $maxSize / $ratio;
        } else {
            $height = $maxSize;
            $width = $maxSize * $ratio;
        }
    }

    return [$base64, $ext, $width, $height];
}

/* =====================================================
   VENDEDORES PARA FILTRO ADMIN
===================================================== */
$vendedores = [];

if ($rol === 'administrador') {
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
}

/* =====================================================
   CONFIGURACIÓN / LOGO TIENDA
===================================================== */
$nombreTienda = 'PESCADORES DE LA PREHISTORIA';
$logoTienda = '';

$resConfig = $conn->query("SELECT nombre, logo FROM configuracion_galeria LIMIT 1");
if ($resConfig && $resConfig->num_rows > 0) {
    $cfg = $resConfig->fetch_assoc();
    $nombreTienda = $cfg['nombre'] ?: $nombreTienda;
    $logoTienda = $cfg['logo'] ?: '';
}

if (empty($logoTienda) || !file_exists($logoTienda)) {
    $rutasPosibles = [
        '../img/logo.png',
        '../img/logo.jpg',
        '../img/panel_principal.jpg',
        '../img/panel_principal.png',
        '../dist/img/logo.png',
        '../dist/img/logo.jpg',
        'img/logo.png',
        'img/logo.jpg',
        'dist/img/logo.png',
        'dist/img/logo.jpg'
    ];

    foreach ($rutasPosibles as $ruta) {
        if (file_exists($ruta)) {
            $logoTienda = $ruta;
            break;
        }
    }
}

[$logoTiendaBase64, $logoTiendaExt] = imagenBase64($logoTienda);
$inicialesTienda = inicialesTexto($nombreTienda);

/* =====================================================
   PROVEEDORES PARA FILTRO
===================================================== */
$proveedores = [];

$sqlProv = "
    SELECT DISTINCT p.proveedor
    FROM productos p
    INNER JOIN vendedor_productos vp ON vp.producto_id = p.id AND vp.activo = 1
    WHERE p.activo = 1
      AND p.tipo_inventario = 'producto'
      AND p.proveedor IS NOT NULL
      AND p.proveedor != ''
";

$paramsProv = [];
$typesProv = '';

if ($filtroVendedor > 0) {
    $sqlProv .= " AND vp.vendedor_id = ? ";
    $paramsProv[] = $filtroVendedor;
    $typesProv .= 'i';
}

$sqlProv .= " ORDER BY p.proveedor ASC ";

$stmtProv = $conn->prepare($sqlProv);
if ($stmtProv) {
    if (!empty($paramsProv)) {
        $stmtProv->bind_param($typesProv, ...$paramsProv);
    }

    $stmtProv->execute();
    $resProv = $stmtProv->get_result();

    while ($row = $resProv->fetch_assoc()) {
        $proveedores[] = $row['proveedor'];
    }
}

/* =====================================================
   LOGO PROVEEDOR PARA PDF
===================================================== */
$logoProveedorBase64 = '';
$logoProveedorExt = 'png';
$logoProveedorWidth = 25;
$logoProveedorHeight = 25;
$inicialesProveedor = inicialesProveedor($filtroProveedor);

if ($filtroProveedor !== '') {
    /*
     * Logo del proveedor:
     * Se toma de la tabla proveedores.logo.
     * Si no existe logo o el archivo no se encuentra, en el PDF se muestran iniciales.
     */
    $stmtLogoProveedor = $conn->prepare("
        SELECT logo, nombre
        FROM proveedores
        WHERE TRIM(LOWER(nombre)) = TRIM(LOWER(?))
        LIMIT 1
    ");

    if ($stmtLogoProveedor) {
        $stmtLogoProveedor->bind_param('s', $filtroProveedor);
        $stmtLogoProveedor->execute();
        $resLogoProveedor = $stmtLogoProveedor->get_result();

        if ($resLogoProveedor && $rowLogo = $resLogoProveedor->fetch_assoc()) {
            [$logoProveedorBase64, $logoProveedorExt, $logoProveedorWidth, $logoProveedorHeight] = imagenBase64($rowLogo['logo'] ?? '');

            if (empty($inicialesProveedor)) {
                $inicialesProveedor = inicialesTexto($rowLogo['nombre'] ?? $filtroProveedor);
            }
        }
    }
}

if (empty($inicialesProveedor) && $filtroProveedor !== '') {
    $inicialesProveedor = inicialesTexto($filtroProveedor);
}

/* =====================================================
   CONSULTA PRINCIPAL
   - Ventas propias: ventas.id_vendedor = vendedor asignado.
   - Ventas terceros: ventas.id_vendedor diferente, NULL o admin/otro usuario.
   - Deuda: pagado = 0, concesión = precio_compra * total vendido.
===================================================== */
$where = "
    vp.activo = 1
    AND p.activo = 1
    AND p.tipo_inventario = 'producto'
";

$params = [];
$types = '';

if ($filtroVendedor > 0) {
    $where .= " AND vp.vendedor_id = ? ";
    $params[] = $filtroVendedor;
    $types .= 'i';
}

if ($filtroProveedor !== '') {
    $where .= " AND p.proveedor = ? ";
    $params[] = $filtroProveedor;
    $types .= 's';
}

$joinFecha = '';
if ($filtroInicio !== '' && $filtroFin !== '') {
    $joinFecha = " AND DATE(v.fecha_venta) BETWEEN ? AND ? ";
    $params[] = $filtroInicio;
    $params[] = $filtroFin;
    $types .= 'ss';
}

$sql = "
    SELECT
        p.id,
        p.nombre AS producto,
        p.proveedor,
        p.cantidad AS stock_actual,
        p.precio_compra,
        p.precio_venta,
        p.tipo_adquisicion,
        p.tipo_codigo,
        u.id AS vendedor_id,
        u.nombre AS vendedor,
        u.email AS vendedor_email,

        IFNULL(SUM(CASE
            WHEN v.id_vendedor = vp.vendedor_id THEN v.cantidad_vendida
            ELSE 0
        END), 0) AS vendidos_propios,

        IFNULL(SUM(CASE
            WHEN v.id IS NOT NULL
             AND (v.id_vendedor IS NULL OR v.id_vendedor <> vp.vendedor_id)
            THEN v.cantidad_vendida
            ELSE 0
        END), 0) AS vendidos_terceros,

        IFNULL(SUM(v.cantidad_vendida), 0) AS vendidos_total,

        COUNT(CASE WHEN v.id_vendedor = vp.vendedor_id THEN v.id END) AS numero_ventas_propias,

        COUNT(CASE
            WHEN v.id IS NOT NULL
             AND (v.id_vendedor IS NULL OR v.id_vendedor <> vp.vendedor_id)
            THEN v.id
        END) AS numero_ventas_terceros,

        CASE
            WHEN p.tipo_adquisicion = 'pagado' THEN 0
            ELSE p.precio_compra * IFNULL(SUM(v.cantidad_vendida), 0)
        END AS deuda_total,

        p.precio_venta * IFNULL(SUM(v.cantidad_vendida), 0) AS venta_total,

        (p.precio_venta - p.precio_compra) * IFNULL(SUM(v.cantidad_vendida), 0) AS ganancia_total,

        CASE
            WHEN p.tipo_adquisicion = 'pagado' THEN 1
            ELSE 0
        END AS es_producto_pagado

    FROM vendedor_productos vp
    INNER JOIN productos p ON p.id = vp.producto_id
    INNER JOIN usuarios u ON u.id = vp.vendedor_id
    LEFT JOIN ventas v
        ON v.id_producto = p.id
        $joinFecha
    WHERE $where
    GROUP BY
        p.id,
        p.nombre,
        p.proveedor,
        p.cantidad,
        p.precio_compra,
        p.precio_venta,
        p.tipo_adquisicion,
        p.tipo_codigo,
        u.id,
        u.nombre,
        u.email
    ORDER BY u.nombre ASC, p.proveedor ASC, p.nombre ASC
";

$stmt = $conn->prepare($sql);
if (!$stmt) {
    die('Error al preparar reporte: ' . e($conn->error));
}

if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}

$stmt->execute();
$res = $stmt->get_result();

$rows = [];
$totales = [
    'vendidos_propios' => 0,
    'vendidos_terceros' => 0,
    'vendidos_total' => 0,
    'ventas' => 0,
    'deuda' => 0,
    'ganancia' => 0,
    'stock' => 0,
    'productos' => 0,
    'productos_pagados' => 0,
    'productos_concesion' => 0
];

$deudaPorProveedor = [];
$ventasAgrupadasPDF = [];
$stockRestantePDF = [];

while ($row = $res->fetch_assoc()) {
    $rows[] = $row;

    $vendidosTotal = (float)$row['vendidos_total'];
    $stockActual = (float)$row['stock_actual'];
    $stockInicial = $stockActual + $vendidosTotal;

    $totales['vendidos_propios'] += (float)$row['vendidos_propios'];
    $totales['vendidos_terceros'] += (float)$row['vendidos_terceros'];
    $totales['vendidos_total'] += $vendidosTotal;
    $totales['ventas'] += (float)$row['venta_total'];
    $totales['deuda'] += (float)$row['deuda_total'];
    $totales['ganancia'] += (float)$row['ganancia_total'];
    $totales['stock'] += $stockActual;
    $totales['productos']++;

    if ((int)$row['es_producto_pagado'] === 1) {
        $totales['productos_pagados']++;
    } else {
        $totales['productos_concesion']++;

        $proveedorKey = $row['proveedor'] ?: 'Sin proveedor';
        if (!isset($deudaPorProveedor[$proveedorKey])) {
            $deudaPorProveedor[$proveedorKey] = 0;
        }

        $deudaPorProveedor[$proveedorKey] += (float)$row['deuda_total'];
    }

    if ($vendidosTotal > 0) {
        $ventasAgrupadasPDF[] = [
            'proveedor' => $row['proveedor'] ?: 'Sin proveedor',
            'producto' => $row['producto'],
            'total_vendido' => $vendidosTotal,
            'stock_actual' => $stockActual,
            'precio_compra' => (float)$row['precio_compra'],
            'deuda_total' => (float)$row['deuda_total'],
            'es_producto_pagado' => (int)$row['es_producto_pagado'] === 1
        ];
    }

    $stockRestantePDF[] = [
        'proveedor' => $row['proveedor'] ?: 'Sin proveedor',
        'producto' => $row['producto'],
        'stock_inicial' => $stockInicial,
        'vendidos' => $vendidosTotal,
        'stock_actual' => $stockActual,
        'es_producto_pagado' => (int)$row['es_producto_pagado'] === 1
    ];
}

ksort($deudaPorProveedor);

$hayVentasTerceros = $totales['vendidos_terceros'] > 0;
$hayProductoPagado = $totales['productos_pagados'] > 0;

$periodoTexto = 'General';
if ($filtroInicio !== '' && $filtroFin !== '') {
    $periodoTexto = $filtroInicio . ' al ' . $filtroFin;
}

$nombreArchivoPdf = 'reporte_proveedor_' . ($filtroProveedor !== '' ? preg_replace('/[^A-Za-z0-9_-]+/', '_', $filtroProveedor) : 'general') . '_' . date('Y-m-d') . '.pdf';

include 'includes/header.php';
include 'includes/navbar.php';
?>

<link rel="stylesheet" href="css/reporte_vendedor_productos.css?v=<?= time() ?>">

<!-- jsPDF para descarga real de PDF -->
<script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf-autotable/3.8.2/jspdf.plugin.autotable.min.js"></script>

<div class="content-wrapper reporte-asignados-page">

    <div class="breadcrumb-card">
        <a href="index.php">
            <i class="fas fa-home"></i>
            Inicio
        </a>

        <span>/</span>

        <a href="dashboard_reportes_ventas.php">
            <i class="fas fa-chart-pie"></i>
            Reportes
        </a>

        <span>/</span>

        <strong>
            <i class="fas fa-user-check"></i>
            Productos asignados
        </strong>
    </div>

    <section class="content-header reporte-header no-print">
        <div class="container-fluid">
            <div class="header-card">
                <div class="header-left">
                    <?php if (!empty($logoTienda) && file_exists($logoTienda)): ?>
                        <div class="header-logo">
                            <img src="<?= e($logoTienda) ?>" alt="<?= e($nombreTienda) ?>">
                        </div>
                    <?php else: ?>
                        <div class="header-icon">
                            <i class="fas fa-chart-line"></i>
                        </div>
                    <?php endif; ?>

                    <div>
                        <span>Reporte comercial</span>
                        <h1>Reporte de productos asignados</h1>
                        <p>Ventas, stock, ganancia y deuda calculadas por productos autorizados.</p>
                    </div>
                </div>

                <div class="header-actions">
                    <button type="button" class="btn btn-danger" onclick="descargarPDFReporte()">
                        <i class="fas fa-file-pdf mr-1"></i>
                        Descargar PDF
                    </button>
                </div>
            </div>
        </div>
    </section>

    <section class="content">
        <div class="container-fluid">

            <div class="filters-card no-print">
                <div class="filters-title">
                    <div>
                        <h3><i class="fas fa-filter mr-2"></i>Filtros del reporte</h3>
                        <p>Selecciona proveedor o fechas para recalcular el reporte. El PDF se genera con estos mismos filtros.</p>
                    </div>
                </div>

                <form method="GET" class="filter-form">
                    <?php if ($rol === 'administrador'): ?>
                        <div class="filter-field vendedor-field">
                            <label>Vendedor</label>
                            <select name="vendedor_id" class="form-control">
                                <option value="0">Todos los vendedores</option>
                                <?php foreach ($vendedores as $v): ?>
                                    <option value="<?= (int)$v['id'] ?>" <?= $filtroVendedor === (int)$v['id'] ? 'selected' : '' ?>>
                                        <?= e($v['nombre']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    <?php endif; ?>

                    <div class="filter-field proveedor-field">
                        <label>Proveedor</label>
                        <select name="proveedor" class="form-control">
                            <option value="">Todos los proveedores</option>
                            <?php foreach ($proveedores as $prov): ?>
                                <option value="<?= e($prov) ?>" <?= $filtroProveedor === $prov ? 'selected' : '' ?>>
                                    <?= e($prov) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="filter-field fecha-field">
                        <label>Fecha inicio</label>
                        <input type="date" name="fecha_inicio" value="<?= e($filtroInicio) ?>" class="form-control">
                    </div>

                    <div class="filter-field fecha-field">
                        <label>Fecha fin</label>
                        <input type="date" name="fecha_fin" value="<?= e($filtroFin) ?>" class="form-control">
                    </div>

                    <div class="filter-actions">
                        <button class="btn btn-primary">
                            <i class="fas fa-filter mr-1"></i>
                            Filtrar
                        </button>

                        <a href="reporte_vendedor_productos.php" class="btn btn-outline-danger">
                            <i class="fas fa-eraser mr-1"></i>
                            Limpiar
                        </a>
                    </div>
                </form>
            </div>

            <div class="alert-toolbar no-print">
                <button type="button" class="btn-show-alert" id="btnMostrarAlertaTerceros" onclick="mostrarAlertaTerceros()">
                    <i class="fas fa-eye mr-1"></i>
                    Mostrar alerta de ventas de terceros
                </button>
            </div>

            <?php if ($hayVentasTerceros): ?>
                <div class="third-sales-alert no-print" id="alertVentasTerceros">
                    <div class="third-alert-icon">
                        <i class="fas fa-triangle-exclamation"></i>
                    </div>

                    <div class="third-alert-text">
                        <strong>Atención: ventas de terceros detectadas</strong>
                        <p>
                            Hay ventas de productos asignados que fueron registradas por otro vendedor, administrador o sin vendedor.
                            Se muestran como <b>Ventas de terceros</b> para no confundirse con ventas propias.
                        </p>
                    </div>

                    <button type="button" class="alert-hide-btn" onclick="ocultarAlertaTerceros()" title="Ocultar alerta">
                        <i class="fas fa-times"></i>
                    </button>
                </div>
            <?php endif; ?>

            <?php if ($hayProductoPagado): ?>
                <div class="alert alert-success no-print info-paid-alert">
                    <i class="fas fa-check-circle mr-2"></i>
                    Los productos con adquisición <strong>Pagado</strong> se excluyen de deuda, pero sí cuentan en venta total y ganancia.
                </div>
            <?php endif; ?>

            <div class="row metric-row">
                <div class="col-xl-3 col-md-6 mb-3">
                    <div class="metric-card metric-blue">
                        <div>
                            <span>Vendidos Totales</span>
                            <strong><?= number_format($totales['vendidos_total']) ?></strong>
                            <small><?= number_format($totales['vendidos_propios']) ?> propios · <?= number_format($totales['vendidos_terceros']) ?> terceros</small>
                        </div>
                        <i class="fas fa-shopping-cart"></i>
                    </div>
                </div>

                <div class="col-xl-3 col-md-6 mb-3">
                    <div class="metric-card metric-red">
                        <div>
                            <span>Deuda Proveedores</span>
                            <strong><?= money($totales['deuda']) ?></strong>
                            <small>Productos en concesión</small>
                        </div>
                        <i class="fas fa-hand-holding-usd"></i>
                    </div>
                </div>

                <div class="col-xl-3 col-md-6 mb-3">
                    <div class="metric-card metric-green">
                        <div>
                            <span>Ganancia</span>
                            <strong><?= money($totales['ganancia']) ?></strong>
                            <small>Venta total <?= money($totales['ventas']) ?></small>
                        </div>
                        <i class="fas fa-chart-line"></i>
                    </div>
                </div>

                <div class="col-xl-3 col-md-6 mb-3">
                    <div class="metric-card metric-orange">
                        <div>
                            <span>Stock Actual</span>
                            <strong><?= number_format($totales['stock']) ?></strong>
                            <small><?= number_format($totales['productos']) ?> productos</small>
                        </div>
                        <i class="fas fa-warehouse"></i>
                    </div>
                </div>
            </div>

            <div class="report-card">
                <div class="report-card-header">
                    <div>
                        <h3><i class="fas fa-table mr-2"></i>Detalle de productos asignados</h3>
                        <p>Ventas propias y ventas de terceros se muestran separadas.</p>
                    </div>
                    <span class="count-badge"><?= number_format(count($rows)) ?> registros</span>
                </div>

                <div class="table-responsive table-no-scroll assigned-detail-wrap">
                    <table class="table table-hover table-sm reporte-table assigned-detail-table">
                        <thead>
                            <tr>
                                <th>Vendedor asignado</th>
                                <th>Producto</th>
                                <th>Proveedor</th>
                                <th class="text-center">Ventas propias</th>
                                <th class="text-center">Ventas terceros</th>
                                <th class="text-center">Total vendido</th>
                                <th class="text-center">Stock</th>
                                <th class="text-right">Compra</th>
                                <th class="text-right">Venta</th>
                                <th class="text-right">Venta total</th>
                                <th class="text-right">Ganancia</th>
                                <th class="text-right">Deuda</th>
                                <th class="text-center">Adquisición</th>
                            </tr>
                        </thead>

                        <tbody>
                            <?php if (empty($rows)): ?>
                                <tr>
                                    <td colspan="13" class="text-center py-5 text-muted">
                                        <i class="fas fa-box-open fa-3x mb-3 d-block"></i>
                                        No hay información para mostrar.
                                    </td>
                                </tr>
                            <?php endif; ?>

                            <?php foreach ($rows as $r): ?>
                                <?php
                                    $propios = (float)$r['vendidos_propios'];
                                    $terceros = (float)$r['vendidos_terceros'];
                                    $total = (float)$r['vendidos_total'];
                                    $stock = (float)$r['stock_actual'];
                                    $esPagado = (int)$r['es_producto_pagado'] === 1;
                                    $rowClass = $esPagado ? 'row-pagado' : '';
                                    $stockClass = $stock <= 0 ? 'stock-danger' : ($stock <= 5 ? 'stock-warning' : 'stock-success');
                                ?>

                                <tr class="<?= $rowClass ?>">
                                    <td>
                                        <strong><?= e($r['vendedor']) ?></strong>
                                    </td>

                                    <td>
                                        <strong><?= e($r['producto']) ?></strong>
                                    </td>

                                    <td><?= e($r['proveedor'] ?: 'Sin proveedor') ?></td>

                                    <td class="text-center">
                                        <span class="badge-own">
                                            <i class="fas fa-user-check mr-1"></i>
                                            <?= number_format($propios) ?>
                                        </span>
                                    </td>

                                    <td class="text-center">
                                        <?php if ($terceros > 0): ?>
                                            <span class="badge-third" title="Ventas del producto registradas por otro vendedor, administrador o sin vendedor.">
                                                <i class="fas fa-users mr-1"></i>
                                                <?= number_format($terceros) ?>
                                            </span>
                                        <?php else: ?>
                                            <span class="badge-muted">0</span>
                                        <?php endif; ?>
                                    </td>

                                    <td class="text-center font-weight-bold"><?= number_format($total) ?></td>

                                    <td class="text-center">
                                        <span class="stock-pill <?= $stockClass ?>"><?= number_format($stock) ?></span>
                                    </td>

                                    <td class="text-right"><?= money($r['precio_compra']) ?></td>
                                    <td class="text-right"><?= money($r['precio_venta']) ?></td>
                                    <td class="text-right"><?= money($r['venta_total']) ?></td>
                                    <td class="text-right text-success font-weight-bold"><?= money($r['ganancia_total']) ?></td>

                                    <td class="text-right font-weight-bold <?= $esPagado ? 'text-success' : 'text-danger' ?>">
                                        <?php if ($esPagado): ?>
                                            <span class="badge-paid">
                                                <i class="fas fa-check-circle mr-1"></i>
                                                PAGADO
                                            </span>
                                        <?php else: ?>
                                            <?= money($r['deuda_total']) ?>
                                        <?php endif; ?>
                                    </td>

                                    <td class="text-center">
                                        <?php if ($esPagado): ?>
                                            <span class="badge-paid">
                                                <i class="fas fa-check-circle mr-1"></i>
                                                Pagado
                                            </span>
                                        <?php else: ?>
                                            <span class="badge-debt">
                                                <i class="fas fa-handshake mr-1"></i>
                                                Concesión
                                            </span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>

                        <?php if (!empty($rows)): ?>
                            <tfoot>
                                <tr>
                                    <th colspan="3" class="text-right">Totales</th>
                                    <th class="text-center"><?= number_format($totales['vendidos_propios']) ?></th>
                                    <th class="text-center"><?= number_format($totales['vendidos_terceros']) ?></th>
                                    <th class="text-center"><?= number_format($totales['vendidos_total']) ?></th>
                                    <th class="text-center"><?= number_format($totales['stock']) ?></th>
                                    <th></th>
                                    <th></th>
                                    <th class="text-right"><?= money($totales['ventas']) ?></th>
                                    <th class="text-right"><?= money($totales['ganancia']) ?></th>
                                    <th class="text-right"><?= money($totales['deuda']) ?></th>
                                    <th></th>
                                </tr>
                            </tfoot>
                        <?php endif; ?>
                    </table>
                </div>
            </div>

            <div class="report-card debt-card">
                <div class="report-card-header debt-header">
                    <div>
                        <h3><i class="fas fa-hand-holding-usd mr-2"></i>Reporte de deuda con proveedores</h3>
                        <p>El detalle visual se mantiene en pantalla. El estado de cuenta completo se descarga en PDF.</p>
                    </div>
                    <span class="debt-total">Total adeudo: <?= money($totales['deuda']) ?></span>
                </div>

                <div class="table-responsive">
                    <table class="table table-hover table-sm reporte-table debt-table">
                        <thead>
                            <tr>
                                <th>Producto</th>
                                <th>Proveedor</th>
                                <th>Vendedor asignado</th>
                                <th class="text-center">Propios</th>
                                <th class="text-center">Terceros</th>
                                <th class="text-center">Total vendido</th>
                                <th class="text-right">Costo unitario</th>
                                <th class="text-right">Deuda total</th>
                                <th class="text-center">Adquisición</th>
                            </tr>
                        </thead>

                        <tbody>
                            <?php if (empty($rows)): ?>
                                <tr>
                                    <td colspan="9" class="text-center py-5 text-muted">
                                        No hay deuda registrada en el periodo seleccionado.
                                    </td>
                                </tr>
                            <?php endif; ?>

                            <?php foreach ($rows as $r): ?>
                                <?php
                                    $esPagado = (int)$r['es_producto_pagado'] === 1;
                                    $terceros = (float)$r['vendidos_terceros'];
                                ?>
                                <tr class="<?= $esPagado ? 'row-pagado' : '' ?>">
                                    <td><?= e($r['producto']) ?></td>
                                    <td><?= e($r['proveedor'] ?: 'Sin proveedor') ?></td>
                                    <td><?= e($r['vendedor']) ?></td>
                                    <td class="text-center"><?= number_format((float)$r['vendidos_propios']) ?></td>
                                    <td class="text-center">
                                        <?php if ($terceros > 0): ?>
                                            <span class="badge-third"><?= number_format($terceros) ?></span>
                                        <?php else: ?>
                                            <span class="badge-muted">0</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-center"><?= number_format((float)$r['vendidos_total']) ?></td>
                                    <td class="text-right"><?= money($r['precio_compra']) ?></td>
                                    <td class="text-right font-weight-bold <?= $esPagado ? 'text-success' : 'text-danger' ?>">
                                        <?php if ($esPagado): ?>
                                            <span class="badge-paid">
                                                <i class="fas fa-check-circle mr-1"></i>
                                                PAGADO
                                            </span>
                                        <?php else: ?>
                                            <?= money($r['deuda_total']) ?>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-center">
                                        <?php if ($esPagado): ?>
                                            <span class="badge-paid">Pagado</span>
                                        <?php else: ?>
                                            <span class="badge-debt">Concesión</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>

                        <?php if (!empty($rows)): ?>
                            <tfoot>
                                <tr>
                                    <th colspan="3" class="text-right">Totales</th>
                                    <th class="text-center"><?= number_format($totales['vendidos_propios']) ?></th>
                                    <th class="text-center"><?= number_format($totales['vendidos_terceros']) ?></th>
                                    <th class="text-center"><?= number_format($totales['vendidos_total']) ?></th>
                                    <th></th>
                                    <th class="text-right"><?= money($totales['deuda']) ?></th>
                                    <th></th>
                                </tr>
                            </tfoot>
                        <?php endif; ?>
                    </table>
                </div>

                <?php if (!empty($deudaPorProveedor)): ?>
                    <div class="provider-summary">
                        <h4><i class="fas fa-list-ul mr-1"></i>Resumen por proveedor</h4>

                        <div class="provider-summary-grid">
                            <?php foreach ($deudaPorProveedor as $prov => $totalProv): ?>
                                <div class="provider-summary-item">
                                    <span><?= e($prov) ?></span>
                                    <strong><?= money($totalProv) ?></strong>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endif; ?>

                <div class="report-note">
                    <i class="fas fa-info-circle mr-1"></i>
                    La deuda se calcula como <strong>precio de compra × total vendido</strong> únicamente en productos de concesión.
                    Las ventas de terceros se separan para que el vendedor/proveedor identifique si el producto fue vendido por alguien más.
                </div>
            </div>

        </div>
    </section>
</div>

<script>
const datosPDF = {
    nombreTienda: <?= json_encode(strtoupper($nombreTienda)) ?>,
    inicialesTienda: <?= json_encode($inicialesTienda) ?>,
    proveedor: <?= json_encode($filtroProveedor) ?>,
    inicialesProveedor: <?= json_encode($inicialesProveedor) ?>,
    periodo: <?= json_encode($periodoTexto) ?>,
    archivo: <?= json_encode($nombreArchivoPdf) ?>,
    totalProveedor: <?= json_encode((float)$totales['deuda']) ?>,
    logoTiendaBase64: <?= json_encode($logoTiendaBase64) ?>,
    logoTiendaExt: <?= json_encode($logoTiendaExt) ?>,
    logoProveedorBase64: <?= json_encode($logoProveedorBase64) ?>,
    logoProveedorExt: <?= json_encode($logoProveedorExt) ?>,
    logoProveedorWidth: <?= json_encode((float)$logoProveedorWidth) ?>,
    logoProveedorHeight: <?= json_encode((float)$logoProveedorHeight) ?>,
    ventasAgrupadas: <?= json_encode($ventasAgrupadasPDF, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>,
    stockRestante: <?= json_encode($stockRestantePDF, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>,
    deudaPorProveedor: <?= json_encode($deudaPorProveedor, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>
};

document.addEventListener('DOMContentLoaded', function () {
    const fechaInicio = document.querySelector('input[name="fecha_inicio"]');
    const fechaFin = document.querySelector('input[name="fecha_fin"]');

    if (fechaInicio && fechaFin) {
        fechaInicio.addEventListener('change', function () {
            if (fechaFin.value && this.value > fechaFin.value) {
                fechaFin.value = this.value;
            }
        });

        fechaFin.addEventListener('change', function () {
            if (fechaInicio.value && this.value < fechaInicio.value) {
                fechaInicio.value = this.value;
            }
        });
    }

    aplicarEstadoAlertaTerceros();
});

function ocultarAlertaTerceros() {
    localStorage.setItem('reporteVentasTercerosOculta', '1');
    aplicarEstadoAlertaTerceros();
}

function mostrarAlertaTerceros() {
    localStorage.removeItem('reporteVentasTercerosOculta');
    aplicarEstadoAlertaTerceros();
}

function aplicarEstadoAlertaTerceros() {
    const alertBox = document.getElementById('alertVentasTerceros');
    const showButton = document.getElementById('btnMostrarAlertaTerceros');
    const hidden = localStorage.getItem('reporteVentasTercerosOculta') === '1';

    if (!alertBox || !showButton) {
        if (showButton) showButton.style.display = 'none';
        return;
    }

    alertBox.style.display = hidden ? 'none' : 'flex';
    showButton.style.display = hidden ? 'inline-flex' : 'none';
}

function formatoMoney(value) {
    return '$' + Number(value || 0).toLocaleString('es-MX', {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2
    });
}

function formatoNumero(value) {
    return Number(value || 0).toLocaleString('es-MX');
}

function descargarPDFReporte() {
    if (!window.jspdf || !window.jspdf.jsPDF) {
        alert('No se pudo cargar jsPDF. Verifica tu conexión o agrega la librería localmente.');
        return;
    }

    const { jsPDF } = window.jspdf;

    const colors = {
        danger: [239, 68, 68],
        dangerDark: [220, 38, 38],
        dark: [37, 37, 37],
        medium: [95, 95, 95],
        light: [248, 250, 252],
        white: [255, 255, 255],
        border: [225, 225, 225]
    };

    const docProv = new jsPDF({
        orientation: 'p',
        unit: 'mm',
        format: 'a4'
    });

    const pageWidth = docProv.internal.pageSize.getWidth();
    const pageHeight = docProv.internal.pageSize.getHeight();
    let y = 25;

    function addFooter() {
        const totalPages = docProv.internal.getNumberOfPages();

        for (let i = 1; i <= totalPages; i++) {
            docProv.setPage(i);
            docProv.setFontSize(8);
            docProv.setTextColor(colors.medium[0], colors.medium[1], colors.medium[2]);
            docProv.setFont('helvetica', 'normal');
            docProv.setDrawColor(220, 220, 220);
            docProv.line(20, pageHeight - 12, pageWidth - 20, pageHeight - 12);
            docProv.text(
                'Documento confidencial para proveedores | ' + new Date().toLocaleDateString(),
                20,
                pageHeight - 6
            );
            docProv.text(
                'Página ' + i + ' de ' + totalPages + ' | Estado de Cuenta',
                pageWidth - 20,
                pageHeight - 6,
                { align: 'right' }
            );
        }
    }

    function addHeader() {
        const logoY = 12;
        const logoSize = 25;

        /*
         * Logo de tienda:
         * Si existe logo en configuracion_galeria.logo se muestra.
         * Si no existe, se muestran únicamente iniciales, sin cuadro.
         */
        if (datosPDF.logoTiendaBase64) {
            try {
                docProv.addImage(
                    datosPDF.logoTiendaBase64,
                    datosPDF.logoTiendaExt || 'png',
                    15,
                    logoY,
                    logoSize,
                    logoSize
                );
            } catch (e) {
                docProv.setFontSize(18);
                docProv.setTextColor(249, 115, 22);
                docProv.setFont('helvetica', 'bold');
                docProv.text(datosPDF.inicialesTienda || 'TP', 15 + logoSize / 2, logoY + 16, { align: 'center' });
            }
        } else {
            docProv.setFontSize(18);
            docProv.setTextColor(249, 115, 22);
            docProv.setFont('helvetica', 'bold');
            docProv.text(datosPDF.inicialesTienda || 'TP', 15 + logoSize / 2, logoY + 16, { align: 'center' });
        }

        docProv.setFontSize(datosPDF.proveedor ? 12 : 14);
        docProv.setTextColor(60, 60, 60);
        docProv.setFont('helvetica', 'bold');
        docProv.text(datosPDF.nombreTienda || 'PESCADORES DE LA PREHISTORIA', pageWidth / 2, logoY + logoSize / 2 + 4, { align: 'center' });

        /*
         * Logo de proveedor:
         * Si hay filtro por proveedor y existe proveedores.logo se muestra.
         * Si no hay logo, se muestran únicamente iniciales, sin cuadro.
         */
        if (datosPDF.proveedor) {
            const proveedorBoxSize = 25;
            const proveedorX = pageWidth - proveedorBoxSize - 15;

            if (datosPDF.logoProveedorBase64) {
                try {
                    const w = Number(datosPDF.logoProveedorWidth || 25);
                    const h = Number(datosPDF.logoProveedorHeight || 25);
                    const x = pageWidth - w - 15;
                    docProv.addImage(
                        datosPDF.logoProveedorBase64,
                        datosPDF.logoProveedorExt || 'png',
                        x,
                        logoY,
                        w,
                        h
                    );
                } catch (e) {
                    docProv.setFontSize(18);
                    docProv.setTextColor(239, 68, 68);
                    docProv.setFont('helvetica', 'bold');
                    docProv.text(datosPDF.inicialesProveedor || 'PR', proveedorX + proveedorBoxSize / 2, logoY + 16, { align: 'center' });
                }
            } else {
                docProv.setFontSize(18);
                docProv.setTextColor(239, 68, 68);
                docProv.setFont('helvetica', 'bold');
                docProv.text(datosPDF.inicialesProveedor || 'PR', proveedorX + proveedorBoxSize / 2, logoY + 16, { align: 'center' });
            }
        }

        docProv.setDrawColor(230, 230, 230);
        docProv.line(15, logoY + logoSize + 8, pageWidth - 15, logoY + logoSize + 8);

        docProv.setFontSize(24);
        docProv.setTextColor(colors.danger[0], colors.danger[1], colors.danger[2]);
        docProv.setFont('helvetica', 'bold');
        docProv.text('ESTADO DE CUENTA', pageWidth / 2, 65, { align: 'center' });
        docProv.text('PAGO A PROVEEDORES', pageWidth / 2, 77, { align: 'center' });

        docProv.setFontSize(10);
        docProv.setTextColor(colors.medium[0], colors.medium[1], colors.medium[2]);
        docProv.text('Generado: ' + new Date().toLocaleString(), pageWidth / 2, 92, { align: 'center' });

        if (datosPDF.proveedor) {
            docProv.setFontSize(12);
            docProv.setTextColor(colors.danger[0], colors.danger[1], colors.danger[2]);
            docProv.setFont('helvetica', 'bold');
            docProv.text('Proveedor: ' + datosPDF.proveedor, pageWidth / 2, 104, { align: 'center' });
        }

        if (datosPDF.periodo && datosPDF.periodo !== 'General') {
            docProv.setFontSize(10);
            docProv.setTextColor(colors.medium[0], colors.medium[1], colors.medium[2]);
            docProv.text('Período: ' + datosPDF.periodo, pageWidth / 2, 114, { align: 'center' });
        }

        y = 135;

        docProv.setFillColor(colors.light[0], colors.light[1], colors.light[2]);
        docProv.roundedRect(40, y - 10, pageWidth - 80, 35, 3, 3, 'F');
        docProv.setDrawColor(colors.danger[0], colors.danger[1], colors.danger[2]);
        docProv.setLineWidth(0.5);
        docProv.roundedRect(40, y - 10, pageWidth - 80, 35, 3, 3, 'S');

        docProv.setFontSize(10);
        docProv.setTextColor(colors.medium[0], colors.medium[1], colors.medium[2]);
        docProv.text('TOTAL A PAGAR A PROVEEDORES', pageWidth / 2, y, { align: 'center' });

        docProv.setFontSize(26);
        docProv.setTextColor(colors.danger[0], colors.danger[1], colors.danger[2]);
        docProv.setFont('helvetica', 'bold');
        docProv.text(formatoMoney(datosPDF.totalProveedor), pageWidth / 2, y + 15, { align: 'center' });

        y += 45;
    }

    addHeader();

    docProv.setFontSize(14);
    docProv.setTextColor(colors.dark[0], colors.dark[1], colors.dark[2]);
    docProv.setFont('helvetica', 'bold');
    docProv.text('1. Detalle de Productos Vendidos', 20, y);
    y += 8;

    const detalle = (datosPDF.ventasAgrupadas || []).map(item => [
        item.proveedor,
        item.producto,
        formatoNumero(item.total_vendido),
        formatoNumero(item.stock_actual),
        formatoMoney(item.precio_compra),
        item.es_producto_pagado ? 'PAGADO' : formatoMoney(item.deuda_total)
    ]);

    if (detalle.length > 0) {
        docProv.autoTable({
            head: [['Proveedor', 'Producto', 'Vendidos', 'Stock Restante', 'P.Compra', 'Deuda Total']],
            body: detalle,
            startY: y,
            theme: 'grid',
            headStyles: {
                fillColor: colors.danger,
                textColor: colors.white,
                fontSize: 8,
                halign: 'center',
                fontStyle: 'bold'
            },
            styles: {
                fontSize: 7,
                cellPadding: 3,
                overflow: 'linebreak',
                halign: 'center'
            },
            columnStyles: {
                0: { cellWidth: 30, fontStyle: 'bold', halign: 'left' },
                1: { cellWidth: 35, halign: 'left' },
                2: { cellWidth: 20, halign: 'center' },
                3: { cellWidth: 20, halign: 'center' },
                4: { cellWidth: 23, halign: 'right' },
                5: { cellWidth: 25, halign: 'right', fontStyle: 'bold' }
            },
            margin: { left: 20, right: 20 },
            tableWidth: pageWidth - 40
        });

        y = docProv.lastAutoTable.finalY + 15;
    } else {
        docProv.setFontSize(14);
        docProv.setTextColor(colors.medium[0], colors.medium[1], colors.medium[2]);
        docProv.text('No hay ventas registradas en el período seleccionado.', pageWidth / 2, y + 20, { align: 'center' });
        y += 35;
    }

    if (y > 220) {
        docProv.addPage();
        y = 25;
    }

    docProv.setFontSize(14);
    docProv.setTextColor(colors.danger[0], colors.danger[1], colors.danger[2]);
    docProv.setFont('helvetica', 'bold');
    docProv.text('2. Stock Restante por Producto', 20, y);
    y += 8;

    const stockData = (datosPDF.stockRestante || []).map(item => [
        item.proveedor,
        item.producto,
        formatoNumero(item.stock_inicial),
        formatoNumero(item.vendidos),
        formatoNumero(item.stock_actual),
        item.es_producto_pagado ? 'PAGADO' : ''
    ]);

    docProv.autoTable({
        head: [['Proveedor', 'Producto', 'Stock Inicial', 'Vendidos', 'Stock Restante', 'Estado']],
        body: stockData,
        startY: y,
        theme: 'striped',
        headStyles: { fillColor: colors.danger, textColor: colors.white, fontSize: 8, halign: 'center' },
        styles: { fontSize: 7, cellPadding: 3, halign: 'center' },
        columnStyles: {
            0: { cellWidth: 35, fontStyle: 'bold', halign: 'left' },
            1: { cellWidth: 45, halign: 'left' },
            2: { cellWidth: 22, halign: 'center' },
            3: { cellWidth: 20, halign: 'center' },
            4: { cellWidth: 22, halign: 'center', fontStyle: 'bold' },
            5: { cellWidth: 20, halign: 'center' }
        },
        margin: { left: 20, right: 20 },
        tableWidth: pageWidth - 40
    });

    y = docProv.lastAutoTable.finalY + 15;

    if (y > 240) {
        docProv.addPage();
        y = 25;
    }

    docProv.setFontSize(14);
    docProv.setTextColor(colors.danger[0], colors.danger[1], colors.danger[2]);
    docProv.setFont('helvetica', 'bold');
    docProv.text('3. Resumen por Proveedor', 20, y);
    y += 8;

    const resumenProvData = Object.entries(datosPDF.deudaPorProveedor || {}).map(([prov, total]) => [
        prov,
        formatoMoney(total)
    ]);

    resumenProvData.push(['', '']);
    resumenProvData.push(['TOTAL GENERAL', formatoMoney(datosPDF.totalProveedor)]);

    const resumenAncho = 140;
    const resumenMargen = (pageWidth - resumenAncho) / 2;

    docProv.autoTable({
        startY: y,
        body: resumenProvData,
        theme: 'plain',
        styles: { fontSize: 10, cellPadding: 6, halign: 'center' },
        columnStyles: {
            0: { cellWidth: 80, fontStyle: 'bold', halign: 'right' },
            1: { cellWidth: 60, halign: 'right', fontStyle: 'bold' }
        },
        margin: { left: resumenMargen, right: resumenMargen }
    });

    addFooter();

    docProv.save(datosPDF.archivo || 'reporte_productos_asignados.pdf');
}
</script>

<?php include 'includes/footer.php'; ?>
