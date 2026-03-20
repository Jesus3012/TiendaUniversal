<?php
ob_start();
session_start();
date_default_timezone_set('America/Mexico_City');

require_once 'includes/db.php';
require_once 'includes/csrf.php';

if (!isset($_SESSION['usuario_id']) || $_SESSION['rol'] !== 'administrador') {
    header("Location: login.php");
    exit;
}

include 'includes/header.php';
include 'includes/navbar.php';

// Configuración de paginación
$registros_por_pagina = isset($_GET['por_pagina']) ? $_GET['por_pagina'] : 25;
$pagina_actual = isset($_GET['pagina']) ? intval($_GET['pagina']) : 1;

// Validar valores permitidos para registros por página
$opciones_por_pagina = [25, 50, 100, 250, 500, 'todos'];
if (!in_array($registros_por_pagina, $opciones_por_pagina)) {
    $registros_por_pagina = 25;
}

$producto_id = isset($_GET['producto_id']) ? intval($_GET['producto_id']) : 0;
$proveedor_filtro = isset($_GET['proveedor']) ? trim($_GET['proveedor']) : '';
$fecha_desde = isset($_GET['fecha_desde']) ? $_GET['fecha_desde'] : '';
$fecha_hasta = isset($_GET['fecha_hasta']) ? $_GET['fecha_hasta'] : '';

$producto_nombre = '';

if ($producto_id > 0) {
    $stmt = $conn->prepare("SELECT nombre, tipo_inventario FROM productos WHERE id = ?");
    $stmt->bind_param("i", $producto_id);
    $stmt->execute();
    $res = $stmt->get_result();
    $producto = $res->fetch_assoc();
    $producto_nombre = $producto['nombre'] ?? '';
}

// Obtener productos para el filtro
$productos_list = $conn->query("SELECT id, nombre, tipo_inventario FROM productos WHERE activo = 1 ORDER BY nombre");

// Obtener proveedores únicos para el filtro
$proveedores_query = "SELECT DISTINCT proveedor FROM productos WHERE proveedor IS NOT NULL AND proveedor != '' AND activo = 1 ORDER BY proveedor";
$proveedores_list = $conn->query($proveedores_query);

// Obtener la fecha actual para el máximo en los inputs de fecha
$fecha_actual = date('Y-m-d');
?>

