<?php
date_default_timezone_set('America/Mexico_City');
session_start();
include 'includes/db.php';
include 'includes/header.php';
include 'includes/navbar.php';

if (!isset($_SESSION['usuario_id'])) {
    header("Location: login.php");
    exit;
}

// ===== OBTENER ARCHIVOS DE LAS 4 CARPETAS =====
$carpetas = [
    'ventas_generales' => 'uploads/Ventas_Generales',
    'ventas_proveedor' => 'uploads/Ventas_Proveedor',
    'stock_general' => 'uploads/Stock_General',
    'stock_proveedor' => 'uploads/Stock_Proveedor'
];

// Crear carpetas si no existen
foreach ($carpetas as $ruta) {
    if (!is_dir($ruta)) {
        mkdir($ruta, 0777, true);
    }
}

$todos_los_archivos = [];

// Obtener todos los registros de la BD
$query_bd = "SELECT nombre_archivo, proveedor, fecha_generacion, usuario_nombre, modulo, total_registros FROM historial_reportes";
$result_bd = $conn->query($query_bd);
$info_bd = [];
while ($row = $result_bd->fetch_assoc()) {
    $info_bd[$row['nombre_archivo']] = [
        'proveedor' => $row['proveedor'],
        'fecha_bd' => strtotime($row['fecha_generacion']),
        'usuario' => $row['usuario_nombre'],
        'modulo' => $row['modulo'],
        'total_registros' => $row['total_registros']
    ];
}

foreach ($carpetas as $nombre_carpeta => $ruta_carpeta) {
    if (file_exists($ruta_carpeta)) {
        $archivos = scandir($ruta_carpeta);
        foreach ($archivos as $archivo) {
            if ($archivo != '.' && $archivo != '..' && !is_dir($ruta_carpeta . '/' . $archivo)) {
                $ruta_completa = $ruta_carpeta . '/' . $archivo;
                $fecha_modificacion = filemtime($ruta_completa);
                $tamaño = filesize($ruta_completa);
                $extension = strtolower(pathinfo($archivo, PATHINFO_EXTENSION));
                $tipo = ($extension == 'pdf') ? 'pdf' : 'excel';
                
                $proveedor_detectado = 'N/A';
                $usuario_genero = 'Sistema';
                $total_registros_reporte = 0;
                
                // Buscar en BD
                if (isset($info_bd[$ruta_completa])) {
                    $proveedor_detectado = $info_bd[$ruta_completa]['proveedor'] ?? 'N/A';
                    $usuario_genero = $info_bd[$ruta_completa]['usuario'] ?? 'Sistema';
                    $total_registros_reporte = $info_bd[$ruta_completa]['total_registros'] ?? 0;
                } elseif (isset($info_bd[$archivo])) {
                    $proveedor_detectado = $info_bd[$archivo]['proveedor'] ?? 'N/A';
                    $usuario_genero = $info_bd[$archivo]['usuario'] ?? 'Sistema';
                    $total_registros_reporte = $info_bd[$archivo]['total_registros'] ?? 0;
                }
                
                // Si el proveedor es 'todos' o está vacío, extraer del nombre
                if ($proveedor_detectado === 'todos' || $proveedor_detectado === 'N/A' || empty($proveedor_detectado)) {
                    if (preg_match('/reporte_inventario_([^_]+)_(\d{4}-\d{2}-\d{2})\.pdf$/', $archivo, $matches)) {
                        $proveedor_detectado = ucwords(str_replace('_', ' ', $matches[1]));
                    } elseif (preg_match('/reporte_proveedor_([^_]+)_(\d{4}-\d{2}-\d{2})\.pdf$/', $archivo, $matches)) {
                        $proveedor_detectado = ucwords(str_replace('_', ' ', $matches[1]));
                    } elseif (preg_match('/reporte_admin_(\d{4}-\d{2}-\d{2})\.pdf$/', $archivo, $matches)) {
                        $proveedor_detectado = 'Ventas Generales';
                    } elseif (preg_match('/reporte_inventario_(\d{4}-\d{2}-\d{2})\.pdf$/', $archivo, $matches)) {
                        $proveedor_detectado = 'Stock General';
                    } else {
                        switch($nombre_carpeta) {
                            case 'ventas_generales': $proveedor_detectado = 'Ventas Generales'; break;
                            case 'ventas_proveedor': $proveedor_detectado = 'Ventas por Proveedor'; break;
                            case 'stock_general': $proveedor_detectado = 'Stock General'; break;
                            case 'stock_proveedor': $proveedor_detectado = 'Stock por Proveedor'; break;
                        }
                    }
                }
                
                $todos_los_archivos[] = [
                    'nombre' => $archivo,
                    'ruta' => $ruta_completa,
                    'carpeta' => $nombre_carpeta,
                    'fecha' => $fecha_modificacion,
                    'tamaño' => $tamaño,
                    'tipo' => $tipo,
                    'proveedor' => $proveedor_detectado,
                    'usuario' => $usuario_genero,
                    'total_registros' => $total_registros_reporte
                ];
            }
        }
    }
}

