<?php
include 'includes/session.php';
include 'includes/db.php';
include 'includes/header.php';
include 'includes/navbar.php';

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

if (!isset($conn) || !($conn instanceof mysqli)) {
    die('No se encontró la conexión $conn. Revisa includes/db.php');
}

$conn->set_charset('utf8mb4');
date_default_timezone_set('America/Mexico_City');

/* ================== FUNCIONES BASE ================== */

function caja_h($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function caja_money($value) {
    return '$' . number_format((float)$value, 2, '.', ',');
}

function caja_to_float($value) {
    $value = str_replace(',', '', (string)$value);
    return is_numeric($value) ? (float)$value : 0.00;
}

function caja_fecha_normal($value, $fallback) {
    $value = trim((string)$value);

    if ($value === '') {
        return $fallback;
    }

    $value = str_replace('T', ' ', $value);

    if (strlen($value) === 16) {
        $value .= ':00';
    }

    $time = strtotime($value);

    if ($time === false) {
        return $fallback;
    }

    return date('Y-m-d H:i:s', $time);
}

function caja_fecha_input($value) {
    $time = strtotime($value);
    return $time ? date('Y-m-d\TH:i', $time) : '';
}

function caja_fetch_all(mysqli $conn, string $sql, string $types = '', array $params = []) {
    $stmt = $conn->prepare($sql);

    if ($types !== '' && count($params) > 0) {
        $bind = [];
        $bind[] = $types;

        foreach ($params as $key => $value) {
            $bind[] = &$params[$key];
        }

        call_user_func_array([$stmt, 'bind_param'], $bind);
    }

    $stmt->execute();
    $result = $stmt->get_result();
    $rows = $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
    $stmt->close();

    return $rows;
}

function caja_fetch_one(mysqli $conn, string $sql, string $types = '', array $params = []) {
    $rows = caja_fetch_all($conn, $sql, $types, $params);
    return $rows[0] ?? [];
}

function caja_execute(mysqli $conn, string $sql, string $types = '', array $params = []) {
    $stmt = $conn->prepare($sql);

    if ($types !== '' && count($params) > 0) {
        $bind = [];
        $bind[] = $types;

        foreach ($params as $key => $value) {
            $bind[] = &$params[$key];
        }

        call_user_func_array([$stmt, 'bind_param'], $bind);
    }

    $stmt->execute();
    $insertId = $stmt->insert_id;
    $stmt->close();

    return $insertId;
}

function caja_usuario_id() {
    return $_SESSION['usuario_id']
        ?? $_SESSION['id_usuario']
        ?? $_SESSION['id']
        ?? null;
}

function caja_usuario_nombre() {
    return $_SESSION['usuario_nombre']
        ?? $_SESSION['nombre_usuario']
        ?? $_SESSION['nombre']
        ?? 'Usuario del sistema';
}

/* ================== EXPRESIONES DEL CORTE ================== */

function caja_filtros_venta($fechaInicio, $fechaFin, $vendedorId) {
    $where = 'v.fecha_venta >= ? AND v.fecha_venta <= ?';
    $types = 'ss';
    $params = [$fechaInicio, $fechaFin];

    if ((int)$vendedorId > 0) {
        $where .= ' AND v.id_vendedor = ?';
        $types .= 'i';
        $params[] = (int)$vendedorId;
    }

    return [$where, $types, $params];
}

function caja_joins_devoluciones() {
    return "
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
                SUM(cantidad_devuelta) AS cantidad_cancelada,
                COUNT(*) AS total_cancelaciones
            FROM ventas_canceladas
            GROUP BY id_venta
        ) vc ON vc.id_venta = v.id
    ";
}

function caja_expresiones() {
    $cancelada = "
        (
            CASE
                WHEN vc.total_cancelaciones IS NULL THEN 0
                WHEN IFNULL(vc.cantidad_cancelada, 0) = 0 THEN v.cantidad_vendida
                ELSE vc.cantidad_cancelada
            END
        )
    ";

    $devuelta = "IFNULL(dp.cantidad_devuelta, 0)";

    $neta = "
        GREATEST(
            v.cantidad_vendida - {$devuelta} - {$cancelada},
            0
        )
    ";

    $metodo = "
        CASE
            WHEN LOWER(TRIM(IFNULL(v.metodo_pago, ''))) LIKE '%efect%'
                OR LOWER(TRIM(IFNULL(v.metodo_pago, ''))) = 'cash'
                THEN 'efectivo'

            WHEN LOWER(TRIM(IFNULL(v.metodo_pago, ''))) LIKE '%tarj%'
                OR LOWER(TRIM(IFNULL(v.metodo_pago, ''))) LIKE '%deb%'
                OR LOWER(TRIM(IFNULL(v.metodo_pago, ''))) LIKE '%cred%'
                OR LOWER(TRIM(IFNULL(v.metodo_pago, ''))) LIKE '%créd%'
                THEN 'tarjeta'

            WHEN LOWER(TRIM(IFNULL(v.metodo_pago, ''))) LIKE '%trans%'
                OR LOWER(TRIM(IFNULL(v.metodo_pago, ''))) LIKE '%spei%'
                THEN 'transferencia'

            ELSE 'otros'
        END
    ";

    return [$cancelada, $devuelta, $neta, $metodo];
}

/* ================== CONSULTAS ================== */

function caja_obtener_resumen(mysqli $conn, $fechaInicio, $fechaFin, $vendedorId) {
    [$where, $types, $params] = caja_filtros_venta($fechaInicio, $fechaFin, $vendedorId);
    [$cancelada, $devuelta, $neta] = caja_expresiones();

    $sql = "
        SELECT
            COALESCE(SUM(v.cantidad_vendida * p.precio_venta), 0) AS ventas_brutas,
            COALESCE(SUM({$devuelta} * p.precio_venta), 0) AS devoluciones,
            COALESCE(SUM({$cancelada} * p.precio_venta), 0) AS cancelaciones,
            COALESCE(SUM({$neta} * p.precio_venta), 0) AS total_neto_sistema,
            COALESCE(SUM({$neta} * p.precio_compra), 0) AS costo_estimado,
            COALESCE(SUM({$neta} * (p.precio_venta - p.precio_compra)), 0) AS utilidad_estimada,
            COALESCE(SUM({$neta}), 0) AS total_productos_vendidos,
            COUNT(DISTINCT IFNULL(v.folio_ticket, CONCAT('VENTA-', v.id))) AS total_tickets
        FROM ventas v
        INNER JOIN productos p ON p.id = v.id_producto
        " . caja_joins_devoluciones() . "
        WHERE {$where}
    ";

    $row = caja_fetch_one($conn, $sql, $types, $params);

    return [
        'ventas_brutas' => (float)($row['ventas_brutas'] ?? 0),
        'devoluciones' => (float)($row['devoluciones'] ?? 0),
        'cancelaciones' => (float)($row['cancelaciones'] ?? 0),
        'total_neto_sistema' => (float)($row['total_neto_sistema'] ?? 0),
        'costo_estimado' => (float)($row['costo_estimado'] ?? 0),
        'utilidad_estimada' => (float)($row['utilidad_estimada'] ?? 0),
        'total_productos_vendidos' => (float)($row['total_productos_vendidos'] ?? 0),
        'total_tickets' => (int)($row['total_tickets'] ?? 0),
    ];
}

