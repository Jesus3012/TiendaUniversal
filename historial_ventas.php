<?php
include 'includes/session.php';
include 'includes/db.php';
include 'includes/header.php';
include 'includes/navbar.php';

if ($_SESSION['rol'] !== 'vendedor' && $_SESSION['rol'] !== 'administrador') {
    header("Location: login.php");
    exit;
}
?>

<style>
/* ================================
   DATA TABLES - ICONOS ORDEN
   Compatible con AdminLTE + scroll
   ================================ */

/* Base para encabezados */
table.dataTable thead th {
    position: relative;
    padding-right: 30px !important;
    vertical-align: middle;
}

/* Icono neutro (columna ordenable) */
.dataTables_scrollHead thead th.sorting::after {
    content: "\f0dc"; /* fa-sort */
    font-family: "Font Awesome 6 Free";
    font-weight: 900;
    position: absolute;
    right: 8px;
    top: 50%;
    transform: translateY(-50%);
    color: #adb5bd;
    font-size: 0.8rem;
    opacity: 0.9;
}

/* Ascendente */
.dataTables_scrollHead thead th.sorting_asc::after {
    content: "\f0de"; /* fa-sort-up */
    color: #0d6efd;
    opacity: 1;
}

/* Descendente */
.dataTables_scrollHead thead th.sorting_desc::after {
    content: "\f0dd"; /* fa-sort-down */
    color: #0d6efd;
    opacity: 1;
}

/* ================================
   🚫 OCULTAR ICONOS CLONADOS
   (Scroll interno DataTables)
   ================================ */
.dataTables_scrollBody thead th::after,
.dataTables_scrollBody thead th::before {
    content: none !important;
    display: none !important;
}

/* ================================
   Quitar iconos en columnas NO ordenables
   ================================ */
table.dataTable thead th.no-sort::after {
    display: none !important;
}

/* ================================
   Mejorar hover visual
   ================================ */
.dataTables_scrollHead thead th:hover::after {
    color: #0a58ca;
    opacity: 1;
}

/* ================================
   Evitar duplicados por DataTables
   ================================ */
table.dataTable thead th::before {
    display: none !important;
}

/* ================================
   Estilos para filas expandibles
   ================================ */
tr.details {
    background-color: #f8f9fa;
}

.details-content {
    padding: 15px;
}

.details-table {
    width: 100%;
    border-collapse: collapse;
    margin-top: 10px;
}

.details-table th {
    background-color: #e9ecef;
    padding: 8px;
    text-align: left;
    font-size: 0.9rem;
}

.details-table td {
    padding: 8px;
    border-bottom: 1px solid #dee2e6;
    font-size: 0.9rem;
}

.details-table tr:last-child td {
    border-bottom: none;
}

.details-table .text-right {
    text-align: right;
}

.details-table .text-center {
    text-align: center;
}

.expand-icon {
    cursor: pointer;
    color: #0d6efd;
    font-size: 1.1rem;
    transition: transform 0.2s;
}

.expand-icon:hover {
    transform: scale(1.1);
}

.expand-icon.expanded {
    transform: rotate(90deg);
}

/* ================================
   Estilos para badges de estado
   ================================ */
.badge-pedido {
    font-size: 0.8rem;
    padding: 3px 8px;
    border-radius: 4px;
}

.badge-pendiente {
    background-color: #ffc107;
    color: #000;
}

.badge-completado {
    background-color: #28a745;
    color: #fff;
}

/* ================================
   Estilos para subtotales
   ================================ */
.subtotal-row {
    background-color: #f1f3f4;
    font-weight: bold;
}

.subtotal-row td {
    border-top: 2px solid #dee2e6;
}
</style>