<div class="content-wrapper">
    <section class="content-header">
        <div class="container-fluid">
            <div class="row mb-2">
                <div class="col-sm-6">
                    <h1>
                        Historial de Stock
                    </h1>
                </div>
                <div class="col-sm-6">
                    <div class="btn-group float-right">
                        <?php
                        // Obtener los filtros actuales de la URL (GET)
                        $params = [];
                        
                        // Solo agregar filtros que tengan valor
                        if (isset($_GET['producto_id']) && $_GET['producto_id'] != '') {
                            $params['producto_id'] = $_GET['producto_id'];
                        }
                        if (isset($_GET['proveedor']) && $_GET['proveedor'] != '') {
                            $params['proveedor'] = $_GET['proveedor'];
                        }
                        if (isset($_GET['fecha_desde']) && $_GET['fecha_desde'] != '') {
                            $params['fecha_desde'] = $_GET['fecha_desde'];
                        }
                        if (isset($_GET['fecha_hasta']) && $_GET['fecha_hasta'] != '') {
                            $params['fecha_hasta'] = $_GET['fecha_hasta'];
                        }
                        
                        // Construir URL con los filtros
                        $url_params = http_build_query($params);
                        ?>
                        <a href="reporte_stock_pdf.php<?= !empty($url_params) ? '?' . $url_params : '' ?>" 
                           class="btn btn-success" target="_blank">
                            <i class="fas fa-file-pdf mr-1"></i> Reporte Stock 
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </section>
    
    <section class="content">
        <div class="container-fluid">
            <!-- ALERTA INFORMATIVA -->
            <div class="alert alert-info" id="infoAlertStock" role="alert" style="display: none;">
                <div class="d-flex align-items-center">
                    <i class="fas fa-info-circle fa-2x mr-3"></i>
                    <div class="flex-grow-1">
                        <h5 class="alert-heading mb-2">Información del Historial</h5>
                        <p class="mb-1"><i class="fas fa-check-circle mr-2"></i> Los ajustes se muestran con valores negativos en rojo.</p>
                        <p class="mb-1"><i class="fas fa-check-circle mr-2"></i> Puedes filtrar por rango de fechas (desde/hasta) o fecha específica.</p>
                        <p class="mb-0"><i class="fas fa-check-circle mr-2"></i> Los colores indican el tipo de movimiento: verde (entrada), rojo (ajuste).</p>
                    </div>
                    <button type="button" class="close ml-3" onclick="ocultarAlertaPermanente()" aria-label="Close">
                        <span aria-hidden="true">&times;</span>
                    </button>
                </div>
            </div>

            <!-- BOTÓN PARA MOSTRAR ALERTA -->
            <div class="text-center mb-3" id="btnMostrarAlertaContainer" style="display: none;">
                <button type="button" class="btn btn-sm btn-outline-info" onclick="mostrarAlerta()">
                    <i class="fas fa-question-circle mr-1"></i> Ver información importante
                </button>
            </div>

            <!-- Filtros con buscadores -->
            <div class="card">
                <div class="card-header">
                    <h3 class="card-title">
                        <i class="fas fa-filter mr-2"></i>Filtros
                    </h3>
                    <div class="card-tools">
                        <small class="text-muted">Haz clic en los selects y escribe para buscar</small>
                    </div>
                </div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-3">
                            <div class="form-group">
                                <label for="producto_id" class="control-label">
                                    <i class="fas fa-box mr-1"></i> Producto:
                                </label>
                                <select name="producto_id" id="producto_id" class="form-control select2-buscador" style="width: 100%;">
                                    <option value="">Todos los productos</option>
                                    <?php 
                                    $productos_list->data_seek(0);
                                    while ($p = $productos_list->fetch_assoc()): 
                                    ?>
                                    <option value="<?= $p['id'] ?>" <?= $producto_id == $p['id'] ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($p['nombre']) ?> (<?= $p['tipo_inventario'] ?>)
                                    </option>
                                    <?php endwhile; ?>
                                </select>
                            </div>
                        </div>
                        
                        <div class="col-md-3">
                            <div class="form-group">
                                <label for="proveedor" class="control-label">
                                    <i class="fas fa-truck mr-1"></i> Proveedor:
                                </label>
                                <select name="proveedor" id="proveedor" class="form-control select2-buscador" style="width: 100%;">
                                    <option value="">Todos los proveedores</option>
                                    <?php 
                                    $proveedores_list->data_seek(0);
                                    while ($prov = $proveedores_list->fetch_assoc()): 
                                        if (!empty($prov['proveedor'])): 
                                    ?>
                                    <option value="<?= htmlspecialchars($prov['proveedor']) ?>" <?= $proveedor_filtro == $prov['proveedor'] ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($prov['proveedor']) ?>
                                    </option>
                                    <?php 
                                        endif;
                                    endwhile; 
                                    ?>
                                </select>
                            </div>
                        </div>
                        
                        <div class="col-md-2">
                            <div class="form-group">
                                <label for="fecha_desde" class="control-label">
                                    <i class="fas fa-calendar-alt mr-1"></i> Fecha desde:
                                </label>
                                <input type="date" name="fecha_desde" id="fecha_desde" 
                                       class="form-control" 
                                       value="<?= htmlspecialchars($fecha_desde) ?>"
                                       max="<?= $fecha_actual ?>">
                            </div>
                        </div>
                        
                        <div class="col-md-2">
                            <div class="form-group">
                                <label for="fecha_hasta" class="control-label">
                                    <i class="fas fa-calendar-alt mr-1"></i> Fecha hasta:
                                </label>
                                <input type="date" name="fecha_hasta" id="fecha_hasta" 
                                       class="form-control" 
                                       value="<?= htmlspecialchars($fecha_hasta) ?>"
                                       max="<?= $fecha_actual ?>">
                            </div>
                        </div>
                        
                        <div class="col-md-2">
                            <div class="form-group">
                                <label class="control-label d-block">&nbsp;</label>
                                <button type="button" class="btn btn-primary btn-block" id="btnFiltrar">
                                    <i class="fas fa-filter mr-1"></i> Filtrar
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- FILTROS ACTIVOS -->
            <div class="row mb-3" id="filtrosActivosContainer" style="<?= ($producto_id > 0 || !empty($proveedor_filtro) || !empty($fecha_desde) || !empty($fecha_hasta)) ? '' : 'display: none;' ?>">
                <div class="col-12">
                    <div class="card card-outline card-primary">
                        <div class="card-body py-2">
                            <div class="d-flex flex-wrap align-items-center">
                                <span class="mr-3 text-primary">
                                    <i class="fas fa-filter"></i> Filtros activos:
                                </span>
                                
                                <span id="filtrosActivos"></span>
                                
                                <button type="button" class="btn btn-sm btn-outline-danger ml-auto" id="btnLimpiarTodos">
                                    <i class="fas fa-eraser mr-1"></i> Limpiar todos
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- TABLA DE HISTORIAL -->
            <div class="card" id="tablaContainer">
                <div class="card-header">
                    <div class="row align-items-center">
                        <div class="col-md-6">
                            <h3 class="card-title" id="tituloTabla">
                                <?php if ($producto_nombre): ?>
                                    Historial de: <strong><?= htmlspecialchars($producto_nombre) ?></strong>
                                <?php else: ?>
                                    Todos los movimientos
                                <?php endif; ?>
                                <?php if (!empty($proveedor_filtro)): ?>
                                    <small class="ml-2 text-muted">(Proveedor: <?= htmlspecialchars($proveedor_filtro) ?>)</small>
                                <?php endif; ?>
                            </h3>
                        </div>
                        <div class="col-md-6">
                            <div class="d-flex justify-content-end align-items-center">
                                <!-- Control de registros por página -->
                                <div class="mr-3">
                                    <div class="input-group input-group-sm" style="width: 150px;">
                                        <div class="input-group-prepend">
                                            <span class="input-group-text">Mostrar</span>
                                        </div>
                                        <select name="por_pagina" id="por_pagina" class="form-control form-control-sm">
                                            <option value="25" <?= $registros_por_pagina == 25 ? 'selected' : '' ?>>25</option>
                                            <option value="50" <?= $registros_por_pagina == 50 ? 'selected' : '' ?>>50</option>
                                            <option value="100" <?= $registros_por_pagina == 100 ? 'selected' : '' ?>>100</option>
                                            <option value="250" <?= $registros_por_pagina == 250 ? 'selected' : '' ?>>250</option>
                                            <option value="500" <?= $registros_por_pagina == 500 ? 'selected' : '' ?>>500</option>
                                            <option value="todos" <?= $registros_por_pagina === 'todos' ? 'selected' : '' ?>>Todos</option>
                                        </select>
                                    </div>
                                </div>
                                <span class="badge badge-info" id="totalRegistros">Total: <?= number_format(0) ?> registros</span>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="card-body p-0" id="tablaBody">
                    <div class="table-responsive">
                        <table class="table table-hover table-striped table-sm">
                            <thead class="bg-secondary">
                                <tr>
                                    <th>Fecha</th>
                                    <th>Producto</th>
                                    <th>Proveedor</th>
                                    <th>Tipo</th>
                                    <th class="text-right">Stock Anterior</th>
                                    <th class="text-center">Agregado / Ajuste</th>
                                    <th class="text-center">Stock Nuevo</th>
                                    <th>Nota</th>
                                    <th>Usuario</th>
                                </tr>
                            </thead>
                            <tbody id="tablaBodyContent">
                                <!-- Aquí se cargarán los datos vía AJAX -->
                                <tr>
                                    <td colspan="9" class="text-center py-4">
                                        <i class="fas fa-spinner fa-spin fa-3x text-primary mb-3"></i>
                                        <h5 class="text-muted">Cargando datos...</h5>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
                
                <div class="card-footer" id="paginacionContainer">
                    <!-- Aquí se cargará la paginación vía AJAX -->
                </div>
            </div>
        </div>
    </section>