function caja_obtener_metodos(mysqli $conn, $fechaInicio, $fechaFin, $vendedorId) {
    [$where, $types, $params] = caja_filtros_venta($fechaInicio, $fechaFin, $vendedorId);
    [, , $neta, $metodo] = caja_expresiones();

    $sql = "
        SELECT
            {$metodo} AS metodo,
            COUNT(DISTINCT IFNULL(v.folio_ticket, CONCAT('VENTA-', v.id))) AS ventas,
            COALESCE(SUM({$neta}), 0) AS productos,
            COALESCE(SUM({$neta} * p.precio_venta), 0) AS total_sistema
        FROM ventas v
        INNER JOIN productos p ON p.id = v.id_producto
        " . caja_joins_devoluciones() . "
        WHERE {$where}
        GROUP BY metodo
        ORDER BY total_sistema DESC
    ";

    $rows = caja_fetch_all($conn, $sql, $types, $params);

    $metodos = [
        'efectivo' => ['metodo' => 'efectivo', 'ventas' => 0, 'productos' => 0, 'total_sistema' => 0],
        'tarjeta' => ['metodo' => 'tarjeta', 'ventas' => 0, 'productos' => 0, 'total_sistema' => 0],
        'transferencia' => ['metodo' => 'transferencia', 'ventas' => 0, 'productos' => 0, 'total_sistema' => 0],
        'otros' => ['metodo' => 'otros', 'ventas' => 0, 'productos' => 0, 'total_sistema' => 0],
    ];

    foreach ($rows as $row) {
        $key = $row['metodo'] ?? 'otros';

        if (!isset($metodos[$key])) {
            $key = 'otros';
        }

        $metodos[$key] = [
            'metodo' => $key,
            'ventas' => (int)$row['ventas'],
            'productos' => (float)$row['productos'],
            'total_sistema' => (float)$row['total_sistema'],
        ];
    }

    return $metodos;
}

function caja_obtener_productos(mysqli $conn, $fechaInicio, $fechaFin, $vendedorId) {
    [$where, $types, $params] = caja_filtros_venta($fechaInicio, $fechaFin, $vendedorId);
    [$cancelada, $devuelta, $neta] = caja_expresiones();

    $sql = "
        SELECT
            p.id AS producto_id,
            p.nombre AS producto_nombre,
            p.categoria,
            MAX(p.precio_venta) AS precio_venta,
            COALESCE(SUM(v.cantidad_vendida), 0) AS cantidad_bruta,
            COALESCE(SUM({$devuelta} + {$cancelada}), 0) AS cantidad_devuelta_cancelada,
            COALESCE(SUM({$neta}), 0) AS cantidad_neta,
            COALESCE(SUM({$neta} * p.precio_venta), 0) AS subtotal,
            COALESCE(SUM({$neta} * (p.precio_venta - p.precio_compra)), 0) AS utilidad_estimada
        FROM ventas v
        INNER JOIN productos p ON p.id = v.id_producto
        " . caja_joins_devoluciones() . "
        WHERE {$where}
        GROUP BY p.id, p.nombre, p.categoria
        HAVING cantidad_bruta > 0
        ORDER BY subtotal DESC, p.nombre ASC
        LIMIT 30
    ";

    return caja_fetch_all($conn, $sql, $types, $params);
}

function caja_obtener_ventas_recientes(mysqli $conn, $fechaInicio, $fechaFin, $vendedorId) {
    [$where, $types, $params] = caja_filtros_venta($fechaInicio, $fechaFin, $vendedorId);
    [, , $neta, $metodo] = caja_expresiones();

    $sql = "
        SELECT
            v.id,
            v.folio_ticket,
            v.fecha_venta,
            u.nombre AS vendedor_nombre,
            {$metodo} AS metodo_normalizado,
            SUM({$neta} * p.precio_venta) AS total_ticket,
            SUM({$neta}) AS piezas
        FROM ventas v
        INNER JOIN productos p ON p.id = v.id_producto
        LEFT JOIN usuarios u ON u.id = v.id_vendedor
        " . caja_joins_devoluciones() . "
        WHERE {$where}
        GROUP BY v.id, v.folio_ticket, v.fecha_venta, u.nombre, metodo_normalizado
        ORDER BY v.fecha_venta DESC
        LIMIT 20
    ";

    return caja_fetch_all($conn, $sql, $types, $params);
}

function caja_obtener_vendedores(mysqli $conn) {
    $sql = "
        SELECT id, nombre, rol
        FROM usuarios
        WHERE rol IN ('administrador', 'vendedor')
        ORDER BY nombre ASC
    ";

    return caja_fetch_all($conn, $sql);
}

function caja_obtener_historial(mysqli $conn) {
    $sql = "
        SELECT 
            c.*,
            u.nombre AS vendedor_nombre
        FROM cortes_caja c
        LEFT JOIN usuarios u ON u.id = c.vendedor_id
        ORDER BY c.created_at DESC
        LIMIT 15
    ";

    return caja_fetch_all($conn, $sql);
}

function caja_registrar_auditoria(mysqli $conn, $usuarioId, $accion, $detalle) {
    try {
        $ip = $_SERVER['REMOTE_ADDR'] ?? null;

        caja_execute(
            $conn,
            "INSERT INTO auditoria (usuario_id, accion, detalle, ip) VALUES (?, ?, ?, ?)",
            "isss",
            [$usuarioId, $accion, $detalle, $ip]
        );
    } catch (Throwable $e) {
        // No detenemos el corte si falla auditoría.
    }
}

/* ================== DATOS INICIALES ================== */

$hoyInicio = date('Y-m-d 00:00:00');
$hoyFin = date('Y-m-d 23:59:59');

$usuarioSesionId = caja_usuario_id();
$usuarioSesionNombre = caja_usuario_nombre();

$error = '';
$mensaje = '';