<!-- Content Wrapper. Contains page content -->
<div class="content-wrapper">
    <!-- Content Header (Page header) -->
    <section class="content-header">
        <div class="container-fluid">
            <div class="row mb-2">
            <div class="col-sm-6">
                <h1>Historial de Ventas / Cancelación</h1>
            </div>
            </div>
        </div><!-- /.container-fluid -->
    </section>
        <!-- Main content -->
        <section>
            <div class="container-fluid">

                <!-- Filtros -->
                <div class="card card-primary card-outline">
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-3 mb-2">
                                <input type="text" id="buscar_producto" class="form-control" placeholder="Buscar producto...">
                            </div>
                            <div class="col-md-3 mb-2">
                                <input type="email" id="buscar_cliente" class="form-control" placeholder="Buscar por cliente...">
                            </div>
                            <div class="col-md-2 mb-2">
                                <input type="date" id="fecha_inicio" class="form-control">
                            </div>
                            <div class="col-md-2 mb-2">
                                <input type="date" id="fecha_fin" class="form-control">
                            </div>
                            <div class="col-md-2 mb-2" style="display: flex; gap: 10px;">
                                <button id="btnBuscar" class="btn btn-warning flex-fill">Buscar</button>
                                <button id="btnLimpiar" class="btn btn-secondary flex-fill">Limpiar</button>
                            </div>
                        </div>
                        <div class="row mt-2">
                            <div class="col-md-12" style="display: flex; gap: 10px;">
                                <button class="btn btn-success" id="export_excel"><i class="fa fa-file-excel"></i> Exportar Excel</button>
                                <button class="btn btn-primary" id="export_pdf"><i class="fas fa-file-pdf"></i> Exportar PDF</button>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Tabla Ventas -->
                <div class="card card-outline card-info">
                    <div class="card-header">
                        <h3 class="card-title">Ventas registradas</h3>
                    </div>
                    <div class="card-body table-responsive p-0">
                        <table id="tablaVentas" class="table table-striped table-hover table-bordered table-sm w-100">
                            <thead class="table-primary">
                                <tr>
                                    <th style="width: 30px;"></th> <!-- Columna para expandir -->
                                    <th>Folio</th>
                                    <th>Total</th>
                                    <th>Cliente</th>
                                    <th>Fecha</th>
                                    <th>Estado</th>
                                    <th>Acciones</th>
                                </tr>
                            </thead>
                            <tbody></tbody>
                        </table>
                    </div>
                </div>
            </div>   
        </section>
    <!-- /.content -->
</div>
<!-- /.content-wrapper -->

<!-- Hidden template for inline use (no visible) -->
<div id="tplDevolucion" style="display:none;">
    <div style="padding:20px; max-width:440px;">
        <h5>Devolución parcial</h5>
        <input type="hidden" id="dv_folio">
        <label>Producto</label>
        <select id="dv_producto" class="form-control mb-2"></select>
        <label>Cantidad a devolver</label>
        <input type="number" id="dv_cantidad" min="1" class="form-control mb-2">
        <label>Motivo</label>
        <input type="text" id="dv_motivo" class="form-control mb-3">
        <div class="d-flex justify-content-end gap-2">
            <button id="dv_cancel" class="btn btn-secondary">Cancelar</button>
            <button id="dv_confirm" class="btn btn-danger">Confirmar</button>
        </div>
    </div>
</div>

<div id="tplCancelarArticulo" style="display:none;">
    <div style="padding:20px; max-width:440px;">
        <h5>Cancelar artículo</h5>
        <input type="hidden" id="ca_folio">
        <label>Producto</label>
        <select id="ca_producto" class="form-control mb-2"></select>
        <label>Motivo</label>
        <input type="text" id="ca_motivo" class="form-control mb-3">
        <div class="d-flex justify-content-end gap-2">
            <button id="ca_cancel" class="btn btn-secondary">Cancelar</button>
            <button id="ca_confirm" class="btn btn-danger">Confirmar</button>
        </div>
    </div>
</div>

<!-- Scripts -->
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

<script>
let tabla;
let detallesCargados = {}; // Cache para detalles expandidos

