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

// Obtener filtros
$filtro_proveedor = $_GET['proveedor'] ?? '';
$filtro_tipo = $_GET['tipo'] ?? '';
$filtro_carpeta = $_GET['carpeta'] ?? '';
$filtro_fecha_desde = $_GET['fecha_desde'] ?? date('Y-m-d', strtotime('-30 days'));
$filtro_fecha_hasta = $_GET['fecha_hasta'] ?? date('Y-m-d');

// ===== OBTENER ARCHIVOS DE TODAS LAS CARPETAS =====
$carpetas = [
    'reportes_proveedor' => 'uploads/reportes_proveedor/',
    'reportes_stock' => 'uploads/reportes_stock/',
    'reportes_ventas' => 'uploads/reportes_ventas/',
    'reportes_inventario' => 'uploads/reportes_inventario/',
    'reportes_costos' => 'uploads/reportes_costos/',
    'reportes_compras' => 'uploads/reportes_compras/'
];

$todos_los_archivos = [];

// Primero, obtener todos los registros de la BD para tener los proveedores
$query_bd = "SELECT nombre_archivo, proveedor, fecha_generacion FROM historial_reportes";
$result_bd = $conn->query($query_bd);
$info_bd = [];
while ($row = $result_bd->fetch_assoc()) {
    $info_bd[$row['nombre_archivo']] = [
        'proveedor' => $row['proveedor'],
        'fecha_bd' => strtotime($row['fecha_generacion'])
    ];
}

foreach ($carpetas as $nombre_carpeta => $ruta_carpeta) {
    if (file_exists($ruta_carpeta)) {
        $archivos = scandir($ruta_carpeta);
        foreach ($archivos as $archivo) {
            if ($archivo != '.' && $archivo != '..' && !is_dir($ruta_carpeta . $archivo)) {
                // Obtener información del archivo
                $ruta_completa = $ruta_carpeta . $archivo;
                $fecha_modificacion = filemtime($ruta_completa);
                $tamaño = filesize($ruta_completa);
                $extension = strtolower(pathinfo($archivo, PATHINFO_EXTENSION));
                
                // Determinar el tipo según extensión
                $tipo = ($extension == 'pdf') ? 'pdf' : 'excel';
                
                // Buscar proveedor en BD primero
                $proveedor_detectado = 'N/A';
                $producto_detectado = 'N/A';
                
                if (isset($info_bd[$archivo])) {
                    // Si existe en BD, usar ese proveedor
                    $proveedor_detectado = $info_bd[$archivo]['proveedor'] ?? 'N/A';
                } else {
                    // Si no está en BD, intentar detectar del nombre
                    if (strpos($archivo, 'proveedor_') === 0) {
                        $partes = explode('_', $archivo);
                        if (count($partes) >= 3) {
                            $proveedor_detectado = ucwords(str_replace('_', ' ', $partes[1]));
                        }
                    } elseif (strpos($archivo, 'producto_') === 0) {
                        $partes = explode('_', $archivo);
                        if (count($partes) >= 3) {
                            $producto_detectado = ucwords(str_replace('_', ' ', $partes[1]));
                        }
                    } elseif (strpos($archivo, 'costos_') === 0) {
                        $proveedor_detectado = 'Reporte de Costos';
                    } elseif (strpos($archivo, 'inventario_') === 0) {
                        $proveedor_detectado = 'Inventario General';
                    }
                }
                
                $todos_los_archivos[] = [
                    'nombre' => $archivo,
                    'ruta' => $ruta_completa,
                    'carpeta' => $nombre_carpeta,
                    'ruta_carpeta' => $ruta_carpeta,
                    'fecha' => $fecha_modificacion,
                    'tamaño' => $tamaño,
                    'tipo' => $tipo,
                    'extension' => $extension,
                    'proveedor' => $proveedor_detectado,
                    'producto' => $producto_detectado
                ];
            }
        }
    }
}

