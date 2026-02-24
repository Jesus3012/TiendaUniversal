<?php
session_start();
include 'includes/db.php';
include 'includes/header.php';
include('includes/navbar.php');

date_default_timezone_set('America/Mexico_City');


$res = $conn->query("SELECT id, nombre, cantidad FROM productos ORDER BY nombre");
if(!$res){
    die("Error SQL: " . $conn->error);
}

$solicitantes = $conn->query("SELECT DISTINCT solicitado_por FROM pedidos ORDER BY solicitado_por");
$ordenes = $conn->query("SELECT id_orden, MAX(solicitado_por) as solicitado_por FROM pedidos GROUP BY id_orden ORDER BY id_orden DESC
");
?>

<style>
.select2-container {
    width: 100% !important;
}
.select2-selection {
    height: 38px !important;
    padding-top: 4px;
}

@media (max-width: 768px){

    #tablaPedidos thead{
        display:none;
    }

    #tablaPedidos tbody tr{
        display:block;
        margin-bottom:15px;
        border:1px solid #ddd;
        border-radius:10px;
        padding:10px;
        background:white;
    }

    #tablaPedidos tbody td{
        display:flex;
        justify-content:space-between;
        padding:6px 4px;
        font-size:14px;
    }

    #tablaPedidos tbody td:before{
        font-weight:bold;
    }

    #tablaPedidos tbody td:nth-child(1):before{ content:"Producto"; }
    #tablaPedidos tbody td:nth-child(2):before{ content:"Stock"; }
    #tablaPedidos tbody td:nth-child(3):before{ content:"Pedir"; }
    #tablaPedidos tbody td:nth-child(4):before{ content:"Faltante"; }
    #tablaPedidos tbody td:nth-child(5):before{ content:"Estado"; }

    .pedir{
        width:80px;
    }
}

.flecha{
    display:inline-block;
    transition: transform .35s ease;
}

.flecha.abierta{
    transform: rotate(90deg);
}

.timeline {
    position: relative;
    padding-left: 20px;
}

.timeline::before {
    content: '';
    position: absolute;
    left: 14px;
    top: 0;
    bottom: 0;
    width: 2px;
    background: #dee2e6;
}

.timeline-item {
    position: relative;
}

.timeline-icon {
    width: 32px;
    height: 32px;
    border-radius: 50%;
    color: #fff;
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
    margin-top: 4px;
}

.timeline-content {
    width: 100%;
}

</style>

