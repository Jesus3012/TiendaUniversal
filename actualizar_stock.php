<?php
include 'includes/db.php';
session_start();

header('Content-Type: application/json');

if (!isset($_POST['ventas'], $_POST['stock_final'])) {
    echo json_encode([
        'status' => 'error',
        'msg' => 'Datos incompletos: faltan ventas o stock_final'
    ]);
    exit;
}

$conn->begin_transaction();

try {
    // Obtener el último folio
    $folioQuery = $conn->query("
        SELECT folio_ticket FROM ventas 
        WHERE folio_ticket LIKE 'Venta_conteo_%' 
        ORDER BY id DESC LIMIT 1
    ");
    
    $ultimoFolio = $folioQuery->fetch_assoc();
    $nuevoNumero = 1;
    
    if ($ultimoFolio) {
        preg_match('/Venta_conteo_(\d+)/', $ultimoFolio['folio_ticket'], $matches);
        if (isset($matches[1])) {
            $nuevoNumero = intval($matches[1]) + 1;
        }
    }
    
    $folioTicket = 'Venta_conteo_' . $nuevoNumero;
    $idVendedor = $_SESSION['usuario_id'] ?? null;
    
    // Obtener nombre del vendedor
    $nombreVendedor = 'Sistema';
    if ($idVendedor) {
        $vQuery = $conn->prepare("SELECT nombre FROM usuarios WHERE id = ?");
        $vQuery->bind_param("i", $idVendedor);
        $vQuery->execute();
        $vResult = $vQuery->get_result();
        if ($vRow = $vResult->fetch_assoc()) {
            $nombreVendedor = $vRow['nombre'];
        }
    }
    
    $totalVentas = 0;
    $totalDeuda = 0;
    $totalGanancia = 0;
    $productosActualizados = 0;
    $registrosVenta = 0;
    $detalleProductos = [];

    foreach ($_POST['ventas'] as $idProducto => $cantidadVendida) {

        $idProducto = (int)$idProducto;
        $cantidadVendida = (int)$cantidadVendida;
        
        if (!isset($_POST['stock_final'][$idProducto])) {
            continue;
        }
        
        $stockFinal = (int)$_POST['stock_final'][$idProducto];
        
        if ($cantidadVendida <= 0) continue;

        // Obtener datos del producto
        $q = $conn->prepare("
            SELECT proveedor, nombre, cantidad, precio_compra, precio_venta, tipo_adquisicion 
            FROM productos 
            WHERE id = ? AND activo = 1
        ");
        $q->bind_param("i", $idProducto);
        $q->execute();
        $result = $q->get_result();
        
        if ($result->num_rows === 0) {
            continue;
        }
        
        $prod = $result->fetch_assoc();

        $proveedor = $prod['proveedor'];
        $nombreProducto = $prod['nombre'];
        $stockInicial = (int)$prod['cantidad'];
        $precioCompra = (float)$prod['precio_compra'];
        $precioVenta = (float)$prod['precio_venta'];
        $tipoAdquisicion = $prod['tipo_adquisicion'] ?? 'concesion';
        
        $esPagado = ($tipoAdquisicion === 'pagado');
        $tipoTexto = $esPagado ? 'Pagado' : 'Concesion';

        // Registrar venta
        $fechaActual = date('Y-m-d H:i:s');
        $stmt = $conn->prepare("
            INSERT INTO ventas
            (folio_ticket, id_producto, id_vendedor, cantidad_vendida, fecha_venta)
            VALUES (?, ?, ?, ?, ?)
        ");

        if (!$stmt) {
            throw new Exception('Error en prepare: ' . $conn->error);
        }

        $stmt->bind_param("siiis", $folioTicket, $idProducto, $idVendedor, $cantidadVendida, $fechaActual);
        
        if (!$stmt->execute()) {
            throw new Exception('Error al insertar venta: ' . $stmt->error);
        }
        
        $registrosVenta++;
        
        // Calcular montos
        $montoVenta = $cantidadVendida * $precioVenta;
        $montoDeuda = $esPagado ? 0 : ($cantidadVendida * $precioCompra);
        $montoGanancia = $montoVenta - $montoDeuda;
        
        $totalVentas += $montoVenta;
        $totalDeuda += $montoDeuda;
        $totalGanancia += $montoGanancia;
        $productosActualizados++;
        
        // Guardar detalle
        $detalleProductos[] = [
            'nombre' => $nombreProducto,
            'cantidad' => $cantidadVendida,
            'precio' => $precioVenta,
            'subtotal' => $montoVenta,
            'tipo' => $tipoTexto
        ];

        // Reporte por proveedor
        $checkTable = $conn->query("SHOW TABLES LIKE 'reporte_proveedor'");
        if ($checkTable->num_rows > 0) {
            $rep = $conn->prepare("
                INSERT INTO reporte_proveedor
                (proveedor, producto_id, stock_inicial, stock_contado, ventas, fecha_conteo)
                VALUES (?, ?, ?, ?, ?, CURDATE())
                ON DUPLICATE KEY UPDATE
                    stock_contado = VALUES(stock_contado),
                    ventas = ventas + VALUES(ventas)
            ");

            $rep->bind_param("siiii", $proveedor, $idProducto, $stockInicial, $stockFinal, $cantidadVendida);
            $rep->execute();
        }

        // Actualizar stock
        $up = $conn->prepare("UPDATE productos SET cantidad = ? WHERE id = ?");
        $up->bind_param("ii", $stockFinal, $idProducto);
        
        if (!$up->execute()) {
            throw new Exception('Error al actualizar stock: ' . $up->error);
        }
    }
    
    if ($registrosVenta === 0) {
        $conn->rollback();
        echo json_encode([
            'status' => 'error',
            'msg' => 'No se encontraron productos con ventas para actualizar. Verifica que hayas modificado el stock contado.'
        ]);
        exit;
    }

    $conn->commit();

    // Construir mensaje elegante para SweetAlert
    $fechaHora = date('d/m/Y H:i:s');
    
    $htmlMensaje = '
    <div style="text-align: left; font-family: \'Segoe UI\', Tahoma, Geneva, Verdana, sans-serif;">
        <div style="background: #f0fdf4; border-left: 4px solid #22c55e; padding: 12px; margin-bottom: 16px; border-radius: 8px;">
            <strong style="color: #166534;">Folio:</strong> <span style="color: #14532d;">' . $folioTicket . '</span><br>
            <strong style="color: #166534;">Fecha:</strong> <span style="color: #14532d;">' . $fechaHora . '</span><br>
            <strong style="color: #166534;">Vendedor:</strong> <span style="color: #14532d;">' . htmlspecialchars($nombreVendedor) . '</span>
        </div>
        
        <div style="margin-bottom: 16px;">
            <div style="font-weight: 600; color: #1e293b; border-bottom: 2px solid #e2e8f0; padding-bottom: 8px; margin-bottom: 12px;">
                Detalle de productos
            </div>
            <table style="width: 100%; font-size: 13px; border-collapse: collapse;">
                <thead>
                    <tr style="background: #f8fafc; border-bottom: 1px solid #e2e8f0;">
                        <th style="text-align: left; padding: 8px;">Producto</th>
                        <th style="text-align: center; padding: 8px;">Cant</th>
                        <th style="text-align: right; padding: 8px;">Precio</th>
                        <th style="text-align: right; padding: 8px;">Subtotal</th>
                    </tr>
                </thead>
                <tbody>';
    
    foreach ($detalleProductos as $item) {
        $htmlMensaje .= '
                    <tr style="border-bottom: 1px solid #f1f5f9;">
                        <td style="padding: 8px;">' . htmlspecialchars($item['nombre']) . '<br><small style="color: #64748b;">' . $item['tipo'] . '</small></td>
                        <td style="text-align: center; padding: 8px;">' . $item['cantidad'] . '</td>
                        <td style="text-align: right; padding: 8px;">$ ' . number_format($item['precio'], 2) . '</td>
                        <td style="text-align: right; padding: 8px;">$ ' . number_format($item['subtotal'], 2) . '</td>
                    </tr>';
    }
    
    $htmlMensaje .= '
                </tbody>
            </table>
        </div>
        
        <div style="background: #f8fafc; border-radius: 8px; padding: 12px; margin-top: 16px;">
            <div style="display: flex; justify-content: space-between; margin-bottom: 8px;">
                <span style="color: #475569;">Total Ventas:</span>
                <strong style="color: #16a34a;">$ ' . number_format($totalVentas, 2) . '</strong>
            </div>
            <div style="display: flex; justify-content: space-between; margin-bottom: 8px;">
                <span style="color: #475569;">Total Deuda (Costo):</span>
                <strong style="color: #dc2626;">$ ' . number_format($totalDeuda, 2) . '</strong>
            </div>
            <div style="display: flex; justify-content: space-between; padding-top: 8px; border-top: 1px solid #e2e8f0;">
                <span style="color: #475569; font-weight: 600;">Total Ganancia:</span>
                <strong style="color: #16a34a; font-size: 16px;">$ ' . number_format($totalGanancia, 2) . '</strong>
            </div>
        </div>
        
        <div style="margin-top: 16px; padding-top: 12px; border-top: 1px solid #e2e8f0; font-size: 12px; color: #64748b; text-align: center;">
            Productos actualizados: ' . $productosActualizados . ' | Registros: ' . $registrosVenta . '
        </div>
    </div>';

    echo json_encode([
        'status' => 'ok',
        'folio' => $folioTicket,
        'htmlMessage' => $htmlMensaje,
        'message' => "Venta registrada correctamente.\nFolio: $folioTicket\nTotal: $" . number_format($totalVentas, 2),
        'totales' => [
            'ventas' => $totalVentas,
            'deuda' => $totalDeuda,
            'ganancia' => $totalGanancia
        ],
        'productos_actualizados' => $productosActualizados,
        'registros_venta' => $registrosVenta
    ]);

} catch (Exception $e) {
    $conn->rollback();
    error_log("ERROR en actualizar_stock: " . $e->getMessage());
    echo json_encode([
        'status' => 'error',
        'msg' => 'Error al guardar: ' . $e->getMessage()
    ]);
}

$conn->close();
?>