// Ordenar por fecha
usort($todos_los_archivos, function($a, $b) {
    return $b['fecha'] - $a['fecha'];
});

// Calcular totales
$total_excel = count(array_filter($todos_los_archivos, function($a) { return $a['tipo'] == 'excel'; }));
$total_pdf = count(array_filter($todos_los_archivos, function($a) { return $a['tipo'] == 'pdf'; }));
$total_bytes = array_sum(array_column($todos_los_archivos, 'tamaño'));
$archivos_hoy = count(array_filter($todos_los_archivos, function($a) { return date('Y-m-d', $a['fecha']) == date('Y-m-d'); }));
$archivos_semana = count(array_filter($todos_los_archivos, function($a) { return $a['fecha'] >= strtotime('-7 days'); }));

// Estadísticas para gráfica
$fechas_grafica = [];
$conteos_grafica = [];
for ($i = 6; $i >= 0; $i--) {
    $fecha = date('Y-m-d', strtotime("-$i days"));
    $fechas_grafica[] = date('d/m', strtotime($fecha));
    $fecha_inicio = strtotime($fecha);
    $fecha_fin = strtotime($fecha . ' 23:59:59');
    $conteo = count(array_filter($todos_los_archivos, function($a) use ($fecha_inicio, $fecha_fin) {
        return $a['fecha'] >= $fecha_inicio && $a['fecha'] <= $fecha_fin;
    }));
    $conteos_grafica[] = $conteo;
}
?>

<link rel="stylesheet" href="css/historial_reportes.css">

<style>
/* SIN SCROLL - La tabla ocupa todo el ancho disponible */
.table-responsive {
    overflow-x: visible !important;
    overflow-y: visible !important;
}

.table {
    width: 100%;
    margin-bottom: 0;
    table-layout: auto;
}

.table th, .table td {
    padding: 8px 8px;
    vertical-align: middle;
    word-break: break-word;
}

/* Anchos relativos para cada columna */
.table th:nth-child(1), .table td:nth-child(1) { width: 8%; }  /* Tipo */
.table th:nth-child(2), .table td:nth-child(2) { width: 22%; } /* Nombre */
.table th:nth-child(3), .table td:nth-child(3) { width: 12%; } /* Carpeta */
.table th:nth-child(4), .table td:nth-child(4) { width: 15%; } /* Proveedor */
.table th:nth-child(5), .table td:nth-child(5) { width: 10%; } /* Usuario */
.table th:nth-child(6), .table td:nth-child(6) { width: 18%; } /* Fecha */
.table th:nth-child(7), .table td:nth-child(7) { width: 8%; }  /* Tamaño */
.table th:nth-child(8), .table td:nth-child(8) { width: 7%; }  /* Acciones */