// Ordenar archivos por fecha (más recientes primero)
usort($todos_los_archivos, function($a, $b) {
    return $b['fecha'] - $a['fecha'];
});

// Aplicar filtros a los archivos
$archivos_filtrados = $todos_los_archivos;

if ($filtro_carpeta) {
    $archivos_filtrados = array_filter($archivos_filtrados, function($a) use ($filtro_carpeta) {
        return $a['carpeta'] == $filtro_carpeta;
    });
}

if ($filtro_tipo) {
    $archivos_filtrados = array_filter($archivos_filtrados, function($a) use ($filtro_tipo) {
        return $a['tipo'] == $filtro_tipo;
    });
}

if ($filtro_proveedor) {
    $archivos_filtrados = array_filter($archivos_filtrados, function($a) use ($filtro_proveedor) {
        return stripos($a['proveedor'], $filtro_proveedor) !== false || 
               stripos($a['nombre'], $filtro_proveedor) !== false;
    });
}

if ($filtro_fecha_desde) {
    $fecha_desde_ts = strtotime($filtro_fecha_desde);
    $archivos_filtrados = array_filter($archivos_filtrados, function($a) use ($fecha_desde_ts) {
        return $a['fecha'] >= $fecha_desde_ts;
    });
}

if ($filtro_fecha_hasta) {
    $fecha_hasta_ts = strtotime($filtro_fecha_hasta . ' 23:59:59');
    $archivos_filtrados = array_filter($archivos_filtrados, function($a) use ($fecha_hasta_ts) {
        return $a['fecha'] <= $fecha_hasta_ts;
    });
}

// Calcular estadísticas para la gráfica (últimos 7 días)
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

<style>
.historial-card {
    border-left: 4px solid #3498db;
    margin-bottom: 15px;
    transition: all 0.3s;
    border-radius: 8px;
    box-shadow: 0 2px 5px rgba(0,0,0,0.1);
}
.historial-card:hover {
    box-shadow: 0 5px 15px rgba(0,0,0,0.2);
    transform: translateY(-2px);
}
.excel-badge {
    background-color: #27ae60;
    color: white;
    padding: 5px 10px;
    border-radius: 5px;
    font-size: 0.8rem;
    display: inline-block;
    font-weight: bold;
}
.pdf-badge {
    background-color: #e74c3c;
    color: white;
    padding: 5px 10px;
    border-radius: 5px;
    font-size: 0.8rem;
    display: inline-block;
    font-weight: bold;
}
.carpeta-badge {
    background-color: #f39c12;
    color: white;
    padding: 5px 10px;
    border-radius: 5px;
    font-size: 0.8rem;
    display: inline-block;
    font-weight: bold;
    cursor: pointer;
    transition: all 0.3s;
}
.carpeta-badge:hover {
    transform: scale(1.05);
    box-shadow: 0 2px 5px rgba(0,0,0,0.2);
}
.filtros-box {
    background-color: #f8f9fa;
    border-radius: 8px;
    padding: 20px;
    margin-bottom: 20px;
    border: 1px solid #dee2e6;
}
.total-card {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: white;
    border-radius: 10px;
    padding: 20px;
    margin-bottom: 20px;
    text-align: center;
    box-shadow: 0 4px 8px rgba(0,0,0,0.1);
}
.total-card h5 {
    font-size: 14px;
    opacity: 0.9;
    margin-bottom: 5px;
}
.total-card h2, .total-card h4 {
    margin: 0;
    font-weight: bold;
}
.descargar-btn {
    background-color: #3498db;
    color: white;
    border: none;
    padding: 8px 15px;
    border-radius: 5px;
    cursor: pointer;
    transition: all 0.3s;
    text-decoration: none;
    display: inline-block;
    font-size: 0.9rem;
}
.descargar-btn:hover {
    background-color: #2980b9;
    transform: scale(1.05);
    color: white;
    text-decoration: none;
}
.ver-detalle-btn {
    background-color: #6c757d;
    color: white;
    border: none;
    padding: 8px 12px;
    border-radius: 5px;
    cursor: pointer;
    transition: all 0.3s;
    margin-right: 5px;
}
.ver-detalle-btn:hover {
    background-color: #5a6268;
}
.tabla-archivos {
    font-size: 0.9rem;
}
.tabla-archivos th {
    background-color: #3498db;
    color: white;
    position: sticky;
    top: 0;
    z-index: 10;
}
.espacio-disco {
    font-family: monospace;
    font-size: 0.85rem;
}
.carpeta-header {
    background-color: #f8f9fa;
    padding: 10px 15px;
    border-radius: 8px;
    margin-bottom: 15px;
    border-left: 4px solid #f39c12;
    cursor: pointer;
    transition: all 0.3s;
}
.carpeta-header:hover {
    background-color: #e9ecef;
    transform: translateX(5px);
}
.carpeta-header.active {
    border-left-color: #27ae60;
    background-color: #e8f5e9;
}
.stats-box {
    background-color: #f8f9fa;
    border-radius: 8px;
    padding: 15px;
    border: 1px solid #dee2e6;
    cursor: pointer;
    transition: all 0.3s;
}
.stats-box:hover {
    transform: translateY(-3px);
    box-shadow: 0 4px 8px rgba(0,0,0,0.1);
    border-color: #f39c12;
}
.stats-box.active {
    border: 2px solid #f39c12;
    background-color: #fff3e0;
}
.tamano-archivo {
    font-family: monospace;
    color: #6c757d;
}