</div>

<!-- PRIMERO jQuery, luego Popper, luego Bootstrap, luego Select2 -->
<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/popper.js@1.16.1/dist/umd/popper.min.js"></script>
<script src="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/js/bootstrap.min.js"></script>

<!-- Select2 CSS y JS -->
<link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
<link href="https://cdn.jsdelivr.net/npm/select2-bootstrap4-theme@1.0.0/dist/select2-bootstrap4.min.css" rel="stylesheet" />
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>

<!-- SweetAlert2 CSS y JS -->
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css">
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

<!-- SCRIPT PRINCIPAL -->
<script>
// Constante para localStorage
const STORAGE_KEY = 'historial_alerta_oculta';

// Función para ocultar alerta permanentemente
function ocultarAlertaPermanente() {
    document.getElementById('infoAlertStock').style.display = 'none';
    document.getElementById('btnMostrarAlertaContainer').style.display = 'block';
    localStorage.setItem(STORAGE_KEY, 'true');
}

// Función para mostrar alerta
function mostrarAlerta() {
    document.getElementById('infoAlertStock').style.display = 'block';
    document.getElementById('btnMostrarAlertaContainer').style.display = 'none';
    localStorage.removeItem(STORAGE_KEY);
}

// Variable para la página actual
let paginaActual = 1;

