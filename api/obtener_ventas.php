<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/ventas_reembolsos_helper.php';

function ov_bind(mysqli_stmt $stmt, string $tipos, array &$valores): void
{
    if ($tipos === '' || $valores === []) {
        return;
    }

    $refs = [$tipos];
    foreach ($valores as &$valor) {
        $refs[] = &$valor;
    }

    $stmt->bind_param(...$refs);
}

$usuarioId = (int) ($_SESSION['usuario_id'] ?? 0);
$rol = mb_strtolower(trim((string) ($_SESSION['rol'] ?? '')), 'UTF-8');

if ($usuarioId <= 0) {
    vrh_responder([
        'success' => false,
        'message' => 'Sesión no válida.',
    ], 401);
}

if (!in_array($rol, ['vendedor', 'administrador', 'super_administrador'], true)) {
    vrh_responder([
        'success' => false,
        'message' => 'No tienes permiso para consultar ventas.',
    ], 403);
}

$global = trim((string) ($_GET['global'] ?? ''));
$inicio = trim((string) ($_GET['inicio'] ?? ''));
$fin = trim((string) ($_GET['fin'] ?? ''));
$filtroEstado = mb_strtolower(
    trim((string) ($_GET['estado'] ?? '')),
    'UTF-8'
);

if (!in_array(
    $filtroEstado,
    ['', 'pendiente', 'completada', 'parcial', 'cancelada'],
    true
)) {
    $filtroEstado = '';
}

/*
|--------------------------------------------------------------------------
| Cálculos de importes y cantidades vigentes
|--------------------------------------------------------------------------
*/
$precio = "COALESCE(NULLIF(v.precio_unitario, 0), p.precio_venta)";
$cancelado = "CASE
    WHEN COALESCE(vc.cancelacion_total, 0) = 1
        THEN v.cantidad_vendida
    ELSE COALESCE(vc.cantidad_cancelada, 0)
END";
$neta = "GREATEST(
    v.cantidad_vendida
    - COALESCE(dp.cantidad_devuelta, 0)
    - ({$cancelado}),
    0
)";
$importeOriginal = "v.cantidad_vendida * {$precio}";
$importeNeto = "({$neta}) * {$precio}";

/*
 * Las ventas históricas generadas por conteo sí tienen fecha_venta, pero
 * varias quedaron sin metodo_pago. Para no perder la información visual,
 * se usa efectivo como respaldo únicamente cuando el folio es Venta_conteo_.
 * La migración incluida guarda ese mismo valor en la base de datos.
 */
$metodoPagoFila = "CASE
    WHEN NULLIF(TRIM(v.metodo_pago), '') IS NOT NULL
        THEN TRIM(v.metodo_pago)
    WHEN COALESCE(v.folio_ticket, '') LIKE 'Venta_conteo_%'
        THEN 'efectivo'
    ELSE ''
END";

$metodoPagoGrupo = "CASE
    WHEN MAX(NULLIF(TRIM(v.metodo_pago), '')) IS NOT NULL
        THEN MAX(NULLIF(TRIM(v.metodo_pago), ''))
    WHEN MAX(
        CASE
            WHEN COALESCE(v.folio_ticket, '') LIKE 'Venta_conteo_%'
                THEN 1
            ELSE 0
        END
    ) = 1
        THEN 'efectivo'
    ELSE ''
END";

$metodoPagoInferidoGrupo = "CASE
    WHEN MAX(NULLIF(TRIM(v.metodo_pago), '')) IS NULL
     AND MAX(
        CASE
            WHEN COALESCE(v.folio_ticket, '') LIKE 'Venta_conteo_%'
                THEN 1
            ELSE 0
        END
     ) = 1
        THEN 1
    ELSE 0
END";

$where = [];
$tipos = '';
$parametros = [];

if ($rol === 'vendedor') {
    $where[] = 'v.id_vendedor = ?';
    $tipos .= 'i';
    $parametros[] = $usuarioId;
}

