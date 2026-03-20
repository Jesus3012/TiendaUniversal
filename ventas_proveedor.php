<?php
date_default_timezone_set('America/Mexico_City');

session_start();
include 'includes/db.php';
include 'includes/header.php';
include 'includes/navbar.php';

// Este producto NO debe aparecer en la deuda con proveedores
define('PRODUCTO_ESPECIAL_NOMBRE', 'libretas');
define('PROVEEDOR_ESPECIAL', 'Nevaris 3D');

// Verificar autenticación
if (!isset($_SESSION['usuario_id'])) {
    header("Location: login.php");
    exit;
}

$proveedorSeleccionado = $_GET['proveedor'] ?? '';
$fechaHoy = date('d/m/Y');

// Obtener proveedores (solo los que tienen productos, excluyendo insumos)
$proveedores = [];
$provQuery = "SELECT DISTINCT p.proveedor 
              FROM productos p 
              WHERE p.proveedor IS NOT NULL 
              AND p.proveedor != '' 
              AND p.activo = 1 
              AND p.tipo_inventario = 'producto'
              ORDER BY p.proveedor";
$provResult = $conn->query($provQuery);
while ($p = $provResult->fetch_assoc()) {
    $proveedores[] = $p['proveedor'];
}
?>

<!-- Select2 CSS para el buscador mejorado -->
<link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
<style>
/* Mejoras visuales sutiles - manteniendo la esencia */
.content-wrapper {
    background-color: #f4f6f9;
}

/* Selector de proveedor */
.proveedor-card {
    border-left: 4px solid #007bff;
    box-shadow: 0 2px 5px rgba(0,0,0,0.1);
    position: relative;
}

/* Botón de reinicio en la esquina superior derecha */
.btn-reset-corner {
    position: absolute;
    top: 10px;
    right: 15px;
    background-color: #6c757d;
    color: white;
    border: none;
    width: 36px;
    height: 36px;
    border-radius: 4px;
    cursor: pointer;
    transition: all 0.3s;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.2rem;
    z-index: 10;
}

.btn-reset-corner:hover:not(:disabled) {
    background-color: #5a6268;
    transform: translateY(-2px);
    box-shadow: 0 2px 5px rgba(0,0,0,0.2);
}

.btn-reset-corner:disabled {
    opacity: 0.5;
    cursor: not-allowed;
}

/* Tabla más legible */
.table thead th {
    background-color: #e9ecef;
    font-weight: 600;
    font-size: 0.9rem;
    vertical-align: middle;
    white-space: nowrap;
}

.table tbody td {
    vertical-align: middle;
    padding: 0.75rem 0.5rem;
}

.table tbody tr:hover {
    background-color: #f8f9fa;
}

/* Input de conteo más visible */
.stock-conteo {
    border: 2px solid #dee2e6;
    border-radius: 4px;
    padding: 0.375rem 0.75rem;
    transition: border-color 0.15s ease-in-out;
    width: 100px;
    margin: 0 auto;
    text-align: center;
}

.stock-conteo:focus {
    border-color: #80bdff;
    outline: 0;
    box-shadow: 0 0 0 0.2rem rgba(0,123,255,0.25);
}

/* Badge para stock bajo */
.stock-bajo {
    background-color: #fff3cd;
    color: #856404;
    border: 1px solid #ffeeba;
    padding: 2px 8px;
    border-radius: 20px;
    font-size: 0.75rem;
    font-weight: 500;
}

/* Totales en footer */
tfoot tr {
    background-color: #f8f9fa;
    border-top: 2px solid #dee2e6;
}

tfoot td {
    font-weight: 700;
}

/* Info boxes - manteniendo el estilo original pero más compactos */
.info-box {
    min-height: 80px;
    border-radius: 4px;
    box-shadow: 0 2px 5px rgba(0,0,0,0.1);
}

.info-box-icon {
    width: 70px;
    height: 70px;
    font-size: 1.8rem;
    line-height: 70px;
}

.info-box-content {
    padding: 10px 15px;
}

.info-box-text {
    font-size: 0.9rem;
    text-transform: uppercase;
    font-weight: 600;
}

.info-box-number {
    font-size: 1.3rem;
    font-weight: 700;
}

/* Gráficas */
.card {
    border-radius: 4px;
    box-shadow: 0 2px 5px rgba(0,0,0,0.1);
    border: none;
}