$(document).ready(function() {
    cargarVentas();

    $('#btnBuscar').click(() => cargarVentas());
    $('#btnLimpiar').click(() => {
        $('#buscar_producto,#buscar_cliente,#fecha_inicio,#fecha_fin').val('');
        cargarVentas();
    });

    $('#export_excel').click(() => {
        const inicio = $('#fecha_inicio').val();
        const fin = $('#fecha_fin').val();

        if (!inicio || !fin) {
            Swal.fire({
                icon: 'warning',
                title: 'Fechas faltantes',
                text: 'Selecciona ambas fechas para exportar.',
                confirmButtonColor: '#3085d6'
            });
            return;
        }

        window.location = `exportar_excel.php?inicio=${inicio}&fin=${fin}`;
    });

    $('#export_pdf').click(() => exportar('pdf'));
});

function cargarVentas() {
    const producto = $('#buscar_producto').val();
    const cliente = $('#buscar_cliente').val();
    const inicio = $('#fecha_inicio').val();
    const fin = $('#fecha_fin').val();

    if (tabla) {
        tabla.destroy();
        detallesCargados = {};
    }

    tabla = $('#tablaVentas').DataTable({
        ajax: {
            url: 'api/obtener_ventas.php',
            data: {
                producto: producto,
                cliente: cliente,
                inicio: inicio,
                fin: fin
            },
            dataSrc: 'data'
        },

        order: [[4, 'desc']], // FECHA más reciente primero

        columns: [
            {
                data: null,
                orderable: false,
                width: '30px',
                render: function() {
                    return '<i class="fas fa-chevron-right expand-icon"></i>';
                }
            },
            { data: 'folio_ticket', title: 'Folio' },
            { 
                data: 'total_general', 
                title: 'Total', 
                render: function(data) { 
                    return data ? `$${parseFloat(data).toFixed(2)}` : '—'; 
                } 
            },
            { data: 'correo_cliente', title: 'Cliente' },
            { 
                data: 'fecha_venta',
                title: 'Fecha',
                render: function(data, type, row) {
                    if (type === 'sort' || type === 'type') {
                        return row.fecha_raw;
                    }
                    return data;
                }
            },
            {
                data: null,
                title: 'Estado',
                render: function(row) {
                    const esPedido = row.folio_ticket ? row.folio_ticket.startsWith('PEDIDO-') : false;
                    
                    if (esPedido) {
                        const estado = row.estado_pedido || 'pendiente';
                        const badgeClass = estado === 'completado' ? 'badge-completado' : 'badge-pendiente';
                        const texto = estado === 'completado' ? 'Completado' : 'Pendiente';
                        return `<span class="badge-pedido ${badgeClass}">${texto}</span>`;
                    }
                    
                    return '<span class="badge-pedido badge-completado">Venta directa</span>';
                }
            },
            {
                data: null,
                title: 'Acciones',
                orderable: false,
                render: function(row) {
                    const folio = row.folio_ticket ? String(row.folio_ticket) : '';
                    const esPedido = folio.startsWith('PEDIDO-');
                    const pedidoCompletado = row.estado_pedido === 'completado';
                    const ticketLink = row.ticket_pdf
                        ? `<a href="tickets/${row.ticket_pdf}" target="_blank" class="text-success" title="Ver PDF"><i class="fas fa-file-pdf"></i></a>`
                        : `<span class="text-muted" title="Sin ticket"><i class="fas fa-file-pdf"></i></span>`;

                    return `
                        ${ticketLink}
                        
                        | <a href="#" class="text-primary reenvio-ticket"
                            data-folio="${encodeURIComponent(folio)}"
                            title="Reenviar ticket">
                            <i class="fas fa-paper-plane"></i>
                        </a>

                        | ${
                            esPedido && pedidoCompletado
                            ? `<span class="text-muted" title="Pedido completado">
                                    <i class="fas fa-times"></i>
                            </span>`
                            : `<a href="#" class="text-warning cancelar-articulo"
                                    data-folio="${encodeURIComponent(folio)}"
                                    title="Cancelar artículo">
                                    <i class="fas fa-times"></i>
                            </a>`
                        }
                        
                        | ${
                            esPedido && pedidoCompletado
                            ? `<span class="text-muted" title="Pedido completado">
                                    <i class="fa-solid fa-arrow-rotate-left"></i>
                            </span>`
                            : `<a href="#" class="text-info devolucion-parcial"
                                    data-folio="${encodeURIComponent(folio)}"
                                    title="Devolución parcial">
                                    <i class="fa-solid fa-arrow-rotate-left" style="color:#7a68b1;"></i>
                            </a>`
                        }
                        
                        | <a href="#" class="text-danger cancelar-venta"
                            data-folio="${encodeURIComponent(folio)}"
                            title="Cancelar venta">
                            <i class="fas fa-ban"></i>
                        </a>
                    `;
                }
            }
        ],

        language: {
            url: "https://cdn.datatables.net/plug-ins/1.13.6/i18n/es-ES.json",
            info: "Mostrando _START_ a _END_ de _TOTAL_ registros",
            paginate: {
                previous: "<i class='fas fa-angle-left'></i> Anterior",
                next: "Siguiente <i class='fas fa-angle-right'></i>"
            }
        },
        dom:
            "<'row'<'col-sm-12'tr>>" +
            "<'row mt-2'<'col-sm-12 col-md-6'i><'col-sm-12 col-md-6 text-right'p>>",
        responsive: true,
        scrollX: true,
        pageLength: 10,
        lengthChange: false,
        autoWidth: false,
        pagingType: "simple_numbers",
        drawCallback: function() {
            $('.dataTables_paginate a').addClass('btn btn-outline-primary btn-sm mx-1');
            bindRowActions();
            bindExpandEvents();
        }
    });

    // Evento para cuando se hace clic en el icono de expandir
    $('#tablaVentas tbody').on('click', 'td:first-child .expand-icon', function() {
        const tr = $(this).closest('tr');
        const row = tabla.row(tr);
        const icon = $(this);
        
        if (row.child.isShown()) {
            // Ocultar detalles
            row.child.hide();
            icon.removeClass('expanded');
        } else {
            // Mostrar detalles
            icon.addClass('expanded');
            
            // Obtener datos de la fila
            const rowData = row.data();
            
            // Verificar si ya tenemos los detalles cargados
            if (detallesCargados[rowData.folio_ticket]) {
                row.child(detallesCargados[rowData.folio_ticket]).show();
            } else {
                // Cargar detalles del servidor
                cargarDetallesVenta(rowData.folio_ticket, row);
            }
        }
    });
}