/* Modal personalizado */
.modal-xl {
    max-width: 90%;
    margin: 30px auto;
}

.modal-body {
    padding: 0;
    height: 80vh;
}

.modal-body iframe {
    width: 100%;
    height: 100%;
    border: none;
}

/* Estilo para la carpeta activa */
.carpeta-activa {
    font-weight: bold;
    color: #f39c12;
}

.metric-card, .small-box {
    border-radius: 10px !important;
    padding: 15px;
    color: white;
    transition: 0.25s ease-in-out;
    box-shadow: 0 4px 10px rgba(0,0,0,0.12);
}

.small-box {
    min-height: 130px; /* Ajusta según necesites */
}
.small-box .inner {
    padding-bottom: 0.5rem;
}

/* Estilos para que parezca una tabla */
.table-container {
    background: white;
    border-radius: 12px;
    box-shadow: 0 4px 12px rgba(0,0,0,0.05);
    border: 1px solid #e9ecef;
    overflow: hidden;
    margin-top: 10px;
}

.table-header {
    background: linear-gradient( #36a12a);
    color: white;
    padding: 15px 20px;
    display: flex;
    justify-content: space-between;
    align-items: center;
    border-bottom: 3px solid #f39c12;
}

.header-left {
    font-size: 1.1rem;
    font-weight: 600;
}

.header-left i {
    margin-right: 10px;
    color: #ffffff;
}

.header-right .badge {
    margin-left: 8px;
    padding: 6px 12px;
    font-size: 0.8rem;
}

.table-body {
    padding: 20px;
    background: #f8f9fa;
}

.table-subtitle {
    font-size: 1rem;
    color: #495057;
    margin-bottom: 20px;
    padding-bottom: 10px;
    border-bottom: 2px dashed #dee2e6;
}

.table-footer {
    background: #f1f3f5;
    padding: 12px 20px;
    border-top: 1px solid #dee2e6;
    font-size: 0.9rem;
    color: #6c757d;
}

.table-footer a {
    color: #f39c12;
    text-decoration: none;
    font-weight: 500;
}

.table-footer a:hover {
    text-decoration: underline;
}

/* Mantén tus estilos actuales de stats-box */
.stats-box {
    background: white;
    border-radius: 8px;
    padding: 15px;
    border: 1px solid #dee2e6;
    cursor: pointer;
    transition: all 0.3s;
    height: 100%;
}

.stats-box:hover {
    transform: translateY(-3px);
    box-shadow: 0 4px 8px rgba(0,0,0,0.1);
    border-color: #f39c12;
}

.stats-box.active {
    border: 2px solid #f39c12;
    background-color: #fff3e0;
}

/* Responsive */
@media (max-width: 768px) {
    .table-header {
        flex-direction: column;
        text-align: center;
        gap: 10px;
    }
    
    .header-right .badge {
        display: inline-block;
        margin: 2px;
    }
}
</style>


<!-- Bootstrap CSS y JS para el modal -->
<link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css">
<script src="https://code.jquery.com/jquery-3.5.1.min.js"></script>
<script src="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/js/bootstrap.min.js"></script>

<div class="content-wrapper">
    <section class="content pt-3">
        <div class="container-fluid">
            
            <!-- Título -->
            <div class="row mb-4">
                <div class="col-12">
                    <div style="display: flex; justify-content: space-between; align-items: center;">
                        <h2 style="margin: 0;">Historial de Archivos</h2>
                        <p class="text-muted" style="margin: 0;">
                            Total archivos: <?= count($todos_los_archivos) ?> |
                            <i class="fas fa-hdd"></i> Espacio total: <?php 
                                $total_bytes = array_sum(array_column($todos_los_archivos, 'tamaño'));
                                echo number_format($total_bytes / 1024 / 1024, 2) . ' MB';
                            ?>
                            <?php if ($filtro_carpeta): ?>
                                | <span class="carpeta-activa"><i class="fas fa-folder-open"></i> Carpeta activa: <?= str_replace('_', ' ', ucwords($filtro_carpeta)) ?></span>
                            <?php endif; ?>
                        </p>
                    </div>
                </div>
            </div>

            <!-- Tarjetas de totales -->
            <?php
            $total_excel = count(array_filter($todos_los_archivos, function($a) { return $a['tipo'] == 'excel'; }));
            $total_pdf = count(array_filter($todos_los_archivos, function($a) { return $a['tipo'] == 'pdf'; }));
            ?>
            
            <!-- Tarjetas de estadísticas mejoradas estilo AdminLTE -->
            <div class="row">
                <div class="col-lg-3 col-6">
                    <div class="small-box bg-info">
                        <div class="inner">
                            <h3><?= count($todos_los_archivos) ?></h3>
                            <p>Total Archivos</p>
                            <div class="mt-2">
                                <span class="badge badge-light"><i class="fas fa-hdd"></i> <?= number_format($total_bytes / 1024 / 1024, 2) ?> MB</span>
                            </div>
                        </div>
                        <div class="icon">
                            <i class="fas fa-file-alt"></i>
                        </div>
                    </div>
                </div>

                <div class="col-lg-3 col-6">
                    <div class="small-box bg-success">
                        <div class="inner">
                            <h3><?= $total_excel ?><sup style="font-size: 20px"></sup></h3>
                            <p>Archivos Excel</p>
                            <div class="mt-2">
                                <?php 
                                $excel_size = array_sum(array_column(array_filter($todos_los_archivos, function($a) { return $a['tipo'] == 'excel'; }), 'tamaño'));
                                ?>
                                <span class="badge badge-light"><i class="fas fa-weight-hanging"></i> <?= number_format($excel_size / 1024 / 1024, 2) ?> MB</span>
                            </div>
                        </div>
                        <div class="icon">
                            <i class="fas fa-file-excel"></i>
                        </div>
                    </div>
                </div>

                <div class="col-lg-3 col-6">
                    <div class="small-box bg-danger">
                        <div class="inner">
                            <h3><?= $total_pdf ?></h3>
                            <p>Archivos PDF</p>
                            <div class="mt-2">
                                <?php 
                                $pdf_size = array_sum(array_column(array_filter($todos_los_archivos, function($a) { return $a['tipo'] == 'pdf'; }), 'tamaño'));
                                ?>
                                <span class="badge badge-light"><i class="fas fa-weight-hanging"></i> <?= number_format($pdf_size / 1024 / 1024, 2) ?> MB</span>
                            </div>
                        </div>
                        <div class="icon">
                            <i class="fas fa-file-pdf"></i>
                        </div>
                    </div>
                </div>

                <div class="col-lg-3 col-6">
                    <div class="small-box bg-warning">
                        <div class="inner">
                            <h3><?= count($carpetas) ?></h3>
                            <p>Carpetas Activas</p>
                            <div class="mt-2">
                                <?php 
                                $archivos_hoy = count(array_filter($todos_los_archivos, function($a) { 
                                    return date('Y-m-d', $a['fecha']) == date('Y-m-d'); 
                                }));
                                $archivos_semana = count(array_filter($todos_los_archivos, function($a) { 
                                    return $a['fecha'] >= strtotime('-7 days'); 
                                }));
                                ?>
                                <span class="badge badge-light"><i class="fas fa-calendar-day"></i> Hoy: <?= $archivos_hoy ?></span>
                                <span class="badge badge-light"><i class="fas fa-calendar-week"></i> 7 días: <?= $archivos_semana ?></span>
                            </div>
                        </div>
                        <div class="icon">
                            <i class="fas fa-folder-open"></i>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Filtros mejorados - Opción 2 (Compacta) -->
            <div class="card card-primary card-outline mb-4">
                <div class="card-header">
                    <h3 class="card-title">
                        <i class="fas fa-filter"></i> Filtros
                    </h3>
                    <div class="card-tools">
                        <span class="badge badge-primary"><?= count($todos_los_archivos) ?> archivos</span>
                    </div>
                </div>
                <div class="card-body">
                    <form method="GET" id="filtrosForm">
                        <div class="row">
                            <div class="col-md-3">
                                <div class="form-group">
                                    <label><i class="fas fa-calendar"></i> Desde</label>
                                    <input type="date" name="fecha_desde" class="form-control" value="<?= $filtro_fecha_desde ?>">
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="form-group">
                                    <label><i class="fas fa-calendar"></i> Hasta</label>
                                    <input type="date" name="fecha_hasta" class="form-control" value="<?= $filtro_fecha_hasta ?>">
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="form-group">
                                    <label><i class="fas fa-tag"></i> Tipo</label>
                                    <select name="tipo" class="form-control">
                                        <option value="">Todos</option>
                                        <option value="excel" <?= $filtro_tipo == 'excel' ? 'selected' : '' ?>>Excel</option>
                                        <option value="pdf" <?= $filtro_tipo == 'pdf' ? 'selected' : '' ?>>PDF</option>
                                    </select>
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="form-group">
                                    <label><i class="fas fa-folder"></i> Carpeta</label>
                                    <select name="carpeta" class="form-control">
                                        <option value="">Todas</option>
                                        <?php foreach($carpetas as $nombre => $ruta): ?>
                                            <option value="<?= $nombre ?>" <?= $filtro_carpeta == $nombre ? 'selected' : '' ?>>
                                                <?= str_replace('_', ' ', ucwords($nombre)) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-md-8">
                                <div class="form-group">
                                    <label><i class="fas fa-search"></i> Buscar</label>
                                    <input type="text" name="proveedor" class="form-control" 
                                        value="<?= htmlspecialchars($filtro_proveedor) ?>" 
                                        placeholder="Proveedor o producto...">
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="form-group">
                                    <label>&nbsp;</label>
                                    <div class="btn-group d-flex">
                                        <button type="submit" class="btn btn-primary">
                                            <i class="fas fa-search"></i> Buscar
                                        </button>
                                        <a href="?" class="btn btn-secondary">
                                            <i class="fas fa-undo"></i> Limpiar
                                        </a>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Carpetas en tarjetas con estilo de tabla -->
            <div class="row mb-4">
                <div class="col-12">
                    <!-- Contenedor principal con estilo de tabla -->
                    <div class="table-container">
                        <!-- Header de la tabla -->
                        <div class="table-header">
                            <div class="header-left">
                                <i class="fas fa-folder-open"></i>
                                <span>CARPETAS DISPONIBLES</span>
                            </div>
                            <div class="header-right">
                                <span class="badge badge-primary"><?= count($carpetas) ?> carpetas</span>
                                <span class="badge badge-danger"><?= count($todos_los_archivos) ?> archivos</span>
                            </div>
                        </div>

                        <!-- Cuerpo de la tabla (aquí van tus cards) -->
                        <div class="table-body">
                            <h5 class="table-subtitle">
                                <i class="fas fa-folder text-warning"></i> 
                                Carpetas disponibles:
                            </h5>
                            
                            <div class="row">
                                <?php foreach($carpetas as $nombre_carpeta => $ruta_carpeta): 
                                    $archivos_carpeta = array_filter($todos_los_archivos, function($a) use ($nombre_carpeta) {
                                        return $a['carpeta'] == $nombre_carpeta;
                                    });
                                    $total_carpeta = count($archivos_carpeta);
                                    $tamaño_carpeta = array_sum(array_column($archivos_carpeta, 'tamaño'));
                                    $excel_carpeta = count(array_filter($archivos_carpeta, function($a) { return $a['tipo'] == 'excel'; }));
                                    $pdf_carpeta = count(array_filter($archivos_carpeta, function($a) { return $a['tipo'] == 'pdf'; }));
                                    
                                    $is_active = ($filtro_carpeta == $nombre_carpeta);
                                ?>
                                    <div class="col-md-4 mb-3">
                                        <div class="stats-box <?= $is_active ? 'active' : '' ?>" onclick="filtrarPorCarpeta('<?= $nombre_carpeta ?>')">
                                            <h6>
                                                <i class="fas fa-folder text-warning"></i> 
                                                <?= str_replace('_', ' ', ucwords($nombre_carpeta)) ?>
                                                <?php if($is_active): ?>
                                                    <span class="badge badge-warning float-right">Activa</span>
                                                <?php endif; ?>
                                            </h6>
                                            <p class="mb-1">
                                                <strong>Total:</strong> <?= $total_carpeta ?> archivos<br>
                                                <strong>Tamaño:</strong> <?= number_format($tamaño_carpeta / 1024 / 1024, 2) ?> MB<br>
                                                <span class="text-success"><i class="fas fa-file-excel"></i> Excel: <?= $excel_carpeta ?></span> | 
                                                <span class="text-danger"><i class="fas fa-file-pdf"></i> PDF: <?= $pdf_carpeta ?></span>
                                            </p>
                                            <?php if ($total_carpeta > 0): ?>
                                                <small class="text-muted">
                                                    <i class="fas fa-clock"></i> Último: <?= date('d/m/Y H:i', max(array_column($archivos_carpeta, 'fecha'))) ?>
                                                </small>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>

                        <!-- Footer de la tabla -->
                        <div class="table-footer">
                            <i class="fas fa-info-circle"></i> Haz clic en cualquier carpeta para filtrar los archivos
                            <?php if($filtro_carpeta): ?>
                                <a href="?" class="float-right">
                                    <i class="fas fa-times"></i> Quitar filtro
                                </a>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Resultados -->
            <div class="card mt-3">
                <div class="card-header" style="background-color: #343a40; color: white;">
                    <h5 class="mb-0" style="font-size: 1rem;">
                        <i class="fas fa-folder-open mr-2"></i> 
                        Archivos encontrados 
                        <?php if ($filtro_carpeta): ?>
                            en <?= str_replace('_', ' ', ucwords($filtro_carpeta)) ?>
                        <?php else: ?>
                            en todas las carpetas
                        <?php endif; ?>
                        (<?= count($archivos_filtrados) ?> resultados)
                    </h5>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-striped table-hover" style="margin-bottom: 0;">
                            <thead>
                                <tr style="background-color: #e9ecef;">
                                    <th style="padding: 12px 8px;">Tipo</th>
                                    <th style="padding: 12px 8px;">Archivo</th>
                                    <th style="padding: 12px 8px;">Carpeta</th>
                                    <th style="padding: 12px 8px;">Proveedor/Producto</th>
                                    <th style="padding: 12px 8px;">Fecha</th>
                                    <th style="padding: 12px 8px;">Tamaño</th>
                                    <th style="padding: 12px 8px;">Acciones</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($archivos_filtrados)): ?>
                                    <tr>
                                        <td colspan="7" class="text-center py-4">
                                            <i class="fas fa-folder-open fa-3x text-muted mb-3"></i>
                                            <p>No se encontraron archivos con los filtros seleccionados</p>
                                            <?php if ($filtro_carpeta): ?>
                                                <a href="?" class="btn btn-sm btn-secondary">
                                                    <i class="fas fa-undo"></i> Ver todas las carpetas
                                                </a>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach($archivos_filtrados as $archivo): ?>
                                        <tr>
                                            <td class="text-center" style="padding: 12px 8px;">
                                                <?php if($archivo['tipo'] == 'excel'): ?>
                                                    <span style="color: #28a745;">
                                                        <i class="fas fa-file-excel fa-lg"></i>
                                                    </span>
                                                <?php else: ?>
                                                    <span style="color: #dc3545;">
                                                        <i class="fas fa-file-pdf fa-lg"></i>
                                                    </span>
                                                <?php endif; ?>
                                            </td>
                                            <td style="padding: 12px 8px;">
                                                <strong><?= htmlspecialchars(substr($archivo['nombre'], 0, 50)) . (strlen($archivo['nombre']) > 50 ? '...' : '') ?></strong>
                                                <?php if($archivo['producto'] != 'N/A'): ?>
                                                    <br><small class="text-muted">Producto: <?= $archivo['producto'] ?></small>
                                                <?php endif; ?>
                                            </td>
                                            <td style="padding: 12px 8px;">
                                                <span onclick="filtrarPorCarpeta('<?= $archivo['carpeta'] ?>')" 
                                                    style="cursor: pointer; padding: 4px 8px; background-color: #cacaca; border-radius: 4px; display: inline-block;">
                                                    <i class="fas fa-folder" style="color: #3498db;"></i> 
                                                    <?= str_replace('_', ' ', $archivo['carpeta']) ?>
                                                </span>
                                            </td>
                                            <td style="padding: 12px 8px;"><?= htmlspecialchars($archivo['proveedor']) ?></td>
                                            <td style="padding: 12px 8px;"><?= date('d/m/Y H:i', $archivo['fecha']) ?></td>
                                            <td style="padding: 12px 8px;">
                                                <?php 
                                                if ($archivo['tamaño'] < 1024) {
                                                    echo $archivo['tamaño'] . ' B';
                                                } elseif ($archivo['tamaño'] < 1048576) {
                                                    echo number_format($archivo['tamaño'] / 1024, 1) . ' KB';
                                                } else {
                                                    echo number_format($archivo['tamaño'] / 1048576, 1) . ' MB';
                                                }
                                                ?>
                                            </td>
                                            <td style="padding: 12px 4px;">
                                                <button class="btn btn-sm btn-outline-secondary" onclick="verReporte('<?= $archivo['ruta'] ?>', '<?= $archivo['tipo'] ?>')">
                                                    <i class="fas fa-eye"></i>
                                                </button>
                                                <a href="<?= $archivo['ruta'] ?>" class="btn btn-sm btn-outline-primary" download="<?= $archivo['nombre'] ?>">
                                                    <i class="fas fa-download"></i>
                                                </a>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
                <?php if(count($archivos_filtrados) > 0): ?>
                <div class="card-footer text-muted" style="background-color: #f8f9fa; font-size: 0.85rem;">
                    <div class="d-flex justify-content-between">
                        <span><i class="fas fa-info-circle"></i> Mostrando <?= count($archivos_filtrados) ?> archivos</span>
                    </div>
                </div>
                <?php endif; ?>
            </div>
                        
            <!-- Gráfica de actividad -->
            <div class="card mt-4">
                <div class="card-header bg-success text-white">
                    <h5 class="mb-0"><i class="fas fa-chart-line mr-2"></i> Actividad de archivos (últimos 7 días)</h5>
                </div>
                <div class="card-body">
                    <canvas id="graficaHistorial" style="height: 300px; width: 100%;"></canvas>
                </div>
            </div>

        </div>
    </section>
