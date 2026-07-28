<?php
/**
 * Funciones reutilizables para aplicar promociones por cantidad.
 * Compatible con PHP 7.4+.
 */

if (!function_exists('promociones_fecha_valida')) {
    function promociones_fecha_valida($fecha)
    {
        $fecha = trim((string) $fecha);

        if ($fecha === '') {
            return true;
        }

        $objeto = DateTime::createFromFormat('Y-m-d', $fecha);

        return $objeto instanceof DateTime
            && $objeto->format('Y-m-d') === $fecha;
    }
}

if (!function_exists('promociones_obtener_activa_producto')) {
    function promociones_obtener_activa_producto($conn, $productoId, $fecha = null)
    {
        $productoId = (int) $productoId;
        $fecha = $fecha ?: date('Y-m-d');

        if ($productoId <= 0 || !promociones_fecha_valida($fecha)) {
            return null;
        }

        $sql = "
            SELECT
                pr.id,
                pr.producto_id,
                pr.cantidad_promocion,
                pr.precio_promocion,
                pr.fecha_inicio,
                pr.fecha_fin,
                pr.activo,
                p.nombre AS producto_nombre,
                p.precio_venta,
                p.stock_especial
            FROM promociones pr
            INNER JOIN productos p
                ON p.id = pr.producto_id
            WHERE pr.producto_id = ?
              AND pr.activo = 1
              AND pr.eliminado = 0
              AND p.activo = 1
              AND p.tipo_inventario = 'producto'
              AND (pr.fecha_inicio IS NULL OR pr.fecha_inicio <= ?)
              AND (pr.fecha_fin IS NULL OR pr.fecha_fin >= ?)
            ORDER BY pr.updated_at DESC, pr.id DESC
            LIMIT 1
        ";

        $stmt = $conn->prepare($sql);

        if (!$stmt) {
            return null;
        }

        $stmt->bind_param('iss', $productoId, $fecha, $fecha);
        $stmt->execute();
        $resultado = $stmt->get_result();
        $promocion = $resultado ? $resultado->fetch_assoc() : null;
        $stmt->close();

        return $promocion ?: null;
    }
}

if (!function_exists('promociones_calcular_linea')) {
    function promociones_calcular_linea($conn, $productoId, $cantidad, $precioUnitario = null)
    {
        $productoId = (int) $productoId;
        $cantidad = (int) $cantidad;

        $respuesta = [
            'producto_id' => $productoId,
            'cantidad' => max(0, $cantidad),
            'precio_unitario' => 0.0,
            'subtotal_base' => 0.0,
            'subtotal_final' => 0.0,
            'ahorro' => 0.0,
            'descuento_porcentaje' => 0.0,
            'aplico_promocion' => false,
            'promocion_id' => null,
            'cantidad_promocion' => 0,
            'precio_promocion' => 0.0,
            'paquetes_promocion' => 0,
            'unidades_sin_promocion' => max(0, $cantidad),
            'descripcion' => '',
        ];

        if ($productoId <= 0 || $cantidad <= 0) {
            return $respuesta;
        }

        $producto = null;

        if ($precioUnitario === null) {
            $stmt = $conn->prepare("
                SELECT id, nombre, precio_venta, activo, tipo_inventario
                FROM productos
                WHERE id = ?
                LIMIT 1
            ");

            if ($stmt) {
                $stmt->bind_param('i', $productoId);
                $stmt->execute();
                $resultado = $stmt->get_result();
                $producto = $resultado ? $resultado->fetch_assoc() : null;
                $stmt->close();
            }

            $precioUnitario = $producto
                ? (float) ($producto['precio_venta'] ?? 0)
                : 0.0;
        } else {
            $precioUnitario = (float) $precioUnitario;
        }

        $subtotalBase = round($precioUnitario * $cantidad, 2);

        $respuesta['precio_unitario'] = $precioUnitario;
        $respuesta['subtotal_base'] = $subtotalBase;
        $respuesta['subtotal_final'] = $subtotalBase;

        if ($precioUnitario <= 0) {
            return $respuesta;
        }

        $promocion = promociones_obtener_activa_producto($conn, $productoId);

        if (!$promocion) {
            return $respuesta;
        }

        $cantidadPromocion = (int) ($promocion['cantidad_promocion'] ?? 0);
        $precioPromocion = (float) ($promocion['precio_promocion'] ?? 0);
        $precioRegularPaquete = round($precioUnitario * $cantidadPromocion, 2);

        // Una promoción deja de aplicarse automáticamente si ya no representa ahorro.
        if (
            $cantidadPromocion < 2
            || $precioPromocion <= 0
            || $precioPromocion >= $precioRegularPaquete
            || $cantidad < $cantidadPromocion
        ) {
            return $respuesta;
        }

        $paquetes = intdiv($cantidad, $cantidadPromocion);
        $restantes = $cantidad % $cantidadPromocion;
        $subtotalFinal = round(
            ($paquetes * $precioPromocion)
            + ($restantes * $precioUnitario),
            2
        );
        $ahorro = max(0, round($subtotalBase - $subtotalFinal, 2));
        $porcentaje = $subtotalBase > 0
            ? round(($ahorro / $subtotalBase) * 100, 2)
            : 0.0;

        $respuesta['subtotal_final'] = $subtotalFinal;
        $respuesta['ahorro'] = $ahorro;
        $respuesta['descuento_porcentaje'] = $porcentaje;
        $respuesta['aplico_promocion'] = $ahorro > 0;
        $respuesta['promocion_id'] = (int) $promocion['id'];
        $respuesta['cantidad_promocion'] = $cantidadPromocion;
        $respuesta['precio_promocion'] = $precioPromocion;
        $respuesta['paquetes_promocion'] = $paquetes;
        $respuesta['unidades_sin_promocion'] = $restantes;
        $respuesta['descripcion'] = sprintf(
            '%d por $%s',
            $cantidadPromocion,
            number_format($precioPromocion, 2, '.', ',')
        );

        return $respuesta;
    }
}

if (!function_exists('promociones_calcular_carrito')) {
    function promociones_calcular_carrito($conn, array $items)
    {
        $lineas = [];
        $subtotalBase = 0.0;
        $subtotalFinal = 0.0;
        $ahorro = 0.0;

        foreach ($items as $item) {
            $productoId = (int) ($item['id'] ?? $item['producto_id'] ?? 0);
            $cantidad = (int) ($item['cantidad'] ?? 0);

            if ($productoId <= 0 || $cantidad <= 0) {
                continue;
            }

            // El precio siempre se toma desde la base de datos.
            $linea = promociones_calcular_linea(
                $conn,
                $productoId,
                $cantidad,
                null
            );

            $lineas[] = $linea;
            $subtotalBase += (float) $linea['subtotal_base'];
            $subtotalFinal += (float) $linea['subtotal_final'];
            $ahorro += (float) $linea['ahorro'];
        }

        return [
            'lineas' => $lineas,
            'subtotal_base' => round($subtotalBase, 2),
            'subtotal_final' => round($subtotalFinal, 2),
            'ahorro_total' => round($ahorro, 2),
        ];
    }
}