/* ================== GUARDAR CORTE ================== */

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['accion'] ?? '') === 'guardar_corte') {
    $fechaInicioPost = caja_fecha_normal($_POST['fecha_inicio'] ?? '', $hoyInicio);
    $fechaFinPost = caja_fecha_normal($_POST['fecha_fin'] ?? '', $hoyFin);
    $vendedorPost = (int)($_POST['vendedor_id'] ?? 0);

    $efectivoContado = caja_to_float($_POST['efectivo_contado'] ?? 0);
    $tarjetaContado = caja_to_float($_POST['tarjeta_contado'] ?? 0);
    $transferenciaContado = caja_to_float($_POST['transferencia_contado'] ?? 0);
    $otrosContado = caja_to_float($_POST['otros_contado'] ?? 0);
    $observaciones = trim((string)($_POST['observaciones'] ?? ''));

    try {
        $resumenPost = caja_obtener_resumen($conn, $fechaInicioPost, $fechaFinPost, $vendedorPost);
        $metodosPost = caja_obtener_metodos($conn, $fechaInicioPost, $fechaFinPost, $vendedorPost);
        $productosPost = caja_obtener_productos($conn, $fechaInicioPost, $fechaFinPost, $vendedorPost);

        $totalContado = $efectivoContado + $tarjetaContado + $transferenciaContado + $otrosContado;
        $totalSistema = (float)$resumenPost['total_neto_sistema'];
        $diferencia = $totalContado - $totalSistema;
        $estado = abs($diferencia) <= 0.01 ? 'cuadrado' : 'con_diferencia';

        $folio = 'CORTE-' . date('Ymd-His') . '-' . random_int(100, 999);

        $vendedorGuardar = $vendedorPost > 0 ? $vendedorPost : null;
        $usuarioGuardar = $usuarioSesionId ? (int)$usuarioSesionId : null;

        $conn->begin_transaction();

        $corteId = caja_execute(
            $conn,
            "
            INSERT INTO cortes_caja (
                folio_corte,
                fecha_inicio,
                fecha_fin,
                vendedor_id,
                usuario_corte_id,
                usuario_corte_nombre,
                ventas_brutas,
                devoluciones,
                cancelaciones,
                total_neto_sistema,
                efectivo_sistema,
                tarjeta_sistema,
                transferencia_sistema,
                otros_sistema,
                efectivo_contado,
                tarjeta_contado,
                transferencia_contado,
                otros_contado,
                total_contado,
                diferencia,
                utilidad_estimada,
                total_productos_vendidos,
                total_tickets,
                observaciones,
                estado
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ",
            "sssiisddddddddddddddddiss",
            [
                $folio,
                $fechaInicioPost,
                $fechaFinPost,
                $vendedorGuardar,
                $usuarioGuardar,
                $usuarioSesionNombre,
                $resumenPost['ventas_brutas'],
                $resumenPost['devoluciones'],
                $resumenPost['cancelaciones'],
                $resumenPost['total_neto_sistema'],
                $metodosPost['efectivo']['total_sistema'],
                $metodosPost['tarjeta']['total_sistema'],
                $metodosPost['transferencia']['total_sistema'],
                $metodosPost['otros']['total_sistema'],
                $efectivoContado,
                $tarjetaContado,
                $transferenciaContado,
                $otrosContado,
                $totalContado,
                $diferencia,
                $resumenPost['utilidad_estimada'],
                $resumenPost['total_productos_vendidos'],
                $resumenPost['total_tickets'],
                $observaciones,
                $estado
            ]
        );

        $conteos = [
            'efectivo' => $efectivoContado,
            'tarjeta' => $tarjetaContado,
            'transferencia' => $transferenciaContado,
            'otros' => $otrosContado,
        ];

        foreach (['efectivo', 'tarjeta', 'transferencia', 'otros'] as $metodo) {
            $sistema = (float)$metodosPost[$metodo]['total_sistema'];
            $contado = (float)$conteos[$metodo];

            caja_execute(
                $conn,
                "
                INSERT INTO cortes_caja_metodos (
                    corte_id,
                    metodo,
                    ventas,
                    productos,
                    total_sistema,
                    total_contado,
                    diferencia
                ) VALUES (?, ?, ?, ?, ?, ?, ?)
                ",
                "isidddd",
                [
                    $corteId,
                    $metodo,
                    $metodosPost[$metodo]['ventas'],
                    $metodosPost[$metodo]['productos'],
                    $sistema,
                    $contado,
                    $contado - $sistema
                ]
            );
        }

        foreach ($productosPost as $producto) {
            caja_execute(
                $conn,
                "
                INSERT INTO cortes_caja_productos (
                    corte_id,
                    producto_id,
                    producto_nombre,
                    categoria,
                    cantidad_bruta,
                    cantidad_devuelta_cancelada,
                    cantidad_neta,
                    precio_venta,
                    subtotal,
                    utilidad_estimada
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ",
                "iissdddddd",
                [
                    $corteId,
                    $producto['producto_id'],
                    $producto['producto_nombre'],
                    $producto['categoria'],
                    $producto['cantidad_bruta'],
                    $producto['cantidad_devuelta_cancelada'],
                    $producto['cantidad_neta'],
                    $producto['precio_venta'],
                    $producto['subtotal'],
                    $producto['utilidad_estimada']
                ]
            );
        }

        caja_registrar_auditoria(
            $conn,
            $usuarioGuardar,
            'CORTE_CAJA',
            'Se generó el corte de caja ' . $folio . ' por ' . caja_money($totalSistema)
        );

        $conn->commit();

        $redirect = strtok($_SERVER['REQUEST_URI'], '?') . '?' . http_build_query([
            'guardado' => 1,
            'folio' => $folio,
            'fecha_inicio' => $fechaInicioPost,
            'fecha_fin' => $fechaFinPost,
            'vendedor_id' => $vendedorPost
        ]);

        header('Location: ' . $redirect);
        exit;
    } catch (Throwable $e) {
        try {
            $conn->rollback();
        } catch (Throwable $rollbackError) {}

        $error = 'No se pudo guardar el corte: ' . $e->getMessage();
    }
}

/* ================== CARGA DE VISTA ================== */

$fechaInicio = caja_fecha_normal($_GET['fecha_inicio'] ?? '', $hoyInicio);
$fechaFin = caja_fecha_normal($_GET['fecha_fin'] ?? '', $hoyFin);
$vendedorFiltro = (int)($_GET['vendedor_id'] ?? 0);

if (strtotime($fechaInicio) > strtotime($fechaFin)) {
    $temporal = $fechaInicio;
    $fechaInicio = $fechaFin;
    $fechaFin = $temporal;
}

try {
    $resumen = caja_obtener_resumen($conn, $fechaInicio, $fechaFin, $vendedorFiltro);
    $metodos = caja_obtener_metodos($conn, $fechaInicio, $fechaFin, $vendedorFiltro);
    $productos = caja_obtener_productos($conn, $fechaInicio, $fechaFin, $vendedorFiltro);
    $ventasRecientes = caja_obtener_ventas_recientes($conn, $fechaInicio, $fechaFin, $vendedorFiltro);
    $vendedores = caja_obtener_vendedores($conn);
    $historial = caja_obtener_historial($conn);
} catch (Throwable $e) {
    $resumen = [
        'ventas_brutas' => 0,
        'devoluciones' => 0,
        'cancelaciones' => 0,
        'total_neto_sistema' => 0,
        'costo_estimado' => 0,
        'utilidad_estimada' => 0,
        'total_productos_vendidos' => 0,
        'total_tickets' => 0,
    ];

    $metodos = [
        'efectivo' => ['metodo' => 'efectivo', 'ventas' => 0, 'productos' => 0, 'total_sistema' => 0],
        'tarjeta' => ['metodo' => 'tarjeta', 'ventas' => 0, 'productos' => 0, 'total_sistema' => 0],
        'transferencia' => ['metodo' => 'transferencia', 'ventas' => 0, 'productos' => 0, 'total_sistema' => 0],
        'otros' => ['metodo' => 'otros', 'ventas' => 0, 'productos' => 0, 'total_sistema' => 0],
    ];

    $productos = [];
    $ventasRecientes = [];
    $vendedores = [];
    $historial = [];

    $error = $error ?: 'Error al cargar el corte: ' . $e->getMessage();
}

if (isset($_GET['guardado']) && $_GET['guardado'] == '1') {
    $mensaje = 'Corte guardado correctamente' . (isset($_GET['folio']) ? ': ' . caja_h($_GET['folio']) : '') . '.';
}

$nombreVendedorFiltro = 'Todos los vendedores';

foreach ($vendedores as $vendedor) {
    if ((int)$vendedor['id'] === $vendedorFiltro) {
        $nombreVendedorFiltro = $vendedor['nombre'];
        break;
    }
}

$totalSistema = (float)$resumen['total_neto_sistema'];

$estadoPreview = 'Cuadrado';
$estadoPreviewClass = 'caja-status-ok';
?>

<style>
.content-wrapper {
    background: linear-gradient(180deg, #FFF4E6, #FFFFFF);
    min-height: 100vh;
    padding: 25px;
    border-radius: 18px 0 0 18px;
}

.page-title {
    font-size: 1.9rem;
    font-weight: 700;
    color: #2c2c2c;
}

.caja-subtitle {
    color: #6c757d;
    font-size: .92rem;
    margin-top: 4px;
}

.caja-toolbar {
    display: flex;
    justify-content: flex-end;
    gap: 10px;
    flex-wrap: wrap;
}

.caja-btn {
    border: none;
    border-radius: 40px;
    padding: 10px 18px;
    font-weight: 700;
    transition: all .28s ease;
    box-shadow: 0 8px 18px rgba(0,0,0,.08);
}

.caja-btn:hover {
    transform: translateY(-2px);
}

.caja-btn-primary {
    background: #111;
    color: white;
}

.caja-btn-primary:hover {
    background: #000;
    color: white;
}

.caja-btn-light {
    background: white;
    color: #2c2c2c;
    border: 1px solid #e4e7eb;
}

.caja-btn-danger {
    background: #fff0f0;
    color: #dc3545;
    border: 1px solid #ffd2d2;
}

.caja-card {
    background: #fff;
    border-radius: 18px;
    box-shadow: 0 10px 26px rgba(0,0,0,0.08);
    border: 1px solid rgba(0,0,0,0.03);
    transition: all .35s ease;
}

.caja-card:hover {
    box-shadow: 0 18px 45px rgba(0,0,0,0.12);
}

.caja-card-body {
    padding: 18px;
}

.caja-filter-card {
    background: #fff;
    border-radius: 18px;
    box-shadow: 0 10px 26px rgba(0,0,0,0.08);
    border: 1px solid rgba(0,0,0,0.03);
    padding: 18px;
}

.caja-label {
    font-size: .72rem;
    color: #69798b;
    text-transform: uppercase;
    letter-spacing: .35px;
    font-weight: 800;
    margin-bottom: 7px;
}

.caja-input,
.caja-select,
.caja-textarea {
    border-radius: 14px !important;
    border: 1px solid #ced4da !important;
    padding: 10px 14px !important;
    min-height: 44px;
    box-shadow: none !important;
}

.caja-textarea {
    min-height: 92px;
    resize: vertical;
}

.caja-input:focus,
.caja-select:focus,
.caja-textarea:focus {
    border-color: #111 !important;
    box-shadow: 0 0 0 3px rgba(17,17,17,.08) !important;
}

.caja-summary-grid {
    display: grid;
    grid-template-columns: repeat(6, minmax(0, 1fr));
    gap: 16px;
}

.caja-stat {
    background: #fff;
    border-radius: 18px;
    box-shadow: 0 10px 26px rgba(0,0,0,0.08);
    border: 1px solid rgba(0,0,0,0.03);
    padding: 16px;
    height: 100%;
    position: relative;
    overflow: hidden;
}

.caja-stat::before {
    content: "";
    position: absolute;
    inset: 0 auto 0 0;
    width: 5px;
    background: #007bff;
}

.caja-stat.stat-success::before { background: #28a745; }
.caja-stat.stat-danger::before { background: #dc3545; }
.caja-stat.stat-warning::before { background: #ffc107; }
.caja-stat.stat-dark::before { background: #111; }

.caja-stat-icon {
    width: 42px;
    height: 42px;
    border-radius: 14px;
    background: #f8fafc;
    display: flex;
    align-items: center;
    justify-content: center;
    color: #34495e;
    margin-bottom: 12px;
    box-shadow: 0 2px 5px rgba(0,0,0,0.03);
}

.caja-stat-label {
    font-size: .68rem;
    color: #69798b;
    text-transform: uppercase;
    letter-spacing: .25px;
    font-weight: 800;
    margin-bottom: 5px;
}

.caja-stat-value {
    font-size: 1.32rem;
    line-height: 1.1;
    font-weight: 800;
    color: #212529;
    letter-spacing: -.5px;
}

.caja-stat-small {
    font-size: .78rem;
    color: #6c757d;
    margin-top: 6px;
}

.caja-section-title {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 14px;
    margin-bottom: 16px;
}

.caja-section-title h5 {
    font-size: 1.15rem;
    font-weight: 800;
    color: #222;
    margin: 0;
}

.caja-section-title small {
    color: #6c757d;
}

.caja-badge {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    border-radius: 40px;
    padding: 7px 12px;
    font-size: .75rem;
    font-weight: 800;
    background: #eef6ff;
    color: #007bff;
    border: 1px solid #d7eaff;
}

.caja-badge-dark {
    background: #111;
    color: white;
    border-color: #111;
}

.caja-badge-success {
    background: #eaf8ef;
    color: #16a34a;
    border-color: #c9efd6;
}

.caja-badge-danger {
    background: #fff0f0;
    color: #dc3545;
    border-color: #ffd2d2;
}

.caja-method-card {
    background: #f8fafc;
    border: 1px solid #edf1f5;
    border-radius: 16px;
    padding: 9px;
}

.caja-method-row {
    display: grid;
    grid-template-columns: 1fr 150px;
    gap: 10px;
    align-items: center;
    background: white;
    border: 1px solid #e8eef5;
    border-radius: 14px;
    padding: 10px;
    margin-bottom: 8px;
}

.caja-method-row:last-child {
    margin-bottom: 0;
}

.caja-method-info {
    display: flex;
    align-items: center;
    gap: 10px;
}

.caja-method-icon {
    width: 38px;
    height: 38px;
    min-width: 38px;
    border-radius: 12px;
    background: #f9fbfd;
    display: flex;
    align-items: center;
    justify-content: center;
    color: #34495e;
}

.caja-method-name {
    font-weight: 800;
    color: #222;
    line-height: 1.1;
}

.caja-method-sub {
    color: #6c757d;
    font-size: .78rem;
    margin-top: 3px;
}

.caja-total-box {
    background: linear-gradient(135deg, #111 0%, #343a40 100%);
    color: white;
    border-radius: 18px;
    padding: 18px;
    min-height: 100%;
}

.caja-total-line {
    display: flex;
    justify-content: space-between;
    gap: 12px;
    padding: 8px 0;
    border-bottom: 1px solid rgba(255,255,255,.12);
}

.caja-total-line:last-child {
    border-bottom: none;
}

.caja-total-line span {
    color: rgba(255,255,255,.75);
    font-weight: 700;
}

.caja-total-line strong {
    font-weight: 900;
}

.caja-difference-ok {
    color: #52d273 !important;
}

.caja-difference-bad {
    color: #ff7676 !important;
}

.caja-table-wrapper {
    background: white;
    border: 1px solid #edf1f5;
    border-radius: 18px;
    overflow: hidden;
}

.caja-table-wrapper .table {
    margin-bottom: 0;
}

.caja-table-wrapper thead th {
    background: #f8fafc;
    color: #69798b;
    font-size: .72rem;
    text-transform: uppercase;
    letter-spacing: .25px;
    border-bottom: 1px solid #edf1f5;
    white-space: nowrap;
}

.caja-table-wrapper tbody td {
    vertical-align: middle;
    border-top: 1px solid #edf1f5;
}

.caja-num {
    text-align: right;
    font-variant-numeric: tabular-nums;
    white-space: nowrap;
}

.caja-empty {
    text-align: center;
    padding: 45px 20px;
    background: white;
    border-radius: 18px;
    color: #6c757d;
}

.caja-empty i {
    font-size: 3rem;
    color: #adb5bd;
    margin-bottom: 12px;
}

.caja-status {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 6px 11px;
    border-radius: 40px;
    font-size: .72rem;
    font-weight: 900;
}

.caja-status-ok {
    background: #eaf8ef;
    color: #16a34a;
}

.caja-status-bad {
    background: #fff0f0;
    color: #dc3545;
}

.toast-custom {
    position: fixed;
    bottom: 20px;
    right: 20px;
    background: #28a745;
    color: white;
    padding: 12px 24px;
    border-radius: 50px;
    box-shadow: 0 5px 15px rgba(0,0,0,0.2);
    z-index: 9999;
    animation: cajaSlideIn .3s ease;
    font-weight: 600;
}

@keyframes cajaSlideIn {
    from { transform: translateX(100%); opacity: 0; }
    to { transform: translateX(0); opacity: 1; }
}

@media (max-width: 1399px) {
    .caja-summary-grid {
        grid-template-columns: repeat(3, minmax(0, 1fr));
    }
}

@media (max-width: 991px) {
    .content-wrapper {
        padding: 18px;
    }

    .caja-summary-grid {
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }

    .caja-toolbar {
        justify-content: flex-start;
    }
}

@media (max-width: 575px) {
    .content-wrapper {
        padding: 14px;
        border-radius: 0;
    }

    .page-title {
        font-size: 1.45rem;
    }

    .caja-summary-grid {
        grid-template-columns: 1fr;
    }

    .caja-method-row {
        grid-template-columns: 1fr;
    }

    .caja-btn {
        width: 100%;
        justify-content: center;
    }

    .caja-section-title {
        align-items: flex-start;
        flex-direction: column;
    }
}

@media print {
    .main-sidebar,
    .main-header,
    .no-print,
    .caja-toolbar,
    .caja-filter-card,
    .historial-cortes {
        display: none !important;
    }

    .content-wrapper {
        margin-left: 0 !important;
        padding: 0 !important;
        background: white !important;
        border-radius: 0 !important;
    }

    .caja-card,
    .caja-stat,
    .caja-filter-card {
        box-shadow: none !important;
        border: 1px solid #ddd !important;
    }

    .caja-summary-grid {
        grid-template-columns: repeat(3, 1fr);
        gap: 8px;
    }

    .caja-stat {
        padding: 10px;
    }

    .caja-stat-icon {
        display: none;
    }

    .caja-stat-value {
        font-size: 1rem;
    }

    .table td,
    .table th {
        font-size: 10px;
        padding: 6px;
    }
}
</style>

<div class="content-wrapper">
    <section class="content-header mb-4">
        <div class="container-fluid">
            <div class="row align-items-center">
                <div class="col-lg-7">
                    <h1 class="page-title mb-0">
                        <i class="fas fa-cash-register mr-2"></i> Corte de Caja
                    </h1>
                    <div class="caja-subtitle">
                        Del <strong><?= caja_h(date('d/m/Y H:i', strtotime($fechaInicio))) ?></strong>
                        al <strong><?= caja_h(date('d/m/Y H:i', strtotime($fechaFin))) ?></strong>
                        · <?= caja_h($nombreVendedorFiltro) ?>
                    </div>
                </div>

                <div class="col-lg-5 mt-3 mt-lg-0">
                    <div class="caja-toolbar">
                        <button type="button" class="caja-btn caja-btn-light" onclick="window.print()">
                            <i class="fas fa-print mr-1"></i> Imprimir
                        </button>

                        <a href="<?= caja_h(strtok($_SERVER['REQUEST_URI'], '?')) ?>" class="caja-btn caja-btn-danger">
                            <i class="fas fa-broom mr-1"></i> Limpiar
                        </a>
                    </div>
                </div>
            </div>

            <div class="row mt-3">
                <div class="col-12">
                    <small class="text-muted">
                        <i class="fas fa-user-check mr-1"></i>
                        Corte generado por: <strong><?= caja_h($usuarioSesionNombre) ?></strong>
                        · Tickets: <strong><?= (int)$resumen['total_tickets'] ?></strong>
                        · Productos vendidos: <strong><?= number_format((float)$resumen['total_productos_vendidos'], 2) ?></strong>
                    </small>
                </div>
            </div>
        </div>
    </section>

    <section class="content">
        <div class="container-fluid">

            <?php if ($mensaje): ?>
                <div class="alert alert-success text-center">
                    <i class="fas fa-check-circle mr-1"></i> <?= $mensaje ?>
                </div>
            <?php endif; ?>

            <?php if ($error): ?>
                <div class="alert alert-danger text-center">
                    <i class="fas fa-exclamation-triangle mr-1"></i> <?= caja_h($error) ?>
                </div>
            <?php endif; ?>

            <div class="caja-filter-card mb-4 no-print">
                <form method="GET">
                    <div class="row align-items-end">
                        <div class="col-xl-3 col-lg-4 col-md-6 mb-3 mb-xl-0">
                            <div class="caja-label">
                                <i class="fas fa-calendar-alt mr-1"></i> Fecha inicio
                            </div>
                            <input type="datetime-local"
                                   name="fecha_inicio"
                                   class="form-control caja-input"
                                   value="<?= caja_h(caja_fecha_input($fechaInicio)) ?>">
                        </div>

                        <div class="col-xl-3 col-lg-4 col-md-6 mb-3 mb-xl-0">
                            <div class="caja-label">
                                <i class="fas fa-calendar-check mr-1"></i> Fecha fin
                            </div>
                            <input type="datetime-local"
                                   name="fecha_fin"
                                   class="form-control caja-input"
                                   value="<?= caja_h(caja_fecha_input($fechaFin)) ?>">
                        </div>

                        <div class="col-xl-3 col-lg-4 col-md-6 mb-3 mb-xl-0">
                            <div class="caja-label">
                                <i class="fas fa-user-tag mr-1"></i> Vendedor
                            </div>
                            <select name="vendedor_id" class="form-control caja-select">
                                <option value="0">Todos los vendedores</option>

                                <?php foreach ($vendedores as $vendedor): ?>
                                    <option value="<?= (int)$vendedor['id'] ?>" <?= (int)$vendedor['id'] === $vendedorFiltro ? 'selected' : '' ?>>
                                        <?= caja_h($vendedor['nombre']) ?><?= !empty($vendedor['rol']) ? ' · ' . caja_h($vendedor['rol']) : '' ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="col-xl-3 col-lg-12 col-md-6">
                            <button type="submit" class="caja-btn caja-btn-primary w-100">
                                <i class="fas fa-sync-alt mr-1"></i> Actualizar corte
                            </button>
                        </div>
                    </div>
                </form>
            </div>

            <div class="caja-summary-grid mb-4">
                <div class="caja-stat stat-dark">
                    <div class="caja-stat-icon">
                        <i class="fas fa-receipt"></i>
                    </div>
                    <div class="caja-stat-label">Venta bruta</div>
                    <div class="caja-stat-value"><?= caja_money($resumen['ventas_brutas']) ?></div>
                    <div class="caja-stat-small">Antes de devoluciones</div>
                </div>

                <div class="caja-stat stat-danger">
                    <div class="caja-stat-icon">
                        <i class="fas fa-undo-alt"></i>
                    </div>
                    <div class="caja-stat-label">Devoluciones</div>
                    <div class="caja-stat-value text-danger"><?= caja_money($resumen['devoluciones']) ?></div>
                    <div class="caja-stat-small">Parciales registradas</div>
                </div>

                <div class="caja-stat stat-danger">
                    <div class="caja-stat-icon">
                        <i class="fas fa-ban"></i>
                    </div>
                    <div class="caja-stat-label">Cancelaciones</div>
                    <div class="caja-stat-value text-danger"><?= caja_money($resumen['cancelaciones']) ?></div>
                    <div class="caja-stat-small">Ventas canceladas</div>
                </div>

                <div class="caja-stat">
                    <div class="caja-stat-icon">
                        <i class="fas fa-calculator"></i>
                    </div>
                    <div class="caja-stat-label">Neto sistema</div>
                    <div class="caja-stat-value text-primary"><?= caja_money($resumen['total_neto_sistema']) ?></div>
                    <div class="caja-stat-small">Total esperado</div>
                </div>

                <div class="caja-stat stat-success">
                    <div class="caja-stat-icon">
                        <i class="fas fa-chart-line"></i>
                    </div>
                    <div class="caja-stat-label">Utilidad estimada</div>
                    <div class="caja-stat-value text-success"><?= caja_money($resumen['utilidad_estimada']) ?></div>
                    <div class="caja-stat-small">Según precio actual</div>
                </div>

                <div class="caja-stat stat-warning">
                    <div class="caja-stat-icon">
                        <i class="fas fa-boxes"></i>
                    </div>
                    <div class="caja-stat-label">Tickets / piezas</div>
                    <div class="caja-stat-value">
                        <?= (int)$resumen['total_tickets'] ?> / <?= number_format((float)$resumen['total_productos_vendidos'], 2) ?>
                    </div>
                    <div class="caja-stat-small">Movimientos del periodo</div>
                </div>
            </div>

            <form method="POST" id="formCorteCaja">
                <input type="hidden" name="accion" value="guardar_corte">
                <input type="hidden" name="fecha_inicio" value="<?= caja_h($fechaInicio) ?>">
                <input type="hidden" name="fecha_fin" value="<?= caja_h($fechaFin) ?>">
                <input type="hidden" name="vendedor_id" value="<?= (int)$vendedorFiltro ?>">

                <div class="row mb-4">
                    <div class="col-lg-8 mb-4 mb-lg-0">
                        <div class="caja-card h-100">
                            <div class="caja-card-body">
                                <div class="caja-section-title">
                                    <div>
                                        <h5>
                                            <i class="fas fa-money-check-alt mr-2"></i>
                                            Conteo por método de pago
                                        </h5>
                                        <small>Captura lo contado físicamente para comparar contra el sistema.</small>
                                    </div>

                                    <span class="caja-badge caja-badge-dark">
                                        Sistema: <?= caja_money($totalSistema) ?>
                                    </span>
                                </div>

                                <div class="caja-method-card">
                                    <?php
                                    $iconsMetodo = [
                                        'efectivo' => 'fas fa-money-bill-wave',
                                        'tarjeta' => 'fas fa-credit-card',
                                        'transferencia' => 'fas fa-exchange-alt',
                                        'otros' => 'fas fa-wallet',
                                    ];

                                    foreach ($metodos as $metodo => $info):
                                        $inputName = $metodo . '_contado';
                                    ?>
                                        <div class="caja-method-row">
                                            <div class="caja-method-info">
                                                <div class="caja-method-icon">
                                                    <i class="<?= caja_h($iconsMetodo[$metodo] ?? 'fas fa-wallet') ?>"></i>
                                                </div>

                                                <div>
                                                    <div class="caja-method-name"><?= caja_h(ucfirst($metodo)) ?></div>
                                                    <div class="caja-method-sub">
                                                        Sistema: <?= caja_money($info['total_sistema']) ?>
                                                        · Tickets: <?= (int)$info['ventas'] ?>
                                                        · Piezas: <?= number_format((float)$info['productos'], 2) ?>
                                                    </div>
                                                </div>
                                            </div>

                                            <input type="number"
                                                   step="0.01"
                                                   min="0"
                                                   name="<?= caja_h($inputName) ?>"
                                                   id="<?= caja_h($inputName) ?>"
                                                   data-sistema="<?= caja_h($info['total_sistema']) ?>"
                                                   class="form-control caja-input monto-contado"
                                                   value="<?= caja_h(number_format((float)$info['total_sistema'], 2, '.', '')) ?>">
                                        </div>
                                    <?php endforeach; ?>
                                </div>

                                <div class="form-group mt-3 mb-0">
                                    <div class="caja-label">
                                        <i class="fas fa-sticky-note mr-1"></i> Observaciones
                                    </div>
                                    <textarea name="observaciones"
                                              class="form-control caja-textarea"
                                              placeholder="Ejemplo: diferencia por cambio, pago pendiente, devolución registrada, etc."></textarea>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="col-lg-4">
                        <div class="caja-total-box">
                            <div class="mb-3">
                                <span class="caja-badge caja-badge-success">
                                    <i class="fas fa-check-circle"></i> Resumen del corte
                                </span>
                            </div>

                            <div class="caja-total-line">
                                <span>Total sistema</span>
                                <strong><?= caja_money($totalSistema) ?></strong>
                            </div>

                            <div class="caja-total-line">
                                <span>Total contado</span>
                                <strong id="total_contado_view">$0.00</strong>
                            </div>

                            <div class="caja-total-line">
                                <span>Diferencia</span>
                                <strong id="diferencia_view">$0.00</strong>
                            </div>

                            <div class="caja-total-line">
                                <span>Estado</span>
                                <strong id="estado_view">Cuadrado</strong>
                            </div>

                            <div class="mt-4 no-print">
                                <button type="button" class="caja-btn caja-btn-light w-100 mb-2" onclick="window.print()">
                                    <i class="fas fa-print mr-1"></i> Imprimir antes de guardar
                                </button>

                                <button type="submit" class="caja-btn caja-btn-primary w-100">
                                    <i class="fas fa-save mr-1"></i> Guardar corte de caja
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </form>

            <div class="row mb-4">
                <div class="col-lg-6 mb-4 mb-lg-0">
                    <div class="caja-card h-100">
                        <div class="caja-card-body">
                            <div class="caja-section-title">
                                <div>
                                    <h5>
                                        <i class="fas fa-list-ul mr-2"></i>
                                        Métodos de pago
                                    </h5>
                                    <small>Totales calculados por el sistema.</small>
                                </div>
                            </div>

                            <div class="caja-table-wrapper table-responsive">
                                <table class="table table-hover">
                                    <thead>
                                        <tr>
                                            <th>Método</th>
                                            <th class="caja-num">Tickets</th>
                                            <th class="caja-num">Piezas</th>
                                            <th class="caja-num">Sistema</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($metodos as $metodo => $info): ?>
                                            <tr>
                                                <td>
                                                    <strong><?= caja_h(ucfirst($metodo)) ?></strong>
                                                </td>
                                                <td class="caja-num"><?= (int)$info['ventas'] ?></td>
                                                <td class="caja-num"><?= number_format((float)$info['productos'], 2) ?></td>
                                                <td class="caja-num">
                                                    <strong><?= caja_money($info['total_sistema']) ?></strong>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>

                        </div>
                    </div>
                </div>

                <div class="col-lg-6">
                    <div class="caja-card h-100">
                        <div class="caja-card-body">
                            <div class="caja-section-title">
                                <div>
                                    <h5>
                                        <i class="fas fa-receipt mr-2"></i>
                                        Últimas ventas del periodo
                                    </h5>
                                    <small>Vista rápida de los últimos movimientos.</small>
                                </div>
                            </div>

                            <?php if (count($ventasRecientes) > 0): ?>
                                <div class="caja-table-wrapper table-responsive">
                                    <table class="table table-hover">
                                        <thead>
                                            <tr>
                                                <th>Folio</th>
                                                <th>Fecha</th>
                                                <th>Método</th>
                                                <th class="caja-num">Total</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($ventasRecientes as $venta): ?>
                                                <tr>
                                                    <td>
                                                        <strong><?= caja_h($venta['folio_ticket'] ?: 'VENTA-' . $venta['id']) ?></strong>
                                                        <br>
                                                        <small class="text-muted"><?= caja_h($venta['vendedor_nombre'] ?: 'Sin vendedor') ?></small>
                                                    </td>
                                                    <td><?= caja_h(date('d/m/Y H:i', strtotime($venta['fecha_venta']))) ?></td>
                                                    <td>
                                                        <span class="caja-badge">
                                                            <?= caja_h(ucfirst($venta['metodo_normalizado'])) ?>
                                                        </span>
                                                    </td>
                                                    <td class="caja-num">
                                                        <strong><?= caja_money($venta['total_ticket']) ?></strong>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            <?php else: ?>
                                <div class="caja-empty">
                                    <i class="fas fa-search"></i>
                                    <h5>No hay ventas en este periodo</h5>
                                    <p class="text-muted mb-0">Ajusta el rango de fechas o selecciona otro vendedor.</p>
                                </div>
                            <?php endif; ?>

                        </div>
                    </div>
                </div>
            </div>

            <div class="caja-card mb-4">
                <div class="caja-card-body">
                    <div class="caja-section-title">
                        <div>
                            <h5>
                                <i class="fas fa-boxes mr-2"></i>
                                Productos vendidos en el corte
                            </h5>
                            <small>Detalle neto considerando devoluciones y cancelaciones.</small>
                        </div>

                        <span class="caja-badge">
                            <i class="fas fa-cube"></i> <?= count($productos) ?> productos
                        </span>
                    </div>

                    <?php if (count($productos) > 0): ?>
                        <div class="caja-table-wrapper table-responsive">
                            <table class="table table-hover">
                                <thead>
                                    <tr>
                                        <th>Producto</th>
                                        <th>Categoría</th>
                                        <th class="caja-num">Vendidas</th>
                                        <th class="caja-num">Dev./Canc.</th>
                                        <th class="caja-num">Netas</th>
                                        <th class="caja-num">Precio</th>
                                        <th class="caja-num">Subtotal</th>
                                        <th class="caja-num">Utilidad</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($productos as $producto): ?>
                                        <tr>
                                            <td>
                                                <strong><?= caja_h($producto['producto_nombre']) ?></strong>
                                            </td>
                                            <td><?= caja_h($producto['categoria'] ?? 'Sin categoría') ?></td>
                                            <td class="caja-num"><?= number_format((float)$producto['cantidad_bruta'], 2) ?></td>
                                            <td class="caja-num"><?= number_format((float)$producto['cantidad_devuelta_cancelada'], 2) ?></td>
                                            <td class="caja-num">
                                                <strong><?= number_format((float)$producto['cantidad_neta'], 2) ?></strong>
                                            </td>
                                            <td class="caja-num"><?= caja_money($producto['precio_venta']) ?></td>
                                            <td class="caja-num">
                                                <strong><?= caja_money($producto['subtotal']) ?></strong>
                                            </td>
                                            <td class="caja-num text-success">
                                                <strong><?= caja_money($producto['utilidad_estimada']) ?></strong>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php else: ?>
                        <div class="caja-empty">
                            <i class="fas fa-box-open"></i>
                            <h5>No hay productos vendidos</h5>
                            <p class="text-muted mb-0">No se encontraron ventas dentro del rango seleccionado.</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <div class="caja-card historial-cortes">
                <div class="caja-card-body">
                    <div class="caja-section-title">
                        <div>
                            <h5>
                                <i class="fas fa-history mr-2"></i>
                                Historial de cortes
                            </h5>
                            <small>Últimos cortes guardados.</small>
                        </div>
                    </div>

                    <?php if (count($historial) > 0): ?>
                        <div class="caja-table-wrapper table-responsive">
                            <table class="table table-hover">
                                <thead>
                                    <tr>
                                        <th>Folio</th>
                                        <th>Periodo</th>
                                        <th>Vendedor</th>
                                        <th class="caja-num">Sistema</th>
                                        <th class="caja-num">Contado</th>
                                        <th class="caja-num">Diferencia</th>
                                        <th>Estado</th>
                                        <th>Fecha corte</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($historial as $corte): ?>
                                        <tr>
                                            <td>
                                                <strong><?= caja_h($corte['folio_corte']) ?></strong>
                                                <br>
                                                <small class="text-muted"><?= caja_h($corte['usuario_corte_nombre'] ?? '') ?></small>
                                            </td>

                                            <td>
                                                <?= caja_h(date('d/m/Y H:i', strtotime($corte['fecha_inicio']))) ?>
                                                <br>
                                                <small class="text-muted">
                                                    a <?= caja_h(date('d/m/Y H:i', strtotime($corte['fecha_fin']))) ?>
                                                </small>
                                            </td>

                                            <td><?= caja_h($corte['vendedor_nombre'] ?: 'Todos') ?></td>

                                            <td class="caja-num"><?= caja_money($corte['total_neto_sistema']) ?></td>
                                            <td class="caja-num"><?= caja_money($corte['total_contado']) ?></td>

                                            <td class="caja-num">
                                                <strong class="<?= abs((float)$corte['diferencia']) <= 0.01 ? 'text-success' : 'text-danger' ?>">
                                                    <?= caja_money($corte['diferencia']) ?>
                                                </strong>
                                            </td>

                                            <td>
                                                <?php if ($corte['estado'] === 'cuadrado'): ?>
                                                    <span class="caja-status caja-status-ok">
                                                        <i class="fas fa-check-circle"></i> Cuadrado
                                                    </span>
                                                <?php else: ?>
                                                    <span class="caja-status caja-status-bad">
                                                        <i class="fas fa-exclamation-circle"></i> Con diferencia
                                                    </span>
                                                <?php endif; ?>
                                            </td>

                                            <td><?= caja_h(date('d/m/Y H:i', strtotime($corte['created_at']))) ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php else: ?>
                        <div class="caja-empty">
                            <i class="fas fa-clock"></i>
                            <h5>Todavía no hay cortes guardados</h5>
                            <p class="text-muted mb-0">Cuando guardes un corte aparecerá aquí.</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

        </div>
    </section>
</div>

<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

<script>
const totalSistema = <?= json_encode((float)$totalSistema) ?>;

function cajaFormatoMoneda(value) {
    const number = Number(value || 0);

    return number.toLocaleString('es-MX', {
        style: 'currency',
        currency: 'MXN'
    });
}

function cajaCalcularConteo() {
    const inputs = document.querySelectorAll('.monto-contado');
    let total = 0;

    inputs.forEach(input => {
        total += Number(input.value || 0);
    });

    const diferencia = total - totalSistema;

    const totalView = document.getElementById('total_contado_view');
    const diferenciaView = document.getElementById('diferencia_view');
    const estadoView = document.getElementById('estado_view');

    if (totalView) {
        totalView.textContent = cajaFormatoMoneda(total);
    }

    if (diferenciaView) {
        diferenciaView.textContent = cajaFormatoMoneda(diferencia);
        diferenciaView.classList.remove('caja-difference-ok', 'caja-difference-bad');

        if (Math.abs(diferencia) <= 0.01) {
            diferenciaView.classList.add('caja-difference-ok');
        } else {
            diferenciaView.classList.add('caja-difference-bad');
        }
    }

    if (estadoView) {
        if (Math.abs(diferencia) <= 0.01) {
            estadoView.textContent = 'Cuadrado';
            estadoView.classList.remove('caja-difference-bad');
            estadoView.classList.add('caja-difference-ok');
        } else {
            estadoView.textContent = 'Con diferencia';
            estadoView.classList.remove('caja-difference-ok');
            estadoView.classList.add('caja-difference-bad');
        }
    }
}

function cajaMostrarToast(mensaje, tipo = 'success') {
    const toast = document.createElement('div');
    toast.className = 'toast-custom';
    toast.textContent = mensaje;

    if (tipo === 'danger') {
        toast.style.background = '#dc3545';
    }

    document.body.appendChild(toast);

    setTimeout(() => {
        toast.style.animation = 'cajaSlideIn .3s reverse';
        setTimeout(() => toast.remove(), 300);
    }, 2200);
}

document.querySelectorAll('.monto-contado').forEach(input => {
    input.addEventListener('input', cajaCalcularConteo);
});

const formCorte = document.getElementById('formCorteCaja');

if (formCorte) {
    formCorte.addEventListener('submit', function(e) {
        e.preventDefault();
        cajaCalcularConteo();

        const diferenciaTexto = document.getElementById('diferencia_view')?.textContent || '$0.00';
        const estadoTexto = document.getElementById('estado_view')?.textContent || 'Cuadrado';

        Swal.fire({
            icon: estadoTexto === 'Cuadrado' ? 'question' : 'warning',
            title: '¿Guardar corte de caja?',
            html: `
                <div class="text-center">
                    <p class="mb-2">Se guardará el corte con el rango seleccionado.</p>
                    <div style="background:#f8fafc;border-radius:14px;padding:14px;border:1px solid #edf1f5;">
                        <strong>Diferencia:</strong> ${diferenciaTexto}<br>
                        <strong>Estado:</strong> ${estadoTexto}
                    </div>
                </div>
            `,
            showCancelButton: true,
            confirmButtonText: 'Sí, guardar corte',
            cancelButtonText: 'Cancelar',
            confirmButtonColor: '#111',
            cancelButtonColor: '#6c757d'
        }).then((result) => {
            if (result.isConfirmed) {
                formCorte.submit();
            }
        });
    });
}

cajaCalcularConteo();

<?php if ($mensaje): ?>
document.addEventListener('DOMContentLoaded', function() {
    cajaMostrarToast('Corte guardado correctamente');
});
<?php endif; ?>
</script>

<?php include 'includes/footer.php'; ?>