/* Badges con colores para PDF y Excel */
.badge-excel {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    padding: 5px 10px;
    border-radius: 20px;
    font-size: 12px;
    font-weight: 600;
    background: #e8f5e9;
    color: #2e7d32;
    white-space: nowrap;
}

.badge-pdf {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    padding: 5px 10px;
    border-radius: 20px;
    font-size: 12px;
    font-weight: 600;
    background: #ffebee;
    color: #c62828;
    white-space: nowrap;
}

.badge-excel i, .badge-pdf i {
    margin-right: 5px;
    font-size: 13px;
}

.carpetas-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 20px;
    margin-bottom: 30px;
}

.carpeta-card {
    cursor: pointer;
    transition: all 0.3s;
    background: white;
    border-radius: 12px;
    padding: 20px;
    text-align: center;
    box-shadow: 0 1px 3px rgba(0,0,0,0.1);
    border: 1px solid #e0e0e0;
}

.carpeta-card.active {
    border-color: #f97316;
    background: #fff7ed;
}

.carpeta-icon {
    font-size: 32px;
    color: #f97316;
    margin-bottom: 10px;
}

.carpeta-card h4 {
    font-size: 16px;
    margin: 10px 0;
    font-weight: 600;
}

.carpeta-count {
    color: #666;
    font-size: 13px;
    margin: 0;
}

.btn-ver, .btn-descargar {
    padding: 5px 8px;
    margin: 0 2px;
    border-radius: 4px;
    font-size: 12px;
}

/* Responsive: en pantallas pequeñas, ajustar tamaños */
@media (max-width: 992px) {
    .table th, .table td {
        padding: 6px 4px;
        font-size: 12px;
    }
    
    .badge-excel, .badge-pdf {
        padding: 3px 6px;
        font-size: 10px;
    }
    
    .badge-excel i, .badge-pdf i {
        font-size: 10px;
    }
    
    .btn-ver, .btn-descargar {
        padding: 3px 5px;
        font-size: 10px;
    }
}
</style>

