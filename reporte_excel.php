<?php
date_default_timezone_set('America/Mexico_City');
session_start();
include 'includes/db.php';
include 'guardar_historial_reporte.php';

// ======================= PRODUCTO ESPECIAL (PAGADO) =======================
// Este producto NO debe aparecer en la deuda con proveedores
define('PRODUCTO_ESPECIAL_NOMBRE', 'libretas');
define('PROVEEDOR_ESPECIAL', 'Nevaris 3D');

if (!isset($_SESSION['usuario_id'])) {
    die('Acceso no autorizado');
}

$usuario_id = $_SESSION['usuario_id'];
$usuario_nombre = $_SESSION['usuario_nombre'] ?? 'Usuario';

$proveedor = $_GET['proveedor'] ?? '';
$fecha_inicio = $_GET['fecha_inicio'] ?? '';
$fecha_fin = $_GET['fecha_fin'] ?? '';

if (!$proveedor || !$fecha_inicio || !$fecha_fin) {
    die('Parámetros incompletos');
}

if (strtotime($fecha_fin) < strtotime($fecha_inicio)) {
    die('La fecha fin no puede ser menor que la fecha inicio');
}

$fecha_inicio_full = $fecha_inicio;
$fecha_fin_full = $fecha_fin;

// Crear carpeta para reportes si no existe
$carpeta_reportes = 'uploads/reportes_proveedor';
if (!file_exists($carpeta_reportes)) {
    mkdir($carpeta_reportes, 0777, true);
}

// Obtener datos para totales (con detección de producto especial)
$query_datos = "
    SELECT 
        rp.ventas,
        p.precio_compra,
        p.nombre,
        (rp.ventas * p.precio_compra) as total_costo,
        CASE 
            WHEN LOWER(p.nombre) LIKE LOWER(?) 
                 AND LOWER(p.proveedor) LIKE LOWER(?) 
            THEN 1
            ELSE 0
        END AS es_producto_especial
    FROM reporte_proveedor rp
    INNER JOIN productos p ON rp.producto_id = p.id
    WHERE rp.proveedor = ?
    AND rp.fecha_conteo BETWEEN ? AND ?
";

$likeNombre = '%' . PRODUCTO_ESPECIAL_NOMBRE . '%';
$likeProveedor = '%' . PROVEEDOR_ESPECIAL . '%';

$stmt = $conn->prepare($query_datos);
$stmt->bind_param("sssss", $likeNombre, $likeProveedor, $proveedor, $fecha_inicio_full, $fecha_fin_full);
$stmt->execute();
$result_datos = $stmt->get_result();

$total_registros = $result_datos->num_rows;
$total_costos_historial = 0;
$total_unidades_vendidas = 0;
$productos_especiales_contados = 0;
$productos_especiales_lista = [];

while ($row = $result_datos->fetch_assoc()) {
    // Solo sumar costos si NO es producto especial
    if (!$row['es_producto_especial']) {
        $total_costos_historial += $row['total_costo'];
    } else {
        $productos_especiales_contados++;
        $productos_especiales_lista[] = $row['nombre'];
    }
    $total_unidades_vendidas += $row['ventas'];
}

// Consulta principal para detalle (CON detección de producto especial)
$query = "
    SELECT 
        p.nombre as producto,
        rp.stock_inicial,
        rp.ventas,
        rp.fecha_conteo,
        (rp.stock_inicial - rp.ventas) as stock_restante,
        p.precio_compra,
        (rp.ventas * p.precio_compra) as costo_total,
        CASE 
            WHEN LOWER(p.nombre) LIKE LOWER(?) 
                 AND LOWER(p.proveedor) LIKE LOWER(?) 
            THEN 1
            ELSE 0
        END AS es_producto_especial
    FROM reporte_proveedor rp
    INNER JOIN productos p ON rp.producto_id = p.id
    WHERE rp.proveedor = ?
    AND rp.fecha_conteo BETWEEN ? AND ?
    ORDER BY 
        es_producto_especial ASC,
        rp.fecha_conteo DESC, 
        p.nombre ASC
";

$stmt = $conn->prepare($query);
$stmt->bind_param("sssss", $likeNombre, $likeProveedor, $proveedor, $fecha_inicio_full, $fecha_fin_full);
$stmt->execute();
$result = $stmt->get_result();