if ($global !== '') {
    $like = '%' . $global . '%';
    $where[] = "(
        COALESCE(v.folio_ticket, '') LIKE ?
        OR COALESCE(v.correo_cliente, '') LIKE ?
        OR COALESCE(p.nombre, '') LIKE ?
        OR COALESCE(u.nombre, '') LIKE ?
        OR ({$metodoPagoFila}) LIKE ?
        OR COALESCE(v.referencia_pago, '') LIKE ?
    )";
    $tipos .= 'ssssss';
    array_push(
        $parametros,
        $like,
        $like,
        $like,
        $like,
        $like,
        $like
    );
}

if ($inicio !== '') {
    $where[] = 'DATE(v.fecha_venta) >= ?';
    $tipos .= 's';
    $parametros[] = $inicio;
}

if ($fin !== '') {
    $where[] = 'DATE(v.fecha_venta) <= ?';
    $tipos .= 's';
    $parametros[] = $fin;
}

$whereSql = $where !== []
    ? 'WHERE ' . implode(' AND ', $where)
    : '';

/*
|--------------------------------------------------------------------------
| Pedidos opcionales
|--------------------------------------------------------------------------
*/
$pedidoJoin = '';
$pedidoSelect = 'NULL AS estado_pedido';

if (vrh_tabla_existe($conn, 'pedidos')) {
    $pedidoJoin = "
        LEFT JOIN (
            SELECT
                id_orden,
                CASE
                    WHEN SUM(estado = 'cancelado') = COUNT(*)
                        THEN 'cancelado'
                    WHEN SUM(estado = 'completado') = COUNT(*)
                        THEN 'completado'
                    ELSE 'pendiente'
                END AS estado_pedido
            FROM pedidos
            GROUP BY id_orden
        ) ped ON ped.id_orden = v.id_orden
    ";

    $pedidoSelect = 'MAX(ped.estado_pedido) AS estado_pedido';
}

/*
|--------------------------------------------------------------------------
| Consulta principal
|--------------------------------------------------------------------------
*/
$sql = "
    SELECT
        COALESCE(
            NULLIF(v.folio_ticket, ''),
            CONCAT('VENTA-', v.id)
        ) AS folio_ticket,
        ROUND(SUM({$importeNeto}), 2) AS total_general,
        ROUND(SUM({$importeOriginal}), 2) AS total_original,
        SUM({$neta}) AS piezas_vigentes,
        SUM(v.cantidad_vendida) AS piezas_originales,
        COALESCE(
            MAX(NULLIF(TRIM(v.correo_cliente), '')),
            'Venta en general'
        ) AS correo_cliente,
        DATE_FORMAT(
            MAX(v.fecha_venta),
            '%d/%m/%Y %H:%i'
        ) AS fecha_venta,
        DATE_FORMAT(
            MAX(v.fecha_venta),
            '%Y-%m-%d %H:%i:%s'
        ) AS fecha_raw,
        MAX(v.fecha_venta) AS fecha_plazo_raw,
        MAX(NULLIF(TRIM(v.ticket_pdf), '')) AS ticket_pdf,
        MAX(v.id_orden) AS id_orden,
        {$pedidoSelect},
        MAX(NULLIF(TRIM(v.motivo_cancelacion), '')) AS motivo_cancelacion,
        DATE_FORMAT(
            MAX(v.fecha_cancelacion),
            '%d/%m/%Y %H:%i'
        ) AS fecha_cancelacion,
        MAX(NULLIF(TRIM(v.metodo_pago), '')) AS metodo_pago_registrado,
        {$metodoPagoGrupo} AS metodo_pago,
        {$metodoPagoInferidoGrupo} AS metodo_pago_inferido,
        CASE
            WHEN SUM({$neta}) <= 0
                THEN 'cancelada'
            WHEN SUM({$neta}) < SUM(v.cantidad_vendida)
              OR SUM(
                    CASE
                        WHEN LOWER(
                            COALESCE(v.estado, 'completada')
                        ) IN ('parcial', 'cancelada')
                            THEN 1
                        ELSE 0
                    END
                 ) > 0
                THEN 'parcial'
            WHEN SUM(
                    CASE
                        WHEN LOWER(
                            COALESCE(v.estado, 'completada')
                        ) = 'pendiente'
                            THEN 1
                        ELSE 0
                    END
                 ) = COUNT(*)
                THEN 'pendiente'
            ELSE 'completada'
        END AS estado_venta,
        MAX(mpr.estado_reembolso_mp) AS estado_reembolso_mp,
        ROUND(
            COALESCE(MAX(mpr.reembolsado_mp), 0),
            2
        ) AS reembolsado_mp
    FROM ventas v
    INNER JOIN productos p
        ON p.id = v.id_producto
    LEFT JOIN usuarios u
        ON u.id = v.id_vendedor
    LEFT JOIN (
        SELECT
            id_venta,
            SUM(cantidad_devuelta) AS cantidad_devuelta
        FROM devoluciones_parciales
        GROUP BY id_venta
    ) dp ON dp.id_venta = v.id
    LEFT JOIN (
        SELECT
            id_venta,
            SUM(
                CASE
                    WHEN COALESCE(cantidad_devuelta, 0) > 0
                        THEN cantidad_devuelta
                    ELSE 0
                END
            ) AS cantidad_cancelada,
            MAX(
                CASE
                    WHEN COALESCE(cantidad_devuelta, 0) <= 0
                        THEN 1
                    ELSE 0
                END
            ) AS cancelacion_total
        FROM ventas_canceladas
        WHERE id_venta IS NOT NULL
        GROUP BY id_venta
    ) vc ON vc.id_venta = v.id
    {$pedidoJoin}
    LEFT JOIN (
        SELECT
            folio_ticket,
            SUBSTRING_INDEX(
                GROUP_CONCAT(
                    status
                    ORDER BY id DESC
                    SEPARATOR '|||'
                ),
                '|||',
                1
            ) AS estado_reembolso_mp,
            SUM(
                CASE
                    WHEN accion IN (
                        'reembolso_total',
                        'reembolso_parcial'
                    )
                     AND LOWER(status) IN (
                        'processed',
                        'approved',
                        'refunded',
                        'processing',
                        'pending',
                        'accepted'
                     )
                        THEN monto
                    ELSE 0
                END
            ) AS reembolsado_mp
        FROM mercadopago_reembolsos
        GROUP BY folio_ticket
    ) mpr ON mpr.folio_ticket = v.folio_ticket
    {$whereSql}
    GROUP BY COALESCE(
        NULLIF(v.folio_ticket, ''),
        CONCAT('VENTA-', v.id)
    )
    ORDER BY MAX(v.fecha_venta) DESC, MAX(v.id) DESC
