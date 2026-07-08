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
<link rel="stylesheet" href="css/pedidos.css?v=<?= time() ?>">

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
                            <a href="dashboard_ventas.php">
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
            <div class="pedidos-page-top d-flex flex-wrap justify-content-between align-items-center mb-3">
                <h3 class="mb-2 mb-md-0" style="color: #2c3e50;">Pedidos / Reabastecimiento</h3>
                <div class="pedidos-top-actions">
                    <button type="button" class="btn-ayuda" onclick="mostrarAyuda()">
                        <i class="fas fa-question-circle"></i> Ayuda
                    </button>
                    <span class="pedidos-actions-separator"></span>
                    <button type="button" class="btn-excel" onclick="exportarExcel()">
                        <i class="fas fa-file-excel me-1"></i> Excel
                    </button>
                    <button type="button" class="btn-pdf" onclick="exportarPDF()">
                        <i class="fas fa-file-pdf me-1"></i> PDF
                    </button>
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

            <div class="pedido-save-actions mb-3">
                <button type="button" class="btn-guardar-pedido-main" onclick="abrirModalSolicitante()">
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
<div class="modal fade" id="modalSolicitante" tabindex="-1" role="dialog" aria-labelledby="modalSolicitanteTitulo" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-solicitante-dialog" role="document">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title" id="modalSolicitanteTitulo">
                    <i class="fas fa-user-edit"></i> ¿Para quién es el pedido?
                </h5>
                <button type="button" class="close text-white" data-dismiss="modal" aria-label="Cerrar">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
            <div class="modal-body">
                <div class="pedido-resumen-modal" id="resumenPedidoModal">
                    <i class="fas fa-boxes"></i>
                    <span>0 productos seleccionados</span>
                </div>
                <label for="nombreSolicitante">Nombre del solicitante</label>
                <input type="text" id="nombreSolicitante" class="form-control" placeholder="Ej: Juan Pérez" autocomplete="off">
                <small class="text-muted d-block mt-2">Este nombre se guardará en el folio del pedido.</small>
            </div>
            <div class="modal-footer">
                <button type="button" id="btnGuardarPedido" class="btn btn-success btn-guardar-pedido" onclick="confirmarGuardado()">
                    <i class="fas fa-save"></i> Guardar pedido
                </button>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="modalHistorial" tabindex="-1" role="dialog" aria-labelledby="modalHistorialTitulo" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered modal-historial-dialog" role="document">
        <div class="modal-content">
            <div class="modal-header bg-dark text-white">
                <h5 class="modal-title" id="modalHistorialTitulo"><i class="fas fa-history"></i> Historial del pedido</h5>
                <button type="button" class="close text-white" data-dismiss="modal" aria-label="Cerrar">
                    <span aria-hidden="true">&times;</span>
                </button>
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
let productosData = <?= json_encode($productosData, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;
let pedidosTemp = [];
let pedidosSeleccionados = {};
let currentPage = 1;
let rowsPerPage = 10;
let currentFilter = '';

$(document).ready(function() {
    $('#modalSolicitante, #modalHistorial').on('hidden.bs.modal', function() {
        limpiarEstadoModalBootstrap();
    });

    $('#modalSolicitante, #modalHistorial').on('shown.bs.modal', function() {
        $(this).modal('handleUpdate');
    });

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

function escapeHtml(text) {
    if (text === null || text === undefined) return '';
    return String(text)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');
}

function escapeAttr(text) {
    return escapeHtml(text).replace(/`/g, '&#096;');
}

function obtenerProductoPorId(id) {
    id = parseInt(id);
    return productosData.find(p => parseInt(p.id) === id) || null;
}

function actualizarResumenPedidoModal() {
    const totalProductos = Object.keys(pedidosSeleccionados).length;
    const totalPiezas = Object.values(pedidosSeleccionados).reduce((acc, item) => acc + (parseInt(item.pedido) || 0), 0);

    const textoProductos = totalProductos === 1 ? '1 producto seleccionado' : `${totalProductos} productos seleccionados`;
    const textoPiezas = totalPiezas === 1 ? '1 pieza' : `${totalPiezas} piezas`;

    $('#resumenPedidoModal span').text(`${textoProductos} · ${textoPiezas}`);
}

function actualizarPedidoTemporal(input) {
    const $row = $(input).closest('tr');
    const id = parseInt($row.data('id'));
    const producto = obtenerProductoPorId(id);
    const pedido = parseInt(input.value) || 0;

    if (!producto || !id) {
        return;
    }

    if (pedido > 0) {
        const stock = parseInt(producto.cantidad) || 0;
        const faltante = Math.max(0, pedido - stock);

        pedidosSeleccionados[id] = {
            id: id,
            nombre: producto.nombre,
            stock: stock,
            pedido: pedido,
            faltante: faltante
        };
    } else {
        delete pedidosSeleccionados[id];
    }

    actualizarResumenPedidoModal();
}

function calcular(input) {
    let tr = $(input).closest('tr');
    let stock = parseInt(tr.data('stock')) || 0;
    let pedido = parseInt(input.value) || 0;

    if (pedido < 0) {
        pedido = 0;
        input.value = '';
    }

    let nuevoStock = Math.max(0, stock - pedido);
    let faltante = Math.max(0, pedido - stock);

    tr.find('.stock').text(nuevoStock);
    tr.find('.faltante').text(faltante);

    let estadoSpan = tr.find('.estado span');
    tr.removeClass('table-warning table-danger table-success');

    if (pedido === 0) {
        estadoSpan.html('Sin pedido').attr('class', 'badge badge-secondary');
    } else if (faltante === 0) {
        tr.addClass('table-success');
        estadoSpan.html('Stock suficiente').attr('class', 'badge badge-success');
    } else if (stock === 0) {
        tr.addClass('table-danger');
        estadoSpan.html('Sin stock').attr('class', 'badge badge-danger');
    } else {
        tr.addClass('table-warning');
        estadoSpan.html('Faltante parcial').attr('class', 'badge badge-warning');
    }

    actualizarPedidoTemporal(input);
}

function renderTable() {
    let filtrados = productosData.filter(p => String(p.nombre || '').toLowerCase().includes(currentFilter));
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
        const id = parseInt(p.id);
        const stockOriginal = parseInt(p.cantidad) || 0;
        const seleccionado = pedidosSeleccionados[id] || null;
        const pedidoGuardado = seleccionado ? parseInt(seleccionado.pedido) || 0 : 0;
        const nuevoStock = Math.max(0, stockOriginal - pedidoGuardado);
        const faltante = Math.max(0, pedidoGuardado - stockOriginal);

        let badgeClase = 'badge badge-secondary';
        let badgeTexto = 'Sin pedido';
        let rowClass = '';

        if (pedidoGuardado > 0 && faltante === 0) {
            badgeClase = 'badge badge-success';
            badgeTexto = 'Stock suficiente';
            rowClass = 'table-success';
        } else if (pedidoGuardado > 0 && stockOriginal === 0) {
            badgeClase = 'badge badge-danger';
            badgeTexto = 'Sin stock';
            rowClass = 'table-danger';
        } else if (pedidoGuardado > 0) {
            badgeClase = 'badge badge-warning';
            badgeTexto = 'Faltante parcial';
            rowClass = 'table-warning';
        }

        html += `<tr class="${rowClass}" data-id="${id}" data-stock="${stockOriginal}">
                    <td data-label="Producto">${escapeHtml(p.nombre)}</td>
                    <td class="text-center" data-label="Stock"><span class="badge badge-info stock">${nuevoStock}</span></td>
                    <td class="text-center" data-label="Pedir">
                        <input type="number"
                               min="0"
                               class="form-control form-control-sm pedir"
                               style="width:80px;margin:0 auto;"
                               value="${pedidoGuardado > 0 ? pedidoGuardado : ''}"
                               oninput="calcular(this)">
                    </td>
                    <td class="text-center faltante fw-bold" data-label="Faltante">${faltante}</td>
                    <td class="text-center estado" data-label="Estado"><span class="${badgeClase}">${badgeTexto}</span></td>
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

    actualizarResumenPedidoModal();
}

function changePage(page) {
    let filtrados = productosData.filter(p => String(p.nombre || '').toLowerCase().includes(currentFilter));
    let totalPages = Math.max(1, Math.ceil(filtrados.length / rowsPerPage));

    currentPage = Math.min(Math.max(parseInt(page) || 1, 1), totalPages);
    renderTable();
}


$('#buscadorProductos').on('keyup', function() { currentFilter = $(this).val().toLowerCase(); currentPage = 1; renderTable(); });


function limpiarEstadoModalBootstrap() {
    $('.modal-backdrop').remove();
    $('body')
        .removeClass('modal-open')
        .css({
            paddingRight: '',
            overflow: ''
        });
}

function ejecutarDespuesDeCerrarModal(modalSelector, callback) {
    const $modal = $(modalSelector);

    if ($modal.length && $modal.hasClass('show')) {
        $modal.one('hidden.bs.modal', function() {
            limpiarEstadoModalBootstrap();
            setTimeout(callback, 80);
        });
        $modal.modal('hide');
        return;
    }

    limpiarEstadoModalBootstrap();
    setTimeout(callback, 40);
}

function mostrarSwalSeguro(options) {
    limpiarEstadoModalBootstrap();
    return Swal.fire(options);
}

function abrirModalSolicitante() {
    pedidosTemp = Object.values(pedidosSeleccionados)
        .map(item => ({
            id: parseInt(item.id),
            nombre: item.nombre,
            stock: parseInt(item.stock) || 0,
            pedido: parseInt(item.pedido) || 0,
            faltante: parseInt(item.faltante) || 0
        }))
        .filter(item => item.id && item.pedido > 0);

    console.log('Pedidos a guardar:', pedidosTemp);

    if (pedidosTemp.length === 0) {
        Swal.fire({
            icon: 'info',
            title: 'Sin pedidos',
            text: 'No has solicitado ningún producto',
            confirmButtonColor: '#f97316'
        });
        return;
    }

    actualizarResumenPedidoModal();

    $('#modalSolicitante').modal({
        backdrop: 'static',
        keyboard: false,
        show: true
    });

    setTimeout(() => {
        $('#nombreSolicitante').trigger('focus');
    }, 350);
}

function confirmarGuardado() {
    const nombre = $('#nombreSolicitante').val().trim();

    pedidosTemp = Object.values(pedidosSeleccionados)
        .map(item => ({
            id: parseInt(item.id),
            nombre: item.nombre,
            stock: parseInt(item.stock) || 0,
            pedido: parseInt(item.pedido) || 0,
            faltante: parseInt(item.faltante) || 0
        }))
        .filter(item => item.id && item.pedido > 0);

    if (pedidosTemp.length === 0) {
        ejecutarDespuesDeCerrarModal('#modalSolicitante', function() {
            mostrarSwalSeguro({
                icon: 'info',
                title: 'Sin pedidos',
                text: 'No hay productos seleccionados para guardar.',
                confirmButtonColor: '#f97316'
            });
        });
        return;
    }

    if (nombre === '') {
        Swal.fire({
            icon: 'error',
            title: 'Falta el solicitante',
            text: 'Escribe para quién es el pedido',
            confirmButtonColor: '#f97316'
        }).then(() => {
            setTimeout(() => $('#nombreSolicitante').trigger('focus'), 150);
        });
        return;
    }

    $('#btnGuardarPedido')
        .prop('disabled', true)
        .html('<i class="fas fa-spinner fa-spin"></i> Guardando...');

    const payload = {
        solicitado_por: nombre,
        pedidos: pedidosTemp
    };

    ejecutarDespuesDeCerrarModal('#modalSolicitante', function() {
        guardarPedidoEnServidor(payload);
    });
}

function guardarPedidoEnServidor(payload) {
    mostrarSwalSeguro({
        title: 'Guardando pedido...',
        text: 'Por favor espera',
        allowOutsideClick: false,
        showConfirmButton: false,
        didOpen: () => {
            Swal.showLoading();
        }
    });

    fetch('guardar_pedido.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(payload)
    })
    .then(async response => {
        const raw = await response.text();

        let data = null;
        try {
            data = JSON.parse(raw);
        } catch (e) {
            throw new Error(raw ? raw.substring(0, 400) : 'El servidor no devolvió JSON válido.');
        }

        if (!response.ok) {
            throw new Error(data.message || 'Error HTTP ' + response.status);
        }

        return data;
    })
    .then(data => {
        Swal.close();
        limpiarEstadoModalBootstrap();

        if (data.success) {
            pedidosSeleccionados = {};
            pedidosTemp = [];

            mostrarSwalSeguro({
                icon: 'success',
                title: '¡Pedido guardado!',
                text: data.message || 'El pedido se ha registrado correctamente',
                confirmButtonColor: '#f97316',
                timer: 2000
            }).then(() => {
                location.reload();
            });
        } else {
            mostrarSwalSeguro({
                icon: 'error',
                title: 'Error',
                text: data.message || 'No se pudo guardar el pedido',
                confirmButtonColor: '#f97316'
            });
        }
    })
    .catch(error => {
        Swal.close();
        limpiarEstadoModalBootstrap();
        console.error('Error:', error);
        mostrarSwalSeguro({
            icon: 'error',
            title: 'Error al guardar pedido',
            text: error.message || 'No se pudo conectar con el servidor',
            confirmButtonColor: '#f97316'
        });
    })
    .finally(() => {
        $('#btnGuardarPedido')
            .prop('disabled', false)
            .html('<i class="fas fa-save"></i> Guardar pedido');
    });
}

function obtenerContenedoresReporte(estadoActivo) {
    if (estadoActivo === 'pendiente') return ['#pendientesContainer'];
    if (estadoActivo === 'completado') return ['#completadosContainer'];
    if (estadoActivo === 'cancelado') return ['#canceladosContainer'];
    return ['#pendientesContainer', '#completadosContainer', '#canceladosContainer'];
}

function obtenerFolioDesdeCard($card) {
    const search = String($card.attr('data-search') || '').trim();
    const matchSearch = search.match(/^(\d+)/);

    if (matchSearch) {
        return matchSearch[1];
    }

    const texto = String($card.text() || '');
    const matchTexto = texto.match(/Folio\s*#\s*(\d+)/i);
    return matchTexto ? matchTexto[1] : '';
}

function cardCoincideConFiltroReporte($card) {
    const tipo = $('#tipoReporte').val();
    const search = String($card.attr('data-search') || '').toLowerCase();

    if (tipo === 'solicitante') {
        const solicitante = String($('#filtroSolicitante').val() || '').trim().toLowerCase();
        return solicitante !== '' && search.includes(solicitante);
    }

    if (tipo === 'orden') {
        const folio = String($('#filtroOrden').val() || '').trim();
        return folio !== '' && obtenerFolioDesdeCard($card) === folio;
    }

    return true;
}

function contarPedidosParaReporte(estadoActivo) {
    let total = 0;
    const contenedores = obtenerContenedoresReporte(estadoActivo);

    contenedores.forEach(selector => {
        $(selector).find('.pedido-card').each(function() {
            const $card = $(this);
            if (cardCoincideConFiltroReporte($card)) {
                total++;
            }
        });
    });

    return total;
}

function tituloEstadoReporte(estadoActivo) {
    if (estadoActivo === 'pendiente') return 'pedidos pendientes';
    if (estadoActivo === 'completado') return 'pedidos completados';
    if (estadoActivo === 'cancelado') return 'pedidos cancelados';
    return 'pedidos registrados';
}

function validarFiltrosReporte() {
    const tipo = $('#tipoReporte').val();

    if (tipo === 'solicitante') {
        const solicitante = $('#filtroSolicitante').val();
        if (!solicitante) {
            Swal.fire({
                icon: 'warning',
                title: 'Selecciona un solicitante',
                text: 'Para generar el reporte por solicitante, primero elige uno de la lista.',
                confirmButtonColor: '#f97316'
            });
            return false;
        }
    }

    if (tipo === 'orden') {
        const folio = $('#filtroOrden').val();
        if (!folio) {
            Swal.fire({
                icon: 'warning',
                title: 'Selecciona un folio',
                text: 'Para generar el reporte por folio, primero elige un número de folio.',
                confirmButtonColor: '#f97316'
            });
            return false;
        }
    }

    return true;
}

function validarDatosReporte(estadoActivo) {
    if (!validarFiltrosReporte()) {
        return false;
    }

    const totalPedidos = contarPedidosParaReporte(estadoActivo);

    if (totalPedidos <= 0) {
        const tipo = $('#tipoReporte').val();
        let detalleFiltro = '';

        if (tipo === 'solicitante') {
            detalleFiltro = ` para el solicitante "${$('#filtroSolicitante').val()}"`;
        } else if (tipo === 'orden') {
            detalleFiltro = ` para el folio #${$('#filtroOrden').val()}`;
        }

        Swal.fire({
            icon: 'info',
            title: 'No hay pedidos para reportar',
            text: `No existen ${tituloEstadoReporte(estadoActivo)}${detalleFiltro}. No se descargó ningún archivo.`,
            confirmButtonColor: '#f97316',
            confirmButtonText: 'Entendido'
        });
        return false;
    }

    return true;
}

function agregarParametroUrl(url, nombre, valor) {
    const separador = url.includes('?') ? '&' : '?';
    return `${url}${separador}${encodeURIComponent(nombre)}=${encodeURIComponent(valor)}`;
}

function construirUrlReporte(formato) {
    const estadoActivo = $('.filtro-estado.active').data('estado') || 'pendiente';
    const tipo = $('#tipoReporte').val();
    let url = formato === 'pdf'
        ? 'exportar_pdf_pedidos.php'
        : 'exportar_excel_pedidos.php';

    url = agregarParametroUrl(url, 'estado', estadoActivo);

    if (tipo === 'solicitante') {
        url = agregarParametroUrl(url, 'solicitado_por', $('#filtroSolicitante').val());
    } else if (tipo === 'orden') {
        url = agregarParametroUrl(url, 'id_orden', $('#filtroOrden').val());
    }

    return url;
}

function limpiarTextoServidor(texto) {
    if (!texto) return '';

    try {
        const data = JSON.parse(texto);
        return data.message || data.error || data.mensaje || 'No hay datos para generar el reporte.';
    } catch (e) {}

    const div = document.createElement('div');
    div.innerHTML = texto;
    const limpio = (div.textContent || div.innerText || '').trim();

    if (!limpio) return 'No se pudo generar el reporte.';
    return limpio.substring(0, 350);
}

function obtenerNombreArchivoReporte(response, fallback) {
    const disposition = response.headers.get('content-disposition') || '';
    const matchUtf8 = disposition.match(/filename\*=UTF-8''([^;]+)/i);
    const matchNormal = disposition.match(/filename="?([^";]+)"?/i);

    if (matchUtf8 && matchUtf8[1]) {
        return decodeURIComponent(matchUtf8[1]);
    }

    if (matchNormal && matchNormal[1]) {
        return matchNormal[1];
    }

    return fallback;
}

async function descargarReporteSeguro(url, opciones) {
    const extension = opciones.extension;
    const abrir = opciones.abrir || false;
    const estadoActivo = $('.filtro-estado.active').data('estado') || 'todos';
    const fecha = new Date().toISOString().slice(0, 10);
    const fallback = `Reporte_Pedidos_${estadoActivo}_${fecha}.${extension}`;

    Swal.fire({
        title: 'Generando reporte...',
        text: 'Por favor espera',
        allowOutsideClick: false,
        showConfirmButton: false,
        didOpen: () => Swal.showLoading()
    });

    try {
        const response = await fetch(url, {
            method: 'GET',
            cache: 'no-store',
            headers: {
                'X-Requested-With': 'XMLHttpRequest'
            }
        });

        const contentType = (response.headers.get('content-type') || '').toLowerCase();

        if (!response.ok || contentType.includes('application/json') || contentType.includes('text/plain') || contentType.includes('text/html')) {
            const texto = await response.text();
            throw new Error(limpiarTextoServidor(texto));
        }

        const blob = await response.blob();

        if (!blob || blob.size === 0) {
            throw new Error('El reporte se generó vacío. No se descargó ningún archivo.');
        }

        const blobUrl = URL.createObjectURL(blob);
        const link = document.createElement('a');
        link.href = blobUrl;
        link.download = obtenerNombreArchivoReporte(response, fallback);
        document.body.appendChild(link);
        link.click();
        document.body.removeChild(link);

        if (abrir) {
            window.open(blobUrl, '_blank');
        }

        setTimeout(() => URL.revokeObjectURL(blobUrl), 4000);

        Swal.close();
        Swal.fire({
            icon: 'success',
            title: 'Reporte generado',
            text: abrir ? 'El archivo se descargó y se abrió en una nueva ventana.' : 'El archivo se descargó correctamente.',
            timer: 1800,
            showConfirmButton: false,
            toast: true,
            position: 'top-end'
        });
    } catch (error) {
        Swal.close();
        console.error('Error reporte:', error);
        Swal.fire({
            icon: 'info',
            title: 'No se generó el reporte',
            text: error.message || 'No hay datos para generar el reporte. No se descargó ningún archivo.',
            confirmButtonColor: '#f97316',
            confirmButtonText: 'Entendido'
        });
    }
}

function exportarExcel() {
    const estadoActivo = $('.filtro-estado.active').data('estado') || 'pendiente';

    if (!validarDatosReporte(estadoActivo)) {
        return;
    }

    const url = construirUrlReporte('excel');

    descargarReporteSeguro(url, {
        extension: 'xls',
        abrir: false
    });
}

function exportarPDF() {
    const estadoActivo = $('.filtro-estado.active').data('estado') || 'pendiente';

    if (!validarDatosReporte(estadoActivo)) {
        return;
    }

    const url = construirUrlReporte('pdf');

    descargarReporteSeguro(url, {
        extension: 'pdf',
        abrir: true
    });
}

function cerrarAyuda() {
    if ($('#noMostrarAyuda').is(':checked')) localStorage.setItem('ocultarAyudaReporte', 'true');
    $('#ayudaReporte').fadeOut();
}

function mostrarAyuda() { $('#ayudaReporte').fadeIn(); }


async function leerRespuestaJson(response) {
    const raw = await response.text();

    let data = null;

    try {
        data = JSON.parse(raw);
    } catch (e) {
        throw new Error(raw ? raw.substring(0, 450) : 'El servidor no devolvió JSON válido.');
    }

    if (!response.ok || !data.success) {
        throw new Error(data.message || 'No se pudo completar la operación.');
    }

    return data;
}

function mostrarCargandoPedido(titulo) {
    Swal.fire({
        title: titulo,
        text: 'Por favor espera',
        allowOutsideClick: false,
        showConfirmButton: false,
        didOpen: () => {
            Swal.showLoading();
        }
    });
}

function completarPedido(folio) {
    Swal.fire({
        title: '¿Completar pedido?',
        text: 'Se marcarán todos los productos pendientes y se guardará el historial del pedido.',
        icon: 'question',
        showCancelButton: true,
        confirmButtonColor: '#28a745',
        cancelButtonColor: '#dc3545',
        confirmButtonText: 'Sí, completar',
        cancelButtonText: 'No'
    }).then(async result => {
        if (!result.isConfirmed) return;

        mostrarCargandoPedido('Completando pedido...');

        try {
            const response = await fetch('completar_pedido.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    folio: folio,
                    id_orden: folio
                })
            });

            const data = await leerRespuestaJson(response);
            Swal.close();

            Swal.fire({
                icon: 'success',
                title: 'Pedido completado',
                html: `
                    <div style="text-align:center;">
                        <p style="margin-bottom:8px;">${escapeHtml(data.message || 'El pedido fue completado correctamente.')}</p>
                    </div>
                `,
                confirmButtonColor: '#28a745',
                timer: 2200
            }).then(() => {
                location.reload();
            });
        } catch (error) {
            Swal.close();
            console.error('Error completarPedido:', error);

            Swal.fire({
                icon: 'error',
                title: 'Error al completar pedido',
                text: error.message || 'No se pudo completar el pedido.',
                confirmButtonColor: '#f97316'
            });
        }
    });
}