<div class="content-wrapper">
    <div class="container-fluid">
        
        <div class="section-header">
            <div class="section-title">
                <i class="fas fa-history"></i>
                <span>Historial de Reportes</span>
            </div>
            <div class="section-divider"></div>
            <p class="text-muted mt-2 mb-0">Gestiona y visualiza todos los reportes generados en el sistema</p>
        </div>

        <!-- Tarjetas de estadísticas -->
        <div class="row">
            <div class="col-lg-3 col-6">
                <div class="small-box bg-primary-custom">
                    <div class="inner">
                        <h3 id="totalArchivos"><?= count($todos_los_archivos) ?></h3>
                        <p>Total Archivos</p>
                    </div>
                    <div class="icon">
                        <i class="fas fa-file-alt"></i>
                    </div>
                </div>
            </div>
            <div class="col-lg-3 col-6">
                <div class="small-box bg-success-custom">
                    <div class="inner">
                        <h3 id="totalExcel"><?= $total_excel ?></h3>
                        <p>Archivos Excel</p>
                    </div>
                    <div class="icon">
                        <i class="fas fa-file-excel"></i>
                    </div>
                </div>
            </div>
            <div class="col-lg-3 col-6">
                <div class="small-box bg-danger-custom">
                    <div class="inner">
                        <h3 id="totalPdf"><?= $total_pdf ?></h3>
                        <p>Archivos PDF</p>
                    </div>
                    <div class="icon">
                        <i class="fas fa-file-pdf"></i>
                    </div>
                </div>
            </div>
            <div class="col-lg-3 col-6">
                <div class="small-box bg-info-custom">
                    <div class="inner">
                        <h3><?= number_format($total_bytes / 1024 / 1024, 2) ?> MB</h3>
                        <p>Espacio Total</p>
                    </div>
                    <div class="icon">
                        <i class="fas fa-hdd"></i>
                    </div>
                </div>
            </div>
        </div>

        <br>
        
        <!-- Filtros -->
        <div class="card-filtros">
            <div class="card-header">
                <h3 class="card-title">
                    <i class="fas fa-filter"></i> Filtros de búsqueda
                </h3>
            </div>
            <div class="card-body">
                <div class="row">
                    <div class="col-md-3 mb-3">
                        <label class="form-label"><i class="fas fa-calendar"></i> Desde</label>
                        <input type="date" id="fecha_desde" class="form-control" onchange="aplicarFiltros()">
                    </div>
                    <div class="col-md-3 mb-3">
                        <label class="form-label"><i class="fas fa-calendar"></i> Hasta</label>
                        <input type="date" id="fecha_hasta" class="form-control" onchange="aplicarFiltros()">
                    </div>
                    <div class="col-md-3 mb-3">
                        <label class="form-label"><i class="fas fa-tag"></i> Tipo</label>
                        <select id="tipo_filtro" class="form-control" onchange="aplicarFiltros()">
                            <option value="">Todos</option>
                            <option value="excel">Excel</option>
                            <option value="pdf">PDF</option>
                        </select>
                    </div>
                    <div class="col-md-3 mb-3">
                        <label class="form-label"><i class="fas fa-search"></i> Buscar</label>
                        <input type="text" id="busqueda" class="form-control" onkeyup="aplicarFiltros()" placeholder="Proveedor o nombre...">
                    </div>
                </div>
                <div class="row">
                    <div class="col-12">
                        <button class="btn btn-secondary" onclick="limpiarFiltros()">
                            <i class="fas fa-undo"></i> Limpiar filtros
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <!-- Carpetas -->
        <div class="carpetas-grid">
            <?php 
            $iconos_carpetas = [
                'ventas_generales' => 'fa-chart-line',
                'ventas_proveedor' => 'fa-truck',
                'stock_general' => 'fa-boxes',
                'stock_proveedor' => 'fa-tags'
            ];
            $nombres_carpetas = [
                'ventas_generales' => 'Ventas Generales',
                'ventas_proveedor' => 'Ventas por Proveedor',
                'stock_general' => 'Stock General',
                'stock_proveedor' => 'Stock por Proveedor'
            ];
            foreach($carpetas as $nombre_carpeta => $ruta_carpeta): 
                $archivos_carpeta = array_filter($todos_los_archivos, function($a) use ($nombre_carpeta) {
                    return $a['carpeta'] == $nombre_carpeta;
                });
                $total_carpeta = count($archivos_carpeta);
            ?>
                <div class="carpeta-card" data-carpeta="<?= $nombre_carpeta ?>" onclick="filtrarPorCarpetaAjax('<?= $nombre_carpeta ?>')">
                    <div class="carpeta-icon">
                        <i class="fas <?= $iconos_carpetas[$nombre_carpeta] ?>"></i>
                    </div>
                    <h4><?= $nombres_carpetas[$nombre_carpeta] ?></h4>
                    <p class="carpeta-count"><?= $total_carpeta ?> archivos</p>
                </div>
            <?php endforeach; ?>
        </div>

        <!-- Tabla de resultados - SIN SCROLL -->
        <div class="table-container">
            <div class="table-header">
                <div>
                    <i class="fas fa-folder-open"></i>
                    <span class="ml-2">Archivos encontrados</span>
                </div>
                <div>
                    <span class="badge bg-white text-dark mr-2" id="resultadosCount"><?= count($todos_los_archivos) ?> resultados</span>
                </div>
            </div>
            <div class="table-body">
                <table class="table table-hover">
                    <thead>
                        <tr>
                            <th>Tipo</th>
                            <th>Nombre del archivo</th>
                            <th>Carpeta</th>
                            <th>Proveedor</th>
                            <th>Usuario</th>
                            <th>Fecha</th>
                            <th>Tamaño</th>
                            <th>Acción</th>
                        </tr>
                    </thead>
                    <tbody id="tablaArchivos">
                        <!-- Se llenará con JavaScript -->
                    </tbody>
                </table>
            </div>
            <div class="table-footer">
                <i class="fas fa-info-circle"></i> 
                Mostrando <span id="mostrandoCount">0</span> de <span id="totalCount"><?= count($todos_los_archivos) ?></span> archivos
                <span class="ml-3"><i class="fas fa-calendar-day"></i> Hoy: <span id="archivosHoy"><?= $archivos_hoy ?></span></span>
                <span class="ml-3"><i class="fas fa-calendar-week"></i> Última semana: <span id="archivosSemana"><?= $archivos_semana ?></span></span>
            </div>
        </div>

        <!-- Gráfica -->
        <div class="card mt-4" style="border-radius: 20px; border: 1px solid #eef2f6; background: white;">
            <div class="card-header" style="background: white; border-bottom: 2px solid #f97316; border-radius: 20px 20px 0 0;">
                <h5 class="mb-0" style="font-weight: 700; color: #1e293b;">
                    <i class="fas fa-chart-line mr-2" style="color: #f97316;"></i> Actividad de archivos (últimos 7 días)
                </h5>
            </div>
            <div class="card-body">
                <canvas id="graficaHistorial" style="height: 300px; width: 100%;"></canvas>
            </div>
        </div>

    </div>