function bindRowActions(){
    // Reenvío de ticket
    $('#tablaVentas').off('click', '.reenvio-ticket').on('click', '.reenvio-ticket', function(e){
        e.preventDefault();
        const folio = decodeURIComponent($(this).data('folio'));
        reenviarTicket(folio);
    });

    // Cancelar artículo
    $('#tablaVentas').off('click', '.cancelar-articulo').on('click', '.cancelar-articulo', function(e){
        e.preventDefault();
        const folio = decodeURIComponent($(this).data('folio'));
        cancelarArticuloModal(folio);
    });

    // Devolución parcial
    $('#tablaVentas').off('click', '.devolucion-parcial').on('click', '.devolucion-parcial', function(e){
        e.preventDefault();
        const folio = decodeURIComponent($(this).data('folio'));
        abrirDevolucionModal(folio);
    });

    // Cancelar venta completa
    $('#tablaVentas').off('click', '.cancelar-venta').on('click', '.cancelar-venta', function(e){
        e.preventDefault();
        const folio = decodeURIComponent($(this).data('folio'));
        cancelarVenta(folio);
    });
}

function bindExpandEvents() {
    // Reinicializar iconos expandidos si es necesario
    $('.expand-icon').removeClass('expanded');
}

function cargarDetallesVenta(folio, row) {
    // Mostrar loading
    row.child('<div class="text-center p-3"><i class="fas fa-spinner fa-spin"></i> Cargando detalles...</div>').show();
    
    $.ajax({
        url: 'api/obtener_detalles_venta.php',
        method: 'GET',
        data: { folio: folio },
        success: function(response) {
            if (response.success && response.data && response.data.length > 0) {
                const detallesHtml = generarTablaDetalles(response.data, response.subtotal, response.iva, response.total);
                detallesCargados[folio] = detallesHtml;
                row.child(detallesHtml).show();
            } else {
                row.child('<div class="alert alert-warning m-3">No hay detalles disponibles para esta venta</div>').show();
            }
        },
        error: function() {
            row.child('<div class="alert alert-danger m-3">Error al cargar los detalles</div>').show();
        }
    });
}

