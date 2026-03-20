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

    /* ================= FOLIO ================= */
    $folioTicket = 'VENTA_' . uniqid();
    $idVendedor = $_SESSION['id_usuario'] ?? null;
    
    // Variables para estadísticas (solo para la respuesta)
    $totalVentas = 0;
    $totalDeuda = 0;
    $totalGanancia = 0;
    $productosEspeciales = [];

    foreach ($_POST['ventas'] as $idProducto => $cantidadVendida) {

        $idProducto      = (int)$idProducto;
        $cantidadVendida = (int)$cantidadVendida;
        $stockFinal      = (int)$_POST['stock_final'][$idProducto];

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

        $proveedor     = $prod['proveedor'];
        $nombreProducto = $prod['nombre'];
        $stockInicial  = (int)$prod['cantidad'];
        $precioCompra  = (float)$prod['precio_compra'];
        $precioVenta   = (float)$prod['precio_venta'];

        // Verificar si es el producto especial (libretas de Nevaris 3D)
        $esEspecial = false;
        if (stripos($nombreProducto, PRODUCTO_ESPECIAL_NOMBRE) !== false && 
            stripos($proveedor, PROVEEDOR_ESPECIAL) !== false) {
            $esEspecial = true;
        }

        /* ===== REGISTRAR VENTA ===== */
        if ($cantidadVendida > 0) {

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
        }

        /* ===== REPORTE POR PROVEEDOR (POR DÍA) ===== */
        // NOTA: Solo guardamos cantidad de ventas, no montos
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
    $mensaje = "Ventas guardadas correctamente.\n";
    $mensaje .= "Total ventas: $" . number_format($totalVentas, 2) . "\n";
    $mensaje .= "Total deuda: $" . number_format($totalDeuda, 2) . "\n";
    $mensaje .= "Total ganancia: $" . number_format($totalGanancia, 2);
    
    if (!empty($productosEspeciales)) {
        $mensaje .= "\n\n✅ Productos pagados (sin deuda): " . implode(', ', $productosEspeciales);
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
        'productos_especiales' => $productosEspeciales
    ]);

} catch (Exception $e) {

    $conn->rollback();

    echo json_encode([
        'status' => 'error',
        'msg' => 'Error al guardar: ' . $e->getMessage()
    ]);
}
?>