// Guardar en historial
$nombre_archivo = 'reporte_' . $proveedor . '_' . $fecha_inicio . '_a_' . $fecha_fin . '_' . date('Ymd_His') . '.xls';
$ruta_completa = $carpeta_reportes . '/' . $nombre_archivo;

$historial_data = [
    'usuario_id' => $usuario_id,
    'usuario_nombre' => $usuario_nombre,
    'tipo_reporte' => 'excel',
    'proveedor' => $proveedor,
    'fecha_generacion' => date('Y-m-d H:i:s'),
    'total_registros' => $total_registros,
    'nombre_archivo' => $nombre_archivo
];

guardarHistorialReporte($conn, $historial_data);

// Iniciar buffer de salida
ob_start();

// Función para formatear moneda
function formatoMoneda($cantidad) {
    return '$' . number_format($cantidad, 2);
}

// Función para formatear fecha
function formatoFecha($fecha) {
    return date('d/m/Y', strtotime($fecha));
}

// Generar Excel con el mismo diseño que el PDF modificado
echo "<html>";
echo "<head>";
echo "<meta charset='UTF-8'>";
echo "<style>";
echo "body { font-family: Arial, sans-serif; margin: 20px; }";

// Encabezados principales
echo "h1 { color: #333; font-size: 24px; text-align: center; margin-bottom: 5px; }";
echo "h2 { color: #2980b9; font-size: 18px; text-align: center; margin-top: 0; }";

// Cuadro de período (como en PDF)
echo ".periodo-box { border: 1px solid #bdc3c7; margin: 10px 0; padding: 10px; text-align: center; }";
echo ".periodo-text { color: #333; font-size: 12px; }";

// Títulos de sección (como en PDF)
echo ".section-title { background-color: #2980b9; color: white; padding: 8px 15px; font-size: 16px; font-weight: bold; margin: 20px 0 10px 0; }";

// Estilos para tablas
echo "table { border-collapse: collapse; width: 100%; margin: 15px 0; font-size: 11px; }";
echo "th { background-color: #34495e; color: white; padding: 8px; text-align: center; font-weight: bold; border: 1px solid #34495e; }";
echo "td { padding: 6px; border: 1px solid #bdc3c7; }";
echo ".fila-par { background-color: #f8f9fa; }";
echo ".fila-impar { background-color: #ffffff; }";

// Estilo para productos especiales
echo ".producto-especial td { background-color: #d4edda !important; }";
echo ".pagado-badge { color: #28a745; font-weight: bold; font-style: italic; }";

// Total row (como en PDF)
echo ".total-row { background-color: #e9ecef; font-weight: bold; }";
echo ".total-label { text-align: right; padding-right: 10px; }";
echo ".total-value { background-color: #e9ecef; font-weight: bold; text-align: right; }";

// Alineaciones
echo ".numero { text-align: right; }";
echo ".centro { text-align: center; }";
echo ".izquierda { text-align: left; }";

// Mensaje sin datos
echo ".no-data { background-color: #fff3cd; color: #856404; text-align: center; padding: 15px; border: 1px solid #ffeeba; }";

// Resumen de productos especiales
echo ".resumen-especiales { background-color: #d4edda; border: 1px solid #c3e6cb; padding: 10px; margin: 10px 0; }";
echo ".resumen-especiales h4 { color: #155724; margin: 0 0 5px 0; }";
echo ".resumen-especiales p { color: #155724; margin: 0; font-size: 11px; }";

// Footer
echo ".footer { color: #7f8c8d; font-size: 10px; text-align: center; margin-top: 20px; font-style: italic; }";
echo "</style>";
echo "</head>";
echo "<body>";

// Formatear fecha para el encabezado
$fecha_encabezado = formatoFecha($fecha_inicio);

/* ================= ENCABEZADO ================= */
echo "<h1>REPORTE DE INVENTARIO</h1>";
echo "<h2>PROVEEDOR: " . strtoupper(htmlspecialchars($proveedor)) . "</h2>";

// Cuadro de período
echo "<div class='periodo-box'>";
echo "<div class='periodo-text'><strong>Período:</strong> " . formatoFecha($fecha_inicio) . " al " . formatoFecha($fecha_fin) . "</div>";
echo "<div class='periodo-text'><strong>Generado:</strong> " . date('d/m/Y H:i') . "</div>";
echo "</div>";

/* ================= DETALLE DE INVENTARIO POR PRODUCTO ================= */
echo "<div class='section-title'>DETALLE DE INVENTARIO POR PRODUCTO</div>";

