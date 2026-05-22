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
   ESTILOS OPTIMIZADOS - TABLA Y BUSCADOR
   ================================ */

/* Para que la tabla se vea bien al 100% zoom */
table.dataTable {
    width: 100% !important;
    font-size: 13px !important;
    font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
}

.dataTables_wrapper {
    overflow-x: auto;
}

table.dataTable thead th,
table.dataTable tbody td {
    padding: 10px 8px !important;
    white-space: normal !important;
    word-break: break-word;
}

table.dataTable tbody td:first-child {
    font-weight: 500;
}

/* Ocultar buscador interno de DataTable */
.dataTables_filter {
    display: none !important;
}

/* Filtros mejorados */
.card-primary.card-outline {
    border-top: 3px solid #f97316;
}

#buscar_global, #fecha_inicio, #fecha_fin {
    border-radius: 8px;
    border: 1px solid #ddd;
    padding: 8px 12px;
    font-size: 14px;
    width: 100%;
}

#buscar_global:focus, #fecha_inicio:focus, #fecha_fin:focus {
    border-color: #f97316;
    outline: none;
    box-shadow: 0 0 0 2px rgba(249,115,22,0.2);
}

.btn-secondary {
    background-color: #6c757d;
    border-color: #6c757d;
    color: white;
    border-radius: 8px;
    padding: 8px 16px;
}

.btn-secondary:hover {
    background-color: #5a6268;
}

/* Badges */
.badge-pedido {
    font-size: 0.75rem;
    padding: 4px 10px;
    border-radius: 20px;
    font-weight: 500;
}

.badge-pendiente {
    background-color: #ffc107;
    color: #000;
}

.badge-completado {
    background-color: #28a745;
    color: #fff;
}

/* Modal Ticket */
.modal-ticket .modal-header {
    background: linear-gradient(135deg, #f97316, #ea580c);
    color: white;
    border: none;
}

.modal-ticket .modal-header .close {
    color: white;
    opacity: 1;
}

.ticket-paper {
    font-family: 'Courier New', monospace;
    background: white;
}

.ticket-header {
    text-align: center;
    border-bottom: 1px dashed #ccc;
    padding-bottom: 10px;
    margin-bottom: 15px;
}

.ticket-info {
    border-bottom: 1px dashed #ccc;
    padding-bottom: 10px;
    margin-bottom: 10px;
}

.ticket-info-row {
    display: flex;
    justify-content: space-between;
    font-size: 12px;
    padding: 3px 0;
}

.ticket-items-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 11px;
}

.ticket-items-table th {
    border-bottom: 1px solid #000;
    text-align: center;
    padding: 5px;
}

.ticket-items-table td {
    padding: 5px;
    text-align: center;
    border-bottom: 1px dotted #ccc;
}

.ticket-total {
    text-align: right;
    font-weight: bold;
    margin-top: 10px;
    padding-top: 10px;
    border-top: 1px dashed #ccc;
}

.ticket-footer {
    text-align: center;
    font-size: 10px;
    margin-top: 15px;
    padding-top: 10px;
    border-top: 1px dashed #ccc;
}

.btn-ver-ticket {
    background: #f97316;
    color: white;
    border: none;
    border-radius: 4px;
    padding: 4px 10px;
    font-size: 11px;
    margin-right: 5px;
    cursor: pointer;
}

.btn-ver-ticket:hover {
    background: #ea580c;
}

/* Paginación */
.dataTables_paginate .paginate_button {
    padding: 6px 12px !important;
    margin: 0 2px !important;
    border-radius: 6px !important;
}

.dataTables_paginate .paginate_button.current {
    background: #f97316 !important;
    color: white !important;
    border-color: #f97316 !important;
}

/* Mensaje sin resultados */
.empty-message {
    text-align: center;
    padding: 50px 20px;
}

.empty-icon {
    font-size: 60px;
    color: #cbd5e1;
    margin-bottom: 15px;
}

.empty-title {
    font-size: 16px;
    font-weight: 500;
    color: #64748b;
    margin: 0 0 5px 0;
}

.empty-text {
    font-size: 13px;
    color: #94a3b8;
    margin: 0;
}
.dataTables_wrapper .bottom {
    display: flex !important;
    justify-content: space-between !important;
    align-items: center !important;
    flex-wrap: wrap !important;
}
.dataTables_info {
    text-align: center !important;
    flex: 1 !important;
}

/* =====================================================
   DISEÑO TARJETA - PAGINACIÓN Y SELECTOR
   ===================================================== */

/* Contenedor inferior */
.dataTables_wrapper .bottom {
    display: flex !important;
    justify-content: space-between !important;
    align-items: center !important;
    flex-wrap: wrap !important;
    gap: 12px !important;
    padding: 16px 20px !important;
    margin-top: 20px !important;
    background: #f8fafc !important;
    border-radius: 16px !important;
}

/* Selector de cantidad */
.dataTables_length label {
    display: flex !important;
    align-items: center !important;
    gap: 8px !important;
    font-size: 0.8rem !important;
    color: #475569 !important;
    margin: 0 !important;
}

.dataTables_length select {
    width: auto !important;
    padding: 6px 24px 6px 10px !important;
    border-radius: 8px !important;
    border: 1px solid #e2e8f0 !important;
    background: white !important;
    font-weight: 500 !important;
    cursor: pointer !important;
}

/* Info de registros */
.dataTables_info {
    font-size: 0.8rem !important;
    color: #475569 !important;
    background: white !important;
    padding: 6px 16px !important;
    border-radius: 20px !important;
    box-shadow: 0 1px 2px rgba(0,0,0,0.03) !important;
    margin: 0 !important;
}

/* Paginación */
.dataTables_paginate {
    display: flex !important;
    gap: 5px !important;
    margin: 0 !important;
}