// Función para cargar datos vía AJAX
function cargarDatos() {
    // Mostrar loading
    $('#tablaBodyContent').html('<tr><td colspan="9" class="text-center py-4"><i class="fas fa-spinner fa-spin fa-3x text-primary mb-3"></i><h5 class="text-muted">Cargando datos...</h5></td></tr>');
    
    // Obtener valores de los filtros
    const producto_id = $('#producto_id').val() || '';
    const proveedor = $('#proveedor').val() || '';
    const fecha_desde = $('#fecha_desde').val() || '';
    const fecha_hasta = $('#fecha_hasta').val() || '';
    const por_pagina = $('#por_pagina').val();
    
    // Validar fechas (si solo hay una fecha, la otra se deja vacía)
    let params_fecha_desde = fecha_desde;
    let params_fecha_hasta = fecha_hasta;
    
    // ACTUALIZAR EL ENLACE DEL REPORTE
    actualizarEnlaceReporte();
    
    // Construir URL con parámetros
    let params = new URLSearchParams({
        ajax: 1,
        producto_id: producto_id,
        proveedor: proveedor,
        fecha_desde: params_fecha_desde,
        fecha_hasta: params_fecha_hasta,
        por_pagina: por_pagina,
        pagina: paginaActual
    });
    
    // Hacer petición AJAX
    fetch('historial_stock_ajax.php?' + params.toString())
        .then(response => response.json())
        .then(data => {
            // Actualizar tabla
            $('#tablaBodyContent').html(data.tabla);
            
            // Actualizar paginación
            $('#paginacionContainer').html(data.paginacion);
            
            // Actualizar total de registros
            $('#totalRegistros').text('Total: ' + data.total_registros + ' registros');
            
            // Actualizar título de la tabla
            let titulo = '';
            if (data.producto_nombre) {
                titulo = 'Historial de: <strong>' + data.producto_nombre + '</strong>';
            } else {
                titulo = 'Todos los movimientos';
            }
            if (data.proveedor_filtro) {
                titulo += ' <small class="ml-2 text-muted">(Proveedor: ' + data.proveedor_filtro + ')</small>';
            }
            $('#tituloTabla').html(titulo);
            
            // Actualizar filtros activos
            actualizarFiltrosActivos(data.filtros);
        })
        .catch(error => {
            console.error('Error:', error);
            $('#tablaBodyContent').html('<tr><td colspan="9" class="text-center py-4 text-danger">Error al cargar los datos</td></tr>');
        });
}

// Función para actualizar el enlace del reporte PDF
function actualizarEnlaceReporte() {
    const producto_id = $('#producto_id').val() || '';
    const proveedor = $('#proveedor').val() || '';
    const fecha_desde = $('#fecha_desde').val() || '';
    const fecha_hasta = $('#fecha_hasta').val() || '';
    
    let params = [];
    if (producto_id) params.push('producto_id=' + encodeURIComponent(producto_id));
    if (proveedor) params.push('proveedor=' + encodeURIComponent(proveedor));
    if (fecha_desde) params.push('fecha_desde=' + encodeURIComponent(fecha_desde));
    if (fecha_hasta) params.push('fecha_hasta=' + encodeURIComponent(fecha_hasta));
    
    let url = 'reporte_stock_pdf.php';
    if (params.length > 0) {
        url += '?' + params.join('&');
    }
    
    // Actualizar el enlace del botón de reporte
    $('.btn-success[href*="reporte_stock_pdf.php"]').attr('href', url);
}