echo "<table>";
echo "<tr>";
echo "<th style='width:55mm;'>Producto</th>";
echo "<th style='width:35mm;'>Stock Inicial</th>";
echo "<th style='width:35mm;'>Ventas</th>";
echo "<th style='width:45mm;'>Stock Restante<br><span style='font-size:9px;'>" . $fecha_encabezado . "</span></th>";
echo "<th style='width:35mm;'>Precio Compra</th>";
echo "<th style='width:45mm;'>Costo Total</th>";
echo "</tr>";

if ($result->num_rows == 0) {
    echo "<tr><td colspan='6' class='no-data'>⚠ No hay registros en el período seleccionado</td></tr>";
} else {
    $fill = false;
    $total_general_costos = 0;
    $total_ventas_con_deuda = 0;
    $productos_especiales_mostrados = [];
    
    while($row = $result->fetch_assoc()){
        // Si es producto especial, el costo es 0
        $costo_total = $row['es_producto_especial'] ? 0 : ($row['ventas'] * $row['precio_compra']);
        
        if (!$row['es_producto_especial']) {
            $total_general_costos += $costo_total;
            $total_ventas_con_deuda += $row['ventas'];
            $fila_class = $fill ? 'fila-par' : 'fila-impar';
        } else {
            $fila_class = 'producto-especial';
            $productos_especiales_mostrados[] = $row['producto'];
        }
        
        echo "<tr class='$fila_class'>";
        
        // Producto (con indicador si es especial)
        $nombre_producto = htmlspecialchars(substr($row['producto'], 0, 28));
        if ($row['es_producto_especial']) {
            $nombre_producto = "✓ " . $nombre_producto . " (PAGADO)";
        }
        echo "<td class='izquierda'>" . $nombre_producto . "</td>";
        
        echo "<td class='centro'>" . $row['stock_inicial'] . "</td>";
        echo "<td class='centro'>" . $row['ventas'] . "</td>";
        echo "<td class='centro'>" . $row['stock_restante'] . "</td>";
        echo "<td class='numero'>" . formatoMoneda($row['precio_compra']) . "</td>";
        
        // Costo total o PAGADO
        if ($row['es_producto_especial']) {
            echo "<td class='centro pagado-badge'>PAGADO</td>";
        } else {
            echo "<td class='numero'>" . formatoMoneda($costo_total) . "</td>";
        }
        
        echo "</tr>";
        
        $fill = !$fill;
    }
    
    echo "</table>";
    
    // Resumen de productos especiales si existen
    if (!empty($productos_especiales_mostrados)) {
        echo "<div class='resumen-especiales'>";
        echo "<h4>✓ Productos pagados por adelantado:</h4>";
        echo "<p>" . htmlspecialchars(implode(', ', array_unique($productos_especiales_mostrados))) . "</p>";
        echo "</div>";
    }
    
    // Fila de total
    echo "<table style='margin-top:5px;'>";
    echo "<tr class='total-row'>";
    echo "<td colspan='4' class='total-label'></td>";
    echo "<td class='total-label' style='background-color:#e9ecef;'>DEUDA TOTAL</td>";
    echo "<td class='total-value'>" . formatoMoneda($total_general_costos) . "</td>";
    echo "</tr>";
    echo "</table>";
    
    // Información adicional
    echo "<div style='margin-top:10px; font-size:10px; color:#7f8c8d;'>";
    echo "<p><i>Nota: Los productos marcados con ✓ están pagados por adelantado y no generan deuda.</i></p>";
    if ($productos_especiales_contados > 0) {
        echo "<p><i>Se excluyeron {$productos_especiales_contados} productos especiales del cálculo de deuda.</i></p>";
    }
    echo "</div>";
}

echo "<div class='footer'>Reporte generado automáticamente - Sistema Tienda Pescadores</div>";

echo "</body>";
echo "</html>";

// Obtener el contenido del buffer
$contenido_excel = ob_get_clean();

// Guardar el archivo en la carpeta
file_put_contents($ruta_completa, $contenido_excel);

// Enviar al navegador para descarga
header('Content-Type: application/vnd.ms-excel');
header('Content-Disposition: attachment; filename="' . $nombre_archivo . '"');
header('Content-Length: ' . strlen($contenido_excel));
header('Cache-Control: max-age=0');

echo $contenido_excel;
?>