</div>

<!-- Modal para visualizar reportes -->
<div class="modal fade" id="reporteModal" tabindex="-1" role="dialog" aria-labelledby="reporteModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-xl" role="document">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title" id="reporteModalLabel">
                    <i class="fas fa-file"></i> Visualizando Archivo
                </h5>
                <button type="button" class="close text-white" data-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
            <div class="modal-body" id="reporteModalBody">
                <!-- Aquí se cargará el contenido -->
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

<!-- Scripts -->
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
// Función para filtrar por carpeta
function filtrarPorCarpeta(carpeta) {
    const url = new URL(window.location.href);
    url.searchParams.set('carpeta', carpeta);
    window.location.href = url.toString();
}

// Función para ver reporte en modal
function verReporte(ruta, tipo) {
    const modalBody = document.getElementById('reporteModalBody');
    const modalTitle = document.getElementById('reporteModalLabel');
    const descargarBtn = document.getElementById('descargarDesdeModal');
    
    // Actualizar título y botón de descarga
    if (tipo === 'pdf' || tipo.toLowerCase() === 'pdf') {
        modalTitle.innerHTML = '<i class="fas fa-file-pdf text-danger"></i> Visualizando PDF';
        modalBody.innerHTML = `<iframe src="${ruta}" type="application/pdf"></iframe>`;
    } else {
        modalTitle.innerHTML = '<i class="fas fa-file-excel text-success"></i> Visualizando Excel';
        modalBody.innerHTML = `
            <div class="text-center p-5">
                <i class="fas fa-file-excel" style="font-size: 64px; color: #27ae60;"></i>
                <h4 class="mt-3">Archivo Excel</h4>
                <p class="text-muted">Los archivos Excel no pueden visualizarse directamente en el navegador.</p>
                <p>Puedes descargar el archivo para abrirlo con Microsoft Excel u otra aplicación compatible.</p>
                <a href="${ruta}" class="btn btn-success btn-lg mt-3" download>
                    <i class="fas fa-download"></i> Descargar Excel
                </a>
            </div>
        `;
    }
    
    // Configurar botón de descarga del modal
    descargarBtn.href = ruta;
    
    // Mostrar modal
    $('#reporteModal').modal('show');
}

