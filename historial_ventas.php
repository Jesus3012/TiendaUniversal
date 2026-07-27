<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/session.php';
require_once __DIR__ . '/includes/db.php';

/*
|--------------------------------------------------------------------------
| Validación de acceso
|--------------------------------------------------------------------------
| Esta validación debe ejecutarse antes de incluir header.php o navbar.php,
| porque esos archivos generan salida HTML y después ya no se puede usar
| header() para redirigir.
*/

$usuario_id_sesion = (int) ($_SESSION['usuario_id'] ?? 0);
$rol_actual = strtolower(trim((string) ($_SESSION['rol'] ?? '')));

$roles_permitidos = [
    'vendedor',
    'administrador',
    'super_administrador',
];

if ($usuario_id_sesion <= 0) {
    header('Location: login.php');
    exit;
}

if (!in_array($rol_actual, $roles_permitidos, true)) {
    header('Location: sin_permiso.php');
    exit;
}

/*
|--------------------------------------------------------------------------
| Interfaz
|--------------------------------------------------------------------------
| Se carga únicamente después de validar la sesión y el rol.
*/

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/navbar.php';
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

#buscar_global, #fecha_inicio, #fecha_fin, #estado_venta {
    border-radius: 8px;
    border: 1px solid #ddd;
    padding: 8px 12px;
    font-size: 14px;
    width: 100%;
}

#buscar_global:focus, #fecha_inicio:focus, #fecha_fin:focus, #estado_venta:focus {
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

.badge-cancelada {
    background-color: #dc3545;
    color: #fff;
}

.badge-parcial {
    background-color: #7c3aed;
    color: #fff;
}

.venta-cancelada-row {
    background: #fff7f7 !important;
}

.venta-cancelada-row td {
    color: #64748b;
}

