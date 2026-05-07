<?php
session_start();
include 'includes/db.php';
include 'includes/header.php';
include('includes/navbar.php');

date_default_timezone_set('America/Mexico_City');

// Solo mostrar productos (no insumos)
$res = $conn->query("SELECT id, nombre, cantidad FROM productos WHERE tipo_inventario = 'producto' ORDER BY nombre");
if(!$res){
    die("Error SQL: " . $conn->error);
}

// Filtrar solicitantes solo de productos
$solicitantes = $conn->query("SELECT DISTINCT p.solicitado_por 
                              FROM pedidos p 
                              INNER JOIN productos prod ON p.nombre_producto = prod.nombre 
                              WHERE prod.tipo_inventario = 'producto' 
                              ORDER BY p.solicitado_por");

// Filtrar órdenes que contengan solo productos
$ordenes = $conn->query("SELECT p.id_orden, MAX(p.solicitado_por) as solicitado_por 
                         FROM pedidos p 
                         INNER JOIN productos prod ON p.nombre_producto = prod.nombre 
                         WHERE prod.tipo_inventario = 'producto' 
                         GROUP BY p.id_orden 
                         ORDER BY p.id_orden DESC");

// Obtener todos los productos para paginación
$productosData = [];
$productosResult = $conn->query("SELECT id, nombre, cantidad FROM productos WHERE tipo_inventario = 'producto' ORDER BY nombre");
while($row = $productosResult->fetch_assoc()) {
    $productosData[] = $row;
}
?>

<link rel="stylesheet" href="css/pedidos.css">

<div class="content-wrapper">
    <section class="content pt-4">
        <div class="container-fluid">

           <!-- BREADCRUMB - Índice mejorado -->
            <div class="breadcrumb-custom">
                <nav aria-label="breadcrumb">
                    <ol class="breadcrumb mb-0">
                        <li class="breadcrumb-item">
                            <a href="<?= $_SESSION['rol'] === 'administrador' ? 'dashboard_admin.php' : 'dashboard_vendedor.php' ?>">
                                <i class="fas fa-home fa-lg"></i> Inicio
                            </a>
                        </li>
                        <li class="breadcrumb-item">
                            <a href="ventas_modulo.php">
                                <i class="fas fa-cash-register"></i> Registrar Venta
                            </a>
                        </li>
                        <li class="breadcrumb-item active">
                            <i class="fas fa-boxes fa-lg"></i> Pedidos / Reabastecimiento
                        </li>
                    </ol>
                </nav>
            </div>

            <!-- Título y botones -->
            <div class="d-flex flex-wrap justify-content-between align-items-center mb-3">
                <h3 class="mb-2 mb-md-0" style="color: #2c3e50;">Pedidos / Reabastecimiento</h3>
                    <div class="d-flex justify-content-end align-items-center" style="gap: 130px;">
                        <button class="btn-ayuda" onclick="mostrarAyuda()">
                            <i class="fas fa-question-circle"></i> Ayuda
                        </button>
                    <div class="border-start border-secondary" style="height: 30px;"></div>
                    <div style="margin-left: 20px;">
                        <button class="btn btn-sm btn-success" style="margin-right: 10px;" onclick="exportarExcel()">
                            <i class="fas fa-file-excel me-1"></i> Excel
                        </button>
                        <button class="btn btn-sm btn-danger" onclick="exportarPDF()">
                            <i class="fas fa-file-pdf me-1"></i> PDF
                        </button>
                    </div>
                </div>
            </div>

            <!-- AYUDA - Fondo gris claro -->
            <div id="ayudaReporte" style="display: none; background: #f8f9fa; border-radius: 20px; margin-bottom: 25px; border: 1px solid #f97316;">
                <div style="padding: 25px 30px;">
                    <div class="text-center mb-4">
                        <div style="background: #f97316; width: 55px; height: 55px; border-radius: 55px; display: inline-flex; align-items: center; justify-content: center;">
                            <i class="fas fa-file-alt fa-xl text-white"></i>
                        </div>
                        <h5 class="mt-3 mb-1" style="color: #2c3e50;">Generar reporte de pedidos</h5>
                        <p class="text-secondary small mb-0">Exporta tus pedidos fácilmente</p>
                    </div>
                    
                    <div class="d-flex justify-content-between align-items-center text-center" style="gap: 15px; margin: 25px 0;">
                        <div style="flex: 1;">
                            <div class="bg-white rounded-circle d-inline-flex align-items-center justify-content-center" style="width: 40px; height: 40px; border: 2px solid #f97316;">
                                <span style="color: #f97316; font-weight: bold;">1</span>
                            </div>
                            <div class="small fw-bold mt-2">Filtro</div>
                            <div class="small text-secondary">Todos/Solicitante/Folio</div>
                        </div>
                        <div><i class="fas fa-arrow-right text-secondary"></i></div>
                        <div style="flex: 1;">
                            <div class="bg-white rounded-circle d-inline-flex align-items-center justify-content-center" style="width: 40px; height: 40px; border: 2px solid #f97316;">
                                <span style="color: #f97316; font-weight: bold;">2</span>
                            </div>
                            <div class="small fw-bold mt-2">Seleccionar</div>
                            <div class="small text-secondary">Elije opción</div>
                        </div>
                        <div><i class="fas fa-arrow-right text-secondary"></i></div>
                        <div style="flex: 1;">
                            <div class="bg-white rounded-circle d-inline-flex align-items-center justify-content-center" style="width: 40px; height: 40px; border: 2px solid #f97316;">
                                <span style="color: #f97316; font-weight: bold;">3</span>
                            </div>
                            <div class="small fw-bold mt-2">Exportar</div>
                            <div class="small text-secondary">Excel o PDF</div>
                        </div>
                    </div>
                    
                    <div class="d-flex justify-content-between align-items-center pt-3" style="border-top: 1px solid #dee2e6;">
                        <label class="mb-0 small text-secondary">
                            <input type="checkbox" id="noMostrarAyuda"> No volver a mostrar
                        </label>
                        <button onclick="cerrarAyuda()" style="background: none; border: none; color: #f97316; font-weight: 500;">
                            <i class="fas fa-times"></i> Cerrar
                        </button>
                    </div>
                </div>
            </div>

            <!-- FILTROS -->
            <div class="row mb-3">
                <div class="col-md-4">
                    <label class="small fw-bold text-secondary">Tipo de reporte</label>
                    <select id="tipoReporte" class="form-control form-control-sm">
                        <option value="todos">Todos los pedidos</option>
                        <option value="solicitante">Filtrar por solicitante</option>
                        <option value="orden">Filtrar por folio</option>
                    </select>
                </div>
                <div class="col-md-4" id="divSolicitante" style="display:none;">
                    <label class="small fw-bold text-secondary">Solicitante</label>
                    <select id="filtroSolicitante" class="form-control form-control-sm">
                        <option value="">Seleccionar...</option>
                        <?php while($s = $solicitantes->fetch_assoc()): ?>
                            <option value="<?= htmlspecialchars($s['solicitado_por']) ?>"><?= htmlspecialchars($s['solicitado_por']) ?></option>
                        <?php endwhile; ?>
                    </select>
                </div>
                <div class="col-md-4" id="divOrden" style="display:none;">
                    <label class="small fw-bold text-secondary">Folio</label>
                    <select id="filtroOrden" class="form-control form-control-sm">
                        <option value="">Seleccionar...</option>
                        <?php while($o = $ordenes->fetch_assoc()): ?>
                            <option value="<?= $o['id_orden'] ?>">Folio #<?= $o['id_orden'] ?></option>
                        <?php endwhile; ?>
                    </select>
                </div>
            </div>

            <!-- TABLA DE PRODUCTOS CON BUSCADOR A LA DERECHA -->
            <div class="card mb-4">
                <div class="card-header">
                    <h5 class="mb-0"><i class="fas fa-boxes"></i> Lista de productos</h5>
                    <div class="buscador-wrapper">
                       <div class="input-group input-group-sm">
                            <span class="input-group-text bg-white">
                                <i class="fas fa-search"></i>
                            </span>
                            <input type="text" id="buscadorProductos" class="form-control" placeholder="Buscar producto...">
                            <span class="input-group-text bg-white" id="contadorProductos" style="width: 30px; display: inline-flex; justify-content: center;">0</span>
                        </div>
                    </div>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover mb-0" id="tablaPedidos">
                            <thead>
                                <tr>
                                    <th>Producto</th>
                                    <th class="text-center">Stock</th>
                                    <th class="text-center" style="width: 100px;">Pedir</th>
                                    <th class="text-center">Faltante</th>
                                    <th class="text-center">Estado</th>
                                </tr>
                            </thead>
                            <tbody id="tablaProductosBody"></tbody>
                        </table>
                    </div>
                    <div class="pagination-wrapper">
                        <div class="rows-per-page">
                            <span class="small">Mostrar:</span>
                            <select id="rowsPerPage">
                                <option value="5">5</option>
                                <option value="10" selected>10</option>
                                <option value="25">25</option>
                                <option value="50">50</option>
                            </select>
                        </div>
                        <div class="pagination-controls" id="paginationControls"></div>
                    </div>
                </div>
            </div>

            <div class="text-end mb-3">
                <button class="btn btn-sm btn-success" onclick="abrirModalSolicitante()">
                    <i class="fas fa-save"></i> Guardar Pedido
                </button>
            </div>

            <hr>

            <!-- FILTRO DE ESTADOS - Actualizado con Cancelados -->
            <div class="d-flex justify-content-end mb-3">
                <div class="btn-group btn-group-sm gap-2">
                    <button class="btn btn-estado btn-estado-pendiente filtro-estado active" data-estado="pendiente">
                        <i class="fas fa-clock"></i> Pendientes
                    </button>
                    <button class="btn btn-estado btn-estado-completado filtro-estado" data-estado="completado">
                        <i class="fas fa-check-circle"></i> Completados
                    </button>
                    <button class="btn btn-estado btn-estado-cancelado filtro-estado" data-estado="cancelado">
                        <i class="fas fa-ban"></i> Cancelados
                    </button>
                    <button class="btn btn-estado btn-estado-todos filtro-estado" data-estado="todos">
                        <i class="fas fa-list"></i> Todos
                    </button>
                </div>
            </div>

            <!-- LISTA DE PEDIDOS CON BUSCADOR A LA DERECHA -->
            <div class="card">
                <div class="card-header">
                    <h5 class="mb-0" id="tituloPedidos"><i class="fas fa-clipboard-list"></i> Pedidos pendientes</h5>
                    <div class="buscador-wrapper">
                        <div class="input-group input-group-sm">
                            <span class="input-group-text bg-white"><i class="fas fa-search"></i></span>
                            <input type="text" id="buscadorPedidos" class="form-control" placeholder="Buscar folio, solicitante...">
                        </div>
                    </div>
                </div>
                <div class="card-body p-2" id="pedidosContainer">
                    <?php
// Pedidos PENDIENTES - Excluir cancelados
$foliosPendientes = $conn->query("
    SELECT DISTINCT p.id_orden, p.solicitado_por, p.fecha
    FROM pedidos p
    INNER JOIN productos prod ON p.nombre_producto = prod.nombre
    WHERE prod.tipo_inventario = 'producto'
    AND p.estado = 'pendiente'
    GROUP BY p.id_orden
    ORDER BY p.id_orden DESC
");

// Pedidos COMPLETADOS - Con la fecha del último producto completado
$foliosCompletados = $conn->query("
    SELECT DISTINCT p.id_orden, p.solicitado_por, p.fecha,
           (SELECT MAX(fecha_completado) FROM pedidos WHERE id_orden = p.id_orden AND estado = 'completado') as ultima_fecha_completado
    FROM pedidos p
    INNER JOIN productos prod ON p.nombre_producto = prod.nombre
    WHERE prod.tipo_inventario = 'producto'
    AND p.estado = 'completado'
    GROUP BY p.id_orden
    ORDER BY p.id_orden DESC
");

// Pedidos CANCELADOS - Incluir fecha_cancelacion desde ordenes_pedido
$foliosCancelados = $conn->query("
    SELECT DISTINCT p.id_orden, p.solicitado_por, p.fecha,
           op.fecha_cancelacion
    FROM pedidos p
    INNER JOIN productos prod ON p.nombre_producto = prod.nombre
    LEFT JOIN ordenes_pedido op ON p.id_orden = op.id_orden
    WHERE prod.tipo_inventario = 'producto'
    AND p.estado = 'cancelado'
    GROUP BY p.id_orden
    ORDER BY p.id_orden DESC
");
                    ?>

<div id="pendientesContainer">
    <?php if($foliosPendientes->num_rows > 0): ?>
        <?php while($f = $foliosPendientes->fetch_assoc()): 
            $productosQuery = $conn->query("SELECT id, nombre_producto, cantidad_pedida, faltante, estado FROM pedidos WHERE id_orden = {$f['id_orden']}");
            $productosList = [];
            $todosCompletados = true;
            while($p = $productosQuery->fetch_assoc()) {
                $productosList[] = $p;
                if($p['estado'] !== 'completado') $todosCompletados = false;
            }
        ?>
            <div class="pedido-card" data-estado="pendiente" data-search="<?= strtolower($f['id_orden'] . ' ' . $f['solicitado_por']) ?>">
                <div class="pedido-header bg-warning d-flex justify-content-between align-items-center" data-toggle="collapse" data-target="#collapsePendiente<?= $f['id_orden'] ?>" style="cursor:pointer;">
                    <div class="pedido-info">
                        <i class="fas fa-chevron-right flecha" style="font-size: 14px;"></i>
                        <strong><i class="fas fa-hashtag"></i> Folio #<?= $f['id_orden'] ?></strong>
                        <span><i class="fas fa-user"></i> <?= htmlspecialchars($f['solicitado_por']) ?></span>
                        <span><i class="fas fa-calendar-alt"></i> <?= date('d/m/Y H:i', strtotime($f['fecha'])) ?></span>
                        <?php if($todosCompletados): ?>
                            <span class="badge-completado-header"><i class="fas fa-check-circle"></i> Completado</span>
                        <?php else: ?>
                            <span class="badge-pendiente-header"><i class="fas fa-clock"></i> Pendiente</span>
                        <?php endif; ?>
                    </div>
                    <div class="pedido-actions">
                        <button class="btn-historial" onclick="event.stopPropagation(); verHistorial(<?= $f['id_orden'] ?>)">
                            <i class="fas fa-history"></i> Historial
                        </button>
                        <?php if(!$todosCompletados): ?>
                            <button class="btn-completar-pedido" onclick="event.stopPropagation(); completarPedido(<?= $f['id_orden'] ?>)">
                                <i class="fas fa-check-double"></i> Completar todo
                            </button>
                        <?php endif; ?>
                    </div>
                </div>
                <div id="collapsePendiente<?= $f['id_orden'] ?>" class="collapse pedido-body">
                    <table class="pedido-table table table-sm mb-0">
                        <thead>
                            <tr>
                                <th>Producto</th>
                                <th class="text-center">Pedido</th>
                                <th class="text-center">Faltante</th>
                                <th class="text-center">Estado</th>
                                <th class="text-center">Acción</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach($productosList as $p): 
                                $isCompletado = $p['estado'] === 'completado';
                            ?>
                                <tr class="<?= $isCompletado ? 'producto-completado' : '' ?>">
                                    <td>
                                        <?= htmlspecialchars($p['nombre_producto']) ?>
                                        <?php if($isCompletado): ?>
                                            <span class="badge-completado-producto ms-2"><i class="fas fa-check-circle"></i> Completado</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-center fw-bold"><?= $p['cantidad_pedida'] ?></td>
                                    <td class="text-center <?= $p['faltante'] > 0 ? 'text-danger fw-bold' : 'text-success' ?>">
                                        <?= $p['faltante'] ?>
                                    </td>
                                    <td class="text-center">
                                        <?php if($isCompletado): ?>
                                            <span class="badge-estado-completado"><i class="fas fa-check"></i> Completado</span>
                                        <?php elseif($p['faltante'] == 0): ?>
                                            <span class="badge-estado-stock">Stock suficiente</span>
                                        <?php elseif($p['faltante'] > 0 && $p['faltante'] < $p['cantidad_pedida']): ?>
                                            <span class="badge-estado-parcial">Parcial</span>
                                        <?php else: ?>
                                            <span class="badge-estado-sinstock">Sin stock</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-center">
                                        <?php if(!$isCompletado): ?>
                                            <button class="btn-completar-producto" onclick="event.stopPropagation(); completarProducto(<?= $p['id'] ?>)">
                                                <i class="fas fa-check"></i> Completar
                                            </button>
                                        <?php else: ?>
                                            <button class="btn-disabled" disabled style="opacity:0.5;">
                                                <i class="fas fa-check-circle"></i> Completado
                                            </button>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    <?php if($todosCompletados): ?>
                        <div class="alert-success-pedido mt-3 mb-0 text-center">
                            <i class="fas fa-check-circle"></i> Este pedido ha sido completado completamente
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        <?php endwhile; ?>
    <?php else: ?>
        <div class="empty-state">
            <div class="empty-state-icon"><i class="fas fa-clock"></i></div>
            <div class="empty-state-title">No hay pedidos pendientes</div>
            <div class="empty-state-text">Todos los pedidos están completados</div>
        </div>
    <?php endif; ?>
</div>

<div id="completadosContainer" style="display: none;">
    <?php if($foliosCompletados->num_rows > 0): ?>
        <?php while($f = $foliosCompletados->fetch_assoc()): ?>
            <div class="pedido-card" data-estado="completado" data-search="<?= strtolower($f['id_orden'] . ' ' . $f['solicitado_por']) ?>">
                <div class="pedido-header bg-success d-flex justify-content-between align-items-center" data-toggle="collapse" data-target="#collapseCompletado<?= $f['id_orden'] ?>" style="cursor:pointer;">
                    <div class="pedido-info">
                        <i class="fas fa-chevron-right flecha" style="font-size: 14px;"></i>
                        <strong><i class="fas fa-hashtag"></i> Folio #<?= $f['id_orden'] ?></strong>
                        <span><i class="fas fa-user"></i> <?= htmlspecialchars($f['solicitado_por']) ?></span>
                        <span><i class="fas fa-calendar-alt"></i> <?= date('d/m/Y H:i', strtotime($f['fecha'])) ?></span>
                        <span class="badge-completado-header"><i class="fas fa-check-circle"></i> Completado</span>
                    </div>
                    <button class="btn-historial" onclick="event.stopPropagation(); verHistorial(<?= $f['id_orden'] ?>)">
                        <i class="fas fa-history"></i> Historial
                    </button>
                </div>
                <div id="collapseCompletado<?= $f['id_orden'] ?>" class="collapse pedido-body">
                    <table class="pedido-table table table-sm mb-0">
                        <thead>
                            <tr>
                                <th>Producto</th>
                                <th class="text-center">Cantidad pedida</th>
                                <th class="text-center">Completado</th>
                                <th class="text-center">Fecha completado</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php 
                            // CONSULTA CORREGIDA - Usando fecha_completado de la tabla pedidos
                            $productosCompletados = $conn->query("
                                SELECT nombre_producto, cantidad_pedida, fecha_completado
                                FROM pedidos 
                                WHERE id_orden = {$f['id_orden']} AND estado = 'completado'
                            ");
                            while($p = $productosCompletados->fetch_assoc()): ?>
                                <tr>
                                    <td><?= htmlspecialchars($p['nombre_producto']) ?></td>
                                    <td class="text-center fw-bold"><?= $p['cantidad_pedida'] ?></td>
                                    <td class="text-center">
                                        <span class="badge-estado-completado">
                                            <i class="fas fa-check-circle"></i> <?= $p['cantidad_pedida'] ?>
                                        </span>
                                    </td>
                                    <td class="text-center">
                                        <?= $p['fecha_completado'] ? date('d/m/Y H:i', strtotime($p['fecha_completado'])) : date('d/m/Y H:i', strtotime($f['fecha'])) ?>
                                    </td>
                                </tr>
                            <?php endwhile; ?>
                        </tbody>
                    </table>
                    <div class="alert-success-pedido mt-3 mb-0 text-center">
                        <i class="fas fa-calendar-check"></i> Pedido completado el <?= date('d/m/Y H:i', strtotime($f['ultima_fecha_completado'] ?? $f['fecha'])) ?>
                    </div>
                </div>
            </div>
        <?php endwhile; ?>
    <?php else: ?>
        <div class="empty-state">
            <div class="empty-state-icon"><i class="fas fa-check-circle"></i></div>
            <div class="empty-state-title">No hay pedidos completados</div>
            <div class="empty-state-text">Aún no se han completado pedidos</div>
        </div>
    <?php endif; ?>
</div>
<div id="canceladosContainer" style="display: none;">
    <?php if($foliosCancelados && $foliosCancelados->num_rows > 0): ?>
        <?php while($f = $foliosCancelados->fetch_assoc()): 
            // Obtener fecha de cancelación desde ordenes_pedido
            $fechaCancelacion = $f['fecha_cancelacion'] ?? $f['fecha'];
        ?>
            <div class="pedido-card" data-estado="cancelado" data-search="<?= strtolower($f['id_orden'] . ' ' . $f['solicitado_por']) ?>">
                <div class="pedido-header bg-danger d-flex justify-content-between align-items-center" data-toggle="collapse" data-target="#collapseCancelado<?= $f['id_orden'] ?>" style="cursor:pointer;">
                    <div class="pedido-info">
                        <i class="fas fa-chevron-right flecha" style="font-size: 14px;"></i>
                        <strong><i class="fas fa-hashtag"></i> Folio #<?= $f['id_orden'] ?></strong>
                        <span><i class="fas fa-user"></i> <?= htmlspecialchars($f['solicitado_por']) ?></span>
                        <span><i class="fas fa-calendar-alt"></i> <?= date('d/m/Y H:i', strtotime($f['fecha'])) ?></span>
                        <span class="badge-cancelado-header"><i class="fas fa-ban"></i> Cancelado</span>
                    </div>
                    <div class="pedido-actions">
                        <button class="btn-historial" onclick="event.stopPropagation(); verHistorial(<?= $f['id_orden'] ?>)">
                            <i class="fas fa-history"></i> Historial
                        </button>
                    </div>
                </div>
                <div id="collapseCancelado<?= $f['id_orden'] ?>" class="collapse pedido-body">
                    <table class="pedido-table table table-sm mb-0">
                        <thead>
                            <tr>
                                <th>Producto</th>
                                <th class="text-center">Cantidad pedida</th>
                                <th class="text-center">Estado</th>
                                <th class="text-center">Fecha cancelación</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php 
                            $productosCancelados = $conn->query("
                                SELECT id, nombre_producto, cantidad_pedida, estado, fecha
                                FROM pedidos 
                                WHERE id_orden = {$f['id_orden']}
                            ");
                            while($p = $productosCancelados->fetch_assoc()): ?>
                                <tr>
                                    <td><?= htmlspecialchars($p['nombre_producto']) ?></td>
                                    <td class="text-center fw-bold"><?= $p['cantidad_pedida'] ?></td>
                                    <td class="text-center">
                                        <span class="badge-estado-cancelado">
                                            <i class="fas fa-ban"></i> Cancelado
                                        </span>
                                    </td>
                                    <td class="text-center">
                                        <?= date('d/m/Y H:i', strtotime($fechaCancelacion)) ?>
                                    </td>
                                </tr>
                            <?php endwhile; ?>
                        </tbody>
                    </table>
                    <div class="alert-danger-pedido mt-3 mb-0 text-center">
                        <i class="fas fa-ban"></i> Este pedido fue cancelado el <?= date('d/m/Y H:i', strtotime($fechaCancelacion)) ?>
                    </div>
                </div>
            </div>
        <?php endwhile; ?>
    <?php else: ?>
        <div class="empty-state">
            <div class="empty-state-icon"><i class="fas fa-ban"></i></div>
            <div class="empty-state-title">No hay pedidos cancelados</div>
            <div class="empty-state-text">No se han cancelado pedidos</div>
        </div>
    <?php endif; ?>
</div>

<div id="todosContainer" style="display: none;"></div>

                </div>
            </div>
        </div>
    </section>
</div>

<!-- MODALES -->
<div class="modal fade" id="modalSolicitante" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title">¿Para quién es el pedido?</h5>
                <button type="button" class="close text-white" data-dismiss="modal">&times;</button>
            </div>
            <div class="modal-body">
                <input type="text" id="nombreSolicitante" class="form-control" placeholder="Ej: Juan Pérez">
            </div>
            <div class="modal-footer">
                <button class="btn btn-success" onclick="confirmarGuardado()">Guardar</button>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="modalHistorial" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-dark text-white">
                <h5 class="modal-title"><i class="fas fa-history"></i> Historial del pedido</h5>
                <button type="button" class="close text-white" data-dismiss="modal">&times;</button>
            </div>
            <div class="modal-body" id="contenidoHistorial">Cargando...</div>
        </div>
    </div>
</div>

<script src="https://code.jquery.com/jquery-3.5.1.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@4.6.2/dist/js/bootstrap.bundle.min.js"></script>
<link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/toastr.js/latest/toastr.min.js"></script>

<script>
let productosData = <?= json_encode($productosData) ?>;
let pedidosTemp = [];
let currentPage = 1;
let rowsPerPage = 10;
let currentFilter = '';

$(document).ready(function() {
    $('#filtroSolicitante, #filtroOrden').select2({ width: '100%' });
    
    $('#tipoReporte').change(function() {
        $('#divSolicitante, #divOrden').hide();
        if ($(this).val() === 'solicitante') $('#divSolicitante').show();
        if ($(this).val() === 'orden') $('#divOrden').show();
    });
    
    $('#rowsPerPage').change(function() { rowsPerPage = parseInt($(this).val()); currentPage = 1; renderTable(); });
    renderTable();
    
$('.filtro-estado').click(function() {
    $('.filtro-estado').removeClass('active');
    $(this).addClass('active');
    let estado = $(this).data('estado');
    
    // Ocultar todos los contenedores
    $('#pendientesContainer, #completadosContainer, #todosContainer, #canceladosContainer').hide();
    
    if (estado === 'pendiente') {
        $('#pendientesContainer').show();
        $('#tituloPedidos').html('<i class="fas fa-clock"></i> Pedidos pendientes');
        aplicarBusquedaPedidos();
    } else if (estado === 'completado') {
        $('#completadosContainer').show();
        $('#tituloPedidos').html('<i class="fas fa-check-circle"></i> Pedidos completados');
        aplicarBusquedaPedidos();
    } else if (estado === 'cancelado') {
        $('#canceladosContainer').show();
        $('#tituloPedidos').html('<i class="fas fa-ban"></i> Pedidos cancelados');
        aplicarBusquedaPedidos();
    } else {
        // Para "Todos", construir el contenido dinámicamente
        $('#todosContainer').show();
        $('#tituloPedidos').html('<i class="fas fa-list"></i> Todos los pedidos');
        
        // Construir contenido de todos los pedidos
        const $todosContainer = $('#todosContainer');
        $todosContainer.empty();
        
        // Clonar todos los pedidos de los tres contenedores
        const $pendientesCards = $('#pendientesContainer .pedido-card').clone();
        const $completadosCards = $('#completadosContainer .pedido-card').clone();
        const $canceladosCards = $('#canceladosContainer .pedido-card').clone();
        
        if ($pendientesCards.length === 0 && $completadosCards.length === 0 && $canceladosCards.length === 0) {
            $todosContainer.html(`
                <div class="empty-state">
                    <div class="empty-state-icon"><i class="fas fa-inbox"></i></div>
                    <div class="empty-state-title">No hay pedidos registrados</div>
                    <div class="empty-state-text">Realiza tu primer pedido desde la lista de productos</div>
                </div>
            `);
        } else {
            // Agregar pedidos pendientes
            if ($pendientesCards.length > 0) {
                $pendientesCards.each(function() {
                    $todosContainer.append($(this));
                });
            }
            // Agregar pedidos completados
            if ($completadosCards.length > 0) {
                $completadosCards.each(function() {
                    $todosContainer.append($(this));
                });
            }
            // Agregar pedidos cancelados
            if ($canceladosCards.length > 0) {
                $canceladosCards.each(function() {
                    $todosContainer.append($(this));
                });
            }
        }
        
        // Restaurar funcionalidad collapse
        $todosContainer.find('[data-toggle="collapse"]').off('click').on('click', function(e) {
            if (!$(e.target).closest('button').length) {
                const target = $(this).data('target');
                $(target).collapse('toggle');
                $(this).find('.flecha').toggleClass('abierta');
            }
        });
        
        aplicarBusquedaPedidos();
    }
});

    // Función auxiliar para aplicar búsqueda al contenedor visible
function aplicarBusquedaPedidos() {
    const texto = $('#buscadorPedidos').val().toLowerCase();
    const containerVisible = $('#pendientesContainer:visible, #completadosContainer:visible, #todosContainer:visible, #canceladosContainer:visible');
    containerVisible.find('.pedido-card').each(function() {
        const search = $(this).attr('data-search') || '';
        $(this).toggle(search.toLowerCase().includes(texto));
    });
}
    
    // ========== AYUDA: Recuperar estado guardado ==========
    const ayudaOculta = localStorage.getItem('ocultarAyudaReporte');
    const noMostrarGuardado = localStorage.getItem('noMostrarAyudaCheckbox');
    
    if (ayudaOculta !== 'true') {
        $('#ayudaReporte').show();
    } else {
        $('#ayudaReporte').hide();
    }
    
    if (noMostrarGuardado === 'true') {
        $('#noMostrarAyuda').prop('checked', true);
    }
    
    // Guardar estado del checkbox cuando cambie
    $('#noMostrarAyuda').on('change', function() {
        if ($(this).is(':checked')) {
            localStorage.setItem('noMostrarAyudaCheckbox', 'true');
        } else {
            localStorage.removeItem('noMostrarAyudaCheckbox');
            localStorage.removeItem('ocultarAyudaReporte');
        }
    });
});

function calcular(input) {
    let tr = $(input).closest('tr');
    let stock = parseInt(tr.data('stock'));
    let pedido = parseInt(input.value) || 0;
    let nuevoStock = Math.max(0, stock - pedido);
    let faltante = Math.max(0, pedido - stock);
    
    tr.find('.stock').text(nuevoStock);
    tr.find('.faltante').text(faltante);
    
    let estadoSpan = tr.find('.estado span');
    tr.removeClass('table-warning table-danger table-success');
    
    if (pedido === 0) estadoSpan.html('Sin pedido').attr('class', 'badge badge-secondary');
    else if (faltante === 0) estadoSpan.html('Stock suficiente').attr('class', 'badge badge-success');
    else if (stock === 0) estadoSpan.html('Sin stock').attr('class', 'badge badge-danger');
    else estadoSpan.html('Faltante parcial').attr('class', 'badge badge-warning');
}

function renderTable() {
    let filtrados = productosData.filter(p => p.nombre.toLowerCase().includes(currentFilter));
    let totalPages = Math.ceil(filtrados.length / rowsPerPage);
    let start = (currentPage - 1) * rowsPerPage;
    let productosPagina = filtrados.slice(start, start + rowsPerPage);
    
    $('#contadorProductos').text(filtrados.length);
    let tbody = $('#tablaProductosBody');
    
    if (filtrados.length === 0) {
        tbody.html('<tr><td colspan="5" class="text-center"><div class="empty-state"><div class="empty-state-icon"><i class="fas fa-box-open"></i></div><div class="empty-state-title">No hay productos</div><div class="empty-state-text">No se encontraron productos</div></div></td></tr>');
        $('#paginationControls').html('');
        return;
    }
    
    let html = '';
    productosPagina.forEach(p => {
        html += `<tr data-id="${p.id}" data-nombre="${p.nombre}" data-stock="${p.cantidad}">
                    <td data-label="Producto">${p.nombre}</td>
                    <td class="text-center" data-label="Stock"><span class="badge badge-info stock">${p.cantidad}</span></td>
                    <td class="text-center" data-label="Pedir"><input type="number" min="0" class="form-control form-control-sm pedir" style="width:80px;margin:0 auto;" oninput="calcular(this)"></td>
                    <td class="text-center faltante fw-bold" data-label="Faltante">0</span></td>
                    <td class="text-center estado" data-label="Estado"><span class="badge badge-secondary">Sin pedido</span></td>
                </tr>`;
    });
    tbody.html(html);
    
    if (totalPages > 1) {
        let pagHtml = '';
        pagHtml += `<button onclick="changePage(1)" ${currentPage === 1 ? 'disabled' : ''}><i class="fas fa-angle-double-left"></i></button>`;
        pagHtml += `<button onclick="changePage(${currentPage - 1})" ${currentPage === 1 ? 'disabled' : ''}><i class="fas fa-angle-left"></i></button>`;
        for (let i = Math.max(1, currentPage - 2); i <= Math.min(totalPages, currentPage + 2); i++) {
            pagHtml += `<button onclick="changePage(${i})" class="${i === currentPage ? 'active' : ''}">${i}</button>`;
        }
        pagHtml += `<button onclick="changePage(${currentPage + 1})" ${currentPage === totalPages ? 'disabled' : ''}><i class="fas fa-angle-right"></i></button>`;
        pagHtml += `<button onclick="changePage(${totalPages})" ${currentPage === totalPages ? 'disabled' : ''}><i class="fas fa-angle-double-right"></i></button>`;
        $('#paginationControls').html(pagHtml);
    } else {
        $('#paginationControls').html('');
    }
}

function changePage(page) { currentPage = page; renderTable(); }

$('#buscadorProductos').on('keyup', function() { currentFilter = $(this).val().toLowerCase(); currentPage = 1; renderTable(); });

function abrirModalSolicitante() {
    pedidosTemp = [];
    
    // Recorrer todas las filas de la tabla de productos
    $('#tablaProductosBody tr').each(function() {
        const $row = $(this);
        const pedidoInput = $row.find('.pedir');
        
        if (pedidoInput.length) {
            const pedido = parseInt(pedidoInput.val()) || 0;
            
            if (pedido > 0) {
                // Obtener datos desde los atributos data o desde las celdas
                const id = $row.data('id');
                const nombre = $row.data('nombre');
                const stock = $row.data('stock');
                const faltante = $row.find('.faltante').text();
                
                if (id && nombre) {
                    pedidosTemp.push({
                        id: id,
                        nombre: nombre,
                        stock: stock,
                        pedido: pedido,
                        faltante: faltante
                    });
                }
            }
        }
    });
    
    console.log('Pedidos a guardar:', pedidosTemp); // Para depuración
    
    if (pedidosTemp.length === 0) {
        Swal.fire({
            icon: 'info',
            title: 'Sin pedidos',
            text: 'No has solicitado ningún producto',
            confirmButtonColor: '#f97316'
        });
        return;
    }
    
    $('#modalSolicitante').modal('show');
}

function confirmarGuardado() {
    const nombre = $('#nombreSolicitante').val().trim();
    
    if (nombre === '') {
        Swal.fire({
            icon: 'error',
            title: 'Error',
            text: 'Escribe para quién es el pedido',
            confirmButtonColor: '#f97316'
        });
        return;
    }
    
    // Mostrar loading
    Swal.fire({
        title: 'Guardando pedido...',
        allowOutsideClick: false,
        didOpen: () => {
            Swal.showLoading();
        }
    });
    
    fetch('guardar_pedido.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ 
            solicitado_por: nombre, 
            pedidos: pedidosTemp 
        })
    })
    .then(response => response.json())
    .then(data => {
        Swal.close();
        
        if (data.success) {
            Swal.fire({
                icon: 'success',
                title: '¡Pedido guardado!',
                text: 'El pedido se ha registrado correctamente',
                confirmButtonColor: '#f97316',
                timer: 2000
            }).then(() => {
                location.reload();
            });
        } else {
            Swal.fire({
                icon: 'error',
                title: 'Error',
                text: data.message || 'No se pudo guardar el pedido',
                confirmButtonColor: '#f97316'
            });
        }
    })
    .catch(error => {
        Swal.close();
        console.error('Error:', error);
        Swal.fire({
            icon: 'error',
            title: 'Error de conexión',
            text: 'No se pudo conectar con el servidor',
            confirmButtonColor: '#f97316'
        });
    });
}

function exportarExcel() {
    // Contar pedidos visibles según el filtro activo
    const estadoActivo = $('.filtro-estado.active').data('estado');
    let totalPedidos = 0;
    
    if (estadoActivo === 'pendiente') {
        totalPedidos = $('#pendientesContainer .pedido-card:visible').length;
    } else if (estadoActivo === 'completado') {
        totalPedidos = $('#completadosContainer .pedido-card:visible').length;
    } else if (estadoActivo === 'cancelado') {
        totalPedidos = $('#canceladosContainer .pedido-card:visible').length;
    } else {
        totalPedidos = $('#pendientesContainer .pedido-card:visible').length + 
                       $('#completadosContainer .pedido-card:visible').length +
                       $('#canceladosContainer .pedido-card:visible').length;
    }
    
    if (totalPedidos === 0) {
        let titulo = '';
        let mensaje = '';
        
        if (estadoActivo === 'pendiente') {
            titulo = 'No hay pedidos pendientes';
            mensaje = 'No existen pedidos pendientes para generar el reporte.';
        } else if (estadoActivo === 'completado') {
            titulo = 'No hay pedidos completados';
            mensaje = 'No existen pedidos completados para generar el reporte.';
        } else if (estadoActivo === 'cancelado') {
            titulo = 'No hay pedidos cancelados';
            mensaje = 'No existen pedidos cancelados para generar el reporte.';
        } else {
            titulo = 'No hay pedidos registrados';
            mensaje = 'No existen pedidos registrados para generar el reporte.';
        }
        
        Swal.fire({
            icon: 'info',
            title: titulo,
            text: mensaje,
            confirmButtonColor: '#f97316',
            confirmButtonText: 'Entendido'
        });
        return;
    }
    
    const tipo = $('#tipoReporte').val();
    let url = 'exportar_excel_pedidos.php';
    
    if (tipo === 'solicitante') {
        const solicitante = $('#filtroSolicitante').val();
        if (!solicitante) {
            Swal.fire({
                icon: 'warning',
                title: 'Selecciona un solicitante',
                text: 'Para exportar por solicitante, primero elige uno de la lista',
                confirmButtonColor: '#f97316'
            });
            return;
        }
        url += '?solicitado_por=' + encodeURIComponent(solicitante);
    } else if (tipo === 'orden') {
        const folio = $('#filtroOrden').val();
        if (!folio) {
            Swal.fire({
                icon: 'warning',
                title: 'Selecciona un folio',
                text: 'Para exportar por folio, primero elige un número de folio',
                confirmButtonColor: '#f97316'
            });
            return;
        }
        url += '?id_orden=' + folio;
    }
    
    window.open(url, '_blank');
}

function exportarPDF() {
    const estadoActivo = $('.filtro-estado.active').data('estado');
    let totalPedidos = 0;
    let containerVisible = '';
    
    // Identificar qué contenedor está visible según el estado
    if (estadoActivo === 'pendiente') {
        containerVisible = '#pendientesContainer';
        totalPedidos = $(containerVisible + ' .pedido-card:visible').length;
    } else if (estadoActivo === 'completado') {
        containerVisible = '#completadosContainer';
        totalPedidos = $(containerVisible + ' .pedido-card:visible').length;
    } else if (estadoActivo === 'cancelado') {
        containerVisible = '#canceladosContainer';
        totalPedidos = $(containerVisible + ' .pedido-card:visible').length;
    } else if (estadoActivo === 'todos') {
        containerVisible = '#todosContainer';
        totalPedidos = $(containerVisible + ' .pedido-card:visible').length;
        
        if (totalPedidos === 0) {
            totalPedidos = $('#pendientesContainer .pedido-card').length + 
                           $('#completadosContainer .pedido-card').length +
                           $('#canceladosContainer .pedido-card').length;
        }
    }
    
    console.log('Estado activo:', estadoActivo);
    console.log('Total pedidos encontrados:', totalPedidos);
    
    if (totalPedidos === 0) {
        let titulo = '';
        let mensaje = '';
        
        if (estadoActivo === 'pendiente') {
            titulo = 'No hay pedidos pendientes';
            mensaje = 'No existen pedidos pendientes para generar el reporte.';
        } else if (estadoActivo === 'completado') {
            titulo = 'No hay pedidos completados';
            mensaje = 'No existen pedidos completados para generar el reporte.';
        } else if (estadoActivo === 'cancelado') {
            titulo = 'No hay pedidos cancelados';
            mensaje = 'No existen pedidos cancelados para generar el reporte.';
        } else {
            titulo = 'No hay pedidos registrados';
            mensaje = 'No existen pedidos registrados para generar el reporte.';
        }
        
        Swal.fire({
            icon: 'info',
            title: titulo,
            text: mensaje,
            confirmButtonColor: '#f97316',
            confirmButtonText: 'Entendido'
        });
        return;
    }
    
    const tipo = $('#tipoReporte').val();
    let url = 'exportar_pdf_pedidos.php?estado=' + encodeURIComponent(estadoActivo);
    
    if (tipo === 'solicitante') {
        const solicitante = $('#filtroSolicitante').val();
        if (!solicitante) {
            Swal.fire({
                icon: 'warning',
                title: 'Selecciona un solicitante',
                text: 'Para exportar por solicitante, primero elige uno de la lista',
                confirmButtonColor: '#f97316'
            });
            return;
        }
        url += '&solicitado_por=' + encodeURIComponent(solicitante);
    } else if (tipo === 'orden') {
        const folio = $('#filtroOrden').val();
        if (!folio) {
            Swal.fire({
                icon: 'warning',
                title: 'Selecciona un folio',
                text: 'Para exportar por folio, primero elige un número de folio',
                confirmButtonColor: '#f97316'
            });
            return;
        }
        url += '&id_orden=' + folio;
    }
    
    // Mostrar loading antes de abrir
    Swal.fire({
        title: 'Generando reporte...',
        text: 'Por favor espera',
        allowOutsideClick: false,
        didOpen: () => {
            Swal.showLoading();
        }
    });
    
    // Hacer una petición fetch para obtener el PDF y descargarlo
    fetch(url)
        .then(response => response.blob())
        .then(blob => {
            // Crear URL del blob
            const blobUrl = URL.createObjectURL(blob);
            
            // 1. DESCARGAR automáticamente
            const link = document.createElement('a');
            link.href = blobUrl;
            const fecha = new Date().toISOString().slice(0, 10);
            const nombreArchivo = `Reporte_Pedidos_${estadoActivo}_${fecha}.pdf`;
            link.download = nombreArchivo;
            document.body.appendChild(link);
            link.click();
            document.body.removeChild(link);
            
            // 2. ABRIR en nueva ventana
            window.open(blobUrl, '_blank');
            
            // 3. Limpiar la URL después de un tiempo
            setTimeout(() => {
                URL.revokeObjectURL(blobUrl);
            }, 3000);
            
            // Cerrar loading
            Swal.close();
            Swal.fire({
                icon: 'success',
                title: 'Reporte generado',
                text: 'El archivo se ha descargado y se ha abierto en una nueva ventana',
                timer: 2000,
                showConfirmButton: false,
                toast: true,
                position: 'top-end'
            });
        })
        .catch(error => {
            console.error('Error:', error);
            Swal.close();
            Swal.fire({
                icon: 'error',
                title: 'Error',
                text: 'No se pudo generar el reporte',
                confirmButtonColor: '#f97316'
            });
        });
}

function cerrarAyuda() {
    if ($('#noMostrarAyuda').is(':checked')) localStorage.setItem('ocultarAyudaReporte', 'true');
    $('#ayudaReporte').fadeOut();
}

function mostrarAyuda() { $('#ayudaReporte').fadeIn(); }

function completarPedido(folio) {
    Swal.fire({
        title: '¿Completar pedido?',
        text: 'Se marcarán todos los productos',
        icon: 'question',
        showCancelButton: true,
        confirmButtonColor: '#28a745',
        cancelButtonColor: '#dc3545',
        confirmButtonText: '¡Sí!',
        cancelButtonText: 'No'
    }).then(result => {
        if (result.isConfirmed) {
            fetch('completar_pedido.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ folio: folio })
            }).then(() => location.reload());
        }
    });
}

function completarProducto(id) {
    Swal.fire({
        title: '¿Completar producto?',
        text: 'Este producto se marcará como completado',
        icon: 'question',
        showCancelButton: true,
        confirmButtonColor: '#28a745',
        cancelButtonColor: '#dc3545',
        confirmButtonText: '¡Sí!',
        cancelButtonText: 'No'
    }).then(result => {
        if (result.isConfirmed) {
            fetch('completar_producto.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ id: id })
            }).then(() => location.reload());
        }
    });
}

function verHistorial(idOrden) {
    $('#modalHistorial').modal('show');
    fetch('ver_historial_pedido.php?id_orden=' + idOrden).then(res => res.json()).then(data => {
        let html = '<div class="timeline">';
        if (data && data.length > 0) {
            data.forEach(l => {
                let icono = l.accion.includes('CREADO') ? 'fa-plus-circle' : (l.accion.includes('completado') ? 'fa-check-circle' : 'fa-file-alt');
                let color = l.accion.includes('CREADO') ? '#0d6efd' : (l.accion.includes('completado') ? '#28a745' : '#6c757d');
                html += `<div class="timeline-item">
                            <div class="timeline-icon" style="background: ${color};"><i class="fas ${icono}"></i></div>
                            <div class="timeline-content">
                                <strong>${l.accion}</strong>
                                <div class="small text-muted">${l.fecha} · ${l.usuario}</div>
                                <div>${l.descripcion}</div>
                            </div>
                        </div>`;
            });
        } else {
            html = '<div class="text-center p-4">No hay historial registrado</div>';
        }
        html += '</div>';
        $('#contenidoHistorial').html(html);
    });
}

// ALERTA DE STOCK BAJO - EN ROJO
let tiempoUltimaAlerta = 0;

toastr.options = {
    closeButton: true,
    progressBar: true,
    positionClass: "toast-top-right",
    timeOut: 5000,
    extendedTimeOut: 2000,
    showMethod: "fadeIn",
    hideMethod: "fadeOut"
};

function verificarStockCritico() {
    fetch('ajax_stock_critico.php')
    .then(res => res.json())
    .then(data => {
        if (data.error || !data.length) return;
        const ahora = Date.now();
        if (ahora - tiempoUltimaAlerta > 300000) {
            let mensaje = '';
            const productosMostrar = data.slice(0, 3);
            productosMostrar.forEach(p => { mensaje += `<strong>${p.nombre}</strong> — Stock: ${p.cantidad}<br>`; });
            if (data.length > 3) mensaje += `<br><span class="small">...y ${data.length - 3} productos más</span>`;
            
            // Usar toastr.error para color ROJO
            toastr.error(mensaje, "⚠ Stock crítico detectado");
            tiempoUltimaAlerta = ahora;
        }
    })
    .catch(err => console.log("Error:", err));
}

verificarStockCritico();
setInterval(verificarStockCritico, 60000);

document.addEventListener('click', function(e) {
    let header = e.target.closest('[data-toggle="collapse"]');
    if (header) setTimeout(() => $(header).find('.flecha').toggleClass('abierta'), 150);
});
</script>
