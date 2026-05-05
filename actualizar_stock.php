<?php
include 'includes/db.php';
session_start();

header('Content-Type: application/json');

// ======================= PRODUCTO ESPECIAL (PAGADO) =======================
// Este producto NO debe aparecer en la deuda con proveedores
define('PRODUCTO_ESPECIAL_NOMBRE', 'libretas');
define('PROVEEDOR_ESPECIAL', 'Nevaris 3D');

if (!isset($_POST['ventas'], $_POST['stock_final'])) {
    echo json_encode([
        'status' => 'error',
        'msg' => 'Datos incompletos'
    ]);
    exit;
}

$conn->begin_transaction();

try {
    // ================= OBTENER EL ÚLTIMO FOLIO DE VENTA_CONTEO =================
    // Buscar el último folio con formato 'Venta_conteo_X'
    $folioQuery = $conn->query("
        SELECT folio_ticket FROM ventas 
        WHERE folio_ticket LIKE 'Venta_conteo_%' 
        ORDER BY id DESC LIMIT 1
    ");
    
    $ultimoFolio = $folioQuery->fetch_assoc();
    $nuevoNumero = 1;
    
    if ($ultimoFolio) {
        // Extraer el número del folio
        preg_match('/Venta_conteo_(\d+)/', $ultimoFolio['folio_ticket'], $matches);
        if (isset($matches[1])) {
            $nuevoNumero = intval($matches[1]) + 1;
        }
    }
    
    $folioTicket = 'Venta_conteo_' . $nuevoNumero;
    $idVendedor = $_SESSION['usuario_id'] ?? null;
    
    // Variables para estadísticas (solo para la respuesta)
    $totalVentas = 0;
    $totalDeuda = 0;
    $totalGanancia = 0;
    $productosEspeciales = [];

    foreach ($_POST['ventas'] as $idProducto => $cantidadVendida) {

        $idProducto      = (int)$idProducto;
        $cantidadVendida = (int)$cantidadVendida;
        $stockFinal      = (int)$_POST['stock_final'][$idProducto];
        
        // Saltar productos sin ventas
        if ($cantidadVendida <= 0) continue;

        /* ===== PRODUCTO ===== */
        $q = $conn->prepare("
            SELECT proveedor, nombre, cantidad, precio_compra, precio_venta 
            FROM productos 
            WHERE id = ?
        ");
        $q->bind_param("i", $idProducto);
        $q->execute();
        $prod = $q->get_result()->fetch_assoc();

        if (!$prod) {
            throw new Exception('Producto no encontrado');
        }

        $proveedor      = $prod['proveedor'];
        $nombreProducto = $prod['nombre'];
        $stockInicial   = (int)$prod['cantidad'];
        $precioCompra   = (float)$prod['precio_compra'];
        $precioVenta    = (float)$prod['precio_venta'];

        // Verificar si es el producto especial (libretas de Nevaris 3D)
        $esEspecial = false;
        if (stripos($nombreProducto, PRODUCTO_ESPECIAL_NOMBRE) !== false && 
            stripos($proveedor, PROVEEDOR_ESPECIAL) !== false) {
            $esEspecial = true;
        }

        /* ===== REGISTRAR VENTA ===== */
        $stmt = $conn->prepare("
            INSERT INTO ventas
            (folio_ticket, id_producto, id_vendedor, cantidad_vendida, fecha_venta)
            VALUES (?, ?, ?, ?, NOW())
        ");

        $stmt->bind_param(
            "siii",
            $folioTicket,
            $idProducto,
            $idVendedor,
            $cantidadVendida
        );

        $stmt->execute();
        
        // Calcular montos (solo para la respuesta, no se guardan en BD)
        $montoVenta = $cantidadVendida * $precioVenta;
        $montoDeuda = $esEspecial ? 0 : ($cantidadVendida * $precioCompra);
        $montoGanancia = $montoVenta - $montoDeuda;
        
        $totalVentas += $montoVenta;
        $totalDeuda += $montoDeuda;
        $totalGanancia += $montoGanancia;
        
        if ($esEspecial) {
            $productosEspeciales[] = $nombreProducto;
        }

        /* ===== REPORTE POR PROVEEDOR (POR DÍA) ===== */
        $rep = $conn->prepare("
            INSERT INTO reporte_proveedor
            (proveedor, producto_id, stock_inicial, stock_contado, ventas, fecha_conteo)
            VALUES (?, ?, ?, ?, ?, CURDATE())
            ON DUPLICATE KEY UPDATE
                stock_contado = VALUES(stock_contado),
                ventas        = IFNULL(ventas, 0) + VALUES(ventas),
                stock_inicial = VALUES(stock_inicial)
        ");

        $rep->bind_param(
            "siiii",
            $proveedor,
            $idProducto,
            $stockInicial,
            $stockFinal,
            $cantidadVendida
        );

        $rep->execute();

        /* ===== ACTUALIZAR STOCK ===== */
        $up = $conn->prepare("
            UPDATE productos
            SET cantidad = ?
            WHERE id = ?
        ");
        $up->bind_param("ii", $stockFinal, $idProducto);
        $up->execute();
    }

    $conn->commit();

    // Preparar mensaje de respuesta
    $mensaje = "Conteo guardado correctamente.\n";
    $mensaje .= "Folio: $folioTicket\n";
    $mensaje .= "Total ventas: $" . number_format($totalVentas, 2) . "\n";
    $mensaje .= "Total deuda: $" . number_format($totalDeuda, 2) . "\n";
    $mensaje .= "Total ganancia: $" . number_format($totalGanancia, 2);
    
    if (!empty($productosEspeciales)) {
        $mensaje .= "\n\nProductos pagados (sin deuda): " . implode(', ', $productosEspeciales);
    }

    echo json_encode([
        'status' => 'ok',
        'folio'  => $folioTicket,
        'message' => $mensaje,
        'totales' => [
            'ventas' => $totalVentas,
            'deuda' => $totalDeuda,
            'ganancia' => $totalGanancia
        ],
        'productos_especiales' => $productosEspeciales,
        'folio_numero' => $nuevoNumero
    ]);

} catch (Exception $e) {
    $conn->rollback();
    echo json_encode([
        'status' => 'error',
        'msg' => 'Error al guardar: ' . $e->getMessage()
    ]);
}

$conn->close();
?>