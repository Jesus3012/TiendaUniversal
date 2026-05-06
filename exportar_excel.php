<?php
session_start();
include 'includes/db.php';

// Verificar autenticacion
if (!isset($_SESSION['usuario_id'])) {
    die('No autorizado');
}

// Obtener parametros
$inicio = isset($_GET['inicio']) ? $_GET['inicio'] : '';
$fin = isset($_GET['fin']) ? $_GET['fin'] : '';
$busqueda = isset($_GET['busqueda']) ? trim($_GET['busqueda']) : '';

// Validar fechas
if (empty($inicio) || empty($fin)) {
    die('Se requieren fechas de inicio y fin');
}

// Obtener datos de la tienda
$sqlConfig = "SELECT nombre, telefono, email, direccion FROM configuracion_galeria LIMIT 1";
$resultConfig = $conn->query($sqlConfig);
$config = $resultConfig->fetch_assoc();
$nombreTienda = $config['nombre'] ?? 'TIENDA PESCADORES';
$telefono = $config['telefono'] ?? '';
$email = $config['email'] ?? '';
$direccion = $config['direccion'] ?? '';

// Configurar headers para Excel
header('Content-Type: application/vnd.ms-excel');
header('Content-Disposition: attachment; filename="reporte_ventas_' . date('Y-m-d') . '.xls"');
header('Cache-Control: max-age=0');
header('Pragma: public');

?>
<html>
<head>
    <meta charset="UTF-8">
    <title>Reporte de Ventas</title>
    <style>
        body {
            font-family: 'Calibri', Arial, sans-serif;
            font-size: 12px;
            margin: 30px 20px;
        }
        
        .header-container {
            margin-bottom: 30px;
            border-bottom: 3px solid #3498db;
            padding-bottom: 15px;
            width: 100%;
            overflow: hidden;
        }
        
        .logo-area {
            float: left;
            width: 20%;
            text-align: center;
            background-color: #2980b9;
            padding: 12px 0;
            border-radius: 10px;
        }
        
        .logo-texto {
            font-size: 26px;
            font-weight: bold;
            color: white;
            letter-spacing: 2px;
        }
        
        .logo-subtexto {
            font-size: 10px;
            color: #ecf0f1;
            margin-top: 3px;
        }
        
        .info-area {
            float: right;
            width: 78%;
            text-align: right;
        }
        
        .clearfix {
            clear: both;
        }
        
        .tienda-nombre {
            font-size: 20px;
            font-weight: bold;
            color: #2c3e50;
            margin-bottom: 8px;
        }
        
        .tienda-datos {
            font-size: 11px;
            color: #555;
            margin: 2px 0;
        }
        
        .tabla-container {
            margin-bottom: 50px;
            page-break-inside: avoid;
        }
        
        h1 {
            color: #2c3e50;
            font-size: 22px;
            font-weight: bold;
            margin-bottom: 5px;
            border-bottom: 3px solid #3498db;
            padding-bottom: 10px;
        }
        
        h2 {
            color: #34495e;
            font-size: 16px;
            font-weight: bold;
            margin-top: 0;
            margin-bottom: 15px;
            background-color: #ecf0f1;
            padding: 8px 12px;
            border-left: 5px solid #3498db;
        }
        
        .info-box {
            background-color: #e8f4fd;
            padding: 12px 15px;
            margin-bottom: 30px;
            border: 1px solid #b8daff;
            border-radius: 5px;
            font-size: 12px;
        }
        
        .info-line {
            margin: 3px 0;
        }
        
        table {
            border-collapse: collapse;
            width: 100%;
            font-family: 'Calibri', Arial, sans-serif;
            font-size: 13px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
        }
        
        th {
            background-color: #2980b9;
            color: white;
            font-weight: bold;
            padding: 14px 10px;
            border: 1px solid #1a5d8a;
            text-align: center;
            font-size: 14px;
        }
        
        td {
            padding: 12px 8px;
            border: 1px solid #ddd;
            vertical-align: top;
        }
        
        tr:nth-child(even) {
            background-color: #f9f9f9;
        }
        
        tr:hover {
            background-color: #f0f7ff;
        }
        
        .numero {
            text-align: right;
        }
        
        .fecha-col {
            text-align: center;
        }
        
        .total-row {
            font-weight: bold;
            background-color: #f0f0f0;
        }
        
        .table-footer {
            background-color: #ecf0f1;
            font-weight: bold;
        }
    </style>
