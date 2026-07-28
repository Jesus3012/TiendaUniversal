<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/session.php';
require_once __DIR__ . '/includes/db.php';

$usuarioId = (int) ($_SESSION['usuario_id'] ?? 0);
$rolActual = strtolower(trim((string) ($_SESSION['rol'] ?? '')));
$rolesPermitidos = ['administrador', 'super_administrador'];

if ($usuarioId <= 0) {
    header('Location: login.php');
    exit;
}

if (!in_array($rolActual, $rolesPermitidos, true)) {
    header('Location: sin_permiso.php?modulo=promociones.php');
    exit;
}

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

function promo_h($valor): string
{
    return htmlspecialchars((string) $valor, ENT_QUOTES, 'UTF-8');
}

function promo_dinero($valor): string
{
    $numero = (float) $valor;
    $decimales = abs($numero - round($numero)) < 0.00001 ? 0 : 2;

    return '$' . number_format($numero, $decimales, '.', ',');
}

function promo_fecha_valida($fecha): bool
{
    $fecha = trim((string) $fecha);

    if ($fecha === '') {
        return true;
    }

    $objeto = DateTime::createFromFormat('Y-m-d', $fecha);

    return $objeto instanceof DateTime
        && $objeto->format('Y-m-d') === $fecha;
}

function promo_csrf_token(): string
{
    if (empty($_SESSION['promociones_csrf'])) {
        $_SESSION['promociones_csrf'] = bin2hex(random_bytes(32));
    }

    return (string) $_SESSION['promociones_csrf'];
}

function promo_csrf_validar(): void
{
    $tokenSesion = (string) ($_SESSION['promociones_csrf'] ?? '');
    $tokenPost = (string) ($_POST['csrf_token'] ?? '');

    if (
        $tokenSesion === ''
        || $tokenPost === ''
        || !hash_equals($tokenSesion, $tokenPost)
    ) {
        $_SESSION['promociones_flash'] = [
            'tipo' => 'error',
            'mensaje' => 'La sesión del formulario expiró. Intenta nuevamente.',
        ];
        header('Location: promociones.php');
        exit;
    }
}

function promo_redirigir(string $url = 'promociones.php'): void
{
    header('Location: ' . $url);
    exit;
}

function promo_flash(string $tipo, string $mensaje): void
{
    $_SESSION['promociones_flash'] = [
        'tipo' => $tipo,
        'mensaje' => $mensaje,
    ];
}