.card-header {
    background-color: #f8f9fa;
    border-bottom: 1px solid #dee2e6;
    font-weight: 600;
}

/* Mensaje de stock final positivo/negativo */
.stock-final-positivo {
    color: #28a745;
    font-weight: 600;
}

.stock-final-negativo {
    color: #dc3545;
    font-weight: 600;
}

/* Separador sutil */
hr.separador {
    border-top: 1px dashed #dee2e6;
    margin: 1rem 0;
}

/* Tooltip personalizado */
.custom-tooltip {
    cursor: help;
    border-bottom: 1px dotted #6c757d;
}

/* Estilos para Select2 - buscador elegante */
.select2-container--default .select2-selection--single {
    height: 38px;
    border: 1px solid #ced4da;
    border-radius: 4px;
}

.select2-container--default .select2-selection--single .select2-selection__rendered {
    line-height: 36px;
    padding-left: 12px;
}

.select2-container--default .select2-selection--single .select2-selection__arrow {
    height: 36px;
}

.select2-container--default .select2-results__option--highlighted[aria-selected] {
    background-color: #007bff;
}

.select2-search--dropdown .select2-search__field {
    border: 1px solid #ced4da;
    border-radius: 4px;
    padding: 6px;
}

.select2-container--default .select2-results__option {
    padding: 8px 12px;
}

/* Barra de búsqueda en tabla */
.search-container {
    padding: 10px 15px;
    background-color: white;
    border-bottom: 1px solid #dee2e6;
}

.search-input {
    border: 1px solid #ced4da;
    border-radius: 20px;
    padding: 8px 15px;
    width: 300px;
    transition: all 0.3s;
}

.search-input:focus {
    border-color: #007bff;
    outline: none;
    box-shadow: 0 0 0 0.2rem rgba(0,123,255,0.25);
    width: 350px;
}

.search-icon {
    position: absolute;
    right: 25px;
    top: 50%;
    transform: translateY(-50%);
    color: #6c757d;
}

/* Indicador de resultados de búsqueda */
.search-stats {
    font-size: 0.85rem;
    color: #6c757d;
    margin-left: 10px;
}

/* Animación para filas filtradas */
.table tbody tr {
    transition: all 0.2s ease;
}

.table tbody tr.hidden-row {
    display: none;
}

/* Mensaje sin resultados */
.no-results {
    text-align: center;
    padding: 30px;
    color: #6c757d;
    font-style: italic;
}

.no-results i {
    font-size: 2rem;
    margin-bottom: 10px;
    color: #adb5bd;
}

/* Input modificado */
.stock-conteo.modificado {
    border-color: #28a745;
    background-color: #f0fff0;
}
</style>