function generarTablaDetalles(items, subtotal, iva, total) {
    let html = `
        <div class="details-content">
            <table class="details-table">
                <thead>
                    <tr>
                        <th>Producto</th>
                        <th class="text-center">Cantidad</th>
                        <th class="text-right">Precio Unit.</th>
                        <th class="text-right">Subtotal</th>
                    </tr>
                </thead>
                <tbody>
    `;
    
    items.forEach(item => {
        const precio = parseFloat(item.precio_unitario || item.precio || 0);
        const cantidad = parseInt(item.cantidad);
        const subtotalItem = precio * cantidad;
        
        html += `
            <tr>
                <td>${item.producto}</td>
                <td class="text-center">${cantidad}</td>
                <td class="text-right">$${precio.toFixed(2)}</td>
                <td class="text-right">$${subtotalItem.toFixed(2)}</td>
            </tr>
        `;
    });
    
    // Agregar total general
    html += `
                </tbody>
                <tfoot>
                    <tr class="subtotal-row">
                        <td colspan="3" class="text-right"><strong>Total:</strong></td>
                        <td class="text-right"><strong>$${parseFloat(total).toFixed(2)}</strong></td>
                    </tr>
                </tfoot>
            </table>
        </div>
    `;
    
    return html;
}

function getRowDataByFolio(folio) {
    const all = tabla.rows().data().toArray();
    for (let i=0;i<all.length;i++){
        if (String(all[i].folio_ticket) === String(folio)) return all[i];
    }
    return null;
}

/* Funciones existentes se mantienen igual */
async function reenviarTicket(folio) {
    try {
        Swal.fire({
            title: 'Reenviando ticket...',
            didOpen: () => Swal.showLoading(),
            allowOutsideClick: false,
            showConfirmButton: false
        });

        const res = await fetch(`enviar_ticket.php?folio=${encodeURIComponent(folio)}`);
        const data = await res.json().catch(()=>({success:false, message:'Respuesta no válida'}));

        Swal.close();
        Swal.fire({ icon: data.success ? 'success' : 'error', title: data.message || (data.success ? 'Ticket reenviado' : 'Error'), timer: 2500, showConfirmButton:false });
    } catch (err) {
        Swal.close();
        Swal.fire({ icon: 'error', title: 'Error al reenviar', text: err.message || err, timer:3000, showConfirmButton:false });
    }
}