</head>
<body>

<!-- ENCABEZADO CON LOGO TEXTO -->
<div class="header-container">
    <div class="logo-area">
        <div class="logo-texto"><?php echo strtoupper(substr($nombreTienda, 0, 2)); ?></div>
        <div class="logo-subtexto">TIENDA</div>
    </div>
    <div class="info-area">
        <div class="tienda-nombre"><?php echo strtoupper($nombreTienda); ?></div>
        <?php if (!empty($direccion)): ?>
        <div class="tienda-datos"><?php echo $direccion; ?></div>
        <?php endif; ?>
        <?php if (!empty($telefono)): ?>
        <div class="tienda-datos">Telefono: <?php echo $telefono; ?></div>
        <?php endif; ?>
        <?php if (!empty($email)): ?>
        <div class="tienda-datos">Email: <?php echo $email; ?></div>
        <?php endif; ?>
    </div>
    <div class="clearfix"></div>
</div>

<h1>REPORTE DE VENTAS</h1>

<div class="info-box">
    <div class="info-line"><strong>Periodo:</strong> <?php echo date('d/m/Y', strtotime($inicio)) . ' al ' . date('d/m/Y', strtotime($fin)); ?></div>
    <div class="info-line"><strong>Fecha de generacion:</strong> <?php echo date('d/m/Y H:i:s'); ?></div>
    <?php if (!empty($busqueda)): ?>
    <div class="info-line"><strong>Filtro aplicado:</strong> <?php echo htmlspecialchars($busqueda); ?></div>
    <?php endif; ?>
</div>

<?php
// ============================================
// CONSULTA PRINCIPAL CORREGIDA
// ============================================
// Primero obtener los folios unicos
$sqlFolios = "SELECT DISTINCT folio_ticket FROM ventas WHERE DATE(fecha_venta) BETWEEN '$inicio' AND '$fin'";
if (!empty($busqueda)) {
    $busqueda_escape = $conn->real_escape_string($busqueda);
    $sqlFolios .= " AND (folio_ticket LIKE '%$busqueda_escape%' OR correo_cliente LIKE '%$busqueda_escape%')";
}
$resultFolios = $conn->query($sqlFolios);

// Variables para estadisticas
$totalGeneral = 0;
$totalVentas = 0;
$totalPedidos = 0;
$totalConteos = 0;
$metodosPago = array();
$ventasPorVendedor = array();
$ventasData = array();

if ($resultFolios && $resultFolios->num_rows > 0) {
    while ($folioRow = $resultFolios->fetch_assoc()) {
        $folio = $folioRow['folio_ticket'];
        
        // Obtener datos de la venta
        $sqlVenta = "SELECT 
                        v.folio_ticket,
                        v.correo_cliente,
                        v.fecha_venta,
                        v.metodo_pago,
                        v.referencia_pago,
                        v.id_vendedor,
                        u.nombre as vendedor_nombre,
                        SUM(v.cantidad_vendida * p.precio_venta) as total_general,
                        COUNT(DISTINCT v.id_producto) as total_productos,
                        SUM(v.cantidad_vendida) as total_unidades
                    FROM ventas v
                    LEFT JOIN usuarios u ON v.id_vendedor = u.id
                    JOIN productos p ON v.id_producto = p.id
                    WHERE v.folio_ticket = '$folio'
                    GROUP BY v.folio_ticket, v.correo_cliente, v.fecha_venta, v.metodo_pago, 
                             v.referencia_pago, v.id_vendedor, u.nombre";
        
        $resultVenta = $conn->query($sqlVenta);
        
        if ($resultVenta && $row = $resultVenta->fetch_assoc()) {
            $totalGeneral += floatval($row['total_general'] ?? 0);
            $totalVentas++;
            
            $tipoVenta = 'Directa';
            if (strpos($row['folio_ticket'], 'PEDIDO-') === 0) {
                $tipoVenta = 'Pedido';
                $totalPedidos++;
            } elseif (strpos($row['folio_ticket'], 'Venta_conteo') === 0) {
                $tipoVenta = 'Conteo';
                $totalConteos++;
            }
            
            $metodo = !empty($row['metodo_pago']) ? $row['metodo_pago'] : 'Efectivo';
            if (!isset($metodosPago[$metodo])) {
                $metodosPago[$metodo] = array('cantidad' => 0, 'total' => 0);
            }
            $metodosPago[$metodo]['cantidad']++;
            $metodosPago[$metodo]['total'] += floatval($row['total_general'] ?? 0);
            
            $vendedor = !empty($row['vendedor_nombre']) ? $row['vendedor_nombre'] : 'Sistema';
            if (!isset($ventasPorVendedor[$vendedor])) {
                $ventasPorVendedor[$vendedor] = array('cantidad' => 0, 'total' => 0);
            }
            $ventasPorVendedor[$vendedor]['cantidad']++;
            $ventasPorVendedor[$vendedor]['total'] += floatval($row['total_general'] ?? 0);
            
            $ventasData[] = array(
                'folio' => $row['folio_ticket'],
                'total' => $row['total_general'],
                'cliente' => (!empty($row['correo_cliente']) && $row['correo_cliente'] != 'null') ? $row['correo_cliente'] : 'Venta general',
                'fecha' => date('d/m/Y', strtotime($row['fecha_venta'])),
                'hora' => date('H:i:s', strtotime($row['fecha_venta'])),
                'metodo_pago' => $row['metodo_pago'] ?? 'Efectivo',
                'referencia' => $row['referencia_pago'] ?? '---',
                'vendedor' => $vendedor,
                'productos' => $row['total_productos'] ?? 0,
                'unidades' => $row['total_unidades'] ?? 0,
                'tipo' => $tipoVenta
            );
        }
    }
}
?>

