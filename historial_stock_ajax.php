<?php
ob_start();
session_start();
require_once 'includes/db.php';

if (!isset($_SESSION['usuario_id']) || $_SESSION['rol'] !== 'administrador') {
    exit;
}

// Obtener parámetros de la petición AJAX
$producto_id = isset($_GET['producto_id']) ? intval($_GET['producto_id']) : 0;
$proveedor_filtro = isset($_GET['proveedor']) ? trim($_GET['proveedor']) : '';
$fecha_desde = isset($_GET['fecha_desde']) ? $_GET['fecha_desde'] : '';
$fecha_hasta = isset($_GET['fecha_hasta']) ? $_GET['fecha_hasta'] : '';
$registros_por_pagina = isset($_GET['por_pagina']) ? $_GET['por_pagina'] : 25;
$pagina_actual = isset($_GET['pagina']) ? intval($_GET['pagina']) : 1;

// Validar valores permitidos para registros por página
$opciones_por_pagina = [25, 50, 100, 250, 500, 'todos'];
if (!in_array($registros_por_pagina, $opciones_por_pagina)) {
    $registros_por_pagina = 25;
}

// Obtener nombre del producto si está seleccionado
$producto_nombre = '';
if ($producto_id > 0) {
    $stmt = $conn->prepare("SELECT nombre FROM productos WHERE id = ?");
    $stmt->bind_param("i", $producto_id);
    $stmt->execute();
    $res = $stmt->get_result();
    $producto = $res->fetch_assoc();
    $producto_nombre = $producto['nombre'] ?? '';
}

// Construir query base
$base_query = "FROM historial_stock h 
               LEFT JOIN usuarios u ON h.usuario_id = u.id
               LEFT JOIN productos p ON h.producto_id = p.id
               WHERE 1=1";

if ($producto_id > 0) {
    $base_query .= " AND h.producto_id = $producto_id";
}

if (!empty($proveedor_filtro)) {
    $proveedor_filtro_escaped = $conn->real_escape_string($proveedor_filtro);
    $base_query .= " AND p.proveedor = '$proveedor_filtro_escaped'";
}

// Manejo de fechas - puede ser solo una o ambas
if (!empty($fecha_desde) && !empty($fecha_hasta)) {
    $base_query .= " AND DATE(h.fecha_movimiento) BETWEEN '" . $conn->real_escape_string($fecha_desde) . "' AND '" . $conn->real_escape_string($fecha_hasta) . "'";
} elseif (!empty($fecha_desde)) {
    $base_query .= " AND DATE(h.fecha_movimiento) >= '" . $conn->real_escape_string($fecha_desde) . "'";
} elseif (!empty($fecha_hasta)) {
    $base_query .= " AND DATE(h.fecha_movimiento) <= '" . $conn->real_escape_string($fecha_hasta) . "'";
}

// Obtener total de registros
$count_query = "SELECT COUNT(*) as total " . $base_query;
$total_result = $conn->query($count_query);
$total_registros = $total_result->fetch_assoc()['total'];

// Calcular offset
if ($registros_por_pagina === 'todos') {
    $offset = 0;
    $limit = $total_registros;
    $total_paginas = 1;
} else {
    $registros_por_pagina = intval($registros_por_pagina);
    $offset = ($pagina_actual - 1) * $registros_por_pagina;
    $total_paginas = ceil($total_registros / $registros_por_pagina);
}

// Asegurar que la página actual sea válida
if ($pagina_actual < 1) $pagina_actual = 1;
if ($pagina_actual > $total_paginas && $total_paginas > 0) $pagina_actual = $total_paginas;

// Recalcular offset después de validar página
if ($registros_por_pagina !== 'todos') {
    $offset = ($pagina_actual - 1) * $registros_por_pagina;
}

// Construir query final
$query = "SELECT h.*, u.nombre as usuario_nombre, p.nombre as producto_nombre, 
                 p.tipo_inventario, p.proveedor " . $base_query . " ORDER BY h.fecha_movimiento DESC";

if ($registros_por_pagina !== 'todos') {
    $query .= " LIMIT $offset, $registros_por_pagina";
}

$historial = $conn->query($query);

