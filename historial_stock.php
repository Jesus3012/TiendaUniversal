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

$productos_list = $conn->query("SELECT id, nombre, tipo_inventario FROM productos WHERE activo = 1 ORDER BY nombre");
$proveedores_query = "SELECT DISTINCT proveedor FROM productos WHERE proveedor IS NOT NULL AND proveedor != '' AND activo = 1 ORDER BY proveedor";
$proveedores_list = $conn->query($proveedores_query);
$fecha_actual = date('Y-m-d');
?>

<link rel="stylesheet" href="css/historial_stock.css">

<div class="content-wrapper">
    <section class="content-header">
        <div class="container-fluid">
            <div class="row mb-2">
                <div class="col-sm-6">
                    <h1>
                        <i class="fas fa-history mr-2"></i> Historial de Stock
                    </h1>
                </div>
                <div class="col-sm-6">
                    <div class="btn-group float-right">
                        <?php
                        $params = [];
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
                        $url_params = http_build_query($params);
                        ?>
                        <a href="reporte_stock_pdf.php<?= !empty($url_params) ? '?' . $url_params : '' ?>" 
                           class="btn btn-success btn-sm" target="_blank">
                            <i class="fas fa-file-pdf mr-1"></i> Reporte Stock 
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </section>
    
    <section class="content">
        <div class="container-fluid">
            <!-- ALERTA LLAMATIVA -->
            <div class="alert alert-llamativa" id="infoAlertStock" role="alert" style="display: none;">
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
                <button type="button" class="btn btn-sm btn-outline-primary" onclick="mostrarAlerta()">
                    <i class="fas fa-question-circle mr-1"></i> Ver información importante
                </button>
            </div>

            <!-- FILTROS - Selects normales (sin buscador adicional) -->
            <div class="card">
                <div class="card-header">
                    <h3 class="card-title">
                        <i class="fas fa-filter mr-2"></i> Filtros
                    </h3>
                </div>
                <div class="card-body">
                    <div class="filtros-row">
                        <div class="filtro-item">
                            <div class="form-group">
                                <label for="producto_id">
                                    <i class="fas fa-box mr-1"></i> Producto
                                </label>
                                <select name="producto_id" id="producto_id" class="form-control">
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
                        
                        <div class="filtro-item">
                            <div class="form-group">
                                <label for="proveedor">
                                    <i class="fas fa-truck mr-1"></i> Proveedor
                                </label>
                                <select name="proveedor" id="proveedor" class="form-control">
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
                        
                        <div class="filtro-item">
                            <div class="form-group">
                                <label for="fecha_desde">
                                    <i class="fas fa-calendar-alt mr-1"></i> Desde
                                </label>
                                <input type="date" name="fecha_desde" id="fecha_desde" 
                                       class="form-control" 
                                       value="<?= htmlspecialchars($fecha_desde) ?>"
                                       max="<?= $fecha_actual ?>">
                            </div>
                        </div>
                        
                        <div class="filtro-item">
                            <div class="form-group">
                                <label for="fecha_hasta">
                                    <i class="fas fa-calendar-alt mr-1"></i> Hasta
                                </label>
                                <input type="date" name="fecha_hasta" id="fecha_hasta" 
                                       class="form-control" 
                                       value="<?= htmlspecialchars($fecha_hasta) ?>"
                                       max="<?= $fecha_actual ?>">
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- FILTROS ACTIVOS CON BOTÓN LIMPIAR DENTRO -->
            <div class="filtros-activos-container" id="filtrosActivosContainer" style="<?= ($producto_id > 0 || !empty($proveedor_filtro) || !empty($fecha_desde) || !empty($fecha_hasta)) ? '' : 'display: none;' ?>">
                <div class="d-flex flex-wrap align-items-center justify-content-between">
                    <div class="d-flex flex-wrap align-items-center">
                        <span class="mr-3" style="color: #f97316; font-size: 0.8rem;">
                            <i class="fas fa-filter"></i> Filtros activos:
                        </span>
                        <span id="filtrosActivos"></span>
                    </div>
                    <button type="button" class="btn-limpiar-todos" id="btnLimpiarTodos">
                        <i class="fas fa-eraser mr-1"></i> Limpiar todos
                    </button>
                </div>
            </div>

            <!-- TABLA DE HISTORIAL -->
            <div class="card">
                <div class="card-header">
                    <div class="row align-items-center">
                        <div class="col-md-6">
                            <h3 class="card-title" id="tituloTabla" style="font-size: 1rem;">
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
                                <div class="mr-3">
                                    <div class="input-group input-group-sm" style="width: 140px;">
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
                                <span class="badge-total" id="totalRegistros">Total: 0 registros</span>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover table-sm">
                            <thead>
                                <tr>
                                    <th>Fecha</th>
                                    <th>Producto</th>
                                    <th>Proveedor</th>
                                    <th>Tipo</th>
                                    <th class="text-right">Stock Ant.</th>
                                    <th class="text-center">Cantidad</th>
                                    <th class="text-center">Stock Nuevo</th>
                                    <th>Nota</th>
                                    <th>Usuario</th>
                                </tr>
                            </thead>
                            <tbody id="tablaBodyContent">
                                <tr>
                                    <td colspan="9" class="text-center py-4">
                                        <i class="fas fa-spinner fa-spin fa-3x" style="color: #f97316;"></i>
                                        <h5 class="text-muted mt-2">Cargando datos...</h5>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
                
                <div class="card-footer" id="paginacionContainer"></div>
            </div>
        </div>
    </section>
</div>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/popper.js@1.16.1/dist/umd/popper.min.js"></script>
<script src="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/js/bootstrap.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