<!-- TABLA 1: LISTADO DE VENTAS -->
<div class="tabla-container">
    <h2>1. LISTADO DE VENTAS (<?php echo count($ventasData); ?> registros)</h2>
    <table>
        <thead>
            <tr>
                <th>FOLIO</th>
                <th>TOTAL</th>
                <th>CLIENTE</th>
                <th>FECHA</th>
                <th>HORA</th>
                <th>METODO PAGO</th>
                <th>REFERENCIA</th>
                <th>VENDEDOR</th>
                <th>PROD</th>
                <th>UND</th>
                <th>TIPO</th>
            </tr>
        </thead>
        <tbody>
<?php
if (count($ventasData) > 0) {
    foreach ($ventasData as $row) {
        ?>
        <tr>
            <td><?php echo $row['folio']; ?></td>
            <td class="numero">$<?php echo number_format($row['total'], 2); ?></td>
            <td><?php echo $row['cliente']; ?></td>
            <td class="fecha-col"><?php echo $row['fecha']; ?></td>
            <td class="fecha-col"><?php echo $row['hora']; ?></td>
            <td><?php echo $row['metodo_pago']; ?></td>
            <td><?php echo $row['referencia']; ?></td>
            <td><?php echo $row['vendedor']; ?></td>
            <td class="numero"><?php echo $row['productos']; ?></td>
            <td class="numero"><?php echo $row['unidades']; ?></td>
            <td><?php echo $row['tipo']; ?></td>
        </tr>
        <?php
    }
} else {
    ?>
        <tr>
            <td colspan="11" style="text-align: center;">No hay ventas registradas en el periodo seleccionado</td>
        </tr>
    <?php
}
?>
            <tr class="table-footer">
                <td colspan="2"><strong>TOTALES: <?php echo count($ventasData); ?> ventas</strong></td>
                <td colspan="9"></td>
            </tr>
        </tbody>
    </table>
</div>

<?php
// TABLA 2: TOP PRODUCTOS
$sqlTop = "SELECT 
                p.nombre as producto,
                SUM(v.cantidad_vendida) as total_vendido,
                SUM(v.cantidad_vendida * p.precio_venta) as total_ingresos
            FROM ventas v
            JOIN productos p ON v.id_producto = p.id
            WHERE DATE(v.fecha_venta) BETWEEN '$inicio' AND '$fin'
            GROUP BY v.id_producto, p.nombre
            ORDER BY total_vendido DESC
            LIMIT 10";

$resultTop = $conn->query($sqlTop);
?>

<div class="tabla-container">
    <h2>2. TOP 10 PRODUCTOS MAS VENDIDOS</h2>
    <table>
        <thead>
            <tr>
                <th width="8%">POSICION</th>
                <th width="52%">PRODUCTO</th>
                <th width="20%">UNIDADES VENDIDAS</th>
                <th width="20%">INGRESOS TOTALES</th>
            </tr>
        </thead>
        <tbody>