// Gráfica
document.addEventListener('DOMContentLoaded', function() {
    const canvas = document.getElementById('graficaHistorial');
    if (canvas) {
        const ctx = canvas.getContext('2d');
        new Chart(ctx, {
            type: 'line',
            data: {
                labels: <?= json_encode($fechas_grafica) ?>,
                datasets: [{
                    label: 'Archivos generados/subidos',
                    data: <?= json_encode($conteos_grafica) ?>,
                    borderColor: '#3498db',
                    backgroundColor: 'rgba(52, 152, 219, 0.1)',
                    tension: 0.4,
                    fill: true,
                    pointBackgroundColor: '#3498db',
                    pointBorderColor: '#fff',
                    pointBorderWidth: 2,
                    pointRadius: 5
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        display: false
                    },
                    tooltip: {
                        enabled: true
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        ticks: {
                            stepSize: 1,
                            precision: 0
                        }
                    }
                }
            }
        });
    }
});

// Limpiar modal cuando se cierra
$('#reporteModal').on('hidden.bs.modal', function () {
    document.getElementById('reporteModalBody').innerHTML = '';
});

// Auto-submit del formulario cuando se selecciona una carpeta (opcional)
document.getElementById('carpetaSelect').addEventListener('change', function() {
    document.getElementById('filtrosForm').submit();
});
</script>