// Función para validar fechas con SweetAlert2
function validarFechas() {
    const fecha_desde = $('#fecha_desde').val();
    const fecha_hasta = $('#fecha_hasta').val();
    const fecha_actual = new Date().toISOString().split('T')[0];
    
    // Validar que no sean fechas futuras
    if (fecha_desde && fecha_desde > fecha_actual) {
        Swal.fire({
            icon: 'error',
            title: 'Fecha inválida',
            text: 'La fecha desde no puede ser mayor a hoy',
            confirmButtonColor: '#3085d6',
            confirmButtonText: 'Entendido'
        });
        $('#fecha_desde').val('');
        return false;
    }
    
    if (fecha_hasta && fecha_hasta > fecha_actual) {
        Swal.fire({
            icon: 'error',
            title: 'Fecha inválida',
            text: 'La fecha hasta no puede ser mayor a hoy',
            confirmButtonColor: '#3085d6',
            confirmButtonText: 'Entendido'
        });
        $('#fecha_hasta').val('');
        return false;
    }
    
    // Validar que fecha_desde no sea mayor que fecha_hasta (si ambas existen)
    if (fecha_desde && fecha_hasta && fecha_desde > fecha_hasta) {
        Swal.fire({
            icon: 'warning',
            title: 'Rango de fechas incorrecto',
            text: 'La fecha desde no puede ser mayor que la fecha hasta',
            confirmButtonColor: '#3085d6',
            confirmButtonText: 'Corregir'
        });
        $('#fecha_desde').val('');
        $('#fecha_hasta').val('');
        return false;
    }
    
    return true;
}

// Función para actualizar los filtros activos
function actualizarFiltrosActivos(filtros) {
    const container = $('#filtrosActivosContainer');
    const filtrosHtml = $('#filtrosActivos');
    
    if (filtros.length > 0) {
        container.show();
        let html = '';
        
        filtros.forEach(filtro => {
            let colorClass = '';
            let icon = '';
            
            switch(filtro.tipo) {
                case 'producto':
                    colorClass = 'badge-primary';
                    icon = 'fa-box';
                    break;
                case 'proveedor':
                    colorClass = 'badge-info';
                    icon = 'fa-truck';
                    break;
                case 'fecha_desde':
                case 'fecha_hasta':
                    colorClass = 'badge-success';
                    icon = 'fa-calendar-alt';
                    break;
            }
            
            html += `<span class="badge ${colorClass} mr-2 mb-1 p-2">
                <i class="fas ${icon} mr-1"></i> ${filtro.texto}
                <a href="#" class="text-white ml-2 quitar-filtro" data-tipo="${filtro.tipo}">
                    <i class="fas fa-times"></i>
                </a>
            </span>`;
        });
        
        filtrosHtml.html(html);
    } else {
        container.hide();
    }
}

// Función para ir a una página específica
function irPagina(pagina) {
    paginaActual = pagina;
    cargarDatos();
}

// Inicializar cuando el documento esté listo
$(document).ready(function() {
    // Inicializar Select2
    $('.select2-buscador').select2({
        theme: 'bootstrap4',
        width: '100%',
        placeholder: 'Selecciona una opción',
        allowClear: true,
        language: {
            noResults: function() {
                return "No se encontraron resultados";
            }
        }
    });
    
    // Verificar estado de la alerta
    const alertaOculta = localStorage.getItem(STORAGE_KEY);
    
    if (alertaOculta === 'true') {
        document.getElementById('infoAlertStock').style.display = 'none';
        document.getElementById('btnMostrarAlertaContainer').style.display = 'block';
    } else {
        document.getElementById('infoAlertStock').style.display = 'block';
        document.getElementById('btnMostrarAlertaContainer').style.display = 'none';
    }

    // Actualizar enlace cuando cambian los filtros
    $('#producto_id, #proveedor, #fecha_desde, #fecha_hasta').on('change', function() {
        if (validarFechas()) {
            actualizarEnlaceReporte();
        }
    });

    // Evento para aplicar filtros
    $('#btnFiltrar').on('click', function() {
        if (validarFechas()) {
            paginaActual = 1;
            cargarDatos();
        }
    });
    
    // Evento para cambiar cantidad de registros por página
    $('#por_pagina').on('change', function() {
        paginaActual = 1;
        cargarDatos();
    });
    
   // Evento para quitar filtros individuales
    $(document).on('click', '.quitar-filtro', function(e) {
        e.preventDefault();
        const tipo = $(this).data('tipo');
        
        switch(tipo) {
            case 'producto':
                $('#producto_id').val('').trigger('change');
                break;
            case 'proveedor':
                $('#proveedor').val('').trigger('change');
                break;
            case 'fecha_desde':
                $('#fecha_desde').val('');
                break;
            case 'fecha_hasta':
                $('#fecha_hasta').val('');
                break;
        }
        
        paginaActual = 1;
        actualizarEnlaceReporte(); // ACTUALIZAR ENLACE
        cargarDatos();
    });

    // Evento para limpiar todos los filtros
    $('#btnLimpiarTodos').on('click', function() {
        $('#producto_id').val('').trigger('change');
        $('#proveedor').val('').trigger('change');
        $('#fecha_desde').val('');
        $('#fecha_hasta').val('');
        $('#por_pagina').val('25');
        
        paginaActual = 1;
        actualizarEnlaceReporte(); // ACTUALIZAR ENLACE
        cargarDatos();
    });
        
    // Evento para paginación (delegación de eventos)
    $(document).on('click', '.page-link', function(e) {
        e.preventDefault();
        const onclickAttr = $(this).attr('onclick');
        if (onclickAttr) {
            const match = onclickAttr.match(/irPagina\((\d+)\)/);
            if (match) {
                irPagina(parseInt(match[1]));
            }
        }
    });
    
    // Cargar datos iniciales
    cargarDatos();
});
</script>