// Generar HTML de la tabla
ob_start();
if ($historial->num_rows === 0): ?>
    <tr>
        <td colspan="9" class="text-center py-4">
            <i class="fas fa-history fa-3x text-muted mb-3"></i>
            <h5 class="text-muted">No hay movimientos para mostrar</h5>
        </td>
    </tr>
<?php else: ?>
    <?php 
    $contador = ($registros_por_pagina === 'todos') ? 1 : $offset + 1;
    while ($row = $historial->fetch_assoc()): 
        
        // CORRECCIÓN: Manejo correcto de cantidades
        $cantidad_mostrar = $row['cantidad_agregada'];
        $color_clase = '';
        $signo = '';
        
        if ($row['tipo_movimiento'] == 'entrada') {
            // Entradas siempre son positivas
            $color_clase = 'text-success';
            $signo = '+';
            $cantidad_mostrar = abs($row['cantidad_agregada']);
        } elseif ($row['tipo_movimiento'] == 'salida') {
            // Salidas siempre son negativas
            $color_clase = 'text-danger';
            $signo = '-';
            $cantidad_mostrar = abs($row['cantidad_agregada']);
        } elseif ($row['tipo_movimiento'] == 'ajuste') {
            // Para ajustes, usar el valor original directamente
            if ($row['cantidad_agregada'] > 0) {
                $color_clase = 'text-success';
                $signo = '+';
                $cantidad_mostrar = $row['cantidad_agregada'];
            } elseif ($row['cantidad_agregada'] < 0) {
                $color_clase = 'text-danger';
                $signo = '-';
                $cantidad_mostrar = abs($row['cantidad_agregada']);
            } else {
                $color_clase = '';
                $signo = '';
                $cantidad_mostrar = 0;
            }
        }
        
        // Determinar el badge de tipo de movimiento
        $badge_class = '';
        $badge_text = '';
        if ($row['tipo_movimiento'] == 'entrada') {
            $badge_class = 'badge-entrada';
            $badge_text = 'Entrada';
        } elseif ($row['tipo_movimiento'] == 'salida') {
            $badge_class = 'badge-ajuste';
            $badge_text = 'Salida';
        } else {
            $badge_class = 'badge-ajuste';
            $badge_text = 'Ajuste';
        }
        
        // Determinar el color del badge de inventario
        $inventory_badge_class = ($row['tipo_inventario'] == 'producto') ? 'badge-producto' : 'badge-insumo';
        $inventory_badge_text = ($row['tipo_inventario'] == 'producto') ? 'Producto' : 'Insumo';
    ?>
    <tr>
        <td><?= date('d/m/Y H:i', strtotime($row['fecha_movimiento'])) ?></td>
        <td>
            <strong><?= htmlspecialchars($row['producto_nombre'] ?? 'Producto eliminado') ?></strong>
            <?php if ($row['tipo_inventario']): ?>
                <span class="<?= $inventory_badge_class ?>"><?= $inventory_badge_text ?></span>
            <?php endif; ?>
        </td>
        <td>
            <?php if (!empty($row['proveedor'])): ?>
                <i class="fas fa-truck mr-1"></i> <?= htmlspecialchars($row['proveedor']) ?>
            <?php else: ?>
                <span class="text-muted">-</span>
            <?php endif; ?>
        </td>
        <td>
            <span class="<?= $badge_class ?>"><?= $badge_text ?></span>
        </td>
        <td class="text-right"><?= number_format($row['cantidad_anterior'], 2) ?></td>
        <td class="text-center <?= $color_clase ?> font-weight-bold">
            <?php if ($signo): ?>
                <?= $signo . number_format($cantidad_mostrar, 2) ?>
            <?php else: ?>
                <?= number_format($cantidad_mostrar, 2) ?>
            <?php endif; ?>
        </td>
        <td class="text-center font-weight-bold"><?= number_format($row['cantidad_nueva'], 2) ?></td>
        <td>
            <?= htmlspecialchars($row['nota'] ?? '-') ?>
            <?php if (empty($row['nota']) && $row['cantidad_anterior'] == 0): ?>
                <small class="text-muted">Stock inicial</small>
            <?php endif; ?>
        </td>
        <td>
            <?php if ($row['usuario_nombre']): ?>
                <i class="fas fa-user mr-1"></i> <?= htmlspecialchars($row['usuario_nombre']) ?>
            <?php else: ?>
                <span class="text-muted">Sistema</span>
            <?php endif; ?>
        </td>
    </tr>
    <?php 
    $contador++;
    endwhile; 
    ?>