<div class="content-wrapper">
    <section class="content pt-4">
        <div class="container-fluid">

            <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center mb-3 gap-2">
                <h3 class="mb-2 mb-md-0">Módulo de Pedidos / Reabastecimiento</h3>

                <button class="btn btn-info mb-2 mb-sm-0" onclick="mostrarAyuda()" title="Muestra las instrucciones para generar reportes">
                    <i class="fas fa-question-circle"></i> Ayuda
                </button>
                <div class="btn-group">
                    <button class="btn btn-primary" onclick="exportarExcel()">
                        <i class="fas fa-file-excel"></i> Excel
                    </button>

                    <button class="btn btn-danger" onclick="exportarPDF()">
                        <i class="fas fa-file-pdf"></i> PDF
                    </button>
                </div>
            </div>

            <div id="ayudaReporte" class="alert alert-info alert-dismissible fade show" role="alert" style="display:none;">
                <h5 class="mb-2"><i class="fas fa-info-circle"></i> ¿Cómo generar un reporte?</h5>
                
                <ol class="mb-3">
                    <li>Selecciona el <strong>tipo de filtro</strong>.</li>
                    <li>Elige el <strong>solicitante</strong> o el <strong>folio</strong>.</li>
                    <li>Presiona <strong>Excel</strong> o <strong>PDF</strong> para descargar.</li>
                </ol>

                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <input type="checkbox" id="noMostrarAyuda">
                        <label for="noMostrarAyuda" class="mb-0">No volver a mostrar este mensaje</label>
                    </div>

                    <button class="btn btn-sm btn-primary" onclick="cerrarAyuda()">
                        Entendido
                    </button>
                </div>
            </div>

            <!-- FILTROS -->
        <div class="row mb-3 align-items-end">
                <!-- Tipo de reporte -->
                <div class="col-md-4">
                    <label class="font-weight-bold">Tipo de reporte</label>
                    <select id="tipoReporte" class="form-control">
                        <option value="todos">Todos los pedidos</option>
                        <option value="solicitante">Filtrar por solicitante</option>
                        <option value="orden">Filtrar por folio</option>
                    </select>
                </div>

                <!-- Solicitante -->
                <div class="col-md-4" id="divSolicitante" style="display:none;">
                    <label class="font-weight-bold">Solicitante</label>
                    <select id="filtroSolicitante" class="form-control w-100">
                        <option value="">Buscar quién hizo el pedido...</option>
                        <?php while($s = $solicitantes->fetch_assoc()): ?>
                            <option value="<?= $s['solicitado_por'] ?>">
                                <?= $s['solicitado_por'] ?>
                            </option>
                        <?php endwhile; ?>
                    </select>
                </div>

                <!-- Folio -->
                <div class="col-md-4" id="divOrden" style="display:none;">
                    <label class="font-weight-bold">Folio del pedido</label>
                    <select id="filtroOrden" class="form-control w-100">
                        <option value="">Buscar folio...</option>
                        <?php while($o = $ordenes->fetch_assoc()): ?>
                            <option value="<?= $o['id_orden'] ?>">
                                Folio #<?= $o['id_orden'] ?> — <?= $o['solicitado_por'] ?>
                            </option>
                        <?php endwhile; ?>
                    </select>
                </div>
            </div>
            
            <!-- TABLA -->
            <div class="card card-outline card-primary shadow">
                <div class="card-header d-flex align-items-center">
                    <h5 class="mb-0">
                        <i class="fas fa-boxes"></i> Lista de productos
                    </h5>
                    <div class="ml-auto" style="width:320px;">
                        <div class="input-group input-group-sm">
                            <div class="input-group-prepend">
                                <span class="input-group-text bg-white border-right-0">
                                    <i class="fas fa-search text-muted"></i>
                                </span>
                            </div>
                            <input type="text"
                                id="buscadorProductos"
                                class="form-control border-left-0"
                                placeholder="Buscar producto...">
                            <div class="input-group-append">
                                <span class="input-group-text bg-white">
                                    <span id="contadorProductos" class="text-muted">0</span>
                                </span>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover table-striped text-center mb-0" id="tablaPedidos">
                            <thead class="bg-dark text-white">
                                <tr>
                                    <th>Producto</th>
                                    <th>Stock Actual</th>
                                    <th>Pedir</th>
                                    <th>Articulos por hacer</th>
                                    <th>Estado</th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php while($row = $res->fetch_assoc()): ?>
                                <tr data-id="<?= $row['id'] ?>"
                                    data-nombre="<?= $row['nombre'] ?>"
                                    data-stock="<?= $row['cantidad'] ?>">

                                    <td class="text-left font-weight-bold"><?= $row['nombre'] ?></td>
                                    <td><span class="badge badge-info p-2 stock"><?= $row['cantidad'] ?></span></td>
                                    <td>
                                        <input type="number" min="0"
                                            class="form-control form-control-sm pedir"
                                            oninput="calcular(this)">
                                    </td>
                                    <td class="faltante font-weight-bold">0</td>
                                    <td class="estado">
                                        <span class="badge badge-secondary">Sin pedido</span>
                                    </td>
                                </tr>
                            <?php endwhile; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <div class="d-flex justify-content-end">
                <button class="btn btn-success" onclick="abrirModalSolicitante()">
                    <i class="fas fa-save"></i> Guardar Pedido
                </button>
            </div>

            <hr class="my-4">
            <div class="d-flex justify-content-end mb-2">
                <div class="btn-group btn-group-sm">
                    <button class="btn btn-outline-primary filtro-estado active" data-estado="pendiente">Pendientes</button>
                    <button class="btn btn-outline-success filtro-estado" data-estado="completado">Completados</button>
                    <button class="btn btn-outline-secondary filtro-estado" data-estado="todos">Todos</button>
                </div>
            </div>

            <hr class="my-4">
            <div class="card card-outline card-warning shadow">
                <div class="card-header d-flex align-items-center">
                    <h5 class="mb-0" id="tituloPedidos">
                        <i class="fas fa-clipboard-list"></i>
                        Pedidos pendientes por completar
                    </h5>

                    <div class="ml-auto" style="width:360px;">
                        <div class="input-group input-group-sm">
                            <div class="input-group-prepend">
                                <span class="input-group-text bg-white border-right-0">
                                    <i class="fas fa-search text-muted"></i>
                                </span>
                            </div>
                            <input type="text"
                                id="buscadorPedidos"
                                class="form-control border-left-0"
                                placeholder="Buscar folio, solicitante o producto...">
                        </div>
                    </div>
                </div>

                <div class="card-body p-2">
                <?php
                $foliosPendientes = $conn->query("
                    SELECT 
                        p.id_orden,
                        MAX(p.solicitado_por) as solicitado_por,
                        MAX(p.fecha) as fecha
                    FROM pedidos p
                    WHERE EXISTS (
                        SELECT 1 
                        FROM pedidos px
                        WHERE px.id_orden = p.id_orden
                        AND px.estado = 'pendiente'
                    )
                    GROUP BY p.id_orden
                    ORDER BY p.id_orden DESC
                ");

                $foliosCompletados = $conn->query("
                    SELECT 
                        p.id_orden,
                        MAX(p.solicitado_por) as solicitado_por,
                        MAX(p.fecha) as fecha
                    FROM pedidos p
                    WHERE NOT EXISTS (
                        SELECT 1 
                        FROM pedidos px
                        WHERE px.id_orden = p.id_orden
                        AND px.estado = 'pendiente'
                    )
                    GROUP BY p.id_orden
                    ORDER BY p.id_orden DESC
                ");

                if($foliosPendientes->num_rows == 0){
                    echo '<div class="alert alert-success text-center m-2">
                            <i class="fas fa-check-circle"></i>
                            No hay pedidos pendientes
                        </div>';
                }
                ?>

                <div id="accordionPedidos">
                    <?php $i=0; while($f = $foliosPendientes->fetch_assoc()): $i++; ?>


                        <?php

                        // Construimos texto de búsqueda del pedido
                        $textoBusqueda = $f['id_orden'] . ' ' . $f['solicitado_por'];

                        $productosTxt = $conn->query("
                            SELECT nombre_producto
                            FROM pedidos
                            WHERE id_orden = {$f['id_orden']}
                        ");

                        while($pt = $productosTxt->fetch_assoc()){
                            $textoBusqueda .= ' ' . $pt['nombre_producto'];
                        }
                        ?>

                        <div class="border rounded mb-3 shadow-sm pedido-card"
                            data-search="<?= strtolower($textoBusqueda) ?>"
                            data-estado="pendiente">


                            <!-- ENCABEZADO BONITO -->
                            <div class="bg-warning p-2 d-flex justify-content-between align-items-center flex-wrap"
                                data-toggle="collapse"
                                data-target="#collapse<?= $i ?>"
                                style="cursor:pointer;">

                                <div class="text-dark d-flex align-items-center">
                                   <i class="fas fa-chevron-right mr-2 flecha"></i>

                                    <strong>Folio #<?= $f['id_orden'] ?></strong>

                                    <span class="ml-3">
                                        <i class="fas fa-user"></i> <?= $f['solicitado_por'] ?>
                                    </span>

                                    <span class="ml-3">
                                        <i class="fas fa-clock"></i>
                                        <?= date('d/m/Y H:i', strtotime($f['fecha'])) ?>
                                    </span>
                                    
                                    <?php
                                        $diff = (new DateTime($f['fecha']))->diff(new DateTime());

                                        if($diff->days > 0){
                                            $tiempo = "Hace {$diff->days} día(s)";
                                        }elseif($diff->h > 0){
                                            $tiempo = "Hace {$diff->h} hora(s)";
                                        }else{
                                            $tiempo = "Hace {$diff->i} minuto(s)";
                                        }
                                    ?>
                                    <span class="ml-3 text-muted">
                                        <i class="fas fa-clock"></i> <?= $tiempo ?>
                                    </span>
                                </div>
                                <div>
                                    <button class="btn btn-info btn-sm"
                                        onclick="event.stopPropagation(); verHistorial(<?= $f['id_orden'] ?>)">
                                        Historial
                                        <i class="fas fa-history"></i>
                                    </button>
                                    <button class="btn btn-success btn-sm"
                                            onclick="event.stopPropagation(); completarPedido(<?= $f['id_orden'] ?>)">
                                        Completar pedido
                                    </button>
                                </div>
                            </div>

                            <!--COLLAPSE -->
                            <div id="collapse<?= $i ?>" 
                                class="collapse"
                                data-parent="#accordionPedidos">

                                <!-- TABLA COMPACTA -->
                                <div class="table-responsive">
                                    <table class="table table-sm table-hover text-center mb-0">
                                        <thead class="bg-light">
                                            <tr> 
                                                <th class="text-left">Producto</th>
                                                <th>Pedido</th>
                                                <th>Artículos por hacer</th>
                                                <th>Completar articulo</th>

                                            </tr>
                                        </thead>
                                        <tbody>
                                        <?php
                                        $productos = $conn->query("
                                            SELECT id, nombre_producto, cantidad_pedida, faltante
                                            FROM pedidos
                                            WHERE id_orden = {$f['id_orden']}
                                        ");

                                        while($p = $productos->fetch_assoc()):
                                        ?>
                                            <tr>
                                                <td class="text-left"><?= $p['nombre_producto'] ?></td>
                                                <td>
                                                    <span class="badge badge-info"><?= $p['cantidad_pedida'] ?></span>
                                                </td>
                                                <td>
                                                    <?php if($p['faltante'] > 0): ?>
                                                        <span class="badge badge-danger"><?= $p['faltante'] ?></span>
                                                    <?php else: ?>
                                                        <span class="badge badge-success">0</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <button class="btn btn-sm btn-outline-success"
                                                        onclick="completarProducto(<?= $p['id'] ?>)">
                                                        <i class="fas fa-check"></i>
                                                    </button>
                                                </td>
                                            </tr>
                                        <?php endwhile; ?>
                                        
                                        
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                        <?php endwhile; ?>

                        <?php while($f = $foliosCompletados->fetch_assoc()): $i++; ?>
                        <?php
                        // Construimos texto de búsqueda
                        $textoBusqueda = $f['id_orden'] . ' ' . $f['solicitado_por'];

                        $productosTxt = $conn->query("
                            SELECT nombre_producto
                            FROM pedidos
                            WHERE id_orden = {$f['id_orden']}
                        ");

                        while($pt = $productosTxt->fetch_assoc()){
                            $textoBusqueda .= ' ' . $pt['nombre_producto'];
                        }
                        ?>

                        <div class="border rounded mb-3 shadow-sm pedido-card"
                            data-search="<?= strtolower($textoBusqueda) ?>"
                            data-estado="completado">

                            <div class="bg-success p-2 d-flex justify-content-between align-items-center flex-wrap"
                                data-toggle="collapse"
                                data-target="#collapse<?= $i ?>"
                                style="cursor:pointer;">

                                <div class="text-white d-flex align-items-center">
                                    <i class="fas fa-chevron-right mr-2 flecha"></i>

                                    <strong>Folio #<?= $f['id_orden'] ?></strong>

                                    <span class="ml-3">
                                        <i class="fas fa-user"></i> <?= $f['solicitado_por'] ?>
                                    </span>

                                    <span class="ml-3">
                                        <i class="fas fa-clock"></i>
                                        <?= date('d/m/Y H:i', strtotime($f['fecha'])) ?>
                                    </span>
                                </div>
                                <button class="btn btn-light btn-sm"
                                    onclick="event.stopPropagation(); verHistorial(<?= $f['id_orden'] ?>)">
                                    Historial
                                    <i class="fas fa-history"></i>
                                </button>

                            </div>

                            <div id="collapse<?= $i ?>" 
                                class="collapse"
                                data-parent="#accordionPedidos">

                                <div class="table-responsive">
                                    <table class="table table-sm table-hover text-center mb-0">
                                        <thead class="bg-light">
                                            <tr> 
                                                <th class="text-left">Producto</th>
                                                <th>Original</th>
                                                <th>Solventado</th>
                                                <th>Completado el</th>

                                            </tr>
                                        </thead>
                                        <?php
                                        $productos = $conn->query("
                                            SELECT 
                                                p.nombre_producto,
                                                ps.cantidad_original,
                                                ps.cantidad_solventada,
                                                ps.cantidad_faltante,
                                                ps.fecha
                                            FROM pedidos p
                                            LEFT JOIN pedidos_solventados ps 
                                                ON ps.id_producto = p.id
                                                AND ps.id_pedido = p.id_orden
                                            WHERE p.id_orden = {$f['id_orden']}
                                        ");

                                        while($p = $productos->fetch_assoc()):
                                        ?>
                                            <tr>
                                                <td class="text-left"><?= $p['nombre_producto'] ?></td>
                                                <td>
                                                    <span class="badge badge-info"> <?= $p['cantidad_original'] ?? 0 ?> </span>
                                                </td>
                                                <td>
                                                    <span class="badge badge-success"> <?= $p['cantidad_solventada'] ?? 0 ?> </span>
                                                </td>
                                                <td>
                                                    <?= $p['fecha'] ? date("d/m/Y H:i", strtotime($p['fecha'])) : '-' ?> 
                                                </td>
                                            </tr>
                                        <?php endwhile; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>

                        <?php endwhile; ?>

                    </div>
                </div>
            </div>

        </div>
    </section>
</div>

<!-- MODAL -->
<div class="modal fade" id="modalSolicitante">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header bg-primary">
        <h5 class="modal-title text-white">¿Para quién es el pedido?</h5>
        <button type="button" class="close text-white" data-dismiss="modal">&times;</button>
      </div>
      <div class="modal-body">
        <input type="text" id="nombreSolicitante" class="form-control form-control-lg"
               placeholder="Ej: Juan Pérez">
      </div>
      <div class="modal-footer">
        <button class="btn btn-success" onclick="confirmarGuardado()">Confirmar Pedido</button>
      </div>
    </div>
  </div>
</div>

<div class="modal fade" id="modalHistorial">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <div class="modal-header bg-dark text-white">
        <h5 class="modal-title">
            <i class="fas fa-history"></i> Historial del pedido
        </h5>
        <button type="button" class="close text-white" data-dismiss="modal">&times;</button>
      </div>
      <div class="modal-body" id="contenidoHistorial">
        <div class="text-center text-muted">Cargando historial...</div>
      </div>
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
let pedidosTemp = [];

$(document).ready(function(){

    // Activar Select2
    $('#filtroSolicitante').select2({ width:'100%' });
    $('#filtroOrden').select2({ width:'100%' });

    $('#tipoReporte').on('change', function(){
        const tipo = $(this).val();

        // Siempre ocultar y limpiar
        $('#divSolicitante').hide();
        $('#divOrden').hide();
        $('#filtroSolicitante').val('').trigger('change');
        $('#filtroOrden').val('').trigger('change');

        if(tipo === 'solicitante'){
            $('#divSolicitante').show();
        }

        if(tipo === 'orden'){
            $('#divOrden').show();
        }
    });

});

function calcular(input){
    const tr = input.closest('tr');
    const stock = parseInt(tr.dataset.stock);
    const pedido = parseInt(input.value) || 0;

    let nuevoStock = stock - pedido;
    if(nuevoStock < 0) nuevoStock = 0;
    tr.querySelector('.stock').innerText = nuevoStock;

    let faltante = pedido - stock;
    if(faltante < 0) faltante = 0;
    tr.querySelector('.faltante').innerText = faltante;

    const estado = tr.querySelector('.estado');
    tr.classList.remove('table-warning','table-danger','table-success');

    if(pedido === 0){
        estado.innerHTML = '<span class="badge badge-secondary">Sin pedido</span>';
    } else if(faltante === 0){
        estado.innerHTML = '<span class="badge badge-success">Stock suficiente</span>';
        tr.classList.add('table-success');
    } else if(stock === 0){
        estado.innerHTML = '<span class="badge badge-danger">Sin stock</span>';
        tr.classList.add('table-danger');
    } else {
        estado.innerHTML = '<span class="badge badge-warning">Faltante parcial</span>';
        tr.classList.add('table-warning');
    }
}

function abrirModalSolicitante(){
    pedidosTemp = [];
    document.querySelectorAll('#tablaPedidos tbody tr').forEach(f => {
        const pedido = parseInt(f.querySelector('.pedir').value) || 0;
        if(pedido > 0){
            pedidosTemp.push({
                id: f.dataset.id,
                nombre: f.dataset.nombre,
                stock: f.dataset.stock,
                pedido: pedido,
                faltante: f.querySelector('.faltante').innerText
            });
        }
    });

    if(pedidosTemp.length === 0){
        Swal.fire('Sin pedidos','No has solicitado ningún producto','info');
        return;
    }

    $('#modalSolicitante').modal('show');
}

function confirmarGuardado(){
    const nombre = $('#nombreSolicitante').val().trim();
    if(nombre === ''){
        Swal.fire('Error','Escribe para quién es el pedido','error');
        return;
    }

    fetch('guardar_pedido.php',{
        method:'POST',
        headers:{'Content-Type':'application/json'},
        body: JSON.stringify({ solicitado_por:nombre, pedidos:pedidosTemp })
    }).then(()=> {
        $('#modalSolicitante').modal('hide');
        Swal.fire('Correcto','Pedido guardado','success').then(()=>location.reload());
    });
}

function construirURL(base){
    const tipo = $('#tipoReporte').val();
    if(tipo === 'solicitante'){
        const s = $('#filtroSolicitante').val();
        return base + '?solicitado_por=' + encodeURIComponent(s);
    }
    if(tipo === 'orden'){
        const o = $('#filtroOrden').val();
        return base + '?id_orden=' + o;
    }
    return base;
}

function exportarExcel(){
    const url = construirURL('exportar_excel_pedidos.php');

    fetch(url)
    .then(res => res.json())
    .then(data => {
        if(data.sin_pedidos){
            Swal.fire({
                icon: 'info',
                text: data.mensaje,
                confirmButtonText: 'Entendido'
            });
        }else{
            window.open(url, '_blank');
        }
    })
    .catch(() => {
        window.open(url, '_blank');
    });
}

function exportarPDF(){
    const url = construirURL('exportar_pdf_pedidos.php');

    fetch(url)
    .then(res => res.json())
    .then(data => {
        if(data.sin_pedidos){
            Swal.fire({
                icon: 'info',
                text: data.mensaje,
                confirmButtonText: 'Entendido'
            });
        }else{
            window.open(url, '_blank');
        }
    })
    .catch(() => {
        window.open(url, '_blank');
    });
}


$(document).ready(function(){

    if(localStorage.getItem('ocultarAyudaReporte') !== 'true'){
        $('#ayudaReporte').show();
    }

});

function cerrarAyuda(){
    if($('#noMostrarAyuda').is(':checked')){
        localStorage.setItem('ocultarAyudaReporte', 'true');
    }
    $('#ayudaReporte').fadeOut();
}

function mostrarAyuda(){
    $('#ayudaReporte').fadeIn();
}

function completarPedido(folio){
    Swal.fire({
        title: '¿Completar TODO el pedido?',
        text: 'Todos los productos del folio se marcarán como completados',
        icon: 'question',
        showCancelButton: true,
        confirmButtonText: 'Sí, completar',
        cancelButtonText: 'Cancelar'
    }).then(r=>{
        if(r.isConfirmed){
            fetch('completar_pedido.php',{
                method:'POST',
                headers:{'Content-Type':'application/json'},
                body: JSON.stringify({folio:folio})
            }).then(()=>location.reload());
        }
    });
}

function completarProducto(id){
    Swal.fire({
        title: '¿Completar este producto?',
        icon: 'question',
        showCancelButton: true,
        confirmButtonText: 'Sí',
        cancelButtonText: 'Cancelar'
    }).then(r=>{
        if(r.isConfirmed){
            fetch('completar_producto.php',{
                method:'POST',
                headers:{'Content-Type':'application/json'},
                body: JSON.stringify({id:id})
            }).then(()=>location.reload());
        }
    });
}

document.addEventListener('click', function(e){

    // buscamos si el click fue en algo que abre el collapse
    const header = e.target.closest('[data-toggle="collapse"]');
    if(!header) return;

    // buscamos la flecha más cercana aunque esté fuera
    const flecha = header.parentElement.querySelector('.flecha');
    if(!flecha) return;

    setTimeout(()=>{
        flecha.classList.toggle('abierta');
    },150);

});

document.getElementById('buscadorPedidos').addEventListener('input', function(){
    const texto = this.value.toLowerCase();

    document.querySelectorAll('.pedido-card').forEach(card=>{
        const contenido = card.dataset.search;

        if(contenido.includes(texto)){
            card.style.display = '';
        }else{
            card.style.display = 'none';
        }
    });
});

const filasProductos = document.querySelectorAll('#tablaPedidos tbody tr');
const contador = document.getElementById('contadorProductos');

function actualizarContador(){
    let visibles = 0;
    filasProductos.forEach(f => {
        if(f.style.display !== 'none') visibles++;
    });
    contador.innerText = visibles;
}

document.getElementById('buscadorProductos').addEventListener('keyup', function(){
    const texto = this.value.toLowerCase();

    filasProductos.forEach(fila => {
        const nombre = fila.dataset.nombre.toLowerCase();

        if(nombre.includes(texto)){
            fila.style.display = '';
        }else{
            fila.style.display = 'none';
        }
    });

    actualizarContador();
});

actualizarContador();

$('.filtro-estado').click(function(){
    $('.filtro-estado').removeClass('active');
    $(this).addClass('active');

    const estado = $(this).data('estado');
    const titulo = document.getElementById('tituloPedidos');

    // 🔹 Cambiar título dinámico
    if(estado === 'pendiente'){
        titulo.innerHTML = '<i class="fas fa-clipboard-list"></i> Pedidos pendientes por completar';
    }
    if(estado === 'completado'){
        titulo.innerHTML = '<i class="fas fa-check-circle text-success"></i> Pedidos completados';
    }
    if(estado === 'todos'){
        titulo.innerHTML = '<i class="fas fa-list"></i> Todos los pedidos';
    }

    // 🔹 Filtrar tarjetas
    $('.pedido-card').each(function(){
        const e = $(this).data('estado');

        if(estado === 'todos' || estado === e){
            $(this).show();
        }else{
            $(this).hide();
        }
    }); 
});
//  forzamos filtro al cargar la página para evitar que se mezclen pendientes y completados
$(document).ready(function(){
    $('.filtro-estado.active').click();
});

function verHistorial(idOrden){
    $('#modalHistorial').modal('show');

    fetch('ver_historial_pedido.php?id_orden=' + idOrden)
    .then(res => res.json())
    .then(data => {

        const cont = document.getElementById('contenidoHistorial');

        if(!data || data.length === 0){
            cont.innerHTML = `
                <div class="alert alert-info text-center">
                    <i class="fas fa-info-circle"></i>
                    Este pedido no tiene historial registrado.
                </div>`;
            return;
        }

        let html = `
            <ul class="timeline list-unstyled">
        `;

        data.forEach(l => {

            let icono = 'fa-file-alt';
            let color = 'secondary';

            if (l.accion.includes('CREADO')) {
                icono = 'fa-plus-circle';
                color = 'primary';
            } else if (l.accion.includes('AGREGADO')) {
                icono = 'fa-box';
                color = 'info';
            } else if (l.accion.includes('STOCK')) {
                icono = 'fa-warehouse';
                color = 'warning';
            } else if (l.accion.includes('completado')) {
                icono = 'fa-check-circle';
                color = 'success';
            } else if (l.accion.includes('cancelado')) {
                icono = 'fa-times-circle';
                color = 'danger';
            }

            html += `
                <li class="timeline-item mb-4">
                    <div class="d-flex align-items-start">
                        <div class="timeline-icon bg-${color}">
                            <i class="fas ${icono}"></i>
                        </div>
                        <div class="timeline-content ml-3">
                            <div class="card shadow-sm">
                                <div class="card-body p-2">
                                    <strong>${l.accion}</strong>
                                    <div class="small text-muted mb-1">
                                        ${l.fecha} · ${l.usuario}
                                    </div>
                                    <div>${l.descripcion}</div>
                                </div>
                            </div>
                        </div>
                    </div>
                </li>
            `;
        });

        html += '</ul>';

        cont.innerHTML = html;
    });
}


let ultimaCantidadCriticos = -1;

toastr.options = {
    closeButton: true,
    progressBar: true,
    positionClass: "toast-top-right",
    timeOut: 4000,
    extendedTimeOut: 2000,
    showMethod: "fadeIn",
    hideMethod: "fadeOut"
};

function verificarStockCritico(){

    fetch('ajax_stock_critico.php')
    .then(res => res.json())
    .then(data => {

        if(data.error) return;

        if(data.length > 0){

            if(data.length !== ultimaCantidadCriticos){

                let mensaje = '';

                data.forEach(p => {
                    mensaje += `<strong>${p.nombre}</strong> — Stock: ${p.cantidad}<br>`;
                });

                toastr.error(
                    mensaje,
                    "⚠ Stock crítico detectado"
                );

            }
        }

        ultimaCantidadCriticos = data.length;

    })
    .catch(err => {
        console.log("Error stock crítico:", err);
    });
}

$(document).ready(function(){
    verificarStockCritico();
    setInterval(verificarStockCritico, 30000);
});

</script>