function cancelarArticuloModal(folio) {
    const row = getRowDataByFolio(folio);
    if (!row) return Swal.fire({icon:'error', title:'Folio no encontrado'});

    // Cargar detalles actualizados del folio
    $.ajax({
        url: 'api/obtener_detalles_venta.php',
        method: 'GET',
        data: { folio: folio },
        success: function(response) {
            if (!response.success || !response.data) {
                return Swal.fire({icon:'error', title:'Error al cargar productos'});
            }
            
            const items = response.data.filter(item => !item.cancelado); // Solo mostrar no cancelados
            
            if (!items.length) {
                return Swal.fire({icon:'info', title:'No hay artículos disponibles para cancelar'});
            }

            const tpl = $($('#tplCancelarArticulo').html());
            tpl.find('#ca_folio').val(folio);
            const select = tpl.find('#ca_producto').empty();
            
            items.forEach((it, idx) => {
                const nombre = it.producto || ('Artículo ' + (idx+1));
                const precio = parseFloat(it.precio_unitario || it.precio || 0).toFixed(2);
                const display = `${nombre} - $${precio} (x${it.cantidad})`;
                select.append(`<option value="${it.id_producto}" data-idx="${idx}">${$('<div>').text(display).html()}</option>`);
            });

            Swal.fire({
                html: tpl.prop('outerHTML'),
                showConfirmButton: false,
                showCloseButton: true,
                didOpen: () => {
                    const modal = Swal.getHtmlContainer();
                    $(modal).find('#ca_cancel').on('click', () => Swal.close());
                    $(modal).find('#ca_confirm').on('click', async () => {
                        const id_producto = $(modal).find('#ca_producto').val();
                        const motivo = $(modal).find('#ca_motivo').val();
                        const productoItem = items.find(it => it.id_producto == id_producto);

                        if(!productoItem) return Swal.fire({icon:'error', title:'Producto inválido'});

                        try {
                            Swal.fire({title:'Procesando...', didOpen:()=>Swal.showLoading(), showConfirmButton:false, allowOutsideClick:false});
                            const res = await fetch('api/cancelar_articulo.php', {
                                method: 'POST',
                                headers: {'Content-Type':'application/json'},
                                body: JSON.stringify({ 
                                    folio: folio, 
                                    id_producto: id_producto,
                                    cantidad: productoItem.cantidad,
                                    motivo: motivo 
                                })
                            });
                            const data = await res.json().catch(()=>({success:false, message:'Respuesta no válida'}));
                            Swal.close();
                            Swal.fire({ icon: data.success ? 'success' : 'error', title: data.message || (data.success?'Artículo cancelado':'Error'), timer:2000, showConfirmButton:false });
                            
                            // Recargar tabla y limpiar cache de detalles
                            detallesCargados = {};
                            tabla.ajax.reload(null, false);
                        } catch (err) {
                            Swal.close();
                            Swal.fire({ icon:'error', title:'Error', text: err.message || err, timer:2500, showConfirmButton:false });
                        }
                    });
                }
            });
        },
        error: function() {
            Swal.fire({icon:'error', title:'Error al cargar productos'});
        }
    });
}

function abrirDevolucionModal(folio) {
    const row = getRowDataByFolio(folio);
    if (!row) return Swal.fire({icon:'error', title:'Folio no encontrado'});

    // Cargar detalles actualizados
    $.ajax({
        url: 'api/obtener_detalles_venta.php',
        method: 'GET',
        data: { folio: folio },
        success: function(response) {
            if (!response.success || !response.data) {
                return Swal.fire({icon:'error', title:'Error al cargar productos'});
            }
            
            const items = response.data.filter(item => !item.cancelado && item.cantidad > (item.devueltos || 0));

            if (!items.length) {
                return Swal.fire({icon:'info', title:'No hay artículos disponibles para devolver'});
            }

            const tpl = $($('#tplDevolucion').html());
            tpl.find('#dv_folio').val(folio);
            const select = tpl.find('#dv_producto').empty();

            items.forEach((it) => {
                const idp = it.id_producto;  
                const disponible = it.cantidad - (it.devueltos || 0);     
                const nombre = it.producto;
                const precio = parseFloat(it.precio_unitario || it.precio || 0).toFixed(2);

                select.append(`
                    <option value="${idp}" data-max="${disponible}" data-precio="${precio}">
                        ${nombre} - $${precio} (Disponible: ${disponible})
                    </option>
                `);
            });

            Swal.fire({
                html: tpl.prop('outerHTML'),
                showConfirmButton: false,
                showCloseButton: true,
                didOpen: () => {
                    const modal = Swal.getHtmlContainer();

                    $(modal).find('#dv_cancel').on('click', () => Swal.close());

                    $(modal).find('#dv_confirm').on('click', async () => {
                        const id_producto = $(modal).find('#dv_producto').val();
                        const max = Number($(modal).find('#dv_producto option:selected').data('max'));
                        const cantidad = Number($(modal).find('#dv_cantidad').val());
                        const motivo = $(modal).find('#dv_motivo').val() || '';

                        if (!id_producto) {
                            return Swal.fire({icon:'error', title:'Producto inválido'});
                        }

                        if (!cantidad || cantidad <= 0 || cantidad > max) {
                            return Swal.fire({
                                icon:'warning',
                                title:`Cantidad inválida`,
                                text:`Máximo permitido: ${max}`
                            });
                        }

                        try {
                            Swal.fire({
                                title:'Procesando...',
                                didOpen:()=>Swal.showLoading(),
                                showConfirmButton:false,
                                allowOutsideClick:false
                            });

                            const res = await fetch('api/devolver_parcial.php', {
                                method: 'POST',
                                headers: {'Content-Type':'application/json'},
                                body: JSON.stringify({
                                    folio: folio,
                                    id_producto: id_producto,
                                    cantidad: cantidad,
                                    motivo: motivo
                                })
                            });

                            const data = await res.json().catch(()=>({success:false, message:'Respuesta no válida'}));

                            Swal.close();
                            Swal.fire({
                                icon: data.success ? 'success' : 'error',
                                title: data.message || (data.success ? 'Devolución realizada' : 'Error'),
                                timer:2000,
                                showConfirmButton:false
                            });

                            // Recargar tabla y limpiar cache
                            detallesCargados = {};
                            tabla.ajax.reload(null, false);

                        } catch (err) {
                            Swal.close();
                            Swal.fire({
                                icon:'error',
                                title:'Error',
                                text: err.message || err,
                                timer:2500,
                                showConfirmButton:false
                            });
                        }
                    });
                }
            });
        },
        error: function() {
            Swal.fire({icon:'error', title:'Error al cargar productos'});
        }
    });
}