function completarProducto(id) {
    Swal.fire({
        title: '¿Completar producto?',
        text: 'Este producto se marcará como completado y se guardará en el historial.',
        icon: 'question',
        showCancelButton: true,
        confirmButtonColor: '#28a745',
        cancelButtonColor: '#dc3545',
        confirmButtonText: 'Sí, completar',
        cancelButtonText: 'No'
    }).then(async result => {
        if (!result.isConfirmed) return;

        mostrarCargandoPedido('Completando producto...');

        try {
            const response = await fetch('completar_producto.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    id: id,
                    id_pedido: id
                })
            });

            const data = await leerRespuestaJson(response);
            Swal.close();

            Swal.fire({
                icon: 'success',
                title: 'Producto completado',
                html: `
                    <div style="text-align:center;">
                        <p style="margin-bottom:8px;">${escapeHtml(data.message || 'El producto fue completado correctamente.')}</p>
                    </div>
                `,
                confirmButtonColor: '#28a745',
                timer: 2200
            }).then(() => {
                location.reload();
            });
        } catch (error) {
            Swal.close();
            console.error('Error completarProducto:', error);

            Swal.fire({
                icon: 'error',
                title: 'Error al completar producto',
                text: error.message || 'No se pudo completar el producto.',
                confirmButtonColor: '#f97316'
            });
        }
    });
}

