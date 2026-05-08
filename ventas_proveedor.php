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

// Función para quitar acentos
function quitarAcentos($texto) {
    $acentos = array(
        'Á' => 'A', 'É' => 'E', 'Í' => 'I', 'Ó' => 'O', 'Ú' => 'U',
        'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u',
        'Ñ' => 'N', 'ñ' => 'n',
        'Ü' => 'U', 'ü' => 'u'
    );
    return strtr($texto, $acentos);
}

// Obtener proveedores
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

<!-- Select2 CSS -->
<link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />

<!-- Estilos del Dashboard -->
<link rel="stylesheet" href="css/ventas_proveedor.css">

<div class="content-wrapper">
    <div class="container-fluid">
        
        <!-- BREADCRUMB BLANCO -->
        <div class="custom-breadcrumb">
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb">
                    <li class="breadcrumb-item">
                        <a href="<?= $_SESSION['rol'] === 'administrador' ? 'dashboard_admin.php' : 'dashboard_vendedor.php' ?>">
                            <i class="fas fa-home"></i> Inicio
                        </a>
                    </li>
                    <li class="breadcrumb-item">
                        <a href="dashboard_ventas.php">
                            <i class="fas fa-cash-register"></i> Registrar Venta
                        </a>
                    </li>
                    <li class="breadcrumb-item active">
                        <i class="fas fa-handshake"></i> Venta por Proveedor
                    </li>
                </ol>
            </nav>
        </div>

        <!-- SELECTOR DE PROVEEDOR -->
        <div class="card proveedor-card mb-4">
            <div class="card-header">
                <h5 class="mb-0">
                    <i class="fas fa-industry mr-2"></i> Venta por Proveedor
                    <?php if ($proveedorSeleccionado): ?>
                        <small class="ml-2" style="color: rgba(255,255,255,0.8);">(Mostrando solo productos)</small>
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
                            <label class="font-weight-bold text-muted mb-2">
                                <i class="fas fa-search mr-1"></i> Seleccionar proveedor
                            </label>
                            <select name="proveedor" id="proveedorSelect" style="width: 100%;">
                                <option value="">— Seleccione un proveedor —</option>
                                <?php foreach ($proveedores as $prov): ?>
                                    <option value="<?= htmlspecialchars($prov) ?>" <?= $proveedorSeleccionado === $prov ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($prov) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <small class="text-muted mt-1 d-block">
                                <i class="fas fa-info-circle"></i> Puedes buscar escribiendo en el campo
                            </small>
                        </div>
                    </div>
                </form>
            </div>
        </div>

        <?php if ($proveedorSeleccionado):

            // ========== CONSULTA SIMPLIFICADA - SIN COLLATE ==========
            $productos = [];
            
            // Consulta simple sin CASE
            $sql = "SELECT id, nombre, cantidad, precio_compra, precio_venta, fecha_registro, proveedor
                    FROM productos 
                    WHERE activo = 1 
                    AND tipo_inventario = 'producto'
                    ORDER BY nombre ASC";
            
            $result = $conn->query($sql);
            
            // Normalizar el proveedor seleccionado para comparación
            $proveedorNormalizado = quitarAcentos(strtolower(trim($proveedorSeleccionado)));
            
            while ($row = $result->fetch_assoc()) {
                // Normalizar el proveedor de la BD
                $provBD = $row['proveedor'] ?? '';
                $provBDNormalizado = quitarAcentos(strtolower(trim($provBD)));
                
                // Comparar ignorando acentos
                if ($provBDNormalizado == $proveedorNormalizado) {
                    // Verificar si es producto especial
                    $nombreNormalizado = quitarAcentos(strtolower(trim($row['nombre'])));
                    $esEspecial = 0;
                    
                    if ($nombreNormalizado == quitarAcentos(strtolower(PRODUCTO_ESPECIAL_NOMBRE)) && 
                        $provBDNormalizado == quitarAcentos(strtolower(PROVEEDOR_ESPECIAL))) {
                        $esEspecial = 1;
                    }
                    
                    $row['es_producto_especial'] = $esEspecial;
                    $productos[] = $row;
                }
            }
        ?>

            <?php if (empty($productos)): ?>
                <div class="alert alert-info mt-4" style="border-radius: 16px; border-left: 4px solid #3b82f6;">
                    <i class="fas fa-info-circle mr-2"></i>
                    <strong>Información:</strong> Este proveedor no tiene productos registrados o solo tiene insumos.
                </div>
            <?php else: ?>

                <!-- TABLA PRINCIPAL -->
                <div class="card shadow-sm mt-4" style="border-radius: 20px; border: none; overflow: hidden;">
                    <div class="card-header" style="background: white; border-bottom: 2px solid #f97316; padding: 15px 20px;">
                        <div class="d-flex flex-column flex-md-row justify-content-between align-items-start align-items-md-center gap-3">
                            <!-- Título izquierda -->
                            <div class="d-flex align-items-center flex-wrap gap-2">
                                <i class="fas fa-boxes" style="color: #f97316; font-size: 1.2rem;"></i>
                                <h5 class="mb-0 fw-bold" style="color: #1e293b;">
                                    Control de Stock – 
                                    <strong style="color: #f97316;"><?= htmlspecialchars($proveedorSeleccionado) ?></strong>
                                </h5>
                                <span class="badge" style="background: #f97316; color: white; padding: 5px 12px; border-radius: 20px;">
                                    <i class="fas fa-box me-1"></i> <?= count($productos) ?> productos
                                </span>
                            </div>

                            <!-- Acciones derecha -->
                            <div class="d-flex flex-column flex-sm-row align-items-stretch align-items-sm-center gap-2">
                                <!-- Buscador -->
                                <div class="position-relative" style="min-width: 220px;">
                                    <input type="text" id="buscarProducto" class="search-input" placeholder="Buscar producto..." style="width: 100%; padding-right: 35px;">
                                    <i class="fas fa-search search-icon" style="position: absolute; right: 12px; top: 50%; transform: translateY(-50%); color: #94a3b8;"></i>
                                </div>
                                
                                <!-- Grupo de botones de exportación -->
                                <div class="d-flex gap-2">
                                    <!-- Botón Excel -->
                                    <div class="dropdown">
                                        <button class="btn btn-excel dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false" style="background: #22c55e; border: none; color: white; border-radius: 40px; padding: 8px 18px; font-size: 0.75rem; font-weight: 600; transition: all 0.3s;">
                                            <i class="fas fa-file-excel me-1"></i> Excel
                                        </button>
                                        <ul class="dropdown-menu p-3" style="min-width: 280px; border-radius: 16px; border: none; box-shadow: 0 10px 25px rgba(0,0,0,0.1);">
                                            <li>
                                                <form id="excelForm" action="reporte_excel.php" method="GET" target="_blank">
                                                    <input type="hidden" name="proveedor" value="<?= htmlspecialchars($proveedorSeleccionado) ?>">
                                                    <div class="mb-3">
                                                        <label class="small fw-bold text-muted mb-1">Fecha inicio</label>
                                                        <input type="date" name="fecha_inicio" class="form-control form-control-sm" required style="border-radius: 10px; border: 1px solid #e2e8f0;">
                                                    </div>
                                                    <div class="mb-3">
                                                        <label class="small fw-bold text-muted mb-1">Fecha fin</label>
                                                        <input type="date" name="fecha_fin" class="form-control form-control-sm" required style="border-radius: 10px; border: 1px solid #e2e8f0;">
                                                    </div>
                                                    <button type="submit" class="btn w-100 mt-2" style="background: #22c55e; color: white; border-radius: 40px; padding: 8px; font-size: 0.75rem; font-weight: 600;">
                                                        <i class="fas fa-download me-1"></i> Exportar a Excel
                                                    </button>
                                                </form>
                                            </li>
                                        </ul>
                                    </div>
                                    <!-- Botón PDF -->
                                    <div class="dropdown">
                                        <button class="btn btn-pdf dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false" style="background: #ef4444; border: none; color: white; border-radius: 40px; padding: 8px 18px; font-size: 0.75rem; font-weight: 600; transition: all 0.3s;">
                                            <i class="fas fa-file-pdf me-1"></i> PDF
                                        </button>
                                        <ul class="dropdown-menu p-3" style="min-width: 280px; border-radius: 16px; border: none; box-shadow: 0 10px 25px rgba(0,0,0,0.1);">
                                            <li>
                                                <form id="pdfForm" action="reporte_pdf.php" method="GET">
                                                    <input type="hidden" name="proveedor" value="<?= htmlspecialchars($proveedorSeleccionado) ?>">
                                                    <div class="mb-3">
                                                        <label class="small fw-bold text-muted mb-1">Fecha inicio</label>
                                                        <input type="date" name="fecha_inicio" class="form-control form-control-sm" required style="border-radius: 10px; border: 1px solid #e2e8f0;">
                                                    </div>
                                                    <div class="mb-3">
                                                        <label class="small fw-bold text-muted mb-1">Fecha fin</label>
                                                        <input type="date" name="fecha_fin" class="form-control form-control-sm" required style="border-radius: 10px; border: 1px solid #e2e8f0;">
                                                    </div>
                                                    <button type="button" id="btnGenerarPDF" class="btn w-100 mt-2" style="background: #ef4444; color: white; border-radius: 40px; padding: 8px; font-size: 0.75rem; font-weight: 600;">
                                                        <i class="fas fa-download me-1"></i> Exportar a PDF
                                                    </button>
                                                </form>
                                            </li>
                                        </ul>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="card-body p-0">
                        <div class="search-stats px-3 py-2" id="searchStats" style="display: none; background: #f8fafc; border-bottom: 1px solid #eef2f6;">
                            <span id="resultadosVisibles" class="small text-muted"></span>
                        </div>

                        <form id="formStockFinal">
                            <div class="table-wrapper">
                                <table class="productos-table" id="tablaProductos">
                                    <thead>
                                        <tr>
                                            <th>Producto</th>
                                            <th class="col-stock">
                                                Stock Inicial
                                                <br><span style="font-size: 0.65rem;"><?= $fechaHoy ?></span>
                                            </th>
                                            <th class="col-conteo">
                                                Stock Contado
                                                <br><span style="font-size: 0.65rem;"><?= $fechaHoy ?></span>
                                            </th>
                                            <th class="col-ventas">Ventas</th>
                                            <th class="col-final">Stock Final</th>
                                            <th class="col-monto">Venta $</th>
                                            <th class="col-monto">Deuda $</th>
                                            <th class="col-monto">Ganancia $</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($productos as $p):
                                            $stockInicial = (int)$p['cantidad'];
                                            $fechaRegistro = date('d/m/Y', strtotime($p['fecha_registro']));
                                            $esEspecial = $p['es_producto_especial'];
                                        ?>
                                        <tr data-id="<?= $p['id'] ?>"
                                            data-stock-inicial="<?= $stockInicial ?>"
                                            data-precio-venta="<?= $p['precio_venta'] ?>"
                                            data-precio-compra="<?= $p['precio_compra'] ?>"
                                            data-nombre="<?= strtolower(htmlspecialchars($p['nombre'])) ?>"
                                            data-es-especial="<?= $esEspecial ?>">
                                            
                                            <td class="nombre-producto">
                                                <strong><?= htmlspecialchars($p['nombre']) ?></strong>
                                                <?php if ($esEspecial): ?>
                                                    <span class="badge-pagado">
                                                        <i class="fas fa-check-circle"></i> Pagado
                                                    </span>
                                                <?php endif; ?>
                                                <br><small class="text-muted">Reg: <?= $fechaRegistro ?></small>
                                            </td>

                                            <td class="text-center">
                                                <strong class="text-primary"><?= number_format($stockInicial) ?></strong>
                                            </td>

                                            <td class="text-center">
                                                <input type="number" 
                                                    class="stock-conteo" 
                                                    value="<?= $stockInicial ?>" 
                                                    min="0" 
                                                    max="<?= $stockInicial ?>"
                                                    data-original="<?= $stockInicial ?>"
                                                    data-es-especial="<?= $esEspecial ?>"
                                                    style="width: 90px;">
                                                <input type="hidden" name="ventas[<?= $p['id'] ?>]" class="ventas-input" value="0">
                                                <input type="hidden" name="stock_final[<?= $p['id'] ?>]" class="stock-final-input" value="<?= $stockInicial ?>">
                                                <input type="hidden" name="es_especial[<?= $p['id'] ?>]" class="es-especial-input" value="<?= $esEspecial ?>">
                                            </td>

                                            <td class="text-center ventasCalculadas fw-bold">0</td>
                                            <td class="text-center stockFinal fw-bold"><?= number_format($stockInicial) ?></td>
                                            <td class="text-right ventaMonto text-success fw-bold">$0.00</td>
                                            <td class="text-right deudaMonto <?= $esEspecial ? 'text-success' : 'text-danger' ?>">
                                                <?= $esEspecial ? '<span class="badge-pagado">PAGADO</span>' : '$0.00' ?>
                                            </td>
                                            <td class="text-right gananciaMonto text-success fw-bold">$0.00</td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                    <tfoot style="background: #f8fafc; border-top: 2px solid #f97316;">
                                        <tr class="fw-bold">
                                            <td colspan="5" class="text-right">TOTALES</td>
                                            <td class="text-right text-success" id="totalVentas">$0.00</td>
                                            <td class="text-right text-danger" id="totalDeuda">$0.00</td>
                                            <td class="text-right text-success" id="totalGanancia">$0.00</td>
                                        </tr>
                                    </tfoot>
                                </table>
                            </div>

                            <div class="text-right p-4">
                                <button type="submit" class="btn btn-guardar">
                                    <i class="fas fa-save mr-2"></i> Guardar Conteo
                                </button>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- INFO BOXES -->
                <div class="row mt-4">
                    <div class="col-md-4 col-12 mb-3">
                        <div class="info-box" style="background: linear-gradient(135deg, #3b82f6, #2563eb); color: white;">
                            <div class="info-box-icon"><i class="fas fa-cash-register"></i></div>
                            <div class="info-box-content">
                                <div class="info-box-text">Ventas</div>
                                <div class="info-box-number" id="infoVentas">$0.00</div>
                                <small>Total vendido</small>
                            </div>
                        </div>
                    </div>

                    <div class="col-md-4 col-12 mb-3">
                        <div class="info-box" style="background: linear-gradient(135deg, #ef4444, #dc2626); color: white;">
                            <div class="info-box-icon"><i class="fas fa-file-invoice-dollar"></i></div>
                            <div class="info-box-content">
                                <div class="info-box-text">Deuda</div>
                                <div class="info-box-number" id="infoDeuda">$0.00</div>
                                <small>Costo de ventas</small>
                            </div>
                        </div>
                    </div>

                    <div class="col-md-4 col-12 mb-3">
                        <div class="info-box" style="background: linear-gradient(135deg, #22c55e, #16a34a); color: white;">
                            <div class="info-box-icon"><i class="fas fa-chart-line"></i></div>
                            <div class="info-box-content">
                                <div class="info-box-text">Ganancia</div>
                                <div class="info-box-number" id="infoGanancia">$0.00</div>
                                <small>Margen bruto</small>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- GRÁFICAS -->
                <div class="row mt-4">
                    <div class="col-md-6 col-12 mb-4">
                        <div class="chart-card">
                            <div class="card-header">
                                <h6 class="mb-0"><i class="fas fa-chart-bar mr-2"></i> Resumen de Ventas</h6>
                            </div>
                            <div class="card-body">
                                <canvas id="graficaVentas"></canvas>
                            </div>
                        </div>
                    </div>

                    <div class="col-md-6 col-12 mb-4">
                        <div class="chart-card">
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
</div>