.venta-cancelada-row td:first-child {
    border-left: 3px solid #dc3545;
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

/* Acciones bloqueadas por lógica de venta */
.acciones-venta {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    flex-wrap: nowrap;
}

.acciones-venta a,
.acciones-venta span {
    display: inline-flex;
    align-items: center;
    justify-content: center;
}


/* Mantener los colores originales de cada acción */
.acciones-venta .text-success:not(.accion-deshabilitada),
.acciones-venta a.text-success:not(.accion-deshabilitada) {
    color: #28a745 !important;
}

.acciones-venta .reenvio-ticket:not(.accion-deshabilitada) {
    color: #f97316 !important;
}

.acciones-venta .cancelar-articulo:not(.accion-deshabilitada) {
    color: #ffc107 !important;
}

.acciones-venta .devolucion-parcial:not(.accion-deshabilitada) {
    color: #7a68b1 !important;
}

.acciones-venta .cancelar-venta:not(.accion-deshabilitada) {
    color: #dc3545 !important;
}

.acciones-venta .text-success:not(.accion-deshabilitada) i,
.acciones-venta .reenvio-ticket:not(.accion-deshabilitada) i,
.acciones-venta .cancelar-articulo:not(.accion-deshabilitada) i,
.acciones-venta .devolucion-parcial:not(.accion-deshabilitada) i,
.acciones-venta .cancelar-venta:not(.accion-deshabilitada) i {
    color: inherit !important;
}

.accion-deshabilitada {
    color: #cbd5e1 !important;
    cursor: not-allowed !important;
    opacity: 0.75;
    text-decoration: none !important;
    pointer-events: auto;
}

.accion-deshabilitada i {
    color: #cbd5e1 !important;
}

.accion-cargando {
    color: #94a3b8 !important;
    cursor: wait !important;
}

.accion-cargando i {
    color: #94a3b8 !important;
}

.accion-separador {
    color: #d1d5db;
    font-size: 11px;
    user-select: none;
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
                        <div class="col-lg-3 col-md-6 mb-2">
                            <input type="text" id="buscar_global" class="form-control" placeholder="Buscar folio, producto o cliente...">
                        </div>
                        <div class="col-lg-2 col-md-6 mb-2">
                            <input type="date" id="fecha_inicio" class="form-control" aria-label="Fecha inicial">
                        </div>
                        <div class="col-lg-2 col-md-6 mb-2">
                            <input type="date" id="fecha_fin" class="form-control" aria-label="Fecha final">
                        </div>
                        <div class="col-lg-3 col-md-6 mb-2">
                            <select id="estado_venta" class="form-control">
                                <option value="">Todos los estados</option>
                                <option value="completada">Completadas</option>
                                <option value="pendiente">Pendientes</option>
                                <option value="parcial">Con ajuste parcial</option>
                                <option value="cancelada">Canceladas</option>
                            </select>
                        </div>
                        <div class="col-lg-2 col-md-12 mb-2">
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
                    <label><i class="fas fa-comment"></i> Motivo de cancelación <small class="text-muted">(opcional)</small>:</label>
                    <textarea id="ca_motivo" class="form-control" rows="3" placeholder="Puedes dejarlo vacío o escribir el motivo..."></textarea>
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
                    <label><i class="fas fa-comment"></i> Motivo de devolución <small class="text-muted">(opcional)</small>:</label>
                    <textarea id="dv_motivo" class="form-control" rows="3" placeholder="Puedes dejarlo vacío o escribir el motivo..."></textarea>
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
    
    // Filtros de fecha y estado
    $('#estado_venta').on('change', function() {
        if (tabla) {
            tabla.ajax.reload();
        }
    });

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
        $('#buscar_global, #fecha_inicio, #fecha_fin, #estado_venta').val('');
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
        const estado = $('#estado_venta').val();
        if (!inicio || !fin) {
            Swal.fire({ icon: 'warning', title: 'Fechas requeridas', text: 'Selecciona ambas fechas', confirmButtonColor: '#f97316' });
            return;
        }
        window.location = `exportar_excel.php?inicio=${inicio}&fin=${fin}&busqueda=${encodeURIComponent(busqueda)}&estado=${encodeURIComponent(estado)}`;
    });
    
    // Exportar PDF
    $('#export_pdf').click(() => {
        const inicio = $('#fecha_inicio').val();
        const fin = $('#fecha_fin').val();
        const busqueda = $('#buscar_global').val();
        const estado = $('#estado_venta').val();
        if (!inicio || !fin) {
            Swal.fire({ icon: 'warning', title: 'Fechas requeridas', text: 'Selecciona ambas fechas', confirmButtonColor: '#f97316' });
            return;
        }
        window.location = `api/exportar_ventas.php?inicio=${inicio}&fin=${fin}&busqueda=${encodeURIComponent(busqueda)}&estado=${encodeURIComponent(estado)}`;
    });
    
    // Eventos de modales
    $('#btnConfirmarCancelarArticulo').on('click', function() {
        const folio = $('#ca_folio').val();
        const producto = $('#ca_producto').val();
        const motivo = ($('#ca_motivo').val() || '').trim();

        if (!producto) {
            Swal.fire('Error', 'Selecciona un producto', 'error');
            return;
        }

        confirmarCancelarArticulo(folio, producto, motivo);
    });

    $('#btnConfirmarDevolucion').on('click', function() {
        const folio = $('#dv_folio').val();
        const producto = $('#dv_producto').val();
        const cantidad = parseInt($('#dv_cantidad').val(), 10);
        const motivo = ($('#dv_motivo').val() || '').trim();
        const maxPermitido = parseInt($('#dv_producto option:selected').data('max-devolucion') || 0, 10);

        if (!producto) {
            Swal.fire('Error', 'Selecciona un producto', 'error');
            return;
        }
        if (!cantidad || cantidad <= 0) {
            Swal.fire('Error', 'Ingresa una cantidad válida', 'error');
            return;
        }
        if (maxPermitido > 0 && cantidad > maxPermitido) {
            Swal.fire('Cantidad no permitida', `Puedes devolver máximo ${maxPermitido} pieza(s) de este producto.`, 'warning');
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
                    fin: $('#fecha_fin').val(),
                    estado: $('#estado_venta').val()
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
                data: 'estado_venta',
                render: function(data, type, row) {
                    const estado = String(data || 'completada').toLowerCase();
                    const motivo = row.motivo_cancelacion
                        ? ` title="${escapeHtml(row.motivo_cancelacion)}"`
                        : '';

                    if (estado === 'cancelada') {
                        return `<span class="badge-pedido badge-cancelada"${motivo}><i class="fas fa-ban"></i> Cancelada</span>`;
                    }

                    if (estado === 'pendiente') {
                        return '<span class="badge-pedido badge-pendiente"><i class="fas fa-clock"></i> Pendiente</span>';
                    }

                    if (estado === 'parcial') {
                        return '<span class="badge-pedido badge-parcial"><i class="fas fa-circle-half-stroke"></i> Ajuste parcial</span>';
                    }

                    return '<span class="badge-pedido badge-completado"><i class="fas fa-check-circle"></i> Completada</span>';
                }
            },
            {
                data: null,
                orderable: false,
                render: function(row) {
                    const folio = row.folio_ticket ? String(row.folio_ticket) : '';
                    const folioEncoded = encodeURIComponent(folio);
                    const esPedido = folio.startsWith('PEDIDO-');
                    const pedidoCompletado = row.estado_pedido === 'completado';
                    const estadoVenta = String(row.estado_venta || 'completada').toLowerCase();
                    const ventaCancelada = estadoVenta === 'cancelada';
                    const puedeCancelarTotal = row.puede_cancelar_total === true || Number(row.puede_cancelar_total) === 1;
                    const puedeDevolucionParcial = row.puede_devolucion_parcial === true || Number(row.puede_devolucion_parcial) === 1;
                    const diasCancelacion = Math.max(Number(row.dias_restantes_cancelacion_total || 0), 0);
                    const diasDevolucion = Math.max(Number(row.dias_restantes_devolucion_parcial || 0), 0);
                    const motivoPlazoCancelacion = String(row.motivo_bloqueo_cancelacion_total || 'El plazo de cancelación ya no está disponible.');
                    const motivoPlazoDevolucion = String(row.motivo_bloqueo_devolucion_parcial || 'El plazo de devolución ya no está disponible.');
                    const correoCliente = row.correo_cliente ? String(row.correo_cliente).trim() : '';
                    const puedeReenviar = tieneCorreoValido(correoCliente) && !ventaCancelada;

                    const ticketLink = row.ticket_pdf
                        ? `<a href="tickets/${row.ticket_pdf}" target="_blank" class="text-success" title="Ver PDF"><i class="fas fa-file-pdf"></i></a>`
                        : `<span class="text-muted" title="Sin ticket"><i class="fas fa-file-pdf"></i></span>`;

                    const reenvioLink = puedeReenviar
                        ? `<a href="#" class="reenvio-ticket" data-folio="${folioEncoded}" data-correo="${escapeHtml(correoCliente)}" title="Reenviar ticket" style="color: #f97316;">
                                <i class="fas fa-paper-plane"></i>
                           </a>`
                        : `<span class="reenvio-ticket accion-deshabilitada" data-disabled="1" data-folio="${folioEncoded}" title="${ventaCancelada ? 'No se reenvía un ticket como venta vigente porque la operación está cancelada' : 'No se puede reenviar: la venta no tiene correo registrado'}">
                                <i class="fas fa-paper-plane"></i>
                           </span>`;

                    const accionesParcialesBloqueadas = ventaCancelada || (esPedido && pedidoCompletado) || !puedeDevolucionParcial;
                    let motivoBloqueoParcial = '';

                    if (ventaCancelada) {
                        motivoBloqueoParcial = 'La venta ya está cancelada';
                    } else if (esPedido && pedidoCompletado) {
                        motivoBloqueoParcial = 'No disponible para pedido completado';
                    } else if (!puedeDevolucionParcial) {
                        motivoBloqueoParcial = motivoPlazoDevolucion;
                    }

                    const tituloParcialDisponible = diasDevolucion === 0
                        ? 'Disponible únicamente durante el día de hoy'
                        : `Plazo disponible: ${diasDevolucion} día(s) restante(s)`;

                    const cancelarArticuloLink = accionesParcialesBloqueadas
                        ? `<span class="accion-deshabilitada" title="${escapeAttr(motivoBloqueoParcial)}"><i class="fas fa-times-circle"></i></span>`
                        : `<a href="#" class="cancelar-articulo accion-validable accion-cargando accion-deshabilitada" data-enabled="false" style="color: #ffc107;" data-folio="${folioEncoded}" title="Validando productos de la venta. ${escapeAttr(tituloParcialDisponible)}">
                                <i class="fas fa-times-circle"></i>
                           </a>`;

                    const devolucionLink = accionesParcialesBloqueadas
                        ? `<span class="accion-deshabilitada" title="${escapeAttr(motivoBloqueoParcial)}"><i class="fas fa-arrow-rotate-left"></i></span>`
                        : `<a href="#" class="devolucion-parcial accion-validable accion-cargando accion-deshabilitada" data-enabled="false" style="color: #7a68b1;" data-folio="${folioEncoded}" title="Validando productos de la venta. ${escapeAttr(tituloParcialDisponible)}">
                                <i class="fas fa-arrow-rotate-left"></i>
                           </a>`;

                    let cancelarVentaLink = '';
                    if (ventaCancelada) {
                        cancelarVentaLink = `<span class="accion-deshabilitada" title="La venta ya está cancelada"><i class="fas fa-ban"></i></span>`;
                    } else if (!puedeCancelarTotal) {
                        cancelarVentaLink = `<span class="accion-deshabilitada" title="${escapeAttr(motivoPlazoCancelacion)}"><i class="fas fa-ban"></i></span>`;
                    } else {
                        const tituloCancelacion = diasCancelacion === 0
                            ? 'Cancelar venta completa · disponible únicamente durante el día de hoy'
                            : `Cancelar venta completa · ${diasCancelacion} día(s) restante(s)`;
                        cancelarVentaLink = `<a href="#" class="cancelar-venta" data-folio="${folioEncoded}" title="${escapeAttr(tituloCancelacion)}" style="color: #dc3545;">
                                <i class="fas fa-ban"></i>
                           </a>`;
                    }

                    return `
                        <span class="acciones-venta" data-folio="${folioEncoded}" data-estado="${estadoVenta}">
                            <button class="btn-ver-ticket" data-folio="${folioEncoded}" title="Ver ticket">
                                <i class="fas fa-eye"></i> Ver
                            </button>
                            ${ticketLink}
                            <span class="accion-separador">|</span>
                            ${reenvioLink}
                            <span class="accion-separador">|</span>
                            ${cancelarArticuloLink}
                            <span class="accion-separador">|</span>
                            ${devolucionLink}
                            <span class="accion-separador">|</span>
                            ${cancelarVentaLink}
                        </span>
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
        createdRow: function(row, data) {
            if (String(data.estado_venta || '').toLowerCase() === 'cancelada') {
                $(row).addClass('venta-cancelada-row');
            }
        },
        drawCallback: function(settings) {
            $('.dataTables_paginate .paginate_button').addClass('btn btn-sm');
            bindAcciones();
            agregarDataLabels();
            prevalidarAccionesVisibles();

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

// ================================
// VALIDACIONES DE ACCIONES POR FOLIO
// ================================
const resumenVentasCache = new Map();

function safeDecodeURIComponent(valor) {
    try {
        return decodeURIComponent(valor || '');
    } catch (e) {
        return valor || '';
    }
}

function tieneCorreoValido(correo) {
    const valor = (correo || '').toString().trim();

    if (!valor) return false;

    const bloqueados = [
        'null',
        'cliente no registrado',
        'venta en general',
        'sin correo',
        'no aplica',
        'n/a'
    ];

    if (bloqueados.includes(valor.toLowerCase())) {
        return false;
    }

    return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(valor);
}

function obtenerCantidadItem(item) {
    const cantidad = parseInt(
        item?.cantidad ??
        item?.cantidad_vendida ??
        item?.cantidad_disponible ??
        item?.qty ??
        0,
        10
    );

    return Number.isFinite(cantidad) && cantidad > 0 ? cantidad : 0;
}

function obtenerClaveProducto(item) {
    return String(
        item?.id_producto ??
        item?.producto_id ??
        item?.id ??
        item?.producto ??
        item?.nombre ??
        ''
    );
}

function calcularResumenVenta(items) {
    const lista = Array.isArray(items) ? items : [];
    const productosValidos = lista.filter(item => obtenerCantidadItem(item) > 0);
    const claves = new Set();

    let cantidadTotal = 0;

    productosValidos.forEach(item => {
        cantidadTotal += obtenerCantidadItem(item);
        const clave = obtenerClaveProducto(item);

        if (clave !== '') {
            claves.add(clave);
        }
    });

    const articulosDiferentes = claves.size || productosValidos.length;

    return {
        items: productosValidos,
        articulosDiferentes,
        cantidadTotal,
        puedeCancelarArticulo: articulosDiferentes >= 2,
        puedeDevolucionParcial: articulosDiferentes >= 2 || cantidadTotal > 1
    };
}

async function obtenerResumenVenta(folio, refrescar = false) {
    const folioReal = (folio || '').toString();

    if (!folioReal) {
        throw new Error('Folio inválido');
    }

    if (!refrescar && resumenVentasCache.has(folioReal)) {
        return resumenVentasCache.get(folioReal);
    }

    const response = await fetch(`api/obtener_detalles_venta.php?folio=${encodeURIComponent(folioReal)}`, {
        method: 'GET',
        cache: 'no-store'
    });

    const data = await response.json();

    if (!data.success || !Array.isArray(data.data)) {
        throw new Error(data.message || 'No se pudieron obtener los productos de la venta.');
    }

    const resumen = calcularResumenVenta(data.data);
    resumenVentasCache.set(folioReal, resumen);

    return resumen;
}

function aplicarEstadoAccion($accion, habilitada, tituloHabilitado, tituloBloqueado) {
    $accion.removeClass('accion-cargando');

    if (habilitada) {
        $accion
            .removeClass('accion-deshabilitada')
            .attr('data-enabled', 'true')
            .attr('title', tituloHabilitado);
    } else {
        $accion
            .addClass('accion-deshabilitada')
            .attr('data-enabled', 'false')
            .attr('title', tituloBloqueado);
    }
}

function actualizarAccionesPorFolio(folio, resumen) {
    const folioEncoded = encodeURIComponent(folio);

    $(`.cancelar-articulo[data-folio="${folioEncoded}"]`).each(function() {
        aplicarEstadoAccion(
            $(this),
            resumen.puedeCancelarArticulo,
            'Cancelar un artículo de la venta',
            'Bloqueado: para cancelar artículo la venta debe tener al menos 2 artículos diferentes.'
        );
    });

    $(`.devolucion-parcial[data-folio="${folioEncoded}"]`).each(function() {
        aplicarEstadoAccion(
            $(this),
            resumen.puedeDevolucionParcial,
            'Gestionar devolución parcial',
            'Bloqueado: para devolución parcial debe haber 2 artículos o 1 artículo con más de 1 pieza.'
        );
    });
}

function bloquearAccionesPorError(folio, mensaje) {
    const folioEncoded = encodeURIComponent(folio);

    $(`.accion-validable[data-folio="${folioEncoded}"]`).each(function() {
        aplicarEstadoAccion(
            $(this),
            false,
            '',
            mensaje || 'No se pudo validar la venta.'
        );
    });
}

function prevalidarAccionesVisibles() {
    const folios = new Set();

    $('#tablaVentas tbody .acciones-venta').each(function() {
        const estado = String($(this).attr('data-estado') || '').toLowerCase();
        if (estado === 'cancelada') {
            return;
        }

        const folio = safeDecodeURIComponent($(this).attr('data-folio') || '');
        if (folio) {
            folios.add(folio);
        }
    });

    folios.forEach(async (folio) => {
        try {
            const resumen = await obtenerResumenVenta(folio);
            actualizarAccionesPorFolio(folio, resumen);
        } catch (error) {
            console.error('No se pudieron validar acciones para folio:', folio, error);
            bloquearAccionesPorError(folio, error.message);
        }
    });
}

function accionEstaBloqueada(elemento) {
    const $el = $(elemento);
    return $el.hasClass('accion-deshabilitada') || $el.attr('data-enabled') === 'false' || $el.attr('data-disabled') === '1';
}

function mostrarMotivoBloqueo(elemento, tituloDefault = 'Acción no disponible') {
    const mensaje = $(elemento).attr('title') || tituloDefault;

    Swal.fire({
        icon: 'info',
        title: tituloDefault,
        text: mensaje,
        confirmButtonColor: '#f97316'
    });
}

function maxDevolucionPermitida(producto, resumen) {
    const cantidad = obtenerCantidadItem(producto);

    if (resumen.articulosDiferentes <= 1) {
        return Math.max(cantidad - 1, 0);
    }

    return cantidad;
}

function bindAcciones() {
    // Botón Ver Ticket
    $('.btn-ver-ticket').off('click').on('click', function(e) {
        e.preventDefault();
        const folio = safeDecodeURIComponent($(this).attr('data-folio') || $(this).data('folio'));
        verTicket(folio);
    });

    // Reenvío de ticket
    $('.reenvio-ticket').off('click').on('click', function(e) {
        e.preventDefault();

        if (accionEstaBloqueada(this)) {
            mostrarMotivoBloqueo(this, 'Reenvío bloqueado');
            return;
        }

        const folio = safeDecodeURIComponent($(this).attr('data-folio') || $(this).data('folio'));
        reenviarTicket(folio);
    });

    // Cancelar artículo
    $('.cancelar-articulo').off('click').on('click', async function(e) {
        e.preventDefault();

        if (accionEstaBloqueada(this)) {
            mostrarMotivoBloqueo(this, 'Cancelación de artículo bloqueada');
            return;
        }

        const folio = safeDecodeURIComponent($(this).attr('data-folio') || $(this).data('folio'));
        cargarProductosParaCancelar(folio);
    });

    // Devolución parcial
    $('.devolucion-parcial').off('click').on('click', function(e) {
        e.preventDefault();

        if (accionEstaBloqueada(this)) {
            mostrarMotivoBloqueo(this, 'Devolución parcial bloqueada');
            return;
        }

        const folio = safeDecodeURIComponent($(this).attr('data-folio') || $(this).data('folio'));
        cargarProductosParaDevolucion(folio);
    });

    // Cancelar venta/pedido
    $('.cancelar-venta').off('click').on('click', function(e) {
        e.preventDefault();
        const folio = safeDecodeURIComponent($(this).attr('data-folio') || $(this).data('folio'));
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
        cache: false,
        success: function(response) {
            Swal.close();

            if (response.success && response.data && response.data.length > 0) {
                productosVenta = response.data;
                const resumen = calcularResumenVenta(productosVenta);
                resumenVentasCache.set(folio, resumen);
                actualizarAccionesPorFolio(folio, resumen);

                if (!resumen.puedeCancelarArticulo) {
                    Swal.fire({
                        icon: 'info',
                        title: 'Cancelación de artículo bloqueada',
                        text: 'Para cancelar un artículo, la venta debe tener al menos 2 artículos diferentes. Si solo tiene un artículo con varias piezas, usa devolución parcial.',
                        confirmButtonColor: '#f97316'
                    });
                    return;
                }

                const select = $('#ca_producto');
                select.empty();
                select.append('<option value="">Seleccionar producto...</option>');

                resumen.items.forEach(producto => {
                    const cantidad = obtenerCantidadItem(producto);
                    const idProducto = producto.id_producto ?? producto.producto_id ?? producto.id;
                    const nombreProducto = producto.producto ?? producto.nombre ?? 'Producto';
                    select.append(`<option value="${idProducto}">${escapeHtml(nombreProducto)} (Cant: ${cantidad})</option>`);
                });

                $('#ca_folio').val(folio);
                $('#ca_motivo').val('');
                $('#modalCancelarArticulo').modal('show');
            } else {
                Swal.fire('Error', response.message || 'No se pudieron cargar los productos', 'error');
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
            motivo: motivo || ''
        }),
        contentType: 'application/json',
        dataType: 'json',
        success: function(response) {
            Swal.close();
            $('#modalCancelarArticulo').modal('hide');
            resumenVentasCache.delete(folio);
            
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
        cache: false,
        success: function(response) {
            Swal.close();

            if (response.success && response.data && response.data.length > 0) {
                productosVenta = response.data;
                const resumen = calcularResumenVenta(productosVenta);
                resumenVentasCache.set(folio, resumen);
                actualizarAccionesPorFolio(folio, resumen);

                if (!resumen.puedeDevolucionParcial) {
                    Swal.fire({
                        icon: 'info',
                        title: 'Devolución parcial bloqueada',
                        text: 'Para gestionar una devolución parcial, la venta debe tener 2 artículos o un solo artículo con más de 1 pieza.',
                        confirmButtonColor: '#f97316'
                    });
                    return;
                }

                const select = $('#dv_producto');
                select.empty();
                select.append('<option value="">Seleccionar producto...</option>');

                resumen.items.forEach(producto => {
                    const cantidad = obtenerCantidadItem(producto);
                    const maxPermitido = maxDevolucionPermitida(producto, resumen);
                    const idProducto = producto.id_producto ?? producto.producto_id ?? producto.id;
                    const nombreProducto = producto.producto ?? producto.nombre ?? 'Producto';

                    if (maxPermitido > 0) {
                        select.append(`
                            <option
                                value="${idProducto}"
                                data-max="${cantidad}"
                                data-max-devolucion="${maxPermitido}"
                            >
                                ${escapeHtml(nombreProducto)} (Disponible: ${cantidad}, máximo a devolver: ${maxPermitido})
                            </option>
                        `);
                    }
                });

                if (select.find('option').length <= 1) {
                    Swal.fire({
                        icon: 'info',
                        title: 'Devolución parcial bloqueada',
                        text: 'No hay productos con cantidad suficiente para realizar una devolución parcial.',
                        confirmButtonColor: '#f97316'
                    });
                    return;
                }

                $('#dv_folio').val(folio);
                $('#dv_cantidad').val('');
                $('#dv_motivo').val('');
                $('#modalDevolucion').modal('show');

                $('#dv_producto').off('change').on('change', function() {
                    const selectedOption = $(this).find('option:selected');
                    const maxCantidad = selectedOption.data('max-devolucion') || 0;
                    $('#dv_cantidad')
                        .attr('max', maxCantidad)
                        .attr('placeholder', `Máximo: ${maxCantidad}`)
                        .val('');
                });
            } else {
                Swal.fire('Error', response.message || 'No se pudieron cargar los productos', 'error');
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

// Confirmar devolución
function confirmarDevolucion(folio, idProducto, cantidad, motivo) {
    const cantidadInt = parseInt(cantidad, 10);
    const maxPermitido = parseInt($('#dv_producto option:selected').data('max-devolucion') || 0, 10);

    if (!cantidadInt || cantidadInt <= 0) {
        Swal.fire('Error', 'Ingresa una cantidad válida', 'error');
        return;
    }

    if (maxPermitido > 0 && cantidadInt > maxPermitido) {
        Swal.fire('Cantidad no permitida', `Puedes devolver máximo ${maxPermitido} pieza(s) de este producto.`, 'warning');
        return;
    }

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
            cantidad: cantidadInt,
            motivo: motivo || ''
        }),
        contentType: 'application/json',
        dataType: 'json',
        success: function(response) {
            Swal.close();
            $('#modalDevolucion').modal('hide');
            resumenVentasCache.delete(folio);

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
const estadoVentaTicket = String(response.estado_venta || 'completada').toLowerCase();
const motivoCancelacionTicket = response.motivo_cancelacion || '';
const fechaCancelacionTicket = response.fecha_cancelacion || '';
                
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
                <div style="display: flex; justify-content: space-between; align-items:center; gap:10px; font-size: 11px; padding: 3px 0;">
                    <span style="font-weight: bold;">ESTADO:</span>
                    <span style="font-weight:800; color:${estadoVentaTicket === 'cancelada' ? '#dc2626' : (estadoVentaTicket === 'parcial' ? '#7c3aed' : (estadoVentaTicket === 'pendiente' ? '#ca8a04' : '#16a34a'))};">
                        ${estadoVentaTicket === 'cancelada' ? 'CANCELADA' : (estadoVentaTicket === 'parcial' ? 'AJUSTE PARCIAL' : (estadoVentaTicket === 'pendiente' ? 'PENDIENTE' : 'COMPLETADA'))}
                    </span>
                </div>
                ${estadoVentaTicket === 'cancelada' && motivoCancelacionTicket ? `
                    <div style="font-size:10px; padding:5px 0 2px; color:#991b1b;">
                        <b>Motivo:</b> ${escapeHtml(motivoCancelacionTicket)}
                    </div>
                ` : ''}
                ${estadoVentaTicket === 'cancelada' && fechaCancelacionTicket ? `
                    <div style="font-size:10px; padding:2px 0; color:#64748b;">
                        <b>Cancelada:</b> ${escapeHtml(fechaCancelacionTicket)}
                    </div>
                ` : ''}
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

function escapeAttr(text) {
    return escapeHtml(String(text || ''))
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');
}

async function reenviarTicket(folio) {
    if (!folio) {
        Swal.fire({ icon: 'error', title: 'Error', text: 'No se pudo obtener el folio', confirmButtonColor: '#f97316' });
        return;
    }

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
        ? 'Esta acción restaurará el stock y cancelará el pedido.'
        : 'Esta acción restaurará el stock y cancelará la venta.';

    Swal.fire({
        title: titulo,
        html: `
            <div style="text-align:left;">
                <p style="margin:0 0 12px; color:#475569; font-size:14px; line-height:1.45;">
                    ${texto}
                </p>
                <label for="motivo_cancelacion_venta" style="display:block; font-weight:700; font-size:13px; color:#334155; margin-bottom:6px;">
                    Motivo de cancelación <span style="font-weight:500; color:#94a3b8;">(opcional)</span>
                </label>
                <textarea
                    id="motivo_cancelacion_venta"
                    class="swal2-textarea"
                    placeholder="Puedes dejarlo vacío o escribir el motivo..."
                    style="width:100%; min-height:95px; margin:0; resize:vertical; border-radius:12px; font-size:14px;"
                ></textarea>
            </div>
        `,
        icon: 'warning',
        showCancelButton: true,
        confirmButtonText: 'Sí, cancelar',
        cancelButtonText: 'No, mantener',
        confirmButtonColor: '#d33',
        cancelButtonColor: '#3085d6',
        focusConfirm: false,
        preConfirm: () => {
            const motivo = document.getElementById('motivo_cancelacion_venta')?.value || '';
            return {
                motivo: motivo.trim()
            };
        }
    }).then((result) => {
        if (result.isConfirmed) {
            procesarCancelacionVenta(folio, esPedido, result.value?.motivo || '');
        }
    });
}

async function procesarCancelacionVenta(folio, esPedido, motivo = '') {
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
            forzar: false,
            motivo: motivo
        };

        // console.log("Enviando petición:", requestBody);

        const response = await fetch('api/cancelar_venta.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json'
            },
            body: JSON.stringify(requestBody)
        });

        const data = await response.json();
        Swal.close();
        resumenVentasCache.delete(folio);

        // console.log("Respuesta del servidor:", data);

        if (data.pedido_completado === true) {
            Swal.fire({
                title: 'Pedido completado',
                html: `
                    <div style="text-align:left;">
                        <p style="margin-bottom:10px;">${escapeHtml(data.message || 'Este pedido ya está completado. ¿Seguro que deseas cancelarlo?')}</p>
                        ${motivo ? `<p style="margin:0; color:#64748b; font-size:13px;"><b>Motivo:</b> ${escapeHtml(motivo)}</p>` : `<p style="margin:0; color:#94a3b8; font-size:13px;">Sin motivo capturado.</p>`}
                    </div>
                `,
                icon: 'warning',
                showCancelButton: true,
                confirmButtonText: 'Sí, cancelar',
                cancelButtonText: 'No',
                confirmButtonColor: '#d33'
            }).then((result) => {
                if (result.isConfirmed) {
                    cancelarPedidoCompletado(folio, motivo);
                }
            });
        } else if (data.success) {
            const tituloExito = esPedido ? '¡Pedido cancelado!' : '¡Venta cancelada!';
            const detalleLog = data.cantidad_total_cancelada
                ? `Cantidad cancelada: ${data.cantidad_total_cancelada}`
                : 'Log guardado correctamente.';

            Swal.fire({
                icon: 'success',
                title: tituloExito,
                html: `
                    <div style="text-align:center;">
                        <p style="margin-bottom:8px;">${escapeHtml(data.message || 'Cancelación realizada correctamente.')}</p>
                        <p style="margin:0; color:#64748b; font-size:13px;">${escapeHtml(detalleLog)}</p>
                    </div>
                `,
                confirmButtonColor: '#28a745',
                timer: 2200
            }).then(() => {
                tabla.ajax.reload(null, false);
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

async function cancelarPedidoCompletado(folio, motivo = '') {
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
            forzar: true,
            motivo: motivo
        };

        const response = await fetch('api/cancelar_venta.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json'
            },
            body: JSON.stringify(requestBody)
        });

        const data = await response.json();
        Swal.close();
        resumenVentasCache.delete(folio);

        // console.log("Respuesta final:", data);

        if (data.success) {
            Swal.fire({
                icon: 'success',
                title: '¡Pedido cancelado!',
                text: data.message,
                confirmButtonColor: '#28a745',
                timer: 2200
            }).then(() => {
                tabla.ajax.reload(null, false);
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