function verHistorial(idOrden) {
    $('#modalHistorial').modal('show');
    fetch('ver_historial_pedido.php?id_orden=' + idOrden)
        .then(res => res.json())
        .then(data => {
            let html = '<div class="timeline">';

            if (data && data.length > 0) {
                data.forEach(l => {
                    const accionTexto = String(l.accion || '');
                    const accionLower = accionTexto.toLowerCase();

                    let icono = 'fa-file-alt';
                    let color = '#6c757d';

                    if (accionLower.includes('creado')) {
                        icono = 'fa-plus-circle';
                        color = '#0d6efd';
                    } else if (accionLower.includes('completado')) {
                        icono = 'fa-check-circle';
                        color = '#28a745';
                    } else if (accionLower.includes('cancelado')) {
                        icono = 'fa-ban';
                        color = '#dc3545';
                    }

                    html += `<div class="timeline-item">
                                <div class="timeline-icon" style="background: ${color};"><i class="fas ${icono}"></i></div>
                                <div class="timeline-content">
                                    <strong>${escapeHtml(accionTexto)}</strong>
                                    <div class="small text-muted">${escapeHtml(l.fecha || '')} · ${escapeHtml(l.usuario || 'Sistema')}</div>
                                    <div>${escapeHtml(l.descripcion || l.detalle || '')}</div>
                                </div>
                            </div>`;
                });
            } else {
                html = '<div class="text-center p-4">No hay historial registrado</div>';
            }

            html += '</div>';
            $('#contenidoHistorial').html(html);
        })
        .catch(error => {
            $('#contenidoHistorial').html(`
                <div class="text-center p-4 text-danger">
                    <i class="fas fa-exclamation-triangle"></i><br>
                    No se pudo cargar el historial.
                </div>
            `);
            console.error('Error historial:', error);
        });
}