<!-- Scripts -->
<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

<script>
document.addEventListener('DOMContentLoaded', function() {
    
    // Inicializar Select2
    $('#proveedorSelect').select2({
        placeholder: 'Buscar proveedor...',
        allowClear: true,
        width: '100%'
    }).on('change', function() {
        if (this.value) {
            document.getElementById('proveedorForm').submit();
        }
    });

    // Botón reiniciar
    const btnReset = document.getElementById('btnResetProveedor');
    if (btnReset) {
        btnReset.addEventListener('click', function() {
            Swal.fire({
                title: '¿Reiniciar selección?',
                text: 'Se quitará el proveedor seleccionado',
                icon: 'question',
                showCancelButton: true,
                confirmButtonColor: '#f97316',
                cancelButtonColor: '#6c757d',
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

    <?php if ($proveedorSeleccionado && !empty($productos)): ?>

    const inputsConteo = document.querySelectorAll('.stock-conteo');
    let hayModificaciones = false;

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
        });
    });

    // Buscador mejorado con mensaje "No hay artículos"
    const buscarInput = document.getElementById('buscarProducto');
    const searchStats = document.getElementById('searchStats');
    const resultadosVisibles = document.getElementById('resultadosVisibles');
    const totalOriginal = document.querySelectorAll('#tablaProductos tbody tr:not(.no-results-row)').length;

    function eliminarFilaNoResultados() {
        const filaNoResultados = document.querySelector('.no-results-row');
        if (filaNoResultados) {
            filaNoResultados.remove();
        }
    }

    function crearFilaNoResultados(termino) {
        eliminarFilaNoResultados();
        const noResultsRow = document.createElement('tr');
        noResultsRow.className = 'no-results-row';
        noResultsRow.innerHTML = `
            <td colspan="8" class="text-center py-5">
                <div style="display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 10px;">
                    <i class="fas fa-search fa-3x" style="color: #cbd5e1;"></i>
                    <p class="text-muted mb-0" style="font-size: 0.9rem;">
                        No se encontraron productos con "<strong style="color: #f97316;">${escapeHtml(termino)}</strong>"
                    </p>
                    <small class="text-muted">Prueba con otro término de búsqueda</small>
                </div>
            </td>
        `;
        return noResultsRow;
    }

    function buscarProductos() {
        const termino = buscarInput.value.toLowerCase().trim();
        let contadorVisibles = 0;
        
        const filasProductos = document.querySelectorAll('#tablaProductos tbody tr:not(.no-results-row)');
        
        filasProductos.forEach(fila => {
            const nombreProducto = fila.querySelector('.nombre-producto').innerText.toLowerCase();
            if (termino === '' || nombreProducto.includes(termino)) {
                fila.classList.remove('hidden-row');
                contadorVisibles++;
            } else {
                fila.classList.add('hidden-row');
            }
        });
        
        if (termino.length > 0) {
            searchStats.style.display = 'block';
            resultadosVisibles.innerHTML = `<i class="fas fa-filter me-1"></i> Mostrando ${contadorVisibles} de ${totalOriginal} productos`;
            
            if (contadorVisibles === 0) {
                const noResultsRow = crearFilaNoResultados(termino);
                document.querySelector('#tablaProductos tbody').appendChild(noResultsRow);
            } else {
                eliminarFilaNoResultados();
            }
        } else {
            searchStats.style.display = 'none';
            filasProductos.forEach(fila => fila.classList.remove('hidden-row'));
            eliminarFilaNoResultados();
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

    function escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }

    // Gráficas
    let chartVentas = new Chart(document.getElementById('graficaVentas'), {
        type: 'bar',
        data: {
            labels: ['Ventas', 'Deuda', 'Ganancia'],
            datasets: [{
                data: [0, 0, 0],
                backgroundColor: ['#3b82f6', '#ef4444', '#22c55e'],
                borderRadius: 8
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: true,
            plugins: { legend: { display: false } },
            scales: { y: { beginAtZero: true, ticks: { callback: function(v) { return '$' + v.toLocaleString(); } } } }
        }
    });

    const nombres = [];
    const stockInicialArr = [];
    const stockFinalArr = [];

    document.querySelectorAll('#tablaProductos tbody tr').forEach(tr => {
        let nombre = tr.querySelector('.nombre-producto').innerText.split('\n')[0].trim();
        if (nombre.length > 20) nombre = nombre.substring(0, 18) + '...';
        nombres.push(nombre);
        stockInicialArr.push(parseInt(tr.dataset.stockInicial));
        stockFinalArr.push(parseInt(tr.dataset.stockInicial));
    });

    let chartStock = new Chart(document.getElementById('graficaStock'), {
        type: 'bar',
        data: {
            labels: nombres,
            datasets: [
                { label: 'Stock Inicial', data: stockInicialArr, backgroundColor: '#3b82f6', borderRadius: 8 },
                { label: 'Stock Final', data: stockFinalArr, backgroundColor: '#22c55e', borderRadius: 8 }
            ]
        },
        options: {
            responsive: true,
            maintainAspectRatio: true,
            plugins: { legend: { position: 'top' } },
            scales: { y: { beginAtZero: true, ticks: { stepSize: 1 } } }
        }
    });

    function recalcular() {
        let tv = 0, td = 0, tg = 0;
        let index = 0;

        document.querySelectorAll('#tablaProductos tbody tr:not(.hidden-row)').forEach(tr => {
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
            let dm = esEspecial ? 0 : ventas * pc;
            let gm = vm - dm;

            tr.querySelector('.ventasCalculadas').innerHTML = ventas;
            tr.querySelector('.stockFinal').innerHTML = sc;
            tr.querySelector('.ventaMonto').innerHTML = '$' + vm.toFixed(2);
            
            let deudaCell = tr.querySelector('.deudaMonto');
            if (esEspecial) {
                deudaCell.innerHTML = '<span class="badge-pagado">PAGADO</span>';
            } else {
                deudaCell.innerHTML = '$' + dm.toFixed(2);
            }
            
            tr.querySelector('.gananciaMonto').innerHTML = '$' + gm.toFixed(2);
            tr.querySelector('.ventas-input').value = ventas;
            tr.querySelector('.stock-final-input').value = sc;

            if (!tr.classList.contains('hidden-row')) {
                stockFinalArr[index] = sc;
            }
            index++;

            tv += vm;
            td += dm;
            tg += gm;
        });

        document.getElementById('totalVentas').innerHTML = '$' + tv.toFixed(2);
        document.getElementById('totalDeuda').innerHTML = '$' + td.toFixed(2);
        document.getElementById('totalGanancia').innerHTML = '$' + tg.toFixed(2);
        document.getElementById('infoVentas').innerHTML = '$' + tv.toFixed(2);
        document.getElementById('infoDeuda').innerHTML = '$' + td.toFixed(2);
        document.getElementById('infoGanancia').innerHTML = '$' + tg.toFixed(2);

        chartVentas.data.datasets[0].data = [tv, td, tg];
        chartVentas.update();
        chartStock.update();
    }

    inputsConteo.forEach(input => input.addEventListener('input', recalcular));
    recalcular();

    // Guardar
    document.getElementById('formStockFinal').addEventListener('submit', function(e) {
        e.preventDefault();

        if (!hayModificaciones) {
            Swal.fire({ icon: 'warning', title: 'Sin modificaciones', text: 'Debes modificar al menos un artículo para guardar.', confirmButtonColor: '#f97316' });
            return;
        }

        Swal.fire({
            title: '¿Guardar conteo?',
            text: 'Se actualizará el stock de los productos modificados',
            icon: 'question',
            showCancelButton: true,
            confirmButtonColor: '#22c55e',
            cancelButtonColor: '#6c757d',
            confirmButtonText: 'Sí, guardar',
            cancelButtonText: 'Cancelar'
        }).then((result) => {
            if (result.isConfirmed) {
                Swal.fire({ title: 'Guardando...', allowOutsideClick: false, didOpen: () => Swal.showLoading() });

                const formData = new FormData(e.target);
                fetch('actualizar_stock.php', { method: 'POST', body: formData })
                    .then(r => r.json())
                    .then(res => {
                        if (res.status === 'ok') {
                            Swal.fire({ icon: 'success', title: '¡Éxito!', text: res.message, timer: 1500, showConfirmButton: false })
                                .then(() => location.reload());
                        } else {
                            Swal.fire('Error', res.msg || 'Ocurrió un error', 'error');
                        }
                    })
                    .catch(() => Swal.fire('Error', 'Error de conexión', 'error'));
            }
        });
    });

    <?php endif; ?>

    // Fechas por defecto
    function setDefaultDates() {
        const today = new Date();
        const firstDay = new Date(today.getFullYear(), today.getMonth(), 1);
        const formatDate = (date) => `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}-${String(date.getDate()).padStart(2, '0')}`;
        
        document.querySelectorAll('#excelForm input[type="date"], #pdfForm input[type="date"]').forEach((input, index) => {
            input.value = index % 2 === 0 ? formatDate(firstDay) : formatDate(today);
        });
    }
    setDefaultDates();

});

// Función para generar PDF con descarga automática + nueva ventana
document.getElementById('btnGenerarPDF')?.addEventListener('click', function() {
    const form = document.getElementById('pdfForm');
    const fechaInicio = form.querySelector('input[name="fecha_inicio"]').value;
    const fechaFin = form.querySelector('input[name="fecha_fin"]').value;
    const proveedor = form.querySelector('input[name="proveedor"]').value;
    
    if (!fechaInicio || !fechaFin) {
        Swal.fire({
            icon: 'warning',
            title: 'Fechas requeridas',
            text: 'Por favor selecciona ambas fechas',
            confirmButtonColor: '#ef4444'
        });
        return;
    }
    
    // Mostrar loading
    Swal.fire({
        title: 'Generando PDF...',
        text: 'Por favor espera',
        allowOutsideClick: false,
        didOpen: () => {
            Swal.showLoading();
        }
    });
    
    // Construir URL con parámetros
    const url = `reporte_pdf.php?proveedor=${encodeURIComponent(proveedor)}&fecha_inicio=${encodeURIComponent(fechaInicio)}&fecha_fin=${encodeURIComponent(fechaFin)}`;
    
    // Opción 1: Abrir en nueva ventana y descargar automáticamente
    // Esto funciona si el PHP envía los headers correctos (Content-Disposition: attachment)
    
    // Crear un enlace temporal para descargar
    const link = document.createElement('a');
    link.href = url;
    link.target = '_blank';
    link.download = `reporte_${proveedor}_${fechaInicio}_${fechaFin}.pdf`;
    
    // Simular clic para descargar
    document.body.appendChild(link);
    link.click();
    document.body.removeChild(link);
    
    // También abrir en nueva ventana
    window.open(url, '_blank');
    
    // Cerrar SweetAlert después de un momento
    setTimeout(() => {
        Swal.close();
        Swal.fire({
            icon: 'success',
            title: 'PDF Generado',
            text: 'El PDF se ha descargado y abierto en una nueva pestaña',
            timer: 2000,
            showConfirmButton: false
        });
    }, 1500);
});

</script>

<?php include('includes/footer.php'); ?>