<script>
const STORAGE_KEY = 'historial_alerta_oculta';
let paginaActual = 1;
let timeoutFiltro = null;

function ocultarAlertaPermanente() {
    document.getElementById('infoAlertStock').style.display = 'none';
    document.getElementById('btnMostrarAlertaContainer').style.display = 'block';
    localStorage.setItem(STORAGE_KEY, 'true');
}

function mostrarAlerta() {
    document.getElementById('infoAlertStock').style.display = 'block';
    document.getElementById('btnMostrarAlertaContainer').style.display = 'none';
    localStorage.removeItem(STORAGE_KEY);
}

function cargarDatos() {
    $('#tablaBodyContent').html('<tr><td colspan="9" class="text-center py-4"><i class="fas fa-spinner fa-spin fa-3x" style="color: #f97316;"></i><h5 class="text-muted mt-2">Cargando datos...</h5></td></tr>');
    
    const producto_id = $('#producto_id').val() || '';
    const proveedor = $('#proveedor').val() || '';
    const fecha_desde = $('#fecha_desde').val() || '';
    const fecha_hasta = $('#fecha_hasta').val() || '';
    const por_pagina = $('#por_pagina').val();
    
    actualizarEnlaceReporte();
    
    let params = new URLSearchParams({
        ajax: 1,
        producto_id: producto_id,
        proveedor: proveedor,
        fecha_desde: fecha_desde,
        fecha_hasta: fecha_hasta,
        por_pagina: por_pagina,
        pagina: paginaActual
    });
    
    fetch('historial_stock_ajax.php?' + params.toString())
        .then(response => response.json())
        .then(data => {
            $('#tablaBodyContent').html(data.tabla);
            $('#paginacionContainer').html(data.paginacion);
            $('#totalRegistros').text('Total: ' + data.total_registros + ' registros');
            
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
            actualizarFiltrosActivos(data.filtros);
        })
        .catch(error => {
            console.error('Error:', error);
            $('#tablaBodyContent').html('<tr><td colspan="9" class="text-center py-4 text-danger">Error al cargar los datos</td></tr>');
        });
}

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
    if (params.length > 0) url += '?' + params.join('&');
    
    $('.btn-success[href*="reporte_stock_pdf.php"]').attr('href', url);
}

function actualizarFiltrosActivos(filtros) {
    const container = $('#filtrosActivosContainer');
    const filtrosHtml = $('#filtrosActivos');
    
    if (filtros && filtros.length > 0) {
        container.show();
        let html = '';
        filtros.forEach(filtro => {
            let icon = '';
            switch(filtro.tipo) {
                case 'producto': icon = 'fa-box'; break;
                case 'proveedor': icon = 'fa-truck'; break;
                case 'fecha_desde': icon = 'fa-calendar-alt'; break;
                case 'fecha_hasta': icon = 'fa-calendar-alt'; break;
            }
            html += `<span class="badge-filtro mr-2 mb-1">
                <i class="fas ${icon} mr-1"></i> ${filtro.texto}
                <a href="#" class="text-dark ml-2 quitar-filtro" data-tipo="${filtro.tipo}" style="opacity: 0.7;">
                    <i class="fas fa-times"></i>
                </a>
            </span>`;
        });
        filtrosHtml.html(html);
    } else {
        container.hide();
    }
}

function aplicarFiltrosEnTiempoReal() {
    if (timeoutFiltro) clearTimeout(timeoutFiltro);
    timeoutFiltro = setTimeout(() => {
        paginaActual = 1;
        cargarDatos();
    }, 500);
}

function irPagina(pagina) {
    paginaActual = pagina;
    cargarDatos();
}

$(document).ready(function() {
    const alertaOculta = localStorage.getItem(STORAGE_KEY);
    if (alertaOculta === 'true') {
        $('#infoAlertStock').hide();
        $('#btnMostrarAlertaContainer').show();
    } else {
        $('#infoAlertStock').show();
        $('#btnMostrarAlertaContainer').hide();
    }

    // Filtros en tiempo real
    $('#producto_id, #proveedor, #fecha_desde, #fecha_hasta, #por_pagina').on('change', function() {
        aplicarFiltrosEnTiempoReal();
    });
    
    // Quitar filtros individuales
    $(document).on('click', '.quitar-filtro', function(e) {
        e.preventDefault();
        const tipo = $(this).data('tipo');
        switch(tipo) {
            case 'producto': $('#producto_id').val('').trigger('change'); break;
            case 'proveedor': $('#proveedor').val('').trigger('change'); break;
            case 'fecha_desde': $('#fecha_desde').val('').trigger('change'); break;
            case 'fecha_hasta': $('#fecha_hasta').val('').trigger('change'); break;
        }
        paginaActual = 1;
        actualizarEnlaceReporte();
        cargarDatos();
    });
    
    // Limpiar todos los filtros
    $('#btnLimpiarTodos').on('click', function() {
        $('#producto_id').val('').trigger('change');
        $('#proveedor').val('').trigger('change');
        $('#fecha_desde').val('');
        $('#fecha_hasta').val('');
        $('#por_pagina').val('25');
        paginaActual = 1;
        actualizarEnlaceReporte();
        cargarDatos();
    });
    
    // Paginación
    $(document).on('click', '.page-link', function(e) {
        e.preventDefault();
        const onclickAttr = $(this).attr('onclick');
        if (onclickAttr) {
            const match = onclickAttr.match(/irPagina\((\d+)\)/);
            if (match) irPagina(parseInt(match[1]));
        }
    });
    
    cargarDatos();
});
</script>

<?php include 'includes/footer.php'; ?>