.dataTables_paginate .paginate_button {
    padding: 6px 12px !important;
    border-radius: 8px !important;
    background: white !important;
    border: 1px solid #e2e8f0 !important;
    color: #475569 !important;
    font-weight: 500 !important;
    transition: all 0.2s !important;
    cursor: pointer !important;
}

.dataTables_paginate .paginate_button.current {
    background: #f97316 !important;
    border-color: #f97316 !important;
    color: white !important;
    box-shadow: 0 2px 5px rgba(249,115,22,0.2) !important;
}

.dataTables_paginate .paginate_button:hover:not(.current) {
    background: #f1f5f9 !important;
    border-color: #cbd5e1 !important;
}

/* Estilos para modales de devolución y cancelación */
.modal-content-custom {
    border-radius: 16px;
    box-shadow: 0 20px 25px -5px rgba(0,0,0,0.1);
}

.modal-header-custom {
    background: linear-gradient(135deg, #f97316, #ea580c);
    color: white;
    border-radius: 16px 16px 0 0;
    padding: 15px 20px;
}

.modal-header-custom .close {
    color: white;
    opacity: 1;
}

.modal-header-custom h5 {
    margin: 0;
    font-weight: 600;
}

.modal-body-custom {
    padding: 20px;
}

.modal-footer-custom {
    border-top: 1px solid #e2e8f0;
    padding: 15px 20px;
}

/* =====================================================
   VERSIÓN ULTRA COMPACTA PARA MÓVIL
   TODO EN UNA SOLA LÍNEA
===================================================== */
@media (max-width: 768px) {
    /* Ocultar thead */
    .dataTable thead {
        display: none;
    }
    
    /* Cada venta como tarjeta compacta */
    .dataTable tbody tr {
        display: block;
        margin-bottom: 8px;
        border-radius: 10px;
        background: white;
        box-shadow: 0 1px 3px rgba(0,0,0,0.08);
        overflow: hidden;
    }
    
    /* Todas las celdas en UNA SOLA LÍNEA */
    .dataTable tbody td {
        display: inline-flex !important;
        align-items: center;
        justify-content: space-between;
        padding: 6px 10px !important;
        font-size: 0.7rem !important;
        border: none !important;
        background: white;
        width: auto !important;
    }
    
    /* Primera celda (Folio) - más ancha */
    .dataTable tbody td:first-child {
        background: linear-gradient(135deg, #fff7ed, #ffffff);
        font-weight: 700;
        font-size: 0.7rem;
        padding: 8px 10px !important;
        display: flex !important;
        width: 100% !important;
        border-bottom: 1px solid #fef3c7 !important;
    }
    
    /* Ocultar data-label en primera celda */
    .dataTable tbody td:first-child:before {
        display: none;
    }
    
    /* Resto de celdas en línea horizontal */
    .dataTable tbody td:not(:first-child) {
        display: inline-flex !important;
        width: auto !important;
        padding: 5px 8px !important;
        gap: 4px;
    }
    
    /* Etiquetas pequeñas */
    .dataTable tbody td:not(:first-child):before {
        content: attr(data-label);
        font-size: 0.55rem;
        font-weight: 600;
        color: #f97316;
        margin-right: 4px;
    }
    
    /* Badge de estado compacto */
    .badge-pedido {
        font-size: 0.55rem !important;
        padding: 2px 6px !important;
        border-radius: 15px !important;
    }
    
    .badge-pedido i {
        font-size: 0.5rem;
        margin-right: 2px;
    }
    
    /* Contenedor de acciones en línea */
    .dataTable tbody td:last-child {
        display: inline-flex !important;
        gap: 5px;
        background: transparent;
        padding: 5px 8px !important;
    }
    
    /* Botones de acción compactos */
    .btn-ver-ticket {
        padding: 2px 6px !important;
        font-size: 0.55rem !important;
        border-radius: 12px !important;
    }
    
    .dataTable tbody td:last-child a {
        font-size: 0.6rem;
        padding: 2px 3px;
    }
    
    .dataTable tbody td:last-child a i {
        font-size: 0.55rem;
    }
    
    /* Separador entre elementos */
    .dataTable tbody td:not(:first-child) + td:not(:first-child) {
        border-left: 1px solid #e9ecef;
    }
}
</style>

<!-- Content Wrapper -->
<div class="content-wrapper">
    <section class="content-header">
    <div class="container-fluid">
        <div class="row mb-2">
            <div class="col-sm-6">
                <h1>
                    <i class="fas fa-hand-holding-usd" style="color: #f97316; margin-right: 10px;"></i>
                    Historial de Ventas
                </h1>
            </div>
        </div>
    </div>
</section>

    <section>
        <div class="container-fluid">

            <!-- Filtros - UN SOLO BUSCADOR GLOBAL -->
            <div class="card card-primary card-outline">
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-4 mb-2">
                            <input type="text" id="buscar_global" class="form-control" placeholder="Buscar producto o cliente...">
                        </div>
                        <div class="col-md-3 mb-2">
                            <input type="date" id="fecha_inicio" class="form-control" placeholder="Fecha inicio">
                        </div>
                        <div class="col-md-3 mb-2">
                            <input type="date" id="fecha_fin" class="form-control" placeholder="Fecha fin">
                        </div>
                        <div class="col-md-2 mb-2">
                            <button id="btnLimpiar" class="btn btn-secondary w-100">Limpiar filtros</button>
                        </div>
                    </div>
                    <div class="row mt-2">
                        <div class="col-md-12" style="display: flex; gap: 10px; justify-content: flex-end;">
                            <button class="btn btn-success" id="export_excel"><i class="fa fa-file-excel"></i> Excel</button>
                            <button class="btn btn-primary" id="export_pdf"><i class="fas fa-file-pdf"></i> PDF</button>
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
                    <table id="tablaVentas" class="table table-striped table-hover table-bordered w-100">
                        <thead class="table-primary">
                            <tr>
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
</div>

<!-- Modal Ticket -->
<div class="modal fade" id="modalTicket" tabindex="-1" role="dialog">
    <div class="modal-dialog modal-sm" role="document" style="max-width: 420px;">
        <div class="modal-content modal-ticket">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-receipt"></i> TICKET DE VENTA</h5>
                <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
            <div class="modal-body" id="modalTicketBody" style="background: white;">
                <!-- Contenido dinámico -->
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-dismiss="modal">Cerrar</button>
                <button type="button" class="btn btn-primary" id="btnImprimirTicket" style="background: #f97316;">Imprimir</button>
            </div>
        </div>
    </div>
</div>

<!-- Modal para Cancelar Artículo -->
<div class="modal fade" id="modalCancelarArticulo" tabindex="-1" role="dialog">
    <div class="modal-dialog" role="document">
        <div class="modal-content modal-content-custom">
            <div class="modal-header modal-header-custom">
                <h5 class="modal-title">
                    <i class="fas fa-times-circle"></i> Cancelar Artículo
                </h5>
                <button type="button" class="close" data-dismiss="modal">
                    <span>&times;</span>
                </button>
            </div>
            <div class="modal-body modal-body-custom">
                <input type="hidden" id="ca_folio">
                <div class="form-group">
                    <label><i class="fas fa-box"></i> Seleccionar producto:</label>
                    <select id="ca_producto" class="form-control">
                        <option value="">Cargando productos...</option>
                    </select>
                </div>
                <div class="form-group">
                    <label><i class="fas fa-comment"></i> Motivo de cancelación:</label>
                    <textarea id="ca_motivo" class="form-control" rows="3" placeholder="Ej: Cliente cambió de opinión, producto defectuoso, etc."></textarea>
                </div>
            </div>
            <div class="modal-footer modal-footer-custom">
                <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancelar</button>
                <button type="button" class="btn btn-danger" id="btnConfirmarCancelarArticulo">
                    <i class="fas fa-check"></i> Confirmar cancelación
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Modal para Devolución Parcial -->
<div class="modal fade" id="modalDevolucion" tabindex="-1" role="dialog">
    <div class="modal-dialog" role="document">
        <div class="modal-content modal-content-custom">
            <div class="modal-header modal-header-custom">
                <h5 class="modal-title">
                    <i class="fas fa-arrow-rotate-left"></i> Devolución Parcial
                </h5>
                <button type="button" class="close" data-dismiss="modal">
                    <span>&times;</span>
                </button>
            </div>
            <div class="modal-body modal-body-custom">
                <input type="hidden" id="dv_folio">
                <div class="form-group">
                    <label><i class="fas fa-box"></i> Seleccionar producto:</label>
                    <select id="dv_producto" class="form-control">
                        <option value="">Cargando productos...</option>
                    </select>
                </div>
                <div class="form-group">
                    <label><i class="fas fa-sort-numeric-down"></i> Cantidad a devolver:</label>
                    <input type="number" id="dv_cantidad" class="form-control" min="1" placeholder="Ej: 2">
                </div>
                <div class="form-group">
                    <label><i class="fas fa-comment"></i> Motivo de devolución:</label>
                    <textarea id="dv_motivo" class="form-control" rows="3" placeholder="Ej: Producto defectuoso, no cumple expectativas, etc."></textarea>
                </div>
            </div>
            <div class="modal-footer modal-footer-custom">
                <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancelar</button>
                <button type="button" class="btn btn-warning" id="btnConfirmarDevolucion">
                    <i class="fas fa-check"></i> Confirmar devolución
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Scripts -->
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@4.6.0/dist/js/bootstrap.bundle.min.js"></script>

<script>
let tabla;
let timeoutBusqueda;
let productosVenta = [];

$(document).ready(function() {
    cargarVentas();
    
    // BUSCADOR GLOBAL EN TIEMPO REAL
    $('#buscar_global').on('keyup', function() {
        clearTimeout(timeoutBusqueda);
        timeoutBusqueda = setTimeout(() => {
            if (tabla) {
                tabla.ajax.reload();
            }
        }, 400);
    });
    
    // Filtro de fechas
    $('#fecha_inicio, #fecha_fin').on('change', function() {
        const inicio = $('#fecha_inicio').val();
        const fin = $('#fecha_fin').val();
        
        if (inicio && fin && new Date(inicio) > new Date(fin)) {
            Swal.fire({
                icon: 'warning',
                title: 'Rango inválido',
                text: 'La fecha inicio no puede ser mayor a la fecha fin',
                confirmButtonColor: '#f97316'
            });
            return;
        }
        if (tabla) {
            tabla.ajax.reload();
        }
    });
    
    // Botón limpiar
    $('#btnLimpiar').on('click', function() {
        $('#buscar_global, #fecha_inicio, #fecha_fin').val('');
        if (tabla) {
            tabla.ajax.reload();
        }
        Swal.fire({
            icon: 'success',
            title: 'Filtros limpiados',
            timer: 1500,
            showConfirmButton: false
        });
    });
    
    // Exportar Excel
    $('#export_excel').click(() => {
        const inicio = $('#fecha_inicio').val();
        const fin = $('#fecha_fin').val();
        const busqueda = $('#buscar_global').val();
        if (!inicio || !fin) {
            Swal.fire({ icon: 'warning', title: 'Fechas requeridas', text: 'Selecciona ambas fechas', confirmButtonColor: '#f97316' });
            return;
        }
        window.location = `exportar_excel.php?inicio=${inicio}&fin=${fin}&busqueda=${encodeURIComponent(busqueda)}`;
    });
    
    // Exportar PDF
    $('#export_pdf').click(() => {
        const inicio = $('#fecha_inicio').val();
        const fin = $('#fecha_fin').val();
        const busqueda = $('#buscar_global').val();
        if (!inicio || !fin) {
            Swal.fire({ icon: 'warning', title: 'Fechas requeridas', text: 'Selecciona ambas fechas', confirmButtonColor: '#f97316' });
            return;
        }
        window.location = `api/exportar_ventas.php?inicio=${inicio}&fin=${fin}&busqueda=${encodeURIComponent(busqueda)}`;
    });
    
    // Eventos de modales
    $('#btnConfirmarCancelarArticulo').on('click', function() {
        const folio = $('#ca_folio').val();
        const producto = $('#ca_producto').val();
        const motivo = $('#ca_motivo').val();
        
        if (!producto) {
            Swal.fire('Error', 'Selecciona un producto', 'error');
            return;
        }
        if (!motivo) {
            Swal.fire('Error', 'Ingresa un motivo', 'error');
            return;
        }
        
        confirmarCancelarArticulo(folio, producto, motivo);
    });
    
    $('#btnConfirmarDevolucion').on('click', function() {
        const folio = $('#dv_folio').val();
        const producto = $('#dv_producto').val();
        const cantidad = $('#dv_cantidad').val();
        const motivo = $('#dv_motivo').val();
        
        if (!producto) {
            Swal.fire('Error', 'Selecciona un producto', 'error');
            return;
        }
        if (!cantidad || cantidad <= 0) {
            Swal.fire('Error', 'Ingresa una cantidad válida', 'error');
            return;
        }
        if (!motivo) {
            Swal.fire('Error', 'Ingresa un motivo', 'error');
            return;
        }
        
        confirmarDevolucion(folio, producto, cantidad, motivo);
    });
});

function cargarVentas() {
    if (tabla) {
        tabla.destroy();
    }

    tabla = $('#tablaVentas').DataTable({
        ajax: {
            url: 'api/obtener_ventas.php',
            type: 'GET',
            data: function(d) {
                return {
                    global: $('#buscar_global').val(),
                    inicio: $('#fecha_inicio').val(),
                    fin: $('#fecha_fin').val()
                };
            },
            dataSrc: 'data',
            error: function(xhr, error, thrown) {
                console.error('Error en AJAX:', error);
                console.error('Respuesta:', xhr.responseText);
            }
        },
        columns: [
            { data: 'folio_ticket', defaultContent: '—' },
            { 
                data: 'total_general', 
                render: function(data) { 
                    return data ? `$${parseFloat(data).toFixed(2)}` : '$0.00'; 
                } 
            },
            { 
                data: 'correo_cliente', 
                render: function(data) {
                    if (!data || data === '' || data === 'null' || data === 'Cliente no registrado') {
                        return 'Venta en general';
                    }
                    return data;
                }
            },
            { 
                data: 'fecha_venta', 
                defaultContent: '—',
                render: function(data, type, row) {
                    if (type === 'sort') {
                        return row.fecha_raw || '';
                    }
                    return data || '—';
                }
            },
            {
                data: null,
                render: function(row) {
                    const esPedido = row.folio_ticket && row.folio_ticket.startsWith('PEDIDO-');
                    if (esPedido) {
                        const estado = row.estado_pedido || 'pendiente';
                        if (estado === 'completado') {
                            return '<span class="badge-pedido badge-completado"><i class="fas fa-check-circle"></i> Completado</span>';
                        } else if (estado === 'cancelado') {
                            return '<span class="badge-pedido" style="background: #dc3545; color: white;"><i class="fas fa-ban"></i> Cancelado</span>';
                        } else {
                            return '<span class="badge-pedido badge-pendiente"><i class="fas fa-clock"></i> Pendiente</span>';
                        }
                    }
                    return '<span class="badge-pedido badge-completado"><i class="fas fa-tag"></i> Venta directa</span>';
                }
            },
            {
                data: null,
                orderable: false,
                render: function(row) {
                    const folio = row.folio_ticket ? String(row.folio_ticket) : '';
                    const esPedido = folio.startsWith('PEDIDO-');
                    const pedidoCompletado = row.estado_pedido === 'completado';
                    const ticketLink = row.ticket_pdf
                        ? `<a href="tickets/${row.ticket_pdf}" target="_blank" class="text-success" title="Ver PDF"><i class="fas fa-file-pdf"></i></a>`
                        : `<span class="text-muted" title="Sin ticket"><i class="fas fa-file-pdf"></i></span>`;

                    return `
                        <button class="btn-ver-ticket" data-folio="${encodeURIComponent(folio)}">
                            <i class="fas fa-eye"></i> Ver
                        </button>
                        ${ticketLink}
                        | <a href="#" class="reenvio-ticket" data-folio="${encodeURIComponent(folio)}" title="Reenviar" style="color: #f97316;">
                            <i class="fas fa-paper-plane"></i>
                        </a>
                        | ${!esPedido || !pedidoCompletado ? 
                            `<a href="#" class="cancelar-articulo" data-folio="${encodeURIComponent(folio)}" title="Cancelar artículo" style="color: #ffc107;">
                                <i class="fas fa-times-circle"></i>
                            </a>` : 
                            `<span class="text-muted"><i class="fas fa-times-circle"></i></span>`
                        }
                        | ${!esPedido || !pedidoCompletado ? 
                            `<a href="#" class="devolucion-parcial" data-folio="${encodeURIComponent(folio)}" title="Devolución" style="color: #7a68b1;">
                                <i class="fas fa-arrow-rotate-left"></i>
                            </a>` : 
                            `<span class="text-muted"><i class="fas fa-arrow-rotate-left"></i></span>`
                        }
                        | <a href="#" class="cancelar-venta" data-folio="${encodeURIComponent(folio)}" title="Cancelar venta" style="color: #dc3545;">
                            <i class="fas fa-ban"></i>
                        </a>
                    `;
                }
            }
        ],
        language: {
            processing: "Procesando...",
            lengthMenu: "Mostrar _MENU_ registros",
            info: "Mostrando _START_ a _END_ de _TOTAL_ registros",
            infoEmpty: "No hay registros",
            infoFiltered: "(filtrado de _MAX_ registros totales)",
            zeroRecords: "No se encontraron ventas",
            paginate: {
                first: '<i class="fas fa-angle-double-left"></i>',
                previous: '<i class="fas fa-angle-left"></i>',
                next: '<i class="fas fa-angle-right"></i>',
                last: '<i class="fas fa-angle-double-right"></i>'
            }
        },
        order: [[3, 'desc']],
        pageLength: 10,
        lengthChange: true,
        lengthMenu: [[10, 25, 50, 100], [10, 25, 50, 100]],
        responsive: true,
        searching: false,
        processing: true,
        serverSide: false,
        stateSave: false,
        autoWidth: false,
        dom: 'rt<"bottom"lip><"clear">',
        columnDefs: [
            { orderable: false, targets: [4, 5] },
            { type: 'date', targets: [3] },
            { className: "text-center", targets: [4] },
            { className: "text-nowrap", targets: [5] }
        ],
        drawCallback: function(settings) {
            $('.dataTables_paginate .paginate_button').addClass('btn btn-sm');
            bindAcciones();
            agregarDataLabels();

            var api = this.api();
            if (api.rows().count() === 0) {
                $('#tablaVentas tbody').html(`
                    <tr class="odd">
                        <td valign="top" colspan="6" class="dataTables_empty">
                            <div class="empty-message">
                                <div class="empty-icon">
                                    <i class="fas fa-chart-line"></i>
                                </div>
                                <div class="empty-title">No hay ventas registradas</div>
                                <div class="empty-text">Prueba con otros filtros o realiza una nueva venta</div>
                            </div>
                         </div>
                      </div>
                  </tr>
                `);
            }
        }
    });
}

function agregarDataLabels() {
    var columnas = ['', '', '', '', ''];
    
    $('#tablaVentas tbody tr').each(function() {
        $(this).find('td').each(function(index) {
            if (index > 0 && columnas[index-1]) {
                $(this).attr('data-label', columnas[index-1]);
            }
        });
    });
}

// Llamar después de cada recarga
$('#tablaVentas').on('draw.dt', function() {
    agregarDataLabels();
});

function bindAcciones() {
    // Botón Ver Ticket
    $('.btn-ver-ticket').off('click').on('click', function(e) {
        e.preventDefault();
        const folio = decodeURIComponent($(this).data('folio'));
        verTicket(folio);
    });
    
    // Reenvío de ticket
    $('.reenvio-ticket').off('click').on('click', function(e) {
        e.preventDefault();
        const folio = decodeURIComponent($(this).data('folio'));
        reenviarTicket(folio);
    });
    
    // Cancelar artículo - MODAL MEJORADO
    $('.cancelar-articulo').off('click').on('click', function(e) {
        e.preventDefault();
        const folio = decodeURIComponent($(this).data('folio'));
        cargarProductosParaCancelar(folio);
    });
    
    // Devolución parcial - MODAL MEJORADO
    $('.devolucion-parcial').off('click').on('click', function(e) {
        e.preventDefault();
        const folio = decodeURIComponent($(this).data('folio'));
        cargarProductosParaDevolucion(folio);
    });
    
    // Cancelar venta/pedido
    $('.cancelar-venta').off('click').on('click', function(e) {
        e.preventDefault();
        const folio = decodeURIComponent($(this).data('folio'));
        cancelarVenta(folio);
    });
}

// Cargar productos para cancelar artículo
function cargarProductosParaCancelar(folio) {
    Swal.fire({
        title: 'Cargando productos...',
        didOpen: () => Swal.showLoading(),
        allowOutsideClick: false,
        showConfirmButton: false
    });
    
    $.ajax({
        url: 'api/obtener_detalles_venta.php',
        method: 'GET',
        data: { folio: folio },
        dataType: 'json',
        success: function(response) {
            Swal.close();
            
            if (response.success && response.data && response.data.length > 0) {
                productosVenta = response.data;
                
                // Limpiar y llenar select
                const select = $('#ca_producto');
                select.empty();
                select.append('<option value="">Seleccionar producto...</option>');
                
                productosVenta.forEach(producto => {
                    select.append(`<option value="${producto.id_producto}">${producto.producto} (Cant: ${producto.cantidad})</option>`);
                });
                
                $('#ca_folio').val(folio);
                $('#ca_motivo').val('');
                $('#modalCancelarArticulo').modal('show');
            } else {
                Swal.fire('Error', 'No se pudieron cargar los productos', 'error');
            }
        },
        error: function() {
            Swal.close();
            Swal.fire('Error', 'Error de conexión', 'error');
        }
    });
}

// Confirmar cancelación de artículo - VERSIÓN CORREGIDA
function confirmarCancelarArticulo(folio, idProducto, motivo) {
    // Buscar el nombre del producto en productosVenta
    const productoSeleccionado = productosVenta.find(p => p.id_producto == idProducto);
    
    if (!productoSeleccionado) {
        Swal.fire('Error', 'No se encontró el producto seleccionado', 'error');
        return;
    }
    
    const nombreProducto = productoSeleccionado.producto;
    
    Swal.fire({
        title: 'Procesando...',
        didOpen: () => Swal.showLoading(),
        allowOutsideClick: false,
        showConfirmButton: false
    });
    
    $.ajax({
        url: 'api/cancelar_articulo.php',
        method: 'POST',
        data: JSON.stringify({
            folio: folio,
            producto: nombreProducto,  // 👈 Enviar nombre, no ID
            motivo: motivo
        }),
        contentType: 'application/json',
        dataType: 'json',
        success: function(response) {
            Swal.close();
            $('#modalCancelarArticulo').modal('hide');
            
            if (response.success) {
                Swal.fire({
                    icon: 'success',
                    title: 'Artículo cancelado',
                    text: response.message,
                    timer: 2000,
                    showConfirmButton: false
                }).then(() => {
                    tabla.ajax.reload();
                });
            } else {
                Swal.fire('Error', response.message, 'error');
            }
        },
        error: function(xhr) {
            Swal.close();
            let errorMsg = 'Error de conexión';
            try {
                const response = JSON.parse(xhr.responseText);
                errorMsg = response.message || errorMsg;
            } catch(e) {}
            Swal.fire('Error', errorMsg, 'error');
        }
    });
}

// Cargar productos para devolución
function cargarProductosParaDevolucion(folio) {
    Swal.fire({
        title: 'Cargando productos...',
        didOpen: () => Swal.showLoading(),
        allowOutsideClick: false,
        showConfirmButton: false
    });
    
    $.ajax({
        url: 'api/obtener_detalles_venta.php',
        method: 'GET',
        data: { folio: folio },
        dataType: 'json',
        success: function(response) {
            Swal.close();
            
            if (response.success && response.data && response.data.length > 0) {
                productosVenta = response.data;
                
                // Limpiar y llenar select
                const select = $('#dv_producto');
                select.empty();
                select.append('<option value="">Seleccionar producto...</option>');
                
                productosVenta.forEach(producto => {
                    select.append(`<option value="${producto.id_producto}" data-max="${producto.cantidad}">${producto.producto} (Cantidad disponible: ${producto.cantidad})</option>`);
                });
                
                $('#dv_folio').val(folio);
                $('#dv_cantidad').val('');
                $('#dv_motivo').val('');
                $('#modalDevolucion').modal('show');
                
                // Actualizar max cuando cambie el producto
                $('#dv_producto').off('change').on('change', function() {
                    const selectedOption = $(this).find('option:selected');
                    const maxCantidad = selectedOption.data('max') || 0;
                    $('#dv_cantidad').attr('max', maxCantidad);
                    $('#dv_cantidad').attr('placeholder', `Máximo: ${maxCantidad}`);
                });
            } else {
                Swal.fire('Error', 'No se pudieron cargar los productos', 'error');
            }
        },
        error: function() {
            Swal.close();
            Swal.fire('Error', 'Error de conexión', 'error');
        }
    });
}

// Confirmar devolución
function confirmarDevolucion(folio, idProducto, cantidad, motivo) {
    Swal.fire({
        title: 'Procesando...',
        didOpen: () => Swal.showLoading(),
        allowOutsideClick: false,
        showConfirmButton: false
    });
    
    $.ajax({
        url: 'api/devolver_parcial.php',
        method: 'POST',
        data: JSON.stringify({
            folio: folio,
            id_producto: idProducto,
            cantidad: parseInt(cantidad),
            motivo: motivo
        }),
        contentType: 'application/json',
        dataType: 'json',
        success: function(response) {
            Swal.close();
            $('#modalDevolucion').modal('hide');
            
            if (response.success) {
                Swal.fire({
                    icon: 'success',
                    title: 'Devolución procesada',
                    text: response.message,
                    timer: 2000,
                    showConfirmButton: false
                }).then(() => {
                    tabla.ajax.reload();
                });
            } else {
                Swal.fire('Error', response.message, 'error');
            }
        },
        error: function() {
            Swal.close();
            Swal.fire('Error', 'Error de conexión', 'error');
        }
    });
}

function verTicket(folio) {
    Swal.fire({ title: 'Cargando...', didOpen: () => Swal.showLoading(), allowOutsideClick: false, showConfirmButton: false });
    
    $.ajax({
        url: 'api/obtener_detalles_venta.php',
        method: 'GET',
        data: { folio: folio },
        dataType: 'json',
        success: function(response) {
            Swal.close();
            
            if (response.success && response.data && response.data.length > 0) {
const items = response.data;
const total = response.total || 0;
// Usar los datos directamente de response, NO de items[0]
const fechaVenta = response.fecha_venta;
const clienteCorreo = response.correo_cliente;
const vendedorNombre = response.vendedor_nombre;
                
                // Obtener datos de la tienda
                $.ajax({
                    url: 'api/obtener_configuracion.php',
                    method: 'GET',
                    dataType: 'json',
                    async: false,
                    success: function(config) {
                        window.tiendaConfig = config;
                    },
                    error: function() {
                        window.tiendaConfig = { nombre: 'TIENDA PESCADORES', telefono: '', email: '', direccion: '', logo: '' };
                    }
                });
                
                // Formatear fecha CORRECTAMENTE
// Formatear fecha CORRECTAMENTE
let fechaStr = 'Fecha no disponible';
if (fechaVenta) {
    const fecha = new Date(fechaVenta);
    if (!isNaN(fecha.getTime())) {
        fechaStr = fecha.toLocaleDateString('es-MX', { 
            day: '2-digit', 
            month: '2-digit', 
            year: 'numeric', 
            hour: '2-digit', 
            minute: '2-digit',
            hour12: false
        });
    } else {
        // Intentar parsear formato YYYY-MM-DD HH:MM:SS
        const partes = fechaVenta.match(/(\d{4})-(\d{2})-(\d{2}) (\d{2}):(\d{2}):(\d{2})/);
        if (partes) {
            const fechaObj = new Date(partes[1], partes[2]-1, partes[3], partes[4], partes[5], partes[6]);
            if (!isNaN(fechaObj.getTime())) {
                fechaStr = fechaObj.toLocaleDateString('es-MX', { 
                    day: '2-digit', 
                    month: '2-digit', 
                    year: 'numeric', 
                    hour: '2-digit', 
                    minute: '2-digit' 
                });
            }
        }
    }
}

const cliente = (clienteCorreo && clienteCorreo !== '' && clienteCorreo !== 'null') ? clienteCorreo : 'Venta en general';
                
               
                const logoBase64 = window.tiendaConfig?.logo_base64 || '';
                const tiendaNombre = window.tiendaConfig?.nombre || 'TIENDA PESCADORES';
                const tiendaTelefono = window.tiendaConfig?.telefono || '';
                const tiendaEmail = window.tiendaConfig?.email || '';
                const tiendaDireccion = window.tiendaConfig?.direccion || '';
                
                let itemsHtml = '';
                items.forEach(item => {
                    const precio = parseFloat(item.precio_unitario || item.precio || 0);
                    const cantidad = parseInt(item.cantidad);
                    itemsHtml += `
                        <tr>
                            <td style="text-align: center; padding: 5px; border-bottom: 1px dotted #ccc;">${cantidad}</td>
                            <td style="padding: 5px; border-bottom: 1px dotted #ccc;">${escapeHtml(item.producto)}</td>
                            <td style="text-align: center; padding: 5px; border-bottom: 1px dotted #ccc;">$${precio.toFixed(2)}</td>
                            <td style="text-align: center; padding: 5px; border-bottom: 1px dotted #ccc;">$${(precio * cantidad).toFixed(2)}</td>
                        </tr>
                    `;
                });
                
                // Logo HTML
                let logoHtml = '';
                if (logoBase64) {
                    logoHtml = `<img src="${logoBase64}" style="width: 60px; height: auto; margin-bottom: 10px;">`;
                } else {
                    logoHtml = `<i class="fas fa-store" style="font-size: 40px; color: #f97316; margin-bottom: 10px;"></i>`;
                }
                
    const html = `
        <div style="font-family: 'Courier New', monospace; background: white; max-width: 380px; margin: 0 auto; padding: 20px;">
            <div style="text-align: center; border-bottom: 1px dashed #333; padding-bottom: 10px; margin-bottom: 15px;">
                ${logoHtml}
                <h4 style="font-size: 14px; font-weight: bold; margin: 5px 0;">${escapeHtml(tiendaNombre)}</h4>
                ${tiendaDireccion ? `<p style="font-size: 9px; margin: 2px 0;">${escapeHtml(tiendaDireccion)}</p>` : ''}
                ${tiendaTelefono ? `<p style="font-size: 9px; margin: 2px 0;">Tel: ${escapeHtml(tiendaTelefono)}</p>` : ''}
                ${tiendaEmail ? `<p style="font-size: 9px; margin: 2px 0;">${escapeHtml(tiendaEmail)}</p>` : ''}
            </div>
            <div style="border-bottom: 1px dashed #333; padding-bottom: 10px; margin-bottom: 10px;">
                <div style="display: flex; justify-content: space-between; font-size: 11px; padding: 3px 0;">
                    <span style="font-weight: bold;">FOLIO:</span>
                    <span>${escapeHtml(folio)}</span>
                </div>
                <div style="display: flex; justify-content: space-between; font-size: 11px; padding: 3px 0;">
                    <span style="font-weight: bold;">CLIENTE:</span>
                    <span>${escapeHtml(cliente)}</span>
                </div>
                <div style="display: flex; justify-content: space-between; font-size: 11px; padding: 3px 0;">
                    <span style="font-weight: bold;">VENDEDOR:</span>
                    <span>${escapeHtml(vendedorNombre || 'Sistema')}</span>
                </div>
                <div style="display: flex; justify-content: space-between; font-size: 11px; padding: 3px 0;">
                    <span style="font-weight: bold;">FECHA:</span>
                    <span>${fechaStr}</span>
                </div>
            </div>
            <table style="width: 100%; border-collapse: collapse; font-size: 10px;">
                <thead>
                    <tr>
                        <th style="text-align: center; border-bottom: 1px solid #333; padding: 5px 0;">CANT</th>
                        <th style="text-align: center; border-bottom: 1px solid #333; padding: 5px 0;">PRODUCTO</th>
                        <th style="text-align: center; border-bottom: 1px solid #333; padding: 5px 0;">PRECIO</th>
                        <th style="text-align: center; border-bottom: 1px solid #333; padding: 5px 0;">SUBTOTAL</th>
                    </tr>
                </thead>
                <tbody>
                    ${itemsHtml}
                </tbody>
            </table>
            <div style="text-align: right; font-weight: bold; margin-top: 10px; padding-top: 10px; border-top: 1px dashed #333;">
                TOTAL: $${parseFloat(total).toFixed(2)}
            </div>
            <div style="text-align: center; font-size: 9px; margin-top: 15px; padding-top: 10px; border-top: 1px dashed #333; color: #666;">
                ¡Gracias por tu compra!<br>Este ticket es válido como comprobante
            </div>
        </div>
    `;
                
                $('#modalTicketBody').html(html);
                $('#modalTicket').modal('show');
                
                // Configurar botón de imprimir
                $('#btnImprimirTicket').off('click').on('click', function() {
                    const contenido = $('#modalTicketBody').html();
                    
                    // Crear una nueva ventana para imprimir
                    const ventanaImpresion = window.open('', '_blank');
                    
                    ventanaImpresion.document.write(`
                        <!DOCTYPE html>
                        <html>
                        <head>
                            <title>Ticket de Venta - ${escapeHtml(folio)}</title>
                            <meta charset="UTF-8">
                            <style>
                                * {
                                    margin: 0;
                                    padding: 0;
                                    box-sizing: border-box;
                                }
                                body {
                                    font-family: 'Courier New', monospace;
                                    background: white;
                                    padding: 20px;
                                    display: flex;
                                    justify-content: center;
                                    align-items: center;
                                    min-height: 100vh;
                                }
                                .ticket-container {
                                    max-width: 380px;
                                    width: 100%;
                                    margin: 0 auto;
                                    background: white;
                                }
                                table {
                                    width: 100%;
                                    border-collapse: collapse;
                                }
                                td, th {
                                    padding: 5px;
                                }
                                @media print {
                                    body {
                                        padding: 0;
                                        margin: 0;
                                    }
                                    .no-print {
                                        display: none;
                                    }
                                }
                            </style>
                        </head>
                        <body>
                            <div class="ticket-container">
                                ${contenido}
                            </div>
                            <script>
                                window.onload = function() {
                                    window.print();
                                    setTimeout(function() {
                                        window.close();
                                    }, 500);
                                };
                            <\/script>
                        </body>
                        </html>
                    `);
                    
                    ventanaImpresion.document.close();
                });
            } else {
                Swal.fire({ icon: 'error', title: 'Error', text: 'No se pudieron cargar los detalles', confirmButtonColor: '#f97316' });
            }
        },
        error: function() {
            Swal.close();
            Swal.fire({ icon: 'error', title: 'Error', text: 'Error de conexión', confirmButtonColor: '#f97316' });
        }
    });
}

function escapeHtml(text) {
    if (!text) return '';
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

async function reenviarTicket(folio) {
    try {
        Swal.fire({ title: 'Reenviando...', didOpen: () => Swal.showLoading(), allowOutsideClick: false, showConfirmButton: false });
        const res = await fetch(`enviar_ticket.php?folio=${encodeURIComponent(folio)}`);
        const data = await res.json();
        Swal.close();
        Swal.fire({ icon: data.success ? 'success' : 'error', title: data.message || (data.success ? 'Ticket reenviado' : 'Error'), timer: 2500, showConfirmButton: false });
    } catch (err) {
        Swal.close();
        Swal.fire({ icon: 'error', title: 'Error', text: err.message, confirmButtonColor: '#f97316' });
    }
}

function cancelarVenta(folio) {
    console.log("Cancelando con folio:", folio);
    
    if (!folio) {
        Swal.fire({
            icon: 'error',
            title: 'Error',
            text: 'No se pudo obtener el folio'
        });
        return;
    }
    
    const esPedido = folio.toString().startsWith('PEDIDO-');
    const titulo = esPedido ? '¿Cancelar pedido?' : '¿Cancelar venta?';
    const texto = esPedido 
        ? '¿Estás seguro de que deseas cancelar este pedido? Esta acción no se puede deshacer y se restaurará el stock.'
        : '¿Estás seguro de que deseas cancelar esta venta? Esta acción no se puede deshacer.';
    
    Swal.fire({
        title: titulo,
        text: texto,
        icon: 'warning',
        showCancelButton: true,
        confirmButtonText: 'Sí, cancelar',
        cancelButtonText: 'No, mantener',
        confirmButtonColor: '#d33',
        cancelButtonColor: '#3085d6'
    }).then((result) => {
        if (result.isConfirmed) {
            procesarCancelacionVenta(folio, esPedido);
        }
    });
}

async function procesarCancelacionVenta(folio, esPedido) {
    Swal.fire({
        title: 'Verificando...',
        allowOutsideClick: false,
        showConfirmButton: false,
        didOpen: () => {
            Swal.showLoading();
        }
    });
    
    try {
        const requestBody = {
            folio: folio.toString(),
            forzar: false
        };
        
        console.log("Enviando petición:", requestBody);
        
        const response = await fetch('api/cancelar_venta.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json'
            },
            body: JSON.stringify(requestBody)
        });
        
        const data = await response.json();
        Swal.close();
        
        console.log("Respuesta del servidor:", data);
        
        if (data.pedido_completado === true) {
            Swal.fire({
                title: 'Pedido completado',
                text: data.message,
                icon: 'warning',
                showCancelButton: true,
                confirmButtonText: 'Sí, cancelar',
                cancelButtonText: 'No',
                confirmButtonColor: '#d33'
            }).then((result) => {
                if (result.isConfirmed) {
                    cancelarPedidoCompletado(folio);
                }
            });
        } else if (data.success) {
            const tituloExito = esPedido ? '¡Pedido cancelado!' : '¡Venta cancelada!';
            Swal.fire({
                icon: 'success',
                title: tituloExito,
                text: data.message,
                confirmButtonColor: '#28a745',
                timer: 2000
            }).then(() => {
                location.reload();
            });
        } else {
            const tituloError = esPedido ? 'Error al cancelar pedido' : 'Error al cancelar venta';
            Swal.fire({
                icon: 'error',
                title: tituloError,
                text: data.message || 'No se pudo cancelar',
                confirmButtonColor: '#f97316'
            });
        }
    } catch (error) {
        Swal.close();
        console.error('Error:', error);
        Swal.fire({
            icon: 'error',
            title: 'Error de conexión',
            text: error.message,
            confirmButtonColor: '#f97316'
        });
    }
}

async function cancelarPedidoCompletado(folio) {
    Swal.fire({
        title: 'Cancelando pedido completado...',
        allowOutsideClick: false,
        showConfirmButton: false,
        didOpen: () => {
            Swal.showLoading();
        }
    });
    
    try {
        const requestBody = {
            folio: folio.toString(),
            forzar: true
        };
        
        console.log("Enviando petición con forzar=true:", requestBody);
        
        const response = await fetch('api/cancelar_venta.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json'
            },
            body: JSON.stringify(requestBody)
        });
        
        const data = await response.json();
        Swal.close();
        
        console.log("Respuesta final:", data);
        
        if (data.success) {
            Swal.fire({
                icon: 'success',
                title: '¡Pedido cancelado!',
                text: data.message,
                confirmButtonColor: '#28a745',
                timer: 2000
            }).then(() => {
                location.reload();
            });
        } else {
            Swal.fire({
                icon: 'error',
                title: 'Error',
                text: data.message || 'No se pudo cancelar el pedido',
                confirmButtonColor: '#f97316'
            });
        }
    } catch (error) {
        Swal.close();
        console.error('Error:', error);
        Swal.fire({
            icon: 'error',
            title: 'Error de conexión',
            text: error.message,
            confirmButtonColor: '#f97316'
        });
    }
}
</script>

<?php include 'includes/footer.php'; ?>