<style>
/* ESTILOS ORIGINALES QUE YA TENÍAS */
.badge-producto {
    background-color: #e3f2fd;
    color: #0d47a1;
    padding: 3px 8px;
    border-radius: 12px;
    font-size: 0.7rem;
    margin-left: 5px;
}

.badge-insumo {
    background-color: #e5f5e5;
    color: #148c20;
    padding: 3px 8px;
    border-radius: 12px;
    font-size: 0.7rem;
    margin-left: 5px;
}

/* Estilos para la tabla */
.table td {
    vertical-align: middle;
}

.text-success {
    color: #28a745 !important;
    font-weight: 600;
}

.text-danger {
    color: #dc3545 !important;
    font-weight: 600;
}

/* Estilos para la alerta */
#infoAlertStock {
    transition: all 0.3s ease;
    margin-bottom: 1rem;
}

#btnMostrarAlertaContainer {
    transition: all 0.3s ease;
    margin-bottom: 1rem;
}

#btnMostrarAlertaContainer .btn {
    border-radius: 20px;
    padding: 0.25rem 1rem;
    font-size: 0.9rem;
}

.close {
    opacity: 0.7;
    transition: opacity 0.2s;
    cursor: pointer;
    font-size: 1.5rem;
}

.close:hover {
    opacity: 1;
}

/* Estilos básicos para Select2 */
.select2-container--bootstrap4 .select2-selection--single {
    height: calc(1.5em + 0.75rem + 2px);
}

/* Estilos para la paginación */
.pagination {
    margin: 0;
}

.page-link {
    padding: 0.375rem 0.75rem;
    font-size: 0.875rem;
    line-height: 1.5;
    cursor: pointer;
}

.page-item.active .page-link {
    background-color: #007bff;
    border-color: #007bff;
}

/* Control de registros por página */
.input-group-sm .form-control {
    height: calc(1.5em + 0.5rem + 2px);
    padding: 0.25rem 0.5rem;
    font-size: 0.875rem;
}

.input-group-text {
    font-size: 0.875rem;
}

/* Estilos para los badges de filtros activos */
.badge {
    font-size: 0.9rem;
    font-weight: normal;
    padding: 0.5rem 0.75rem;
}

.badge a {
    text-decoration: none;
    opacity: 0.8;
}

.badge a:hover {
    opacity: 1;
}

.badge-primary {
    background-color: #007bff;
}

.badge-info {
    background-color: #17a2b8;
}

.badge-success {
    background-color: #28a745;
}

/* Botón limpiar todos */
.btn-outline-danger {
    border-color: #dc3545;
    color: #dc3545;
}

.btn-outline-danger:hover {
    background-color: #dc3545;
    color: white;
}

/* Botón filtrar */
.btn-primary {
    background-color: #007bff;
    border-color: #007bff;
}

.btn-primary:hover {
    background-color: #0069d9;
    border-color: #0062cc;
}

/* Responsive */
@media (max-width: 768px) {
    .d-flex.justify-content-end {
        justify-content: flex-start !important;
        margin-top: 0.5rem;
    }
    
    .mr-3 {
        margin-right: 0 !important;
        margin-bottom: 0.5rem;
    }
    
    .table td, .table th {
        font-size: 0.85rem;
    }
    
    .badge {
        font-size: 0.8rem;
        padding: 0.3rem 0.5rem;
    }
}
</style>