";

$stmt = $conn->prepare($sql);

if (!$stmt) {
    vrh_responder([
        'success' => false,
        'message' => 'No se pudo preparar la consulta de ventas: '
            . $conn->error,
    ], 500);
}

ov_bind($stmt, $tipos, $parametros);
$stmt->execute();
$result = $stmt->get_result();

$ventas = [];
$globalLower = mb_strtolower($global, 'UTF-8');

while ($row = $result->fetch_assoc()) {
    if (
        $filtroEstado !== ''
        && $row['estado_venta'] !== $filtroEstado
    ) {
        continue;
    }

    if (
        $global !== ''
        && in_array(
            $globalLower,
            [
                'cancelada',
                'canceladas',
                'completada',
                'completadas',
                'parcial',
                'pendiente',
            ],
            true
        )
    ) {
        $buscado = rtrim($globalLower, 's');

        if ($buscado === 'cancelada') {
            $buscado = 'cancelada';
        } elseif ($buscado === 'completada') {
            $buscado = 'completada';
        }

        if (
            $row['estado_venta'] !== $buscado
            && stripos(
                (string) $row['estado_pedido'],
                $buscado
            ) === false
        ) {
            continue;
        }
    }

    $row['total_general'] = (float) (
        $row['total_general'] ?? 0
    );
    $row['total_original'] = (float) (
        $row['total_original'] ?? 0
    );
    $row['reembolsado_mp'] = (float) (
        $row['reembolsado_mp'] ?? 0
    );
    $row['piezas_vigentes'] = (int) (
        $row['piezas_vigentes'] ?? 0
    );
    $row['piezas_originales'] = (int) (
        $row['piezas_originales'] ?? 0
    );
    $row['metodo_pago'] = trim((string) (
        $row['metodo_pago'] ?? ''
    ));
    $row['metodo_pago_inferido'] = (int) (
        $row['metodo_pago_inferido'] ?? 0
    );

    /*
     * El backend procesa nuevamente estas mismas reglas. Aquí se consultan
     * para que la interfaz muestre cada acción habilitada o bloqueada.
     */
    $estadoVenta = mb_strtolower(
        (string) ($row['estado_venta'] ?? 'completada'),
        'UTF-8'
    );
    $fechaVentaPlazo = (string) (
        $row['fecha_plazo_raw']
        ?? $row['fecha_raw']
        ?? ''
    );
    $metodoPagoPlazo = (string) (
        $row['metodo_pago'] ?? ''
    );

    $row['puede_cancelar_total'] = false;
    $row['puede_devolucion_parcial'] = false;
    $row['motivo_bloqueo_cancelacion_total'] = '';
    $row['motivo_bloqueo_devolucion_parcial'] = '';
    $row['dias_restantes_cancelacion_total'] = 0;
    $row['dias_restantes_devolucion_parcial'] = 0;
    $row['dias_transcurridos_cancelacion_total'] = 0;
    $row['dias_transcurridos_devolucion_parcial'] = 0;
    $row['dias_configurados_cancelacion_total'] = 0;
    $row['dias_configurados_devolucion_parcial'] = 0;
    $row['fecha_limite_cancelacion_total'] = '';
    $row['fecha_limite_devolucion_parcial'] = '';

    if ($estadoVenta === 'cancelada') {
        $row['motivo_bloqueo_cancelacion_total'] =
            'La venta ya está cancelada.';
        $row['motivo_bloqueo_devolucion_parcial'] =
            'La venta ya está cancelada.';
    } elseif ($fechaVentaPlazo === '') {
        $row['motivo_bloqueo_cancelacion_total'] =
            'La venta no tiene una fecha válida para calcular el plazo.';
        $row['motivo_bloqueo_devolucion_parcial'] =
            'La venta no tiene una fecha válida para calcular el plazo.';
    } elseif ($metodoPagoPlazo === '') {
        $row['motivo_bloqueo_cancelacion_total'] =
            'La venta no tiene método de pago registrado.';
        $row['motivo_bloqueo_devolucion_parcial'] =
            'La venta no tiene método de pago registrado.';
    } else {
        try {
            $plazoTotal = vrh_validar_plazo(
                $conn,
                $metodoPagoPlazo,
                $fechaVentaPlazo,
                'total'
            );

            $row['puede_cancelar_total'] = true;
            $row['dias_restantes_cancelacion_total'] = (int) (
                $plazoTotal['dias_restantes'] ?? 0
            );
            $row['dias_transcurridos_cancelacion_total'] = (int) (
                $plazoTotal['dias_transcurridos'] ?? 0
            );
            $row['dias_configurados_cancelacion_total'] = (int) (
                $plazoTotal['dias_permitidos'] ?? 0
            );
            $row['fecha_limite_cancelacion_total'] = (string) (
                $plazoTotal['fecha_limite_formateada'] ?? ''
            );
        } catch (Throwable $e) {
            $row['motivo_bloqueo_cancelacion_total'] =
                $e->getMessage();
        }

        try {
            $plazoParcial = vrh_validar_plazo(
                $conn,
                $metodoPagoPlazo,
                $fechaVentaPlazo,
                'parcial'
            );

            $row['puede_devolucion_parcial'] = true;
            $row['dias_restantes_devolucion_parcial'] = (int) (
                $plazoParcial['dias_restantes'] ?? 0
            );
            $row['dias_transcurridos_devolucion_parcial'] = (int) (
                $plazoParcial['dias_transcurridos'] ?? 0
            );
            $row['dias_configurados_devolucion_parcial'] = (int) (
                $plazoParcial['dias_permitidos'] ?? 0
            );
            $row['fecha_limite_devolucion_parcial'] = (string) (
                $plazoParcial['fecha_limite_formateada'] ?? ''
            );
        } catch (Throwable $e) {
            $row['motivo_bloqueo_devolucion_parcial'] =
                $e->getMessage();
        }
    }

    unset($row['fecha_plazo_raw']);
    $ventas[] = $row;
}

$stmt->close();

vrh_responder([
    'success' => true,
    'data' => $ventas,
    'recordsTotal' => count($ventas),
]);