function cancelarVenta(folio) {
    Swal.fire({
        title: '¿Cancelar esta venta completa?',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonText: 'Sí, cancelar venta',
        cancelButtonText: 'No',
        confirmButtonColor: '#d33'
    }).then(async (r) => {
        if (!r.isConfirmed) return;

        try {
            Swal.fire({
                title:'Cancelando venta...',
                didOpen:()=>Swal.showLoading(),
                showConfirmButton:false,
                allowOutsideClick:false
            });

            const res = await fetch('api/cancelar_venta.php', {
                method: 'POST',
                headers: {'Content-Type':'application/json'},
                body: JSON.stringify({ folio: folio })
            });

            const data = await res.json();

            Swal.close();

            if (data.pedido_completado) {
                const confirmacion = await Swal.fire({
                    title: 'Pedido completado',
                    text: data.message,
                    icon: 'warning',
                    showCancelButton: true,
                    confirmButtonText: 'Sí, cancelar de todos modos',
                    cancelButtonText: 'No'
                });

                if (!confirmacion.isConfirmed) return;

                Swal.fire({
                    title:'Cancelando pedido...',
                    didOpen:()=>Swal.showLoading(),
                    showConfirmButton:false,
                    allowOutsideClick:false
                });

                const resForzado = await fetch('api/cancelar_venta.php', {
                    method: 'POST',
                    headers: {'Content-Type':'application/json'},
                    body: JSON.stringify({ 
                        folio: folio,
                        forzar: true
                    })
                });

                const dataFinal = await resForzado.json();
                Swal.close();

                Swal.fire({
                    icon: dataFinal.success ? 'success' : 'error',
                    title: dataFinal.message,
                    timer:2500,
                    showConfirmButton:false
                });

                if (dataFinal.success) {
                    detallesCargados = {};
                    tabla.ajax.reload(null, false);
                }

                return;
            }

            Swal.fire({
                icon: data.success ? 'success' : 'error',
                title: data.message,
                timer:2000,
                showConfirmButton:false
            });

            if (data.success) {
                detallesCargados = {};
                tabla.ajax.reload(null, false);
            }

        } catch (err) {
            Swal.close();
            Swal.fire({
                icon:'error',
                title:'Error',
                text: err.message || err,
                timer:3000,
                showConfirmButton:false
            });
        }
    });
}

function exportar(tipo) {
    const producto = $('#buscar_producto').val();
    const cliente = $('#buscar_cliente').val();
    const inicio = $('#fecha_inicio').val();
    const fin = $('#fecha_fin').val();
    window.location = `api/exportar_ventas.php?format=${tipo}&producto=${encodeURIComponent(producto)}&cliente=${encodeURIComponent(cliente)}&inicio=${inicio}&fin=${fin}`;
}
</script>