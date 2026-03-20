<?php 
include 'includes/session.php';
include 'includes/db.php';
include 'includes/header.php';
include 'includes/navbar.php';

// ---------- SERVIDOR: PROCESAMIENTO POST (registro definitivo) ----------
if (isset($_POST['registrar_semanal_confirmado'])) {

    // $_POST['cantidades'] viene del formulario final enviado
    $cantidades = $_POST['cantidades'] ?? [];
    $erroresStock = [];

    // Revalidar en servidor que no quede stock negativo
    foreach ($cantidades as $id_producto => $cantidad) {
        $cantidad = intval($cantidad);
        if ($cantidad > 0) {
            // MODIFICADO: Verificar que sea producto antes de actualizar
            $q = $conn->query("
                SELECT cantidad, tipo_inventario 
                FROM productos 
                WHERE id = " . intval($id_producto)
            );
            if (!$q) continue;
            $prod = $q->fetch_assoc();
            
            // Validar que sea producto
            if($prod['tipo_inventario'] !== 'producto') {
                $erroresStock[] = $id_producto;
                continue;
            }
            
            if ($cantidad > $prod['cantidad']) {
                $erroresStock[] = $id_producto;
            }
        }
    }

    if (!empty($erroresStock)) {
        // Swal error y redirección
        echo "
        <script src='https://cdn.jsdelivr.net/npm/sweetalert2@11'></script>
        <script>
        Swal.fire({
            icon: 'error',
            title: 'Inventario insuficiente o producto inválido',
            text: 'Verifica las cantidades y que solo estés vendiendo productos.',
            confirmButtonColor: '#d33'
        }).then(()=>{ window.location='venta_admin.php'; });
        </script>";
        exit;
    }


    //   GENERAR FOLIO DEL TICKET
  
   $folio_ticket = "VENTA_" . date("Y-m-d_H:i:s") . "-" . rand(1000, 9999);

    //   REGISTRAR VENTAS

    foreach ($cantidades as $id_producto => $cantidad) {

        $cantidad = intval($cantidad);

        if ($cantidad > 0) {

            // Actualizar inventario (solo productos)
            $conn->query("
                UPDATE productos 
                SET cantidad = cantidad - $cantidad 
                WHERE id = " . intval($id_producto) . "
                AND tipo_inventario = 'producto'
            ");

            // Insertar en ventas con FOLIO
            $conn->query("
                INSERT INTO ventas (folio_ticket, id_producto, cantidad_vendida, fecha_venta)
                VALUES ('$folio_ticket', ".intval($id_producto).", $cantidad, NOW())
            ");
        }
    }


    // Mensaje éxito con SweetAlert y redirección
    echo "
    <script src='https://cdn.jsdelivr.net/npm/sweetalert2@11'></script>
    <script>
    Swal.fire({
        icon: 'success',
        title: 'Registro semanal guardado',
        text: 'La actualización del inventario fue exitosa.\\nFolio generado: $folio_ticket',
        confirmButtonColor: '#28a745'
    }).then(()=>{ window.location='venta_admin.php'; });
    </script>";
    exit;
}


// ---------- CONSULTA PRODUCTOS (para construir la tabla) ----------
// MODIFICADO: Solo mostrar productos (no insumos)
$productos = $conn->query("
    SELECT id, nombre, cantidad, precio_compra, precio_venta, proveedor
    FROM productos
    WHERE tipo_inventario = 'producto'
    ORDER BY nombre ASC
");

$total_productos = $productos->num_rows;
?>

<!-- Styles (paleta naranja suave, minimalista) -->
<style>
/* Fondo general */
.content-wrapper { background: #FFF4E6 !important; padding: 25px; }

/* Header */
.content-header h1 { font-weight: 600; color: #d87a2f !important; }
.content-header p { color: #8a827a !important; }

/* Card */
.card-primary { border: none; border-radius: 14px; background: #ffffff !important; }
.card-header.bg-primary { background: #e69138 !important; border-radius: 14px 14px 0 0; border: none; }
.card-title { font-weight: 600; color: #fff !important; }

/* Table */
.table { background: white; border-radius: 8px; overflow: hidden; }
.table thead { background: #f3e1d2; color: #6a4a38; font-weight: bold; }
.table tbody tr:hover { background: #fff4e8; transition: 0.2s ease-in-out; }

/* Stock badge */
.badge-info { background: #ffb366 !important; color: #4a2a12; }

/* Inputs */
.table input.form-control { border: 1px solid #e6c4a8; border-radius: 6px; }
.table input.form-control:focus { border-color: #e69138; box-shadow: 0 0 4px rgba(230,145,56,0.6); }

/* Row highlight when qty >0 */
.table tbody tr.active-row { background: #fff8ef; }

/* Stock low/critical */
.stock-low { background: #fff7ed; }      /* visually same as hover but can color text */
.stock-critical { background: #fff0e6; border-left: 4px solid #d9534f; }

/* Botones principales */
.btn-success { background: #e69138 !important; border: none !important; padding: 10px 20px; font-size: 16px; border-radius: 8px; }
.btn-success:hover { background: #cf7d22 !important; transform: translateY(-2px); transition: .15s; }

/* Tool area */
.tool-row { display:flex; gap:10px; align-items:center; margin-bottom:12px; }

/* Chart container */
#previewChart { max-width: 700px; height: 320px; margin: 0 auto; }

/* Summary table in Swal html - small styles */
.summary-table { width:100%; border-collapse:collapse; }
.summary-table th, .summary-table td { padding:6px 8px; border-bottom:1px solid #eee; text-align:left; font-size:13px; }
.summary-total { font-weight:700; color:#6a4a38; }

/* Estilos para el buscador */
.buscador-container {
    position: relative;
    min-width: 300px;
}

.clear-search {
    position: absolute;
    right: 45px;
    top: 50%;
    transform: translateY(-50%);
    color: #999;
    cursor: pointer;
    z-index: 10;
    display: none;
    font-size: 16px;
}

.clear-search:hover {
    color: #e69138;
}

.buscador-container input:not(:placeholder-shown) + .clear-search,
.buscador-container input:focus + .clear-search {
    display: block;
}

/* Mensaje sin resultados */
#noResultadosMensaje td {
    background: #fff9f0;
    color: #8a6d4f;
    font-style: italic;
    padding: 30px;
}

/* BUSCADOR FUSIONADO - BIEN A LA DERECHA */
.buscador-container {
    margin-left: auto;
    min-width: 300px;
}

.buscador-fusionado {
    background: white;
    border-radius: 30px !important;
    overflow: hidden;
    box-shadow: 0 2px 8px rgba(0,0,0,0.1);
    transition: all 0.3s ease;
    border: 1px solid rgba(255,255,255,0.3);
}

.buscador-fusionado:hover,
.buscador-fusionado:focus-within {
    box-shadow: 0 4px 12px rgba(0,0,0,0.15);
    border-color: #fff;
}

.contador-prepend .input-group-text {
    background: #f8f9fa !important;
    color: #000000 !important;
    font-weight: 600;
    font-size: 13px;
    padding-right: 12px;
    border-radius: 30px 0 0 30px !important;
}

.contador-prepend .input-group-text i {
    color: #e69138;
}

.search-prepend .input-group-text {
    background: white !important;
    padding-left: 5px;
    padding-right: 5px;
}

.search-prepend .input-group-text i {
    color: #adb5bd;
}

.buscador-fusionado input.form-control {
    padding-left: 5px;
    font-size: 14px;
}

.buscador-fusionado input.form-control::placeholder {
    color: #adb5bd;
    font-style: italic;
}

.buscador-fusionado input.form-control:focus {
    box-shadow: none;
}

.clear-search {
    color: #adb5bd;
    cursor: pointer;
    font-size: 16px;
    padding: 0 12px;
    transition: all 0.2s ease;
    display: flex;
    align-items: center;
    height: 100%;
}

.clear-search:hover {
    color: #e69138;
    transform: scale(1.1);
}

/* Ocultar el clear cuando el input está vacío */
.buscador-fusionado input:placeholder-shown ~ .input-group-append .clear-search {
    opacity: 0;
    pointer-events: none;
}

/* Responsive */
@media (max-width: 768px) {
    .card-header.bg-primary {
        flex-direction: column;
        align-items: stretch !important;
        gap: 10px;
    }
    
    .buscador-container {
        margin-left: 0;
        min-width: auto;
    }
}
</style>

<!-- Dependencias JS (SweetAlert2, Chart.js, html2canvas, jsPDF, SheetJS) -->
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/html2canvas/1.4.1/html2canvas.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/xlsx@0.18.5/dist/xlsx.full.min.js"></script>

<!-- CONTENIDO ADMIN LTE -->
<div class="content-wrapper">
    <section class="content-header">
        <h1 class="text-primary">Registro Semanal de Ventas</h1>
        <p class="text-muted">
            Registra cuántas unidades se vendieron esta semana para actualizar estadísticas e inventario.
        </p>
    </section>

    <section>
        <div class="card card-primary shadow">
            <div class="card-header bg-primary d-flex justify-content-between align-items-center">
                <div class="d-flex align-items-center">
                    <h3 class="card-title mb-0">Captura de cantidades vendidas</h3>
                </div>
                
                <!-- BUSCADOR Y CONTADOR FUSIONADO - BIEN A LA DERECHA -->
                <div class="buscador-container">
                    <div class="input-group input-group-sm buscador-fusionado">
                        <div class="input-group-prepend contador-prepend">
                            <span class="input-group-text bg-white border-0">
                                <i class="fas fa-box text-muted mr-1"></i>
                                <span id="contadorProductos"><?= $total_productos ?></span>
                            </span>
                        </div>
                        <div class="input-group-prepend search-prepend">
                            <span class="input-group-text bg-white border-0">
                                <i class="fas fa-search text-muted"></i>
                            </span>
                        </div>
                        <input type="text" 
                            id="buscadorProductos" 
                            class="form-control border-0" 
                            placeholder="Buscar producto o proveedor...">
                        <div class="input-group-append">
                            <span class="input-group-text bg-white border-0 p-0">
                                <span class="clear-search" onclick="limpiarBusqueda()" title="Limpiar búsqueda">
                                    <i class="fas fa-times-circle"></i>
                                </span>
                            </span>
                        </div>
                    </div>
                </div>
            </div>

            <div class="card-body">
                <div class="tool-row">
                    <button id="btnPreview" class="btn btn-info"><i class="fas fa-eye"></i> Previsualizar & Confirmar</button>
                    <div style="margin-left:auto;color:#6a4a38;font-size:14px;">
                        <i class="fas fa-exclamation-circle" style="color:#d87a2f;margin-right:6px"></i>
                        <span>Stocks críticos en rojo (≤5)</span>
                    </div>
                </div>

                <form method="POST" id="formVentaAdmin">
                    <div class="table-responsive">
                        <table class="table table-bordered table-striped table-hover" id="tablaProductos">
                            <thead>
                                <tr>
                                    <th>Producto</th>
                                    <th>Proveedor</th>
                                    <th>Stock Actual</th>
                                    <th>Precio Compra</th>
                                    <th>Precio Venta</th>
                                    <th>Cantidad Vendida (Semana)</th>
                                    <th>Utilidad Est.</th>
                                    <th>Stock Final</th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php while($p = $productos->fetch_assoc()): 
                                // atributos: id, nombre, cantidad, precio_compra, precio_venta, proveedor
                                $id = intval($p['id']);
                            ?>
                                <tr data-id="<?= $id ?>">
                                    <td><?= htmlspecialchars($p['nombre']) ?></td>
                                    <td><?= htmlspecialchars($p['proveedor']) ?></td>
                                    <td class="stock-current"><span class="badge badge-info"><?= $p['cantidad'] ?></span></td>
                                    <td>$<?= number_format($p['precio_compra'],2) ?></td>
                                    <td>$<?= number_format($p['precio_venta'],2) ?></td>
                                    <td>
                                        <input type="number" 
                                            class="form-control qty-input" 
                                            name="cantidades[<?= $id ?>]" 
                                            min="0" 
                                            value="0"
                                            data-stock="<?= intval($p['cantidad']) ?>"
                                            data-pcomp="<?= floatval($p['precio_compra']) ?>"
                                            data-pvent="<?= floatval($p['precio_venta']) ?>">
                                    </td>
                                    <td class="utilidad-cell">$0.00</td>
                                    <td class="stock-final-cell"><?= intval($p['cantidad']) ?></td>
                                </tr>
                            <?php endwhile; ?>
                            </tbody>
                        </table>
                    </div>
                    <!-- Botón oculto de envío final: será presionado por JS luego de confirmación -->
                    <input type="hidden" name="registrar_semanal_confirmado" value="1">
                </form>

                <!-- Area para la gráfica en la previsualización -->
                <div id="previewChartWrap" style="display:none;margin-top:18px;">
                    <canvas id="previewChart"></canvas>
                </div>

            </div>

            <div class="card-footer text-right">
                <button id="btnSubmitFinal" class="btn btn-success btn-lg">
                    <i class="fas fa-save"></i> Guardar Registro Semanal
                </button>
            </div>
        </div>
    </section>
</div>

<script>
/* ------------------------------
   UTIL: form, inputs y listeners
   ------------------------------*/
const qtyInputs = document.querySelectorAll('.qty-input');
const previewBtn = document.getElementById('btnPreview');
const submitFinalBtn = document.getElementById('btnSubmitFinal');
const form = document.getElementById('formVentaAdmin');
let currentChart = null;

// Umbral de stock bajo/critico
const CRITICAL_THRESHOLD = 5;
const LOW_THRESHOLD = 6;

// Función que recalcula utilidades y stock final por fila
function recalcRow(input) {
    const tr = input.closest('tr');
    const stock = parseInt(input.dataset.stock) || 0;
    const pcomp = parseFloat(input.dataset.pcomp) || 0;
    const pvent = parseFloat(input.dataset.pvent) || 0;
    const qty = Math.max(0, parseInt(input.value) || 0);

    const utilidad = qty * (pvent - pcomp);
    const stockFinal = stock - qty;

    // actualizar celdas
    tr.querySelector('.utilidad-cell').textContent = '$' + utilidad.toFixed(2);
    tr.querySelector('.stock-final-cell').textContent = stockFinal;

    // marcar fila activa si qty>0
    if (qty > 0) tr.classList.add('active-row'); else tr.classList.remove('active-row');

    // marcar advertencias por stock
    if (stockFinal < 0) {
        tr.classList.add('stock-critical');
        tr.classList.remove('stock-low');
    } else if (stockFinal <= CRITICAL_THRESHOLD) {
        tr.classList.add('stock-critical');
        tr.classList.remove('stock-low');
    } else if (stockFinal <= LOW_THRESHOLD) {
        tr.classList.add('stock-low');
        tr.classList.remove('stock-critical');
    } else {
        tr.classList.remove('stock-low','stock-critical');
    }
}

// Attach listeners para recalcular en vivo
qtyInputs.forEach(input => {
    recalcRow(input);
    input.addEventListener('input', () => recalcRow(input));
    input.addEventListener('change', () => recalcRow(input));
});

// ------------------------------
// BUSCADOR DE PRODUCTOS
// ------------------------------
const buscador = document.getElementById('buscadorProductos');
const contadorSpan = document.getElementById('contadorProductos');
const filasProductos = document.querySelectorAll('#tablaProductos tbody tr');

function filtrarProductos() {
    const texto = buscador.value.toLowerCase().trim();
    let visibles = 0;
    
    filasProductos.forEach(fila => {
        // Obtener texto de búsqueda de la fila (producto + proveedor)
        const producto = fila.children[0].textContent.toLowerCase();
        const proveedor = fila.children[1].textContent.toLowerCase();
        const textoCompleto = producto + ' ' + proveedor;
        
        if (texto === '' || textoCompleto.includes(texto)) {
            fila.style.display = '';
            visibles++;
        } else {
            fila.style.display = 'none';
        }
    });
    
    // Actualizar contador
    contadorSpan.textContent = visibles;
    
    // Mostrar mensaje si no hay resultados
    const tabla = document.getElementById('tablaProductos');
    const tbody = tabla.querySelector('tbody');
    let mensajeNoResultados = document.getElementById('noResultadosMensaje');
    
    if (visibles === 0 && texto !== '') {
        if (!mensajeNoResultados) {
            mensajeNoResultados = document.createElement('tr');
            mensajeNoResultados.id = 'noResultadosMensaje';
            mensajeNoResultados.innerHTML = `
                <td colspan="8" class="text-center py-4">
                    <div class="text-muted">
                        <i class="fas fa-search fa-2x mb-2"></i><br>
                        No se encontraron productos que coincidan con "<strong>${texto}</strong>"
                    </div>
                </td>
            `;
            tbody.appendChild(mensajeNoResultados);
        } else {
            mensajeNoResultados.querySelector('td').innerHTML = `
                <div class="text-muted">
                    <i class="fas fa-search fa-2x mb-2"></i><br>
                    No se encontraron productos que coincidan con "<strong>${texto}</strong>"
                </div>
            `;
        }
    } else {
        if (mensajeNoResultados) {
            mensajeNoResultados.remove();
        }
    }
}

// Función para limpiar búsqueda
function limpiarBusqueda() {
    const buscador = document.getElementById('buscadorProductos');
    buscador.value = '';
    filtrarProductos();
    buscador.focus();
}

// Evento para el buscador (con debounce para mejor rendimiento)
let timeoutId;
buscador.addEventListener('input', function() {
    clearTimeout(timeoutId);
    timeoutId = setTimeout(filtrarProductos, 300); // 300ms de delay
});

// También filtrar al presionar Enter
buscador.addEventListener('keypress', function(e) {
    if (e.key === 'Enter') {
        e.preventDefault();
        clearTimeout(timeoutId);
        filtrarProductos();
    }
});

// Inicializar contador
contadorSpan.textContent = filasProductos.length;

// ------------------------------
// Generar resumen de preview
// ------------------------------
function gatherPreviewData() {
    const rows = document.querySelectorAll('#tablaProductos tbody tr:not(#noResultadosMensaje)');
    const items = [];
    let totalVenta = 0;
    let totalUtilidad = 0;
    let totalCant = 0;
    let hasNegative = false;

    rows.forEach(r => {
        // Solo considerar filas visibles y que no sean el mensaje de no resultados
        if (r.style.display === 'none' || r.id === 'noResultadosMensaje') return;
        
        const id = r.dataset.id;
        const nombre = r.children[0].textContent.trim();
        const proveedor = r.children[1].textContent.trim();
        const stock = parseInt(r.querySelector('.qty-input').dataset.stock) || 0;
        const qty = Math.max(0, parseInt(r.querySelector('.qty-input').value) || 0);
        const pcomp = parseFloat(r.querySelector('.qty-input').dataset.pcomp) || 0;
        const pvent = parseFloat(r.querySelector('.qty-input').dataset.pvent) || 0;
        const utilidad = qty * (pvent - pcomp);
        const venta = qty * pvent;
        const stockFinal = stock - qty;

        if (qty > 0) {
            items.push({
                id, nombre, proveedor, stock, qty, pcomp, pvent, utilidad, venta, stockFinal
            });
        }

        totalVenta += venta;
        totalUtilidad += utilidad;
        totalCant += qty;

        if (stockFinal < 0) hasNegative = true;
    });

    // ordenar por qty desc para gráfica/top
    items.sort((a,b) => b.qty - a.qty);

    return { items, totalVenta, totalUtilidad, totalCant, hasNegative };
}

// ------------------------------
// Mostrar preview modal con Swal + Grafica
// ------------------------------
previewBtn.addEventListener('click', async function() {
    const data = gatherPreviewData();

    if (data.totalCant === 0) {
        Swal.fire({ icon:'warning', title:'Sin cantidades', text:'No ingresaste ninguna cantidad para registrar.' });
        return;
    }

    // Si hay negativos en stock, alerta y no permite continuar
    if (data.hasNegative) {
        Swal.fire({ icon:'error', title:'Stock insuficiente', text:'Una o más filas dejarían stock negativo. Ajusta las cantidades.' });
        return;
    }

    // Construir HTML resumen (tabla pequeña)
    let html = '<div style=\"max-height:380px;overflow:auto\">';
    html += '<table class=\"summary-table\"><thead><tr><th>Producto</th><th>Cant.</th><th>Venta Est.</th><th>Utilidad Est.</th><th>Stock final</th></tr></thead><tbody>';
    data.items.forEach(it => {
        html += `<tr>
            <td>${it.nombre}</td>
            <td>${it.qty}</td>
            <td>$${it.venta.toFixed(2)}</td>
            <td>$${it.utilidad.toFixed(2)}</td>
            <td>${it.stockFinal}</td>
        </tr>`;
    });
    html += `</tbody></table>`;
    html += `<hr/><div style='padding:6px 0'><strong>Total Productos:</strong> ${data.totalCant} &nbsp;&nbsp; <strong>Total Venta:</strong> $${data.totalVenta.toFixed(2)} &nbsp;&nbsp; <strong>Total Utilidad:</strong> $${data.totalUtilidad.toFixed(2)}</div>`;
    html += '</div>';

    // Crear un contenedor temporal para la gráfica
    const chartContainer = document.createElement('div');
    chartContainer.style.width = '100%';
    chartContainer.style.marginTop = '12px';
    chartContainer.innerHTML = '<canvas id=\"swalChart\" style=\"width:100%;max-width:600px;height:240px\"></canvas>';

    // Mostrar Swal con HTML y contenedor (usa preConfirm para enviar)
    const { value } = await Swal.fire({
        title: 'Resumen previo - Confirma',
        html: html,
        showCancelButton: true,
        confirmButtonText: 'Confirmar y registrar',
        cancelButtonText: 'Cancelar',
        width: 800,
        didOpen: () => {
            // Insertar gráfica al final del contenido del swal
            const content = Swal.getHtmlContainer();
            content.appendChild(chartContainer);
            // Render chart
            renderSwalChart(data.items);
        },
        preConfirm: () => {
            // construir un objeto oculto con las cantidades para enviar por POST
            // añadimos inputs hidden al formulario y enviamos
            // limpiar inputs hidden previos
            const existing = document.querySelectorAll('.hidden-post-input');
            existing.forEach(e => e.remove());

            data.items.forEach(it => {
                const input = document.createElement('input');
                input.type = 'hidden';
                input.name = `cantidades[${it.id}]`;
                input.value = it.qty;
                input.classList.add('hidden-post-input');
                form.appendChild(input);
            });

            // agregar indicador de confirmacion (ya tenemos registrar_semanal_confirmado en servidor)
            const marker = document.createElement('input');
            marker.type = 'hidden';
            marker.name = 'registrar_semanal_confirmado';
            marker.value = '1';
            marker.classList.add('hidden-post-input');
            form.appendChild(marker);

            // return true para cerrar modal y proceder
            return true;
        }
    });

    if (value) {
        // Enviar el formulario (envío normal, recargará la página y servidor hará el registro)
        form.submit();
    } else {
        // Si el usuario canceló, limpiar cualquier input hidden si quedó
        const existing = document.querySelectorAll('.hidden-post-input');
        existing.forEach(e => e.remove());
    }
});

// ------------------------------
// Render chart inside Swal
// ------------------------------
function renderSwalChart(items) {

    // limpiar canvas previo
    const oldCanvas = document.getElementById('swalChart');
    if (oldCanvas) oldCanvas.remove();

    // crear canvas nuevo con tamaño fijo
    const newCanvas = document.createElement('canvas');
    newCanvas.id = "swalChart";
    newCanvas.width = 600;
    newCanvas.height = 260;

    Swal.getHtmlContainer().appendChild(newCanvas);

    const labels = items.slice(0,8).map(it => it.nombre);
    const dataQty = items.slice(0,8).map(it => it.qty);

    const ctx = newCanvas.getContext('2d');

    // destruir chart previo
    if (currentChart) {
        currentChart.destroy();
        currentChart = null;
    }

    currentChart = new Chart(ctx, {
        type: 'bar',
        data: {
            labels,
            datasets: [{
                label: 'Cantidad registrada',
                data: dataQty,
                backgroundColor: function(context) {
                    const idx = context.dataIndex;
                    return `rgba(230, ${145 - idx*6}, ${56 - idx*3}, 0.85)`;
                },
            }]
        },
        options: {
            responsive: false,
            maintainAspectRatio: false,
            plugins: { 
                legend: { display:false }
            },
            scales: {
                y: { 
                    beginAtZero:true,
                    ticks: { precision:0 }
                }
            }
        }
    });
}

// ------------------------------
// Botón guardar final (si el usuario presiona el botón inferior)
// Vamos a replicar la misma validación previa y mostrar confirmación antes de enviar.
// ------------------------------
submitFinalBtn.addEventListener('click', async function(e){
    e.preventDefault();
    const data = gatherPreviewData();
    if (data.totalCant === 0) { Swal.fire({icon:'warning',title:'Sin datos',text:'No ingresaste cantidades.'}); return; }
    if (data.hasNegative) { Swal.fire({icon:'error',title:'Stock insuficiente',text:'Ajusta cantidades para no quedar con stock negativo.'}); return; }

    const res = await Swal.fire({
        title: '¿Registrar ahora?',
        html: `<p>Se registrarán <strong>${data.totalCant}</strong> productos con utilidad estimada <strong>$${data.totalUtilidad.toFixed(2)}</strong>.</p>`,
        showCancelButton: true,
        confirmButtonText: 'Si, registrar',
        cancelButtonText: 'Cancelar'
    });

    if (res.isConfirmed) {
        // agregar campos ocultos como en preview
        const existing = document.querySelectorAll('.hidden-post-input');
        existing.forEach(e => e.remove());
        data.items.forEach(it => {
            const input = document.createElement('input');
            input.type = 'hidden';
            input.name = `cantidades[${it.id}]`;
            input.value = it.qty;
            input.classList.add('hidden-post-input');
            form.appendChild(input);
        });
        const marker = document.createElement('input');
        marker.type = 'hidden';
        marker.name = 'registrar_semanal_confirmado';
        marker.value = '1';
        marker.classList.add('hidden-post-input');
        form.appendChild(marker);

        form.submit();
    }
});

// Inicializar tooltips y demás
document.addEventListener('DOMContentLoaded', function() {
    // Cualquier inicialización adicional
});
</script>