<?php endif;
$tabla_html = ob_get_clean();

// Generar HTML de la paginación
ob_start();
if ($total_paginas > 1 && $registros_por_pagina !== 'todos'): ?>
    <div class="row">
        <div class="col-sm-6">
            <span class="text-muted">
                Mostrando <?= $offset + 1 ?> al <?= min($offset + $registros_por_pagina, $total_registros) ?> de <?= number_format($total_registros) ?> registros
            </span>
        </div>
        <div class="col-sm-6">
            <nav aria-label="Page navigation" class="float-right">
                <ul class="pagination pagination-sm mb-0">
                    <li class="page-item <?= $pagina_actual <= 1 ? 'disabled' : '' ?>">
                        <a class="page-link" href="javascript:void(0)" onclick="irPagina(<?= $pagina_actual - 1 ?>)" tabindex="-1">Anterior</a>
                    </li>
                    
                    <?php
                    $rango = 2;
                    $inicio = max(1, $pagina_actual - $rango);
                    $fin = min($total_paginas, $pagina_actual + $rango);
                    
                    if ($inicio > 1) {
                        echo '<li class="page-item"><a class="page-link" href="javascript:void(0)" onclick="irPagina(1)">1</a></li>';
                        if ($inicio > 2) {
                            echo '<li class="page-item disabled"><span class="page-link">...</span></li>';
                        }
                    }
                    
                    for ($i = $inicio; $i <= $fin; $i++) {
                        echo '<li class="page-item ' . ($i == $pagina_actual ? 'active' : '') . '">';
                        echo '<a class="page-link" href="javascript:void(0)" onclick="irPagina(' . $i . ')">' . $i . '</a>';
                        echo '</li>';
                    }
                    
                    if ($fin < $total_paginas) {
                        if ($fin < $total_paginas - 1) {
                            echo '<li class="page-item disabled"><span class="page-link">...</span></li>';
                        }
                        echo '<li class="page-item"><a class="page-link" href="javascript:void(0)" onclick="irPagina(' . $total_paginas . ')">' . $total_paginas . '</a></li>';
                    }
                    ?>
                    
                    <li class="page-item <?= $pagina_actual >= $total_paginas ? 'disabled' : '' ?>">
                        <a class="page-link" href="javascript:void(0)" onclick="irPagina(<?= $pagina_actual + 1 ?>)">Siguiente</a>
                    </li>
                </ul>
            </nav>
        </div>
    </div>
<?php elseif ($registros_por_pagina === 'todos' && $total_registros > 0): ?>
    <div class="row">
        <div class="col-12">
            <span class="text-muted">
                Mostrando todos los <?= number_format($total_registros) ?> registros
            </span>
        </div>
    </div>
<?php endif;
$paginacion_html = ob_get_clean();

// Construir array de filtros activos
$filtros_activos = [];

if ($producto_id > 0) {
    $filtros_activos[] = [
        'tipo' => 'producto',
        'texto' => 'Producto: ' . htmlspecialchars($producto_nombre)
    ];
}

if (!empty($proveedor_filtro)) {
    $filtros_activos[] = [
        'tipo' => 'proveedor',
        'texto' => 'Proveedor: ' . htmlspecialchars($proveedor_filtro)
    ];
}

if (!empty($fecha_desde)) {
    $filtros_activos[] = [
        'tipo' => 'fecha_desde',
        'texto' => 'Desde: ' . date('d/m/Y', strtotime($fecha_desde))
    ];
}

if (!empty($fecha_hasta)) {
    $filtros_activos[] = [
        'tipo' => 'fecha_hasta',
        'texto' => 'Hasta: ' . date('d/m/Y', strtotime($fecha_hasta))
    ];
}

// Devolver respuesta JSON
header('Content-Type: application/json');
echo json_encode([
    'tabla' => $tabla_html,
    'paginacion' => $paginacion_html,
    'total_registros' => number_format($total_registros),
    'producto_nombre' => $producto_nombre,
    'proveedor_filtro' => $proveedor_filtro,
    'filtros' => $filtros_activos
]);
?>