// ALERTA DE STOCK BAJO - UNA SOLA VEZ POR ENTRADA AL MÓDULO
// - Si recargas por guardar/completar pedido, NO vuelve a salir.
// - Si sales del módulo y vuelves a entrar, SÍ vuelve a salir.
const STOCK_ALERT_KEY = 'pedidos_stock_critico_mostrado_en_entrada';

function obtenerTipoNavegacion() {
    const navEntries = performance.getEntriesByType && performance.getEntriesByType('navigation');

    if (navEntries && navEntries.length > 0) {
        return navEntries[0].type || 'navigate';
    }

    if (performance.navigation && performance.navigation.type === 1) {
        return 'reload';
    }

    return 'navigate';
}

function prepararAlertaStockPorEntrada() {
    const tipoNavegacion = obtenerTipoNavegacion();

    // Si no es recarga, significa que el usuario está entrando al módulo desde otra pantalla,
    // desde el menú, desde el historial o abriendo la ruta de nuevo. En ese caso permitimos
    // mostrar la alerta otra vez.
    if (tipoNavegacion !== 'reload') {
        sessionStorage.removeItem(STOCK_ALERT_KEY);
    }
}

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
    // Si ya se mostró en esta entrada al módulo, no volvemos a molestar.
    if (sessionStorage.getItem(STOCK_ALERT_KEY) === 'true') {
        return;
    }

    fetch('ajax_stock_critico.php')
    .then(res => res.json())
    .then(data => {
        if (data.error || !data.length) return;

        let mensaje = '';
        const productosMostrar = data.slice(0, 3);

        productosMostrar.forEach(p => {
            mensaje += `<strong>${escapeHtml(p.nombre)}</strong> — Stock: ${escapeHtml(p.cantidad)}<br>`;
        });

        if (data.length > 3) {
            mensaje += `<br><span class="small">...y ${data.length - 3} productos más</span>`;
        }

        toastr.error(mensaje, "⚠ Stock crítico detectado");

        // Guardamos que ya se mostró durante esta entrada al módulo.
        // Si la página se recarga por completar/guardar, esta marca se conserva.
        sessionStorage.setItem(STOCK_ALERT_KEY, 'true');
    })
    .catch(err => console.log("Error:", err));
}

prepararAlertaStockPorEntrada();
verificarStockCritico();

document.addEventListener('click', function(e) {
    let header = e.target.closest('[data-toggle="collapse"]');
    if (header) setTimeout(() => $(header).find('.flecha').toggleClass('abierta'), 150);
});
</script>