<?php
if ($resultTop && $resultTop->num_rows > 0) {
    $posicion = 1;
    while ($row = $resultTop->fetch_assoc()) {
        ?>
        <tr>
            <td class="numero"><?php echo $posicion; ?></td>
            <td><strong><?php echo $row['producto']; ?></strong></td>
            <td class="numero"><?php echo $row['total_vendido']; ?></td>
            <td class="numero">$<?php echo number_format($row['total_ingresos'], 2); ?></td>
        </tr>
        <?php
        $posicion++;
    }
} else {
    ?>
        <tr>
            <td colspan="4" style="text-align: center;">No hay datos disponibles</td>
        </tr>
    <?php
}
?>
        </tbody>
    </table>
</div>

<!-- TABLA 3: VENTAS POR METODO DE PAGO -->
<div class="tabla-container">
    <h2>3. VENTAS POR METODO DE PAGO</h2>
    <table>
        <thead>
            <tr>
                <th width="40%">METODO DE PAGO</th>
                <th width="30%">NUMERO DE VENTAS</th>
                <th width="30%">MONTO TOTAL</th>
            </tr>
        </thead>
        <tbody>
<?php
if (!empty($metodosPago)) {
    foreach ($metodosPago as $metodo => $datos) {
        ?>
        <tr>
            <td><strong><?php echo $metodo; ?></strong></td>
            <td class="numero"><?php echo $datos['cantidad']; ?></td>
            <td class="numero">$<?php echo number_format($datos['total'], 2); ?></td>
        </tr>
        <?php
    }
} else {
    ?>
        <tr>
            <td colspan="3" style="text-align: center;">No hay datos disponibles</td>
        </tr>
    <?php
}
?>
        </tbody>
    </table>
</div>

<!-- TABLA 4: VENTAS POR VENDEDOR -->
<div class="tabla-container">
    <h2>4. VENTAS POR VENDEDOR</h2>
    <table>
        <thead>
            <tr>
                <th width="40%">VENDEDOR</th>
                <th width="30%">NUMERO DE VENTAS</th>
                <th width="30%">MONTO TOTAL</th>
            </tr>
        </thead>
        <tbody>
<?php
if (!empty($ventasPorVendedor)) {
    foreach ($ventasPorVendedor as $vendedor => $datos) {
        ?>
        <tr>
            <td><strong><?php echo $vendedor; ?></strong></td>
            <td class="numero"><?php echo $datos['cantidad']; ?></td>
            <td class="numero">$<?php echo number_format($datos['total'], 2); ?></td>
        </tr>
        <?php
    }
} else {
    ?>
        <tr>
            <td colspan="3" style="text-align: center;">No hay datos disponibles</td>
        </tr>
    <?php
}
?>
        </tbody>
    </table>
</div>

<!-- TABLA 5: VENTAS POR TIPO -->
<div class="tabla-container">
    <h2>5. VENTAS POR TIPO</h2>
    <table>
        <thead>
            <tr>
                <th width="40%">TIPO DE VENTA</th>
                <th width="30%">NUMERO DE VENTAS</th>
                <th width="30%">PORCENTAJE</th>
            </tr>
        </thead>
        <tbody>
<?php
$tiposVenta = array(
    'Ventas directas' => ($totalVentas - $totalPedidos - $totalConteos),
    'Pedidos' => $totalPedidos,
    'Conteos rapidos' => $totalConteos
);
if ($totalVentas > 0) {
    foreach ($tiposVenta as $tipo => $cantidad) {
        $porcentaje = ($cantidad / $totalVentas) * 100;
        ?>
        <tr>
            <td><strong><?php echo $tipo; ?></strong></td>
            <td class="numero"><?php echo $cantidad; ?></td>
            <td class="numero"><?php echo number_format($porcentaje, 1); ?>%</td>
        </tr>
        <?php
    }
    ?>
    <tr class="total-row">
        <td><strong>TOTAL GENERAL</strong></td>
        <td class="numero"><strong><?php echo $totalVentas; ?></strong></td>
        <td class="numero"><strong>100%</strong></td>
    </tr>
    <?php
} else {
    ?>
        <tr>
            <td colspan="3" style="text-align: center;">No hay datos disponibles</td>
        </tr>
    <?php
}
?>
        </tbody>
    </table>
</div>

<?php
$conn->close();
?>

</body>
</html>