<div class="content-wrapper">
    <section class="content pt-3">
        <div class="container-fluid">

            <!-- =================== SELECT PROVEEDOR CON BUSCADOR =================== -->
            <div class="card card-outline card-primary proveedor-card">
                <div class="card-header">
                    <h5 class="mb-0">
                        <i class="fas fa-industry mr-2"></i> Venta por proveedor
                        <?php if ($proveedorSeleccionado): ?>
                            <small class="ml-2 text-muted">(Mostrando solo productos)</small>
                        <?php endif; ?>
                    </h5>
                </div>
                <button type="button" id="btnResetProveedor" class="btn-reset-corner" 
                        <?= !$proveedorSeleccionado ? 'disabled' : '' ?>>
                    <i class="fas fa-undo-alt"></i>
                </button>
                <div class="card-body">
                    <form method="GET" id="proveedorForm">
                        <div class="row">
                            <div class="col-md-8 col-12">
                                <label class="font-weight-bold">Seleccionar proveedor</label>
                                <select name="proveedor" id="proveedorSelect" class="form-control" style="width: 100%;">
                                    <option value="">— Seleccione un proveedor —</option>
                                    <?php foreach ($proveedores as $prov): ?>
                                        <option value="<?= htmlspecialchars($prov) ?>" <?= $proveedorSeleccionado === $prov ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($prov) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <small class="text-muted">
                                    <i class="fas fa-info-circle"></i> Puedes buscar escribiendo en el campo
                                </small>
                            </div>
                        </div>
                    </form>
                </div>
            </div>

            <?php if ($proveedorSeleccionado):

                // Obtener productos SOLO de tipo producto, identificando el producto especial
                $productos = [];
                $q = $conn->prepare("SELECT id, nombre, cantidad, precio_compra, precio_venta, fecha_registro,
                                    CASE 
                                        WHEN LOWER(nombre) LIKE LOWER(?) 
                                            AND LOWER(proveedor) LIKE LOWER(?) 
                                        THEN 1
                                        ELSE 0
                                    END AS es_producto_especial
                                    FROM productos 
                                    WHERE proveedor = ? AND activo = 1 AND tipo_inventario = 'producto'
                                    ORDER BY nombre ASC");
                
                $likeNombre = '%' . PRODUCTO_ESPECIAL_NOMBRE . '%';
                $likeProveedor = '%' . PROVEEDOR_ESPECIAL . '%';
                $q->bind_param("sss", $likeNombre, $likeProveedor, $proveedorSeleccionado);
                $q->execute();
                $r = $q->get_result();

                while ($row = $r->fetch_assoc()) {
                    $productos[] = $row;
                }
            ?>

                <?php if (empty($productos)): ?>
                    <!-- Mensaje cuando no hay productos -->
                    <div class="alert alert-info mt-4">
                        <i class="fas fa-info-circle mr-2"></i>
                        <strong>Información:</strong> Este proveedor no tiene productos registrados o solo tiene insumos.
                    </div>
                <?php else: ?>

                    <!-- =================== TABLA CON BUSCADOR =================== -->
                    <div class="card card-outline card-success mt-4">
                        <div class="card-header d-flex justify-content-between align-items-center">
                            <h5 class="mb-0">
                                <i class="fas fa-boxes mr-2"></i> 
                                Control de stock – <strong><?= htmlspecialchars($proveedorSeleccionado) ?></strong>
                                <span class="badge badge-primary ml-2" id="totalProductos"><?= count($productos) ?> productos</span>
                            </h5>

                            <!-- Reemplaza los botones actuales con estos -->
                            <div class="d-flex align-items-center">
                                <!-- BUSCADOR EN TIEMPO REAL -->
                                <div class="position-relative mr-3">
                                    <input type="text" id="buscarProducto" class="search-input" placeholder="Buscar producto...">
                                    <i class="fas fa-search search-icon"></i>
                                </div>
                                
                                <!-- BOTONES DE EXPORTACIÓN CON FECHAS - VERSIÓN CORREGIDA -->
                                <div class="btn-group mr-2" role="group">
                                    <button type="button" class="btn btn-success btn-sm dropdown-toggle" data-bs-toggle="dropdown" aria-expanded="false">
                                        <i class="fas fa-file-excel"></i> Excel
                                    </button>
                                    <ul class="dropdown-menu dropdown-menu-end p-3" style="min-width: 250px;">
                                        <li>
                                            <form id="excelForm" action="reporte_excel.php" method="GET" target="_blank">
                                                <input type="hidden" name="proveedor" value="<?= htmlspecialchars($proveedorSeleccionado) ?>">
                                                <div class="mb-2">
                                                    <label class="small fw-bold">Fecha inicio:</label>
                                                    <input type="date" name="fecha_inicio" class="form-control form-control-sm" required>
                                                </div>
                                                <div class="mb-2">
                                                    <label class="small fw-bold">Fecha fin:</label>
                                                    <input type="date" name="fecha_fin" class="form-control form-control-sm" required>
                                                </div>
                                                <button type="submit" class="btn btn-success btn-sm w-100">
                                                    <i class="fas fa-download"></i> Exportar Excel
                                                </button>
                                            </form>
                                        </li>
                                    </ul>
                                </div>

                                <div class="btn-group" role="group">
                                    <button type="button" class="btn btn-danger btn-sm dropdown-toggle" data-bs-toggle="dropdown" aria-expanded="false">
                                        <i class="fas fa-file-pdf"></i> PDF
                                    </button>
                                    <ul class="dropdown-menu dropdown-menu-end p-3" style="min-width: 250px;">
                                        <li>
                                            <form id="pdfForm" action="reporte_pdf.php" method="GET" target="_blank">
                                                <input type="hidden" name="proveedor" value="<?= htmlspecialchars($proveedorSeleccionado) ?>">
                                                <div class="mb-2">
                                                    <label class="small fw-bold">Fecha inicio:</label>
                                                    <input type="date" name="fecha_inicio" class="form-control form-control-sm" required>
                                                </div>
                                                <div class="mb-2">
                                                    <label class="small fw-bold">Fecha fin:</label>
                                                    <input type="date" name="fecha_fin" class="form-control form-control-sm" required>
                                                </div>
                                                <button type="submit" class="btn btn-danger btn-sm w-100">
                                                    <i class="fas fa-download"></i> Exportar PDF
                                                </button>
                                            </form>
                                        </li>
                                    </ul>
                                </div>
                            </div>
                        </div>

                        <div class="card-body table-responsive p-0">
                            <!-- Estadísticas de búsqueda -->
                            <div class="search-stats px-3 py-2 bg-light" id="searchStats" style="display: none;">
                                <span id="resultadosVisibles"></span>
                            </div>

                            <form id="formStockFinal">
                                <table class="table table-bordered table-sm table-hover mb-0" id="tablaProductos">
                                    <thead class="bg-light">
                                        <tr>
                                            <th>Producto</th>
                                            <th class="text-center" style="width: 120px;">
                                                Stock Inicial
                                                <br><small class="text-muted">Hoy: <?= $fechaHoy ?></small>
                                            </th>
                                            <th class="text-center" style="width: 140px;">
                                                Stock después de contar
                                                <br><small class="text-muted"><?= $fechaHoy ?></small>
                                            </th>
                                            <th class="text-center" style="width: 80px;">Ventas</th>
                                            <th class="text-center" style="width: 100px;">Stock Final</th>
                                            <th class="text-right" style="width: 100px;">Venta $</th>
                                            <th class="text-right" style="width: 100px;">Deuda $</th>
                                            <th class="text-right" style="width: 100px;">Ganancia $</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php 
                                        $totalVentas = 0;
                                        $totalDeuda = 0;
                                        $totalGanancia = 0;
                                        foreach ($productos as $p):
                                            $stockInicial = (int)$p['cantidad'];
                                            $fechaRegistro = date('d/m/Y', strtotime($p['fecha_registro']));
                                            $esEspecial = $p['es_producto_especial'];
                                        ?>
                                        <tr data-id="<?= $p['id'] ?>"
                                            data-stock-inicial="<?= $stockInicial ?>"
                                            data-precio-venta="<?= $p['precio_venta'] ?>"
                                            data-precio-compra="<?= $p['precio_compra'] ?>"
                                            data-nombre="<?= strtolower(htmlspecialchars($p['nombre'])) ?>"
                                            data-es-especial="<?= $esEspecial ?>"
                                            class="<?= $esEspecial ? 'table-success' : '' ?>">
                                            
                                            <td class="font-weight-bold nombre-producto">
                                                <?= htmlspecialchars($p['nombre']) ?>
                                                <?php if ($esEspecial): ?>
                                                    <span class="badge badge-success ml-1">
                                                        <i class="fas fa-check-circle"></i> Pagado
                                                    </span>
                                                <?php endif; ?>
                                                <br><small class="text-muted">Reg: <?= $fechaRegistro ?></small>
                                            </td>

                                            <td class="text-center align-middle">
                                                <strong><?= $stockInicial ?></strong>
                                            </td>

                                            <td class="text-center">
                                                <input type="number" 
                                                    class="form-control form-control-sm stock-conteo text-center" 
                                                    value="<?= $stockInicial ?>" 
                                                    min="0" 
                                                    max="<?= $stockInicial ?>"
                                                    data-original="<?= $stockInicial ?>"
                                                    data-es-especial="<?= $esEspecial ?>"
                                                    style="width: 90px; margin: 0 auto;">
                                                <input type="hidden" name="ventas[<?= $p['id'] ?>]" class="ventas-input" value="0">
                                                <input type="hidden" name="stock_final[<?= $p['id'] ?>]" class="stock-final-input" value="<?= $stockInicial ?>">
                                                <input type="hidden" name="es_especial[<?= $p['id'] ?>]" class="es-especial-input" value="<?= $esEspecial ?>">
                                            </td>

                                            <td class="text-center align-middle font-weight-bold ventasCalculadas">0</td>
                                            <td class="text-center align-middle font-weight-bold stockFinal"><?= $stockInicial ?></td>
                                            <td class="text-right align-middle ventaMonto">$0.00</td>
                                            <td class="text-right align-middle deudaMonto <?= $esEspecial ? 'text-success' : 'text-danger' ?>">
                                                <?= $esEspecial ? '<span class="badge badge-success">PAGADO</span>' : '$0.00' ?>
                                            </td>
                                            <td class="text-right align-middle gananciaMonto text-success">$0.00</td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                    <tfoot class="bg-light font-weight-bold">
                                        <tr>
                                            <td colspan="5" class="text-right">TOTALES</td>
                                            <td class="text-right" id="totalVentas">$0.00</td>
                                            <td class="text-right" id="totalDeuda">$0.00</td>
                                            <td class="text-right" id="totalGanancia">$0.00</td>
                                        </tr>
                                    </tfoot>
                                </table>

                                <div class="text-right p-3">
                                    <button type="submit" class="btn btn-success">
                                        <i class="fas fa-save mr-1"></i> Guardar Conteo
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>

                    <!-- =================== INFO BOXES MEJORADOS =================== -->
                    <div class="row mt-4">
                        <div class="col-md-4 col-12 mb-3">
                            <div class="info-box bg-info">
                                <span class="info-box-icon"><i class="fas fa-cash-register"></i></span>
                                <div class="info-box-content">
                                    <span class="info-box-text">Ventas</span>
                                    <span class="info-box-number" id="infoVentas">$0.00</span>
                                    <small>Total vendido</small>
                                </div>
                            </div>
                        </div>

                        <div class="col-md-4 col-12 mb-3">
                            <div class="info-box bg-danger">
                                <span class="info-box-icon"><i class="fas fa-file-invoice-dollar"></i></span>
                                <div class="info-box-content">
                                    <span class="info-box-text">Deuda</span>
                                    <span class="info-box-number" id="infoDeuda">$0.00</span>
                                    <small>Costo de ventas</small>
                                </div>
                            </div>
                        </div>

                        <div class="col-md-4 col-12 mb-3">
                            <div class="info-box bg-success">
                                <span class="info-box-icon"><i class="fas fa-chart-line"></i></span>
                                <div class="info-box-content">
                                    <span class="info-box-text">Ganancia</span>
                                    <span class="info-box-number" id="infoGanancia">$0.00</span>
                                    <small>Margen bruto</small>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- =================== GRÁFICAS =================== -->
                    <div class="row mt-4">
                        <div class="col-md-6 col-12 mb-3">
                            <div class="card" style="height: 350px;">
                                <div class="card-header">
                                    <h6 class="mb-0"><i class="fas fa-chart-bar mr-2"></i> Resumen de Ventas</h6>
                                </div>
                                <div class="card-body">
                                    <canvas id="graficaVentas"></canvas>
                                </div>
                            </div>
                        </div>

                        <div class="col-md-6 col-12 mb-3">
                            <div class="card" style="height: 350px;">
                                <div class="card-header">
                                    <h6 class="mb-0"><i class="fas fa-chart-line mr-2"></i> Comparación de Stock</h6>
                                </div>
                                <div class="card-body">
                                    <canvas id="graficaStock"></canvas>
                                </div>
                            </div>
                        </div>
                    </div>

                <?php endif; ?>
            <?php endif; ?>

        </div>
    </section>
</div>

<!-- Scripts -->
<!-- jQuery PRIMERO -->
<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>

<!-- Bootstrap 5 Bundle (incluye Popper) -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>

<!-- Select2 -->
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>

<!-- Chart.js -->
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0"></script>

<!-- SweetAlert2 -->
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

<script>
document.addEventListener('DOMContentLoaded', function() {
    
    // ================= INICIALIZAR SELECT2 =================
    $('#proveedorSelect').select2({
        placeholder: 'Buscar proveedor...',
        allowClear: true,
        width: '100%',
        language: {
            noResults: function() {
                return "No se encontraron proveedores";
            },
            searching: function() {
                return "Buscando...";
            }
        }
    }).on('change', function() {
        if (this.value) {
            document.getElementById('proveedorForm').submit();
        }
    });

    // ================= BOTÓN REINICIAR =================
    const btnReset = document.getElementById('btnResetProveedor');
    if (btnReset) {
        btnReset.addEventListener('click', function() {
            Swal.fire({
                title: '¿Reiniciar selección?',
                text: 'Se quitará el proveedor seleccionado',
                icon: 'question',
                showCancelButton: true,
                confirmButtonColor: '#3085d6',
                cancelButtonColor: '#d33',
                confirmButtonText: 'Sí, reiniciar',
                cancelButtonText: 'Cancelar'
            }).then((result) => {
                if (result.isConfirmed) {
                    $('#proveedorSelect').val(null).trigger('change');
                    window.location.href = window.location.pathname;
                }
            });
        });
    }

    // ================= INICIALIZAR DROPDOWNS DE BOOTSTRAP 5 =================
    // Bootstrap 5 inicializa automáticamente, pero podemos forzar si es necesario
    var dropdownElementList = [].slice.call(document.querySelectorAll('.dropdown-toggle'))
    var dropdownList = dropdownElementList.map(function (dropdownToggleEl) {
        return new bootstrap.Dropdown(dropdownToggleEl)
    });

    <?php if ($proveedorSeleccionado && !empty($productos)): ?>

    // ================= VARIABLES PARA CONTROL DE MODIFICACIONES =================
    const inputsConteo = document.querySelectorAll('.stock-conteo');
    let hayModificaciones = false;

    function todasLasVentasSonCero() {
        let todasCero = true;
        inputsConteo.forEach(input => {
            const original = parseInt(input.dataset.original);
            const actual = parseInt(input.value) || 0;
            if (actual !== original) {
                todasCero = false;
            }
        });
        return todasCero;
    }

    inputsConteo.forEach(input => {
        input.addEventListener('input', function() {
            const original = parseInt(this.dataset.original);
            const actual = parseInt(this.value) || 0;
            
            if (actual !== original) {
                this.classList.add('modificado');
                hayModificaciones = true;
            } else {
                this.classList.remove('modificado');
                hayModificaciones = Array.from(inputsConteo).some(inp => 
                    parseInt(inp.value) !== parseInt(inp.dataset.original)
                );
            }
            recalcular();
            
            if (todasLasVentasSonCero() && hayModificaciones === false) {
                Swal.fire({
                    icon: 'info',
                    title: 'Ventas en cero',
                    text: 'Todos los artículos tienen ventas en cero. No podrás guardar hasta que modifiques al menos un artículo.',
                    showConfirmButton: true,
                    confirmButtonText: 'Entendido',
                    timer: 3000,
                    timerProgressBar: true
                });
            }
        });
    });

    // ================= BUSCADOR =================
    const buscarInput = document.getElementById('buscarProducto');
    const tablaBody = document.querySelector('#tablaProductos tbody');
    const filas = document.querySelectorAll('#tablaProductos tbody tr');
    const searchStats = document.getElementById('searchStats');
    const resultadosVisibles = document.getElementById('resultadosVisibles');
    const totalOriginal = filas.length;

    function buscarProductos() {
        const termino = buscarInput.value.toLowerCase().trim();
        let contadorVisibles = 0;

        filas.forEach(fila => {
            const nombreProducto = fila.querySelector('.nombre-producto').innerText.toLowerCase();
            if (nombreProducto.includes(termino)) {
                fila.classList.remove('hidden-row');
                contadorVisibles++;
            } else {
                fila.classList.add('hidden-row');
            }
        });

        if (termino.length > 0) {
            searchStats.style.display = 'block';
            resultadosVisibles.innerHTML = `Mostrando ${contadorVisibles} de ${totalOriginal} productos`;
            
            if (contadorVisibles === 0) {
                const noResultsRow = document.createElement('tr');
                noResultsRow.className = 'no-results-row';
                noResultsRow.innerHTML = `
                    <td colspan="8" class="text-center no-results">
                        <i class="fas fa-search"></i>
                        <p>No se encontraron productos con "<strong>${termino}</strong>"</p>
                    </td>
                `;
                
                const existingNoResults = document.querySelector('.no-results-row');
                if (existingNoResults) existingNoResults.remove();
                tablaBody.appendChild(noResultsRow);
            } else {
                const existingNoResults = document.querySelector('.no-results-row');
                if (existingNoResults) existingNoResults.remove();
            }
        } else {
            searchStats.style.display = 'none';
            filas.forEach(fila => fila.classList.remove('hidden-row'));
            const existingNoResults = document.querySelector('.no-results-row');
            if (existingNoResults) existingNoResults.remove();
        }

        recalcular();
    }

    let timeoutId;
    buscarInput.addEventListener('input', function() {
        clearTimeout(timeoutId);
        timeoutId = setTimeout(buscarProductos, 300);
    });

    buscarInput.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') {
            this.value = '';
            buscarProductos();
        }
    });

    // ================= GRÁFICAS =================
    let chartVentas = new Chart(document.getElementById('graficaVentas'), {
        type: 'bar',
        data: {
            labels: ['Ventas', 'Deuda', 'Ganancia'],
            datasets: [{
                data: [0, 0, 0],
                backgroundColor: ['#17a2b8', '#dc3545', '#28a745'],
                borderRadius: 4
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: { display: false },
                tooltip: {
                    callbacks: {
                        label: function(context) {
                            return '$' + context.raw.toFixed(2);
                        }
                    }
                }
            },
            scales: {
                y: {
                    beginAtZero: true,
                    ticks: {
                        callback: function(value) {
                            return '$' + value;
                        }
                    }
                }
            }
        }
    });

    const nombres = [];
    const stockInicialArr = [];
    const stockFinalArr = [];

    document.querySelectorAll('tbody tr:not(.no-results-row)').forEach(tr => {
        nombres.push(tr.querySelector('.nombre-producto').innerText.split('\n')[0].trim());
        stockInicialArr.push(parseInt(tr.dataset.stockInicial));
        stockFinalArr.push(parseInt(tr.dataset.stockInicial));
    });

    let chartStock = new Chart(document.getElementById('graficaStock'), {
        type: 'bar',
        data: {
            labels: nombres,
            datasets: [
                {
                    label: 'Stock Inicial',
                    data: stockInicialArr,
                    backgroundColor: '#007bff',
                    borderRadius: 4
                },
                {
                    label: 'Stock Final',
                    data: stockFinalArr,
                    backgroundColor: '#28a745',
                    borderRadius: 4
                }
            ]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                tooltip: {
                    callbacks: {
                        label: function(context) {
                            return context.dataset.label + ': ' + context.raw;
                        }
                    }
                }
            },
            scales: {
                y: {
                    beginAtZero: true,
                    ticks: {
                        stepSize: 1
                    }
                }
            }
        }
    });

    // ================= RECALCULO =================
    function recalcular() {
        let tv = 0, td = 0, tg = 0;

        document.querySelectorAll('tbody tr:not(.hidden-row):not(.no-results-row)').forEach((tr, index) => {
            let si = parseInt(tr.dataset.stockInicial);
            let pv = parseFloat(tr.dataset.precioVenta);
            let pc = parseFloat(tr.dataset.precioCompra);
            let esEspecial = tr.dataset.esEspecial === '1';

            let input = tr.querySelector('.stock-conteo');
            let sc = parseInt(input.value) || 0;

            sc = Math.min(Math.max(sc, 0), si);
            input.value = sc;

            let ventas = si - sc;
            let vm = ventas * pv;
            
            // Si es producto especial, la deuda es 0
            let dm = esEspecial ? 0 : ventas * pc;
            let gm = vm - dm;

            tr.querySelector('.ventasCalculadas').innerHTML = ventas;
            tr.querySelector('.stockFinal').innerHTML = sc;
            tr.querySelector('.ventaMonto').innerText = '$' + vm.toFixed(2);
            
            // Mostrar deuda según sea especial o no
            let deudaCell = tr.querySelector('.deudaMonto');
            if (esEspecial) {
                deudaCell.innerHTML = '<span class="badge badge-success">PAGADO</span>';
            } else {
                deudaCell.innerText = '$' + dm.toFixed(2);
            }
            
            tr.querySelector('.gananciaMonto').innerText = '$' + gm.toFixed(2);

            tr.querySelector('.ventas-input').value = ventas;
            tr.querySelector('.stock-final-input').value = sc;

            if (!tr.classList.contains('hidden-row')) {
                stockFinalArr[index] = sc;
            }

            tv += vm;
            td += dm;
            tg += gm;
        });

        document.getElementById('totalVentas').innerText = '$' + tv.toFixed(2);
        document.getElementById('totalDeuda').innerText = '$' + td.toFixed(2);
        document.getElementById('totalGanancia').innerText = '$' + tg.toFixed(2);

        document.getElementById('infoVentas').innerText = '$' + tv.toFixed(2);
        document.getElementById('infoDeuda').innerText = '$' + td.toFixed(2);
        document.getElementById('infoGanancia').innerText = '$' + tg.toFixed(2);

        chartVentas.data.datasets[0].data = [tv, td, tg];
        chartVentas.update();
        chartStock.update();
    }

    inputsConteo.forEach(input => {
        input.addEventListener('input', recalcular);
    });

    // ================= GUARDAR =================
    document.getElementById('formStockFinal').addEventListener('submit', function(e) {
        e.preventDefault();

        if (!hayModificaciones) {
            Swal.fire({
                icon: 'warning',
                title: 'Sin modificaciones',
                text: 'No has modificado ningún artículo. Debes cambiar al menos un valor para guardar.',
                confirmButtonColor: '#3085d6',
                confirmButtonText: 'Entendido'
            });
            return;
        }

        let valoresInvalidos = false;
        inputsConteo.forEach(input => {
            const valor = parseInt(input.value) || 0;
            const max = parseInt(input.max);
            if (valor < 0 || valor > max) {
                valoresInvalidos = true;
            }
        });

        if (valoresInvalidos) {
            Swal.fire({
                icon: 'error',
                title: 'Valores inválidos',
                text: 'Hay valores de stock fuera de rango. Por favor verifica.',
                confirmButtonColor: '#3085d6'
            });
            return;
        }

        if (todasLasVentasSonCero()) {
            Swal.fire({
                icon: 'error',
                title: 'Ventas en cero',
                text: 'No se puede guardar porque todas las ventas están en cero. Debes modificar al menos un artículo.',
                confirmButtonColor: '#3085d6',
                confirmButtonText: 'Entendido'
            });
            return;
        }

        Swal.fire({
            title: '¿Guardar conteo?',
            text: 'Se actualizará el stock de los productos modificados',
            icon: 'question',
            showCancelButton: true,
            confirmButtonColor: '#28a745',
            cancelButtonColor: '#d33',
            confirmButtonText: 'Sí, guardar',
            cancelButtonText: 'Cancelar'
        }).then((result) => {
            if (result.isConfirmed) {
                Swal.fire({
                    title: 'Guardando...',
                    text: 'Por favor espera',
                    allowOutsideClick: false,
                    didOpen: () => {
                        Swal.showLoading();
                    }
                });

                // Crear objeto con datos especiales
                const formData = new FormData(e.target);
                
                // Agregar información de productos especiales
                document.querySelectorAll('tr[data-es-especial="1"]').forEach(tr => {
                    const id = tr.dataset.id;
                    formData.append('especiales[' + id + ']', '1');
                });

                fetch('actualizar_stock.php', {
                    method: 'POST',
                    body: formData
                })
                .then(r => r.json())
                .then(res => {
                    if (res.status === 'ok') {
                        Swal.fire({
                            icon: 'success',
                            title: '¡Éxito!',
                            text: res.message || 'Venta guardada correctamente',
                            timer: 1500,
                            showConfirmButton: false
                        }).then(() => {
                            location.reload();
                        });
                    } else {
                        Swal.fire('Error', res.msg || 'Ocurrió un error', 'error');
                    }
                })
                .catch(() => {
                    Swal.fire('Error', 'Error de conexión', 'error');
                });
            }
        });
    });

    recalcular();

    <?php endif; ?>
    
    // ================= FECHAS POR DEFECTO =================
    function setDefaultDates() {
        const today = new Date();
        const firstDay = new Date(today.getFullYear(), today.getMonth(), 1);
        
        const formatDate = (date) => {
            const year = date.getFullYear();
            const month = String(date.getMonth() + 1).padStart(2, '0');
            const day = String(date.getDate()).padStart(2, '0');
            return `${year}-${month}-${day}`;
        };
        
        document.querySelectorAll('#excelForm input[type="date"], #pdfForm input[type="date"]').forEach((input, index) => {
            if (index % 2 === 0) {
                input.value = formatDate(firstDay);
            } else {
                input.value = formatDate(today);
            }
        });
    }
    
    setDefaultDates();

});
</script>