function promo_auditar($conn, int $usuarioId, string $accion, string $detalle): void
{
    $stmt = $conn->prepare("
        INSERT INTO auditoria (usuario_id, accion, detalle, fecha)
        VALUES (?, ?, ?, NOW())
    ");

    if (!$stmt) {
        return;
    }

    $stmt->bind_param('iss', $usuarioId, $accion, $detalle);
    $stmt->execute();
    $stmt->close();
}

function promo_obtener_producto($conn, int $productoId): ?array
{
    $stmt = $conn->prepare("
        SELECT
            id,
            nombre,
            categoria,
            proveedor,
            imagen,
            precio_venta,
            cantidad,
            stock_especial
        FROM productos
        WHERE id = ?
          AND activo = 1
          AND tipo_inventario = 'producto'
        LIMIT 1
    ");

    if (!$stmt) {
        return null;
    }

    $stmt->bind_param('i', $productoId);
    $stmt->execute();
    $resultado = $stmt->get_result();
    $producto = $resultado ? $resultado->fetch_assoc() : null;
    $stmt->close();

    return $producto ?: null;
}

function promo_obtener_registro($conn, int $promocionId): ?array
{
    $stmt = $conn->prepare("
        SELECT pr.*, p.nombre AS producto_nombre, p.precio_venta
        FROM promociones pr
        INNER JOIN productos p ON p.id = pr.producto_id
        WHERE pr.id = ?
          AND pr.eliminado = 0
        LIMIT 1
    ");

    if (!$stmt) {
        return null;
    }

    $stmt->bind_param('i', $promocionId);
    $stmt->execute();
    $resultado = $stmt->get_result();
    $registro = $resultado ? $resultado->fetch_assoc() : null;
    $stmt->close();

    return $registro ?: null;
}

function promo_desactivar_otras($conn, int $productoId, int $exceptoId, int $usuarioId): void
{
    $stmt = $conn->prepare("
        UPDATE promociones
        SET activo = 0,
            actualizado_por = ?,
            updated_at = NOW()
        WHERE producto_id = ?
          AND id <> ?
          AND activo = 1
          AND eliminado = 0
    ");

    if (!$stmt) {
        throw new RuntimeException('No fue posible preparar la actualización de promociones anteriores.');
    }

    $stmt->bind_param('iii', $usuarioId, $productoId, $exceptoId);
    $stmt->execute();
    $stmt->close();
}

function promo_estado(array $promocion): array
{
    $hoy = date('Y-m-d');
    $activa = (int) ($promocion['activo'] ?? 0) === 1;
    $inicio = trim((string) ($promocion['fecha_inicio'] ?? ''));
    $fin = trim((string) ($promocion['fecha_fin'] ?? ''));

    if (!$activa) {
        return ['clave' => 'inactiva', 'texto' => 'Inactiva'];
    }

    if ($fin !== '' && $fin < $hoy) {
        return ['clave' => 'vencida', 'texto' => 'Vencida'];
    }

    if ($inicio !== '' && $inicio > $hoy) {
        return ['clave' => 'programada', 'texto' => 'Programada'];
    }

    return ['clave' => 'activa', 'texto' => 'Activa'];
}

// ---------------------------------------------------------------------
// Acciones POST
// ---------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    promo_csrf_validar();

    $accion = trim((string) ($_POST['accion'] ?? 'guardar'));

    try {
        if ($accion === 'guardar') {
            $promocionId = (int) ($_POST['promocion_id'] ?? 0);
            $productoId = (int) ($_POST['producto_id'] ?? 0);
            $cantidadPromocion = (int) ($_POST['cantidad_promocion'] ?? 0);
            $precioPromocion = round((float) ($_POST['precio_promocion'] ?? 0), 2);
            $fechaInicio = trim((string) ($_POST['fecha_inicio'] ?? ''));
            $fechaFin = trim((string) ($_POST['fecha_fin'] ?? ''));
            $activo = isset($_POST['activo']) ? 1 : 0;

            $producto = promo_obtener_producto($conn, $productoId);

            if (!$producto) {
                throw new InvalidArgumentException('Selecciona un producto válido y activo.');
            }

            if ($cantidadPromocion < 2) {
                throw new InvalidArgumentException('La promoción debe requerir al menos 2 unidades.');
            }

            if ($precioPromocion <= 0) {
                throw new InvalidArgumentException('El precio de promoción debe ser mayor que cero.');
            }

            $precioRegularPaquete = round(
                ((float) $producto['precio_venta']) * $cantidadPromocion,
                2
            );

            if ($precioPromocion >= $precioRegularPaquete) {
                throw new InvalidArgumentException(
                    'El precio promocional debe ser menor a ' . promo_dinero($precioRegularPaquete) . '.'
                );
            }

            if (!promo_fecha_valida($fechaInicio) || !promo_fecha_valida($fechaFin)) {
                throw new InvalidArgumentException('Las fechas de la promoción no son válidas.');
            }

            if ($fechaInicio !== '' && $fechaFin !== '' && $fechaInicio > $fechaFin) {
                throw new InvalidArgumentException('La fecha final no puede ser anterior a la fecha inicial.');
            }

            $fechaInicioDb = $fechaInicio !== '' ? $fechaInicio : null;
            $fechaFinDb = $fechaFin !== '' ? $fechaFin : null;

            $conn->begin_transaction();

            if ($promocionId > 0) {
                $existente = promo_obtener_registro($conn, $promocionId);

                if (!$existente) {
                    throw new RuntimeException('La promoción seleccionada ya no existe.');
                }

                if ($activo === 1) {
                    promo_desactivar_otras($conn, $productoId, $promocionId, $usuarioId);
                }

                $stmt = $conn->prepare("
                    UPDATE promociones
                    SET producto_id = ?,
                        cantidad_promocion = ?,
                        precio_promocion = ?,
                        fecha_inicio = ?,
                        fecha_fin = ?,
                        activo = ?,
                        actualizado_por = ?,
                        updated_at = NOW()
                    WHERE id = ?
                      AND eliminado = 0
                ");

                if (!$stmt) {
                    throw new RuntimeException('No fue posible preparar la edición de la promoción.');
                }

                $stmt->bind_param(
                    'iidssiii',
                    $productoId,
                    $cantidadPromocion,
                    $precioPromocion,
                    $fechaInicioDb,
                    $fechaFinDb,
                    $activo,
                    $usuarioId,
                    $promocionId
                );
                $stmt->execute();
                $stmt->close();

                promo_auditar(
                    $conn,
                    $usuarioId,
                    'EDITAR_PROMOCION',
                    sprintf(
                        'Editó la promoción ID %d: %d unidades de %s por %s.',
                        $promocionId,
                        $cantidadPromocion,
                        $producto['nombre'],
                        promo_dinero($precioPromocion)
                    )
                );

                $mensaje = 'La promoción se actualizó correctamente.';
            } else {
                if ($activo === 1) {
                    promo_desactivar_otras($conn, $productoId, 0, $usuarioId);
                }

                $stmt = $conn->prepare("
                    INSERT INTO promociones (
                        producto_id,
                        cantidad_promocion,
                        precio_promocion,
                        fecha_inicio,
                        fecha_fin,
                        activo,
                        creado_por,
                        actualizado_por,
                        eliminado,
                        created_at,
                        updated_at
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 0, NOW(), NOW())
                ");

                if (!$stmt) {
                    throw new RuntimeException('No fue posible preparar el registro de la promoción.');
                }

                $stmt->bind_param(
                    'iidssiii',
                    $productoId,
                    $cantidadPromocion,
                    $precioPromocion,
                    $fechaInicioDb,
                    $fechaFinDb,
                    $activo,
                    $usuarioId,
                    $usuarioId
                );
                $stmt->execute();
                $promocionId = (int) $stmt->insert_id;
                $stmt->close();

                promo_auditar(
                    $conn,
                    $usuarioId,
                    'CREAR_PROMOCION',
                    sprintf(
                        'Creó la promoción ID %d: %d unidades de %s por %s.',
                        $promocionId,
                        $cantidadPromocion,
                        $producto['nombre'],
                        promo_dinero($precioPromocion)
                    )
                );

                $mensaje = 'La promoción se creó correctamente.';
            }

            $conn->commit();
            promo_flash('success', $mensaje);
            promo_redirigir('promociones.php');
        }

        if ($accion === 'alternar') {
            $promocionId = (int) ($_POST['promocion_id'] ?? 0);
            $promocion = promo_obtener_registro($conn, $promocionId);

            if (!$promocion) {
                throw new RuntimeException('La promoción seleccionada ya no existe.');
            }

            $nuevoEstado = (int) $promocion['activo'] === 1 ? 0 : 1;

            if (
                $nuevoEstado === 1
                && !empty($promocion['fecha_fin'])
                && $promocion['fecha_fin'] < date('Y-m-d')
            ) {
                throw new InvalidArgumentException('Actualiza la fecha final antes de activar una promoción vencida.');
            }

            $conn->begin_transaction();

            if ($nuevoEstado === 1) {
                promo_desactivar_otras(
                    $conn,
                    (int) $promocion['producto_id'],
                    $promocionId,
                    $usuarioId
                );
            }

            $stmt = $conn->prepare("
                UPDATE promociones
                SET activo = ?,
                    actualizado_por = ?,
                    updated_at = NOW()
                WHERE id = ?
                  AND eliminado = 0
            ");

            if (!$stmt) {
                throw new RuntimeException('No fue posible cambiar el estado de la promoción.');
            }

            $stmt->bind_param('iii', $nuevoEstado, $usuarioId, $promocionId);
            $stmt->execute();
            $stmt->close();

            promo_auditar(
                $conn,
                $usuarioId,
                $nuevoEstado === 1 ? 'ACTIVAR_PROMOCION' : 'DESACTIVAR_PROMOCION',
                sprintf(
                    '%s la promoción ID %d de %s.',
                    $nuevoEstado === 1 ? 'Activó' : 'Desactivó',
                    $promocionId,
                    $promocion['producto_nombre']
                )
            );

            $conn->commit();
            promo_flash(
                'success',
                $nuevoEstado === 1
                    ? 'La promoción quedó activa.'
                    : 'La promoción quedó inactiva.'
            );
            promo_redirigir('promociones.php');
        }

        if ($accion === 'eliminar') {
            $promocionId = (int) ($_POST['promocion_id'] ?? 0);
            $promocion = promo_obtener_registro($conn, $promocionId);

            if (!$promocion) {
                throw new RuntimeException('La promoción seleccionada ya no existe.');
            }

            $stmt = $conn->prepare("
                UPDATE promociones
                SET activo = 0,
                    eliminado = 1,
                    actualizado_por = ?,
                    updated_at = NOW()
                WHERE id = ?
            ");

            if (!$stmt) {
                throw new RuntimeException('No fue posible eliminar la promoción.');
            }

            $stmt->bind_param('ii', $usuarioId, $promocionId);
            $stmt->execute();
            $stmt->close();

            promo_auditar(
                $conn,
                $usuarioId,
                'ELIMINAR_PROMOCION',
                sprintf(
                    'Eliminó la promoción ID %d de %s.',
                    $promocionId,
                    $promocion['producto_nombre']
                )
            );

            promo_flash('success', 'La promoción se eliminó del catálogo.');
            promo_redirigir('promociones.php');
        }
    } catch (Throwable $error) {
        try {
            $conn->rollback();
        } catch (Throwable $rollbackError) {
            // La transacción pudo no haberse iniciado.
        }

        promo_flash('error', $error->getMessage());
        $destino = 'promociones.php';

        if (!empty($_POST['promocion_id'])) {
            $destino .= '?editar=' . (int) $_POST['promocion_id'] . '#formulario-promocion';
        }

        promo_redirigir($destino);
    }
}

// ---------------------------------------------------------------------
// Datos de la pantalla
// ---------------------------------------------------------------------
$productos = [];
$resultadoProductos = $conn->query("
    SELECT
        id,
        nombre,
        categoria,
        proveedor,
        imagen,
        precio_venta,
        cantidad,
        stock_especial
    FROM productos
    WHERE activo = 1
      AND tipo_inventario = 'producto'
    ORDER BY nombre ASC
");

if ($resultadoProductos) {
    while ($fila = $resultadoProductos->fetch_assoc()) {
        $productos[] = $fila;
    }
}

$editarId = (int) ($_GET['editar'] ?? 0);
$promocionEditar = $editarId > 0
    ? promo_obtener_registro($conn, $editarId)
    : null;

$busqueda = trim((string) ($_GET['q'] ?? ''));
$filtroEstado = trim((string) ($_GET['estado'] ?? 'todos'));
$estadosValidos = ['todos', 'activa', 'programada', 'vencida', 'inactiva'];

if (!in_array($filtroEstado, $estadosValidos, true)) {
    $filtroEstado = 'todos';
}

$pagina = max(1, (int) ($_GET['pagina'] ?? 1));
$porPagina = 10;
$offset = ($pagina - 1) * $porPagina;

$where = ['pr.eliminado = 0'];
$parametros = [];
$tipos = '';

if ($busqueda !== '') {
    $where[] = "(
        p.nombre LIKE ?
        OR p.categoria LIKE ?
        OR p.proveedor LIKE ?
    )";
    $termino = '%' . $busqueda . '%';
    $parametros[] = $termino;
    $parametros[] = $termino;
    $parametros[] = $termino;
    $tipos .= 'sss';
}

if ($filtroEstado === 'activa') {
    $where[] = "pr.activo = 1
        AND (pr.fecha_inicio IS NULL OR pr.fecha_inicio <= CURDATE())
        AND (pr.fecha_fin IS NULL OR pr.fecha_fin >= CURDATE())";
} elseif ($filtroEstado === 'programada') {
    $where[] = "pr.activo = 1 AND pr.fecha_inicio > CURDATE()";
} elseif ($filtroEstado === 'vencida') {
    $where[] = "pr.activo = 1 AND pr.fecha_fin IS NOT NULL AND pr.fecha_fin < CURDATE()";
} elseif ($filtroEstado === 'inactiva') {
    $where[] = 'pr.activo = 0';
}

$whereSql = implode(' AND ', $where);

function promo_bind_parametros($stmt, string $tipos, array &$parametros): void
{
    if ($tipos === '' || empty($parametros)) {
        return;
    }

    $referencias = [$tipos];

    foreach ($parametros as $indice => $valor) {
        $referencias[] = &$parametros[$indice];
    }

    call_user_func_array([$stmt, 'bind_param'], $referencias);
}

$totalRegistros = 0;
$stmtConteo = $conn->prepare("
    SELECT COUNT(*) AS total
    FROM promociones pr
    INNER JOIN productos p ON p.id = pr.producto_id
    WHERE {$whereSql}
");

if ($stmtConteo) {
    promo_bind_parametros($stmtConteo, $tipos, $parametros);
    $stmtConteo->execute();
    $resultadoConteo = $stmtConteo->get_result();
    $totalRegistros = (int) (($resultadoConteo ? $resultadoConteo->fetch_assoc()['total'] : 0) ?? 0);
    $stmtConteo->close();
}

$totalPaginas = max(1, (int) ceil($totalRegistros / $porPagina));

if ($pagina > $totalPaginas) {
    $pagina = $totalPaginas;
    $offset = ($pagina - 1) * $porPagina;
}

$promociones = [];
$stmtLista = $conn->prepare("
    SELECT
        pr.*,
        p.nombre AS producto_nombre,
        p.categoria,
        p.proveedor,
        p.imagen,
        p.precio_venta,
        p.cantidad,
        p.stock_especial
    FROM promociones pr
    INNER JOIN productos p ON p.id = pr.producto_id
    WHERE {$whereSql}
    ORDER BY
        CASE
            WHEN pr.activo = 1
             AND (pr.fecha_inicio IS NULL OR pr.fecha_inicio <= CURDATE())
             AND (pr.fecha_fin IS NULL OR pr.fecha_fin >= CURDATE()) THEN 0
            WHEN pr.activo = 1 AND pr.fecha_inicio > CURDATE() THEN 1
            WHEN pr.activo = 0 THEN 2
            ELSE 3
        END,
        pr.updated_at DESC,
        pr.id DESC
    LIMIT ? OFFSET ?
");

if ($stmtLista) {
    $parametrosLista = $parametros;
    $parametrosLista[] = $porPagina;
    $parametrosLista[] = $offset;
    $tiposLista = $tipos . 'ii';
    promo_bind_parametros($stmtLista, $tiposLista, $parametrosLista);
    $stmtLista->execute();
    $resultadoLista = $stmtLista->get_result();

    if ($resultadoLista) {
        while ($fila = $resultadoLista->fetch_assoc()) {
            $promociones[] = $fila;
        }
    }

    $stmtLista->close();
}

$estadisticas = [
    'activas' => 0,
    'programadas' => 0,
    'vencidas' => 0,
    'inactivas' => 0,
];

$resultadoStats = $conn->query("
    SELECT
        SUM(CASE
            WHEN activo = 1
             AND (fecha_inicio IS NULL OR fecha_inicio <= CURDATE())
             AND (fecha_fin IS NULL OR fecha_fin >= CURDATE())
            THEN 1 ELSE 0 END) AS activas,
        SUM(CASE
            WHEN activo = 1 AND fecha_inicio > CURDATE()
            THEN 1 ELSE 0 END) AS programadas,
        SUM(CASE
            WHEN activo = 1 AND fecha_fin IS NOT NULL AND fecha_fin < CURDATE()
            THEN 1 ELSE 0 END) AS vencidas,
        SUM(CASE WHEN activo = 0 THEN 1 ELSE 0 END) AS inactivas
    FROM promociones
    WHERE eliminado = 0
");

if ($resultadoStats) {
    $filaStats = $resultadoStats->fetch_assoc();

    foreach ($estadisticas as $clave => $valor) {
        $estadisticas[$clave] = (int) ($filaStats[$clave] ?? 0);
    }
}

$flash = $_SESSION['promociones_flash'] ?? null;
unset($_SESSION['promociones_flash']);

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/navbar.php';

$cssRuta = __DIR__ . '/css/promociones.css';
$cssVersion = is_file($cssRuta) ? filemtime($cssRuta) : time();
?>
<link rel="stylesheet" href="css/promociones.css?v=<?= (int) $cssVersion ?>">

<div class="content-wrapper promociones-page">
    <div class="container-fluid promociones-container">
        <header class="promociones-hero">
            <div class="promociones-hero-icon">
                <i class="fas fa-tags"></i>
            </div>
            <div class="promociones-hero-copy">
                <span class="promociones-eyebrow">Ventas y catálogo</span>
                <h1>Promociones</h1>
                <p>Administra las ofertas especiales de tus productos y paquetes.</p>   
            </div>
        </header>

        <section class="promociones-stats">
            <article class="promo-stat-card promo-stat-card-active">
                <div class="promo-stat-card-copy">
                    <strong><?= $estadisticas['activas'] ?></strong>
                    <span>Activas ahora</span>
                    <small>Promociones vigentes y listas para vender.</small>
                </div>
                <div class="promo-stat-card-icon">
                    <i class="fas fa-bolt"></i>
                </div>
                <div class="promo-stat-card-orb"></div>
            </article>
            <article class="promo-stat-card promo-stat-card-scheduled">
                <div class="promo-stat-card-copy">
                    <strong><?= $estadisticas['programadas'] ?></strong>
                    <span>Programadas</span>
                    <small>Ofertas preparadas para activarse después.</small>
                </div>
                <div class="promo-stat-card-icon">
                    <i class="fas fa-calendar-days"></i>
                </div>
                <div class="promo-stat-card-orb"></div>
            </article>
            <article class="promo-stat-card promo-stat-card-expired">
                <div class="promo-stat-card-copy">
                    <strong><?= $estadisticas['vencidas'] ?></strong>
                    <span>Vencidas</span>
                    <small>Promociones que ya requieren revisión.</small>
                </div>
                <div class="promo-stat-card-icon">
                    <i class="fas fa-hourglass-end"></i>
                </div>
                <div class="promo-stat-card-orb"></div>
            </article>
            <article class="promo-stat-card promo-stat-card-inactive">
                <div class="promo-stat-card-copy">
                    <strong><?= $estadisticas['inactivas'] ?></strong>
                    <span>Inactivas</span>
                    <small>Guardadas, pero desactivadas por ahora.</small>
                </div>
                <div class="promo-stat-card-icon">
                    <i class="fas fa-pause"></i>
                </div>
                <div class="promo-stat-card-orb"></div>
            </article>
        </section>

        <section class="promociones-layout">
            <article class="promociones-panel promociones-form-panel" id="formulario-promocion">
                <div class="panel-heading">
                    <div class="panel-heading-icon"><i class="fas fa-wand-magic-sparkles"></i></div>
                    <div>
                        <h2><?= $promocionEditar ? 'Editar promoción' : 'Nueva promoción' ?></h2>
                        <p>El precio indicado será el total del paquete completo.</p>
                    </div>
                </div>

                <form method="post" id="promoForm" autocomplete="off" novalidate>
                    <input type="hidden" name="csrf_token" value="<?= promo_h(promo_csrf_token()) ?>">
                    <input type="hidden" name="accion" value="guardar">
                    <input type="hidden" name="promocion_id" value="<?= (int) ($promocionEditar['id'] ?? 0) ?>">
                    <input type="hidden" name="producto_id" id="producto_id" value="<?= (int) ($promocionEditar['producto_id'] ?? 0) ?>">

                    <div class="form-group">
                        <label for="producto_busqueda">Producto <span>*</span></label>
                        <div class="product-picker" id="productPicker">
                            <div class="input-icon-wrap product-picker-control">
                                <i class="fas fa-box"></i>
                                <input
                                    type="text"
                                    id="producto_busqueda"
                                    placeholder="Busca por nombre, categoría o ID..."
                                    value="<?= $promocionEditar ? promo_h($promocionEditar['producto_nombre'] . ' · #' . $promocionEditar['producto_id']) : '' ?>"
                                    autocomplete="off"
                                    required
                                >
                                <button type="button" class="product-picker-toggle" id="productPickerToggle" aria-label="Mostrar productos">
                                    <i class="fas fa-chevron-down"></i>
                                </button>
                            </div>
                            <div class="product-picker-menu" id="productPickerMenu" hidden>
                                <div class="product-picker-results" id="productPickerResults"></div>
                                <div class="product-picker-empty" id="productPickerEmpty">No se encontraron productos.</div>
                            </div>
                        </div>
                        <small>Incluye productos normales y productos especiales sin límite.</small>
                    </div>

                    <div class="producto-seleccionado" id="productoSeleccionado">
                        <div class="producto-seleccionado-icon"><i class="fas fa-box-open"></i></div>
                        <div>
                            <strong>Selecciona un producto</strong>
                            <span>Aquí verás su precio actual.</span>
                        </div>
                    </div>

                    <div class="form-grid-2">
                        <div class="form-group">
                            <label for="cantidad_promocion">Cantidad requerida <span>*</span></label>
                            <div class="input-icon-wrap">
                                <i class="fas fa-layer-group"></i>
                                <input
                                    type="number"
                                    min="2"
                                    step="1"
                                    id="cantidad_promocion"
                                    name="cantidad_promocion"
                                    value="<?= promo_h($promocionEditar['cantidad_promocion'] ?? 3) ?>"
                                    required
                                >
                            </div>
                        </div>

                        <div class="form-group">
                            <label for="precio_promocion">Precio del paquete <span>*</span></label>
                            <div class="input-icon-wrap">
                                <i class="fas fa-dollar-sign"></i>
                                <input
                                    type="number"
                                    min="0.01"
                                    step="0.01"
                                    id="precio_promocion"
                                    name="precio_promocion"
                                    value="<?= promo_h($promocionEditar['precio_promocion'] ?? '') ?>"
                                    placeholder="25.00"
                                    required
                                >
                            </div>
                        </div>
                    </div>

                    <div class="promo-preview" id="promoPreview">
                        <div class="promo-preview-main">
                            <span>Ejemplo de promoción</span>
                            <strong>Selecciona un producto y captura el precio.</strong>
                        </div>
                        <div class="promo-preview-saving" id="promoAhorro">Ahorro: $0</div>
                    </div>

                    <div class="form-grid-2">
                        <div class="form-group">
                            <label for="fecha_inicio">Inicia</label>
                            <input
                                type="date"
                                id="fecha_inicio"
                                name="fecha_inicio"
                                value="<?= promo_h($promocionEditar['fecha_inicio'] ?? '') ?>"
                            >
                        </div>
                        <div class="form-group">
                            <label for="fecha_fin">Finaliza</label>
                            <input
                                type="date"
                                id="fecha_fin"
                                name="fecha_fin"
                                value="<?= promo_h($promocionEditar['fecha_fin'] ?? '') ?>"
                            >
                        </div>
                    </div>

                    <label class="promo-switch-row">
                        <span class="promo-switch-copy">
                            <strong>Promoción activa</strong>
                            <small>Al activarla, cualquier promoción anterior del producto se desactiva.</small>
                        </span>
                        <span class="promo-switch">
                            <input
                                type="checkbox"
                                name="activo"
                                value="1"
                                <?= !$promocionEditar || (int) $promocionEditar['activo'] === 1 ? 'checked' : '' ?>
                            >
                            <span></span>
                        </span>
                    </label>

                    <div class="form-actions">
                        <?php if ($promocionEditar): ?>
                            <a href="promociones.php" class="btn-cancelar">
                                <i class="fas fa-times"></i> Cancelar
                            </a>
                        <?php endif; ?>
                        <button type="submit" class="btn-guardar-promocion">
                            <i class="fas fa-floppy-disk"></i>
                            <?= $promocionEditar ? 'Guardar cambios' : 'Crear promoción' ?>
                        </button>
                    </div>
                </form>
            </article>

            <article class="promociones-panel promociones-catalog-panel">
                <div class="catalog-heading">
                    <div>
                        <span class="promociones-eyebrow">Catálogo</span>
                        <h2>Promociones registradas</h2>
                        <p>Solo una promoción puede estar activa por producto.</p>
                    </div>
                    <span class="catalog-count"><?= number_format($totalRegistros) ?></span>
                </div>

                <form method="get" class="promo-toolbar">
                    <div class="promo-search">
                        <i class="fas fa-search"></i>
                        <input
                            type="search"
                            name="q"
                            value="<?= promo_h($busqueda) ?>"
                            placeholder="Buscar producto, categoría o proveedor..."
                        >
                    </div>
                    <select name="estado" aria-label="Filtrar por estado">
                        <option value="todos" <?= $filtroEstado === 'todos' ? 'selected' : '' ?>>Todos los estados</option>
                        <option value="activa" <?= $filtroEstado === 'activa' ? 'selected' : '' ?>>Activas</option>
                        <option value="programada" <?= $filtroEstado === 'programada' ? 'selected' : '' ?>>Programadas</option>
                        <option value="vencida" <?= $filtroEstado === 'vencida' ? 'selected' : '' ?>>Vencidas</option>
                        <option value="inactiva" <?= $filtroEstado === 'inactiva' ? 'selected' : '' ?>>Inactivas</option>
                    </select>
                    <button type="submit"><i class="fas fa-filter"></i> Filtrar</button>
                    <?php if ($busqueda !== '' || $filtroEstado !== 'todos'): ?>
                        <a href="promociones.php" class="promo-clear"><i class="fas fa-eraser"></i></a>
                    <?php endif; ?>
                </form>

                <?php if (empty($promociones)): ?>
                    <div class="promo-empty">
                        <i class="fas fa-tags"></i>
                        <h3>No hay promociones para mostrar</h3>
                        <p>Crea una oferta o cambia los filtros actuales.</p>
                    </div>
                <?php else: ?>
                    <div class="promociones-list">
                        <?php foreach ($promociones as $promocion):
                            $estado = promo_estado($promocion);
                            $cantidad = (int) $promocion['cantidad_promocion'];
                            $precioVenta = (float) $promocion['precio_venta'];
                            $precioNormal = round($precioVenta * $cantidad, 2);
                            $precioOferta = (float) $promocion['precio_promocion'];
                            $ahorro = max(0, round($precioNormal - $precioOferta, 2));
                            $porcentaje = $precioNormal > 0 ? round(($ahorro / $precioNormal) * 100) : 0;
                            $sinAhorro = $ahorro <= 0;
                        ?>
                            <article class="promotion-card <?= $sinAhorro ? 'promotion-card-warning' : '' ?>">
                                <div class="promotion-card-header">
                                    <div class="promotion-product">
                                        <div class="promotion-product-image">
                                            <?php if (!empty($promocion['imagen']) && is_file(__DIR__ . '/' . ltrim($promocion['imagen'], '/\\'))): ?>
                                                <img src="<?= promo_h($promocion['imagen']) ?>" alt="<?= promo_h($promocion['producto_nombre']) ?>">
                                            <?php else: ?>
                                                <i class="fas fa-box"></i>
                                            <?php endif; ?>
                                            <?php if ((int) $promocion['stock_especial'] === 1): ?>
                                                <span class="special-product-mark" title="Producto especial"><i class="fas fa-infinity"></i></span>
                                            <?php endif; ?>
                                        </div>
                                        <div class="promotion-product-copy">
                                            <div class="promotion-badges-row">
                                                <div class="promotion-state state-<?= promo_h($estado['clave']) ?>">
                                                    <?= promo_h($estado['texto']) ?>
                                                </div>
                                                <?php if ((int) $promocion['stock_especial'] === 1): ?>
                                                    <div class="promotion-secondary-badge"><i class="fas fa-infinity"></i> Especial</div>
                                                <?php endif; ?>
                                            </div>
                                            <h3><?= promo_h($promocion['producto_nombre']) ?></h3>
                                            <p>
                                                <?= promo_h($promocion['categoria'] ?: 'Sin categoría') ?>
                                                <span>•</span>
                                                <?= promo_h($promocion['proveedor'] ?: 'Sin proveedor') ?>
                                            </p>
                                        </div>
                                    </div>

                                    <div class="promotion-actions">
                                        <a href="promociones.php?editar=<?= (int) $promocion['id'] ?>#formulario-promocion" class="action-edit" title="Editar">
                                            <i class="fas fa-pen"></i>
                                        </a>
                                        <form
                                            method="post"
                                            class="js-toggle-promo"
                                            data-producto="<?= promo_h($promocion['producto_nombre']) ?>"
                                            data-estado="<?= (int) $promocion['activo'] === 1 ? 'desactivar' : 'activar' ?>"
                                        >
                                            <input type="hidden" name="csrf_token" value="<?= promo_h(promo_csrf_token()) ?>">
                                            <input type="hidden" name="accion" value="alternar">
                                            <input type="hidden" name="promocion_id" value="<?= (int) $promocion['id'] ?>">
                                            <button type="submit" class="<?= (int) $promocion['activo'] === 1 ? 'action-pause' : 'action-play' ?>" title="<?= (int) $promocion['activo'] === 1 ? 'Desactivar' : 'Activar' ?>">
                                                <i class="fas <?= (int) $promocion['activo'] === 1 ? 'fa-pause' : 'fa-play' ?>"></i>
                                            </button>
                                        </form>
                                        <form
                                            method="post"
                                            class="js-delete-promo"
                                            data-producto="<?= promo_h($promocion['producto_nombre']) ?>"
                                        >
                                            <input type="hidden" name="csrf_token" value="<?= promo_h(promo_csrf_token()) ?>">
                                            <input type="hidden" name="accion" value="eliminar">
                                            <input type="hidden" name="promocion_id" value="<?= (int) $promocion['id'] ?>">
                                            <button type="submit" class="action-delete" title="Eliminar">
                                                <i class="fas fa-trash"></i>
                                            </button>
                                        </form>
                                    </div>
                                </div>

                                <div class="promotion-card-body">
                                    <div class="promotion-metric promotion-offer">
                                        <span>Oferta</span>
                                        <strong><?= $cantidad ?> por <?= promo_dinero($precioOferta) ?></strong>
                                        <small><?= promo_dinero($precioOferta / max(1, $cantidad)) ?> por unidad</small>
                                    </div>

                                    <div class="promotion-metric promotion-saving">
                                        <?php if ($sinAhorro): ?>
                                            <strong><i class="fas fa-triangle-exclamation"></i> Revisar precio</strong>
                                            <small>La oferta ya no reduce el precio actual.</small>
                                        <?php else: ?>
                                            <strong>Ahorras <?= promo_dinero($ahorro) ?></strong>
                                            <small><?= $porcentaje ?>% menos que <?= promo_dinero($precioNormal) ?></small>
                                        <?php endif; ?>
                                    </div>

                                    <div class="promotion-metric promotion-dates">
                                        <span><i class="fas fa-calendar-day"></i> <?= !empty($promocion['fecha_inicio']) ? date('d/m/Y', strtotime($promocion['fecha_inicio'])) : 'Inicio inmediato' ?></span>
                                        <span><i class="fas fa-calendar-check"></i> <?= !empty($promocion['fecha_fin']) ? date('d/m/Y', strtotime($promocion['fecha_fin'])) : 'Sin vencimiento' ?></span>
                                    </div>
                                </div>

                                <div class="promotion-card-footer">
                                    <span class="promotion-footer-price">Precio normal del paquete: <?= promo_dinero($precioNormal) ?></span>
                                    <span class="promotion-footer-id">Producto #<?= (int) $promocion['producto_id'] ?></span>
                                </div>
                            </article>
                        <?php endforeach; ?>
                    </div>

                    <?php if ($totalPaginas > 1): ?>
                        <nav class="promo-pagination" aria-label="Paginación">
                            <?php
                            $queryBase = [
                                'q' => $busqueda,
                                'estado' => $filtroEstado,
                            ];
                            $inicioPaginas = max(1, $pagina - 2);
                            $finPaginas = min($totalPaginas, $pagina + 2);
                            ?>
                            <a class="<?= $pagina <= 1 ? 'disabled' : '' ?>" href="?<?= http_build_query(array_merge($queryBase, ['pagina' => max(1, $pagina - 1)])) ?>">
                                <i class="fas fa-chevron-left"></i>
                            </a>
                            <?php for ($numero = $inicioPaginas; $numero <= $finPaginas; $numero++): ?>
                                <a class="<?= $numero === $pagina ? 'active' : '' ?>" href="?<?= http_build_query(array_merge($queryBase, ['pagina' => $numero])) ?>">
                                    <?= $numero ?>
                                </a>
                            <?php endfor; ?>
                            <a class="<?= $pagina >= $totalPaginas ? 'disabled' : '' ?>" href="?<?= http_build_query(array_merge($queryBase, ['pagina' => min($totalPaginas, $pagina + 1)])) ?>">
                                <i class="fas fa-chevron-right"></i>
                            </a>
                            <span>Página <?= $pagina ?> de <?= $totalPaginas ?></span>
                        </nav>
                    <?php endif; ?>
                <?php endif; ?>
            </article>
        </section>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<style>
    .swal2-popup.promo-swal-popup {
        width: min(430px, calc(100vw - 28px));
        padding: 1.55rem 1.35rem 1.3rem;
        border-radius: 22px;
        font-family: inherit;
    }

    .swal2-popup.promo-swal-popup .swal2-title {
        color: #172033;
        font-size: 1.25rem;
        font-weight: 900;
        letter-spacing: -0.025em;
    }

    .swal2-popup.promo-swal-popup .swal2-html-container {
        margin-top: .65rem;
        color: #64748b;
        font-size: .88rem;
        line-height: 1.5;
    }

    .swal2-popup.promo-swal-popup .swal2-actions {
        gap: 8px;
        margin-top: 1.15rem;
    }

    .swal2-popup.promo-swal-popup .swal2-confirm,
    .swal2-popup.promo-swal-popup .swal2-cancel {
        min-height: 42px;
        margin: 0;
        padding: 0 17px;
        border: 0;
        border-radius: 12px;
        font-size: .78rem;
        font-weight: 850;
        box-shadow: none !important;
    }
</style>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const promoFlash = <?= json_encode(
        is_array($flash) ? $flash : null,
        JSON_UNESCAPED_UNICODE |
        JSON_UNESCAPED_SLASHES |
        JSON_HEX_TAG |
        JSON_HEX_APOS |
        JSON_HEX_AMP |
        JSON_HEX_QUOT
    ) ?>;

    const swalBase = {
        customClass: {
            popup: 'promo-swal-popup'
        },
        buttonsStyling: true,
        confirmButtonColor: '#f97316',
        cancelButtonColor: '#64748b',
        reverseButtons: true,
        allowEscapeKey: true
    };

    function promoSwal(opciones) {
        if (typeof Swal !== 'undefined') {
            return Swal.fire(Object.assign({}, swalBase, opciones));
        }

        const texto = opciones.text || opciones.html || '';
        const requiereConfirmacion = Boolean(opciones.showCancelButton);

        if (requiereConfirmacion) {
            return Promise.resolve({
                isConfirmed: window.confirm(`${opciones.title || ''}\n\n${texto}`)
            });
        }

        window.alert(`${opciones.title || ''}\n\n${texto}`);
        return Promise.resolve({ isConfirmed: true });
    }

    if (promoFlash && promoFlash.mensaje) {
        const esExito = promoFlash.tipo === 'success';

        promoSwal({
            icon: esExito ? 'success' : 'error',
            title: esExito ? 'Operación completada' : 'No se pudo completar',
            text: String(promoFlash.mensaje),
            showConfirmButton: !esExito,
            confirmButtonText: 'Entendido',
            timer: esExito ? 2800 : undefined,
            timerProgressBar: esExito
        });
    }

    const productos = <?= json_encode($productos, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>;
    const productoInput = document.getElementById('producto_busqueda');
    const productoIdInput = document.getElementById('producto_id');
    const productoSeleccionado = document.getElementById('productoSeleccionado');
    const cantidadInput = document.getElementById('cantidad_promocion');
    const precioInput = document.getElementById('precio_promocion');
    const preview = document.getElementById('promoPreview');
    const ahorro = document.getElementById('promoAhorro');
    const fechaInicio = document.getElementById('fecha_inicio');
    const fechaFin = document.getElementById('fecha_fin');
    const productPicker = document.getElementById('productPicker');
    const productPickerMenu = document.getElementById('productPickerMenu');
    const productPickerResults = document.getElementById('productPickerResults');
    const productPickerEmpty = document.getElementById('productPickerEmpty');
    const productPickerToggle = document.getElementById('productPickerToggle');
    const promoForm = document.getElementById('promoForm');

    let productoActual = null;
    let suppressClose = false;

    function moneda(valor) {
        return new Intl.NumberFormat('es-MX', {
            style: 'currency',
            currency: 'MXN',
            maximumFractionDigits: Number.isInteger(valor) ? 0 : 2
        }).format(valor);
    }

    function escapeHtml(texto) {
        return String(texto ?? '')
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    function normalizar(texto) {
        return String(texto || '')
            .normalize('NFD')
            .replace(/[̀-ͯ]/g, '')
            .toLowerCase()
            .trim();
    }

    function etiquetaProducto(producto) {
        return `${producto.nombre} · #${producto.id}`;
    }

    function buscarProductoPorId(id) {
        return productos.find(function (producto) {
            return Number(producto.id) === Number(id);
        }) || null;
    }

    function buscarProductos(termino) {
        const q = normalizar(termino);
        if (!q) {
            return productos.slice(0, 16);
        }

        return productos.filter(function (producto) {
            const nombre = normalizar(producto.nombre);
            const categoria = normalizar(producto.categoria);
            const proveedor = normalizar(producto.proveedor);
            const idTexto = String(producto.id);
            return nombre.includes(q)
                || categoria.includes(q)
                || proveedor.includes(q)
                || idTexto.includes(q)
                || (`#${idTexto}`).includes(q);
        }).slice(0, 16);
    }

    function abrirPicker() {
        productPicker.classList.add('is-open');
        productPickerMenu.hidden = false;
        renderResultados(productoInput.value);
    }

    function cerrarPicker() {
        productPicker.classList.remove('is-open');
        productPickerMenu.hidden = true;
    }

    function renderResultados(termino) {
        const resultados = buscarProductos(termino);
        productPickerResults.innerHTML = '';

        if (!resultados.length) {
            productPickerEmpty.style.display = 'block';
            return;
        }

        productPickerEmpty.style.display = 'none';

        resultados.forEach(function (producto) {
            const button = document.createElement('button');
            button.type = 'button';
            button.className = 'product-option';
            button.dataset.id = String(producto.id);

            const especial = Number(producto.stock_especial) === 1;
            const stockTexto = especial ? 'Especial · sin límite' : `${Number(producto.cantidad || 0)} disponibles`;

            button.innerHTML = `
                <div class="product-option-main">
                    <span class="product-option-name">${escapeHtml(producto.nombre)}</span>
                    <span class="product-option-meta">#${producto.id} · ${escapeHtml(producto.categoria || 'Sin categoría')}</span>
                </div>
                <div class="product-option-side">
                    <strong>${moneda(Number(producto.precio_venta || 0))}</strong>
                    <small>${stockTexto}</small>
                </div>
            `;

            button.addEventListener('mousedown', function () {
                suppressClose = true;
            });
            button.addEventListener('click', function () {
                seleccionarProducto(producto);
                suppressClose = false;
            });
            productPickerResults.appendChild(button);
        });
    }

    function mostrarProducto(producto) {
        productoActual = producto;

        if (!producto) {
            productoIdInput.value = '';
            productoSeleccionado.classList.remove('has-product');
            productoSeleccionado.innerHTML = `
                <div class="producto-seleccionado-icon"><i class="fas fa-box-open"></i></div>
                <div><strong>Selecciona un producto</strong><span>Aquí verás su precio actual.</span></div>
            `;
            actualizarPreview();
            return;
        }

        productoIdInput.value = producto.id;
        const esEspecial = Number(producto.stock_especial) === 1;
        const stockTexto = esEspecial ? 'Producto especial · sin límite' : `${Number(producto.cantidad || 0)} disponibles`;
        productoSeleccionado.innerHTML = `
            <div class="producto-seleccionado-icon"><i class="fas ${esEspecial ? 'fa-infinity' : 'fa-box'}"></i></div>
            <div>
                <strong>${escapeHtml(producto.nombre)}</strong>
                <span>Precio actual: ${moneda(Number(producto.precio_venta))} · ${stockTexto}</span>
            </div>
        `;
        productoSeleccionado.classList.add('has-product');
        actualizarPreview();
    }

    function seleccionarProducto(producto) {
        productoInput.value = etiquetaProducto(producto);
        mostrarProducto(producto);
        cerrarPicker();
    }

    function actualizarPreview() {
        const cantidad = Math.max(0, Number(cantidadInput.value || 0));
        const precioPromo = Math.max(0, Number(precioInput.value || 0));

        if (!productoActual || cantidad < 2 || precioPromo <= 0) {
            preview.classList.remove('valid', 'invalid');
            preview.querySelector('strong').textContent = 'Selecciona un producto y captura el precio.';
            ahorro.textContent = 'Ahorro: $0';
            return;
        }

        const precioUnitario = Number(productoActual.precio_venta || 0);
        const precioNormal = precioUnitario * cantidad;
        const ahorroValor = precioNormal - precioPromo;

        preview.querySelector('strong').textContent = `${cantidad} × ${moneda(precioUnitario)} = ${moneda(precioNormal)} → promoción ${moneda(precioPromo)}`;

        if (ahorroValor > 0) {
            preview.classList.add('valid');
            preview.classList.remove('invalid');
            ahorro.textContent = `Ahorro: ${moneda(ahorroValor)}`;
        } else {
            preview.classList.add('invalid');
            preview.classList.remove('valid');
            ahorro.textContent = 'El precio debe ser menor al total normal';
        }
    }

    productoInput.addEventListener('focus', function () {
        abrirPicker();
    });

    productoInput.addEventListener('input', function () {
        abrirPicker();
        renderResultados(this.value);

        const exacto = productos.find(function (producto) {
            return normalizar(etiquetaProducto(producto)) === normalizar(productoInput.value);
        }) || null;

        if (exacto) {
            mostrarProducto(exacto);
        } else if (!productoInput.value.trim()) {
            mostrarProducto(null);
        } else {
            productoIdInput.value = '';
            productoActual = null;
            actualizarPreview();
        }
    });

    productoInput.addEventListener('keydown', function (event) {
        if (event.key === 'ArrowDown') {
            abrirPicker();
            const first = productPickerResults.querySelector('.product-option');
            if (first) {
                event.preventDefault();
                first.focus();
            }
        }
        if (event.key === 'Escape') {
            cerrarPicker();
        }
    });

    productPickerToggle.addEventListener('click', function () {
        if (productPicker.classList.contains('is-open')) {
            cerrarPicker();
        } else {
            abrirPicker();
            productoInput.focus();
        }
    });

    document.addEventListener('click', function (event) {
        if (!productPicker.contains(event.target)) {
            cerrarPicker();
        }
    });

    document.addEventListener('focusin', function (event) {
        if (productPickerResults.contains(event.target) && event.target.classList.contains('product-option')) {
            event.target.addEventListener('keydown', function onKey(ev) {
                const opciones = Array.from(productPickerResults.querySelectorAll('.product-option'));
                const index = opciones.indexOf(event.target);
                if (ev.key === 'ArrowDown') {
                    ev.preventDefault();
                    (opciones[index + 1] || opciones[index]).focus();
                }
                if (ev.key === 'ArrowUp') {
                    ev.preventDefault();
                    if (index <= 0) {
                        productoInput.focus();
                    } else {
                        opciones[index - 1].focus();
                    }
                }
                if (ev.key === 'Enter' || ev.key === ' ') {
                    ev.preventDefault();
                    event.target.click();
                }
                if (ev.key === 'Escape') {
                    cerrarPicker();
                    productoInput.focus();
                }
            }, { once: true });
        }
    });

    productPicker.addEventListener('focusout', function () {
        setTimeout(function () {
            if (suppressClose) {
                suppressClose = false;
                return;
            }
            if (!productPicker.contains(document.activeElement)) {
                cerrarPicker();
            }
        }, 120);
    });

    cantidadInput.addEventListener('input', actualizarPreview);
    precioInput.addEventListener('input', actualizarPreview);

    promoForm.addEventListener('submit', async function (event) {
        event.preventDefault();

        const productoId = Number(productoIdInput.value || 0);
        const cantidad = Number(cantidadInput.value || 0);
        const precioPromo = Number(precioInput.value || 0);
        const inicio = fechaInicio.value || '';
        const fin = fechaFin.value || '';

        if (!productoId || !productoActual) {
            await promoSwal({
                icon: 'warning',
                title: 'Selecciona un producto',
                text: 'Elige un producto válido desde la lista antes de guardar la promoción.',
                confirmButtonText: 'Seleccionar producto'
            });
            productoInput.focus();
            abrirPicker();
            return;
        }

        if (!Number.isInteger(cantidad) || cantidad < 2) {
            await promoSwal({
                icon: 'warning',
                title: 'Cantidad no válida',
                text: 'La promoción debe requerir al menos 2 unidades.',
                confirmButtonText: 'Corregir cantidad'
            });
            cantidadInput.focus();
            return;
        }

        if (!Number.isFinite(precioPromo) || precioPromo <= 0) {
            await promoSwal({
                icon: 'warning',
                title: 'Precio no válido',
                text: 'Captura un precio de paquete mayor que cero.',
                confirmButtonText: 'Corregir precio'
            });
            precioInput.focus();
            return;
        }

        const precioNormal = Number(productoActual.precio_venta || 0) * cantidad;

        if (precioPromo >= precioNormal) {
            await promoSwal({
                icon: 'warning',
                title: 'La promoción no genera ahorro',
                html: `El precio del paquete debe ser menor que <strong>${moneda(precioNormal)}</strong>.`,
                confirmButtonText: 'Cambiar precio'
            });
            precioInput.focus();
            return;
        }

        if (inicio && fin && inicio > fin) {
            await promoSwal({
                icon: 'warning',
                title: 'Revisa las fechas',
                text: 'La fecha final no puede ser anterior a la fecha de inicio.',
                confirmButtonText: 'Corregir fechas'
            });
            fechaFin.focus();
            return;
        }

        if (typeof Swal !== 'undefined') {
            Swal.fire({
                customClass: { popup: 'promo-swal-popup' },
                title: Number(this.querySelector('[name="promocion_id"]').value || 0) > 0
                    ? 'Guardando cambios...'
                    : 'Creando promoción...',
                text: 'Espera un momento.',
                allowOutsideClick: false,
                allowEscapeKey: false,
                showConfirmButton: false,
                didOpen: function () {
                    Swal.showLoading();
                }
            });
        }

        this.submit();
    });

    const inicialId = Number(productoIdInput.value || 0);
    if (inicialId > 0) {
        const inicial = buscarProductoPorId(inicialId);
        if (inicial) {
            productoInput.value = etiquetaProducto(inicial);
            mostrarProducto(inicial);
        } else {
            actualizarPreview();
        }
    } else {
        actualizarPreview();
    }

    fechaInicio.addEventListener('change', function () {
        if (this.value) {
            fechaFin.min = this.value;
        } else {
            fechaFin.removeAttribute('min');
        }
    });

    if (fechaInicio.value) {
        fechaFin.min = fechaInicio.value;
    }

    document.querySelectorAll('.js-toggle-promo').forEach(function (formulario) {
        formulario.addEventListener('submit', async function (event) {
            event.preventDefault();

            const accion = formulario.dataset.estado === 'activar'
                ? 'activar'
                : 'desactivar';
            const producto = formulario.dataset.producto || 'este producto';
            const activar = accion === 'activar';

            const resultado = await promoSwal({
                icon: 'question',
                title: activar ? '¿Activar promoción?' : '¿Desactivar promoción?',
                html: activar
                    ? `La promoción de <strong>${escapeHtml(producto)}</strong> quedará disponible para las ventas.`
                    : `La promoción de <strong>${escapeHtml(producto)}</strong> dejará de aplicarse en las ventas.`,
                showCancelButton: true,
                confirmButtonText: activar ? 'Sí, activar' : 'Sí, desactivar',
                cancelButtonText: 'Cancelar',
                confirmButtonColor: activar ? '#16a34a' : '#f59e0b'
            });

            if (resultado.isConfirmed) {
                formulario.submit();
            }
        });
    });

    document.querySelectorAll('.js-delete-promo').forEach(function (formulario) {
        formulario.addEventListener('submit', async function (event) {
            event.preventDefault();

            const producto = formulario.dataset.producto || 'este producto';
            const resultado = await promoSwal({
                icon: 'warning',
                title: '¿Eliminar promoción?',
                html: `Se eliminará la promoción de <strong>${escapeHtml(producto)}</strong> del catálogo.`,
                showCancelButton: true,
                confirmButtonText: 'Sí, eliminar',
                cancelButtonText: 'Cancelar',
                confirmButtonColor: '#dc2626'
            });

            if (resultado.isConfirmed) {
                formulario.submit();
            }
        });
    });
});
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