</div>

<!-- Modal -->
<div class="modal fade modal-reporte" id="reporteModal" tabindex="-1">
    <div class="modal-dialog modal-xl">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">
                    <i class="fas fa-file"></i> Visualizando Archivo
                </h5>
                <button type="button" class="close" data-dismiss="modal">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
            <div class="modal-body" id="reporteModalBody">
                <div class="text-center p-5">
                    <div class="spinner-border text-primary" role="status">
                        <span class="sr-only">Cargando...</span>
                    </div>
                    <p class="mt-3">Cargando archivo...</p>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-dismiss="modal">Cerrar</button>
                <a href="#" id="descargarDesdeModal" class="btn btn-primary" download>
                    <i class="fas fa-download"></i> Descargar
                </a>
            </div>
        </div>
    </div>
</div>

<script src="https://code.jquery.com/jquery-3.6.4.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@4.6.2/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

<script>
const todosArchivos = <?php echo json_encode($todos_los_archivos); ?>;
let archivosFiltrados = [...todosArchivos];
let carpetaSeleccionada = '';
let grafica = null;

function initGrafica() {
    const ctx = document.getElementById('graficaHistorial').getContext('2d');
    grafica = new Chart(ctx, {
        type: 'line',
        data: {
            labels: <?= json_encode($fechas_grafica) ?>,
            datasets: [{
                label: 'Archivos generados',
                data: <?= json_encode($conteos_grafica) ?>,
                borderColor: '#f97316',
                backgroundColor: 'rgba(249, 115, 22, 0.1)',
                tension: 0.4,
                fill: true,
                pointBackgroundColor: '#f97316',
                pointBorderColor: '#fff',
                pointBorderWidth: 2,
                pointRadius: 5
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: { legend: { display: false }, tooltip: { enabled: true } },
            scales: { y: { beginAtZero: true, ticks: { stepSize: 1, precision: 0 } } }
        }
    });
}

function renderizarTabla() {
    const tbody = document.getElementById('tablaArchivos');
    const resultadosCount = document.getElementById('resultadosCount');
    const mostrandoCount = document.getElementById('mostrandoCount');
    const totalCount = document.getElementById('totalCount');
    
    if (archivosFiltrados.length === 0) {
        tbody.innerHTML = `<tr><td colspan="8" class="text-center py-5"><i class="fas fa-folder-open fa-3x text-muted mb-3"></i><p class="mb-0">No se encontraron archivos</p></td></table>`;
        resultadosCount.textContent = '0 resultados';
        mostrandoCount.textContent = '0';
        return;
    }
    
    let html = '';
    for (const archivo of archivosFiltrados) {
        const tipoBadge = archivo.tipo === 'excel' 
            ? '<span class="badge-excel"><i class="fas fa-file-excel"></i> Excel</span>'
            : '<span class="badge-pdf"><i class="fas fa-file-pdf"></i> PDF</span>';
        
        const nombreCarpeta = {
            'ventas_generales': 'Ventas Generales',
            'ventas_proveedor': 'Ventas por Proveedor',
            'stock_general': 'Stock General',
            'stock_proveedor': 'Stock por Proveedor'
        }[archivo.carpeta] || archivo.carpeta;
        
        let tamaño = '';
        if (archivo.tamaño < 1024) tamaño = archivo.tamaño + ' B';
        else if (archivo.tamaño < 1048576) tamaño = (archivo.tamaño / 1024).toFixed(1) + ' KB';
        else tamaño = (archivo.tamaño / 1048576).toFixed(1) + ' MB';
        
        const fecha = new Date(archivo.fecha * 1000);
        const fechaStr = fecha.toLocaleDateString('es-MX') + ' ' + fecha.toLocaleTimeString('es-MX', {hour: '2-digit', minute:'2-digit'});
        
        html += `
            <tr>
                <td>${tipoBadge}</td>
                <td title="${escapeHtml(archivo.nombre)}">${escapeHtml(archivo.nombre.substring(0, 50))}${archivo.nombre.length > 50 ? '...' : ''}</td>
                <td><span class="badge bg-light" style="cursor: pointer; padding: 4px 8px;" onclick="filtrarPorCarpetaAjax('${archivo.carpeta}')"><i class="fas fa-folder text-warning"></i> ${nombreCarpeta}</span></td>
                <td>${escapeHtml(archivo.proveedor)}</td>
                <td><small><i class="fas fa-user text-muted"></i> ${escapeHtml(archivo.usuario || 'Sistema')}</small></td>
                <td><small>${fechaStr}</small></td>
                <td><span class="badge bg-light">${tamaño}</span></td>
                <td>
                    <button class="btn-ver btn btn-sm btn-outline-info" onclick="verReporte('${archivo.ruta}', '${archivo.tipo}')" title="Ver">
                        <i class="fas fa-eye"></i>
                    </button>
                    <a href="${archivo.ruta}" class="btn-descargar btn btn-sm btn-outline-success" download="${archivo.nombre}" title="Descargar">
                        <i class="fas fa-download"></i>
                    </a>
                 </td>
            </tr>
        `;
    }
    
    tbody.innerHTML = html;
    resultadosCount.textContent = archivosFiltrados.length + ' resultados';
    mostrandoCount.textContent = archivosFiltrados.length;
}

function aplicarFiltros() {
    const fechaDesde = document.getElementById('fecha_desde').value;
    const fechaHasta = document.getElementById('fecha_hasta').value;
    const tipoFiltro = document.getElementById('tipo_filtro').value;
    const busqueda = document.getElementById('busqueda').value.toLowerCase();
    
    archivosFiltrados = todosArchivos.filter(archivo => {
        if (carpetaSeleccionada && archivo.carpeta !== carpetaSeleccionada) return false;
        if (fechaDesde && new Date(archivo.fecha * 1000) < new Date(fechaDesde)) return false;
        if (fechaHasta) {
            const fechaHastaDate = new Date(fechaHasta);
            fechaHastaDate.setHours(23, 59, 59);
            if (new Date(archivo.fecha * 1000) > fechaHastaDate) return false;
        }
        if (tipoFiltro && archivo.tipo !== tipoFiltro) return false;
        if (busqueda) {
            return archivo.nombre.toLowerCase().includes(busqueda) || 
                   archivo.proveedor.toLowerCase().includes(busqueda) ||
                   (archivo.usuario && archivo.usuario.toLowerCase().includes(busqueda));
        }
        return true;
    });
    renderizarTabla();
    actualizarContadores();
}

function filtrarPorCarpetaAjax(carpeta) {
    document.querySelectorAll('.carpeta-card').forEach(card => card.classList.remove('active'));
    const cardSeleccionada = document.querySelector(`.carpeta-card[data-carpeta="${carpeta}"]`);
    if (cardSeleccionada) cardSeleccionada.classList.add('active');
    
    if (carpetaSeleccionada === carpeta) {
        carpetaSeleccionada = '';
        cardSeleccionada?.classList.remove('active');
    } else {
        carpetaSeleccionada = carpeta;
    }
    aplicarFiltros();
}

function limpiarFiltros() {
    document.getElementById('fecha_desde').value = '';
    document.getElementById('fecha_hasta').value = '';
    document.getElementById('tipo_filtro').value = '';
    document.getElementById('busqueda').value = '';
    carpetaSeleccionada = '';
    document.querySelectorAll('.carpeta-card').forEach(card => card.classList.remove('active'));
    archivosFiltrados = [...todosArchivos];
    renderizarTabla();
    actualizarContadores();
}

function actualizarContadores() {
    const carpetasCount = {
        'ventas_generales': 0, 'ventas_proveedor': 0,
        'stock_general': 0, 'stock_proveedor': 0
    };
    archivosFiltrados.forEach(archivo => {
        if (carpetasCount[archivo.carpeta] !== undefined) carpetasCount[archivo.carpeta]++;
    });
    document.querySelectorAll('.carpeta-card').forEach(card => {
        const carpeta = card.getAttribute('data-carpeta');
        const countSpan = card.querySelector('.carpeta-count');
        if (countSpan && carpetasCount[carpeta] !== undefined) countSpan.textContent = carpetasCount[carpeta] + ' archivos';
    });
    
    document.getElementById('totalArchivos').textContent = archivosFiltrados.length;
    document.getElementById('totalExcel').textContent = archivosFiltrados.filter(a => a.tipo === 'excel').length;
    document.getElementById('totalPdf').textContent = archivosFiltrados.filter(a => a.tipo === 'pdf').length;
    
    const hoy = new Date(); hoy.setHours(0,0,0,0);
    const semanaAtras = new Date(); semanaAtras.setDate(semanaAtras.getDate() - 7);
    document.getElementById('archivosHoy').textContent = archivosFiltrados.filter(a => new Date(a.fecha * 1000) >= hoy).length;
    document.getElementById('archivosSemana').textContent = archivosFiltrados.filter(a => new Date(a.fecha * 1000) >= semanaAtras).length;
}

function verReporte(ruta, tipo) {
    const modalBody = document.getElementById('reporteModalBody');
    const modalTitle = document.querySelector('#reporteModal .modal-title');
    const descargarBtn = document.getElementById('descargarDesdeModal');
    
    if (tipo === 'pdf') {
        modalTitle.innerHTML = '<i class="fas fa-file-pdf text-danger"></i> Visualizando PDF';
        modalBody.innerHTML = `<iframe src="${ruta}" style="width: 100%; height: 70vh; border: none;"></iframe>`;
    } else {
        modalTitle.innerHTML = '<i class="fas fa-file-excel text-success"></i> Visualizando Excel';
        modalBody.innerHTML = `
            <div class="text-center p-5">
                <i class="fas fa-file-excel" style="font-size: 64px; color: #22c55e;"></i>
                <h4 class="mt-3">Archivo Excel</h4>
                <p class="text-muted">Los archivos Excel no pueden visualizarse directamente en el navegador.</p>
                <a href="${ruta}" class="btn btn-success btn-lg mt-3" download><i class="fas fa-download"></i> Descargar Excel</a>
            </div>
        `;
    }
    descargarBtn.href = ruta;
    $('#reporteModal').modal('show');
}

function escapeHtml(text) {
    if (!text) return '';
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

document.addEventListener('DOMContentLoaded', function() {
    archivosFiltrados = [...todosArchivos];
    renderizarTabla();
    initGrafica();
});

$('#reporteModal').on('hidden.bs.modal', function () {
    document.getElementById('reporteModalBody').innerHTML = '<div class="text-center p-5"><div class="spinner-border text-primary" role="status"><span class="sr-only">Cargando...</span></div><p class="mt-3">Cargando archivo...</p></div>';
});
</script>